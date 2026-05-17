<?php

declare(strict_types=1);

namespace Phlex\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlex\Common\Container\ServiceProviderInterface;
use Phlex\Common\Database\ConnectionPool;
use Phlex\Common\Logger\LoggerFactory;
use Phlex\Common\Logger\LogChannels;
use Phlex\Common\Logger\StructuredLogger;
use Phlex\Common\Logger\AuditLogger;
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
 * @internal Phlex-internal service provider; consumed by ContainerFactory only.
 *
 * @package Phlex\Common\Container\Providers
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
        ];
    }
}
