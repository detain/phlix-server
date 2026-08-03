<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media;

use Phlix\Media\Library\ItemRepository;
use Phlix\Media\RecommendationService;
use Phlix\Media\SimilarityService;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Unit tests for {@see RecommendationService::computeBecauseYouWatched()}.
 *
 * These pin the SPLIT between the two watch-history reads, which is easy to
 * "tidy" back into one query and silently regress:
 *
 *  - the SEED list is BOUNDED by {@see RecommendationService::MAX_WATCHED_SEEDS}
 *    because each seed costs one `getSimilar()` round trip on the event loop;
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

    /** @var list<string> Every media item id getSimilar() was asked about, in order. */
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
            function (mixed ...$args) use ($profileIds, $seedIds, $allWatchedIds): mixed {
                $sql = is_string($args[0] ?? null) ? $args[0] : '';
                $this->calls[] = ['sql' => $sql, 'params' => $args[1] ?? null];

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

                // DELETE / INSERT on user_recommendations.
                return 1;
            }
        );

        return new RecommendationService($db, $this->makeSimilarityService($similarBySeed));
    }

    /**
     * A real (final) SimilarityService over a mocked DB, so getSimilar() returns
     * exactly the rows the test wants for each seed.
     *
     * @param array<string, list<array{id: string, score: float}>> $similarBySeed
     */
    private function makeSimilarityService(array $similarBySeed): SimilarityService
    {
        $simDb = $this->createMock(Connection::class);
        $simDb->method('query')->willReturnCallback(
            function (mixed ...$args) use ($similarBySeed): array {
                $params = $args[1] ?? null;
                $source = is_array($params) && isset($params[0]) && is_string($params[0]) ? $params[0] : '';
                $this->similarLookups[] = $source;

                $rows = [];
                foreach ($similarBySeed[$source] ?? [] as $similar) {
                    $rows[] = [
                        'similar_item_id' => $similar['id'],
                        'score' => (string) $similar['score'],
                        'reason' => 'genre',
                        'title' => 'Title of ' . $similar['id'],
                        'metadata_json' => null,
                    ];
                }

                return $rows;
            }
        );

        /** @var ItemRepository&\PHPUnit\Framework\MockObject\MockObject $itemRepo */
        $itemRepo = $this->createMock(ItemRepository::class);

        return new SimilarityService($simDb, $itemRepo);
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
     * The fan-out is driven by the BOUNDED seed list, not by the complete
     * exclusion set: one getSimilar() per seed and no more, even when the
     * account has 300 completions.
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
            'One getSimilar() per SEED — the exclusion set must never drive the fan-out.'
        );
        $this->assertSame($seeds, $this->similarLookups);
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
     * issues exactly one of each (plus the profile lookup) — the split must not
     * cost an extra profile round trip per list.
     */
    public function testHistoryIsReadWithExactlyThreeQueriesBeforeScoring(): void
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
        }

        $this->assertSame(1, $profileCalls, 'Profiles are resolved once and shared by both history reads.');
        $this->assertSame(1, $seedCalls);
        $this->assertSame(1, $exclusionCalls);
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
