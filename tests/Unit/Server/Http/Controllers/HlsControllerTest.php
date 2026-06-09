<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Streaming\HlsStreamer;
use Phlix\Media\Streaming\QualitySelector;
use Phlix\Media\Transcoding\TranscodeManager;
use Phlix\Server\Http\Controllers\HlsController;
use Phlix\Server\Http\Request;

/**
 * Unit tests for {@see HlsController}.
 *
 * The controller now serves REAL on-disk ffmpeg output rather than placeholder
 * manifests: the master playlist is built from the job's variant descriptor,
 * the variant playlist is read from the FFmpeg-produced stream_N.m3u8 (with
 * segment URIs rewritten to the canonical route form), and segments are streamed
 * from disk. A real HlsStreamer over a temp dir backs the file reads.
 *
 *   GET /hls/{job_id}/master.m3u8                          -> getMasterPlaylist
 *   GET /hls/{job_id}/{variant_index}/playlist.m3u8        -> getVariantPlaylist
 *   GET /hls/{job_id}/{variant_index}/{segment_number}.ts  -> getSegment
 *   GET /hls/{job_id}/playlist                             -> getPlaylist
 */
class HlsControllerTest extends TestCase
{
    private string $segmentDir;
    private HlsStreamer $streamer;

    protected function setUp(): void
    {
        $this->segmentDir = sys_get_temp_dir() . '/phlix_hlsctl_' . uniqid();
        mkdir($this->segmentDir, 0755, true);
        $this->streamer = new HlsStreamer($this->segmentDir, 'http://localhost:8096', new QualitySelector());
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->segmentDir);
    }

    private function request(): Request
    {
        return new Request();
    }

    public function testMasterPlaylistUsesJobVariantDescriptor(): void
    {
        $manager = $this->createMock(TranscodeManager::class);
        $manager->method('getJobVariant')->willReturn([
            'width' => 1280,
            'height' => 720,
            'bandwidth' => 2800000,
            'status' => 'running',
        ]);
        $controller = new HlsController($this->streamer, $manager);

        $response = $controller->getMasterPlaylist($this->request(), ['job_id' => 'job-1']);

        $this->assertSame(200, $response->statusCode);
        $this->assertSame('application/vnd.apple.mpegurl', $response->headers['Content-Type']);
        $this->assertStringContainsString('#EXTM3U', $response->body);
        $this->assertStringContainsString('RESOLUTION=1280x720', $response->body);
        $this->assertStringContainsString('BANDWIDTH=2800000', $response->body);
        $this->assertStringContainsString('0/playlist.m3u8', $response->body);
    }

    public function testMasterPlaylistFallsBackWithoutManager(): void
    {
        $controller = new HlsController($this->streamer);
        $response = $controller->getMasterPlaylist($this->request(), ['job_id' => 'job-1']);
        $this->assertSame(200, $response->statusCode);
        $this->assertStringContainsString('RESOLUTION=1920x1080', $response->body);
    }

    public function testMasterPlaylistReturns400WhenJobIdEmpty(): void
    {
        $controller = new HlsController($this->streamer);
        $response = $controller->getMasterPlaylist($this->request(), ['job_id' => '']);
        $this->assertSame(400, $response->statusCode);
        $this->assertSame('job_id is required', json_decode($response->body, true)['error']);
    }

    public function testVariantPlaylistReadsAndRewritesSegmentUris(): void
    {
        $dir = "{$this->segmentDir}/job-2";
        mkdir($dir, 0755, true);
        file_put_contents(
            "{$dir}/stream_0.m3u8",
            "#EXTM3U\n#EXT-X-VERSION:6\n#EXTINF:2.0,\nsegment_0_000.ts\n"
            . "#EXTINF:2.0,\nsegment_0_001.ts\n#EXT-X-ENDLIST\n"
        );
        $controller = new HlsController($this->streamer);

        $response = $controller->getVariantPlaylist(
            $this->request(),
            ['job_id' => 'job-2', 'variant_index' => '0']
        );

        $this->assertSame(200, $response->statusCode);
        $this->assertSame('application/vnd.apple.mpegurl', $response->headers['Content-Type']);
        $this->assertStringContainsString("\n0.ts\n", $response->body);
        $this->assertStringContainsString("\n1.ts\n", $response->body);
        $this->assertStringNotContainsString('segment_0_000.ts', $response->body);
        $this->assertStringContainsString('#EXT-X-ENDLIST', $response->body);
    }

    public function testVariantPlaylistReturns404WhenNotReady(): void
    {
        $controller = new HlsController($this->streamer);
        $response = $controller->getVariantPlaylist(
            $this->request(),
            ['job_id' => 'nope', 'variant_index' => '0']
        );
        $this->assertSame(404, $response->statusCode);
    }

    public function testGetSegmentReturns200WithContent(): void
    {
        $dir = "{$this->segmentDir}/job-3";
        mkdir($dir, 0755, true);
        file_put_contents("{$dir}/segment_0_000.ts", 'segment-binary-content');
        $controller = new HlsController($this->streamer);

        $response = $controller->getSegment($this->request(), [
            'job_id' => 'job-3',
            'variant_index' => '0',
            'segment_number' => '0',
        ]);

        $this->assertSame(200, $response->statusCode);
        $this->assertSame('video/mp2t', $response->headers['Content-Type']);
        $this->assertSame('segment-binary-content', $response->body);
        $this->assertSame('public, max-age=31536000', $response->headers['Cache-Control']);
        $this->assertSame('bytes', $response->headers['Accept-Ranges']);
    }

    public function testGetSegmentReturns404WhenNotFound(): void
    {
        $controller = new HlsController($this->streamer);
        $response = $controller->getSegment($this->request(), [
            'job_id' => 'job-x',
            'variant_index' => '0',
            'segment_number' => '999',
        ]);
        $this->assertSame(404, $response->statusCode);
        $this->assertSame('Segment not found', json_decode($response->body, true)['error']);
    }

    public function testGetPlaylistReturnsMasterUrl(): void
    {
        $controller = new HlsController($this->streamer);
        $response = $controller->getPlaylist($this->request(), ['job_id' => 'job-9']);
        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('/hls/job-9/master.m3u8', $body['playlist_url']);
        $this->assertSame('job-9', $body['job_id']);
    }

    public function testGetPlaylistReturns400WhenJobIdEmpty(): void
    {
        $controller = new HlsController($this->streamer);
        $response = $controller->getPlaylist($this->request(), ['job_id' => '']);
        $this->assertSame(400, $response->statusCode);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $p = "{$dir}/{$e}";
            is_dir($p) ? $this->rrmdir($p) : unlink($p);
        }
        rmdir($dir);
    }
}
