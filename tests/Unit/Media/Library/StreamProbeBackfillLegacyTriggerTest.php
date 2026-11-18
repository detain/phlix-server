<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library;

use Phlix\Media\Library\StreamProbeBackfill;
use Phlix\Media\MediaAsset\MediaAssetJobStore;
use Phlix\Media\MediaAsset\StreamProbeJob;
use Phlix\Media\MediaAsset\StreamProbeScheduler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The legacy trigger for the background full-track-set probe.
 *
 * {@see StreamProbeBackfill::needsFullStreamProbe()} is the single predicate both playback-info
 * paths use, via {@see StreamProbeScheduler::scheduleIfLegacy()}. The scheduler writes a job
 * file only; these tests run it against a real temp-directory queue, and never probe.
 */
final class StreamProbeBackfillLegacyTriggerTest extends TestCase
{
    private string $queueDir = '';

    protected function setUp(): void
    {
        $this->queueDir = sys_get_temp_dir() . '/phlix_stream_trigger_' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->queueDir)) {
            foreach (array_diff((array) scandir($this->queueDir), ['.', '..']) as $entry) {
                @unlink($this->queueDir . '/' . $entry);
            }
            @rmdir($this->queueDir);
        }
    }

    /** @return array<string, mixed> An unprobed item with a path. */
    private function item(): array
    {
        return ['id' => 'ep-1', 'path' => '/media/ep-1.mkv', 'streams_probed_at' => null];
    }

    /** @return list<array<string, mixed>> The legacy shape: one video row with a codec, ONE audio row. */
    private function legacyRows(): array
    {
        return [
            ['stream_type' => 'video', 'codec' => 'h264'],
            ['stream_type' => 'audio', 'codec' => 'aac'],
        ];
    }

    private function store(): MediaAssetJobStore
    {
        return new MediaAssetJobStore($this->queueDir);
    }

    public function testALegacyItemMatchesTheTrigger(): void
    {
        $this->assertTrue(StreamProbeBackfill::needsFullStreamProbe($this->item(), $this->legacyRows()));
    }

    public function testAProbedItemDoesNotMatch(): void
    {
        $item = $this->item();
        $item['streams_probed_at'] = '2026-11-01 00:00:00';

        $this->assertFalse(StreamProbeBackfill::needsFullStreamProbe($item, $this->legacyRows()));
    }

    public function testRowsThatAlreadyHoldASubtitleDoNotMatch(): void
    {
        $rows = [...$this->legacyRows(), ['stream_type' => 'subtitle', 'codec' => 'subrip']];

        $this->assertFalse(StreamProbeBackfill::needsFullStreamProbe($this->item(), $rows));
    }

    public function testRowsThatAlreadyHoldASecondAudioDoNotMatch(): void
    {
        $rows = [...$this->legacyRows(), ['stream_type' => 'audio', 'codec' => 'ac3']];

        $this->assertFalse(StreamProbeBackfill::needsFullStreamProbe($this->item(), $rows));
    }

    /**
     * A video-only gap is the existing video path's repair (ensureVideoCodecFor on the detail
     * endpoint), which replaces the whole set. Playback info must not enqueue a second stream job.
     */
    public function testAVideoOnlyMissingItemUsesTheVideoPathNotTheStreamJob(): void
    {
        $rows = [
            ['stream_type' => 'video', 'codec' => null],
            ['stream_type' => 'audio', 'codec' => 'aac'],
        ];

        $this->assertFalse(StreamProbeBackfill::needsFullStreamProbe($this->item(), $rows));
    }

    public function testAnItemWithoutAnIdOrPathDoesNotMatch(): void
    {
        $noId = $this->item();
        $noId['id'] = '';
        $noPath = $this->item();
        $noPath['path'] = '';

        $this->assertFalse(StreamProbeBackfill::needsFullStreamProbe($noId, $this->legacyRows()));
        $this->assertFalse(StreamProbeBackfill::needsFullStreamProbe($noPath, $this->legacyRows()));
    }

    public function testSchedulerEnqueuesOneStreamJobForALegacyItem(): void
    {
        $store = $this->store();
        $scheduler = new StreamProbeScheduler($store);

        $this->assertTrue($scheduler->scheduleIfLegacy($this->item(), $this->legacyRows()));

        $this->assertSame(1, $store->streamProbeQueueSize());
        $this->assertSame(0, $store->queueSize(), 'the chapter/trickplay queue is untouched');
    }

    /** Enqueueing the same legacy item again is a no-op: the pending job is kept, no second file. */
    public function testSchedulerEnqueueIsIdempotent(): void
    {
        $store = $this->store();
        $scheduler = new StreamProbeScheduler($store);

        $scheduler->scheduleIfLegacy($this->item(), $this->legacyRows());
        $this->assertTrue($scheduler->scheduleIfLegacy($this->item(), $this->legacyRows()));

        $this->assertSame(1, $store->streamProbeQueueSize());
    }

    public function testSchedulerEnqueuesNothingForAProbedItem(): void
    {
        $store = $this->store();
        $scheduler = new StreamProbeScheduler($store);
        $item = $this->item();
        $item['streams_probed_at'] = '2026-11-01 00:00:00';

        $this->assertFalse($scheduler->scheduleIfLegacy($item, $this->legacyRows()));

        $this->assertSame(0, $store->streamProbeQueueSize());
    }

    public function testSchedulerEnqueuesNothingForAVideoOnlyMissingItem(): void
    {
        $store = $this->store();
        $scheduler = new StreamProbeScheduler($store);
        $rows = [
            ['stream_type' => 'video', 'codec' => null],
            ['stream_type' => 'audio', 'codec' => 'aac'],
        ];

        $this->assertFalse($scheduler->scheduleIfLegacy($this->item(), $rows));

        $this->assertSame(0, $store->streamProbeQueueSize());
    }

    /**
     * Finding 3: when the store cannot write the job, scheduleIfLegacy() swallows the failure, logs one
     * warning, and returns false. It does not throw, so the playback-info response is unaffected. A broken
     * catch that rethrows would fail this test with the RuntimeException. The enqueue fails because the
     * stream lock path is a dangling symlink, so the store cannot open the lock. The warning from that open
     * is silenced.
     */
    public function testAFailedEnqueueIsSwallowedAndLogged(): void
    {
        $store = $this->store();
        symlink($this->queueDir . '/no-such-dir/target', $this->queueDir . '/.streams.lock');

        $messages = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->willReturnCallback(
            function (string|\Stringable $message) use (&$messages): void {
                $messages[] = (string) $message;
            }
        );
        $scheduler = new StreamProbeScheduler($store, $logger);

        set_error_handler(static fn (): bool => true);
        try {
            $scheduled = $scheduler->scheduleIfLegacy($this->item(), $this->legacyRows());
        } finally {
            restore_error_handler();
        }

        $this->assertFalse($scheduled, 'a failed write reports not scheduled, without throwing');
        $this->assertStringContainsString('could not be enqueued', $messages[0] ?? '');
    }

    /**
     * Finding 1: when the stream queue lock stays busy, the playback enqueue is dropped without throwing and
     * the warning carries the item id. The dropped enqueue self-heals on the next playback.
     */
    public function testABusyQueueLockIsSwallowedAndLogsTheItemId(): void
    {
        $store = $this->store();
        $store->enqueueStreamProbe(new StreamProbeJob('some-other-item'));
        $holder = fopen($this->queueDir . '/.streams.lock', 'c');
        $this->assertIsResource($holder);
        flock($holder, LOCK_EX);

        $contexts = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->willReturnCallback(
            function (string|\Stringable $message, array $context = []) use (&$contexts): void {
                $contexts[] = $context;
            }
        );
        $scheduler = new StreamProbeScheduler($store, $logger);

        $scheduled = $scheduler->scheduleIfLegacy($this->item(), $this->legacyRows());

        flock($holder, LOCK_UN);
        fclose($holder);

        $this->assertFalse($scheduled, 'a busy lock reports not scheduled, without throwing');
        $this->assertSame('ep-1', $contexts[0]['item_id'] ?? null, 'the log names the dropped item');
        $this->assertSame(1, $store->streamProbeQueueSize(), 'only the job that already held the queue is there');
    }
}
