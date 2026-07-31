<?php

namespace Phlix\Tests\Unit\LiveTv;

use PHPUnit\Framework\TestCase;
use Phlix\LiveTv\ComskipRunner;

/**
 * @since 0.12.0
 */
class ComskipRunnerTest extends TestCase
{
    /** @var string Test-owned scratch directory holding every fixture this class writes */
    private string $tempDir;

    /** @var string Path to the fake comskip binary used by the isAvailable() tests */
    private string $fakeComskipPath;

    /**
     * @var string|null Why fixture scripts cannot be executed here, or null if they can.
     *                  Probed once per process — see {@see self::requireExecutableFixtures()}.
     */
    private static ?string $execFailure = null;

    /** @var bool Whether the exec probe has run yet in this process */
    private static bool $execProbed = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixtures live in a directory this class owns, not directly in the shared
        // temp root: unique per test, removed in tearDown, and never colliding with
        // a sibling test's leftovers.
        $this->tempDir = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'phlix_comskip_test_'
            . bin2hex(random_bytes(8));

        if (!mkdir($this->tempDir, 0700) && !is_dir($this->tempDir)) {
            $this->fail("Unable to create test temp dir: {$this->tempDir}");
        }

        $this->fakeComskipPath = $this->tempDir . DIRECTORY_SEPARATOR . 'fake_comskip';
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
        parent::tearDown();
    }

    /**
     * Delete the test-owned temp dir and everything in it.
     */
    private function removeTempDir(): void
    {
        if (!is_dir($this->tempDir)) {
            return;
        }

        $entries = scandir($this->tempDir);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            @unlink($this->tempDir . DIRECTORY_SEPARATOR . $entry);
        }

        @rmdir($this->tempDir);
    }

    /**
     * Write an executable fake comskip script into the test-owned temp dir.
     *
     * @param string $name Base name for the script
     * @param string $body Full script contents, shebang included
     *
     * @return string Absolute path to the script
     */
    private function writeScript(string $name, string $body): string
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . $name . '.sh';
        file_put_contents($path, $body);
        chmod($path, 0755);

        return $path;
    }

    /**
     * Create an empty recording file inside the test-owned temp dir.
     *
     * ComskipRunner derives the EDL path from the recording path, so keeping the
     * recording here keeps the EDL here too.
     *
     * @return string Absolute path to the recording
     */
    private function makeRecording(): string
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'recording.ts';
        touch($path);

        return $path;
    }

    /**
     * Skip the calling test unless fixture scripts can actually be executed.
     *
     * The run() tests only mean anything if the fake comskip really runs, which
     * needs an exec-capable temp filesystem and a working /bin/bash. Where either
     * is missing (a noexec mount, a stripped container) the honest outcome is a
     * skip naming the cause, not a failure that looks like a ComskipRunner bug.
     */
    private function requireExecutableFixtures(): void
    {
        if (!self::$execProbed) {
            self::$execProbed = true;
            self::$execFailure = $this->probeExec();
        }

        if (self::$execFailure !== null) {
            $this->markTestSkipped(self::$execFailure);
        }
    }

    /**
     * Run a throwaway script the same way ComskipRunner does and report why it
     * could not run, if it could not.
     *
     * @return string|null Reason fixtures are not executable, or null if they are
     */
    private function probeExec(): ?string
    {
        $probe = $this->writeScript('exec_probe', "#!/bin/bash\nexit 7\n");

        $process = proc_open(
            escapeshellcmd($probe) . ' 2>&1',
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        if (!is_resource($process)) {
            return 'Cannot start subprocesses in this environment (proc_open failed).';
        }

        $output = '';
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                $output .= (string) stream_get_contents($pipe);
                fclose($pipe);
            }
        }

        $exitCode = proc_close($process);

        if ($exitCode === 7) {
            return null;
        }

        // 126 = found but not executable (typically a noexec mount);
        // 127 = interpreter missing (no /bin/bash).
        return sprintf(
            'Cannot execute fixture scripts from %s (exit code %d: %s). '
            . 'Needs an exec-capable temp filesystem and /bin/bash.',
            $this->tempDir,
            $exitCode,
            trim($output) === '' ? 'no output' : trim($output)
        );
    }

    public function testIsAvailableTrueWhenBinaryExists(): void
    {
        // Create a fake executable comskip
        file_put_contents($this->fakeComskipPath, '#!/bin/bash');
        chmod($this->fakeComskipPath, 0755);

        $runner = new ComskipRunner($this->fakeComskipPath);

        $this->assertTrue($runner->isAvailable());
    }

    public function testIsAvailableFalseWhenBinaryMissing(): void
    {
        $runner = new ComskipRunner('/nonexistent/comskip');

        $this->assertFalse($runner->isAvailable());
    }

    public function testIsAvailableFalseWhenBinaryNotExecutable(): void
    {
        // Create a file that is not executable
        file_put_contents($this->fakeComskipPath, '#!/bin/bash');
        chmod($this->fakeComskipPath, 0644);

        $runner = new ComskipRunner($this->fakeComskipPath);

        $this->assertFalse($runner->isAvailable());
    }

    public function testRunExecutesComskipAndReturnsEdlPath(): void
    {
        $this->requireExecutableFixtures();

        // Create a mock comskip that creates an EDL file with the correct name
        // The EDL file has same basename as recording but .edl extension
        $tempScript = $this->writeScript('comskip_mock', <<<'SCRIPT'
#!/bin/bash
# Get the recording path (last argument)
recording_path="${@: -1}"
# Derive EDL path: same directory, same basename, .edl extension
basename=$(basename "$recording_path" .ts)
edl_dir=$(dirname "$recording_path")
touch "$edl_dir/${basename}.edl"
exit 0
SCRIPT);

        $runner = new ComskipRunner($tempScript);
        $recordingPath = $this->makeRecording();

        $edlPath = $runner->run($recordingPath);

        $expectedEdlPath = substr($recordingPath, 0, -3) . '.edl';
        $this->assertEquals($expectedEdlPath, $edlPath);
        $this->assertFileExists($edlPath);
    }

    public function testRunThrowsWhenComskipFails(): void
    {
        $this->requireExecutableFixtures();

        $tempScript = $this->writeScript('comskip_fail', "#!/bin/bash\nexit 1\n");

        $runner = new ComskipRunner($tempScript);
        $recordingPath = $this->makeRecording();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Comskip failed with exit code 1');
        $runner->run($recordingPath);
    }

    /**
     * A failing comskip must be reported as a failure even when the read loop
     * ends by draining every pipe rather than by observing the exit.
     *
     * This is the ordering behind a long-running CI flake. The kernel closes a
     * dying child's descriptors before it makes the child reapable, so EOF can
     * arrive a scheduler slice ahead of proc_get_status() reporting the exit —
     * and the chunk-cap in the read loop force-closes pipes on its own. The loop
     * then falls out with no status ever observed. Assuming success there turned
     * "comskip exited 1" into the unrelated "EDL file not found" message.
     *
     * The window is a few microseconds wide, so it is forced here through the
     * processStatus() seam instead of being raced for: the status reads as still
     * running for long enough that the read loop cannot exit by observing it.
     */
    public function testRunReportsFailureWhenPipesDrainBeforeProcessIsReapable(): void
    {
        $this->requireExecutableFixtures();

        $tempScript = $this->writeScript('comskip_fail_drained', "#!/bin/bash\nexit 1\n");

        // The read loop needs only a couple of status checks for a process that
        // exits immediately, so a budget of 50 guarantees it never breaks on the
        // status and must leave through the drained-pipes path. The remaining
        // budget is then consumed by the post-loop wait at 10ms a poll — well
        // inside the 10s timeout, which stays generous so a slow CI box cannot
        // turn this into a timeout assertion.
        $runner = new StatusSeamComskipRunner($tempScript, 50, 10);
        $recordingPath = $this->makeRecording();

        try {
            $runner->run($recordingPath);
            $this->fail('Expected a RuntimeException: the fake comskip exited 1.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Comskip failed with exit code 1', $e->getMessage());
        }

        // Guard the test's own premise. Consuming more status reads than the
        // forced budget can only happen in the post-loop wait, which only runs
        // when the loop ended with its pipes drained and no status observed —
        // exactly the ordering under test. Without this the test could quietly
        // degrade into re-testing the ordinary path and still pass.
        $this->assertGreaterThan(
            50,
            $runner->statusReads(),
            'Expected the run to leave the read loop via the drained-pipes path.'
        );
    }

    /**
     * The post-loop wait for an exit status is itself bounded: a process that
     * closes its pipes but never reports an exit must hit the timeout rather
     * than spin forever.
     */
    public function testRunTimesOutWhenExitStatusNeverArrivesAfterPipesClose(): void
    {
        $this->requireExecutableFixtures();

        $tempScript = $this->writeScript('comskip_never_reaped', "#!/bin/bash\nexit 0\n");

        // Negative budget = the status always reads as running, so the exit is
        // never observable and only the deadline can end the run.
        $runner = new StatusSeamComskipRunner($tempScript, -1, 1);
        $recordingPath = $this->makeRecording();

        $start = hrtime(true);
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Comskip timed out after 1 seconds');
            $runner->run($recordingPath);
        } finally {
            $elapsedSeconds = (hrtime(true) - $start) / 1_000_000_000.0;
            $this->assertLessThan(10.0, $elapsedSeconds);
        }
    }

    public function testRunThrowsWhenRecordingNotFound(): void
    {
        // Use a valid comskip path (executable) but with a non-existent recording
        $tempScript = $this->writeScript('comskip_valid', "#!/bin/bash\nexit 0\n");

        $runner = new ComskipRunner($tempScript);
        $nonExistentPath = $this->tempDir . DIRECTORY_SEPARATOR . 'nonexistent_recording.ts';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Recording file not found');
        $runner->run($nonExistentPath);
    }

    public function testRunThrowsWhenComskipNotAvailable(): void
    {
        $runner = new ComskipRunner('/nonexistent/comskip');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Comskip is not available');
        $runner->run($this->tempDir . DIRECTORY_SEPARATOR . 'test.ts');
    }

    /**
     * SV-4.3 / SV-3.1d-comskip: a wedged comskip that never exits is SIGKILLed
     * at the configured timeout, and the run reports a bounded timeout error
     * rather than blocking forever. Uses a 1-second timeout override so the test
     * does not have to wait the production 300s.
     */
    public function testRunTimesOutAndKillsWedgedProcess(): void
    {
        $this->requireExecutableFixtures();

        // A fake comskip that sleeps far longer than the timeout, holding its
        // stdout/stderr pipes open with no output — the classic wedged process.
        $tempScript = $this->writeScript('comskip_wedged', "#!/bin/bash\nsleep 30\n");

        // 1-second timeout so the poll loop reaches its deadline quickly.
        $runner = new ComskipRunner($tempScript, null, 1);
        $recordingPath = $this->makeRecording();

        $start = hrtime(true);
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Comskip timed out after 1 seconds');
            $runner->run($recordingPath);
        } finally {
            $elapsedSeconds = (hrtime(true) - $start) / 1_000_000_000.0;
            // Must give up promptly (well under the wedged process's 30s sleep),
            // proving the timeout is reachable and the process was terminated.
            $this->assertLessThan(10.0, $elapsedSeconds);
        }
    }

    /**
     * The process SIGKILLed on the read loop's timeout must also be reaped.
     *
     * proc_terminate() alone leaves the killed child a zombie until the PHP
     * process exits, and ComskipRunner runs inside long-lived Workerman workers
     * — so an unreaped child is one zombie per comskip timeout, forever.
     *
     * Asserted by asking the kernel: with the child reaped, this process has no
     * children left to wait on, so waitpid(-1) reports ECHILD (-1). An unreaped
     * zombie is returned by that same call instead. The wedged fixture's `sleep`
     * grandchild is orphaned by the kill, not inherited, so it is never a
     * candidate here — which is also why proc_close() does not block on it.
     */
    public function testRunReapsTheProcessItKillsOnTimeout(): void
    {
        $this->requireExecutableFixtures();

        if (!function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('Needs ext-pcntl to observe unreaped child processes.');
        }

        $tempScript = $this->writeScript('comskip_wedged_reap', "#!/bin/bash\nsleep 30\n");

        $runner = new ComskipRunner($tempScript, null, 1);
        $recordingPath = $this->makeRecording();

        try {
            $runner->run($recordingPath);
            $this->fail('Expected a RuntimeException: the fake comskip never exits.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Comskip timed out after 1 seconds', $e->getMessage());
        }

        // A zombie appears as soon as the kernel delivers the SIGKILL, but give
        // it a bounded grace period rather than racing the scheduler for it.
        $deadline = hrtime(true) + 500_000_000;
        $leaked = -1;
        do {
            $status = 0;
            $leaked = pcntl_waitpid(-1, $status, WNOHANG);
            if ($leaked > 0) {
                break;
            }
            usleep(10_000);
        } while (hrtime(true) < $deadline);

        $this->assertLessThanOrEqual(
            0,
            $leaked,
            'The timed-out comskip was killed but never reaped — it is left a zombie.'
        );
    }

    /**
     * The chunk cap is charged per pipe, so a flood on one stream cannot spend
     * the budget the other needs.
     *
     * A single shared counter made the "per pipe" cap a combined one: reads on
     * stdout were billed to stderr as well, and once the total tripped, whichever
     * pipe came up next was force-closed and the rest of its output dropped.
     * stderr is the text quoted back in the exception this run throws, so losing
     * it is what turns a real comskip failure into an unexplained one.
     *
     * Driven with a cap of one chunk per pipe and a stdout that keeps flooding,
     * which pins the two semantics apart however stream_select happens to order
     * the pipes: under a shared counter, stdout's read either force-closes stderr
     * (a second truncation warning) or is itself force-closed before reading a
     * byte (an empty stdout). Per pipe, neither can happen.
     */
    public function testChunkCapIsChargedPerPipeNotSharedBetweenThem(): void
    {
        $this->requireExecutableFixtures();

        $tempScript = $this->writeScript('comskip_flood', <<<'SCRIPT'
#!/bin/bash
# ~320 KB on stdout: far past a one-chunk cap, and still flowing when it trips.
for _ in {1..40}; do
    printf '%08192d' 0
done
exit 1
SCRIPT);

        $logger = new RecordingComskipLogger();
        // 10s rather than the production 300s: if the run ever fails to converge,
        // this should fail as a test, not hang as one.
        $runner = new OneChunkPerPipeComskipRunner($tempScript, $logger, 10);
        $recordingPath = $this->makeRecording();

        try {
            $runner->run($recordingPath);
            $this->fail('Expected a RuntimeException: the fake comskip exits non-zero.');
        } catch (\RuntimeException $e) {
            // Exit code is left unasserted: the flood is force-closed mid-write,
            // so comskip may exit 1 or die of the resulting SIGPIPE.
            $this->assertStringContainsString('Comskip failed with exit code', $e->getMessage());
        }

        // stdout spent its own chunk, and nothing else's...
        $failures = $logger->matching('Comskip execution failed');
        $this->assertCount(1, $failures);
        $this->assertNotSame(
            '',
            $failures[0]['context']['stdout'],
            'stdout was force-closed before a single read — its budget went to the other pipe.'
        );

        // ...and stderr was never closed on stdout's account.
        $truncations = $logger->matching('Comskip output truncated');
        $this->assertCount(
            1,
            $truncations,
            'Only the flooded pipe should hit the cap; stderr closed at EOF with a budget of its own.'
        );
        $this->assertSame('stdout', $truncations[0]['context']['pipe']);
    }
}

