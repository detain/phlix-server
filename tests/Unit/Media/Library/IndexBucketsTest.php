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
            ['value' => '', 'count' => 8], // Unrated bucket (empty string matches null/'' treated as Unrated)
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
        // Reference "now" is fixed to a mid-week, mid-month, mid-year day
        // (Wed 2026-06-17; weekStart Mon 2026-06-15) so each of the five buckets
        // is cleanly separable and the test is deterministic regardless of the
        // calendar day it runs on. (Relative offsets like "-10 days" crossed the
        // month boundary on first-of-month days and emptied "This month".)
        $now = strtotime('2026-06-17 12:00:00');
        $today = '2026-06-17'; // == todayStart → Today
        $thisWeek = '2026-06-16'; // >= weekStart(06-15), < today → This week
        $thisMonth = '2026-06-05'; // >= monthStart(06-01), < weekStart → This month
        $thisYear = '2026-03-01'; // >= yearStart(01-01), < monthStart → This year
        $older = '2024-01-01'; // < yearStart → Older

        $distincts = [
            ['value' => $today, 'count' => 5],
            ['value' => $thisWeek, 'count' => 10],
            ['value' => $thisMonth, 'count' => 15],
            ['value' => $thisYear, 'count' => 20],
            ['value' => $older, 'count' => 25],
        ];

        $result = $this->buckets->build(IndexBuckets::FIELD_DATE_ADDED, $distincts, 'asc', $now);

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

    // -------------------------------------------------------------------------
    // Alignment / cumulative-offset verification tests
    // -------------------------------------------------------------------------

    /**
     * Alignment rule: bucket.offset must equal the actual row offset in media_items
     * for items matching that bucket's key, when ordered by the sort field.
     * This test verifies that withOffsets() produces monotonically increasing
     * cumulative offsets that represent the count of all preceding items.
     */
    public function testCumulativeOffsetsMatchCumulativeSumExactly(): void
    {
        // Distinct pairs sorted by value ASC
        $distincts = [
            ['value' => 'A', 'count' => 5],
            ['value' => 'B', 'count' => 3],
            ['value' => 'C', 'count' => 7],
            ['value' => 'D', 'count' => 2],
        ];

        $result = $this->buckets->build(IndexBuckets::FIELD_NAME, $distincts, 'asc');

        // Verify each bucket's offset equals the cumulative count of all previous buckets
        // offset[n] should equal sum(counts[0..n-1])
        $this->assertSame('A', $result[0]['key']);
        $this->assertSame(0, $result[0]['offset']);           // sum(counts before A) = 0
        $this->assertSame(5, $result[0]['count']);

        $this->assertSame('B', $result[1]['key']);
        $this->assertSame(5, $result[1]['offset']);          // sum(counts before B) = 5
        $this->assertSame(3, $result[1]['count']);

        $this->assertSame('C', $result[2]['key']);
        $this->assertSame(8, $result[2]['offset']);          // sum(counts before C) = 5 + 3
        $this->assertSame(7, $result[2]['count']);

        $this->assertSame('D', $result[3]['key']);
        $this->assertSame(15, $result[3]['offset']);         // sum(counts before D) = 5 + 3 + 7
        $this->assertSame(2, $result[3]['count']);
    }

    /**
     * Verifies that every field type starts its first bucket at offset 0.
     * The alignment rule requires offset=0 for the first bucket (no items precede it).
     */
    public function testAllFieldsFirstBucketHasOffsetZero(): void
    {
        $baseDistincts = [['value' => 'test', 'count' => 1]];

        $fields = [
            IndexBuckets::FIELD_NAME,
            IndexBuckets::FIELD_YEAR,
            IndexBuckets::FIELD_RATING,
            IndexBuckets::FIELD_RUNTIME,
            IndexBuckets::FIELD_DATE_ADDED,
        ];

        foreach ($fields as $field) {
            $result = $this->buckets->build($field, $baseDistincts, 'asc');
            $this->assertNotEmpty($result, "Field {$field} should produce at least one bucket");
            $this->assertSame(0, $result[0]['offset'], "Field {$field} first bucket must have offset 0");
        }
    }

    /**
     * Cumulative offsets must never decrease — they are by definition a running sum.
     * This is a fail-fast guard against any future off-by-one errors in withOffsets().
     */
    public function testCumulativeOffsetsNeverDecrease(): void
    {
        $distincts = [
            ['value' => 'P', 'count' => 10],
            ['value' => 'Q', 'count' => 1],
            ['value' => 'R', 'count' => 5],
            ['value' => 'S', 'count' => 3],
            ['value' => 'T', 'count' => 2],
        ];

        $result = $this->buckets->build(IndexBuckets::FIELD_NAME, $distincts, 'asc');

        $previousOffset = -1;
        foreach ($result as $bucket) {
            $this->assertGreaterThanOrEqual(
                $previousOffset,
                $bucket['offset'],
                'Offset must never decrease — cumulative offsets must be monotonically non-decreasing'
            );
            $previousOffset = $bucket['offset'];
        }
    }

    /**
     * The last bucket's offset + its count must equal the total item count.
     * This verifies the alignment rule end-to-end: jumping to the last bucket's
     * offset lands at the correct position, and scrolling by count lands at total.
     */
    public function testLastBucketOffsetPlusCountEqualsTotal(): void
    {
        $distincts = [
            ['value' => 2018, 'count' => 10],
            ['value' => 2019, 'count' => 5],
            ['value' => 2020, 'count' => 3],
            ['value' => 2021, 'count' => 12],
        ];

        $result = $this->buckets->build(IndexBuckets::FIELD_YEAR, $distincts, 'asc');

        $total = array_sum(array_column($result, 'count'));
        $lastBucket = end($result);
        \assert($lastBucket !== false);

        $this->assertSame(
            $total,
            $lastBucket['offset'] + $lastBucket['count'],
            'Last bucket offset + last bucket count must equal total (end of list boundary)'
        );
    }

    /**
     * Single-bucket case: offset must be 0 (no items precede the only bucket).
     */
    public function testSingleBucketHasOffsetZero(): void
    {
        $distincts = [['value' => 'Z', 'count' => 42]];

        $result = $this->buckets->build(IndexBuckets::FIELD_NAME, $distincts, 'asc');

        $this->assertCount(1, $result);
        $this->assertSame(0, $result[0]['offset']);
        $this->assertSame(42, $result[0]['count']);
    }

    /**
     * DESC order: bucket display order is reversed, but within the reversed list,
     * offsets must still be cumulative from 0. The offset represents position in
     * the sorted list, not in the display order — so desc still has valid offsets.
     */
    public function testDescOrderCumulativeOffsetsStillCorrect(): void
    {
        $distincts = [
            ['value' => 2000, 'count' => 5],
            ['value' => 2010, 'count' => 10],
            ['value' => 2020, 'count' => 3],
        ];

        $descResult = $this->buckets->build(IndexBuckets::FIELD_YEAR, $distincts, 'desc');

        // desc: [2020(count=3), 2010(count=10), 2000(count=5)]
        // offsets: [0, 3, 13]
        $this->assertCount(3, $descResult);

        // First bucket in desc (largest year) still starts at 0
        $this->assertSame('2020', $descResult[0]['key']);
        $this->assertSame(0, $descResult[0]['offset']);
        $this->assertSame(3, $descResult[0]['count']);

        // Second bucket offset = first bucket count
        $this->assertSame('2010', $descResult[1]['key']);
        $this->assertSame(3, $descResult[1]['offset']);   // 0 + 3
        $this->assertSame(10, $descResult[1]['count']);

        // Third bucket offset = first + second counts
        $this->assertSame('2000', $descResult[2]['key']);
        $this->assertSame(13, $descResult[2]['offset']); // 0 + 3 + 10
        $this->assertSame(5, $descResult[2]['count']);

        // And the total alignment check
        $total = array_sum(array_column($descResult, 'count'));
        $lastBucket = end($descResult);
        $this->assertSame($total, $lastBucket['offset'] + $lastBucket['count']);
    }

    /**
     * All-zero counts: every bucket has count=0, so every offset must be 0
     * (no items precede anything, and jumping to offset 0 is always valid).
     */
    public function testAllZeroCountsAllZeroOffsets(): void
    {
        $distincts = [
            ['value' => 'X', 'count' => 0],
            ['value' => 'Y', 'count' => 0],
            ['value' => 'Z', 'count' => 0],
        ];

        $result = $this->buckets->build(IndexBuckets::FIELD_NAME, $distincts, 'asc');

        foreach ($result as $bucket) {
            $this->assertSame(0, $bucket['offset'], 'Zero-count buckets must all have offset 0');
            $this->assertSame(0, $bucket['count']);
        }
    }

    /**
     * The total in the response must equal the sum of all bucket counts.
     * This is the server↔rail alignment: total items = sum of items per bucket.
     */
    public function testTotalEqualsSumOfBucketCounts(): void
    {
        $distincts = [
            ['value' => 'G', 'count' => 3],
            ['value' => 'PG', 'count' => 7],
            ['value' => 'PG-13', 'count' => 2],
            ['value' => 'R', 'count' => 5],
            ['value' => 'NC-17', 'count' => 1],
            ['value' => 'X', 'count' => 0],
            ['value' => 'UNRATED', 'count' => 4],
        ];

        $result = $this->buckets->build(IndexBuckets::FIELD_RATING, $distincts, 'asc');

        $totalFromBuckets = array_sum(array_column($result, 'count'));
        $this->assertSame(22, $totalFromBuckets); // 3+7+2+5+1+0+4
    }
}
