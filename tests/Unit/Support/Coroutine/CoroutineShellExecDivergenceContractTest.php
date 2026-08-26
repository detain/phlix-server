<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Support\Coroutine;

use Phlix\Media\Markers\ChapterMarkerService;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Tests\Support\Coroutine\RunsInCoroutine;
use PHPUnit\Framework\TestCase;

/**
 * S403 — contract: the three-arm shape on an UNEXECUTABLE command is PINNED.
 *
 * ## THE DIVERGENCE, MEASURED (PHP 8.3.6 / swoole 6.2.1, 2026-08-26, this box)
 *
 * For a command that cannot be executed (e.g. `/nonexistent/phlix-binary`,
 * or `definitely-not-a-real-command-12345 2>/dev/null`):
 *
 *   Arm                                    Inside a coroutine   Main stack
 *   ---------------------------------------------------------------------
 *   Swoole\Coroutine\System::exec          array{code,output}   (not used)
 *                                          code=127, output=''
 *   shell_exec                             (not used)           null
 *   FfmpegRunner::runCoroutineAwareShellExec   ''               null
 *   ChapterMarkerService::runCommand           ''               null
 *
 * The three arms therefore disagree in SHAPE: the coroutine exec arm surfaces
 * the failure as an ARRAY with an empty-string `output` (which the wrappers
 * map to `''`), while the blocking `shell_exec` arm surfaces it as `null`.
 * No code-based rule can align them for every input without inventing a new
 * contract — a real command may legitimately exit 127, and `shell_exec`
 * returns output even for non-zero exit codes — so S403's decision is
 * **PINNED**: the divergence is documented here and benign for all current
 * callers (both S196 records — the fork inventory docblock and the
 * ChapterMarkerService fork test — reference this test by name).
 *
 * ## WHAT THIS TEST PINS — SHAPES AND INVARIANTS, NOT EXACT VALUES (S169/S170)
 *
 * Per the errCode lesson: the exit code (127 here) may vary across swoole
 * builds, so no assertion pins a concrete code. Asserted instead:
 *   - exec arm  → array carrying `code` (int, non-zero) and `output`
 *     (string, '' under the `2>/dev/null` redirect) keys;
 *   - shell_exec arm → null;
 *   - both wrappers → '' inside a coroutine, null on the main stack.
 *
 * Any of those drifting (exec returning false, output becoming null, the
 * wrapper normalising '' to null, ...) turns the named test red.
 */
final class CoroutineShellExecDivergenceContractTest extends TestCase
{
    use RunsInCoroutine;

    /**
     * Unexecutable on every box: no such binary, stderr discarded so the
     * shell's "not found" message cannot leak into any captured output.
     */
    private const UNEXECUTABLE = 'definitely-not-a-real-command-12345 2>/dev/null';

    /**
     * The exec arm: inside a coroutine an unexecutable command must surface
     * as an ARRAY with `code` + `output` keys — never false, never null.
     * `code` is asserted as a non-zero int (any swoole build agrees the
     * command failed); `output` is asserted as '' (the pinned divergence vs
     * shell_exec's null — the `2>/dev/null` redirect makes stdout empty).
     */
    public function testCoroutineExecArmReturnsFailureArrayWithCodeAndOutputKeys(): void
    {
        $result = $this->runInCoroutine(
            static fn (): mixed => \Swoole\Coroutine\System::exec(self::UNEXECUTABLE)
        );

        $this->assertIsArray($result, 'the exec arm must return the failure array, not false/null');
        $this->assertArrayHasKey('code', $result, 'the documented exec shape carries a code key');
        $this->assertIsInt($result['code'], 'code must be an int');
        $this->assertNotSame(0, $result['code'], 'an unexecutable command cannot exit 0');
        $this->assertArrayHasKey('output', $result, 'the documented exec shape carries an output key');
        $this->assertIsString($result['output'], 'output must be a string');
        $this->assertSame('', $result['output'], 'the empty-string output IS the pinned divergence');
    }

