<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Markers\Detection;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Markers\Detection\BackgroundDetectorWorker;
use Phlix\Media\Markers\Detection\IntroDetectionJob;
use Phlix\Media\Markers\Detection\IntroDetectionResult;
use Phlix\Media\Markers\Detection\IntroMarkerCandidate;
use Phlix\Media\Markers\Detection\MarkerCandidateRepository;
use Phlix\Media\Markers\Detection\MarkerCandidateStore;
use Psr\Log\NullLogger;

/**
 * SV-0.7: enqueue → drain smoke coverage for the marker/intro-detection worker.
 *
 * The worker is supervised by start.php (armed via ::start(int) → Timer → runOnce)
 * and consumes MarkerCandidateStore's file-based job queue. These tests point a
 * real MarkerCandidateStore at a temp directory and stub the detection collaborators
 * (IntroDetectionJob / MarkerCandidateRepository) so the dequeue → detect → complete
 * loop is exercised without running real ChromaPrint fingerprinting.
 */
final class BackgroundDetectorWorkerTest extends TestCase
{
    private string $testQueueDir;
    private MarkerCandidateStore $store;

    protected function setUp(): void
    {
        $this->testQueueDir = sys_get_temp_dir() . '/phlix_test_bgworker_' . uniqid();
        $this->store = new MarkerCandidateStore($this->testQueueDir);
    }

    protected function tearDown(): void
    {
        $this->store->clear();

        if (is_dir($this->testQueueDir)) {
            $files = glob($this->testQueueDir . '/*');
            if ($files !== false) {
                foreach ($files as $file) {
                    unlink($file);
                }
            }
            rmdir($this->testQueueDir);
        }
    }

    /**
     * Build a worker around the real temp-dir store with stubbed detection.
     */
    private function makeWorker(IntroDetectionJob $job, MarkerCandidateRepository $repo): BackgroundDetectorWorker
    {
        return new BackgroundDetectorWorker($job, $this->store, $repo, new NullLogger());
    }

    private function resultWithNoMarkers(string $showId): IntroDetectionResult
    {
        return new IntroDetectionResult(
            show_id: $showId,
            episodes_fingerprinted: 0,
            intro_candidate: null,
            outro_candidate: null,
            episodes_processed: [],
        );
    }

    public function testRunOnceDequeuesAndDrainsAQueuedShow(): void
    {
        $this->store->enqueueShow('show-1');
        $this->assertSame(1, $this->store->queueSize(), 'sanity: show enqueued');

        $job = $this->createMock(IntroDetectionJob::class);
        $job->expects($this->once())
            ->method('detectForShow')
            ->with('show-1')
            ->willReturn($this->resultWithNoMarkers('show-1'));

        // No markers → repository must not be touched.
        $repo = $this->createMock(MarkerCandidateRepository::class);
        $repo->expects($this->never())->method('storeCandidates');

        $worker = $this->makeWorker($job, $repo);

        $this->assertTrue($worker->runOnce(), 'runOnce must report it processed a show.');
        $this->assertSame(0, $worker->getPendingCount(), 'the queue must be drained after processing.');
        $this->assertSame(0, $this->store->queueSize());
    }

    public function testRunOnceOnEmptyQueueReturnsFalse(): void
    {
        $job = $this->createMock(IntroDetectionJob::class);
        $job->expects($this->never())->method('detectForShow');

        $repo = $this->createMock(MarkerCandidateRepository::class);
        $repo->expects($this->never())->method('storeCandidates');

        $worker = $this->makeWorker($job, $repo);

        $this->assertFalse($worker->runOnce(), 'runOnce on an empty queue must return false.');
        $this->assertSame(0, $worker->getPendingCount());
    }

    public function testRunOnceStoresCandidatesWhenMarkersDetected(): void
    {
        $this->store->enqueueShow('show-42');

        $result = new IntroDetectionResult(
            show_id: 'show-42',
            episodes_fingerprinted: 3,
            intro_candidate: new IntroMarkerCandidate(0, 90, 'fp1', 85),
            outro_candidate: null,
            episodes_processed: ['ep-1', 'ep-2', 'ep-3'],
        );

        $job = $this->createMock(IntroDetectionJob::class);
        $job->method('detectForShow')->with('show-42')->willReturn($result);

        $repo = $this->createMock(MarkerCandidateRepository::class);
        $repo->expects($this->once())
            ->method('storeCandidates')
            ->with('show-42', $result);

        $worker = $this->makeWorker($job, $repo);

        $this->assertTrue($worker->runOnce());
        $this->assertSame(0, $worker->getPendingCount());
    }

    public function testRunOnceSurvivesDetectorFailureAndAdvancesTheQueue(): void
    {
        // A backlog of two shows; the first show's detection throws.
        $this->store->enqueueShow('bad-show');

        $job = $this->createMock(IntroDetectionJob::class);
        $job->method('detectForShow')->willReturnCallback(
            function (string $showId): IntroDetectionResult {
                if ($showId === 'bad-show') {
                    throw new \RuntimeException('detector blew up');
                }
                return $this->resultWithNoMarkers($showId);
            }
        );

        $repo = $this->createMock(MarkerCandidateRepository::class);
        $repo->expects($this->never())->method('storeCandidates');

        $worker = $this->makeWorker($job, $repo);

        // The throw is caught: the job still counts as "processed" (true) and the
        // failed show is removed so it does not wedge the loop.
        $this->assertTrue($worker->runOnce(), 'a thrown detection must be caught, not wedge the worker.');
        $this->assertSame(0, $worker->getPendingCount(), 'the failed show must be drained, not left stuck.');

        // The loop keeps working: a subsequently enqueued show still drains.
        $this->store->enqueueShow('good-show');
        $this->assertTrue($worker->runOnce());
        $this->assertSame(0, $worker->getPendingCount());
    }
}
