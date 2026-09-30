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
 * 1. Client initiates the WebSocket handshake carrying its JWT either in the
 *    `Sec-WebSocket-Protocol: bearer, <jwt>` subprotocol (preferred — the same
 *    carrier law phlix-hub `:8804` ships, S237) or in the legacy `?token=<jwt>`
 *    query param (transitional; clients retire per estate policy
 *    WEBSOCKET_URL_QUERY_REFUSED). {@see resolveHandshakeToken()} is the law.
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
     * Subprotocol marker id of the bearer credential carrier — the exact name
     * phlix-hub `:8804` uses (`SyncPlayRelayWorker::BEARER_SUBPROTOCOL`, S237)
     * so one estate law has one wire vocabulary.
     */
    public const BEARER_SUBPROTOCOL = 'bearer';

    /**
     * The 101 response header line selecting the bearer marker back to the
     * client. NEVER the token — a credential is not a protocol-id, and
     * echoing it would re-publish on the RESPONSE wire the very secret the
     * carrier exists to keep off URLs (RFC 6455 §4.2.2 permits answering only
     * with a protocol the server actually supports; `bearer` is the only one).
     */
    public const BEARER_SUBPROTOCOL_ECHO = 'Sec-WebSocket-Protocol: bearer';

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
     * Authenticate a WebSocket connection at the handshake stage (SV-4.7).
     *
     * This is the REAL, wired auth path: {@see WebSocketServer::onWebSocketConnect}
     * runs at the WS-handshake stage — where the upgrade request's query string
     * (`?token=<jwt>`) is actually populated — extracts the token from the parsed
     * {@see \Workerman\Protocols\Http\Request}, and delegates to this method. On a
     * valid token the validated `sub` is handed straight to
     * {@see Connection::setAuthenticated()}; the caller closes the connection when
     * this returns false.
     *
     * Behaviour:
     * - Missing/empty token: rejected (returns false) when auth is required
     *   ({@see $requireAuth} — i.e. a JWT secret is configured); allowed anonymous
     *   (returns true) when auth is not required (dev, no secret).
     * - Invalid/expired token, or a token without a string `sub`: rejected.
     * - Valid token: marks the connection authenticated with the derived user id
     *   and returns true.
     *
     * Unlike {@see onConnect()} this does NOT read `$_GET` (empty/stale at the
     * TCP-accept stage under Workerman) and does NOT stash the user id in a
     * superglobal — it authenticates the {@see Connection} wrapper directly.
     *
     * @param Connection  $connection The WebSocket connection wrapper to authenticate.
     * @param string|null $token      The handshake credential resolved by
     *                                {@see resolveHandshakeToken()} (bearer
     *                                subprotocol or legacy query carrier).
     * @return bool True if the connection may proceed; false if it must be rejected/closed.
     */
    public function authenticateConnection(Connection $connection, ?string $token): bool
    {
        $remoteIp = $connection->getConnection()->getRemoteIp();

        if ($token === null || $token === '') {
            if ($this->requireAuth) {
                $this->logger?->warning('WS connection rejected: missing token', [
                    'remote_ip' => $remoteIp,
                ]);
                return false;
            }

            $this->logger?->debug('WS unauthenticated connection allowed (auth not required)', [
                'remote_ip' => $remoteIp,
            ]);
            return true;
        }

        try {
            $payload = $this->jwtHandler->validateToken($token);

            if ($payload === null) {
                $this->logger?->warning('WS connection rejected: invalid token', [
                    'remote_ip' => $remoteIp,
                    'reason' => 'Token validation failed',
                ]);
                return false;
            }

            $sub = $payload['sub'] ?? null;
            if (!is_string($sub) || $sub === '') {
                $this->logger?->warning('WS connection rejected: missing user ID in token', [
                    'remote_ip' => $remoteIp,
                ]);
                return false;
            }

            $connection->setAuthenticated(true, $sub);

            $this->logger?->info('WS connection authenticated', [
                'user_id' => $sub,
                'remote_ip' => $remoteIp,
            ]);
            return true;
        } catch (\Throwable $e) {
            $this->logger?->error('WS authentication error', [
                'remote_ip' => $remoteIp,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * LEGACY TCP-accept handler — kept for backward compatibility only.
     *
     * @deprecated Reads `$_GET['token']` at the TCP-accept stage, BEFORE the WS
     *   handshake, where the query string is not yet populated under Workerman, and
     *   stashes the user id in `$_GET`. Use {@see authenticateConnection()} from the
     *   handshake-stage {@see WebSocketServer::onWebSocketConnect} hook instead —
     *   that is the wired, correct-lifecycle auth path.
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

    /**
     * Whether a `Sec-WebSocket-Protocol` offer list includes the `bearer` marker.
     *
     * Per-entry RFC 7230 token comparison after OWS trim: `bearer-chat` is a
     * different protocol-id and does NOT count as an offer. Mirrors phlix-hub
     * `SyncPlayRelayWorker::negotiatedSubprotocolEcho()` — the echo gate for the
     * 101 answer lives here so server and hub negotiate identically.
     *
     * @param string|null $offeredProtocols Raw header value (absent = null).
     */
    public static function offersBearerSubprotocol(?string $offeredProtocols): bool
    {
        if ($offeredProtocols === null || $offeredProtocols === '') {
            return false;
        }

        foreach (explode(',', $offeredProtocols) as $protocolId) {
            if (trim($protocolId) === self::BEARER_SUBPROTOCOL) {
                return true;
            }
        }

        return false;
    }

    /**
     * The JWT carried by the `bearer` subprotocol carrier, or null when it holds none.
     *
     * Browser WebSocket APIs cannot set an Authorization header, so the carrier
     * form is `Sec-WebSocket-Protocol: bearer, <jwt>`: the `bearer` entry is the
     * marker and the first other non-empty entry is the credential. Mirrors
     * phlix-hub `ClientRelayWorker::extractClientToken()` subprotocol step.
     *
     * @param string|null $offeredProtocols Raw header value (absent = null).
     */
    public static function bearerSubprotocolToken(?string $offeredProtocols): ?string
    {
        if ($offeredProtocols === null || $offeredProtocols === '') {
            return null;
        }

        foreach (explode(',', $offeredProtocols) as $entry) {
            $candidate = trim($entry);
            if ($candidate !== '' && $candidate !== self::BEARER_SUBPROTOCOL) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Resolve the handshake credential from the two transitional carriers.
     *
     * The priority law (estate policy WEBSOCKET_URL_QUERY_REFUSED; phlix-syncplay
     * SPEC.md §8.4 — `:8097` CURRENT=query, TARGET=bearer subprotocol):
     *
     * 1. The `bearer` subprotocol carrier wins when it carries a token.
     * 2. `?token=<jwt>` is the legacy fallback while clients retire it.
     * 3. Both carriers present with DIFFERENT tokens → reject (`mismatch`): a
     *    half-migrated client must fail loudly at the handshake instead of
     *    silently authenticating with one credential while presenting another.
     * 4. An empty `?token=` value carries no credential and counts as absent.
     *
     * @param string|null $offeredProtocols Raw `Sec-WebSocket-Protocol` header value.
     * @param string|null $queryToken       Raw `token` query param value.
     * @return array{token: ?string, carrier: 'bearer'|'query'|'none', mismatch: bool}
     *         `token` is the credential to validate (null when absent or
     *         mismatched); `carrier` names the winning carrier for diagnostics
     *         and never carries credential material itself.
     */
    public static function resolveHandshakeToken(?string $offeredProtocols, ?string $queryToken): array
    {
        $bearerToken = self::bearerSubprotocolToken($offeredProtocols);
        $legacyToken = ($queryToken === null || $queryToken === '') ? null : $queryToken;

        if ($bearerToken !== null && $legacyToken !== null && $bearerToken !== $legacyToken) {
            return ['token' => null, 'carrier' => 'none', 'mismatch' => true];
        }

        if ($bearerToken !== null) {
            return ['token' => $bearerToken, 'carrier' => 'bearer', 'mismatch' => false];
        }

        if ($legacyToken !== null) {
            return ['token' => $legacyToken, 'carrier' => 'query', 'mismatch' => false];
        }

        return ['token' => null, 'carrier' => 'none', 'mismatch' => false];
    }

    /**
     * Mask every `token=` query value in a URL/URI so it can reach a log line.
     *
     * Bearer credentials in query strings land in access logs, proxy logs and
     * histories — the exact exposure the estate carrier law removes from the
     * URL. While legacy `?token=` clients still exist, any handshake URI this
     * package logs passes through here first, so diagnostics can record the
     * requested path without ever echoing the credential back.
     *
     * Matching is exhaustive over token-named params: whole names only (a
     * case-insensitive `[?&]token=` — HTTP query names reach PHP lowercased by
     * parse_str, so `?TOKEN=`, `?Token=` and `?token=` are the SAME param
     * server-side and every spelling gets masked here), and values stop at
     * `&`/`#` boundaries. Repeat params (`?TOKEN=jwt&token=secret`) are all
     * redacted regardless of which one the server-side parse collapsed to
     * (last-wins) — over-redacting a log line is always safe; leaking one
     * unmasked spelling is not. Near-miss names (`?mytoken=`) are untouched.
     * The masked occurrence is normalised to lowercase `token=[redacted]`.
     */
    public static function redactTokenQuery(string $uri): string
    {
        $redacted = preg_replace('/([?&])token=[^&#]*/i', '$1token=[redacted]', $uri);

        return $redacted ?? $uri;
    }
}
