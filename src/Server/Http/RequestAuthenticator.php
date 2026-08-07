<?php

/**
 * Phlix media server component: Http.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http;

use Phlix\Auth\AuthManager;
use Phlix\Server\Http\Controllers\AuthController;

/**
 * Shared HTTP request authentication collaborator.
 *
 * C6/B4: This class extracts the auth resolution logic that was previously
 * duplicated between {@see \Phlix\Server\Workerman\HttpHandler} and
 * `public/index.php`. Both entry points now delegate to this collaborator
 * so the cookie-fallback behavior and media-stream authorization cannot
 * drift apart.
 *
 * The collaborator:
 *  1. Resolves the bearer token OR falls back to the `phlix_session` cookie.
 *  2. Validates the token and populates `$request->userId`.
 *  3. Handles the media-stream pre-flight authorization for byte-serving.
 *
 * S6: When a request is authenticated via cookie (not bearer), state-changing
 * methods (POST/PUT/DELETE/PATCH) are CSRF-exposed. This collaborator also
 * validates the Origin/Referer header for such requests to prevent CSRF
 * attacks. Bearer-authenticated requests are not vulnerable because browsers
 * do not auto-attach Authorization headers cross-origin.
 *
 * @package Phlix\Server\Http
 * @since   S6+B4+C3+C6
 */
final class RequestAuthenticator
{
    public function __construct(
        private readonly AuthManager $authManager,
    ) {
    }

    /**
     * Resolve and validate the authentication for a request.
     *
     * Checks the Bearer token first, then falls back to the `phlix_session`
     * HttpOnly cookie set by {@see AuthController::browserAuthResponse()}.
     * On success, `$request->userId` is populated with the authenticated user ID.
     *
     * @param Request $request The request to authenticate (modified in place).
     *
     * @return bool True when a valid authentication was resolved and applied,
     *             false when no recognized credential was present.
     *
     * @since C6/B4
     */
    public function authenticate(Request $request): bool
    {
        $token = $request->getBearerToken();

        // No Bearer token — try the session cookie.
        if ($token === null || $token === '') {
            $cookieToken = $request->getCookie(AuthController::SESSION_COOKIE);
            if (is_string($cookieToken) && $cookieToken !== '') {
                $token = $cookieToken;
            }
        }

        if ($token === null || $token === '') {
            return false;
        }

        $auth = $this->authManager->validateAccessToken($token);
        if (!is_array($auth) || !is_string($auth['user_id'] ?? null)) {
            return false;
        }

        $request->userId = $auth['user_id'];

        // S80: publish the session's profile in the SAME place, on the SAME pass,
        // for BOTH entry points (the Workerman handler and `public/index.php` each
        // call this method before dispatch). Doing it here rather than in a
        // middleware is deliberate: `AuthMiddleware` is instantiated inline as
        // `new AuthMiddleware()` at nineteen sites in `Application.php` and would
        // have to grow a dependency at all nineteen, and several surfaces —
        // notably the pre-router fast paths and the direct-play
        // `/media/{id}/stream` route — never reach a middleware group at all.
        //
        // `$auth['profile_id']` has already been ownership-checked by
        // {@see \Phlix\Auth\AuthManager::resolveProfileForUser()}; it is not the
        // raw claim.
        $profileId = $auth['profile_id'] ?? null;
        $request->profileId = is_string($profileId) && $profileId !== '' ? $profileId : null;

        if ($request->profileId !== null) {
            RequestContext::setProfileId($request->profileId);
        }

        return true;
    }

    /**
     * Whether the given request was authenticated via a session cookie
     * (as opposed to a Bearer token).
     *
     * Used by callers to determine if CSRF protection is required:
     * cookie-authenticated state-changing requests are CSRF-vulnerable because
     * browsers auto-attach cookies cross-origin, whereas Bearer tokens are not
     * sent automatically.
     *
     * @param Request $request The request to check.
     *
     * @return bool True when the request has a userId set via cookie auth.
     *
     * @since S6
     */
    public function isCookieAuthenticated(Request $request): bool
    {
        if ($request->userId === null || $request->userId === '') {
            return false;
        }

        // If a Bearer token was present and valid, it's not cookie-auth.
        // We detect this by checking if the Authorization header was used.
        $bearer = $request->getBearerToken();
        if ($bearer !== null && $bearer !== '') {
            return false;
        }

        // A userId is set but no Bearer token → must have come from the cookie.
        return true;
    }

