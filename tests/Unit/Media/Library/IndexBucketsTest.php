<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Library\IndexBuckets;

class IndexBucketsTest extends TestCase
{
    private IndexBuckets $buckets;

    protected function setUp(): void
    {
        $this->buckets = new IndexBuckets();
    }

    public function testNameBucketsOnePerLetter(): void
    {
        $distincts = [
            ['value' => 'Alpha', 'count' => 1],
            ['value' => 'Bravo', 'count' => 1],
        ];

        $result = $this->buckets->build(IndexBuckets::FIELD_NAME, $distincts, 'asc');

        $this->assertCount(2, $result);
        $this->assertSame('A', $result[0]['key']);
        $this->assertSame('A', $result[0]['label']);
        $this->assertSame(0, $result[0]['offset']);
        $this->assertSame(1, $result[0]['count']);

        $this->assertSame('B', $result[1]['key']);
        $this->assertSame('B', $result[1]['label']);
        $this->assertSame(1, $result[1]['offset']);
        $this->assertSame(1, $result[1]['count']);
    }

    public function testNameBucketsCountSum(): void
    {
        $distincts = [
            ['value' => 'Alpha', 'count' => 3],
            ['value' => 'Apple', 'count' => 2],
            ['value' => 'Bravo', 'count' => 5],
        ];

        $result = $this->buckets->build(IndexBuckets::FIELD_NAME, $distincts, 'asc');

        $totalCount = array_sum(array_column($result, 'count'));
        $this->assertSame(10, $totalCount); // 3 + 2 + 5 = 10

        // Alpha and Apple both start with A, so one A bucket with count=5
        // Bravo starts with B, so one B bucket with count=5
        $this->assertCount(2, $result);

        $aBucket = $result[0];
        $this->assertSame('A', $aBucket['key']);
        $this->assertSame(5, $aBucket['count']); // 3 + 2

        $bBucket = $result[1];
        $this->assertSame('B', $bBucket['key']);
        $this->assertSame(5, $bBucket['count']);
    }

    public function testYearBucketsNoCollapse(): void
    {
        // 5 distinct years — below threshold
        $distincts = [
            ['value' => 2018, 'count' => 10],
            ['value' => 2019, 'count' => 20],
            ['value' => 2020, 'count' => 15],
            ['value' => 2021, 'count' => 25],
            ['value' => 2022, 'count' => 30],
        ];

        $result = $this->buckets->build(IndexBuckets::FIELD_YEAR, $distincts, 'asc');

        $this->assertCount(5, $result);
        $this->assertSame('2018', $result[0]['key']);
        $this->assertSame('2018', $result[0]['label']);
        $this->assertSame('2022', $result[4]['key']);
        $this->assertSame('2022', $result[4]['label']);
    }

    public function testYearBucketsCollapseToDecades(): void
    {
        // 35 distinct years — above threshold → collapse to decades
        $distincts = [];
        for ($year = 1990; $year <= 2024; $year++) {
            $distincts[] = ['value' => $year, 'count' => 10];
        }

        $result = $this->buckets->build(IndexBuckets::FIELD_YEAR, $distincts, 'asc');

        // 1990-2024 = 35 years → decades: 1990s, 2000s, 2010s, 2020s = 4 buckets
        $this->assertCount(4, $result);

        $this->assertSame('1990', $result[0]['key']);
        $this->assertSame('1990s', $result[0]['label']);
        $this->assertSame(100, $result[0]['count']); // 10 years × 10 each

        $this->assertSame('2000', $result[1]['key']);
        $this->assertSame('2000s', $result[1]['label']);

        $this->assertSame('2010', $result[2]['key']);
        $this->assertSame('2010s', $result[2]['label']);

        $this->assertSame('2020', $result[3]['key']);
        $this->assertSame('2020s', $result[3]['label']);
        $this->assertSame(50, $result[3]['count']); // 2020-2024 = 5 years × 10 each
    }

    public function testYearBucketsCumulativeOffsets(): void
    {
        $distincts = [
            ['value' => 2018, 'count' => 10],
            ['value' => 2019, 'count' => 5],
            ['value' => 2020, 'count' => 8],
        ];

        $result = $this->buckets->build(IndexBuckets::FIELD_YEAR, $distincts, 'asc');

        // Offsets should be cumulative from 0
        $this->assertSame(0, $result[0]['offset']);   // 2018: starts at 0
        $this->assertSame(10, $result[1]['offset']); // 2019: starts after 2018's 10
        $this->assertSame(15, $result[2]['offset']); // 2020: starts after 2018's 10 + 2019's 5
    }

    public function testRatingBuckets(): void
    {
        $distincts = [
            ['value' => 'G', 'count' => 20],
            ['value' => 'PG', 'count' => 30],
            ['value' => 'PG-13', 'count' => 15],
            ['value' => 'R', 'count' => 25],
            ['value' => 'NC-17', 'count' => 5],
            ['value' => 'X', 'count' => 3],
            ['value' => 'UNRATED', 'count' => 12],
            ['value' => null, 'count' => 8], // Unrated bucket
        ];

        $result = $this->buckets->build(IndexBuckets::FIELD_RATING, $distincts, 'asc');

        // 7 RATING_ORDER + Unrated = 8 buckets
        $this->assertCount(8, $result);

        // First bucket should be G
        $this->assertSame('G', $result[0]['key']);
        $this->assertSame('G', $result[0]['label']);
        $this->assertSame(20, $result[0]['count']);

        // Last bucket should be Unrated
        $lastBucket = end($result);
        $this->assertSame('Unrated', $lastBucket['key']);
        $this->assertSame('Unrated', $lastBucket['label']);
        $this->assertSame(8, $lastBucket['count']);
    }

