<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library;

use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Library\LibraryScanWorker;
use Phlix\Media\Library\ScanJobRepository;
use Phlix\Media\MediaAsset\MediaAssetBackfill;
use Phlix\Media\MediaAsset\MediaAssetBackfillResult;
use Phlix\Media\Metadata\LibraryMetadataMatcher;
use PHPUnit\Framework\TestCase;

/**
 * S284 — the `media_assets` branch of {@see LibraryScanWorker::runOnce()}.
 *
 * ## The branch this file exists to pin
 *
 * `runOnce()` is a chain of `elseif`s ending in an `else` that runs a FULL
 * LIBRARY SCAN. That shape makes a mis-spelled or missing branch dangerous in a
 * specific way: the job does not fail, it silently becomes the single most
 * expensive operation in the product (a production music rescan ran 9 h 55 m).
 * So every test here asserts what the worker did NOT do — `scanLibrary()` must
 * never be reached — alongside what it did.
 *
 * The dependency is nullable because PHP-DI's `autowire()` skips defaulted ctor
 * params, and the estate has been bitten by nullable deps silently resolving to
 * null. `testANullBackfillFailsTheJobInsteadOfFallingThroughToAFullScan()` is the
 * net under that: absent wiring must be loud.
 */
final class LibraryScanWorkerMediaAssetsJobTest extends TestCase
{
    /** A matcher that must never be used by a `media_assets` job. */
    private function unusedMatcher(): LibraryMetadataMatcher
    {
        $matcher = $this->createMock(LibraryMetadataMatcher::class);
        $matcher->expects($this->never())->method('matchLibrary');

        return $matcher;
    }

    /**
     * A LibraryManager that must never be asked to scan, rescan, prune, clear or
     * delete anything. This is the assertion that separates "the branch ran" from
     * "the branch fell through to the expensive default".
     */
    private function untouchedLibraries(): LibraryManager
    {
        $libraries = $this->createMock(LibraryManager::class);
        $libraries->expects($this->never())->method('scanLibrary');
        $libraries->expects($this->never())->method('rescanLibrary');
        $libraries->expects($this->never())->method('pruneLibrary');
        $libraries->expects($this->never())->method('clearMetadata');
        $libraries->expects($this->never())->method('clearArtwork');
        $libraries->expects($this->never())->method('deleteAllItems');

        return $libraries;
    }

    public function testAMediaAssetsJobRunsTheBackfillAndStampsItsCountersOnTheRow(): void
    {
        $jobs = $this->createMock(ScanJobRepository::class);
        $jobs->expects($this->once())->method('claimNext')->willReturn([
            'id' => 'job-1',
            'library_id' => 'lib-1',
            'type' => 'media_assets',
        ]);
        $jobs->expects($this->never())->method('markFailed');
        $jobs->expects($this->once())->method('markCompleted')->with(
            'job-1',
            [
                'items_found' => 9,
                'items_updated' => 9,
                'items_added' => 4,
            ]
        );

        $backfill = $this->createMock(MediaAssetBackfill::class);
        $backfill->expects($this->once())
            ->method('reenqueueLibrary')
            ->with('lib-1', $this->isType('callable'))
            ->willReturn(new MediaAssetBackfillResult(9, 4, 2, 1, 1, 1));

        $worker = new LibraryScanWorker(
            $jobs,
            $this->untouchedLibraries(),
            $this->unusedMatcher(),
            $this->createMock(StructuredLogger::class),
            $backfill,
        );

        $this->assertTrue($worker->runOnce());
    }

