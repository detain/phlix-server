<?php

/**
 * Phlix media server component: HTTP controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers\Auth;

use Phlix\Auth\AuthManager;
use Phlix\Auth\QuickConnectPair;
use Phlix\Auth\QuickConnectStateStore;
use Phlix\Auth\QuickConnectStateStoreInterface;
use Phlix\Auth\RateLimitException;
use Phlix\Common\RateLimit\RateLimiterInterface;
use Phlix\Plugins\OAuth2\CallbackUrl;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Stats\ClientHeartbeatStore;
use Phlix\Stats\ClientHeartbeatStoreInterface;
use Workerman\MySQL\Connection;

/**
 * Quick-connect device pairing + consent-gated client telemetry (S518 / AD-25 + AD-27).
 *
 * ## The pairing flow (AD-25)
 *
 *   TV (unpaired)                       phone (signed-in app)
 *     | POST .../initiate ----> create pending {code, secret}
 *     | <---- {code, secret, expiresIn}   (secret stays with the TV)
 *     |  shows code + QR(code+secret)
 *     | GET .../{code}/status (poll)      phone: POST .../{code}/approve {secret}
 *     | <---- {state: pending}            (behind AuthMiddleware — the session
 *     | <---- {state: approved}            IS the approval authority)
 *     | POST .../{code}/token {secret}
 *     | <---- {accessToken, refreshToken, serverUrl, ...}  (one-shot redemption)
 *
 * ## Auth placement — every decision, with its reason
 *
 * - `initiate` PUBLIC. The TV has no credential yet; requiring one inverts the
 *   flow. Its budget is the IP-keyed `quick_connect_initiate` limiter.
 * - `status` PUBLIC + rate-limited (AC-1). The poller is the same credential-less
 *   TV. A guessed code reveals only the state of a pairing the guesser cannot
 *   redeem (the secret is 256-bit and never derivable from the state), and
 *   unknown/expired-vs-never-existed is still bounded by the limiter budget.
 * - `approve` AUTHENTICATED — the only quick-connect route behind the
 *   `AuthMiddleware` route group, per the mint's ownership law: approval means
 *   "a logged-in account consents to hand this device its identity", so the
 *   session IS the authority and the body's secret proves possession of the
 *   scanned QR. The approving `userId` is taken from the validated session
 *   (`$request->userId`), NEVER from the body — no new trust boundary is
 *   invented, and the pairing cannot be self-approved by the unauthenticated TV.
 *   Per-user limiter `quick_connect_approve` backs the secret with an
 *   attempt budget (anti code-guess).
 * - `token` PUBLIC, deliberately. The caller IS the credential-less TV; forcing a
 *   session here would deadlock the flow. What gates it is the pairing secret
 *   itself (constant-time compared, server-held, one-shot): `approved` + matching
 *   secret + the row then vanishes. It shares the `quick_connect_status`
 *   bucket — the TV's poll-then-redeem pair is ONE polling surface and one
 *   budget keeps the doctrine simple.
 * - `heartbeat` PUBLIC pre-pairing telemetry; the consent flag in the body is
 *   the gate (fail-fast 400 `consent_required` when absent or false).
 *
 * ## Why token is NOT folded into status (the sketch's single-poll variant)
 *
 * `status` is polled every couple of seconds by every TV on the LAN; `token`
 * happens exactly once per pairing. Folding issuance into the poll would put the
 * long-lived secret on the hot path (logged, retried, proxied far more often),
 * and would make "polling for state" and "burning the pairing" the same
 * operation — a retry-safe read must never be a one-shot mutation. The live
 * auth stack (per-surface limiter doctrine, `buildAuthResponse` living on the
 * login path) prefers the separate, explicit exchange, so that is the shape.
 *
 * ## Response shape
 *
 * The token body is the survey-d camelCase contract
 * (`accessToken`/`refreshToken`/`serverUrl`) with the full
 * {@see AuthManager::buildAuthResponse()} payload folded in under the same
 * camelCase names the sketch sets — snake_case `login` keeps its shape; this
 * endpoint's contract is the sketch, and the sketch says camelCase.
 *
 * @package Phlix\Server\Http\Controllers\Auth
 * @since 1.2.3
 */
final class QuickConnectController
{
    /**
     * S518 lane survival token — code-resident by design (this constant is the
     * single home; the suite asserts it executes, prose files never carry it).
     */
    public const string SURVIVAL_TOKEN = 'S518QCTELX9P7';

