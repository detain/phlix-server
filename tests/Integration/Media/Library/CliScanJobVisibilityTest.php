<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media\Library;

use Phlix\Common\Database\ConnectionPool;
use Phlix\Console\Commands\LibraryScanCommand;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Library\ScanJobRepository;
use Phlix\Media\Library\ScanResult;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Throwable;
use Workerman\MySQL\Connection;

/**
 * S150 — real-MySQL proof that a CLI-initiated scan is visible in the admin
 * Libraries page, and that it can never strand a permanently-`running` row.
 *
 * ## Why real MySQL, and why a real killed process
 *
 * The unit tests in `tests/Unit/Console/Commands/LibraryScanCommandTest` pin the
 * command's contract against a MOCKED {@see ScanJobRepository}. A mock cannot prove
 * the two things that actually broke on production:
 *
 *  1. **The row lands.** `library_scan_jobs.type` and `.status` are ENUM columns
 *     (migrations 001 / 084). A mock accepts any string; MySQL in strict mode rejects
 *     a wrong one with error 1265, and a job row that fails to INSERT is exactly the
 *     "invisible scan" S150 exists to fix.
 *  2. **A killed run lands as `failed`.** No in-process double can be killed. The
 *     signal case is therefore exercised by spawning a REAL `php` subprocess, letting
 *     it open a real `running` row, sending it a real `SIGTERM`, and then reading the
 *     row back from the database.
 *
 * The live defect this guards: during the S145 healing rescan a CLI scan had been
 * running ~45 minutes and demonstrably repairing rows, while the admin page showed a
 * red `failed` badge from a job that had ended hours earlier
 * (`error = 'Interrupted by server restart'`). A stale failure reads as "the last
 * thing that happened, broke" — strictly worse than no badge at all.
 *
 * With no reachable MySQL the test self-skips.
 *
 * @covers \Phlix\Console\Commands\LibraryScanCommand
 * @covers \Phlix\Media\Library\ScanJobRepository
 */
final class CliScanJobVisibilityTest extends TestCase
{
    use RequiresRealDatabase;

    private ?Connection $db = null;

    private string $libraryId = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->requireRealDatabase('skipping the S150 CLI scan-job test. Runs in CI / docker-compose.');

