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
use Phlix\Auth\AuthManager;
use Phlix\Auth\AuthProviderRegistry;
use Phlix\Auth\JwtHandler;
use Phlix\Auth\ProviderManager;
use Phlix\Auth\UserProfileManager;
use Phlix\Auth\UserRepository;
use Phlix\Auth\WatchHistory;
use Phlix\Auth\WebAuthn\WebAuthnCredentialRepository;
use Phlix\Auth\WebAuthn\WebAuthnManager;
use Phlix\Auth\WebAuthn\WebAuthnSettings;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Server\Http\Controllers\AuthProviderController;
use Phlix\Server\Http\Controllers\WebAuthnController;
use Phlix\Stats\StatsCollector;
use Psr\EventDispatcher\EventDispatcherInterface;

use function DI\autowire;
use function DI\factory;
use function DI\get;

/**
 * Registers the auth subsystem: JWT handler, user repository, auth
 * manager, profiles, watch history.
 *
 * The JWT handler is configured with a factory that reads JWT_SECRET
 * from the environment, defaulting to the existing
 * "default-secret-change-me" sentinel from public/index.php to preserve
 * parity for local installs that never set the env var.
 *
 * @internal Phlix-internal service provider.
 *
 * @package Phlix\Common\Container\Providers
 * @since 0.10.0
 */
final class AuthServicesProvider implements ServiceProviderInterface
{
    /**
     * Default JWT secret used when JWT_SECRET is not present in the
     * environment. Matches the historical literal from public/index.php
     * so existing deployments continue to authenticate while we migrate
     * to the container.
     */
    public const DEFAULT_JWT_SECRET = 'default-secret-change-me';

    /**
     * Boot-time guard: refuse to serve when the JWT signing key is missing or
     * still the shipped {@see self::DEFAULT_JWT_SECRET} sentinel.
     *
     * The secret protects far more than JWTs: {@see \Phlix\Auth\SignedUrl::fromEnv()}
     * HMAC-derives the media signed-URL key from `JWT_SECRET` whenever
     * `PHLIX_SIGNED_URL_SECRET` is unset, so a default/empty `JWT_SECRET` means
     * forgeable auth tokens AND forgeable stream URLs. We therefore fail fast at
     * boot (called from `start.php` before `Worker::runAll()`) rather than come up
     * with a guessable key.
     *
     * The check is skipped only in the test environment — when
     * `PHLIX_ENV === 'test'` or a PHPUnit runtime constant is defined — so the
     * suite (which never sets a real secret) is unaffected.
     *
     * @param string|false|null $secret Override for the configured secret; defaults
     *                                  to `getenv('JWT_SECRET')`. A test seam — the
     *                                  string `false`/`null` model an unset env var.
     *
     * @throws \RuntimeException When the secret is empty or equals the sentinel in
     *                           a non-test environment.
     *
     * @return void
     *
     * @since 0.55.0
     */
    public static function assertSecretConfigured(string|false|null $secret = null): void
    {
        if (self::isTestEnvironment()) {
            return;
        }

        $value = (string) ($secret ?? getenv('JWT_SECRET') ?: '');

        if ($value === '') {
            throw new \RuntimeException(
                'CRITICAL: JWT_SECRET is not set. Refusing to start with an empty signing key — '
                . 'JWTs and media signed URLs would be unsigned/forgeable. '
                . 'Set a high-entropy JWT_SECRET environment variable (e.g. `openssl rand -hex 32`) '
                . 'before starting the server.'
            );
        }

        if ($value === self::DEFAULT_JWT_SECRET) {
            throw new \RuntimeException(
                'CRITICAL: JWT_SECRET is still the shipped default ("' . self::DEFAULT_JWT_SECRET . '"). '
                . 'Refusing to start with a guessable signing key — JWTs and media signed URLs would be forgeable. '
                . 'Set a unique high-entropy JWT_SECRET environment variable (e.g. `openssl rand -hex 32`) '
                . 'before starting the server.'
            );
        }
    }

    /**
     * Whether the secret guard should be bypassed because the process is running
     * under the test harness.
     *
     * @return bool
     */
    private static function isTestEnvironment(): bool
    {
        return getenv('PHLIX_ENV') === 'test'
            || defined('PHPUNIT_COMPOSER_INSTALL')
            || defined('__PHPUNIT_PHAR__');
    }

    /**
     * Register the auth bindings.
     *
     * @param ContainerBuilder<\DI\Container> $builder
     * @param array<string, mixed>            $appConfig
     *
     * @return void
     *
     * @since 0.10.0
     */
    public function register(ContainerBuilder $builder, array $appConfig): void
    {
        $jwtSecret = (string)(getenv('JWT_SECRET') ?: self::DEFAULT_JWT_SECRET);
        $jwtConfig = is_array($appConfig['jwt'] ?? null) ? $appConfig['jwt'] : [];
        $jwtTtl = is_numeric($jwtConfig['ttl'] ?? null) ? (int)$jwtConfig['ttl'] : 3600;
        $jwtRefreshTtl = is_numeric($jwtConfig['refresh_ttl'] ?? null)
            ? (int)$jwtConfig['refresh_ttl']
            : 604800;
        $jwtAlgorithm = is_string($jwtConfig['algorithm'] ?? null) ? $jwtConfig['algorithm'] : 'HS256';

        $builder->addDefinitions([
            JwtHandler::class => factory(
                static function () use ($jwtSecret, $jwtAlgorithm, $jwtTtl, $jwtRefreshTtl): JwtHandler {
                    return new JwtHandler($jwtSecret, $jwtAlgorithm, $jwtTtl, $jwtRefreshTtl);
                }
            ),

            UserRepository::class => autowire(),
            UserProfileManager::class => autowire(),
            WatchHistory::class => autowire(),

            AuthProviderRegistry::class => autowire(),
            ProviderManager::class => autowire(),

            AuthProviderController::class => autowire(),

            // `statsCollector` is wired so successful logins/logouts land in
            // stats_user_activity (the admin dashboard activity feed). PHP-DI
            // skips optional ctor params with defaults during autowiring, so it
            // must be named explicitly — without this it stays null and no
            // activity is recorded.
            AuthManager::class => autowire()
                ->constructorParameter('logger', get('logger.auth'))
                ->constructorParameter('eventDispatcher', get(EventDispatcherInterface::class))
                ->constructorParameter('db', get(\Workerman\MySQL\Connection::class))
                ->constructorParameter('statsCollector', get(StatsCollector::class))
                // Wired so register() honours the `auth.signup_mode` setting
                // (open|approval|disabled). PHP-DI skips optional ctor params
                // with defaults during autowiring, so it must be named.
                ->constructorParameter('settingsRepository', get(SettingsRepository::class)),

            // WebAuthn — rpId/rpName/rpOrigin come from $appConfig['webauthn'].
            // Without this factory, php-di would try to autowire string scalars
            // and fail with "Parameter $rpId of __construct() has no value defined".
            WebAuthnSettings::class => factory(
                static function () use ($appConfig): WebAuthnSettings {
                    $cfg = is_array($appConfig['webauthn'] ?? null) ? $appConfig['webauthn'] : [];
                    return WebAuthnSettings::fromConfig($cfg);
                }
            ),
            WebAuthnCredentialRepository::class => autowire(),
            WebAuthnManager::class => autowire(),
            WebAuthnController::class => autowire(),
        ]);
    }
}
