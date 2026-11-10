<?php

/**
 * Phlix media server component: SyncPlay Authentication Middleware.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Server\WebSocket;

use Phlix\Admin\SettingsRepository;
use Phlix\Auth\AuthManager;
use Phlix\Auth\JwtHandler;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\LogChannels;

/**
 * SyncPlay Authentication Middleware for WebSocket connections.
 *
 * Validates the handshake JWT (bearer subprotocol preferred, legacy
 * `?token=` accepted only while the retirement gate resolves to allow — see
 * docs/dev/WEBSOCKET_AUTH_CARRIERS.md) and enforces
 * authentication for SyncPlay rooms. The legacy query carrier additionally
 * answers to the CP-007 retirement gate, resolved live per decision by
 * {@see QueryTokenGatePolicy} (precedence: explicit env `PHLIX_WS_QUERY_TOKEN`
 * > admin row `auth.ws_query_token` > PHASE B default deny, flipped at
 * CP-024, admin control wired 2026-11-10); bearer and admitted-cookie legs
 * are never governed by it.
 *
 * ## Authentication Flow
 *
 * 1. Client initiates the WebSocket handshake carrying its JWT either in the
 *    `Sec-WebSocket-Protocol: bearer, <jwt>` subprotocol (preferred — the same
 *    carrier law phlix-hub `:8804` ships, S237) or in the legacy `?token=<jwt>`
 *    query param (transitional; clients retire per estate policy
 *    WEBSOCKET_URL_QUERY_REFUSED). {@see resolveHandshakeToken()} is the law.
 * 2. {@see authenticateConnection()} validates the JWT at the handshake stage
 * 3. If invalid/expired (or missing while auth is required): the gate returns
 *    false and the caller — {@see WebSocketServer::onWebSocketConnect()} —
 *    closes the socket
 * 4. If valid: userId is extracted and attached to the connection context
 *
 * ## Usage (the live wiring)
 *
 * ```php
 * $middleware = new SyncPlayAuthMiddleware($jwtSecret, true, $authManager);
 * // called from WebSocketServer::onWebSocketConnect():
 * if (!$middleware->authenticateConnection($wsConnection, $token)) {
 *     $connection->close(); // caller rejects the handshake
 * }
 * ```
 *
 * @author Phlix Development Team
 *
 * @see JwtHandler For JWT validation
 * @see Connection For connection representation
 */
class SyncPlayAuthMiddleware
{
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
     * Query-carrier gate dispositions (CP-007/CP-024, COOKIE-DESIGN.md §4.1;
     * the stored tier landed 2026-11-10 — {@see QueryTokenGatePolicy}).
     *
     * The `:8097` legacy `?token=` carrier is observed under `allow` (every
     * query handshake logs one redacted telemetry line) and REFUSED pre-101
     * under `deny`. PHASE A shipped `allow` as the default; PHASE B (CP-024,
     * shipped once the last query-producing client — mobile, §4.4 — flipped)
     * ships `deny`: the straggler escape hatch is an explicit
     * `PHLIX_WS_QUERY_TOKEN=allow`, which restores the PHASE A behavior
     * byte-ident. PHASE C removes the branch. Tiers: env seam
     * `PHLIX_WS_QUERY_TOKEN` (config `websocket.query_token`) above the
     * shared-schema admin row `auth.ws_query_token` (same `allow`/`deny`
     * vocabulary) above the shipped deny — resolved in ONE place,
     * {@see QueryTokenGatePolicy::allowsQueryCarrier()}.
     */
    public const QUERY_TOKEN_ALLOW = 'allow';
    public const QUERY_TOKEN_DENY = 'deny';

    /**
     * JWT handler for token validation.
     *
     * @var JwtHandler
     */
    private JwtHandler $jwtHandler;

    /**
     * HTTP-parity liveness seam: the SAME {@see AuthManager} the HTTP rail
     * authenticates through ({@see \Phlix\Server\Http\RequestAuthenticator}), or
     * null when unwired.
     *
     * When wired, every handshake credential must additionally survive
     * {@see AuthManager::validateAccessToken()} — user status active (S1),
     * deleted-account null (H-1), token-revocation watermark vs `iat` (M-1),
     * all through the same 5s LRU cache HTTP uses. When null (direct
     * construction in tests, or a dev boot without the container), the gate
     * keeps its crypto + `type === 'access'` law and NOTHING more — which is
     * exactly why the resident `start.php` wiring supplies it: without the
     * seam, a revoked identity would stay live on :8097 for the token's TTL.
     *
     * @var AuthManager|null
     */
    private ?AuthManager $authManager;

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
     * Whether the legacy `?token=` query carrier may complete a handshake
     * (CP-007 §4.1 + the 2026-11-10 admin-control wiring). THE ONE LAW
     * HOME for the gate's effective state is {@see QueryTokenGatePolicy}:
     * env > admin row > PHASE B default deny, resolved LIVE per decision —
     * this field holds the resolver, not a parsed snapshot, because a
     * resident `:8097` worker must answer with the admin's CURRENT word
     * (the de573d6e rework law: an instance-frozen answer is a process
     * cache in a per-decision costume). Bearer and admitted-cookie
     * carriers are governed by {@see resolveHandshakeToken()} alone; this
     * gate never touches them.
     *
     * @var QueryTokenGatePolicy
     */
    private QueryTokenGatePolicy $queryGate;

