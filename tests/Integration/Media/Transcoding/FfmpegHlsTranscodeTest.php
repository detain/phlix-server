<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media\Transcoding;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\FfmpegRunner;

/**
 * Exercises {@see FfmpegRunner::probe()} against a REAL ffmpeg binary: generate a
 * short clip, read its codecs back. Skipped when ffmpeg/ffprobe are not installed
 * so the suite stays green on minimal CI images.
 *
 * Everything else this file once held has been removed with the builders it
 * covered — the whole-file HLS cases with `startHlsTranscode()` (SV-4.13) and the
 * CMAF case with `startCmafTranscode()` (S59); see the two notes below. Real
 * ffmpeg coverage of the LIVE encode path lives in
 * {@see Fmp4SegmentProductionTest}, {@see VodMpdSegmentResolutionTest} and
 * {@see DashOnDemandServeTest}.
 */
class FfmpegHlsTranscodeTest extends TestCase
{
    private string $dir;
    private FfmpegRunner $runner;

    protected function setUp(): void
    {
        $this->runner = new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', sys_get_temp_dir());
        if (!$this->runner->isAvailable()) {
            $this->markTestSkipped('ffmpeg binary not available');
        }
        $this->dir = sys_get_temp_dir() . '/phlix_hls_it_' . uniqid();
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->dir) && is_dir($this->dir)) {
            $this->rrmdir($this->dir);
        }
    }

    private function makeClip(string $path): void
    {
        $cmd = sprintf(
            '%s -y -hide_banner -loglevel error -f lavfi -i testsrc=duration=4:size=320x240:rate=24 '
            . '-f lavfi -i sine=frequency=440:duration=4 -c:v libx264 -pix_fmt yuv420p '
            . '-c:a aac -shortest %s 2>/dev/null',
            escapeshellarg('/usr/bin/ffmpeg'),
            escapeshellarg($path)
        );
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, 'failed to generate test clip');
        $this->assertFileExists($path);
    }

    public function testProbeReadsCodecsFromGeneratedClip(): void
    {
        $clip = "{$this->dir}/in.mkv";
        $this->makeClip($clip);

        $probe = $this->runner->probe($clip);
        $this->assertIsArray($probe);
        $codecs = array_map(static fn($s) => $s['codec_name'] ?? null, $probe['streams']);
        $this->assertContains('h264', $codecs);
        $this->assertContains('aac', $codecs);
    }

    // NOTE (SV-4.2 test hygiene): the two whole-file HLS integration cases that
    // lived here — testDetachedHlsTranscodeProducesSegmentsAndPlaylist and
    // testRemuxCopyPathProducesPlayableHls — exercised
    // FfmpegRunner::startHlsTranscode(), which was removed as dead code by commit
    // 015ea7a7 (SV-4.13: "Remove superseded whole-file command builders"). The
    // whole-file HLS path was superseded by the on-demand per-segment encode path
    // (buildSegmentCommand/startSegmentEncode, covered by the unit suite) and the
    // CMAF path below. Those two cases could no longer resolve the method (fatal
    // "Call to undefined method") so they were removed rather than left erroring.

    // S59 REMOVED testDetachedCmafTranscodeProducesBothDashAndHls() here. It was
    // the ONLY caller of FfmpegRunner::startCmafTranscode() anywhere, so it
    // pinned an orphan: a whole-file `-f dash -hls_playlist 1` encode with zero
    // callers in `src/`, writing `chunk-…m4s` names nothing in this codebase
    // serves. S49's audit kept it deliberately, as the best available reference
    // for CORRECT CMAF output — the plan said to delete it only once S56/S58 had
    // their own real-ffmpeg tests. They now do, over the code that actually runs:
    //   - Fmp4SegmentProductionTest (S56) — real ffmpeg, parsed ISO-BMFF boxes,
    //     H.264/AAC and HEVC/AC-3, init/fragment split;
    //   - VodMpdSegmentResolutionTest (S58) — the real writer's manifest, every
    //     template expansion resolved to a file, `ffmpeg -i manifest.mpd`;
    //   - DashOnDemandServeTest (S59) — the serve path producing those same
    //     segments on demand.

    private function rrmdir(string $dir): void
    {
        $entries = scandir($dir) ?: [];
        foreach ($entries as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $path = "{$dir}/{$e}";
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
