<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media;

use Phlix\Media\Library\ItemRepository;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Real-DB proof of the Wave 2 host hook {@see ItemRepository::findByMetadataField()}:
 * a row written with an external id inside `metadata_json` (exactly the shape
 * the anilist plugin persists — a top-level `mal_id`, and the host resolver's
 * nested `external_ids.<source>`) can be found back by that id, a non-matching
 * value returns null, and a value carrying SQL/JSON metacharacters is treated
 * as a plain bound parameter (no injection).
 *
 * This exercises MySQL's actual `JSON_UNQUOTE(JSON_EXTRACT(metadata_json, ?))`
 * evaluation (JSON number vs string coercion, the bound path argument) that a
 * mocked connection cannot. CI applies all migrations to the `phlix_test`
 * MySQL service before the suite; locally — with no reachable MySQL — the test
 * self-skips. The unit tests
 * {@see \Phlix\Tests\Unit\Media\Library\ItemRepositoryTest} cover the query
 * shape + injection-safety with a mock regardless.
 *
 */
final class FindByMetadataFieldTest extends TestCase
{
    use RequiresRealDatabase;

    private ?Connection $db = null;

    /** @var string UUID of the disposable parent library. */
    private string $libraryId = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->requireRealDatabase('skipping findByMetadataField test. Runs in CI / docker-compose.');

        $this->libraryId = $this->uuid();
        $this->db->query(
            'INSERT INTO libraries (id, name, type, paths) VALUES (?, ?, ?, ?)',
            [$this->libraryId, 'findByMetadataField Lib', 'movie', json_encode(['/tmp/phlix-fbmf-test'])],
        );
    }

    protected function tearDown(): void
    {
        if ($this->db !== null && $this->libraryId !== '') {
            // ON DELETE CASCADE removes the media_items rows with the parent library.
            $this->db->query('DELETE FROM libraries WHERE id = ?', [$this->libraryId]);
        }
        parent::tearDown();
    }

    public function testFindsRowByTopLevelMalId(): void
    {
        $this->assertNotNull($this->db);
        $repo = new ItemRepository($this->db);

        // Persist the anilist shape: a top-level integer `mal_id` in the blob,
        // written through the same update(['metadata_json' => …]) seam the
        // plugin uses.
        $id = $repo->create([
            'library_id' => $this->libraryId,
            'name' => 'Cowboy Bebop',
            'type' => 'movie',
            'path' => '/tmp/phlix-fbmf-test/bebop.mkv',
        ]);
        $repo->update($id, ['metadata_json' => ['mal_id' => 205, 'title_english' => 'Cowboy Bebop']]);

        // mal_id is a JSON number; the plugin passes an int. Both match.
        $foundByInt = $repo->findByMetadataField('mal_id', 205);
        $this->assertNotNull($foundByInt);
        $this->assertSame($id, $foundByInt['id']);

        $foundByString = $repo->findByMetadataField('mal_id', '205');
        $this->assertNotNull($foundByString);
        $this->assertSame($id, $foundByString['id']);
    }

    public function testFindsRowByNestedExternalId(): void
    {
        $this->assertNotNull($this->db);
        $repo = new ItemRepository($this->db);

        $id = $repo->create([
            'library_id' => $this->libraryId,
            'name' => 'The Thing',
            'type' => 'movie',
            'path' => '/tmp/phlix-fbmf-test/thing.mkv',
        ]);
        $repo->update($id, ['metadata_json' => ['external_ids' => ['imdb' => 'tt0084787']]]);

        $found = $repo->findByMetadataField('external_ids.imdb', 'tt0084787');
        $this->assertNotNull($found);
        $this->assertSame($id, $found['id']);
    }

    public function testReturnsNullForNonMatchingValue(): void
    {
        $this->assertNotNull($this->db);
        $repo = new ItemRepository($this->db);

        $id = $repo->create([
            'library_id' => $this->libraryId,
            'name' => 'Akira',
            'type' => 'movie',
            'path' => '/tmp/phlix-fbmf-test/akira.mkv',
        ]);
        $repo->update($id, ['metadata_json' => ['mal_id' => 47]]);

        $this->assertNull($repo->findByMetadataField('mal_id', 999999));
    }

    public function testInjectionMetacharactersAreTreatedAsAPlainValue(): void
    {
        $this->assertNotNull($this->db);
        $repo = new ItemRepository($this->db);

        // A value laden with SQL/JSON metacharacters must not blow up the query
        // and must not match anything — it is a bound parameter, not code. The
        // disposable library still exists after the call (proves no DROP ran).
        $payload = "205' OR '1'='1";
        $this->assertNull($repo->findByMetadataField('mal_id', $payload));

        $stillThere = $this->db->query('SELECT id FROM libraries WHERE id = ?', [$this->libraryId]);
        $this->assertNotEmpty($stillThere, 'the query must be fully parameterized (library survives)');
    }

    private function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}
