<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\SegmentProcessRegistry;
use PHPUnit\Framework\TestCase;

/**
 * A real local ffmpeg CMAF segment encode, through the real command builder and the
 * real registry, must not be reaped by the stall rule.
 *
 * The encode is a 720p `veryslow` one-thread segment of a generated test pattern. It
 * takes about 15-17 s on the dev host. The stall window is 30 s. That is a generous
 * margin over the measured 4.7 s end-of-input flush, so a host slowed about 6x still
 * never reaches it, and a healthy encode is never reaped by the stall rule. The window
 * is not tuned to this encode's length. At 30 s the window is longer than the encode,
 * so this test does not, by itself, prove that the progress file beats the byte-size
 * probe. That discrimination is covered by SegmentProcessRegistryProgressTest. Skipped
 * when ffmpeg or ffprobe is not installed.
 */
final class SegmentEncodeStallWatchdogRealFfmpegTest extends TestCase
{
    private const SEC = 1_000_000_000;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/phlix_real_stall_' . uniqid('', true);
        mkdir($this->dir, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function ffmpegPath(): ?string
    {
        $path = trim((string) shell_exec('command -v ffmpeg 2>/dev/null'));

        return $path === '' ? null : $path;
    }

    /**
     * The ffprobe beside the configured ffmpeg, else the one on PATH. Null when neither
     * exists, so the test skips instead of assuming /usr/bin.
     */
    private function ffprobePath(string $ffmpeg): ?string
    {
        $beside = dirname($ffmpeg) . '/ffprobe';
        if (is_file($beside) && is_executable($beside)) {
            return $beside;
        }
        $path = trim((string) shell_exec('command -v ffprobe 2>/dev/null'));

        return $path === '' ? null : $path;
    }

    public function testARealCmafEncodeIsNotReapedByTheStallRule(): void
    {
        $ffmpeg = $this->ffmpegPath();
        if ($ffmpeg === null) {
            self::markTestSkipped('ffmpeg is not installed');
        }
        if (!function_exists('posix_kill')) {
            self::markTestSkipped('needs posix_kill to probe the encode process');
        }

        $source = $this->dir . '/source.mp4';
        exec(
            escapeshellarg($ffmpeg) . ' -nostdin -y -hide_banner -loglevel error'
            . ' -f lavfi -i ' . escapeshellarg('testsrc2=size=1280x720:rate=25')
            . ' -t 8 -c:v libx264 -preset ultrafast -pix_fmt yuv420p '
            . escapeshellarg($source) . ' < /dev/null',
            $out,
            $rc,
        );
        if ($rc !== 0 || !is_file($source)) {
            self::markTestSkipped('could not generate the test pattern');
        }

        $ffprobe = $this->ffprobePath($ffmpeg);
        if ($ffprobe === null) {
            self::markTestSkipped('ffprobe is not installed');
        }

        $runner = new FfmpegRunner($ffmpeg, $ffprobe, $this->dir);
        $runner->setSegmentEncodeThreads(1);
        $registry = new SegmentProcessRegistry();
        // 30 s stall window (the measured 4.7 s flush x about 6 for a loaded host), 120 s wall cap.
        $registry->configureWatchdog(30.0, 120.0);
        $runner->setSegmentProcessRegistry($registry);

        $final = $this->dir . '/seg-v720p-00000.m4s';
        $pid = $runner->startSegmentEncode(
            $source,
            $final,
            0.0,
            6.0,
            [
                'video_codec' => 'libx264',
                'preset' => 'veryslow',
                'crf' => 23,
                'width' => 1280,
                'height' => 720,
                'pix_fmt' => 'yuv420p',
                'profile' => 'high',
                'level' => '4.1',
                'audio_codec' => 'aac',
                'audio_bitrate' => '128k',
                'audio_channels' => 2,
                'segment_format' => 'fmp4',
                'init_file' => $this->dir . '/init-v720p.m4s',
            ],
            $final,
        );
        self::assertGreaterThan(0, $pid, 'the encode must launch');

        // Drive the watchdog at the same cadence as the timer, until the segment lands.
        $signalled = 0;
        $deadline = hrtime(true) + 60 * self::SEC;
        while (!is_file($final) && hrtime(true) < $deadline) {
            usleep(100_000);
            $signalled += $registry->sweep();
        }

        self::assertSame(0, $signalled, 'a healthy CMAF encode must not be reaped by the stall rule');
        self::assertFileExists($final, 'the encode must publish the segment');

        // After the publish the chain removes its temps. Once the process is gone the
        // registry drops the entry, so nothing is left behind.
        $settle = hrtime(true) + 10 * self::SEC;
        while ($registry->registeredKeyCount() > 0 && hrtime(true) < $settle) {
            usleep(100_000);
            $registry->sweep();
        }
        self::assertSame(0, $registry->registeredKeyCount(), 'the finished encode is released');
        self::assertSame([], glob($this->dir . '/*.part-*') ?: [], 'no temp is left behind');
        self::assertSame([], glob($this->dir . '/*.prog') ?: [], 'the progress file is cleaned up');
    }
}
