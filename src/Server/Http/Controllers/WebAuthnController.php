<?php

declare(strict_types=1);

namespace Phlex\Server\Http\Controllers;

use Phlex\Auth\AuthManager;
use Phlex\Auth\WebAuthn\WebAuthnCredentialRepository;
use Phlex\Auth\WebAuthn\WebAuthnManager;
use Phlex\Auth\WebAuthn\WebAuthnSettings;
use Phlex\Server\Http\Request;
use Phlex\Server\Http\Response;
use Phlex\Server\Http\Router;
use Workerman\MySQL\Connection;

final class WebAuthnController
{
    private WebAuthnManager $webauthnManager;
    private AuthManager $authManager;

    public function __construct(
        WebAuthnManager $webauthnManager,
        AuthManager $authManager
    ) {
        $this->webauthnManager = $webauthnManager;
        $this->authManager = $authManager;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function startRegistration(Request $request, array $params): Response
    {
        $userId = $request->userId ?? null;
        if (!$userId) {
            return (new Response())->status(401)->json(['error' => 'Unauthorized']);
        }

        $data = is_array($request->body) ? $request->body : [];
        $username = $data['username'] ?? null;

        if (!is_string($username)) {
            $user = $this->authManager->getUser($userId);
            $username = is_array($user) ? ($user['username'] ?? 'user') : 'user';
        }

        try {
            $options = $this->webauthnManager->startRegistration($userId, is_string($username) ? $username : 'user');
            return (new Response())->json($options);
        } catch (\InvalidArgumentException $e) {
            return (new Response())->status(400)->json(['error' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    public function finishRegistration(Request $request, array $params): Response
    {
        $userId = $request->userId ?? null;
        if (!$userId) {
            return (new Response())->status(401)->json(['error' => 'Unauthorized']);
        }

        $data = is_array($request->body) ? $request->body : [];
        $credential = $data['credential'] ?? null;
        $challenge = $data['challenge'] ?? null;

        if (!is_array($credential) || !is_string($challenge)) {
            return (new Response())->status(400)->json([
                'error' => 'Missing required fields: credential, challenge'
            ]);
        }

        $user = $this->authManager->getUser($userId);
        $username = is_array($user) ? ($user['username'] ?? 'user') : 'user';

        try {
            $credentialId = $this->webauthnManager->finishRegistration(
                $userId,
                is_string($username) ? $username : 'user',
                $credential,
                $challenge
            );

            return (new Response())->json([
                'credential_id' => $credentialId,
                'message' => 'Passkey registered successfully'
            ]);
        } catch (\InvalidArgumentException $e) {
            return (new Response())->status(400)->json(['error' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    public function startAuthentication(Request $request, array $params): Response
    {
        $data = is_array($request->body) ? $request->body : [];
        $username = $data['username'] ?? null;

        if (!is_string($username)) {
            return (new Response())->status(400)->json([
                'error' => 'Missing required field: username'
            ]);
        }

        try {
            $options = $this->webauthnManager->startAuthentication($username);
            return (new Response())->json($options);
        } catch (\InvalidArgumentException $e) {
            return (new Response())->status(400)->json(['error' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    public function finishAuthentication(Request $request, array $params): Response
    {
        $data = is_array($request->body) ? $request->body : [];
        $username = $data['username'] ?? null;
        $credential = $data['credential'] ?? null;
        $challenge = $data['challenge'] ?? null;

        if (!is_string($username) || !is_array($credential) || !is_string($challenge)) {
            return (new Response())->status(400)->json([
                'error' => 'Missing required fields: username, credential, challenge'
            ]);
        }

        try {
            $result = $this->webauthnManager->finishAuthentication(
                $username,
                $credential,
                $challenge
            );

            if (!$result->isFailure()) {
                $authResponse = $this->authManager->buildAuthResponse($result->userId ?? '');
                return (new Response())->json($authResponse);
            }

            return (new Response())->status(401)->json(['error' => $result->error ?? 'Authentication failed']);
        } catch (\InvalidArgumentException $e) {
            return (new Response())->status(401)->json(['error' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    public function listCredentials(Request $request, array $params): Response
    {
        $userId = $request->userId ?? null;
        if (!$userId) {
            return (new Response())->status(401)->json(['error' => 'Unauthorized']);
        }

        try {
            $credentials = $this->webauthnManager->listCredentials($userId);
            $items = [];

            foreach ($credentials as $cred) {
                $items[] = $cred->toArray();
            }

            return (new Response())->json([
                'credentials' => $items
            ]);
        } catch (\Throwable $e) {
            return (new Response())->status(500)->json(['error' => 'Failed to list credentials']);
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    public function deleteCredential(Request $request, array $params): Response
    {
        $userId = $request->userId ?? null;
        if (!$userId) {
            return (new Response())->status(401)->json(['error' => 'Unauthorized']);
        }

        $credentialId = $params['id'] ?? null;
        if (!is_string($credentialId)) {
            return (new Response())->status(400)->json([
                'error' => 'Missing credential ID'
            ]);
        }

        try {
            $deleted = $this->webauthnManager->deleteCredential($userId, $credentialId);

            if ($deleted) {
                return (new Response())->json([
                    'message' => 'Credential deleted successfully'
                ]);
            }

            return (new Response())->status(404)->json([
                'error' => 'Credential not found or not owned by user'
            ]);
        } catch (\Throwable $e) {
            return (new Response())->status(500)->json(['error' => 'Failed to delete credential']);
        }
    }

    public static function registerRoutes(Router &$router, string $controllerClass): void
    {
        $router->post('/api/v1/auth/webauthn/register/options', [$controllerClass, 'startRegistration']);
        $router->post('/api/v1/auth/webauthn/register/verify', [$controllerClass, 'finishRegistration']);
        $router->post('/api/v1/auth/webauthn/login/options', [$controllerClass, 'startAuthentication']);
        $router->post('/api/v1/auth/webauthn/login/verify', [$controllerClass, 'finishAuthentication']);
        $router->get('/api/v1/me/webauthn/credentials', [$controllerClass, 'listCredentials']);
        $router->delete('/api/v1/me/webauthn/credentials/{id}', [$controllerClass, 'deleteCredential']);
    }
}
