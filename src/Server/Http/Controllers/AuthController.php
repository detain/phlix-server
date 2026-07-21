<?php

/**
 * Phlix media server component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Auth\AuthManager;
use Phlix\Auth\RateLimitException;
use Phlix\Auth\SignupDisabledException;
use Phlix\Auth\AccountInactiveException;
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
     * Creates a new AuthController instance.
     *
     * The rate limiters are optional so existing direct-construction call sites
     * (and the degraded no-container fallback in Application) keep working; the
     * DI factory binds each one explicitly to its {@see RateLimitProfiles}
     * container id (PHP-DI skips optional ctor params during autowiring, so an
     * unbound limiter would silently stay null and leave the surface open).
     *
     * @param AuthManager             $authManager    The authentication manager
     * @param RateLimiterInterface|null $registerLimiter Limiter guarding register()
     * @param RateLimiterInterface|null $refreshLimiter  Limiter guarding refresh()
     */
    public function __construct(
        AuthManager $authManager,
        ?RateLimiterInterface $registerLimiter = null,
        ?RateLimiterInterface $refreshLimiter = null,
    ) {
        $this->authManager = $authManager;
        $this->registerLimiter = $registerLimiter;
        $this->refreshLimiter = $refreshLimiter;
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
                return $this->browserAuthResponse($result, '/');
            }
            return (new Response())->status(201)->json($result);
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

        if (!is_string($username) || $username === '' || !is_string($password) || $password === '') {
            $msg = 'Missing required fields: username, password';
            return $isBrowser
                ? (new Response())->redirect('/login?error=' . rawurlencode($msg))
                : (new Response())->status(400)->json(['error' => $msg]);
        }

        $deviceId = $request->getHeader('X-Device-Id') ?? 'unknown';

        try {
            $result = $this->authManager->login($username, $password, $deviceId);
            if ($isBrowser) {
                return $this->browserAuthResponse($result, '/');
            }
            return (new Response())->json($result);
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
        } catch (InvalidArgumentException $e) {
            if ($isBrowser) {
                return (new Response())->redirect('/login?error=' . rawurlencode($e->getMessage()));
            }
            return (new Response())->status(401)->json(['error' => $e->getMessage()]);
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
     * On success the response carries a fresh access + refresh token pair AND
     * re-issues both auth cookies, rotating the refresh cookie so the new
     * credential replaces the old one (the previous cookie value is overwritten
     * with the same HttpOnly/Secure/SameSite attributes login uses).
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
            // a valid refresh credential without JS ever touching it.
            $this->attachAuthCookies($response, $result);
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
     * Browser-form logout: clear session cookies and redirect to /login.
     *
     * The JSON API equivalent is just "drop your stored token" client
     * side; we don't currently revoke refresh tokens server-side (that
     * lives in SessionManager and will move into AuthManager in a
     * later phase per the buildAuthResponse() docstring).
     *
     * @param Request $request The HTTP request.
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return Response 302 redirect with cleared cookies.
     */
    public function logout(Request $request, array $params): Response
    {
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
     */
    private function browserAuthResponse(array $authResponse, string $redirectTo): Response
    {
        $response = (new Response())->redirect($redirectTo);
        $this->attachAuthCookies($response, $authResponse);
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
     * @param array<string, mixed> $authResponse The shape returned by
     *        AuthManager (access_token, refresh_token, expires_in, user).
     */
    private function attachAuthCookies(Response $response, array $authResponse): void
    {
        $access = is_string($authResponse['access_token'] ?? null) ? $authResponse['access_token'] : '';
        $refresh = is_string($authResponse['refresh_token'] ?? null) ? $authResponse['refresh_token'] : '';
        // Both lifetimes come from the AuthManager -> JwtHandler that minted
        // these tokens, never from a literal here: a cookie whose Max-Age
        // disagrees with its token's `exp` either strands a still-valid
        // credential or leaves a dead one in the jar. See TokenTtlPolicy.
        $expiresIn = is_int($authResponse['expires_in'] ?? null)
            ? $authResponse['expires_in']
            : $this->authManager->accessTtl();

        $secure = self::cookiesSecure();

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

    /**
     * Whether auth cookies should carry the `Secure` attribute.
     *
     * Defaults to true so the session/refresh credentials are never
     * transmitted over plain HTTP. Only a deliberate local-development
     * opt-out (`PHLIX_COOKIE_INSECURE=1`) disables it, so an HTTP dev
     * server can still set cookies. Any other value — including unset —
     * keeps `Secure` on.
     */
    private static function cookiesSecure(): bool
    {
        $optOut = getenv('PHLIX_COOKIE_INSECURE');

        return $optOut !== '1';
    }
}
