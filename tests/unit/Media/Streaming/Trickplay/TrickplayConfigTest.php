<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Media\Streaming\Trickplay;

use PHPUnit\Framework\TestCase;
use Phlex\Media\Streaming\Trickplay\TrickplayConfig;

class TrickplayConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = new TrickplayConfig();

        $this->assertEquals(10, $config->interval_seconds);
        $this->assertEquals(8, $config->grid_columns);
        $this->assertEquals(4, $config->grid_rows);
        $this->assertEquals(160, $config->thumb_width);
        $this->assertEquals(90, $config->thumb_height);
        $this->assertEquals('jpeg', $config->image_format);
        $this->assertEquals(72, $config->jpeg_quality);
    }

    public function testCustomValues(): void
    {
        $config = new TrickplayConfig(
            interval_seconds: 5,
            grid_columns: 10,
            grid_rows: 6,
            thumb_width: 200,
            thumb_height: 120,
            image_format: 'png',
            jpeg_quality: 85,
        );

        $this->assertEquals(5, $config->interval_seconds);
        $this->assertEquals(10, $config->grid_columns);
        $this->assertEquals(6, $config->grid_rows);
        $this->assertEquals(200, $config->thumb_width);
        $this->assertEquals(120, $config->thumb_height);
        $this->assertEquals('png', $config->image_format);
        $this->assertEquals(85, $config->jpeg_quality);
    }

    public function testGetThumbnailsPerGrid(): void
    {
        $config = new TrickplayConfig(grid_columns: 8, grid_rows: 4);
        $this->assertEquals(32, $config->getThumbnailsPerGrid());

        $config2 = new TrickplayConfig(grid_columns: 10, grid_rows: 6);
        $this->assertEquals(60, $config2->getThumbnailsPerGrid());
    }

    public function testGetGridDimensions(): void
    {
        $config = new TrickplayConfig(grid_columns: 8, grid_rows: 4, thumb_width: 160, thumb_height: 90);
        $dimensions = $config->getGridDimensions();

        $this->assertEquals(1280, $dimensions['width']);
        $this->assertEquals(360, $dimensions['height']);
    }

    public function testGetFileExtension(): void
    {
        $jpegConfig = new TrickplayConfig(image_format: 'jpeg');
        $this->assertEquals('.jpg', $jpegConfig->getFileExtension());

        $pngConfig = new TrickplayConfig(image_format: 'png');
        $this->assertEquals('.png', $pngConfig->getFileExtension());
    }

    public function testGetMimeType(): void
    {
        $jpegConfig = new TrickplayConfig(image_format: 'jpeg');
        $this->assertEquals('image/jpeg', $jpegConfig->getMimeType());

        $pngConfig = new TrickplayConfig(image_format: 'png');
        $this->assertEquals('image/png', $pngConfig->getMimeType());
    }

    public function testInvalidIntervalSecondsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TrickplayConfig(interval_seconds: 0);
    }

    public function testInvalidGridColumnsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TrickplayConfig(grid_columns: 0);
    }

    public function testInvalidGridRowsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TrickplayConfig(grid_rows: 0);
    }

    public function testInvalidThumbWidthThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TrickplayConfig(thumb_width: 0);
    }

    public function testInvalidThumbHeightThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TrickplayConfig(thumb_height: 0);
    }

    public function testInvalidImageFormatThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TrickplayConfig(image_format: 'gif');
    }

    public function testInvalidJpegQualityTooLowThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TrickplayConfig(jpeg_quality: 0);
    }

    public function testInvalidJpegQualityTooHighThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TrickplayConfig(jpeg_quality: 101);
    }
}
