<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use Phlix\Media\Transcoding\EncodeSlotLedger;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\SegmentProcessRegistry;
use Phlix\Media\Transcoding\TranscodeManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Workerman\MySQL\Connection;

/**
 * The cross-worker admission ledger for on-demand segment encodes.
 *
 * Covers the acquire/refuse cap, release (single-line, middle-of-three, idempotent and unknown-token
 * releases, and the failed-write retry flag), age expiry, the pid keep rule (a running launch past its
 * lifetime is kept, a dead one is dropped, and the hard ceiling always drops), corrupt-line skipping,
 * future-dated and backward-clock stamps, the prewarm budget and headroom rules, the fail-closed path
 * when the lock, the lock file or the ledger is unavailable, the lock being taken on the lock file
 * before the ledger is read, the atomic temp-and-rename rewrite (a failed or short write leaves the
 * previous ledger intact), and the one normalised wall-bound int shared by the ledger and the registry.
 *
 * The clock, the lock and the temp-file writer are injected seams, so nothing here sleeps. The lock
 * tests use the real flock on a real lock file, through a second handle in the same process.
 *
 * The TranscodeManager slot-release gate (a reaped token is freed only by a confirmed settle, each reap
 * settles on its own, and a launch's release uses its own pid) is exercised at the end of this file, on
 * the real ledger file. The lock-contention deferral, the every-access-under-lock probe and the stale-temp
 * cleanup are exercised here as well.
 */
final class EncodeSlotLedgerTest extends TestCase
{
    private const LIFETIME = 330;

    private const NOW = 1_700_000_000;

    private string $dir;

    private string $path;

