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
 * The encode is a short 640x360 `veryslow` one-thread segment of a generated test
 * pattern, so the run stays well under half a minute. The stall window is set to 3 s,
 * far tighter than production (30 s). The encode writes no bytes to its temp family
 * until the end, so the old byte-size signal sees 3 s of silence and reaps it. The
 * progress file keeps the encode alive. Skipped when ffmpeg is not installed.
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
            . ' -f lavfi -i ' . escapeshellarg('testsrc2=size=640x360:rate=25')
            . ' -t 8 -c:v libx264 -preset ultrafast -pix_fmt yuv420p '
            . escapeshellarg($source) . ' < /dev/null',
            $out,
            $rc,
        );
        if ($rc !== 0 || !is_file($source)) {
            self::markTestSkipped('could not generate the test pattern');
        }

        $runner = new FfmpegRunner($ffmpeg, '/usr/bin/ffprobe', $this->dir);
        $runner->setSegmentEncodeThreads(1);
        $registry = new SegmentProcessRegistry();
        $registry->configureWatchdog(3.0, 60.0);
        $runner->setSegmentProcessRegistry($registry);

        $final = $this->dir . '/seg-v360p-00000.m4s';
        $pid = $runner->startSegmentEncode(
            $source,
            $final,
            0.0,
            6.0,
            [
                'video_codec' => 'libx264',
                'preset' => 'veryslow',
                'crf' => 23,
                'width' => 640,
                'height' => 360,
                'pix_fmt' => 'yuv420p',
                'profile' => 'high',
                'level' => '4.1',
                'audio_codec' => 'aac',
                'audio_bitrate' => '128k',
                'audio_channels' => 2,
                'segment_format' => 'fmp4',
                'init_file' => $this->dir . '/init-v360p.m4s',
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
