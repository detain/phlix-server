<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\FfmpegRunner;

/**
 * probe() runs its ffprobe under `timeout -k 2 10`, the same wrapper the segment launcher
 * uses. A killed or wedged probe must read as the existing failed-probe result (null) and
 * never raise. The shell is replaced by a recording seam, so no real ffprobe is started.
 */
final class FfmpegRunnerProbeTimeoutTest extends TestCase
{
    public function testProbeCommandIsPrefixedWithTheTimeoutWrapper(): void
    {
        $runner = new ProbeRecordingRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/tmp');
        $runner->probe('/media/some file.mkv');

        self::assertCount(1, $runner->commands);
        $cmd = $runner->commands[0];

        self::assertStringStartsWith("timeout -k 2 10 '/usr/bin/ffprobe' -v quiet", $cmd);
        self::assertStringContainsString("-show_format -show_streams '/media/some file.mkv'", $cmd);
        self::assertStringEndsWith('2>/dev/null', $cmd);
    }

    public function testTheTimeoutPrefixComesBeforeTheProbeBinary(): void
    {
        $runner = new ProbeRecordingRunner('/usr/bin/ffmpeg', '/opt/ff/ffprobe', '/tmp');
        $runner->probe('/x.mkv');

        $cmd = $runner->commands[0];
        $prefix = 'timeout -k 2 10 ';
        self::assertSame(0, strpos($cmd, $prefix));
        // The binary starts exactly where the wrapper prefix ends.
        self::assertSame(strlen($prefix), strpos($cmd, "'/opt/ff/ffprobe'"));
    }

    public function testEmptyOutputFromAKilledProbeReadsAsNull(): void
    {
        // A `timeout` kill leaves stdout empty: the shell returns '' (Swoole) or null (shell_exec).
        $runner = new ProbeRecordingRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/tmp', '');

        self::assertNull($runner->probe('/killed.mkv'));
    }

    public function testPartialJsonFromAKilledProbeReadsAsNull(): void
    {
        $runner = new ProbeRecordingRunner(
            '/usr/bin/ffmpeg',
            '/usr/bin/ffprobe',
            '/tmp',
            '{"streams":[{"codec_type":"vid'
        );

        self::assertNull($runner->probe('/partial.mkv'));
    }

    public function testMissingProbeBinaryReadsAsNull(): void
    {
        // Proves only the null mapping for a missing binary: the real shell runs a nonexistent
        // ffprobe, which exits non-zero with empty stdout, and parseProbeOutput() maps that to null.
        // This does not verify the timeout wrapper; the command-prefix tests above cover that.
        $runner = new FfmpegRunner('/usr/bin/ffmpeg', '/nonexistent/ffprobe', '/tmp');

        self::assertNull($runner->probe('/whatever.mkv'));
    }
}

/**
 * Returns a fixed stdout for every shell line and records the command it was given.
 */
final class ProbeRecordingRunner extends FfmpegRunner
{
    /** @var list<string> */
    public array $commands = [];

    public function __construct(
        string $ffmpegPath,
        string $ffprobePath,
        string $transcodeDir,
        private readonly ?string $stdout = null,
    ) {
        parent::__construct($ffmpegPath, $ffprobePath, $transcodeDir);
    }

    protected function runShellCapture(string $cmd): ?string
    {
        $this->commands[] = $cmd;

        return $this->stdout;
    }
}
