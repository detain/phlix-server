<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Collections;

use Phlix\Auth\SignedUrl;
use Phlix\Media\CollectionService;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Metadata\BackdropSrcset;
use Phlix\Media\Metadata\TmdbProvider;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Regression coverage for SV-0.6 — the TMDB-collections UUID-as-int bug.
 *
 * The original defect cast the media_item UUID PK to `int` before looking it up,
 * which collapsed every UUID to 0 (or a wrong small int) and made collection
 * sync a silent no-op. This suite pins:
 *   - findById() receives the EXACT UUID string (never "0" / an int cast);
 *   - the membership INSERT binds the UUID string as media_item_id;
 *   - the no-collection / no-tmdb-id paths remove membership and succeed;
 *   - PART A: a value that originated as an int and was coerced with `(string)`
 *     at the scanner call site (MediaScanner.php:1385) flows through as its exact
 *     string identity — it neither throws a TypeError under strict_types nor
 *     silently no-ops (the failure the surrounding catch would otherwise hide).
 */
final class CollectionServiceTest extends TestCase
{
    /**
     * A UUID whose leading character is a letter, so `(int) $uuid === 0` — this is
     * exactly the value that the original int-cast bug corrupted to 0.
     */
    private const MOVIE_UUID = 'ab12cd34-e5f6-7890-abcd-ef1234567890';

    private const TMDB_MOVIE_ID = 550;
    private const TMDB_COLLECTION_ID = 10;
    private const LOCAL_COLLECTION_ID = 42;

    /**
     * Core regression: findById() is called with the exact UUID string, never an
     * int-cast of it. Returning null short-circuits so no DB write happens.
     */
    public function testSyncLooksUpMovieByExactUuidStringNeverIntCast(): void
    {
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->expects($this->once())
            ->method('findById')
            ->with($this->identicalTo(self::MOVIE_UUID))
            ->willReturn(null);

        $calls = [];
        $db = $this->recordingDb($calls, null);

        $tmdb = $this->createMock(TmdbProvider::class);
        $tmdb->method('hasApiKey')->willReturn(true);
        $service = new CollectionService($db, $itemRepo, $tmdb);

        $result = $service->syncCollectionForMovie(self::MOVIE_UUID);

        $this->assertFalse($result);
        $this->assertSame([], $calls, 'No DB query should run when the movie is not found.');
    }

    /**
     * findById() returns null → sync returns false and writes nothing.
     */
    public function testSyncReturnsFalseAndWritesNothingWhenMovieMissing(): void
    {
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->method('findById')->with(self::MOVIE_UUID)->willReturn(null);

        $tmdb = $this->createMock(TmdbProvider::class);
        $tmdb->method('hasApiKey')->willReturn(true);
        $tmdb->expects($this->never())->method('getCollectionIdForMovie');

        $calls = [];
        $db = $this->recordingDb($calls, null);

        $service = new CollectionService($db, $itemRepo, $tmdb);

        $this->assertFalse($service->syncCollectionForMovie(self::MOVIE_UUID));
        $this->assertSame([], $calls);
    }

    /**
     * Movie metadata carries no numeric tmdb_id → removeCollectionMembership runs a
     * DELETE keyed on the exact UUID, and sync returns true (success, no collection).
     */
    public function testSyncWithoutTmdbIdDeletesMembershipByUuidAndSucceeds(): void
    {
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->method('findById')->with(self::MOVIE_UUID)->willReturn([
            'id' => self::MOVIE_UUID,
            'metadata_json' => json_encode(['title' => 'Untagged Movie']),
        ]);

        $tmdb = $this->createMock(TmdbProvider::class);
        $tmdb->method('hasApiKey')->willReturn(true);
        $tmdb->expects($this->never())->method('getCollectionIdForMovie');

        $calls = [];
        $db = $this->recordingDb($calls, null);

        $service = new CollectionService($db, $itemRepo, $tmdb);

        $this->assertTrue($service->syncCollectionForMovie(self::MOVIE_UUID));

        $deletes = $this->callsMatching($calls, 'DELETE FROM media_collection_members');
        $this->assertCount(1, $deletes, 'Exactly one membership DELETE expected.');
        $this->assertSame([self::MOVIE_UUID], $deletes[0]['params']);
        $this->assertSame([], $this->callsMatching($calls, 'INSERT INTO media_collection_members'));
    }

