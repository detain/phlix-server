<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Collections;

use Phlix\Auth\SignedUrl;
use Phlix\Media\CollectionService;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Metadata\TmdbProvider;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * S436 — Headline AC: `getCollectionMembers()` executes WITHOUT MySQL 1054 against
 * a real migrated schema and shapes artwork from `metadata_json`.
 *
 * Before the fix the method SELECTed `m.poster_url, m.backdrop_url` from `media_items`,
 * columns that do not exist (only `media_collections` has them, migration 064).
 * Any production call raised:
 *   ERROR 1054 (42S22): Unknown column 'm.poster_url' in 'field list'
 *
 * This test proves:
 *  1. The corrected query runs green on a real MySQL schema built from migrations.
 *  2. Members are returned from actual DB rows (not hand-built arrays).
 *  3. Artwork URLs flow through the S104 shaping contract (SignedUrl + BackdropSrcset).
 *
 * @see CollectionService::getCollectionMembers()
 */
final class CollectionMembersRealDbTest extends TestCase
{
    use RequiresRealDatabase;

    private const LANE_TOKEN = 'S436COLLECTIONCOLUMNSX2W7';

    /** Unique per-run namespace to avoid collisions on a shared test DB. */
    private const RUN_PREFIX = 's436_';

    private Connection $db;
    private string $libId = '';
    private string $itemId = '';
    private int $collectionId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->requireRealDatabase(
            'skipping S436 real-DB collection-members proof. Runs in CI with MySQL.'
        );

        $this->libId = self::RUN_PREFIX . 'lib_' . bin2hex(random_bytes(4));
        $this->itemId = self::RUN_PREFIX . 'item_' . bin2hex(random_bytes(4));
        $this->collectionId = random_int(900000, 999999);

        putenv('PHLIX_SIGNED_URL_SECRET=s436-collection-members-secret');
        SignedUrl::resetSharedForTesting();

        $this->seedSchema();
    }

    protected function tearDown(): void
    {
        $this->cleanSeed();
        putenv('PHLIX_SIGNED_URL_SECRET');
        SignedUrl::resetSharedForTesting();
        parent::tearDown();
    }

    /**
     * Proves getCollectionMembers() executes without 1054 and returns members
     * whose artwork is shaped from metadata_json.
     */
    public function testGetCollectionMembersExecutesWithoutErrorAndShapesArtworkFromMetadataJson(): void
    {
        $service = $this->buildService();

        $members = $service->getCollectionMembers($this->collectionId);

        // AC 1: No exception raised (would have been 1054 before the fix)
        $this->assertNotEmpty($members, self::LANE_TOKEN . ': getCollectionMembers must return members');

        // AC 2: Members returned from the real DB row
        $this->assertCount(1, $members, 'exactly one member inserted and expected back');

        $member = $members[0];

        // Verify identity fields
        $this->assertSame($this->itemId, $member['id']);
        $this->assertSame('S436 Proof Movie', $member['name']);
        $this->assertSame('movie', $member['type']);
        $this->assertSame(1, $member['tmdb_part_order']);

        // AC 3: Artwork shaped from metadata_json (not dead columns)
        // The stored metadata_json contains a TMDB /w500 backdrop.
        // BackdropSrcset::rowUrl() should swap it to the row width /w780.
        $this->assertIsString($member['backdrop_url']);
        $this->assertStringContainsString(
            '/w780/',
            (string) $member['backdrop_url'],
            'backdrop must be width-swapped to row budget via BackdropSrcset::rowUrl()'
        );

        // Poster passes through unchanged (external TMDB URL, no re-mint applies)
        $this->assertSame(
            'https://image.tmdb.org/t/p/w500/ps.jpg',
            $member['poster_url'],
            'external TMDB poster passes through without modification'
        );

        // metadata_json is preserved in the response for the router layer
        $this->assertIsString($member['metadata_json']);
    }

    /**
     * Proves a null/missing metadata_json yields null artwork gracefully (no crash).
     */
    public function testMemberWithNullMetadataJsonReturnsNullArtwork(): void
    {
        // Insert an item with NULL metadata_json
        $nullMetaItemId = self::RUN_PREFIX . 'nullmeta_' . bin2hex(random_bytes(4));
        $this->db->query(
            'INSERT INTO media_items (id, library_id, name, type, path, metadata_json)
             VALUES (?, ?, ?, ?, ?, NULL)',
            [$nullMetaItemId, $this->libId, 'No Meta Movie', 'movie', '/tmp/s436-null.mkv']
        );
        $this->db->query(
            'INSERT INTO media_collection_members (collection_id, media_item_id, tmdb_part_order)
             VALUES (?, ?, ?)',
            [$this->collectionId, $nullMetaItemId, 2]
        );

        $service = $this->buildService();
        $members = $service->getCollectionMembers($this->collectionId);

        $this->assertCount(2, $members);

        // The second member (no metadata) must have null artwork
        $nullMember = $members[1];
        $this->assertSame($nullMetaItemId, $nullMember['id']);
        $this->assertNull($nullMember['poster_url']);
        $this->assertNull($nullMember['backdrop_url']);
    }

    // ---------------------------------------------------------------
    // Schema seeding (relies on migrations already applied to the DB)
    // ---------------------------------------------------------------

    private function seedSchema(): void
    {
        // Library
        $this->db->query(
            'INSERT INTO libraries (id, name, type, paths) VALUES (?, ?, ?, ?)',
            [$this->libId, 'S436 Test Lib', 'movie', json_encode(['/tmp'])]
        );

        // Media item with artwork in metadata_json (the real storage pattern)
        $metadata = json_encode([
            'poster_url' => 'https://image.tmdb.org/t/p/w500/ps.jpg',
            'backdrop_url' => 'https://image.tmdb.org/t/p/w500/bd.jpg',
        ]);
        $this->db->query(
            'INSERT INTO media_items (id, library_id, name, type, path, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$this->itemId, $this->libId, 'S436 Proof Movie', 'movie', '/tmp/s436-proof.mkv', $metadata]
        );

        // Collection
        $this->db->query(
            'INSERT INTO media_collections (id, tmdb_collection_id, name) VALUES (?, ?, ?)',
            [$this->collectionId, $this->collectionId, 'S436 Proof Collection']
        );

        // Membership
        $this->db->query(
            'INSERT INTO media_collection_members (collection_id, media_item_id, tmdb_part_order)
             VALUES (?, ?, ?)',
            [$this->collectionId, $this->itemId, 1]
        );
    }

    private function cleanSeed(): void
    {
        // Delete in FK-safe order
        $this->db->query(
            'DELETE FROM media_collection_members WHERE collection_id = ?',
            [$this->collectionId]
        );
        $this->db->query('DELETE FROM media_items WHERE id LIKE ?', [self::RUN_PREFIX . '%']);
        $this->db->query('DELETE FROM media_collections WHERE id = ?', [$this->collectionId]);
        $this->db->query('DELETE FROM libraries WHERE id = ?', [$this->libId]);
    }

    // ---------------------------------------------------------------
    // Service builder
    // ---------------------------------------------------------------

    private function buildService(): CollectionService
    {
        $itemRepo = $this->createMock(ItemRepository::class);
        $tmdb = $this->createMock(TmdbProvider::class);

        return new CollectionService($this->db, $itemRepo, $tmdb);
    }
}
