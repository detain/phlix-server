<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library;

use PHPUnit\Framework\TestCase;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Library\LibraryScanWorker;
use Phlix\Media\Library\ScanJobRepository;
use Phlix\Media\Library\ScanResult;
use Phlix\Media\Metadata\LibraryMetadataMatcher;
use RuntimeException;
use Workerman\Timer;
use Workerman\Worker;

/**
 * Unit tests for {@see LibraryScanWorker} (Step 1.1b).
 *
 * Covers every branch of {@see LibraryScanWorker::runOnce()} with mocked
 * dependencies. The {@see LibraryScanWorker::start()} method is intentionally
 * NOT covered: it only installs a {@see \Workerman\Timer}, which requires a
 * running Workerman event loop and is therefore an infra-untestable daemon
 * entry point (kept a one-liner so there is almost nothing to cover).
 */
class LibraryScanWorkerTest extends TestCase
{
    /**
     * Build a worker with a throwaway null-channel logger so the worker's log
     * calls do not write to disk during tests.
     */
    private function makeLogger(): StructuredLogger
    {
        return $this->createMock(StructuredLogger::class);
    }

    /**
     * A metadata-matcher mock that expects NOT to be invoked. Used by the
     * scan/rescan/empty/invalid tests, where the worker must never run a
     * metadata match.
     */
    private function makeUnusedMatcher(): LibraryMetadataMatcher
    {
        $matcher = $this->createMock(LibraryMetadataMatcher::class);
        $matcher->expects($this->never())->method('matchLibrary');
        return $matcher;
    }

    /**
     * runOnce() with a `scan` job: claims, runs scanLibrary, marks completed,
     * returns true (and does NOT mark failed / rescan).
     */
    public function testRunOnceProcessesScanJob(): void
    {
        $jobs = $this->createMock(ScanJobRepository::class);
        $jobs->expects($this->once())
            ->method('claimNext')
            ->willReturn(['id' => 'job-1', 'library_id' => 'lib-1', 'type' => 'scan']);
        $jobs->expects($this->once())->method('markCompleted')->with('job-1');
        $jobs->expects($this->never())->method('markFailed');

        $libraries = $this->createMock(LibraryManager::class);
        $libraries->expects($this->once())->method('scanLibrary')
            ->with('lib-1', $this->isType('callable'));
        $libraries->expects($this->never())->method('rescanLibrary');

        $worker = new LibraryScanWorker($jobs, $libraries, $this->makeUnusedMatcher(), $this->makeLogger());

        $this->assertTrue($worker->runOnce());
    }

    /**
     * runOnce() with a `rescan` job: runs rescanLibrary + markCompleted,
     * returns true.
     */
    public function testRunOnceProcessesRescanJob(): void
    {
        $jobs = $this->createMock(ScanJobRepository::class);
        $jobs->expects($this->once())
            ->method('claimNext')
            ->willReturn(['id' => 'job-2', 'library_id' => 'lib-2', 'type' => 'rescan']);
        $jobs->expects($this->once())->method('markCompleted')->with('job-2');
        $jobs->expects($this->never())->method('markFailed');

        $libraries = $this->createMock(LibraryManager::class);
        $libraries->expects($this->once())->method('rescanLibrary')
            ->with('lib-2', $this->isType('array'), $this->isType('callable'))
            ->willReturn(new ScanResult());
        $libraries->expects($this->never())->method('scanLibrary');

        $worker = new LibraryScanWorker($jobs, $libraries, $this->makeUnusedMatcher(), $this->makeLogger());

        $this->assertTrue($worker->runOnce());
    }

