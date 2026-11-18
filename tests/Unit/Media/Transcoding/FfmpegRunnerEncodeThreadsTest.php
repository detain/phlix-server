<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\FfmpegRunner;

/**
 * Per-launch libx264 `-threads` override (`encode_threads` segment param) and the
 * fixed-8 default that config/ffmpeg.php ships for `segment_encode_threads`.
 *
 * The runner is a subclass that records the shell lines it would run, so no ffmpeg or
 * ffprobe process is started. The probe returns null, which means "no tone-map", so the
 * command under test is the plain software libx264 line.
 */
final class FfmpegRunnerEncodeThreadsTest extends TestCase
{
    private const THREADS_ENV = 'PHLIX_SEGMENT_ENCODE_THREADS';

    protected function tearDown(): void
    {
        putenv(self::THREADS_ENV);
    }

    /**
     * @return array<string, mixed>
     */
    private function videoParams(): array
    {
        return [
            'video_codec' => 'libx264',
            'preset' => 'veryfast',
            'crf' => 23,
            'maxrate' => 3000000,
            'bufsize' => 6000000,
            'width' => 1280,
            'height' => 720,
        ];
    }

    private function runnerWithConfiguredThreads(?int $configured): RecordingRunner
    {
        $runner = new RecordingRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/tmp/segs');
        // Decode cap held off so the only `-threads` in the line is the encode cap.
        $runner->setSegmentDecodeThreads(0);
        $runner->setSegmentEncodeThreads($configured);

        return $runner;
    }

    private function buildWith(FfmpegRunner $runner, array $extra = []): string
    {
        return $runner->buildSegmentCommand(
            '/media/in.mkv',
            '/tmp/segs/job1/seg-v720p-00042.ts',
            252.0,
            6.0,
            array_merge($this->videoParams(), $extra)
        );
    }

    public function testAbsentEncodeThreadsKeyUsesTheConfiguredValue(): void
    {
        $cmd = $this->buildWith($this->runnerWithConfiguredThreads(4));

        self::assertSame(1, substr_count($cmd, '-threads'));
        self::assertStringContainsString(' -threads 4 ', $cmd);
    }

    public function testPresentEncodeThreadsOverridesTheConfiguredValue(): void
    {
        $cmd = $this->buildWith($this->runnerWithConfiguredThreads(4), ['encode_threads' => 6]);

        self::assertSame(1, substr_count($cmd, '-threads'));
        self::assertStringContainsString(' -threads 6 ', $cmd);
        self::assertStringNotContainsString(' -threads 4 ', $cmd);
    }

    public function testPresentEncodeThreadsOverridesAConfiguredDefaultOfEight(): void
    {
        // The shipped default is 8; a per-launch 2 lowers it for that launch only.
        $runner = $this->runnerWithConfiguredThreads(8);
        $lowered = $this->buildWith($runner, ['encode_threads' => 2]);
        $unchanged = $this->buildWith($runner);

        self::assertStringContainsString(' -threads 2 ', $lowered);
        self::assertStringContainsString(' -threads 8 ', $unchanged);
    }

    public function testPresentEncodeThreadsOverridesWhenTheConfiguredFlagIsOff(): void
    {
        // A positive per-launch value is honoured even when the configured cap is disabled.
        $cmd = $this->buildWith($this->runnerWithConfiguredThreads(0), ['encode_threads' => 5]);

        self::assertStringContainsString(' -threads 5 ', $cmd);
    }

    public function testZeroEncodeThreadsFallsBackToTheConfiguredValue(): void
    {
        $cmd = $this->buildWith($this->runnerWithConfiguredThreads(4), ['encode_threads' => 0]);

        self::assertSame(1, substr_count($cmd, '-threads'));
        self::assertStringContainsString(' -threads 4 ', $cmd);
    }

