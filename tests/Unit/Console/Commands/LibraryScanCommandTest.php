<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Console\Commands;

use InvalidArgumentException;
use Phlix\Console\Commands\LibraryScanCommand;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Library\ScanJobRepository;
use Phlix\Media\Library\ScanResult;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class LibraryScanCommandTest extends TestCase
{
    private function tester(LibraryManager $manager): CommandTester
    {
        $application = new Application();
        $application->add(new LibraryScanCommand(fn(): LibraryManager => $manager));

        return new CommandTester($application->find('library:scan'));
    }

    public function testScanCallsScanLibrary(): void
    {
        $manager = $this->createMock(LibraryManager::class);
        // scanLibrary() returns a ScanResult since S96(b); ScanResult is final, so
        // PHPUnit cannot auto-generate a return value and the stub must be explicit.
        $manager->expects($this->once())->method('scanLibrary')->with('lib-1')
            ->willReturn(new ScanResult());
        $manager->expects($this->never())->method('rescanLibrary');

        $tester = $this->tester($manager);
        $exitCode = $tester->execute(['libraryId' => 'lib-1']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Scan of library "lib-1" complete.', $tester->getDisplay());
    }

    public function testRescanFlagCallsRescanLibrary(): void
    {
        $manager = $this->createMock(LibraryManager::class);
        $manager->expects($this->once())->method('rescanLibrary')->with('lib-2')
            ->willReturn(new ScanResult());
        $manager->expects($this->never())->method('scanLibrary');

        $tester = $this->tester($manager);
        $exitCode = $tester->execute(['libraryId' => 'lib-2', '--rescan' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Rescan of library "lib-2" complete.', $tester->getDisplay());
    }

    /**
     * Review r1 INFO-10: `failed` must be RENDERED somewhere, not merely reachable.
     *
     * S96(f) put the counter in `ScanResult::toArray()`, in `library_scan_jobs
     * .items_failed` and in the app log — but the admin SPA's `ScanJob` interface does
     * not list it, so the only operator-visible surface was `curl`/`grep`. This command
     * already had the whole `ScanResult` and discarded it.
     */
    public function testScanRendersTheCountersIncludingFailed(): void
    {
        $result = new ScanResult();
        $result->scanned = 10;
        $result->added = 7;
        $result->updated = 1;
        $result->removed = 0;
        $result->failed = 2;

        $manager = $this->createMock(LibraryManager::class);
        $manager->method('scanLibrary')->willReturn($result);

        $tester = $this->tester($manager);
        $exitCode = $tester->execute(['libraryId' => 'lib-3'], ['capture_stderr_separately' => true]);

        // Review r2 F7: a lossy scan must be visible to a NON-HUMAN caller too. It used to
        // print to stdout behind exit 0, so a cron/CI wrapper inspecting the exit status or
        // stderr saw an unqualified success while the library was missing files.
        $this->assertSame(
            3,
            $exitCode,
            'a scan that lost files must exit non-zero (3 = completed-with-loss, distinct from 1 = '
            . 'did-not-run), so cron/CI notices'
        );
        $this->assertStringContainsString('scanned: 10', $tester->getDisplay());
        $this->assertStringContainsString('added: 7', $tester->getDisplay());
        $this->assertStringContainsString('failed: 2', $tester->getDisplay());
        $this->assertStringContainsString(
            '2 file(s) could not be indexed',
            $tester->getErrorOutput(),
            'the warning belongs on STDERR — a wrapper that only reads stderr must still see it'
        );
        $this->assertStringNotContainsString(
            'could not be indexed',
            $tester->getDisplay(),
            'and it must not ALSO go to stdout, or piping stdout into a parser picks up prose'
        );
    }

    /**
     * The lossy exit code must not be the same as the did-not-run one, or a wrapper
     * cannot tell "fix your config" from "your library just lost files".
     *
     * Review r3 finding 10 added the third comparison: the code must also differ from
     * `Command::INVALID` (2), Symfony's **"invalid input / usage"**. It WAS 2, so
     * "the scan ran and lost 5 files" and "you typed the arguments wrong" arrived at a
     * wrapper as the same integer — the very distinction the constant exists to make.
     * Asserting against the framework constant (not the literal 2) means this stays
     * pinned even if Symfony renumbers it.
     */
    public function testTheLossyExitCodeIsDistinctFromTheFailureExitCode(): void
    {
        $lossy = new ScanResult();
        $lossy->scanned = 1;
        $lossy->failed = 1;

        $manager = $this->createMock(LibraryManager::class);
        $manager->method('scanLibrary')->willReturn($lossy);
        $lossyCode = $this->tester($manager)->execute(['libraryId' => 'lib-5']);

        $throwing = $this->createMock(LibraryManager::class);
        $throwing->method('scanLibrary')->willThrowException(new InvalidArgumentException('nope'));
        $failureCode = $this->tester($throwing)->execute(['libraryId' => 'lib-6']);

        $this->assertSame(Command::FAILURE, $failureCode);
        $this->assertNotSame($failureCode, $lossyCode);
        $this->assertNotSame(Command::SUCCESS, $lossyCode);
        $this->assertNotSame(
            Command::INVALID,
            $lossyCode,
            'the lossy code must not be Symfony\'s INVALID (2) — that is the framework\'s '
            . '"invalid input/usage", i.e. a scan that did NOT run, so overloading it destroys '
            . 'exactly the distinction this exit code was added to provide'
        );
    }

    public function testCleanScanDoesNotWarnAboutFailures(): void
    {
        $result = new ScanResult();
        $result->scanned = 4;
        $result->added = 4;

        $manager = $this->createMock(LibraryManager::class);
        $manager->method('scanLibrary')->willReturn($result);

        $tester = $this->tester($manager);
        $tester->execute(['libraryId' => 'lib-4']);
        $display = $tester->getDisplay();

        $this->assertStringContainsString('failed: 0', $display);
        $this->assertStringNotContainsString('could not be indexed', $display);
    }

    public function testUnknownLibraryExitsOne(): void
    {
        $manager = $this->createMock(LibraryManager::class);
        $manager->method('scanLibrary')
            ->willThrowException(new InvalidArgumentException('Library not found: missing'));

        $tester = $this->tester($manager);
        $exitCode = $tester->execute(['libraryId' => 'missing']);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Scan failed: Library not found: missing', $tester->getDisplay());
    }

    // ---------------------------------------------------------------------
    // S150 — a CLI scan must be visible in the admin Libraries page.
    //
    // ⚠ These are UNIT tests over a mocked ScanJobRepository, so they pin the
    // command's CONTRACT (which repository calls, in which order, with which
    // arguments). They cannot prove the row actually lands in MySQL with a valid
    // ENUM `type`/`status`, nor that a killed process leaves it `failed` — a mock
    // accepts any string and never dies. That is proven separately, against a real
    // database and a real killed subprocess, in
    // tests/Integration/Media/Library/CliScanJobVisibilityTest.
    // ---------------------------------------------------------------------

    private function testerWithJobs(LibraryManager $manager, ScanJobRepository $jobs): CommandTester
    {
        $application = new Application();
        $application->add(new LibraryScanCommand(
            fn(): LibraryManager => $manager,
            fn(): ScanJobRepository => $jobs,
        ));

        return new CommandTester($application->find('library:scan'));
    }

    public function testAScanOpensARunningJobRowBeforeTheScanAndCompletesIt(): void
    {
        $result = new ScanResult();
        $result->scanned = 7;
        $result->added = 3;
        $result->removed = 1;

        $jobs = $this->createMock(ScanJobRepository::class);
        $jobs->method('hasActiveJobForLibrary')->willReturn(false);
        $jobs->expects($this->never())->method('startRunning');
        $jobs->expects($this->once())
            ->method('startRunningIfIdle')
            ->with('lib-j1', 'scan')
            ->willReturn('job-j1');
        $jobs->expects($this->once())
            ->method('markCompleted')
            ->with('job-j1', ['items_added' => 3, 'items_removed' => 1, 'items_failed' => 0]);
        $jobs->expects($this->never())->method('markFailed');

        $manager = $this->createMock(LibraryManager::class);
        $manager->expects($this->once())->method('scanLibrary')->willReturn($result);

        $this->assertSame(Command::SUCCESS, $this->testerWithJobs($manager, $jobs)->execute([
            'libraryId' => 'lib-j1',
        ]));
    }

    /**
     * `--rescan` must open the row with `type = 'rescan'`, not `'scan'`. The admin
     * badge renders the type, so getting this wrong mislabels a 10-hour healing run
     * as an ordinary incremental scan.
     */
    public function testARescanOpensTheRowWithTheRescanType(): void
    {
        $jobs = $this->createMock(ScanJobRepository::class);
        $jobs->method('hasActiveJobForLibrary')->willReturn(false);
        $jobs->expects($this->never())->method('startRunning');
        $jobs->expects($this->once())
            ->method('startRunningIfIdle')
            ->with('lib-j2', 'rescan')
            ->willReturn('job-j2');
        $jobs->expects($this->once())->method('markCompleted');

        $manager = $this->createMock(LibraryManager::class);
        $manager->expects($this->once())->method('rescanLibrary')->willReturn(new ScanResult());

        $this->assertSame(Command::SUCCESS, $this->testerWithJobs($manager, $jobs)->execute([
            'libraryId' => 'lib-j2',
            '--rescan' => true,
        ]));
    }

    /**
     * The scanner's per-file ticks must reach the job row, throttled — otherwise the
     * badge shows a `running` job stuck at 0 % for the whole run, which is only
     * marginally better than the stale `failed` badge S150 exists to remove.
     */
    public function testProgressTicksStreamOntoTheJobRow(): void
    {
        $writes = [];

        $jobs = $this->createMock(ScanJobRepository::class);
        $jobs->method('hasActiveJobForLibrary')->willReturn(false);
        $jobs->method('startRunningIfIdle')->willReturn('job-j3');
        $jobs->method('updateProgress')->willReturnCallback(
            function (string $jobId, array $counts, ?string $cur = null) use (&$writes): void {
                $writes[] = [$jobId, $counts, $cur];
            },
        );

        // S120 — the callback RECORDS the sink it was handed, the assertion runs
        // OUTSIDE it. It used to call self::assertIsCallable() inline, which
        // LibraryScanCommand.php:246's `catch (Throwable $e)` swallowed (it wraps the
        // scanLibrary() call at :245), leaving this test red on the $writes diff below
        // with no trace of the real cause.
        $manager = $this->createMock(LibraryManager::class);
        $sink = null;
        $manager->method('scanLibrary')->willReturnCallback(
            static function (string $lib, ?callable $onProgress = null) use (&$sink): ScanResult {
                $sink = $onProgress;

                if ($onProgress !== null) {
                    for ($i = 1; $i <= 30; $i++) {
                        $onProgress($i, 30, "/music/file-$i.flac", ['added' => $i, 'failed' => 0]);
                    }
                }

                return new ScanResult();
            },
        );

        $this->testerWithJobs($manager, $jobs)->execute(['libraryId' => 'lib-j3']);

        $this->assertIsCallable($sink, 'the CLI must pass a progress sink to the scanner');

        // Same throttle as the worker: one write at 25, one on the final file.
        // ⚠ `items_updated` is the PROCESSED-FILE numerator, not rows written.
        $this->assertSame([
            ['job-j3', ['items_found' => 30, 'items_updated' => 25, 'items_added' => 25, 'items_failed' => 0],
                '/music/file-25.flac'],
            ['job-j3', ['items_found' => 30, 'items_updated' => 30, 'items_added' => 30, 'items_failed' => 0],
                '/music/file-30.flac'],
        ], $writes);
    }

    public function testAThrownScanMarksTheJobFailedWithTheReason(): void
    {
        $jobs = $this->createMock(ScanJobRepository::class);
        $jobs->method('hasActiveJobForLibrary')->willReturn(false);
        $jobs->method('startRunningIfIdle')->willReturn('job-j4');
        $jobs->expects($this->never())->method('markCompleted');
        $jobs->expects($this->once())->method('markFailed')->with('job-j4', 'Library not found: gone');

        $manager = $this->createMock(LibraryManager::class);
        $manager->method('scanLibrary')->willThrowException(new InvalidArgumentException('Library not found: gone'));

        $this->assertSame(Command::FAILURE, $this->testerWithJobs($manager, $jobs)->execute([
            'libraryId' => 'gone',
        ]));
    }

    /**
     * A scan that COMPLETED but lost files stays `completed` — the lost files are
     * reported through `items_failed`, exactly as the worker reports them. Flipping
     * the row to `failed` would make the badge claim the scan never ran.
     */
    public function testALossyButCompletedScanStillMarksTheJobCompleted(): void
    {
        $lossy = new ScanResult();
        $lossy->scanned = 5;
        $lossy->failed = 2;

        $jobs = $this->createMock(ScanJobRepository::class);
        $jobs->method('hasActiveJobForLibrary')->willReturn(false);
        $jobs->method('startRunningIfIdle')->willReturn('job-j5');
        $jobs->expects($this->once())
            ->method('markCompleted')
            ->with('job-j5', ['items_added' => 0, 'items_removed' => 0, 'items_failed' => 2]);
        $jobs->expects($this->never())->method('markFailed');

        $manager = $this->createMock(LibraryManager::class);
        $manager->method('scanLibrary')->willReturn($lossy);

        $this->assertSame(3, $this->testerWithJobs($manager, $jobs)->execute(['libraryId' => 'lib-j5']));
    }

    /**
     * S150 decision: a CLI scan REFUSES to start while the library already has a
     * queued/running job. Two scanners over one library race on every per-file
     * find-or-create, and only one of them can own the badge.
     */
    public function testAnAlreadyActiveJobRefusesTheScan(): void
    {
        $jobs = $this->createMock(ScanJobRepository::class);
        $jobs->method('hasActiveJobForLibrary')->with('lib-busy')->willReturn(true);
        $jobs->expects($this->never())->method('startRunning');
        $jobs->expects($this->never())->method('startRunningIfIdle');

        $manager = $this->createMock(LibraryManager::class);
        $manager->expects($this->never())->method('scanLibrary');
        $manager->expects($this->never())->method('rescanLibrary');

        $tester = $this->testerWithJobs($manager, $jobs);
        $this->assertSame(Command::FAILURE, $tester->execute(['libraryId' => 'lib-busy']));
        $this->assertStringContainsString('already queued or running', $tester->getDisplay());
    }

    /** `--force` is the documented escape hatch for a row stranded by a kill -9. */
    public function testForceOverridesTheAlreadyActiveRefusal(): void
    {
        $jobs = $this->createMock(ScanJobRepository::class);
        $jobs->method('hasActiveJobForLibrary')->willReturn(true);
        $jobs->expects($this->once())->method('startRunning')->willReturn('job-forced');
        $jobs->expects($this->never())->method('startRunningIfIdle');

        $manager = $this->createMock(LibraryManager::class);
        $manager->expects($this->once())->method('scanLibrary')->willReturn(new ScanResult());

        $this->assertSame(Command::SUCCESS, $this->testerWithJobs($manager, $jobs)->execute([
            'libraryId' => 'lib-busy',
            '--force' => true,
        ]));
    }

    /**
     * S151 review finding 5 — the pre-check is not the guarantee, the guarded INSERT
     * is. Two invocations started together BOTH read "no active job"; only
     * `startRunningIfIdle()` returning NULL can stop the second one running.
     *
     * The mock reproduces exactly that interleaving: `hasActiveJobForLibrary()` says
     * idle (the competitor had not inserted yet), and the INSERT then finds it has.
     * The scan must not run, and no terminal stamp may be written — the row belongs to
     * the winner and stamping it would report the LOSER's outcome on it.
     */
    public function testLosingTheInsertRaceRefusesTheScanEvenThoughThePreCheckSaidIdle(): void
    {
        $jobs = $this->createMock(ScanJobRepository::class);
        $jobs->method('newJobId')->willReturn('job-race');
        $jobs->method('hasActiveJobForLibrary')->willReturn(false);
        $jobs->expects($this->once())->method('startRunningIfIdle')->willReturn(null);
        $jobs->expects($this->never())->method('markCompleted');
        $jobs->expects($this->never())->method('markFailed');
        $jobs->expects($this->never())->method('updateProgress');

        $manager = $this->createMock(LibraryManager::class);
        $manager->expects($this->never())->method('scanLibrary');
        $manager->expects($this->never())->method('rescanLibrary');

        $tester = $this->testerWithJobs($manager, $jobs);
        $this->assertSame(Command::FAILURE, $tester->execute(['libraryId' => 'lib-race']));
        $this->assertStringContainsString('already queued or running', $tester->getDisplay());
    }

    /**
     * S151 review finding 1 — the id must be minted BEFORE the row is created, so the
     * termination handlers can name a row that does not exist yet.
     *
     * Ordering is asserted directly: `newJobId()` must be called, and it must be
     * called before the INSERT. If a future edit goes back to letting the INSERT mint
     * the id, there is again a window in which a committed, externally visible row has
     * no handler that can fail it — which is what stranded rows `running` forever in
     * 3 runs out of 10 of the integration test.
     */
    public function testTheJobIdIsMintedBeforeTheRowIsCreated(): void
    {
        $order = [];

        $jobs = $this->createMock(ScanJobRepository::class);
        $jobs->method('hasActiveJobForLibrary')->willReturn(false);
        $jobs->method('newJobId')->willReturnCallback(static function () use (&$order): string {
            $order[] = 'newJobId';

            return 'job-order';
        });
        $jobs->method('startRunningIfIdle')->willReturnCallback(
            static function (string $libraryId, string $type, ?string $jobId = null) use (&$order): ?string {
                $order[] = 'insert:' . (string) $jobId;

                return $jobId;
            },
        );
        $jobs->expects($this->once())->method('markCompleted')->with('job-order', $this->anything());

        $manager = $this->createMock(LibraryManager::class);
        $manager->method('scanLibrary')->willReturn(new ScanResult());

        $this->assertSame(Command::SUCCESS, $this->testerWithJobs($manager, $jobs)->execute([
            'libraryId' => 'lib-order',
        ]));
        $this->assertSame(
            ['newJobId', 'insert:job-order'],
            $order,
            'the id must exist before the row does, and it must be the id the INSERT is handed',
        );
    }

    /**
     * ⚠ Job tracking is OBSERVABILITY. It must never be able to refuse an operator's
     * scan: an unreachable store degrades to the pre-S150 behaviour (the scan runs,
     * the UI just cannot see it), with a warning on stderr.
     */
    public function testAnUnavailableJobStoreStillRunsTheScan(): void
    {
        $manager = $this->createMock(LibraryManager::class);
        $manager->expects($this->once())->method('scanLibrary')->willReturn(new ScanResult());

        $application = new Application();
        $application->add(new LibraryScanCommand(
            fn(): LibraryManager => $manager,
            static function (): ScanJobRepository {
                throw new \RuntimeException('no database');
            },
        ));
        $tester = new CommandTester($application->find('library:scan'));

        $this->assertSame(Command::SUCCESS, $tester->execute(['libraryId' => 'lib-nodb']));
        $this->assertStringContainsString('Scan-job tracking unavailable', $tester->getDisplay());
    }
}