    private int $now = self::NOW;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/phlix_slots_' . uniqid('', true);
        mkdir($this->dir, 0755, true);
        $this->path = $this->dir . '/' . EncodeSlotLedger::FILE_NAME;
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->dir);
    }

    private function ledger(
        ?\Closure $lock = null,
        ?LoggerInterface $logger = null,
        ?string $path = null,
        ?\Closure $write = null,
    ): EncodeSlotLedger {
        $clock = fn (): int => $this->now;

        return new EncodeSlotLedger($path ?? $this->path, self::LIFETIME, $logger, $clock, $lock, $write);
    }

    private function lineFor(string $token, int $stamp): string
    {
        return $token . ' ' . $stamp . " 0\n";
    }

    private function fileText(): string
    {
        return is_file($this->path) ? (string) file_get_contents($this->path) : '';
    }

    private function lockPath(): string
    {
        return $this->dir . '/' . EncodeSlotLedger::LOCK_FILE_NAME;
    }

    /**
     * A pid that named a process which has exited and been reaped, so it names nothing running.
     */
    private function deadPid(): int
    {
        $proc = proc_open([PHP_BINARY, '-r', 'exit(0);'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc);
        $pid = (int) proc_get_status($proc)['pid'];
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($proc); // waits for the child and reaps it

        return $pid;
    }

    public function testAcquireAdmitsUpToTheCapThenRefuses(): void
    {
        $ledger = $this->ledger();

        $first = $ledger->acquire(3);
        $second = $ledger->acquire(3);
        $third = $ledger->acquire(3);
        $fourth = $ledger->acquire(3);

        $this->assertIsArray($first);
        $this->assertIsArray($second);
        $this->assertIsArray($third);
        $this->assertSame([1, 2, 3], [$first['inFlight'], $second['inFlight'], $third['inFlight']]);
        $this->assertNull($fourth, 'the fourth admission at a cap of 3 is refused');
        $this->assertCount(3, array_filter(explode("\n", $this->fileText())));
    }

    public function testATokenIsOnDemandOrPrewarmByItsPrefix(): void
    {
        $ledger = $this->ledger();

        $demand = $ledger->acquire(8);
        $prewarm = $ledger->acquire(8, true, 8, 8);

        $this->assertIsArray($demand);
        $this->assertIsArray($prewarm);
        $this->assertMatchesRegularExpression('/^d[0-9a-f]{16}$/', $demand['token']);
        $this->assertMatchesRegularExpression('/^p[0-9a-f]{16}$/', $prewarm['token']);
    }

    public function testReleaseFreesASlot(): void
    {
        $ledger = $this->ledger();
        $grant = $ledger->acquire(1);
        $this->assertIsArray($grant);
        $this->assertNull($ledger->acquire(1), 'the cap of 1 is full');

        $this->assertTrue($ledger->release($grant['token']));

        $this->assertIsArray($ledger->acquire(1), 'the released slot is admitted again');
    }

    public function testReleaseOfTheMiddleOfThreeLinesRemovesOnlyThatLine(): void
    {
        $ledger = $this->ledger();
        $first = $ledger->acquire(8);
        $middle = $ledger->acquire(8);
        $last = $ledger->acquire(8);
        $this->assertIsArray($first);
        $this->assertIsArray($middle);
        $this->assertIsArray($last);

        $this->assertTrue($ledger->release($middle['token']));

        $text = $this->fileText();
        $this->assertStringContainsString($first['token'], $text, 'the first line remains');
        $this->assertStringNotContainsString($middle['token'], $text, 'the named line is removed');
        $this->assertStringContainsString($last['token'], $text, 'the last line remains');
        $this->assertSame(2, count(array_filter(explode("\n", $text))), 'exactly two lines remain');
    }

    public function testStaleLinesExpireByAgeNotByDirectoryScan(): void
    {
        // A worker died between acquire and launch: its line is one lifetime old and no release ever comes.
        file_put_contents(
            $this->path,
            $this->lineFor('d' . str_repeat('a', 16), $this->now - self::LIFETIME)
        );
        $ledger = $this->ledger();

        $grant = $ledger->acquire(1);

        $this->assertIsArray($grant, 'an expired line no longer holds the only slot');
        $this->assertSame(1, $grant['inFlight']);
        $this->assertStringNotContainsString(str_repeat('a', 16), $this->fileText(), 'the expired line is dropped on the rewrite');
    }

    public function testALineYoungerThanTheLifetimeStillHoldsItsSlot(): void
    {
        file_put_contents(
            $this->path,
            $this->lineFor('d' . str_repeat('b', 16), $this->now - self::LIFETIME + 1)
        );

        $this->assertNull($this->ledger()->acquire(1), 'a line one second inside the lifetime still counts');
    }

    public function testALineWhosePidIsStillRunningIsKeptPastItsLifetime(): void
    {
        $token = 'd' . str_repeat('f', 16);
        file_put_contents($this->path, $token . ' ' . ($this->now - self::LIFETIME) . ' ' . getmypid() . "\n");

        $this->assertNull($this->ledger()->acquire(1), 'a running launch keeps its slot past the lifetime');
        $this->assertStringContainsString($token, $this->fileText(), 'the line is kept on the rewrite');
    }

    public function testALineWhosePidIsDeadIsDroppedPastItsLifetime(): void
    {
        $token = 'd' . str_repeat('e', 16);
        file_put_contents($this->path, $token . ' ' . ($this->now - self::LIFETIME) . ' ' . $this->deadPid() . "\n");

        $grant = $this->ledger()->acquire(1);

        $this->assertIsArray($grant, 'a dead launch past its lifetime no longer holds the slot');
        $this->assertStringNotContainsString($token, $this->fileText());
    }

    /**
     * An expired line whose pid's signal-0 probe returns $errno (null for a missing posix extension). The pid is
     * one no process can hold, so the /proc zombie check reads nothing and only the probe decides.
     *
     * @return array{token: string, grant: array<string, mixed>|null, text: string}
     */
    private function expiredLineUnderSignalZero(?int $errno): array
    {
        $token = 'd' . str_repeat('a', 16);
        file_put_contents($this->path, $token . ' ' . ($this->now - self::LIFETIME) . " 2000000000\n");
        $ledger = $this->ledger();
        $ledger->setSignalZeroProbe(static fn (int $pid): ?int => $errno);
        $grant = $ledger->acquire(1);

        return ['token' => $token, 'grant' => $grant, 'text' => $this->fileText()];
    }

    public function testEpermOnTheLinesPidCountsAsAliveSoTheExpiredLineIsKept(): void
    {
        // EPERM (1): the process exists and is not ours to signal. The registry rule says alive, so the line keeps its slot.
        $seen = $this->expiredLineUnderSignalZero(1);

        $this->assertNull($seen['grant'], 'EPERM: the pid is alive, so the expired line still holds the only slot');
        $this->assertStringContainsString($seen['token'], $seen['text']);
    }

    public function testAnyErrnoOtherThanEsrchCountsAsAliveSoTheExpiredLineIsKept(): void
    {
        // EINVAL (22) stands for any errno other than ESRCH: nothing shows the pid gone.
        $seen = $this->expiredLineUnderSignalZero(22);

        $this->assertNull($seen['grant'], 'a non-ESRCH errno: the pid is alive, so the line is kept');
        $this->assertStringContainsString($seen['token'], $seen['text']);
    }

    public function testEsrchOnTheLinesPidCountsAsGoneSoTheExpiredLineIsDropped(): void
    {
        $seen = $this->expiredLineUnderSignalZero(3);

        $this->assertIsArray($seen['grant'], 'ESRCH: the pid is gone, so the expired line no longer holds the slot');
        $this->assertStringNotContainsString($seen['token'], $seen['text']);
    }

    public function testWithoutPosixTheLinesPidCountsAsAliveSoTheExpiredLineIsKept(): void
    {
        // No posix extension and no /proc entry for the pid: nothing shows it gone, so it is alive.
        $seen = $this->expiredLineUnderSignalZero(null);

        $this->assertNull($seen['grant'], 'no posix: the pid is alive, so the expired line is kept');
        $this->assertStringContainsString($seen['token'], $seen['text']);
    }

    public function testTheHardCeilingDropsALineEvenWhenItsPidIsRunning(): void
    {
        $token = 'd' . str_repeat('c', 16);
        $ceilingAge = EncodeSlotLedger::HARD_CEILING_FACTOR * self::LIFETIME;
        file_put_contents($this->path, $token . ' ' . ($this->now - $ceilingAge) . ' ' . getmypid() . "\n");

        $grant = $this->ledger()->acquire(1);

        $this->assertIsArray($grant, 'past the hard ceiling the line is dropped whatever its pid does');
        $this->assertStringNotContainsString($token, $this->fileText());
    }

    public function testAttachedPidKeepsTheLaunchesLineAliveUntilItExits(): void
    {
        $ledger = $this->ledger();
        $grant = $ledger->acquire(1);
        $this->assertIsArray($grant);

        $this->assertFalse($ledger->attachPid($grant['token'], 0), 'a non-positive pid is not recorded');
        $this->assertTrue($ledger->attachPid($grant['token'], getmypid()));
        $this->assertStringContainsString(' ' . getmypid(), $this->fileText(), 'the pid is on the line');

        $this->now += self::LIFETIME; // the encode is still running past its lifetime
        $this->assertNull($ledger->acquire(1), 'the running launch keeps its slot past the lifetime');
    }

    public function testCorruptLinesAreIgnored(): void
    {
        $valid = 'd' . str_repeat('c', 16);
        $text = "garbage\n"
            . "d0123456789abcdef abc 0\n"            // non-numeric stamp
            . "x0123456789abcdef {$this->now} 0\n"    // bad token prefix
            . "d0123456789abcdef {$this->now} 0 extra\n" // trailing field
            . "d0123 {$this->now} 0\n"                // short token
            . $this->lineFor($valid, $this->now);
        file_put_contents($this->path, $text);
        $ledger = $this->ledger();

        $grant = $ledger->acquire(2);

        $this->assertIsArray($grant);
        $this->assertSame(2, $grant['inFlight'], 'only the one valid line is counted before this admission');
        $this->assertNull($ledger->acquire(2), 'the cap of 2 is now full, and no corrupt line counted');
        $this->assertSame(
            2,
            count(array_filter(explode("\n", $this->fileText()))),
            'the rewrite leaves only the valid lines'
        );
    }

    public function testAFutureDatedLineIsKeptAndItsStampIsClampedToNow(): void
    {
        $far = 'd' . str_repeat('6', 16);
        $near = 'd' . str_repeat('7', 16);
        file_put_contents(
            $this->path,
            $this->lineFor($far, $this->now + 10 * self::LIFETIME)
            . $this->lineFor($near, $this->now + EncodeSlotLedger::FUTURE_SKEW_SECONDS)
        );

        $grant = $this->ledger()->acquire(8);

        $this->assertIsArray($grant);
        $this->assertSame(3, $grant['inFlight'], 'both future-dated lines still hold their slots');
        $text = $this->fileText();
        $this->assertStringContainsString($far . ' ' . $this->now . ' 0', $text, 'a far-future stamp is clamped to now');
        $this->assertStringContainsString(
            $near . ' ' . ($this->now + EncodeSlotLedger::FUTURE_SKEW_SECONDS) . ' 0',
            $text,
            'a stamp within the skew allowance is kept as written'
        );
    }

    public function testABackwardClockStepDoesNotDeleteALiveLine(): void
    {
        $token = 'd' . str_repeat('8', 16);
        file_put_contents($this->path, $this->lineFor($token, $this->now));
        $this->now -= 1000; // the wall clock stepped back 1000 s

        $this->assertNull($this->ledger()->acquire(1), 'the live line still holds the only slot after the step');
        $this->assertStringContainsString($token . ' ' . $this->now . ' 0', $this->fileText(), 'its stamp follows the clock');
    }

    public function testReleaseOfAnUnknownTokenIsANoOp(): void
    {
        $ledger = $this->ledger();
        $grant = $ledger->acquire(4);
        $this->assertIsArray($grant);
        $before = $this->fileText();

        $this->assertFalse($ledger->release('d' . str_repeat('0', 16)), 'a token that was never admitted');
        $this->assertFalse($ledger->release('not-a-token'), 'a malformed token');
        $this->assertFalse($ledger->lastFailed(), 'a no-op is not a failure');
        $this->assertSame($before, $this->fileText(), 'the ledger is untouched');
    }

    public function testATokenWithATrailingNewlineIsNotAToken(): void
    {
        $ledger = $this->ledger();
        $grant = $ledger->acquire(4);
        $this->assertIsArray($grant);

        $this->assertFalse($ledger->release($grant['token'] . "\n"), 'the token pattern is anchored with \z, not $');
        $this->assertStringContainsString($grant['token'], $this->fileText(), 'the line is untouched');
    }

    public function testReleasingATokenTwiceIsIdempotent(): void
    {
        $ledger = $this->ledger();
        $grant = $ledger->acquire(4);
        $this->assertIsArray($grant);

        $this->assertTrue($ledger->release($grant['token']));
        $this->assertFalse($ledger->release($grant['token']), 'the second release removes nothing');
        $this->assertSame('', $this->fileText());
    }

    public function testAReleaseThatFailsToWriteReportsFailureAndTheLineStays(): void
    {
        $writes = new \stdClass();
        $writes->failing = false;
        $writer = static function (string $file, string $text) use ($writes): int|false {
            return $writes->failing ? false : file_put_contents($file, $text);
        };
        $ledger = $this->ledger(null, null, null, $writer);
        $grant = $ledger->acquire(4);
        $this->assertIsArray($grant);

        $writes->failing = true;
        $this->assertFalse($ledger->release($grant['token']));
        $this->assertTrue($ledger->lastFailed(), 'a failed write is reported, so the caller may retry');
        $this->assertStringContainsString($grant['token'], $this->fileText(), 'the line stays until a release succeeds');

        $writes->failing = false;
        $this->assertTrue($ledger->release($grant['token']), 'the retry removes the line');
        $this->assertFalse($ledger->lastFailed(), 'a successful retry clears the failure');
    }

    public function testPrewarmRefusesAtTheBudgetWhileTheReaderStillAdmits(): void
    {
        // cap 8, prewarm total ceiling 6 (cap less headroom), prewarm budget 2.
        $ledger = $this->ledger();

        $this->assertIsArray($ledger->acquire(8, true, 6, 2));
        $this->assertIsArray($ledger->acquire(8, true, 6, 2));
        $this->assertNull($ledger->acquire(8, true, 6, 2), 'the prewarm budget of 2 is spent');

        $this->assertIsArray($ledger->acquire(8), 'a seek is still admitted under the cap');
    }

    public function testPrewarmRefusesInsideTheInteractiveHeadroom(): void
    {
        // cap 4, prewarm total ceiling 2 (headroom 2), budget 4: the headroom binds first.
        $ledger = $this->ledger();

        $this->assertIsArray($ledger->acquire(4, true, 2, 4));
        $this->assertIsArray($ledger->acquire(4, true, 2, 4));
        $this->assertNull($ledger->acquire(4, true, 2, 4), 'prewarm yields the headroom to a seek');

        $this->assertIsArray($ledger->acquire(4), 'the reader keeps the headroom');
        $this->assertIsArray($ledger->acquire(4));
        $this->assertNull($ledger->acquire(4), 'the cap of 4 holds for everyone');
    }

    public function testPrewarmAndDemandLinesShareTheGlobalCap(): void
    {
        $ledger = $this->ledger();

        $this->assertIsArray($ledger->acquire(2));
        $this->assertIsArray($ledger->acquire(2));
        $this->assertNull($ledger->acquire(2, true, 2, 2), 'a prewarm launch still counts against the global cap');
    }

    public function testFailsClosedWhenTheLockIsUnavailable(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');
        $ledger = $this->ledger(static fn ($handle): bool => false, $logger);

        $this->assertNull($ledger->acquire(8), 'no lock, no admission: the caller answers 503');
        $this->assertNull($ledger->acquire(8), 'the second refusal is still refused');
        $this->assertFalse($ledger->release('d' . str_repeat('1', 16)), 'release without the lock reports false');
    }

    public function testEveryLedgerReadAndWriteHappensWhileTheLockIsHeld(): void
    {
        // A seeded ledger, so the read path is taken (a missing ledger is never read).
        file_put_contents($this->path, $this->lineFor('d' . str_repeat('6', 16), $this->now));
        /** @var list<array{0: string, 1: bool}> $events Each entry is [operation, lock held at that moment]. */
        $events = [];
        // Asks for the lock from a second handle without waiting. A refusal means the ledger's own handle holds it.
        // It only records; the assertions run after acquire() returns.
        $probe = function (string $operation) use (&$events): void {
            $handle = fopen($this->lockPath(), 'c');
            $held = $handle !== false && @flock($handle, LOCK_EX | LOCK_NB) === false;
            if ($handle !== false) {
                fclose($handle); // closing a probe that won the lock releases it again
            }
            $events[] = [$operation, $held];
        };
        $lock = function ($handle) use (&$events): bool {
            $acquired = EncodeSlotLedger::lockExclusive($handle);
            $events[] = ['lock', $acquired];

            return $acquired;
        };
        $read = function (string $file) use ($probe): string|false {
            $probe('read');

            return file_get_contents($file);
        };
        $write = function (string $file, string $text) use ($probe): int|false {
            $probe('write');

            return file_put_contents($file, $text);
        };
        $ledger = new EncodeSlotLedger($this->path, self::LIFETIME, null, fn (): int => $this->now, $lock, $write, $read);

        $this->assertIsArray($ledger->acquire(4));

        $this->assertSame('lock', $events[0][0], 'the lock is the first thing acquire does');
        $this->assertTrue($events[0][1], 'the lock was taken');
        $reads = array_values(array_filter($events, static fn (array $e): bool => $e[0] === 'read'));
        $writes = array_values(array_filter($events, static fn (array $e): bool => $e[0] === 'write'));
        $this->assertCount(1, $reads, 'the ledger is read once under the lock');
        $this->assertCount(1, $writes, 'the ledger is replaced once under the lock');
        foreach (array_merge($reads, $writes) as [$operation, $held]) {
            // A read or write that ran without the lock would show held === false here, and fail.
            $this->assertTrue($held, 'the ledger ' . $operation . ' ran while the lock was held');
        }
        $this->assertFileExists($this->lockPath(), 'the lock lives in its own file');
    }

    public function testAContendedLockRefusesWithinTheBoundAndAReleaseDefersWithoutDroppingItsToken(): void
    {
        $attempts = 0;
        $lock = function ($handle) use (&$attempts): bool {
            $attempts++;

            return EncodeSlotLedger::lockExclusive($handle);
        };
        $ledger = $this->ledger($lock);
        $admitted = $ledger->acquire(4);
        $this->assertIsArray($admitted);
        $token = $admitted['token'];

        // A separate handle holds the lock file for the whole of the attempts below.
        $holder = fopen($this->lockPath(), 'c');
        $this->assertIsResource($holder);
        $this->assertTrue(flock($holder, LOCK_EX));

        $attempts = 0;
        $start = hrtime(true);
        $refused = $ledger->acquire(4);
        $elapsedMs = (hrtime(true) - $start) / 1_000_000.0;
        $attemptCount = $attempts;

        $this->assertNull($refused, 'a lock held by another handle refuses admission (fail closed)');
        $this->assertTrue($ledger->lastFailed());
        // The budget is 10 ms in 2 ms steps: at most 1 + 5 attempts. The wait is bounded, so it never blocks.
        $this->assertGreaterThanOrEqual(2, $attemptCount, 'the lock is retried before refusing');
        $this->assertLessThanOrEqual(1 + intdiv(EncodeSlotLedger::LOCK_BUDGET_MICROSECONDS, EncodeSlotLedger::LOCK_RETRY_SLEEP_MICROSECONDS), $attemptCount);
        $this->assertLessThan(100.0, $elapsedMs, 'the refusal returns within the bound, not after a blocking wait');

        // A release under contention is deferred: false, reported as a failure, and the token stays in the file.
        $this->assertFalse($ledger->release($token), 'a release under contention is deferred');
        $this->assertTrue($ledger->lastFailed(), 'the deferral is a retryable failure, not a no-op');
        $this->assertStringContainsString($token, $this->fileText(), 'the deferred token is not dropped from the ledger');

        flock($holder, LOCK_UN);
        fclose($holder);
        $this->assertTrue($ledger->release($token), 'the deferred release lands once the lock is free');
        $this->assertStringNotContainsString($token, $this->fileText());
    }

    public function testAStaleTempLeftByACrashIsRemovedOnTheNextRewrite(): void
    {
        $prefix = $this->dir . '/' . EncodeSlotLedger::FILE_NAME . '.';
        $stale = $prefix . str_repeat('a', 16) . '.tmp';   // a worker died between its write and its rename
        $fresh = $prefix . str_repeat('b', 16) . '.tmp';   // a temp younger than the lifetime
        $unrelated = $prefix . 'notes.tmp';                 // does not match the temp pattern
        file_put_contents($stale, 'torn');
        touch($stale, $this->now - self::LIFETIME - 1);
        file_put_contents($fresh, 'young');
        touch($fresh, $this->now - 5);
        file_put_contents($unrelated, 'keep');
        touch($unrelated, $this->now - 100_000);

        $this->assertIsArray($this->ledger()->acquire(4));

        $this->assertFileDoesNotExist($stale, 'a temp older than the lifetime is removed on a locked rewrite');
        $this->assertFileExists($fresh, 'a temp younger than the lifetime is left alone');
        $this->assertFileExists($unrelated, 'a file that does not match the temp pattern is never touched');
    }

    /** True when a second handle cannot take the lock file, that is, the ledger's own handle holds it. */
    private function lockHeldByAnotherHandle(): bool
    {
        $handle = fopen($this->lockPath(), 'c');
        $held = $handle !== false && @flock($handle, LOCK_EX | LOCK_NB) === false;
        if ($handle !== false) {
            fclose($handle); // closing a probe that won the lock releases it again
        }

        return $held;
    }

    public function testTheStaleTempSweepListsTheDirectoryOnlyWithTheLockReleased(): void
    {
        // The rewrite path must not readdir the segment root under the ledger lock. Each listing records
        // whether the lock was held at that moment. The assertions run after acquire() returns.
        /** @var list<bool> $heldAtEachListing */
        $heldAtEachListing = [];
        $listDir = function (string $dir) use (&$heldAtEachListing): array|false {
            $heldAtEachListing[] = $this->lockHeldByAnotherHandle();

            return scandir($dir);
        };
        $ledger = new EncodeSlotLedger($this->path, self::LIFETIME, null, fn (): int => $this->now, null, null, null, $listDir);

        $this->assertIsArray($ledger->acquire(4));

        $this->assertCount(1, $heldAtEachListing, 'the first rewrite lists the directory once');
        $this->assertFalse($heldAtEachListing[0], 'the directory listing ran with the lock released, not under it');
    }

    public function testTheStaleTempSweepRunsOncePerLifetimeNotOnEveryRewrite(): void
    {
        $listings = 0;
        $listDir = function (string $dir) use (&$listings): array|false {
            $listings++;

            return scandir($dir);
        };
        $ledger = new EncodeSlotLedger($this->path, self::LIFETIME, null, fn (): int => $this->now, null, null, null, $listDir);

        $this->assertIsArray($ledger->acquire(4));
        $this->assertIsArray($ledger->acquire(4));
        $this->assertSame(1, $listings, 'two rewrites inside one lifetime list the directory once');

        $this->now += self::LIFETIME;
        $this->assertIsArray($ledger->acquire(4));
        $this->assertSame(2, $listings, 'once a lifetime has passed, the next rewrite sweeps again');
    }

    public function testAcquireRefusesWhileAnotherHandleHoldsTheLockFile(): void
    {
        $holder = fopen($this->lockPath(), 'c');
        $this->assertIsResource($holder);
        $this->assertTrue(flock($holder, LOCK_EX));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');
        // Non-blocking, so the test cannot hang behind the holder: a contended lock is a refusal.
        $nonBlocking = static fn ($handle): bool => @flock($handle, LOCK_EX | LOCK_NB);
        $ledger = $this->ledger($nonBlocking, $logger);

        $this->assertNull($ledger->acquire(4), 'a held lock file refuses admission');
        $this->assertFileDoesNotExist($this->path, 'nothing is written while the admission is refused');

        flock($holder, LOCK_UN);
        fclose($holder);
        $this->assertIsArray($ledger->acquire(4), 'admission resumes once the holder lets go');
    }

    public function testAFailedWriteRefusesAdmissionAndKeepsThePreviousLedger(): void
    {
        $seeded = $this->lineFor('d' . str_repeat('5', 16), $this->now);
        file_put_contents($this->path, $seeded);
        $writer = static fn (string $file, string $text): false => false;
        $ledger = $this->ledger(null, null, null, $writer);

        $this->assertNull($ledger->acquire(8), 'a failed write refuses the admission (fail closed)');
        $this->assertTrue($ledger->lastFailed());
        $this->assertSame($seeded, $this->fileText(), 'the previous ledger is intact');
        $this->assertSame([], glob($this->dir . '/' . EncodeSlotLedger::FILE_NAME . '.*.tmp') ?: [], 'the temp file is removed');
    }

    public function testAShortWriteIsAFailureAndKeepsThePreviousLedger(): void
    {
        $seeded = $this->lineFor('d' . str_repeat('4', 16), $this->now);
        file_put_contents($this->path, $seeded);
        // Writes only half of the text, as a full disk or a crash mid-write would.
        $writer = static fn (string $file, string $text): int|false => file_put_contents($file, substr($text, 0, intdiv(strlen($text), 2)));
        $ledger = $this->ledger(null, null, null, $writer);

        $this->assertNull($ledger->acquire(8), 'a short write refuses the admission');
        $this->assertSame($seeded, $this->fileText(), 'the previous ledger is not replaced by a short file');
        $this->assertSame([], glob($this->dir . '/' . EncodeSlotLedger::FILE_NAME . '.*.tmp') ?: [], 'the short temp file is removed');
    }

    public function testTheLedgerIsReplacedByRenameNotRewrittenInPlace(): void
    {
        $this->ledger()->acquire(8);
        $before = fileinode($this->path);

        $this->ledger()->acquire(8);

        $this->assertNotSame($before, fileinode($this->path), 'the new ledger is a new file renamed over the old one');
    }

    public function testAFailedLedgerOpenFailsClosed(): void
    {
        // A regular file sits where the segment directory should be, so the ledger cannot be created.
        file_put_contents($this->dir . '/blocker', 'x');
        $ledger = $this->ledger(null, null, $this->dir . '/blocker/' . EncodeSlotLedger::FILE_NAME);

        $this->assertNull($ledger->acquire(8));
    }

    public function testAMissingDirectoryAndFileAreCreatedOnFirstUse(): void
    {
        $path = $this->dir . '/nested/deeper/' . EncodeSlotLedger::FILE_NAME;
        $ledger = $this->ledger(null, null, $path);

        $this->assertIsArray($ledger->acquire(2));
        $this->assertFileExists($path);
    }

    public function testTheStampIsTheWallClockSecondOfAdmission(): void
    {
        $this->ledger()->acquire(2);

        $this->assertMatchesRegularExpression(
            '/^[pd][0-9a-f]{16} ' . self::NOW . ' 0$/',
            trim($this->fileText()),
            'the ledger stamp is time() in whole seconds, comparable across processes'
        );
    }

    public function testLifetimeIsTheWallBoundPlusTheMargin(): void
    {
        $this->assertSame(300 + EncodeSlotLedger::LIFETIME_MARGIN_SECONDS, EncodeSlotLedger::lifetimeFor(300));
        $this->assertSame(1 + EncodeSlotLedger::LIFETIME_MARGIN_SECONDS, EncodeSlotLedger::lifetimeFor(0));
    }

    public function testAFloatOrNumericStringWallBoundGivesTheSameIntForEveryCaller(): void
    {
        $this->assertSame(600, EncodeSlotLedger::wallSecondsFrom(600, 300));
        $this->assertSame(600, EncodeSlotLedger::wallSecondsFrom(600.0, 300));
        $this->assertSame(600, EncodeSlotLedger::wallSecondsFrom('600', 300));
        $this->assertSame(600, EncodeSlotLedger::wallSecondsFrom(600.9, 300));
        $this->assertSame(300, EncodeSlotLedger::wallSecondsFrom(0, 300), 'zero falls back to the default');
        $this->assertSame(300, EncodeSlotLedger::wallSecondsFrom(-5, 300), 'a negative value falls back to the default');
        $this->assertSame(300, EncodeSlotLedger::wallSecondsFrom('abc', 300), 'a non-numeric string falls back to the default');
        $this->assertSame(300, EncodeSlotLedger::wallSecondsFrom(null, 300), 'an unset value falls back to the default');
        $this->assertSame(
            600 + EncodeSlotLedger::LIFETIME_MARGIN_SECONDS,
            EncodeSlotLedger::lifetimeFor(EncodeSlotLedger::wallSecondsFrom(600.0, 300)),
            'the ledger lifetime is built from the same normalised int'
        );
    }

    // -------------------------------------------------------------------------
    // TranscodeManager slot release (findings 1, 2, 6). Driven through the manager's private
    // admission, settle and sweep steps, on the real ledger file in $this->dir.
    // -------------------------------------------------------------------------

    private function manager(?SegmentProcessRegistry $registry = null, ?\Closure $lock = null): TranscodeManager
    {
        $m = new TranscodeManager(
            $this->createMock(Connection::class),
            $this->createMock(FfmpegRunner::class),
            $this->dir,
            null,
            6,
            null,
            null,
            null,
            null,
            null,
            null,
            200,
        );
        $m->setEncodeSlotLedger(new EncodeSlotLedger($this->path, self::LIFETIME, null, null, $lock));
        if ($registry !== null) {
            $m->setSegmentProcessRegistry($registry);
        }

        return $m;
    }

    /** @return mixed */
    private function callManager(TranscodeManager $m, string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod($m, $method))->invoke($m, ...$args);
    }

    /** @return array{token: string, inFlight: int} */
    private function admitOne(TranscodeManager $m): array
    {
        $grant = $this->callManager($m, 'admitSegmentLaunch', false);
        $this->assertIsArray($grant);

        return $grant;
    }

    private function ledgerHas(string $token): bool
    {
        return str_contains($this->fileText(), $token . ' ');
    }

    public function testAReapedTokenSurvivesAReleaseForItsKeyWhileItsSettleIsUnconfirmed(): void
    {
        $lockState = new \stdClass();
        $lockState->contended = false;
        $lock = function ($handle) use ($lockState): bool {
            return $lockState->contended ? false : EncodeSlotLedger::lockExclusive($handle);
        };
        $m = $this->manager(null, $lock);
        $grant = $this->admitOne($m);
        $final = $this->dir . '/burst/seg-00001.ts';
        mkdir(dirname($final), 0755, true);
        $this->callManager($m, 'settleLaunch', $final, $grant['token'], 4242);

        // The launch finished, but its release is deferred by the contended lock: the token now sits in releasePending.
        $lockState->contended = true;
        $this->callManager($m, 'releaseSlot', $grant['token']);
        $this->assertTrue($this->ledgerHas($grant['token']), 'a deferred release keeps the line');

        $m->invalidateReservation($final); // reaped: the TERM is sent, the process may still run, the deferred token is reaped too

        // A launch finished, or a publish of the new encode: the release for the reaped token is refused.
        $lockState->contended = false;
        $this->callManager($m, 'releaseSlot', $grant['token']);
        $this->assertTrue($this->ledgerHas($grant['token']), 'a release call cannot free a reaped token');

        // The same holds through the retry pass: the lock is free now, so only the unsettled-skip stops the deferred
        // release from being retried into a free. Without that skip this sweep releases the token and the line goes.
        file_put_contents($final, 'segment');
        $this->callManager($m, 'sweepHeldSlots');
        $this->assertTrue($this->ledgerHas($grant['token']), 'a publish cannot free a reaped token while its settle is unconfirmed');

        $m->onSegmentKeySettled($final, true);
        $this->assertFalse($this->ledgerHas($grant['token']), 'the confirmed settle is the gate that frees it');
    }

    public function testAConfirmedSettleOfASecondReapFreesOnlyItsOwnTokens(): void
    {
        $m = $this->manager();
        $final = $this->dir . '/burst/seg-00002.ts';
        mkdir(dirname($final), 0755, true);

        $first = $this->admitOne($m);
        $this->callManager($m, 'settleLaunch', $final, $first['token'], 4242);
        $m->invalidateReservation($final);      // reap 1
        $m->onSegmentKeySettled($final, false); // reap 1 settles unconfirmed: its launch may still run

        $second = $this->admitOne($m);
        $this->callManager($m, 'settleLaunch', $final, $second['token'], 4343);
        $m->invalidateReservation($final);      // reap 2 of the same key
        $this->assertTrue($this->ledgerHas($first['token']));
        $this->assertTrue($this->ledgerHas($second['token']));

        $m->onSegmentKeySettled($final, true);  // the registry confirms reap 2 only

        $this->assertFalse($this->ledgerHas($second['token']), 'the confirmed reap frees its own token');
        $this->assertTrue($this->ledgerHas($first['token']), 'the earlier unconfirmed reap keeps its token');
    }

    public function testASiblingPublishOrDeathDoesNotReleaseALiveLaunchsToken(): void
    {
        // Two launches of one key, both tracked by this worker's registry. The sibling (pid 4242) dies, and its
        // publish makes the final exist. Our launch (pid 4343) is still running. Its token must stay held.
        $alive = [4242 => true, 4343 => true];
        $registry = new SegmentProcessRegistry(
            null,
            null,
            static function (int $pid) use (&$alive): bool {
                return $alive[$pid] ?? false;
            },
            0.05,
        );
        // Fake pids: no /proc view, so the liveness probe alone decides, and no real process is read.
        $registry->setProcStatReader(static fn (int $pid): ?string => null);
        $m = $this->manager($registry);
        $final = $this->dir . '/burst/seg-00003.ts';
        mkdir(dirname($final), 0755, true);
        $registry->register($final, 4242);
        $registry->register($final, 4343);
        $sibling = $this->admitOne($m);
        $ours = $this->admitOne($m);
        $this->callManager($m, 'settleLaunch', $final, $sibling['token'], 4242);
        $this->callManager($m, 'settleLaunch', $final, $ours['token'], 4343);

        $alive[4242] = false;
        file_put_contents($final, 'sibling segment');
        $this->callManager($m, 'sweepHeldSlots');

        $this->assertFalse($this->ledgerHas($sibling['token']), 'the dead sibling launch is finished: released');
        $this->assertTrue($this->ledgerHas($ours['token']), 'a sibling publish or death does not free a live launch');
    }

    public function testADeferredReleaseIsNotRetriedIntoAFreeOfATokenReapedSinceItFailed(): void
    {
        // A launch is finished (its segment is published), but its release is deferred by a contended lock. The
        // key is then reaped before the retry runs. The retry must not free the reaped token: only its settle may.
        $lockState = new \stdClass();
        $lockState->contended = false;
        $lock = function ($handle) use ($lockState): bool {
            return $lockState->contended ? false : EncodeSlotLedger::lockExclusive($handle);
        };
        $m = $this->manager(null, $lock);
        $final = $this->dir . '/burst/seg-00004.ts';
        mkdir(dirname($final), 0755, true);
        $grant = $this->admitOne($m);
        $this->callManager($m, 'settleLaunch', $final, $grant['token'], 4242);

        $lockState->contended = true;
        file_put_contents($final, 'published');   // the launch is finished: the release is attempted, and deferred
        $this->callManager($m, 'sweepHeldSlots');
        $this->assertTrue($this->ledgerHas($grant['token']), 'a release deferred by the contended lock keeps the line');

        $m->invalidateReservation($final);        // the key is reaped while the deferred release is still pending

        $lockState->contended = false;
        $this->callManager($m, 'sweepHeldSlots'); // the retry runs, and it must skip the reaped token
        $this->assertTrue($this->ledgerHas($grant['token']), 'the retry does not free a reaped token before its settle');

        $m->onSegmentKeySettled($final, true);
        $this->assertFalse($this->ledgerHas($grant['token']), 'the confirmed settle frees it');
    }

    public function testAPidZeroTokenIsNotFinishedWhileItsKeyIsTracked(): void
    {
        // The key is tracked by this worker (a live launch with pid 4242). A token that never recorded its own pid
        // (or whose entry has moved to a reap) is not known to be finished, so launchFinished() must not release it.
        $registry = new SegmentProcessRegistry(null, null, static fn (int $pid): bool => true, 0.05);
        $registry->setProcStatReader(static fn (int $pid): ?string => null);
        $m = $this->manager($registry);
        $final = $this->dir . '/burst/seg-00005.ts';
        mkdir(dirname($final), 0755, true);
        $registry->register($final, 4242);
        $grant = $this->admitOne($m);

        $this->assertFalse(
            $this->callManager($m, 'launchFinished', $final, $grant['token']),
            'a token with no pid is not finished while its key is tracked'
        );
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir . '/' . $entry;
            if (is_dir($full) && !is_link($full)) {
                $this->rrmdir($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($dir);
    }
}
