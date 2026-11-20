<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\MediaAsset;

use Phlix\Media\MediaAsset\MediaAssetJob;
use Phlix\Media\MediaAsset\MediaAssetJobStore;
use Phlix\Media\MediaAsset\StreamProbeJob;
use PHPUnit\Framework\TestCase;

/**
 * S284 — {@see MediaAssetJobStore}'s own de-duplication guard.
 *
 * ## Why this file was written
 *
 * The store's `enqueue()` has documented itself as "Idempotent — if the item is
 * already enqueued, this is a no-op" since SV-1.3, and **nothing in the tree
 * exercised that claim**: no test file for the class existed at all. S284 found
 * it by mutation — deleting the `file_exists()` early return left every
 * media-asset test green, because the caller S284 added has a guard of its own.
 *
 * That is the shape worth being explicit about: the store is the LAST line of the
 * re-enqueue's idempotency (layer 3 of 3 — row, then queue file, then artefact),
 * and a last line nothing tests is a line that can be deleted for free. These
 * tests assert on the queue's observable size, not on a return value, because
 * `enqueue()` returns nothing either way.
 *
 * The stream-probe kind is named `<20-digit-microseconds>-<itemId>.streams.job.json`, so the
 * fixtures below write names at explicit stamps to control queue position.
 */
final class MediaAssetJobStoreIdempotencyTest extends TestCase
{
    private string $queueDir = '';

    protected function setUp(): void
    {
        $this->queueDir = sys_get_temp_dir() . '/phlix_s284_store_' . uniqid();
    }

    protected function tearDown(): void
    {
        if ($this->queueDir === '' || !is_dir($this->queueDir)) {
            return;
        }
        foreach (array_diff((array) scandir($this->queueDir), ['.', '..']) as $entry) {
            @unlink($this->queueDir . '/' . $entry);
        }
        @rmdir($this->queueDir);
    }

    private function fileCount(): int
    {
        $files = glob($this->queueDir . '/*.job.json');

        return is_array($files) ? count($files) : 0;
    }

    public function testEnqueueingTheSameItemTwiceLeavesOneJobFile(): void
    {
        $store = new MediaAssetJobStore($this->queueDir);

        $store->enqueue(new MediaAssetJob('item-a', '/media/a.mkv', 120));
        $this->assertSame(1, $this->fileCount());
        $this->assertSame(1, $store->queueSize());

        $store->enqueue(new MediaAssetJob('item-a', '/media/a.mkv', 120));

        $this->assertSame(1, $this->fileCount(), 'a repeat enqueue must not add a second job file');
        $this->assertSame(1, $store->queueSize());
    }

    /**
     * The CONTROL beside it: a DIFFERENT item is not swallowed, so "the count
     * stayed 1" cannot be explained by a store that refuses every enqueue after
     * the first.
     */
    public function testADifferentItemIsStillEnqueued(): void
    {
        $store = new MediaAssetJobStore($this->queueDir);

        $store->enqueue(new MediaAssetJob('item-a', '/media/a.mkv', 120));
        $store->enqueue(new MediaAssetJob('item-b', '/media/b.mp4', 60));

        $this->assertSame(2, $this->fileCount());
        $this->assertSame(2, $store->queueSize());
    }

    /**
     * The repeat enqueue must not silently REWRITE the pending job either — a
     * store that overwrote in place would keep the file count at one while
     * discarding the payload the worker is about to read.
     */
    public function testARepeatEnqueueDoesNotOverwriteThePendingJobPayload(): void
    {
        $store = new MediaAssetJobStore($this->queueDir);

        $store->enqueue(new MediaAssetJob('item-a', '/media/original.mkv', 120));
        $store->enqueue(new MediaAssetJob('item-a', '/media/REPLACED.mkv', 7));

        $job = $store->dequeue();

        $this->assertNotNull($job);
        $this->assertSame('/media/original.mkv', $job->path);
        $this->assertSame(120, $job->duration);
        $this->assertSame(0, $this->fileCount(), 'dequeue consumes the single job file');
    }

    /**
     * After the worker completes an item the queue is empty again, so the SAME
     * item can be enqueued afresh later. Without this, the guard above would be
     * indistinguishable from "an item may only ever be processed once".
     */
    public function testAnItemCanBeReEnqueuedAfterItsJobCompletes(): void
    {
        $store = new MediaAssetJobStore($this->queueDir);

        $store->enqueue(new MediaAssetJob('item-a', '/media/a.mkv', 120));
        $store->complete('item-a');
        $this->assertSame(0, $this->fileCount());
        $this->assertFalse($store->isEnqueued('item-a'));

        $store->enqueue(new MediaAssetJob('item-a', '/media/a.mkv', 120));
        $this->assertSame(1, $this->fileCount());
        $this->assertTrue($store->isEnqueued('item-a'));
    }

    /**
     * Stream-probe jobs are a second kind in the same directory. Re-enqueueing an item that already
     * has a pending stream probe leaves the single pending job untouched.
     */
    public function testEnqueueingTheSameStreamProbeTwiceLeavesOneJob(): void
    {
        $store = new MediaAssetJobStore($this->queueDir);

        $store->enqueueStreamProbe(new StreamProbeJob('item-a'));
        $store->enqueueStreamProbe(new StreamProbeJob('item-a'));

        $this->assertSame(1, $store->streamProbeQueueSize());
        $job = $store->dequeueStreamProbe();
        $this->assertNotNull($job);
        $this->assertSame(0, $job->attempts, 'the second enqueue did not overwrite the pending job');
    }