        $this->libraryId = $this->uuid();
        $this->db()->query(
            'INSERT INTO libraries (id, name, type, paths) VALUES (?, ?, ?, ?)',
            [$this->libraryId, 'S150 CLI Visibility', 'music', json_encode(['/tmp/phlix-s150'])],
        );
    }

    protected function tearDown(): void
    {
        if ($this->db !== null && $this->libraryId !== '') {
            $this->db->query('DELETE FROM library_scan_jobs WHERE library_id = ?', [$this->libraryId]);
            $this->db->query('DELETE FROM libraries WHERE id = ?', [$this->libraryId]);
        }
        parent::tearDown();
    }

    /**
     * The whole point: before the scan finishes there is a `running` row of the right
     * type for this library — that is what the admin page polls — and by the end it is
     * `completed` with counters matching the printed summary.
     */
    public function testACliRescanIsRunningInTheJobTableWhileItRunsAndCompletedAfterwards(): void
    {
        /** @var array<string, mixed>|null $midScanRow */
        $midScanRow = null;

        $result = new ScanResult();
        $result->scanned = 30;
        $result->added = 12;
        $result->removed = 2;

        $manager = $this->createMock(LibraryManager::class);
        $manager->method('rescanLibrary')->willReturnCallback(
            function (string $lib, array $paths = [], ?callable $onProgress = null) use (&$midScanRow, $result) {
                // Drive the sink far enough to cross the throttle, then read the row
                // back through a SEPARATE query — i.e. observe exactly what the admin
                // page's poll would observe, mid-scan.
                if ($onProgress !== null) {
                    for ($i = 1; $i <= 26; $i++) {
                        $onProgress($i, 30, "/tmp/phlix-s150/file-$i.flac", ['added' => 12, 'failed' => 0]);
                    }
                }
                $midScanRow = $this->latestJobRow();

                return $result;
            },
        );

        $tester = $this->tester($manager);
        $this->assertSame(Command::SUCCESS, $tester->execute([
            'libraryId' => $this->libraryId,
            '--rescan' => true,
        ]));

        $this->assertIsArray($midScanRow, 'a CLI rescan must create a library_scan_jobs row BEFORE it starts');
        $this->assertSame('running', $midScanRow['status'], 'the row must read `running` while the scan runs');
        $this->assertSame(
            'rescan',
            $midScanRow['type'],
            '`--rescan` must be recorded as type `rescan`; the badge renders the type, and mislabelling a '
            . 'multi-hour healing run as an ordinary `scan` is how an operator concludes it is stuck',
        );
        $this->assertSame(30, (int) $midScanRow['items_found'], 'progress denominator must stream mid-scan');
        $this->assertSame(25, (int) $midScanRow['items_updated'], 'processed-file numerator, throttled at 25');
        $this->assertSame('/tmp/phlix-s150/file-25.flac', $midScanRow['current_path']);

        $final = $this->latestJobRow();
        $this->assertIsArray($final);
        $this->assertSame('completed', $final['status']);
        $this->assertSame(12, (int) $final['items_added'], 'final counters must match the printed summary');
        $this->assertSame(2, (int) $final['items_removed']);
        $this->assertSame(0, (int) $final['items_failed']);
        $this->assertNotNull($final['completed_at']);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('added: 12', $display);
        $this->assertStringContainsString('removed: 2', $display);
    }

    /** A throwing scan must leave the row `failed` with the real reason, never `running`. */
    public function testAThrowingScanLeavesTheRowFailedWithTheReason(): void
    {
        $manager = $this->createMock(LibraryManager::class);
        $manager->method('scanLibrary')->willThrowException(new RuntimeException('disk went away'));

        $this->assertSame(Command::FAILURE, $this->tester($manager)->execute([
            'libraryId' => $this->libraryId,
        ]));

        $row = $this->latestJobRow();
        $this->assertIsArray($row);
        $this->assertSame('failed', $row['status']);
        $this->assertSame('disk went away', $row['error']);
        $this->assertNotNull($row['completed_at']);
    }

    /**
     * ⚠ **THE ACCEPTANCE CRITERION THAT NEEDS A REAL PROCESS.** A CLI run killed
     * mid-scan (a deploy's SIGTERM, an operator's Ctrl-C) must land `failed` with a
     * truthful reason — never linger `running` forever, which is what would keep a
     * spinner alive and block the next scan's refusal check.
     *
     * A mock cannot be killed, so this spawns a real `php` subprocess running the real
     * command against the real database, waits until it has written its `running` row,
     * then sends a real SIGTERM.
     *
     * ## ⚠ THIS TEST WAS FLAKY, AND THE FLAKE WAS THE PRODUCT, NOT THE TEST
     *
     * It failed 3 runs in 10 (and a standalone harness stranded 12 of 15 rows with the
     * MySQL general log on) because `LibraryScanCommand` used to INSERT the `running`
     * row BEFORE installing its signal handlers. Using the row's appearance as the
     * "child is killable" probe is only sound if a visible row implies a handler
     * exists — which it did not. The command now mints the job id, installs the
     * handlers, and only then inserts, so the row's appearance IS a valid readiness
     * probe and this test's original shape became correct rather than lucky. Do not
     * "fix" a recurrence by adding a sleep before the kill; that hides the defect the
     * test exists to catch.
     */
    public function testAKilledCliScanLandsAsFailedAndNeverStaysRunning(): void
    {
        foreach (['proc_open', 'posix_kill'] as $needed) {
            if (!function_exists($needed)) {
                $this->markTestSkipped($needed . '() unavailable — cannot kill a real process here.');
            }
        }

        $script = $this->writeRunnerScript();
        $pidFile = sys_get_temp_dir() . '/phlix-s150-pid-' . bin2hex(random_bytes(6));
        $childPid = 0;

        try {
            $pipes = [];
            $proc = proc_open(
                [PHP_BINARY, $script, $this->libraryId, $pidFile],
                [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes,
                dirname(__DIR__, 4),
                array_merge($_ENV, [
                    'DB_HOST' => getenv('DB_HOST') ?: '127.0.0.1',
                    'DB_PORT' => (string) (getenv('DB_PORT') ?: 3306),
                    'DB_DATABASE' => getenv('DB_DATABASE') ?: 'phlix_test',
                    'DB_USER' => getenv('DB_USER') ?: 'root',
                    'DB_PASSWORD' => getenv('DB_PASSWORD') ?: 'root',
                ]),
            );
            $this->assertIsResource($proc, 'could not spawn the CLI scan subprocess');

            // ⚠ The child's OWN pid, read from a file it writes, NOT
            // `proc_get_status()['pid']`. Under PHPUnit the latter was measured to
            // report a stale, already-reaped pid (`cached: true, running: false,
            // exitcode: 0`) while the child was demonstrably alive for another 60 s —
            // so `proc_terminate()` short-circuited and signalled nothing, and the
            // test passed a SIGTERM that was never sent. A test that cannot deliver
            // its own stimulus is a guaranteed false green.
            $running = $this->waitFor(function () use ($pidFile, &$childPid): ?array {
                if ($childPid === 0 && is_file($pidFile)) {
                    $childPid = (int) trim((string) @file_get_contents($pidFile));
                }
                $row = $this->latestJobRow();

                return $childPid > 0 && is_array($row) && $row['status'] === 'running' ? $row : null;
            });

            $this->assertIsArray(
                $running,
                'the subprocess must have written its pid file AND a `running` row within the timeout',
            );
            $this->assertGreaterThan(0, $childPid);
            $this->assertTrue(posix_kill($childPid, SIGTERM), 'SIGTERM must reach the live child');

            $final = $this->waitFor(function (): ?array {
                $row = $this->latestJobRow();

                return is_array($row) && $row['status'] !== 'running' ? $row : null;
            });

            $this->assertIsArray(
                $final,
                'a SIGTERMed CLI scan must NOT leave the row `running` forever — that is what keeps a UI '
                . 'spinner alive and blocks the next scan',
            );
            $this->assertSame('failed', $final['status']);
            $this->assertIsString($final['error']);
            $this->assertStringContainsString(
                'signal',
                (string) $final['error'],
                'the recorded reason must say what actually happened, not a generic failure',
            );
            $this->assertNotNull($final['completed_at']);
        } finally {
            if ($childPid > 0) {
                // Belt and braces: never leave a 60-second sleeper behind.
                @posix_kill($childPid, SIGKILL);
            }
            if (isset($proc) && is_resource($proc)) {
                // Reap explicitly. Left to the resource destructor this costs ~50 s,
                // because PHP waits on the stale pid it cached at spawn time.
                @proc_close($proc);
            }
            @unlink($script);
            @unlink($pidFile);
        }
    }

    /**
     * Poll `$probe` until it returns non-null, or the timeout expires.
     *
     * @param callable(): (array<string, mixed>|null) $probe
     * @return array<string, mixed>|null
     */
    private function waitFor(callable $probe, float $timeoutSeconds = 15.0): ?array
    {
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            $value = $probe();
            if ($value !== null) {
                return $value;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        return null;
    }

    /**
     * S151 review finding 7 — the SIGTERM handler writes to the database, and the
     * original proof delivered its signal at a QUIESCENT point (the child was inside
     * `sleep(60)` with no query in flight). In a real `library:scan` the scanner issues
     * a query per file, so the arrival point that matters is MID-QUERY.
     *
     * This child therefore spends its whole life inside back-to-back `SELECT SLEEP(2)`
     * statements on the SAME connection the repository writes through, and is signalled
     * while one is in flight. If re-entering that connection from the handler could
     * desync the client, `markFailed()` would throw, `failJob()`'s `catch (Throwable)`
     * would swallow it, and the row would stay `running` — a green here is the whole
     * guarantee.
     *
     * ⚠ Note what this also demonstrates: the stamp is not instantaneous. PHP dispatches
     * an async signal handler at an opcode boundary, so a signal arriving 100 ms into a
     * 2-second query is acted on ~1.9 s later, when the query returns. That is why the
     * child's queries are 2 s and not 60 s.
     */
    public function testASigtermDeliveredMidQueryStillLandsTheRowFailed(): void
    {
        foreach (['proc_open', 'posix_kill'] as $needed) {
            if (!function_exists($needed)) {
                $this->markTestSkipped($needed . '() unavailable — cannot kill a real process here.');
            }
        }

        $script = $this->writeSlowQueryRunnerScript();
        $suffix = bin2hex(random_bytes(6));
        $pidFile = sys_get_temp_dir() . '/phlix-s151-pid-' . $suffix;
        $busyFile = sys_get_temp_dir() . '/phlix-s151-busy-' . $suffix;
        $childPid = 0;

        try {
            $pipes = [];
            $proc = proc_open(
                [PHP_BINARY, $script, $this->libraryId, $pidFile, $busyFile],
                [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes,
                dirname(__DIR__, 4),
                array_merge($_ENV, [
                    'DB_HOST' => getenv('DB_HOST') ?: '127.0.0.1',
                    'DB_PORT' => (string) (getenv('DB_PORT') ?: 3306),
                    'DB_DATABASE' => getenv('DB_DATABASE') ?: 'phlix_test',
                    'DB_USER' => getenv('DB_USER') ?: 'root',
                    'DB_PASSWORD' => getenv('DB_PASSWORD') ?: 'root',
                ]),
            );
            $this->assertIsResource($proc, 'could not spawn the CLI scan subprocess');

            // Gate on the child's OWN "I am inside a query now" marker, not on the row.
            $ready = $this->waitFor(function () use ($pidFile, $busyFile, &$childPid): ?array {
                if ($childPid === 0 && is_file($pidFile)) {
                    $childPid = (int) trim((string) @file_get_contents($pidFile));
                }
                $row = $this->latestJobRow();

                return $childPid > 0 && is_file($busyFile) && is_array($row) && $row['status'] === 'running'
                    ? $row
                    : null;
            });

            $this->assertIsArray($ready, 'the subprocess must reach the mid-query state within the timeout');
            $this->assertTrue(posix_kill($childPid, SIGTERM), 'SIGTERM must reach the live child');

            $final = $this->waitFor(function (): ?array {
                $row = $this->latestJobRow();

                return is_array($row) && $row['status'] !== 'running' ? $row : null;
            });

            $this->assertIsArray(
                $final,
                'a SIGTERM delivered while a query was in flight must still land the row terminal — if the '
                . 'handler could corrupt the shared connection, markFailed() would throw and the row would '
                . 'stay `running`',
            );
            $this->assertSame('failed', $final['status']);
            $this->assertStringContainsString('signal', (string) $final['error']);
        } finally {
            if ($childPid > 0) {
                @posix_kill($childPid, SIGKILL);
            }
            if (isset($proc) && is_resource($proc)) {
                @proc_close($proc);
            }
            @unlink($script);
            @unlink($pidFile);
            @unlink($busyFile);
        }
    }

    /**
     * S151 review finding 5 — the refusal must survive an interleaving in which BOTH
     * callers read "no active job" before either inserts.
     *
     * `hasActiveJobForLibrary()` + `startRunning()` is a check-then-act pair with
     * nothing between them, so the guarantee has to live in the INSERT. This drives
     * exactly that order against real MySQL: read idle twice, then insert twice.
     */
    public function testTheGuardedInsertRefusesTheSecondStarterEvenWhenBothSawAnIdleLibrary(): void
    {
        $jobs = new ScanJobRepository($this->db());

        $this->assertFalse($jobs->hasActiveJobForLibrary($this->libraryId), 'fixture must start idle');
        $this->assertFalse($jobs->hasActiveJobForLibrary($this->libraryId), 'and the competitor reads idle too');

        $winner = $jobs->startRunningIfIdle($this->libraryId, 'scan');
        $loser = $jobs->startRunningIfIdle($this->libraryId, 'rescan');

        $this->assertIsString($winner, 'the first starter must get a row');
        $this->assertNull(
            $loser,
            'the second must be refused BY THE INSERT — its pre-check already said idle, so nothing else can '
            . 'stop it starting a concurrent scan over the same library',
        );

        $rows = $this->db()->query(
            'SELECT id FROM library_scan_jobs WHERE library_id = ?',
            [$this->libraryId],
        );
        $this->assertIsArray($rows);
        $this->assertCount(1, $rows, 'exactly one row may exist — the loser must not have written anything');

        // ⚠ Regression guard for the `'0'`-is-falsy landmine: Connection::query()
        // returns lastInsertId() ('0' for this UUID-keyed table) on a successful
        // INSERT and null on one that wrote nothing. A `if (!$result)` test would read
        // the WINNER as a refusal too, and no scan would ever start.
        $this->assertNotSame('', (string) $winner, 'a successful guarded insert must return the job id');
    }

    /**
     * S151 review finding 4 — "is the library's NEWEST job active?" is not the same
     * question as "does the library have an active job", and only the second is safe to
     * gate a write on.
     *
     * `queued_at` is a TIMESTAMP with 1-second granularity, and a terminal job can
     * legitimately be newer than a live one (a `metadata` job that completed while a
     * long CLI `rescan` is still running). Both shapes are staged here; a
     * newest-row-only implementation reports `false` for both and lets a second scanner
     * loose on a library that is actively being scanned.
     */
    public function testAnActiveJobIsSeenEvenWhenANewerTerminalJobExists(): void
    {
        $jobs = new ScanJobRepository($this->db());
        $db = $this->db();

        $liveId = $this->uuid();
        $doneId = $this->uuid();

        // A live `running` job, stamped ten minutes ago.
        $db->query(
            'INSERT INTO library_scan_jobs (id, library_id, type, status, queued_at, started_at)'
            . ' VALUES (?, ?, ?, ?, NOW() - INTERVAL 10 MINUTE, NOW() - INTERVAL 10 MINUTE)',
            [$liveId, $this->libraryId, 'rescan', 'running'],
        );
        // A NEWER terminal job, exactly as a metadata match that finished meanwhile.
        $db->query(
            'INSERT INTO library_scan_jobs (id, library_id, type, status, queued_at, completed_at)'
            . ' VALUES (?, ?, ?, ?, NOW(), NOW())',
            [$doneId, $this->libraryId, 'metadata', 'completed'],
        );

        $latest = $jobs->getLatestForLibrary($this->libraryId);
        $this->assertIsArray($latest);
        $this->assertSame(
            'completed',
            $latest['status'],
            'fixture check: the NEWEST row is the terminal one, which is what makes this case interesting',
        );

        $this->assertTrue(
            $jobs->hasActiveJobForLibrary($this->libraryId),
            'a `running` job must be seen even though a newer job has completed — reading only the newest '
            . 'row reports "idle" for a library that is actively being scanned',
        );
        $this->assertNull(
            $jobs->startRunningIfIdle($this->libraryId, 'scan'),
            'and the guarded insert must agree, or the refusal is decorative',
        );
    }

    /**
     * The same hole without any newer row: two jobs written in the SAME second tie on
     * `queued_at`, and `ORDER BY queued_at DESC LIMIT 1` then picks arbitrarily.
     */
    public function testAQueuedJobTyingOnQueuedAtWithATerminalOneIsStillSeen(): void
    {
        $jobs = new ScanJobRepository($this->db());
        $db = $this->db();

        $stamp = '2026-07-27 13:15:03';
        foreach ([[$this->uuid(), 'queued'], [$this->uuid(), 'completed']] as [$id, $status]) {
            $db->query(
                'INSERT INTO library_scan_jobs (id, library_id, type, status, queued_at) VALUES (?, ?, ?, ?, ?)',
                [$id, $this->libraryId, 'scan', $status, $stamp],
            );
        }

        $this->assertTrue(
            $jobs->hasActiveJobForLibrary($this->libraryId),
            'a `queued` job tying on queued_at with a `completed` one must still count as active',
        );
    }

    /**
     * S150 decision, proven end to end: with a `running` row already present, a second
     * CLI scan refuses — and `--force` gets past it.
     */
    public function testASecondCliScanRefusesWhileAJobIsRunningUnlessForced(): void
    {
        $jobs = new ScanJobRepository($this->db());
        $jobs->startRunning($this->libraryId, 'scan');

        $refused = $this->createMock(LibraryManager::class);
        $refused->expects($this->never())->method('scanLibrary');

        $tester = $this->tester($refused);
        $this->assertSame(Command::FAILURE, $tester->execute(['libraryId' => $this->libraryId]));
        $this->assertStringContainsString('already queued or running', $tester->getDisplay());

        $forced = $this->createMock(LibraryManager::class);
        $forced->expects($this->once())->method('scanLibrary')->willReturn(new ScanResult());

        $this->assertSame(Command::SUCCESS, $this->tester($forced)->execute([
            'libraryId' => $this->libraryId,
            '--force' => true,
        ]));
    }

    /**
     * The type written by the CLI must be one the ENUM actually admits. This is the
     * assertion a mocked repository can never make: MySQL rejects a bad ENUM member
     * under strict mode, and the resulting scan is invisible — the S150 bug again.
     */
    public function testTheJobTypeWrittenByTheCliIsAValidEnumMember(): void
    {
        $jobs = new ScanJobRepository($this->db());
        $scanId = $jobs->startRunning($this->libraryId, 'scan');
        $rescanId = $jobs->startRunning($this->libraryId, 'rescan');

        foreach ([$scanId => 'scan', $rescanId => 'rescan'] as $id => $expected) {
            $row = $this->db()->row('SELECT type, status, started_at FROM library_scan_jobs WHERE id = ?', [$id]);
            $this->assertIsArray($row, 'startRunning() must actually INSERT a row');
            $this->assertSame($expected, $row['type']);
            $this->assertSame('running', $row['status'], 'the row must be born `running`, never `queued`');
            $this->assertNotNull(
                $row['started_at'],
                'started_at must be stamped at insert — a NULL there makes the badge show no elapsed time',
            );
        }
    }

    /**
     * A row inserted by {@see ScanJobRepository::startRunning()} must be invisible to
     * {@see ScanJobRepository::claimNext()}. If it were `queued`, the background worker
     * would pick it up and run a SECOND, concurrent scan of the same library.
     */
    public function testAStartRunningRowIsNeverClaimableByTheWorker(): void
    {
        $jobs = new ScanJobRepository($this->db());
        $mine = $jobs->startRunning($this->libraryId, 'rescan');

        $claimed = $jobs->claimNext();

        if ($claimed !== null) {
            $this->assertNotSame(
                $mine,
                $claimed['id'] ?? null,
                'the worker must never be able to claim a row the CLI is already executing',
            );
        } else {
            $this->assertNull($claimed);
        }
    }

    private function tester(LibraryManager $manager): CommandTester
    {
        $jobs = new ScanJobRepository($this->db());

        $application = new Application();
        $application->add(new LibraryScanCommand(
            static fn(): LibraryManager => $manager,
            static fn(): ScanJobRepository => $jobs,
        ));

        return new CommandTester($application->find('library:scan'));
    }

    /** @return array<string, mixed>|null */
    private function latestJobRow(): ?array
    {
        $row = $this->db()->row(
            'SELECT * FROM library_scan_jobs WHERE library_id = ? ORDER BY queued_at DESC, started_at DESC LIMIT 1',
            [$this->libraryId],
        );

        /** @var array<string, mixed>|null $row */
        return is_array($row) ? $row : null;
    }

    /**
     * Write the tiny PHP program the SIGTERM case spawns: it builds the REAL command
     * against the REAL repository, with a LibraryManager stub whose "scan" simply
     * blocks — long enough for this test to kill it mid-run.
     */
    private function writeRunnerScript(): string
    {
        $root = dirname(__DIR__, 4);
        $path = sys_get_temp_dir() . '/phlix-s150-runner-' . bin2hex(random_bytes(6)) . '.php';

        $code = <<<'PHP'
<?php

declare(strict_types=1);

require __DIR__PLACEHOLDER . '/vendor/autoload.php';

use Phlix\Common\Database\ConnectionPool;
use Phlix\Console\Commands\LibraryScanCommand;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Library\ScanJobRepository;
use Phlix\Media\Library\ScanResult;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;

$libraryId = $argv[1] ?? '';
$pidFile = $argv[2] ?? '';

// Publish our REAL pid: the parent cannot trust proc_get_status() here.
if ($pidFile !== '') {
    file_put_contents($pidFile, (string) getmypid());
}

ConnectionPool::init(__DIR__PLACEHOLDER . '/config/database.php');
$jobs = new ScanJobRepository(ConnectionPool::getConnection('mysql'));

/**
 * A manager whose scan BLOCKS, so the parent test can kill this process mid-run.
 * `sleep()` here is correct and is NOT the Workerman blocking-sleep violation: this
 * is a one-shot CLI process with no event loop, which is precisely the shape a real
 * `php bin/phlix library:scan` has.
 */
$manager = new class extends LibraryManager {
    public function __construct()
    {
    }

    public function scanLibrary(
        string $libraryId,
        ?callable $onProgress = null,
        bool $readEveryFile = false
    ): ScanResult {
        if ($onProgress !== null) {
            $onProgress(1, 1000, '/tmp/phlix-s150/blocking.flac', ['added' => 0, 'failed' => 0]);
        }
        sleep(60);

        return new ScanResult();
    }
};

$application = new Application();
$application->setAutoExit(false);
$application->add(new LibraryScanCommand(
    static fn(): LibraryManager => $manager,
    static fn(): ScanJobRepository => $jobs,
));

$application->find('library:scan')->run(
    new ArrayInput(['libraryId' => $libraryId]),
    new ConsoleOutput(),
);
PHP;

        $written = file_put_contents($path, str_replace('__DIR__PLACEHOLDER', var_export($root, true), $code));
        $this->assertNotFalse($written, 'could not write the subprocess runner script');

        return $path;
    }

    /**
     * Write the program the MID-QUERY case spawns. Identical to
     * {@see self::writeRunnerScript()} except that the stub manager, instead of
     * sleeping in PHP, keeps a query in flight on the SAME connection the
     * {@see ScanJobRepository} writes through — which is the state a real scan is in
     * for essentially its whole life, and the state the original SIGTERM proof never
     * covered.
     */
    private function writeSlowQueryRunnerScript(): string
    {
        $root = dirname(__DIR__, 4);
        $path = sys_get_temp_dir() . '/phlix-s151-runner-' . bin2hex(random_bytes(6)) . '.php';

        $code = <<<'PHP'
<?php

declare(strict_types=1);

require __DIR__PLACEHOLDER . '/vendor/autoload.php';

use Phlix\Common\Database\ConnectionPool;
use Phlix\Console\Commands\LibraryScanCommand;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Library\ScanJobRepository;
use Phlix\Media\Library\ScanResult;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;

$libraryId = $argv[1] ?? '';
$pidFile = $argv[2] ?? '';
$busyFile = $argv[3] ?? '';

if ($pidFile !== '') {
    file_put_contents($pidFile, (string) getmypid());
}

ConnectionPool::init(__DIR__PLACEHOLDER . '/config/database.php');
$conn = ConnectionPool::getConnection('mysql');
$jobs = new ScanJobRepository($conn);

/**
 * A manager that keeps a query IN FLIGHT on the repository's own connection, so a
 * signal arriving here is a signal arriving mid-query. Two seconds per statement,
 * not sixty: PHP dispatches an async signal handler at an opcode boundary, so the
 * handler cannot run until the statement in progress returns.
 */
$manager = new class ($conn, $busyFile) extends LibraryManager {
    public function __construct(private $conn, private string $busyFile)
    {
    }

    public function scanLibrary(
        string $libraryId,
        ?callable $onProgress = null,
        bool $readEveryFile = false
    ): ScanResult {
        for ($i = 0; $i < 60; $i++) {
            $this->conn->query('SELECT SLEEP(2)');
            if ($this->busyFile !== '') {
                // Written only AFTER a query has already completed, so by the time the
                // parent sees it the next one is in flight.
                file_put_contents($this->busyFile, (string) $i);
            }
        }

        return new ScanResult();
    }
};

$application = new Application();
$application->setAutoExit(false);
$application->add(new LibraryScanCommand(
    static fn(): LibraryManager => $manager,
    static fn(): ScanJobRepository => $jobs,
));

$application->find('library:scan')->run(
    new ArrayInput(['libraryId' => $libraryId]),
    new ConsoleOutput(),
);
PHP;

        $written = file_put_contents($path, str_replace('__DIR__PLACEHOLDER', var_export($root, true), $code));
        $this->assertNotFalse($written, 'could not write the subprocess runner script');

        return $path;
    }

    private function db(): Connection
    {
        $this->assertInstanceOf(Connection::class, $this->db);

        return $this->db;
    }

    private function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}
