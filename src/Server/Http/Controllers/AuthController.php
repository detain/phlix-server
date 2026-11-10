<?php

/**
 * Phlix media server component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Auth\AuthManager;
use Phlix\Auth\AuthProviderBootstrapper;
use Phlix\Auth\CookiePolicy;
use Phlix\Auth\RateLimitException;
use Phlix\Auth\SignupDisabledException;
use Phlix\Auth\AccountInactiveException;
use Phlix\Auth\PasswordChangeRequiredException;
use Phlix\Shared\Events\Auth\UserLoggedOut;
use Phlix\Common\RateLimit\RateLimiterInterface;
use InvalidArgumentException;

/**
 * Handles authentication-related HTTP requests.
 *
 * This controller provides endpoints for user registration, login,
 * token refresh, and user profile retrieval.
 *
 * @author Phlix Media Server Team
 * @version 1.0.0
 * @description Authentication controller for user registration, login, and token management.
 * @see Request For request representation
 * @see Response For response generation
 * @see AuthManager For authentication logic
 */
class AuthController
{
    /**
     * Cookie name that stores the access token for the browser flow.
     * Long-lived clients (CLI, mobile, Roku) keep using bearer tokens
     * in the Authorization header instead.
     */
    public const SESSION_COOKIE = 'phlix_session';

    /**
     * Cookie name that stores the refresh token. Separate from the
     * access cookie so the access token can be rotated without losing
     * the refresh credential.
     */
    public const REFRESH_COOKIE = 'phlix_refresh';

    /** @var AuthManager The authentication manager instance */
    private AuthManager $authManager;

    /**
     * Per-surface rate limiter for account registration (SV-4.15(f)); the
     * DB-backed {@see RateLimitProfiles::REGISTER} instance in production. Null
     * only in the degraded no-container fallback, where it is a no-op.
     *
     * @var RateLimiterInterface|null
     */
    private ?RateLimiterInterface $registerLimiter;

    /**
     * Per-surface rate limiter for token refresh (SV-4.15(f)); the DB-backed
     * {@see RateLimitProfiles::REFRESH} instance in production. Null only in the
     * degraded no-container fallback, where it is a no-op.
     *
     * @var RateLimiterInterface|null
     */
    private ?RateLimiterInterface $refreshLimiter;

    /**
     * Request-path self-heal of the per-worker auth-provider registry from the
     * persisted enable flags (S44 Finding 3). Optional so existing
     * direct-construction call sites keep working; the DI factory binds it
     * explicitly. Null = no self-heal (the LDAP path relies on the boot-time
     * registration only).
     *
     * @var AuthProviderBootstrapper|null
     */
    private ?AuthProviderBootstrapper $providerBootstrapper;

    /**
     * The auth-cookie issuance policy (COOKIE-DESIGN §2.1/§2.4): custody
     * classification, the master switch, the scheme law, and the Secure-
     * attribute decision all read through this one collaborator. Defaults to a
     * store-less policy whose answers reproduce the shipped posture (enabled,
     * env-only insecure, live settings unread), so the degraded no-container
     * fallback and every direct-construction call site behave exactly as
     * before this seam existed.
     */
    private CookiePolicy $cookiePolicy;

    /**
     * Creates a new AuthController instance.
     *
     * The rate limiters are optional so existing direct-construction call sites
     * (and the degraded no-container fallback in Application) keep working; the
     * DI factory binds each one explicitly to its {@see RateLimitProfiles}
     * container id (PHP-DI skips optional ctor params during autowiring, so an
     * unbound limiter would silently stay null and leave the surface open).
     * The same named-binding law applies to `cookiePolicy`: the container
     * wires the settings-backed policy; leaving it unbound keeps the shipped
     * defaults (safe, but the admin-facing auth.cookie.* toggles would be
     * inert — so it IS bound, see AuthServicesProvider).
     *
     * @param AuthManager             $authManager    The authentication manager
     * @param RateLimiterInterface|null $registerLimiter Limiter guarding register()
     * @param RateLimiterInterface|null $refreshLimiter  Limiter guarding refresh()
     * @param AuthProviderBootstrapper|null $providerBootstrapper Request-path
     *        self-heal for the per-worker provider registry (LDAP login path).
     * @param CookiePolicy|null $cookiePolicy Auth-cookie policy (null = the
     *        shipped-default store-less policy).
     */
    public function __construct(
        AuthManager $authManager,
        ?RateLimiterInterface $registerLimiter = null,
        ?RateLimiterInterface $refreshLimiter = null,
        ?AuthProviderBootstrapper $providerBootstrapper = null,
        ?CookiePolicy $cookiePolicy = null,
    ) {
        $this->authManager = $authManager;
        $this->registerLimiter = $registerLimiter;
        $this->refreshLimiter = $refreshLimiter;
        $this->providerBootstrapper = $providerBootstrapper;
        $this->cookiePolicy = $cookiePolicy ?? new CookiePolicy();
    }

