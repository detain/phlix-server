<?php

/**
 * Phlix media server component: Auth.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Auth;

use Phlix\Admin\SettingsRepository;
use Phlix\Auth\Dto\UserRow;
use Phlix\Shared\Events\Auth\UserCreated;
use Phlix\Shared\Events\Auth\UserLoggedIn;
use Phlix\Shared\Events\Auth\UserLoggedOut;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Stats\StatsCollector;
use Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;
use Workerman\MySQL\Connection;

/**
 * Authentication manager for user registration, login, and token management.
 *
 * This class orchestrates all authentication-related operations including
 * user registration, login with credential verification, JWT token generation
 * and validation, and session management.
 *
 * @author Phlix Team
 * @version 1.0.0
 * @description Handles user authentication workflows including registration,
 *              login, token refresh, and validation for the Phlix Media Server.
 * @see JwtHandler For JWT token creation and validation
 * @see UserRepository For user data access and management
 * @see AuditLogger For security audit logging
 */
class AuthManager
{
    /**
     * Display name given to the first profile created automatically at
     * signup (S81: `AuthManager::register()` and the external-provider
     * create path both call `UserProfileManager::create()` with this name).
     */
    public const FIRST_PROFILE_NAME = 'Main';

    private const RATE_LIMIT_MAX_ATTEMPTS = 5;
    private const RATE_LIMIT_WINDOW_SECONDS = 900; // 15 minutes

    /**
     * Hard cap on the number of IPs held in the static in-memory fallback
     * store. The fallback is only reachable when no DbLoginRateLimitStore is
     * injected (tests / legacy callers) — production wires the DB store via
     * AuthServicesProvider (SV-1.10) — but we still bound it so the process
     * memory cannot grow without limit if the fallback is ever exercised in a
     * resident worker. When the cap is reached, expired entries are swept and,
     * if still full, the oldest window is evicted.
     */
    private const RATE_LIMIT_FALLBACK_MAX_IPS = 10000;

    /**
     * Central login rate limit store.
     *
     * When a DbLoginRateLimitStore is injected, the rate limit is shared
     * across all workers (×1 budget, not ×N workers). When null (unit tests /
     * legacy callers), falls back to the in-memory store below.
     *
     * @var DbLoginRateLimitStore|null
     */
    private ?DbLoginRateLimitStore $loginRateLimitStore;

    /**
     * Profile scope resolver, used to stamp the {@see JwtHandler::CLAIM_PROFILE_ID}
     * claim onto freshly minted tokens (S80).
     *
     * ⚠ Optional ONLY because this constructor already has nine optional
     * parameters and dozens of tests build the manager by hand. PHP-DI's
     * `autowire()` skips optional parameters, so it MUST stay named explicitly in
     * `AuthServicesProvider` — exactly as `settingsRepository`, `providerManager`
     * and `loginRateLimitStore` above it are, and for the same reason. Left null,
     * every token would be minted without a profile claim and every session would
     * silently fall back to the account-wide `is_active` flag, i.e. S80 would be
     * inert with a fully green suite.
     * `tests/Unit/Auth/AuthManagerProfileClaimWiringGuardTest` is the net under
     * that: it resolves this class from the REAL container and asserts the
     * property is populated.
     *
     * @var UserProfileManager|null
     */
    private ?UserProfileManager $profileManager;

    /**
     * In-memory fallback rate limit store used when no DbLoginRateLimitStore
     * is injected (tests / legacy callers).
     *
     * @var array<string, array{attempts: int, reset_at: int}>
     */
    private static array $rateLimitStore = [];

    /** @var UserRepository User data access repository */
    private UserRepository $userRepository;

    /** @var JwtHandler JWT token handler for access and refresh tokens */
    private JwtHandler $jwtHandler;

    /** @var AuditLogger Security audit logger for login/logout events */
    private AuditLogger $auditLogger;

    /** @var StructuredLogger General application logger */
    private StructuredLogger $logger;

    /** @var EventDispatcherInterface|null PSR-14 dispatcher for auth lifecycle events. */
    private ?EventDispatcherInterface $eventDispatcher;

    /**
     * Optional handle to the underlying MySQL connection so register()
     * can wrap the first-user admin promotion (create() + setAdmin()) in
     * a transaction. When null (unit tests / legacy callers), register()
     * falls back to the non-transactional execution and still works.
     *
     * @var Connection|null
     */
    private ?Connection $db;

    /** @var ProviderManager|null Bridge to external auth providers (OIDC, LDAP, etc.). */
    private ?ProviderManager $providerManager;

    /**
     * Optional stats collector. When wired, successful logins and logouts are
     * recorded into stats_user_activity, which feeds the admin dashboard's
     * activity feed. Null in unit tests / legacy callers (recording no-ops).
     *
     * @var StatsCollector|null
     */
    private ?StatsCollector $statsCollector;

    /**
     * Optional server-settings store used to read the `auth.signup_mode`
     * effective value (open|approval|disabled). Null in unit tests / legacy
     * callers, in which case registration falls back to the historical
     * always-open behaviour.
     *
     * @var SettingsRepository|null
     */
    private ?SettingsRepository $settingsRepository;

    /**
     * Effective password-strength policy, built from
     * {@see $settingsRepository} so `auth.password.min_length` applies LIVE.
     *
     * @var PasswordPolicy
     */
    private PasswordPolicy $passwordPolicy;

    /**
     * In-worker TTL cache for user status lookups.
     *
     * Avoids a PK lookup on user_repository.getStatus() for every authenticated
     * request when the same user makes multiple concurrent requests. The short
     * TTL (5 seconds) means status revocation takes effect within a few seconds
     * rather than immediately (see {@see self::invalidateUserStatusCache()} for
     * the in-process path that makes a status change take effect immediately,
     * without waiting for the TTL), which is acceptable for the "disable
     * account" use-case while significantly reducing DB load.
     *
     * Bounded by {@see self::USER_STATUS_CACHE_MAX}: insertion order doubles as
     * an LRU (a cache hit re-inserts the entry at the end via unset()+reassign,
     * so the map's key order is always oldest-first) exactly like
     * {@see \Phlix\Media\Library\ItemRepository::$genreFacetCache} — see that
     * property's docblock for why the unset()-before-reassign step matters for
     * eviction correctness (a plain value overwrite of an existing key leaves
     * it in its original position, not the end).
     *
     * @var array<string, array{status: string, cachedAt: int}> keyed by userId
     */
    private array $userStatusCache = [];