    /**
     * A pending chapter/trickplay job and a pending stream probe for the SAME item must coexist: the
     * stream probe must not be swallowed by the trickplay guard, and neither dequeue may return the other.
     */
    public function testAStreamProbeAndATrickplayJobForTheSameItemCoexist(): void
    {
        $store = new MediaAssetJobStore($this->queueDir);

        $store->enqueue(new MediaAssetJob('item-a', '/media/a.mkv', 120));
        $store->enqueueStreamProbe(new StreamProbeJob('item-a'));

        $this->assertSame(1, $store->queueSize());
        $this->assertSame(1, $store->streamProbeQueueSize());

        $asset = $store->dequeue();
        $this->assertInstanceOf(MediaAssetJob::class, $asset);
        $this->assertSame(0, $store->queueSize());
        $this->assertSame(1, $store->streamProbeQueueSize(), 'dequeuing the trickplay job leaves the stream job');

        $probe = $store->dequeueStreamProbe();
        $this->assertInstanceOf(StreamProbeJob::class, $probe);
        $this->assertSame(0, $store->streamProbeQueueSize());
    }

    /** The chapter/trickplay dequeue never returns a stream-probe file. */
    public function testTheTrickplayDequeueIgnoresStreamProbeFiles(): void
    {
        $store = new MediaAssetJobStore($this->queueDir);

        $store->enqueueStreamProbe(new StreamProbeJob('item-a'));

        $this->assertNull($store->dequeue());
        $this->assertSame(0, $store->queueSize());
        $this->assertSame(1, $store->streamProbeQueueSize());
    }

    /** The attempt count survives the round trip through the job file, and the bound is reached at MAX_ATTEMPTS. */
    public function testStreamProbeAttemptCountRoundTripsThroughTheFile(): void
    {
        $store = new MediaAssetJobStore($this->queueDir);

        $store->enqueueStreamProbe(new StreamProbeJob('item-a', StreamProbeJob::MAX_ATTEMPTS - 1));
        $job = $store->dequeueStreamProbe();

        $this->assertNotNull($job);
        $this->assertSame(StreamProbeJob::MAX_ATTEMPTS - 1, $job->attempts);
        $this->assertFalse($job->isExhausted());
        $this->assertTrue($job->nextAttempt()->isExhausted());
        $this->assertSame(0, $store->streamProbeQueueSize(), 'dequeue consumes the stream job file');
    }

    /** A corrupt stream-probe file is removed and yields no job rather than an exception. */
    public function testACorruptStreamProbeFileIsRemovedAndYieldsNothing(): void
    {
        $store = new MediaAssetJobStore($this->queueDir);
        $this->writeCorruptStreamFile(1, 'item-x');

        $this->assertNull($store->dequeueStreamProbe());
        $this->assertSame(0, $store->streamProbeQueueSize());
    }

    /** clear() empties both kinds, so an operator reset leaves no stale stream probe behind. */
    public function testClearRemovesBothKinds(): void
    {
        $store = new MediaAssetJobStore($this->queueDir);

        $store->enqueue(new MediaAssetJob('item-a', '/media/a.mkv', 120));
        $store->enqueueStreamProbe(new StreamProbeJob('item-b'));
        $store->clear();

        $this->assertSame(0, $store->queueSize());
        $this->assertSame(0, $store->streamProbeQueueSize());
    }

    /**
     * Writes a stream-probe job file at an explicit queue position. The name is the shape the store writes:
     * a 20-digit microsecond stamp, then the item id. The second line is the content timestamp, as before.
     */
    private function writeStreamFile(int $stamp, string $itemId, int $attempts): string
    {
        $file = $this->queueDir . '/' . $this->streamFileName($stamp, $itemId);
        file_put_contents($file, json_encode(['item_id' => $itemId, 'attempts' => $attempts]) . "\n" . microtime(true) . "\n");

        return $file;
    }

    /** Writes a stream-probe-named file whose content is not JSON, at an explicit queue position. */
    private function writeCorruptStreamFile(int $stamp, string $itemId): string
    {
        $file = $this->queueDir . '/' . $this->streamFileName($stamp, $itemId);
        file_put_contents($file, "not json\n1.0\n");

        return $file;
    }

    private function streamFileName(int $stamp, string $itemId): string
    {
        return sprintf('%020d-%s.streams.job.json', $stamp, $itemId);
    }

    /**
     * Finding 5: a playback first-attempt enqueue must not reset the attempt count of a pending retry.
     */
    public function testAFirstAttemptEnqueueDoesNotResetAPendingRetry(): void
    {
        $store = new MediaAssetJobStore($this->queueDir);

        $store->enqueueStreamProbe(new StreamProbeJob('item-a', 1));
        $store->enqueueStreamProbe(new StreamProbeJob('item-a', 0));

        $job = $store->dequeueStreamProbe();
        $this->assertNotNull($job);
        $this->assertSame(1, $job->attempts, 'a playback enqueue must not reset the retry count to 0');
        $this->assertSame(0, $store->streamProbeQueueSize());
    }