    /** Telemetry taxonomy caps mirror the migration-106 column widths. */
    private const int MAX_VERSION_LENGTH = ClientHeartbeatStoreInterface::MAX_VERSION_LENGTH;

    private const int MAX_CLIENT_TYPE_LENGTH = ClientHeartbeatStoreInterface::MAX_CLIENT_TYPE_LENGTH;

    private const int MAX_BUILD_TOKEN_LENGTH = ClientHeartbeatStoreInterface::MAX_BUILD_TOKEN_LENGTH;

    /**
     * Instance id shape: client-generated opaque id, 8-64 chars of the
     * URL/QR-safe set. (The quick-connect CODE has its own stricter alphabet in
     * {@see QuickConnectStateStore::normalizeCode()} — do not conflate them.)
     */
    private const string INSTANCE_ID_PATTERN = '/^[A-Za-z0-9._-]{8,64}$/';

    /**
     * Pairing store and heartbeat landing. Resolved once here (injected store
     * wins; else built from the shared pooled Connection DI injects; else null,
     * which every handler answers 503 — the no-container degraded fallback is a
     * test venue and lying about a persisted pairing would be worse than a 5xx).
     */
    private ?QuickConnectStateStoreInterface $pairs;

    private ?ClientHeartbeatStoreInterface $heartbeats;

    /**
     * @param AuthManager              $authManager      Mints the session pair on redemption
     *                                                   (same builder the login path uses).
     * @param QuickConnectStateStoreInterface|null $pairs         Explicit store override (tests).
     * @param ClientHeartbeatStoreInterface|null $heartbeats      Explicit landing override (tests).
     * @param RateLimiterInterface|null $initiateLimiter `quick_connect_initiate` profile; null = no-op.
     * @param RateLimiterInterface|null $statusLimiter   `quick_connect_status` profile (status + token).
     * @param RateLimiterInterface|null $approveLimiter  `quick_connect_approve` profile (per user).
     * @param RateLimiterInterface|null $telemetryLimiter `telemetry_heartbeat` profile.
     * @param Connection|null          $db               Shared pooled connection the default stores
     *                                                   build on (PHP-DI binds it explicitly —
     *                                                   optional params are otherwise skipped).
     * @param string                   $serverUrl        Operator-configured absolute base URL
     *                                                   (`hub.public_url`); '' = derive per-request.
     */
    public function __construct(
        private readonly AuthManager $authManager,
        ?QuickConnectStateStoreInterface $pairs = null,
        ?ClientHeartbeatStoreInterface $heartbeats = null,
        private readonly ?RateLimiterInterface $initiateLimiter = null,
        private readonly ?RateLimiterInterface $statusLimiter = null,
        private readonly ?RateLimiterInterface $approveLimiter = null,
        private readonly ?RateLimiterInterface $telemetryLimiter = null,
        ?Connection $db = null,
        private readonly string $serverUrl = '',
    ) {
        $this->pairs = $pairs ?? ($db !== null ? new QuickConnectStateStore($db) : null);
        $this->heartbeats = $heartbeats ?? ($db !== null ? new ClientHeartbeatStore($db) : null);
    }

    // -----------------------------------------------------------------
    // AD-25 — quick-connect pairing
    // -----------------------------------------------------------------

    /**
     * POST /api/v1/auth/quick-connect/initiate — open a pairing window.
     *
     * PUBLIC (see class docblock for the full auth law). No request fields; an
     * unparseable body is simply an empty body to this endpoint. Mints the
     * unambiguous 6-letter code and 256-bit secret; the secret is returned once,
     * to this caller, and is the only thing that later redeems the pairing.
     *
     * @param Request                $request Route request.
     * @param array<string, string>  $params  Path params (unused).
     */
    public function initiate(Request $request, array $params): Response
    {
        $this->enforceRateLimit($this->initiateLimiter, 'qc-init:' . $request->getTrustedClientIp());

        if ($this->pairs === null) {
            return $this->unavailable();
        }

        try {
            $pair = $this->pairs->issue();
        } catch (\Throwable) {
            // Fail closed: no row persisted ⇒ no pairing handed out.
            return $this->unavailable();
        }

        return (new Response())->json([
            'code' => $pair->code,
            'secret' => $pair->secret,
            'expiresIn' => max(0, $pair->expiresAt - time()),
        ]);
    }