/**
 * ComskipRunner with a one-chunk-per-pipe read cap.
 *
 * Lets the cap be reached in a couple of reads instead of the 1.6 MB per pipe
 * the production value asks for.
 *
 * @since 0.12.0
 */
class OneChunkPerPipeComskipRunner extends ComskipRunner
{
    /** @var int One chunk per pipe, so the cap trips on a flooded pipe's second read */
    protected const MAX_CHUNKS_PER_PIPE = 1;
}

/**
 * Captures log records so what the runner reported can be asserted.
 *
 * @since 0.12.0
 */
class RecordingComskipLogger extends \Psr\Log\AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> Every record logged */
    public array $records = [];

    /**
     * @param mixed $level Log level
     * @param string|\Stringable $message Log message
     * @param array<string, mixed> $context Log context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => is_scalar($level) ? (string) $level : '?',
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /**
     * Records whose message contains the given substring.
     *
     * @param string $needle Message substring to match
     *
     * @return list<array{level: string, message: string, context: array<string, mixed>}> Matching records
     */
    public function matching(string $needle): array
    {
        return array_values(array_filter(
            $this->records,
            static fn (array $record): bool => str_contains($record['message'], $needle)
        ));
    }
}

/**
 * ComskipRunner with the process-status read under test control.
 *
 * Lets a test hold the status at "still running" for a chosen number of reads,
 * which reproduces the pipes-drained-before-the-process-is-reapable ordering
 * exactly instead of hammering the real thing and hoping the kernel obliges.
 *
 * @since 0.12.0
 */
