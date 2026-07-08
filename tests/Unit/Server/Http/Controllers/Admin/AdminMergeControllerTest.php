<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers\Admin;

use Phlix\Common\Database\PhlixMySQLConnection;
use Phlix\Common\Database\PooledMySQLConnection;
use Phlix\Media\Library\DuplicateFinder;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\SeriesMerger;
use Phlix\Server\Http\Controllers\Admin\AdminMergeController;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Test-double capability marker: an {@see ItemRepository} that also exposes a
 * `seed()` helper for populating the in-memory store. Lets the test type the
 * double as `ItemRepository&SeedableItemRepository` so `seed()` is visible.
 */
interface SeedableItemRepository
{
    /** @param array<string, mixed> $row */
    public function seed(array $row): string;
}

/**
 * Unit tests for AdminMergeController (Step 1.6, Feature 1).
 *
 * Auth (401/403) is enforced by {@see \Phlix\Server\Http\Middleware\AdminMiddleware}
 * upstream of this controller (verified in the AdminRoutes integration test);
 * here we assert the controller's own behaviour given an already-authenticated
 * admin request — the preview group shape, the apply `{moved, deleted}` shape,
 * and the input-validation HTTP-code matrix (400 cross-library / cross-type /
 * empty / self-merge, 404 missing primary, 503 when no transactional merger).
 *
 * The controller is driven with REAL {@see DuplicateFinder} and
 * {@see SeriesMerger} instances over an in-memory {@see ItemRepository} double
 * so the assertions are non-vacuous end-to-end.
 *
 * @covers \Phlix\Server\Http\Controllers\Admin\AdminMergeController
 */
