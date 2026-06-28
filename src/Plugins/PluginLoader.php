<?php

declare(strict_types=1);

namespace Phlix\Plugins;

use Phlix\Common\Events\ListenerRegistry;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Common\Version;
use Phlix\Plugins\Exception\PluginEnableException;
use Phlix\Plugins\Exception\PluginInstallException;
use Phlix\Plugins\Exception\PluginNotFoundException;
use Phlix\Plugins\Installer\ComposerRunner;
use Phlix\Plugins\Installer\HttpInstaller;
use Phlix\Plugins\Repository\PluginRepository;
use Phlix\Plugins\Signature\SignatureVerifier;
use Phlix\Plugins\Util\RecursiveDelete;
use Phlix\Shared\Plugin\EventNameMap;
use Phlix\Shared\Plugin\LifecycleInterface;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Public-facing orchestrator that exercises the whole Phase A plugin
 * pipeline (install → enable → disable → uninstall).
 *
 * The loader composes one collaborator per concern so each is testable
 * in isolation:
 *
 *  - {@see HttpInstaller}   — downloads sources, validates manifest.
 *  - {@see ComposerRunner}  — resolves per-plugin `vendor/`.
 *  - {@see SignatureVerifier} — checks `sha256:` signatures.
 *  - {@see PluginRepository} — CRUD for the `plugins` table.
 *  - {@see ListenerRegistry} — PSR-14 subscribe / unsubscribe facade.
 *  - {@see ContainerInterface} — resolves the plugin entry class via
 *    autowiring; gives the plugin a handle to the host container.
 *
 * Auto-enable on boot is handled by
 * {@see \Phlix\Common\Container\Providers\PluginsProvider} which calls
 * {@see self::bootstrapEnabled()} once the container is built.
 *
 * @package Phlix\Plugins
 * @since 0.10.0
 */
class PluginLoader
{
    /**
     * Live record of `(pluginName => list<array{eventClass, callable}>)`
     * subscriptions so {@see disable()} can call
     * {@see ListenerRegistry::unsubscribe()} for exactly the callables
     * it registered.
     *
     * @var array<string, list<array{class-string, callable}>>
     */
    private array $activeSubscriptions = [];

    /**
     * Live record of the in-memory plugin entry-class instances. Allows
     * {@see disable()} to call `onDisable()` on the exact instance that
     * was enabled, and `bootstrapEnabled()` to skip plugins that have
     * already been brought up earlier in the same process.
     *
     * @var array<string, LifecycleInterface>
     */
    private array $entryInstances = [];

    public function __construct(
        private readonly HttpInstaller $installer,
        private readonly ComposerRunner $composer,
        private readonly SignatureVerifier $signatureVerifier,
        private readonly PluginRepository $repository,
        private readonly ListenerRegistry $listenerRegistry,
        private readonly ContainerInterface $container,
        private readonly AuditLogger $auditLogger,
        private ?StructuredLogger $logger = null,
    ) {
    }

    /**
     * Install a plugin from a remote URL (or `file://` URL for tests).
     *
     * Steps:
     *  1. Download + extract via {@see HttpInstaller}.
     *  2. Validate `phlix_min_server_version` against {@see Version::STRING}.
     *  3. Verify the manifest signature (if present) via {@see SignatureVerifier}.
     *  4. Run `composer install --no-dev` via {@see ComposerRunner}.
     *  5. Persist a row in `plugins`.
     *
     * @param string      $sourceUrl HTTPS URL, `file://` URL, or stub `plugin.json` URL.
     * @param string|null $expectedSha256 Pinned artifact sha256 from the catalog
     *        entry (SV-S1b/SV-S2b). `null` = un-pinned install, which is
     *        refused by default-deny unless `PHLIX_PLUGINS_ALLOW_UNVERIFIED=1`.
     * @param string|null $pinnedRef Pinned commit sha to download (SV-S1b).
     *
     * @return Manifest Parsed manifest of the installed plugin.
     *
     * @throws PluginInstallException
     *
     * @since 0.10.0
     */
    public function install(string $sourceUrl, ?string $expectedSha256 = null, ?string $pinnedRef = null): Manifest
    {
        // SECURITY (SV-S2b, default-deny): an install with no pinned artifact
        // digest is unverified. Refuse it before we even fetch bytes, unless the
        // operator has explicitly opted into unverified installs. This is
        // checked here (the single install chokepoint) so both the admin UI and
        // the auto-updater inherit it. file:// dev/test sources are exempt — see
        // assertVerifiedOrOverride().
        $this->assertVerifiedOrOverride($sourceUrl, $expectedSha256);

        [$manifest, $directory] = $this->installer->install($sourceUrl, $expectedSha256, $pinnedRef);
        return $this->finalizeInstall($manifest, $directory, $sourceUrl);
    }

