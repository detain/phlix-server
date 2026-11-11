<?php

/**
 * Phlix media server component: Http.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Server\Http;

use Phlix\Auth\AuthManager;
use Phlix\Auth\CookiePolicy;
use Phlix\Server\Http\Controllers\AuthController;

/**
 * Shared HTTP request authentication collaborator.
 *
 * C6/B4: This class extracts the auth resolution logic that was previously
 * duplicated between {@see \Phlix\Server\Workerman\HttpHandler} and
 * `public/index.php`. The Workerman handler delegates here; the second such
 * caller was the now-deleted `public/index.php` front controller, and any
 * revived HTTP entry point must delegate to this collaborator rather than
 * re-implement, so the cookie-fallback behavior and media-stream
 * authorization cannot drift apart.
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
        // for every HTTP entry point (the Workerman handler calls this method
        // before dispatch; the deleted `public/index.php` front controller was
        // the second such caller). Doing it here rather than in a
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

        // CP-029 / D-1a: the family id of THIS request's token, taken from the
        // same already-validated payload (validateAccessToken returns the claim
        // it read, null when the token predates the store). The per-device
        // revoke surface and the `current` flag on the session list key off
        // exactly this value — it is never sourced from the client.
        $familyId = $auth['session_id'] ?? null;
        $request->tokenSessionId = is_string($familyId) && $familyId !== '' ? $familyId : null;

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
        // Only check state-changing methods that are CSRF-exposed. (GET keeps
        // exactly ONE exception downstream: the forced-logout link leg,
        // review minor 21 — named at its single call site in HttpHandler.)
        if (!in_array($request->method, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
            return true;
        }

        return $this->validateSameSiteOrigin($request);
    }

    /**
     * The same-site header law WITHOUT the method gate: the full arm order
     * (Sec-Fetch-Site belt, both-absent refusal, literal-`null` Origin
     * refusal, Referer/Origin exact-host checks) lives in the SSOT
     * {@see CookiePolicy::stateChangingRequestOriginAllowed()} — CP-009
     * relocated it there as the server twin of the hub's ratified law, so
     * the HTTP gate and the WS cookie carrier (CP-006) read ONE
     * implementation. {@see validateCsrfOrigin()} is this method behind the
     * state-changing-methods guard.
     *
     * review minor 21: `GET /auth/logout` is state-changing IN EFFECT — a
     * cross-site `<img src="/auth/logout">` drive-by terminates the session —
     * so the cookie-auth gate runs THIS variant on that one path while every
     * other GET stays exempt exactly as before. Same-origin `<a href>` link
     * logouts carry Referer (or Origin) like any browser navigation and pass
     * unchanged.
     *
     * @param Request $request The incoming request.
     *
     * @return bool True when the origin is valid or no check is needed;
     *              false when the request should be rejected.
     */
    public function validateSameSiteOrigin(Request $request): bool
    {
        return CookiePolicy::stateChangingRequestOriginAllowed(
            $request->getHeader('Origin'),
            $request->getHeader('Referer'),
            $request->getHeader('Host'),
            $request->getHeader('Sec-Fetch-Site')
        );
    }
}