    /**
     * Finding 5: a retry replaces a first-attempt file that a playback enqueue wrote while the run was in progress.
     */
    public function testARetryOverwritesAPendingFirstAttemptWithItsCount(): void
    {
        $store = new MediaAssetJobStore($this->queueDir);

        $store->enqueueStreamProbe(new StreamProbeJob('item-a', 0));
        $store->enqueueStreamProbe(new StreamProbeJob('item-a', 1));

        $this->assertSame(1, $store->streamProbeQueueSize(), 'the retry leaves one pending file for the item');
        $job = $store->dequeueStreamProbe();
        $this->assertNotNull($job);
        $this->assertSame(1, $job->attempts);
    }

    /**
     * Finding 1: the order is by the name's timestamp prefix, not by file mtime or the content timestamp.
     * The fixture inverts the mtimes, so a reader that sorted by mtime would return item-b here.
     */
    public function testStreamDequeueIsOldestFirstByNamePrefixNotMtime(): void
    {
        $store = new MediaAssetJobStore($this->queueDir);
        $now = time();
        $older = $this->writeStreamFile(1000, 'item-a', 0);
        $newer = $this->writeStreamFile(2000, 'item-b', 0);
        touch($older, $now - 10);   // newer mtime on the older-named job
        touch($newer, $now - 100);  // older mtime on the newer-named job

        $job = $store->dequeueStreamProbe();

        $this->assertNotNull($job);
        $this->assertSame('item-a', $job->itemId);
        $this->assertSame(1, $store->streamProbeQueueSize(), 'only the chosen file is consumed');
    }

    /** Finding 1: equal stamps are broken by item id, so the order is deterministic. */
    public function testEqualStampsAreBrokenByItemId(): void
    {
        $store = new MediaAssetJobStore($this->queueDir);
        $this->writeStreamFile(500, 'item-b', 0);
        $this->writeStreamFile(500, 'item-a', 0);

        $first = $store->dequeueStreamProbe();
        $second = $store->dequeueStreamProbe();

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame('item-a', $first->itemId);
        $this->assertSame('item-b', $second->itemId);
    }

    /**
     * Finding 1, the starvation regression: the oldest job sorts LAST by item id. Under the previous
     * name-ordered window (alphabetically first 512 names) it sat beyond the window and waited for every
     * newer-named job to drain. With the timestamp prefix it is the first name, so it is taken at once.
     */
    public function testAJobOlderThanTheWholeWindowIsNotStarved(): void
    {
        $store = new MediaAssetJobStore($this->queueDir);
        $cap = MediaAssetJobStore::STREAM_SCAN_CAP;
        for ($i = 0; $i < $cap; $i++) {
            $this->writeStreamFile(1000000 + $i, sprintf('newer-%04d', $i), 0);
        }
        $this->writeStreamFile(999999, 'zzz-oldest', 0);

        $job = $store->dequeueStreamProbe();

        $this->assertNotNull($job);
        $this->assertSame('zzz-oldest', $job->itemId, 'the oldest job is taken even though its name sorts last');
        $this->assertSame($cap, $store->streamProbeQueueSize(), 'exactly one file was consumed');
    }

    /**
     * Finding 1: with a backlog above the window, jobs still drain in enqueue order, end to end.
     * The stamps are unique and shuffled, so the drain order can only be right if the names sort by time.
     */
    public function testBacklogAboveTheWindowDrainsInChronologicalOrder(): void
    {
        $store = new MediaAssetJobStore($this->queueDir);
        $total = MediaAssetJobStore::STREAM_SCAN_CAP + 40;
        $expected = [];
        for ($i = 0; $i < $total; $i++) {
            $stamp = (($i * 7919) % $total) + 1000; // 7919 is prime and does not divide $total: stamps are unique
            $itemId = sprintf('i%05d', $stamp);
            $this->writeStreamFile($stamp, $itemId, 0);
            $expected[] = $itemId;
        }
        sort($expected, SORT_STRING);

        $drained = [];
        for ($i = 0; $i < $total; $i++) {
            $job = $store->dequeueStreamProbe();
            $this->assertNotNull($job);
            $drained[] = $job->itemId;
        }

        $this->assertSame($expected, $drained);
        $this->assertSame(0, $store->streamProbeQueueSize());
    }

    /**
     * Finding 1, idempotence beyond the window: a pending job whose name sorts past the 512-name window must
     * still be found, or a playback enqueue would create a second pending job for the same item.
     */
    public function testAPlaybackEnqueueFindsAPendingJobBeyondTheWindow(): void
    {
        $store = new MediaAssetJobStore($this->queueDir);
        $cap = MediaAssetJobStore::STREAM_SCAN_CAP;
        for ($i = 0; $i < $cap; $i++) {
            $this->writeStreamFile(1000 + $i, sprintf('older-%04d', $i), 0);
        }

        $store->enqueueStreamProbe(new StreamProbeJob('item-a')); // newest stamp: beyond the window
        $store->enqueueStreamProbe(new StreamProbeJob('item-a')); // must find the pending job and do nothing

        $this->assertSame($cap + 1, $store->streamProbeQueueSize(), 'no second pending job for item-a');
        $this->assertTrue($store->isStreamProbeEnqueued('item-a'));
    }

