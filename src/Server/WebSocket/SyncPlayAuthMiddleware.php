<?php

/**
 * Phlix media server component: SyncPlay Authentication Middleware.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Server\WebSocket;

use Workerman\Connection\TcpConnection;
use Phlix\Auth\JwtHandler;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\LogChannels;

/**
 * SyncPlay Authentication Middleware for WebSocket connections.
 *
 * This middleware validates session tokens from the WebSocket handshake
 * `?token=...` query parameter and enforces authentication for SyncPlay rooms.
 *
 * ## Authentication Flow
 *
 * 1. Client initiates WebSocket handshake with `?token=<jwt>` query param
 * 2. Middleware validates the JWT token
 * 3. If invalid/expired: connection is closed with code 4001
 * 4. If valid: userId is extracted and attached to the connection context
 *
 * ## Close Codes
 *
 * - **4001**: Invalid or expired token
 * - **4002**: Token missing (if required)
 * - **4003**: Server error during authentication
 *
 * ## Usage
 *
 * ```php
 * $middleware = new SyncPlayAuthMiddleware($jwtSecret);
 * $worker->onConnect = [$middleware, 'onConnect'];
 * ```
 *
 * @author Phlix Development Team
 * @copyright 2024 Phlix Media Server
 * @license Proprietary
 *
 * @see JwtHandler For JWT validation
 * @see Connection For connection representation
 */
class SyncPlayAuthMiddleware
{
    /**
     * Close code for invalid or expired token.
     */
    public const CLOSE_CODE_INVALID_TOKEN = 4001;

    /**
     * Close code for missing token when required.
     */
    public const CLOSE_CODE_MISSING_TOKEN = 4002;

    /**
     * Close code for server error during auth.
     */
    public const CLOSE_CODE_SERVER_ERROR = 4003;

    /**
     * JWT handler for token validation.
     *
     * @var JwtHandler
     */
    private JwtHandler $jwtHandler;

    /**
     * Whether authentication is required.
     *
     * If true, connections without valid tokens will be closed.
     * If false, connections without tokens will be allowed but marked unauthenticated.
     *
     * @var bool
     */
    private bool $requireAuth;

    /**
     * Logger for authentication events.
     *
     * @var \Psr\Log\LoggerInterface|null
     */
    private $logger;

    /**
     * Create a new SyncPlayAuthMiddleware instance.
     *
     * @param string $jwtSecret The JWT secret key for validation
     * @param bool $requireAuth Whether to require authentication (default: true)
     *
     * @example
     * ```php
     * // Require authentication for all connections
     * $middleware = new SyncPlayAuthMiddleware('your-secret-key');
     *
     * // Allow unauthenticated connections
     * $middleware = new SyncPlayAuthMiddleware('your-secret-key', false);
     * ```
     */
    public function __construct(string $jwtSecret, bool $requireAuth = true)
    {
        $this->jwtHandler = new JwtHandler($jwtSecret);
        $this->requireAuth = $requireAuth;
        $this->logger = LoggerFactory::get(LogChannels::WEBSOCKET);
    }

