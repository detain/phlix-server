<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use Phlix\Access\ProfileAccessPolicy;
use Phlix\Auth\AuthManager;
use Phlix\Auth\PasswordChangeRequiredException;
use Phlix\Auth\QuickConnectPair;
use Phlix\Auth\QuickConnectStateStore;
use Phlix\Auth\QuickConnectStateStoreInterface;
use Phlix\Auth\UserProfileManager;
use Phlix\Auth\UserRepository;
use Phlix\Auth\WebAuthn\WebAuthnManager;
use Phlix\Common\RateLimit\RateLimiterInterface;
use Phlix\Media\Storage\AvatarStorage;
use Phlix\Server\Http\Controllers\Auth\QuickConnectController;
use Phlix\Server\Http\Controllers\AuthController;
use Phlix\Server\Http\Controllers\ProfilesController;
use Phlix\Server\Http\Controllers\WebAuthnController;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Shared\Auth\AuthResult;
use PHPUnit\Framework\TestCase;

/**
 * SRV-M17 — the ACCOUNT-HOLD exception must reach the client as the
 * contracts-registered distinct 403, never as the router's bare 500 (and,
 * on the LDAP leg, never as the misleading 503 'provider not available').
 *
 * The hold law itself (SRV-M18: no route mints around it) is pinned by
 * {@see \Phlix\Tests\Unit\Auth\AuthManagerLatchGateTest}; this suite pins
 * the HTTP boundary mapping the exception docblock now promises:
 *   POST /api/v1/auth/login          (local password)  → 403 { error, code }
 *   POST /api/v1/auth/login          (browser leg)     → 302 /login?error=...
 *   POST /api/v1/auth/login          (ldap: prefix)    → 403 — NOT 503
 *   POST /api/v1/auth/webauthn/finish                  → 403 { error, code }
 *   POST /api/v1/auth/quick-connect/{code}/token       → 403 (pair stays spent)
 *   POST /api/v1/profiles/{id}/switch                  → 403 (switch committed)
 *
 * Red at the parent by construction: before the cure the exception escaped
 * each controller (RuntimeException with no catch-arm — the unit harness
 * shows it as an ERROR/throw) and, on the LDAP leg, was swallowed by the
 * broader RuntimeException arm into 503 provider_unavailable.
 */
final class PasswordChangeRequiredHttpBoundaryTest extends TestCase
{
    private const HOLD_COPY = 'Your password must be changed before you can sign in. '
        . 'Ask your server administrator to reset it.';

    // -----------------------------------------------------------------
    // POST /api/v1/auth/login — local password leg
    // -----------------------------------------------------------------

    public function test_password_login_on_held_account_answers_403_with_the_contracts_code(): void
    {
        $authManager = $this->createMock(AuthManager::class);
        $authManager->method('login')
            ->willThrowException(new PasswordChangeRequiredException());

        $controller = new AuthController($authManager);
        $request = new Request();
        $request->body = ['username' => 'held', 'password' => 'correct-password'];

        $response = $controller->login($request, []);

        self::assertHoldEnvelope($response);
    }

    public function test_password_login_browser_leg_on_held_account_redirects_with_the_hold_copy(): void
    {
        $authManager = $this->createMock(AuthManager::class);
        $authManager->method('login')
            ->willThrowException(new PasswordChangeRequiredException());

        $controller = new AuthController($authManager);
        $request = new Request();
        $request->path = '/auth/login';
        $request->body = ['username' => 'held', 'password' => 'correct-password'];

        $response = $controller->login($request, []);

        self::assertSame(302, $response->statusCode);
        self::assertSame(
            '/login?error=' . rawurlencode(self::HOLD_COPY),
            $response->headers['Location'] ?? null
        );
    }

    // -----------------------------------------------------------------
    // POST /api/v1/auth/login — ldap: leg (F8: hold must not masquerade
    // as an infrastructure outage)
    // -----------------------------------------------------------------

    public function test_ldap_login_on_held_account_answers_403_not_the_503_unavailable_arm(): void
    {
        $authManager = $this->createMock(AuthManager::class);
        $authManager->method('loginWithProvider')
            ->willThrowException(new PasswordChangeRequiredException());

        $controller = new AuthController($authManager);
        $request = new Request();
        $request->body = ['username' => 'ldap:held', 'password' => 'correct-password'];

        $response = $controller->login($request, []);

        self::assertHoldEnvelope($response);
        // The 503 arm's copy must be gone — 'not available' is a lie when
        // the provider answered and the ACCOUNT is what refuses.
        $body = json_decode((string) $response->body, true);
        self::assertIsArray($body);
        self::assertStringNotContainsString('not available', (string) ($body['error'] ?? ''));
        self::assertStringNotContainsString('provider_unavailable', (string) ($body['code'] ?? ''));
    }

