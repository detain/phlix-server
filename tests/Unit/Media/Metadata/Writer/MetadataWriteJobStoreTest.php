<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata\Writer;

use InvalidArgumentException;
use Phlix\Media\Metadata\Writer\MetadataWriteJob;
use Phlix\Media\Metadata\Writer\MetadataWriteJobStore;
use PHPUnit\Framework\TestCase;

/**
 * S87 — the file-based queue backing the metadata write-back enqueue.
 *
 * Mirrors the CollectionJobStore contract the scanner already relies on:
 * lazy directory mint (S439 zero-residue census — a constructed/resolved store
 * touches no disk), one bounded idempotent job file per item, FIFO drain by
 * the in-file timestamp, corrupt entries skipped not fatal.
 */
final class MetadataWriteJobStoreTest extends TestCase
{
    private string $queueDir = '';

    protected function tearDown(): void
    {
        if ($this->queueDir !== '' && is_dir($this->queueDir)) {
            foreach ((array) glob($this->queueDir . '/*') as $file) {
                if (is_string($file) && is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($this->queueDir);
        }
    }

    private function makeStore(): MetadataWriteJobStore
    {
        $this->queueDir = sys_get_temp_dir() . '/phlix_s87_store_' . uniqid('', true);
        return new MetadataWriteJobStore($this->queueDir);
    }

    public function test_construction_and_reads_never_mint_the_queue_directory(): void
    {
        $store = $this->makeStore();

        $this->assertSame(0, $store->queueSize());
        $this->assertFalse($store->isEnqueued('nope'));
        $this->assertNull($store->dequeue());
        $this->assertFalse(
            is_dir($this->queueDir),
            'S439 census: a resolved-but-never-written store must leave ZERO /tmp residue.'
        );
    }

    public function test_enqueue_mints_directory_and_one_file_per_item(): void
    {
        $store = $this->makeStore();

        $store->enqueue(new MetadataWriteJob('item-1', 'lib-1'));
        $store->enqueue(new MetadataWriteJob('item-2', 'lib-1'));

        $this->assertSame(2, $store->queueSize());
        $this->assertTrue($store->isEnqueued('item-1'));
    }

    public function test_enqueue_is_idempotent_per_item(): void
    {
        $store = $this->makeStore();
        $job = new MetadataWriteJob('item-1', 'lib-1');

        $store->enqueue($job);
        $store->enqueue($job);

        $this->assertSame(
            1,
            $store->queueSize(),
            'A re-scan while a job is pending must not pile up duplicates (fresh row read at drain).'
        );
    }

    public function test_dequeue_returns_fifo_and_removes_the_job(): void
    {
        $store = $this->makeStore();
        $store->enqueue(new MetadataWriteJob('first', 'lib-1'));
        usleep(1000);
        $store->enqueue(new MetadataWriteJob('second', 'lib-1'));

        $a = $store->dequeue();
        $b = $store->dequeue();

        $this->assertInstanceOf(MetadataWriteJob::class, $a);
        $this->assertSame('first', $a?->itemId, 'Drain order is FIFO by enqueue timestamp.');
        $this->assertSame('lib-1', $a?->libraryId);
        $this->assertSame('second', $b?->itemId);
        $this->assertNull($store->dequeue(), 'Queue is empty after both jobs drained.');
        $this->assertFalse($store->isEnqueued('first'), 'Dequeue removes the job file.');
    }

    public function test_corrupt_job_file_is_skipped_not_fatal(): void
    {
        $store = $this->makeStore();
        $store->enqueue(new MetadataWriteJob('good', 'lib-1'));
        $dir = $store->getQueueDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($dir . '/broken.job.json', "not-json\n123\n");

        $corrupt = $store->dequeue();
        $clean = $store->dequeue();

        $this->assertNull($corrupt, 'A corrupt entry reads as an empty dequeue and is dropped.');
        $this->assertSame('good', $clean?->itemId, 'The queue keeps working past one corrupt file.');
    }

    public function test_complete_and_clear_drain_the_queue(): void
    {
        $store = $this->makeStore();
        $store->enqueue(new MetadataWriteJob('item-1', 'lib-1'));
        $store->enqueue(new MetadataWriteJob('item-2', 'lib-1'));

        $store->complete('item-1');
        $this->assertSame(1, $store->queueSize());

        $store->clear();
        $this->assertSame(0, $store->queueSize());
    }

    public function test_hostile_item_id_is_sanitized_into_the_filename(): void
    {
        $store = $this->makeStore();
        $hostile = "../../etc/passwd\x00";

        $store->enqueue(new MetadataWriteJob($hostile, 'lib-1'));

        $files = (array) glob($store->getQueueDir() . '/*');
        $this->assertCount(1, $files, 'One queue file, fully inside the queue dir.');
        $this->assertStringEndsWith('.job.json', (string) $files[0]);
        $this->assertStringStartsWith(
            $store->getQueueDir(),
            realpath((string) $files[0]) ?: '',
            'Sanitisation must keep the job file INSIDE the queue directory.'
        );
    }

    public function test_job_roundtrips_through_array(): void
    {
        $job = new MetadataWriteJob('item-9', 'lib-4');

        $restored = MetadataWriteJob::fromArray($job->toArray());

        $this->assertSame('item-9', $restored->itemId);
        $this->assertSame('lib-4', $restored->libraryId);
    }

    public function test_from_array_fails_fast_on_missing_fields(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MetadataWriteJob::fromArray(['library_id' => 'lib-1']);
    }
}