    /**
     * Record one attempt against `$limiter` for `$key` and, when the key is over
     * budget, throw {@see RateLimitException} — which the central mapping
     * (SV-4.15(c)) turns into a 429 + `Retry-After` + `code=rate_limited`
     * response at every dispatch entrypoint. A null limiter is a no-op (the
     * degraded no-container fallback).
     *
     * @throws RateLimitException When the key has exceeded its window budget.
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
     * Handles user registration.
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Path parameters (unused)
     * @return Response JSON response with user data or error
     *
     * @required_fields username, email, password
     */
    public function register(Request $request, array $params): Response
    {
        // Brute-force / spam guard keyed on the TRUSTED client IP
        // (trusted-proxy-aware; a forged X-Forwarded-For can no longer mint a
        // fresh bucket — SV-4.15 HIGH) — NOT $_SERVER, which is stale under
        // Workerman's resident workers. A trip throws RateLimitException ->
        // central 429 mapping.
        $this->enforceRateLimit($this->registerLimiter, 'register:' . $request->getTrustedClientIp());

        $data = $request->body;
        $username = $data['username'] ?? null;
        $email = $data['email'] ?? null;
        $password = $data['password'] ?? null;

        $isBrowser = $this->isBrowserRequest($request);
        $cookieMode = $this->classifyRequest($request);

        if (
            !is_string($username) || $username === ''
            || !is_string($email) || $email === ''
            || !is_string($password) || $password === ''
        ) {
            $msg = 'Missing required fields: username, email, password';
            return $isBrowser
                ? (new Response())->redirect('/auth/register?error=' . rawurlencode($msg))
                : (new Response())->status(400)->json(['error' => $msg]);
        }

        try {
            $result = $this->authManager->register($username, $email, $password);

            // Approval mode: the account was created pending and no tokens were
            // issued. Don't set session cookies — there is nothing to log in as.
            if (($result['status'] ?? null) === 'pending') {
                if ($isBrowser) {
                    $msg = is_string($result['message'] ?? null)
                        ? $result['message']
                        : 'Your account is awaiting administrator approval.';
                    return (new Response())->redirect('/login?notice=' . rawurlencode($msg));
                }
                return (new Response())->status(202)->json($result);
            }

            if ($isBrowser) {
                return $this->browserAuthResponse($result, '/', $cookieMode === CookiePolicy::MODE_COOKIE);
            }
            // CP-003 dual-emit (COOKIE-DESIGN §2.2): the JSON 201 envelope stays
            // byte-ident for every legacy bearer client; browser-class clients
            // on a §2.4-eligible scheme additionally receive the HttpOnly pair.
            $response = (new Response())->status(201)->json($result);
            if ($cookieMode === CookiePolicy::MODE_COOKIE) {
                $this->attachAuthCookies($response, $result);
            }
            return $response;
        } catch (SignupDisabledException $e) {
            if ($isBrowser) {
                return (new Response())->redirect('/auth/register?error=' . rawurlencode($e->getMessage()));
            }
            return (new Response())->status(403)->json([
                'error' => $e->getMessage(),
                'code' => SignupDisabledException::ERROR_CODE,
            ]);
        } catch (InvalidArgumentException $e) {
            if ($isBrowser) {
                return (new Response())->redirect('/auth/register?error=' . rawurlencode($e->getMessage()));
            }
            return (new Response())->status(400)->json(['error' => $e->getMessage()]);
        }
    }