    // -----------------------------------------------------------------
    // POST /api/v1/auth/webauthn/finish — ceremony succeeds, mint refuses
    // -----------------------------------------------------------------

    public function test_webauthn_finish_on_held_account_answers_403(): void
    {
        $webauthnManager = $this->createMock(WebAuthnManager::class);
        $webauthnManager->method('finishAuthentication')
            ->willReturn(new AuthResult(success: true, userId: 'user-held'));

        $authManager = $this->createMock(AuthManager::class);
        $authManager->method('buildAuthResponse')
            ->willThrowException(new PasswordChangeRequiredException());

        $controller = new WebAuthnController($webauthnManager, $authManager);
        $request = new Request();
        $request->body = [
            'username' => 'held',
            'credential' => ['id' => 'c1'],
            'challenge' => 'ch-1',
        ];

        $response = $controller->finishAuthentication($request, []);

        self::assertHoldEnvelope($response);
    }

    // -----------------------------------------------------------------
    // POST /api/v1/auth/quick-connect/{code}/token — holder's pairing is
    // already spent when the mint seam refuses
    // -----------------------------------------------------------------

    public function test_quick_connect_token_on_held_approver_answers_403(): void
    {
        $pair = new QuickConnectPair('ACDFGH', 's', QuickConnectPair::STATE_APPROVED, 'user-held', time() + 300);
        $store = $this->createMock(QuickConnectStateStoreInterface::class);
        $store->expects(self::once())
            ->method('consumeApproved')
            ->with('ACDFGH', 's')
            ->willReturn([QuickConnectStateStore::RESULT_APPROVED, $pair]);

        $authManager = $this->createMock(AuthManager::class);
        $authManager->method('buildAuthResponse')
            ->willThrowException(new PasswordChangeRequiredException());

        $controller = new QuickConnectController($authManager, $store);
        $request = new Request();
        $request->body = ['secret' => 's'];

        $response = $controller->token($request, ['code' => 'ACDFGH']);

        self::assertHoldEnvelope($response);
    }

    // -----------------------------------------------------------------
    // POST /api/v1/profiles/{profileId}/switch — switch commits, mint refuses
    // -----------------------------------------------------------------

    public function test_profile_switch_on_held_account_answers_403(): void
    {
        $profiles = $this->createMock(UserProfileManager::class);
        $profiles->method('findById')
            ->willReturnCallback(
                static fn (string $id): array => ['id' => $id, 'user_id' => 'user-1'],
            );
        $profiles->expects(self::once())
            ->method('switchProfile')
            ->with('user-1', 'p-2')
            ->willReturn(true);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findAdminById')->willReturn(null);

        $authManager = $this->createMock(AuthManager::class);
        $authManager->method('buildAuthResponse')
            ->willThrowException(new PasswordChangeRequiredException());

        $controller = new ProfilesController(
            $profiles,
            new ProfileAccessPolicy($profiles, $userRepo),
            $authManager,
            $this->createMock(AvatarStorage::class),
            $this->createMock(RateLimiterInterface::class),
        );
        $request = new Request();
        $request->userId = 'user-1';

        $response = $controller->switchProfile($request, ['profileId' => 'p-2']);

        self::assertHoldEnvelope($response);
    }

    /**
     * The SRV-M17 envelope law: 403 { error: hold copy, code: the
     * contracts-registered auth.password_change_required } and NOTHING
     * else — no token keys, no session material.
     */
    private static function assertHoldEnvelope(Response $response): void
    {
        self::assertSame(403, $response->statusCode);

        $body = json_decode((string) $response->body, true);
        self::assertIsArray($body);
        self::assertSame(self::HOLD_COPY, $body['error'] ?? null);
        self::assertSame(PasswordChangeRequiredException::ERROR_CODE, $body['code'] ?? null);
        self::assertSame(['error', 'code'], array_keys($body));

        // Nothing session-shaped may ride out of a refusal.
        self::assertArrayNotHasKey('access_token', $body);
        self::assertArrayNotHasKey('accessToken', $body);
        self::assertArrayNotHasKey('refresh_token', $body);
    }
}
