<?php

/**
 * Phlix media server test: Media\Music.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Music;

use Phlix\Media\Music\MusicScanPrefetcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * S122(b) — the read-ahead pool: the CAP, the measurement behind it, and the
 * never-block / never-influence-the-index guarantees.
 *
 * The cap tests are the point of this file. The whole justification for the pool is a
 * measurement (1.73x at 4 readers, **0.59x at 8** — worse than serial), and a
 * measurement that lives only in a comment gets "optimised" away by the next person who
 * assumes more parallelism is better. So the number is asserted, and so is the presence
 * of its citation in the source.
 *
 * @internal
 */
#[CoversClass(MusicScanPrefetcher::class)]
final class MusicScanPrefetcherTest extends TestCase
{
    /** @var list<string> Paths to clean up. */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path) || file_exists($path)) {
                @unlink($path);
            }
        }
        $this->paths = [];

        parent::tearDown();
    }

    /**
     * AC 3 — concurrency is bounded at 4, and the bound is not a guess.
     *
     * Every value at or above the cap clamps DOWN to 4 and every value at or below 1
     * clamps UP to 1 (1 = the pre-S122 scanner, which is always one reader).
     */
    public function testTheConcurrencyCapIsFourAndEverythingAboveItClampsDown(): void
    {
        self::assertSame(4, MusicScanPrefetcher::MAX_READERS, 'the cap is 4 — measured, see below');

        self::assertSame(1, MusicScanPrefetcher::clampReaders(-99));
        self::assertSame(1, MusicScanPrefetcher::clampReaders(0));
        self::assertSame(1, MusicScanPrefetcher::clampReaders(1));
        self::assertSame(2, MusicScanPrefetcher::clampReaders(2));
        self::assertSame(3, MusicScanPrefetcher::clampReaders(3));
        self::assertSame(4, MusicScanPrefetcher::clampReaders(4));
        self::assertSame(4, MusicScanPrefetcher::clampReaders(5), '5 must clamp to 4');
        self::assertSame(4, MusicScanPrefetcher::clampReaders(8), '8 measured 0.59x — WORSE than serial');
        self::assertSame(4, MusicScanPrefetcher::clampReaders(16));
        self::assertSame(4, MusicScanPrefetcher::clampReaders(PHP_INT_MAX));
    }

    /**
     * AC 3 — the measurement is CITED IN THE CODE, not just honoured by it.
     *
     * A bare `min(4, …)` is indistinguishable from a magic number, and a magic number is
     * what gets raised. This asserts that the class carries the figures that justify the
     * cap — the 1.73x, the 0.59x collapse at 8, and the reason (a single latency-bound
     * spindle) — so that raising `MAX_READERS` requires deleting an explicit,
     * falsifiable claim rather than editing a lone integer.
     */
    public function testTheCapCitesTheMeasurementThatJustifiesIt(): void
    {
        $source = file_get_contents((new \ReflectionClass(MusicScanPrefetcher::class))->getFileName() ?: '');
        self::assertIsString($source);

        foreach (['1.73x', '0.59x', '117.0', '67.7', '197.8'] as $figure) {
            self::assertStringContainsString(
                $figure,
                $source,
                'MusicScanPrefetcher must cite the measured figure ' . $figure . '. If the cap is '
                . 'ever changed, replace these numbers with the NEW measurement — do not delete them.'
            );
        }

        self::assertStringContainsString(
            'rotational',
            $source,
            'the WHY (a single rotational spindle, latency-bound at queue depth 0.14) has to '
            . 'travel with the numbers, or a future reader will assume the cap was arbitrary'
        );
    }

    /**
     * `config/scanner.php` can LOWER the concurrency but never raise it above the cap.
     */
    public function testConfigCanLowerTheConcurrencyButNeverRaiseItPastTheCap(): void
    {
        self::assertSame(4, MusicScanPrefetcher::configuredReaders([]), 'absent key means the default');
        self::assertSame(1, MusicScanPrefetcher::configuredReaders(['music_read_concurrency' => 1]));
        self::assertSame(3, MusicScanPrefetcher::configuredReaders(['music_read_concurrency' => 3]));
        self::assertSame(
            4,
            MusicScanPrefetcher::configuredReaders(['music_read_concurrency' => 32]),
            'a config file must not be able to raise the cap'
        );
        self::assertSame(
            4,
            MusicScanPrefetcher::configuredReaders(['music_read_concurrency' => 'nonsense']),
            'a non-numeric value degrades to the default, never to 0 or to an unbounded value'
        );
        self::assertSame(2, MusicScanPrefetcher::configuredReaders(['music_read_concurrency' => '2']));
    }

    /**
     * The shipped `config/scanner.php` default is the measured optimum, and the file
     * carries the measurement too — the config file is where an operator looks first.
     */
    public function testTheShippedConfigDefaultIsTheMeasuredOptimum(): void
    {
        $config = require dirname(__DIR__, 4) . '/config/scanner.php';
        self::assertIsArray($config);
        self::assertSame(4, $config['music_read_concurrency'] ?? null);

        $source = file_get_contents(dirname(__DIR__, 4) . '/config/scanner.php');
        self::assertIsString($source);
        self::assertStringContainsString('1.73x', $source);
        self::assertStringContainsString('0.59x', $source);
    }

    /**
     * A concurrency of 1 means NO POOL AT ALL — byte-for-byte the pre-S122 scanner, and
     * the kill switch for a mount where the page cache is defeated (`direct_io`).
     */
    public function testAConcurrencyOfOneSpawnsNothingAndSubmitIsInert(): void
    {
        $prefetcher = new MusicScanPrefetcher(new NullLogger(), 1);
        $prefetcher->open();

        self::assertSame(0, $prefetcher->poolSize(), 'no children');
        self::assertSame(1, $prefetcher->readersInFlight(), 'the scanner itself is the one reader');

        $prefetcher->submit(__FILE__);
        $prefetcher->drain();

        self::assertSame(
            ['submitted' => 0, 'dropped' => 0, 'readers_in_flight' => 1],
            $prefetcher->stats(),
            'with no pool there is nothing to submit to and nothing to drop'
        );

        $prefetcher->close();
    }

    /**
     * The pool is `readers - 1` children, because the scanner is the other reader.
     */
    public function testThePoolIsOneSmallerThanTheReadersInFlightTarget(): void
    {
        foreach ([2 => 1, 3 => 2, 4 => 3] as $readers => $expectedPool) {
            $prefetcher = new MusicScanPrefetcher(new NullLogger(), $readers);
            $prefetcher->open();

            self::assertSame($expectedPool, $prefetcher->poolSize(), 'readers=' . $readers);
            self::assertSame($readers, $prefetcher->readersInFlight(), 'readers=' . $readers);

            $prefetcher->close();
            self::assertSame(0, $prefetcher->poolSize(), 'close() must reap every child');
        }
    }

    /**
     * A path is handed to a child and counted; a path that cannot be a filesystem path
     * is refused without reaching one.
     *
     * The NUL check matters because the wire protocol IS NUL-delimited: a path
     * containing NUL would desynchronise the child's stream and make it warm garbage.
     */
    public function testSubmitCountsRealPathsAndRefusesImpossibleOnes(): void
    {
        $prefetcher = new MusicScanPrefetcher(new NullLogger(), 2);
        $prefetcher->open();
        self::assertSame(1, $prefetcher->poolSize());

        $prefetcher->submit(__FILE__);
        $prefetcher->submit('');
        $prefetcher->submit("/tmp/has\0nul.mp3");
        $prefetcher->drain();

        $stats = $prefetcher->stats();
        self::assertSame(1, $stats['submitted'], 'only the real path is submitted');
        self::assertSame(0, $stats['dropped']);

        $prefetcher->close();
    }

    /**
     * ⚠ THE SAFETY PROPERTY: `submit()` NEVER BLOCKS, even when a child is wedged on a
     * read that will never return.
     *
     * A reader stuck on a FIFO with no writer stops draining its stdin; the pipe fills at
     * the kernel buffer size; every further submit is then DROPPED rather than blocking
     * the scanner. A dropped prefetch is invisible — the scanner reads that file itself —
     * whereas a blocked submit would freeze the whole scan behind a warmer that has no
     * business being on the critical path.
     */
    public function testSubmitDropsWorkRatherThanBlockingWhenAReaderIsWedged(): void
    {
        if (!function_exists('posix_mkfifo')) {
            self::markTestSkipped('posix_mkfifo() is required to wedge a reader deterministically');
        }

        $fifo = sys_get_temp_dir() . '/phlix_s122_fifo_' . bin2hex(random_bytes(6));
        self::assertTrue(posix_mkfifo($fifo, 0o600), 'the fixture needs a FIFO');
        $this->paths[] = $fifo;

        $prefetcher = new MusicScanPrefetcher(new NullLogger(), 2);
        $prefetcher->open();
        self::assertSame(1, $prefetcher->poolSize());

        // The child blocks inside fopen() on the FIFO and stops reading its stdin.
        $prefetcher->submit($fifo);

        // Now overrun the pipe. Each path is ~100 bytes and a pipe buffer is 64 KiB, so
        // well under 2,000 submits must start dropping. If submit() blocked, this test
        // would hang instead of failing — which is itself the signal.
        $filler = sys_get_temp_dir() . '/phlix_s122_' . str_repeat('x', 80) . '.mp3';
        $start = hrtime(true);
        for ($i = 0; $i < 2000; $i++) {
            $prefetcher->submit($filler);
        }
        $elapsedMs = (hrtime(true) - $start) / 1_000_000.0;

        $stats = $prefetcher->stats();
        self::assertGreaterThan(0, $stats['dropped'], 'a wedged reader must cause DROPS, not blocking');
        self::assertLessThan(
            5_000.0,
            $elapsedMs,
            '2,000 submits against a wedged reader must still return promptly (measured well under 1 s)'
        );

        $prefetcher->close();
    }

    /**
     * The reader program really does read the file it is given — verified from the
     * kernel's own counters (`/proc/<pid>/io` `rchar`), not from the program's word.
     *
     * This is the one assertion that proves the pool does anything at all. It exercises
     * `scripts/prefetch-audio-headers.php` end to end over its real NUL-delimited
     * protocol.
     */
    public function testTheReaderProgramActuallyReadsTheFileItIsGiven(): void
    {
        $script = MusicScanPrefetcher::readerScript();
        self::assertFileExists($script, 'the reader program must ship with the pool');

        $fixture = sys_get_temp_dir() . '/phlix_s122_read_' . bin2hex(random_bytes(6)) . '.mp3';
        // An ID3v2.3 header declaring a 200 KB tag, then 200 KB of body: enough that a
        // read is unmistakable in the counters.
        $declared = 200 * 1024;
        $header = 'ID3' . "\x03\x00" . "\x00"
            . chr(($declared >> 21) & 0x7F) . chr(($declared >> 14) & 0x7F)
            . chr(($declared >> 7) & 0x7F) . chr($declared & 0x7F);
        file_put_contents($fixture, $header . str_repeat("\x00", $declared + 4096));
        $this->paths[] = $fixture;

        $pipes = [];
        $proc = proc_open(
            [PHP_BINARY, $script],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        self::assertIsResource($proc);

        $status = proc_get_status($proc);
        $pid = is_array($status) ? (int) ($status['pid'] ?? 0) : 0;
        self::assertGreaterThan(0, $pid);

        $ioFile = '/proc/' . $pid . '/io';
        if (!is_readable($ioFile)) {
            fclose($pipes[0]);
            proc_terminate($proc);
            proc_close($proc);
            self::markTestSkipped('/proc/<pid>/io is unavailable, so the read cannot be measured');
        }

        $before = $this->rchar($ioFile);
        fwrite($pipes[0], $fixture . "\0");
        fflush($pipes[0]);

        $after = $before;
        $deadline = hrtime(true) + 10_000_000_000;
        while (hrtime(true) < $deadline) {
            $after = $this->rchar($ioFile);
            if ($after - $before >= $declared) {
                break;
            }
            usleep(5000);
        }

        fclose($pipes[0]);
        proc_terminate($proc);
        proc_close($proc);

        self::assertGreaterThanOrEqual(
            $declared,
            $after - $before,
            'the reader must actually read the declared ID3v2 tag region — it decodes the length '
            . 'from the file header rather than guessing, so a fixed-size read would fail here'
        );
    }

    /**
     * A missing reader program disables the pool instead of failing the scan.
     */
    public function testAMissingReaderProgramDisablesThePoolWithoutThrowing(): void
    {
        $prefetcher = new MusicScanPrefetcher(
            new NullLogger(),
            4,
            '/nonexistent/phlix/prefetch-audio-headers.php'
        );

        $prefetcher->open();

        self::assertSame(0, $prefetcher->poolSize());
        self::assertSame(1, $prefetcher->readersInFlight());

        $prefetcher->submit(__FILE__);
        self::assertSame(0, $prefetcher->stats()['submitted']);

        $prefetcher->close();
    }

    /**
     * The reader program is where the pool says it is.
     */
    public function testTheReaderScriptPathResolvesInsideTheRepository(): void
    {
        self::assertSame(
            dirname(__DIR__, 4) . '/scripts/prefetch-audio-headers.php',
            MusicScanPrefetcher::readerScript()
        );
    }

    /**
     * Reads `rchar` (bytes this process obtained from read-like syscalls) from
     * `/proc/<pid>/io`.
     *
     * @param string $ioFile Path to the proc file.
     * @return int
     */
    private function rchar(string $ioFile): int
    {
        $raw = @file_get_contents($ioFile);
        if (!is_string($raw) || preg_match('/^rchar:\s+(\d+)/m', $raw, $m) !== 1) {
            return 0;
        }

        return (int) $m[1];
    }
}