    public function testRunOnceMetadataJobStreamsProgressToTheJob(): void
    {
        $jobs = $this->createMock(ScanJobRepository::class);
        $jobs->expects($this->once())
            ->method('claimNext')
            ->willReturn(['id' => 'job-3', 'library_id' => 'lib-3', 'type' => 'metadata']);
        $jobs->expects($this->once())->method('markCompleted')->with('job-3');
        // The matcher's progress callback is forwarded verbatim onto the job row.
        $jobs->expects($this->once())
            ->method('updateProgress')
            ->with('job-3', ['items_found' => 10, 'items_updated' => 4]);

        $matcher = $this->createMock(LibraryMetadataMatcher::class);
        $matcher->expects($this->once())
            ->method('matchLibrary')
            ->willReturnCallback(static function (string $lib, ?callable $onProgress = null): array {
                if ($onProgress !== null) {
                    $onProgress(4, 10, 2);
                }
                return ['matched' => 2, 'processed' => 4];
            });

        $libraries = $this->createMock(LibraryManager::class);
        $libraries->expects($this->never())->method('scanLibrary');
        $libraries->expects($this->never())->method('rescanLibrary');

        $worker = new LibraryScanWorker($jobs, $libraries, $matcher, $this->makeLogger());

        $this->assertTrue($worker->runOnce());
    }

    public function testRunOnceScanJobStreamsThrottledProgress(): void
    {
        $writes = [];
        $jobs = $this->createMock(ScanJobRepository::class);
        $jobs->expects($this->once())
            ->method('claimNext')
            ->willReturn(['id' => 'job-9', 'library_id' => 'lib-9', 'type' => 'scan']);
        $jobs->expects($this->once())->method('markCompleted')->with('job-9');
        $jobs->method('updateProgress')->willReturnCallback(
            function (string $jobId, array $counts, ?string $cur = null) use (&$writes): void {
                $writes[] = $counts;
            },
        );

        // The scan engine drives the progress sink once per file across 30 files.
        $libraries = $this->createMock(LibraryManager::class);
        $libraries->expects($this->once())
            ->method('scanLibrary')
            ->willReturnCallback(static function (string $lib, ?callable $onProgress = null): void {
                if ($onProgress === null) {
                    return;
                }
                for ($i = 1; $i <= 30; $i++) {
                    $onProgress($i, 30, "/media/file-$i.mkv");
                }
            });

        $worker = new LibraryScanWorker($jobs, $libraries, $this->makeUnusedMatcher(), $this->makeLogger());
        $this->assertTrue($worker->runOnce());

        // Throttled to one write per 25 files (at 25) plus the final file (30).
        $this->assertSame([
            ['items_found' => 30, 'items_updated' => 25],
            ['items_found' => 30, 'items_updated' => 30],
        ], $writes);
    }

    /**
     * runOnce() with an empty queue (claimNext() === null): returns false and
     * touches neither the scan engine nor the mark* methods.
     */
    public function testRunOnceReturnsFalseWhenNothingQueued(): void
    {
        $jobs = $this->createMock(ScanJobRepository::class);
        $jobs->expects($this->once())->method('claimNext')->willReturn(null);
        $jobs->expects($this->never())->method('markCompleted');
        $jobs->expects($this->never())->method('markFailed');

        $libraries = $this->createMock(LibraryManager::class);
        $libraries->expects($this->never())->method('scanLibrary');
        $libraries->expects($this->never())->method('rescanLibrary');

        $worker = new LibraryScanWorker($jobs, $libraries, $this->makeUnusedMatcher(), $this->makeLogger());

        $this->assertFalse($worker->runOnce());
    }

    /**
     * runOnce() where the scan throws: marks the job failed with the exception
     * message, does NOT mark completed, and still returns true (a job was
     * processed — it failed).
     */
    public function testRunOnceMarksFailedWhenScanThrows(): void
    {
        $jobs = $this->createMock(ScanJobRepository::class);
        $jobs->expects($this->once())
            ->method('claimNext')
            ->willReturn(['id' => 'job-3', 'library_id' => 'lib-3', 'type' => 'scan']);
        $jobs->expects($this->never())->method('markCompleted');
        $jobs->expects($this->once())->method('markFailed')->with('job-3', 'disk gone');

        $libraries = $this->createMock(LibraryManager::class);
        $libraries->expects($this->once())
            ->method('scanLibrary')
            ->with('lib-3', $this->isType('callable'))
            ->willThrowException(new RuntimeException('disk gone'));

        $worker = new LibraryScanWorker($jobs, $libraries, $this->makeUnusedMatcher(), $this->makeLogger());

        $this->assertTrue($worker->runOnce());
    }