    /**
     * Validate the Origin/Referer header for a state-changing request.
     *
     * S6: CSRF protection for cookie-authenticated requests. Browsers do not
     * auto-attach Authorization headers cross-origin, so Bearer-auth requests
     * are safe. But cookie-auth requests ARE vulnerable — the browser will
     * send the cookie automatically on cross-origin state-changing requests.
     *
     * This method validates that the request's Origin (or Referer, fallback)
     * matches the server's origin. Requests without a matching origin are
     * rejected with 403.
     *
     * @param Request $request The incoming request.
     *
     * @return bool True when the origin is valid or no check is needed;
     *              false when the request should be rejected.
     *
     * @since S6
     */
    public function validateCsrfOrigin(Request $request): bool
    {
        // Only check state-changing methods that are CSRF-exposed.
        if (!in_array($request->method, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
            return true;
        }

        $origin = $request->getHeader('Origin');
        $referer = $request->getHeader('Referer');

        // If neither header is present, reject — legitimate browsers always
        // send at least one for state-changing requests.
        if ($origin === null && $referer === null) {
            return false;
        }

        // Parse the server's host header into host and port components.
        $serverHost = $request->getHeader('Host') ?? '';
        $serverHostParts = self::parseHostHeader($serverHost);

        // Validate the Referer header if present.
        if ($referer !== null) {
            $refererParts = parse_url($referer);
            if (!is_array($refererParts)) {
                return false;
            }
            $refererHost = $refererParts['host'] ?? '';
            $refererPort = isset($refererParts['port']) ? (int) $refererParts['port'] : null;

            // Exact host match required (prevents evil-example.com matching example.com).
            if (!$this->hostsMatch($refererHost, $refererPort, $serverHostParts)) {
                return false;
            }
        }

        // Validate the Origin header if present.
        if ($origin !== null && $origin !== '' && $origin !== 'null') {
            $originParts = parse_url($origin);
            if (!is_array($originParts)) {
                return false;
            }
            $originHost = $originParts['host'] ?? '';
            $originPort = isset($originParts['port']) ? (int) $originParts['port'] : null;

            // Exact host match required (prevents evil-example.com matching example.com).
            if (!$this->hostsMatch($originHost, $originPort, $serverHostParts)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Parse a Host header value into its host and port components.
     *
     * @param string $hostHeader The Host header value (e.g., "example.com" or "example.com:8080").
     *
     * @return array{host: string, port: int|null} The parsed host and port.
     */
    private static function parseHostHeader(string $hostHeader): array
    {
        $parts = parse_url('http://' . $hostHeader);
        if (!is_array($parts)) {
            return ['host' => $hostHeader, 'port' => null];
        }
        return [
            'host' => $parts['host'] ?? $hostHeader,
            'port' => isset($parts['port']) ? (int) $parts['port'] : null,
        ];
    }

    /**
     * Check if an origin host matches the server host exactly.
     *
     * The host comparison is exact — "example.com.evil.com" does NOT match
     * "example.com" — preventing suffix-bypass attacks. Port handling allows
     * either both to have no port, or both to have the same port.
     *
     * @param string $originHost The origin/referer host.
     * @param int|null $originPort The origin/referer port, or null if not specified.
     * @param array{host: string, port: int|null} $serverHostParts Parsed server host parts.
     *
     * @return bool True if hosts match exactly.
     */
    private function hostsMatch(string $originHost, ?int $originPort, array $serverHostParts): bool
    {
        // Exact host comparison — no suffix matching.
        if ($originHost !== $serverHostParts['host']) {
            return false;
        }

        // Port must either both be specified and match, or both be absent.
        $originHasPort = $originPort !== null;
        $serverHasPort = $serverHostParts['port'] !== null;

        if ($originHasPort !== $serverHasPort) {
            // One has a port, the other doesn't — reject.
            return false;
        }

        if ($originHasPort && $originPort !== $serverHostParts['port']) {
            return false;
        }

        return true;
    }
}