    /** User status cache TTL in nanoseconds (5 seconds). */
    private const USER_STATUS_CACHE_TTL_NS = 5_000_000_000;

    /**
     * Hard cap on distinct user IDs held in {@see $userStatusCache}. Without a
     * cap, a single long-lived worker would accumulate one entry per distinct
     * user that ever authenticated against it for the lifetime of the
     * process — unbounded growth on a busy, long-running resident worker.
     * When the cap is reached, the oldest (least-recently-used) entry is
     * evicted to make room for the new one.
     */
    private const USER_STATUS_CACHE_MAX = 5000;

    /**
     * Create a new AuthManager instance.
     *
     * @param UserRepository $userRepository User data access repository
     * @param JwtHandler $jwtHandler JWT token handler
     * @param AuditLogger $auditLogger Security audit logger
     * @param StructuredLogger|null $logger Optional application logger
     * @param EventDispatcherInterface|null $eventDispatcher Optional PSR-14 dispatcher;
     *                                       when supplied,
     *                                       {@see UserCreated},
     *                                       {@see UserLoggedIn}, and
     *                                       {@see UserLoggedOut} are published from
     *                                       the matching lifecycle methods.
     * @param Connection|null $db Optional database connection for transactions.
     * @param ProviderManager|null $providerManager Optional bridge to external providers.
     * @param StatsCollector|null $statsCollector Optional stats collector; when
     *                                       supplied, successful logins/logouts
     *                                       are recorded for the admin dashboard.
     * @param SettingsRepository|null $settingsRepository Optional server-settings
     *                                       store; when supplied, register() honours
     *                                       the `auth.signup_mode` setting
     *                                       (open|approval|disabled).
     * @param DbLoginRateLimitStore|null $loginRateLimitStore Optional central DB-backed
     *                                       rate limit store; when supplied, the login
     *                                       throttle is shared across all workers (not
     *                                       multiplied by worker count) and bounded by
     *                                       TTL cleanup. When null (tests / legacy),
     *                                       falls back to an in-memory per-worker store.
     *
     * @example
     * ```php
     * $authManager = new AuthManager(
     *     new UserRepository($db),
     *     new JwtHandler($secretKey),
     *     new AuditLogger($config)
     * );
     * ```
     */
    public function __construct(
        UserRepository $userRepository,
        JwtHandler $jwtHandler,
        AuditLogger $auditLogger,
        ?StructuredLogger $logger = null,
        ?EventDispatcherInterface $eventDispatcher = null,
        ?Connection $db = null,
        ?ProviderManager $providerManager = null,
        ?StatsCollector $statsCollector = null,
        ?SettingsRepository $settingsRepository = null,
        ?DbLoginRateLimitStore $loginRateLimitStore = null,
        ?UserProfileManager $profileManager = null
    ) {
        $this->userRepository = $userRepository;
        $this->jwtHandler = $jwtHandler;
        $this->auditLogger = $auditLogger;
        $this->logger = $logger ?? $this->createDefaultLogger();
        $this->eventDispatcher = $eventDispatcher;
        $this->db = $db;
        $this->providerManager = $providerManager;
        $this->statsCollector = $statsCollector;
        $this->settingsRepository = $settingsRepository;
        $this->loginRateLimitStore = $loginRateLimitStore;
        $this->profileManager = $profileManager;
        // Built from the already-explicitly-wired settings store rather than
        // taken as its own optional ctor param: PHP-DI skips optional params
        // during autowiring, so an unnamed PasswordPolicy param would silently
        // arrive null and the min-length setting would go inert (class (g)).
        $this->passwordPolicy = new PasswordPolicy($settingsRepository);
    }

    /**
     * The password-strength policy backing `auth.password.min_length`.
     *
     * Exposed so callers holding an AuthManager (notably `AdminUserController`)
     * can enforce the SAME effective policy rather than re-deriving it — the
     * three duplicated `strlen($password) < 8` literals this replaced are
     * exactly what made the setting half-effective before.
     *
     * @return PasswordPolicy The effective policy.
     *
     * @since 1.3.0
     */
    public function passwordPolicy(): PasswordPolicy
    {
        return $this->passwordPolicy;
    }

    /**
     * Gets the cached user status or fetches from repository if cache miss/expired.
     *
     * Uses a short TTL (5 seconds) to reduce DB lookups for every authenticated
     * request while still allowing near-instant account revocation to take effect.
     *
     * @param string $userId The user ID to look up
     * @return string The user status ('active', 'disabled', 'pending', etc.)
     */
    private function getCachedUserStatus(string $userId): string
    {
        $now = hrtime(true);

        // Check cache for valid (non-expired) entry
        if (isset($this->userStatusCache[$userId])) {
            $entry = $this->userStatusCache[$userId];
            if (($now - $entry['cachedAt']) < self::USER_STATUS_CACHE_TTL_NS) {
                // LRU touch: move to the MRU (end) position so a hot user's
                // entry outlives cold ones when the cache is at its cap. Plain
                // key lookups leave a PHP array's key order untouched, so this
                // unset()+reassign is required for the eviction below to be a
                // genuine LRU rather than pure insertion order.
                unset($this->userStatusCache[$userId]);
                $this->userStatusCache[$userId] = $entry;
                return $entry['status'];
            }
        }

        // Cache miss or expired - fetch from DB
        $status = $this->userRepository->getStatus($userId) ?? 'active';

        // Store in cache. unset() first (see the LRU-touch comment above) so a
        // stale-entry recompute is reinserted at the MRU end rather than left
        // in its original array position.
        unset($this->userStatusCache[$userId]);
        $this->userStatusCache[$userId] = [
            'status' => $status,
            'cachedAt' => (int) $now,
        ];

        // Bound the cache: evict the oldest (least-recently-used) entry once
        // over the cap so a single worker cannot accumulate one entry per
        // distinct user forever.
        if (count($this->userStatusCache) > self::USER_STATUS_CACHE_MAX) {
            $oldest = array_key_first($this->userStatusCache);
            if ($oldest !== null) {
                unset($this->userStatusCache[$oldest]);
            }
        }

        return $status;
    }

