<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use Phlix\Auth\AuthManager;
use Phlix\Auth\AuthProviderRegistry;
use Phlix\Auth\JwtHandler;
use Phlix\Auth\PasswordChangeRequiredException;
use Phlix\Auth\ProviderManager;
use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Shared\Auth\AuthResult;
use Phlix\Shared\Auth\ProviderInterface;
use PHPUnit\Framework\TestCase;

/**
 * SRV-M18 (review server__pm) — the `must_change_password` latch is an
 * ACCOUNT HOLD and must block EVERY token-minting path, not just the two
 * password legs.
 *
 * Before this cure the flag was consulted only inside
 * {@see AuthManager::login()} and {@see AuthManager::refreshToken()}, while
 * {@see AuthManager::loginWithProvider()} signed a full session after a
 * successful provider exchange and every external minter
 * (`WebAuthnController::finishAuthentication`, `QuickConnectController`
 * approval, the profile-switch endpoint) handed its user id straight to
 * {@see AuthManager::buildAuthResponse()} — so the admin
 * `reset-password` force-change wall walked right off via passkey or LDAP.
 *
 * The cure is two-fold and these tests pin both halves:
 *  1. `loginWithProvider()` refuses the held account BEFORE any success
 *     bookkeeping (no `updateLastLogin`, no `logLogin` audit, no activity,
 *     no dispatch) — the same typed exception + refusal audit the password
 *     login leg raises, mirrored in shape.
 *  2. `buildAuthResponse()` — the ONE seam every mint converges on — refuses
 *     a held account as its first act, so passkey/quick-connect/profile-
 *     switch callers cannot mint either.
 *
 * The password login and refresh legs keep their own earlier checks; their
 * envelopes are byte-identical to the shipped ones and are pinned UNMODIFIED
 * by the sibling suites (`AuthManagerStatusCacheTest`, `TokenTtlEnforcement-
 * Test`, `PasswordChangeRequiredCopyTest`), which these laws must not move.
 *
 * @package Phlix\Tests\Unit\Auth
 */
final class AuthManagerLatchGateTest extends TestCase
{
    private const SECRET = 'test-secret-key-12345';

    /** @var list<string> log files minted by silentLogger(), removed in tearDown(). */
    private array $mintedLogPaths = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Same doctrine as AuthManagerProviderRateLimitTest: the rate-limit
        // store is a process-wide static keyed on client IP.
        AuthManager::resetRateLimitStore();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        AuthManager::resetRateLimitStore();
        foreach ($this->mintedLogPaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->mintedLogPaths = [];
    }

    private function silentLogger(): StructuredLogger
    {
        $path = sys_get_temp_dir() . '/phlix_latch_gate_' . uniqid() . '.log';
        $this->mintedLogPaths[] = $path;

        return new StructuredLogger('test', [
            'handlers' => [
                'stream' => [
                    'type' => 'stream',
                    'path' => $path,
                    'level' => 'debug',
                ],
            ],
        ]);
    }

    private function manager(
        UserRepository $repo,
        ?ProviderManager $providerManager = null,
        ?AuditLogger $audit = null,
        ?JwtHandler $jwt = null,
    ): AuthManager {
        return new AuthManager(
            $repo,
            $jwt ?? new JwtHandler(self::SECRET, 'HS256', 3600, 604800),
            $audit ?? $this->createMock(AuditLogger::class),
            $this->silentLogger(),
            null,   // eventDispatcher
            null,   // db
            $providerManager,
        );
    }

    /**
     * A real ProviderManager (final, so it cannot be mocked) wired to a
     * registry holding a stub 'ldap' provider that returns the given result.
     */
    private function providerReturning(AuthResult $result, UserRepository $repo): ProviderManager
    {
        $registry = new AuthProviderRegistry();
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('ldap');
        $provider->method('authenticate')->willReturn($result);
        $registry->registerProvider($provider);

        return new ProviderManager($registry, $repo);
    }