    /**
     * Create a new SyncPlayAuthMiddleware instance.
     *
     * @param string $jwtSecret The JWT secret key for validation
     * @param bool $requireAuth Whether to require authentication (default: true)
     * @param AuthManager|null $authManager Optional HTTP-parity liveness seam —
     *   the resident `start.php` path supplies the container's AuthManager so
     *   the handshake enforces the same active/deleted/revocation law every
     *   HTTP request passes; without it only crypto + access-type are checked
     *   (tests/dev direct construction).
     * @param string|null $queryTokenPolicy CP-007 query-carrier gate ENV tier —
     *   the `websocket.query_token` config value (env seam
     *   PHLIX_WS_QUERY_TOKEN) when set; null (the parameter default, and the
     *   nullableText form of unset/empty) means the operator stayed silent and
     *   the admin row / PHASE B default decide — {@see QueryTokenGatePolicy}
     *   for the full precedence. Legacy 1-3-arg construction therefore resolves
     *   to `deny` through the resolver's default tier, the same answer CP-024
     *   shipped as the literal `deny` parameter default.
     * @param SettingsRepository|null $queryTokenSettings Admin-row store for
     *   tier 2 (`auth.ws_query_token`, read live per decision); null — every
     *   pre-wiring construction shape — leaves the row tier unavailable and
     *   the PHASE B default answers, movement-invisible.
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
    public function __construct(
        string $jwtSecret,
        bool $requireAuth = true,
        ?AuthManager $authManager = null,
        ?string $queryTokenPolicy = null,
        ?SettingsRepository $queryTokenSettings = null
    ) {
        $this->jwtHandler = new JwtHandler($jwtSecret);
        $this->requireAuth = $requireAuth;
        $this->authManager = $authManager;
        $this->logger = LoggerFactory::get(LogChannels::WEBSOCKET);
        $this->queryGate = new QueryTokenGatePolicy($queryTokenPolicy, $queryTokenSettings, $this->logger);
    }

    /**
     * Read the query-carrier gate policy — pure, fail-OPEN by design.
     *
     * This is the ENV tier's explicit-value vocabulary and it is UNMOVED:
     * only an explicit `deny` (trim + case-insensitive) denies; every other
     * value — `allow`, empty, garbage — allows. This mirrors the estate's
     * opt-in-tightening family (SH-064 TLS gate): a typo in a production env
     * file must never silently lock legacy clients out; tightening happens
     * only when the operator says so unambiguously.
     *
     * Scope note (2026-11-10 wiring): the resolver applies this reading to
     * SET env values only ({@see QueryTokenGatePolicy::allowsQueryCarrier()}
     * treats unset/empty as env silence and defers to the admin row / PHASE B
     * default) — the admin row has its OWN stricter law (exact enum member or
     * fail-closed deny), because a stored garbage row is an explicit-but-
     * broken admin statement, not an operator typo mid-boot.
     *
     * The CP-024 PHASE B flip changed WHAT FEEDS THIS READER by default —
     * `config/server.php` handed it the string 'deny' whenever the env knob
     * was unset or empty, so the shipped posture denied — not what an
     * explicit knob value means: a set-but-unrecognized value still reads as
     * allow under the rule above. The 11-10 wiring replaces that synthesized
     * 'deny' with nullable pass-through so silence can reach tier 2.
     *
     * @param string $rawPolicy Config/env value as supplied.
     * @return bool True when the query carrier stays accepted.
     */
    public static function parseQueryTokenPolicy(string $rawPolicy): bool
    {
        return strtolower(trim($rawPolicy)) !== self::QUERY_TOKEN_DENY;
    }

