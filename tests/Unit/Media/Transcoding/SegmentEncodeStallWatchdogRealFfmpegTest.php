<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\SegmentProcessRegistry;
use PHPUnit\Framework\TestCase;

/**
 * A real local ffmpeg CMAF segment encode, through the real command builder and the
 * real registry, must not be reaped by the stall rule. It also proves that the progress
 * signal, not the byte size, is what keeps it alive (review finding 4).
 *
 * The encode is a 12 s 720p `veryslow` one-thread segment of a generated test pattern,
 * which takes about 30-35 s on the dev host. The stall window is 20 s, so the encode
 * outlasts the window by a wide margin. A CMAF encode's temp is 0 bytes until the end, so
 * a byte-size probe would reap it at 20 s, and this test would then fail. The progress
 * probe keeps it alive. The end-of-input flush is about 4.7 s for a 6 s 720p encode, so
 * the 20 s window leaves about 4x headroom over it. The elapsed-time assertion below
 * fails loudly if the host is so fast that the encode no longer outlasts the window,
 * because then the test would prove nothing. Skipped when ffmpeg or ffprobe is not
 * installed. Runs in about 35 s.
 */
final class SegmentEncodeStallWatchdogRealFfmpegTest extends TestCase
{
    private const SEC = 1_000_000_000;

    /** The stall window this test runs under, in seconds. */
    private const STALL_WINDOW_SECONDS = 20;

    /** The segment length. At 720p veryslow on one thread it takes well past the window. */
    private const SEGMENT_SECONDS = 12;

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
            . ' -t ' . (self::SEGMENT_SECONDS + 2) . ' -c:v libx264 -preset ultrafast -pix_fmt yuv420p '
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
        // A 20 s stall window, shorter than this encode. A 120 s wall cap.
        $registry->configureWatchdog((float) self::STALL_WINDOW_SECONDS, 120.0);
        $runner->setSegmentProcessRegistry($registry);

        $final = $this->dir . '/seg-v720p-00000.m4s';
        $launched = hrtime(true);
        $pid = $runner->startSegmentEncode(
            $source,
            $final,
            0.0,
            (float) self::SEGMENT_SECONDS,
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

        // Drive the watchdog at the same cadence as the timer, until the segment lands. The
        // deadline is generous, so a loaded host finishes the encode and does not fail here.
        $signalled = 0;
        $deadline = hrtime(true) + 150 * self::SEC;
        while (!is_file($final) && hrtime(true) < $deadline) {
            usleep(100_000);
            $signalled += $registry->sweep();
        }
        $elapsedSeconds = (hrtime(true) - $launched) / self::SEC;

        self::assertGreaterThan(
            self::STALL_WINDOW_SECONDS,
            $elapsedSeconds,
            'the encode must outlast the stall window, or this test proves nothing about the progress signal',
        );
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