    /**
     * Install a plugin from a local source directory.
     *
     * Mostly used by integration tests and operator-side `dev install`
     * workflows where the plugin already lives on disk.
     *
     * @param string $localPath Absolute path to a directory containing `plugin.json`.
     *
     * @throws PluginInstallException
     *
     * @since 0.10.0
     */
    public function installFromDirectory(string $localPath): Manifest
    {
        [$manifest, $directory] = $this->installer->installFromDirectory($localPath);
        return $this->finalizeInstall($manifest, $directory, $localPath);
    }

    /**
     * Common post-stage steps for {@see install()} and
     * {@see installFromDirectory()}: version check, signature check,
     * composer install, DB insert, audit log.
     */
    private function finalizeInstall(Manifest $manifest, string $directory, string $source): Manifest
    {
        if (!self::satisfiesServerVersion($manifest->phlixMinServerVersion)) {
            RecursiveDelete::remove($directory);
            throw new PluginInstallException(sprintf(
                'Plugin %s requires Phlix >= %s but running server is %s.',
                $manifest->name,
                $manifest->phlixMinServerVersion,
                Version::STRING,
            ));
        }

        $signatureResult = $this->signatureVerifier->verify($manifest, $directory);
        if ($signatureResult === SignatureVerifier::RESULT_INVALID) {
            RecursiveDelete::remove($directory);
            throw new PluginInstallException(sprintf(
                'Plugin %s signature did not verify against the trusted-key allowlist.',
                $manifest->name,
            ));
        }
        if ($signatureResult === SignatureVerifier::RESULT_UNSIGNED) {
            $this->logger()->warning('installing unsigned plugin', [
                'plugin' => $manifest->name,
                'source' => $source,
            ]);
        }

        try {
            $this->composer->install($directory);
        } catch (PluginInstallException $e) {
            RecursiveDelete::remove($directory);
            throw $e;
        }

        if ($this->repository->existsByName($manifest->name)) {
            $this->repository->delete($manifest->name);
        }

        $defaults = self::defaultSettings($manifest);
        $this->repository->insert($manifest, false, $defaults);

        $this->auditLogger->logPluginAction(
            null,
            'install',
            $manifest->name,
            [
                'version'   => $manifest->version,
                'source'    => $source,
                'directory' => $directory,
            ],
        );
        $this->logger()->info('plugin installed', [
            'plugin' => $manifest->name,
            'version' => $manifest->version,
            'source' => $source,
            'directory' => $directory,
        ]);

        return $manifest;
    }

    /**
     * Enable a previously-installed plugin: load `vendor/autoload.php`,
     * instantiate the entry class via the container, call
     * `onEnable()`, subscribe declared listeners, and flip the
     * `enabled` flag in the DB.
     *
     * @param string $name Manifest name (e.g. `phlix-plugin-lastfm`).
     *
     * @throws PluginNotFoundException
     * @throws PluginEnableException
     *
     * @since 0.10.0
     */
    public function enable(string $name): void
    {
        $installed = $this->repository->findByName($name);
        if (isset($this->entryInstances[$name])) {
            $this->logger()->info('plugin already enabled in this process', ['plugin' => $name]);
            return;
        }

        $autoload = $installed->directory . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }

        $entryFqcn = $installed->manifest->entry;
        if (!class_exists($entryFqcn)) {
            throw new PluginEnableException(sprintf(
                'Plugin %s entry class %s does not exist (forgot composer install?).',
                $name,
                $entryFqcn,
            ));
        }

        try {
            $instance = $this->container->get($entryFqcn);
        } catch (Throwable $e) {
            throw new PluginEnableException(sprintf(
                'Plugin %s entry class %s could not be resolved: %s',
                $name,
                $entryFqcn,
                $e->getMessage(),
            ), 0, $e);
        }

        if (!$instance instanceof LifecycleInterface) {
            throw new PluginEnableException(sprintf(
                'Plugin %s entry class %s must implement %s.',
                $name,
                $entryFqcn,
                LifecycleInterface::class,
            ));
        }

        try {
            $instance->onEnable($this->container);
        } catch (Throwable $e) {
            throw new PluginEnableException(sprintf(
                'Plugin %s onEnable() threw: %s',
                $name,
                $e->getMessage(),
            ), 0, $e);
        }

        // Fail fast on unknown manifest event aliases — the actual
        // subscription happens off subscribedEvents() below, but if the
        // manifest names an alias that EventNameMap can't translate the
        // plugin is misconfigured and we'd rather surface that at enable
        // time than at first dispatch.
        $subscriptions = [];
        foreach ($installed->manifest->events as $alias) {
            $fqcn = EventNameMap::fromAlias($alias);
            if ($fqcn === null) {
                throw new PluginEnableException(sprintf(
                    'Plugin %s declared unknown event alias "%s".',
                    $name,
                    $alias,
                ));
            }
        }

