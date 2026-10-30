<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library;

use Phlix\Media\Library\ItemRepository;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Workerman\MySQL\Connection;

/**
 * M-SERVER-08 — SQL/binding contract for the two ItemRepository paging laws,
 * captured on a recording `Connection` double.
 *
 * The behavioural halves (tie-walks dropping/repeating rows; a 10,001-row
 * library actually being capped) are proved against a REAL MySQL in
 * {@see \Phlix\Tests\Integration\Media\ItemRepositoryPagingLawsRealDbTest} —
 * that file narrates why doubles cannot prove those. What belongs HERE is the
 * text-level contract for the two paths a shared `phlix_test` venue cannot
 * exercise behaviourally:
 *
 *  - `query()`/`queryWithSmartRules()` OFFSET walks: their own PageLimit
 *    clamp (1..100) is BELOW the row ceiling, so only the ORDER BY tail is in
 *    question — and the tail text is the pin.
 *  - the smart-rules JOIN arm: its ORDER BY must carry the QUALIFIED
 *    `media_items.id` (a bare `id` is ambiguous against `metadata_ratings.id`
 *    — MySQL error 1052). That arm additionally dies today on a real MySQL
 *    for an unrelated, pre-existing reason (`rating_range` puts
 *    `COALESCE(AVG(r.score), 0)` in WHERE — error 1111, reported separately),
 *    so a text pin is the only honest teeth available until that is cured.
 *
 * Ceiling bindings are asserted through the class's OWN constant (reflection,
 * never a re-typed number) so the pins track the declared value, plus one
 * tripwire that the declared value is still the S99 10,000.
 */
final class ItemRepositoryPagingLawsTest extends TestCase
{
    /** @var list<array{sql: string, bindings: list<mixed>}> */
    private array $log = [];

    private function repo(): ItemRepository
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')
            ->willReturnCallback(function (string $sql, array $bindings = []): array {
                // Direct property write: psalm cannot analyse a by-ref property alias
                // here (UnsupportedPropertyReferenceUsage), and $this is bound by the
                // enclosing method so the closure needs no use().
                $this->log[] = ['sql' => $sql, 'bindings' => array_values($bindings)];
                if (stripos($sql, 'COUNT(') !== false) {
                    return [['count' => 0]];
                }
                return [];
            });

