<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use Phlix\Media\Transcoding\FfmpegRunner;
use PHPUnit\Framework\TestCase;

/**
 * The exit code the Swoole exec path reports for a finished process.
 *
 * A process killed by a signal can come back with code 0 and a non-zero signal. A
 * killed trickplay pass that wrote 60 framemd5 lines would then pass the slot gate, so
 * the signal must count as failure. The result arrays here are injected, the way
 * System::exec() would return them, so no Swoole runtime is needed.
 */
final class FfmpegRunnerSignalExitTest extends TestCase
{
    public function testACleanExitWithNoSignalIsZero(): void
    {
        $this->assertSame(0, FfmpegRunner::exitCodeFromSystemExec(['code' => 0, 'signal' => 0, 'output' => '']));
    }

    public function testAKillBySignalReportedAsCodeZeroIsAFailure(): void
    {
        // The case that mattered: code 0 with a signal. Shell convention gives 128 + 9.
        $exit = FfmpegRunner::exitCodeFromSystemExec(['code' => 0, 'signal' => 9, 'output' => '']);

        $this->assertNotSame(0, $exit);
        $this->assertSame(137, $exit);
    }

    public function testASignalWithANonZeroCodeKeepsTheReportedCode(): void
    {
        $this->assertSame(1, FfmpegRunner::exitCodeFromSystemExec(['code' => 1, 'signal' => 15, 'output' => '']));
    }

    public function testANonZeroCodeWithoutASignalIsReturnedAsIs(): void
    {
        $this->assertSame(251, FfmpegRunner::exitCodeFromSystemExec(['code' => 251, 'signal' => 0]));
    }

    public function testAResultWithNoCodeIsAFailure(): void
    {
        $this->assertSame(1, FfmpegRunner::exitCodeFromSystemExec([]));
    }

    public function testAnAbsentSignalKeyIsTreatedAsNoSignal(): void
    {
        $this->assertSame(0, FfmpegRunner::exitCodeFromSystemExec(['code' => 0]));
    }
}
