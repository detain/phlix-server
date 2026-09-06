<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media;

use Phlix\Media\CollectionJob;
use Phlix\Media\CollectionJobStore;
use Phlix\Media\CollectionService;
use Phlix\Media\CollectionWorker;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Metadata\TmdbProvider;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * S215: the CollectionWorker is the CONSUMER that drains the file-based
 * collection queue the scanner enqueues into. These tests assert a queued job
 * is drained and results in a real collection sync (the membership INSERT the
 * service writes), that the sync hits TMDB `/collection/{id}` at most ONCE per
 * collection (AC3), and that a failing job is drained rather than retried
 * forever. Mirrors SimilarityWorkerTest.
 */
final class CollectionWorkerTest extends TestCase
{
    // Merge-lane canary (P-1). Lives in the comment-stripped PHP corpus via the
    // executing assertion below, never in a *.md file. Not a security assertion.
    private const LANE_SENTINEL = 'S215COLLECTIONSYNCX9K4';

    private const MOVIE_UUID = 'aa11bb22-cc33-4455-6677-889900112233';
    private const TMDB_MOVIE_ID = 155;
    private const TMDB_COLLECTION_ID = 10;
    private const LOCAL_COLLECTION_ID = 77;

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
        $dir = sys_get_temp_dir() . '/phlix_col_worker_' . uniqid('', true);
        $this->tmpQueues[] = $dir;
        return new CollectionJobStore($dir);
    }

    public function testTheLaneSentinelIsCodeResident(): void
    {
        $this->assertSame(
            'S215COLLECTIONSYNCX9K4',
            self::LANE_SENTINEL,
            'The merge-lane canary must live in executable PHP, not in documentation.'
        );
    }

    public function testRunOnceDrainsQueuedJobAndSyncsCollectionMembership(): void
    {
        $store = $this->makeStore();
        $store->enqueue(new CollectionJob(self::MOVIE_UUID));

        $calls = [];
        $db = $this->recordingDb($calls);

        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->method('findById')
            ->with(self::MOVIE_UUID)
            ->willReturn([
                'id' => self::MOVIE_UUID,
                'metadata_json' => json_encode(['tmdb_id' => self::TMDB_MOVIE_ID]),
            ]);

        // The movie sits at parts index 0 → part order 1. getCollection is pinned
        // to EXACTLY ONE call: the pre-S215 sync fetched /collection/{id} twice
        // (once inside getOrCreateCollection, once again for the part order).
        $tmdb = $this->createMock(TmdbProvider::class);
        $tmdb->method('hasApiKey')->willReturn(true);
        $tmdb->method('getCollectionIdForMovie')
            ->with(self::TMDB_MOVIE_ID)
            ->willReturn(self::TMDB_COLLECTION_ID);
        $tmdb->expects($this->once())
            ->method('getCollection')
            ->with(self::TMDB_COLLECTION_ID)
            ->willReturn([
                'name' => 'The Dark Knight Saga',
                'overview' => null,
                'poster_path' => null,
                'backdrop_path' => null,
                'parts' => [['id' => self::TMDB_MOVIE_ID]],
            ]);

        $service = new CollectionService($db, $itemRepo, $tmdb);
        $worker = new CollectionWorker($store, $service, null, 1);

        $processed = $worker->runOnce();

        $this->assertSame(1, $processed, 'The single queued job must be processed.');
        $this->assertSame(0, $store->queueSize(), 'The job must be drained from the queue.');

        $inserts = $this->callsMatching($calls, 'INSERT INTO media_collection_members');
        $this->assertCount(1, $inserts, 'A membership row must be written for the drained item.');
        $this->assertSame(
            [self::LOCAL_COLLECTION_ID, self::MOVIE_UUID, 1],
            $inserts[0]['params'],
            'Membership must bind (local collection id, the UUID string, part order 1).'
        );
    }

    public function testFailingJobIsDrainedNotRetriedForever(): void
    {
        $store = $this->makeStore();
        $store->enqueue(new CollectionJob('broken-item'));

        $callsUnused = [];
        $callsUnused = [];
        $db = $this->recordingDb($callsUnused);

        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->method('findById')->with('broken-item')->willReturn([
            'id' => 'broken-item',
            'metadata_json' => json_encode(['tmdb_id' => self::TMDB_MOVIE_ID]),
        ]);

        $tmdb = $this->createMock(TmdbProvider::class);
        $tmdb->method('hasApiKey')->willReturn(true);
        $tmdb->method('getCollectionIdForMovie')->willThrowException(new \RuntimeException('TMDB down'));

        $service = new CollectionService($db, $itemRepo, $tmdb);
        $worker = new CollectionWorker($store, $service, null, 1);

        $processed = $worker->runOnce();

        $this->assertSame(1, $processed, 'A failing job still counts as processed (it was drained).');
        $this->assertSame(
            0,
            $store->queueSize(),
            'A failing job must be completed anyway — the worker must not spin on it forever.'
        );
    }

    public function testRunOnceOnEmptyQueueIsANoOp(): void
    {
        $store = $this->makeStore();

        $calls = [];
        $db = $this->recordingDb($calls);
        $itemRepo = $this->createMock(ItemRepository::class);
        $tmdb = $this->createMock(TmdbProvider::class);
        $service = new CollectionService($db, $itemRepo, $tmdb);
        $worker = new CollectionWorker($store, $service, null, 1);

        $this->assertSame(0, $worker->runOnce());
        $this->assertSame([], $calls, 'An empty queue must touch neither the DB nor the provider.');
    }

    /**
     * @param array<int, array{sql: string, params: array<int, mixed>}> $calls
     */
    private function recordingDb(array &$calls): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            /**
             * @param array<int, mixed> $params
             * @return array<int, array<string, mixed>>
             */
            function (string $sql, array $params = []) use (&$calls): array {
                $calls[] = ['sql' => $sql, 'params' => $params];
                if (str_contains($sql, 'FROM media_collections WHERE tmdb_collection_id')) {
                    return [[
                        'id' => self::LOCAL_COLLECTION_ID,
                        'tmdb_collection_id' => self::TMDB_COLLECTION_ID,
                        'name' => 'The Dark Knight Saga',
                        'overview' => null,
                        'poster_url' => null,
                        'backdrop_url' => null,
                    ]];
                }
                return [];
            }
        );

        return $db;
    }

    /**
     * @param array<int, array{sql: string, params: array<int, mixed>}> $calls
     * @return array<int, array{sql: string, params: array<int, mixed>}>
     */
    private function callsMatching(array $calls, string $needle): array
    {
        return array_values(array_filter(
            $calls,
            static fn (array $call): bool => str_contains($call['sql'], $needle)
        ));
    }
}