    public function testRuntimeBuckets(): void
    {
        $distincts = [
            ['value' => 15, 'count' => 10],   // 1-30min
            ['value' => 45, 'count' => 20],   // 31-60min
            ['value' => 75, 'count' => 15],  // 61-90min
            ['value' => 105, 'count' => 8],  // 91-120min
            ['value' => 150, 'count' => 5],  // 120min+
        ];

        $result = $this->buckets->build(IndexBuckets::FIELD_RUNTIME, $distincts, 'asc');

        $this->assertCount(5, $result);

        $this->assertSame('1-30min', $result[0]['key']);
        $this->assertSame('1-30min', $result[0]['label']);
        $this->assertSame(10, $result[0]['count']);

        $this->assertSame('31-60min', $result[1]['key']);
        $this->assertSame('31-60min', $result[1]['label']);
        $this->assertSame(20, $result[1]['count']);

        $this->assertSame('61-90min', $result[2]['key']);
        $this->assertSame('61-90min', $result[2]['label']);
        $this->assertSame(15, $result[2]['count']);

        $this->assertSame('91-120min', $result[3]['key']);
        $this->assertSame('91-120min', $result[3]['label']);
        $this->assertSame(8, $result[3]['count']);

        $this->assertSame('120min+', $result[4]['key']);
        $this->assertSame('120min+', $result[4]['label']);
        $this->assertSame(5, $result[4]['count']);
    }

    public function testDateAddedBuckets(): void
    {
        $today = date('Y-m-d');
        $thisWeek = date('Y-m-d', strtotime('-1 day')); // Yesterday = Monday this week
        $thisMonth = date('Y-m-d', strtotime('-10 days'));
        $thisYear = date('Y-m-d', strtotime('-60 days'));
        $older = date('Y-m-d', strtotime('-200 days'));

        $distincts = [
            ['value' => $today, 'count' => 5],
            ['value' => $thisWeek, 'count' => 10],
            ['value' => $thisMonth, 'count' => 15],
            ['value' => $thisYear, 'count' => 20],
            ['value' => $older, 'count' => 25],
        ];

        $result = $this->buckets->build(IndexBuckets::FIELD_DATE_ADDED, $distincts, 'asc');

        $this->assertCount(5, $result);

        $this->assertSame('Today', $result[0]['key']);
        $this->assertSame('5', (string) $result[0]['count']);

        $this->assertSame('This week', $result[1]['key']);
        $this->assertSame('10', (string) $result[1]['count']);

        $this->assertSame('This month', $result[2]['key']);
        $this->assertSame('15', (string) $result[2]['count']);

        $this->assertSame('This year', $result[3]['key']);
        $this->assertSame('20', (string) $result[3]['count']);

        $this->assertSame('Older', $result[4]['key']);
        $this->assertSame('25', (string) $result[4]['count']);
    }

    public function testUnknownFieldDefaultsToName(): void
    {
        $distincts = [
            ['value' => 'Zebra', 'count' => 3],
            ['value' => 'Alpha', 'count' => 7],
        ];

        $result = $this->buckets->build('unknown_field', $distincts, 'asc');

        // Should fall back to name bucketing (letter buckets)
        $this->assertCount(2, $result);
        $this->assertSame('A', $result[0]['key']); // Alpha
        $this->assertSame('Z', $result[1]['key']); // Zebra
    }

    public function testEmptyDistinctsReturnsEmptyArray(): void
    {
        $result = $this->buckets->build(IndexBuckets::FIELD_NAME, [], 'asc');

        $this->assertSame([], $result);
    }

    public function testWithOffsetsCumulativeBehavior(): void
    {
        $buckets = [
            ['key' => 'A', 'label' => 'A', 'count' => 10],
            ['key' => 'B', 'label' => 'B', 'count' => 5],
            ['key' => 'C', 'label' => 'C', 'count' => 8],
        ];

        $result = $this->buckets->withOffsets($buckets);

        $this->assertSame(0, $result[0]['offset']);
        $this->assertSame(10, $result[1]['offset']); // 10 + 0
        $this->assertSame(15, $result[2]['offset']); // 10 + 5
    }

    public function testDescOrderReversesBucketOrder(): void
    {
        $distincts = [
            ['value' => 2018, 'count' => 10],
            ['value' => 2019, 'count' => 5],
            ['value' => 2020, 'count' => 8],
        ];

        $ascResult = $this->buckets->build(IndexBuckets::FIELD_YEAR, $distincts, 'asc');
        $descResult = $this->buckets->build(IndexBuckets::FIELD_YEAR, $distincts, 'desc');

        // Keys should be reversed
        $this->assertSame('2018', $ascResult[0]['key']);
        $this->assertSame('2020', $descResult[0]['key']);

        // Counts per bucket should match by key, regardless of display order
        $ascByKey = [];
        foreach ($ascResult as $b) {
            $ascByKey[$b['key']] = $b['count'];
        }
        $descByKey = [];
        foreach ($descResult as $b) {
            $descByKey[$b['key']] = $b['count'];
        }
        ksort($ascByKey);
        ksort($descByKey);
        $this->assertSame($ascByKey, $descByKey);

        // Offsets should still be cumulative within the reversed order
        // desc: [2020(count=8), 2019(count=5), 2018(count=10)]
        // offsets: [0, 8, 13]
        $this->assertSame(0, $descResult[0]['offset']);
        $this->assertSame(8, $descResult[1]['offset']);  // 8 + 0
        $this->assertSame(13, $descResult[2]['offset']); // 8 + 5
    }
}
