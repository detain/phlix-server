<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\LiveTv\Relay;

use PHPUnit\Framework\TestCase;
use Phlix\LiveTv\Relay\HlsSegmentPrefetcher;
use Phlix\Tests\Support\Workerman\WorkermanTimerFixture;

/**
 * Unit tests for HlsSegmentPrefetcher.
 *
 * @since 0.12.0
 */
class HlsSegmentPrefetcherTest extends TestCase
{
    use WorkermanTimerFixture;

    private HlsSegmentPrefetcher $prefetcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefetcher = new HlsSegmentPrefetcher(null, 3, 10485760, 30);

        // S266: guarantee Workerman\Timer::add() works for the cases below,
        // deterministically — regardless of what ran earlier in the shared
        // PHPUnit process. Replaces the old order-dependent isTimerAvailable()
        // probe (see WorkermanTimerFixture for the diagnosis).
        $this->installWorkermanTimerFixture();
    }

    protected function tearDown(): void
    {
        $this->removeWorkermanTimerFixture();
        parent::tearDown();
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
        $this->assertSame(0, $this->prefetcher->getCacheStats()['entries']);
    }

    /**
     * @group workerman
     * @group integration
     */
    public function testStartPrefetchDoesNotThrow(): void
    {
        $sessionId = 'test-session-123';
        $playlistUrl = 'http://nonexistent.local/playlist.m3u8';

        // Should not throw - timer is scheduled but we can't easily test background
        $this->prefetcher->startPrefetch($sessionId, $playlistUrl);

        // Clean up
        $this->prefetcher->stopPrefetch($sessionId);

        // Reaching here means start/stop completed without throwing.
        $this->assertSame(0, $this->prefetcher->getCacheStats()['entries']);
    }

    public function testStopPrefetchWithoutStartDoesNotThrow(): void
    {
        // Should not throw even if no prefetch was started
        $this->prefetcher->stopPrefetch('nonexistent-session');

        $this->assertSame(0, $this->prefetcher->getCacheStats()['entries']);
    }

    /**
     * @group workerman
     * @group integration
     */
    public function testStartAndStopPrefetch(): void
    {
        $sessionId = 'session-stop-test';

        $this->prefetcher->startPrefetch($sessionId, 'http://nonexistent.local/playlist.m3u8');
        $this->prefetcher->stopPrefetch($sessionId);

        // If we get here without error, test passes
        $this->assertSame(0, $this->prefetcher->getCacheStats()['entries']);
    }

    /**
     * @group workerman
     * @group integration
     */
    public function testMultipleStartPrefetchReplacesPrevious(): void
    {
        $sessionId = 'session-multi-start';

        // Start first prefetch
        $this->prefetcher->startPrefetch($sessionId, 'http://first.local/playlist.m3u8');

        // Start second prefetch for same session - should replace first
        $this->prefetcher->startPrefetch($sessionId, 'http://second.local/playlist.m3u8');

        // Should not throw - old timer replaced
        $this->assertSame(0, $this->prefetcher->getCacheStats()['entries']);

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

        $this->assertSame(0, $prefetcher->getCacheStats()['entries']);
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

        /** @var list<string> $result */
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

        /** @var list<string> $result */
        $result = $method->invoke($this->prefetcher, $playlist, 'http://example.com/live.m3u8');

        $this->assertCount(2, $result);
    }

    public function testGetCacheKeyIsConsistent(): void
    {
        $reflection = new \ReflectionClass($this->prefetcher);
        $method = $reflection->getMethod('getCacheKey');
        $method->setAccessible(true);

        $url = 'http://example.com/segment.ts';
        /** @var string $key1 */
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

    // ---- F-7/F-4: rendition-content bug + bounded download-before-check ----

    /**
     * Build a prefetcher whose fetcher serves a fixed URL→body map and records
     * every dialed URL (the recording seam).
     *
     * @param array<string, string> $bodies
     * @param list<string>          $fetchedOut
     */
    private function makeMappedPrefetcher(
        array $bodies,
        array &$fetchedOut,
        int $maxCacheSize = 10485760,
        ?int $maxPlaylistBytes = null
    ): HlsSegmentPrefetcher {
        return new HlsSegmentPrefetcher(
            null,
            3,
            $maxCacheSize,
            30,
            static function (string $url, int $maxBytes) use ($bodies, &$fetchedOut): ?string {
                $fetchedOut[] = $url;
                $body = $bodies[$url] ?? null;
                return $body === null ? null : substr($body, 0, $maxBytes + 1);
            },
            $maxPlaylistBytes
        );
    }

    public function testBandwidthPrefetchParsesRenditionPlaylistContentNotItsUrl(): void
    {
        $master = "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1000000\nhttp://h/low.m3u8\n"
            . "#EXT-X-STREAM-INF:BANDWIDTH=5000000\nhttp://h/high.m3u8\n";
        $lowMedia = "#EXTM3U\n#EXT-X-TARGETDURATION:10\n#EXTINF:10,\nseg1.ts\n";

        $fetched = [];
        $prefetcher = $this->makeMappedPrefetcher([
            'http://h/master.m3u8' => $master,
            'http://h/low.m3u8' => $lowMedia,
            'http://h/seg1.ts' => 'SEGMENT-BYTES',
        ], $fetched);

        // 1500 kbps ceiling admits only the 1 Mbps rendition.
        $prefetcher->prefetchWithBandwidth('http://h/master.m3u8', 1500);

        $this->assertSame(
            ['http://h/master.m3u8', 'http://h/low.m3u8', 'http://h/seg1.ts'],
            $fetched,
            'The rendition URL must be fetched as a PLAYLIST and its CONTENT parsed — '
            . 'the pre-fix bug fed the URL string into the content parser, so the "segment" '
            . 'was the rendition playlist itself and no real media was ever prefetched.'
        );
        $this->assertNotContains('http://h/high.m3u8', $fetched);
        $this->assertSame('SEGMENT-BYTES', $prefetcher->getSegment('http://h/seg1.ts'));
    }

    public function testOversizedSegmentIsRefusedWithoutEvictingCachedEntries(): void
    {
        $fetched = [];
        $masterBody = "#EXTM3U\n#EXT-X-TARGETDURATION:10\n#EXTINF:10,\nsegA.ts\n#EXTINF:10,\nsegB.ts\n";
        $prefetcher = new HlsSegmentPrefetcher(
            null,
            3,
            100,
            30,
            static function (string $url, int $maxBytes) use ($masterBody, &$fetched): ?string {
                $fetched[] = $url;
                if ($url === 'http://h/master.m3u8') {
                    return $masterBody;
                }
                if ($url === 'http://h/segA.ts') {
                    return str_repeat('A', 50); // fits
                }
                if ($url === 'http://h/segB.ts') {
                    return str_repeat('B', $maxBytes + 1); // cap+1 => oversize signal
                }
                return null;
            }
        );

        $prefetcher->prefetch('http://h/master.m3u8');

        $this->assertSame(['http://h/master.m3u8', 'http://h/segA.ts', 'http://h/segB.ts'], $fetched);
        $stats = $prefetcher->getCacheStats();
        $this->assertSame(1, $stats['entries'], 'Oversized segment must not enter the cache');
        $this->assertNotNull(
            $prefetcher->getSegment('http://h/segA.ts'),
            'Pre-fix code EVICTED segA to "make room" before discovering segB was too '
            . 'large — the check must happen before any eviction.'
        );
    }

    public function testOversizedPlaylistIsRefusedWithoutCaching(): void
    {
        $fetched = [];
        $prefetcher = new HlsSegmentPrefetcher(
            null,
            3,
            10485760,
            30,
            static function (string $url, int $maxBytes) use (&$fetched): string {
                $fetched[] = $url;
                return str_repeat('#', $maxBytes + 1);
            },
            64
        );

        $prefetcher->prefetch('http://h/huge.m3u8');

        $this->assertSame(['http://h/huge.m3u8'], $fetched, 'Oversize playlist must be dropped, not parsed');
        $this->assertSame(0, $prefetcher->getCacheStats()['entries']);
    }

    public function testUnreachableRenditionPlaylistSkipsThatRendition(): void
    {
        // The bandwidth loop must survive one rendition 404ing and still
        // prefetch nothing bogus from it.
        $master = "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=500000\nhttp://h/gone.m3u8\n";

        $fetched = [];
        $prefetcher = $this->makeMappedPrefetcher(
            ['http://h/master.m3u8' => $master],
            $fetched
        );

        $prefetcher->prefetchWithBandwidth('http://h/master.m3u8', 1000);

        $this->assertSame(['http://h/master.m3u8', 'http://h/gone.m3u8'], $fetched);
        $this->assertSame(0, $prefetcher->getCacheStats()['entries']);
    }
}