    /**
     * Handles user login.
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Path parameters (unused)
     * @return Response JSON response with tokens or error
     *
     * @required_fields username, password
     * @header X-Device-Id Device identifier for token binding
     */
    public function login(Request $request, array $params): Response
    {
        $data = $request->body;
        $username = $data['username'] ?? null;
        // Fall back to the `email` field when `username` is missing/blank, so a
        // client that submits only an email still authenticates. AuthManager::login
        // matches the identifier against both the username and email columns.
        if (!is_string($username) || $username === '') {
            $email = $data['email'] ?? null;
            if (is_string($email) && $email !== '') {
                $username = $email;
            }
        }
        $password = $data['password'] ?? null;

        $isBrowser = $this->isBrowserRequest($request);
        $cookieMode = $this->classifyRequest($request);

        if (!is_string($username) || $username === '' || !is_string($password) || $password === '') {
            $msg = 'Missing required fields: username, password';
            return $isBrowser
                ? (new Response())->redirect('/login?error=' . rawurlencode($msg))
                : (new Response())->status(400)->json(['error' => $msg]);
        }

        $deviceId = $request->getHeader('X-Device-Id') ?? 'unknown';

        // S44: LDAP login path (Path A). An `ldap:`-prefixed identifier is
        // routed through the external-provider flow (ProviderManager's prefix
        // dispatch) rather than the local password store. The LDAP provider must
        // be enabled + registered (see AuthProviderBootstrapper); when it is not,
        // loginWithProvider() throws and we answer 503 rather than a misleading
        // "invalid credentials".
        if (str_starts_with($username, 'ldap:')) {
            return $this->ldapLogin($username, $password, $deviceId, $isBrowser, $cookieMode);
        }

        try {
            $result = $this->authManager->login($username, $password, $deviceId);
            if ($isBrowser) {
                return $this->browserAuthResponse($result, '/', $cookieMode === CookiePolicy::MODE_COOKIE);
            }
            // CP-003 dual-emit (COOKIE-DESIGN §2.2): the JSON 200 envelope stays
            // byte-ident for every legacy bearer client; browser-class clients
            // on a §2.4-eligible scheme additionally receive the HttpOnly pair.
            $response = (new Response())->json($result);
            if ($cookieMode === CookiePolicy::MODE_COOKIE) {
                $this->attachAuthCookies($response, $result);
            }
            return $response;
        } catch (AccountInactiveException $e) {
            // Correct credentials but the account is pending/disabled — 403 with
            // a distinct code so clients can show the right message.
            if ($isBrowser) {
                return (new Response())->redirect('/login?error=' . rawurlencode($e->getMessage()));
            }
            return (new Response())->status(403)->json([
                'error' => $e->getMessage(),
                'code' => $e->errorCode,
            ]);
        } catch (PasswordChangeRequiredException $e) {
            // SRV-M17: correct credentials, but the account carries the
            // must_change_password HOLD. Same 403 boundary mapping as the
            // inactive arm — the code `auth.password_change_required` is the
            // contracts-registered member of the auth.* family, so the SPA
            // shows the change-password guidance instead of a bare 500.
            if ($isBrowser) {
                return (new Response())->redirect('/login?error=' . rawurlencode($e->getMessage()));
            }
            return (new Response())->status(403)->json([
                'error' => $e->getMessage(),
                'code' => $e->errorCode,
            ]);
        } catch (InvalidArgumentException $e) {
            if ($isBrowser) {
                return (new Response())->redirect('/login?error=' . rawurlencode($e->getMessage()));
            }
            return (new Response())->status(401)->json(['error' => $e->getMessage()]);
        }
    }

