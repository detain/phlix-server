<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use InvalidArgumentException;
use Phlix\Auth\AuthManager;
use Phlix\Auth\JwtHandler;
use Phlix\Auth\RateLimitException;
use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Common\Logger\StructuredLogger;
use PHPUnit\Framework\TestCase;

/**
 * SH-017 (secaudit finding 1) — the login brute-force budget gains a
 * per-ACCOUNT window alongside the per-IP one (migration 126), so failures
 * spread across many source IPs can no longer grind a single account at an
 * effectively unlimited rate.
 *
 * These tests drive the public login() through the in-memory fallback store
 * (no DbLoginRateLimitStore injected), rotating $_SERVER['REMOTE_ADDR'] to
 * simulate the IP-hopping attacker the cure targets. Pre-cure, every fresh
 * IP bought a fresh 5-attempt window and NONE of these cases tripped.
 */
final class AuthManagerAccountRateLimitTest extends TestCase
{
    /** @var list<string> log files minted by silentLogger(), removed in tearDown(). */
    private array $mintedLogPaths = [];

    private ?string $savedRemoteAddr = null;

    protected function setUp(): void
    {
        parent::setUp();
        AuthManager::resetRateLimitStore();
        $this->savedRemoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        AuthManager::resetRateLimitStore();
        if ($this->savedRemoteAddr === null) {
            unset($_SERVER['REMOTE_ADDR']);
        } else {
            $_SERVER['REMOTE_ADDR'] = $this->savedRemoteAddr;
        }
        foreach ($this->mintedLogPaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->mintedLogPaths = [];
    }

    private function silentLogger(): StructuredLogger
    {
        $path = sys_get_temp_dir() . '/phlix_acct_rl_' . uniqid() . '.log';
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

    private function manager(UserRepository $repo): AuthManager
    {
        return new AuthManager(
            $repo,
            new JwtHandler('test-secret-key-12345', 'HS256', 3600, 604800),
            $this->createMock(AuditLogger::class),
            $this->silentLogger(),
        );
    }

    /** A repository where NO identifier resolves (unknown-user branch). */
    private function missRepo(): UserRepository
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findByUsername')->willReturn(null);
        $repo->method('findByEmail')->willReturn(null);

        return $repo;
    }

    /** Set the simulated client IP for the next login() call. */
    private function fromIp(string $ip): void
    {
        $_SERVER['REMOTE_ADDR'] = $ip;
    }

    public function test_unknown_identifier_is_charged_and_locks_across_ips(): void
    {
        // Anti-enumeration symmetry: failures for a NONEXISTENT identifier
        // charge the account window exactly like known-user failures — the
        // attacker cannot tell which names exist by watching the budget, and
        // typo-squatting a name still burns its window. Pre-cure this path
        // only ever charged the (rotating, therefore free) IP.
        $manager = $this->manager($this->missRepo());

        for ($i = 0; $i < 5; $i++) {
            $this->fromIp('198.18.0.' . $i);
            try {
                $manager->login('ghost-name', 'wrong-pass-' . $i, 'dev-1');
                $this->fail('Expected InvalidArgumentException on attempt ' . ($i + 1));
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Invalid username or password', $e->getMessage());
            }
        }

        // A BRAND-NEW IP still meets the 429 ladder — the exhausted window is
        // the account's, not the source's.
        $this->fromIp('198.18.9.9');
        $this->expectException(RateLimitException::class);
        $manager->login('ghost-name', 'one-more', 'dev-1');
    }

    public function test_identifier_normalization_shares_one_budget_across_spellings(): void
    {
        // Case and surrounding whitespace variations of the same identifier
        // must draw from ONE account window (trim + casefold), or the lockout
        // stretches infinitely.
        $manager = $this->manager($this->missRepo());

        $spellings = ['ALICE', ' alice', 'Alice', 'ALICE ', 'aLiCe'];
        foreach ($spellings as $i => $spelling) {
            $this->fromIp('198.19.0.' . $i);
            try {
                $manager->login($spelling, 'wrong', 'dev-1');
                $this->fail('Expected InvalidArgumentException on attempt ' . ($i + 1));
            } catch (InvalidArgumentException $e) {
                // the plain rejection is the expected leg here
            }
        }

        $this->fromIp('198.19.9.9');
        $this->expectException(RateLimitException::class);
        $manager->login('alice', 'wrong', 'dev-1');
    }

    public function test_known_user_wrong_password_locks_the_account_across_ips(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findByUsername')->willReturn(['id' => 'user-victim', 'status' => 'active']);
        $repo->method('verifyPassword')->willReturn(false);
        $manager = $this->manager($repo);

        for ($i = 0; $i < 5; $i++) {
            $this->fromIp('198.20.0.' . $i);
            try {
                $manager->login('victim', 'guess-' . $i, 'dev-1');
                $this->fail('Expected InvalidArgumentException on attempt ' . ($i + 1));
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Invalid username or password', $e->getMessage());
            }
        }

        $this->fromIp('198.20.9.9');
        $this->expectException(RateLimitException::class);
        $manager->login('victim', 'guess-6', 'dev-1');
    }

    public function test_successful_login_releases_the_account_window(): void
    {
        // A correct password must clear BOTH budgets; otherwise yesterday's
        // typos would 429 today's legitimate user from a new network.
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findByUsername')->willReturn(['id' => 'user-ok', 'status' => 'active']);
        $repo->method('verifyPassword')->willReturnCallback(
            static fn (string $id, string $password): bool => $password === 'right-pass'
        );
        $repo->method('findById')->willReturn([
            'id' => 'user-ok',
            'username' => 'ok-user',
            'email' => 'ok@example.com',
            'display_name' => 'ok',
            'is_admin' => 0,
            'password_hash' => 'xxx',
        ]);
        $manager = $this->manager($repo);

        // Four charged failures from four IPs (under the budget on purpose).
        for ($i = 0; $i < 4; $i++) {
            $this->fromIp('198.21.0.' . $i);
            try {
                $manager->login('ok-user', 'wrong', 'dev-1');
                $this->fail('Expected InvalidArgumentException on warmup ' . ($i + 1));
            } catch (InvalidArgumentException $e) {
                // expected
            }
        }

        // The correct password lands on a FIFTH fresh IP and must succeed,
        // releasing the account window (pre-cure nothing was released there
        // because nothing was charged).
        $this->fromIp('198.21.9.9');
        $result = $manager->login('ok-user', 'right-pass', 'dev-1');
        $this->assertSame('user-ok', $result['user']['id']);

        // Five post-success failures from five fresh IPs must all be plain
        // rejections again (fresh budget, not a carried-over lockout), and
        // only NOW does the account budget trip — proving the five
        // PRE-success failures were forgiven.
        for ($i = 0; $i < 5; $i++) {
            $this->fromIp('198.22.0.' . $i);
            try {
                $manager->login('ok-user', 'wrong-again-' . $i, 'dev-1');
                $this->fail('Expected plain rejection after the window was released');
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Invalid username or password', $e->getMessage());
            }
        }

        $this->fromIp('198.23.0.0');
        $this->expectException(RateLimitException::class);
        $manager->login('ok-user', 'wrong-final', 'dev-1');
    }

    public function test_ip_window_ladder_is_untouched_by_the_account_cure(): void
    {
        // Preservation law (SH-017 must not reorder 074): five failures from
        // ONE ip against FIVE DIFFERENT identifiers still trip the IP window
        // on the sixth attempt — the account spread does not rescue the IP.
        $manager = $this->manager($this->missRepo());

        $this->fromIp('198.24.0.1');
        for ($i = 0; $i < 5; $i++) {
            try {
                $manager->login('name-' . $i, 'wrong', 'dev-1');
                $this->fail('Expected InvalidArgumentException on attempt ' . ($i + 1));
            } catch (InvalidArgumentException $e) {
                // expected
            }
        }

        $this->expectException(RateLimitException::class);
        $manager->login('someone-else', 'wrong', 'dev-1');
    }
}
