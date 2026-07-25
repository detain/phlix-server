<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Stats;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Library\MediaItemShaper;
use Phlix\Stats\StatsCollector;
use Phlix\Stats\StorageSnapshotHelper;
use Workerman\MySQL\Connection;

/**
 * @covers \Phlix\Stats\StorageSnapshotHelper
 */
class StorageSnapshotHelperTest extends TestCase
{
    /**
     * The EXACT members of the `media_items.type` column ENUM as built up by
     * migrations 001 → 011 → 034. Spelled out literally (rather than derived)
     * so this test fails loudly if either the schema or the fold map moves.
     *
     * @var list<string>
     */
    private const MEDIA_ITEM_TYPE_ENUM = [
        'movie',
        'series',
        'season',
        'episode',
        'track',
        'music',
        'album',
        'artist',
        'video',
        'audio',
        'book',
        'photo',
        'audiobook',
    ];

    /**
     * The EXACT members of the `stats_storage.media_type` column ENUM after
     * migration 086 widened it with `book`.
     *
     * @var list<string>
     */
    private const STATS_STORAGE_MEDIA_TYPE_ENUM = ['movie', 'series', 'music', 'photo', 'book'];

    /**
     * Build a DB mock whose type-count query returns the given rows.
     *
     * `bootstrapSnapshot()` also asks `stats_storage` how old the newest snapshot
     * is (see {@see StorageSnapshotHelper::bootstrapSnapshot()}), so the mock
     * answers per statement rather than per call: by default it reports an EMPTY
     * `stats_storage` (i.e. stale ⇒ record), and `$snapshotAgeSeconds` makes it
     * report an existing snapshot of that age.
     *
     * @param array<int, array{type: string, item_count: int}> $rows
     * @param int|null $snapshotAgeSeconds Age of the newest stats_storage row, or
     *        null for "table is empty".
     * @return Connection&\PHPUnit\Framework\MockObject\MockObject
     */
    private function dbReturningTypeCounts(array $rows, ?int $snapshotAgeSeconds = null)
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function (string $sql) use ($rows, $snapshotAgeSeconds): array {
                if (str_contains($sql, 'FROM stats_storage')) {
                    return $snapshotAgeSeconds === null
                        ? [['newest' => null, 'age_seconds' => null]]
                        : [['newest' => '2026-01-01 00:00:00', 'age_seconds' => $snapshotAgeSeconds]];
                }

                return $rows;
            }
        );

        return $db;
    }

    public function testTypeToBucketCoversEveryMediaItemTypeEnumMember(): void
    {
        $mapped = array_keys(StorageSnapshotHelper::TYPE_TO_BUCKET);
        sort($mapped);

        $expected = self::MEDIA_ITEM_TYPE_ENUM;
        sort($expected);

        $this->assertSame(
            $expected,
            $mapped,
            'Every media_items.type ENUM member must fold to a bucket; an unmapped '
            . 'type is silently dropped from the storage snapshot.'
        );
    }

    public function testTypeToBucketAgreesWithMediaItemShaperValidTypes(): void
    {
        $shaperTypes = (new \ReflectionClass(MediaItemShaper::class))
            ->getConstant('VALID_TYPES');

        $this->assertIsArray($shaperTypes);

        $shaper = $shaperTypes;
        sort($shaper);

        $mapped = array_keys(StorageSnapshotHelper::TYPE_TO_BUCKET);
        sort($mapped);

        $this->assertSame(
            $shaper,
            $mapped,
            'StorageSnapshotHelper::TYPE_TO_BUCKET and MediaItemShaper::VALID_TYPES '
            . 'enumerate the same column ENUM and must stay in lockstep.'
        );
    }

    public function testEveryBucketIsAValidStatsStorageEnumMember(): void
    {
        $this->assertSame(self::STATS_STORAGE_MEDIA_TYPE_ENUM, StorageSnapshotHelper::BUCKETS);

        foreach (StorageSnapshotHelper::TYPE_TO_BUCKET as $type => $bucket) {
            $this->assertContains(
                $bucket,
                self::STATS_STORAGE_MEDIA_TYPE_ENUM,
                sprintf('Type "%s" folds to "%s", which is not a stats_storage ENUM member.', $type, $bucket)
            );
        }
    }

    public function testCollectBucketsAlwaysReturnsEveryBucketEvenWithNoRows(): void
    {
        $buckets = StorageSnapshotHelper::collectBuckets($this->dbReturningTypeCounts([]));

        $this->assertSame(StorageSnapshotHelper::BUCKETS, array_keys($buckets));
        foreach ($buckets as $totals) {
            $this->assertSame(0, $totals['count']);
            $this->assertSame(0, $totals['bytes']);
        }
    }

    /**
     * `track` is the type the music scanner actually writes, and it was absent
     * from the pre-fix map — so the Music bucket counted zero for a fully
     * populated music library.
     */
    public function testTrackCountsTowardTheMusicBucket(): void
    {
        $buckets = StorageSnapshotHelper::collectBuckets($this->dbReturningTypeCounts([
            ['type' => 'track', 'item_count' => 4200],
        ]));

        $this->assertSame(4200, $buckets['music']['count']);
    }

    public function testBookAndAudiobookBothCountTowardTheBookBucket(): void
    {
        $buckets = StorageSnapshotHelper::collectBuckets($this->dbReturningTypeCounts([
            ['type' => 'book', 'item_count' => 12],
            ['type' => 'audiobook', 'item_count' => 5],
        ]));

        $this->assertSame(17, $buckets['book']['count']);
    }

    public function testVideoCountsTowardTheMovieBucket(): void
    {
        $buckets = StorageSnapshotHelper::collectBuckets($this->dbReturningTypeCounts([
            ['type' => 'movie', 'item_count' => 150],
            ['type' => 'video', 'item_count' => 7],
        ]));

        $this->assertSame(157, $buckets['movie']['count']);
    }

    public function testAllThirteenTypesFoldWithoutDroppingAnyCount(): void
    {
        $rows = [];
        foreach (self::MEDIA_ITEM_TYPE_ENUM as $index => $type) {
            // Distinct per-type counts so a mis-fold changes the totals.
            $rows[] = ['type' => $type, 'item_count' => $index + 1];
        }
        $expectedTotal = array_sum(range(1, count(self::MEDIA_ITEM_TYPE_ENUM)));

        $buckets = StorageSnapshotHelper::collectBuckets($this->dbReturningTypeCounts($rows));

        $actualTotal = 0;
        foreach ($buckets as $totals) {
            $actualTotal += $totals['count'];
        }

        $this->assertSame(
            $expectedTotal,
            $actualTotal,
            'No row of any valid type may be dropped by the fold.'
        );
    }

    public function testUnknownTypeIsIgnoredRatherThanFatal(): void
    {
        $buckets = StorageSnapshotHelper::collectBuckets($this->dbReturningTypeCounts([
            ['type' => 'movie', 'item_count' => 3],
            ['type' => 'not-a-real-enum-member', 'item_count' => 99],
        ]));

        $this->assertSame(3, $buckets['movie']['count']);
        $actualTotal = 0;
        foreach ($buckets as $totals) {
            $actualTotal += $totals['count'];
        }
        $this->assertSame(3, $actualTotal);
    }

    public function testNonStringTypeIsIgnored(): void
    {
        /** @var array<int, array{type: string, item_count: int}> $rows */
        $rows = [
            ['type' => null, 'item_count' => 10],
            ['item_count' => 20],
        ];

        $buckets = StorageSnapshotHelper::collectBuckets($this->dbReturningTypeCounts($rows));

        foreach ($buckets as $totals) {
            $this->assertSame(0, $totals['count']);
        }
    }

    /**
     * ONE batch call carrying every bucket — not one call per bucket.
     *
     * `DashboardService::getStorageSummary()` SUMS the rows that share a
     * `recorded_at` second, so a snapshot run must produce exactly one row per
     * bucket (S102 review r1, MED-2). Recording bucket-by-bucket is what let
     * several raw types collide inside one bucket.
     */
    public function testBootstrapSnapshotRecordsOneSnapshotPerBucketInASingleBatch(): void
    {
        $db = $this->dbReturningTypeCounts([
            ['type' => 'episode', 'item_count' => 300],
            ['type' => 'track', 'item_count' => 900],
        ]);

        /** @var array<string, int> $recorded */
        $recorded = [];
        $collector = $this->createMock(StatsCollector::class);
        $collector->expects($this->never())->method('recordStorageSnapshot');
        $collector->expects($this->once())
            ->method('recordStorageSnapshots')
            ->willReturnCallback(
                function (array $totals) use (&$recorded): void {
                    foreach ($totals as $bucket => $entry) {
                        $recorded[$bucket] = $entry['count'];
                    }
                }
            );

        StorageSnapshotHelper::bootstrapSnapshot($collector, $db);

        $this->assertSame(StorageSnapshotHelper::BUCKETS, array_keys($recorded));
        $this->assertSame(300, $recorded['series']);
        $this->assertSame(900, $recorded['music']);
        $this->assertSame(0, $recorded['photo']);
        $this->assertSame(0, $recorded['book']);
    }

    /**
     * `public/index.php` calls `bootstrapSnapshot()` on EVERY PHP-FPM request. Its
     * docblock always promised "if data is stale or missing", but nothing checked
     * — so every request re-ran `du -sb` over both vault roots and wrote another
     * five rows. Now that the dashboard SUMS same-second rows, a second run inside
     * one `recorded_at` second would double the totals, so the promise has to be
     * real (S102 review r1, MED-2).
     */
    public function testBootstrapSnapshotSkipsWhenTheNewestSnapshotIsFresh(): void
    {
        $db = $this->dbReturningTypeCounts(
            [['type' => 'movie', 'item_count' => 10]],
            60,
        );

        $collector = $this->createMock(StatsCollector::class);
        $collector->expects($this->never())->method('recordStorageSnapshots');
        $collector->expects($this->never())->method('recordStorageSnapshot');

        StorageSnapshotHelper::bootstrapSnapshot($collector, $db);
    }

    public function testBootstrapSnapshotRecordsWhenTheNewestSnapshotIsStale(): void
    {
        $db = $this->dbReturningTypeCounts(
            [['type' => 'movie', 'item_count' => 10]],
            86_400,
        );

        $collector = $this->createMock(StatsCollector::class);
        $collector->expects($this->once())->method('recordStorageSnapshots');

        StorageSnapshotHelper::bootstrapSnapshot($collector, $db);
    }

    /**
     * S102 review r2 LOW-5 — a FUTURE-dated newest row must not read as "fresh
     * forever", which contradicted `snapshotIsStale()`'s own "fails OPEN" docblock.
     *
     * `TIMESTAMPDIFF(SECOND, MAX(recorded_at), NOW())` is NEGATIVE once the newest
     * row is ahead of the clock (the clock stepped backwards after a write, or one
     * stray row carries a future date), and a plain `>=` then never fires again: the
     * PHP-FPM fallback stops refreshing permanently. Measured pre-fix: one row dated
     * +1 day → `bootstrapSnapshot()` wrote nothing at all, forever.
     *
     * @return array<string, array{0: int}>
     */
    public static function futureDatedSnapshotProvider(): array
    {
        return [
            'a day into the future' => [-86_400],
            'a year into the future' => [-31_536_000],
            'just past the staleness window, backwards' => [-21_600],
        ];
    }

    /**
     * @dataProvider futureDatedSnapshotProvider
     */
    public function testBootstrapSnapshotRecordsWhenTheNewestSnapshotIsDatedInTheFuture(int $age): void
    {
        $db = $this->dbReturningTypeCounts([['type' => 'movie', 'item_count' => 10]], $age);

        $collector = $this->createMock(StatsCollector::class);
        $collector->expects($this->once())
            ->method('recordStorageSnapshots')
            ->willReturnCallback(static function (): void {
            });

        StorageSnapshotHelper::bootstrapSnapshot($collector, $db);
    }

    /**
     * The other side of LOW-5: a few seconds of clock jitter is NOT staleness, so
     * the absolute-distance comparison must not turn every tiny backwards step into
     * a fresh `du -sb` sweep of both vault roots.
     */
    public function testBootstrapSnapshotStillSkipsForAFewSecondsOfBackwardsJitter(): void
    {
        $db = $this->dbReturningTypeCounts([['type' => 'movie', 'item_count' => 10]], -5);

        $collector = $this->createMock(StatsCollector::class);
        $collector->expects($this->never())->method('recordStorageSnapshots');

        StorageSnapshotHelper::bootstrapSnapshot($collector, $db);
    }
}
