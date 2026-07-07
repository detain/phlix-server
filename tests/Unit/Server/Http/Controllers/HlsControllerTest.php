<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Streaming\HlsStreamer;
use Phlix\Media\Streaming\QualitySelector;
use Phlix\Media\Transcoding\SegmentBusyException;
use Phlix\Media\Transcoding\TranscodeManager;
use Phlix\Server\Http\Controllers\HlsController;
use Phlix\Server\Http\Request;

/**
 * Unit tests for {@see HlsController}.
 *
 * The controller serves the CMAF transcode job directory's files verbatim:
 *   GET /hls/{job_id}/playlist  -> getPlaylist (JSON master url)
 *   GET /hls/{job_id}/{file}    -> serveFile   (master.m3u8 / media_N.m3u8 / *.m4s)
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

    private function controller(?TranscodeManager $manager = null): HlsController
    {
        return new HlsController($this->streamer, $manager);
    }

    private function writeJobFile(string $jobId, string $file, string $content): void
    {
        $dir = "{$this->segmentDir}/{$jobId}";
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents("{$dir}/{$file}", $content);
    }

    public function testGetPlaylistReturnsMasterUrl(): void
    {
        $res = $this->controller()->getPlaylist(new Request(), ['job_id' => 'job-9']);
        $this->assertSame(200, $res->statusCode);
        $body = json_decode($res->body, true);
        $this->assertSame('/hls/job-9/master.m3u8', $body['playlist_url']);
        $this->assertSame('job-9', $body['job_id']);
    }

    public function testGetPlaylistReturns400WhenJobIdEmpty(): void
    {
        $res = $this->controller()->getPlaylist(new Request(), ['job_id' => '']);
        $this->assertSame(400, $res->statusCode);
    }

    public function testServesMasterPlaylistWithHlsContentType(): void
    {
        $this->writeJobFile('job-1', 'master.m3u8', "#EXTM3U\n#EXT-X-VERSION:7\nmedia_0.m3u8\n");
        $res = $this->controller()->serveFile(new Request(), ['job_id' => 'job-1', 'file' => 'master.m3u8']);

        $this->assertSame(200, $res->statusCode);
        $this->assertSame('application/vnd.apple.mpegurl', $res->headers['Content-Type']);
        $this->assertSame('no-cache', $res->headers['Cache-Control']);
        $this->assertStringContainsString('#EXTM3U', $res->body);
    }

    public function testServesMediaPlaylistVerbatim(): void
    {
        $playlist = "#EXTM3U\n#EXT-X-MAP:URI=\"init-0.m4s\"\n#EXTINF:2.0,\nchunk-0-00001.m4s\n";
        $this->writeJobFile('job-2', 'media_0.m3u8', $playlist);
        $res = $this->controller()->serveFile(new Request(), ['job_id' => 'job-2', 'file' => 'media_0.m3u8']);

        $this->assertSame(200, $res->statusCode);
        // Served verbatim — relative segment URIs are kept (no rewriting).
        $this->assertSame($playlist, $res->body);
    }

    public function testServesFmp4SegmentWithVideoMp4ContentType(): void
    {
        $this->writeJobFile('job-3', 'chunk-0-00001.m4s', 'SEGMENTBYTES');
        $res = $this->controller()->serveFile(new Request(), ['job_id' => 'job-3', 'file' => 'chunk-0-00001.m4s']);

        $this->assertSame(200, $res->statusCode);
        $this->assertSame('video/mp4', $res->headers['Content-Type']);
        $this->assertSame('public, max-age=31536000', $res->headers['Cache-Control']);
        $this->assertSame('SEGMENTBYTES', $res->body);
    }

    public function testServesInitSegment(): void
    {
        $this->writeJobFile('job-4', 'init-0.m4s', 'INIT');
        $res = $this->controller()->serveFile(new Request(), ['job_id' => 'job-4', 'file' => 'init-0.m4s']);
        $this->assertSame(200, $res->statusCode);
        $this->assertSame('INIT', $res->body);
    }

    public function testServeFile404WhenMissing(): void
    {
        $res = $this->controller()->serveFile(new Request(), ['job_id' => 'nope', 'file' => 'master.m3u8']);
        $this->assertSame(404, $res->statusCode);
    }

    public function testServesOnDemandSegmentThroughTranscodeManager(): void
    {
        // A seg-NNNNN.ts request is routed through the transcoder, which produces
        // (or serves cached) the segment; the controller then serves its bytes.
        $manager = $this->createMock(TranscodeManager::class);
        $manager->expects($this->once())
            ->method('ensureSegment')
            ->with('job-seg', 5)
            ->willReturnCallback(function (string $jobId, int $index): string {
                $this->writeJobFile($jobId, 'seg-00005.ts', 'TSBYTES');
                return "{$this->segmentDir}/{$jobId}/seg-00005.ts";
            });

        $res = $this->controller($manager)->serveFile(
            new Request(),
            ['job_id' => 'job-seg', 'file' => 'seg-00005.ts']
        );

        $this->assertSame(200, $res->statusCode);
        $this->assertSame('video/mp2t', $res->headers['Content-Type']);
        $this->assertSame('TSBYTES', $res->body);
    }

    public function testOnDemandSegment404WhenTranscoderReturnsNull(): void
    {
        $manager = $this->createMock(TranscodeManager::class);
        $manager->method('ensureSegment')->willReturn(null);

        $res = $this->controller($manager)->serveFile(
            new Request(),
            ['job_id' => 'job-seg', 'file' => 'seg-00005.ts']
        );

        $this->assertSame(404, $res->statusCode);
    }

    public function testOnDemandSegmentReturns503WhenTranscoderBusy(): void
    {
        $manager = $this->createMock(TranscodeManager::class);
        $manager->method('ensureSegment')->willThrowException(new SegmentBusyException('busy'));

        $res = $this->controller($manager)->serveFile(
            new Request(),
            ['job_id' => 'job-seg', 'file' => 'seg-00005.ts']
        );

        $this->assertSame(503, $res->statusCode);
        $this->assertSame('1', $res->headers['Retry-After'] ?? null);
    }

    public function testOnDemandSegment404WhenTranscoderUnavailable(): void
    {
        // Degenerate container-less construction: no transcoder → segments 404
        // (playlists/static files still serve).
        $res = $this->controller(null)->serveFile(
            new Request(),
            ['job_id' => 'job-seg', 'file' => 'seg-00005.ts']
        );

        $this->assertSame(404, $res->statusCode);
    }

    public function testServeFileRejectsPathTraversal(): void
    {
        $this->writeJobFile('job-5', 'master.m3u8', 'x');
        foreach (['../master.m3u8', '..', 'a/b', 'secret.php', ''] as $bad) {
            $res = $this->controller()->serveFile(new Request(), ['job_id' => 'job-5', 'file' => $bad]);
            $this->assertContains($res->statusCode, [400, 404], "filename '{$bad}' must not be served");
            $this->assertNotSame(200, $res->statusCode);
        }
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
