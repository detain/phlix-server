<?php

namespace Phlex\Tests\Unit\LiveTv;

use PHPUnit\Framework\TestCase;
use Phlex\LiveTv\ComskipRunner;
use Phlex\LiveTv\ComskipEdlParser;
use Phlex\LiveTv\ComskipPostProcessor;
use Phlex\Media\Markers\ChapterMarker;
use Phlex\Media\Markers\MarkerService;
use Phlex\Media\Markers\MarkerSet;
use Psr\Log\NullLogger;

/**
 * @since 0.12.0
 */
class ComskipPostProcessorTest extends TestCase
{
    private $mockComskip;
    private $mockEdlParser;
    private $mockMarkerService;
    private ComskipPostProcessor $processor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockComskip = $this->createMock(ComskipRunner::class);
        $this->mockEdlParser = $this->createMock(ComskipEdlParser::class);
        $this->mockMarkerService = $this->createMock(MarkerService::class);

        $this->processor = new ComskipPostProcessor(
            $this->mockComskip,
            $this->mockEdlParser,
            $this->mockMarkerService,
            new NullLogger()
        );
    }

    public function testProcessRecordingRunsComskipAndStoresChapters(): void
    {
        $mediaItemId = 'media_123';
        $recordingPath = '/var/recordings/test.ts';
        $edlPath = '/var/recordings/test.edl';

        $chapters = [
            new ChapterMarker(0, 45, 'Commercial @ 00:00:00 (45s)'),
            new ChapterMarker(60, 120, 'Commercial @ 00:01:00 (60s)'),
        ];

        // isProcessed returns false (no existing chapters)
        $this->mockMarkerService->method('getMarkers')
            ->with($mediaItemId)
            ->willReturn(MarkerSet::empty());

        // Comskip is available
        $this->mockComskip->expects($this->once())
            ->method('isAvailable')
            ->willReturn(true);

        // Run comskip returns EDL path
        $this->mockComskip->expects($this->once())
            ->method('run')
            ->with($recordingPath)
            ->willReturn($edlPath);

        // Parse EDL returns chapters
        $this->mockEdlParser->expects($this->once())
            ->method('parse')
            ->with($edlPath)
            ->willReturn($chapters);

        // storeChapters is called
        $this->mockMarkerService->expects($this->once())
            ->method('storeChapters')
            ->with($mediaItemId, $chapters);

        $this->processor->processRecording($mediaItemId, $recordingPath);
    }

    public function testProcessRecordingIsIdempotent(): void
    {
        $mediaItemId = 'media_123';
        $recordingPath = '/var/recordings/test.ts';

        // Already has chapters
        $this->mockMarkerService->method('getMarkers')
            ->with($mediaItemId)
            ->willReturn(new MarkerSet(
                null,
                null,
                [new ChapterMarker(0, 45, 'Existing Chapter')]
            ));

        // isAvailable should NOT be called since we short-circuit early
        $this->mockComskip->expects($this->never())
            ->method('isAvailable');

        // run should NOT be called
        $this->mockComskip->expects($this->never())
            ->method('run');

        $this->processor->processRecording($mediaItemId, $recordingPath);
    }

    public function testIsProcessedChecksMarkerService(): void
    {
        $mediaItemId = 'media_123';

        // Has no chapters
        $this->mockMarkerService->expects($this->once())
            ->method('getMarkers')
            ->with($mediaItemId)
            ->willReturn(MarkerSet::empty());

        $this->assertFalse($this->processor->isProcessed($mediaItemId));
    }

    public function testIsProcessedReturnsTrueWhenChaptersExist(): void
    {
        $mediaItemId = 'media_123';

        $chapters = [
            new ChapterMarker(0, 45, 'Chapter 1'),
        ];

        $this->mockMarkerService->expects($this->once())
            ->method('getMarkers')
            ->with($mediaItemId)
            ->willReturn(new MarkerSet(null, null, $chapters));

        $this->assertTrue($this->processor->isProcessed($mediaItemId));
    }

    public function testProcessRecordingSkipsWhenComskipNotAvailable(): void
    {
        $mediaItemId = 'media_123';
        $recordingPath = '/var/recordings/test.ts';

        // isProcessed returns false
        $this->mockMarkerService->method('getMarkers')
            ->with($mediaItemId)
            ->willReturn(MarkerSet::empty());

        // Comskip not available
        $this->mockComskip->expects($this->once())
            ->method('isAvailable')
            ->willReturn(false);

        // run should NOT be called
        $this->mockComskip->expects($this->never())
            ->method('run');

        // storeChapters should NOT be called
        $this->mockMarkerService->expects($this->never())
            ->method('storeChapters');

        $this->processor->processRecording($mediaItemId, $recordingPath);
    }

    public function testProcessRecordingContinuesWhenComskipThrows(): void
    {
        $mediaItemId = 'media_123';
        $recordingPath = '/var/recordings/test.ts';

        // isProcessed returns false
        $this->mockMarkerService->method('getMarkers')
            ->with($mediaItemId)
            ->willReturn(MarkerSet::empty());

        // Comskip is available
        $this->mockComskip->expects($this->once())
            ->method('isAvailable')
            ->willReturn(true);

        // run throws an exception
        $this->mockComskip->expects($this->once())
            ->method('run')
            ->willThrowException(new \RuntimeException('Comskip failed'));

        // storeChapters should NOT be called
        $this->mockMarkerService->expects($this->never())
            ->method('storeChapters');

        // Should not throw - error is handled internally
        $this->processor->processRecording($mediaItemId, $recordingPath);
    }

    public function testProcessRecordingContinuesWhenParseReturnsEmpty(): void
    {
        $mediaItemId = 'media_123';
        $recordingPath = '/var/recordings/test.ts';
        $edlPath = '/var/recordings/test.edl';

        // isProcessed returns false
        $this->mockMarkerService->method('getMarkers')
            ->with($mediaItemId)
            ->willReturn(MarkerSet::empty());

        // Comskip is available
        $this->mockComskip->expects($this->once())
            ->method('isAvailable')
            ->willReturn(true);

        $this->mockComskip->expects($this->once())
            ->method('run')
            ->willReturn($edlPath);

        // Parse returns empty
        $this->mockEdlParser->expects($this->once())
            ->method('parse')
            ->willReturn([]);

        // storeChapters should NOT be called
        $this->mockMarkerService->expects($this->never())
            ->method('storeChapters');

        $this->processor->processRecording($mediaItemId, $recordingPath);
    }
}
