<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\LiveTv\Relay;

use PHPUnit\Framework\TestCase;
use Phlix\LiveTv\Relay\SegmentCache;

/**
 * Eviction behaviour for {@see SegmentCache}.
 *
 * Covers:
 *   - N+1 push when count cap is N evicts the oldest entry.
 *   - Refcount > 0 pins a segment so it survives eviction.
 *   - Cumulative-duration cap evicts independently of count cap.
 *   - size()/totalDuration()/stats() report accurate values.
 *
 * @covers \Phlix\LiveTv\Relay\SegmentCache
 *
 * @since Wave 2 (post-O.7)
 */
final class SegmentCacheEvictionTest extends TestCase
{
    public function testPushingNplus1SegmentsEvictsOldestWhenCountCapReached(): void
    {
        $cache = new SegmentCache(maxSegments: 3, maxBufferSeconds: 1000.0);

        $cache->push('seg-1', 'aaa', 2.0);
        $cache->push('seg-2', 'bbb', 2.0);
        $cache->push('seg-3', 'ccc', 2.0);
        $cache->push('seg-4', 'ddd', 2.0); // triggers eviction of seg-1

        $this->assertSame(3, $cache->size());
        $this->assertNull($cache->get('seg-1'), 'oldest segment must be evicted');
        $this->assertSame('bbb', $cache->get('seg-2'));
        $this->assertSame('ccc', $cache->get('seg-3'));
        $this->assertSame('ddd', $cache->get('seg-4'));
    }

    public function testPinnedSegmentIsNotEvictedEvenWhenItIsOldest(): void
    {
        $cache = new SegmentCache(maxSegments: 2, maxBufferSeconds: 1000.0);

        $cache->push('seg-old', 'aaa', 2.0);
        $this->assertTrue($cache->acquire('seg-old'));

        $cache->push('seg-mid', 'bbb', 2.0);
        $cache->push('seg-new', 'ccc', 2.0); // would normally evict seg-old, but it's pinned

        // seg-old is pinned, so seg-mid (the oldest releasable) is evicted instead.
        $this->assertSame('aaa', $cache->get('seg-old'), 'pinned segment must survive eviction');
        $this->assertNull($cache->get('seg-mid'), 'next-oldest unpinned segment must be evicted');
        $this->assertSame('ccc', $cache->get('seg-new'));

        // Releasing then pushing again should now allow seg-old to be evicted.
        $cache->release('seg-old');
        $cache->push('seg-newer', 'ddd', 2.0);

        $this->assertNull($cache->get('seg-old'), 'unpinned segment becomes eligible again');
    }

    public function testDurationCapEvictsIndependentlyOfCountCap(): void
    {
        // Count cap is generous; duration cap is the binding constraint.
        $cache = new SegmentCache(maxSegments: 1000, maxBufferSeconds: 10.0);

        $cache->push('seg-1', 'x', 5.0);
        $cache->push('seg-2', 'x', 5.0);
        $this->assertSame(10.0, $cache->totalDuration());
        $this->assertSame(2, $cache->size());

        // Adding a 5s segment pushes total to 15s > 10s cap → evict oldest.
        $cache->push('seg-3', 'x', 5.0);

        $this->assertSame(2, $cache->size(), 'duration cap must trigger eviction');
        $this->assertNull($cache->get('seg-1'), 'oldest evicted under duration pressure');
        $this->assertSame(10.0, $cache->totalDuration());
    }

    public function testStatsReportAccurateCountsAndDurations(): void
    {
        $cache = new SegmentCache(maxSegments: 6, maxBufferSeconds: 30.0);

        $cache->push('seg-1', 'aa', 4.0);
        $cache->push('seg-2', 'bbbb', 6.0);

        $stats = $cache->stats();
        $this->assertSame(6, $stats['max_segments']);
        $this->assertSame(30.0, $stats['max_buffer_seconds']);
        $this->assertSame(2, $stats['segments']);
        $this->assertSame(10.0, $stats['buffered_seconds']);
    }

    public function testReleaseClampsAtZero(): void
    {
        $cache = new SegmentCache(maxSegments: 2, maxBufferSeconds: 1000.0);
        $cache->push('seg-1', 'x', 1.0);

        // Releasing more times than acquired should not underflow.
        $cache->release('seg-1');
        $cache->release('seg-1');
        $cache->release('seg-1');

        $cache->push('seg-2', 'y', 1.0);
        $cache->push('seg-3', 'z', 1.0); // should evict seg-1 cleanly

        $this->assertNull($cache->get('seg-1'));
        $this->assertSame(2, $cache->size());
    }
}
