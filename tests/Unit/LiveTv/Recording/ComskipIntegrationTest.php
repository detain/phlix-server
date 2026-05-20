<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\LiveTv\Recording;

use PHPUnit\Framework\TestCase;
use Phlix\LiveTv\ComskipEdlParser;
use Phlix\LiveTv\ComskipRunner;
use Phlix\LiveTv\Recording\ComskipIntegration;
use Phlix\Media\Markers\ChapterMarker;
use Psr\Log\NullLogger;
use Workerman\MySQL\Connection;

/**
 * @since 0.12.0
 */
class ComskipIntegrationTest extends TestCase
{
    private ComskipIntegration $integration;
    private $mockRunner;
    private $mockParser;
    private $mockDb;
    private $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockRunner = $this->createMock(ComskipRunner::class);
        $this->mockParser = $this->createMock(ComskipEdlParser::class);
        $this->mockDb = $this->createMock(Connection::class);

        $this->integration = new ComskipIntegration(
            $this->mockRunner,
            $this->mockParser,
            $this->mockDb,
            new NullLogger()
        );

        $this->tempDir = sys_get_temp_dir() . '/comskip_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Clean up temp files
        $files = glob($this->tempDir . '/*');
        foreach ($files as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    public function testProcessRecordingRunsComskipAndParses(): void
    {
        $recordingId = 'test-recording-id';
        $recordingPath = $this->tempDir . '/test_recording.ts';
        $edlPath = $this->tempDir . '/test_recording.edl';

        // Create a fake recording file
        file_put_contents($recordingPath, 'fake video content');

        // Mock runner to return EDL path
        $this->mockRunner->method('isAvailable')->willReturn(true);
        $this->mockRunner
            ->expects($this->once())
            ->method('run')
            ->with($recordingPath)
            ->willReturn($edlPath);

        // Create a fake EDL file
        file_put_contents($edlPath, "0.0\t30.0\t3\n");

        // Mock parser to return chapter markers
        $chapters = [
            new ChapterMarker(0, 30, 'Commercial @ 00:00:00 (30s)'),
        ];
        $this->mockParser
            ->expects($this->once())
            ->method('parse')
            ->with($edlPath)
            ->willReturn($chapters);

        // Mock database update
        $this->mockDb
            ->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('UPDATE livetv_recordings'),
                $this->callback(function ($params) use ($edlPath) {
                    return $params[0] === $edlPath
                        && $params[1] === 1
                        && $params[2] === 30;
                })
            );

        $result = $this->integration->processRecording($recordingId, $recordingPath);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('edl_path', $result);
        $this->assertArrayHasKey('frame_count', $result);
        $this->assertArrayHasKey('duration_seconds', $result);
        $this->assertArrayHasKey('segments', $result);
        $this->assertEquals($edlPath, $result['edl_path']);
        $this->assertEquals(1, $result['frame_count']);
        $this->assertEquals(30, $result['duration_seconds']);
        $this->assertEquals(1, $result['segments']);
    }

    public function testProcessRecordingStoresResultInDb(): void
    {
        $recordingId = 'test-recording-id';
        $recordingPath = $this->tempDir . '/test_recording.ts';
        $edlPath = $this->tempDir . '/test_recording.edl';

        file_put_contents($recordingPath, 'fake video content');
        file_put_contents($edlPath, "0.0\t60.0\t3\n30.0\t90.0\t3\n");

        $this->mockRunner->method('isAvailable')->willReturn(true);
        $this->mockRunner->method('run')->willReturn($edlPath);

        $chapters = [
            new ChapterMarker(0, 60, 'Commercial @ 00:00:00 (60s)'),
            new ChapterMarker(30, 90, 'Commercial @ 00:00:30 (60s)'),
        ];
        $this->mockParser->method('parse')->willReturn($chapters);

        // Expect database update with correct values
        $this->mockDb
            ->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('UPDATE livetv_recordings'),
                $this->callback(function ($params) use ($edlPath, $recordingId) {
                    return $params[0] === $edlPath
                        && $params[1] === 2 // frame count
                        && $params[2] === 120 // total duration (60 + 60)
                        && $params[3] === $recordingId;
                })
            );

        $result = $this->integration->processRecording($recordingId, $recordingPath);

        $this->assertEquals(2, $result['frame_count']);
        $this->assertEquals(120, $result['duration_seconds']);
    }

    public function testGetEdlSegmentsReturnsParsed(): void
    {
        $recordingId = 'test-recording-id';
        $edlPath = $this->tempDir . '/test_recording.edl';
        file_put_contents($edlPath, "0.0\t30.0\t3\n");

        $chapters = [
            new ChapterMarker(0, 30, 'Commercial @ 00:00:00 (30s)'),
        ];

        // Mock database query to return array (SELECT result format)
        $this->mockDb
            ->method('query')
            ->willReturn([
                [
                    'recording_id' => 'test-recording-id',
                    'commercial_edl_path' => $edlPath,
                ]
            ]);

        $this->mockParser
            ->expects($this->once())
            ->method('parse')
            ->with($edlPath)
            ->willReturn($chapters);

        $segments = $this->integration->getEdlSegments($recordingId);

        $this->assertIsArray($segments);
        $this->assertCount(1, $segments);
        $this->assertInstanceOf(ChapterMarker::class, $segments[0]);
        $this->assertEquals(0, $segments[0]->start_seconds);
        $this->assertEquals(30, $segments[0]->end_seconds);
    }

    public function testMarkProcessedSetsTimestamp(): void
    {
        $recordingId = 'test-recording-id';

        // Mock UPDATE query which returns rowCount
        $this->mockDb
            ->expects($this->once())
            ->method('query')
            ->willReturn(1); // rowCount for UPDATE

        $this->integration->markProcessed($recordingId);

        // Assert that query was called
        $this->assertTrue(true);
    }

    public function testProcessRecordingThrowsWhenComskipUnavailable(): void
    {
        $recordingId = 'test-recording-id';
        $recordingPath = $this->tempDir . '/test_recording.ts';
        file_put_contents($recordingPath, 'fake video content');

        $this->mockRunner->method('isAvailable')->willReturn(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Comskip is not available');

        $this->integration->processRecording($recordingId, $recordingPath);
    }

    public function testProcessRecordingThrowsWhenFileNotFound(): void
    {
        $recordingId = 'test-recording-id';
        $recordingPath = $this->tempDir . '/nonexistent.ts';

        $this->mockRunner->method('isAvailable')->willReturn(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Recording file not found');

        $this->integration->processRecording($recordingId, $recordingPath);
    }
}
