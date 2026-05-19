<?php

declare(strict_types=1);

namespace Phlex\Server\Http\Controllers;

use Phlex\Server\Http\Request;
use Phlex\Server\Http\Response;
use Phlex\Auth\AuthManager;
use InvalidArgumentException;

/**
 * Handles authentication-related HTTP requests.
 *
 * This controller provides endpoints for user registration, login,
 * token refresh, and user profile retrieval.
 *
 * @author Phlex Media Server Team
 * @version 1.0.0
 * @description Authentication controller for user registration, login, and token management.
 * @see Request For request representation
 * @see Response For response generation
 * @see AuthManager For authentication logic
 */
class AuthController
{
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

        if (!is_string($username) || $username === '' || !is_string($email) || $email === '' || !is_string($password) || $password === '') {
            return (new Response())->status(400)->json([
                'error' => 'Missing required fields: username, email, password',
            ]);
        }

        try {
            $result = $this->authManager->register($username, $email, $password);
            return (new Response())->status(201)->json($result);
        } catch (InvalidArgumentException $e) {
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

        if (!is_string($username) || $username === '' || !is_string($password) || $password === '') {
            return (new Response())->status(400)->json([
                'error' => 'Missing required fields: username, password',
            ]);
        }

        $deviceId = $request->getHeader('X-Device-Id') ?? 'unknown';

        try {
            $result = $this->authManager->login($username, $password, $deviceId);
            return (new Response())->json($result);
        } catch (InvalidArgumentException $e) {
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
}