    /**
     * runOnce() where rescan throws: marks failed, returns true.
     */
    public function testRunOnceMarksFailedWhenRescanThrows(): void
    {
        $jobs = $this->createMock(ScanJobRepository::class);
        $jobs->expects($this->once())
            ->method('claimNext')
            ->willReturn(['id' => 'job-4', 'library_id' => 'lib-4', 'type' => 'rescan']);
        $jobs->expects($this->never())->method('markCompleted');
        $jobs->expects($this->once())->method('markFailed')->with('job-4', 'boom');

        $libraries = $this->createMock(LibraryManager::class);
        $libraries->expects($this->once())
            ->method('rescanLibrary')
            ->with('lib-4')
            ->willThrowException(new RuntimeException('boom'));

        $worker = new LibraryScanWorker($jobs, $libraries, $this->makeUnusedMatcher(), $this->makeLogger());

        $this->assertTrue($worker->runOnce());
    }

    /**
     * Defensive: a claimed row missing a usable id/library_id is skipped — the
     * worker neither scans nor marks the row completed, and returns true so the
     * caller advances past the bad row.
     */
    public function testRunOnceSkipsInvalidClaimedRow(): void
    {
        $jobs = $this->createMock(ScanJobRepository::class);
        // No 'id'/'library_id' string keys → defensive skip.
        $jobs->expects($this->once())
            ->method('claimNext')
            ->willReturn(['type' => 'scan']);
        $jobs->expects($this->never())->method('markCompleted');
        $jobs->expects($this->never())->method('markFailed');

        $libraries = $this->createMock(LibraryManager::class);
        $libraries->expects($this->never())->method('scanLibrary');
        $libraries->expects($this->never())->method('rescanLibrary');

        $worker = new LibraryScanWorker($jobs, $libraries, $this->makeUnusedMatcher(), $this->makeLogger());

        $this->assertTrue($worker->runOnce());
    }

    /**
     * Defaulting an unknown/absent `type` falls back to a plain scan (the
     * controller only ever enqueues `scan`/`rescan`, but the worker treats
     * anything that is not `rescan` as a scan).
     */
    public function testRunOnceDefaultsUnknownTypeToScan(): void
    {
        $jobs = $this->createMock(ScanJobRepository::class);
        $jobs->expects($this->once())
            ->method('claimNext')
            ->willReturn(['id' => 'job-5', 'library_id' => 'lib-5']);
        $jobs->expects($this->once())->method('markCompleted')->with('job-5');

        $libraries = $this->createMock(LibraryManager::class);
        $libraries->expects($this->once())->method('scanLibrary')->with('lib-5');
        $libraries->expects($this->never())->method('rescanLibrary');

        $worker = new LibraryScanWorker($jobs, $libraries, $this->makeUnusedMatcher(), $this->makeLogger());

        $this->assertTrue($worker->runOnce());
    }

    /**
     * runOnce() with a `metadata` job: runs the metadata matcher (NOT the scan
     * engine), marks completed, returns true.
     */
    public function testRunOnceProcessesMetadataJob(): void
    {
        $jobs = $this->createMock(ScanJobRepository::class);
        $jobs->expects($this->once())
            ->method('claimNext')
            ->willReturn(['id' => 'job-6', 'library_id' => 'lib-6', 'type' => 'metadata']);
        $jobs->expects($this->once())->method('markCompleted')->with('job-6');
        $jobs->expects($this->never())->method('markFailed');

        $libraries = $this->createMock(LibraryManager::class);
        $libraries->expects($this->never())->method('scanLibrary');
        $libraries->expects($this->never())->method('rescanLibrary');

        $matcher = $this->createMock(LibraryMetadataMatcher::class);
        $matcher->expects($this->once())
            ->method('matchLibrary')
            ->with('lib-6')
            ->willReturn(['matched' => 3, 'processed' => 5]);

        $worker = new LibraryScanWorker($jobs, $libraries, $matcher, $this->makeLogger());

        $this->assertTrue($worker->runOnce());
    }