    /**
     * Movie has a tmdb_id but the provider reports no collection → membership is
     * removed by UUID and sync returns true.
     */
    public function testSyncWhenProviderReportsNoCollectionRemovesMembershipAndSucceeds(): void
    {
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->method('findById')->with(self::MOVIE_UUID)->willReturn([
            'id' => self::MOVIE_UUID,
            'metadata_json' => json_encode(['tmdb_id' => self::TMDB_MOVIE_ID]),
        ]);

        $tmdb = $this->createMock(TmdbProvider::class);
        $tmdb->method('hasApiKey')->willReturn(true);
        $tmdb->expects($this->once())
            ->method('getCollectionIdForMovie')
            ->with(self::TMDB_MOVIE_ID)
            ->willReturn(null);
        $tmdb->expects($this->never())->method('getCollection');

        $calls = [];
        $db = $this->recordingDb($calls, null);

        $service = new CollectionService($db, $itemRepo, $tmdb);

        $this->assertTrue($service->syncCollectionForMovie(self::MOVIE_UUID));

        $deletes = $this->callsMatching($calls, 'DELETE FROM media_collection_members');
        $this->assertCount(1, $deletes);
        $this->assertSame([self::MOVIE_UUID], $deletes[0]['params']);
    }

    /**
     * Happy path: the membership INSERT binds (localCollectionId, UUID, partOrder),
     * with media_item_id being the UUID string and tmdb_part_order = matching part
     * index + 1.
     */
    public function testSyncHappyPathInsertsMembershipKeyedByUuidWithPartOrder(): void
    {
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->expects($this->once())
            ->method('findById')
            ->with($this->identicalTo(self::MOVIE_UUID))
            ->willReturn([
                'id' => self::MOVIE_UUID,
                'metadata_json' => json_encode(['tmdb_id' => self::TMDB_MOVIE_ID]),
            ]);

        // The movie's tmdb_id sits at index 1 of the parts list → part order 2.
        $tmdb = $this->tmdbWithCollection([100, self::TMDB_MOVIE_ID, 200]);

        $calls = [];
        $db = $this->recordingDb($calls, $this->collectionRow());

        $service = new CollectionService($db, $itemRepo, $tmdb);

        $this->assertTrue($service->syncCollectionForMovie(self::MOVIE_UUID));

        $inserts = $this->callsMatching($calls, 'INSERT INTO media_collection_members');
        $this->assertCount(1, $inserts, 'Exactly one membership INSERT expected.');
        $this->assertSame(
            [self::LOCAL_COLLECTION_ID, self::MOVIE_UUID, 2],
            $inserts[0]['params'],
            'Membership must bind (collection_id, <uuid>, tmdb_part_order).'
        );
    }

    /**
     * Part-order fallback: the movie's tmdb_id is absent from the collection parts
     * → tmdb_part_order defaults to 0 (still keyed by the UUID).
     */
    public function testSyncFallsBackToPartOrderZeroWhenMovieNotInParts(): void
    {
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->method('findById')->with(self::MOVIE_UUID)->willReturn([
            'id' => self::MOVIE_UUID,
            'metadata_json' => json_encode(['tmdb_id' => self::TMDB_MOVIE_ID]),
        ]);

        // Parts do NOT contain TMDB_MOVIE_ID → fallback part order 0.
        $tmdb = $this->tmdbWithCollection([100, 200]);

        $calls = [];
        $db = $this->recordingDb($calls, $this->collectionRow());

        $service = new CollectionService($db, $itemRepo, $tmdb);

        $this->assertTrue($service->syncCollectionForMovie(self::MOVIE_UUID));

        $inserts = $this->callsMatching($calls, 'INSERT INTO media_collection_members');
        $this->assertCount(1, $inserts);
        $this->assertSame([self::LOCAL_COLLECTION_ID, self::MOVIE_UUID, 0], $inserts[0]['params']);
    }

    /**
     * A movie row with a null / absent metadata_json normalises to an empty array
     * (no tmdb_id) → membership is removed by UUID and sync succeeds.
     */
    public function testSyncWithNullMetadataJsonNormalisesAndRemovesMembership(): void
    {
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->method('findById')->with(self::MOVIE_UUID)->willReturn([
            'id' => self::MOVIE_UUID,
            'metadata_json' => null,
        ]);

        $tmdb = $this->createMock(TmdbProvider::class);
        $tmdb->method('hasApiKey')->willReturn(true);
        $tmdb->expects($this->never())->method('getCollectionIdForMovie');

        $calls = [];
        $db = $this->recordingDb($calls, null);

        $service = new CollectionService($db, $itemRepo, $tmdb);

        $this->assertTrue($service->syncCollectionForMovie(self::MOVIE_UUID));

        $deletes = $this->callsMatching($calls, 'DELETE FROM media_collection_members');
        $this->assertCount(1, $deletes);
        $this->assertSame([self::MOVIE_UUID], $deletes[0]['params']);
    }

