<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Library\ItemRepository;
use RuntimeException;
use Throwable;
use Workerman\MySQL\Connection;

/**
 * SH-023 (major) — media/search `total` must be the LIBRARY MATCH COUNT,
 * not the page length.
 *
 * The endpoint shipped `total = count($shapedItems)`, so every popular query
 * reported at most `limit` (default 50) and the SPA's cap notice ("showing X
 * of Y", ui SearchPage) could never fire. searchCount() is the count leg that
 * fixes it, and it mirrors the THREE-statement structure of search() on
 * purpose (see {@see ItemRepository::mergeSearchResults()} for why the halves
 * are separate indexed statements, never one OR-ed query):
 *
 *   1. the primary leg — FULLTEXT match over non-music rows, or the LIKE
 *      variant exactly where search()/searchFuzzy() use it;
 *   2. the artist-container leg, minus everything the primary leg already
 *      counts (the merge dedupes by id — the count must dedupe the same way);
 *   3. the album-container leg, minus primary AND minus artist hits (the
 *      mergeSearchResults(artists, albums) dedup inside the container half).
 *
 * The failure ladder mirrors the page path leg-for-leg: fulltext throws ->
 * count continues on the LIKE primary with LIKE-shaped exclusions; a container
 * statement throws -> containers contribute 0 (the page swallows the same
 * failure to `[]`); the direct-fuzzy primary throwing is NOT swallowed,
 * because searchFuzzy() does not swallow it either.
 *
 * Unlike the page legs, no statement carries a LIMIT — the whole point is the
 * count beyond the page. That is measured as a structural pin below.
 */
final class ItemRepositorySearchCountTest extends TestCase
{
    /**
     * @param array<string, int|Throwable> $answers leg => count or Throwable
     *        ('primary' = the non-music count leg in whichever mode runs,
     *        'artists' = the music_artists leg, 'albums' = the music_albums leg)
     * @param list<array{0: string, 1: array<mixed>}> $seen collected statements
     */
    private function repository(array $answers, array &$seen): ItemRepository
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')
            ->willReturnCallback(function (string $sql, array $args = []) use ($answers, &$seen) {
                $seen[] = [$sql, $args];

                $leg = null;
                if (preg_match('/JOIN music_artists/', $sql) === 1) {
                    $leg = 'artists';
                } elseif (preg_match('/JOIN music_albums/', $sql) === 1) {
                    $leg = 'albums';
                } elseif (preg_match('/COUNT\(\*\)/', $sql) === 1) {
                    $leg = 'primary';
                }

                if ($leg === null || !array_key_exists($leg, $answers)) {
                    self::fail('unexpected statement issued by searchCount(): ' . $sql);
                }

                $answer = $answers[$leg];
                if ($answer instanceof Throwable) {
                    throw $answer;
                }

                return [['count' => (string) $answer]];
            });

