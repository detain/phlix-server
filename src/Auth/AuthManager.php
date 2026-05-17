<?php

declare(strict_types=1);

namespace Phlex\Auth;

use Phlex\Common\Events\Auth\UserCreated;
use Phlex\Common\Events\Auth\UserLoggedIn;
use Phlex\Common\Events\Auth\UserLoggedOut;
use Phlex\Common\Logger\AuditLogger;
use Phlex\Common\Logger\LogChannels;
use Phlex\Common\Logger\LoggerFactory;
use Phlex\Common\Logger\StructuredLogger;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Authentication manager for user registration, login, and token management.
 *
 * This class orchestrates all authentication-related operations including
 * user registration, login with credential verification, JWT token generation
 * and validation, and session management.
 *
 * @author Phlex Team
 * @version 1.0.0
 * @description Handles user authentication workflows including registration,
 *              login, token refresh, and validation for the Phlex Media Server.
 * @see JwtHandler For JWT token creation and validation
 * @see UserRepository For user data access and management
 * @see AuditLogger For security audit logging
 */
class AuthManager
{
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
        ?EventDispatcherInterface $eventDispatcher = null
    ) {
        $this->userRepository = $userRepository;
        $this->jwtHandler = $jwtHandler;
        $this->auditLogger = $auditLogger;
        $this->logger = $logger ?? $this->createDefaultLogger();
        $this->eventDispatcher = $eventDispatcher;
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
        $tempDir = sys_get_temp_dir() . '/phlex_auth_' . uniqid();
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
     * Register a new user account.
     *
     * Creates a new user with the provided credentials and returns
     * authentication tokens for immediate login.
     *
     * @param string $username Unique username (3-50 characters)
     * @param string $email User's email address (must be valid format)
     * @param string $password User's password (minimum 8 characters)
     *
     * @return array<string, mixed> Authentication response with access_token,
     *         refresh_token, token_type, expires_in, and user data
     *
     * @throws \InvalidArgumentException If validation fails:
     *         - Username must be 3-50 characters
     *         - Email must be valid format
     *         - Password must be at least 8 characters
     *         - Username already taken
     *         - Email already registered
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

        // Create user
        $userId = $this->userRepository->create([
            'username' => $username,
            'email' => $email,
            'password' => $password,
            'display_name' => $username,
        ]);

        if ($isFirstUser) {
            // Minimum-viable admin bootstrap: the operator who
            // registered first owns the box. Phase D will replace this
            // with a real RBAC + invite flow.
            $this->userRepository->setAdmin($userId, true);
            $this->logger->info('Promoted first user to admin', [
                'user_id' => $userId,
                'username' => $username,
            ]);
        }

        $this->logger->info('User registered', ['user_id' => $userId, 'username' => $username]);

        $this->dispatchUserCreated($userId, $username, $email);

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
     *
     * @example
     * ```php
     * $result = $authManager->login('john_doe', 'secure_pass123', 'device-uuid-123');
     * ```
     */
    public function login(string $username, string $password, string $deviceId): array
    {
        $user = $this->userRepository->findByUsername($username);

        if (!$user || !$this->userRepository->verifyPassword($user['id'], $password)) {
            $this->auditLogger->logFailedAuth('invalid_credentials', [
                'username' => $username,
                'device_id' => $deviceId,
            ]);
            throw new \InvalidArgumentException('Invalid username or password');
        }

        // Update last login
        $this->userRepository->updateLastLogin($user['id']);

        $this->auditLogger->logLogin($user['id'], $deviceId, true);

        $this->logger->info('User logged in', ['user_id' => $user['id'], 'device_id' => $deviceId]);

        $userId = self::asString($user['id'] ?? null);
        $this->dispatchUserLoggedIn($userId, $deviceId);

        return $this->createAuthResponse($user['id']);
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

        $userId = $payload['sub'];

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