    /**
     * runOnce() where the metadata matcher throws: marks the job failed, does
     * NOT mark completed, still returns true.
     */
    public function testRunOnceMarksFailedWhenMetadataMatchThrows(): void
    {
        $jobs = $this->createMock(ScanJobRepository::class);
        $jobs->expects($this->once())
            ->method('claimNext')
            ->willReturn(['id' => 'job-7', 'library_id' => 'lib-7', 'type' => 'metadata']);
        $jobs->expects($this->never())->method('markCompleted');
        $jobs->expects($this->once())->method('markFailed')->with('job-7', 'tmdb down');

        $libraries = $this->createMock(LibraryManager::class);
        $libraries->expects($this->never())->method('scanLibrary');
        $libraries->expects($this->never())->method('rescanLibrary');

        $matcher = $this->createMock(LibraryMetadataMatcher::class);
        $matcher->expects($this->once())
            ->method('matchLibrary')
            ->with('lib-7')
            ->willThrowException(new RuntimeException('tmdb down'));

        $worker = new LibraryScanWorker($jobs, $libraries, $matcher, $this->makeLogger());

        $this->assertTrue($worker->runOnce());
    }

    /**
     * runOnce() with a plain `metadata` job disables force refresh on the matcher
     * (skip-already-matched behaviour is preserved).
     */
    public function testRunOnceMetadataJobDisablesForceRefresh(): void
    {
        $jobs = $this->createMock(ScanJobRepository::class);
        $jobs->expects($this->once())
            ->method('claimNext')
            ->willReturn(['id' => 'job-8', 'library_id' => 'lib-8', 'type' => 'metadata']);
        $jobs->expects($this->once())->method('markCompleted')->with('job-8');

        $libraries = $this->createMock(LibraryManager::class);

        $matcher = $this->createMock(LibraryMetadataMatcher::class);
        $matcher->expects($this->once())->method('setForceRefresh')->with(false);
        $matcher->expects($this->once())->method('matchLibrary')->with('lib-8')
            ->willReturn(['matched' => 0, 'processed' => 0]);

        $worker = new LibraryScanWorker($jobs, $libraries, $matcher, $this->makeLogger());

        $this->assertTrue($worker->runOnce());
    }

    /**
     * runOnce() with a `prune` job runs pruneLibrary (NOT scan/rescan), records
     * the removed count on the job row, and marks completed.
     */
    public function testRunOnceProcessesPruneJob(): void
    {
        $jobs = $this->createMock(ScanJobRepository::class);
        $jobs->expects($this->once())
            ->method('claimNext')
            ->willReturn(['id' => 'job-p', 'library_id' => 'lib-p', 'type' => 'prune']);
        $jobs->expects($this->once())->method('markCompleted')->with('job-p');
        $jobs->expects($this->never())->method('markFailed');
        $jobs->expects($this->once())
            ->method('updateProgress')
            ->with('job-p', ['items_removed' => 4]);

        $libraries = $this->createMock(LibraryManager::class);
        $libraries->expects($this->once())->method('pruneLibrary')->with('lib-p')->willReturn(4);
        $libraries->expects($this->never())->method('scanLibrary');
        $libraries->expects($this->never())->method('rescanLibrary');

        $worker = new LibraryScanWorker($jobs, $libraries, $this->makeUnusedMatcher(), $this->makeLogger());

        $this->assertTrue($worker->runOnce());
    }

    /**
     * runOnce() with a `clear_metadata` job runs clearMetadata and forwards its
     * (processed, total) progress onto the job row, then marks completed.
     */
    public function testRunOnceProcessesClearMetadataJob(): void
    {
        $jobs = $this->createMock(ScanJobRepository::class);
        $jobs->expects($this->once())
            ->method('claimNext')
            ->willReturn(['id' => 'job-cm', 'library_id' => 'lib-cm', 'type' => 'clear_metadata']);
        $jobs->expects($this->once())->method('markCompleted')->with('job-cm');
        $jobs->expects($this->never())->method('markFailed');
        $jobs->expects($this->once())
            ->method('updateProgress')
            ->with('job-cm', ['items_found' => 8, 'items_updated' => 3]);

        $libraries = $this->createMock(LibraryManager::class);
        $libraries->expects($this->never())->method('scanLibrary');
        $libraries->expects($this->never())->method('rescanLibrary');
        $libraries->expects($this->once())
            ->method('clearMetadata')
            ->with('lib-cm', $this->isType('callable'))
            ->willReturnCallback(static function (string $lib, ?callable $onProgress = null): int {
                if ($onProgress !== null) {
                    $onProgress(3, 8);
                }
                return 8;
            });

        $worker = new LibraryScanWorker($jobs, $libraries, $this->makeUnusedMatcher(), $this->makeLogger());

        $this->assertTrue($worker->runOnce());
    }

