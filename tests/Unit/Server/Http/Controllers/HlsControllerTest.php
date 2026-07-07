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
            // A5: signature is (jobId, variant, index); the legacy unprefixed
            // seg-NNNNN.ts match passes null for the variant (A6 adds variant parsing).
            ->with('job-seg', null, 5)
            ->willReturnCallback(function (string $jobId, ?string $variant, int $index): string {
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

    public function testServesMultiVariantSegmentThroughTranscodeManager(): void
    {
        // A6: a seg-v{V}-NNNNN.ts request parses the rendition id from the URL and
        // routes it to ensureSegment(jobId, '{V}', index) — the multi-variant path.
        $manager = $this->createMock(TranscodeManager::class);
        $manager->expects($this->once())
            ->method('ensureSegment')
            ->with('job-mv', '1080p', 42)
            ->willReturnCallback(function (string $jobId, ?string $variant, int $index): string {
                $this->writeJobFile($jobId, 'seg-v1080p-00042.ts', 'MVBYTES');
                return "{$this->segmentDir}/{$jobId}/seg-v1080p-00042.ts";
            });

        $res = $this->controller($manager)->serveFile(
            new Request(),
            ['job_id' => 'job-mv', 'file' => 'seg-v1080p-00042.ts']
        );

        $this->assertSame(200, $res->statusCode);
        $this->assertSame('video/mp2t', $res->headers['Content-Type']);
        $this->assertSame('MVBYTES', $res->body);
    }

    public function testServesOriginalVariantSegmentThroughTranscodeManager(): void
    {
        // The "original" rung is a valid rendition id (letters only) — it must parse
        // just like the resolution rungs.
        $manager = $this->createMock(TranscodeManager::class);
        $manager->expects($this->once())
            ->method('ensureSegment')
            ->with('job-orig', 'original', 7)
            ->willReturnCallback(function (string $jobId, ?string $variant, int $index): string {
                $this->writeJobFile($jobId, 'seg-voriginal-00007.ts', 'ORIGBYTES');
                return "{$this->segmentDir}/{$jobId}/seg-voriginal-00007.ts";
            });

        $res = $this->controller($manager)->serveFile(
            new Request(),
            ['job_id' => 'job-orig', 'file' => 'seg-voriginal-00007.ts']
        );

        $this->assertSame(200, $res->statusCode);
        $this->assertSame('ORIGBYTES', $res->body);
    }

    public function testMultiVariantSegment404WhenTranscoderReturnsNull(): void
    {
        // Unknown variant / out-of-range index → ensureSegment returns null → 404
        // (self-heals via client retry once the segment materializes).
        $manager = $this->createMock(TranscodeManager::class);
        $manager->expects($this->once())
            ->method('ensureSegment')
            ->with('job-mv', '480p', 3)
            ->willReturn(null);

        $res = $this->controller($manager)->serveFile(
            new Request(),
            ['job_id' => 'job-mv', 'file' => 'seg-v480p-00003.ts']
        );

        $this->assertSame(404, $res->statusCode);
    }

    public function testMultiVariantSegmentReturns503WhenTranscoderBusy(): void
    {
        // Back-pressure (SegmentBusyException → 503 + Retry-After) is intact on the
        // new variant-aware path, not just the legacy one.
        $manager = $this->createMock(TranscodeManager::class);
        $manager->expects($this->once())
            ->method('ensureSegment')
            ->with('job-mv', '720p', 12)
            ->willThrowException(new SegmentBusyException('busy'));

        $res = $this->controller($manager)->serveFile(
            new Request(),
            ['job_id' => 'job-mv', 'file' => 'seg-v720p-00012.ts']
        );

        $this->assertSame(503, $res->statusCode);
        $this->assertSame('1', $res->headers['Retry-After'] ?? null);
    }

    public function testServesPerVariantMediaPlaylistAsStaticFile(): void
    {
        // media_v{V}.m3u8 per-variant playlists are written up front by the pipeline
        // and served verbatim — NO transcoder call, correct HLS content-type.
        $manager = $this->createMock(TranscodeManager::class);
        $manager->expects($this->never())->method('ensureSegment');

        $playlist = "#EXTM3U\n#EXT-X-VERSION:7\n#EXTINF:2.0,\nseg-v1080p-00000.ts\n";
        $this->writeJobFile('job-pl', 'media_v1080p.m3u8', $playlist);

        $res = $this->controller($manager)->serveFile(
            new Request(),
            ['job_id' => 'job-pl', 'file' => 'media_v1080p.m3u8']
        );

        $this->assertSame(200, $res->statusCode);
        $this->assertSame('application/vnd.apple.mpegurl', $res->headers['Content-Type']);
        $this->assertSame('no-cache', $res->headers['Cache-Control']);
        $this->assertSame($playlist, $res->body);
    }

    public function testServesOriginalVariantMediaPlaylistAsStaticFile(): void
    {
        $manager = $this->createMock(TranscodeManager::class);
        $manager->expects($this->never())->method('ensureSegment');

        $playlist = "#EXTM3U\n#EXT-X-VERSION:7\nseg-voriginal-00000.ts\n";
        $this->writeJobFile('job-pl2', 'media_voriginal.m3u8', $playlist);

        $res = $this->controller($manager)->serveFile(
            new Request(),
            ['job_id' => 'job-pl2', 'file' => 'media_voriginal.m3u8']
        );

        $this->assertSame(200, $res->statusCode);
        $this->assertSame('application/vnd.apple.mpegurl', $res->headers['Content-Type']);
        $this->assertSame($playlist, $res->body);
    }

    public function testMalformedVariantSegmentIsNotRoutedToTranscoder(): void
    {
        // A malformed/malicious-looking variant filename must NOT reach ensureSegment.
        // Traversal shapes are caught by the isSafeFilename() gate (400); a name that
        // passes that gate but matches neither seg regex falls through to a static
        // lookup that 404s. Either way: never 200, never a transcoder call.
        $manager = $this->createMock(TranscodeManager::class);
        $manager->expects($this->never())->method('ensureSegment');

        $bad = [
            'seg-v../../etc-00001.ts',   // traversal via variant field → 400 (isSafeFilename)
            'seg-v1080p-00001.ts/../x',  // trailing traversal → 400 (isSafeFilename)
            'seg-v1080p.ts',             // missing index → no regex match → 404 static
            'seg-vABC-00001.ts',         // uppercase variant → new regex rejects → 404 static
            'seg-v-00001.ts',            // empty variant field → no match → 404 static
        ];
        foreach ($bad as $file) {
            $res = $this->controller($manager)->serveFile(
                new Request(),
                ['job_id' => 'job-bad', 'file' => $file]
            );
            $this->assertContains(
                $res->statusCode,
                [400, 404],
                "filename '{$file}' must be rejected, not served"
            );
            $this->assertNotSame(200, $res->statusCode);
        }
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
