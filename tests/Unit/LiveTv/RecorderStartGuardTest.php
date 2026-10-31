<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\LiveTv;

use Phlix\Common\Logger\StructuredLogger;
use Phlix\LiveTv\Dto\ResultSet;
use Phlix\LiveTv\Recorder;
use Phlix\LiveTv\TimeShift\DbTimeShiftSessionStore;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * minors M-SERVER-81 (W-min108) — the recorder re-validates the stream URL at
 * RECORD START via the same StreamUrlGuard law the parser (M3UParser) and the
 * tuner (IptvTunerDriver) apply.
 *
 * Before the cure, a URL reaching startRecording — whether stored/resolved
 * earlier (the explicit-URL bypass) or DNS-resolved at tune time — was
 * handed straight to ffmpeg: a validate-then-late-spawn window in which a
 * rebinding hostname or a persisted `file://`/metadata endpoint would be
 * fetched by the recording worker. The refusal path keeps the SV-3.1a shape:
 * the row lands FAILED with the refusal copy, no fake PID is stored, nothing
 * is launched, and no client-facing wire shape moves (refusalReason never
 * throws — zero new throw sites).
 *
 * Harness merges the two house idioms: the recording-Connection + fakeResult
 * rows of RecorderRecoveryTest and the launch-disabling anonymous-subclass
 * double of RecorderSpawnLogTest (no real ffmpeg is ever spawned).
 */
final class RecorderStartGuardTest extends TestCase
{
    /** @var Connection&\PHPUnit\Framework\MockObject\MockObject */
    private $db;

