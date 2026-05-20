<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Streaming\Trickplay;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Streaming\Trickplay\TrickplayResult;

class TrickplayResultTest extends TestCase
{
    public function testImageFilesAccessible(): void
    {
        $imageFiles = [
            'bif_00.jpg' => ['offset' => 0, 'size' => 4096],
            'bif_01.jpg' => ['offset' => 4096, 'size' => 4096],
        ];

        $result = new TrickplayResult(
            job_id: 'job-123',
            interval_seconds: 10,
            grid_columns: 8,
            grid_rows: 4,
            image_files: $imageFiles,
            index_xml: '/var/trickplay/job-123/index.xml',
        );

        $this->assertEquals($imageFiles, $result->image_files);
        $this->assertEquals(2, $result->getThumbnailCount());
        $this->assertEquals(2, $result->getGridCount());
    }

    public function testIntervalAccessible(): void
    {
        $result = new TrickplayResult(
            job_id: 'job-123',
            interval_seconds: 5,
            grid_columns: 8,
            grid_rows: 4,
            image_files: [],
            index_xml: '/var/trickplay/job-123/index.xml',
        );

        $this->assertEquals(5, $result->interval_seconds);
    }

    public function testGetThumbnailCount(): void
    {
        $imageFiles = [
            'bif_00.jpg' => ['offset' => 0, 'size' => 4096],
            'bif_01.jpg' => ['offset' => 4096, 'size' => 4096],
            'bif_02.jpg' => ['offset' => 8192, 'size' => 4096],
        ];

        $result = new TrickplayResult(
            job_id: 'job-123',
            interval_seconds: 10,
            grid_columns: 8,
            grid_rows: 4,
            image_files: $imageFiles,
            index_xml: '/var/trickplay/job-123/index.xml',
        );

        $this->assertEquals(3, $result->getThumbnailCount());
    }

    public function testGetSortedImageFiles(): void
    {
        $imageFiles = [
            'bif_02.jpg' => ['offset' => 8192, 'size' => 4096],
            'bif_00.jpg' => ['offset' => 0, 'size' => 4096],
            'bif_01.jpg' => ['offset' => 4096, 'size' => 4096],
        ];

        $result = new TrickplayResult(
            job_id: 'job-123',
            interval_seconds: 10,
            grid_columns: 8,
            grid_rows: 4,
            image_files: $imageFiles,
            index_xml: '/var/trickplay/job-123/index.xml',
        );

        $sorted = $result->getSortedImageFiles();
        $keys = array_keys($sorted);

        $this->assertEquals('bif_00.jpg', $keys[0]);
        $this->assertEquals('bif_01.jpg', $keys[1]);
        $this->assertEquals('bif_02.jpg', $keys[2]);
    }

    public function testGetTimeForIndex(): void
    {
        $result = new TrickplayResult(
            job_id: 'job-123',
            interval_seconds: 10,
            grid_columns: 8,
            grid_rows: 4,
            image_files: ['bif_00.jpg' => ['offset' => 0, 'size' => 4096]],
            index_xml: '/var/trickplay/job-123/index.xml',
        );

        $this->assertEquals(0, $result->getTimeForIndex(0));
        $this->assertEquals(10, $result->getTimeForIndex(1));
        $this->assertEquals(30, $result->getTimeForIndex(3));
        $this->assertEquals(100, $result->getTimeForIndex(10));
    }

    public function testGetIndexForTime(): void
    {
        $result = new TrickplayResult(
            job_id: 'job-123',
            interval_seconds: 10,
            grid_columns: 8,
            grid_rows: 4,
            image_files: ['bif_00.jpg' => ['offset' => 0, 'size' => 4096]],
            index_xml: '/var/trickplay/job-123/index.xml',
        );

        $this->assertEquals(0, $result->getIndexForTime(0));
        $this->assertEquals(0, $result->getIndexForTime(5));
        $this->assertEquals(1, $result->getIndexForTime(10));
        $this->assertEquals(3, $result->getIndexForTime(35));
        $this->assertEquals(10, $result->getIndexForTime(100));
    }

    public function testGridDimensionsAccessible(): void
    {
        $result = new TrickplayResult(
            job_id: 'job-123',
            interval_seconds: 10,
            grid_columns: 8,
            grid_rows: 4,
            image_files: [],
            index_xml: '/var/trickplay/job-123/index.xml',
        );

        $this->assertEquals(8, $result->grid_columns);
        $this->assertEquals(4, $result->grid_rows);
    }

    public function testJobIdAccessible(): void
    {
        $result = new TrickplayResult(
            job_id: 'job-456',
            interval_seconds: 10,
            grid_columns: 8,
            grid_rows: 4,
            image_files: [],
            index_xml: '/var/trickplay/job-456/index.xml',
        );

        $this->assertEquals('job-456', $result->job_id);
    }

    public function testIndexXmlPathAccessible(): void
    {
        $result = new TrickplayResult(
            job_id: 'job-123',
            interval_seconds: 10,
            grid_columns: 8,
            grid_rows: 4,
            image_files: [],
            index_xml: '/var/trickplay/job-123/index.xml',
        );

        $this->assertEquals('/var/trickplay/job-123/index.xml', $result->index_xml);
    }
}
