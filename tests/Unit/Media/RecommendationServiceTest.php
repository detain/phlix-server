<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media;

use Phlix\Media\RecommendationService;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Unit tests for {@see RecommendationService::computeBecauseYouWatched()}.
 *
 * These pin the SPLIT between the two watch-history reads, which is easy to
 * "tidy" back into one query and silently regress:
 *
 *  - the SEED list is BOUNDED by {@see RecommendationService::MAX_WATCHED_SEEDS}
 *    because the seed count sizes the ONE batched `IN(...)` similarity read
 *    (pre-R19-12 every seed cost its own `getSimilar()` round trip);
 *  - the already-watched EXCLUSION set is UNBOUNDED, because a partial exclusion
 *    set recommends items the user already finished — the worse the longer their
 *    history is.
 *
 * The SQL shape of both queries is asserted too: the seed query must keep its
 * `GROUP BY` + `MAX()` + `ORDER BY` + `LIMIT ?` form and the exclusion query must
 * carry no `ORDER BY`, because prod runs with `ONLY_FULL_GROUP_BY` and rejects
 * `SELECT DISTINCT col ... ORDER BY other_col` outright (error 3065).
 */
final class RecommendationServiceTest extends TestCase
{
    private const USER = 'user-1';
    private const PROFILE_A = 'profile-a';
    private const PROFILE_B = 'profile-b';

    /** @var list<array{sql: string, params: mixed}> Every query issued on the service DB. */
    private array $calls = [];

