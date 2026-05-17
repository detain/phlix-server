<?php

namespace Phlex\Tests\Unit\LiveTv;

use PHPUnit\Framework\TestCase;
use Phlex\LiveTv\ComskipRunner;

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

            $expectedEdlPath = substr($recordingPath, 0, strrpos($recordingPath, '.')) . '.edl';
            $this->assertEquals($expectedEdlPath, $edlPath);
            $this->assertFileExists($edlPath);
        } finally {
            @unlink($recordingPath);
            $expectedEdlPath = substr($recordingPath, 0, strrpos($recordingPath, '.')) . '.edl';
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
}