        return new ItemRepository($db);
    }

    private function ceiling(): int
    {
        $value = (new ReflectionClass(ItemRepository::class))->getConstant('ABSOLUTE_ROW_CEILING');
        $this->assertIsInt($value);
        return $value;
    }

    /** @return array{sql: string, bindings: list<mixed>} */
    private function lastQuery(): array
    {
        $this->assertNotEmpty($this->log, 'the repository must have issued a query');
        return $this->log[count($this->log) - 1];
    }

    public function testTheDeclaredCeilingIsStillTheS99TenThousand(): void
    {
        // Tripwire, not a preference: PhotoController::getSlideshow() asks for
        // exactly 10,000 rows. Anyone lowering the ceiling truncates it silently
        // and must say so in the same commit — this pin forces the conversation.
        $this->assertSame(10000, $this->ceiling());
    }

    // ------------------------------------------------------------------
    // Law (b) — the ceiling reaches every caller-fed LIMIT binding

    public function testGetByLibraryClampsAnUnboundedLimitAndNegativeOffset(): void
    {
        $ceiling = $this->ceiling();
        $this->repo()->getByLibrary('lib-1', PHP_INT_MAX, 0);
        $q = $this->lastQuery();
        $this->assertSame($ceiling, $q['bindings'][1], 'LIMIT bind must be min(max(1,·), ceiling)');
        $this->assertStringContainsString(', id ASC LIMIT ? OFFSET ?', $q['sql']);

        $this->repo()->getByLibrary('lib-1', 0, -7);
        $q = $this->lastQuery();
        $this->assertSame(1, $q['bindings'][1], 'limit 0 must normalize up to 1 (max(1,·))');
        $this->assertSame(0, $q['bindings'][2], 'negative offsets normalize to 0');
    }

    public function testGetTopLevelByLibraryClampsLimitAndNormalizesOffset(): void
    {
        $ceiling = $this->ceiling();
        $this->repo()->getTopLevelByLibrary('lib-1', PHP_INT_MAX, -2);
        $q = $this->lastQuery();
        $this->assertSame($ceiling, $q['bindings'][1]);
        $this->assertSame(0, $q['bindings'][2]);
        $this->assertStringContainsString('ORDER BY id ASC', $q['sql'], 'the stable cursor stays');
    }

    public function testGetByTypeKeepsItsCeilingThroughTheRatingCapBindings(): void
    {
        $ceiling = $this->ceiling();
        $this->repo()->getByType('lib-1', 'movie', PHP_INT_MAX, 0, ['G', 'PG']);
        $q = $this->lastQuery();
        $n = count($q['bindings']);
        // assertGreaterThan does not narrow $n for psalm (CI attested); the fail()
        // guard form (JoinPasswordThrottleTest) does, and keeps the offset typed.
        if ($n < 2) {
            $this->fail('expected at least 2 bindings (limit+offset), got ' . $n);
        }
        // bindings: [library, type, cap-bindings…, limit, offset]
        $this->assertSame($ceiling, $q['bindings'][$n - 2]);
        $this->assertStringContainsString(', id ASC LIMIT ? OFFSET ?', $q['sql']);
    }

    public function testGetByAllowedRatingsClampsThroughTheMergedBindings(): void
    {
        $ceiling = $this->ceiling();
        $this->repo()->getByAllowedRatings('lib-1', ['G', 'PG'], PHP_INT_MAX, -4);
        $q = $this->lastQuery();
        $n = count($q['bindings']);
        // assertGreaterThan does not narrow $n for psalm (CI attested); the fail()
        // guard form (JoinPasswordThrottleTest) does, and keeps the offset typed.
        if ($n < 2) {
            $this->fail('expected at least 2 bindings (limit+offset), got ' . $n);
        }
        $this->assertSame($ceiling, $q['bindings'][$n - 2]);
        $this->assertSame(0, $q['bindings'][$n - 1]);
        $this->assertStringContainsString(', id ASC', $q['sql']);
    }

    public function testGetByAllowedGenresClampsThroughTheMergedBindings(): void
    {
        $ceiling = $this->ceiling();
        $this->repo()->getByAllowedGenres('lib-1', ['Action'], PHP_INT_MAX, -4);
        $q = $this->lastQuery();
        $n = count($q['bindings']);
        // assertGreaterThan does not narrow $n for psalm (CI attested); the fail()
        // guard form (JoinPasswordThrottleTest) does, and keeps the offset typed.
        if ($n < 2) {
            $this->fail('expected at least 2 bindings (limit+offset), got ' . $n);
        }
        $this->assertSame($ceiling, $q['bindings'][$n - 2]);
        $this->assertSame(0, $q['bindings'][$n - 1]);
        $this->assertStringContainsString(', id ASC', $q['sql']);
    }

    public function testSearchTrioAndRecentlyAddedClampEveryLimitBinding(): void
    {
        $ceiling = $this->ceiling();
        $repo = $this->repo();

        $repo->search('dune', PHP_INT_MAX);
        $repo->searchFuzzy('dune', PHP_INT_MAX);
        $repo->getRecentlyAdded('lib-1', PHP_INT_MAX);

        $this->assertNotEmpty($this->log);
        foreach ($this->log as $entry) {
            if (!isset($entry['bindings']) || $entry['bindings'] === []) {
                continue;
            }
            $last = $entry['bindings'][count($entry['bindings']) - 1];
            if ($last === $ceiling) {
                continue;
            }
            $this->assertLessThanOrEqual(
                $ceiling,
                $last,
                "every trailing LIMIT bind must respect the ceiling:\n" . $entry['sql']
            );
        }

        // Positive presence: the fuzzy arm and the recently-added arm each bound
        // with exactly the ceiling (not "small by accident of other binds").
        $clamped = array_filter(
            $this->log,
            fn (array $e): bool => $e['bindings'] !== []
                && $e['bindings'][count($e['bindings']) - 1] === $ceiling
        );
        $this->assertGreaterThanOrEqual(3, count($clamped), 'the three ceiling sites must all appear');
    }

    // ------------------------------------------------------------------
    // Law (a) text contract — query() + queryWithSmartRules() arms

    public function testQueryDefaultSortOrderCarriesTheIdTail(): void
    {
        $this->repo()->query(['sort' => 'name', 'order' => 'asc'], 'lib-1');
        $q = $this->lastQuery();
        $this->assertStringContainsString(', id ASC LIMIT ? OFFSET ?', $q['sql']);
    }

    public function testQueryYearSortArmCarriesTheIdTail(): void
    {
        // year_sort is the arm whose values tie wholesale across same-year
        // releases — the classic skip/repeat. (Materialized column since
        // minors M-SERVER-20 / W-min100 migration 121; it used to be
        // CAST(metadata_json->'$.year') inlined here.)
        $this->repo()->query(['sort' => 'year', 'order' => 'desc'], 'lib-1');
        $q = $this->lastQuery();
        $this->assertStringContainsString('year_sort DESC', $q['sql'], 'the year arm must be the one under test');
        $this->assertStringContainsString(', id ASC LIMIT ? OFFSET ?', $q['sql']);
    }

    public function testQueryRatingScoreOverrideCarriesTheIdTail(): void
    {
        $this->repo()->query(['sort' => 'rating', 'order' => 'desc'], 'lib-1');
        $q = $this->lastQuery();
        $this->assertStringContainsString('rating_score DESC', $q['sql'], 'the override must be the one under test');
        $this->assertStringContainsString(', id ASC LIMIT ? OFFSET ?', $q['sql']);
    }

    public function testSmartRulesNonJoinArmCarriesTheIdTail(): void
    {
        $this->repo()->queryWithSmartRules([['type' => 'studio', 'value' => 'Fox']], [], 'lib-1');
        $q = $this->lastQuery();
        $this->assertStringNotContainsString('metadata_ratings', $q['sql'], 'the non-join arm must be under test');
        $this->assertStringContainsString(', id ASC LIMIT ? OFFSET ?', $q['sql']);
    }

    public function testSmartRulesJoinArmCarriesTheQUALIFIEDIdTail(): void
    {
        $this->repo()->queryWithSmartRules([['type' => 'rating_range', 'value' => '7-10']], [], 'lib-1');
        // Two queries on this arm (count + select); the select is last.
        $q = $this->lastQuery();
        $this->assertStringContainsString('LEFT JOIN metadata_ratings r', $q['sql'], 'the join arm must be under test');
        $this->assertStringContainsString(', media_items.id ASC LIMIT ? OFFSET ?', $q['sql']);
    }
}
