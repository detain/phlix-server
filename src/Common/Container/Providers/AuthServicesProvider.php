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
use Phlix\Auth\AuthProviderBootstrapper;
use Phlix\Auth\AuthProviderRegistry;
use Phlix\Auth\DbLoginRateLimitStore;
use Phlix\Auth\JwtHandler;
use Phlix\Auth\ProviderManager;
use Phlix\Auth\TokenTtlPolicy;
use Phlix\Auth\UserIdentityRepository;
use Phlix\Auth\UserProfileManager;
use Phlix\Auth\UserRepository;
use Phlix\Auth\WatchHistory;
use Phlix\Auth\WebAuthn\WebAuthnCredentialRepository;
use Phlix\Auth\WebAuthn\WebAuthnManager;
use Phlix\Auth\WebAuthn\WebAuthnSettings;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Common\RateLimit\DbRateLimiter;
use Phlix\Common\RateLimit\RateLimiter;
use Phlix\Common\RateLimit\RateLimitProfiles;
use Phlix\Server\Http\Controllers\AuthController;
use Phlix\Server\Http\Controllers\AuthProviderController;
use Phlix\Server\Http\Controllers\WebAuthnController;
use Phlix\Stats\StatsCollector;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Workerman\MySQL\Connection;

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
        // The TTLs deliberately do NOT come from $appConfig. They used to be
        // read from $appConfig['jwt'], but `config/server.php` has never
        // composed a `jwt` key, so that branch was dead and the 3600/604800
        // fallbacks were ALWAYS what shipped. The lifetimes now live in
        // `config/auth.php` and are resolved LIVE per mint by TokenTtlPolicy,
        // so `auth.access_ttl` / `auth.refresh_ttl` apply without a restart.
        // The algorithm is NOT exposed as a setting (see plan §10 Phase 5
        // "DO NOT EXPOSE") and stays boot-only.
        $jwtConfig = is_array($appConfig['jwt'] ?? null) ? $appConfig['jwt'] : [];
        $jwtAlgorithm = is_string($jwtConfig['algorithm'] ?? null) ? $jwtConfig['algorithm'] : 'HS256';

        $builder->addDefinitions([
            JwtHandler::class => factory(
                static function (ContainerInterface $c) use ($jwtSecret, $jwtAlgorithm): JwtHandler {
                    // PHP-DI would skip the optional $ttlPolicy parameter during
                    // autowiring and silently leave the handler on its ctor
                    // defaults, making both settings inert (read-path class
                    // (g)). It must be passed explicitly.
                    //
                    // DEFERRED, not eager: SettingsRepository needs a live DB
                    // connection, and resolving it here would make JwtHandler —
                    // a pure crypto object — transitively un-resolvable in any
                    // context that has not run ConnectionPool::init() yet.
                    $ttlPolicy = TokenTtlPolicy::deferred(
                        static function () use ($c): ?SettingsRepository {
                            $resolved = $c->get(SettingsRepository::class);

                            return $resolved instanceof SettingsRepository ? $resolved : null;
                        }
                    );

                    return new JwtHandler(
                        $jwtSecret,
                        $jwtAlgorithm,
                        TokenTtlPolicy::DEFAULT_ACCESS_TTL,
                        TokenTtlPolicy::DEFAULT_REFRESH_TTL,
                        $ttlPolicy
                    );
                }
            ),

            UserRepository::class => autowire(),

            // S46: the `user_identities` join table (migration 092) repository.
            // Autowired — it needs only a Workerman MySQL Connection, resolved
            // the same way UserRepository's is. UserRepository dual-writes via a
            // local `new UserIdentityRepository($this->db)` (so it shares the
            // same connection/transaction and needs no ctor change), but this
            // entry is the canonical DI home the S45 account-linking controller
            // and S47 unlink will consume.
            UserIdentityRepository::class => autowire(),

            // `settings` is named explicitly: PHP-DI skips optional ctor params
            // during autowiring, which would leave the profile cap pinned at
            // MAX_PROFILES_PER_USER and make `auth.max_profiles` inert.
            UserProfileManager::class => autowire()
                ->constructorParameter('settings', get(SettingsRepository::class)),
            WatchHistory::class => autowire(),

            AuthProviderRegistry::class => autowire(),
            ProviderManager::class => autowire(),

            // S44: persists auth.oidc.enabled / auth.ldap.enabled and
            // (re-)registers enabled+configured providers into the per-worker
            // registry. Autowired — SettingsRepository, AuthProviderRegistry and
            // the two no-arg plugin entry classes all resolve without hints.
            AuthProviderBootstrapper::class => autowire(),

            AuthProviderController::class => autowire(),

            // S44: the (previously dead) OIDC authorize/callback controller.
            // `db` is named explicitly so it constructs the DB-backed
            // DbOidcStateStore — PHP-DI skips optional ctor params during
            // autowiring, which would otherwise leave $db null and fall back to
            // the per-worker InMemoryOidcStateStore. Under Workerman a
            // process-local OAuth-state store leaks/loses state across the
            // resident workers and concurrent requests (see CLAUDE.md
            // "Workerman Session Model"), so the shared oauth_state_store table
            // is mandatory for the PKCE/CSRF state to survive the round trip.
            \Phlix\Plugins\Oidc\Controller\OidcCallbackController::class => autowire()
                ->constructorParameter('db', get(\Workerman\MySQL\Connection::class))
                // S44 Finding 3: request-path self-heal of the per-worker
                // provider registry from the persisted `auth.oidc.enabled` flag.
                // PHP-DI skips optional ctor params during autowiring, so bind it
                // explicitly — without it the controller can 503 on workers that
                // booted before OIDC was enabled.
                ->constructorParameter('bootstrapper', get(AuthProviderBootstrapper::class))
                // S45: the account-link store the OIDC callback's link branch
                // writes to. Optional ctor param (PHP-DI skips it during
                // autowiring), so bind explicitly — without it a link flow 503s.
                ->constructorParameter('identities', get(UserIdentityRepository::class)),

            // S45/S47: the authenticated account-linking endpoints (list, link
            // LDAP, and the S47 DELETE unlink). `bootstrapper` + `userRepository`
            // are optional ctor params (PHP-DI skips optional params during
            // autowiring), so bind them explicitly — `bootstrapper` for the
            // request-path LDAP self-heal, `userRepository` for the unlink
            // last-sign-in-method safety guard (without it that guard fails safe
            // and would refuse removing the last identity). `identities` +
            // `registry` autowire.
            \Phlix\Server\Http\Controllers\AccountLinkController::class => autowire()
                ->constructorParameter('bootstrapper', get(AuthProviderBootstrapper::class))
                ->constructorParameter('userRepository', get(UserRepository::class)),

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
                ->constructorParameter('settingsRepository', get(SettingsRepository::class))
                // S44: bridge to the external-provider system so an `ldap:`-prefixed
                // login reaches ProviderManager's prefix dispatch. PHP-DI skips
                // optional ctor params during autowiring, so without naming it
                // AuthManager::$providerManager stays null and loginWithProvider()
                // throws "ProviderManager is not configured" — LDAP login would 500.
                ->constructorParameter('providerManager', get(ProviderManager::class))
                // SV-1.10: the central DB-backed login rate-limit store. Named
                // for the same PHP-DI reason — without it, AuthManager falls back
                // to the UNBOUNDED per-worker static array (no sweep/LRU/cap, and
                // ×workers), leaving brute-force protection weak and leaky. With
                // it wired, the brute-force budget is unified across workers and
                // bounded by TTL cleanup (login_rate_limit table, migration 074).
                ->constructorParameter('loginRateLimitStore', get(DbLoginRateLimitStore::class)),

            // SV-4.15(f): register/refresh get their OWN per-surface DB-backed
            // rate limiters. AuthController is otherwise autowired; the limiter
            // ctor params are optional (PHP-DI skips optional params during
            // autowiring), so each must be bound EXPLICITLY to its
            // RateLimitProfiles container id — an unbound limiter would silently
            // stay null and leave the surface unprotected.
            AuthController::class => autowire()
                ->constructorParameter('registerLimiter', get(RateLimitProfiles::REGISTER))
                ->constructorParameter('refreshLimiter', get(RateLimitProfiles::REFRESH))
                // S44 Finding 3: request-path self-heal of the per-worker provider
                // registry from the persisted `auth.ldap.enabled` flag, so the
                // `ldap:` login path doesn't 503 on workers that booted before LDAP
                // was enabled. PHP-DI skips optional ctor params during autowiring,
                // so bind it explicitly.
                ->constructorParameter('providerBootstrapper', get(AuthProviderBootstrapper::class)),

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
            // SV-4.15(f): the start/finish WebAuthn authentication ceremonies get
            // their own per-surface DB-backed limiters, bound explicitly to their
            // RateLimitProfiles container ids for the same optional-param reason
            // as AuthController above.
            WebAuthnController::class => autowire()
                ->constructorParameter('startAuthLimiter', get(RateLimitProfiles::WEBAUTHN_START))
                ->constructorParameter('finishAuthLimiter', get(RateLimitProfiles::WEBAUTHN_FINISH)),
        ]);

        $this->registerRateLimiters($builder, $appConfig);
    }

    /**
     * SV-4.15(d): register ONE rate-limiter instance per surface under its
     * {@see RateLimitProfiles} container id, with `{max, window}` sourced from
     * `config/server.php`'s `rate_limit` section (falling back to
     * {@see RateLimitProfiles::defaults()} per-key).
     *
     * Each id resolves to a DISTINCT instance via an explicit `factory()`
     * closure — the server registers Common services with explicit factories,
     * never autowiring, to avoid the PHP-DI optional-ctor-param landmine (a
     * skipped `$cap`/`$clock` default is silently accepted, but a skipped
     * required scalar throws).
     *
     * Backend per surface follows {@see RateLimitProfiles::isDbBacked()}:
     *
     * - `register` / `refresh` / `webauthn_start` / `webauthn_finish` →
     *   {@see DbRateLimiter} (shared, DB-backed, migration 085) so the
     *   brute-force counter is TRUE-global across ALL HTTP workers. The
     *   {@see Connection} injected is the SAME pooled `mysql` one
     *   {@see \Phlix\Auth\DbLoginRateLimitStore} uses (bound to
     *   `Connection::class` in `CoreServicesProvider`), NOT a dedicated `txn`
     *   connection: these are single-statement upsert/select/delete calls, not a
     *   multi-statement transaction.
     * - `jwks` / `ws_connect` → worker-local in-memory {@see RateLimiter}
     *   (`jwks` is a cache-frontable public DoS surface; the `:8097` WS worker
     *   runs `count=1`, so per-worker == global there).
     *
     * `login` is intentionally NOT registered here — it keeps its own
     * {@see \Phlix\Auth\DbLoginRateLimitStore} (migration 074), wired into
     * {@see AuthManager} above.
     *
     * @param ContainerBuilder<\DI\Container> $builder
     * @param array<string, mixed>            $appConfig
     *
     * @return void
     */
    private function registerRateLimiters(ContainerBuilder $builder, array $appConfig): void
    {
        $section = $appConfig['rate_limit'] ?? null;
        $rateLimit = is_array($section) ? $section : [];

        $definitions = [];

        foreach (RateLimitProfiles::defaults() as $id => $spec) {
            $surfaceRaw = $rateLimit[$spec['key']] ?? null;
            $surface = is_array($surfaceRaw) ? $surfaceRaw : [];

            // Clamped, not trusted. `$appConfig['rate_limit']` now carries
            // admin-editable `server.rate_limit.*` overrides (via
            // EffectiveConfig's boot overlay), and a `max` of 0 would reject
            // every request to the surface — `refresh` at 0 signs out the whole
            // install as access tokens expire. RateLimitProfiles::MIN_MAX is
            // the lock-out fail-safe.
            $max = RateLimitProfiles::clampMax(self::intOr($surface, 'max', $spec['max']));
            $window = RateLimitProfiles::clampWindow(self::intOr($surface, 'window', $spec['window']));

            if (RateLimitProfiles::isDbBacked($id)) {
                // Shared DB-backed limiter — TRUE-global across every HTTP worker.
                // The closure captures $window/$max BY VALUE at definition time so
                // each surface keeps its own thresholds and its own instance; the
                // Connection is resolved from the container (bound in
                // CoreServicesProvider), mirroring DbLoginRateLimitStore.
                $definitions[$id] = factory(
                    static fn (Connection $db): DbRateLimiter => new DbRateLimiter($db, $window, $max)
                );
                continue;
            }

            // Worker-local in-memory limiter (jwks / ws_connect). Arrow fn
            // captures $window/$max BY VALUE, so each surface gets its own
            // thresholds and its own instance.
            $definitions[$id] = factory(
                static fn (): RateLimiter => new RateLimiter($window, $max)
            );
        }

        $builder->addDefinitions($definitions);
    }

    /**
     * Read an int from a `rate_limit` sub-array, coercing numeric strings and
     * falling back to `$default` for absent/non-numeric values.
     *
     * @param array<array-key, mixed> $config
     *
     * @return int
     */
    private static function intOr(array $config, string $key, int $default): int
    {
        /**
         * @var mixed $value
         */
        $value = $config[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        return $default;
    }
}
