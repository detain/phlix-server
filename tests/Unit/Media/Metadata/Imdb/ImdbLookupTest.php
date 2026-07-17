<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata\Imdb;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Metadata\Imdb\ImdbLookup;
use Workerman\MySQL\Connection;

/**
 * Unit tests for {@see ImdbLookup}: title normalization, year-tolerant matching,
 * best-candidate selection, and row mapping — all against a mocked Connection.
 *
 * @since 0.21.0
 */
class ImdbLookupTest extends TestCase
{
    public function testNormalizeTitleLowercasesStripsPunctuationAndArticle(): void
    {
        $this->assertSame('matrix', ImdbLookup::normalizeTitle('The Matrix'));
        $this->assertSame('spiderman homecoming', ImdbLookup::normalizeTitle('Spider-Man: Homecoming'));
        $this->assertSame('amelie', ImdbLookup::normalizeTitle('  Amelie  '));
        $this->assertSame('quiet place', ImdbLookup::normalizeTitle('A Quiet Place'));
        $this->assertSame('avengers', ImdbLookup::normalizeTitle('An Avengers'));
    }

    public function testLookupWithYearUsesPlusMinusOneWindowAndExactYearPreference(): void
    {
        $captured = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use (&$captured): array {
                $captured['sql'] = $sql;
                $captured['params'] = $params;
                return [[
                    'tconst' => 'tt0133093',
                    'primary_title' => 'The Matrix',
                    'start_year' => 1999,
                    'genres' => 'Action,Sci-Fi',
                    'average_rating' => '8.7',
                    'num_votes' => '1900000',
                    'runtime_minutes' => 136,
                ]];
            }
        );

        $lookup = new ImdbLookup($db);
        $result = $lookup->lookup('The Matrix', 1999);

        $this->assertNotNull($result);
        $this->assertSame('tt0133093', $result['imdb_id']);
        $this->assertSame('The Matrix', $result['title']);
        $this->assertSame(1999, $result['year']);
        $this->assertSame(['Action', 'Sci-Fi'], $result['genres']);
        $this->assertSame(8.7, $result['average_rating']);
        $this->assertSame(1900000, $result['num_votes']);
        $this->assertSame(136, $result['runtime_minutes']);