    /**
     * Finding 1, retries: a retry is re-enqueued with a new prefix, so it goes to the BACK of the queue,
     * behind the jobs that were already waiting, and it carries its incremented count.
     */
    public function testARetryIsRequeuedBehindTheJobsAlreadyWaiting(): void
    {
        $store = new MediaAssetJobStore($this->queueDir);
        $store->enqueueStreamProbe(new StreamProbeJob('item-a', 0));
        usleep(2000); // separate the microsecond stamps
        $store->enqueueStreamProbe(new StreamProbeJob('item-b', 0));

        $first = $store->dequeueStreamProbe();
        $this->assertNotNull($first);
        $this->assertSame('item-a', $first->itemId);

        usleep(2000);
        $store->enqueueStreamProbe(new StreamProbeJob('item-a', 1)); // the retry of item-a

        $second = $store->dequeueStreamProbe();
        $third = $store->dequeueStreamProbe();
        $this->assertNotNull($second);
        $this->assertNotNull($third);
        $this->assertSame('item-b', $second->itemId, 'the waiting job is served before the retry');
        $this->assertSame('item-a', $third->itemId);
        $this->assertSame(1, $third->attempts);
    }

    /**
     * Finding 4a: the job is written to a dot-named temp file and the final name appears only at the rename.
     * The writer and rename seams RECORD what the directory holds at each step. The assertions run after the
     * enqueue, outside the seams, so a direct write to the final name fails here.
     */
    public function testAStreamJobIsWrittenThroughATempFileAndRenamedIntoPlace(): void
    {
        $seen = [
            'writeTo' => null,
            'finalExistedAtWrite' => null,
            'renameFrom' => null,
            'renameTo' => null,
            'tempExistedAtRename' => null,
            'finalExistedAtRename' => null,
        ];

        $writer = function (string $path, string $data) use (&$seen): int|false {
            $seen['writeTo'] = $path;
            $seen['finalExistedAtWrite'] = $this->streamJobFiles() !== [];

            return file_put_contents($path, $data);
        };
        $renamer = static function (string $from, string $to) use (&$seen): bool {
            $seen['renameFrom'] = $from;
            $seen['renameTo'] = $to;
            $seen['tempExistedAtRename'] = is_file($from);
            $seen['finalExistedAtRename'] = is_file($to);

            return rename($from, $to);
        };

        $store = new MediaAssetJobStore($this->queueDir, $writer, $renamer);
        $store->enqueueStreamProbe(new StreamProbeJob('item-a', 0));

        $this->assertIsString($seen['writeTo']);
        $this->assertMatchesRegularExpression('/\/\.[0-9a-f]{16}\.tmp$/', $seen['writeTo'], 'the write goes to a dot-named temp file');
        $this->assertFalse($seen['finalExistedAtWrite'], 'no final name exists while the temp file is written');
        $this->assertTrue($seen['tempExistedAtRename'], 'the temp file is complete when it is renamed');
        $this->assertFalse($seen['finalExistedAtRename'], 'the final name does not exist before the rename');
        $this->assertSame($seen['writeTo'], $seen['renameFrom']);
        $this->assertMatchesRegularExpression('/\/\d{20}-item-a\.streams\.job\.json$/', (string) $seen['renameTo']);

        // scandir() can return false; naming the listing keeps psalm's array_filter
        // subject list<string> instead of list<string|false> (E1 SRV-CURE 2026-11-20).
        $listing = scandir($this->queueDir);
        $entries = array_values(array_diff($listing === false ? [] : $listing, ['.', '..']));
        $finals = array_values(array_filter($entries, static fn (string $e): bool => str_ends_with($e, '.streams.job.json')));
        $temps = array_values(array_filter($entries, static fn (string $e): bool => str_ends_with($e, '.tmp')));

        $this->assertCount(1, $finals);
        $this->assertMatchesRegularExpression('/^\d{20}-item-a\.streams\.job\.json$/', $finals[0]);
        $this->assertSame([], $temps, 'the temp file was renamed, not left behind');

        $lines = explode("\n", (string) file_get_contents($this->queueDir . '/' . $finals[0]));
        $decoded = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['item_id' => 'item-a', 'attempts' => 0], $decoded, 'the renamed file is complete');
    }

    /**
     * Finding 2: a temp file that a writer has not yet renamed is invisible to the stream kind. It is not
     * counted, not found by the enqueue check, not dequeued, and not removed by a dequeue. A dequeue that
     * saw it as a job would consume a half-written file.
     */
    public function testAPartialTempFileIsNeverDequeuedOrCounted(): void
    {
        $store = new MediaAssetJobStore($this->queueDir);
        $temp = $this->queueDir . '/.0123456789abcdef.tmp';
        file_put_contents($temp, '{"item_id":"item-p","att');

        $this->assertSame(0, $store->streamProbeQueueSize());
        $this->assertFalse($store->isStreamProbeEnqueued('item-p'));
        $this->assertSame(0, $store->queueSize());
        $this->assertNull($store->dequeueStreamProbe());
        $this->assertNull($store->dequeue());
        $this->assertFileExists($temp, 'a dequeue never touches an in-progress temp file');
    }

    /**
     * A file with a name outside the stream-probe shape (for example the old unprefixed form) is junk. It sorts
     * first here, so the dequeue meets it, removes it and returns the valid job behind it in the same call.
     */
    public function testAJunkStreamNameIsRemovedAndSkipped(): void
    {
        $store = new MediaAssetJobStore($this->queueDir);
        $junk = $this->queueDir . '/0-item-x.streams.job.json';
        file_put_contents($junk, json_encode(['item_id' => 'item-x', 'attempts' => 0]) . "\n1.0\n");
        $store->enqueueStreamProbe(new StreamProbeJob('item-y'));

        $job = $store->dequeueStreamProbe();

        $this->assertNotNull($job);
        $this->assertSame('item-y', $job->itemId);
        $this->assertFileDoesNotExist($junk);
        $this->assertSame(0, $store->streamProbeQueueSize());
    }

    /**
     * Finding 4: a corrupt head file is removed and skipped, and the next valid job is returned by the same call.
     */
    public function testACorruptHeadFileIsSkippedWithinOneDequeue(): void
    {
        $store = new MediaAssetJobStore($this->queueDir);
        $bad = $this->writeCorruptStreamFile(1, 'item-bad');
        $this->writeStreamFile(2, 'item-good', 0);

        $job = $store->dequeueStreamProbe();

        $this->assertNotNull($job);
        $this->assertSame('item-good', $job->itemId);
        $this->assertFileDoesNotExist($bad, 'the corrupt file is removed');
        $this->assertSame(0, $store->streamProbeQueueSize());
    }

    /**
     * Finding 4, the bound: one call examines at most STREAM_CORRUPT_SKIP_BUDGET files. A run of corrupt
     * files is cleared across calls and never loops within one.
     */
    public function testTheCorruptSkipBudgetBoundsOneCall(): void
    {
        $store = new MediaAssetJobStore($this->queueDir);
        $budget = MediaAssetJobStore::STREAM_CORRUPT_SKIP_BUDGET;
        for ($i = 1; $i <= $budget; $i++) {
            $this->writeCorruptStreamFile($i, sprintf('item-%02d', $i));
        }
        $this->writeStreamFile($budget + 1, 'item-good', 0);

        $firstCall = $store->dequeueStreamProbe();
        $this->assertNull($firstCall, 'one call gives up once the budget is spent');
        $this->assertSame(1, $store->streamProbeQueueSize(), 'the valid job waits for the next tick');

        $job = $store->dequeueStreamProbe();
        $this->assertNotNull($job);
        $this->assertSame('item-good', $job->itemId);
    }

    /**
     * Findings 2 and 3: a write that fails throws, so the caller can decide. It does not drop the job silently.
     * The lock path is a dangling symlink, so the enqueue cannot take the lock. The warning from that open is
     * silenced, and the RuntimeException is what the test asserts.
     */
    public function testAFailedStreamWriteThrowsInsteadOfDroppingTheJobSilently(): void
    {
        $store = new MediaAssetJobStore($this->queueDir);
        symlink($this->queueDir . '/no-such-dir/target', $this->queueDir . '/.streams.lock');

        set_error_handler(static fn (): bool => true);
        try {
            $this->expectException(\RuntimeException::class);
            $store->enqueueStreamProbe(new StreamProbeJob('item-x'));
        } finally {
            restore_error_handler();
        }
    }

    /** The stream-probe job files (regular files only), by name. */
    private function streamJobFiles(): array
    {
        $files = glob($this->queueDir . '/*.streams.job.json');

        return is_array($files) ? array_values(array_filter($files, 'is_file')) : [];
    }

    /** Every directory entry except `.`, `..` and the stream lock file. */
    private function entries(): array
    {
        return array_values(array_diff((array) scandir($this->queueDir), ['.', '..', '.streams.lock']));
    }

    /** Runs an enqueue and returns the RuntimeException it threw, or null. The assertions are made by the caller. */
    private function enqueueCatching(MediaAssetJobStore $store, StreamProbeJob $job): ?\RuntimeException
    {
        try {
            $store->enqueueStreamProbe($job);
        } catch (\RuntimeException $e) {
            return $e;
        }

        return null;
    }

    /**
     * Finding 4b: a failed temp write throws, leaves no final name, and removes the partial temp file.
     */
    public function testAFailedTempWriteLeavesNoFinalNameAndThrows(): void
    {
        $writer = static function (string $path, string $data): false {
            file_put_contents($path, 'partial');

            return false;
        };
        $store = new MediaAssetJobStore($this->queueDir, $writer);

        $caught = $this->enqueueCatching($store, new StreamProbeJob('item-w', 0));

        $this->assertInstanceOf(\RuntimeException::class, $caught);
        $this->assertSame([], $this->entries(), 'no final name, no temp residue and no sentinel');
        $this->assertFalse($store->isStreamProbeEnqueued('item-w'));
    }

    /**
     * Finding 4b: a failed rename throws and removes the temp file. The final name never appears.
     */
    public function testAFailedRenameRemovesTheTempFileAndThrows(): void
    {
        $renamer = static fn (string $from, string $to): bool => false;
        $store = new MediaAssetJobStore($this->queueDir, null, $renamer);

        $caught = $this->enqueueCatching($store, new StreamProbeJob('item-r', 0));

        $this->assertInstanceOf(\RuntimeException::class, $caught);
        $this->assertSame([], $this->entries(), 'the temp file is removed and the final name never existed');
    }

    /**
     * Finding 1: a playback enqueue that finds the stream lock busy for the whole budget throws with the busy
     * code and the item id, writes nothing, and returns within a bounded time. After release it succeeds.
     *
     * The ceiling is 100 ms. The budget is MediaAssetJobStore::LOCK_BUDGET_MS (10 ms) plus at most one 1 ms pause,
     * so the 100 ms ceiling leaves room for a loaded CI host. It is still far below the 500 ms the test allowed
     * before, so a budget raised to that order fails here.
     */
    public function testAStreamEnqueueFailsFastWhenTheLockStaysBusy(): void
    {
        $store = new MediaAssetJobStore($this->queueDir);
        $holder = fopen($this->queueDir . '/.streams.lock', 'c');
        $this->assertIsResource($holder);
        flock($holder, LOCK_EX);

        $started = hrtime(true);
        $caught = $this->enqueueCatching($store, new StreamProbeJob('item-busy', 0));
        $elapsedMs = (hrtime(true) - $started) / 1_000_000.0;

        flock($holder, LOCK_UN);
        fclose($holder);

        $this->assertInstanceOf(\RuntimeException::class, $caught);
        $this->assertSame(MediaAssetJobStore::ERROR_LOCK_BUSY, $caught->getCode());
        $this->assertStringContainsString('item-busy', $caught->getMessage(), 'the dropped enqueue is named by item id');
        $this->assertLessThan(100.0, $elapsedMs, 'the wait is bounded by the lock budget, not by the holder');
        $this->assertSame([], $this->streamJobFiles(), 'nothing is written while the lock is busy');

        $store->enqueueStreamProbe(new StreamProbeJob('item-busy', 0));
        $this->assertCount(1, $this->streamJobFiles(), 'after release the same enqueue succeeds');
    }

    /**
     * Finding 2: the pending sentinel makes a second playback enqueue a no-op with one job file, and the
     * sentinel exists while the job is pending.
     */
    public function testASecondEnqueueWhilePendingWritesNoSecondJobFile(): void
    {
        $store = new MediaAssetJobStore($this->queueDir);

        $store->enqueueStreamProbe(new StreamProbeJob('item-a', 0));
        $this->assertFileExists($this->queueDir . '/.pending-item-a.streams');

        $store->enqueueStreamProbe(new StreamProbeJob('item-a', 0));

        $this->assertCount(1, $this->streamJobFiles(), 'a second enqueue while pending creates no second job file');
    }

    /**
     * Finding 2: a stale sentinel (older than the TTL) is not trusted. When no job file exists it does not
     * block a new enqueue.
     */
    public function testAStaleSentinelWithNoJobFileDoesNotBlockAnEnqueue(): void
    {
        $store = new MediaAssetJobStore($this->queueDir);
        $sentinel = $this->queueDir . '/.pending-item-a.streams';
        touch($sentinel, time() - (MediaAssetJobStore::SENTINEL_TTL_SECONDS + 60));

        $store->enqueueStreamProbe(new StreamProbeJob('item-a', 0));

        $this->assertCount(1, $this->streamJobFiles(), 'the stale sentinel did not suppress the enqueue');
        $this->assertFileExists($sentinel, 'the sentinel is refreshed with the new job');
    }

    /**
     * Finding 2: a stale sentinel whose job IS still pending falls back to the directory scan, finds it, and
     * writes no second job file.
     */
    public function testAStaleSentinelWithAPendingJobFallsBackAndWritesNoSecondFile(): void
    {
        $store = new MediaAssetJobStore($this->queueDir);
        $store->enqueueStreamProbe(new StreamProbeJob('item-a', 0));
        $sentinel = $this->queueDir . '/.pending-item-a.streams';
        touch($sentinel, time() - (MediaAssetJobStore::SENTINEL_TTL_SECONDS + 60));

        $store->enqueueStreamProbe(new StreamProbeJob('item-a', 0));

        $this->assertCount(1, $this->streamJobFiles());
    }

    /** Finding 2: a dequeued job removes its sentinel, after the job file. */
    public function testADequeuedJobRemovesItsSentinel(): void
    {
        $store = new MediaAssetJobStore($this->queueDir);
        $store->enqueueStreamProbe(new StreamProbeJob('item-a', 0));
        $this->assertFileExists($this->queueDir . '/.pending-item-a.streams');

        $job = $store->dequeueStreamProbe();

        $this->assertNotNull($job);
        $this->assertFileDoesNotExist($this->queueDir . '/.pending-item-a.streams');
        $this->assertSame([], $this->streamJobFiles());
    }

    /**
     * Finding 2: a sentinel that cannot be written fails the enqueue, and the job file written just before is
     * removed. A sentinel is never left without the job it describes.
     */
    public function testAFailedSentinelWriteRemovesTheJobItWouldHaveMarked(): void
    {
        $store = new MediaAssetJobStore($this->queueDir);
        symlink($this->queueDir . '/no-such-dir/target', $this->queueDir . '/.pending-item-a.streams');

        $caught = $this->enqueueCatching($store, new StreamProbeJob('item-a', 0));

        $this->assertInstanceOf(\RuntimeException::class, $caught);
        $this->assertSame([], $this->streamJobFiles(), 'the job file is removed with the failed sentinel');
    }

    /**
     * Records each mutation as "<event>:<kind>", where event is write, rename, touch or unlink and kind is
     * temp, job, sentinel or other. The closure shares $events by reference, so the caller can reset it.
     *
     * @param array<int, string> $events
     */
    private function recordingObserver(array &$events): \Closure
    {
        return function (string $event, string $path) use (&$events): void {
            $events[] = $event . ':' . $this->kindOf($path);
        };
    }

    /** The file kind of a stream-kind path, as used by {@see recordingObserver()}. */
    private function kindOf(string $path): string
    {
        $name = basename($path);
        if (str_ends_with($name, '.tmp')) {
            return 'temp';
        }
        if (str_ends_with($name, '.streams.job.json')) {
            return 'job';
        }
        if (str_starts_with($name, '.pending-')) {
            return 'sentinel';
        }

        return 'other';
    }

    /**
     * Finding 6: a first enqueue writes the job through its temp file, renames it into place, and only then
     * touches the sentinel. Writing the sentinel first makes this sequence fail.
     */
    public function testAFirstEnqueueWritesTheJobBeforeTheSentinel(): void
    {
        $events = [];
        $store = new MediaAssetJobStore($this->queueDir, null, null, null, $this->recordingObserver($events));

        $store->enqueueStreamProbe(new StreamProbeJob('item-a', 0));

        $this->assertSame(['write:temp', 'rename:job', 'touch:sentinel'], $events);
    }

    /**
     * Finding 6: a dequeue removes the job file first, then its sentinel. A sentinel left without its job is the
     * state the TTL bounds, so the order is pinned: reversing it makes this sequence fail.
     */
    public function testADequeueRemovesTheJobFileBeforeTheSentinel(): void
    {
        $events = [];
        $store = new MediaAssetJobStore($this->queueDir, null, null, null, $this->recordingObserver($events));
        $store->enqueueStreamProbe(new StreamProbeJob('item-a', 0));
        $events = [];

        $this->assertNotNull($store->dequeueStreamProbe());

        $this->assertSame(['unlink:job', 'unlink:sentinel'], $events);
    }

    /**
     * Finding 6: a retry removes the listed pending job before it writes the replacement, and the replacement is
     * renamed into place before the sentinel is refreshed.
     */
    public function testARetryRemovesTheOldJobBeforeWritingTheReplacement(): void
    {
        $events = [];
        $store = new MediaAssetJobStore($this->queueDir, null, null, null, $this->recordingObserver($events));
        $store->enqueueStreamProbe(new StreamProbeJob('item-a', 0));
        $events = [];

        $store->enqueueStreamProbe(new StreamProbeJob('item-a', 1));

        $this->assertSame(['unlink:job', 'write:temp', 'rename:job', 'touch:sentinel'], $events);
        $this->assertCount(1, $this->streamJobFiles());
    }

    /**
     * Finding 6: when the sentinel write fails, the job was renamed into place first and is then removed. A
     * sentinel written first would fail before any job existed, so this sequence pins the order.
     */
    public function testAFailedSentinelWriteRenamesThenRemovesTheJob(): void
    {
        $events = [];
        $store = new MediaAssetJobStore($this->queueDir, null, null, null, $this->recordingObserver($events));
        symlink($this->queueDir . '/no-such-dir/target', $this->queueDir . '/.pending-item-a.streams');

        $caught = $this->enqueueCatching($store, new StreamProbeJob('item-a', 0));

        $this->assertInstanceOf(\RuntimeException::class, $caught);
        $this->assertSame(
            ['write:temp', 'rename:job', 'touch:sentinel', 'unlink:job', 'unlink:sentinel'],
            $events,
        );
        $this->assertSame([], $this->streamJobFiles());
    }

    /**
     * Finding 8: a STALE sentinel makes the enqueue list the item's pending files, and that listing finds a job
     * beyond the 512-name window. The enqueue refreshes the sentinel and writes no second file. Removing the scan
     * (listing nothing for a stale sentinel) writes a second file and fails this test.
     *
     * An ABSENT sentinel runs no scan by design, so a playback for an item with no job does no listing. The scan
     * is therefore reached through a stale sentinel only.
     */
    public function testAStaleSentinelScanFindsAPendingJobBeyondTheWindow(): void
    {
        $store = new MediaAssetJobStore($this->queueDir);
        $cap = MediaAssetJobStore::STREAM_SCAN_CAP;
        for ($i = 0; $i < $cap; $i++) {
            $this->writeStreamFile(1000 + $i, sprintf('older-%04d', $i), 0);
        }
        $store->enqueueStreamProbe(new StreamProbeJob('item-a', 0)); // newest stamp: beyond the window
        $sentinel = $this->queueDir . '/.pending-item-a.streams';
        touch($sentinel, time() - (MediaAssetJobStore::SENTINEL_TTL_SECONDS + 60));

        $store->enqueueStreamProbe(new StreamProbeJob('item-a', 0));

        $this->assertSame($cap + 1, $store->streamProbeQueueSize(), 'the scan found the pending job: no second file');
        clearstatcache();
        $this->assertGreaterThan(time() - 60, (int) filemtime($sentinel), 'the scan refreshed the sentinel');
    }

    /**
     * Finding 1: a playback enqueue that finds a fresh sentinel returns without taking the stream lock. A busy
     * lock therefore cannot drop it, and it writes nothing.
     */
    public function testAFreshSentinelEnqueueDoesNotNeedTheLock(): void
    {
        $store = new MediaAssetJobStore($this->queueDir);
        $store->enqueueStreamProbe(new StreamProbeJob('item-a', 0));
        $holder = fopen($this->queueDir . '/.streams.lock', 'c');
        $this->assertIsResource($holder);
        flock($holder, LOCK_EX);

        try {
            $store->enqueueStreamProbe(new StreamProbeJob('item-a', 0));
        } finally {
            flock($holder, LOCK_UN);
            fclose($holder);
        }

        $this->assertCount(1, $this->streamJobFiles(), 'the fresh-sentinel enqueue wrote nothing and did not need the lock');
    }

    /**
     * Finding 3: `a.b` and `a_b` get different job names and sentinels, so a pending job for one is never taken
     * for a pending job for the other.
     */
    public function testDistinctIdsThatUsedToSanitiseAlikeGetDistinctNames(): void
    {
        $store = new MediaAssetJobStore($this->queueDir);

        $store->enqueueStreamProbe(new StreamProbeJob('a.b', 0));
        $store->enqueueStreamProbe(new StreamProbeJob('a_b', 0));

        $this->assertSame(2, $store->streamProbeQueueSize(), 'a.b and a_b each have a pending job');
        $this->assertFileExists($this->queueDir . '/.pending-a%2Eb.streams');
        $this->assertFileExists($this->queueDir . '/.pending-a%5Fb.streams');
        $this->assertTrue($store->isStreamProbeEnqueued('a.b'));
        $this->assertTrue($store->isStreamProbeEnqueued('a_b'));
    }

    /**
     * Finding 3: a retry of `a.b` removes only `a.b`'s pending file. The pending job for `a_b` survives, and the
     * retry is written beside it.
     */
    public function testARetryOfOneIdDoesNotRemoveTheOtherIdsJob(): void
    {
        $store = new MediaAssetJobStore($this->queueDir);
        $store->enqueueStreamProbe(new StreamProbeJob('a_b', 0));
        $store->enqueueStreamProbe(new StreamProbeJob('a.b', 0));

        $store->enqueueStreamProbe(new StreamProbeJob('a.b', 1));

        $this->assertSame(2, $store->streamProbeQueueSize(), 'a_b keeps its job and a.b has one retry');
        $drained = [];
        while (($job = $store->dequeueStreamProbe()) !== null) {
            $drained[$job->itemId] = $job->attempts;
        }
        ksort($drained);
        $this->assertSame(['a.b' => 1, 'a_b' => 0], $drained);
    }

    /**
     * Finding 4: a job file that cannot be READ (an I/O error, not corrupt content) is not removed. The call skips
     * it and returns the next valid job. A later call, once the read works, still finds the file.
     */
    public function testAnUnreadableJobFileIsNotRemoved(): void
    {
        // The store creates the queue directory, so it is built before any fixture file is written.
        $failingReader = static fn (string $path): string|false => str_contains($path, 'item-u')
            ? false
            : (string) file_get_contents($path);
        $store = new MediaAssetJobStore($this->queueDir, null, null, $failingReader);
        $unreadable = $this->writeStreamFile(1, 'item-u', 0);
        $this->writeStreamFile(2, 'item-good', 0);

        $job = $store->dequeueStreamProbe();

        $this->assertNotNull($job);
        $this->assertSame('item-good', $job->itemId);
        $this->assertFileExists($unreadable, 'an unreadable file is left in place, not removed');

        $recovered = (new MediaAssetJobStore($this->queueDir))->dequeueStreamProbe();
        $this->assertNotNull($recovered);
        $this->assertSame('item-u', $recovered->itemId, 'the job was not lost: a later read finds it');
    }

    /**
     * Finding 7: a retry whose stale pending file cannot be removed fails and writes nothing. The stuck entry
     * is a non-empty-free directory that matches the job glob, so unlink() fails and the file still exists.
     */
    public function testARetryWhoseStalePendingFileCannotBeRemovedWritesNothing(): void
    {
        $store = new MediaAssetJobStore($this->queueDir);
        $stuck = $this->queueDir . '/' . $this->streamFileName(1, 'item-a');
        mkdir($stuck);

        try {
            $caught = $this->enqueueCatching($store, new StreamProbeJob('item-a', 1));

            $this->assertInstanceOf(\RuntimeException::class, $caught);
            $this->assertDirectoryExists($stuck, 'the stale entry is still there');
            $this->assertSame([], $this->streamJobFiles(), 'no replacement was written beside it');
        } finally {
            @rmdir($stuck);
        }
    }
}