    /**
     * runOnce() with a `clear_artwork` job runs clearArtwork and forwards its
     * (processed, total) progress onto the job row, then marks completed.
     */
    public function testRunOnceProcessesClearArtworkJob(): void
    {
        $jobs = $this->createMock(ScanJobRepository::class);
        $jobs->expects($this->once())
            ->method('claimNext')
            ->willReturn(['id' => 'job-ca', 'library_id' => 'lib-ca', 'type' => 'clear_artwork']);
        $jobs->expects($this->once())->method('markCompleted')->with('job-ca');
        $jobs->expects($this->never())->method('markFailed');
        $jobs->expects($this->once())
            ->method('updateProgress')
            ->with('job-ca', ['items_found' => 5, 'items_updated' => 5]);

        $libraries = $this->createMock(LibraryManager::class);
        $libraries->expects($this->never())->method('scanLibrary');
        $libraries->expects($this->never())->method('rescanLibrary');
        $libraries->expects($this->once())
            ->method('clearArtwork')
            ->with('lib-ca', $this->isType('callable'))
            ->willReturnCallback(static function (string $lib, ?callable $onProgress = null): int {
                if ($onProgress !== null) {
                    $onProgress(5, 5);
                }
                return 5;
            });

        $worker = new LibraryScanWorker($jobs, $libraries, $this->makeUnusedMatcher(), $this->makeLogger());

        $this->assertTrue($worker->runOnce());
    }

    /**
     * runOnce() with a `delete_all` job runs deleteAllItems (destructive), records
     * the removed count on the job row, and marks completed.
     */
    public function testRunOnceProcessesDeleteAllJob(): void
    {
        $jobs = $this->createMock(ScanJobRepository::class);
        $jobs->expects($this->once())
            ->method('claimNext')
            ->willReturn(['id' => 'job-da', 'library_id' => 'lib-da', 'type' => 'delete_all']);
        $jobs->expects($this->once())->method('markCompleted')->with('job-da');
        $jobs->expects($this->never())->method('markFailed');
        $jobs->expects($this->once())
            ->method('updateProgress')
            ->with('job-da', ['items_removed' => 12]);

        $libraries = $this->createMock(LibraryManager::class);
        $libraries->expects($this->once())->method('deleteAllItems')->with('lib-da')->willReturn(12);
        $libraries->expects($this->never())->method('scanLibrary');
        $libraries->expects($this->never())->method('rescanLibrary');

        $worker = new LibraryScanWorker($jobs, $libraries, $this->makeUnusedMatcher(), $this->makeLogger());

        $this->assertTrue($worker->runOnce());
    }

    /**
     * runOnce() where a maintenance op throws: marks the job failed, does NOT
     * mark completed, still returns true (a job was processed — it failed).
     */
    public function testRunOnceMarksFailedWhenMaintenanceOpThrows(): void
    {
        $jobs = $this->createMock(ScanJobRepository::class);
        $jobs->expects($this->once())
            ->method('claimNext')
            ->willReturn(['id' => 'job-x', 'library_id' => 'lib-x', 'type' => 'delete_all']);
        $jobs->expects($this->never())->method('markCompleted');
        $jobs->expects($this->once())->method('markFailed')->with('job-x', 'kaboom');

        $libraries = $this->createMock(LibraryManager::class);
        $libraries->expects($this->once())
            ->method('deleteAllItems')
            ->with('lib-x')
            ->willThrowException(new RuntimeException('kaboom'));

        $worker = new LibraryScanWorker($jobs, $libraries, $this->makeUnusedMatcher(), $this->makeLogger());

        $this->assertTrue($worker->runOnce());
    }

