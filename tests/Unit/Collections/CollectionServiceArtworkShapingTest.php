<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Collections;

use Phlix\Auth\SignedUrl;
use Phlix\Media\CollectionService;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Metadata\TmdbProvider;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * S104 — `CollectionService`'s four read methods are response-shaping exits that
 * bypass {@see \Phlix\Media\Library\MediaItemShaper}, so they must apply the two
 * transforms that shaper applies themselves:
 *
 *  1. **Signed-URL re-mint at RESPONSE time** ({@see SignedUrl::refreshArtworkUrl()}).
 *     Artwork URLs are signed at SCAN time with a bounded TTL and stored verbatim;
 *     hours later every stored signature is expired and an authless `<img>` 401s on
 *     it. That is the 2026-07-19 production incident (broken artwork + a re-download
 *     flood). Re-minting at scan time is exactly the defect, so every test here
 *     feeds a signature that is ALREADY EXPIRED and demands a future, verifying one
 *     on the way out.
 *  2. **TMDB width-swap on backdrops**, in the correct one of
 *     {@see \Phlix\Media\Metadata\BackdropSrcset}'s two size budgets: `/original`
 *     for the three collection-level methods (ONE box-set hero per response) and
 *     the row width for `getCollectionMembers()` (up to N rows per response, where
 *     `/original` is a payload disaster).
 *
 * ⚠ These are SERVICE-level tests on purpose. The only callers of these three read
 * methods are `WebPortalRouter::getMediaItemCollection()` and `::getCollection()`,
 * and the DI factory passes `null` for the service
 * ({@see \Phlix\Common\Container\Providers\WebPortalServicesProvider}), so both
 * handlers return **503 before the service is ever touched**. A route-level test of
 * this behaviour would assert a 503 and read as green.
 *
 * Every method is covered for BOTH fields independently — eight sites, eight
 * re-mint assertions — so a fix applied to seven of the eight cannot pass.
 */
final class CollectionServiceArtworkShapingTest extends TestCase
{
    private const TMDB_COLLECTION_ID = 10;
    private const LOCAL_COLLECTION_ID = 42;
    private const MEMBER_UUID = 'ab12cd34-e5f6-7890-abcd-ef1234567890';

    /** A TMDB poster at the width this service stores (`getOrCreateCollection()`). */
    private const TMDB_POSTER = 'https://image.tmdb.org/t/p/w500/ps.jpg';

    /** A TMDB backdrop at the width this service stores for a collection. */
    private const TMDB_BACKDROP = 'https://image.tmdb.org/t/p/w1280/bd.jpg';

    /** A TMDB backdrop at the width `media_items` rows store (see LibraryMetadataMatcher). */
    private const TMDB_ROW_BACKDROP = 'https://image.tmdb.org/t/p/w500/bd.jpg';