    /**
     * Authenticate an `ldap:`-prefixed identifier through the external-provider
     * flow and mint a local session on success (S44, Path A).
     *
     * Delegates to {@see AuthManager::loginWithProvider()}, which uses
     * {@see \Phlix\Auth\ProviderManager}'s prefix dispatch to reach the
     * registered {@see \Phlix\Plugins\Ldap\LdapProvider}. The `ldap:` prefix is
     * preserved on the identifier (so ProviderManager can parse it) while the
     * bare username + password are passed as the provider credentials.
     *
     * @param string $identifier The full `ldap:<username>` login identifier.
     * @param string $password   The submitted password.
     * @param string $deviceId   Device identifier for token binding.
     * @param bool   $isBrowser  Whether to reply with a cookie redirect (browser)
     *                           or a JSON token blob (API client).
     * @param string $cookieMode The classifyRequest() custody decision
     *                           (CookiePolicy::MODE_*), same as the local arm.
     */
    private function ldapLogin(
        string $identifier,
        string $password,
        string $deviceId,
        bool $isBrowser,
        string $cookieMode,
    ): Response {
        $localUsername = substr($identifier, strlen('ldap:'));

        // S44 Finding 3 — reconcile THIS worker's provider registry with the
        // persisted `auth.ldap.enabled` flag before attempting the login. The
        // registry is per-worker in-memory state, so an admin who enables LDAP on
        // one worker would otherwise get 503s on ~(N-1)/N of workers until a
        // restart; conversely a since-disabled provider is dropped here. Reads
        // settings only (no network I/O), same as the boot registration.
        $this->providerBootstrapper?->ensureProviderRegistered(AuthProviderBootstrapper::LDAP);

        try {
            $result = $this->authManager->loginWithProvider(
                'ldap:' . $localUsername,
                ['username' => $localUsername, 'password' => $password],
                $deviceId,
            );

            if ($isBrowser) {
                return $this->browserAuthResponse($result, '/', $cookieMode === CookiePolicy::MODE_COOKIE);
            }
            // CP-003: same dual-emit law as the local-password arm — the LDAP
            // provider minting the session changes nothing about custody.
            $response = (new Response())->json($result);
            if ($cookieMode === CookiePolicy::MODE_COOKIE) {
                $this->attachAuthCookies($response, $result);
            }
            return $response;
        } catch (RateLimitException $e) {
            // S44 Finding 2 — the brute-force throttle tripped inside
            // loginWithProvider(). RateLimitException extends RuntimeException, so
            // it MUST be caught (and re-thrown) before the RuntimeException handler
            // below, otherwise it would be mislabelled as "provider unavailable"
            // (503) instead of reaching the central 429 + Retry-After mapping.
            throw $e;
        } catch (InvalidArgumentException $e) {
            // The provider rejected the login. S44 Finding 5 — LdapProvider
            // distinguishes `user_not_found` from `invalid_credentials`; echoing
            // that raw code back is a user-enumeration oracle. Return a single
            // generic credential-rejection message instead (the real provider
            // error is already logged server-side by AuthManager via AuditLogger).
            // A genuine config/connection failure (LdapProvider prefixes these
            // with `ldap_error:`) is a 500-class problem, not a bad password — so
            // surface it as a 503 "unavailable" rather than masking it as 401.
            if (str_starts_with($e->getMessage(), 'ldap_error:')) {
                $msg = 'LDAP authentication is temporarily unavailable.';
                if ($isBrowser) {
                    return (new Response())->redirect('/login?error=' . rawurlencode($msg));
                }
                return (new Response())->status(503)->json([
                    'error' => $msg,
                    'code' => 'provider_unavailable',
                ]);
            }

            $msg = 'Invalid credentials';
            if ($isBrowser) {
                return (new Response())->redirect('/login?error=' . rawurlencode($msg));
            }
            return (new Response())->status(401)->json(['error' => $msg]);
        } catch (PasswordChangeRequiredException $e) {
            // SRV-M17 (and the F8 note in REVIEW-SRV-MAJORS-T3): the provider IS
            // available and the credentials were correct — the ACCOUNT is held.
            // Answer the same distinct 403 as the local-password arm instead of
            // letting this RuntimeException fall into the arm below, which
            // mislabels a hold as an infrastructure outage ('not available').
            if ($isBrowser) {
                return (new Response())->redirect('/login?error=' . rawurlencode($e->getMessage()));
            }
            return (new Response())->status(403)->json([
                'error' => $e->getMessage(),
                'code' => $e->errorCode,
            ]);
        } catch (\RuntimeException $e) {
            // The LDAP provider is not enabled/registered (or ProviderManager is
            // unavailable) — AuthProviderNotFoundException extends RuntimeException.
            // The prose names that permanent state ("not enabled"), which the
            // ldap_error: arm above ("temporarily unavailable") deliberately
            // does not: an operator seeing it knows to configure, not to retry.
            // Full auth-failure status mapping is the SRV-M17 named follow-up.
            $msg = 'LDAP authentication is not enabled on this server.';
            if ($isBrowser) {
                return (new Response())->redirect('/login?error=' . rawurlencode($msg));
            }
            return (new Response())->status(503)->json([
                'error' => $msg,
                'code' => 'provider_unavailable',
            ]);
        }
    }

