<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\FfmpegRunner;

/**
 * perf #10 (launch part): {@see FfmpegRunner::startSegmentEncode()} launches its
 * encode through {@see FfmpegRunner::buildDetachedSegmentCommand()} and must still
 * leave the encode running detached after the call returns, returning the same
 * pid / log / `.part` semantics. These tests drive the REAL launch path with a fake
 * ffmpeg (a shell script that writes its output argument) and prove the publish
 * happens after the call has returned, on both the CLI (blocking shell_exec) branch
 * and, when ext-swoole is available, the coroutine (Swoole async exec) branch.
 */
final class FfmpegRunnerDetachedLaunchTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/phlix-detached-launch-' . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
            $this->fail('Could not create temp dir for the detached-launch test');
        }
        $this->dir = $dir;
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            foreach (glob($this->dir . '/*') ?: [] as $entry) {
                @unlink($entry);
            }
            @rmdir($this->dir);
        }
        $this->dir = '';
    }

    /**
     * A fake ffmpeg: writes a marker to its LAST argument (the output path the
     * segment command names last), then exits 0. The atomic-publish chain then
     * moves that temp to the final segment path.
     */
    private function fakeFfmpeg(): string
    {
        $bin = $this->dir . '/fake-ffmpeg';
        file_put_contents(
            $bin,
            "#!/bin/sh\nfor a; do last=\$a; done\nprintf 'segment-bytes' > \"\$last\"\nexit 0\n",
        );
        chmod($bin, 0755);

        return $bin;
    }

    private function runner(): FfmpegRunner
    {
        return new FfmpegRunner($this->fakeFfmpeg(), '/usr/bin/ffprobe', $this->dir);
    }

    private function waitForFile(string $path, float $seconds): bool
    {
        $deadline = hrtime(true) + (int) ($seconds * 1_000_000_000);
        while (hrtime(true) < $deadline) {
            if (is_file($path)) {
                return true;
            }
            usleep(20_000);
        }

        return is_file($path);
    }

    public function testCliLaunchReturnsAPidAndTheEncodePublishesAfterTheCallReturns(): void
    {
        $outFile = $this->dir . '/seg-v720p-00042.ts';

        $pid = $this->runner()->startSegmentEncode(
            '/media/in.mkv',
            $outFile,
            252.0,
            6.0,
            ['video_codec' => 'copy', 'audio_codec' => 'copy'],
        );

        // The launch returns the detached group leader's pid (the setsid PID).
        self::assertGreaterThan(0, $pid, 'launch returns the spawned pid');

        // The encode is detached: it publishes the final segment on its own, after
        // the synchronous call has already returned to us.
        self::assertTrue($this->waitForFile($outFile, 15.0), 'detached encode published the segment');
        self::assertSame('segment-bytes', file_get_contents($outFile));

        // The per-directory log is the same sink as before.
        self::assertFileExists($this->dir . '/ffmpeg-segments.log');

        // No `.part` temp is left behind after a successful publish.
        self::assertSame([], glob($outFile . '.part-*') ?: [], 'no temp survives a successful publish');
    }

    public function testCoroutineLaunchReturnsAPidAndTheEncodePublishes(): void
    {
        if (!extension_loaded('swoole') || !class_exists(\Swoole\Coroutine::class)) {
            $this->markTestSkipped('ext-swoole not loaded; coroutine launch branch not exercisable');
        }

        \Swoole\Coroutine::set(['log_level' => SWOOLE_LOG_ERROR, 'trace_flags' => 0]);

        $outFile = $this->dir . '/seg-v720p-00043.ts';
        $pid = 0;
        $cid = -1;
        $runner = $this->runner();

        \Swoole\Coroutine\run(function () use ($runner, $outFile, &$pid, &$cid): void {
            $cid = \Swoole\Coroutine::getCid();
            $pid = $runner->startSegmentEncode(
                '/media/in.mkv',
                $outFile,
                258.0,
                6.0,
                ['video_codec' => 'copy', 'audio_codec' => 'copy'],
            );
        });

        self::assertGreaterThan(0, $cid, 'test must run inside a coroutine (getCid() > 0)');
        self::assertGreaterThan(0, $pid, 'coroutine launch returns the spawned pid');
        self::assertTrue($this->waitForFile($outFile, 15.0), 'detached encode published the segment');
    }

    /**
     * Item 5: startDetached() treats an empty or whitespace-only launch result as a failed launch, with
     * the same log line and a zero pid, as launchDetachedSegment() does. The real shell always echoes the
     * fork's pid, so the shell output is stubbed at runShellCapture(). A real pid still comes back.
     */
    public function testAnEmptyDetachedLaunchOutputIsAFailedLaunchAndIsLogged(): void
    {
        $logger = new ProbeCacheRecordingLogger();
        $runner = new class ('/usr/bin/ffmpeg', '/usr/bin/ffprobe', $this->dir, $logger) extends FfmpegRunner {
            /** The stubbed shell output: a string, or null for a shell that failed to run. */
            public ?string $output = null;

            protected function runShellCapture(string $cmd): ?string
            {
                return $this->output;
            }
        };

        $runner->output = '';
        self::assertSame(0, $runner->startDetached('true', $this->dir), 'empty output is a failed launch');

        $runner->output = "  \n";
        self::assertSame(0, $runner->startDetached('true', $this->dir), 'whitespace-only output is a failed launch');

        $runner->output = null;
        self::assertSame(0, $runner->startDetached('true', $this->dir), 'a shell that did not run is a failed launch');

        $line = 'Failed to launch detached transcode';
        self::assertSame(
            [$line, $line, $line],
            $logger->messages('error'),
            'each failed launch logs the same line as launchDetachedSegment()'
        );

        $runner->output = "4242\n";
        self::assertSame(4242, $runner->startDetached('true', $this->dir), 'a real pid is returned');
        self::assertCount(3, $logger->messages('error'), 'a real launch logs no error');
    }
}
