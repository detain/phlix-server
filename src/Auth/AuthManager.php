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
use Phlix\Session\SessionManager;
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
     * Optional device-session manager (M-1, security audit 2026-09-29).
     *
     * When wired, logout() ends the user's `sessions` rows server-side in
     * addition to revoking JWTs, so a device presenting a session-cookie login
     * cannot keep authenticating from the session table after an explicit
     * logout. When null (legacy hand-built managers, unit-test callers that
     * never touch sessions) JWT revocation alone still holds — the token
     * denylist is the primary control; the device-session teardown is
     * belt-and-braces so the two stores do not diverge.
     *
     * @var SessionManager|null
     */
    private ?SessionManager $sessionManager;

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
     * In-worker TTL cache for the per-request auth-state lookup (status +
     * token-revocation watermark).
     *
     * Avoids a PK lookup on UserRepository::getAuthState() for every
     * authenticated request when the same user makes multiple concurrent
     * requests. The short TTL (5 seconds) means status revocation AND token
     * revocation (logout / password change / admin bump) take effect within a
     * few seconds rather than immediately (see
     * {@see self::invalidateUserStatusCache()} for the in-process path that
     * makes a same-worker change take effect immediately, without waiting for
     * the TTL), which is acceptable for the "disable account" use-case while
     * significantly reducing DB load.
     *
     * SINGLE-LAYER CACHE DOCTRINE (M-6, security audit 2026-09-29): this is the
     * ONLY cache tier on the revocation hot path. The repository previously
     * layered a 60-second status cache under this one, silently making the real
     * revocation ceiling 60s while every docblock here claimed 5s. The inner
     * layer is deleted; `AuthManagerRevocationCeilingTest` pins the bound so a
     * future re-caching at the repository fails loudly instead of re-lying.
     *
     * Bounded by {@see self::USER_STATUS_CACHE_MAX}: insertion order doubles as
     * an LRU (a cache hit re-inserts the entry at the end via unset()+reassign,
     * so the map's key order is always oldest-first) exactly like
     * {@see \Phlix\Media\Library\ItemRepository::$genreFacetCache} — see that
     * property's docblock for why the unset()-before-reassign step matters for
     * eviction correctness (a plain value overwrite of an existing key leaves
     * it in its original position, not the end).
     *
     * @var array<string, array{status: string|null, tokensNotValidAfter: int, cachedAt: int}> keyed by userId
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
     * @param SessionManager|null $sessionManager Optional device-session manager;
     *                                       when supplied, logout() also ends the
     *                                       user's device sessions server-side (M-1)
     *                                       instead of only revoking JWTs.
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
        ?UserProfileManager $profileManager = null,
        ?SessionManager $sessionManager = null
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
        $this->sessionManager = $sessionManager;
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
     * Gets the cached auth state (status + token-revocation watermark) for a
     * user, fetching from the repository on cache miss/expiry.
     *
     * Uses a short TTL (5 seconds) to reduce DB lookups for every authenticated
     * request while still allowing near-instant account revocation to take
     * effect. 5 seconds is the AUTHORITY, not an aspiration — see the
     * single-layer cache doctrine on {@see self::$userStatusCache} (M-6).
     *
     * H-1 (security audit 2026-09-29, fail-OPEN fix): a missing user row used
     * to be defaulted to 'active' here. The `status` column is a NOT NULL ENUM
     * — a null from the repository can ONLY mean "row does not exist" (a
     * DELETED user), so `?? 'active'` let deleted accounts pass access-token
     * validation and — worse — re-mint 7-day refresh pairs forever, with
     * {@see self::invalidateUserStatusCache()} re-caching 'active' on every
     * admin delete. Null now flows through as null and every enforcement site
     * compares `!== 'active'`, so "unknown" fails CLOSED on both paths.
     *
     * NOTE the deliberate asymmetry with the login-time fallbacks in
     * {@see self::login()} / {@see self::verifyCredentials()} (`?? 'active'`
     * on an ALREADY-FETCHED row): there, a missing status key means a legacy
     * row shape from a hydrated user object, not a missing row — the row's
     * existence is already proven. That fallback is safe and stays.
     *
     * @param string $userId The user ID to look up
     *
     * @return array{status: string|null, tokensNotValidAfter: int} The stored
     *         status (null = the user row no longer exists) and the epoch-second
     *         token-revocation watermark (0 = never revoked).
     */
    private function getCachedAuthState(string $userId): array
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
                return ['status' => $entry['status'], 'tokensNotValidAfter' => $entry['tokensNotValidAfter']];
            }
        }

        // Cache miss or expired - fetch from DB. A missing row yields
        // ['status' => null, ...] — NEVER defaulted to 'active' (H-1).
        $state = $this->userRepository->getAuthState($userId)
            ?? ['status' => null, 'tokensNotValidAfter' => 0];

        // Store in cache. unset() first (see the LRU-touch comment above) so a
        // stale-entry recompute is reinserted at the MRU end rather than left
        // in its original array position.
        unset($this->userStatusCache[$userId]);
        $this->userStatusCache[$userId] = [
            'status' => $state['status'],
            'tokensNotValidAfter' => $state['tokensNotValidAfter'],
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

        return $state;
    }

    /**
     * Is the account active right now, per the cached auth state? (H-1/M-6:
     * the single enforcement predicate for "this user may still authenticate",
     * null status — a deleted row — fails closed.)
     */
    private function isUserActive(?string $status): bool
    {
        return $status === 'active';
    }

    /**
     * Was this token minted BEFORE the user's revocation watermark? (M-1.)
     *
     * The watermark says "everything issued up to instant W is dead". Tokens
     * carry `iat` (seconds); a token minted at or before the watermark was
     * minted before the logout/password-change/admin bump that set it, so it
     * must not authenticate or re-mint. A token with NO iat claim cannot be
     * placed after the watermark — fail closed and reject it once any
     * watermark exists (our own JwtHandler always stamps iat).
     *
     * @param int $tokensNotValidAfter Watermark epoch seconds (0 = never revoked).
     * @param array<string, mixed> $payload Validated JWT payload.
     */
    private function isTokenRevoked(int $tokensNotValidAfter, array $payload): bool
    {
        if ($tokensNotValidAfter <= 0) {
            return false;
        }

        $issuedAt = $payload['iat'] ?? null;
        if (!is_int($issuedAt) && !is_numeric($issuedAt)) {
            return true; // un-placeable issue time + live watermark ⇒ reject
        }

        return (int) $issuedAt <= $tokensNotValidAfter;
    }

    /**
     * Clears the cached auth state for a user (call when status or the token
     * watermark changes).
     *
     * Called by {@see \Phlix\Server\Http\Controllers\Admin\AdminUserController}
     * after any admin action that changes a user's `status` column (approve,
     * disable, reject/delete) or revocation watermark so an in-process change
     * is reflected on THIS worker's very next request for that user, instead of
     * waiting out the {@see self::USER_STATUS_CACHE_TTL_NS} TTL. Other resident
     * workers in the same process pool do not share this cache (it is
     * in-worker only) and converge only via the TTL — with the inner repository
     * cache deleted (M-6), the 5-second window is now the GENUINE ceiling on
     * cross-worker revocation latency; immediate invalidation is only possible
     * for same-worker requests.
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
     * Claim (or lose) the first-admin election, INSIDE the caller's open
     * transaction (L-3, security audit 2026-09-29).
     *
     * Protocol on the single-row `first_admin_election` sentinel:
     *   1. `INSERT IGNORE (1, NULL)` — when no row exists this claims the
     *      election for the current transaction; when an UNCOMMITTED claim by
     *      another registration exists, InnoDB blocks this statement until that
     *      transaction resolves (this blocking is the serialisation; it is why
     *      the claim must run inside the transaction, never before it).
     *   2. `SELECT ... FOR UPDATE` (a current read — a plain SELECT could serve
     *      a pre-block snapshot under REPEATABLE READ) returns the row's
     *      winner. On a FRESH install `user_id IS NULL` here can only be MY
     *      uncommitted claim: every winner stamps its id before committing,
     *      and a crash/rollback between claim and stamp releases the row with
     *      the transaction, so a failed first registration never bricks the
     *      election.
     *   3. On an UPGRADED install the NULL sentinel is NOT proof of being
     *      first. Migration 108 only CREATEs the table, and every pre-existing
     *      user arrived outside register() (CLI create/promote, the admin user
     *      UI) — none of which stamp the sentinel. So an unstamped NULL row is
     *      honoured ONLY when `users` is empty, verified under the same FOR
     *      UPDATE serialisation (step 1 blocks every concurrent election on
     *      this row): a present user row ⇒ NOT first ⇒ false. Without this
     *      check, one later unauthenticated /api/v1/auth/register would
     *      self-elect ACTIVE ADMIN through the virgin sentinel on every
     *      upgraded box — and the first-user branch skips the signup gate
     *      with it. Migration 109 backfills the sentinel on upgrade; this
     *      check is the belt that holds even before 109 has run.
     *
     * @param Connection $db The connection with the registration transaction
     *                       already open.
     *
     * @return bool True when THIS caller won the election (sentinel claim
     *              held AND the users table empty).
     */
    private function claimFirstAdminElection(Connection $db): bool
    {
        $db->query('INSERT IGNORE INTO first_admin_election (id, user_id) VALUES (1, NULL)');
        $rows = $db->query('SELECT user_id FROM first_admin_election WHERE id = 1 FOR UPDATE');
        if (!is_array($rows) || $rows === []) {
            // INSERT IGNORE guarantees the row exists on this connection; an
            // empty read would mean the sentinel table is missing (migrations
            // not run). Fail loud rather than silently electing everyone.
            throw new \RuntimeException(
                'first_admin_election sentinel unreadable — run migrations (108_first_admin_election.sql)'
            );
        }
        $row = $rows[0];
        if (!is_array($row) || ($row['user_id'] ?? null) !== null) {
            return false;
        }

        // Step 3: the sentinel is unstamped — 'first' still requires an empty
        // `users` table. A non-array probe result is an unreadable state, so
        // it fails CLOSED (never elect on uncertainty), same doctrine as the
        // sentinel read above. When this returns false on an upgraded box the
        // transaction may still commit the fresh (1, NULL) row this statement
        // inserted; that residue is exactly what 109's UPDATE arm heals, and
        // re-running the election later re-hits this check, so it is inert.
        $existingUsers = $db->query('SELECT 1 FROM users LIMIT 1');
        if (!is_array($existingUsers) || $existingUsers !== []) {
            $this->logger->warning('First-admin election suppressed: unstamped sentinel, users present', [
                'reason' => 'upgraded_install_backfill_pending',
                'fix' => 'migrations/109_first_admin_election_backfill.sql',
            ]);

            return false;
        }

        return true;
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

        // L-3 (security audit 2026-09-29): the first-user election used to be
        // `countUsers() === 0` evaluated OUTSIDE the transaction — two
        // concurrent first registrations both saw zero rows and BOTH became
        // admin (and both bypassed a 'disabled' gate). The election is now a
        // sentinel-row claim INSIDE the transaction, so InnoDB's duplicate-key
        // serialisation decides exactly one winner — and an unstamped sentinel
        // is only honoured when `users` is genuinely empty, so an upgraded
        // install (virgin 108 sentinel, pre-existing CLI/admin-UI users) cannot
        // be taken over by a later register(). Without a Connection the legacy
        // count check remains (unit-test / pre-transaction callers get no
        // protection to begin with).
        //
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
            $isFirstUser = $db !== null
                ? $this->claimFirstAdminElection($db)
                : $this->userRepository->countUsers() === 0;

            // Resolve the signup gate (S1). The first user ALWAYS bootstraps as
            // an active admin regardless of mode, so the gate only applies to
            // Nth users.
            $signupMode = $this->resolveSignupMode();
            if (!$isFirstUser && $signupMode === 'disabled') {
                $this->auditLogger->logFailedAuth('signups_disabled', [
                    'username' => $username,
                ]);
                throw new SignupDisabledException();
            }

            // Status the new account is created with: active for the first user
            // and for 'open' mode; pending for 'approval' mode (no tokens
            // issued).
            $status = ($isFirstUser || $signupMode !== 'approval') ? 'active' : 'pending';

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
                if ($db !== null) {
                    // Record the winner in the sentinel, same transaction: a
                    // crash before commit releases the claim (see helper).
                    $db->query(
                        'UPDATE first_admin_election SET user_id = ? WHERE id = 1',
                        [$userId]
                    );
                }
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
            // A gate rejection is a policy answer, not a fault: the rollback
            // above already ran (releasing any in-progress first-admin claim —
            // that is precisely why the election must die with the failed
            // transaction), and the audit log already carries the event.
            if ($e instanceof SignupDisabledException) {
                throw $e;
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

        // L-1 (timing oracle): a miss must spend the same Argon2id time a hit
        // spends, or response timing enumerates usernames.
        if ($user === null || $userId === null) {
            $this->userRepository->burnPasswordVerifyTime($password);
            $this->recordFailedAttempt($clientIp);
            $this->auditLogger->logFailedAuth('invalid_credentials', [
                'username' => $username,
                'device_id' => $deviceId,
            ]);
            throw new \InvalidArgumentException('Invalid username or password');
        }
        if (!$this->userRepository->verifyPassword($userId, $password)) {
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
        // tokens.
        //
        // Why `?? 'active'` is SAFE here but NOT in getCachedAuthState() (H-1):
        // this operates on an ALREADY-FETCHED row — $user !== null is proven
        // above, so a missing 'status' key can only be a legacy hydrated-row
        // shape, never a deleted account. The hot-path lookup's null means
        // "row gone" and must fail closed; this one means "old row, pre-S1"
        // and defaults to the historical behaviour.
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
        if ($user === null || $userId === null) {
            // L-1 (timing oracle): burn the same Argon2id time as a real hit.
            $this->userRepository->burnPasswordVerifyTime($password);
            return null;
        }
        if (!$this->userRepository->verifyPassword($userId, $password)) {
            return null;
        }

        // Mirror login(): only fully 'active' accounts may stream. The `?? 'active'`
        // fallback is safe HERE (row existence already proven above — a missing key
        // is a legacy row shape, not a deleted account) and unsafe on the hot-path
        // lookup, where null means "row gone" — see getCachedAuthState() (H-1).
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
        //
        // H-1 (fail-open fix): a null status here means the user ROW IS GONE
        // (the column is a NOT NULL ENUM) — deleted accounts must not re-mint.
        // M-1: the same cached lookup also carries the token-revocation
        // watermark, so a refresh token from before the user's last
        // logout/password-change can no longer mint a fresh 7-day pair.
        $state = $this->getCachedAuthState($userId);
        if (!$this->isUserActive($state['status'])) {
            $this->auditLogger->logFailedAuth('account_' . ($state['status'] ?? 'deleted'), [
                'user_id' => $userId,
                'context' => 'refresh',
            ]);
            throw new \InvalidArgumentException('Account is not active');
        }
        if ($this->isTokenRevoked($state['tokensNotValidAfter'], $payload)) {
            $this->auditLogger->logFailedAuth('token_revoked', [
                'user_id' => $userId,
                'context' => 'refresh',
            ]);
            throw new \InvalidArgumentException('Token has been revoked');
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
        //
        // H-1 (fail-open fix): null status = the user row no longer exists —
        // deleted accounts authenticate as NO ONE from here on.
        // M-1: tokens minted at/before the user's revocation watermark (last
        // logout / password change / admin bump) are likewise rejected, within
        // the 5s cached-state window documented on $userStatusCache.
        $userId = self::asString($payload['sub'] ?? null);
        if ($userId === '') {
            return null;
        }
        $state = $this->getCachedAuthState($userId);
        if (!$this->isUserActive($state['status'])) {
            return null;
        }
        if ($this->isTokenRevoked($state['tokensNotValidAfter'], $payload)) {
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
     * Log a user out SERVER-SIDE and publish {@see UserLoggedOut}.
     *
     * M-1 (security audit 2026-09-29): this used to be an event emission with
     * an explicit "token revocation will be implemented in a later phase"
     * note — the phase is now. Logout bumps the per-user token-revocation
     * watermark (`users.tokens_not_valid_after = NOW()`), which kills every
     * JWT pair issued up to this instant on BOTH enforcement paths
     * (validateAccessToken per request, refreshToken on re-mint), and — when a
     * SessionManager is wired — ends the user's device-session rows so the
     * cookie-backed session store cannot outlive the revoked tokens.
     *
     * Granularity is deliberately PER-USER ("log out everywhere"), matching the
     * documented meaning of the watermark and the blast radius of the other
     * bump triggers (password change, admin disable). The presented token's
     * lineage is always dead: the request that logs out can still complete
     * (auth already resolved upstream), no later request can.
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
        // The watermark + device-session teardown belong to the explicit-user
        // intent paths. REASON_EXPIRED/REASON_REVOKED are bookkeeping emissions
        // (a timer noticing an old session) and must NOT nuke the account's
        // live tokens, so the bump runs only for REASON_EXPLICIT and
        // REASON_REVOKED (an admin-forced logout IS a revocation).
        if ($reason !== UserLoggedOut::REASON_EXPIRED) {
            $this->revokeUserTokens($userId, 'logout:' . $reason);
            $this->sessionManager?->endAllUserSessions($userId);
        }

        $this->logger->info('User logged out', [
            'user_id' => $userId,
            'session_id' => $sessionId,
            'reason' => $reason,
        ]);
        $this->recordActivity($userId, 'logout', $this->getClientIp());
        $this->dispatchUserLoggedOut($userId, $sessionId, $reason);
    }

    /**
     * Kill every JWT minted up to NOW() for one user (M-1 denylist bump).
     *
     * Single choke point for all bump triggers: explicit logout, password
     * change/reset, admin disable/delete. Writes the watermark, then clears
     * THIS worker's cached auth state so the new bound applies on the very
     * next request here; other workers converge via the 5s TTL (the honest,
     * now-pinned ceiling — see the single-layer cache doctrine on
     * {@see self::$userStatusCache}).
     *
     * SECOND-GRANULARITY EDGE (documented, deliberately strict): the watermark
     * is a DATETIME and the check is `iat <= watermark`, so a logout followed
     * by a fresh login WITHIN THE SAME WALL-CLOCK SECOND mints a token whose
     * `iat` equals the watermark — it is rejected until the next second ticks.
     * The UX cost is at most one second on a path where the user just chose to
     * end every session; loosening to `<` would let a token minted in the same
     * second as an admin-forced revocation survive it. Strictness wins.
     *
     * @param string $userId User whose outstanding token pairs should die.
     * @param string $reason Audit context (e.g. 'password_changed').
     *
     * @return void
     */
    public function revokeUserTokens(string $userId, string $reason): void
    {
        $this->userRepository->revokeTokensBeforeNow($userId);
        $this->invalidateUserStatusCache($userId);
        $this->logger->info('User tokens revoked', [
            'user_id' => $userId,
            'reason' => $reason,
        ]);
    }

    /**
     * {@see self::verifyCredentials()} with the LOGIN rate-limit budget applied (M-5).
     *
     * verifyCredentials() is deliberately unthrottled — internal callers
     * (provider flows) must keep it pure. The OPDS Basic-auth gate is the one
     * externally-reachable caller, and Basic auth re-presents credentials on
     * every request, so without this wrapper an attacker gets unlimited
     * Argon2id-cost guesses from one socket: a free password-crashing rig on
     * the same budget login() guards.
     *
     * Failures are charged PER-IP against the same DbLoginRateLimitStore
     * budget as login() (one shared ceiling per IP — a client brute-forcing
     * OPDS is brute-forcing the account, no separate quota to earn); success
     * clears the window so a legitimate e-reader that finally lands a correct
     * password is not punished for earlier typos. Per-segment re-auth UX is
     * unaffected: only WRONG passwords count.
     *
     * @param string $usernameOrEmail Identifier from the Basic header.
     * @param string $password        Password from the Basic header.
     * @param string $clientIp        Caller-supplied, already-trusted client IP
     *                                (taken from Request::getTrustedClientIp()
     *                                by the middleware — NOT $_SERVER, which is
     *                                stale under resident Workerman workers).
     *
     * @return string|null User id on success, null on bad credentials.
     *
     * @throws RateLimitException When the IP has exhausted the login budget;
     *                            the central dispatch maps it to a 429 +
     *                            Retry-After envelope (same contract as
     *                            login()).
     */
    public function verifyCredentialsThrottled(string $usernameOrEmail, string $password, string $clientIp): ?string
    {
        $this->checkRateLimit($clientIp);

        $userId = $this->verifyCredentials($usernameOrEmail, $password);
        if ($userId === null) {
            $this->recordFailedAttempt($clientIp);
            return null;
        }

        $this->clearRateLimit($clientIp);
        return $userId;
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
