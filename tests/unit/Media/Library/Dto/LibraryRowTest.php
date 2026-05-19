<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Media\Library\Dto;

use Phlex\Media\Library\Dto\LibraryRow;
use PHPUnit\Framework\TestCase;

/**
 * Smoke tests for the LibraryRow DTO.
 */
class LibraryRowTest extends TestCase
{
    public function testFromRowDecodesPathsAndOptionsFromJsonStrings(): void
    {
        $row = LibraryRow::fromRow([
            'id' => 'lib-1',
            'name' => 'Movies',
            'type' => 'video',
            'paths' => '["/mnt/movies"]',
            'options' => '{"scan_interval":3600}',
        ]);

        $this->assertSame('lib-1', $row->id);
        $this->assertSame('Movies', $row->name);
        $this->assertSame('video', $row->type);
        $this->assertSame(['/mnt/movies'], $row->paths);
        $this->assertSame(3600, $row->options['scan_interval']);
    }

    public function testFromRowToleratesMissingColumns(): void
    {
        $row = LibraryRow::fromRow([]);

        $this->assertSame('', $row->id);
        $this->assertSame([], $row->paths);
        $this->assertSame([], $row->options);
    }

    public function testToArrayReturnsDecodedRow(): void
    {
        $row = LibraryRow::fromRow([
            'id' => 'lib-1',
            'paths' => '["/a","/b"]',
            'options' => '{"x":1}',
        ]);

        $arr = $row->toArray();
        $this->assertSame(['/a', '/b'], $arr['paths']);
        $this->assertSame(['x' => 1], $arr['options']);
    }
}
