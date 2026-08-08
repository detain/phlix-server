<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\MediaAsset;

use Phlix\Media\Library\ItemRepository;
use Phlix\Media\MediaAsset\MediaAssetBackfill;
use Phlix\Media\MediaAsset\MediaAssetJobStore;
use Phlix\Media\Streaming\Trickplay\BifWriter;
use Phlix\Media\Transcoding\FfmpegRunner;
use PHPUnit\Framework\TestCase;

/**
 * S284 — the re-enqueue pass that gives an already-scanned library a way to
 * acquire trickplay artefacts.
 *
 * ## What is being defended
 *
 * The media-asset queue is a FILE queue whose only producer is the scanner, so
 * an install scanned before S275 fixed the trickplay producer holds no
 * `sprite.jpg`, no `timeline.json` and no `thumbs.bif`, forever. This class is
 * the targeted re-prime. Its two failure modes are opposite and both silent:
 * enqueue too little (the backfill "succeeds" and nothing is generated) or
 * enqueue too much (every run re-runs ffmpeg over the whole library).
 *
 * So the queue is a REAL {@see MediaAssetJobStore} over a temp directory, never a
 * mock: the idempotency claim is "there are still N job FILES", and a mock of the
 * store could only ever report the calls the test itself made. Only
 * {@see ItemRepository} is doubled, because a library's rows are the input.
 */
final class MediaAssetBackfillTest extends TestCase
{
    private string $root = '';

    /** Where the producer writes artefacts; also the FfmpegRunner transcode dir. */
    private string $transcodeDir = '';

    /** Where the file queue lives. */
    private string $queueDir = '';

    /** Where the fake media files live. */
    private string $mediaDir = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/phlix_s284_' . uniqid();
        $this->transcodeDir = $this->root . '/transcodes';
        $this->queueDir = $this->root . '/queue';
        $this->mediaDir = $this->root . '/media';
        foreach ([$this->transcodeDir, $this->queueDir, $this->mediaDir] as $dir) {
            mkdir($dir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            $this->rrmdir($this->root);
        }
    }

