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

    /** Captured destination of error_log() for the disclosure-law pins. */
    private ?string $logFile = null;

    protected function setUp(): void
    {
        $this->segmentDir = sys_get_temp_dir() . '/phlix_hlsctl_' . uniqid();
        mkdir($this->segmentDir, 0755, true);
        $this->streamer = new HlsStreamer($this->segmentDir, 'http://localhost:8096', new QualitySelector());
    }

    protected function tearDown(): void
    {
        ini_restore('error_log');
        if ($this->logFile !== null && is_file($this->logFile)) {
            @unlink($this->logFile);
        }
        $this->logFile = null;
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
        $res = $this->controller()->getPlaylist(new Request(), ['job_id' => '545d36e0-7173-4c40-b9d4-2f87c3c3c107']);
        $this->assertSame(200, $res->statusCode);
        /** @var array<array-key, mixed> $body */
        $body = json_decode($res->body, true);
        $this->assertSame('/hls/545d36e0-7173-4c40-b9d4-2f87c3c3c107/master.m3u8', $body['playlist_url']);
        $this->assertSame('545d36e0-7173-4c40-b9d4-2f87c3c3c107', $body['job_id']);
    }

    public function testGetPlaylistReturns400WhenJobIdEmpty(): void
    {
        $res = $this->controller()->getPlaylist(new Request(), ['job_id' => '']);
        $this->assertSame(400, $res->statusCode);
        // S-01 residue cure [W-min109 review MAJOR-1]: the player-facing HLS pointer
        // endpoint rides the canonical '<Field> ID is required' human family — the raw
        // snake_case parameter name must never reach an end user.
        /** @var array<string, mixed> $body */
        $body = json_decode($res->body, true);
        $this->assertSame(['error' => 'Job ID is required'], $body);
    }

    public function testServesMasterPlaylistWithHlsContentType(): void
    {
        $this->writeJobFile('c6a4eb7f-9bd8-4148-8ee3-6b69e726ac54', 'master.m3u8', "#EXTM3U\n#EXT-X-VERSION:7\nmedia_0.m3u8\n");
        $res = $this->controller()->serveFile(new Request(), ['job_id' => 'c6a4eb7f-9bd8-4148-8ee3-6b69e726ac54', 'file' => 'master.m3u8']);

        $this->assertSame(200, $res->statusCode);
        $this->assertSame('application/vnd.apple.mpegurl', $res->headers['Content-Type']);
        $this->assertSame('no-cache', $res->headers['Cache-Control']);
        $this->assertStringContainsString('#EXTM3U', $this->bodyOf($res));
    }

    public function testServesMediaPlaylistVerbatim(): void
    {
        $playlist = "#EXTM3U\n#EXT-X-MAP:URI=\"init-0.m4s\"\n#EXTINF:2.0,\nchunk-0-00001.m4s\n";
        $this->writeJobFile('8ce1146b-ce68-4c16-a190-81ac10c9beb1', 'media_0.m3u8', $playlist);
        $res = $this->controller()->serveFile(new Request(), ['job_id' => '8ce1146b-ce68-4c16-a190-81ac10c9beb1', 'file' => 'media_0.m3u8']);

        $this->assertSame(200, $res->statusCode);
        // Served verbatim — relative segment URIs are kept (no rewriting).
        $this->assertSame($playlist, $this->bodyOf($res));
    }

    public function testServesFmp4SegmentWithVideoMp4ContentType(): void
    {
        $this->writeJobFile('4ca55e47-0a3c-42b1-85d0-d49e5c6a817e', 'chunk-0-00001.m4s', 'SEGMENTBYTES');
        $res = $this->controller()->serveFile(new Request(), ['job_id' => '4ca55e47-0a3c-42b1-85d0-d49e5c6a817e', 'file' => 'chunk-0-00001.m4s']);

        $this->assertSame(200, $res->statusCode);
        $this->assertSame('video/mp4', $res->headers['Content-Type']);
        $this->assertSame('public, max-age=31536000', $res->headers['Cache-Control']);
        $this->assertSame('SEGMENTBYTES', $this->bodyOf($res));
    }

    public function testServesInitSegment(): void
    {
        $this->writeJobFile('782d718e-f1be-47ff-a8c5-03ec72451230', 'init-0.m4s', 'INIT');
        $res = $this->controller()->serveFile(new Request(), ['job_id' => '782d718e-f1be-47ff-a8c5-03ec72451230', 'file' => 'init-0.m4s']);
        $this->assertSame(200, $res->statusCode);
        $this->assertSame('INIT', $this->bodyOf($res));
    }

    public function testServeFile404WhenMissing(): void
    {
        $res = $this->controller()->serveFile(new Request(), ['job_id' => '76272dc4-faf6-4073-b711-f58c736830d2', 'file' => 'master.m3u8']);
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
            ->with('a44f47c6-fad6-4ea8-a960-8facb6f4daf9', null, 5)
            ->willReturnCallback(function (string $jobId, ?string $variant, int $index): string {
                $this->writeJobFile($jobId, 'seg-00005.ts', 'TSBYTES');
                return "{$this->segmentDir}/{$jobId}/seg-00005.ts";
            });

        $res = $this->controller($manager)->serveFile(
            new Request(),
            ['job_id' => 'a44f47c6-fad6-4ea8-a960-8facb6f4daf9', 'file' => 'seg-00005.ts']
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
            ['job_id' => 'a44f47c6-fad6-4ea8-a960-8facb6f4daf9', 'file' => 'seg-00005.ts']
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
            ->with('0dae76a0-13a5-4fe4-9fd6-6b8194b7fd49', '1080p', 42)
            ->willReturnCallback(function (string $jobId, ?string $variant, int $index): string {
                $this->writeJobFile($jobId, 'seg-v1080p-00042.ts', 'MVBYTES');
                return "{$this->segmentDir}/{$jobId}/seg-v1080p-00042.ts";
            });

        $res = $this->controller($manager)->serveFile(
            new Request(),
            ['job_id' => '0dae76a0-13a5-4fe4-9fd6-6b8194b7fd49', 'file' => 'seg-v1080p-00042.ts']
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
            ->with('1af3b9aa-5977-47b3-b212-5ed017707f9b', 'original', 7)
            ->willReturnCallback(function (string $jobId, ?string $variant, int $index): string {
                $this->writeJobFile($jobId, 'seg-voriginal-00007.ts', 'ORIGBYTES');
                return "{$this->segmentDir}/{$jobId}/seg-voriginal-00007.ts";
            });

        $res = $this->controller($manager)->serveFile(
            new Request(),
            ['job_id' => '1af3b9aa-5977-47b3-b212-5ed017707f9b', 'file' => 'seg-voriginal-00007.ts']
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
            ->with('0dae76a0-13a5-4fe4-9fd6-6b8194b7fd49', '480p', 3)
            ->willReturn(null);

        $res = $this->controller($manager)->serveFile(
            new Request(),
            ['job_id' => '0dae76a0-13a5-4fe4-9fd6-6b8194b7fd49', 'file' => 'seg-v480p-00003.ts']
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
            ->with('0dae76a0-13a5-4fe4-9fd6-6b8194b7fd49', '720p', 12)
            ->willThrowException(new SegmentBusyException('busy'));

        $res = $this->controller($manager)->serveFile(
            new Request(),
            ['job_id' => '0dae76a0-13a5-4fe4-9fd6-6b8194b7fd49', 'file' => 'seg-v720p-00012.ts']
        );

        $this->assertSame(503, $res->statusCode);
        $this->assertSame('1', $res->headers['Retry-After'] ?? null);
    }

    public function testSegmentCommandBuildRefusalAnswers503NotAnEscapingError(): void
    {
        // INV-A P3 cure (playback-lag): a persisted transcode_jobs.segment_params
        // row outside the ffmpeg argv allowlist makes FfmpegRunner::argvParam()
        // throw InvalidArgumentException synchronously inside ensureSegment().
        // Before the cure that IAE escaped to the router — every segment of the
        // job answered 500 and hls.js retried the storm. The serve arm now
        // answers the codebase's transient envelope: 503 + Retry-After (the
        // 063c59b6 LiveTV not-materialized precedent), flat {error} body, no
        // new wire code.
        $manager = $this->createMock(TranscodeManager::class);
        $manager->expects($this->once())
            ->method('ensureSegment')
            ->with('7f6a2b1c-9d4e-4a3b-8c7d-1e2f3a4b5c6d', null, 3)
            ->willThrowException(new \InvalidArgumentException(
                'transcode segment param "video_codec" is outside the ffmpeg argv allowlist;'
                . ' refusing to build the command'
            ));

        $res = $this->controller($manager)->serveFile(
            new Request(),
            ['job_id' => '7f6a2b1c-9d4e-4a3b-8c7d-1e2f3a4b5c6d', 'file' => 'seg-00003.ts']
        );

        $this->assertSame(503, $res->statusCode);
        $this->assertSame('2', $res->headers['Retry-After'] ?? null);
        /** @var array<string, mixed> $body */
        $body = json_decode($res->body, true);
        $this->assertSame(['error' => 'segment build refused'], $body);
    }

    public function testSegmentCommandBuildRefusalErrorLogsJobIdOnly(): void
    {
        // Operator disclosure law: exactly one error_log line naming the JOB id;
        // the persisted param VALUES (and even the param-name prose of the
        // exception message) never enter it — FfmpegRunner::argvParam() already
        // logged the offending slot name to the transcoding channel itself.
        $manager = $this->createMock(TranscodeManager::class);
        $manager->method('ensureSegment')
            ->willThrowException(new \InvalidArgumentException(
                'transcode segment param "video_codec" is outside the ffmpeg argv allowlist;'
                . ' refusing to build the command'
            ));

        $logFile = (string) tempnam(sys_get_temp_dir(), 'phlix-hls03-log-');
        $this->logFile = $logFile;
        ini_set('error_log', $logFile);

        $res = $this->controller($manager)->serveFile(
            new Request(),
            ['job_id' => '7f6a2b1c-9d4e-4a3b-8c7d-1e2f3a4b5c6d', 'file' => 'seg-00003.ts']
        );

        $this->assertSame(503, $res->statusCode);
        $log = (string) file_get_contents($logFile);
        $this->assertStringContainsString('7f6a2b1c-9d4e-4a3b-8c7d-1e2f3a4b5c6d', $log);
        $this->assertStringNotContainsString(
            'video_codec',
            $log,
            'the refusal detail stays out of the controller line — job id only'
        );
    }

    public function testServesPerVariantMediaPlaylistAsStaticFile(): void
    {
        // media_v{V}.m3u8 per-variant playlists are written up front by the pipeline
        // and served verbatim — NO transcoder call, correct HLS content-type.
        $manager = $this->createMock(TranscodeManager::class);
        $manager->expects($this->never())->method('ensureSegment');

        $playlist = "#EXTM3U\n#EXT-X-VERSION:7\n#EXTINF:2.0,\nseg-v1080p-00000.ts\n";
        $this->writeJobFile('8ccf1fa0-8f9b-4fb8-b528-7a917803a200', 'media_v1080p.m3u8', $playlist);

        $res = $this->controller($manager)->serveFile(
            new Request(),
            ['job_id' => '8ccf1fa0-8f9b-4fb8-b528-7a917803a200', 'file' => 'media_v1080p.m3u8']
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
        $this->writeJobFile('035f816e-b14f-4723-a7bc-19acf2cf8514', 'media_voriginal.m3u8', $playlist);

        $res = $this->controller($manager)->serveFile(
            new Request(),
            ['job_id' => '035f816e-b14f-4723-a7bc-19acf2cf8514', 'file' => 'media_voriginal.m3u8']
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
        $this->writeJobFile('fd824f32-7436-4804-bb14-413d3e8b926a', 'master.m3u8', $master);
        $top = "#EXTM3U\n#EXT-X-VERSION:3\n#EXTINF:2.0,\nseg-v1080p-00000.ts\n";
        $this->writeJobFile('fd824f32-7436-4804-bb14-413d3e8b926a', 'media_v1080p.m3u8', $top);
        $this->writeJobFile('fd824f32-7436-4804-bb14-413d3e8b926a', 'media_v720p.m3u8', "#EXTM3U\nseg-v720p-00000.ts\n");
        // media_voriginal.m3u8 intentionally NOT written (the pre-v9 folded shape).

        $res = $this->controller($manager)->serveFile(
            new Request(),
            ['job_id' => 'fd824f32-7436-4804-bb14-413d3e8b926a', 'file' => 'media_voriginal.m3u8']
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
            ->with('73f6b49c-29de-4878-b0c7-1a8a77e56e61')
            ->willReturnCallback(function (string $jobId) use ($regenerated): bool {
                $this->writeJobFile($jobId, 'media_voriginal.m3u8', $regenerated);
                return true;
            });

        // Dir exists but the Original playlist does not — the swept-then-replayed
        // signed-URL case.
        $this->writeJobFile('73f6b49c-29de-4878-b0c7-1a8a77e56e61', 'master.m3u8', "#EXTM3U\n#EXT-X-VERSION:7\n");

        $res = $this->controller($manager)->serveFile(
            new Request(),
            ['job_id' => '73f6b49c-29de-4878-b0c7-1a8a77e56e61', 'file' => 'media_voriginal.m3u8']
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
        $this->writeJobFile('26212ecf-c89a-4329-a247-9882c114ac9a', 'master.m3u8', $master);
        $orig = "#EXTM3U\n#EXT-X-VERSION:3\n#EXTINF:2.0,\nseg-voriginal-00000.ts\n";
        $this->writeJobFile('26212ecf-c89a-4329-a247-9882c114ac9a', 'media_voriginal.m3u8', $orig);
        $this->writeJobFile('26212ecf-c89a-4329-a247-9882c114ac9a', 'media_v1080p.m3u8', "#EXTM3U\nseg-v1080p-00000.ts\n");

        $res = $this->controller()->serveFile(
            new Request(),
            ['job_id' => '26212ecf-c89a-4329-a247-9882c114ac9a', 'file' => 'media_voriginal.m3u8']
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
        $this->writeJobFile('3964cf8a-ecc8-42ee-b02f-2de9ac1e9b42', 'master.m3u8', $master);
        $this->writeJobFile('3964cf8a-ecc8-42ee-b02f-2de9ac1e9b42', 'media_v1080p.m3u8', "#EXTM3U\nseg-v1080p-00000.ts\n");

        $res = $this->controller()->serveFile(
            new Request(),
            ['job_id' => '3964cf8a-ecc8-42ee-b02f-2de9ac1e9b42', 'file' => 'media_v2160p.m3u8']
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
                ['job_id' => '9de09d47-ade5-4a29-8a9f-f77fc3c8d831', 'file' => $file]
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
            ['job_id' => 'a44f47c6-fad6-4ea8-a960-8facb6f4daf9', 'file' => 'seg-00005.ts']
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
            ['job_id' => 'a44f47c6-fad6-4ea8-a960-8facb6f4daf9', 'file' => 'seg-00005.ts']
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
            ->with('0dae76a0-13a5-4fe4-9fd6-6b8194b7fd49', '720p', 12)
            ->willThrowException(new SegmentCacheFullException('cache full'));
        $manager->expects($this->once())->method('sweepSegmentCache')->willReturn(0);

        $res = $this->controller($manager)->serveFile(
            new Request(),
            ['job_id' => '0dae76a0-13a5-4fe4-9fd6-6b8194b7fd49', 'file' => 'seg-v720p-00012.ts']
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
            ['job_id' => 'a44f47c6-fad6-4ea8-a960-8facb6f4daf9', 'file' => 'seg-00005.ts']
        );

        $this->assertSame(404, $res->statusCode);
    }

    public function testServeFileRejectsPathTraversal(): void
    {
        $this->writeJobFile('87ed2112-516f-4238-be83-715f83a7407d', 'master.m3u8', 'x');
        foreach (['../master.m3u8', '..', 'a/b', 'secret.php', ''] as $bad) {
            $res = $this->controller()->serveFile(new Request(), ['job_id' => '87ed2112-516f-4238-be83-715f83a7407d', 'file' => $bad]);
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
        $this->writeJobFile('622d1172-02ca-4e6a-9989-c11a105b8230', 'chunk-0-00001.m4s', 'ABCDEFGHIJ');
        $req = new Request();
        $req->headers['Range'] = 'bytes=2-5';

        $res = $this->controller()->serveFile($req, ['job_id' => '622d1172-02ca-4e6a-9989-c11a105b8230', 'file' => 'chunk-0-00001.m4s']);

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
        $this->writeJobFile('5fb34b5e-f21f-4e58-8bc1-6717a4509521', 'chunk-0-00002.m4s', 'ABCDEFGHIJ');
        $req = new Request();
        $req->headers['Range'] = 'bytes=6-';

        $res = $this->controller()->serveFile($req, ['job_id' => '5fb34b5e-f21f-4e58-8bc1-6717a4509521', 'file' => 'chunk-0-00002.m4s']);

        $this->assertSame(206, $res->statusCode);
        $this->assertSame(6, $res->fileOffset);
        $this->assertSame(4, $res->fileLength);
        $this->assertSame('GHIJ', $this->bodyOf($res));
    }

    public function testSegmentUnsatisfiableRangeReturns416(): void
    {
        // start-pos itself is past EOF — genuinely unsatisfiable, still 416.
        $this->writeJobFile('264b5235-70ed-45d4-8507-200684eadc74', 'chunk-0-00003.m4s', 'ABCDEFGHIJ');
        $req = new Request();
        $req->headers['Range'] = 'bytes=50-60';

        $res = $this->controller()->serveFile($req, ['job_id' => '264b5235-70ed-45d4-8507-200684eadc74', 'file' => 'chunk-0-00003.m4s']);

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
        $this->writeJobFile('56bedcee-7ba0-4991-8ca4-a11fddac4a61', 'chunk-0-00005.m4s', 'ABCDEFGHIJ');
        $req = new Request();
        $req->headers['Range'] = 'bytes=0-999999999';

        $res = $this->controller()->serveFile($req, ['job_id' => '56bedcee-7ba0-4991-8ca4-a11fddac4a61', 'file' => 'chunk-0-00005.m4s']);

        $this->assertSame(206, $res->statusCode);
        $this->assertSame(0, $res->fileOffset);
        $this->assertSame(10, $res->fileLength);
        $this->assertSame('ABCDEFGHIJ', $this->bodyOf($res));
    }

    public function testSegmentSuffixRangeReturnsLastNBytes(): void
    {
        // Reviewer S3 round-1 finding 2 (INFO): `bytes=-N` ("last N bytes") is now
        // honoured instead of falling through to a whole-file 200.
        $this->writeJobFile('adedbd03-cb54-4923-8839-1d8c98b998a6', 'chunk-0-00006.m4s', 'ABCDEFGHIJ');
        $req = new Request();
        $req->headers['Range'] = 'bytes=-4';

        $res = $this->controller()->serveFile($req, ['job_id' => 'adedbd03-cb54-4923-8839-1d8c98b998a6', 'file' => 'chunk-0-00006.m4s']);

        $this->assertSame(206, $res->statusCode);
        $this->assertSame(6, $res->fileOffset);
        $this->assertSame(4, $res->fileLength);
        $this->assertSame('GHIJ', $this->bodyOf($res));
    }

    public function testSegmentSuffixRangeLongerThanFileReturnsWholeFile(): void
    {
        // A suffix larger than the file just clamps to byte 0 — the whole file,
        // still satisfiable, still 206 (matches common server behaviour/RFC intent).
        $this->writeJobFile('f20b73e6-d035-45bf-ac05-44647f79eed8', 'chunk-0-00007.m4s', 'ABCDEFGHIJ');
        $req = new Request();
        $req->headers['Range'] = 'bytes=-999';

        $res = $this->controller()->serveFile($req, ['job_id' => 'f20b73e6-d035-45bf-ac05-44647f79eed8', 'file' => 'chunk-0-00007.m4s']);

        $this->assertSame(206, $res->statusCode);
        $this->assertSame(0, $res->fileOffset);
        $this->assertSame(10, $res->fileLength);
        $this->assertSame('ABCDEFGHIJ', $this->bodyOf($res));
    }

    public function testSegmentZeroLengthSuffixRangeReturns416(): void
    {
        // `bytes=-0` is not a valid suffix-length per RFC 7233 §2.1 — unsatisfiable.
        $this->writeJobFile('1ab55630-77e3-481b-a8c1-62d1fb3ba0e7', 'chunk-0-00008.m4s', 'ABCDEFGHIJ');
        $req = new Request();
        $req->headers['Range'] = 'bytes=-0';

        $res = $this->controller()->serveFile($req, ['job_id' => '1ab55630-77e3-481b-a8c1-62d1fb3ba0e7', 'file' => 'chunk-0-00008.m4s']);

        $this->assertSame(416, $res->statusCode);
        $this->assertSame('bytes */10', $res->headers['Content-Range']);
        $this->assertNull($res->filePath);
    }

    public function testSegmentMultiRangeFallsBackToFull200(): void
    {
        // We only support a single range; a multi-range value is ignored and the
        // whole file is streamed (a valid RFC 7233 fallback).
        $this->writeJobFile('060061d4-26d6-4693-9269-4c17814df954', 'chunk-0-00004.m4s', 'ABCDEFGHIJ');
        $req = new Request();
        $req->headers['Range'] = 'bytes=0-2,4-6';

        $res = $this->controller()->serveFile($req, ['job_id' => '060061d4-26d6-4693-9269-4c17814df954', 'file' => 'chunk-0-00004.m4s']);

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
        $this->writeJobFile('19fb7da4-35be-4a27-a1f4-1ad29e1482a1', 'chunk-0-00010.m4s', 'ABCDEFGHIJ');
        $req = new Request();
        $req->headers['Range'] = 'bytes=5-2';

        $res = $this->controller()->serveFile($req, ['job_id' => '19fb7da4-35be-4a27-a1f4-1ad29e1482a1', 'file' => 'chunk-0-00010.m4s']);

        $this->assertSame(416, $res->statusCode);
        $this->assertSame('bytes */10', $res->headers['Content-Range']);
        $this->assertNull($res->filePath);
    }

    public function testImmutableSegmentReturns304OnMatchingIfModifiedSince(): void
    {
        // Immutable (max-age=31536000) segments answer a conditional GET with 304
        // when If-Modified-Since matches the file mtime — no body re-sent.
        $this->writeJobFile('ec3dfe52-6020-4fa7-8bdb-f40a81a00846', 'chunk-0-00009.m4s', 'TSBYTES');
        $path = "{$this->segmentDir}/ec3dfe52-6020-4fa7-8bdb-f40a81a00846/chunk-0-00009.m4s";
        $lastModified = gmdate('D, d M Y H:i:s', (int) filemtime($path)) . ' GMT';

        $req = new Request();
        $req->headers['If-Modified-Since'] = $lastModified;

        $res = $this->controller()->serveFile($req, ['job_id' => 'ec3dfe52-6020-4fa7-8bdb-f40a81a00846', 'file' => 'chunk-0-00009.m4s']);

        $this->assertSame(304, $res->statusCode);
        $this->assertSame($lastModified, $res->headers['Last-Modified']);
        $this->assertNull($res->filePath);
    }

    public function testNoCachePlaylistIsNeverShortCircuitedTo304(): void
    {
        // Playlists are `no-cache` (they may be rewritten during the encode), so a
        // matching If-Modified-Since must still serve the full body, not 304.
        $this->writeJobFile('cb5eb37d-86b6-42d2-9f35-547f5c1c36c9', 'media_0.m3u8', "#EXTM3U\n");
        $path = "{$this->segmentDir}/cb5eb37d-86b6-42d2-9f35-547f5c1c36c9/media_0.m3u8";
        $lastModified = gmdate('D, d M Y H:i:s', (int) filemtime($path)) . ' GMT';

        $req = new Request();
        $req->headers['If-Modified-Since'] = $lastModified;

        $res = $this->controller()->serveFile($req, ['job_id' => 'cb5eb37d-86b6-42d2-9f35-547f5c1c36c9', 'file' => 'media_0.m3u8']);

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
            ->with('7ca0dfb1-30a9-46e1-be10-c348cb77f3a7', $variant, $index, $audioId)
            ->willReturnCallback(function () use ($file): string {
                // Produce the file the way the real encode does, so the static
                // serve below has something to hand back.
                $this->writeJobFile('7ca0dfb1-30a9-46e1-be10-c348cb77f3a7', $file, 'FMP4BYTES');
                return "{$this->segmentDir}/7ca0dfb1-30a9-46e1-be10-c348cb77f3a7/{$file}";
            });

        $res = $this->controller($manager)->serveFile(
            new Request(),
            ['job_id' => '7ca0dfb1-30a9-46e1-be10-c348cb77f3a7', 'file' => $file]
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
        $this->assertFileDoesNotExist("{$this->segmentDir}/7058e92c-e224-41fd-bafd-210421af5bfc/init-v240p.m4s");

        $manager = $this->createMock(TranscodeManager::class);
        $manager->expects($this->once())
            ->method('ensureSegment')
            ->with('7058e92c-e224-41fd-bafd-210421af5bfc', '240p', 0, null)
            ->willReturnCallback(function (): string {
                $this->writeJobFile('7058e92c-e224-41fd-bafd-210421af5bfc', 'init-v240p.m4s', 'INITBYTES');
                return "{$this->segmentDir}/7058e92c-e224-41fd-bafd-210421af5bfc/init-v240p.m4s";
            });

        $res = $this->controller($manager)->serveFile(
            new Request(),
            ['job_id' => '7058e92c-e224-41fd-bafd-210421af5bfc', 'file' => 'init-v240p.m4s']
        );

        $this->assertSame(200, $res->statusCode);
        $this->assertSame('INITBYTES', $this->bodyOf($res));
        $this->assertFileExists("{$this->segmentDir}/7058e92c-e224-41fd-bafd-210421af5bfc/init-v240p.m4s");
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
            ->with('7ca0dfb1-30a9-46e1-be10-c348cb77f3a7', '4321p', 0, null)
            ->willReturn(null);

        $res = $this->controller($manager)->serveFile(
            new Request(),
            ['job_id' => '7ca0dfb1-30a9-46e1-be10-c348cb77f3a7', 'file' => 'seg-v4321p-00000.m4s']
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
        $this->writeJobFile('fbca6550-5913-4ebb-864e-d788e886c783', 'seg-v1080p-00000.m4s', 'STALEBYTES');

        $res = $this->controller(null)->serveFile(
            new Request(),
            ['job_id' => 'fbca6550-5913-4ebb-864e-d788e886c783', 'file' => 'seg-v1080p-00000.m4s']
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
            ->with('7ca0dfb1-30a9-46e1-be10-c348cb77f3a7', '720p', 12, null)
            ->willThrowException(new SegmentBusyException('busy'));
        $manager->expects($this->never())->method('sweepSegmentCache');

        $res = $this->controller($manager)->serveFile(
            new Request(),
            ['job_id' => '7ca0dfb1-30a9-46e1-be10-c348cb77f3a7', 'file' => 'seg-v720p-00012.m4s']
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
            ->with('7ca0dfb1-30a9-46e1-be10-c348cb77f3a7', null, 0, 'a0')
            ->willThrowException(new SegmentCacheFullException('cache full'));
        $manager->expects($this->once())->method('sweepSegmentCache')->willReturn(0);

        $res = $this->controller($manager)->serveFile(
            new Request(),
            ['job_id' => '7ca0dfb1-30a9-46e1-be10-c348cb77f3a7', 'file' => 'init-a0.m4s']
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
            $this->writeJobFile('c261f532-7b3f-4bd8-bf8b-441bbc281c97', $file, $bytes);
            $res = $this->controller($manager)->serveFile(
                new Request(),
                ['job_id' => 'c261f532-7b3f-4bd8-bf8b-441bbc281c97', 'file' => $file]
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
            ->with('95ffd7bd-2971-4fe2-b2c1-e6eeeff358bd', '240p', 1, null)
            ->willReturnCallback(function (): string {
                $this->writeJobFile('95ffd7bd-2971-4fe2-b2c1-e6eeeff358bd', 'seg-v240p-00001.m4s', 'ABCDEFGHIJ');
                return "{$this->segmentDir}/95ffd7bd-2971-4fe2-b2c1-e6eeeff358bd/seg-v240p-00001.m4s";
            });

        $req = new Request();
        $req->headers['Range'] = 'bytes=2-5';

        $res = $this->controller($manager)->serveFile(
            $req,
            ['job_id' => '95ffd7bd-2971-4fe2-b2c1-e6eeeff358bd', 'file' => 'seg-v240p-00001.m4s']
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
                ['job_id' => '9de09d47-ade5-4a29-8a9f-f77fc3c8d831', 'file' => $file]
            );
            $this->assertContains(
                $res->statusCode,
                [400, 404],
                "filename '{$file}' must be rejected, not served"
            );
            $this->assertNotSame(200, $res->statusCode);
        }
    }

    /**
     * 2026-11-03 security review (server__secaudit, Critical): `{job_id}` is
     * matched against the RAW percent-encoded path and rawurldecoded exactly
     * once at the router boundary (S435), so a `%2e%2e%2f` sequence reaches the
     * handler as a literal `../` segment — and the value used to flow, only
     * empty-checked, straight into {@see HlsStreamer::getJobDirectory()} and
     * the served path. The cure is the UUID-shape arm at the entry gate: real
     * ids are minted exclusively by Uuid::v4(), so shape-equality accepts every
     * genuine job and refuses every traversal shape. The 400 envelope stays
     * byte-identical to the file arm's pre-existing one — no new wire surface.
     *
     * @return array<string, array{0: string}>
     */
    public static function traversalJobIdShapes(): array
    {
        return [
            'bare dot-dot' => ['..'],
            'dot-dot chain' => ['../..'],
            'decoded traversal (S435 arrival shape)' => ['../../etc/passwd'],
            'percent form undecoded' => ['%2e%2e%2f%2e%2e%2fetc%2fpasswd'],
            'dot-slash prefix' => ['./secret'],
            'uppercase uuid' => ['BE04FD51-BD8E-4ABB-885E-6048C18FCF48'],
            'uuid without hyphens' => ['be04fd51bd8e42bb885e6048c18fcf48'],
            'valid uuid welded to traversal' => ['782d718e-f1be-47ff-a8c5-03ec72451230/../../etc'],
        ];
    }

    /**
     * @dataProvider traversalJobIdShapes
     */
    public function testTraversalShapedJobIdsAreRefusedWithTheExistingFourHundredEnvelope(string $jobId): void
    {
        $res = $this->controller()->serveFile(new Request(), ['job_id' => $jobId, 'file' => 'master.m3u8']);
        $this->assertSame(400, $res->statusCode);
        /** @var array<array-key, mixed> $body */
        $body = json_decode($res->body, true);
        $this->assertSame(['error' => 'Invalid request'], $body);
    }

    /**
     * The pointer-echo arm carries the same law: a traversal-shaped id never
     * even becomes a URL. Empty keeps its own pre-existing envelope.
     */
    public function testGetPlaylistRefusesTraversalShapedJobIds(): void
    {
        $res = $this->controller()->getPlaylist(new Request(), ['job_id' => '../../etc/passwd']);
        $this->assertSame(400, $res->statusCode);
        /** @var array<array-key, mixed> $body */
        $body = json_decode($res->body, true);
        $this->assertSame(['error' => 'Invalid request'], $body);
    }

    /**
     * Full wire chain: the exact attack shape from the finding. A Router at
     * production config compiles {job_id} to [^/]+ over the raw path, decodes
     * once at the boundary (proven by the spy leg below), and the registered
     * controller gate answers 400 — bytes outside the segment base never
     * resolve.
     */
    public function testPercentEncodedTraversalIsDecodedAtTheRouterBoundaryAndRefusedByTheGate(): void
    {
        $encoded = '/hls/%2e%2e%2f%2e%2e%2fsecret/master.m3u8';

        // Leg 1 — boundary proof: the same rail with a spy handler receives
        // the DECODED traversal. Without this leg the 400 below could be
        // matching a mere typo; this is what makes the encoded form load-bearing.
        $spyRouter = new \Phlix\Server\Http\Router();
        $seen = null;
        $capture = function (Request $req, array $params) use (&$seen): \Phlix\Server\Http\Response {
            $seen = $params;
            return new \Phlix\Server\Http\Response();
        };
        $spyRouter->get('/hls/{job_id}/{file}', $capture);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = $encoded;
        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $spyRouter->dispatch(Request::fromGlobals());
        $this->assertIsArray($seen, 'the spy rail must have been reached through the real matcher');
        $this->assertSame('../../secret', $seen['job_id'], 'S435: the boundary hands handlers the decoded segment');

        // Leg 2 — the production handler on the same Router refuses it.
        $router = new \Phlix\Server\Http\Router();
        $router->get('/hls/{job_id}/{file}', [$this->controller(), 'serveFile']);
        $response = $router->dispatch(Request::fromGlobals());
        $this->assertSame(400, $response->statusCode);
        /** @var array<array-key, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame(['error' => 'Invalid request'], $body);
    }

    /**
     * Defense-in-depth arm: even a VALID-UUID job dir that is a symlink out of
     * the segment base streams nothing — serveJobFile's realpath containment
     * answers the same 404 a missing file already answers.
     */
    public function testSymlinkedJobDirectoryOutsideTheSegmentBaseIsNotStreamed(): void
    {
        $jobId = '1f111111-1111-4111-8111-111111111111';
        $outside = sys_get_temp_dir() . '/phlix_hls_escape_' . uniqid();
        mkdir($outside, 0755, true);
        file_put_contents("{$outside}/master.m3u8", "EXFILTRATED\n");
        $link = "{$this->segmentDir}/{$jobId}";
        if (!@symlink($outside, $link)) {
            $this->markTestSkipped('symlinks are unavailable on this filesystem');
        }

        $res = $this->controller()->serveFile(new Request(), ['job_id' => $jobId, 'file' => 'master.m3u8']);
        $this->assertSame(404, $res->statusCode);

        $this->assertSame(false, str_contains($this->bodyOf($res), 'EXFILTRATED'));
        @unlink($link);
        $this->rrmdir($outside);
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
