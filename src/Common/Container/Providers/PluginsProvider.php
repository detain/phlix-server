<?php

declare(strict_types=1);

namespace Phlix\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Common\Events\ListenerRegistry;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Plugins\Installer\ComposerRunner;
use Phlix\Plugins\Installer\HttpInstaller;
use Phlix\Plugins\PluginLoader;
use Phlix\Plugins\Repository\PluginRepository;
use Phlix\Plugins\Signature\SignatureVerifier;
use Psr\Container\ContainerInterface;
use Workerman\MySQL\Connection;

use function DI\factory;

/**
 * Wires the plugin loader stack into the container.
 *
 * Registers (all singletons by default):
 *
 *  - {@see PluginRepository}     — DB-backed CRUD for `plugins` table.
 *  - {@see HttpInstaller}        — URL + local-dir source staging.
 *  - {@see ComposerRunner}       — per-plugin `composer install`.
 *  - {@see SignatureVerifier}    — trusted-key allowlist verification.
 *  - {@see PluginLoader}         — public orchestrator that combines
 *    the above with the {@see ListenerRegistry} (from
 *    {@see EventServicesProvider}) and the host container itself.
 *
 * The loader is _registered_ here but NOT auto-enabled — calling
 * {@see PluginLoader::bootstrapEnabled()} is the operator's choice
 * because some bootstrap paths (CLI migrations, unit tests) don't want
 * plugins to come up. The server bootstrap in
 * `src/Server/Core/Application.php` (future commit) is the canonical
 * call site.
 *
 * @internal Phlix-internal service provider.
 *
 * @package Phlix\Common\Container\Providers
 * @since 0.10.0
 */
final class PluginsProvider implements ServiceProviderInterface
{
    /**
     * Default subdirectory (relative to the project root) where plugins
     * are installed. Override by setting `plugins_base_dir` in the
     * application config.
     */
    public const DEFAULT_PLUGINS_DIR = 'var/plugins';

    /**
     * Register the plugin-loader bindings.
     *
     * @param ContainerBuilder<\DI\Container> $builder
     * @param array<string, mixed>            $appConfig
     *
     * @since 0.10.0
     */
    public function register(ContainerBuilder $builder, array $appConfig): void
    {
        $pluginsBaseDir = is_string($appConfig['plugins_base_dir'] ?? null)
            ? (string) $appConfig['plugins_base_dir']
            : self::resolveDefaultDir();

        $composerTimeout = self::envInt(
            'PHLIX_PLUGINS_COMPOSER_TIMEOUT',
            ComposerRunner::DEFAULT_TIMEOUT_SECONDS,
        );
        $requireSignature = self::envBool('PHLIX_PLUGINS_REQUIRE_SIGNATURE', false);
        $loggerConfigPath = $appConfig['logger_config_path'] ?? null;

        $builder->addDefinitions([
            PluginRepository::class => factory(
                static function (ContainerInterface $c) use ($pluginsBaseDir): PluginRepository {
                    /** @var Connection $db */
                    $db = $c->get(Connection::class);
                    return new PluginRepository($db, $pluginsBaseDir);
                }
            ),

            HttpInstaller::class => factory(
                static function () use ($pluginsBaseDir, $loggerConfigPath): HttpInstaller {
                    if (is_string($loggerConfigPath) && $loggerConfigPath !== '') {
                        LoggerFactory::init($loggerConfigPath);
                    }
                    return new HttpInstaller(
                        $pluginsBaseDir,
                        LoggerFactory::get(LogChannels::PLUGINS),
                    );
                }
            ),

            ComposerRunner::class => factory(
                static function () use ($composerTimeout, $loggerConfigPath): ComposerRunner {
                    if (is_string($loggerConfigPath) && $loggerConfigPath !== '') {
                        LoggerFactory::init($loggerConfigPath);
                    }
                    return new ComposerRunner(
                        $composerTimeout,
                        ComposerRunner::DEFAULT_COMPOSER_BIN,
                        LoggerFactory::get(LogChannels::PLUGINS),
                    );
                }
            ),

            SignatureVerifier::class => factory(
                static function () use ($requireSignature): SignatureVerifier {
                    return new SignatureVerifier([], $requireSignature);
                }
            ),

            PluginLoader::class => factory(
                static function (ContainerInterface $c) use ($loggerConfigPath): PluginLoader {
                    if (is_string($loggerConfigPath) && $loggerConfigPath !== '') {
                        LoggerFactory::init($loggerConfigPath);
                    }
                    /** @var HttpInstaller $installer */
                    $installer = $c->get(HttpInstaller::class);
                    /** @var ComposerRunner $composer */
                    $composer = $c->get(ComposerRunner::class);
                    /** @var SignatureVerifier $verifier */
                    $verifier = $c->get(SignatureVerifier::class);
                    /** @var PluginRepository $repo */
                    $repo = $c->get(PluginRepository::class);
                    /** @var ListenerRegistry $registry */
                    $registry = $c->get(ListenerRegistry::class);
                    /** @var AuditLogger $audit */
                    $audit = $c->get(AuditLogger::class);

                    $logger = null;
                    if ($c->has('logger.plugins')) {
                        $candidate = $c->get('logger.plugins');
                        if ($candidate instanceof StructuredLogger) {
                            $logger = $candidate;
                        }
                    }
                    if ($logger === null) {
                        $logger = LoggerFactory::get(LogChannels::PLUGINS);
                    }

                    return new PluginLoader(
                        $installer,
                        $composer,
                        $verifier,
                        $repo,
                        $registry,
                        $c,
                        $audit,
                        $logger,
                    );
                }
            ),
        ]);
    }

    /**
     * Resolve the default `var/plugins/` directory relative to the
     * project root (`src/Common/Container/Providers/PluginsProvider.php`
     * -> up four levels).
     */
    private static function resolveDefaultDir(): string
    {
        return dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . self::DEFAULT_PLUGINS_DIR;
    }

    /**
     * Read a boolean env var with the same truthy semantics used by
     * {@see \Phlix\Common\Container\ContainerFactory::shouldCompile()}.
     */
    private static function envBool(string $name, bool $default): bool
    {
        $value = getenv($name);
        if ($value === false) {
            return $default;
        }
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Read an integer env var with a default fallback.
     */
    private static function envInt(string $name, int $default): int
    {
        $value = getenv($name);
        if ($value === false || !is_numeric($value)) {
            return $default;
        }
        return (int) $value;
    }
}
