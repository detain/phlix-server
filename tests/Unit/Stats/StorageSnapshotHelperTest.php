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
     * @param array<int, array{type: string, item_count: int}> $rows
     * @return Connection&\PHPUnit\Framework\MockObject\MockObject
     */
    private function dbReturningTypeCounts(array $rows)
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')
            ->with($this->stringContains('FROM media_items'))
            ->willReturn($rows);

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

    public function testBootstrapSnapshotRecordsOneSnapshotPerBucket(): void
    {
        $db = $this->dbReturningTypeCounts([
            ['type' => 'episode', 'item_count' => 300],
            ['type' => 'track', 'item_count' => 900],
        ]);

        $recorded = [];
        $collector = $this->createMock(StatsCollector::class);
        $collector->method('recordStorageSnapshot')
            ->willReturnCallback(
                function (string $mediaType, int $count, int $bytes) use (&$recorded): void {
                    $recorded[$mediaType] = $count;
                }
            );

        StorageSnapshotHelper::bootstrapSnapshot($collector, $db);

        $this->assertSame(StorageSnapshotHelper::BUCKETS, array_keys($recorded));
        $this->assertSame(300, $recorded['series']);
        $this->assertSame(900, $recorded['music']);
        $this->assertSame(0, $recorded['photo']);
        $this->assertSame(0, $recorded['book']);
    }
}