        return new ItemRepository($db);
    }

    public function test_the_count_sums_primary_and_container_legs_in_fulltext_mode(): void
    {
        $seen = [];
        $repo = $this->repository(['primary' => 7, 'artists' => 3, 'albums' => 2], $seen);

        $this->assertSame(12, $repo->searchCount('dune'));

        $this->assertCount(3, $seen, 'exactly one statement per leg');
        $this->assertStringContainsString('IN BOOLEAN MODE', $seen[0][0]);
        $this->assertStringContainsString(
            "%dune%",
            $seen[1][1][0] ?? '',
            'containers match LIKE-escaped, as on the page path'
        );
    }

    public function test_no_count_statement_carries_a_limit(): void
    {
        $seen = [];
        $repo = $this->repository(['primary' => 7, 'artists' => 3, 'albums' => 2], $seen);

        $repo->searchCount('dune');

        foreach ($seen as [$sql, $args]) {
            $this->assertStringNotContainsString('LIMIT', $sql, 'total is the match count, not a page');
        }
    }

    public function test_the_operator_only_query_counts_on_the_like_leg_without_touching_fulltext(): void
    {
        $seen = [];
        $repo = $this->repository(['primary' => 4, 'artists' => 1, 'albums' => 0], $seen);

        // '@@@' sanitizes to '' — search() goes straight to the LIKE scan and
        // this is the same decision, measured, not a guess.
        $this->assertSame(5, $repo->searchCount('@@@'));

        foreach ($seen as [$sql]) {
            $this->assertStringNotContainsString('AGAINST', $sql);
        }
        $this->assertStringContainsString('name LIKE ?', $seen[0][0]);
    }

    public function test_the_count_falls_back_to_like_when_fulltext_errors(): void
    {
        $seen = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')
            ->willReturnCallback(function (string $sql, ?array $args = null) use (&$seen) {
                $seen[] = [$sql, $args ?? []];

                if (preg_match('/JOIN music_artists/', $sql) === 1) {
                    return [['count' => '1']];
                }

                if (preg_match('/JOIN music_albums/', $sql) === 1) {
                    return [['count' => '0']];
                }

                if (str_contains($sql, 'IN BOOLEAN MODE')) {
                    throw new RuntimeException('syntax error in fulltext search expression');
                }

                return [['count' => '4']];
            });
        $repo = new ItemRepository($db);

        $this->assertSame(5, $repo->searchCount('dune++@@'), 'LIKE primary (4) + artists (1) + albums (0)');
        $this->assertStringContainsString('IN BOOLEAN MODE', $seen[0][0], 'the fulltext leg is attempted first');
        $this->assertStringContainsString('name LIKE ?', $seen[1][0], 'then the LIKE primary counts instead');
    }

    public function test_container_failures_degrade_to_primary_only(): void
    {
        $seen = [];
        $repo = $this->repository([
            'primary' => 9,
            'artists' => new RuntimeException('music_* tables absent'),
            'albums' => 0,
        ], $seen);

        $this->assertSame(
            9,
            $repo->searchCount('dune'),
            'the page path swallows container failure to [] — the count swallows it to +0'
        );
        $this->assertCount(2, $seen, 'primary + first container leg, then the container try/catch closes the half');
    }

    public function test_the_direct_fuzzy_primary_failure_is_not_swallowed(): void
    {
        $seen = [];
        $repo = $this->repository([
            'primary' => new RuntimeException('connection lost'),
            'artists' => 0,
            'albums' => 0,
        ], $seen);

        // search('@@@') -> searchFuzzy() -> an UNGUARDED LIKE query: it throws.
        $this->expectException(RuntimeException::class);
        $repo->searchCount('@@@');
    }

    public function test_container_legs_exclude_rows_the_primary_leg_already_counts(): void
    {
        $seen = [];
        $repo = $this->repository(['primary' => 1, 'artists' => 0, 'albums' => 0], $seen);

        $repo->searchCount('dune');

        $this->assertStringContainsString(
            'AGAINST',
            $seen[1][0],
            'the artist leg must subtract the FULLTEXT primary set by id, not just the name mirror'
        );
        $this->assertStringContainsString(
            'NOT EXISTS',
            $seen[2][0],
            'the album leg must also subtract the artist hits (mergeSearchResults(artists, albums) dedup)'
        );
    }

    public function test_the_like_fallback_rewrites_the_container_exclusions_too(): void
    {
        $seen = [];
        $db = $this->createMock(Connection::class);
        $mode = 'fulltext';
        $db->method('query')
            ->willReturnCallback(function (string $sql, array $args = []) use (&$seen, &$mode) {
                $seen[] = [$sql, $args];

                if (preg_match('/JOIN music_(artists|albums)/', $sql) === 1) {
                    return [['count' => '0']];
                }

                if (str_contains($sql, 'IN BOOLEAN MODE')) {
                    $mode = 'like';

                    throw new RuntimeException('syntax error in fulltext search expression');
                }

                return [['count' => '4']];
            });
        $repo = new ItemRepository($db);

        $this->assertSame(4, $repo->searchCount('a+b'));

        $containerSql = array_values(array_filter(
            $seen,
            static fn (array $e): bool => (bool) preg_match('/JOIN music_/', $e[0]),
        ));
        $this->assertNotSame([], $containerSql);
        foreach ($containerSql as [$sql]) {
            $this->assertStringNotContainsString(
                'AGAINST',
                $sql,
                'after the fallback the exclusion must reflect the LIKE primary, not the failed FULLTEXT one',
            );
        }
        $this->assertSame('like', $mode);
    }
}
