<?php

namespace Phlix\Tests\Unit\LiveTv;

use PHPUnit\Framework\TestCase;
use Phlix\LiveTv\ComskipRunner;

/**
 * @since 0.12.0
 */
class ComskipRunnerTest extends TestCase
{
    private string $fakeComskipPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeComskipPath = '/tmp/fake_comskip_' . uniqid();
    }

    protected function tearDown(): void
    {
        if (file_exists($this->fakeComskipPath)) {
            unlink($this->fakeComskipPath);
        }
        parent::tearDown();
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
        // Create a mock comskip that creates an EDL file with the correct name
        // The EDL file has same basename as recording but .edl extension
        $tempScript = '/tmp/comskip_mock_' . uniqid() . '.sh';
        $scriptContent = <<<'SCRIPT'
#!/bin/bash
# Get the recording path (last argument)
recording_path="${@: -1}"
# Derive EDL path: same directory, same basename, .edl extension
basename=$(basename "$recording_path" .ts)
edl_dir=$(dirname "$recording_path")
touch "$edl_dir/${basename}.edl"
exit 0
SCRIPT;
        file_put_contents($tempScript, $scriptContent);
        chmod($tempScript, 0755);

        $runner = new ComskipRunner($tempScript);
        $recordingPath = '/tmp/test_recording_' . uniqid() . '.ts';
        touch($recordingPath);

        try {
            $edlPath = $runner->run($recordingPath);

            $expectedEdlPath = substr($recordingPath, 0, -3) . '.edl';
            $this->assertEquals($expectedEdlPath, $edlPath);
            $this->assertFileExists($edlPath);
        } finally {
            @unlink($recordingPath);
            $expectedEdlPath = substr($recordingPath, 0, -3) . '.edl';
            @unlink($expectedEdlPath);
            if (file_exists($tempScript)) {
                unlink($tempScript);
            }
        }
    }

    public function testRunThrowsWhenComskipFails(): void
    {
        $tempScript = '/tmp/comskip_fail_' . uniqid() . '.sh';
        file_put_contents($tempScript, "#!/bin/bash\nexit 1\n");
        chmod($tempScript, 0755);

        $runner = new ComskipRunner($tempScript);
        $recordingPath = '/tmp/test_recording_' . uniqid() . '.ts';
        touch($recordingPath);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Comskip failed with exit code 1');
            $runner->run($recordingPath);
        } finally {
            @unlink($recordingPath);
            if (file_exists($tempScript)) {
                unlink($tempScript);
            }
        }
    }

    public function testRunThrowsWhenRecordingNotFound(): void
    {
        // Use a valid comskip path (executable) but with a non-existent recording
        $tempScript = '/tmp/comskip_valid_' . uniqid() . '.sh';
        file_put_contents($tempScript, "#!/bin/bash\nexit 0\n");
        chmod($tempScript, 0755);

        $runner = new ComskipRunner($tempScript);
        $nonExistentPath = '/tmp/nonexistent_recording_' . uniqid() . '.ts';

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Recording file not found');
            $runner->run($nonExistentPath);
        } finally {
            if (file_exists($tempScript)) {
                unlink($tempScript);
            }
        }
    }

    public function testRunThrowsWhenComskipNotAvailable(): void
    {
        $runner = new ComskipRunner('/nonexistent/comskip');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Comskip is not available');
        $runner->run('/tmp/test.ts');
    }

    /**
     * SV-4.3 / SV-3.1d-comskip: a wedged comskip that never exits is SIGKILLed
     * at the configured timeout, and the run reports a bounded timeout error
     * rather than blocking forever. Uses a 1-second timeout override so the test
     * does not have to wait the production 300s.
     */
    public function testRunTimesOutAndKillsWedgedProcess(): void
    {
        // A fake comskip that sleeps far longer than the timeout, holding its
        // stdout/stderr pipes open with no output — the classic wedged process.
        $tempScript = '/tmp/comskip_wedged_' . uniqid() . '.sh';
        file_put_contents($tempScript, "#!/bin/bash\nsleep 30\n");
        chmod($tempScript, 0755);

        // 1-second timeout so the poll loop reaches its deadline quickly.
        $runner = new ComskipRunner($tempScript, null, 1);
        $recordingPath = '/tmp/test_recording_' . uniqid() . '.ts';
        touch($recordingPath);

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
            @unlink($recordingPath);
            @unlink(substr($recordingPath, 0, -3) . '.edl');
            if (file_exists($tempScript)) {
                unlink($tempScript);
            }
        }
    }
}