        // Normalized title + year window [1998, 2000] + exact-year tiebreak.
        $this->assertSame(['matrix', 1998, 2000, 1999], $captured['params']);
        $sql = $captured['sql'];
        $this->assertIsString($sql);
        $this->assertStringContainsString('BETWEEN ? AND ?', $sql);
        $this->assertStringContainsString('start_year = ?', $sql);
    }

    public function testLookupWithoutYearOrdersByVotes(): void
    {
        $captured = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use (&$captured): array {
                $captured['sql'] = $sql;
                $captured['params'] = $params;
                return [[
                    'tconst' => 'tt0111161',
                    'primary_title' => 'The Shawshank Redemption',
                    'start_year' => 1994,
                    'genres' => 'Drama',
                    'average_rating' => '9.3',
                    'num_votes' => '2800000',
                    'runtime_minutes' => 142,
                ]];
            }
        );

        $lookup = new ImdbLookup($db);
        $result = $lookup->lookup('The Shawshank Redemption', null);

        $this->assertNotNull($result);
        $this->assertSame('tt0111161', $result['imdb_id']);
        $this->assertSame(['shawshank redemption'], $captured['params']);
        $sql = $captured['sql'];
        $this->assertIsString($sql);
        $this->assertStringContainsString('ORDER BY num_votes DESC', $sql);
        $this->assertStringNotContainsString('BETWEEN', $sql);
    }

    public function testLookupReturnsNullWhenNoRows(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $lookup = new ImdbLookup($db);
        $this->assertNull($lookup->lookup('Nonexistent Film', 2020));
    }

    public function testLookupReturnsNullForEmptyNormalizedTitle(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('query');

        $lookup = new ImdbLookup($db);
        $this->assertNull($lookup->lookup('   ', 2020));
    }

    public function testGetByImdbIdMapsRowAndNullableFields(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([[
            'tconst' => 'tt1234567',
            'primary_title' => 'Untitled',
            'start_year' => null,
            'genres' => null,
            'average_rating' => null,
            'num_votes' => null,
            'runtime_minutes' => null,
        ]]);

        $lookup = new ImdbLookup($db);
        $result = $lookup->getByImdbId('tt1234567');

        $this->assertNotNull($result);
        $this->assertSame('tt1234567', $result['imdb_id']);
        $this->assertNull($result['year']);
        $this->assertSame([], $result['genres']);
        $this->assertNull($result['average_rating']);
        $this->assertNull($result['num_votes']);
        $this->assertNull($result['runtime_minutes']);
    }

    public function testGetByImdbIdReturnsNullForEmptyId(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('query');

        $lookup = new ImdbLookup($db);
        $this->assertNull($lookup->getByImdbId(''));
    }

    public function testLookupByAkaResolvesViaAlternateTitleWithYearWindow(): void
    {
        $captured = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use (&$captured): array {
                $captured['sql'] = $sql;
                $captured['params'] = $params;
                // The on-disk German title resolves to the canonical Matrix tconst.
                return [[
                    'tconst' => 'tt0133093',
                    'primary_title' => 'The Matrix',
                    'start_year' => 1999,
                    'genres' => 'Action,Sci-Fi',
                    'average_rating' => '8.7',
                    'num_votes' => '1900000',
                    'runtime_minutes' => 136,
                ]];
            }
        );

        $lookup = new ImdbLookup($db);
        $result = $lookup->lookupByAka('Matrix - Die Vollendung', 1999);

        $this->assertNotNull($result);
        $this->assertSame('tt0133093', $result['imdb_id']);
        $this->assertSame('The Matrix', $result['title']);
        $this->assertSame(1999, $result['year']);

        // Joins akas → titles, exact normalized aka + year window + exact-year tiebreak.
        $sql = $captured['sql'];
        $this->assertIsString($sql);
        $this->assertStringContainsString('imdb_title_akas', $sql);
        $this->assertStringContainsString('INNER JOIN imdb_titles', $sql);
        $this->assertStringContainsString('a.normalized_title = ?', $sql);
        $this->assertStringContainsString('BETWEEN ? AND ?', $sql);
        $this->assertSame(['matrix die vollendung', 1998, 2000, 1999], $captured['params']);
    }

    public function testLookupByAkaWithoutYearOrdersByVotes(): void
    {
        $captured = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use (&$captured): array {
                $captured['sql'] = $sql;
                $captured['params'] = $params;
                return [[
                    'tconst' => 'tt0000005',
                    'primary_title' => 'A Quiet Place',
                    'start_year' => 2018,
                    'genres' => 'Horror',
                    'average_rating' => '7.5',
                    'num_votes' => '500000',
                    'runtime_minutes' => 90,
                ]];
            }
        );

        $lookup = new ImdbLookup($db);
        $result = $lookup->lookupByAka('Un Lugar Tranquilo', null);

        $this->assertNotNull($result);
        $this->assertSame('tt0000005', $result['imdb_id']);
        $this->assertSame(['un lugar tranquilo'], $captured['params']);
        $sql = $captured['sql'];
        $this->assertIsString($sql);
        $this->assertStringContainsString('ORDER BY t.num_votes DESC', $sql);
        $this->assertStringNotContainsString('BETWEEN', $sql);
    }

    public function testLookupByAkaReturnsNullWhenNoAkaMatches(): void
    {
        // A non-matching title must NOT resolve (no false positives).
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $lookup = new ImdbLookup($db);
        $this->assertNull($lookup->lookupByAka('Some Unrelated Title', 2001));
    }

    public function testLookupByAkaReturnsNullForEmptyNormalizedTitle(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('query');

        $lookup = new ImdbLookup($db);
        $this->assertNull($lookup->lookupByAka('   ', 2001));
    }
}