    /**
     * Movie belongs to a collection but the collection cannot be fetched/created
     * (TMDB returns nothing) → sync returns false and writes no membership row.
     */
    public function testSyncReturnsFalseWhenCollectionCannotBeResolved(): void
    {
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->method('findById')->with(self::MOVIE_UUID)->willReturn([
            'id' => self::MOVIE_UUID,
            'metadata_json' => json_encode(['tmdb_id' => self::TMDB_MOVIE_ID]),
        ]);

        $tmdb = $this->createMock(TmdbProvider::class);
        $tmdb->method('hasApiKey')->willReturn(true);
        $tmdb->method('getCollectionIdForMovie')
            ->with(self::TMDB_MOVIE_ID)
            ->willReturn(self::TMDB_COLLECTION_ID);
        $tmdb->method('getCollection')
            ->with(self::TMDB_COLLECTION_ID)
            ->willReturn(null); // collection cannot be fetched → getOrCreateCollection() null

        $calls = [];
        $db = $this->recordingDb($calls, null);

        $service = new CollectionService($db, $itemRepo, $tmdb);

        $this->assertFalse($service->syncCollectionForMovie(self::MOVIE_UUID));
        $this->assertSame([], $this->callsMatching($calls, 'INSERT INTO media_collection_members'));
    }

    /**
     * PART A regression (MediaScanner.php:1385 `(string) $itemId`).
     *
     * A value that originated as an int and was coerced with `(string)` — exactly
     * what the scanner call site now does — must flow through as its precise string
     * identity: findById() receives the coerced string, and a membership row is
     * written keyed on that same string. This proves the coercion does not throw a
     * TypeError under strict_types and does not silently no-op.
     */
    public function testSyncAcceptsCoercedNumericStringIdWithoutTypeErrorOrNoOp(): void
    {
        $intId = 123456;
        $coerced = (string) $intId; // mirrors MediaScanner.php:1385 exactly

        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->expects($this->once())
            ->method('findById')
            ->with($this->identicalTo($coerced))
            ->willReturn([
                'id' => $coerced,
                'metadata_json' => json_encode(['tmdb_id' => self::TMDB_MOVIE_ID]),
            ]);

        $tmdb = $this->tmdbWithCollection([self::TMDB_MOVIE_ID]);

        $calls = [];
        $db = $this->recordingDb($calls, $this->collectionRow());

        $service = new CollectionService($db, $itemRepo, $tmdb);

        $this->assertTrue($service->syncCollectionForMovie($coerced));

        $inserts = $this->callsMatching($calls, 'INSERT INTO media_collection_members');
        $this->assertCount(1, $inserts, 'Coerced id must still write a membership row (no silent no-op).');
        $this->assertSame($coerced, $inserts[0]['params'][1]);
        $this->assertIsString($inserts[0]['params'][1]);
    }

    /**
     * S33 empty-API-key smell fix: when no TMDB key is configured, the injected
     * provider reports `hasApiKey() === false`, and sync must skip CLEANLY — no
     * item lookup, no TMDB request, no DB write — returning true (a no-op
     * success) rather than churning failing requests. This is the replacement for
     * the old dead `$tmdbApiKey` parameter callers filled with an empty string:
     * the key now lives on the provider, and its absence is a clean skip.
     */
    public function testSyncSkipsCleanlyWhenTmdbKeyIsNotConfigured(): void
    {
        $itemRepo = $this->createMock(ItemRepository::class);
        // With no key, the movie is never even looked up.
        $itemRepo->expects($this->never())->method('findById');

        $tmdb = $this->createMock(TmdbProvider::class);
        $tmdb->method('hasApiKey')->willReturn(false);
        $tmdb->expects($this->never())->method('getCollectionIdForMovie');
        $tmdb->expects($this->never())->method('getCollection');

        $calls = [];
        $db = $this->recordingDb($calls, null);

        $service = new CollectionService($db, $itemRepo, $tmdb);

        // No-op success: unconfigured TMDB is not an error, and nothing is written.
        $this->assertTrue($service->syncCollectionForMovie(self::MOVIE_UUID));
        $this->assertSame([], $calls, 'No DB query may run when TMDB is unconfigured.');
    }