class StatusSeamComskipRunner extends ComskipRunner
{
    /** @var int Status reads to report as running; negative means always */
    private int $forcedRunningReads;

    /** @var int Status reads made so far */
    private int $reads = 0;

    /**
     * @param string $comskipPath Path to the fake comskip binary
     * @param int $forcedRunningReads Number of status reads to force to "running",
     *                                or a negative value to force every read
     * @param int|null $timeoutSeconds Execution timeout override in seconds
     */
    public function __construct(string $comskipPath, int $forcedRunningReads, ?int $timeoutSeconds = null)
    {
        parent::__construct($comskipPath, null, $timeoutSeconds);
        $this->forcedRunningReads = $forcedRunningReads;
    }

    /**
     * Number of status reads made so far.
     *
     * Lets a test assert which way the run left the read loop.
     */
    public function statusReads(): int
    {
        return $this->reads;
    }

    /**
     * @param resource $process Process handle from proc_open()
     *
     * @return array{running: bool, exitcode: int} Status array, possibly forced to "running"
     */
    protected function processStatus($process): array
    {
        $status = parent::processStatus($process);
        $this->reads++;

        if ($this->forcedRunningReads < 0 || $this->reads <= $this->forcedRunningReads) {
            // Union keeps the left-hand overrides and fills the rest from the real status.
            return ['running' => true, 'exitcode' => -1] + $status;
        }

        return $status;
    }
}