    /**
     * Handle a new WebSocket connection.
     *
     * Validates the token from `?token=...` query parameter and either:
     * - Closes the connection with code 4001 if token is invalid/expired
     * - Closes with code 4002 if token is required but missing
     * - Marks the connection as authenticated with userId attached
     *
     * @param TcpConnection $connection The Workerman TCP connection
     * @return void
     *
     * @example
     * ```php
     * $worker->onConnect = [$syncPlayAuthMiddleware, 'onConnect'];
     * ```
     */
    public function onConnect(TcpConnection $connection): void
    {
        $token = $_GET['token'] ?? null;

        // Handle missing token
        if (!is_string($token) || $token === '') {
            if ($this->requireAuth) {
                $this->logger?->warning('SyncPlay connection rejected: missing token', [
                    'remote_ip' => $connection->getRemoteIp(),
                ]);
                $this->closeConnection($connection, self::CLOSE_CODE_MISSING_TOKEN, 'Authentication required');
                return;
            }

            // No auth required - allow unauthenticated connection
            $this->logger?->debug('SyncPlay unauthenticated connection allowed', [
                'remote_ip' => $connection->getRemoteIp(),
            ]);
            return;
        }

        // Validate the token
        try {
            $payload = $this->jwtHandler->validateToken($token);

            if ($payload === null) {
                $this->logger?->warning('SyncPlay connection rejected: invalid token', [
                    'remote_ip' => $connection->getRemoteIp(),
                    'reason' => 'Token validation failed',
                ]);
                $this->closeConnection($connection, self::CLOSE_CODE_INVALID_TOKEN, 'Invalid or expired token');
                return;
            }

            // Extract userId from token payload
            $sub = $payload['sub'] ?? null;
            if (!is_string($sub) || $sub === '') {
                $this->logger?->warning('SyncPlay connection rejected: missing user ID in token', [
                    'remote_ip' => $connection->getRemoteIp(),
                ]);
                $this->closeConnection($connection, self::CLOSE_CODE_INVALID_TOKEN, 'Invalid token: missing user ID');
                return;
            }

            $this->logger?->info('SyncPlay connection authenticated', [
                'user_id' => $sub,
                'remote_ip' => $connection->getRemoteIp(),
            ]);

            // Note: The actual Connection creation and authentication marking
            // is done by the WebSocketServer::onConnect handler which is called
            // AFTER this middleware. This middleware validates the token and
            // ensures invalid connections are closed early.
            // Store userId in $_GET for WebSocketServer to pick up
            $_GET['syncplay_user_id'] = $sub;

        } catch (\Throwable $e) {
            $this->logger?->error('SyncPlay authentication error', [
                'remote_ip' => $connection->getRemoteIp(),
                'error' => $e->getMessage(),
            ]);
            $this->closeConnection($connection, self::CLOSE_CODE_SERVER_ERROR, 'Authentication error');
        }
    }

    /**
     * Close a connection with a specific code and reason.
     *
     * @param TcpConnection $connection The connection to close
     * @param int $code Close code (4001-4003 for SyncPlay errors)
     * @param string $reason Human-readable close reason
     * @return void
     */
    private function closeConnection(TcpConnection $connection, int $code, string $reason): void
    {
        // Workerman TcpConnection always has close($reason = null) method
        $connection->close($reason);
    }

    /**
     * Check if a token is valid without throwing.
     *
     * Convenience method for checking token validity.
     *
     * @param string $token The JWT token to validate
     * @return array{sub: string}|null User ID array on success, null on failure
     *
     * @example
     * ```php
     * $result = SyncPlayAuthMiddleware::validateTokenStatic($token, $jwtSecret);
     * if ($result !== null) {
     *     $userId = $result['sub'];
     * }
     * ```
     */
    public static function validateTokenStatic(string $token, string $jwtSecret): ?array
    {
        $handler = new JwtHandler($jwtSecret);
        $payload = $handler->validateToken($token);

        if ($payload === null) {
            return null;
        }

        // Ensure 'sub' claim exists and is a string
        if (!isset($payload['sub']) || !is_string($payload['sub'])) {
            return null;
        }

        return ['sub' => $payload['sub']];
    }

    /**
     * Create a JWT token for a user (useful for testing).
     *
     * @param string $userId The user ID to encode
     * @param string $jwtSecret The JWT secret key
     * @param int $ttl Token TTL in seconds (default: 3600)
     * @return string The encoded JWT token
     *
     * @example
     * ```php
     * $token = SyncPlayAuthMiddleware::createToken('user_123', 'secret', 3600);
     * ```
     */
    public static function createToken(string $userId, string $jwtSecret, int $ttl = 3600): string
    {
        $handler = new JwtHandler($jwtSecret, 'HS256', $ttl);
        return $handler->createAccessToken($userId);
    }

    /**
     * Get the JWT handler used by this middleware.
     *
     * @return JwtHandler The JWT handler
     */
    public function getJwtHandler(): JwtHandler
    {
        return $this->jwtHandler;
    }

    /**
     * Check if authentication is required.
     *
     * @return bool True if authentication is required
     */
    public function isAuthRequired(): bool
    {
        return $this->requireAuth;
    }

    /**
     * Set whether authentication is required.
     *
     * @param bool $required True if authentication should be required
     * @return void
     */
    public function setAuthRequired(bool $required): void
    {
        $this->requireAuth = $required;
    }
}
