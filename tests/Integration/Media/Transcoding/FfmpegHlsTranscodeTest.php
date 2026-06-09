<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media\Transcoding;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\FfmpegRunner;

/**
 * End-to-end exercise of the HLS pipeline against a REAL ffmpeg binary: it
 * generates a short clip, runs a detached HLS transcode, and asserts that valid
 * segments + playlist (with ENDLIST) are produced. Skipped when ffmpeg/ffprobe
 * are not installed so the suite stays green on minimal CI images.
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

    public function testDetachedHlsTranscodeProducesSegmentsAndPlaylist(): void
    {
        $clip = "{$this->dir}/in.mkv";
        $this->makeClip($clip);

        $out = "{$this->dir}/job";
        mkdir($out, 0755, true);

        // Force an encode (libx264) with a short segment length so the 4s clip
        // splits into multiple keyframe-aligned segments.
        $pid = $this->runner->startHlsTranscode($clip, $out, [
            'video_codec' => 'libx264',
            'preset' => 'ultrafast',
            'crf' => 28,
            'audio_codec' => 'aac',
            'audio_bitrate' => '96k',
            'segment_seconds' => 1,
        ]);
        $this->assertGreaterThan(0, $pid);

        $deadline = microtime(true) + 30.0;
        while (microtime(true) < $deadline) {
            if (file_exists("{$out}/.complete") || file_exists("{$out}/.failed")) {
                break;
            }
            usleep(200000);
        }

        $log = is_file("{$out}/ffmpeg.log") ? (string) file_get_contents("{$out}/ffmpeg.log") : '';
        $this->assertFileDoesNotExist("{$out}/.failed", "ffmpeg failed: {$log}");
        $this->assertFileExists("{$out}/.complete");

        $this->assertFileExists("{$out}/stream_0.m3u8");
        $playlist = (string) file_get_contents("{$out}/stream_0.m3u8");
        $this->assertStringContainsString('#EXTM3U', $playlist);
        $this->assertStringContainsString('#EXT-X-ENDLIST', $playlist);

        $segments = glob("{$out}/segment_0_*.ts") ?: [];
        $this->assertGreaterThanOrEqual(1, count($segments));
        foreach ($segments as $seg) {
            $this->assertGreaterThan(0, (int) filesize($seg), 'segment is empty');
        }
    }

    public function testRemuxCopyPathProducesPlayableHls(): void
    {
        $clip = "{$this->dir}/in.mp4";
        $this->makeClip($clip);

        $out = "{$this->dir}/remux";
        mkdir($out, 0755, true);

        $pid = $this->runner->startHlsTranscode($clip, $out, [
            'video_codec' => 'copy',
            'audio_codec' => 'copy',
            'segment_seconds' => 2,
        ]);
        $this->assertGreaterThan(0, $pid);

        $deadline = microtime(true) + 20.0;
        while (microtime(true) < $deadline && !file_exists("{$out}/.complete") && !file_exists("{$out}/.failed")) {
            usleep(200000);
        }

        $log = is_file("{$out}/ffmpeg.log") ? (string) file_get_contents("{$out}/ffmpeg.log") : '';
        $this->assertFileExists("{$out}/.complete", "remux failed: {$log}");
        $this->assertFileExists("{$out}/stream_0.m3u8");
        $this->assertGreaterThanOrEqual(1, count(glob("{$out}/segment_0_*.ts") ?: []));
    }

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
