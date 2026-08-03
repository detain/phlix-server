<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media;

use Phlix\Auth\AuthManager;
use Phlix\Common\Uuid;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Markers\MarkerService;
use Phlix\Media\Markers\PlaybackMarkerService;
use Phlix\Server\Http\Request;
use Phlix\Server\WebPortal\WebPortalRouter;
use Phlix\Session\PlaybackController;
use Phlix\Session\SessionManager;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Real-DB proof for the S101 LIST-shape backdrop — the fix that gives a
 * wide-backdrop row renderer something to paint.
 *
 * **Why this exists next to the unit tests.** `MediaItemShaperTest` and
 * `WebPortalRouterMediaTest` both already assert the backdrop keys, but both hand
 * the shaper a row that ALREADY carries a decoded `'metadata' => [...]` array —
 * `WebPortalRouterMediaTest` mocks `ItemRepository::query()` outright. So neither
 * can see the one step that stands between a stored row and a painted backdrop:
 * `ItemRepository::hydrateItem()` decoding `media_items.metadata_json` into
 * `metadata`. A test that constructs its own already-hydrated input cannot
 * distinguish "handles real rows" from "handles rows that exist only in this
 * file" — the exact class of miss that let the music read path (S99) survive
 * three green mock-only diagnoses.
 *
 * **The other thing only a real row can answer:** does an item that was scanned
 * BEFORE S101 expose a backdrop, or does it need a rescan? The fixture answers it
 * by execution — it writes the row the way the scanner does (no artwork keys at
 * all), then enriches it the way `LibraryMetadataMatcher::persistMetadata()` does
 * (`ItemRepository::update()` with a merged `metadata_json`), and reads the LIST
 * endpoint back. No scan is involved in the read.
 *
 * Driven through `WebPortalRouter::dispatch()` because that is the ONE seam both
 * HTTP entry points share: `public/index.php` resolves `WebPortalRouter` from the
 * container and calls `dispatch()` (public/index.php:240-242), and the resident
 * `Phlix\Server\Workerman\HttpHandler` falls through to the same object when
 * `Application`'s router 404s. `/api/v1/media` has exactly ONE registration
 * repo-wide (`WebPortalRouter.php:309`), so proving the key here proves it on
 * both paths.
 */
final class MediaListBackdropIntegrationTest extends TestCase
{
    use RequiresRealDatabase;

    /** A TMDB backdrop is STORED at /w500 — the width the resolver harvests. */
    private const STORED_TMDB_BACKDROP = 'https://image.tmdb.org/t/p/w500/s101bg.jpg';

    /** fanart.tv has no width ladder, so the stored URL must pass through as-is. */
    private const STORED_FANART_BACKDROP = 'https://assets.fanart.tv/fanart/music/s101/bg.jpg';

    private ?Connection $db = null;

    private ?ItemRepository $items = null;

    private string $libraryId = '';

    /** @var array<string, string> Logical fixture key → media_items UUID. */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->requireRealDatabase('skipping the S101 list-backdrop test. Runs in CI.');
        $this->assertNotNull($this->db);

        $this->items = new ItemRepository($this->db);
        $this->libraryId = Uuid::v4();

        $this->db->query(
            "INSERT INTO libraries (id, name, type, paths) VALUES (?, ?, 'movie', '[]')",
            [$this->libraryId, 'S101 Backdrop IT Library'],
        );

