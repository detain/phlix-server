<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media;

use Phlix\Media\Library\ItemRepository;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Real-DB proof that media listings ignore a leading article ("The", "A", "An",
 * the common Romance/German articles) when ordering and when bucketing the A-Z
 * jump rail — while the stored/displayed `name` is unchanged.
 *
 * This exercises the actual MySQL evaluation of {@see \Phlix\Media\Library\SortTitle}'s
 * portable `CASE … COLLATE utf8mb4_bin` expression (collation behaviour, LEFT/
 * SUBSTRING semantics) that a mocked connection cannot. CI applies all migrations
 * to the `phlix_test` MySQL service before the suite (see phpunit.yml); locally —
 * with no reachable MySQL — the test self-skips. The unit tests
 * {@see \Phlix\Tests\Unit\Media\Library\SortTitleTest} and
 * {@see \Phlix\Tests\Unit\Media\Library\ItemRepositoryTest} cover the logic with
 * a mock regardless.
 *
 */
final class SortTitleOrderingTest extends TestCase
{
    use RequiresRealDatabase;

    private ?Connection $db = null;

    /** @var string UUID of the disposable parent library. */
    private string $libraryId = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->requireRealDatabase('skipping sort-title ordering test. Runs in CI / docker-compose.');

        $this->libraryId = $this->uuid();
        $this->db->query(
            'INSERT INTO libraries (id, name, type, paths) VALUES (?, ?, ?, ?)',
            [$this->libraryId, 'SortTitle Ordering Lib', 'movie', json_encode(['/tmp/phlix-sorttitle-test'])],
        );

        $repo = new ItemRepository($this->db);
        foreach ($this->fixtureNames() as $name) {
            $repo->create([
                'library_id' => $this->libraryId,
                'name' => $name,
                'type' => 'movie',
                'path' => '/tmp/phlix-sorttitle-test/' . md5($name) . '.mkv',
            ]);
        }
    }

    protected function tearDown(): void
    {
        if ($this->db !== null && $this->libraryId !== '') {
            // ON DELETE CASCADE removes the media_items rows with the parent library.
            $this->db->query('DELETE FROM libraries WHERE id = ?', [$this->libraryId]);
        }
        parent::tearDown();
    }

    public function testQueryOrdersByArticleStrippedTitle(): void
    {
        $this->assertNotNull($this->db);
        $repo = new ItemRepository($this->db);

        $result = $repo->query(['limit' => 50], $this->libraryId);
        $names = array_map(
            static function (array $row): string {
                $name = $row['name'];

                return is_string($name) ? $name : '';
            },
            $result['items']
        );

        // Ordered by the key in [], the article is ignored; "name" itself is unchanged.
        // "The " has an empty key (only an article) and sorts first.
        $this->assertSame([
            'The ',         // "" (empty key sorts first)
            'An Apple',     // Apple
            'Apple Core',   // Apple Core
            'Box',          // Box
            'El Camino',    // Camino
            'the matrix',   // matrix
            'The Plot',     // Plot
            'Plot Device',  // Plot Device
            'Zebra',        // Zebra
        ], $names);
    }

    public function testLetterCountsBucketsByStrippedFirstLetter(): void
    {
        $this->assertNotNull($this->db);
        $repo = new ItemRepository($this->db);

        $counts = [];
        foreach ($repo->letterCounts([], $this->libraryId) as $row) {
            $counts[$row['letter']] = $row['count'];
        }

        $this->assertSame(2, $counts['A'] ?? 0, '"An Apple" + "Apple Core"');
        $this->assertSame(1, $counts['B'] ?? 0, '"Box"');
        $this->assertSame(1, $counts['C'] ?? 0, '"El Camino" → Camino');
        $this->assertSame(1, $counts['M'] ?? 0, '"the matrix" → matrix');
        $this->assertSame(2, $counts['P'] ?? 0, '"The Plot" + "Plot Device"');
        $this->assertSame(1, $counts['Z'] ?? 0, '"Zebra"');
        // An empty sort key ("The ") buckets under '#', not dropped (keeps the
        // rail offsets aligned with the grid).
        $this->assertSame(1, $counts['#'] ?? 0, '"The " (empty key) folds to #');
        // The crux: article-prefixed titles do NOT bucket under their article's letter.
        $this->assertArrayNotHasKey('T', $counts, '"The Plot"/"the matrix" must not fall under T');
        $this->assertArrayNotHasKey('E', $counts, '"El Camino" must not fall under E');
    }

    /**
     * @return list<string>
     */
    private function fixtureNames(): array
    {
        return ['The Plot', 'Plot Device', 'An Apple', 'Apple Core', 'Zebra', 'the matrix', 'El Camino', 'Box', 'The '];
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
