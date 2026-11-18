<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\MediaAsset;

use Phlix\Media\Library\ItemRepository;
use Phlix\Media\MediaAsset\MediaAssetGenerationJob;
use Phlix\Media\MediaAsset\MediaAssetJobStore;
use Phlix\Media\MediaAsset\MediaAssetWorker;
use Phlix\Media\MediaAsset\StreamProbeJob;
use Phlix\Media\Transcoding\FfmpegRunner;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Workerman\MySQL\Connection;

/**
 * Background full-track-set probe for a legacy item: the processor
 * ({@see MediaAssetGenerationJob::processStreamProbe()}) and the worker branch
 * ({@see MediaAssetWorker}) that runs it.
 *
 * Doubles only: FfmpegRunner and ItemRepository are mocked, so no ffmpeg runs and no
 * database is touched. The queue is a real {@see MediaAssetJobStore} over a temp directory,
 * because the store is final and its file behaviour is part of what is under test.
 *
 * Guarantees pinned here:
 * - success writes the whole set through replaceStreams() and only then stamps streams_probed_at;
 * - a failed run stamps nothing and leaves the item unprobed;
 * - a failed run is re-queued with one more attempt, and the job is dropped after
 *   StreamProbeJob::MAX_ATTEMPTS runs.
 */
final class MediaAssetStreamProbeWorkerTest extends TestCase
{
    private string $queueDir = '';

    private string $mediaFile = '';