    /** @var StructuredLogger&\PHPUnit\Framework\MockObject\MockObject */
    private $logger;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->db = $this->createMock(Connection::class);
        $this->logger = $this->createMock(StructuredLogger::class);
        $this->tmpDir = sys_get_temp_dir() . '/phlix_s81_' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        @rmdir($this->tmpDir);
    }

    /** @param array<string, mixed> $overrides */
    private function recordingRow(array $overrides = []): array
    {
        return array_merge([
            'recording_id' => 'rec-s81',
            'channel_id' => 'ch-1',
            'program_id' => null,
            'user_id' => null,
            'title' => 'Guard Test Show',
            'description' => null,
            'start_time' => time() - 30,
            'end_time' => time() + 1800,
            'priority' => Recorder::PRIORITY_NORMAL,
            'quality' => 'default',
            'storage_path' => $this->tmpDir . '/rec-s81.ts',
            'storage_size' => 0,
            'status' => Recorder::STATUS_SCHEDULED,
            'pid' => null,
            'error_message' => null,
            'series_rule_id' => null,
            'duplicate_group' => null,
            'pre_padding_seconds' => 0,
            'post_padding_seconds' => 0,
            'created_at' => '2024-01-01 00:00:00',
            'updated_at' => '2024-01-01 00:00:00',
        ], $overrides);
    }

    /** @param list<array<string, mixed>> $rows */
    private function fakeResult(array $rows): ResultSet
    {
        return new class ($rows) extends ResultSet {
            /** @var list<array<string, mixed>> */
            private array $rowList;

            /** @param list<array<string, mixed>> $rows */
            public function __construct(array $rows)
            {
                $this->rowList = array_values($rows);
                $this->num_rows = count($this->rowList);
            }

            /** @return array<string, mixed>|false */
            public function fetch(): array|false
            {
                return array_shift($this->rowList) ?? false;
            }
        };
    }

    /**
     * Recorder wired to a scheduled row; $updates collects every UPDATE's
     * parameter list and $launched (an ArrayObject sink shared with the
     * double) proves whether the ffmpeg launch seam was reached — no real
     * process is ever spawned.
     *
     * @param-out array<int, list<mixed>> $updates
     */
    private function guardedRecorder(bool $strict, array &$updates, \ArrayObject $launched): Recorder
    {
        $row = $this->recordingRow();
        $updates = [];

        $this->db
            ->method('query')
            ->willReturnCallback(
                /** @param list<mixed> $params */
                /** @return ResultSet|null */
                function (string $sql, array $params = []) use ($row, &$updates) {
                    if (str_starts_with(ltrim($sql), 'SELECT')) {
                        return $this->fakeResult([$row]);
                    }

                    $updates[] = $params;

                    return null;
                }
            );

        return new class (
            $this->db,
            new DbTimeShiftSessionStore($this->db),
            $this->tmpDir,
            0,
            $this->logger,
            null,
            '/usr/bin/ffmpeg',
            null,
            $strict,
            $launched
        ) extends Recorder {
            private \ArrayObject $sink;

            /** @param array<int, mixed> $args */
            public function __construct(...$args)
            {
                $sink = array_pop($args);
                assert($sink instanceof \ArrayObject);
                parent::__construct(...$args);
                $this->sink = $sink;
            }

            protected function launchDetached(string $ffmpegCmd, string $logFile): int
            {
                $this->sink->append(['cmd' => $ffmpegCmd, 'log' => $logFile]);

                return 4242;
            }
        };
    }

    public function testExplicitFileSchemeUrlIsRefusedAtRecordingStart(): void
    {
        $updates = [];
        $launched = new \ArrayObject();
        $recorder = $this->guardedRecorder(false, $updates, $launched);

        $result = $recorder->startRecording('rec-s81', 'file:///etc/passwd');

        $this->assertRefusedShape($recorder, $result, $updates, $launched);
    }

    public function testMetadataEndpointUrlIsRefusedAtRecordingStart(): void
    {
        $updates = [];
        $launched = new \ArrayObject();
        $recorder = $this->guardedRecorder(false, $updates, $launched);

        $result = $recorder->startRecording('rec-s81', 'http://169.254.169.254/latest/meta-data');

        $this->assertRefusedShape($recorder, $result, $updates, $launched);
    }

    public function testLoopbackLiteralUrlIsRefusedAtRecordingStart(): void
    {
        $updates = [];
        $launched = new \ArrayObject();
        $recorder = $this->guardedRecorder(false, $updates, $launched);

        $result = $recorder->startRecording('rec-s81', 'http://127.0.0.1:8080/x.ts');

        $this->assertRefusedShape($recorder, $result, $updates, $launched);
    }

    public function testStrictPolicyRefusesThePrivateLiteralTheRelaxedDefaultWouldTolerate(): void
    {
        $updates = [];
        $launched = new \ArrayObject();
        $recorder = $this->guardedRecorder(true, $updates, $launched);

        $result = $recorder->startRecording('rec-s81', 'http://10.5.8.9:8080/x.ts');

        $this->assertRefusedShape($recorder, $result, $updates, $launched);
    }

    public function testPublicLiteralStillSpawnsUnderTheGuardNonStrict(): void
    {
        $updates = [];
        $launched = new \ArrayObject();
        $recorder = $this->guardedRecorder(false, $updates, $launched);

        $result = $recorder->startRecording('rec-s81', 'http://203.0.113.9:8080/s.ts');

        self::assertTrue($result, 'a guard-passing URL must still start the recording');
        self::assertNotCount(0, $launched, 'the launcher seam must be reached');
        self::assertSame(Recorder::STATUS_RECORDING, $updates[0][0]);
    }

    public function testPrivateLiteralStillSpawnsUnderTheRelaxedDefault(): void
    {
        $updates = [];
        $launched = new \ArrayObject();
        $recorder = $this->guardedRecorder(false, $updates, $launched);

        $result = $recorder->startRecording('rec-s81', 'http://10.5.8.9:8080/x.ts');

        self::assertTrue($result, 'the relaxed posture tolerates RFC1918 literals (guard law)');
        self::assertNotCount(0, $launched);
    }

    /**
     * @param array<int, list<mixed>> $updates
     */
    private function assertRefusedShape(Recorder $recorder, bool $result, array $updates, \ArrayObject $launched): void
    {
        self::assertFalse($result, 'a refused stream URL must not report a started recording');
        self::assertCount(0, $launched, 'the launcher must NEVER be reached');
        self::assertSame(0, $recorder->getActiveRecordingCount());
        $refusals = array_values(array_filter(
            $updates,
            static fn (array $params): bool => $params[0] === Recorder::STATUS_FAILED,
        ));
        self::assertCount(1, $refusals, 'exactly the FAILED status UPDATE may be written');
        self::assertSame(
            'Stream URL refused at recording start',
            $refusals[0][1],
            'the refusal copy lands in the existing error_message column',
        );
        self::assertSame('rec-s81', $refusals[0][2]);
    }
}
