<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Streaming\Trickplay;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Streaming\Trickplay\TrickplayGenerator;
use Phlix\Media\Transcoding\FfmpegRunner;

class TrickplayGeneratorTest extends TestCase
{
    public function testCalculateGridCount(): void
    {
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $generator = new TrickplayGenerator($ffmpeg, '/tmp/trickplay');

        $config = new \Phlix\Media\Streaming\Trickplay\TrickplayConfig(
            interval_seconds: 10,
            grid_columns: 8,
            grid_rows: 4,
        );

        // 30 second video / 10s interval = 3 thumbnails / 32 per grid = 1 grid
        $this->assertEquals(1, $generator->calculateGridCount(30, $config));

        // 320 second video / 10s interval = 32 thumbnails / 32 per grid = 1 grid
        $this->assertEquals(1, $generator->calculateGridCount(320, $config));

        // 330 second video / 10s interval = 33 thumbnails / 32 per grid = 2 grids
        $this->assertEquals(2, $generator->calculateGridCount(330, $config));

        // 640 second video / 10s interval = 64 thumbnails / 32 per grid = 2 grids
        $this->assertEquals(2, $generator->calculateGridCount(640, $config));
    }

    public function testCalculateGridCountWithDifferentInterval(): void
    {
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $generator = new TrickplayGenerator($ffmpeg, '/tmp/trickplay');

        $config = new \Phlix\Media\Streaming\Trickplay\TrickplayConfig(
            interval_seconds: 5,
            grid_columns: 8,
            grid_rows: 4,
        );

        // 160 second video / 5s interval = 32 thumbnails / 32 per grid = 1 grid
        $this->assertEquals(1, $generator->calculateGridCount(160, $config));

        // 165 second video / 5s interval = 33 thumbnails / 32 per grid = 2 grids
        $this->assertEquals(2, $generator->calculateGridCount(165, $config));
    }

    public function testConstructorSetsProperties(): void
    {
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $generator = new TrickplayGenerator($ffmpeg, '/var/trickplay');

        $this->assertInstanceOf(TrickplayGenerator::class, $generator);
    }

    public function testExtractFrameReturnsBool(): void
    {
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->method('getFfmpegPath')->willReturn('/usr/bin/ffmpeg');

        $generator = new TrickplayGenerator($ffmpeg, '/tmp/trickplay');

        // Test with non-existent video file - should return false
        $result = $generator->extractFrame('/nonexistent/video.mkv', 10, '/tmp/thumb.jpg');
        $this->assertFalse($result);
    }

    public function testGenerateThrowsWhenProbeFails(): void
    {
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->method('probe')->willReturn(null);

        $generator = new TrickplayGenerator($ffmpeg, '/tmp/trickplay');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to probe video');
        $generator->generate('job-123', '/nonexistent/video.mkv');
    }

    public function testGenerateThrowsWhenDurationIsInvalid(): void
    {
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->method('probe')->willReturn([
            'format' => ['duration' => 0],
        ]);

        $generator = new TrickplayGenerator($ffmpeg, '/tmp/trickplay');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid video duration');
        $generator->generate('job-123', '/nonexistent/video.mkv');
    }

    public function testCleanupWithNonExistentJob(): void
    {
        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $generator = new TrickplayGenerator($ffmpeg, '/tmp/trickplay');

        // Should not throw
        $generator->cleanup('nonexistent-job');
        $this->assertTrue(true);
    }
}
