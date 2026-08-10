<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Streaming\HlsStreamer;
use Phlix\Media\Streaming\QualitySelector;
use Phlix\Media\Transcoding\SegmentBusyException;
use Phlix\Media\Transcoding\SegmentCacheFullException;
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
        /** @var array<array-key, mixed> $body */
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

    public function testFoldedOriginalPlaylistIsNoLongerAliasedToTopRung(): void
    {
        // S98 REMOVAL PIN. This is the exact fixture the deleted pre-v9 alias
        // existed for: a folded job dir whose master lists real rungs that ARE on
        // disk, but with NO media_voriginal.m3u8. The alias used to answer 200 with
        // the highest-BANDWIDTH rung's bytes; it is gone, so this is now a plain
        // 404 like any other absent rung.
        //
        // This test is the red-on-revert guard for the removal — restoring the
        // alias turns the 404 back into a 200 carrying $top.
        $manager = $this->createMock(TranscodeManager::class);
        $manager->expects($this->never())->method('ensureSegment');

        $master = "#EXTM3U\n#EXT-X-VERSION:3\n"
            . "#EXT-X-STREAM-INF:BANDWIDTH=5000000,RESOLUTION=1920x1080,CODECS=\"avc1.640029,mp4a.40.2\"\n"
            . "media_v1080p.m3u8\n"
            . "#EXT-X-STREAM-INF:BANDWIDTH=2800000,RESOLUTION=1280x720,CODECS=\"avc1.64001F,mp4a.40.2\"\n"
            . "media_v720p.m3u8\n";
        $this->writeJobFile('job-fold', 'master.m3u8', $master);
        $top = "#EXTM3U\n#EXT-X-VERSION:3\n#EXTINF:2.0,\nseg-v1080p-00000.ts\n";
        $this->writeJobFile('job-fold', 'media_v1080p.m3u8', $top);
        $this->writeJobFile('job-fold', 'media_v720p.m3u8', "#EXTM3U\nseg-v720p-00000.ts\n");
        // media_voriginal.m3u8 intentionally NOT written (the pre-v9 folded shape).

        $res = $this->controller($manager)->serveFile(
            new Request(),
            ['job_id' => 'job-fold', 'file' => 'media_voriginal.m3u8']
        );

        $this->assertSame(404, $res->statusCode);
        // And specifically NOT the top rung's bytes served under an alias.
        $this->assertNotSame($top, $this->bodyOf($res));
    }

    public function testMissingOriginalPlaylistIsRegeneratedRatherThanAliased(): void
    {
        // S98 REPLACEMENT PATH. Regeneration — not aliasing — is what now covers a
        // job whose media_voriginal.m3u8 is absent (e.g. an LRU-swept dir). The
        // ensurePlaylistRegenerated() call runs BEFORE the (now deleted) alias site
        // and writes a REAL Original playlist, which is then served verbatim.
        $manager = $this->createMock(TranscodeManager::class);
        $manager->expects($this->never())->method('ensureSegment');

        $regenerated = "#EXTM3U\n#EXT-X-VERSION:7\n#EXTINF:2.0,\nseg-voriginal-00000.ts\n";
        $manager->expects($this->once())
            ->method('ensurePlaylistRegenerated')
            ->with('job-regen')
            ->willReturnCallback(function (string $jobId) use ($regenerated): bool {
                $this->writeJobFile($jobId, 'media_voriginal.m3u8', $regenerated);
                return true;
            });

        // Dir exists but the Original playlist does not — the swept-then-replayed
        // signed-URL case.
        $this->writeJobFile('job-regen', 'master.m3u8', "#EXTM3U\n#EXT-X-VERSION:7\n");

        $res = $this->controller($manager)->serveFile(
            new Request(),
            ['job_id' => 'job-regen', 'file' => 'media_voriginal.m3u8']
        );

        $this->assertSame(200, $res->statusCode);
        $this->assertSame('application/vnd.apple.mpegurl', $res->headers['Content-Type']);
        $this->assertSame($regenerated, $this->bodyOf($res));
    }

    public function testOriginalPlaylistServedVerbatimWhenPresent(): void
    {
        // The normal case for every v9+ job (S49: the Original is always written):
        // media_voriginal.m3u8 is served verbatim. Note the master here deliberately
        // DOES list the original — a pre-v9 copy-original job shape — to prove the
        // bytes come from the file on disk, not from anything the master says.
        $master = "#EXTM3U\n#EXT-X-VERSION:3\n"
            . "#EXT-X-STREAM-INF:BANDWIDTH=8000000,RESOLUTION=1920x1080,CODECS=\"avc1.640029,mp4a.40.2\"\n"
            . "media_voriginal.m3u8\n"
            . "#EXT-X-STREAM-INF:BANDWIDTH=5000000,RESOLUTION=1920x1080,CODECS=\"avc1.640029,mp4a.40.2\"\n"
            . "media_v1080p.m3u8\n";
        $this->writeJobFile('job-orig-pl', 'master.m3u8', $master);
        $orig = "#EXTM3U\n#EXT-X-VERSION:3\n#EXTINF:2.0,\nseg-voriginal-00000.ts\n";
        $this->writeJobFile('job-orig-pl', 'media_voriginal.m3u8', $orig);
        $this->writeJobFile('job-orig-pl', 'media_v1080p.m3u8', "#EXTM3U\nseg-v1080p-00000.ts\n");

        $res = $this->controller()->serveFile(
            new Request(),
            ['job_id' => 'job-orig-pl', 'file' => 'media_voriginal.m3u8']
        );

        $this->assertSame(200, $res->statusCode);
        $this->assertSame($orig, $this->bodyOf($res));
    }

    public function testUnknownVariantPlaylistStill404s(): void
    {
        // The fallback is ONLY for the `original` alias — a genuinely missing,
        // arbitrary rung must still 404 (never masked by the top-rung alias).
        $master = "#EXTM3U\n#EXT-X-VERSION:3\n"
            . "#EXT-X-STREAM-INF:BANDWIDTH=5000000,RESOLUTION=1920x1080,CODECS=\"avc1.640029,mp4a.40.2\"\n"
            . "media_v1080p.m3u8\n";
        $this->writeJobFile('job-unk', 'master.m3u8', $master);
        $this->writeJobFile('job-unk', 'media_v1080p.m3u8', "#EXTM3U\nseg-v1080p-00000.ts\n");

        $res = $this->controller()->serveFile(
            new Request(),
            ['job_id' => 'job-unk', 'file' => 'media_v2160p.m3u8']
        );

        $this->assertSame(404, $res->statusCode);
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

    public function testOnDemandSegmentReturns503AndSweepsWhenCacheFull(): void
    {
        // SV-1.9: the ENOSPC guard throws SegmentCacheFullException from
        // ensureSegment when the segment-cache filesystem is low on space. The
        // controller must trigger an opportunistic sweep AND return 503 with a
        // Retry-After of '3' — distinct from the SegmentBusy path's '1' so the
        // client backs off longer while the sweep reclaims space.
        $manager = $this->createMock(TranscodeManager::class);
        $manager->expects($this->once())
            ->method('ensureSegment')
            ->willThrowException(new SegmentCacheFullException('cache full'));
        $manager->expects($this->once())->method('sweepSegmentCache')->willReturn(0);

        $res = $this->controller($manager)->serveFile(
            new Request(),
            ['job_id' => 'job-seg', 'file' => 'seg-00005.ts']
        );

        $this->assertSame(503, $res->statusCode);
        $this->assertSame('3', $res->headers['Retry-After'] ?? null);
    }

    public function testMultiVariantSegmentReturns503AndSweepsWhenCacheFull(): void
    {
        // SV-1.9: the ENOSPC guard is identical on the variant-aware path — the
        // same catch handles seg-v{V}-NNNNN.ts, sweeping + 503 + Retry-After '3'.
        $manager = $this->createMock(TranscodeManager::class);
        $manager->expects($this->once())
            ->method('ensureSegment')
            ->with('job-mv', '720p', 12)
            ->willThrowException(new SegmentCacheFullException('cache full'));
        $manager->expects($this->once())->method('sweepSegmentCache')->willReturn(0);

        $res = $this->controller($manager)->serveFile(
            new Request(),
            ['job_id' => 'job-mv', 'file' => 'seg-v720p-00012.ts']
        );

        $this->assertSame(503, $res->statusCode);
        $this->assertSame('3', $res->headers['Retry-After'] ?? null);
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

    // ─────────────────────────────────────────────────────────────────
    // S310 — the fMP4 serve path
    // ─────────────────────────────────────────────────────────────────

    /**
     * Every filename an fMP4 HLS playlist can reference, and the exact
     * `ensureSegment($jobId, $variant, $index, $audioId)` call it must make.
     *
     * This is the join between S57's writer and this controller: the playlist
     * writer emits an `#EXT-X-MAP` init plus these segment names, and until S310
     * nothing on the serve side parsed a single one of them back into the
     * arguments the producer needs. An init maps to index 0 of its OWN rendition
     * because producing segment 0 is what publishes the init file.
     *
     * @return array<string, array{0:string, 1:string|null, 2:string|null, 3:int}>
     */
    public static function fmp4Shapes(): array
    {
        return [
            'video init'      => ['init-v720p.m4s', '720p', null, 0],
            'video segment'   => ['seg-v1080p-00042.m4s', '1080p', null, 42],
            'video segment 0' => ['seg-v240p-00000.m4s', '240p', null, 0],
            'original rung'   => ['seg-voriginal-00007.m4s', 'original', null, 7],
            'audio init'      => ['init-a0.m4s', null, 'a0', 0],
            'audio segment'   => ['seg-a1-00003.m4s', null, 'a1', 3],
            'legacy init'     => ['init.m4s', null, null, 0],
            'legacy segment'  => ['seg-00012.m4s', null, null, 12],
        ];
    }

    /**
     * @dataProvider fmp4Shapes
     */
    public function testEveryFmp4PlaylistReferenceShapeTriggersItsOwnEncode(
        string $file,
        ?string $variant,
        ?string $audioId,
        int $index
    ): void {
        $manager = $this->createMock(TranscodeManager::class);
        $manager->expects($this->once())
            ->method('ensureSegment')
            ->with('job-fmp4', $variant, $index, $audioId)
            ->willReturnCallback(function () use ($file): string {
                // Produce the file the way the real encode does, so the static
                // serve below has something to hand back.
                $this->writeJobFile('job-fmp4', $file, 'FMP4BYTES');
                return "{$this->segmentDir}/job-fmp4/{$file}";
            });

        $res = $this->controller($manager)->serveFile(
            new Request(),
            ['job_id' => 'job-fmp4', 'file' => $file]
        );

        $this->assertSame(200, $res->statusCode);
        $this->assertSame('video/mp4', $res->headers['Content-Type']);
        $this->assertSame('public, max-age=31536000', $res->headers['Cache-Control']);
        $this->assertSame('FMP4BYTES', $this->bodyOf($res));
    }

    /**
     * ⚠ The single most important case in this file.
     *
     * hls.js resolves `#EXT-X-MAP:URI="init-v{V}.m4s"` BEFORE it fetches any
     * media segment, and the init has no producer of its own — it is published
     * beside segment 0. So if the init arm is missing, the very first
     * byte-bearing request of an fMP4 stream 404s, no encode is ever triggered,
     * and the presentation is unreachable from its own opening request no matter
     * what the segment arms do. The file is deliberately ABSENT beforehand:
     * without that assertion a 200 could mean "it was already on disk".
     */
    public function testTheExtXMapInitIsProducedOnDemandRatherThanAssumedPresent(): void
    {
        $this->assertFileDoesNotExist("{$this->segmentDir}/job-map/init-v240p.m4s");

        $manager = $this->createMock(TranscodeManager::class);
        $manager->expects($this->once())
            ->method('ensureSegment')
            ->with('job-map', '240p', 0, null)
            ->willReturnCallback(function (): string {
                $this->writeJobFile('job-map', 'init-v240p.m4s', 'INITBYTES');
                return "{$this->segmentDir}/job-map/init-v240p.m4s";
            });

        $res = $this->controller($manager)->serveFile(
            new Request(),
            ['job_id' => 'job-map', 'file' => 'init-v240p.m4s']
        );

        $this->assertSame(200, $res->statusCode);
        $this->assertSame('INITBYTES', $this->bodyOf($res));
        $this->assertFileExists("{$this->segmentDir}/job-map/init-v240p.m4s");
    }

    /**
     * An fMP4 miss the producer cannot satisfy is a 404 DECISION, not a silent
     * fall-through to the static lookup that would have answered before S310.
     */
    public function testAnUnproducibleFmp4SegmentIs404(): void
    {
        $manager = $this->createMock(TranscodeManager::class);
        $manager->expects($this->once())
            ->method('ensureSegment')
            ->with('job-fmp4', '4321p', 0, null)
            ->willReturn(null);

        $res = $this->controller($manager)->serveFile(
            new Request(),
            ['job_id' => 'job-fmp4', 'file' => 'seg-v4321p-00000.m4s']
        );

        $this->assertSame(404, $res->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($res->body, true);
        $this->assertSame('segment unavailable', $body['error']);
    }

    /**
     * The pre-S310 behaviour, pinned as the thing that must NOT come back: with
     * no transcoder the `.m4s` request must 404, not quietly serve whatever
     * happens to be on disk. The file is deliberately PRESENT so a regression to
     * "fall through to the static lookup" shows up as a 200 here.
     */
    public function testWithoutATranscoderAnFmp4RequestIs404NotASilentStaticServe(): void
    {
        $this->writeJobFile('job-nom', 'seg-v1080p-00000.m4s', 'STALEBYTES');

        $res = $this->controller(null)->serveFile(
            new Request(),
            ['job_id' => 'job-nom', 'file' => 'seg-v1080p-00000.m4s']
        );

        $this->assertSame(404, $res->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($res->body, true);
        $this->assertSame('segment unavailable', $body['error']);
    }

    /**
     * The 503 contract carries across to the fMP4 arms unchanged. Omitting it
     * would re-open the seek cascade on fMP4 jobs: a hard 404 is fatal to
     * hls.js, a 503 is retried.
     */
    public function testAnFmp4SegmentReturns503WithRetryAfterWhenBusy(): void
    {
        $manager = $this->createMock(TranscodeManager::class);
        $manager->expects($this->once())
            ->method('ensureSegment')
            ->with('job-fmp4', '720p', 12, null)
            ->willThrowException(new SegmentBusyException('busy'));
        $manager->expects($this->never())->method('sweepSegmentCache');

        $res = $this->controller($manager)->serveFile(
            new Request(),
            ['job_id' => 'job-fmp4', 'file' => 'seg-v720p-00012.m4s']
        );

        $this->assertSame(503, $res->statusCode);
        $this->assertSame('1', $res->headers['Retry-After'] ?? null);
        /** @var array<string, mixed> $body */
        $body = json_decode($res->body, true);
        $this->assertSame('segment busy', $body['error']);
    }

    /**
     * Cache-full sweeps THEN asks for the longer retry, on an fMP4 INIT — the
     * request a client makes first, and therefore the one most likely to meet a
     * full cache on a cold job. The sweep is asserted as a call, not inferred
     * from the status code.
     */
    public function testAnFmp4InitReturns503AndSweepsWhenCacheFull(): void
    {
        $manager = $this->createMock(TranscodeManager::class);
        $manager->expects($this->once())
            ->method('ensureSegment')
            ->with('job-fmp4', null, 0, 'a0')
            ->willThrowException(new SegmentCacheFullException('cache full'));
        $manager->expects($this->once())->method('sweepSegmentCache')->willReturn(0);

        $res = $this->controller($manager)->serveFile(
            new Request(),
            ['job_id' => 'job-fmp4', 'file' => 'init-a0.m4s']
        );

        $this->assertSame(503, $res->statusCode);
        $this->assertSame('3', $res->headers['Retry-After'] ?? null);
        /** @var array<string, mixed> $body */
        $body = json_decode($res->body, true);
        $this->assertSame('segment cache full', $body['error']);
    }

    /**
     * The control for the whole S310 widening: names that are NOT on-demand
     * artefacts must still be served statically, with no transcoder call. The
     * legacy `chunk-*.m4s` and `init-0.m4s` shapes are real files this server
     * has always served verbatim, and `init-0.m4s` sits one character from the
     * new `init-a{A}.m4s` arm — a router that took the whole token as the audio
     * group would 404 a file that exists.
     */
    public function testStaticM4sNamesAreStillServedWithoutTouchingTheTranscoder(): void
    {
        $manager = $this->createMock(TranscodeManager::class);
        $manager->expects($this->never())->method('ensureSegment');

        $static = [
            'chunk-0-00001.m4s' => 'CHUNKBYTES',
            'init-0.m4s' => 'LEGACYINIT',
            'media_v1080p.m3u8' => "#EXTM3U\n",
        ];
        foreach ($static as $file => $bytes) {
            $this->writeJobFile('job-static', $file, $bytes);
            $res = $this->controller($manager)->serveFile(
                new Request(),
                ['job_id' => 'job-static', 'file' => $file]
            );
            $this->assertSame(200, $res->statusCode, "{$file} must still serve statically");
            $this->assertSame($bytes, $this->bodyOf($res), "{$file} bytes");
        }
    }

    /**
     * Range/conditional-GET semantics are unchanged for a PRODUCED fMP4 segment.
     *
     * The pre-S310 Range cases above all use `chunk-*.m4s`, which is a STATIC
     * name that never touches the transcoder — so none of them can tell you the
     * serve path still works once a request has gone through the producer
     * first. This one does: the trigger fires, and the response is still a real
     * 206 window over the produced file.
     */
    public function testAProducedFmp4SegmentStillHonoursByteRangeWith206(): void
    {
        $manager = $this->createMock(TranscodeManager::class);
        $manager->expects($this->once())
            ->method('ensureSegment')
            ->with('job-rng', '240p', 1, null)
            ->willReturnCallback(function (): string {
                $this->writeJobFile('job-rng', 'seg-v240p-00001.m4s', 'ABCDEFGHIJ');
                return "{$this->segmentDir}/job-rng/seg-v240p-00001.m4s";
            });

        $req = new Request();
        $req->headers['Range'] = 'bytes=2-5';

        $res = $this->controller($manager)->serveFile(
            $req,
            ['job_id' => 'job-rng', 'file' => 'seg-v240p-00001.m4s']
        );

        $this->assertSame(206, $res->statusCode);
        $this->assertSame('video/mp4', $res->headers['Content-Type']);
        $this->assertSame(2, $res->fileOffset);
        $this->assertSame(4, $res->fileLength);
        $this->assertSame('CDEF', $this->bodyOf($res));
    }

    /**
     * Malformed fMP4 names get the same treatment their `.ts` peers do: never a
     * 200, never a transcoder call.
     */
    public function testMalformedFmp4NamesAreNotRoutedToTheTranscoder(): void
    {
        $manager = $this->createMock(TranscodeManager::class);
        $manager->expects($this->never())->method('ensureSegment');

        $bad = [
            'seg-v../../etc-00001.m4s',  // traversal via the variant field → 400
            'init-v../../etc.m4s',       // traversal via the init variant field → 400
            'seg-vABC-00001.m4s',        // uppercase rendition → no match → 404 static
            'seg-v-00001.m4s',           // empty rendition → no match → 404 static
            'init-v.m4s',                // empty init rendition → no match → 404 static
            'init-a.m4s',                // empty init audio group → no match → 404 static
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