    /**
     * The progress sink handed to the backfill must write the SAME
     * `items_found`/`items_updated` shape the other library-wide jobs write, or
     * `GET /api/v1/libraries/{id}/scan-status` renders no percentage for this job
     * type while happily rendering one for its siblings.
     */
    public function testTheProgressSinkWritesTheScanStatusPercentageShape(): void
    {
        $progressWrites = [];

        /**
         * What the backfill saw, RECORDED rather than asserted — see the note on the
         * closure below. Its initial value is the "never called at all" case, so the
         * three outcomes are distinguishable in the failure message.
         */
        $sinkObserved = 'the backfill was never called';

        $jobs = $this->createMock(ScanJobRepository::class);
        $jobs->method('claimNext')->willReturn([
            'id' => 'job-1',
            'library_id' => 'lib-1',
            'type' => 'media_assets',
        ]);
        $jobs->method('updateProgress')->willReturnCallback(
            static function (string $jobId, array $counts) use (&$progressWrites): void {
                $progressWrites[] = [$jobId, $counts];
            }
        );

        $backfill = $this->createMock(MediaAssetBackfill::class);
        $backfill->method('reenqueueLibrary')->willReturnCallback(
            // ⚠ NOTHING IN THIS CLOSURE MAY ASSERT, and the reason is specific to
            // this seam rather than a general style rule. The closure runs INSIDE
            // `LibraryScanWorker::runOnce()`'s `try`, whose `catch (Throwable $e)`
            // turns anything thrown here into `markFailed()` — including PHPUnit's
            // own `ExpectationFailedException`. S180's prober measured it: a
            // tripwire planted on the `self::assertNotNull($onProgress)` that used
            // to sit here took the test red on the `assertSame($progressWrites)`
            // below instead, and its own message never surfaced (verdict DEGRADED).
            // The assertion could not fail its own test, and its failure would have
            // been reported as an unrelated array diff. RECORD here, assert after
            // `runOnce()` has returned.
            static function (string $libraryId, ?callable $onProgress) use (&$sinkObserved): MediaAssetBackfillResult {
                $sinkObserved = $onProgress === null
                    ? 'the backfill was called with a NULL progress sink'
                    : 'the backfill was called with a progress sink';

                if ($onProgress !== null) {
                    $onProgress(1, 2);
                    $onProgress(2, 2);
                }

                return new MediaAssetBackfillResult(2, 2);
            }
        );

        $worker = new LibraryScanWorker(
            $jobs,
            $this->untouchedLibraries(),
            $this->unusedMatcher(),
            $this->createMock(StructuredLogger::class),
            $backfill,
        );
        $worker->runOnce();

        // Both recorded inside callbacks, both asserted HERE — outside them, after
        // the code under test has returned, where a failure decides the test.
        $this->assertSame(
            'the backfill was called with a progress sink',
            $sinkObserved,
            'the worker must hand the backfill a progress sink; without one the job '
            . 'runs blind and scan-status shows no percentage for `media_assets`'
        );
        $this->assertSame(
            [
                ['job-1', ['items_found' => 2, 'items_updated' => 1]],
                ['job-1', ['items_found' => 2, 'items_updated' => 2]],
            ],
            $progressWrites
        );
    }

    public function testANullBackfillFailsTheJobInsteadOfFallingThroughToAFullScan(): void
    {
        $failures = [];

        $jobs = $this->createMock(ScanJobRepository::class);
        $jobs->method('claimNext')->willReturn([
            'id' => 'job-1',
            'library_id' => 'lib-1',
            'type' => 'media_assets',
        ]);
        $jobs->expects($this->never())->method('markCompleted');
        $jobs->method('markFailed')->willReturnCallback(
            static function (string $jobId, string $error) use (&$failures): void {
                $failures[] = [$jobId, $error];
            }
        );

        $worker = new LibraryScanWorker(
            $jobs,
            $this->untouchedLibraries(),
            $this->unusedMatcher(),
            $this->createMock(StructuredLogger::class),
            null,
        );

        $this->assertTrue($worker->runOnce());
        $this->assertCount(1, $failures);
        $this->assertSame('job-1', $failures[0][0]);
        $this->assertStringContainsString('MediaAssetBackfill', $failures[0][1]);
    }

    /**
     * The CONTROL beside the two tests above: an unrelated job type must still
     * reach the default scan branch, so "scanLibrary was never called" cannot be
     * explained by a worker that has stopped scanning altogether.
     */
    public function testAPlainScanJobStillReachesTheScanBranch(): void
    {
        $jobs = $this->createMock(ScanJobRepository::class);
        $jobs->method('claimNext')->willReturn([
            'id' => 'job-2',
            'library_id' => 'lib-1',
            'type' => 'scan',
        ]);
        $jobs->expects($this->once())->method('markCompleted');

        $libraries = $this->createMock(LibraryManager::class);
        $libraries->expects($this->once())
            ->method('scanLibrary')
            ->willReturn(new \Phlix\Media\Library\ScanResult());

        $backfill = $this->createMock(MediaAssetBackfill::class);
        $backfill->expects($this->never())->method('reenqueueLibrary');

        $worker = new LibraryScanWorker(
            $jobs,
            $libraries,
            $this->unusedMatcher(),
            $this->createMock(StructuredLogger::class),
            $backfill,
        );

        $this->assertTrue($worker->runOnce());
    }
}