    /** @var list<string> Every seed id the batched similarity read was asked for, in order. */
    private array $similarLookups = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->calls = [];
        $this->similarLookups = [];
    }

    // ---------------------------------------------------------------- harness

    /**
     * Builds the service with a mocked DB that answers each of its queries.
     *
     * @param list<string> $profileIds Rows the user_profiles lookup returns.
     * @param list<string> $seedIds Rows the BOUNDED seed query returns (the mock
     *        cannot enforce SQL `LIMIT`, so the caller supplies the capped slice).
     * @param list<string> $allWatchedIds Rows the UNBOUNDED exclusion query returns.
     * @param array<string, list<array{id: string, score: float}>> $similarBySeed
     */
    private function makeService(
        array $profileIds,
        array $seedIds,
        array $allWatchedIds,
        array $similarBySeed = []
    ): RecommendationService {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (mixed ...$args) use ($profileIds, $seedIds, $allWatchedIds, $similarBySeed): mixed {
                $sql = is_string($args[0] ?? null) ? $args[0] : '';
                $params = $args[1] ?? null;
                $this->calls[] = ['sql' => $sql, 'params' => $params];

                if (str_contains($sql, 'FROM user_profiles')) {
                    return array_map(static fn(string $id): array => ['id' => $id], $profileIds);
                }
                if (str_contains($sql, 'MAX(last_watched_at)')) {
                    return array_map(
                        static fn(string $id): array => [
                            'media_item_id' => $id,
                            'last_completed_at' => '2026-07-27 12:00:00',
                        ],
                        $seedIds
                    );
                }
                if (str_contains($sql, 'SELECT DISTINCT media_item_id')) {
                    return array_map(static fn(string $id): array => ['media_item_id' => $id], $allWatchedIds);
                }
                if (str_contains($sql, 'FROM item_similar')) {
                    // R19-12 batched read: positional params are the seed list
                    // followed by the per-seed LIMIT. The mock cannot enforce
                    // `rn <= ?` — the caller supplies each seed's rows.
                    $requested = [];
                    if (is_array($params)) {
                        foreach (array_slice(array_values($params), 0, -1) as $param) {
                            if (is_string($param)) {
                                $requested[] = $param;
                            }
                        }
                    }
                    foreach ($requested as $seed) {
                        $this->similarLookups[] = $seed;
                    }

                    $rows = [];
                    foreach ($requested as $seed) {
                        foreach ($similarBySeed[$seed] ?? [] as $similar) {
                            $rows[] = [
                                'seed_id' => $seed,
                                'similar_item_id' => $similar['id'],
                                'score' => (string) $similar['score'],
                                'reason' => 'genre',
                            ];
                        }
                    }

                    return $rows;
                }

                // DELETE / INSERT on user_recommendations.
                return 1;
            }
        );

        return new RecommendationService($db);
    }

    /**
     * @param string $needle Substring identifying the query.
     * @return array{sql: string, params: mixed}|null
     */
    private function findCall(string $needle): ?array
    {
        foreach ($this->calls as $call) {
            if (str_contains($call['sql'], $needle)) {
                return $call;
            }
        }

        return null;
    }

    /**
     * Extracts the media item ids written by the user_recommendations INSERT.
     *
     * The insert binds five values per row: (user_id, media_item_id, reason,
     * score, computed_at).
     *
     * @return list<string>
     */
    private function insertedRecommendationIds(): array
    {
        $call = $this->findCall('INSERT INTO user_recommendations');
        if ($call === null || !is_array($call['params'])) {
            return [];
        }

        $params = array_values($call['params']);
        $ids = [];
        for ($i = 1; $i < count($params); $i += 5) {
            if (is_string($params[$i])) {
                $ids[] = $params[$i];
            }
        }

        return $ids;
    }

    /**
     * @param int $count How many ids to generate.
     * @return list<string>
     */
    private function watchedIds(int $count): array
    {
        $ids = [];
        for ($i = 1; $i <= $count; $i++) {
            $ids[] = sprintf('watched-%03d', $i);
        }

        return $ids;
    }

    // ------------------------------------------------------------------ tests

    /**
     * THE REGRESSION. An account with more completions than MAX_WATCHED_SEEDS:
     * an item completed long ago sits OUTSIDE the seed window, so it is absent
     * from the seed list but present in the exclusion set. It must never be
     * recommended back.
     *
     * Reusing the capped seed list as the exclusion set makes this fail.
     */
    public function testExclusionSetCoversWatchedItemsOutsideTheSeedWindow(): void
    {
        $allWatched = $this->watchedIds(300);
        $seeds = array_slice($allWatched, 0, RecommendationService::MAX_WATCHED_SEEDS);

        // watched-300 was completed long ago: outside the 50-seed window, but
        // still a completed item. watched-002 is inside the window.
        $service = $this->makeService(
            [self::PROFILE_A],
            $seeds,
            $allWatched,
            [
                'watched-001' => [
                    ['id' => 'watched-300', 'score' => 0.99],
                    ['id' => 'watched-002', 'score' => 0.98],
                    ['id' => 'fresh-a', 'score' => 0.50],
                ],
            ]
        );

        $service->computeBecauseYouWatched(self::USER);

        $inserted = $this->insertedRecommendationIds();

        $this->assertNotContains(
            'watched-300',
            $inserted,
            'An item completed outside the seed window is still WATCHED and must not be recommended.'
        );
        $this->assertNotContains('watched-002', $inserted, 'Seeds themselves must not be recommended.');
        $this->assertContains('fresh-a', $inserted, 'Unwatched similar items must still be recommended.');
    }

    /**
     * The exclusion set is read with its OWN query, not derived from the seeds,
     * and that query is given every profile of the account.
     */
    public function testExclusionSetIsFetchedWithItsOwnUnboundedQuery(): void
    {
        $service = $this->makeService(
            [self::PROFILE_A, self::PROFILE_B],
            ['watched-001'],
            ['watched-001', 'watched-002'],
            ['watched-001' => [['id' => 'fresh-a', 'score' => 0.5]]]
        );

        $service->computeBecauseYouWatched(self::USER);

        $call = $this->findCall('SELECT DISTINCT media_item_id');
        $this->assertNotNull($call, 'The exclusion set must be read by its own dedicated query.');
        $this->assertStringContainsString('FROM watch_history', $call['sql']);
        $this->assertStringContainsString("playback_status = 'completed'", $call['sql']);
        $this->assertStringContainsString('profile_id IN (?,?)', $call['sql']);
        $this->assertSame(
            [self::PROFILE_A, self::PROFILE_B],
            $call['params'],
            'Placeholders bind a contiguous list — bindMore() re-keys, so gaps corrupt the binding.'
        );
    }

    /**
     * The exclusion query must carry NO `ORDER BY` (and no `LIMIT`).
     * `SELECT DISTINCT col ... ORDER BY other_col` is rejected by MySQL with
     * error 3065 under the ONLY_FULL_GROUP_BY sql_mode prod runs with, and an
     * exclusion set has no use for ordering anyway.
     */
    public function testExclusionQueryHasNoOrderByAndNoLimit(): void
    {
        $service = $this->makeService(
            [self::PROFILE_A],
            ['watched-001'],
            ['watched-001', 'watched-002'],
            ['watched-001' => [['id' => 'fresh-a', 'score' => 0.5]]]
        );

        $service->computeBecauseYouWatched(self::USER);

        $call = $this->findCall('SELECT DISTINCT media_item_id');
        $this->assertNotNull($call);
        $this->assertStringNotContainsStringIgnoringCase(
            'ORDER BY',
            $call['sql'],
            'SELECT DISTINCT + ORDER BY on a non-selected column is MySQL error 3065.'
        );
        $this->assertStringNotContainsStringIgnoringCase(
            'LIMIT',
            $call['sql'],
            'The exclusion set must stay complete; a LIMIT here reintroduces the regression.'
        );
    }

    /**
     * The similarity read is sized by the BOUNDED seed list, not by the complete
     * exclusion set: the batch asks for exactly the seeds and nothing more, even
     * when the account has 300 completions.
     */
    public function testSeedFanOutIsCappedAtMaxWatchedSeeds(): void
    {
        $allWatched = $this->watchedIds(300);
        $seeds = array_slice($allWatched, 0, RecommendationService::MAX_WATCHED_SEEDS);

        $service = $this->makeService([self::PROFILE_A], $seeds, $allWatched);

        $service->computeBecauseYouWatched(self::USER);

        $this->assertCount(
            RecommendationService::MAX_WATCHED_SEEDS,
            $this->similarLookups,
            'The batched read must carry exactly the seeds — the exclusion set must never size it.'
        );
        $this->assertSame($seeds, $this->similarLookups);
        $this->assertSame(
            1,
            count(array_filter($this->calls, static fn(array $c): bool => str_contains($c['sql'], 'FROM item_similar'))),
            'R19-12: ONE batched similarity read for the whole seed set, not one per seed.'
        );
    }

    /**
     * The seed query binds MAX_WATCHED_SEEDS as its LIMIT, after the profile
     * placeholders, as one contiguous positional list.
     */
    public function testSeedQueryBindsTheCapAsItsLimit(): void
    {
        $service = $this->makeService(
            [self::PROFILE_A, self::PROFILE_B],
            ['watched-001'],
            ['watched-001'],
            ['watched-001' => [['id' => 'fresh-a', 'score' => 0.5]]]
        );

        $service->computeBecauseYouWatched(self::USER);

        $call = $this->findCall('MAX(last_watched_at)');
        $this->assertNotNull($call);
        $this->assertSame(
            [self::PROFILE_A, self::PROFILE_B, RecommendationService::MAX_WATCHED_SEEDS],
            $call['params']
        );
        $this->assertSame(
            [0, 1, 2],
            array_keys((array) $call['params']),
            'bindMore() re-keys positional params — the list must be contiguous.'
        );
    }

    /**
     * The seed query keeps the ONLY_FULL_GROUP_BY-safe shape. Reverting it to
     * `SELECT DISTINCT media_item_id ... ORDER BY last_watched_at` is MySQL
     * error 3065 on prod.
     */
    public function testSeedQueryKeepsGroupByOrderByLimitShape(): void
    {
        $service = $this->makeService(
            [self::PROFILE_A],
            ['watched-001'],
            ['watched-001'],
            ['watched-001' => [['id' => 'fresh-a', 'score' => 0.5]]]
        );

        $service->computeBecauseYouWatched(self::USER);

        $call = $this->findCall('MAX(last_watched_at)');
        $this->assertNotNull($call);
        $sql = $call['sql'];

        $this->assertStringContainsString('MAX(last_watched_at) AS last_completed_at', $sql);
        $this->assertStringContainsString('GROUP BY media_item_id', $sql);
        $this->assertStringContainsString('ORDER BY last_completed_at DESC', $sql);
        $this->assertStringContainsString('LIMIT ?', $sql);
        $this->assertStringNotContainsString(
            'DISTINCT',
            $sql,
            'DISTINCT + ORDER BY on a non-selected column is MySQL error 3065.'
        );
    }

    /**
     * Sanity: the two history reads return different lists, and the recompute
     * issues exactly one of each (plus the profile lookup, plus the ONE batched
     * similarity read) — the split must not cost an extra profile round trip
     * per list, and the fan-out must have collapsed to a single query.
     */
    public function testHistoryIsReadWithThreeHistoryQueriesPlusOneBatchedSimilarityRead(): void
    {
        $service = $this->makeService(
            [self::PROFILE_A],
            ['watched-001'],
            ['watched-001', 'watched-002'],
            ['watched-001' => [['id' => 'fresh-a', 'score' => 0.5]]]
        );

        $service->computeBecauseYouWatched(self::USER);

        $profileCalls = 0;
        $seedCalls = 0;
        $exclusionCalls = 0;
        $batchCalls = 0;
        foreach ($this->calls as $call) {
            if (str_contains($call['sql'], 'FROM user_profiles')) {
                $profileCalls++;
            }
            if (str_contains($call['sql'], 'MAX(last_watched_at)')) {
                $seedCalls++;
            }
            if (str_contains($call['sql'], 'SELECT DISTINCT media_item_id')) {
                $exclusionCalls++;
            }
            if (str_contains($call['sql'], 'FROM item_similar')) {
                $batchCalls++;
            }
        }

        $this->assertSame(1, $profileCalls, 'Profiles are resolved once and shared by both history reads.');
        $this->assertSame(1, $seedCalls);
        $this->assertSame(1, $exclusionCalls);
        $this->assertSame(1, $batchCalls, 'R19-12: all seeds are served by one batched CTE read.');

        $batch = $this->findCall('FROM item_similar');
        $this->assertNotNull($batch);
        $this->assertStringContainsString('ROW_NUMBER() OVER', $batch['sql']);
        $this->assertStringContainsString('PARTITION BY s.media_item_id', $batch['sql']);
        $this->assertStringContainsString('WHERE r.rn <= ?', $batch['sql']);
        $this->assertStringContainsString('INNER JOIN media_items', $batch['sql']);
        $this->assertSame(
            [0, 1],
            array_keys((array) $batch['params']),
            'Seeds then the per-seed limit — positional binding must stay contiguous.'
        );
        $this->assertSame(['watched-001', 20], array_values((array) $batch['params']));
    }

    public function testNoProfilesClearsRecommendationsAndReadsNoHistory(): void
    {
        $service = $this->makeService([], [], []);

        $service->computeBecauseYouWatched(self::USER);

        $this->assertNotNull($this->findCall('DELETE FROM user_recommendations'));
        $this->assertNull($this->findCall('MAX(last_watched_at)'));
        $this->assertNull($this->findCall('SELECT DISTINCT media_item_id'));
        $this->assertNull($this->findCall('INSERT INTO user_recommendations'));
    }

    public function testNoCompletedItemsClearsRecommendationsWithoutInserting(): void
    {
        $service = $this->makeService([self::PROFILE_A], [], []);

        $service->computeBecauseYouWatched(self::USER);

        $this->assertNotNull($this->findCall('DELETE FROM user_recommendations'));
        $this->assertNull($this->findCall('INSERT INTO user_recommendations'));
        $this->assertSame([], $this->similarLookups);
    }

    public function testEmptyUserIdIssuesNoQueries(): void
    {
        $service = $this->makeService([self::PROFILE_A], ['watched-001'], ['watched-001']);

        $service->computeBecauseYouWatched('');

        $this->assertSame([], $this->calls);
    }
}