    /**
     * This middleware's live query-carrier gate disposition (CP-007;
     * admin-row tier wired 2026-11-10 — {@see QueryTokenGatePolicy}).
     *
     * Resolved FRESH on every call — env tier (boot value), then the admin
     * row `auth.ws_query_token` (live SELECT, honored on the very next
     * handshake, no restart), then the PHASE B default deny. Consumed by
     * {@see WebSocketServer::onWebSocketConnect()} at the query
     * carrier leg ONLY — the telemetry/rejection log site and the pre-101
     * reject both live there, keeping this class the credential-law home and
     * the server the socket-lifecycle home, exactly like every other reject
     * idiom on that hook.
     */
    public function queryTokenCarrierAllowed(): bool
    {
        return $this->queryGate->allowsQueryCarrier();
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
     * - Valid token whose `type` claim is not `access` (e.g. the 7-day refresh
     *   token, which is otherwise signing-valid): rejected (R17-02).
     * - Valid access token for an account that is not `active`, whose row no
     *   longer exists (deleted), or whose `iat` falls at/before the user's
     *   token-revocation watermark: rejected when the {@see $authManager}
     *   liveness seam is wired (the resident `:8097` path) — the EXACT
     *   {@see AuthManager::validateAccessToken()} every HTTP request passes
     *   through `RequestAuthenticator`, including its 5s cached-state cadence.
     * - Valid, live access token: marks the connection authenticated with the
     *   derived user id and returns true.
     *
     * Account-liveness bound (honest, resident-worker law): the check runs at
     * the HANDSHAKE, mirroring HTTP's per-request re-check as closely as a
     * long-lived socket allows. A connection accepted while its identity was
     * live and deactivated afterwards stays open until it disconnects — HTTP
     * revokes such a token on the very next request; the WS rail has no next
     * request to gate. Deliberately NOT shipped here: a revocation reaper
     * sweeping open sockets (out of scope; the per-message probe through the
     * same cached seam is the cheap follow-up if the window is ever tightened —
     * see the fixlog rider note). On the unwired direct-construction path
     * (tests/dev, no container) the seam is absent and the gate keeps only the
     * crypto + access-type law.
     *
     * It does NOT read `$_GET` and does NOT stash the user id in a superglobal
     * — it authenticates the {@see Connection} wrapper directly. (The
     * deprecated TCP-accept rail that did both — `onConnect()` — was deleted
     * with INFO-SERVER-32: under Workerman `$_GET` is empty/stale at
     * TCP-accept anyway, and a dormant superglobal reader on an auth class is
     * a regression waiting to be re-wired.)
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

            // R17-02: only ACCESS tokens may authenticate a WebSocket. The
            // refresh token is a signing-valid, unexpired credential too —
            // validateToken() alone would let a stolen long-lived refresh token
            // (7d TTL vs the access token's 1h) open a live SyncPlay session.
            if (($payload['type'] ?? null) !== 'access') {
                $this->logger?->warning('WS connection rejected: token type is not access', [
                    'remote_ip' => $remoteIp,
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

            // HTTP-parity liveness law: when the AuthManager seam is wired (the
            // resident :8097 path), the same token must additionally survive the
            // EXACT call every HTTP request passes — active status (S1),
            // deleted-account null (H-1), revocation watermark vs iat (M-1),
            // through the same 5s cached-state cadence. Re-verification of the
            // signature inside is negligible (HS256 + cache hit) and buys
            // identity-of-code-path: this gate cannot drift from the HTTP gate
            // because it IS the HTTP gate. Failure rejections take the SAME
            // false→pre-101-close path as an invalid token — no new wire codes.
            if ($this->authManager !== null && $this->authManager->validateAccessToken($token) === null) {
                $this->logger?->warning(
                    'WS connection rejected: account not live (disabled, deleted, or token revoked)',
                    [
                        'remote_ip' => $remoteIp,
                        'user_id' => $sub,
                    ]
                );
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
     * Check if a token is valid without throwing.
     *
     * Convenience method for checking token validity.
     *
     * Scope: this is a CRYPTO + access-type verdict only. It holds no secret
     * beyond `$jwtSecret` and no AuthManager, so it CANNOT apply the
     * user-liveness law (active/deleted/revocation watermark) that the wired
     * handshake gate enforces — do not treat a non-null result as
     * "this token authenticates a WS handshake today".
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

        // R17-02: access-only law, mirrored on this public rail so a caller
        // cannot get a "valid" verdict for a refresh token here while the
        // handshake paths reject it.
        if (($payload['type'] ?? null) !== 'access') {
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
     * Resolve the handshake credential from the three carriers.
     *
     * The priority law (estate policy WEBSOCKET_URL_QUERY_REFUSED; phlix-syncplay
     * SPEC.md §8.4 — `:8097` CURRENT=query, TARGET=bearer subprotocol; cookie
     * carrier per COOKIE-DESIGN.md §2.3, CP-006):
     *
     * 1. The `bearer` subprotocol carrier wins when it carries a token.
     * 2. The `phlix_session` cookie rides THIRD: same-origin browser sockets
     *    send the jar automatically, so a hosted-SPA handshake needs no
     *    explicit credential — but the cookie is accepted ONLY when
     *    `$sessionCookieAllowed` says so, and the caller computes that from
     *    the SAME state-changing request-origin law the HTTP rails enforce
     *    (absent-or-same-site; both-origin-headers absent FAILS CLOSED: an
     *    ambient credential presented with no origin context is exactly the
     *    smuggling shape the mirror-of-{@see \Phlix\Server\Http\RequestAuthenticator::validateCsrfOrigin()}
     *    reading of §2.3 refuses. Native clients are untouched — they carry
     *    no jar, so the gate never runs for them).
     * 3. `?token=<jwt>` is the legacy fallback while clients retire it.
     * 4. Bearer and query present with DIFFERENT tokens → reject (`mismatch`):
     *    a half-migrated client must fail loudly at the handshake instead of
     *    silently authenticating with one credential while presenting another.
     *    The law stays EXACTLY between the two EXPLICIT carriers: a jar is
     *    ambient and may be stale, so an explicit credential silently
     *    outranks it (priority is the disclosure), and a REFUSED cookie never
     *    blocks the query carrier — a rejected ambient value is not a
     *    credential the client is entitled to be authenticated as.
     * 5. An empty `?token=` value or empty cookie carries no credential and
     *    counts as absent.
     *
     * @param string|null $offeredProtocols    Raw `Sec-WebSocket-Protocol` header value.
     * @param string|null $queryToken          Raw `token` query param value.
     * @param string|null $sessionCookie       Raw `phlix_session` cookie value, if any.
     * @param bool        $sessionCookieAllowed Whether the request-origin law admits
     *                                         the cookie carrier for THIS handshake.
     * @return array{token: ?string, carrier: 'bearer'|'cookie'|'query'|'none', mismatch: bool}
     *         `token` is the credential to validate (null when absent or
     *         mismatched); `carrier` names the winning carrier for diagnostics
     *         (and drives the 101 marker-echo law) and never carries
     *         credential material itself.
     *
     * @since 1.3.0 (cookie carrier; carriers resolver since 1.0.0)
     */
    public static function resolveHandshakeToken(
        ?string $offeredProtocols,
        ?string $queryToken,
        ?string $sessionCookie = null,
        bool $sessionCookieAllowed = false
    ): array {
        $bearerToken = self::bearerSubprotocolToken($offeredProtocols);
        $legacyToken = ($queryToken === null || $queryToken === '') ? null : $queryToken;
        $cookieToken = ($sessionCookie === null || $sessionCookie === '') ? null : $sessionCookie;

        if ($bearerToken !== null && $legacyToken !== null && $bearerToken !== $legacyToken) {
            return ['token' => null, 'carrier' => 'none', 'mismatch' => true];
        }

        if ($bearerToken !== null) {
            return ['token' => $bearerToken, 'carrier' => 'bearer', 'mismatch' => false];
        }

        if ($cookieToken !== null && $sessionCookieAllowed) {
            return ['token' => $cookieToken, 'carrier' => 'cookie', 'mismatch' => false];
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
     * Matching is exhaustive over token-named params: whole names only,
     * case-insensitively (`[?&]token=` with `/i`), values stopping at `&`/`#`
     * boundaries. The case-insensitivity is a REDACTION statement, not an
     * auth statement: parse_str preserves key case and Workerman's
     * `Request::get('token')` is an exact-key lookup, so a wrong-case spelling
     * like `?TOKEN=` never populates the credential read and never
     * authenticates the handshake. It still matters here because this helper
     * masks the RAW URI that the mismatch-forensics log line writes verbatim:
     * a request can carry a live JWT (valid against REST) under any case
     * spelling beside a lowercase decoy `?token=` — the exact half-migration
     * shape that fires the mismatch — and a lowercase-only regex masked only
     * the decoy while the real credential reached the log unredacted. So
     * every token-named spelling gets masked; the cost is mild
     * over-redaction of spellings the exact-key read would never treat as
     * credentials, which is the safe direction on a log-only path —
     * over-redacting a log line is always safe; leaking one unmasked
     * spelling is not. Near-miss names (`?mytoken=`) are untouched: the
     * case-insensitivity applies to the NAME, never to name substrings. The
     * masked occurrence is normalised to lowercase `token=[redacted]`.
     */
    public static function redactTokenQuery(string $uri): string
    {
        $redacted = preg_replace('/([?&])token=[^&#]*/i', '$1token=[redacted]', $uri);

        return $redacted ?? $uri;
    }
}
