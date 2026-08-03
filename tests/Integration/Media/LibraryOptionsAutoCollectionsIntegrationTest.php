<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media;

use Phlix\Common\Uuid;
use Phlix\Media\Library\FolderWatcher;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Library\MediaScanner;
use Phlix\Media\Library\ScanJobRepository;
use Phlix\Media\Library\Dto\LibraryRow;
use Phlix\Media\Music\MusicLibraryService;
use Phlix\Server\Http\Controllers\LibraryController;
use Phlix\Server\Http\Request;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Real-MySQL round-trip + merge proof for the S33 per-library auto-collections
 * toggle (updates.md — togglable auto-collections).
 *
 * The unit suite exercises {@see LibraryController}'s options-merge and
 * {@see LibraryRow::autoCollectionsEnabled()} entirely through a MOCKED
 * {@see LibraryManager} — the manager's `getLibrary()` return value is stubbed
 * and its `updateLibrary()` arguments are asserted, so the actual
 * `json_encode()` → MySQL `libraries.options` JSON column → `SELECT *` →
 * `LibraryRow::fromRow()` decode path is never executed. This repo has a
 * documented history of mock-DB tests hiding real bugs that only surface against
 * live MySQL (LiveTv RowQuery/ResultSet; metrics ONLY_FULL_GROUP_BY / GROUP BY
 * alias), so this test drives the REAL controller → real {@see LibraryManager}
 * → live {@see Connection} and asserts against BOTH the typed accessor and the
 * raw stored JSON that:
 *
 *  - create() with `autoCollections.enabled=false` stores
 *    `{"autoCollections":{"enabled":false}}` and reads back
 *    `autoCollectionsEnabled() === false`;
 *  - a library that never stored the flag reads back the default `true`;
 *  - editing autoCollections via update() PRESERVES a stored sibling option
 *    (`series_per_directory`) — the JSON merge does not clobber it;
 *  - editing an UNRELATED sibling option (`metadata_priority`) via update()
 *    PRESERVES a previously stored autoCollections flag (the reverse merge).
 *
 * Only the scan/watch side-effects ({@see MediaScanner}, {@see FolderWatcher},
 * {@see MusicLibraryService}, {@see ScanJobRepository}) are doubled — every
 * persistence read/write goes through real MySQL. CI applies all migrations to
 * the `phlix_test` service before the suite; locally, with no reachable MySQL,
 * it self-skips (same guard as {@see \Phlix\Tests\Integration\Auth\NextUpIntegrationTest}).
 */
final class LibraryOptionsAutoCollectionsIntegrationTest extends TestCase
{
    use RequiresRealDatabase;

    private ?Connection $db = null;

    private ?LibraryController $controller = null;

    /** @var list<string> Every library id created during a test, for teardown. */
    private array $libraryIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->requireRealDatabase('skipping auto-collections options round-trip. Runs in CI / docker.');

        $this->assertNotNull($this->db);

        // Real LibraryManager backed by the live connection; only the
        // scan/watch collaborators (never touched by create/update/getLibrary's
        // persistence path) are doubled.
        $manager = new LibraryManager(
            $this->db,
            $this->createMock(MediaScanner::class),
            $this->createMock(FolderWatcher::class),
            $this->createMock(MusicLibraryService::class),
        );

        // create() enqueues a background scan job — double it so no scan row is
        // written; the returned id is only echoed in the 201 payload.
        $scanJobs = $this->createMock(ScanJobRepository::class);
        $scanJobs->method('enqueue')->willReturn(Uuid::v4());

