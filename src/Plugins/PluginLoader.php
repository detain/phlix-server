<?php

/**
 * Phlix media server component: Plugins.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins;

use Phlix\Common\Events\ListenerRegistry;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Common\Version;
use Phlix\Media\Metadata\Resolution\SourceRegistry;
use Phlix\Plugins\Exception\PluginEnableException;
use Phlix\Plugins\Exception\PluginInstallException;
use Phlix\Plugins\Exception\PluginNotFoundException;
use Phlix\Plugins\Installer\ComposerRunner;
use Phlix\Plugins\Installer\HttpInstaller;
use Phlix\Plugins\Repository\PluginRepository;
use Phlix\Plugins\Signature\SignatureVerifier;
use Phlix\Plugins\Util\RecursiveDelete;
use DI\FactoryInterface;
use Phlix\Shared\Metadata\MetadataSourceInterface;
use Phlix\Shared\Plugin\ConfigurableInterface;
use Phlix\Shared\Plugin\EventNameMap;
use Phlix\Shared\Plugin\LifecycleInterface;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
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
        private readonly ?SourceRegistry $sourceRegistry = null,
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

        // Carry the operator's state across a RE-install (i.e. an update).
        //
        // This block used to be a bare delete-then-insert-with-defaults, which
        // silently destroyed two things every time a plugin was updated:
        // the `enabled` flag, and the ENTIRE settings blob — including API keys
        // and OAuth tokens. Same failure family as
        // PluginRepository::updateSettings()'s wholesale replace, but wider.
        //
        // It went unnoticed for so long only because auto-update never actually
        // ran (PluginAutoUpdateWorker armed a bare 86400s timer that a
        // deployed-to box never reached). The moment that was fixed, the first
        // real update cycle disabled three plugins and wiped Trakt's OAuth
        // access + refresh tokens on production. Recovered from the nightly
        // backup; do not reintroduce this.
        //
        // A plugin is still installed DISABLED when it is genuinely new — that
        // default belongs to first install, not to an upgrade an operator never
        // asked to be switched off.
        $previous = $this->repository->existsByName($manifest->name)
            ? $this->repository->findByName($manifest->name)
            : null;

        $defaults = self::defaultSettings($manifest);
        if ($previous !== null) {
            // Manifest defaults supply any NEWLY-declared setting; the stored
            // values win wherever they exist, so an upgrade neither loses
            // configuration nor misses new keys.
            $settings = array_merge($defaults, $previous->settings);
            $enabled  = $previous->enabled;

            $this->repository->delete($manifest->name);
        } else {
            $settings = $defaults;
            $enabled  = false;
        }

        $this->repository->insert($manifest, $enabled, $settings);

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

        // Operator-initiated enable = wire into THIS worker + PERSIST the
        // enabled flag + write an audit row. The wire step (onEnable +
        // subscribe + register) is shared with the boot re-attach path
        // {@see self::bootstrapEnabled()}, which deliberately does NOT persist
        // or audit (the plugin is already persisted-enabled — re-attaching it
        // in every resident worker at boot must not spam N DB writes / audit
        // rows, once per worker × 14+ workers).
        $registeredSource = $this->wire($installed);

        $this->repository->setEnabled($name, true);

        $this->auditLogger->logPluginAction(
            null,
            'enable',
            $name,
            ['subscriptions' => count($this->activeSubscriptions[$name] ?? [])],
        );
        $this->logger()->info('plugin enabled', [
            'plugin' => $name,
            'subscriptions' => count($this->activeSubscriptions[$name] ?? []),
            'metadata_source' => $registeredSource,
        ]);
    }

    /**
     * Wire an already-loaded, persisted-enabled plugin into THIS worker's
     * dispatch surfaces: run its (de-blocked, boot-safe) `onEnable()`, subscribe
     * its {@see LifecycleInterface::subscribedEvents()} into this worker's
     * {@see ListenerRegistry}, and register any {@see MetadataSourceInterface}
     * into this worker's {@see SourceRegistry}.
     *
     * This is the boot re-attach primitive shared by {@see self::enable()} and
     * {@see self::bootstrapEnabled()}. It performs NO persistence and writes NO
     * audit row — those side-effects belong ONLY to the operator-initiated
     * {@see self::enable()} path. Every plugin's `onEnable()` is required to be
     * a cheap, non-blocking "wire" step (no network / migrations / blocking I/O
     * — deferred to first use); running it across every resident worker at boot
     * must never hang a worker (the item-5c3 landmine).
     *
     * @param InstalledPlugin $installed The persisted plugin record.
     *
     * @return string|null The registered metadata-source name, or null when the
     *                      plugin is not a {@see MetadataSourceInterface}.
     *
     * @throws PluginEnableException On a missing/invalid entry class or a
     *         throwing `onEnable()` / bad subscription — callers that must not
     *         let one bad plugin abort the rest (bootstrapEnabled) catch this.
     */
    private function wire(InstalledPlugin $installed): ?string
    {
        $name = $installed->name();

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

        $instance = $this->instantiateEntry($installed, $entryFqcn);

        if (!$instance instanceof LifecycleInterface) {
            throw new PluginEnableException(sprintf(
                'Plugin %s entry class %s must implement %s.',
                $name,
                $entryFqcn,
                LifecycleInterface::class,
            ));
        }

        // Deliver the persisted settings to the plugin BEFORE onEnable() so
        // onEnable() can rely on them being present. The entry class opts into
        // this by implementing the shared ConfigurableInterface (preferred) or,
        // for plugins that expose a settings hook but have not yet migrated to
        // the interface, by declaring a public `configure(array $settings)`
        // method. This is the ONLY channel by which a plugin receives its
        // configured API keys/options — without it, an enabled plugin would run
        // with empty settings. (Legacy plugins whose constructor REQUIRES the
        // settings array get them injected at construction instead — see
        // {@see self::instantiateEntry()}.)
        $this->applyPersistedSettings($instance, $installed, $name);

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

        // First-class metadata-source registration (Step 3.5). Replaces the
        // brittle per-plugin `method_exists($manager,'registerProvider')`/FQCN
        // container-sniffing the anidb/myanimelist plugins used to do in their
        // own onEnable(): if the entry instance implements the shared typed
        // contract, the host registers it here, sniff-free. Deregistered in
        // disable() for a leak-free enable/disable cycle.
        $registeredSource = null;
        if ($this->sourceRegistry !== null && $instance instanceof MetadataSourceInterface) {
            $this->sourceRegistry->register($instance);
            $registeredSource = $instance->sourceName();
        }

        return $registeredSource;
    }

    /**
     * Instantiate a plugin entry class.
     *
     * Preferred path: resolve via the host container (PHP-DI autowiring).
     * Plugins SHOULD keep an autowirable (all-optional) constructor and
     * receive their persisted settings through
     * {@see ConfigurableInterface::configure()} instead of the constructor.
     *
     * Compatibility fallback: an older plugin whose constructor REQUIRES the
     * settings array (e.g. `__construct(array $settings)`) cannot be
     * autowired — PHP-DI throws because it cannot guess an `array` value.
     * Rather than fail the enable outright, if the host container is a PHP-DI
     * factory we retry with the persisted settings bound to the first
     * `array`-typed (or untyped) required constructor parameter. This keeps
     * such plugins working until they migrate to {@see ConfigurableInterface}.
     * If the fallback is unavailable or does not apply, the original
     * resolution error is surfaced verbatim as a {@see PluginEnableException}
     * so the operator sees the real reason (e.g. "Parameter $apiKey has no
     * value guessable" → the plugin needs an autowirable constructor).
     *
     * @param InstalledPlugin $installed The installed plugin (source of settings).
     * @param string          $entryFqcn The manifest entry class FQCN.
     *
     * @return object The constructed entry instance.
     *
     * @throws PluginEnableException When neither path can build the entry class.
     */
    private function instantiateEntry(InstalledPlugin $installed, string $entryFqcn): object
    {
        try {
            $resolved = $this->container->get($entryFqcn);
        } catch (Throwable $getError) {
            return $this->fallbackOrThrow($installed, $entryFqcn, $getError);
        }
        if (is_object($resolved)) {
            return $resolved;
        }
        return $this->fallbackOrThrow(
            $installed,
            $entryFqcn,
            new \UnexpectedValueException(sprintf(
                'container resolved a non-object (%s)',
                get_debug_type($resolved),
            )),
        );
    }

    /**
     * Try the settings-constructor fallback, or throw a
     * {@see PluginEnableException} carrying the original resolution cause.
     */
    private function fallbackOrThrow(InstalledPlugin $installed, string $entryFqcn, Throwable $cause): object
    {
        $made = $this->makeWithSettings($entryFqcn, $installed->settings);
        if ($made !== null) {
            $this->logger()->info('plugin entry built via settings-constructor fallback', [
                'plugin' => $installed->name(),
                'entry'  => $entryFqcn,
            ]);
            return $made;
        }
        throw new PluginEnableException(sprintf(
            'Plugin %s entry class %s could not be resolved: %s',
            $installed->name(),
            $entryFqcn,
            $cause->getMessage(),
        ), 0, $cause);
    }

    /**
     * Best-effort construction of a legacy entry class whose constructor
     * requires the settings array, binding the persisted settings to that
     * parameter and letting the container autowire the rest.
     *
     * Returns null (so the caller falls back to the clean resolution error)
     * when the container is not a PHP-DI factory, the class is missing, the
     * constructor has no `array`-bindable required parameter, or `make()`
     * still cannot satisfy the remaining parameters.
     *
     * @param array<string, mixed> $settings Persisted settings map.
     */
    private function makeWithSettings(string $entryFqcn, array $settings): ?object
    {
        if (!$this->container instanceof FactoryInterface || !class_exists($entryFqcn)) {
            return null;
        }
        $paramName = self::settingsConstructorParam($entryFqcn);
        if ($paramName === null) {
            return null;
        }
        try {
            $made = $this->container->make($entryFqcn, [$paramName => $settings]);
        } catch (Throwable) {
            return null;
        }
        return is_object($made) ? $made : null;
    }

    /**
     * Name of the first REQUIRED constructor parameter that can accept the
     * settings array (typed `array`, or untyped). Returns null when the
     * constructor is absent, has no required parameters, or its required
     * parameters are all typed as something the settings array cannot fill
     * (a scalar/object) — in which case the settings-constructor fallback
     * does not apply.
     *
     * @param class-string $fqcn
     */
    private static function settingsConstructorParam(string $fqcn): ?string
    {
        try {
            $ctor = (new ReflectionClass($fqcn))->getConstructor();
        } catch (ReflectionException) {
            return null;
        }
        if ($ctor === null) {
            return null;
        }
        foreach ($ctor->getParameters() as $param) {
            if ($param->isOptional()) {
                continue;
            }
            $type = $param->getType();
            if ($type === null || ($type instanceof ReflectionNamedType && $type->getName() === 'array')) {
                return $param->getName();
            }
        }
        return null;
    }

    /**
     * Deliver the plugin's persisted settings to the entry instance via its
     * settings hook, if it exposes one.
     *
     * Prefers the typed {@see ConfigurableInterface}; also accepts a plugin
     * that declares a public `configure(array $settings)` method but has not
     * yet migrated to the interface (duck-typed, guarded so an unrelated
     * `configure()` — e.g. a no-arg one — is never called). A plugin that
     * received its settings through the constructor fallback and exposes no
     * hook is simply skipped.
     *
     * @param string $name Manifest name (for the error message).
     *
     * @throws PluginEnableException When the hook throws.
     */
    private function applyPersistedSettings(object $instance, InstalledPlugin $installed, string $name): void
    {
        if (!($instance instanceof ConfigurableInterface) && !self::hasConfigureHook($instance)) {
            return;
        }
        try {
            /** @var ConfigurableInterface $instance */
            $instance->configure($installed->settings);
        } catch (Throwable $e) {
            throw new PluginEnableException(sprintf(
                'Plugin %s configure() threw: %s',
                $name,
                $e->getMessage(),
            ), 0, $e);
        }
    }

    /**
     * Whether an entry instance exposes a public `configure()` method whose
     * first parameter can receive the settings array (typed `array`, untyped,
     * or variadic). Used to bridge plugins that predate
     * {@see ConfigurableInterface}. A `configure()` with no parameters (or a
     * non-array-compatible first parameter) is rejected so we never call the
     * wrong method.
     */
    private static function hasConfigureHook(object $instance): bool
    {
        if (!method_exists($instance, 'configure')) {
            return false;
        }
        try {
            $method = new \ReflectionMethod($instance, 'configure');
        } catch (ReflectionException) {
            return false;
        }
        if (!$method->isPublic() || $method->isStatic()) {
            return false;
        }
        $params = $method->getParameters();
        if ($params === []) {
            return false;
        }
        $type = $params[0]->getType();
        return $type === null
            || $params[0]->isVariadic()
            || ($type instanceof ReflectionNamedType && $type->getName() === 'array');
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
            // Deregister the metadata source first (Step 3.5) so the registry
            // never holds a reference to a plugin that is on its way down — the
            // mirror of the enable()-time register(). Truly removes the entry
            // (no leak across enable/disable cycles).
            if ($this->sourceRegistry !== null && $instance instanceof MetadataSourceInterface) {
                $this->sourceRegistry->deregisterInstance($instance);
            }
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
     * Instantiate a plugin's entry class without calling onEnable().
     *
     * This is used by the admin API to access plugin methods like
     * `testCredentials()` without fully enabling the plugin (which
     * would subscribe event listeners and potentially perform I/O).
     *
     * The entry instance will have its `configure()` called with
     * the persisted settings so it can validate credentials.
     *
     * @param string $name Manifest name (e.g. `phlix-plugin-trakt`).
     *
     * @return object|null The entry instance, or null if the plugin
     *                    could not be instantiated.
     *
     * @throws PluginNotFoundException When no installed plugin matches the name.
     *
     * @since 0.18.0
     */
    public function getEntryInstance(string $name): ?object
    {
        $installed = $this->repository->findByName($name);

        $autoload = $installed->directory . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }

        $entryFqcn = $installed->manifest->entry;
        if (!class_exists($entryFqcn)) {
            $this->logger()->warning('plugin entry class does not exist', [
                'plugin' => $name,
                'entry' => $entryFqcn,
            ]);
            return null;
        }

        $instance = $this->instantiateEntry($installed, $entryFqcn);

        // Deliver settings so the plugin can use them for credential testing
        $this->applyPersistedSettings($instance, $installed, $name);

        return $instance;
    }

    /**
     * Re-attach every persisted-as-enabled plugin into THIS worker's dispatch
     * surfaces. Called from each resident worker's `onWorkerStart` (the HTTP
     * workers — playback → scrobble — and the `library-scan` managed worker —
     * `MediaItemAdded` → enrich) so that after any restart every enabled plugin
     * is actually subscribed to events and registered as a metadata source in
     * every worker that dispatches the events it cares about. This is the F1
     * keystone: without it, an admin "enable" only wires the single HTTP worker
     * that served the request and is lost on restart.
     *
     * WIRE-ONLY: uses {@see self::wire()}, which runs each plugin's (de-blocked,
     * boot-safe) `onEnable()` + subscribes + registers, but performs NO
     * persistence and writes NO audit row. It must NOT call {@see self::enable()}
     * — that would re-run `setEnabled()` + an `enable` audit row once per worker
     * (14+ HTTP workers + the managed workers) on every boot. The plugin is
     * already persisted-enabled; boot re-attach only mirrors that state into the
     * live worker.
     *
     * Idempotent: a plugin already wired in this worker (e.g. an admin enabled it
     * in this exact worker before this ran) is skipped. Failures are logged but
     * do not bubble up — one broken plugin must not block the rest from coming
     * online, nor stop the worker from serving.
     *
     * PREREQUISITE (item-5c3): every plugin's `onEnable()` must be non-blocking
     * (no network / migrations / blocking I/O — deferred to first use). A
     * blocking `onEnable()` here would hang the worker at boot across the whole
     * fleet, which is exactly the outage this call was reverted for in the past.
     *
     * @return void
     *
     * @since 0.10.0
     */
    public function bootstrapEnabled(): void
    {
        foreach ($this->repository->listEnabled() as $installed) {
            $name = $installed->name();
            if (isset($this->entryInstances[$name])) {
                // Already wired in this worker — nothing to do (idempotent).
                continue;
            }
            try {
                $this->wire($installed);
            } catch (Throwable $e) {
                $this->logger()->error('failed to bootstrap enabled plugin', [
                    'plugin' => $name,
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
     * schema, falling back to `null` for keys without a `default`.
     *
     * EVERY declared setting key is always given a slot: the declared
     * `default` when present, otherwise `null`. A `required: true`
     * setting with no `default` is therefore materialised as `null`
     * (a slot is still created) — `required` is **advisory** metadata
     * for the settings UI, not a load-time rejection. This keeps the
     * materialised array's key-set identical to the manifest's
     * declared key-set and avoids silently dropping required-but-
     * defaultless keys.
     *
     * @return array<string, mixed>
     */
    private static function defaultSettings(Manifest $manifest): array
    {
        $defaults = [];
        foreach ($manifest->settings as $key => $schema) {
            $defaults[$key] = $schema['default'] ?? null;
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
