<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media;

use Phlix\Media\CollectionJob;
use Phlix\Media\CollectionJobStore;
use Phlix\Media\CollectionService;
use Phlix\Media\CollectionWorker;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Metadata\TmdbProvider;
use Phlix\Tests\Support\Coroutine\RunsInCoroutine;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Workerman\MySQL\Connection;

/**
 * S215 — the `CollectionWorker` coroutine fork on both arms.
 *
 * `runOnce()` picks `processConcurrently()` (Channel-as-semaphore fan-out)
 * when `isCoroutineContext()` is true and `processSequentially()` otherwise.
 * `CollectionWorkerTest` drives the worker from the main stack only, so the
 * concurrent arm — the one the supervised production worker executes inside
 * its Swoole loop — would otherwise stay unexecuted by the suite (the S170
 * defect class). This is the `SimilarityWorkerCoroutineForkTest` (S196)
 * pattern applied to the collection trio, and it doubles as the covering
 * test `ForkInventoryGuardTest` requires for `src/Media/CollectionWorker.php`.
 *
 * Branch identity is OBSERVED via each arm's own log line: the concurrent arm
 * logs `CollectionWorker::processConcurrently Starting batch`, the sequential
 * arm logs `CollectionWorker::processSequentially Starting batch`. Both arms
 * run against a REAL file-backed `CollectionJobStore` over a temp directory
 * and the REAL `CollectionService` over mocked DB/repo/provider (the same
 * construction as `CollectionServiceTest`); only the logger is faked.
 */
final class CollectionWorkerCoroutineForkTest extends TestCase
{
    use RunsInCoroutine;

    private const TMDB_MOVIE_ID = 550;
    private const TMDB_COLLECTION_ID = 10;
    private const LOCAL_COLLECTION_ID = 42;

    private string $queueDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->queueDir = sys_get_temp_dir() . '/phlix_collection_fork_' . bin2hex(random_bytes(4));
        mkdir($this->queueDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->queueDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->queueDir);
        parent::tearDown();
    }

    /**
     * A worker over a REAL seeded store plus the REAL CollectionService on
     * mocks — same construction as `CollectionServiceTest`'s happy path.
     *
     * @return array{0: CollectionWorker, 1: object{messages: list<string>}}
     */
    private function buildWorker(int $jobCount): array
    {
        $store = new CollectionJobStore($this->queueDir);
        for ($i = 0; $i < $jobCount; $i++) {
            $store->enqueue(new CollectionJob('movie-uuid-' . $i));
        }

        $record = new class {
            /** @var list<string> */
            public array $messages = [];
        };

        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->method('findById')->willReturnCallback(
            static fn (string $id): array => [
                'id' => $id,
                'metadata_json' => json_encode(['tmdb_id' => self::TMDB_MOVIE_ID]),
            ]
        );

        $tmdb = $this->createMock(TmdbProvider::class);
        $tmdb->method('hasApiKey')->willReturn(true);
        $tmdb->method('getCollectionIdForMovie')->willReturn(self::TMDB_COLLECTION_ID);
        $tmdb->method('getCollection')->willReturn([
            'name' => 'The Saga Collection',
            'overview' => null,
            'poster_path' => null,
            'backdrop_path' => null,
            'parts' => [['id' => self::TMDB_MOVIE_ID]],
        ]);

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function (string $sql): array {
                if (str_contains($sql, 'FROM media_collections')) {
                    return [[
                        'id' => self::LOCAL_COLLECTION_ID,
                        'tmdb_collection_id' => self::TMDB_COLLECTION_ID,
                        'name' => 'The Saga Collection',
                        'overview' => null,
                        'poster_url' => null,
                        'backdrop_url' => null,
                    ]];
                }

                return [];
            }
        );

        $service = new CollectionService($db, $itemRepo, $tmdb);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('debug')->willReturnCallback(
            static function (string $message) use ($record): void {
                $record->messages[] = $message;
            }
        );

        return [new CollectionWorker($store, $service, $logger, 2), $record];
    }

    /**
     * INSIDE a real coroutine, runOnce() must take the concurrent arm: the
     * batch is processed, the queue drains, and the processConcurrently log
     * line is observed.
     */
    public function testCoroutineArmProcessesBatchConcurrently(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to execute the coroutine branch.');
        }

        [$worker, $record] = $this->buildWorker(2);

        $processed = $this->runInCoroutine(fn (): int => $worker->runOnce());

        $this->assertSame(2, $processed);
        $this->assertSame(0, $worker->getPendingCount(), 'every job must be drained from the real queue');
        $this->assertContains(
            'CollectionWorker::processConcurrently Starting batch [count=2]',
            $record->messages,
            'the coroutine arm must take the concurrent path'
        );
        $this->assertNotContains(
            'CollectionWorker::processSequentially Starting batch [count=2]',
            $record->messages
        );
    }

    /**
     * OUTSIDE a coroutine the same call must take the sequential arm: the
     * batch is processed, the queue drains, and the processSequentially log
     * line is observed.
     */
    public function testBlockingArmProcessesBatchSequentially(): void
    {
        [$worker, $record] = $this->buildWorker(2);

        $processed = $worker->runOnce();

        $this->assertSame(2, $processed);
        $this->assertSame(0, $worker->getPendingCount(), 'every job must be drained from the real queue');
        $this->assertContains(
            'CollectionWorker::processSequentially Starting batch [count=2]',
            $record->messages,
            'the main-stack arm must take the sequential path'
        );
        $this->assertNotContains(
            'CollectionWorker::processConcurrently Starting batch [count=2]',
            $record->messages
        );
    }
}