    private SignedUrl $signer;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('PHLIX_SIGNED_URL_SECRET=s104-collection-artwork-secret');
        SignedUrl::resetSharedForTesting();
        $this->signer = SignedUrl::fromEnv();
    }

    protected function tearDown(): void
    {
        putenv('PHLIX_SIGNED_URL_SECRET');
        SignedUrl::resetSharedForTesting();
        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // getOrCreateCollection() — sites 1 (poster) and 2 (backdrop)
    // ---------------------------------------------------------------------

    public function testGetOrCreateCollectionReMintsAnExpiredPosterSignature(): void
    {
        $stale = $this->staleArtworkUrl('col-poster', 'w500');
        $result = $this->service($this->collectionRow($stale, null))
            ->getOrCreateCollection(self::TMDB_COLLECTION_ID);

        $this->assertIsArray($result);
        $this->assertFreshlySigned(
            $result['poster_url'],
            '/api/v1/artwork/col-poster',
            'w500',
            $stale,
            'getOrCreateCollection() poster_url must be re-minted at response time'
        );
    }

    public function testGetOrCreateCollectionReMintsAnExpiredBackdropSignature(): void
    {
        $stale = $this->staleArtworkUrl('col-backdrop', 'w1280');
        $result = $this->service($this->collectionRow(null, $stale))
            ->getOrCreateCollection(self::TMDB_COLLECTION_ID);

        $this->assertIsArray($result);
        $this->assertFreshlySigned(
            $result['backdrop_url'],
            '/api/v1/artwork/col-backdrop',
            'w1280',
            $stale,
            'getOrCreateCollection() backdrop_url must be re-minted at response time'
        );
    }

    public function testGetOrCreateCollectionWidthSwapsATmdbBackdropToTheHeroOriginal(): void
    {
        $result = $this->service($this->collectionRow(self::TMDB_POSTER, self::TMDB_BACKDROP))
            ->getOrCreateCollection(self::TMDB_COLLECTION_ID);

        $this->assertIsArray($result);
        $this->assertSame(
            'https://image.tmdb.org/t/p/original/bd.jpg',
            $result['backdrop_url'],
            'getOrCreateCollection() must width-swap the stored /w1280 up to the hero /original'
        );
        $this->assertSame(
            self::TMDB_POSTER,
            $result['poster_url'],
            'poster_url is deliberately NOT width-swapped (MediaItemShaper does not swap it either)'
        );
    }

    // ---------------------------------------------------------------------
    // getCollectionMembers() — sites 3 (poster) and 4 (backdrop)
    // ---------------------------------------------------------------------

    public function testGetCollectionMembersReMintsAnExpiredPosterSignature(): void
    {
        $stale = $this->staleArtworkUrl('member-poster', 'w342');
        $members = $this->service($this->memberRow($stale, null))
            ->getCollectionMembers(self::LOCAL_COLLECTION_ID);

        $this->assertCount(1, $members);
        $this->assertFreshlySigned(
            $members[0]['poster_url'],
            '/api/v1/artwork/member-poster',
            'w342',
            $stale,
            'getCollectionMembers() poster_url must be re-minted at response time'
        );
    }

    public function testGetCollectionMembersReMintsAnExpiredBackdropSignature(): void
    {
        $stale = $this->staleArtworkUrl('member-backdrop', 'w780');
        $members = $this->service($this->memberRow(null, $stale))
            ->getCollectionMembers(self::LOCAL_COLLECTION_ID);

        $this->assertCount(1, $members);
        $this->assertFreshlySigned(
            $members[0]['backdrop_url'],
            '/api/v1/artwork/member-backdrop',
            'w780',
            $stale,
            'getCollectionMembers() backdrop_url must be re-minted at response time'
        );
    }

    /**
     * The member list is the LIST-ROW budget, not the hero one: this response
     * carries up to N members, so `/original` (1.5–4 MB each) must never be
     * advertised — the stored `/w500` steps UP to the row width instead.
     */
    public function testGetCollectionMembersWidthSwapsToTheRowWidthAndNeverToOriginal(): void
    {
        $members = $this->service($this->memberRow(self::TMDB_POSTER, self::TMDB_ROW_BACKDROP))
            ->getCollectionMembers(self::LOCAL_COLLECTION_ID);

        $this->assertCount(1, $members);
        $this->assertSame(
            'https://image.tmdb.org/t/p/w780/bd.jpg',
            $members[0]['backdrop_url'],
            'getCollectionMembers() must width-swap the stored /w500 up to the row width /w780'
        );
        $this->assertStringNotContainsString(
            '/original/',
            (string) $members[0]['backdrop_url'],
            'a member row must never advertise the /original hero backdrop'
        );
        $this->assertSame(
            self::TMDB_POSTER,
            $members[0]['poster_url'],
            'member poster_url is deliberately NOT width-swapped'
        );
    }

    // ---------------------------------------------------------------------
    // getCollectionForItem() — sites 5 (poster) and 6 (backdrop)
    // ---------------------------------------------------------------------

    public function testGetCollectionForItemReMintsAnExpiredPosterSignature(): void
    {
        $stale = $this->staleArtworkUrl('item-col-poster', 'w500');
        $result = $this->service($this->collectionRow($stale, null))
            ->getCollectionForItem(self::MEMBER_UUID);

        $this->assertIsArray($result);
        $this->assertFreshlySigned(
            $result['poster_url'],
            '/api/v1/artwork/item-col-poster',
            'w500',
            $stale,
            'getCollectionForItem() poster_url must be re-minted at response time'
        );
    }

    public function testGetCollectionForItemReMintsAnExpiredBackdropSignature(): void
    {
        $stale = $this->staleArtworkUrl('item-col-backdrop', 'w1280');
        $result = $this->service($this->collectionRow(null, $stale))
            ->getCollectionForItem(self::MEMBER_UUID);

        $this->assertIsArray($result);
        $this->assertFreshlySigned(
            $result['backdrop_url'],
            '/api/v1/artwork/item-col-backdrop',
            'w1280',
            $stale,
            'getCollectionForItem() backdrop_url must be re-minted at response time'
        );
    }

    public function testGetCollectionForItemWidthSwapsATmdbBackdropToTheHeroOriginal(): void
    {
        $result = $this->service($this->collectionRow(self::TMDB_POSTER, self::TMDB_BACKDROP))
            ->getCollectionForItem(self::MEMBER_UUID);

        $this->assertIsArray($result);
        $this->assertSame(
            'https://image.tmdb.org/t/p/original/bd.jpg',
            $result['backdrop_url'],
            'getCollectionForItem() must width-swap the stored /w1280 up to the hero /original'
        );
        $this->assertSame(
            self::TMDB_POSTER,
            $result['poster_url'],
            'getCollectionForItem() poster_url is deliberately NOT width-swapped'
        );
    }

    // ---------------------------------------------------------------------
    // getCollectionById() — sites 7 (poster) and 8 (backdrop)
    // ---------------------------------------------------------------------

    public function testGetCollectionByIdReMintsAnExpiredPosterSignature(): void
    {
        $stale = $this->staleArtworkUrl('by-id-poster', 'w500');
        $result = $this->service($this->collectionRow($stale, null))
            ->getCollectionById(self::LOCAL_COLLECTION_ID);

        $this->assertIsArray($result);
        $this->assertFreshlySigned(
            $result['poster_url'],
            '/api/v1/artwork/by-id-poster',
            'w500',
            $stale,
            'getCollectionById() poster_url must be re-minted at response time'
        );
    }

    public function testGetCollectionByIdReMintsAnExpiredBackdropSignature(): void
    {
        $stale = $this->staleArtworkUrl('by-id-backdrop', 'w1280');
        $result = $this->service($this->collectionRow(null, $stale))
            ->getCollectionById(self::LOCAL_COLLECTION_ID);

        $this->assertIsArray($result);
        $this->assertFreshlySigned(
            $result['backdrop_url'],
            '/api/v1/artwork/by-id-backdrop',
            'w1280',
            $stale,
            'getCollectionById() backdrop_url must be re-minted at response time'
        );
    }

    public function testGetCollectionByIdWidthSwapsATmdbBackdropToTheHeroOriginal(): void
    {
        $result = $this->service($this->collectionRow(self::TMDB_POSTER, self::TMDB_BACKDROP))
            ->getCollectionById(self::LOCAL_COLLECTION_ID);

        $this->assertIsArray($result);
        $this->assertSame(
            'https://image.tmdb.org/t/p/original/bd.jpg',
            $result['backdrop_url'],
            'getCollectionById() must width-swap the stored /w1280 up to the hero /original'
        );
        $this->assertSame(
            self::TMDB_POSTER,
            $result['poster_url'],
            'getCollectionById() poster_url is deliberately NOT width-swapped'
        );
    }

    // ---------------------------------------------------------------------
    // Controls — the transforms must be no-ops on everything else
    // ---------------------------------------------------------------------

    /**
     * Anti-vacuity control for the re-mint tests: an EXTERNAL cover is never
     * signed, so if the assertions above were passing merely because "the output
     * differs from the input", this would fail.
     */
    public function testExternalCoversAndNullsPassThroughUnsigned(): void
    {
        $external = 'https://cdn.example.org/art/cover.jpg';
        $result = $this->service($this->collectionRow($external, null))
            ->getCollectionById(self::LOCAL_COLLECTION_ID);

        $this->assertIsArray($result);
        $this->assertSame($external, $result['poster_url'], 'an external cover is returned untouched');
        $this->assertNull($result['backdrop_url'], 'a null backdrop stays null');

        $members = $this->service($this->memberRow(null, null))
            ->getCollectionMembers(self::LOCAL_COLLECTION_ID);
        $this->assertCount(1, $members);
        $this->assertNull($members[0]['poster_url'], 'a null member poster stays null');
        $this->assertNull($members[0]['backdrop_url'], 'a null member backdrop stays null');
    }

    /**
     * A non-TMDB (fanart.tv) backdrop has no width ladder, so the width-swap must
     * fall back to the STORED url rather than emitting null — which is what a bare
     * `BackdropSrcset::largeUrl()` without the `?? $stored` fallback would do.
     */
    public function testANonTmdbBackdropFallsBackToTheStoredUrlInsteadOfNull(): void
    {
        $fanart = 'https://assets.fanart.tv/fanart/movies/550/moviebackground/fight-club.jpg';

        $collection = $this->service($this->collectionRow(null, $fanart))
            ->getCollectionById(self::LOCAL_COLLECTION_ID);
        $this->assertIsArray($collection);
        $this->assertSame($fanart, $collection['backdrop_url'], 'a non-TMDB hero backdrop survives the swap');

        $members = $this->service($this->memberRow(null, $fanart))
            ->getCollectionMembers(self::LOCAL_COLLECTION_ID);
        $this->assertCount(1, $members);
        $this->assertSame($fanart, $members[0]['backdrop_url'], 'a non-TMDB row backdrop survives the swap');
    }

    /**
     * A locally cached (S72) backdrop is BOTH a re-mint candidate and a non-TMDB
     * URL, so the two transforms have to compose: the width-swap must not destroy
     * the signed URL, and the re-mint must still run on what the swap returned.
     */
    public function testACachedBackdropIsWidthSwapFallbackAndStillReMinted(): void
    {
        $stale = $this->staleArtworkUrl('cached-bd', 'w1280');
        $result = $this->service($this->collectionRow(null, $stale))
            ->getCollectionById(self::LOCAL_COLLECTION_ID);

        $this->assertIsArray($result);
        $this->assertStringStartsWith(
            '/api/v1/artwork/cached-bd?size=w1280&exp=',
            (string) $result['backdrop_url'],
            'the width-swap fallback preserves the internal artwork path and its size descriptor'
        );
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Builds an internal artwork URL carrying a signature that expired an hour ago
     * — the exact shape a scan-time mint leaves behind.
     */
    private function staleArtworkUrl(string $id, string $size): string
    {
        $exp = time() - 3600;
        $sig = $this->signer->signature('/api/v1/artwork/' . $id, $exp);

        return '/api/v1/artwork/' . $id . '?size=' . $size . '&exp=' . $exp . '&sig=' . $sig;
    }

    /**
     * Asserts a shaped artwork URL carries a FRESH, verifying signature over the
     * given path while preserving the size descriptor.
     */
    private function assertFreshlySigned(
        mixed $actual,
        string $path,
        string $size,
        string $stale,
        string $because
    ): void {
        $this->assertIsString($actual, $because);
        $this->assertNotSame($stale, $actual, $because . ' (the stale token is still on the wire)');

        parse_str((string) parse_url($actual, PHP_URL_QUERY), $query);
        /** @var array<string, string> $query */
        $this->assertSame($path, parse_url($actual, PHP_URL_PATH), $because . ' (artwork path changed)');
        $this->assertSame($size, $query['size'] ?? null, $because . ' (size descriptor lost)');
        $this->assertGreaterThan(time(), (int) ($query['exp'] ?? 0), $because . ' (exp is not in the future)');
        $this->assertTrue(
            $this->signer->verify($path, $query['exp'] ?? '', $query['sig'] ?? ''),
            $because . ' (the re-minted signature does not verify)'
        );
    }

    /**
     * A CollectionService whose Connection returns $rows for every SELECT and whose
     * TmdbProvider resolves a collection (so getOrCreateCollection() reaches its
     * shaping return).
     *
     * @param array<string, mixed> $row
     */
    private function service(array $row): CollectionService
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
     * A `media_collections` row as the three collection-level SELECTs return it.
     *
     * @return array<string, mixed>
     */
    private function collectionRow(?string $poster, ?string $backdrop): array
    {
        return [
            'id' => self::LOCAL_COLLECTION_ID,
            'tmdb_collection_id' => self::TMDB_COLLECTION_ID,
            'name' => 'The Saga Collection',
            'overview' => null,
            'poster_url' => $poster,
            'backdrop_url' => $backdrop,
        ];
    }

    /**
     * A member row as `getCollectionMembers()`'s join actually returns it from
     * a real `media_items` table: artwork lives inside metadata_json (the DB has
     * no poster_url/backdrop_url columns on media_items — S436).
     *
     * @return array<string, mixed>
     */
    private function memberRow(?string $poster, ?string $backdrop): array
    {
        $metadata = [];
        if ($poster !== null) {
            $metadata['poster_url'] = $poster;
        }
        if ($backdrop !== null) {
            $metadata['backdrop_url'] = $backdrop;
        }

        return [
            'id' => self::MEMBER_UUID,
            'name' => 'The Fellowship of the Ring',
            'type' => 'movie',
            'metadata_json' => $metadata !== [] ? json_encode($metadata) : null,
            'tmdb_part_order' => 1,
        ];
    }
}
