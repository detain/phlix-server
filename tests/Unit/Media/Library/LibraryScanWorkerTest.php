<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library;

use PHPUnit\Framework\TestCase;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Library\LibraryScanWorker;
use Phlix\Media\Library\ScanJobRepository;
use RuntimeException;

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
        $libraries->expects($this->once())->method('scanLibrary')->with('lib-1');
        $libraries->expects($this->never())->method('rescanLibrary');

        $worker = new LibraryScanWorker($jobs, $libraries, $this->makeLogger());

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
        $libraries->expects($this->once())->method('rescanLibrary')->with('lib-2');
        $libraries->expects($this->never())->method('scanLibrary');

        $worker = new LibraryScanWorker($jobs, $libraries, $this->makeLogger());

        $this->assertTrue($worker->runOnce());
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

        $worker = new LibraryScanWorker($jobs, $libraries, $this->makeLogger());

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
            ->with('lib-3')
            ->willThrowException(new RuntimeException('disk gone'));

        $worker = new LibraryScanWorker($jobs, $libraries, $this->makeLogger());

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

        $worker = new LibraryScanWorker($jobs, $libraries, $this->makeLogger());

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

        $worker = new LibraryScanWorker($jobs, $libraries, $this->makeLogger());

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

        $worker = new LibraryScanWorker($jobs, $libraries, $this->makeLogger());

        $this->assertTrue($worker->runOnce());
    }
}
