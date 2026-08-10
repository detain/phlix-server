<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\SegmentBusyException;
use Phlix\Media\Transcoding\SegmentCacheFullException;
use Phlix\Media\Transcoding\TranscodeManager;
use Phlix\Server\Http\Controllers\DashController;
use Phlix\Server\Http\Request;

/**
 * Unit tests for {@see DashController}.
 *
 * DASH is served from the SAME CMAF job directory as HLS (shared fMP4 segments):
 *   GET /dash/{job_id}/manifest -> getManifest (JSON mpd url)
 *   GET /dash/{job_id}/{file}   -> serveFile   (manifest.mpd / *.m4s)
 *
 * S59 added the on-demand trigger: a `.m4s` request routes through
 * {@see TranscodeManager::ensureSegment()} before the static serve. These cases
 * pin WHICH arguments each filename shape maps to (the mapping is the whole
 * contract with S58's `SegmentTemplate`s) and the 503 handling; the real-bytes
 * proof that a triggered encode actually produces the file lives in
 * {@see \Phlix\Tests\Integration\Media\Transcoding\DashOnDemandServeTest}.
 */
class DashControllerTest extends TestCase
{
    private string $segmentDir;

    protected function setUp(): void
    {
        $this->segmentDir = sys_get_temp_dir() . '/phlix_dashctl_' . uniqid();
        mkdir($this->segmentDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->segmentDir);
    }

