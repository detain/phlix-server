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

    /**
     * Resolves the served bytes of a response. File-backed responses (segments and
     * playlists now stream via {@see \Phlix\Server\Http\Response::withFile()} rather
     * than buffering into `->body`), so read the window from disk; plain responses
     * (JSON errors) fall back to the buffered body.
     */
    private function bodyOf(\Phlix\Server\Http\Response $res): string
    {
        if ($res->filePath === null) {
            return $res->body;
        }
        $bytes = $res->fileLength > 0
            ? file_get_contents($res->filePath, false, null, $res->fileOffset, $res->fileLength)
            : file_get_contents($res->filePath, false, null, $res->fileOffset);
        return $bytes === false ? '' : $bytes;
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
        $this->assertStringContainsString('#EXTM3U', $this->bodyOf($res));
    }

    public function testServesMediaPlaylistVerbatim(): void
    {
        $playlist = "#EXTM3U\n#EXT-X-MAP:URI=\"init-0.m4s\"\n#EXTINF:2.0,\nchunk-0-00001.m4s\n";
        $this->writeJobFile('job-2', 'media_0.m3u8', $playlist);
        $res = $this->controller()->serveFile(new Request(), ['job_id' => 'job-2', 'file' => 'media_0.m3u8']);

        $this->assertSame(200, $res->statusCode);
        // Served verbatim — relative segment URIs are kept (no rewriting).
        $this->assertSame($playlist, $this->bodyOf($res));
    }

    public function testServesFmp4SegmentWithVideoMp4ContentType(): void
    {
        $this->writeJobFile('job-3', 'chunk-0-00001.m4s', 'SEGMENTBYTES');
        $res = $this->controller()->serveFile(new Request(), ['job_id' => 'job-3', 'file' => 'chunk-0-00001.m4s']);

        $this->assertSame(200, $res->statusCode);
        $this->assertSame('video/mp4', $res->headers['Content-Type']);
        $this->assertSame('public, max-age=31536000', $res->headers['Cache-Control']);
        $this->assertSame('SEGMENTBYTES', $this->bodyOf($res));
    }

    public function testServesInitSegment(): void
    {
        $this->writeJobFile('job-4', 'init-0.m4s', 'INIT');
        $res = $this->controller()->serveFile(new Request(), ['job_id' => 'job-4', 'file' => 'init-0.m4s']);
        $this->assertSame(200, $res->statusCode);
        $this->assertSame('INIT', $this->bodyOf($res));
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
        $this->assertSame('TSBYTES', $this->bodyOf($res));
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
        $this->assertSame('MVBYTES', $this->bodyOf($res));
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
        $this->assertSame('ORIGBYTES', $this->bodyOf($res));
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
        $this->assertSame($playlist, $this->bodyOf($res));
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
        $this->assertSame($playlist, $this->bodyOf($res));
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

    public function testSegmentHonoursByteRangeWith206(): void
    {
        // S3: segments stream via withFile() with real Range support. A single
        // byte-range yields 206 with the requested window (Workerman derives
        // Content-Range from the offset/length passed to withFile()).
        // A static fMP4 segment (immutable, served directly by serveJobFile — no
        // transcoder gate) exercises the file-streaming Range path.
        $this->writeJobFile('job-r', 'chunk-0-00001.m4s', 'ABCDEFGHIJ');
        $req = new Request();
        $req->headers['Range'] = 'bytes=2-5';

        $res = $this->controller()->serveFile($req, ['job_id' => 'job-r', 'file' => 'chunk-0-00001.m4s']);

        $this->assertSame(206, $res->statusCode);
        $this->assertSame('video/mp4', $res->headers['Content-Type']);
        $this->assertNotNull($res->filePath);
        $this->assertSame(2, $res->fileOffset);
        $this->assertSame(4, $res->fileLength);
        $this->assertSame('CDEF', $this->bodyOf($res));
    }

    public function testSegmentOpenEndedRangeReturns206ToEof(): void
    {
        // `bytes=6-` (no end) → from offset 6 to EOF.
        $this->writeJobFile('job-r2', 'chunk-0-00002.m4s', 'ABCDEFGHIJ');
        $req = new Request();
        $req->headers['Range'] = 'bytes=6-';

        $res = $this->controller()->serveFile($req, ['job_id' => 'job-r2', 'file' => 'chunk-0-00002.m4s']);

        $this->assertSame(206, $res->statusCode);
        $this->assertSame(6, $res->fileOffset);
        $this->assertSame(4, $res->fileLength);
        $this->assertSame('GHIJ', $this->bodyOf($res));
    }

    public function testSegmentUnsatisfiableRangeReturns416(): void
    {
        // start-pos itself is past EOF — genuinely unsatisfiable, still 416.
        $this->writeJobFile('job-r3', 'chunk-0-00003.m4s', 'ABCDEFGHIJ');
        $req = new Request();
        $req->headers['Range'] = 'bytes=50-60';

        $res = $this->controller()->serveFile($req, ['job_id' => 'job-r3', 'file' => 'chunk-0-00003.m4s']);

        $this->assertSame(416, $res->statusCode);
        $this->assertSame('bytes */10', $res->headers['Content-Range']);
        $this->assertNull($res->filePath);
    }

    public function testSegmentOverLongRangeEndIsClampedToEofNotRejected(): void
    {
        // Reviewer S3 round-1 finding 1 (MINOR): RFC 7233 §2.1 — a satisfiable
        // start with an over-long last-byte-pos MUST be clamped to EOF and answered
        // 206, not rejected with 416 (a conforming client/cache can send
        // `bytes=0-999999999` against a segment it hasn't measured yet).
        $this->writeJobFile('job-r5', 'chunk-0-00005.m4s', 'ABCDEFGHIJ');
        $req = new Request();
        $req->headers['Range'] = 'bytes=0-999999999';

        $res = $this->controller()->serveFile($req, ['job_id' => 'job-r5', 'file' => 'chunk-0-00005.m4s']);

        $this->assertSame(206, $res->statusCode);
        $this->assertSame(0, $res->fileOffset);
        $this->assertSame(10, $res->fileLength);
        $this->assertSame('ABCDEFGHIJ', $this->bodyOf($res));
    }

    public function testSegmentSuffixRangeReturnsLastNBytes(): void
    {
        // Reviewer S3 round-1 finding 2 (INFO): `bytes=-N` ("last N bytes") is now
        // honoured instead of falling through to a whole-file 200.
        $this->writeJobFile('job-r6', 'chunk-0-00006.m4s', 'ABCDEFGHIJ');
        $req = new Request();
        $req->headers['Range'] = 'bytes=-4';

        $res = $this->controller()->serveFile($req, ['job_id' => 'job-r6', 'file' => 'chunk-0-00006.m4s']);

        $this->assertSame(206, $res->statusCode);
        $this->assertSame(6, $res->fileOffset);
        $this->assertSame(4, $res->fileLength);
        $this->assertSame('GHIJ', $this->bodyOf($res));
    }

    public function testSegmentSuffixRangeLongerThanFileReturnsWholeFile(): void
    {
        // A suffix larger than the file just clamps to byte 0 — the whole file,
        // still satisfiable, still 206 (matches common server behaviour/RFC intent).
        $this->writeJobFile('job-r7', 'chunk-0-00007.m4s', 'ABCDEFGHIJ');
        $req = new Request();
        $req->headers['Range'] = 'bytes=-999';

        $res = $this->controller()->serveFile($req, ['job_id' => 'job-r7', 'file' => 'chunk-0-00007.m4s']);

        $this->assertSame(206, $res->statusCode);
        $this->assertSame(0, $res->fileOffset);
        $this->assertSame(10, $res->fileLength);
        $this->assertSame('ABCDEFGHIJ', $this->bodyOf($res));
    }

    public function testSegmentZeroLengthSuffixRangeReturns416(): void
    {
        // `bytes=-0` is not a valid suffix-length per RFC 7233 §2.1 — unsatisfiable.
        $this->writeJobFile('job-r8', 'chunk-0-00008.m4s', 'ABCDEFGHIJ');
        $req = new Request();
        $req->headers['Range'] = 'bytes=-0';

        $res = $this->controller()->serveFile($req, ['job_id' => 'job-r8', 'file' => 'chunk-0-00008.m4s']);

        $this->assertSame(416, $res->statusCode);
        $this->assertSame('bytes */10', $res->headers['Content-Range']);
        $this->assertNull($res->filePath);
    }

    public function testSegmentMultiRangeFallsBackToFull200(): void
    {
        // We only support a single range; a multi-range value is ignored and the
        // whole file is streamed (a valid RFC 7233 fallback).
        $this->writeJobFile('job-r4', 'chunk-0-00004.m4s', 'ABCDEFGHIJ');
        $req = new Request();
        $req->headers['Range'] = 'bytes=0-2,4-6';

        $res = $this->controller()->serveFile($req, ['job_id' => 'job-r4', 'file' => 'chunk-0-00004.m4s']);

        $this->assertSame(200, $res->statusCode);
        $this->assertSame(0, $res->fileOffset);
        $this->assertSame(0, $res->fileLength);
        $this->assertSame('ABCDEFGHIJ', $this->bodyOf($res));
    }

    public function testSegmentInvertedRangeReturns416(): void
    {
        // An in-file but inverted range (`bytes=5-2`, first-byte-pos > last-byte-pos)
        // is unsatisfiable → 416. This exercises the `$start <= $end` guard in
        // parseRange() with a satisfiable start (`$start < $fileSize` is TRUE), the
        // one satisfiability branch the `bytes=50-60` test can't reach (there the
        // start is itself past EOF so the earlier `$start < $fileSize` short-circuits).
        $this->writeJobFile('job-r9', 'chunk-0-00010.m4s', 'ABCDEFGHIJ');
        $req = new Request();
        $req->headers['Range'] = 'bytes=5-2';

        $res = $this->controller()->serveFile($req, ['job_id' => 'job-r9', 'file' => 'chunk-0-00010.m4s']);

        $this->assertSame(416, $res->statusCode);
        $this->assertSame('bytes */10', $res->headers['Content-Range']);
        $this->assertNull($res->filePath);
    }

    public function testImmutableSegmentReturns304OnMatchingIfModifiedSince(): void
    {
        // Immutable (max-age=31536000) segments answer a conditional GET with 304
        // when If-Modified-Since matches the file mtime — no body re-sent.
        $this->writeJobFile('job-c', 'chunk-0-00009.m4s', 'TSBYTES');
        $path = "{$this->segmentDir}/job-c/chunk-0-00009.m4s";
        $lastModified = gmdate('D, d M Y H:i:s', (int) filemtime($path)) . ' GMT';

        $req = new Request();
        $req->headers['If-Modified-Since'] = $lastModified;

        $res = $this->controller()->serveFile($req, ['job_id' => 'job-c', 'file' => 'chunk-0-00009.m4s']);

        $this->assertSame(304, $res->statusCode);
        $this->assertSame($lastModified, $res->headers['Last-Modified']);
        $this->assertNull($res->filePath);
    }

    public function testNoCachePlaylistIsNeverShortCircuitedTo304(): void
    {
        // Playlists are `no-cache` (they may be rewritten during the encode), so a
        // matching If-Modified-Since must still serve the full body, not 304.
        $this->writeJobFile('job-c2', 'media_0.m3u8', "#EXTM3U\n");
        $path = "{$this->segmentDir}/job-c2/media_0.m3u8";
        $lastModified = gmdate('D, d M Y H:i:s', (int) filemtime($path)) . ' GMT';

        $req = new Request();
        $req->headers['If-Modified-Since'] = $lastModified;

        $res = $this->controller()->serveFile($req, ['job_id' => 'job-c2', 'file' => 'media_0.m3u8']);

        $this->assertSame(200, $res->statusCode);
        $this->assertSame('no-cache', $res->headers['Cache-Control']);
        $this->assertSame("#EXTM3U\n", $this->bodyOf($res));
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
