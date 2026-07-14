<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media\Transcoding;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\FfmpegRunner;

/**
 * End-to-end exercise of the transcode pipeline against a REAL ffmpeg binary: it
 * generates a short clip, probes its codecs, and runs a detached CMAF transcode,
 * asserting that a valid DASH manifest + HLS master/media playlists and fMP4
 * segments are produced from a single encode. Skipped when ffmpeg/ffprobe are not
 * installed so the suite stays green on minimal CI images.
 *
 * (The former whole-file HLS cases were removed with FfmpegRunner::startHlsTranscode
 * in SV-4.13 — see the note above testDetachedCmafTranscodeProducesBothDashAndHls.)
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

    public function testDetachedCmafTranscodeProducesBothDashAndHls(): void
    {
        $clip = "{$this->dir}/in.mkv";
        $this->makeClip($clip);

        $out = "{$this->dir}/cmaf";
        mkdir($out, 0755, true);

        $pid = $this->runner->startCmafTranscode($clip, $out, [
            'video_codec' => 'libx264',
            'preset' => 'ultrafast',
            'crf' => 28,
            'audio_codec' => 'aac',
            'audio_bitrate' => '96k',
            'segment_seconds' => 1,
        ]);
        $this->assertGreaterThan(0, $pid);

        $deadline = microtime(true) + 30.0;
        while (microtime(true) < $deadline && !file_exists("{$out}/.complete") && !file_exists("{$out}/.failed")) {
            usleep(200000);
        }

        $log = is_file("{$out}/ffmpeg.log") ? (string) file_get_contents("{$out}/ffmpeg.log") : '';
        $this->assertFileExists("{$out}/.complete", "cmaf failed: {$log}");

        // DASH manifest + HLS master/media playlists from a single encode.
        $this->assertFileExists("{$out}/manifest.mpd");
        $this->assertFileExists("{$out}/master.m3u8");
        $this->assertStringContainsString('<MPD', (string) file_get_contents("{$out}/manifest.mpd"));
        $this->assertStringContainsString('#EXTM3U', (string) file_get_contents("{$out}/master.m3u8"));

        // Shared fMP4 init + media segments.
        $this->assertGreaterThanOrEqual(1, count(glob("{$out}/init-*.m4s") ?: []));
        $chunks = glob("{$out}/chunk-*.m4s") ?: [];
        $this->assertGreaterThanOrEqual(1, count($chunks));
        foreach ($chunks as $c) {
            $this->assertGreaterThan(0, (int) filesize($c));
        }
    }

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