    /**
     * GET /api/v1/auth/quick-connect/{code}/status — the TV's cheap poll.
     *
     * PUBLIC + IP-rate-limited. NEVER accepts or returns the secret. States
     * follow the survey-d enum exactly: `pending`, `approved`, `denied`
     * (reserved — no server surface writes it yet; kept in-contract for the
     * future deny half) and `expired` (row present but past its window). A code
     * that never existed answers 404 `pairing_not_found`, and that indistinction
     * is the anti-oracle posture: shape-invalid codes never reach SQL at all.
     *
     * @param Request               $request Route request.
     * @param array<string, string> $params  `code` from the path.
     */
    public function status(Request $request, array $params): Response
    {
        $this->enforceRateLimit($this->statusLimiter, 'qc-status:' . $request->getTrustedClientIp());

        $code = QuickConnectStateStore::normalizeCode($params['code'] ?? '');
        if ($code === null) {
            return $this->pairingNotFound();
        }

        if ($this->pairs === null) {
            return $this->unavailable();
        }

        $pair = $this->pairs->find($code);
        if ($pair === null) {
            return $this->pairingNotFound();
        }

        if ($pair->isExpired(time())) {
            return (new Response())->json(['state' => 'expired']);
        }

        return (new Response())->json(['state' => $pair->state]);
    }

    /**
     * POST /api/v1/auth/quick-connect/{code}/approve — the signed-in yes.
     *
     * AUTHENTICATED (AuthMiddleware group — the only quick-connect route that
     * requires the session). The identity granted to the TV is
     * `$request->userId`, the middleware-validated session subject; the body
     * carries only `{secret}` proving the phone scanned this pairing's QR. A
     * wrong secret is 403, a pending→approved race lost on a second phone is
     * 409, and an unknown/expired code is 404 — no state is disclosed either way.
     *
     * @param Request               $request Route request (authenticated).
     * @param array<string, string> $params  `code` from the path.
     */
    public function approve(Request $request, array $params): Response
    {
        $userId = $request->userId;
        if ($userId === null || $userId === '') {
            // Belt to AuthMiddleware's braces: a session-less request must never
            // approve, not even if route wiring regressed.
            return (new Response())->status(401)->json(['error' => 'Unauthorized', 'code' => 'unauthorized']);
        }

        $this->enforceRateLimit($this->approveLimiter, 'qc-approve:' . $userId);

        if ($this->pairs === null) {
            return $this->unavailable();
        }

        $code = QuickConnectStateStore::normalizeCode($params['code'] ?? '');
        $body = $request->body;
        $secret = $body['secret'] ?? null;
        if ($code === null || !is_string($secret) || $secret === '') {
            return (new Response())->status(400)->json(['error' => 'secret is required', 'code' => 'invalid_request']);
        }

        $result = $this->pairs->approve($code, $secret, $userId);
        if ($result === QuickConnectStateStore::RESULT_APPROVED) {
            return (new Response())->json(['state' => QuickConnectPair::STATE_APPROVED]);
        }

        return match ($result) {
            QuickConnectStateStore::RESULT_BAD_SECRET => (new Response())
                ->status(403)
                ->json(['error' => 'Invalid pairing secret', 'code' => 'invalid_secret']),
            QuickConnectStateStore::RESULT_NOT_READY => (new Response())
                ->status(409)
                ->json(['error' => 'Pairing is not pending', 'code' => 'pairing_not_pending']),
            default => $this->pairingNotFound(),
        };
    }

    /**
     * POST /api/v1/auth/quick-connect/{code}/token — redeem the pairing.
     *
     * PUBLIC by necessity (the redeemer is the credential-less TV); the pairing
     * secret in the body is the bearer credential — constant-time compared,
     * one-shot: a successful exchange DELETES the pairing row under the same
     * row lock that validated it (double-spend is structurally refused, and a
     * replayed secret gets 404 like any spent pairing). The minted token is the
     * same access/refresh pair `POST /api/v1/auth/login` hands out
     * ({@see AuthManager::buildAuthResponse()}), for the user who approved —
     * the session IS the ownership proof; nothing here invents a new trust.
     *
     * @param Request               $request Route request.
     * @param array<string, string> $params  `code` from the path.
     */
    public function token(Request $request, array $params): Response
    {
        // Same bucket as the status poll: poll-then-redeem is one TV-side surface.
        $this->enforceRateLimit($this->statusLimiter, 'qc-status:' . $request->getTrustedClientIp());

        $code = QuickConnectStateStore::normalizeCode($params['code'] ?? '');
        $body = $request->body;
        $secret = $body['secret'] ?? null;
        if ($code === null || !is_string($secret) || $secret === '') {
            return (new Response())->status(400)->json(['error' => 'secret is required', 'code' => 'invalid_request']);
        }

        if ($this->pairs === null) {
            return $this->unavailable();
        }

        [$result, $pair] = $this->pairs->consumeApproved($code, $secret);
        if ($result !== QuickConnectStateStore::RESULT_APPROVED || $pair === null || $pair->userId === null) {
            // Unknown, spent, wrong-secret and not-yet-approved collapse here to
            // the not-found shape: this endpoint must not confirm to a caller
            // holding a wrong secret that the code itself was real.
            return $this->pairingNotFound();
        }

        if ($pair->isExpired(time())) {
            // Belt: the store filters live rows; a clock-skew survivor must not mint.
            return $this->pairingNotFound();
        }

        $auth = $this->authManager->buildAuthResponse($pair->userId);

        return (new Response())->json([
            'accessToken' => $auth['access_token'],
            'refreshToken' => $auth['refresh_token'],
            'tokenType' => $auth['token_type'],
            'expiresIn' => $auth['expires_in'],
            'profileId' => $auth['profile_id'],
            'user' => $auth['user'],
            'serverUrl' => $this->resolveServerUrl($request),
        ]);
    }

