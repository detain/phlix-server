<?php

/**
 * Phlix media server component: Providers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlix\Admin\SettingsRepository;
use Phlix\Common\Container\DegradedBuild;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Common\Database\ConnectionPool;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Server\Http\Middleware\SecurityHeadersPolicy;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Workerman\MySQL\Connection;

use function DI\factory;

/**
 * Registers the foundational bindings used by every other provider.
 *
 * - {@see Connection} resolves to the singleton MySQL connection vended
 *   by {@see ConnectionPool::getConnection()}. The static pool is wrapped
 *   rather than replaced; the Phase B work removes the static.
 * - {@see LoggerFactory} is initialised once with the configured logger
 *   config path and bound to the container as a value so callers can
 *   resolve it for ad-hoc channels.
 * - One named binding per {@see LogChannels} constant is registered
 *   ("logger.auth", "logger.http", etc.) so providers/consumers can
 *   reference a channel with `DI\get('logger.auth')` instead of pulling
 *   the factory.
 * - {@see AuditLogger} is wired against the AUDIT channel so AuthManager
 *   resolves with the correct logger automatically.
 *
 * @internal Phlix-internal service provider; consumed by ContainerFactory only.
 *
 * @package Phlix\Common\Container\Providers
 * @since 0.10.0
 */
final class CoreServicesProvider implements ServiceProviderInterface
{
    /**
     * Register database, logger factory and per-channel logger bindings.
     *
     * @param ContainerBuilder<\DI\Container> $builder
     * @param array<string, mixed>            $appConfig Must contain `db_config_path`
     *                                         and `logger_config_path` keys
     *                                         (the factory injects these).
     *
     * @return void
     *
     * @since 0.10.0
     */
    public function register(ContainerBuilder $builder, array $appConfig): void
    {
        $dbConfigPath = $appConfig['db_config_path'] ?? null;
        $loggerConfigPath = $appConfig['logger_config_path'] ?? null;

        $definitions = [
            'app.config' => $appConfig,
            'app.db_config_path' => $dbConfigPath,
            'app.logger_config_path' => $loggerConfigPath,

            // R5: ConnectionPool bound to DI container so it can be injected
            // into Application (replacing static ConnectionPool::getConnection calls).
            ConnectionPool::class => factory(static function () use ($dbConfigPath): ConnectionPool {
                if (is_string($dbConfigPath) && $dbConfigPath !== '' && ConnectionPool::getInstance() === null) {
                    ConnectionPool::init($dbConfigPath);
                }
                return ConnectionPool::getInstance() ?? new ConnectionPool();
            }),

            // Initialise the static pools exactly once on first resolve.
            Connection::class => factory(static function () use ($dbConfigPath): Connection {
                if (is_string($dbConfigPath) && $dbConfigPath !== '' && ConnectionPool::getInstance() === null) {
                    ConnectionPool::init($dbConfigPath);
                }
                return ConnectionPool::getConnection('mysql');
            }),

            LoggerFactory::class => factory(static function () use ($loggerConfigPath): LoggerFactory {
                if (is_string($loggerConfigPath) && $loggerConfigPath !== '') {
                    LoggerFactory::init($loggerConfigPath);
                }
                return new LoggerFactory();
            }),
        ];

        foreach (self::channels() as $alias => $channel) {
            $definitions[$alias] = factory(static function () use ($loggerConfigPath, $channel): StructuredLogger {
                if (is_string($loggerConfigPath) && $loggerConfigPath !== '') {
                    LoggerFactory::init($loggerConfigPath);
                }
                return LoggerFactory::get($channel);
            });
        }

        // Default StructuredLogger autowiring target -> application channel.
        $definitions[StructuredLogger::class] = factory(static function () use ($loggerConfigPath): StructuredLogger {
            if (is_string($loggerConfigPath) && $loggerConfigPath !== '') {
                LoggerFactory::init($loggerConfigPath);
            }
            return LoggerFactory::get(LogChannels::APPLICATION);
        });

        // Plugin-safe alias so $container->get(LoggerInterface::class) resolves correctly.
        $definitions[LoggerInterface::class] = $definitions[StructuredLogger::class];

        // W4 — the two configurable security-header values, threaded from the
        // effective-settings store. Bound HERE (not in the Admin provider that
        // owns SettingsRepository) because its consumer is the repo-wide HTTP
        // surface: every response passes through HttpHandler, which resolves
        // this policy once per worker and re-reads values LIVE per decorate().
        // The store is fetched OPTIONAL-ly: a container built without the admin
        // subsystem must still yield a policy answering the shipped constants
        // (byte-identical to the pre-key hardcoded headers) — never a build
        // failure on the hot HTTP path. A store that IS bound but cannot build
        // is loudly degraded via DegradedBuild, same idiom as the artwork /
        // metadata / discovery policy factories.
        $definitions[SecurityHeadersPolicy::class] = factory(
            static function (ContainerInterface $c): SecurityHeadersPolicy {
                try {
                    $settings = $c->get(SettingsRepository::class);
                    $store = $settings instanceof SettingsRepository ? $settings : null;
                } catch (\Throwable $e) {
                    DegradedBuild::warnUnlessAbsent(
                        $c,
                        LogChannels::HTTP,
                        'The settings store is bound but could not be built; the '
                        . 'HSTS max-age and X-Frame-Options headers fall back to their '
                        . 'shipped (strict, pre-key) defaults. Admin-saved security-header '
                        . 'overrides stay ignored by this worker until it is recycled.',
                        $e
                    );

                    $store = null;
                }

                return new SecurityHeadersPolicy($store);
            }
        );

        $definitions[AuditLogger::class] = factory(static function () use ($loggerConfigPath): AuditLogger {
            if (is_string($loggerConfigPath) && $loggerConfigPath !== '') {
                LoggerFactory::init($loggerConfigPath);
            }
            return new AuditLogger(LoggerFactory::get(LogChannels::AUDIT));
        });

        $builder->addDefinitions($definitions);
    }

    /**
     * Map of container alias -> log channel name. Exposed for tests.
     *
     * @return array<string, string>
     *
     * @since 0.10.0
     */
    public static function channels(): array
    {
        return [
            'logger.application' => LogChannels::APPLICATION,
            'logger.http' => LogChannels::HTTP,
            'logger.websocket' => LogChannels::WEBSOCKET,
            'logger.database' => LogChannels::DATABASE,
            'logger.media' => LogChannels::MEDIA,
            'logger.streaming' => LogChannels::STREAMING,
            'logger.transcoding' => LogChannels::TRANSCODING,
            'logger.auth' => LogChannels::AUTH,
            'logger.session' => LogChannels::SESSION,
            'logger.audit' => LogChannels::AUDIT,
            'logger.dlna' => LogChannels::DLNA,
            'logger.livetv' => LogChannels::LIVETV,
            'logger.plugins' => LogChannels::PLUGINS,
            'logger.hub' => LogChannels::HUB,
        ];
    }
}
