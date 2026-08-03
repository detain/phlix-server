<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media;

use Phlix\Media\Library\ItemRepository;
use Phlix\Media\SimilarityJob;
use Phlix\Media\SimilarityJobStore;
use Phlix\Media\SimilarityService;
use Phlix\Media\SimilarityWorker;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * SV-2.9: the SimilarityWorker is the CONSUMER that drains the file-based
 * similarity queue the scanner enqueues into. These tests assert a queued job
 * is drained and results in a real similarity update (an INSERT into
 * item_similar), that the candidate scan is bounded to the job's library (not
 * the O(N²) full-table scan the original finding flagged), and that a failing
 * job is drained rather than retried forever.
 *
 */
final class SimilarityWorkerTest extends TestCase
{
    /** @var list<string> Queue directories to clean up in tearDown. */
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

    private function makeStore(): SimilarityJobStore
    {
        $dir = sys_get_temp_dir() . '/phlix_sim_worker_' . uniqid('', true);
        $this->tmpQueues[] = $dir;
        return new SimilarityJobStore($dir);
    }

    /**
     * @return array<string, mixed> A metadata blob that satisfies hasCompleteMetadata().
     */
    private function completeMetadata(): array
    {
        return [
            'genres' => ['Action', 'Sci-Fi'],
            'actors' => ['Alice', 'Bob'],
            'directors' => ['Carol'],
            'rating' => 8.0,
            'year' => 2020,
        ];
    }

    public function test_run_once_drains_queued_job_and_writes_similarity_bounded_to_library(): void
    {
        $store = $this->makeStore();
        $store->enqueue(new SimilarityJob('item-src', 'lib-1'));

        $meta = $this->completeMetadata();

        /** @var ItemRepository&\PHPUnit\Framework\MockObject\MockObject $itemRepo */
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->method('findById')
            ->with('item-src')
            ->willReturn(['id' => 'item-src', 'metadata' => $meta]);

        $captured = [];
        /** @var Connection&\PHPUnit\Framework\MockObject\MockObject $db */
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function ($sql, $params = null) use (&$captured, $meta) {
                $captured[] = ['sql' => (string) $sql, 'params' => $params];
                if (str_contains((string) $sql, 'FROM media_items')) {
                    return [['id' => 'item-cand', 'metadata_json' => json_encode($meta)]];
                }
                return [];
            }
        );

        $service = new SimilarityService($db, $itemRepo);
        $worker = new SimilarityWorker($store, $service, null, 1);

        $processed = $worker->runOnce();

        $this->assertSame(1, $processed, 'The single queued job must be processed.');
        $this->assertSame(0, $store->queueSize(), 'The job must be drained from the queue.');

        // Behavioural proof of an actual similarity update.
        $inserted = array_filter(
            $captured,
            static fn (array $q): bool => str_starts_with(ltrim($q['sql']), 'INSERT INTO item_similar')
        );
        $this->assertNotEmpty($inserted, 'A similarity row must be INSERTed for the drained item.');

        // Proof the candidate set is bounded to the job's library, not the full table.
        $select = null;
        foreach ($captured as $q) {
            if (str_contains($q['sql'], 'FROM media_items')) {
                $select = $q;
                break;
            }
        }
        $this->assertNotNull($select, 'A candidate SELECT must have run.');
        $this->assertStringContainsString(
            'library_id = ?',
            $select['sql'],
            'The candidate scan must be library-bounded (SV-2.9), not a full-table scan.'
        );
        $this->assertIsArray($select['params']);
        $this->assertContains('lib-1', $select['params'], "The job's libraryId must bind the candidate scan.");
    }

    public function test_run_once_returns_zero_when_queue_empty(): void
    {
        $store = $this->makeStore();
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('query');
        $itemRepo = $this->createMock(ItemRepository::class);
        $service = new SimilarityService($db, $itemRepo);

        $worker = new SimilarityWorker($store, $service, null, 2);

        $this->assertSame(0, $worker->runOnce(), 'An empty queue processes nothing.');
    }

    public function test_failing_job_is_drained_not_retried(): void
    {
        $store = $this->makeStore();
        $store->enqueue(new SimilarityJob('item-boom', 'lib-1'));

        $itemRepo = $this->createMock(ItemRepository::class);
        // Simulate a hard failure while computing similarity for the item.
        $itemRepo->method('findById')->willThrowException(new \RuntimeException('boom'));

        $db = $this->createMock(Connection::class);
        $service = new SimilarityService($db, $itemRepo);

        $worker = new SimilarityWorker($store, $service, null, 1);

        $processed = $worker->runOnce();

        $this->assertSame(1, $processed, 'A failing job still counts as processed.');
        $this->assertSame(
            0,
            $store->queueSize(),
            'A failing job must be drained, not left to spin forever.'
        );
    }

    public function test_get_pending_count_reflects_queue(): void
    {
        $store = $this->makeStore();
        $store->enqueue(new SimilarityJob('a', 'lib-1'));
        $store->enqueue(new SimilarityJob('b', 'lib-1'));

        $db = $this->createMock(Connection::class);
        $itemRepo = $this->createMock(ItemRepository::class);
        $service = new SimilarityService($db, $itemRepo);

        $worker = new SimilarityWorker($store, $service);

        $this->assertSame(2, $worker->getPendingCount());
    }

    public function test_run_once_drains_up_to_max_concurrent_per_tick(): void
    {
        $store = $this->makeStore();
        $store->enqueue(new SimilarityJob('a', 'lib-1'));
        $store->enqueue(new SimilarityJob('b', 'lib-1'));
        $store->enqueue(new SimilarityJob('c', 'lib-1'));

        // Source items resolve to null → computeSimilarForItem returns early (no
        // DB writes), which keeps the batch-size assertion isolated from the
        // similarity math while still exercising the drain loop.
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->method('findById')->willReturn(null);
        $db = $this->createMock(Connection::class);
        $service = new SimilarityService($db, $itemRepo);

        $worker = new SimilarityWorker($store, $service, null, 2);

        $this->assertSame(2, $worker->runOnce(), 'At most max_concurrent jobs drain per tick.');
        $this->assertSame(1, $store->queueSize(), 'The remaining job stays queued for the next tick.');
        $this->assertSame(1, $worker->runOnce(), 'The next tick drains the remainder.');
        $this->assertSame(0, $store->queueSize());
    }
}