    /**
     * Handles token refresh requests.
     *
     * The refresh token is sourced, in priority order, from:
     *   1. the request body / JSON `refresh_token` field (backwards-compatible
     *      with existing CLI/native clients that POST the token explicitly), then
     *   2. the `phlix_refresh` HttpOnly cookie set at login — this is what the
     *      in-memory-access-token web/native flow uses, where JS never sees the
     *      refresh credential and so cannot put it in the body.
     *
     * On success the response carries a fresh access + refresh token pair.
     * The auth cookies are re-issued (rotated) only when the request is
     * browser-class on a §2.4-eligible scheme — under the same classifyRequest
     * policy as the JSON login/register arms. Before CP-003 this rotation was
     * UNCONDITIONAL, so a device-class body-token refresh also received
     * cookies it can never use (the cookie program's own scaffolding bug,
     * BANK.md); the classification now suppresses that header ride.
     *
     * The raw refresh token is never logged.
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Path parameters (unused)
     * @return Response JSON response with new tokens or error
     *
     * @required_fields refresh_token (body) OR phlix_refresh (cookie)
     */
    public function refresh(Request $request, array $params): Response
    {
        // Throttle refresh churn per TRUSTED client IP (trusted-proxy-aware; a
        // forged X-Forwarded-For can no longer reset the bucket — SV-4.15 HIGH) —
        // NOT $_SERVER, which is stale under Workerman's resident workers. A
        // trip throws RateLimitException -> central 429 mapping.
        $this->enforceRateLimit($this->refreshLimiter, 'refresh:' . $request->getTrustedClientIp());

        $data = $request->body;
        $refreshToken = $data['refresh_token'] ?? null;

        // Body token takes precedence (existing clients keep working); fall
        // back to the HttpOnly refresh cookie when the body omits it — read the
        // same way the entry points read SESSION_COOKIE (see HttpHandler).
        if (!is_string($refreshToken) || $refreshToken === '') {
            $cookieToken = $request->getCookie(self::REFRESH_COOKIE);
            if (is_string($cookieToken) && $cookieToken !== '') {
                $refreshToken = $cookieToken;
            }
        }

        if (!is_string($refreshToken) || $refreshToken === '') {
            return (new Response())->status(400)->json([
                'error' => 'refresh_token is required',
            ]);
        }

        try {
            $result = $this->authManager->refreshToken($refreshToken);
            $response = (new Response())->json($result);
            // Rotate the auth cookies so the browser/native httpOnly flow keeps
            // a valid refresh credential without JS ever touching it — but
            // only for cookie-class requests (CP-003 cure: device-class
            // body-token refreshes must never see Set-Cookie, §2.1 law).
            if ($this->classifyRequest($request) === CookiePolicy::MODE_COOKIE) {
                $this->attachAuthCookies($response, $result);
            }
            return $response;
        } catch (InvalidArgumentException $e) {
            return (new Response())->status(401)->json(['error' => $e->getMessage()]);
        }
    }

    /**
     * Gets the current authenticated user's profile.
     *
     * @param Request $request The HTTP request
     * @param array<string, string> $params Path parameters (unused)
     * @return Response JSON response with user data or error
     *
     * @requires Valid bearer token or authenticated session
     */
    public function me(Request $request, array $params): Response
    {
        $userId = $request->userId ?? null;
        if (!$userId) {
            return (new Response())->status(401)->json(['error' => 'Unauthorized']);
        }

        $user = $this->authManager->getUser($userId);
        if (!$user) {
            return (new Response())->status(404)->json(['error' => 'User not found']);
        }

        return (new Response())->json(['user' => $user]);
    }

