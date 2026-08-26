<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Markers;

use Phlix\Media\Markers\ChapterMarkerService;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Tests\Support\Coroutine\RunsInCoroutine;
use PHPUnit\Framework\TestCase;

/**
 * S196 — the `ChapterMarkerService::runCommand()` coroutine fork on both arms.
 *
 * `runCommand()` executes via `Swoole\Coroutine\System::exec()` inside a
 * coroutine and `shell_exec()` outside one. The class had NO test file at
 * all, so the coroutine arm (the one a production worker's chapter
 * extraction executes) was unexecuted by the suite.
 *
 * Branch identity is OBSERVED through a measured, deterministic difference
 * between the two arms for the same input: an unexecutable command yields
 * `['code' => 127, 'output' => '']` from `Coroutine\System::exec` (measured
 * 2026-08-25 on swoole 6.2.1/PHP 8.3.6), which runCommand maps to `''`,
 * while `shell_exec()` yields `null`. That divergence is benign for the only
 * caller (`extractFromFile()` treats `null` and `''` identically) and is
 * FILED as S403 (allocation requested with this PR) in the fork inventory
 * rather than patched, because no code-based rule can make the arms identical
 * for every input without inventing a new contract (a real command may
 * legitimately exit 127, and `shell_exec` returns output even for non-zero
 * exit codes).
 */
final class ChapterMarkerServiceCoroutineForkTest extends TestCase
{
    use RunsInCoroutine;

    private function buildService(): ChapterMarkerService
    {
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->method('getFfprobePath')->willReturn('/bin/echo');

        return new ChapterMarkerService($ffmpeg);
    }

    /**
     * INSIDE a real coroutine, runCommand() must take the
     * Coroutine\System::exec arm: an unexecutable command surfaces as '' (the
     * exec array's empty output), NOT null.
     */
    public function testCoroutineArmRunsExecAndReportsEmptyOutput(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to execute the coroutine branch.');
        }

        $service = $this->buildService();
        $runCommand = new \ReflectionMethod(ChapterMarkerService::class, 'runCommand');
        $runCommand->setAccessible(true);

        $output = $this->runInCoroutine(
            fn () => $runCommand->invoke($service, 'definitely-not-a-real-command-12345 2>/dev/null')
        );

        $this->assertSame('', $output, 'the exec arm must report the empty output of an unexecutable command');
    }

    /**
     * INSIDE a real coroutine, a working command's output must flow back
     * through the exec arm unchanged.
     */
    public function testCoroutineArmReturnsCapturedStdout(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to execute the coroutine branch.');
        }

        $service = $this->buildService();
        $runCommand = new \ReflectionMethod(ChapterMarkerService::class, 'runCommand');
        $runCommand->setAccessible(true);

        $output = $this->runInCoroutine(
            fn () => $runCommand->invoke($service, "echo '{\"chapters\":[]}'")
        );

        $this->assertIsString($output);
        $this->assertStringContainsString('chapters', $output);
    }

    /**
     * OUTSIDE a coroutine the same unexecutable command must take the
     * shell_exec arm: null, the documented failure shape.
     */
    public function testBlockingArmUsesShellExecAndReportsNull(): void
    {
        $service = $this->buildService();
        $runCommand = new \ReflectionMethod(ChapterMarkerService::class, 'runCommand');
        $runCommand->setAccessible(true);

        $output = $runCommand->invoke($service, 'definitely-not-a-real-command-12345 2>/dev/null');

        $this->assertNull($output, 'the shell_exec arm must report null for an unexecutable command');
    }
}