        $this->seedFixtures();
    }

    protected function tearDown(): void
    {
        $db = $this->db;
        if ($db !== null) {
            $db->query('DELETE FROM media_items WHERE library_id = ?', [$this->libraryId]);
            $db->query('DELETE FROM libraries WHERE id = ?', [$this->libraryId]);
        }

        parent::tearDown();
    }

    /**
     * Guards the fixture: the enriched row must really be stored as JSON TEXT in
     * `media_items.metadata_json`, with the backdrop at the width the resolver
     * harvests. If a future change starts storing a pre-widened URL — or storing
     * the metadata anywhere else — this fails instead of the read tests below
     * passing for the wrong reason.
     */
    public function testTheFixtureStoresTheBackdropAsRealJsonInTheColumn(): void
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $rows = $db->query(
            'SELECT metadata_json FROM media_items WHERE id = ?',
            [$this->ids['enriched']],
        );
        $this->assertIsArray($rows);
        $this->assertArrayHasKey(0, $rows);
        $this->assertIsArray($rows[0]);

        $raw = $rows[0]['metadata_json'] ?? null;
        $this->assertIsString($raw, 'metadata_json must be stored as a JSON string');

        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded);
        $this->assertSame(self::STORED_TMDB_BACKDROP, $decoded['backdrop_url'] ?? null);
        $this->assertArrayNotHasKey(
            'backdrop_srcset',
            $decoded,
            'the fixture must NOT pre-store a srcset — the shaper has to derive it',
        );
    }

    /**
     * THE acceptance criterion: a row that already exists in the database — never
     * rescanned, only metadata-enriched — comes back from `GET /api/v1/media`
     * with a ROW-sized backdrop.
     *
     * Pre-S101 `shape()` emitted no backdrop key at all, so both assertions below
     * read a missing key.
     */
    public function testAnExistingEnrichedRowExposesARowSizedBackdropOnTheListEndpoint(): void
    {
        $item = $this->listItem('enriched');

        $this->assertArrayHasKey('backdrop_url', $item);
        $this->assertSame(
            'https://image.tmdb.org/t/p/w780/s101bg.jpg',
            $item['backdrop_url'],
            'the stored /w500 must be stepped up to the row width /w780',
        );
        $this->assertSame(
            'https://image.tmdb.org/t/p/w780/s101bg.jpg 780w, '
                . 'https://image.tmdb.org/t/p/w1280/s101bg.jpg 1280w',
            $item['backdrop_srcset'],
        );
    }

    /**
     * The row budget: a listing that can carry {@see \Phlix\Common\Http\PageLimit::MAX}
     * rows must never advertise the multi-megabyte `/original` hero asset, and must
     * not grow the detail-only `backdrop_url_large` key.
     */
    public function testTheListRowNeverCarriesTheHeroSizedBackdrop(): void
    {
        $item = $this->listItem('enriched');

        $this->assertArrayNotHasKey('backdrop_url_large', $item);
        $this->assertStringNotContainsString('/original/', (string) $item['backdrop_url']);
        $this->assertStringNotContainsString('/original/', (string) $item['backdrop_srcset']);
    }

    /**
     * A row the scanner wrote and nothing ever enriched has no `backdrop_url` key
     * in its stored JSON. Both keys must still be PRESENT and null, so every row in
     * a page has the same shape and the client never sees a broken `<img>`.
     */
    public function testAnUnenrichedRowDegradesToNullOnBothKeys(): void
    {
        $item = $this->listItem('scanner-only');

        $this->assertArrayHasKey('backdrop_url', $item);
        $this->assertArrayHasKey('backdrop_srcset', $item);
        $this->assertNull($item['backdrop_url']);
        $this->assertNull($item['backdrop_srcset']);
    }

    /**
     * A `track` — a type with no landscape art — is not special-cased away; it
     * simply has no stored backdrop and degrades to null like any other row.
     */
    public function testABackdroplessTypeDegradesToNull(): void
    {
        $item = $this->listItem('track');

        $this->assertSame('track', $item['type']);
        $this->assertNull($item['backdrop_url']);
        $this->assertNull($item['backdrop_srcset']);
    }

    /**
     * There is deliberately no `type` allowlist: fanart.tv supplies real artist
     * backgrounds, so an `artist` row WITH a stored backdrop must keep it. A
     * non-TMDB URL has no width ladder, so it passes through verbatim and the
     * srcset is null — the client then uses the single `backdrop_url`.
     */
    public function testAnArtistRowKeepsItsNonTmdbBackdropWithNoDerivedSrcset(): void
    {
        $item = $this->listItem('artist');

        $this->assertSame('artist', $item['type']);
        $this->assertSame(self::STORED_FANART_BACKDROP, $item['backdrop_url']);
        $this->assertNull($item['backdrop_srcset']);
    }

    /**
     * `metadata_json` is partly attacker-influenced (a `.nfo` beside a media file,
     * or a plugin), and S101 multiplied the exposure from one hero URL to three
     * strings on up to a full page of rows. A stored `javascript:` URL must become
     * null rather than being echoed into the response — asserted against a value
     * that made a real round trip through MySQL, not an in-memory array.
     */
    public function testAHostileStoredBackdropIsDroppedRatherThanEchoed(): void
    {
        $item = $this->listItem('hostile');

        $this->assertNull($item['backdrop_url']);
        $this->assertNull($item['backdrop_srcset']);
    }

    // -- fixture ---------------------------------------------------------------

    private function seedFixtures(): void
    {
        // 1. Written the way MediaScanner writes it: filesystem basics only, no
        //    artwork keys at all. Then enriched the way
        //    LibraryMetadataMatcher::persistMetadata() does — items->update() with
        //    a MERGED metadata_json. This is the "existing row" case.
        $this->ids['enriched'] = $this->create('movie', 'S101 Enriched Movie', [
            'name' => 'S101 Enriched Movie',
            'year' => 2011,
        ]);
        $this->repo()->update($this->ids['enriched'], [
            'metadata_json' => [
                'name' => 'S101 Enriched Movie',
                'year' => 2011,
                'overview' => 'Enriched after the scan.',
                'poster_url' => 'https://image.tmdb.org/t/p/w500/s101poster.jpg',
                'backdrop_url' => self::STORED_TMDB_BACKDROP,
            ],
            'metadata_refreshed_at' => date('Y-m-d H:i:s'),
        ]);

        // 2. Scanned, never enriched.
        $this->ids['scanner-only'] = $this->create('movie', 'S101 Unenriched Movie', [
            'name' => 'S101 Unenriched Movie',
        ]);

        // 3. A music track — production-shaped metadata_json (name + sub_type only).
        $this->ids['track'] = $this->create('track', 'S101 Track', [
            'sub_type' => 'track',
            'name' => 'S101 Track',
        ]);

        // 4. An artist with a real fanart.tv background.
        $this->ids['artist'] = $this->create('artist', 'S101 Artist', [
            'sub_type' => 'artist',
            'name' => 'S101 Artist',
            'backdrop_url' => self::STORED_FANART_BACKDROP,
        ]);

        // 5. A hostile URL that a .nfo or plugin could have written.
        $this->ids['hostile'] = $this->create('movie', 'S101 Hostile Movie', [
            'name' => 'S101 Hostile Movie',
            'backdrop_url' => 'javascript:alert(document.domain)',
        ]);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function create(string $type, string $name, array $metadata): string
    {
        return $this->repo()->create([
            'library_id' => $this->libraryId,
            'name' => $name,
            'type' => $type,
            'path' => '/s101/' . bin2hex(random_bytes(8)) . '.mkv',
            'metadata_json' => $metadata,
        ]);
    }

    // -- read path -------------------------------------------------------------

    /**
     * Dispatch `GET /api/v1/media` scoped to this fixture's library and return the
     * one row with the given logical key.
     *
     * @return array<string, mixed>
     */
    private function listItem(string $key): array
    {
        $wanted = $this->ids[$key] ?? '';
        $this->assertNotSame('', $wanted, "unknown fixture key '$key'");

        foreach ($this->listItems() as $row) {
            if (($row['id'] ?? null) === $wanted) {
                return $row;
            }
        }

        $this->fail("fixture row '$key' ($wanted) was not returned by GET /api/v1/media");
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listItems(): array
    {
        $request = new Request();
        $request->method = 'GET';
        $request->path = '/api/v1/media';
        $request->query = ['libraryId' => $this->libraryId, 'limit' => '100'];
        $request->userId = 'user-s101';

        $response = $this->router()->dispatch($request);
        $this->assertSame(200, $response->statusCode);

        $body = json_decode((string) $response->body, true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('items', $body);
        $this->assertIsArray($body['items']);

        $out = [];
        foreach ($body['items'] as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $out[] = $row;
            }
        }

        return $out;
    }

    private function router(): WebPortalRouter
    {
        return new WebPortalRouter(
            $this->createMock(LibraryManager::class),
            $this->repo(),
            $this->createMock(SessionManager::class),
            $this->createMock(PlaybackController::class),
            $this->createMock(AuthManager::class),
            $this->createMock(PlaybackMarkerService::class),
            $this->createMock(MarkerService::class),
        );
    }

    private function repo(): ItemRepository
    {
        $items = $this->items;
        $this->assertNotNull($items);

        return $items;
    }
}