    /**
     * The shell_exec arm: on the main stack the same command must surface as
     * null — the documented blocking-arm failure shape.
     */
    public function testShellExecArmReturnsNullOnMainStack(): void
    {
        $output = shell_exec(self::UNEXECUTABLE);

        $this->assertNull($output, 'the shell_exec arm must report null for an unexecutable command');
    }

    /**
     * The FfmpegRunner wrapper arm, inside a coroutine: it maps the exec
     * array's empty output to '' — pinned, because the main stack maps the
     * same command to null (next test).
     */
    public function testCoroutineAwareShellExecReturnsEmptyStringInsideCoroutine(): void
    {
        $runner = new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/tmp');
        $method = new \ReflectionMethod(FfmpegRunner::class, 'runCoroutineAwareShellExec');
        $method->setAccessible(true);

        $output = $this->runInCoroutine(
            static fn (): mixed => $method->invoke($runner, self::UNEXECUTABLE)
        );

        $this->assertSame('', $output, 'the coroutine wrapper arm must report the empty exec output');
    }

    /**
     * The FfmpegRunner wrapper arm, main stack: the shell_exec fallback's
     * null flows through unchanged.
     */
    public function testCoroutineAwareShellExecReturnsNullOnMainStack(): void
    {
        $runner = new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/tmp');
        $method = new \ReflectionMethod(FfmpegRunner::class, 'runCoroutineAwareShellExec');
        $method->setAccessible(true);

        $output = $method->invoke($runner, self::UNEXECUTABLE);

        $this->assertNull($output, 'the main-stack wrapper arm must report null');
    }

    /**
     * ChapterMarkerService::runCommand, inside a coroutine: '' (its exec-arm
     * mapping), never null — the same divergence the fork test observes.
     */
    public function testRunCommandReturnsEmptyStringInsideCoroutine(): void
    {
        $service = new ChapterMarkerService($this->createMock(FfmpegRunner::class));
        $method = new \ReflectionMethod(ChapterMarkerService::class, 'runCommand');
        $method->setAccessible(true);

        $output = $this->runInCoroutine(
            static fn (): mixed => $method->invoke($service, self::UNEXECUTABLE)
        );

        $this->assertSame('', $output, 'the coroutine runCommand arm must report the empty exec output');
    }

    /**
     * ChapterMarkerService::runCommand, main stack: null via shell_exec.
     */
    public function testRunCommandReturnsNullOnMainStack(): void
    {
        $service = new ChapterMarkerService($this->createMock(FfmpegRunner::class));
        $method = new \ReflectionMethod(ChapterMarkerService::class, 'runCommand');
        $method->setAccessible(true);

        $output = $method->invoke($service, self::UNEXECUTABLE);

        $this->assertNull($output, 'the main-stack runCommand arm must report null');
    }

    /**
     * FfmpegRunner::runProbeCommand, inside a coroutine: '' — the fork
     * inventory docblock records it alongside the wrapper, so the contract
     * pins it too (same exec-arm mapping).
     */
    public function testRunProbeCommandReturnsEmptyStringInsideCoroutine(): void
    {
        $runner = new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/tmp');
        $method = new \ReflectionMethod(FfmpegRunner::class, 'runProbeCommand');
        $method->setAccessible(true);

        $output = $this->runInCoroutine(
            static fn (): mixed => $method->invoke($runner, self::UNEXECUTABLE)
        );

        $this->assertSame('', $output, 'the coroutine probe arm must report the empty exec output');
    }

    /**
     * FfmpegRunner::runProbeCommand, main stack: null via shell_exec.
     */
    public function testRunProbeCommandReturnsNullOnMainStack(): void
    {
        $runner = new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/tmp');
        $method = new \ReflectionMethod(FfmpegRunner::class, 'runProbeCommand');
        $method->setAccessible(true);

        $output = $method->invoke($runner, self::UNEXECUTABLE);

        $this->assertNull($output, 'the main-stack probe arm must report null');
    }
}
