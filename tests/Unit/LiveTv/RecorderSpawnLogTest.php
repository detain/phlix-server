<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\LiveTv;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\LiveTv\Recorder;
use Phlix\LiveTv\TimeShift\DbTimeShiftSessionStore;
use Workerman\MySQL\Connection;

/**
 * F-10 regression suite: ffmpeg recording logs must be PER RECORDING.
 *
 * Pre-fix, every spawnRecording appended to `<logDir>/ffmpeg_recording.log`,
 * so concurrent captures mutually clobbered each other's post-mortems. Uses
 * the documented `launchDetached` test double seam (no real ffmpeg process is
 * ever spawned here).
 *
 * @since 2.3.0
 */
final class RecorderSpawnLogTest extends TestCase
{
    private string $tmpDir;
    /** @var Connection&MockObject */
    private $mockDb;
    /** @var StructuredLogger&MockObject */
    private $mockLogger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/reclog-test-' . bin2hex(random_bytes(6));
        $this->mockDb = $this->createMock(Connection::class);
        $this->mockLogger = $this->createMock(StructuredLogger::class);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/**/*') ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($this->tmpDir . '/logs');
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    /**
     * Recorder double that captures the launch instead of running it.
     *
     * @return array{0: Recorder, 1: array{cmd: string, log: string}}
     */
    private function capturingRecorder(string $recordingId, string $logDir): array
    {
        $recorder = new class (
            $this->mockDb,
            new DbTimeShiftSessionStore($this->mockDb),
            $this->tmpDir,
            0,
            $this->mockLogger
        ) extends Recorder {
            /** @var array{cmd: string, log: string}|array<never, never> */
            public array $launchCapture = [];

            protected function launchDetached(string $ffmpegCmd, string $logFile): int
            {
                $this->launchCapture = ['cmd' => $ffmpegCmd, 'log' => $logFile];
                return 4242;
            }
        };

        $method = new \ReflectionMethod(Recorder::class, 'spawnRecording');
        $method->setAccessible(true);
        $pid = $method->invoke(
            $recorder,
            'http://example.com/live/1.ts',
            $logDir . '/' . $recordingId . '.ts',
            $logDir,
            $recordingId
        );

        $this->assertSame(4242, $pid, 'spawnRecording must surface the launcher PID');
        $this->assertNotSame([], $recorder->launchCapture, 'launchDetached must have been reached');

        return [$recorder, $recorder->launchCapture];
    }

    public function testLogIsNamedPerRecordingNotShared(): void
    {
        $logDir = $this->tmpDir . '/logs';

        [, $first] = $this->capturingRecorder('rec-11111111-aaaa', $logDir);
        [, $second] = $this->capturingRecorder('rec-22222222-bbbb', $logDir);

        $this->assertSame($logDir . '/ffmpeg_rec-11111111-aaaa.log', $first['log']);
        $this->assertSame($logDir . '/ffmpeg_rec-22222222-bbbb.log', $second['log']);

        $this->assertStringNotContainsString('ffmpeg_recording.log', $first['log']);
        $this->assertStringNotContainsString('ffmpeg_recording.log', $second['log']);
    }

    public function testHostileRecordingIdCannotEscapeLogDir(): void
    {
        $logDir = $this->tmpDir . '/logs';

        [, $captured] = $this->capturingRecorder('../../etc/pwn', $logDir);

        // Path traversal bytes are neutralised to '_' — the log stays inside
        // the per-recording naming scheme.
        $this->assertSame($logDir . '/ffmpeg_.._.._etc_pwn.log', $captured['log']);
    }

    public function testLaunchCommandTargetsTheRecordingOutput(): void
    {
        $logDir = $this->tmpDir . '/logs';

        [, $captured] = $this->capturingRecorder('rec-333', $logDir);

        $this->assertStringContainsString('-i', $captured['cmd']);
        $this->assertStringContainsString('rec-333.ts', $captured['cmd']);
    }
}
