<?php

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
    private const RATE_LIMIT_MAX_ATTEMPTS = 5;
    private const RATE_LIMIT_WINDOW_SECONDS = 900; // 15 minutes

    /** @var array<string, array{attempts: int, reset_at: int}> Static rate limit storage per IP */
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
        ?SettingsRepository $settingsRepository = null
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
     * Create a default logger for authentication events.
     *
     * @return StructuredLogger A configured logger instance
     *
     * @example
     * ```php
     * $logger = $this->createDefaultLogger();
     * ```
     */
    private function createDefaultLogger(): StructuredLogger
    {
        $tempDir = sys_get_temp_dir() . '/phlix_auth_' . uniqid();
        mkdir($tempDir, 0755, true);

        $config = [
            'handlers' => [
                'stream' => [
                    'type' => 'stream',
                    'path' => $tempDir . '/auth.log',
                    'level' => 'debug',
                ],
            ],
            'processors' => [
                'context' => true,
                'request_id' => false,
                'user_id' => false,
            ],
        ];

        return new StructuredLogger(LogChannels::AUTH, $config);
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
     * @throws RateLimitException When rate limit is exceeded
     */
    private function checkRateLimit(string $ip): void
    {
        $now = time();

        if (!isset(self::$rateLimitStore[$ip])) {
            return;
        }

        $record = &self::$rateLimitStore[$ip];

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
     */
    private function recordFailedAttempt(string $ip): void
    {
        $now = time();

        if (!isset(self::$rateLimitStore[$ip])) {
            self::$rateLimitStore[$ip] = [
                'attempts' => 0,
                'reset_at' => $now + self::RATE_LIMIT_WINDOW_SECONDS,
            ];
        }

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
     * Clear rate limit data for a client IP after successful auth.
     */
    private function clearRateLimit(string $ip): void
    {
        unset(self::$rateLimitStore[$ip]);
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

        if (strlen($password) < 8) {
            throw new \InvalidArgumentException('Password must be at least 8 characters');
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
        if ($this->providerManager === null) {
            throw new \RuntimeException(
                'ProviderManager is not configured. External auth providers are unavailable.'
            );
        }

        $result = $this->providerManager->authenticate($username, $credentials);

        if ($result->isFailure()) {
            $this->auditLogger->logFailedAuth($result->error ?? 'provider_auth_failed', [
                'username' => $username,
                'device_id' => $deviceId,
            ]);
            throw new \InvalidArgumentException($result->error ?? 'Authentication failed');
        }

        $userId = $result->userId;

        if ($userId === null) {
            $externalId = $result->externalId ?? '';
            $email = $result->getEmail();
            $displayName = $result->getDisplayName();

            $userId = $this->userRepository->findOrCreateByExternalId(
                $externalId,
                $email,
                $displayName,
            );
        }

        $this->userRepository->updateLastLogin($userId);

        $this->auditLogger->logLogin($userId, $deviceId, true);

        $this->logger->info('User logged in via external provider', [
            'user_id' => $userId,
            'device_id' => $deviceId,
        ]);

        $this->recordActivity($userId, 'login', $this->getClientIp());

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
        $status = $this->userRepository->getStatus($userId) ?? 'active';
        if ($status !== 'active') {
            $this->auditLogger->logFailedAuth('account_' . $status, [
                'user_id' => $userId,
                'context' => 'refresh',
            ]);
            throw new \InvalidArgumentException('Account is not active');
        }

        return $this->createAuthResponse($userId);
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
        $status = $this->userRepository->getStatus($userId) ?? 'active';
        if ($status !== 'active') {
            return null;
        }

        return [
            'user_id' => $payload['sub'],
            'expires_at' => $payload['exp'],
        ];
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
    private function createAuthResponse(string $userId): array
    {
        return $this->buildAuthResponse($userId);
    }

    /**
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int, user: array<string, mixed>|null}
     */
    public function buildAuthResponse(string $userId): array
    {
        $accessToken = $this->jwtHandler->createAccessToken($userId);
        $refreshToken = $this->jwtHandler->createRefreshToken($userId);
        $user = $this->getUser($userId);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'user' => $user,
        ];
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