        $this->controller = new LibraryController($manager, $scanJobs);
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            foreach ($this->libraryIds as $id) {
                $this->db->query('DELETE FROM libraries WHERE id = ?', [$id]);
            }
        }
        $this->libraryIds = [];
        parent::tearDown();
    }

    /**
     * create() with an explicit `autoCollections.enabled=false` persists the
     * canonical `{"autoCollections":{"enabled":false}}` into the real JSON column
     * and reads back `autoCollectionsEnabled() === false`.
     */
    public function testCreatePersistsAutoCollectionsFalseAndReadsBackFalse(): void
    {
        $id = $this->createLibrary([
            'name'  => 'S33 IT Movies (off)',
            'type'  => 'movie',
            'paths' => ['/tmp/phlix-s33-it/off'],
            'autoCollections' => ['enabled' => false],
        ]);

        // Typed accessor against the freshly SELECTed row.
        $row = $this->readBack($id);
        $this->assertFalse(
            $row->autoCollectionsEnabled(),
            'A stored explicit enabled=false must read back as disabled',
        );

        // Raw stored JSON shape (decoded — MySQL normalises JSON formatting).
        $stored = $this->readRawOptions($id);
        $this->assertSame(['autoCollections' => ['enabled' => false]], $stored);
    }

    /**
     * create() with an explicit `autoCollections.enabled=true` round-trips to
     * `true` and stores the canonical shape (guards against a truthy value being
     * dropped or mis-encoded by the real column).
     */
    public function testCreatePersistsAutoCollectionsTrueAndReadsBackTrue(): void
    {
        $id = $this->createLibrary([
            'name'  => 'S33 IT Movies (on)',
            'type'  => 'movie',
            'paths' => ['/tmp/phlix-s33-it/on'],
            'autoCollections' => ['enabled' => true],
        ]);

        $row = $this->readBack($id);
        $this->assertTrue($row->autoCollectionsEnabled());
        $this->assertSame(['autoCollections' => ['enabled' => true]], $this->readRawOptions($id));
    }

    /**
     * A library created WITHOUT the flag stores no `autoCollections` key and
     * reads back the historical default of `true` (un-migrated libraries keep
     * generating collections).
     */
    public function testCreateWithoutFlagReadsBackDefaultTrue(): void
    {
        $id = $this->createLibrary([
            'name'  => 'S33 IT Movies (default)',
            'type'  => 'movie',
            'paths' => ['/tmp/phlix-s33-it/default'],
        ]);

        $row = $this->readBack($id);
        $this->assertTrue(
            $row->autoCollectionsEnabled(),
            'Absent autoCollections must default to enabled (true) for backward compatibility',
        );

        $stored = $this->readRawOptions($id);
        $this->assertArrayNotHasKey(
            'autoCollections',
            $stored,
            'No autoCollections key should be written when the flag was never supplied',
        );
    }

    /**
     * MERGE (edit autoCollections, keep sibling): a series library that already
     * stores `series_per_directory=true` must retain it after an update that
     * only toggles autoCollections off — the JSON merge must not clobber the
     * sibling. Reads back through real MySQL, asserting BOTH keys survive in the
     * stored blob and via their typed accessors.
     */
    public function testUpdateAutoCollectionsPreservesStoredSeriesPerDirectory(): void
    {
        $id = $this->createLibrary([
            'name'  => 'S33 IT Series (merge)',
            'type'  => 'series',
            'paths' => ['/tmp/phlix-s33-it/series'],
            'series_per_directory' => true,
        ]);

        // Precondition: sibling stored, autoCollections absent → default true.
        $before = $this->readBack($id);
        $this->assertTrue($before->seriesPerDirectory());
        $this->assertTrue($before->autoCollectionsEnabled());
        $this->assertArrayNotHasKey('autoCollections', $this->readRawOptions($id));

        // Update: toggle autoCollections OFF only.
        $this->updateLibrary($id, ['autoCollections' => ['enabled' => false]]);

        $after = $this->readBack($id);
        $this->assertFalse(
            $after->autoCollectionsEnabled(),
            'autoCollections must now be disabled',
        );
        $this->assertTrue(
            $after->seriesPerDirectory(),
            'series_per_directory sibling must survive the autoCollections merge',
        );

        $stored = $this->readRawOptions($id);
        $this->assertSame(['enabled' => false], $stored['autoCollections'] ?? null);
        $this->assertTrue(
            (bool) ($stored['series_per_directory'] ?? false),
            'The stored blob must still contain series_per_directory alongside autoCollections',
        );
    }

    /**
     * REVERSE MERGE (edit sibling, keep autoCollections): a library that already
     * stores `autoCollections.enabled=false` must retain it after an update that
     * only edits an UNRELATED option (`metadata_priority`). This exercises the
     * controller's unified options-merge pass triggered by a NON-autoCollections
     * key, proving the stored toggle is not resurrected/lost through the real DB.
     */
    public function testUpdateUnrelatedOptionPreservesStoredAutoCollections(): void
    {
        $id = $this->createLibrary([
            'name'  => 'S33 IT Movies (reverse merge)',
            'type'  => 'movie',
            'paths' => ['/tmp/phlix-s33-it/reverse'],
            'autoCollections' => ['enabled' => false],
        ]);

        // Precondition: autoCollections=false stored, no metadata_priority.
        $before = $this->readBack($id);
        $this->assertFalse($before->autoCollectionsEnabled());
        $this->assertNull($before->metadataPriority());

        // Update a DIFFERENT option only.
        $this->updateLibrary($id, ['metadata_priority' => ['movie' => ['tmdb', 'local']]]);

        $after = $this->readBack($id);
        $this->assertFalse(
            $after->autoCollectionsEnabled(),
            'The stored autoCollections=false must survive editing an unrelated option',
        );
        $this->assertSame(
            ['movie' => ['tmdb', 'local']],
            $after->metadataPriority(),
            'The newly edited metadata_priority sibling must be stored',
        );

        $stored = $this->readRawOptions($id);
        $this->assertSame(['enabled' => false], $stored['autoCollections'] ?? null);
        $this->assertArrayHasKey('metadata_priority', $stored);
    }

    /**
     * update() with a `null` autoCollections CLEARS the key (falls back to the
     * default ON), and does so while preserving a stored sibling.
     */
    public function testUpdateNullAutoCollectionsClearsToDefault(): void
    {
        $id = $this->createLibrary([
            'name'  => 'S33 IT Series (clear)',
            'type'  => 'series',
            'paths' => ['/tmp/phlix-s33-it/clear'],
            'series_per_directory' => true,
            'autoCollections' => ['enabled' => false],
        ]);

        $before = $this->readBack($id);
        $this->assertFalse($before->autoCollectionsEnabled());

        // Clear the override.
        $this->updateLibrary($id, ['autoCollections' => null]);

        $after = $this->readBack($id);
        $this->assertTrue(
            $after->autoCollectionsEnabled(),
            'A null autoCollections must clear the override back to the default (enabled)',
        );
        $this->assertTrue(
            $after->seriesPerDirectory(),
            'Clearing autoCollections must not disturb the series_per_directory sibling',
        );

        $stored = $this->readRawOptions($id);
        $this->assertArrayNotHasKey('autoCollections', $stored);
        $this->assertTrue((bool) ($stored['series_per_directory'] ?? false));
    }

    /**
     * Drive controller->create() as an admin and assert 201; returns the new id
     * and registers it for teardown.
     *
     * @param array<string, mixed> $body
     */
    private function createLibrary(array $body): string
    {
        $controller = $this->controller;
        $this->assertNotNull($controller);

        $response = $controller->create($this->adminRequest($body), []);
        $this->assertSame(201, $response->statusCode, 'create() must return 201; body: ' . $response->body);

        $payload = json_decode($response->body, true);
        $this->assertIsArray($payload);
        $id = $payload['library_id'] ?? null;
        $this->assertIsString($id);
        $this->assertNotSame('', $id);

        $this->libraryIds[] = $id;
        return $id;
    }

    /**
     * Drive controller->update() as an admin and assert 200.
     *
     * @param array<string, mixed> $body
     */
    private function updateLibrary(string $id, array $body): void
    {
        $controller = $this->controller;
        $this->assertNotNull($controller);

        $response = $controller->update($this->adminRequest($body), ['id' => $id]);
        $this->assertSame(200, $response->statusCode, 'update() must return 200; body: ' . $response->body);
    }

    /**
     * SELECT the row and hydrate a {@see LibraryRow} — the same decode path
     * production uses.
     */
    private function readBack(string $id): LibraryRow
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $result = $db->query('SELECT * FROM libraries WHERE id = ?', [$id]);
        $this->assertIsArray($result);
        $this->assertNotEmpty($result, 'The library row must exist after create()');
        $first = $result[0];
        $this->assertIsArray($first);

        $row = [];
        foreach ($first as $key => $value) {
            if (is_string($key)) {
                $row[$key] = $value;
            }
        }
        return LibraryRow::fromRow($row);
    }

    /**
     * Fetch the RAW `options` JSON column exactly as MySQL stored it and decode
     * it — proving the real column round-trip independently of LibraryRow.
     *
     * @return array<string, mixed>
     */
    private function readRawOptions(string $id): array
    {
        $db = $this->db;
        $this->assertNotNull($db);

        $result = $db->query('SELECT options FROM libraries WHERE id = ?', [$id]);
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        $raw = $result[0]['options'] ?? null;
        $this->assertIsString($raw, 'The options column must contain a JSON string');

        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, 'Stored options must decode to an array');

        $out = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /**
     * An authenticated admin request carrying the given JSON body.
     *
     * @param array<string, mixed> $body
     */
    private function adminRequest(array $body): Request
    {
        $request = new Request();
        $request->userId = 's33-it-admin';
        $request->body = $body;
        return $request;
    }
}