    private function controller(): DashController
    {
        return new DashController($this->segmentDir);
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
     * Resolves the served bytes of a response. File-backed responses (the MPD and
     * segments now stream via {@see \Phlix\Server\Http\Response::withFile()} rather
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

    public function testGetManifestReturnsMpdUrl(): void
    {
        $res = $this->controller()->getManifest(new Request(), ['job_id' => 'job-1']);
        $this->assertSame(200, $res->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($res->body, true);
        $this->assertSame('/dash/job-1/manifest.mpd', $body['manifest_url']);
        $this->assertSame('DASH', $body['protocol']);
    }

    public function testGetManifestReturns400WhenJobIdEmpty(): void
    {
        $res = $this->controller()->getManifest(new Request(), ['job_id' => '']);
        $this->assertSame(400, $res->statusCode);
    }

    public function testServesMpdWithDashContentType(): void
    {
        $this->writeJobFile('job-2', 'manifest.mpd', '<?xml version="1.0"?><MPD></MPD>');
        $res = $this->controller()->serveFile(new Request(), ['job_id' => 'job-2', 'file' => 'manifest.mpd']);

        $this->assertSame(200, $res->statusCode);
        $this->assertSame('application/dash+xml', $res->headers['Content-Type']);
        $this->assertSame('no-cache', $res->headers['Cache-Control']);
        $this->assertStringContainsString('<MPD>', $this->bodyOf($res));
    }

    public function testServesM4sSegment(): void
    {
        $this->writeJobFile('job-3', 'chunk-1-00002.m4s', 'AUDIOBYTES');
        $res = $this->controller()->serveFile(new Request(), ['job_id' => 'job-3', 'file' => 'chunk-1-00002.m4s']);

        $this->assertSame(200, $res->statusCode);
        $this->assertSame('video/mp4', $res->headers['Content-Type']);
        $this->assertSame('AUDIOBYTES', $this->bodyOf($res));
    }

    public function testServeFile404WhenMissing(): void
    {
        $res = $this->controller()->serveFile(new Request(), ['job_id' => 'job-x', 'file' => 'manifest.mpd']);
        $this->assertSame(404, $res->statusCode);
    }

    public function testServeFileRejectsTraversal(): void
    {
        $res = $this->controller()->serveFile(new Request(), ['job_id' => 'job-3', 'file' => '..']);
        $this->assertSame(400, $res->statusCode);
    }

    // ─────────────────────────────────────────────────────────────────
    // S59 — the on-demand trigger
    // ─────────────────────────────────────────────────────────────────

    /**
     * Every filename S58's `SegmentTemplate`s can expand to, and the exact
     * `ensureSegment($jobId, $variant, $index, $audioId)` call it must make.
     *
     * This is the join between the two halves of the feature: the manifest
     * writer emits these six shapes, and nothing else in the codebase asserts
     * that the serve path parses the same six back into the arguments the
     * producer needs. An init maps to index 0 of its own rendition because that
     * is what publishes the init file (see the controller docblock).
     *
     * @return array<string, array{0:string, 1:string|null, 2:string|null, 3:int}>
     */
    public static function segmentShapes(): array
    {
        return [
            'video segment'      => ['seg-v1080p-00042.m4s', '1080p', null, 42],
            'video segment 0'    => ['seg-v240p-00000.m4s', '240p', null, 0],
            'original rung'      => ['seg-voriginal-00007.m4s', 'original', null, 7],
            'audio segment'      => ['seg-a1-00003.m4s', null, 'a1', 3],
            'legacy segment'     => ['seg-00012.m4s', null, null, 12],
            'video init'         => ['init-v720p.m4s', '720p', null, 0],
            'audio init'         => ['init-a0.m4s', null, 'a0', 0],
            'legacy init'        => ['init.m4s', null, null, 0],
        ];
    }

    /**
     * @dataProvider segmentShapes
     */
    public function testEveryManifestReferenceShapeTriggersItsOwnEncode(
        string $file,
        ?string $variant,
        ?string $audioId,
        int $index
    ): void {
        $manager = $this->createMock(TranscodeManager::class);
        $manager->expects($this->once())
            ->method('ensureSegment')
            ->with('job-9', $variant, $index, $audioId)
            ->willReturnCallback(function () use ($file): string {
                // Produce the file the way the real encode does, so the static
                // serve below has something to hand back.
                $this->writeJobFile('job-9', $file, 'FMP4BYTES');
                return "{$this->segmentDir}/job-9/{$file}";
            });

        $res = (new DashController($this->segmentDir, $manager))
            ->serveFile(new Request(), ['job_id' => 'job-9', 'file' => $file]);

        $this->assertSame(200, $res->statusCode);
        $this->assertSame('video/mp4', $res->headers['Content-Type']);
        $this->assertSame('FMP4BYTES', $this->bodyOf($res));
    }

    /**
     * The manifest itself is NOT an on-demand artefact — it must never reach the
     * segment producer. Control for the case above: if
     * {@see \Phlix\Server\Http\Controllers\SegmentRequestParser::parse()}
     * over-matched, the `never()` here would fire.
     */
    public function testTheManifestItselfNeverTriggersAnEncode(): void
    {
        $this->writeJobFile('job-9', 'manifest.mpd', '<?xml version="1.0"?><MPD></MPD>');
        $manager = $this->createMock(TranscodeManager::class);
        $manager->expects($this->never())->method('ensureSegment');
        $manager->expects($this->never())->method('ensurePlaylistRegenerated');

        $res = (new DashController($this->segmentDir, $manager))
            ->serveFile(new Request(), ['job_id' => 'job-9', 'file' => 'manifest.mpd']);

        $this->assertSame(200, $res->statusCode);
        $this->assertSame('application/dash+xml', $res->headers['Content-Type']);
    }

    /**
     * An MPEG-TS name is not a DASH artefact either: the `.ts` serve path is
     * HlsController's, and this controller must not start encoding for it.
     * Without an extension check in the regexes, `seg-v1080p-00042.ts` would
     * match just as well as the `.m4s` — which is the mutation this kills.
     */
    public function testAMpegTsSegmentNameIsNotADashOnDemandArtefact(): void
    {
        $this->writeJobFile('job-9', 'seg-v1080p-00042.ts', 'TSBYTES');
        $manager = $this->createMock(TranscodeManager::class);
        $manager->expects($this->never())->method('ensureSegment');

        $res = (new DashController($this->segmentDir, $manager))
            ->serveFile(new Request(), ['job_id' => 'job-9', 'file' => 'seg-v1080p-00042.ts']);

        $this->assertSame(200, $res->statusCode);
        $this->assertSame('video/mp2t', $res->headers['Content-Type']);
    }

    /**
     * A miss the producer cannot satisfy (unknown rung / index past the end) is
     * a 404 DECISION, not a serve of nothing.
     */
    public function testAnUnproducibleSegmentIs404(): void
    {
        $manager = $this->createMock(TranscodeManager::class);
        $manager->expects($this->once())->method('ensureSegment')->willReturn(null);

        $res = (new DashController($this->segmentDir, $manager))
            ->serveFile(new Request(), ['job_id' => 'job-9', 'file' => 'seg-v4321p-00000.m4s']);

        $this->assertSame(404, $res->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($res->body, true);
        $this->assertSame('segment unavailable', $body['error']);
    }

    /**
     * The transcoder is optional (container-less construction). A null one must
     * 404 the segment, NOT fall through to a static serve — otherwise the whole
     * on-demand path would degrade to the pre-S59 behaviour silently.
     */
    public function testWithoutATranscoderASegmentRequestIs404NotASilentStaticServe(): void
    {
        // The file is deliberately PRESENT: the point is that a null transcoder
        // takes the refusal branch before the static lookup can hide it.
        $this->writeJobFile('job-9', 'seg-v1080p-00000.m4s', 'STALEBYTES');

        $res = $this->controller()->serveFile(
            new Request(),
            ['job_id' => 'job-9', 'file' => 'seg-v1080p-00000.m4s']
        );

        $this->assertSame(404, $res->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($res->body, true);
        $this->assertSame('segment unavailable', $body['error']);
    }

    /**
     * SegmentBusy → 503 + `Retry-After: 1`, exactly as the HLS path answers it.
     * Omitting this would re-open the seek cascade on the DASH path (a hard 404
     * is fatal to a player; a 503 is retried).
     */
    public function testSegmentBusyBecomes503WithRetryAfter(): void
    {
        $manager = $this->createMock(TranscodeManager::class);
        $manager->method('ensureSegment')->willThrowException(new SegmentBusyException('busy'));
        $manager->expects($this->never())->method('sweepSegmentCache');

        $res = (new DashController($this->segmentDir, $manager))
            ->serveFile(new Request(), ['job_id' => 'job-9', 'file' => 'seg-v1080p-00000.m4s']);

        $this->assertSame(503, $res->statusCode);
        $this->assertSame('1', $res->headers['Retry-After']);
        /** @var array<string, mixed> $body */
        $body = json_decode($res->body, true);
        $this->assertSame('segment busy', $body['error']);
    }

    /**
     * SegmentCacheFull → sweep, THEN 503 + `Retry-After: 3`. The sweep is the
     * part that makes the retry able to succeed, so it is asserted as a call,
     * not inferred from the status code.
     */
    public function testSegmentCacheFullSweepsThenAsksForALongerRetry(): void
    {
        $manager = $this->createMock(TranscodeManager::class);
        $manager->method('ensureSegment')->willThrowException(new SegmentCacheFullException('full'));
        $manager->expects($this->once())->method('sweepSegmentCache')->willReturn(0);

        $res = (new DashController($this->segmentDir, $manager))
            ->serveFile(new Request(), ['job_id' => 'job-9', 'file' => 'seg-a0-00001.m4s']);

        $this->assertSame(503, $res->statusCode);
        $this->assertSame('3', $res->headers['Retry-After']);
        /** @var array<string, mixed> $body */
        $body = json_decode($res->body, true);
        $this->assertSame('segment cache full', $body['error']);
    }

    /**
     * A swept job directory regenerates its manifest, the DASH peer of the HLS
     * playlist miss-recovery. Paired with
     * {@see testTheManifestItselfNeverTriggersAnEncode()}, which proves the call
     * does NOT happen when the manifest is present — so this is not a test that
     * would pass with an unconditional call.
     */
    public function testAMissingManifestIsRegeneratedBeforeServing(): void
    {
        $manager = $this->createMock(TranscodeManager::class);
        $manager->expects($this->never())->method('ensureSegment');
        $manager->expects($this->once())
            ->method('ensurePlaylistRegenerated')
            ->with('job-9')
            ->willReturnCallback(function (): bool {
                $this->writeJobFile('job-9', 'manifest.mpd', '<?xml version="1.0"?><MPD/>');
                return true;
            });

        $res = (new DashController($this->segmentDir, $manager))
            ->serveFile(new Request(), ['job_id' => 'job-9', 'file' => 'manifest.mpd']);

        $this->assertSame(200, $res->statusCode);
        $this->assertStringContainsString('<MPD/>', $this->bodyOf($res));
    }

    /**
     * A traversal attempt must be refused BEFORE the transcoder is touched — the
     * filename gate is the first statement of the handler, so a crafted name can
     * never reach the producer with a job id attached.
     */
    public function testAnUnsafeFilenameNeverReachesTheTranscoder(): void
    {
        $manager = $this->createMock(TranscodeManager::class);
        $manager->expects($this->never())->method('ensureSegment');
        $manager->expects($this->never())->method('getJobMediaItemId');

        $res = (new DashController($this->segmentDir, $manager))
            ->serveFile(new Request(), ['job_id' => 'job-9', 'file' => '../seg-v1080p-00000.m4s']);

        $this->assertSame(400, $res->statusCode);
    }

    /**
     * An empty job id is refused the same way, and never reaches the producer.
     */
    public function testAnEmptyJobIdIs400(): void
    {
        $manager = $this->createMock(TranscodeManager::class);
        $manager->expects($this->never())->method('ensureSegment');

        $res = (new DashController($this->segmentDir, $manager))
            ->serveFile(new Request(), ['job_id' => '', 'file' => 'manifest.mpd']);

        $this->assertSame(400, $res->statusCode);
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
