<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use Phlix\Auth\AuthManager;
use Phlix\Auth\JwtHandler;
use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Common\Logger\StructuredLogger;
use PHPUnit\Framework\TestCase;

/**
 * Verifies AuthManager::verifyCredentials — the session-free credential check
 * behind HTTP Basic auth on the OPDS feeds. Unlike login() it issues no tokens
 * and creates no session; it answers "valid credentials for an active account?"
 * and returns the user id (or null).
 */
final class AuthManagerVerifyCredentialsTest extends TestCase
{
    private function silentLogger(): StructuredLogger
    {
        return new StructuredLogger('test', [
            'handlers' => [
                'stream' => [
                    'type' => 'stream',
                    'path' => sys_get_temp_dir() . '/phlix_verifycreds_' . uniqid() . '.log',
                    'level' => 'debug',
                ],
            ],
        ]);
    }

    private function manager(UserRepository $repo): AuthManager
    {
        return new AuthManager(
            $repo,
            new JwtHandler('test-secret-key-12345', 'HS256', 3600, 604800),
            $this->createMock(AuditLogger::class),
            $this->silentLogger(),
        );
    }

    public function testReturnsUserIdForValidActiveCredentials(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findByUsername')->with('alice')->willReturn(['id' => 'user-42', 'status' => 'active']);
        $repo->method('verifyPassword')->with('user-42', 'topsecret123')->willReturn(true);

        $this->assertSame('user-42', $this->manager($repo)->verifyCredentials('alice', 'topsecret123'));
    }

    public function testFallsBackToEmailLookup(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findByUsername')->willReturn(null);
        $repo->expects($this->once())
            ->method('findByEmail')
            ->with('alice@example.com')
            ->willReturn(['id' => 'user-42', 'status' => 'active']);
        $repo->method('verifyPassword')->willReturn(true);

        $this->assertSame('user-42', $this->manager($repo)->verifyCredentials('alice@example.com', 'topsecret123'));
    }

    public function testDefaultsToActiveWhenStatusColumnMissing(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findByUsername')->willReturn(['id' => 'user-42']); // no status key
        $repo->method('verifyPassword')->willReturn(true);

        $this->assertSame('user-42', $this->manager($repo)->verifyCredentials('alice', 'topsecret123'));
    }

    public function testReturnsNullForWrongPassword(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findByUsername')->willReturn(['id' => 'user-42', 'status' => 'active']);
        $repo->method('verifyPassword')->willReturn(false);

        $this->assertNull($this->manager($repo)->verifyCredentials('alice', 'wrong'));
    }

    public function testReturnsNullForUnknownUser(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findByUsername')->willReturn(null);
        $repo->method('findByEmail')->willReturn(null);

        $this->assertNull($this->manager($repo)->verifyCredentials('ghost', 'whatever'));
    }

    public function testReturnsNullForInactiveAccount(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findByUsername')->willReturn(['id' => 'user-42', 'status' => 'pending']);
        $repo->method('verifyPassword')->willReturn(true);

        $this->assertNull($this->manager($repo)->verifyCredentials('alice', 'topsecret123'));
    }

    /**
     * SRV-M18 REWORK scope decision (review server__pm [Minor], EXTENDED):
     * the hold covers the OPDS HTTP-Basic surface too — the admin reset flow
     * stamps the flag precisely because the password is trusted-but-
     * compromised, so a compromised password must not keep streaming while the
     * SPA is walled. This method's contract is silent nulls and the arm
     * honours it: no exception, no audit row.
     *
     * This test goes RED at 3e3e47a8 (the parent): there the held account
     * still streams (the check returns 'user-42').
     */
    public function testReturnsNullForHeldAccount(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findByUsername')->willReturn(['id' => 'user-42', 'status' => 'active']);
        $repo->method('verifyPassword')->with('user-42', 'topsecret123')->willReturn(true);
        $repo->method('mustChangePassword')->with('user-42')->willReturn(true);

        $this->assertNull($this->manager($repo)->verifyCredentials('alice', 'topsecret123'));
    }

    /**
     * The hold consult happens only AFTER the credentials verify and the
     * account proves active — never before. That ordering keeps the extra
     * SELECT from becoming an account-enumeration oracle and matches the
     * documented refusal shape (a held refusal is indistinguishable from any
     * other silent null).
     */
    public function testHeldAccountFlagIsConsultedOnlyAfterCredentialsVerify(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findByUsername')->willReturn(['id' => 'user-42', 'status' => 'active']);
        $repo->method('verifyPassword')->willReturn(false);
        $repo->expects($this->never())->method('mustChangePassword');

        $this->assertNull($this->manager($repo)->verifyCredentials('alice', 'wrong'));
    }
}
