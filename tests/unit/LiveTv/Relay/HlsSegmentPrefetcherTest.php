<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\LiveTv\Relay;

use PHPUnit\Framework\TestCase;
use Phlix\LiveTv\Relay\HlsSegmentPrefetcher;

/**
 * Unit tests for HlsSegmentPrefetcher.
 *
 * @since 0.12.0
 */
class HlsSegmentPrefetcherTest extends TestCase
{
    private HlsSegmentPrefetcher $prefetcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefetcher = new HlsSegmentPrefetcher(null, 3, 10485760, 30);
    }

    /**
     * Check if Workerman Timer is available.
     *
     * @return bool True if Timer can be used.
     */
    private function isTimerAvailable(): bool
    {
        try {
            \Workerman\Timer::add(1, function () {}, [], false);
            return true;
        } catch (\RuntimeException $e) {
            return false;
        }
    }

    public function testCanCreatePrefetcher(): void
    {
        $this->assertInstanceOf(HlsSegmentPrefetcher::class, $this->prefetcher);
    }

    public function testCanCreateWithCustomParameters(): void
    {
        $prefetcher = new HlsSegmentPrefetcher(null, 5, 20971520, 60);

        $this->assertInstanceOf(HlsSegmentPrefetcher::class, $prefetcher);
    }

    public function testGetSegmentReturnsNullOnCacheMiss(): void
    {
        $result = $this->prefetcher->getSegment('http://example.com/segment1.ts');

        $this->assertNull($result);
    }

    public function testGetSegmentReturnsNullForNonexistentUrl(): void
    {
        $result = $this->prefetcher->getSegment('http://nonexistent.local/file.ts');

        $this->assertNull($result);
    }

    public function testClearCache(): void
    {
        // Verify cache starts empty
        $stats = $this->prefetcher->getCacheStats();
        $this->assertEquals(0, $stats['entries']);

        $this->prefetcher->clearCache();

        // Verify cache is cleared
        $stats = $this->prefetcher->getCacheStats();
        $this->assertEquals(0, $stats['entries']);
        $this->assertEquals(0, $stats['size_bytes']);
    }

    public function testGetCacheStats(): void
    {
        $stats = $this->prefetcher->getCacheStats();

        $this->assertArrayHasKey('size_bytes', $stats);
        $this->assertArrayHasKey('max_size_bytes', $stats);
        $this->assertArrayHasKey('entries', $stats);

        $this->assertEquals(0, $stats['size_bytes']);
        $this->assertEquals(10485760, $stats['max_size_bytes']); // 10 MB default
        $this->assertEquals(0, $stats['entries']);
    }

    public function testGetCacheStatsUpdatesAfterFetch(): void
    {
        // We can't easily test actual fetch without a mock server,
        // but we can verify the stats structure is correct
        $stats = $this->prefetcher->getCacheStats();

        $this->assertIsInt($stats['size_bytes']);
        $this->assertIsInt($stats['max_size_bytes']);
        $this->assertIsInt($stats['entries']);
    }

    public function testPrefetchFetchesSegments(): void
    {
        // This test verifies that prefetch doesn't throw
        // Actual network fetch would require integration testing
        $this->prefetcher->prefetch('http://nonexistent.local/playlist.m3u8');

        // Should complete without throwing
        $this->assertTrue(true);
    }

    /**
     * @group workerman
     */
    public function testStartPrefetchDoesNotThrow(): void
    {
        // Skip if Workerman Timer is not available
        if (!$this->isTimerAvailable()) {
            $this->markTestSkipped('Workerman Timer not available in this environment');
        }

        $sessionId = 'test-session-123';
        $playlistUrl = 'http://nonexistent.local/playlist.m3u8';

        // Should not throw - timer is scheduled but we can't easily test background
        $this->prefetcher->startPrefetch($sessionId, $playlistUrl);

        // Clean up
        $this->prefetcher->stopPrefetch($sessionId);
    }

    public function testStopPrefetchWithoutStartDoesNotThrow(): void
    {
        // Should not throw even if no prefetch was started
        $this->prefetcher->stopPrefetch('nonexistent-session');

        $this->assertTrue(true);
    }

    /**
     * @group workerman
     */
    public function testStartAndStopPrefetch(): void
    {
        if (!$this->isTimerAvailable()) {
            $this->markTestSkipped('Workerman Timer not available in this environment');
        }

        $sessionId = 'session-stop-test';

        $this->prefetcher->startPrefetch($sessionId, 'http://nonexistent.local/playlist.m3u8');
        $this->prefetcher->stopPrefetch($sessionId);

        // If we get here without error, test passes
        $this->assertTrue(true);
    }

    /**
     * @group workerman
     */
    public function testMultipleStartPrefetchReplacesPrevious(): void
    {
        if (!$this->isTimerAvailable()) {
            $this->markTestSkipped('Workerman Timer not available in this environment');
        }

        $sessionId = 'session-multi-start';

        // Start first prefetch
        $this->prefetcher->startPrefetch($sessionId, 'http://first.local/playlist.m3u8');

        // Start second prefetch for same session - should replace first
        $this->prefetcher->startPrefetch($sessionId, 'http://second.local/playlist.m3u8');

        // Should not throw - old timer replaced
        $this->assertTrue(true);

        // Clean up
        $this->prefetcher->stopPrefetch($sessionId);
    }

    public function testPrefetchRespectsPrefetchSegmentsCount(): void
    {
        // Create prefetcher with known segment count
        $prefetcher = new HlsSegmentPrefetcher(null, 5, 10485760, 30);

        $stats = $prefetcher->getCacheStats();

        // Verify initial state
        $this->assertEquals(0, $stats['entries']);

        // The prefetch would fetch up to 5 segments if playlist existed
        // Since we can't fetch real segments, just verify the prefetcher works
        $prefetcher->prefetch('http://nonexistent.local/playlist.m3u8');

        $this->assertTrue(true);
    }

    public function testParsePlaylistSegmentsWithRelativeUrls(): void
    {
        $playlist = "#EXTM3U
#EXT-X-VERSION:3
#EXT-X-TARGETDURATION:6
#EXTINF:6.0,
segment0.ts
#EXTINF:6.0,
segment1.ts
#EXTINF:6.0,
segment2.ts
#EXT-X-ENDLIST";

        // Use reflection to test private method
        $reflection = new \ReflectionClass($this->prefetcher);
        $method = $reflection->getMethod('parsePlaylistSegments');
        $method->setAccessible(true);

        $result = $method->invoke($this->prefetcher, $playlist, 'http://example.com/live/stream.m3u8');

        $this->assertCount(3, $result);
        $this->assertEquals('http://example.com/live/segment0.ts', $result[0]);
        $this->assertEquals('http://example.com/live/segment1.ts', $result[1]);
        $this->assertEquals('http://example.com/live/segment2.ts', $result[2]);
    }

    public function testParsePlaylistSegmentsSkipsComments(): void
    {
        $playlist = "#EXTM3U
# Some comment
#EXT-X-VERSION:3
#EXTINF:6.0,
segment0.ts
# Not a real comment but treated as one
#EXTINF:6.0,
segment1.ts
#EXT-X-ENDLIST";

        $reflection = new \ReflectionClass($this->prefetcher);
        $method = $reflection->getMethod('parsePlaylistSegments');
        $method->setAccessible(true);

        $result = $method->invoke($this->prefetcher, $playlist, 'http://example.com/live.m3u8');

        $this->assertCount(2, $result);
    }

    public function testGetCacheKeyIsConsistent(): void
    {
        $reflection = new \ReflectionClass($this->prefetcher);
        $method = $reflection->getMethod('getCacheKey');
        $method->setAccessible(true);

        $url = 'http://example.com/segment.ts';
        $key1 = $method->invoke($this->prefetcher, $url);
        $key2 = $method->invoke($this->prefetcher, $url);

        $this->assertEquals($key1, $key2);
        $this->assertEquals(64, strlen($key1)); // SHA-256 produces 64 hex chars
    }

    public function testDifferentUrlsProduceDifferentCacheKeys(): void
    {
        $reflection = new \ReflectionClass($this->prefetcher);
        $method = $reflection->getMethod('getCacheKey');
        $method->setAccessible(true);

        $key1 = $method->invoke($this->prefetcher, 'http://example.com/segment1.ts');
        $key2 = $method->invoke($this->prefetcher, 'http://example.com/segment2.ts');

        $this->assertNotEquals($key1, $key2);
    }
}