final class AdminMergeControllerTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────────
    // duplicates() — GET preview
    // ─────────────────────────────────────────────────────────────────

    public function testDuplicatesReturnsGroupShapeForLibrary(): void
    {
        $repo = $this->makeRepo();

        // A duplicate pair: HxH with 100 episodes (primary) + HxH with 1 episode.
        $primary = $repo->seed(['library_id' => 'lib-1', 'parent_id' => null, 'type' => 'series', 'name' => 'Hunter x Hunter']);
        $primarySeason = $repo->seed(['library_id' => 'lib-1', 'parent_id' => $primary, 'type' => 'season', 'name' => 'Season 1', 'metadata_json' => ['season' => 1]]);
        for ($i = 1; $i <= 100; $i++) {
            $repo->seed(['library_id' => 'lib-1', 'parent_id' => $primarySeason, 'type' => 'episode', 'name' => "E{$i}", 'metadata_json' => ['season' => 1, 'episode' => $i]]);
        }
        $dup = $repo->seed(['library_id' => 'lib-1', 'parent_id' => null, 'type' => 'series', 'name' => 'HunterxHunter']);
        $dupSeason = $repo->seed(['library_id' => 'lib-1', 'parent_id' => $dup, 'type' => 'season', 'name' => 'Season 1', 'metadata_json' => ['season' => 1]]);
        $repo->seed(['library_id' => 'lib-1', 'parent_id' => $dupSeason, 'type' => 'episode', 'name' => 'E101', 'metadata_json' => ['season' => 1, 'episode' => 101]]);

        $controller = $this->makeController($repo);
        $response = $controller->duplicates(new Request(), ['id' => 'lib-1']);

        $this->assertSame(200, $response->statusCode);
        /** @var array{groups: array<int, array<string, mixed>>} $body */
        $body = $this->decode($response->body);
        $this->assertArrayHasKey('groups', $body);
        $this->assertCount(1, $body['groups']);

        // Group shape (from Step 1.3): canonical_key, type, library_id, primary, duplicates[].
        /** @var array{type: mixed, library_id: mixed, primary: array<string, mixed>, duplicates: array<int, array<string, mixed>>} $group */
        $group = $body['groups'][0];
        $this->assertArrayHasKey('canonical_key', $group);
        $this->assertSame('series', $group['type']);
        $this->assertSame('lib-1', $group['library_id']);
        $this->assertSame($primary, $group['primary']['id']);
        // Primary subtree = 1 season + 100 episodes = 101 descendants; the
        // duplicate = 1 season + 1 episode = 2. The primary (more descendants)
        // wins.
        $this->assertSame(101, $group['primary']['descendant_count']);
        $this->assertCount(1, $group['duplicates']);
        $this->assertSame($dup, $group['duplicates'][0]['id']);
        $this->assertSame(2, $group['duplicates'][0]['descendant_count']);
    }

    public function testDuplicatesReturnsEmptyGroupsWhenNoDuplicates(): void
    {
        $repo = $this->makeRepo();
        $repo->seed(['library_id' => 'lib-1', 'parent_id' => null, 'type' => 'series', 'name' => 'Solo Show']);

        $controller = $this->makeController($repo);
        $response = $controller->duplicates(new Request(), ['id' => 'lib-1']);

        $this->assertSame(200, $response->statusCode);
        $body = $this->decode($response->body);
        $this->assertArrayHasKey('groups', $body);
        $this->assertSame([], $body['groups']);
    }

    public function testDuplicatesRejectsEmptyLibraryId(): void
    {
        $repo = $this->makeRepo();
        $controller = $this->makeController($repo);

        $response = $controller->duplicates(new Request(), ['id' => '']);

        $this->assertSame(400, $response->statusCode);
        $this->assertArrayHasKey('error', $this->decode($response->body));
    }

    // ─────────────────────────────────────────────────────────────────
    // merge() — POST apply (happy path)
    // ─────────────────────────────────────────────────────────────────

    public function testMergeReturnsMovedAndDeletedCounts(): void
    {
        $repo = $this->makeRepo();

        $primary = $repo->seed(['library_id' => 'lib-1', 'parent_id' => null, 'type' => 'series', 'name' => 'Hunter x Hunter']);
        $primarySeason = $repo->seed(['library_id' => 'lib-1', 'parent_id' => $primary, 'type' => 'season', 'name' => 'Season 1', 'metadata_json' => ['season' => 1]]);
        $repo->seed(['library_id' => 'lib-1', 'parent_id' => $primarySeason, 'type' => 'episode', 'name' => 'E1', 'metadata_json' => ['season' => 1, 'episode' => 1]]);
        $dup = $repo->seed(['library_id' => 'lib-1', 'parent_id' => null, 'type' => 'series', 'name' => 'HunterxHunter']);
        $dupSeason = $repo->seed(['library_id' => 'lib-1', 'parent_id' => $dup, 'type' => 'season', 'name' => 'Season 1', 'metadata_json' => ['season' => 1]]);
        $repo->seed(['library_id' => 'lib-1', 'parent_id' => $dupSeason, 'type' => 'episode', 'name' => 'E2', 'metadata_json' => ['season' => 1, 'episode' => 2]]);

        $controller = $this->makeController($repo);
        $response = $controller->merge($this->jsonRequest(['primary_id' => $primary, 'duplicate_ids' => [$dup]]));

        $this->assertSame(200, $response->statusCode);
        $body = $this->decode($response->body);
        $this->assertSame(['moved' => 1, 'deleted' => 2], ['moved' => $body['moved'], 'deleted' => $body['deleted']]);
        // The duplicate shell is gone.
        $this->assertNull($repo->findById($dup));
    }

    public function testMergeMovieDeletesDuplicateKeepingPrimary(): void
    {
        $repo = $this->makeRepo();
        $primary = $repo->seed(['library_id' => 'lib-1', 'parent_id' => null, 'type' => 'movie', 'name' => 'Dune']);
        $dup = $repo->seed(['library_id' => 'lib-1', 'parent_id' => null, 'type' => 'movie', 'name' => 'Dune']);

        $controller = $this->makeController($repo);
        $response = $controller->merge($this->jsonRequest(['primary_id' => $primary, 'duplicate_ids' => [$dup]]));

        $this->assertSame(200, $response->statusCode);
        $body = $this->decode($response->body);
        $this->assertSame(0, $body['moved']);
        $this->assertSame(1, $body['deleted']);
        $this->assertNotNull($repo->findById($primary));
        $this->assertNull($repo->findById($dup));
    }

    // ─────────────────────────────────────────────────────────────────
    // merge() — validation matrix
    // ─────────────────────────────────────────────────────────────────

    public function testMergeRejectsMissingPrimaryId(): void
    {
        $repo = $this->makeRepo();
        $controller = $this->makeController($repo);

        $response = $controller->merge($this->jsonRequest(['duplicate_ids' => ['x']]));

        $this->assertSame(400, $response->statusCode);
        $this->assertArrayHasKey('error', $this->decode($response->body));
    }

    public function testMergeRejectsEmptyDuplicateIds(): void
    {
        $repo = $this->makeRepo();
        $primary = $repo->seed(['library_id' => 'lib-1', 'parent_id' => null, 'type' => 'series', 'name' => 'Show']);
        $controller = $this->makeController($repo);

        $response = $controller->merge($this->jsonRequest(['primary_id' => $primary, 'duplicate_ids' => []]));

        $this->assertSame(400, $response->statusCode);
        $this->assertArrayHasKey('error', $this->decode($response->body));
        // The primary is untouched.
        $this->assertNotNull($repo->findById($primary));
    }

    public function testMergeRejectsDuplicateIdsNotAnArray(): void
    {
        $repo = $this->makeRepo();
        $primary = $repo->seed(['library_id' => 'lib-1', 'parent_id' => null, 'type' => 'series', 'name' => 'Show']);
        $controller = $this->makeController($repo);

        $response = $controller->merge($this->jsonRequest(['primary_id' => $primary, 'duplicate_ids' => 'not-an-array']));

        $this->assertSame(400, $response->statusCode);
        $this->assertArrayHasKey('error', $this->decode($response->body));
    }

    public function testMergeRejectsDuplicateIdsWithNonStringEntry(): void
    {
        $repo = $this->makeRepo();
        $primary = $repo->seed(['library_id' => 'lib-1', 'parent_id' => null, 'type' => 'series', 'name' => 'Show']);
        $controller = $this->makeController($repo);

        $response = $controller->merge($this->jsonRequest(['primary_id' => $primary, 'duplicate_ids' => [123]]));

        $this->assertSame(400, $response->statusCode);
        $this->assertArrayHasKey('error', $this->decode($response->body));
    }

    public function testMergeRejectsSelfMerge(): void
    {
        $repo = $this->makeRepo();
        $primary = $repo->seed(['library_id' => 'lib-1', 'parent_id' => null, 'type' => 'series', 'name' => 'Show']);
        $controller = $this->makeController($repo);

        $response = $controller->merge($this->jsonRequest(['primary_id' => $primary, 'duplicate_ids' => [$primary]]));

        $this->assertSame(400, $response->statusCode);
        $this->assertArrayHasKey('error', $this->decode($response->body));
        // The primary is NEVER deleted by a self-merge.
        $this->assertNotNull($repo->findById($primary));
    }

    public function testMergeReturns404WhenPrimaryMissing(): void
    {
        $repo = $this->makeRepo();
        $dup = $repo->seed(['library_id' => 'lib-1', 'parent_id' => null, 'type' => 'series', 'name' => 'Show']);
        $controller = $this->makeController($repo);

        $response = $controller->merge($this->jsonRequest(['primary_id' => 'no-such-id', 'duplicate_ids' => [$dup]]));

        $this->assertSame(404, $response->statusCode);
        $this->assertArrayHasKey('error', $this->decode($response->body));
        // The would-be duplicate is untouched when the primary is missing.
        $this->assertNotNull($repo->findById($dup));
    }

    public function testMergeReturns404WhenDuplicateMissing(): void
    {
        $repo = $this->makeRepo();
        $primary = $repo->seed(['library_id' => 'lib-1', 'parent_id' => null, 'type' => 'series', 'name' => 'Show']);
        $controller = $this->makeController($repo);

        $response = $controller->merge($this->jsonRequest(['primary_id' => $primary, 'duplicate_ids' => ['ghost-id']]));

        $this->assertSame(404, $response->statusCode);
        $this->assertArrayHasKey('error', $this->decode($response->body));
        $this->assertNotNull($repo->findById($primary));
    }

    public function testMergeRejectsCrossLibraryDuplicate(): void
    {
        $repo = $this->makeRepo();
        $primary = $repo->seed(['library_id' => 'lib-1', 'parent_id' => null, 'type' => 'series', 'name' => 'Show']);
        $otherLibDup = $repo->seed(['library_id' => 'lib-2', 'parent_id' => null, 'type' => 'series', 'name' => 'Show']);
        $controller = $this->makeController($repo);

        $response = $controller->merge($this->jsonRequest(['primary_id' => $primary, 'duplicate_ids' => [$otherLibDup]]));

        $this->assertSame(400, $response->statusCode);
        $this->assertArrayHasKey('error', $this->decode($response->body));
        // The cross-library row is never deleted.
        $this->assertNotNull($repo->findById($otherLibDup));
    }

    public function testMergeRejectsCrossTypeDuplicate(): void
    {
        $repo = $this->makeRepo();
        $primary = $repo->seed(['library_id' => 'lib-1', 'parent_id' => null, 'type' => 'series', 'name' => 'Dune']);
        $movieDup = $repo->seed(['library_id' => 'lib-1', 'parent_id' => null, 'type' => 'movie', 'name' => 'Dune']);
        $controller = $this->makeController($repo);

        $response = $controller->merge($this->jsonRequest(['primary_id' => $primary, 'duplicate_ids' => [$movieDup]]));

        $this->assertSame(400, $response->statusCode);
        $this->assertArrayHasKey('error', $this->decode($response->body));
        // The cross-type row is never deleted.
        $this->assertNotNull($repo->findById($movieDup));
    }

    public function testMergeReturns503WhenNoTransactionalMerger(): void
    {
        $repo = $this->makeRepo();
        $primary = $repo->seed(['library_id' => 'lib-1', 'parent_id' => null, 'type' => 'movie', 'name' => 'Dune']);
        $dup = $repo->seed(['library_id' => 'lib-1', 'parent_id' => null, 'type' => 'movie', 'name' => 'Dune']);

        // Merger intentionally null (non-transactional connection at runtime).
        $controller = new AdminMergeController($repo, new DuplicateFinder($repo), null);

        $response = $controller->merge($this->jsonRequest(['primary_id' => $primary, 'duplicate_ids' => [$dup]]));

        $this->assertSame(503, $response->statusCode);
        $this->assertArrayHasKey('error', $this->decode($response->body));
        // No data is touched when merge is unavailable.
        $this->assertNotNull($repo->findById($dup));
    }

    /**
     * Pool-on mode (`DB_POOL_ENABLED=1`) hands SeriesMerger a
     * {@see PooledMySQLConnection} (which `extends Connection`, NOT
     * PhlixMySQLConnection) — and that class fully implements the base
     * transaction API. After the type-hint was widened to the base
     * {@see Connection}, the merge must build and succeed in this mode too
     * (previously it returned 503 ALWAYS). This pins that the pool-on merge
     * path is no longer dead.
     */
    public function testMergeWorksWhenConnectionIsPooled(): void
    {
        $repo = $this->makeRepo();
        $primary = $repo->seed(['library_id' => 'lib-1', 'parent_id' => null, 'type' => 'movie', 'name' => 'Dune']);
        $dup = $repo->seed(['library_id' => 'lib-1', 'parent_id' => null, 'type' => 'movie', 'name' => 'Dune']);

        // A PooledMySQLConnection delegates beginTrans/commit/rollBack to a
        // leased raw connection; outside a coroutine that is a single CLI lease
        // drawn from this factory, so we hand it a stub whose txn calls succeed.
        $controller = new AdminMergeController(
            $repo,
            new DuplicateFinder($repo),
            new SeriesMerger($repo, $this->pooledConnection()),
        );

        $response = $controller->merge($this->jsonRequest(['primary_id' => $primary, 'duplicate_ids' => [$dup]]));

        $this->assertSame(200, $response->statusCode);
        $body = $this->decode($response->body);
        $this->assertSame(0, $body['moved']);
        $this->assertSame(1, $body['deleted']);
        $this->assertNotNull($repo->findById($primary));
        $this->assertNull($repo->findById($dup));
    }

    // ─────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────

    private function makeController(ItemRepository $repo): AdminMergeController
    {
        $conn = $this->createMock(PhlixMySQLConnection::class);
        $conn->method('beginTrans')->willReturn(true);
        $conn->method('commitTrans')->willReturn(true);
        $conn->method('rollBackTrans')->willReturn(true);

        return new AdminMergeController($repo, new DuplicateFinder($repo), new SeriesMerger($repo, $conn));
    }

    /**
     * A real {@see PooledMySQLConnection} (the connection the coroutine pool
     * hands out when `DB_POOL_ENABLED=1`) whose leased raw connection is a stub
     * with succeeding transaction methods. Used to prove SeriesMerger accepts
     * the pooled connection via the base-Connection type hint.
     */
    private function pooledConnection(): PooledMySQLConnection
    {
        $raw = $this->createMock(Connection::class);
        $raw->method('beginTrans')->willReturn(true);
        $raw->method('commitTrans')->willReturn(true);
        $raw->method('rollBackTrans')->willReturn(true);

        return new PooledMySQLConnection(
            'localhost',
            3306,
            'user',
            'pass',
            'db',
            1,
            'utf8mb4',
            static fn (): Connection => $raw,
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(array $body): Request
    {
        $request = new Request();
        $request->body = $body;
        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * In-memory ItemRepository double: an id-keyed store supporting the
     * find/page/count/re-parent/delete primitives DuplicateFinder + SeriesMerger
     * use, plus a `seed()` helper. Mirrors the doubles used in
     * DuplicateFinderTest / SeriesMergerTest.
     *
     * @return ItemRepository&SeedableItemRepository
     */
    private function makeRepo(): ItemRepository
    {
        $mockConn = $this->createMock(Connection::class);

        return new class ($mockConn) extends ItemRepository implements SeedableItemRepository {
            /** @var array<string, array<string, mixed>> */
            private array $store = [];
            private int $seq = 0;

            /** @param array<string, mixed> $row */
            public function seed(array $row): string
            {
                $id = 'id-' . (++$this->seq);
                $this->store[$id] = array_merge([
                    'id' => $id,
                    'library_id' => null,
                    'parent_id' => null,
                    'name' => null,
                    'type' => null,
                    'path' => 'synthetic:' . $id,
                    'metadata_json' => [],
                ], $row, ['id' => $id]);
                return $id;
            }

            public function findById(string $id): ?array
            {
                return isset($this->store[$id]) ? $this->hydrate($this->store[$id]) : null;
            }

            public function getTopLevelByLibrary(string $libraryId, int $limit = 500, int $offset = 0): array
            {
                $matches = [];
                foreach ($this->store as $row) {
                    if (($row['library_id'] ?? null) === $libraryId && ($row['parent_id'] ?? null) === null) {
                        $matches[] = $this->hydrate($row);
                    }
                }
                usort($matches, static function (array $a, array $b): int {
                    $idA = is_string($a['id']) ? $a['id'] : '';
                    $idB = is_string($b['id']) ? $b['id'] : '';
                    return strcmp($idA, $idB);
                });
                return array_slice($matches, $offset, $limit);
            }

            public function countDescendants(string $itemId): int
            {
                $count = 0;
                $stack = [$itemId];
                while ($stack !== []) {
                    $parent = array_pop($stack);
                    foreach ($this->store as $row) {
                        if (($row['parent_id'] ?? null) === $parent) {
                            $count++;
                            $rowId = $row['id'];
                            if (is_string($rowId)) {
                                $stack[] = $rowId;
                            }
                        }
                    }
                }
                return $count;
            }

            public function findByParent(string $parentId): array
            {
                $out = [];
                foreach ($this->store as $row) {
                    if (($row['parent_id'] ?? null) === $parentId) {
                        $out[] = $this->hydrate($row);
                    }
                }
                return $out;
            }

            /**
             * @param array<int, string> $parentIds
             * @return array<string, array<int, array<string, mixed>>>
             */
            public function findByParents(array $parentIds): array
            {
                $out = [];
                foreach ($parentIds as $parentId) {
                    $out[$parentId] = $this->findByParent($parentId);
                }
                return $out;
            }

            public function update(string $id, array $data): void
            {
                if (!isset($this->store[$id])) {
                    return;
                }
                foreach ($data as $key => $value) {
                    $this->store[$id][$key] = $value;
                }
            }

            public function delete(string $id): void
            {
                unset($this->store[$id]);
            }

            /**
             * Hydrate a stored row into the production shape (decoded 'metadata').
             *
             * @param array<string, mixed> $row
             *
             * @return array<string, mixed>
             */
            private function hydrate(array $row): array
            {
                $meta = $row['metadata_json'] ?? [];
                if (is_string($meta)) {
                    $decoded = json_decode($meta, true);
                    $meta = is_array($decoded) ? $decoded : [];
                }
                $row['metadata'] = is_array($meta) ? $meta : [];
                return $row;
            }
        };
    }
}