    /** @return JwtHandler&\PHPUnit\Framework\MockObject\MockObject */
    private function neverMintingJwt(): JwtHandler
    {
        $jwt = $this->createMock(JwtHandler::class);
        $jwt->expects(self::never())->method('createAccessToken');
        $jwt->expects(self::never())->method('createRefreshToken');

        return $jwt;
    }

    public function test_login_with_provider_refuses_latched_account_before_success_bookkeeping(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('mustChangePassword')
            ->willReturnCallback(static fn(string $id): bool => $id === 'user-1');
        $repo->expects(self::never())->method('updateLastLogin');

        $audit = $this->createMock(AuditLogger::class);
        $audit->expects(self::never())->method('logLogin');
        $audit->expects(self::once())->method('logFailedAuth')->with(
            'password_change_required',
            self::callback(static fn(array $context): bool => ($context['username'] ?? null) === 'ldap:alice'
                && ($context['device_id'] ?? null) === 'device-1'),
        );

        $manager = $this->manager(
            $repo,
            $this->providerReturning(new AuthResult(success: true, userId: 'user-1'), $repo),
            $audit,
            $this->neverMintingJwt(),
        );

        $this->expectException(PasswordChangeRequiredException::class);

        $manager->loginWithProvider(
            'ldap:alice',
            ['username' => 'alice', 'password' => 'hunter2'],
            'device-1',
        );
    }

    public function test_login_with_provider_mints_normally_for_an_unlatched_account(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('mustChangePassword')
            ->willReturnCallback(static fn(string $id): bool => false);
        $repo->expects(self::once())->method('updateLastLogin')->with('user-1');

        $audit = $this->createMock(AuditLogger::class);
        $audit->expects(self::once())->method('logLogin')->with('user-1', 'device-1', true);
        $audit->expects(self::never())->method('logFailedAuth');

        $manager = $this->manager(
            $repo,
            $this->providerReturning(new AuthResult(success: true, userId: 'user-1'), $repo),
            $audit,
        );

        $response = $manager->loginWithProvider(
            'ldap:alice',
            ['username' => 'alice', 'password' => 'hunter2'],
            'device-1',
        );

        self::assertIsString($response['access_token']);
        self::assertNotSame('', $response['access_token']);
        self::assertSame('Bearer', $response['token_type']);
    }

    public function test_build_auth_response_refuses_latched_account_at_the_mint_seam(): void
    {
        // This is the public seam the passkey finish (WebAuthnController),
        // the quick-connect approval (QuickConnectController) and the
        // profile switch (ProfilesController) all mint through — refusing
        // here closes every external bypass at once.
        $repo = $this->createMock(UserRepository::class);
        $repo->method('mustChangePassword')
            ->willReturnCallback(static fn(string $id): bool => $id === 'user-1');

        $audit = $this->createMock(AuditLogger::class);
        $audit->expects(self::once())->method('logFailedAuth')->with(
            'password_change_required',
            self::callback(static fn(array $context): bool => ($context['user_id'] ?? null) === 'user-1'
                && ($context['context'] ?? null) === 'auth_response'),
        );

        $manager = $this->manager($repo, null, $audit, $this->neverMintingJwt());

        $this->expectException(PasswordChangeRequiredException::class);

        $manager->buildAuthResponse('user-1');
    }

    public function test_build_auth_response_mints_normally_for_an_unlatched_account(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('mustChangePassword')
            ->willReturnCallback(static fn(string $id): bool => false);

        $manager = $this->manager($repo);

        // No profileManager is wired in this harness, so the claim resolves to
        // the account default (null) — {@see AuthManager::resolveProfileForUser()}.
        $response = $manager->buildAuthResponse('user-1');

        self::assertIsString($response['access_token']);
        self::assertIsString($response['refresh_token']);
        self::assertSame('Bearer', $response['token_type']);
        self::assertSame(3600, $response['expires_in']);
        self::assertNull($response['profile_id']);
    }
}
