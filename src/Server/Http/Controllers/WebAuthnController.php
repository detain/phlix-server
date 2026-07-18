<?php

/**
 * Phlix media server component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Auth\AuthManager;
use Phlix\Auth\RateLimitException;
use Phlix\Auth\WebAuthn\WebAuthnCredentialRepository;
use Phlix\Auth\WebAuthn\WebAuthnManager;
use Phlix\Auth\WebAuthn\WebAuthnSettings;
use Phlix\Common\RateLimit\RateLimiterInterface;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Server\Http\Router;
use Workerman\MySQL\Connection;

final class WebAuthnController
{
    private WebAuthnManager $webauthnManager;
    private AuthManager $authManager;

    /**
     * Per-surface rate limiter for the WebAuthn start-authentication ceremony
     * (SV-4.15(f)); the DB-backed {@see RateLimitProfiles::WEBAUTHN_START}
     * instance in production, null (no-op) only in direct-construction tests.
     *
     * @var RateLimiterInterface|null
     */
    private ?RateLimiterInterface $startAuthLimiter;

    /**
     * Per-surface rate limiter for the WebAuthn finish-authentication ceremony
     * (SV-4.15(f)); the DB-backed {@see RateLimitProfiles::WEBAUTHN_FINISH}
     * instance in production, null (no-op) only in direct-construction tests.
     *
     * @var RateLimiterInterface|null
     */
    private ?RateLimiterInterface $finishAuthLimiter;

    /**
     * The limiters are optional so existing direct-construction call sites keep
     * working; the DI factory binds each explicitly to its
     * {@see RateLimitProfiles} container id (PHP-DI skips optional ctor params
     * during autowiring, so an unbound limiter would silently stay null and
     * leave the surface open).
     */
    public function __construct(
        WebAuthnManager $webauthnManager,
        AuthManager $authManager,
        ?RateLimiterInterface $startAuthLimiter = null,
        ?RateLimiterInterface $finishAuthLimiter = null,
    ) {
        $this->webauthnManager = $webauthnManager;
        $this->authManager = $authManager;
        $this->startAuthLimiter = $startAuthLimiter;
        $this->finishAuthLimiter = $finishAuthLimiter;
    }

    /**
     * Record one attempt against `$limiter` for `$key` and, when over budget,
     * throw {@see RateLimitException} — which the central mapping (SV-4.15(c))
     * turns into a 429 + `Retry-After` + `code=rate_limited` response. A null
     * limiter is a no-op (direct-construction test path).
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
     * Build the rate-limit identifier: the submitted username when present, else
     * the TRUSTED client IP (trusted-proxy-aware; a forged X-Forwarded-For can no
     * longer mint a fresh bucket — SV-4.15 HIGH) — NOT $_SERVER, which is stale
     * under Workerman's resident workers. Keying on the username throttles
     * credential-verification attempts against a single account.
     *
     * SV-4.15 Finding 3 (LOW, accepted tradeoff): per-username keying does NOT
     * throttle HORIZONTAL enumeration — a spray of one attempt each across many
     * usernames from a single IP hits a fresh bucket per username. A secondary
     * per-IP cap is deliberately NOT added here: at the surface's tight 10/60s
     * budget it would false-positive on legitimate shared-IP / NAT'd households
     * (multiple family members behind one public IP), and a correctly-budgeted
     * separate per-IP limiter would need its own profile + DI wiring beyond this
     * fix's scope. WebAuthn "start" is a low-value enumeration oracle (it only
     * reveals whether a username has passkeys); the account-scoped throttle plus
     * the existing per-IP `login` limiter (DbLoginRateLimitStore) are judged
     * sufficient. Revisit if enumeration abuse is observed.
     */
    private function limitIdentifier(mixed $username, Request $request): string
    {
        return (is_string($username) && $username !== '')
            ? $username
            : $request->getTrustedClientIp();
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

        // Throttle enumeration BEFORE the ceremony work. Keyed on the username
        // (falls back to client IP when absent). A trip throws
        // RateLimitException -> central 429 mapping.
        $this->enforceRateLimit(
            $this->startAuthLimiter,
            'webauthn_start:' . $this->limitIdentifier($username, $request)
        );

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

        // Throttle credential-verification attempts BEFORE the ceremony work.
        // Keyed on the username (falls back to client IP when absent). A trip
        // throws RateLimitException -> central 429 mapping.
        $this->enforceRateLimit(
            $this->finishAuthLimiter,
            'webauthn_finish:' . $this->limitIdentifier($username, $request)
        );

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
