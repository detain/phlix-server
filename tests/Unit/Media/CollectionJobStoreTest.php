<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media;

use Phlix\Media\CollectionJob;
use Phlix\Media\CollectionJobStore;
use PHPUnit\Framework\TestCase;

/**
 * S215: the collection job store is the queue the scanner enqueues TMDB
 * box-set syncs into and the CollectionWorker drains. These tests pin the
 * queue semantics (idempotent enqueue, FIFO dequeue, complete, size bound)
 * and — critically — the S439 zero-residue contract: CONSTRUCTING the store
 * and performing READ operations on it never mint the queue directory; only
 * the first enqueue does, so resolving the DI factory (which container-boot
 * tests do) leaves no /tmp/phlix_* residue.
 */
final class CollectionJobStoreTest extends TestCase
{
    /** @var list<string> Queue directories to sweep in tearDown (S439). */
    private array $tmpQueues = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpQueues as $dir) {
            if (is_dir($dir)) {
                foreach ((array) glob($dir . '/*') as $file) {
                    if (is_string($file) && is_file($file)) {
                        @unlink($file);
                    }
                }
                @rmdir($dir);
            }
        }
        $this->tmpQueues = [];
        parent::tearDown();
    }

    private function makeStore(): CollectionJobStore
    {
        $dir = sys_get_temp_dir() . '/phlix_col_store_' . uniqid('', true);
        $this->tmpQueues[] = $dir;
        return new CollectionJobStore($dir);
    }

    public function testConstructionAndReadsNeverMintTheQueueDirectory(): void
    {
        $store = $this->makeStore();

        // Reads on a never-written queue are side-effect free…
        $this->assertSame(0, $store->queueSize());
        $this->assertNull($store->dequeue());
        $this->assertFalse($store->isEnqueued('item-1'));
        $this->assertSame([], $store->getPendingItemIds());
        $store->clear();

        // …and must not leave a /tmp/phlix_* directory behind (S439 census).
        $this->assertDirectoryDoesNotExist(
            $store->getQueueDir(),
            'CollectionJobStore must mint its directory lazily on first enqueue, never at '
            . 'construction/read time — container-boot tests resolve the DI factory and the '
            . 'zero-residue census holds them to this.'
        );
    }

    public function testEnqueueMintsDirectoryAndDequeueReturnsTheJobFifo(): void
    {
        $store = $this->makeStore();

        $store->enqueue(new CollectionJob('item-first'));
        $this->assertDirectoryExists($store->getQueueDir(), 'First enqueue must create the queue directory.');
        usleep(2000);
        $store->enqueue(new CollectionJob('item-second'));

        $this->assertSame(2, $store->queueSize());
        $this->assertTrue($store->isEnqueued('item-first'));

        $first = $store->dequeue();
        $this->assertInstanceOf(CollectionJob::class, $first);
        $this->assertSame('item-first', $first?->itemId, 'Dequeue must be FIFO by enqueue time.');

        $second = $store->dequeue();
        $this->assertSame('item-second', $second?->itemId);

        $this->assertNull($store->dequeue(), 'Drained queue must yield null.');
        $this->assertSame(0, $store->queueSize());
    }

    public function testEnqueueIsIdempotentPerItem(): void
    {
        $store = $this->makeStore();

        $store->enqueue(new CollectionJob('item-dup'));
        $store->enqueue(new CollectionJob('item-dup'));

        $this->assertSame(
            1,
            $store->queueSize(),
            'Re-enqueueing the same item must be a no-op (one pending job per item).'
        );
    }

    public function testCompleteRemovesOnlyTheGivenItem(): void
    {
        $store = $this->makeStore();
        $store->enqueue(new CollectionJob('item-a'));
        $store->enqueue(new CollectionJob('item-b'));

        $store->complete('item-a');

        $this->assertSame(1, $store->queueSize());
        $this->assertFalse($store->isEnqueued('item-a'));
        $this->assertTrue($store->isEnqueued('item-b'));
    }

    public function testPendingItemIdsRoundTripsThroughQueueFiles(): void
    {
        $store = $this->makeStore();
        $store->enqueue(new CollectionJob('uuid-1'));
        $store->enqueue(new CollectionJob('uuid-2'));

        $pending = $store->getPendingItemIds();
        sort($pending);

        $this->assertSame(['uuid-1', 'uuid-2'], $pending);
    }

    public function testJobValueObjectParsesAndRejects(): void
    {
        $job = CollectionJob::fromArray(['item_id' => 'abc-123']);
        $this->assertSame('abc-123', $job->itemId);
        $this->assertSame(['item_id' => 'abc-123'], $job->toArray());

        $this->expectException(\InvalidArgumentException::class);
        CollectionJob::fromArray(['item_id' => '']);
    }
}