    /**
     * S215 AC(3): the sync hits TMDB `/collection/{id}` AT MOST ONCE per
     * collection. The pre-S215 path fetched it twice on the happy path —
     * once inside getOrCreateCollection(), once again in syncCollectionForMovie()
     * for the part-order scan. Both now share a single fetch.
     */
    public function testSyncFetchesCollectionEndpointAtMostOncePerCollection(): void
    {
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->method('findById')->with(self::MOVIE_UUID)->willReturn([
            'id' => self::MOVIE_UUID,
            'metadata_json' => json_encode(['tmdb_id' => self::TMDB_MOVIE_ID]),
        ]);

        $tmdb = $this->createMock(TmdbProvider::class);
        $tmdb->method('hasApiKey')->willReturn(true);
        $tmdb->method('getCollectionIdForMovie')
            ->with(self::TMDB_MOVIE_ID)
            ->willReturn(self::TMDB_COLLECTION_ID);
        $tmdb->expects($this->once())
            ->method('getCollection')
            ->with(self::TMDB_COLLECTION_ID)
            ->willReturn([
                'name' => 'The Saga Collection',
                'overview' => null,
                'poster_path' => null,
                'backdrop_path' => null,
                'parts' => [['id' => self::TMDB_MOVIE_ID]],
            ]);

        $calls = [];
        $db = $this->recordingDb($calls, $this->collectionRow());

        $service = new CollectionService($db, $itemRepo, $tmdb);

        $this->assertTrue($service->syncCollectionForMovie(self::MOVIE_UUID));

        // And the single response still drives the correct part order (index 0 → 1).
        $inserts = $this->callsMatching($calls, 'INSERT INTO media_collection_members');
        $this->assertCount(1, $inserts);
        $this->assertSame([self::LOCAL_COLLECTION_ID, self::MOVIE_UUID, 1], $inserts[0]['params']);
    }