    /**
     * Clears the cached user status for a user (call when status changes).
     *
     * Called by {@see \Phlix\Server\Http\Controllers\Admin\AdminUserController}
     * after any admin action that changes a user's `status` column (approve,
     * disable, reject/delete) so an in-process status change is reflected on
     * THIS worker's very next request for that user, instead of waiting out
     * the {@see self::USER_STATUS_CACHE_TTL_NS} TTL. Other resident workers in
     * the same process pool do not share this cache (it is in-worker only,
     * exactly like {@see UserRepository::$statusCacheById}) and converge only
     * via the TTL — the 5-second window is the ceiling on cross-worker
     * revocation latency, immediate invalidation is only possible for
     * same-worker requests.
     *
     * @param string $userId The user ID to invalidate
     * @return void
     */
    public function invalidateUserStatusCache(string $userId): void
    {
        unset($this->userStatusCache[$userId]);
    }

    /**
     * Resolve the effective signup mode from the settings store.
     *
     * @return string One of 'open', 'approval', 'disabled'. Defaults to
     *                'approval' (the secure default) when the setting is
     *                unreadable or holds an unexpected value. Falls back to
     *                'open' only when no settings store is wired (legacy /
     *                unit-test callers) to preserve historical behaviour.
     */
    private function resolveSignupMode(): string
    {
        if ($this->settingsRepository === null) {
            return 'open';
        }

        try {
            $mode = $this->settingsRepository->getEffective('auth.signup_mode');
        } catch (Throwable $e) {
            $this->logger->warning('Failed to read auth.signup_mode; defaulting to approval', [
                'error' => $e->getMessage(),
            ]);
            return 'approval';
        }

        if (is_string($mode) && in_array($mode, ['open', 'approval', 'disabled'], true)) {
            return $mode;
        }

        return 'approval';
    }

    /**
     * The shared AUTH-channel logger — not a private one in a temp directory.
     *
     * The old body `mkdir()`ed a `sys_get_temp_dir()/phlix_auth_<uniqid>`
     * directory on every construction and pointed a private `StructuredLogger`
     * at a log file inside it — a per-instance leak that survived for the life
     * of the worker. `LoggerFactory::get()` returns one cached instance per
     * channel, so the whole family shares a single logger.
     *
     * @return StructuredLogger The shared AUTH channel logger, routed by
     *         `config/logger.php` to `.logs/app.log` and `.logs/error.log` —
     *         an install-dir destination that creates no directory.
     */
    private function createDefaultLogger(): StructuredLogger
    {
        return LoggerFactory::get(LogChannels::AUTH);
    }

    /**
     * Get the client IP address for rate limiting.
     */
    private function getClientIp(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        return is_string($ip) ? $ip : '127.0.0.1';
    }

