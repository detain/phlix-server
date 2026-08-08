<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\MediaAsset;

use Phlix\Media\MediaAsset\MediaAssetJob;
use Phlix\Media\MediaAsset\MediaAssetJobStore;
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
}