    /**
     * Builds a mock Connection whose query() records every (sql, params) pair into
     * $calls and returns the given collection row for the media_collections SELECT.
     *
     * @param array<int, array{sql: string, params: array<int, mixed>}> $calls
     * @param array<string, mixed>|null                                 $collectionRow
     */
    private function recordingDb(array &$calls, ?array $collectionRow): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            /**
             * @param array<int, mixed> $params
             * @return array<int, array<string, mixed>>
             */
            function (string $sql, array $params = []) use (&$calls, $collectionRow): array {
                $calls[] = ['sql' => $sql, 'params' => $params];
                if (str_contains($sql, 'FROM media_collections WHERE tmdb_collection_id')) {
                    return $collectionRow !== null ? [$collectionRow] : [];
                }
                return [];
            }
        );

        return $db;
    }

    /**
     * A mocked TmdbProvider that resolves the movie to a collection whose `parts`
     * carry the given TMDB ids (in order).
     *
     * @param array<int, int> $partIds
     */
    private function tmdbWithCollection(array $partIds): TmdbProvider
    {
        $parts = [];
        foreach ($partIds as $id) {
            $parts[] = ['id' => $id];
        }

        $tmdb = $this->createMock(TmdbProvider::class);
        $tmdb->method('hasApiKey')->willReturn(true);
        $tmdb->method('getCollectionIdForMovie')
            ->with(self::TMDB_MOVIE_ID)
            ->willReturn(self::TMDB_COLLECTION_ID);
        $tmdb->method('getCollection')
            ->with(self::TMDB_COLLECTION_ID)
            ->willReturn([
                'name' => 'The Saga Collection',
                'overview' => null,
                'poster_path' => null,
                'backdrop_path' => null,
                'parts' => $parts,
            ]);

        return $tmdb;
    }

    /**
     * The media_collections row returned by getOrCreateCollection's SELECT. `id`
     * must be a real int for the service's is_int() guard.
     *
     * @return array<string, mixed>
     */
    private function collectionRow(): array
    {
        return [
            'id' => self::LOCAL_COLLECTION_ID,
            'tmdb_collection_id' => self::TMDB_COLLECTION_ID,
            'name' => 'The Saga Collection',
            'overview' => null,
            'poster_url' => null,
            'backdrop_url' => null,
        ];
    }

    /**
     * Filters recorded query calls to those whose SQL contains $needle.
     *
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

    // -----------------------------------------------------------------
    // E-09 cohort 5a — RowMap fold legacy-oracle vectors
    // -----------------------------------------------------------------

    /**
     * The three collection-level read exits must keep producing the exact
     * legacy scalar shape for every NULL / wrong-type / missing-key variation
     * of the media_collections row, byte-identical to a verbatim transcription
     * of the pre-E-09 inline blocks (the triple-duplicate that now lives in
     * CollectionService::collectionRecordFromRow()).
     *
     * All three hosts run every vector, so per-block drift after the
     * extract-once would fail on the first vector.
     */
    public function testCollectionRecordShapingIsByteIdenticalToLegacyAcrossVectors(): void
    {
        /** @var array<string, array<string, mixed>> $vectors */
        $vectors = [
            'typed' => [
                'id' => self::LOCAL_COLLECTION_ID,
                'tmdb_collection_id' => self::TMDB_COLLECTION_ID,
                'name' => 'The Saga Collection',
                'overview' => 'Eight films',
                'poster_url' => null,
                'backdrop_url' => null,
            ],
            'nulls' => [
                'id' => null,
                'tmdb_collection_id' => null,
                'name' => null,
                'overview' => null,
                'poster_url' => null,
                'backdrop_url' => null,
            ],
            'missing-keys' => [
                'poster_url' => null,
                'backdrop_url' => null,
            ],
            'numeric-strings-are-not-ints' => [
                'id' => '42',
                'tmdb_collection_id' => '10',
                'name' => 'S',
                'overview' => 7,
                'poster_url' => null,
                'backdrop_url' => null,
            ],
            'floats-and-bools' => [
                'id' => 4.2,
                'tmdb_collection_id' => true,
                'name' => true,
                'overview' => 1.0,
                'poster_url' => null,
                'backdrop_url' => null,
            ],
            'array-values' => [
                'id' => [1],
                'tmdb_collection_id' => [],
                'name' => ['x'],
                'overview' => ['a' => 'b'],
                'poster_url' => null,
                'backdrop_url' => null,
            ],
            'tmdb-art' => [
                'id' => self::LOCAL_COLLECTION_ID,
                'tmdb_collection_id' => self::TMDB_COLLECTION_ID,
                'name' => 'S',
                'overview' => null,
                'poster_url' => 'https://image.tmdb.org/t/p/w500/p.jpg',
                'backdrop_url' => 'https://image.tmdb.org/t/p/w1280/b.jpg',
            ],
            'wrong-type-art' => [
                'id' => self::LOCAL_COLLECTION_ID,
                'tmdb_collection_id' => self::TMDB_COLLECTION_ID,
                'name' => 'S',
                'overview' => null,
                'poster_url' => 99,
                'backdrop_url' => true,
            ],
            'empty-string-art' => [
                'id' => self::LOCAL_COLLECTION_ID,
                'tmdb_collection_id' => self::TMDB_COLLECTION_ID,
                'name' => 'S',
                'overview' => '',
                'poster_url' => '',
                'backdrop_url' => '',
            ],
        ];

        foreach ($vectors as $label => $row) {
            $service = $this->recordShapeService($row);
            $expected = $this->legacyCollectionRecord($row);

            $this->assertSame(
                $expected,
                $service->getCollectionById(self::LOCAL_COLLECTION_ID),
                "getCollectionById() must match the legacy block for vector [$label]"
            );
            $this->assertSame(
                $expected,
                $service->getCollectionForItem(self::MOVIE_UUID),
                "getCollectionForItem() must match the legacy block for vector [$label]"
            );
            $this->assertSame(
                $expected,
                $service->getOrCreateCollection(self::TMDB_COLLECTION_ID),
                "getOrCreateCollection() must match the legacy block for vector [$label]"
            );
        }
    }

    /**
     * The getCollectionMembers() join-loop must keep the exact legacy shape
     * for the four strict-scalar sites (id/name/type string-guarded,
     * tmdb_part_order int-guarded) plus the raw metadata_json passthrough,
     * byte-identical to a verbatim transcription of the pre-E-09 block.
     *
     * Vectors keep metadata_json NULL/absent so the artwork legs resolve
     * deterministically through the empty decoded-metadata path.
     */
    public function testCollectionMemberShapingIsByteIdenticalToLegacyAcrossVectors(): void
    {
        /** @var array<string, array<string, mixed>> $vectors */
        $vectors = [
            'typed' => [
                'id' => self::MOVIE_UUID,
                'name' => 'The Fellowship of the Ring',
                'type' => 'movie',
                'metadata_json' => null,
                'tmdb_part_order' => 1,
            ],
            'nulls' => [
                'id' => null,
                'name' => null,
                'type' => null,
                'metadata_json' => null,
                'tmdb_part_order' => null,
            ],
            'missing-keys' => [],
            'numeric-string-part-order' => [
                'id' => self::MOVIE_UUID,
                'name' => 'M',
                'type' => 'movie',
                'metadata_json' => null,
                'tmdb_part_order' => '3',
            ],
            'wrong-type-fields' => [
                'id' => 99,
                'name' => 1.5,
                'type' => true,
                'metadata_json' => null,
                'tmdb_part_order' => [4],
            ],
        ];

        foreach ($vectors as $label => $row) {
            $service = $this->recordShapeService($row);

            $this->assertSame(
                [$this->legacyMemberRecord($row)],
                $service->getCollectionMembers(self::LOCAL_COLLECTION_ID),
                "getCollectionMembers() must match the legacy block for vector [$label]"
            );
        }
    }

    /**
     * A CollectionService whose Connection answers EVERY SELECT with the one
     * given row (the four read exits each re-issue their own SELECT; every
     * vector row is shaped to satisfy whichever table the SQL targets).
     *
     * @param array<string, mixed> $row
     */
    private function recordShapeService(array $row): CollectionService
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            /**
             * @param array<int, mixed> $params
             * @return array<int, array<string, mixed>>
             */
            static fn (string $sql, array $params = []): array => str_starts_with(ltrim($sql), 'SELECT')
                ? [$row]
                : []
        );

        $tmdb = $this->createMock(TmdbProvider::class);
        $tmdb->method('hasApiKey')->willReturn(true);
        $tmdb->method('getCollection')->willReturn([
            'name' => 'The Saga Collection',
            'overview' => null,
            'poster_path' => null,
            'backdrop_path' => null,
            'parts' => [],
        ]);

        return new CollectionService($db, $this->createMock(ItemRepository::class), $tmdb);
    }

    /**
     * Verbatim transcription of the pre-E-09 inline decode block (the
     * byte-identical triple at CollectionService.php:178-190/:401-413/:442-454
     * as of commit 8d6dc80f).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function legacyCollectionRecord(array $row): array
    {
        $storedPoster = is_string($row['poster_url'] ?? null) ? $row['poster_url'] : null;
        $storedBackdrop = is_string($row['backdrop_url'] ?? null) ? $row['backdrop_url'] : null;

        return [
            'id' => is_int($row['id'] ?? null) ? $row['id'] : 0,
            'tmdb_collection_id' => is_int($row['tmdb_collection_id'] ?? null) ? $row['tmdb_collection_id'] : 0,
            'name' => is_string($row['name'] ?? null) ? $row['name'] : '',
            'overview' => is_string($row['overview'] ?? null) ? $row['overview'] : null,
            'poster_url' => SignedUrl::refreshArtworkUrl($storedPoster),
            'backdrop_url' => SignedUrl::refreshArtworkUrl(
                BackdropSrcset::largeUrl($storedBackdrop) ?? $storedBackdrop
            ),
        ];
    }

    /**
     * Verbatim transcription of the pre-E-09 getCollectionMembers() block
     * (CollectionService.php:341-365 as of commit 8d6dc80f) for vectors whose
     * metadata_json decodes to the empty array.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function legacyMemberRecord(array $row): array
    {
        /** @var array<string, mixed> $metadata */
        $metadata = [];
        $storedPoster = is_string($metadata['poster_url'] ?? null) && $metadata['poster_url'] !== ''
            ? $metadata['poster_url']
            : null;
        $storedBackdrop = is_string($metadata['backdrop_url'] ?? null) && $metadata['backdrop_url'] !== ''
            ? $metadata['backdrop_url']
            : null;

        return [
            'id' => is_string($row['id'] ?? null) ? $row['id'] : '',
            'name' => is_string($row['name'] ?? null) ? $row['name'] : '',
            'type' => is_string($row['type'] ?? null) ? $row['type'] : '',
            'metadata_json' => $row['metadata_json'] ?? null,
            'poster_url' => SignedUrl::refreshArtworkUrl($storedPoster),
            'backdrop_url' => SignedUrl::refreshArtworkUrl(
                BackdropSrcset::rowUrl($storedBackdrop) ?? $storedBackdrop
            ),
            'tmdb_part_order' => is_int($row['tmdb_part_order'] ?? null) ? $row['tmdb_part_order'] : 0,
        ];
    }
}