    // -----------------------------------------------------------------
    // AD-27 — consent-gated telemetry heartbeat
    //
    // Route: POST /api/v1/telemetry/heartbeat (public — consenting clients
    // heartbeat before/without pairing; the consent FLAG is the gate, not a
    // session). Survey-c's three hard laws, each implemented literally:
    //   * opt-in:  body `consent` MUST be boolean true; absent/false is 400
    //     `consent_required`. The gate fails FAST — it is the one rejection in
    //     this handler that must never be swallowed, because recording without
    //     it is the privacy incident the whole feature turns on.
    //   * bounded, no PII beyond the device/instance id: the five fields below,
    //     each pattern/length-checked at this parse boundary; unknown extra
    //     fields are dropped, never persisted. No user id, no IP, no token,
    //     no account linkage (see ClientHeartbeatStore for the posture).
    //   * every failure swallowed: a storage error logs at the store boundary
    //     and the client still gets 200 {success:true, recorded:false}. A
    //     best-effort census must never become a client-visible outage or a
    //     retry storm.
    // -----------------------------------------------------------------

    /**
     * POST /api/v1/telemetry/heartbeat — one consenting client's hourly tick.
     *
     * @param Request               $request Route request.
     * @param array<string, string> $params  Path params (unused).
     */
    public function heartbeat(Request $request, array $params): Response
    {
        $this->enforceRateLimit($this->telemetryLimiter, 'telemetry:' . $request->getTrustedClientIp());

        // Single declared-member snapshot (the request body is parsed once at
        // this boundary; everything below works on the trusted local copy).
        $body = $request->body;

        $consent = $body['consent'] ?? false;
        if ($consent !== true) {
            return (new Response())->status(400)->json([
                'error' => 'Telemetry requires explicit opt-in consent',
                'code' => 'consent_required',
            ]);
        }

        $instanceId = $body['instance_id'] ?? null;
        if (!is_string($instanceId) || preg_match(self::INSTANCE_ID_PATTERN, $instanceId) !== 1) {
            return (new Response())->status(400)->json([
                'error' => 'instance_id must be an opaque id of 8-64 [A-Za-z0-9._-] characters',
                'code' => 'invalid_payload',
            ]);
        }

        $version = $body['version'] ?? null;
        if (!is_string($version) || $version === '' || strlen($version) > self::MAX_VERSION_LENGTH) {
            return (new Response())->status(400)->json([
                'error' => 'version is required (max ' . self::MAX_VERSION_LENGTH . ' chars)',
                'code' => 'invalid_payload',
            ]);
        }

        $clientType = $body['client_type'] ?? null;
        if (!is_string($clientType) || $clientType === '' || strlen($clientType) > self::MAX_CLIENT_TYPE_LENGTH) {
            return (new Response())->status(400)->json([
                'error' => 'client_type is required (max ' . self::MAX_CLIENT_TYPE_LENGTH . ' chars)',
                'code' => 'invalid_payload',
            ]);
        }

        // Absent, empty-string or wrong-typed build tokens collapse to '' — a
        // release build legitimately has none; this field is census, not gate.
        $buildToken = $body['build_token'] ?? '';
        if (!is_string($buildToken) || strlen($buildToken) > self::MAX_BUILD_TOKEN_LENGTH) {
            return (new Response())->status(400)->json([
                'error' => 'build_token must be a string of at most ' . self::MAX_BUILD_TOKEN_LENGTH . ' chars',
                'code' => 'invalid_payload',
            ]);
        }

        if ($this->heartbeats === null) {
            return $this->unavailable();
        }

        // `record()` itself never throws (its own catch keeps the swallow
        // guarantee local); the guard here is for the container-less venue.
        $recorded = $this->heartbeats->record($instanceId, $version, $clientType, $buildToken);

        return (new Response())->json(['success' => true, 'recorded' => $recorded]);
    }