    /**
     * Browser-form logout: real server-side teardown, then cookie-clear +
     * redirect to /login.
     *
     * When the request carries a resolvable identity this calls
     * {@see AuthManager::logout()}: the per-user token-revocation watermark
     * (`users.tokens_not_valid_after`) is bumped — killing every JWT minted up
     * to now on both the access and refresh paths, within the 5s cache
     * ceiling — and all device-session rows are ended (M-1, security audit
     * 2026-09-29). The earlier "drop your stored token client-side; we don't
     * revoke server-side" phase note is historical: the revocation it
     * promised now ships.
     *
     * @param Request $request The HTTP request.
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return Response 302 redirect with cleared cookies.
     */
    public function logout(Request $request, array $params): Response
    {
        // M-1 (security audit 2026-09-29): logout used to be cookie-clearing
        // theatre — the minted JWTs stayed fully valid server-side. The route
        // sits outside AuthMiddleware, but the front controller still
        // populates $request->userId from a valid bearer/session cookie, so
        // whenever an identity is attached we run the real server-side
        // teardown: per-user JWT revocation watermark + endAllUserSessions
        // (via AuthManager::logout). An anonymous hit (already logged out,
        // or a stray cookie) keeps the old pure-clear behaviour.
        $userId = $request->userId;
        if (is_string($userId) && $userId !== '') {
            $this->authManager->logout($userId, '', UserLoggedOut::REASON_EXPLICIT);
        }

        return (new Response())
            ->clearCookie(self::SESSION_COOKIE)
            ->clearCookie(self::REFRESH_COOKIE)
            ->redirect('/login');
    }

    /**
     * Detect whether a request came from a browser form submit (vs.
     * a JSON API client). The route alias under `/auth/*` is the
     * canonical browser entry; `Content-Type: application/x-www-form-urlencoded`
     * is the secondary signal for clients that POST to the API path
     * with a form body.
     *
     * This decides the RESPONSE SHAPE (redirect vs JSON) exactly as before.
     * The separate cookie-CUSTODY decision is {@see self::classifyRequest()}.
     */
    private function isBrowserRequest(Request $request): bool
    {
        if (str_starts_with($request->path, '/auth/')) {
            return true;
        }
        $contentType = $request->getHeader('Content-Type') ?? '';
        return str_contains($contentType, 'application/x-www-form-urlencoded')
            || str_contains($contentType, 'multipart/form-data');
    }

    /**
     * CP-003 (COOKIE-DESIGN §2.1/§2.2/§2.4): the per-request custody
     * classification that decides whether Set-Cookie may ride this response
     * at all. Returns CookiePolicy::MODE_COOKIE only when ALL of:
     *
     *  - device marker absent AND the request is browser-class per §2.1 —
     *    a form-era signal (path under `/auth/` or a form Content-Type, the
     *    values isBrowserRequest() reads), or a same-site http(s) Origin
     *    (the hosted SPA's fetch), or `Sec-Fetch-Site: same-origin`. An
     *    Origin that is present but NOT same-site http(s) — foreign web
     *    host, capacitor://, app:// webview vaults — is device-class:
     *    bearer-only, NO Set-Cookie ever. No browser signal at all (curl,
     *    legacy native clients) stays bearer-only = today's JSON posture.
     *  - the `auth.cookie.enabled` master switch is not false;
     *  - the §2.4 scheme law holds: https per the first hop of
     *    X-Forwarded-Proto, or a localhost Host, or the explicit insecure
     *    override (env or stored twin — which also drops the Secure
     *    attribute in attachAuthCookies, so there is never a cookie flagged
     *    `Secure` that a plain-HTTP browser would silently discard).
     *
     * The classify is side-effect-free and per-request: resident workers
     * must never carry a custody decision from one client to the next.
     *
     * @see CookiePolicy for the primitive decisions (pure over header values).
     */
    private function classifyRequest(Request $request): string
    {
        $mode = $this->cookiePolicy->custodyMode(
            $this->isBrowserRequest($request),
            $request->getHeader('X-Phlix-Device-Type'),
            $request->getHeader('Origin'),
            $request->getHeader('Host'),
            $request->getHeader('Sec-Fetch-Site'),
        );

        if ($mode !== CookiePolicy::MODE_COOKIE) {
            return CookiePolicy::MODE_BEARER;
        }

        if (!$this->cookiePolicy->cookieModeEnabled()) {
            return CookiePolicy::MODE_BEARER;
        }

        $forwardedProto = $request->getHeader('X-Forwarded-Proto');
        if (!$this->cookiePolicy->schemeEligible($forwardedProto, $request->getHeader('Host'))) {
            return CookiePolicy::MODE_BEARER;
        }

        return CookiePolicy::MODE_COOKIE;
    }