    protected function setUp(): void
    {
        $this->queueDir = sys_get_temp_dir() . '/phlix_stream_probe_worker_' . uniqid();
        $file = tempnam(sys_get_temp_dir(), 'phlix-sp-');
        $this->mediaFile = $file === false ? '' : $file;
        file_put_contents($this->mediaFile, 'not a real media file');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->queueDir)) {
            foreach (array_diff((array) scandir($this->queueDir), ['.', '..']) as $entry) {
                @unlink($this->queueDir . '/' . $entry);
            }
            @rmdir($this->queueDir);
        }
        if ($this->mediaFile !== '' && is_file($this->mediaFile)) {
            @unlink($this->mediaFile);
        }
    }

    /** @return array<string, mixed> A legacy, unprobed item whose file exists. */
    private function legacyItem(): array
    {
        return [
            'id' => 'it1',
            'name' => 'Legacy',
            'type' => 'movie',
            'path' => $this->mediaFile,
            'streams_probed_at' => null,
        ];
    }

    /** @return array<string, mixed> A probe with one video, two audio and one subtitle stream. */
    private function fullProbe(): array
    {
        return [
            'streams' => [
                ['index' => 0, 'codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1920, 'height' => 1080],
                ['index' => 1, 'codec_type' => 'audio', 'codec_name' => 'ac3', 'channels' => 6],
                ['index' => 2, 'codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 2],
                ['index' => 3, 'codec_type' => 'subtitle', 'codec_name' => 'subrip', 'tags' => ['language' => 'eng']],
            ],
            'format' => ['duration' => '120.0'],
        ];
    }

    private function processor(ItemRepository $repo, FfmpegRunner $ffmpeg): MediaAssetGenerationJob
    {
        return new MediaAssetGenerationJob($ffmpeg, $repo, $this->createMock(Connection::class));
    }

    private function store(): MediaAssetJobStore
    {
        return new MediaAssetJobStore($this->queueDir);
    }

    public function testSuccessWritesTheWholeSetThenStampsTheMarker(): void
    {
        $calls = [];
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->expects($this->once())->method('probe')->with($this->mediaFile)->willReturn($this->fullProbe());

        $repo = $this->createMock(ItemRepository::class);
        $repo->method('findById')->with('it1')->willReturn($this->legacyItem());
        $repo->expects($this->once())->method('replaceStreams')->willReturnCallback(
            function (string $itemId, array $rows) use (&$calls): void {
                $calls[] = ['replaceStreams', $itemId, array_column($rows, 'stream_type')];
            }
        );
        $repo->expects($this->once())->method('markStreamsProbed')->willReturnCallback(
            function (string $itemId) use (&$calls): void {
                $calls[] = ['markStreamsProbed', $itemId];
            }
        );

        $this->assertTrue($this->processor($repo, $ffmpeg)->processStreamProbe(new StreamProbeJob('it1')));

        // The rows are written in one replaceStreams() call BEFORE the marker is stamped.
        $this->assertCount(2, $calls);
        $this->assertSame('replaceStreams', $calls[0][0]);
        $this->assertContains('subtitle', $calls[0][2], 'the full set, including the subtitle row, is written');
        $this->assertSame(['markStreamsProbed', 'it1'], $calls[1]);
    }

    public function testProbeFailureWritesNothingAndStampsNothing(): void
    {
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->method('probe')->willReturn(null);

        $repo = $this->createMock(ItemRepository::class);
        $repo->method('findById')->willReturn($this->legacyItem());
        $repo->expects($this->never())->method('replaceStreams');
        $repo->expects($this->never())->method('markStreamsProbed');

        $this->assertFalse($this->processor($repo, $ffmpeg)->processStreamProbe(new StreamProbeJob('it1')));
    }

    public function testMissingFileIsTerminalWithoutProbingOrStamping(): void
    {
        $item = $this->legacyItem();
        $item['path'] = $this->mediaFile . '.gone';

        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->expects($this->never())->method('probe');

        $repo = $this->createMock(ItemRepository::class);
        $repo->method('findById')->willReturn($item);
        $repo->expects($this->never())->method('replaceStreams');
        $repo->expects($this->never())->method('markStreamsProbed');

        // True means the job is done (dropped); a false would retry three times for nothing.
        $this->assertTrue($this->processor($repo, $ffmpeg)->processStreamProbe(new StreamProbeJob('it1')));
    }

    public function testAlreadyProbedItemIsDoneWithoutProbing(): void
    {
        $item = $this->legacyItem();
        $item['streams_probed_at'] = '2026-11-01 00:00:00';

        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->expects($this->never())->method('probe');

        $repo = $this->createMock(ItemRepository::class);
        $repo->method('findById')->willReturn($item);
        $repo->expects($this->never())->method('replaceStreams');

        $this->assertTrue($this->processor($repo, $ffmpeg)->processStreamProbe(new StreamProbeJob('it1')));
    }

    public function testVanishedItemIsDroppedWithoutProbing(): void
    {
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->expects($this->never())->method('probe');

        $repo = $this->createMock(ItemRepository::class);
        $repo->method('findById')->willReturn(null);

        $this->assertTrue($this->processor($repo, $ffmpeg)->processStreamProbe(new StreamProbeJob('it1')));
    }

    public function testAFailedStreamWriteIsRethrownAndStampsNothing(): void
    {
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->method('probe')->willReturn($this->fullProbe());

        $repo = $this->createMock(ItemRepository::class);
        $repo->method('findById')->willReturn($this->legacyItem());
        $repo->method('replaceStreams')->willThrowException(new \RuntimeException('Deadlock found'));
        $repo->expects($this->never())->method('markStreamsProbed');

        $this->expectException(\RuntimeException::class);
        $this->processor($repo, $ffmpeg)->processStreamProbe(new StreamProbeJob('it1'));
    }

    public function testWorkerDrainsASuccessfulStreamJobAndStampsTheItem(): void
    {
        $store = $this->store();
        $store->enqueueStreamProbe(new StreamProbeJob('it1'));

        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->method('probe')->willReturn($this->fullProbe());

        $repo = $this->createMock(ItemRepository::class);
        $repo->method('findById')->willReturn($this->legacyItem());
        $repo->expects($this->once())->method('replaceStreams');
        $repo->expects($this->once())->method('markStreamsProbed')->with('it1');

        $worker = new MediaAssetWorker($store, $this->processor($repo, $ffmpeg));

        $this->assertSame(1, $worker->runOnce());
        $this->assertSame(0, $store->streamProbeQueueSize(), 'a successful job is not re-queued');
    }

    /**
     * A probe that never succeeds is attempted exactly MAX_ATTEMPTS times, then dropped. The item
     * stays unprobed throughout: no rows written, no marker stamped.
     */
    public function testWorkerRetriesAFailedProbeUpToTheBoundThenDrops(): void
    {
        $store = $this->store();
        $store->enqueueStreamProbe(new StreamProbeJob('it1'));

        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->expects($this->exactly(StreamProbeJob::MAX_ATTEMPTS))->method('probe')->willReturn(null);

        $repo = $this->createMock(ItemRepository::class);
        $repo->method('findById')->willReturn($this->legacyItem());
        $repo->expects($this->never())->method('replaceStreams');
        $repo->expects($this->never())->method('markStreamsProbed');

        $worker = new MediaAssetWorker($store, $this->processor($repo, $ffmpeg));

        for ($run = 1; $run < StreamProbeJob::MAX_ATTEMPTS; $run++) {
            $this->assertSame(1, $worker->runOnce(), "run $run processes the job");
            $this->assertSame(1, $store->streamProbeQueueSize(), "run $run re-queues the failed job");
        }

        $this->assertSame(1, $worker->runOnce(), 'the final run processes the job');
        $this->assertSame(0, $store->streamProbeQueueSize(), 'the job is dropped after MAX_ATTEMPTS runs');
        $this->assertSame(0, $worker->runOnce(), 'nothing is left to run');
    }

    /** A failed write re-queues the job with one more attempt, and leaves the item unprobed. */
    public function testAFailedWriteIsRequeuedWithTheAttemptCount(): void
    {
        $store = $this->store();
        $store->enqueueStreamProbe(new StreamProbeJob('it1'));

        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->method('probe')->willReturn($this->fullProbe());

        $repo = $this->createMock(ItemRepository::class);
        $repo->method('findById')->willReturn($this->legacyItem());
        $repo->method('replaceStreams')->willThrowException(new \RuntimeException('Lock wait timeout'));
        $repo->expects($this->never())->method('markStreamsProbed');

        $worker = new MediaAssetWorker($store, $this->processor($repo, $ffmpeg));

        $this->assertSame(1, $worker->runOnce());

        $requeued = $store->dequeueStreamProbe();
        $this->assertInstanceOf(StreamProbeJob::class, $requeued);
        $this->assertSame(1, $requeued->attempts);
    }

    /**
     * Finding 7: a probe that reads no streams is a completed probe. Nothing is replaced, the rows
     * stored for the item are left as they are, and the marker is stamped so the item is not retried.
     * This pins what the code does. Whether an empty probe should be terminal is an owner decision.
     */
    public function testAnEmptyStreamSetStampsTheMarkerAndReplacesNothing(): void
    {
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->expects($this->once())->method('probe')
            ->willReturn(['streams' => [], 'format' => ['duration' => '120.0']]);

        $repo = $this->createMock(ItemRepository::class);
        $repo->method('findById')->willReturn($this->legacyItem());
        $repo->expects($this->never())->method('replaceStreams');
        $repo->expects($this->once())->method('markStreamsProbed')->with('it1');

        $this->assertTrue($this->processor($repo, $ffmpeg)->processStreamProbe(new StreamProbeJob('it1')));
    }

    /**
     * Finding 4: a corrupt head file is removed and skipped, and the batch still runs the next job.
     */
    public function testACorruptHeadFileDoesNotStopTheBatch(): void
    {
        $store = $this->store();
        // Stamp 1 sorts before any real enqueue stamp, so the corrupt file is the head of the queue.
        $corrupt = $this->queueDir . '/' . sprintf('%020d-item-bad.streams.job.json', 1);
        file_put_contents($corrupt, "not json\n1.0\n");
        $store->enqueueStreamProbe(new StreamProbeJob('it1'));

        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->method('probe')->willReturn($this->fullProbe());

        $repo = $this->createMock(ItemRepository::class);
        $repo->method('findById')->willReturn($this->legacyItem());
        $repo->expects($this->once())->method('markStreamsProbed')->with('it1');

        $worker = new MediaAssetWorker($store, $this->processor($repo, $ffmpeg));

        $this->assertSame(1, $worker->runOnce(), 'the valid job behind the corrupt head is processed');
        $this->assertFileDoesNotExist($corrupt);
        $this->assertSame(0, $store->streamProbeQueueSize());
    }

    /**
     * Finding 2: a re-queue that cannot be written is logged and the job is dropped. It does not throw
     * out of runOnce(), and the next job in the same batch still runs. The failed write is a dangling
     * symlink placed at the stream lock path during the first probe, after its file has been dequeued.
     * The re-queue must take that lock, so it fails there.
     */
    public function testAFailedRequeueIsLoggedAndTheBatchContinues(): void
    {
        $store = $this->store();
        $store->enqueueStreamProbe(new StreamProbeJob('it1'));
        $store->enqueueStreamProbe(new StreamProbeJob('it2'));
        $brokenPath = $this->queueDir . '/.streams.lock';

        $probeCalls = 0;
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->method('probe')->willReturnCallback(
            function () use (&$probeCalls, $brokenPath) {
                $probeCalls++;
                if ($probeCalls === 1) {
                    // The seeding enqueues already created the real lock file, so remove it before planting the link.
                    @unlink($brokenPath);
                    symlink($this->queueDir . '/no-such-dir/target', $brokenPath);

                    return null;
                }

                return $this->fullProbe();
            }
        );

        $repo = $this->createMock(ItemRepository::class);
        $repo->method('findById')->willReturnCallback(
            fn (string $id): array => array_merge($this->legacyItem(), ['id' => $id])
        );
        $repo->expects($this->once())->method('replaceStreams');
        $repo->expects($this->once())->method('markStreamsProbed')->with('it2');

        $warnings = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(
            function (string|\Stringable $message) use (&$warnings): void {
                $warnings[] = (string) $message;
            }
        );

        $worker = new MediaAssetWorker($store, $this->processor($repo, $ffmpeg), $logger);

        set_error_handler(static fn (): bool => true);
        try {
            $processed = $worker->runOnce();
        } finally {
            restore_error_handler();
        }

        $this->assertSame(2, $processed, 'both jobs in the batch were processed');
        $failedRequeue = array_filter(
            $warnings,
            static fn (string $w): bool => str_contains($w, 're-queue FAILED') && str_contains($w, 'itemId=it1')
        );
        $this->assertCount(1, $failedRequeue, 'the failed re-queue is logged with the item id');
    }

    /**
     * Finding 5: a playback enqueue lands while the probe runs and writes a first-attempt file. The failed
     * run's re-queue then overwrites it with its own count, so the count is not reset to 0.
     */
    public function testARetryKeepsItsCountWhenAPlaybackEnqueueRacesTheRun(): void
    {
        $store = $this->store();
        $store->enqueueStreamProbe(new StreamProbeJob('it1'));

        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->method('probe')->willReturnCallback(
            function () use ($store) {
                $store->enqueueStreamProbe(new StreamProbeJob('it1'));

                return null;
            }
        );

        $repo = $this->createMock(ItemRepository::class);
        $repo->method('findById')->willReturn($this->legacyItem());
        $repo->expects($this->never())->method('replaceStreams');
        $repo->expects($this->never())->method('markStreamsProbed');

        $worker = new MediaAssetWorker($store, $this->processor($repo, $ffmpeg));

        $this->assertSame(1, $worker->runOnce());

        $requeued = $store->dequeueStreamProbe();
        $this->assertInstanceOf(StreamProbeJob::class, $requeued);
        $this->assertSame(1, $requeued->attempts, 'the retry keeps its count when a playback enqueue raced it');
    }

    /**
     * Finding 1: a retry whose re-queue hits a busy queue lock is kept pending, not dropped. The lock is held
     * by a separate handle for the first run, so the re-queue fails with the busy code. On the next tick the
     * deferred retry is re-queued first with its incremented count (1), runs, and fails again. Its re-queue
     * then carries count 2. A reset to 0 on deferral would leave count 1 here, and the assertions fail.
     * The third tick runs the job with count 2, and that probe succeeds.
     */
    public function testABusyLockOnARetryKeepsTheJobPendingForTheNextTick(): void
    {
        $store = $this->store();
        $store->enqueueStreamProbe(new StreamProbeJob('it1'));

        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->expects($this->exactly(3))->method('probe')
            ->willReturnOnConsecutiveCalls(null, null, $this->fullProbe());

        $repo = $this->createMock(ItemRepository::class);
        $repo->method('findById')->willReturn($this->legacyItem());
        $repo->expects($this->once())->method('replaceStreams');
        $repo->expects($this->once())->method('markStreamsProbed')->with('it1');

        $worker = new MediaAssetWorker($store, $this->processor($repo, $ffmpeg));

        $holder = fopen($this->queueDir . '/.streams.lock', 'c');
        $this->assertIsResource($holder);
        flock($holder, LOCK_EX);

        $this->assertSame(1, $worker->runOnce(), 'the first run dequeues the job and its probe fails');
        $this->assertSame(0, $store->streamProbeQueueSize(), 'the busy retry is not written while the lock is held');

        flock($holder, LOCK_UN);
        fclose($holder);

        $this->assertSame(1, $worker->runOnce(), 'the next tick re-queues the deferred retry and runs it');
        $this->assertSame(1, $store->streamProbeQueueSize(), 'the second run failed too, so it is re-queued');
        $this->assertSame([2], $this->pendingAttemptCounts(), 'the re-queued job keeps its incremented count');

        $this->assertSame(1, $worker->runOnce(), 'the third tick runs the job with its count');
        $this->assertSame(0, $store->streamProbeQueueSize(), 'the third run succeeded and nothing is left');
    }

    /**
     * The attempt counts of the stream-probe files in the queue directory. Reads the files without consuming them.
     *
     * @return list<int>
     */
    private function pendingAttemptCounts(): array
    {
        $counts = [];
        foreach (glob($this->queueDir . '/*.streams.job.json') ?: [] as $file) {
            $lines = explode("\n", (string) file_get_contents($file));
            /** @var array{attempts: int} $data */
            $data = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
            $counts[] = $data['attempts'];
        }

        return $counts;
    }
}
