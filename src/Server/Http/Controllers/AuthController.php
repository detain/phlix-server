<?php

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Auth\AuthManager;
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
     * Creates a new AuthController instance.
     *
     * @param AuthManager $authManager The authentication manager
     */
    public function __construct(AuthManager $authManager)
    {
        $this->authManager = $authManager;
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
            if ($isBrowser) {
                return $this->browserAuthResponse($result, '/');
            }
            return (new Response())->status(201)->json($result);
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
     * @param Request $request The HTTP request
     * @param array<string, string> $params Path parameters (unused)
     * @return Response JSON response with new tokens or error
     *
     * @required_fields refresh_token
     */
    public function refresh(Request $request, array $params): Response
    {
        $data = $request->body;
        $refreshToken = $data['refresh_token'] ?? null;

        if (!is_string($refreshToken) || $refreshToken === '') {
            return (new Response())->status(400)->json([
                'error' => 'refresh_token is required',
            ]);
        }

        try {
            $result = $this->authManager->refreshToken($refreshToken);
            return (new Response())->json($result);
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
     * from {@see AuthManager::buildAuthResponse()}); the refresh cookie
     * gets a 7-day lifetime to match JwtHandler's refresh token TTL.
     *
     * @param array<string, mixed> $authResponse The shape returned by
     *        AuthManager (access_token, refresh_token, expires_in, user).
     * @param string $redirectTo Where to send the browser next.
     */
    private function browserAuthResponse(array $authResponse, string $redirectTo): Response
    {
        $access = is_string($authResponse['access_token'] ?? null) ? $authResponse['access_token'] : '';
        $refresh = is_string($authResponse['refresh_token'] ?? null) ? $authResponse['refresh_token'] : '';
        $expiresIn = is_int($authResponse['expires_in'] ?? null) ? $authResponse['expires_in'] : 3600;

        $response = (new Response())->redirect($redirectTo);
        if ($access !== '') {
            // HttpOnly so XSS can't read it; SameSite=Lax so top-level
            // navigations from /login still carry it.
            $response->cookie(
                self::SESSION_COOKIE,
                $access,
                maxAge: $expiresIn,
                httpOnly: true,
                sameSite: 'Lax',
            );
        }
        if ($refresh !== '') {
            $response->cookie(
                self::REFRESH_COOKIE,
                $refresh,
                maxAge: 7 * 24 * 3600,
                httpOnly: true,
                sameSite: 'Lax',
            );
        }
        return $response;
    }
}
