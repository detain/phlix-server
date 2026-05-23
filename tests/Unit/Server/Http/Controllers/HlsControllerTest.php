<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Streaming\HlsStreamer;
use Phlix\Media\Streaming\QualitySelector;
use Phlix\Server\Http\Controllers\HlsController;
use Phlix\Server\Http\Request;

/**
 * Unit tests for {@see HlsController}.
 *
 * Covers the four handler methods now wired in Application::loadStreamingRoutes():
 *   GET /hls/{job_id}/master.m3u8          -> getMasterPlaylist
 *   GET /hls/{job_id}/{variant_index}/playlist.m3u8 -> getVariantPlaylist
 *   GET /hls/{job_id}/{variant_index}/{segment_number}.ts -> getSegment
 *   GET /hls/{job_id}/playlist              -> getPlaylist
 *
 * Uses createMock() for HlsStreamer dependency following the project's
 * existing controller-test conventions.
 */
class HlsControllerTest extends TestCase
{
    private HlsController $controller;
    private HlsStreamer $mockStreamer;

    protected function setUp(): void
    {
        $this->mockStreamer = $this->createMock(HlsStreamer::class);
        $this->controller = new HlsController($this->mockStreamer);
    }

    /**
     * Happy path: getMasterPlaylist() returns 200 with master playlist content.
     */
    public function testGetMasterPlaylistReturns200WithPlaylist(): void
    {
        $this->mockStreamer->expects($this->once())
            ->method('generateMasterPlaylist')
            ->with('job-123', $this->anything())
            ->willReturn("#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-STREAM-INF:BANDWIDTH=5000000,RESOLUTION=1920x1080,NAME=\"1080p\"\nstream_0.m3u8\n");

        $request = new Request();
        $response = $this->controller->getMasterPlaylist($request, ['job_id' => 'job-123']);

        $this->assertSame(200, $response->statusCode);
        $this->assertSame('application/vnd.apple.mpegurl', $response->headers['Content-Type']);
        $this->assertStringContainsString('#EXTM3U', $response->body);
    }

    /**
     * Negative: getMasterPlaylist() returns 400 when job_id is empty.
     */
    public function testGetMasterPlaylistReturns400WhenJobIdEmpty(): void
    {
        $this->mockStreamer->expects($this->never())->method('generateMasterPlaylist');

        $request = new Request();
        $response = $this->controller->getMasterPlaylist($request, ['job_id' => '']);

        $this->assertSame(400, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('job_id is required', $body['error']);
    }

    /**
     * Happy path: getVariantPlaylist() returns 200 with variant playlist content.
     */
    public function testGetVariantPlaylistReturns200WithPlaylist(): void
    {
        $this->mockStreamer->expects($this->once())
            ->method('generateVariantPlaylist')
            ->with('job-123', 0, $this->anything(), 6)
            ->willReturn("#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:6\n");

        $request = new Request();
        $response = $this->controller->getVariantPlaylist($request, [
            'job_id' => 'job-123',
            'variant_index' => '0',
        ]);

        $this->assertSame(200, $response->statusCode);
        $this->assertSame('application/vnd.apple.mpegurl', $response->headers['Content-Type']);
        $this->assertStringContainsString('#EXTM3U', $response->body);
    }

    /**
     * Happy path: getSegment() returns 200 with segment content when found.
     */
    public function testGetSegmentReturns200WithContent(): void
    {
        $this->mockStreamer->expects($this->once())
            ->method('getSegmentContent')
            ->with('job-123', 0, 5)
            ->willReturn('segment-binary-content');

        $request = new Request();
        $response = $this->controller->getSegment($request, [
            'job_id' => 'job-123',
            'variant_index' => '0',
            'segment_number' => '5',
        ]);

        $this->assertSame(200, $response->statusCode);
        $this->assertSame('video/mp2t', $response->headers['Content-Type']);
        $this->assertSame('segment-binary-content', $response->body);
        $this->assertSame('public, max-age=31536000', $response->headers['Cache-Control']);
        $this->assertSame('bytes', $response->headers['Accept-Ranges']);
    }

    /**
     * Negative: getSegment() returns 404 when segment not found.
     */
    public function testGetSegmentReturns404WhenNotFound(): void
    {
        $this->mockStreamer->expects($this->once())
            ->method('getSegmentContent')
            ->with('job-123', 0, 999)
            ->willReturn(null);

        $request = new Request();
        $response = $this->controller->getSegment($request, [
            'job_id' => 'job-123',
            'variant_index' => '0',
            'segment_number' => '999',
        ]);

        $this->assertSame(404, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('Segment not found', $body['error']);
    }

    /**
     * Happy path: getPlaylist() returns 200 with playlist URL in JSON.
     */
    public function testGetPlaylistReturns200WithPlaylistUrl(): void
    {
        $this->mockStreamer->expects($this->once())
            ->method('getPlaylistUrl')
            ->with('job-123')
            ->willReturn('http://localhost:8096/hls/job-123/playlist.m3u8');

        $request = new Request();
        $response = $this->controller->getPlaylist($request, ['job_id' => 'job-123']);

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertSame('http://localhost:8096/hls/job-123/playlist.m3u8', $body['playlist_url']);
        $this->assertSame('job-123', $body['job_id']);
    }

    /**
     * Negative: getPlaylist() returns 400 when job_id is empty.
     */
    public function testGetPlaylistReturns400WhenJobIdEmpty(): void
    {
        $this->mockStreamer->expects($this->never())->method('getPlaylistUrl');

        $request = new Request();
        $response = $this->controller->getPlaylist($request, ['job_id' => '']);

        $this->assertSame(400, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('job_id is required', $body['error']);
    }
}