    /**
     * GET /api/v1/admin/telemetry/clients — the fleet census read surface.
     *
     * ADMIN-gated by route middleware (AdminMiddleware, like every other
     * /api/v1/admin surface); this handler adds no second authority. Returns the
     * consented instances ordered by most-recently-seen — the read half of the
     * survey-c requirement that telemetry gets "a data landing + admin read
     * surface", bounded page + total so the census is comparable across pages.
     *
     * @param Request               $request Route request.
     * @param array<string, string> $params  Path params (unused).
     */
    public function adminClients(Request $request, array $params): Response
    {
        $limitRaw = $request->queryString('limit');
        $limit = $limitRaw !== null && is_numeric($limitRaw) ? (int) $limitRaw : 200;

        if ($this->heartbeats === null) {
            return $this->unavailable();
        }

        return (new Response())->json([
            'success' => true,
            'data' => [
                'total' => $this->heartbeats->countInstances(),
                'instances' => $this->heartbeats->recent($limit),
            ],
        ]);
    }

    // -----------------------------------------------------------------
    // Shared rails
    // -----------------------------------------------------------------

    /**
     * Record one hit and trip the central 429 mapping when over budget.
     *
     * Byte-for-byte the AuthController/WebAuthnController posture (SV-4.15): a
     * null limiter is a no-op (the degraded no-container fallback), and a trip
     * THROWS {@see RateLimitException} so the response shape stays the single
     * central one ({@see \Phlix\Server\Core\Application::rateLimitResponse()}).
     */
    private function enforceRateLimit(?RateLimiterInterface $limiter, string $key): void
    {
        if ($limiter === null) {
            return;
        }

        $state = $limiter->hit($key);
        if ($state->limited) {
            throw new RateLimitException($state->resetAt, $state->remaining);
        }
    }

    /**
     * 404 for every "this pairing is not a live pending/approved thing" answer —
     * one shape, no sub-reason, so code space cannot be probed.
     */
    private function pairingNotFound(): Response
    {
        return (new Response())->status(404)->json([
            'error' => 'Pairing not found',
            'code' => 'pairing_not_found',
        ]);
    }

    /**
     * 503 when the pairing/heartbeat store cannot exist at all (no container).
     */
    private function unavailable(): Response
    {
        return (new Response())->status(503)->json([
            'error' => 'Quick-connect storage is not available on this server',
            'code' => 'storage_unavailable',
        ]);
    }

    /**
     * Advertise the base URL the freshly paired TV should dial.
     *
     * Priority: the operator-configured absolute `hub.public_url` when it is a
     * clean absolute http(s) URL (validated with the estate's own
     * {@see CallbackUrl::isAbsolute} — same guard the OAuth redirect-URI path
     * uses, no parallel definition of "absolute"), else the host the TV itself
     * requested us on (Host header + X-Forwarded-Proto scheme). The fallback is
     * safe HERE in a way a Host-derived redirect_uri is not: this value travels
     * in the response BODY back to the very device that sent the Host — it is
     * self-pointing, never a redirect of a third party's browser (the NEW-1
     * phishing chain that poisons Host-derived OAuth redirects cannot form
     * against an echo-to-sender on an endpoint the attacker's victim already
     * dialed). Empty string when neither is usable, which tells the client to
     * keep the address it already used.
     */
    private function resolveServerUrl(Request $request): string
    {
        if ($this->serverUrl !== '' && CallbackUrl::isAbsolute($this->serverUrl)) {
            return rtrim($this->serverUrl, '/');
        }

        $host = trim((string) ($request->getHeader('Host') ?? ''));
        $hostShapeOk = $host !== '' && !str_contains($host, '@')
            && preg_match('/^\[?[A-Za-z0-9.:\-]+\]?(:\d{1,5})?$/', $host) === 1;
        if (!$hostShapeOk) {
            return '';
        }

        $scheme = strtolower((string) ($request->getHeader('X-Forwarded-Proto') ?? 'http'));
        if ($scheme !== 'https') {
            $scheme = 'http';
        }

        return $scheme . '://' . $host;
    }
}