    /**
     * Record a user-activity stat (login/logout) for the admin dashboard.
     *
     * No-ops when no {@see StatsCollector} is wired. Any failure is logged and
     * swallowed so that statistics collection can never break authentication.
     *
     * @param string      $userId       UUID of the user.
     * @param string      $activityType 'login' or 'logout'.
     * @param string|null $ipAddress    Client IP when known.
     */
    private function recordActivity(string $userId, string $activityType, ?string $ipAddress): void
    {
        if ($this->statsCollector === null || $userId === '') {
            return;
        }
        try {
            $this->statsCollector->recordUserActivity($userId, $activityType, $ipAddress);
        } catch (Throwable $e) {
            $this->logger->warning('Failed to record user activity stat', [
                'activity_type' => $activityType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if the client IP has exceeded the rate limit.
     *
     * Uses the injected DbLoginRateLimitStore when available (production, shared
     * across all workers). Falls back to the static in-memory store for tests /
     * legacy callers that did not inject a store.
     *
     * @throws RateLimitException When rate limit is exceeded
     */
    private function checkRateLimit(string $ip): void
    {
        if ($this->loginRateLimitStore !== null) {
            $this->loginRateLimitStore->check($ip, self::RATE_LIMIT_MAX_ATTEMPTS);
            return;
        }

        // Fallback to in-memory store (tests / legacy)
        $now = time();

        if (!isset(self::$rateLimitStore[$ip])) {
            return;
        }

        // Read-only copy: nothing below writes through $record (the cleanup
        // unsets the store entry directly), so the reference this used to take
        // was not load-bearing.
        $record = self::$rateLimitStore[$ip];

        // Clean up expired records
        if ($record['reset_at'] <= $now) {
            unset(self::$rateLimitStore[$ip]);
            return;
        }

        if ($record['attempts'] >= self::RATE_LIMIT_MAX_ATTEMPTS) {
            throw new RateLimitException(
                resetAt: $record['reset_at'],
                remaining: 0
            );
        }
    }

    /**
     * Record a failed authentication attempt for rate limiting.
     *
     * Uses the injected DbLoginRateLimitStore when available (production, shared
     * across all workers). Falls back to the static in-memory store for tests /
     * legacy callers that did not inject a store.
     */
    private function recordFailedAttempt(string $ip): void
    {
        if ($this->loginRateLimitStore !== null) {
            $this->loginRateLimitStore->recordFailedAttempt($ip);
            return;
        }

        // Fallback to in-memory store (tests / legacy)
        $now = time();

        if (!isset(self::$rateLimitStore[$ip])) {
            $this->boundFallbackStore($now);
            self::$rateLimitStore[$ip] = [
                'attempts' => 0,
                'reset_at' => $now + self::RATE_LIMIT_WINDOW_SECONDS,
            ];
        }

        // The reference IS load-bearing here — the window reset and the
        // ++ below must write back into the static store.
        /**
         * @psalm-suppress UnsupportedPropertyReferenceUsage Psalm cannot model a
         *   reference taken into a static property; the code is correct.
         */
        $record = &self::$rateLimitStore[$ip];

        // Reset if window has expired
        if ($record['reset_at'] <= $now) {
            $record = [
                'attempts' => 0,
                'reset_at' => $now + self::RATE_LIMIT_WINDOW_SECONDS,
            ];
        }

        $record['attempts']++;
    }

    /**
     * Bound the static in-memory fallback store before inserting a new IP.
     *
     * Sweeps expired windows first; if the store is still at the hard cap
     * ({@see self::RATE_LIMIT_FALLBACK_MAX_IPS}), evicts the entry with the
     * earliest reset time (closest to expiry) so a single resident worker can
     * never accumulate unbounded IP records. Only reachable when no
     * DbLoginRateLimitStore is injected (tests / legacy).
     *
     * @param int $now Current Unix timestamp.
     */
    private function boundFallbackStore(int $now): void
    {
        if (count(self::$rateLimitStore) < self::RATE_LIMIT_FALLBACK_MAX_IPS) {
            return;
        }

        foreach (self::$rateLimitStore as $key => $record) {
            if ($record['reset_at'] <= $now) {
                unset(self::$rateLimitStore[$key]);
            }
        }

        if (count(self::$rateLimitStore) < self::RATE_LIMIT_FALLBACK_MAX_IPS) {
            return;
        }

        $oldestKey = null;
        $oldestReset = PHP_INT_MAX;
        foreach (self::$rateLimitStore as $key => $record) {
            if ($record['reset_at'] < $oldestReset) {
                $oldestReset = $record['reset_at'];
                $oldestKey = $key;
            }
        }
        if ($oldestKey !== null) {
            unset(self::$rateLimitStore[$oldestKey]);
        }
    }

    /**
     * Clear rate limit data for a client IP after successful auth.
     *
     * Uses the injected DbLoginRateLimitStore when available (production, shared
     * across all workers). Falls back to the static in-memory store for tests /
     * legacy callers that did not inject a store.
     */
    private function clearRateLimit(string $ip): void
    {
        if ($this->loginRateLimitStore !== null) {
            $this->loginRateLimitStore->clear($ip);
            return;
        }

        // Fallback to in-memory store (tests / legacy)
        unset(self::$rateLimitStore[$ip]);
    }

    /**
     * Clears the process-wide login rate-limit store.
     *
     * The static in-memory store is only used when no DbLoginRateLimitStore is
     * injected (tests / legacy callers). When a DbLoginRateLimitStore is in use
     * (production), individual IPs are cleared via clearRateLimit() after a
     * successful login, and expired entries are swept by the store's TTL cleanup.
     *
     * In the test runner, the static store persists across unrelated test cases
     * (they all key on the default 127.0.0.1 IP), so with executionOrder="random"
     * a later auth test can inherit a tripped limiter and fail intermittently. Tests
     * call this in setUp() to start from a clean slate.
     */
    public static function resetRateLimitStore(): void
    {
        self::$rateLimitStore = [];
    }

    /**
     * Register a new user account.
     *
     * Creates a new user with the provided credentials and returns
     * authentication tokens for immediate login.
     *
     * @param string $username Unique username (3-50 characters)
     * @param string $email User's email address (must be valid format)
     * @param string $password User's password (minimum 8 characters)
     *
     * @return array<string, mixed> When the account is created active (open mode
     *         or the first-user bootstrap), the authentication response with
     *         access_token, refresh_token, token_type, expires_in and user data.
     *         When the `auth.signup_mode` setting is 'approval' (and this is not
     *         the first user), the account is created with status='pending', NO
     *         tokens are issued, and the response is
     *         `['status' => 'pending', 'message' => '...', 'user' => null]`.
     *
     * @throws \InvalidArgumentException If validation fails:
     *         - Username must be 3-50 characters
     *         - Email must be valid format
     *         - Password must be at least 8 characters
     *         - Username already taken
     *         - Email already registered
     * @throws SignupDisabledException When `auth.signup_mode` is 'disabled' and
     *         this is not the first user (no account is created).
     *
     * @example
     * ```php
     * $result = $authManager->register('john_doe', 'john@example.com', 'secure_pass123');
     * // Returns: ['access_token' => '...', 'refresh_token' => '...', 'user' => [...]]
     * ```
     */
    public function register(string $username, string $email, string $password): array
    {
        // Validate
        if (strlen($username) < 3 || strlen($username) > 50) {
            throw new \InvalidArgumentException('Username must be 3-50 characters');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid email format');
        }

        $passwordError = $this->passwordPolicy->validate($password);
        if ($passwordError !== null) {
            throw new \InvalidArgumentException($passwordError);
        }

        // Check uniqueness
        if ($this->userRepository->usernameExists($username)) {
            throw new \InvalidArgumentException('Username already taken');
        }

        if ($this->userRepository->emailExists($email)) {
            throw new \InvalidArgumentException('Email already registered');
        }

        // Detect first-user case BEFORE create() so we don't race
        // ourselves: the row we are about to insert must not count as
        // a "prior" user. See Step A.5 for the admin-bootstrap policy.
        $isFirstUser = $this->userRepository->countUsers() === 0;

        // Resolve the signup gate (S1). The first user ALWAYS bootstraps as an
        // active admin regardless of mode, so the gate only applies to Nth users.
        $signupMode = $this->resolveSignupMode();
        if (!$isFirstUser && $signupMode === 'disabled') {
            $this->auditLogger->logFailedAuth('signups_disabled', [
                'username' => $username,
            ]);
            throw new SignupDisabledException();
        }

        // Status the new account is created with: active for the first user and
        // for 'open' mode; pending for 'approval' mode (no tokens issued).
        $status = ($isFirstUser || $signupMode !== 'approval') ? 'active' : 'pending';

        // Wrap create() + setAdmin() in a transaction for the first-user
        // path so a failure between the two does not leave the database
        // with an unauthorized half-promoted account. For the common
        // case (Nth user, no promotion), we still open a transaction so
        // a partial create() rolls back cleanly. When no Connection has
        // been injected (legacy / unit-test callers), fall back to the
        // non-transactional flow.
        $db = $this->db;
        if ($db !== null) {
            $db->beginTrans();
        }

        try {
            // Create user
            $userId = $this->userRepository->create([
                'username' => $username,
                'email' => $email,
                'password' => $password,
                'display_name' => $username,
                'status' => $status,
            ]);

            if ($isFirstUser) {
                // Minimum-viable admin bootstrap: the operator who
                // registered first owns the box. Phase D will replace
                // this with a real RBAC + invite flow.
                $this->userRepository->setAdmin($userId, true);
                $this->logger->info('Promoted first user to admin', [
                    'user_id' => $userId,
                    'username' => $username,
                ]);
            }

            // S81: a signup creates the account's FIRST profile automatically
            // (the AC "signup creates a first profile automatically").
            // Inside the same transaction as the user row: a failure rolls
            // back the account too, so no user can exist profile-less by
            // construction. Skipped for 'pending' accounts (no session yet;
            // resolveProfileIdForUser() heals lazily on their first
            // profile-scoped write after approval).
            if ($this->profileManager !== null && $status === 'active') {
                $this->profileManager->create($userId, [
                    'name' => self::FIRST_PROFILE_NAME,
                ]);
            }

            if ($db !== null) {
                $db->commitTrans();
            }
        } catch (Throwable $e) {
            if ($db !== null) {
                try {
                    $db->rollBackTrans();
                } catch (Throwable $rollbackError) {
                    $this->logger->error('Failed to roll back failed registration', [
                        'username' => $username,
                        'rollback_error' => $rollbackError->getMessage(),
                    ]);
                }
            }
            $this->logger->error('User registration failed', [
                'username' => $username,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $this->logger->info('User registered', [
            'user_id' => $userId,
            'username' => $username,
            'status' => $status,
        ]);

        $this->dispatchUserCreated($userId, $username, $email);

        // Approval mode: the account is pending and CANNOT log in or see media
        // until an admin approves it, so issue no tokens.
        if ($status === 'pending') {
            return [
                'status' => 'pending',
                'message' => 'Your account is awaiting administrator approval.',
                'user' => null,
            ];
        }

        return $this->createAuthResponse($userId);
    }

    /**
     * Authenticate a user with credentials.
     *
     * Verifies the provided username and password, updates the last login
     * timestamp, and returns authentication tokens upon successful auth.
     *
     * @param string $username User's username
     * @param string $password User's password
     * @param string $deviceId Unique identifier for the device/app
     *
     * @return array<string, mixed> Authentication response with access_token,
     *         refresh_token, token_type, expires_in, and user data
     *
     * @throws \InvalidArgumentException If credentials are invalid
     * @throws AccountInactiveException If credentials are valid but the account
     *         is not 'active' (pending approval or disabled) — no tokens issued.
     * @throws RateLimitException If the client IP has exceeded the rate limit.
     *
     * @example
     * ```php
     * $result = $authManager->login('john_doe', 'secure_pass123', 'device-uuid-123');
     * ```
     */
    public function login(string $username, string $password, string $deviceId): array
    {
        $clientIp = $this->getClientIp();
        $this->checkRateLimit($clientIp);

        // Accept either a username or an email as the identifier — the SPA login
        // field is "Username or email" and submits whichever the user typed.
        $user = $this->userRepository->findByUsername($username);
        if ($user === null) {
            $user = $this->userRepository->findByEmail($username);
        }
        $userId = UserRow::string($user, 'id');

        if ($user === null || $userId === null || !$this->userRepository->verifyPassword($userId, $password)) {
            $this->recordFailedAttempt($clientIp);
            $this->auditLogger->logFailedAuth('invalid_credentials', [
                'username' => $username,
                'device_id' => $deviceId,
            ]);
            throw new \InvalidArgumentException('Invalid username or password');
        }

        // Signup approval gate (S1): credentials are correct, but the account
        // must be 'active' to log in. Pending (awaiting approval) and disabled
        // (suspended) accounts are rejected with a distinct error code and no
        // tokens. A missing status column defaults to 'active' for safety.
        $status = UserRow::string($user, 'status') ?? 'active';
        if ($status !== 'active') {
            $this->auditLogger->logFailedAuth('account_' . $status, [
                'username' => $username,
                'device_id' => $deviceId,
            ]);
            throw AccountInactiveException::forStatus($status);
        }

        // S7+F1: if the account has must_change_password set, block normal login
        // and require the user to set a new password via the reset-token flow.
        if ($this->userRepository->mustChangePassword($userId)) {
            $this->auditLogger->logFailedAuth('password_change_required', [
                'username' => $username,
                'device_id' => $deviceId,
            ]);
            throw new PasswordChangeRequiredException();
        }

        $this->clearRateLimit($clientIp);

        // Update last login
        $this->userRepository->updateLastLogin($userId);

        $this->auditLogger->logLogin($userId, $deviceId, true);

        $this->logger->info('User logged in', ['user_id' => $userId, 'device_id' => $deviceId]);

        $this->recordActivity($userId, 'login', $clientIp);

        $this->dispatchUserLoggedIn($userId, $deviceId);

        return $this->createAuthResponse($userId);
    }

    /**
     * Validate a username/email + password pair WITHOUT issuing tokens or
     * creating a device session.
     *
     * This is the lightweight credential check behind HTTP Basic auth on the
     * OPDS feeds (e-reader clients send `Authorization: Basic`, not a Bearer
     * token, and re-send it on every feed/cover/download request). Unlike
     * {@see self::login()} it does not rate-limit, audit, update last-login, or
     * mint a session — it answers the single question "are these credentials
     * valid for an active account?" and returns the user id when they are.
     *
     * @param string $usernameOrEmail The identifier the client supplied.
     * @param string $password        The plaintext password to verify.
     *
     * @return string|null The user id on success; null when the account does not
     *                      exist, the password is wrong, or the account is not
     *                      'active' (pending/suspended).
     *
     * @since 0.44.0
     */
    public function verifyCredentials(string $usernameOrEmail, string $password): ?string
    {
        $user = $this->userRepository->findByUsername($usernameOrEmail);
        if ($user === null) {
            $user = $this->userRepository->findByEmail($usernameOrEmail);
        }

        $userId = UserRow::string($user, 'id');
        if ($user === null || $userId === null || !$this->userRepository->verifyPassword($userId, $password)) {
            return null;
        }

        // Mirror login(): only fully 'active' accounts may stream. A missing
        // status column defaults to active for backwards compatibility.
        $status = UserRow::string($user, 'status') ?? 'active';
        if ($status !== 'active') {
            return null;
        }

        return $userId;
    }

    /**
     * Authenticate a user via an external provider (OIDC, LDAP, SAML, passkey).
     *
     * Handles provider-prefixed usernames (e.g. "oidc:alice@example.com")
     * by delegating to the registered external provider via {@see ProviderManager}.
     * On successful auth, automatically creates a local user row if one does
     * not already exist for the external identity (password_hash = NULL so
     * the user can set a local password later).
     *
     * @param string $username    Provider-prefixed username (e.g. "oidc:alice@google.com")
     *                            or plain username for fallback password auth.
     * @param array<string, mixed> $credentials Provider-specific credentials (e.g. id_token,
     *                            authorization_code) or password for fallback.
     * @param string $deviceId   Unique identifier for the device/app.
     *
     * @return array<string, mixed> Authentication response with access_token,
     *         refresh_token, token_type, expires_in, and user data.
     *
     * @throws \RuntimeException When ProviderManager is not configured.
     * @throws \InvalidArgumentException When authentication fails or provider is unknown.
     *
     * @since 0.12.0 (Step D.1)
     */
    public function loginWithProvider(string $username, array $credentials, string $deviceId): array
    {
        $providerManager = $this->providerManager;
        if ($providerManager === null) {
            throw new \RuntimeException(
                'ProviderManager is not configured. External auth providers are unavailable.'
            );
        }

        // S44 Finding 2 (HIGH) — the external-provider path (LDAP/OIDC) MUST be
        // subject to the SAME per-IP brute-force throttle as the local password
        // login(). Without this an `ldap:`-prefixed login (routed here) bypasses
        // the rate limiter entirely, allowing unthrottled directory-credential
        // guessing. Same store, same client-IP key and same limit as login(), so
        // both surfaces share one budget. checkRateLimit() throws
        // RateLimitException (→ central 429 mapping) when the IP is over budget.
        // (Local var used so the method calls below don't re-widen the property.)
        $clientIp = $this->getClientIp();
        $this->checkRateLimit($clientIp);

        $result = $providerManager->authenticate($username, $credentials);

        if ($result->isFailure()) {
            // Count the failure against the brute-force budget, mirroring login().
            $this->recordFailedAttempt($clientIp);
            $this->auditLogger->logFailedAuth($result->error ?? 'provider_auth_failed', [
                'username' => $username,
                'device_id' => $deviceId,
            ]);
            throw new \InvalidArgumentException($result->error ?? 'Authentication failed');
        }

        // Successful provider auth — clear the IP's failed-attempt window, as
        // login() does after a good password.
        $this->clearRateLimit($clientIp);

        $userId = $result->userId;

        if ($userId === null) {
            $externalId = $result->externalId ?? '';
            $email = $result->getEmail();
            $displayName = $result->getDisplayName();
            // Record the REAL provider that authenticated (OidcProvider/
            // LdapProvider set attributes['provider']), not the old hardcoded
            // 'external'. The provider column is the foundation S46/S47 build on.
            $provider = is_string($result->attributes['provider'] ?? null)
                ? $result->attributes['provider']
                : 'external';

            $created = null;
            $userId = $this->userRepository->findOrCreateByExternalId(
                $provider,
                $externalId,
                $email,
                $displayName,
                $created,
            );

            // S81: a NEW external-provider account (OIDC/GitHub/LDAP) starts
            // with its first profile, exactly like a local signup — wiring
            // register() alone would leave every external signup profile-less
            // (the S81 blocker record). Only the create path reports
            // `$created === true`; a pre-existing owner keeps their profiles.
            if ($created === true && $this->profileManager !== null) {
                $this->profileManager->create($userId, [
                    'name' => self::FIRST_PROFILE_NAME,
                ]);
            }
        }

        $this->userRepository->updateLastLogin($userId);

        $this->auditLogger->logLogin($userId, $deviceId, true);

        $this->logger->info('User logged in via external provider', [
            'user_id' => $userId,
            'device_id' => $deviceId,
        ]);

        $this->recordActivity($userId, 'login', $clientIp);

        $this->dispatchUserLoggedIn($userId, $deviceId);

        return $this->createAuthResponse($userId);
    }

    /**
     * Refresh authentication tokens using a refresh token.
     *
     * Validates the provided refresh token and issues new access/refresh
     * token pair if the refresh token is valid and not expired.
     *
     * @param string $refreshToken Valid refresh token from previous login
     *
     * @return array<string, mixed> Authentication response with new access_token,
     *         refresh_token, token_type, expires_in, and user data
     *
     * @throws \InvalidArgumentException If refresh token is invalid or expired
     *
     * @see JwtHandler::isRefreshToken For refresh token validation
     *
     * @example
     * ```php
     * $result = $authManager->refreshToken($refreshToken);
     * ```
     */
    public function refreshToken(string $refreshToken): array
    {
        if (!$this->jwtHandler->isRefreshToken($refreshToken)) {
            throw new \InvalidArgumentException('Invalid refresh token');
        }

        $payload = $this->jwtHandler->validateToken($refreshToken);
        if (!$payload) {
            throw new \InvalidArgumentException('Expired refresh token');
        }

        $userId = self::asString($payload['sub'] ?? null);
        if ($userId === '') {
            throw new \InvalidArgumentException('Refresh token missing subject');
        }

        // S1 security fix: the token may be cryptographically valid but the
        // backing account could have been disabled (or set pending) since it
        // was issued. Re-check the current DB status with a single lightweight
        // PK lookup and refuse to mint fresh tokens unless the account is
        // active. Mirror this method's existing invalid/expired-token failure
        // contract exactly (throw \InvalidArgumentException, which the caller
        // already maps to a 401) so callers need no change.
        $status = $this->getCachedUserStatus($userId);
        if ($status !== 'active') {
            $this->auditLogger->logFailedAuth('account_' . $status, [
                'user_id' => $userId,
                'context' => 'refresh',
            ]);
            throw new \InvalidArgumentException('Account is not active');
        }

        // S7+F1: if the account has must_change_password set, block token
        // refresh and require the user to set a new password via the reset-token flow.
        if ($this->userRepository->mustChangePassword($userId)) {
            $this->auditLogger->logFailedAuth('password_change_required', [
                'user_id' => $userId,
                'context' => 'refresh',
            ]);
            throw new \InvalidArgumentException('Password change required');
        }

        // S80: carry the session's profile across the re-mint. Without this a
        // device that switched to a child profile would silently revert to the
        // account default the first time its access token expired.
        return $this->createAuthResponse($userId, JwtHandler::profileIdClaim($payload));
    }

    /**
     * Validate an access token and extract user information.
     *
     * @param string $token Bearer token to validate
     *
     * @return array<string, mixed>|null User info with user_id and expires_at
     *         if valid, null if invalid or expired
     *
     * @throws \InvalidArgumentException If token is not an access token
     *
     * @example
     * ```php
     * $info = $authManager->validateAccessToken($bearerToken);
     * if ($info) {
     *     echo "User ID: " . $info['user_id'];
     * }
     * ```
     */
    public function validateAccessToken(string $token): ?array
    {
        if (!$this->jwtHandler->isAccessToken($token)) {
            return null;
        }

        $payload = $this->jwtHandler->validateToken($token);
        if (!$payload) {
            return null;
        }

        // S1 security fix: a cryptographically valid access token must still be
        // backed by an active account. An account disabled mid-session (its 1h
        // token still live) is revoked here on every authenticated request via a
        // single lightweight PK lookup. Mirror the invalid-token failure
        // contract exactly (return null) so HttpHandler simply leaves the
        // request unauthenticated — no signature or caller change.
        $userId = self::asString($payload['sub'] ?? null);
        if ($userId === '') {
            return null;
        }
        $status = $this->getCachedUserStatus($userId);
        if ($status !== 'active') {
            return null;
        }

        // S80: the profile the token was minted for, resolved through the owner
        // check on every request. Returning the RAW claim here would hand the rest
        // of the stack a profile the account may no longer own — a token stays
        // valid for an hour after a profile is deleted or reassigned, so "signed"
        // is not "current". resolveProfileForUser() re-derives ownership and
        // degrades a stale claim to the account default rather than refusing the
        // whole request, which would lock a user out of their own account until
        // their token expired.
        return [
            'user_id' => $payload['sub'],
            'expires_at' => $payload['exp'],
            'profile_id' => $this->resolveProfileForUser(
                $userId,
                JwtHandler::profileIdClaim($payload)
            ),
        ];
    }

    /**
     * Resolve the profile a request should run as, from a CLAIMED profile id.
     *
     * The single place S80's propagated profile is turned into a usable value,
     * and the reason the propagation is safe:
     *
     *   - A claim is **verified, never trusted**. It goes through
     *     {@see UserProfileManager::resolveProfileIdForUser()}, which re-derives
     *     ownership from `user_profiles` against `$userId` on every call. Even
     *     though a JWT claim is signed and so cannot be forged by a client, it can
     *     be STALE: profiles are deleted, and an hour-long access token outlives
     *     that.
     *   - A **stale** claim DEGRADES to the account's default profile rather than
     *     raising. Refusing would lock the user out of their own account for up to
     *     an hour with no way to recover but waiting for expiry.
     *   - A caller-supplied `profile_id` from a body, query string or path is
     *     never routed through here — that path keeps
     *     {@see ProfileNotOwnedException}'s hard refusal, because there it IS
     *     attacker-controlled input rather than something this server signed.
     *
     * @param string      $userId          The authenticated account.
     * @param string|null $claimedProfileId The profile named by the token, or null.
     *
     * @return string|null The profile this request runs as, or null when no
     *                     resolver is wired (legacy hand-built managers in tests).
     *
     * @since S80 (profile-context propagation)
     */
    public function resolveProfileForUser(string $userId, ?string $claimedProfileId): ?string
    {
        $profiles = $this->profileManager;
        if ($profiles === null || trim($userId) === '') {
            return null;
        }

        try {
            return $profiles->resolveProfileIdForUser($userId, $claimedProfileId);
        } catch (ProfileNotOwnedException $e) {
            // Stale, not hostile — the account no longer owns the profile the
            // token names. Fall back to its default.
            $this->logger->info('Token profile claim no longer owned; falling back to default', [
                'user_id' => $userId,
            ]);
        } catch (\Throwable $e) {
            // A profile could not be established at all (e.g. the DB is
            // momentarily unavailable). Authentication itself must not fail for
            // that reason, so return null and let the profile-scoped callers
            // apply their own fail-closed policy.
            $this->logger->warning('Could not resolve a profile for the request', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        try {
            return $profiles->resolveProfileIdForUser($userId, null);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not resolve a default profile for the request', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get user profile information by user ID.
     *
     * @param string $userId Unique user identifier
     *
     * @return array<string, mixed>|null User profile data without password hash,
     *         or null if user not found
     *
     * @example
     * ```php
     * $user = $authManager->getUser('user-uuid-123');
     * if ($user) {
     *     echo "Welcome, " . $user['display_name'];
     * }
     * ```
     */
    public function getUser(string $userId): ?array
    {
        $user = $this->userRepository->findById($userId);
        if (!$user) {
            return null;
        }

        unset($user['password_hash']);
        return $user;
    }

    /**
     * Create authentication response with tokens and user data.
     *
     * Generates new access and refresh tokens for the user and returns
     * the complete authentication response payload.
     *
     * @param string $userId User identifier to generate tokens for
     *
     * @return array<string, mixed> Complete auth response including
     *         access_token, refresh_token, token_type, expires_in, user
     *
     * @example
     * ```php
     * $response = $this->createAuthResponse('user-uuid-123');
     * // Result:
     * // [
     * //     'access_token' => 'eyJ...',
     * //     'refresh_token' => 'eyJ...',
     * //     'token_type' => 'Bearer',
     * //     'expires_in' => 3600,
     * //     'user' => [...],
     * // ]
     * ```
     */
    private function createAuthResponse(string $userId, ?string $profileId = null): array
    {
        return $this->buildAuthResponse($userId, $profileId);
    }

    /**
     * Mint an access + refresh token pair, stamped with the session's profile.
     *
     * @param string      $userId    The authenticated account.
     * @param string|null $profileId The profile this SESSION should run as. Null
     *                               means "the account's default", which is what a
     *                               fresh login gets; a profile switch (S81) and a
     *                               token refresh both pass the session's current
     *                               profile through so it survives the re-mint.
     *                               The value is VERIFIED against `$userId` before
     *                               it is stamped — see {@see self::resolveProfileForUser()}.
     *
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int,
     *     user: array<string, mixed>|null, profile_id: string|null}
     */
    public function buildAuthResponse(string $userId, ?string $profileId = null): array
    {
        $resolvedProfileId = $this->resolveProfileForUser($userId, $profileId);

        // Both tokens carry the claim. If only the access token did, the first
        // refresh an hour later would silently drop the device back to the
        // account-wide default profile.
        $claims = $resolvedProfileId === null
            ? []
            : [JwtHandler::CLAIM_PROFILE_ID => $resolvedProfileId];

        $accessToken = $this->jwtHandler->createAccessToken($userId, $claims);
        $refreshToken = $this->jwtHandler->createRefreshToken($userId, $claims);
        $user = $this->getUser($userId);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'profile_id' => $resolvedProfileId,
            'token_type' => 'Bearer',
            // Read from the handler that just minted the token, NOT a literal.
            // This was a hardcoded 3600 independent of the handler's actual
            // TTL, so the number the client was told and the `exp` baked into
            // the token were two unrelated constants. See TokenTtlPolicy.
            'expires_in' => $this->jwtHandler->accessTtl(),
            'user' => $user,
        ];
    }

    /**
     * Effective access-token lifetime, from the handler that mints the tokens.
     *
     * Exposed so {@see \Phlix\Server\Http\Controllers\AuthController} can set
     * cookie lifetimes from the SAME source as the tokens themselves.
     *
     * @return int Seconds.
     *
     * @since 1.3.0
     */
    public function accessTtl(): int
    {
        return $this->jwtHandler->accessTtl();
    }

    /**
     * Effective refresh-token lifetime, from the handler that mints the tokens.
     *
     * @return int Seconds.
     *
     * @since 1.3.0
     *
     * @see self::accessTtl()
     */
    public function refreshTtl(): int
    {
        return $this->jwtHandler->refreshTtl();
    }

    /**
     * Record that a user has logged out and publish {@see UserLoggedOut}.
     *
     * This is the lightweight A.2 hook: AuthManager does not yet own
     * session-row deletion (that lives in `SessionManager` and is
     * driven by the controllers), so this method simply emits the
     * event so plugins / hub mirrors can react. Token revocation will
     * be implemented in a later phase.
     *
     * @param string $userId    UUID of the user logging out.
     * @param string $sessionId Opaque session identifier (device ID /
     *                          session UUID, depending on phase).
     * @param string $reason    One of {@see UserLoggedOut::REASON_EXPLICIT},
     *                          {@see UserLoggedOut::REASON_EXPIRED},
     *                          {@see UserLoggedOut::REASON_REVOKED}.
     *                          Defaults to "explicit".
     *
     * @return void
     *
     * @since 0.10.0
     */
    public function logout(
        string $userId,
        string $sessionId,
        string $reason = UserLoggedOut::REASON_EXPLICIT
    ): void {
        $this->logger->info('User logged out', [
            'user_id' => $userId,
            'session_id' => $sessionId,
            'reason' => $reason,
        ]);
        $this->recordActivity($userId, 'logout', $this->getClientIp());
        $this->dispatchUserLoggedOut($userId, $sessionId, $reason);
    }

    /**
     * Emit {@see UserCreated}.
     *
     * @param string $userId   UUID of the newly-created user.
     * @param string $username Validated username on the new account.
     * @param string $email    Validated email on the new account.
     *
     * @return void
     */
    private function dispatchUserCreated(string $userId, string $username, string $email): void
    {
        if ($this->eventDispatcher === null) {
            return;
        }
        $this->eventDispatcher->dispatch(new UserCreated(
            userId: $userId,
            username: $username,
            email: $email,
        ));
    }

    /**
     * Emit {@see UserLoggedIn}.
     *
     * IP address and user-agent are not yet plumbed through AuthManager
     * (HTTP request context is created by the controller, not the
     * manager). They are passed as empty strings for now; Phase B will
     * route the request context through.
     *
     * @param string $userId    UUID of the user logging in.
     * @param string $sessionId Session / device identifier.
     *
     * @return void
     */
    private function dispatchUserLoggedIn(string $userId, string $sessionId): void
    {
        if ($this->eventDispatcher === null) {
            return;
        }
        $this->eventDispatcher->dispatch(new UserLoggedIn(
            userId: $userId,
            sessionId: $sessionId,
            ipAddress: '',
            userAgent: '',
        ));
    }

    /**
     * Emit {@see UserLoggedOut}.
     *
     * @param string $userId    UUID of the user logging out.
     * @param string $sessionId Session / device identifier.
     * @param string $reason    Reason constant from {@see UserLoggedOut}.
     *
     * @return void
     */
    private function dispatchUserLoggedOut(string $userId, string $sessionId, string $reason): void
    {
        if ($this->eventDispatcher === null) {
            return;
        }
        $this->eventDispatcher->dispatch(new UserLoggedOut(
            userId: $userId,
            sessionId: $sessionId,
            reason: $reason,
        ));
    }

    /**
     * Coerce a mixed value to a string for event payload use.
     *
     * Returns the empty string for nulls and non-scalars so the caller
     * never has to special-case a missing row column.
     *
     * @param mixed $value Value to coerce.
     *
     * @return string Coerced string ('' when not coercible).
     */
    private static function asString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string)$value;
        }
        return '';
    }
}