    /**
     * Build a 302 redirect response that persists the access + refresh
     * tokens as HttpOnly cookies so subsequent page navigations are
     * automatically authenticated. Used after a successful browser
     * register or login.
     *
     * The access cookie expires alongside the JWT (Max-Age = expires_in
     * from {@see AuthManager::buildAuthResponse()}); the refresh cookie's
     * Max-Age comes from {@see AuthManager::refreshTtl()}, i.e. the same
     * handler that minted the refresh token.
     *
     * @param array<string, mixed> $authResponse The shape returned by
     *        AuthManager (access_token, refresh_token, expires_in, user).
     * @param string $redirectTo Where to send the browser next.
     * @param bool $emitCookies The classifyRequest() decision — redirect arms
     *        whose request is bearer-class (device marker, http-LAN without
     *        the insecure override, master switch off) still redirect, they
     *        simply carry no Set-Cookie (§2.1: device-class never receives
     *        cookies; §2.4: no silent issuance onto plain HTTP).
     */
    private function browserAuthResponse(array $authResponse, string $redirectTo, bool $emitCookies): Response
    {
        $response = (new Response())->redirect($redirectTo);
        if ($emitCookies) {
            $this->attachAuthCookies($response, $authResponse);
        }
        return $response;
    }

    /**
     * Queue the access + refresh auth cookies onto a response.
     *
     * Single source of truth for the auth-cookie attributes so the browser
     * login redirect ({@see self::browserAuthResponse()}) and the token
     * {@see self::refresh()} rotation set cookies identically — HttpOnly so XSS
     * can't read them, Secure so they only ride HTTPS, SameSite=Lax so top-level
     * navigations still carry them. The access cookie expires with the JWT
     * (`expires_in`); the refresh cookie's lifetime is read from the same
     * handler that minted the refresh token, so the two cannot drift when
     * `auth.refresh_ttl` changes. Empty token values are skipped.
     *
     * The `auth.cookie.enabled` master switch is honoured at this single
     * choke point: false ⇒ the server sets no auth cookie on ANY path, form
     * redirect included (§7 rollback law). Classification (who may reach this
     * method at all) lives in {@see self::classifyRequest()}.
     *
     * @param array<string, mixed> $authResponse The shape returned by
     *        AuthManager (access_token, refresh_token, expires_in, user).
     */
    private function attachAuthCookies(Response $response, array $authResponse): void
    {
        if (!$this->cookiePolicy->cookieModeEnabled()) {
            return;
        }

        $access = is_string($authResponse['access_token'] ?? null) ? $authResponse['access_token'] : '';
        $refresh = is_string($authResponse['refresh_token'] ?? null) ? $authResponse['refresh_token'] : '';
        // Both lifetimes come from the AuthManager -> JwtHandler that minted
        // these tokens, never from a literal here: a cookie whose Max-Age
        // disagrees with its token's `exp` either strands a still-valid
        // credential or leaves a dead one in the jar. See TokenTtlPolicy.
        $expiresIn = is_int($authResponse['expires_in'] ?? null)
            ? $authResponse['expires_in']
            : $this->authManager->accessTtl();

        $secure = $this->cookiePolicy->cookiesSecure();

        if ($access !== '') {
            $response->cookie(
                self::SESSION_COOKIE,
                $access,
                maxAge: $expiresIn,
                secure: $secure,
                httpOnly: true,
                sameSite: 'Lax',
            );
        }
        if ($refresh !== '') {
            $response->cookie(
                self::REFRESH_COOKIE,
                $refresh,
                maxAge: $this->authManager->refreshTtl(),
                secure: $secure,
                httpOnly: true,
                sameSite: 'Lax',
            );
        }
    }
}
