<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Phlix\Admin\SettingsRepository;
use Phlix\Auth\AuthManager;
use Phlix\Auth\JwtHandler;
use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Common\Logger\StructuredLogger;

/**
 * The username shape law of `AuthManager::register` (minors M-SERVER-86,
 * review server__webux minor 1).
 *
 * Before the cure the guard read `strlen($username) < 3 || strlen($username) > 50`
 * while its own message promised CHARACTERS. Two defects, both pinned here:
 *
 *  1. BYTE COUNTING: a 17-character CJK username is 51 UTF-8 bytes and was
 *     refused with a false "must be 3-50 characters". mb_strlen is the law;
 *     the acceptance boundary tests use multibyte content so a re-introduced
 *     strlen reddens them (an ASCII-length test cannot tell the difference).
 *  2. WHITESPACE GHOSTS: `'   '` (three spaces) passed the byte floor and the
 *     controller's `!== ''` gate and became an account rendering as blank in
 *     every user list. Trim-first + character floor makes that shape
 *     impossible — the same `trim($name) === ''` law `CollectionController`
 *     already enforced.
 *
 * Trimming is also storage-visible: the uniqueness probe must see the TRIMMED
 * form, and an internal space is preserved untouched (trim, never strip-all).
 *
 * Reaching the post-gate code is observed through a sentinel exception from
 * the mocked repository — the gate's own verdict is the assertion, not the
 * (mocked) persistence behind it.
 */
class RegisterUsernameLawsTest extends TestCase
{
    private const GATE_PASSED = 'sentinel:register-gate-passed';

    /** @var list<string> log files minted by silentLogger(), removed in tearDown(). */
    private array $mintedLogPaths = [];

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach ($this->mintedLogPaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->mintedLogPaths = [];
        putenv('PHLIX_ENV');
    }

    private function settings(): SettingsRepository
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->willReturn(8);

        return $settings;
    }

    private function silentLogger(): StructuredLogger
    {
        $path = sys_get_temp_dir() . '/phlix_reglaws_' . uniqid('', true) . '.log';
        $this->mintedLogPaths[] = $path;

        return new StructuredLogger('test', [
            'handlers' => [
                'stream' => [
                    'type' => 'stream',
                    'path' => $path,
                    'level' => 'debug',
                ],
            ],
            'processors' => ['context' => true, 'request_id' => false, 'user_id' => false],
        ]);
    }

    private function authManager(?callable $usernameProbe = null): AuthManager
    {
        $repo = $this->createMock(UserRepository::class);
        if ($usernameProbe !== null) {
            $repo->method('usernameExists')->willReturnCallback($usernameProbe);
        } else {
            $repo->method('usernameExists')->willReturnCallback(
                // `never` not `bool`: the closure cannot return, and Psalm's
                // tests corpus reads a `: bool` throw-body arrow as an
                // unreachable return value (NoValue) — bottom type is the
                // honest signature (still valid for the `bool`-returning
                // callable shape PHPUnit expects).
                static fn (string $name): never => throw new \RuntimeException(self::GATE_PASSED),
            );
        }

        return new AuthManager(
            $repo,
            new JwtHandler('test-secret-key-12345', 'HS256', 3600, 604800),
            $this->createMock(AuditLogger::class),
            $this->silentLogger(),
            null,
            null,
            null,
            null,
            $this->settings(),
        );
    }

    public function testWhitespaceOnlyUsernameIsRejectedAsGhostAccount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Username must be 3-50 characters');

        $this->authManager()->register('   ', 'ghost@example.test', 'validpassword123');
    }

    public function testSeventeenCharacterCjkUsernamePassesTheFiftyCharacterCeiling(): void
    {
        // The ask's exact shape: 17 CJK characters = 51 UTF-8 bytes, refused by
        // the byte-counting guard while it promised "characters". Reaching the
        // sentinel probe proves the CHARACTER count (17) governs.
        $cjk = 'テストユーザー日本語名前漢字語語名';
        $this->assertSame(17, mb_strlen($cjk));
        $this->assertSame(51, strlen($cjk));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(self::GATE_PASSED);

        $this->authManager()->register($cjk, 'cjk@example.test', 'validpassword123');
    }

    public function testFiftyCharacterCjkUsernameIsStillInsideTheCeiling(): void
    {
        // EXACTLY 50 characters, each 3 bytes = 150 bytes: bytes must not vote.
        $cjk = str_repeat('語', 50);
        $this->assertSame(50, mb_strlen($cjk));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(self::GATE_PASSED);

        $this->authManager()->register($cjk, 'big@example.test', 'validpassword123');
    }

    public function testFiftyOneCharacterCjkUsernameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Username must be 3-50 characters');

        $this->authManager()->register(str_repeat('語', 51), 'over@example.test', 'validpassword123');
    }

    public function testUniquenessAndStorageSeeTheTrimmedForm(): void
    {
        $seen = null;
        $manager = $this->authManager(static function (string $name) use (&$seen): bool {
            $seen = $name;

            return true; // collision -> the pre-existing 'Username already taken' path
        });

        try {
            $manager->register(' alice ', 'trim@example.test', 'validpassword123');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Username already taken', $e->getMessage());
        }

        $this->assertSame('alice', $seen, 'the law trims BEFORE probing/persisting');
    }

    public function testInternalWhitespaceIsPreservedNotStripped(): void
    {
        $seen = null;
        $manager = $this->authManager(static function (string $name) use (&$seen): bool {
            $seen = $name;

            return true;
        });

        try {
            $manager->register('a b c', 'space@example.test', 'validpassword123');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Username already taken', $e->getMessage());
        }

        $this->assertSame('a b c', $seen, 'trim only — never strip-all whitespace (M-SERVER-86 scope)');
    }

    public function testControlAsciiThreeCharacterUsernamePassesFloor(): void
    {
        // Control that is green on BOTH codes: the floor itself stays 3.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(self::GATE_PASSED);

        $this->authManager()->register('bob', 'bob@example.test', 'validpassword123');
    }
}
