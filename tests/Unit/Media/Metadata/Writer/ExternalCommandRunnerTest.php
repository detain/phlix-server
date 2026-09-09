<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata\Writer;

use Phlix\Media\Metadata\Writer\ExecExternalCommandRunner;
use Phlix\Media\Metadata\Writer\ExternalCommandRunnerInterface;
use PHPUnit\Framework\TestCase;

/**
 * S89 — the exec seam runs REAL processes. Hand-written exit codes would
 * certify only the fake's shape (S345 rule 2), so every arm here invokes the
 * actual /bin/sh: success, failure, stderr separation, and the temp-file
 * hygiene the ZeroResidueCensus will enforce suite-wide.
 */
final class ExternalCommandRunnerTest extends TestCase
{
    private ExecExternalCommandRunner $runner;

    protected function setUp(): void
    {
        $this->runner = new ExecExternalCommandRunner();
    }

    public function test_it_is_the_interface_implementation(): void
    {
        $this->assertInstanceOf(ExternalCommandRunnerInterface::class, $this->runner);
    }

    public function test_a_successful_command_reports_zero_and_captures_stdout(): void
    {
        $result = $this->runner->run('/bin/echo runner-probe');

        $this->assertSame(0, $result['exitCode']);
        $this->assertSame('runner-probe', trim($result['stdout']));
    }

    public function test_a_failing_command_reports_its_real_exit_code(): void
    {
        // Negative control for the whole seam: an implementation that always
        // reported 0 would make every interrupted-remux assertion downstream
        // vacuous. /bin/false exits 1 for real.
        $result = $this->runner->run('/bin/false');

        $this->assertNotSame(0, $result['exitCode']);
        $this->assertSame(1, $result['exitCode']);
    }

    public function test_stderr_is_captured_on_its_own_channel_not_merged_into_stdout(): void
    {
        // The remux failure diagnostics the writer surfaces come from stderr;
        // a `2>&1` merge would garble them, a dropped redirect would lose them.
        $result = $this->runner->run("/bin/sh -c 'echo to-err 1>&2; echo to-out'");

        $this->assertSame(0, $result['exitCode']);
        $this->assertStringContainsString('to-err', $result['stderr']);
        $this->assertStringContainsString('to-out', $result['stdout']);
        $this->assertStringNotContainsString('to-err', $result['stdout']);
    }

    public function test_a_killed_process_surfaces_the_shell_signal_exit_shape(): void
    {
        // 128+SIGTERM(15)=143: the exit-code half of "interrupted remux" as an
        // ACTUAL signal death of an ACTUAL binary (the write-side half —
        // partial temp, original intact — is pinned in EmbeddedMetadataWriterTest
        // and the real-tool suite; this seam cannot produce that alone).
        $result = $this->runner->run("/bin/sh -c 'kill -TERM \$\$'");

        $this->assertSame(143, $result['exitCode']);
    }

    public function test_the_stderr_redirect_file_is_cleaned_up_after_every_call(): void
    {
        // finally-unlink discipline. The /tmp/phlix_* residue census covers the
        // suite run, but it would PASS on a stale baseline entry — this is the
        // dedicated positive control for the runner's own temp file.
        $before = glob(sys_get_temp_dir() . '/phlix_execcmd_*') ?: [];
        $this->runner->run('/bin/echo hygiene');
        $this->runner->run('/bin/false');
        $after = glob(sys_get_temp_dir() . '/phlix_execcmd_*') ?: [];

        $this->assertSame($before, $after);
    }
}