    public function testNegativeEncodeThreadsFallsBackToTheConfiguredValue(): void
    {
        $cmd = $this->buildWith($this->runnerWithConfiguredThreads(4), ['encode_threads' => -3]);

        self::assertSame(1, substr_count($cmd, '-threads'));
        self::assertStringContainsString(' -threads 4 ', $cmd);
    }

    public function testZeroEncodeThreadsDropsTheFlagWhenTheConfiguredValueIsAlsoOff(): void
    {
        $cmd = $this->buildWith($this->runnerWithConfiguredThreads(0), ['encode_threads' => 0]);

        self::assertStringNotContainsString('-threads', $cmd);
    }

    public function testEncodeThreadsAboveSixtyFourIsClampedToSixtyFour(): void
    {
        $cmd = $this->buildWith($this->runnerWithConfiguredThreads(4), ['encode_threads' => 1000]);

        self::assertStringContainsString(' -threads 64 ', $cmd);
        self::assertStringNotContainsString('-threads 1000', $cmd);
    }

    public function testEncodeThreadsOfOneIsKeptAsOne(): void
    {
        $cmd = $this->buildWith($this->runnerWithConfiguredThreads(4), ['encode_threads' => 1]);

        self::assertStringContainsString(' -threads 1 ', $cmd);
    }

    public function testNumericStringEncodeThreadsIsRead(): void
    {
        $cmd = $this->buildWith($this->runnerWithConfiguredThreads(4), ['encode_threads' => '7']);

        self::assertStringContainsString(' -threads 7 ', $cmd);
    }

    public function testEncodeThreadsIsIgnoredOnTheHevcBranch(): void
    {
        $cmd = $this->buildWith(
            $this->runnerWithConfiguredThreads(4),
            ['video_codec' => 'libx265', 'encode_threads' => 6]
        );

        self::assertStringContainsString('-c:v libx265', $cmd);
        self::assertStringNotContainsString('-threads', $cmd);
    }

    public function testEncodeThreadsIsIgnoredOnTheStreamCopyBranch(): void
    {
        $cmd = $this->buildWith(
            $this->runnerWithConfiguredThreads(4),
            ['video_codec' => 'copy', 'encode_threads' => 6]
        );

        self::assertStringContainsString('-c:v copy', $cmd);
        self::assertStringNotContainsString('-threads', $cmd);
    }

    public function testShippedConfigDefaultIsEight(): void
    {
        putenv(self::THREADS_ENV);

        /** @var array<string, mixed> $config */
        $config = require dirname(__DIR__, 4) . '/config/ffmpeg.php';

        self::assertSame(8, $config['segment_encode_threads']);
        // config/ffmpeg.php cannot import the runner, so its literal must match the runner constant.
        self::assertSame(FfmpegRunner::DEFAULT_SEGMENT_ENCODE_THREADS, $config['segment_encode_threads']);
    }

    public function testShippedConfigDefaultHonoursTheEnvOverrideAndZeroDisables(): void
    {
        putenv(self::THREADS_ENV . '=3');
        /** @var array<string, mixed> $tuned */
        $tuned = require dirname(__DIR__, 4) . '/config/ffmpeg.php';
        self::assertSame(3, $tuned['segment_encode_threads']);

        putenv(self::THREADS_ENV . '=0');
        /** @var array<string, mixed> $off */
        $off = require dirname(__DIR__, 4) . '/config/ffmpeg.php';
        self::assertSame(0, $off['segment_encode_threads']);
    }

    public function testDefaultConstantIsEight(): void
    {
        self::assertSame(8, FfmpegRunner::DEFAULT_SEGMENT_ENCODE_THREADS);
    }
}

/**
 * Records every shell line the runner would launch and answers probes with "no output".
 */
final class RecordingRunner extends FfmpegRunner
{
    /** @var list<string> */
    public array $commands = [];

    protected function runShellCapture(string $cmd): ?string
    {
        $this->commands[] = $cmd;

        return null;
    }
}
