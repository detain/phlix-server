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
use Phlix\Auth\AuthMethodPolicy;
use Phlix\Auth\RateLimitException;
use Phlix\Auth\WebAuthn\WebAuthnManager;
use Phlix\Common\RateLimit\RateLimiterInterface;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Server\Http\Router;

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
     * F7 auth-method policy for the `auth.webauthn.enabled` toggle. Optional
     * so direct-construction test call sites keep working; the DI binding
     * names it explicitly (PHP-DI skips optional ctor params during
     * autowiring — an unbound policy would silently leave the toggle inert,
     * the same class (g) trap documented across the sibling providers).
     *
     * @var AuthMethodPolicy|null
     */
    private ?AuthMethodPolicy $authPolicy;

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
        ?AuthMethodPolicy $authPolicy = null,
    ) {
        $this->webauthnManager = $webauthnManager;
        $this->authManager = $authManager;
        $this->startAuthLimiter = $startAuthLimiter;
        $this->finishAuthLimiter = $finishAuthLimiter;
        $this->authPolicy = $authPolicy;
    }

    /**
     * F7 gate predicate: is the WEBAUTHN method available right now?
     *
     * NULL policy answers true (pre-F7 behaviour for hand-built controllers;
     * the container always injects one). A THROWING policy read FAILS CLOSED
     * (answers false): the whole ceremony is DB-backed anyway, so a store
     * failure would sink it regardless, and an auth control must never
     * degrade toward "let the ceremony run".
     */
    private function webauthnMethodAvailable(): bool
    {
        if ($this->authPolicy === null) {
            return true;
        }

        try {
            return $this->authPolicy->isEnabled(AuthMethodPolicy::WEBAUTHN);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The 401 answer shared by the two login-ceremony routes. Same fixed
     * body as every disabled-method rejection — it never reveals which
     * other methods remain enabled. Body shape is the code-channel-free
     * `{'error': ...}` exactly like the password login 401 (AuthController
     * :271) — ErrorCodesContractTest forbids inventing code-channel
     * literals outside the vendored contracts registry, and the S44
     * precedent deliberately keeps the generic credential-rejection body
     * minimal. The server-side audit log carries the precise
     * `auth_method_disabled` reason.
     */
    private function disabledMethodResponse(): Response
    {
        return (new Response())->status(401)->json([
            'error' => 'Invalid credentials',
        ]);
    }

    /**
     * The 403 answer for the two enrolment routes: the caller IS signed in
     * (authenticated), the SERVER policy simply refuses new passkey
     * enrolment while the method is off. No `code` field: the contracts
     * registry has no method-disabled member and the emit law forbids
     * inventing one — the `error` text channel (not scanned, not pinned)
     * carries the explanation to the signed-in admin UI.
     */
    private function disabledEnrollmentResponse(): Response
    {
        return (new Response())->status(403)->json([
            'error' => 'Passkey enrolment is disabled on this server',
        ]);
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

        // F7: enrolment is part of the sign-in METHOD, so the toggle refuses
        // NEW credentials while off — but the credential-management routes
        // (list/delete) below stay LIVE always, per the gate-the-route-never-
        // delete-the-config law: stored passkeys survive a toggle, and the
        // R2 write guard guarantees an admin can never have switched off a
        // method while it was their only factor.
        if (!$this->webauthnMethodAvailable()) {
            return $this->disabledEnrollmentResponse();
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

        // F7: same enrolment gate as startRegistration — a ceremony cannot
        // finish what the toggle forbids it from starting.
        if (!$this->webauthnMethodAvailable()) {
            return $this->disabledEnrollmentResponse();
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
        // F7: gate the unauthenticated login ceremony BEFORE any per-username
        // work, right after the throttle so a disabled surface still costs the
        // prober budget. Fixed-body 401 mirrors the password path's opacity.
        if (!$this->webauthnMethodAvailable()) {
            return $this->disabledMethodResponse();
        }

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
        // F7: same login-ceremony gate as startAuthentication — with the
        // method off, neither half of the ceremony runs.
        if (!$this->webauthnMethodAvailable()) {
            return $this->disabledMethodResponse();
        }

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
