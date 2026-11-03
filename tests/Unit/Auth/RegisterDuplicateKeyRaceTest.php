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
 * Signup duplicate-key race translation (review M17, dba2 major 2).
 *
 * AuthManager::register() probes username/email uniqueness OUTSIDE the
 * transaction that inserts, so two concurrent signups can both pass the
 * probes and the loser's INSERT dies on the users UNIQUE with MySQL 1062.
 * Before the cure that raw PDOException escaped to the wire as a generic
 * 500; now it is translated into the SAME 400 copy the pre-check produces,
 * riding AuthController's existing InvalidArgumentException arms — no new
 * wire codes, no shape change.
 *
 * Detection is deliberately message-based: the estate's own doctrine
 * (StreamProbeBackfill, MigrationRunner) records that connection-layer
 * rethrows drop errorInfo, leaving only the message — so a matcher keyed
 * on errorInfo[1] would be a fair-weather test. The non-1062 pin proves
 * unrelated INSERT failures are rethrown verbatim, never friendlied.
 */
class RegisterDuplicateKeyRaceTest extends TestCase
{
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

    private function silentLogger(): StructuredLogger
    {
        $path = sys_get_temp_dir() . '/phlix_dupeRace_' . uniqid('', true) . '.log';
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

    /**
     * Manager whose pre-checks see a free row and whose INSERT dies with the
     * given PDOException message. No Connection is injected: the legacy
     * non-transactional flow keeps the test about the translation itself.
     */
    private function racingManager(string $insertFailureMessage): AuthManager
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->willReturn(8);

        $repo = $this->createMock(UserRepository::class);
        $repo->method('usernameExists')->willReturn(false);
        $repo->method('emailExists')->willReturn(false);
        // No pre-existing users: the first-admin path runs, so the signup
        // gate never intercepts — the INSERT is the only failure surface.
        $repo->method('countUsers')->willReturn(0);
        $repo->method('create')->willThrowException(
            new \PDOException(
                'SQL:INSERT INTO users ... SQLSTATE[23000]: Integrity constraint violation: ' . $insertFailureMessage
            )
        );

        return new AuthManager(
            $repo,
            new JwtHandler('test-secret-key-12345', 'HS256', 3600, 604800),
            $this->createMock(AuditLogger::class),
            $this->silentLogger(),
            null,
            null,
            null,
            null,
            $settings
        );
    }

    public function testUsernameRaceLoserGetsThePreCheckCopyNotAFatal(): void
    {
        // MySQL names an inline column UNIQUE after the column: users.username.
        $manager = $this->racingManager("Duplicate entry 'bob' for key 'users.username'");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Username already taken');

        $manager->register('bob', 'bob@example.com', 'Sup3r-Secret!2026');
    }

    public function testEmailRaceLoserGetsThePreCheckCopyNotAFatal(): void
    {
        $manager = $this->racingManager("Duplicate entry 'bob@example.com' for key 'users.email'");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('That email address is already in use');

        $manager->register('bobby', 'bob@example.com', 'Sup3r-Secret!2026');
    }

    public function testUnrelatedInsertFailureIsRethrownVerbatim(): void
    {
        // A 1062 on a DIFFERENT key must never be friendlied into a signup 400
        // — it is a real fault and keeps its PDOException identity (the wire
        // gets the generic 5xx envelope). Asserted via expectException, not a
        // try/catch: PHPStan's tests corpus flags a `catch (PDOException)`
        // around register() because the @throws contract names only
        // InvalidArgumentException/SignupDisabledException — the PDO path is
        // real but undeclared, so the typed-catch would be "dead" to the
        // analyser. expectException checks the runtime truth without the lie.
        $manager = $this->racingManager("Duplicate entry 'x' for key 'PRIMARY'");

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage("key 'PRIMARY'");

        $manager->register('zoe', 'zoe@example.com', 'Sup3r-Secret!2026');
    }

    public function testNonDuplicatePdoFailureIsRethrownVerbatim(): void
    {
        $manager = $this->racingManager('server has gone away — no Duplicate entry anywhere');

        $this->expectException(\PDOException::class);

        $manager->register('amy', 'amy@example.com', 'Sup3r-Secret!2026');
    }
}