        $declared = $instance->subscribedEvents();
        foreach ($declared as $eventClass => $handler) {
            if (!is_string($eventClass) || !class_exists($eventClass)) {
                throw new PluginEnableException(sprintf(
                    'Plugin %s subscribed to non-existent event class "%s".',
                    $name,
                    is_string($eventClass) ? $eventClass : gettype($eventClass),
                ));
            }
            $callable = self::resolveCallable($instance, $handler, $name, $eventClass);
            $this->listenerRegistry->subscribe($eventClass, $callable);
            $subscriptions[] = [$eventClass, $callable];
        }

        $this->activeSubscriptions[$name] = $subscriptions;
        $this->entryInstances[$name] = $instance;

        $this->repository->setEnabled($name, true);

        $this->auditLogger->logPluginAction(
            null,
            'enable',
            $name,
            ['subscriptions' => count($subscriptions)],
        );
        $this->logger()->info('plugin enabled', [
            'plugin' => $name,
            'subscriptions' => count($subscriptions),
        ]);
    }

    /**
     * Disable a plugin: unsubscribe its listeners, call `onDisable()`,
     * flip the `enabled` flag. The plugin files and per-plugin settings
     * stay on disk so re-enabling later is cheap.
     *
     * @throws PluginNotFoundException
     *
     * @since 0.10.0
     */
    public function disable(string $name): void
    {
        $installed = $this->repository->findByName($name);

        foreach ($this->activeSubscriptions[$name] ?? [] as [$eventClass, $callable]) {
            $this->listenerRegistry->unsubscribe($eventClass, $callable);
        }
        unset($this->activeSubscriptions[$name]);

        $instance = $this->entryInstances[$name] ?? null;
        if ($instance !== null) {
            try {
                $instance->onDisable();
            } catch (Throwable $e) {
                $this->logger()->warning('plugin onDisable() threw — disable continues', [
                    'plugin' => $name,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        unset($this->entryInstances[$name]);

        $this->repository->setEnabled($name, false);

        $this->auditLogger->logPluginAction(
            null,
            'disable',
            $name,
            ['directory' => $installed->directory],
        );
        $this->logger()->info('plugin disabled', [
            'plugin' => $name,
            'directory' => $installed->directory,
        ]);
    }

    /**
     * Uninstall a plugin: disable first if needed, recursively delete
     * the plugin directory, delete the DB row.
     *
     * @throws PluginNotFoundException
     *
     * @since 0.10.0
     */
    public function uninstall(string $name): void
    {
        $installed = $this->repository->findByName($name);
        if ($installed->enabled) {
            $this->disable($name);
        }

        RecursiveDelete::remove($installed->directory);
        $this->repository->delete($name);

        $this->auditLogger->logPluginAction(
            null,
            'uninstall',
            $name,
            ['directory' => $installed->directory],
        );
        $this->logger()->info('plugin uninstalled', [
            'plugin' => $name,
            'directory' => $installed->directory,
        ]);
    }

    /**
     * Snapshot of every installed plugin.
     *
     * @return list<InstalledPlugin>
     *
     * @since 0.10.0
     */
    public function listInstalled(): array
    {
        return $this->repository->listAll();
    }

    /**
     * Snapshot of every enabled plugin.
     *
     * @return list<InstalledPlugin>
     *
     * @since 0.10.0
     */
    public function getEnabled(): array
    {
        return $this->repository->listEnabled();
    }

    /**
     * Fetch a single installed plugin by manifest name.
     *
     * Thin facade over {@see PluginRepository::findByName()} so the admin
     * controllers (configure/detail endpoints) can read one plugin's
     * manifest + persisted settings without reaching past the loader.
     *
     * @param string $name Manifest name (e.g. `phlix-plugin-anidb`).
     *
     * @return InstalledPlugin Fully hydrated DTO (manifest + settings).
     *
     * @throws PluginNotFoundException When no row matches the name.
     *
     * @since 0.12.0 (S6 — plugin configure endpoint)
     */
    public function getInstalled(string $name): InstalledPlugin
    {
        return $this->repository->findByName($name);
    }

    /**
     * Persist a replacement settings map for an installed plugin.
     *
     * Thin facade over {@see PluginRepository::updateSettings()}. The
     * caller is responsible for validating/merging the map against the
     * manifest schema first; this method only writes the JSON blob.
     *
     * @param string               $name     Manifest name.
     * @param array<string, mixed> $settings Full settings map to store.
     *
     * @since 0.12.0 (S6 — plugin configure endpoint)
     */
    public function updateSettings(string $name, array $settings): void
    {
        $this->repository->updateSettings($name, $settings);
    }

    /**
     * Re-attach every persisted-as-enabled plugin to the dispatcher.
     * Called by the {@see \Phlix\Common\Container\Providers\PluginsProvider}
     * after the container is built so server restarts pick up plugins
     * automatically.
     *
     * Failures are logged but do not bubble up — one broken plugin
     * should not block the rest from coming online.
     *
     * @return void
     *
     * @since 0.10.0
     */
    public function bootstrapEnabled(): void
    {
        foreach ($this->repository->listEnabled() as $installed) {
            try {
                $this->enable($installed->name());
            } catch (Throwable $e) {
                $this->logger()->error('failed to bootstrap enabled plugin', [
                    'plugin' => $installed->name(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Operator override env var that opts back into installing **unverified**
     * (un-pinned) plugin sources. Mirrors the `PHLIX_PLUGINS_ALLOW_HTTP`
     * pattern in {@see HttpInstaller}. Default-deny when unset.
     */
    public const ALLOW_UNVERIFIED_ENV = 'PHLIX_PLUGINS_ALLOW_UNVERIFIED';

    /**
     * Default-deny gate (SV-S2b): refuse an install whose artifact is not
     * pinned by a sha256 digest, unless the operator set
     * {@see self::ALLOW_UNVERIFIED_ENV}.
     *
     * `file://` sources (local dev checkouts, integration-test fixtures) are
     * always allowed — they are operator-local bytes, not a remote catalog
     * artifact, so the supply-chain pin does not apply to them.
     *
     * @throws PluginInstallException When the source is remote, un-pinned, and
     *         the override is not enabled.
     */
    private function assertVerifiedOrOverride(string $sourceUrl, ?string $expectedSha256): void
    {
        if ($expectedSha256 !== null && $expectedSha256 !== '') {
            return; // pinned — SV-S1b verifies the bytes against this digest.
        }

        $scheme = strtolower((string) parse_url(trim($sourceUrl), PHP_URL_SCHEME));
        if ($scheme === 'file' || $scheme === '') {
            return; // local source — supply-chain pin is not applicable.
        }

        if (self::allowUnverifiedInstalls()) {
            $this->logger()->warning('installing UNVERIFIED (un-pinned) plugin source — override is enabled', [
                'source' => $sourceUrl,
            ]);
            return;
        }

        throw new PluginInstallException(sprintf(
            'Refusing to install unverified plugin source %s: the catalog entry has no pinned '
            . 'artifact sha256 (schemaVersion 1 / un-pinned). Set %s=1 to override.',
            $sourceUrl,
            self::ALLOW_UNVERIFIED_ENV,
        ));
    }

    /**
     * Whether the operator has opted into unverified installs via the env var.
     */
    private static function allowUnverifiedInstalls(): bool
    {
        $value = getenv(self::ALLOW_UNVERIFIED_ENV);
        return $value !== false
            && in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Compare the manifest's `phlix_min_server_version` against
     * {@see Version::STRING} using {@see version_compare()}.
     */
    private static function satisfiesServerVersion(string $minVersion): bool
    {
        if ($minVersion === '') {
            return true;
        }
        return version_compare(Version::STRING, $minVersion, '>=');
    }

    /**
     * Translate a `[eventClass => handler]` entry from
     * {@see LifecycleInterface::subscribedEvents()} into a PHP callable
     * suitable for {@see ListenerRegistry::subscribe()}.
     *
     * @param string|callable $handler
     *
     * @throws PluginEnableException
     */
    private static function resolveCallable(
        LifecycleInterface $instance,
        $handler,
        string $pluginName,
        string $eventClass,
    ): callable {
        if (is_string($handler)) {
            if (!method_exists($instance, $handler)) {
                throw new PluginEnableException(sprintf(
                    'Plugin %s declared method %s for event %s but the entry class does not implement it.',
                    $pluginName,
                    $handler,
                    $eventClass,
                ));
            }
            /** @var callable $callable */
            $callable = [$instance, $handler];
            return $callable;
        }

        if (is_callable($handler)) {
            return $handler;
        }

        throw new PluginEnableException(sprintf(
            'Plugin %s subscribedEvents()[%s] must be a method name or callable.',
            $pluginName,
            $eventClass,
        ));
    }

    /**
     * Materialise default settings from the manifest's `settings`
     * schema, falling back to null for keys without a `default`.
     *
     * @return array<string, mixed>
     */
    private static function defaultSettings(Manifest $manifest): array
    {
        $defaults = [];
        foreach ($manifest->settings as $key => $schema) {
            if (array_key_exists('default', $schema)) {
                $defaults[$key] = $schema['default'];
            }
        }
        return $defaults;
    }

    /**
     * Lazy plugins-channel logger.
     */
    private function logger(): StructuredLogger
    {
        if ($this->logger === null) {
            $this->logger = LoggerFactory::get(LogChannels::PLUGINS);
        }
        return $this->logger;
    }
}