    private function rrmdir(string $dir): void
    {
        foreach (array_diff((array) scandir($dir), ['.', '..']) as $entry) {
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Create a file on disk and return a hydrated-row-shaped array for it.
     *
     * @return array<string, mixed>
     */
    private function itemOnDisk(string $id, string $filename, int $durationSeconds = 120): array
    {
        $path = $this->mediaDir . '/' . $filename;
        file_put_contents($path, 'not really a video, but it exists');

        return [
            'id' => $id,
            'path' => $path,
            'metadata' => ['duration_seconds' => $durationSeconds],
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function repositoryReturning(array $rows): ItemRepository
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('countByLibrary')->willReturn(count($rows));
        $items->method('getByLibrary')->willReturnCallback(
            /** @return list<array<string, mixed>> */
            static function (string $libraryId, int $limit, int $offset) use ($rows): array {
                return array_values(array_slice($rows, $offset, $limit));
            }
        );

        return $items;
    }

    private function makeBackfill(ItemRepository $items, MediaAssetJobStore $store): MediaAssetBackfill
    {
        return new MediaAssetBackfill(
            $items,
            $store,
            new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', $this->transcodeDir),
        );
    }

    /** Number of job FILES currently in the queue directory. */
    private function queuedFileCount(): int
    {
        $files = glob($this->queueDir . '/*.job.json');

        return is_array($files) ? count($files) : 0;
    }

    /** Write both artefacts for an item, i.e. mark it genuinely complete. */
    private function writeArtefacts(string $itemId): void
    {
        $dir = $this->transcodeDir . '/trickplay/' . $itemId;
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/' . FfmpegRunner::SPRITE_FILENAME, 'sprite');
        file_put_contents($dir . '/' . BifWriter::FILENAME, 'bif');
    }

    public function testEligibleItemsAreEnqueuedAndTheQueueHoldsOneFileEach(): void
    {
        $rows = [
            $this->itemOnDisk('item-a', 'a.mkv'),
            $this->itemOnDisk('item-b', 'b.mp4'),
            $this->itemOnDisk('item-c', 'c.webm'),
        ];
        $store = new MediaAssetJobStore($this->queueDir);

        $result = $this->makeBackfill($this->repositoryReturning($rows), $store)
            ->reenqueueLibrary('lib-1');

        $this->assertSame(3, $result->scanned);
        $this->assertSame(3, $result->enqueued);
        $this->assertSame(3, $this->queuedFileCount(), 'one job file per enqueued item');
        $this->assertSame(3, $store->queueSize());

        // The job actually carries the row's path and duration, so the worker can
        // do the work — an enqueue that lost either would be a queue of no-ops.
        $job = $store->dequeue();
        $this->assertNotNull($job);
        $this->assertStringEndsWith('.mkv', $job->path);
        $this->assertSame(120, $job->duration);
    }

    /**
     * ⚠ THE CORE IDEMPOTENCY CLAIM, asserted as a COUNT of queue entries.
     *
     * "It did not error the second time" would pass on a backfill that doubled
     * the queue, so the assertion is on the row/file count before and after.
     */
    public function testASecondRunWhileTheQueueIsStillPendingAddsNothing(): void
    {
        $rows = [
            $this->itemOnDisk('item-a', 'a.mkv'),
            $this->itemOnDisk('item-b', 'b.mp4'),
        ];
        $store = new MediaAssetJobStore($this->queueDir);
        $backfill = $this->makeBackfill($this->repositoryReturning($rows), $store);

        $first = $backfill->reenqueueLibrary('lib-1');
        $countAfterFirst = $this->queuedFileCount();

        $second = $backfill->reenqueueLibrary('lib-1');
        $countAfterSecond = $this->queuedFileCount();

        $this->assertSame(2, $first->enqueued);
        $this->assertSame(2, $countAfterFirst);

        $this->assertSame(0, $second->enqueued, 'the second pass must enqueue nothing');
        $this->assertSame(2, $second->alreadyQueued);
        $this->assertSame(
            $countAfterFirst,
            $countAfterSecond,
            'running the re-enqueue twice must not duplicate queue entries'
        );
        $this->assertSame(2, $countAfterSecond);
    }

    /**
     * The other half of idempotency: after the worker has DRAINED the queue and
     * written the artefacts, a third run must not re-run ffmpeg over everything.
     * This is the case the pending-file guard cannot cover, because by then there
     * are no job files left to collide with.
     */
    public function testAnItemWhoseArtefactsExistIsNotReEnqueued(): void
    {
        $rows = [
            $this->itemOnDisk('item-a', 'a.mkv'),
            $this->itemOnDisk('item-b', 'b.mp4'),
        ];
        $store = new MediaAssetJobStore($this->queueDir);
        $backfill = $this->makeBackfill($this->repositoryReturning($rows), $store);

        $backfill->reenqueueLibrary('lib-1');
        // Simulate the worker draining the queue and the producer succeeding.
        $store->clear();
        $this->writeArtefacts('item-a');
        $this->writeArtefacts('item-b');
        $this->assertSame(0, $this->queuedFileCount());

        $again = $backfill->reenqueueLibrary('lib-1');

        $this->assertSame(0, $again->enqueued);
        $this->assertSame(2, $again->alreadyComplete);
        $this->assertSame(0, $this->queuedFileCount(), 'a completed library re-enqueues nothing');
    }

    /**
     * A HALF-written item must be retried, not recorded as done — that is the
     * state a crashed worker leaves behind. Runs beside the both-present case
     * above so "it retried" cannot be explained by a check that never passes.
     */
    public function testAnItemWithOnlyASpriteAndNoBifIsReEnqueued(): void
    {
        $rows = [$this->itemOnDisk('item-a', 'a.mkv')];
        $store = new MediaAssetJobStore($this->queueDir);

        $dir = $this->transcodeDir . '/trickplay/item-a';
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/' . FfmpegRunner::SPRITE_FILENAME, 'sprite');
        // No thumbs.bif.

        $result = $this->makeBackfill($this->repositoryReturning($rows), $store)
            ->reenqueueLibrary('lib-1');

        $this->assertSame(1, $result->enqueued, 'sprite without BIF is incomplete, so it must be retried');
        $this->assertSame(0, $result->alreadyComplete);
    }

    public function testRowsWithoutAUsablePathOrContainerAreCountedIneligible(): void
    {
        $rows = [
            // A series container row: no path at all.
            ['id' => 'series-1', 'path' => null, 'metadata' => []],
            // A music track: real file, wrong container for trickplay.
            $this->itemOnDisk('track-1', 'song.mp3'),
            // A row with no id is unusable even though the path looks fine.
            ['id' => '', 'path' => $this->mediaDir . '/x.mkv', 'metadata' => []],
            $this->itemOnDisk('item-a', 'a.mkv'),
        ];
        $store = new MediaAssetJobStore($this->queueDir);

        $result = $this->makeBackfill($this->repositoryReturning($rows), $store)
            ->reenqueueLibrary('lib-1');

        $this->assertSame(4, $result->scanned);
        $this->assertSame(1, $result->enqueued);
        $this->assertSame(3, $result->ineligible);
        $this->assertSame(1, $this->queuedFileCount());
    }

    public function testARowWhoseFileIsGoneIsSkippedRatherThanQueuedToFail(): void
    {
        $present = $this->itemOnDisk('item-a', 'a.mkv');
        $missing = [
            'id' => 'item-gone',
            'path' => $this->mediaDir . '/deleted.mkv',
            'metadata' => ['duration_seconds' => 60],
        ];
        $store = new MediaAssetJobStore($this->queueDir);

        $result = $this->makeBackfill($this->repositoryReturning([$present, $missing]), $store)
            ->reenqueueLibrary('lib-1');

        $this->assertSame(1, $result->missingFile);
        // The succeeding sibling in the same run: "1 skipped" cannot be explained
        // by a pass that skips everything.
        $this->assertSame(1, $result->enqueued);
        $this->assertSame(1, $this->queuedFileCount());
    }

    /**
     * Every walked row lands in exactly one bucket. A backfill whose buckets do
     * not account for every row it walked has silently dropped items, which is
     * the whole failure class S284 exists to correct.
     */
    public function testTheOutcomeBucketsAreDisjointAndSumToTheRowsWalked(): void
    {
        $complete = $this->itemOnDisk('item-done', 'done.mkv');
        $this->writeArtefacts('item-done');

        $rows = [
            $this->itemOnDisk('item-a', 'a.mkv'),
            $complete,
            ['id' => 'series-1', 'path' => null, 'metadata' => []],
            ['id' => 'item-gone', 'path' => $this->mediaDir . '/gone.mkv', 'metadata' => []],
        ];
        $store = new MediaAssetJobStore($this->queueDir);
        $store->enqueue(new \Phlix\Media\MediaAsset\MediaAssetJob(
            'item-preexisting',
            $this->mediaDir . '/a.mkv',
            10
        ));
        $rows[] = [
            'id' => 'item-preexisting',
            'path' => $this->mediaDir . '/a.mkv',
            'metadata' => [],
        ];

        $result = $this->makeBackfill($this->repositoryReturning($rows), $store)
            ->reenqueueLibrary('lib-1');

        $this->assertSame(5, $result->scanned);
        $this->assertSame(1, $result->enqueued);
        $this->assertSame(1, $result->alreadyComplete);
        $this->assertSame(1, $result->alreadyQueued);
        $this->assertSame(1, $result->missingFile);
        $this->assertSame(1, $result->ineligible);
        $this->assertSame(
            $result->scanned,
            $result->enqueued + $result->alreadyComplete + $result->alreadyQueued
                + $result->missingFile + $result->ineligible,
            'the buckets must partition the rows walked'
        );
    }

    public function testProgressIsReportedOncePerRowWithTheLibraryTotalAsDenominator(): void
    {
        $rows = [
            $this->itemOnDisk('item-a', 'a.mkv'),
            ['id' => 'series-1', 'path' => null, 'metadata' => []],
            $this->itemOnDisk('item-b', 'b.mp4'),
        ];
        $store = new MediaAssetJobStore($this->queueDir);

        /** @var list<array{0: int, 1: int}> $ticks */
        $ticks = [];
        $this->makeBackfill($this->repositoryReturning($rows), $store)->reenqueueLibrary(
            'lib-1',
            static function (int $processed, int $total) use (&$ticks): void {
                $ticks[] = [$processed, $total];
            }
        );

        // Recorded in the callback, asserted OUTSIDE it — an assertion inside a
        // callback can be swallowed by a catch it never sees.
        $this->assertSame([[1, 3], [2, 3], [3, 3]], $ticks);
    }

    /**
     * The pager must ADVANCE and must STOP. A `$offset` that never moves is an
     * infinite loop; one that stops after page one silently backfills 500 items
     * out of 60,000 and reports success.
     */
    public function testItPagesThroughLibrariesLargerThanOnePage(): void
    {
        $rows = [];
        for ($i = 0; $i < 501; $i++) {
            $rows[] = [
                'id' => 'item-' . $i,
                'path' => $this->mediaDir . '/shared.mkv',
                'metadata' => ['duration_seconds' => 30],
            ];
        }
        file_put_contents($this->mediaDir . '/shared.mkv', 'x');

        $offsets = [];
        $items = $this->createMock(ItemRepository::class);
        $items->method('countByLibrary')->willReturn(count($rows));
        $items->method('getByLibrary')->willReturnCallback(
            /** @return list<array<string, mixed>> */
            static function (string $libraryId, int $limit, int $offset) use ($rows, &$offsets): array {
                $offsets[] = $offset;
                return array_values(array_slice($rows, $offset, $limit));
            }
        );

        $store = new MediaAssetJobStore($this->queueDir);
        $result = $this->makeBackfill($items, $store)->reenqueueLibrary('lib-1');

        $this->assertSame([0, 500], $offsets, 'exactly two pages, advancing by the page size');
        $this->assertSame(501, $result->scanned);
        $this->assertSame(501, $result->enqueued);
        $this->assertSame(501, $this->queuedFileCount());
    }
}