    /**
     * runOnce() with a `metadata_refresh` job ENABLES force refresh on the matcher
     * (so already-matched items are re-processed) and then runs the same
     * matchLibrary() path — NOT the scan engine.
     */
    public function testRunOnceMetadataRefreshJobEnablesForceRefresh(): void
    {
        $jobs = $this->createMock(ScanJobRepository::class);
        $jobs->expects($this->once())
            ->method('claimNext')
            ->willReturn(['id' => 'job-r', 'library_id' => 'lib-r', 'type' => 'metadata_refresh']);
        $jobs->expects($this->once())->method('markCompleted')->with('job-r');
        $jobs->expects($this->never())->method('markFailed');

        $libraries = $this->createMock(LibraryManager::class);
        $libraries->expects($this->never())->method('scanLibrary');
        $libraries->expects($this->never())->method('rescanLibrary');

        $matcher = $this->createMock(LibraryMetadataMatcher::class);
        $matcher->expects($this->once())->method('setForceRefresh')->with(true);
        $matcher->expects($this->once())->method('matchLibrary')->with('lib-r')
            ->willReturn(['matched' => 7, 'processed' => 7]);

        $worker = new LibraryScanWorker($jobs, $libraries, $matcher, $this->makeLogger());

        $this->assertTrue($worker->runOnce());
    }

    /**
     * start() reaps orphaned `running` jobs BEFORE arming the poll timer, so a
     * scan interrupted by a restart/crash cannot leave the UI spinner alive
     * forever (the music-scan-hang incident).
     *
     * `Timer::add()` refuses to schedule (and throws) unless at least one
     * Workerman worker is registered, so we seed one via reflection — mirroring
     * {@see \Phlix\Tests\Unit\Server\Core\ApplicationBackupTimerTest}.
     */
    public function testStartReapsStaleJobsBeforeArmingTimer(): void
    {
        $workersProp = new \ReflectionProperty(Worker::class, 'workers');
        $workersProp->setAccessible(true);
        /** @var array<int, Worker> $saved */
        $saved = $workersProp->getValue();

        $stub = new Worker();
        $workersProp->setValue(null, [spl_object_id($stub) => $stub]);
        Timer::delAll();

        try {
            $jobs = $this->createMock(ScanJobRepository::class);
            $jobs->expects($this->once())
                ->method('reapStaleJobs')
                ->with('Interrupted by server restart')
                ->willReturn(2);
            $jobs->expects($this->never())->method('claimNext');

            $worker = new LibraryScanWorker(
                $jobs,
                $this->createMock(LibraryManager::class),
                $this->makeUnusedMatcher(),
                $this->makeLogger(),
            );

            $worker->start(5);
        } finally {
            Timer::delAll();
            $workersProp->setValue(null, $saved);
        }
    }

    /**
     * A reaper failure at startup must NOT stop the worker from arming its poll
     * timer (it is caught and logged).
     */
    public function testStartStillArmsTimerWhenReaperThrows(): void
    {
        $workersProp = new \ReflectionProperty(Worker::class, 'workers');
        $workersProp->setAccessible(true);
        /** @var array<int, Worker> $saved */
        $saved = $workersProp->getValue();

        $stub = new Worker();
        $workersProp->setValue(null, [spl_object_id($stub) => $stub]);
        Timer::delAll();

        try {
            $jobs = $this->createMock(ScanJobRepository::class);
            $jobs->expects($this->once())
                ->method('reapStaleJobs')
                ->willThrowException(new RuntimeException('db down'));

            $worker = new LibraryScanWorker(
                $jobs,
                $this->createMock(LibraryManager::class),
                $this->makeUnusedMatcher(),
                $this->makeLogger(),
            );

            // Must not throw despite the reaper error.
            $worker->start(5);

            $tasksProp = new \ReflectionProperty(Timer::class, 'tasks');
            $tasksProp->setAccessible(true);
            /** @var array<int, mixed> $tasks */
            $tasks = $tasksProp->getValue();
            $this->assertNotEmpty($tasks, 'poll timer should still be armed');
        } finally {
            Timer::delAll();
            $workersProp->setValue(null, $saved);
        }
    }
}
