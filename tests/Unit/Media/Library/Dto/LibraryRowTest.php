<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library\Dto;

use Phlix\Media\Library\Dto\LibraryRow;
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

    /**
     * seriesPerDirectory() coerces a range of truthy representations to true.
     *
     * @dataProvider truthySeriesPerDirectoryProvider
     */
    public function testSeriesPerDirectoryCoercesTruthyValues(mixed $value): void
    {
        $row = LibraryRow::fromRow([
            'id' => 'lib-1',
            'type' => 'series',
            'options' => ['series_per_directory' => $value],
        ]);

        $this->assertTrue($row->seriesPerDirectory());
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function truthySeriesPerDirectoryProvider(): array
    {
        return [
            'bool true' => [true],
            'int 1' => [1],
            'string "1"' => ['1'],
            'string "true"' => ['true'],
            'string "TRUE" (case-insensitive)' => ['TRUE'],
            'string "yes"' => ['yes'],
            'string "on"' => ['on'],
            'string " true " (trimmed)' => [' true '],
        ];
    }

    /**
     * seriesPerDirectory() returns false for falsy / absent / unrecognised values.
     *
     * @dataProvider falsySeriesPerDirectoryProvider
     */
    public function testSeriesPerDirectoryCoercesFalsyValues(mixed $value): void
    {
        $row = LibraryRow::fromRow([
            'id' => 'lib-1',
            'type' => 'series',
            'options' => ['series_per_directory' => $value],
        ]);

        $this->assertFalse($row->seriesPerDirectory());
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function falsySeriesPerDirectoryProvider(): array
    {
        return [
            'bool false' => [false],
            'int 0' => [0],
            'int 2 (only 1 is true)' => [2],
            'string "0"' => ['0'],
            'string "false"' => ['false'],
            'string "no"' => ['no'],
            'empty string' => [''],
            'null' => [null],
            'array' => [['x']],
        ];
    }

    /**
     * Missing options key (and a fully missing options blob) defaults to false.
     */
    public function testSeriesPerDirectoryDefaultsFalseWhenAbsent(): void
    {
        $present = LibraryRow::fromRow([
            'id' => 'lib-1',
            'type' => 'series',
            'options' => ['scan_interval' => 3600],
        ]);
        $this->assertFalse($present->seriesPerDirectory());

        $missing = LibraryRow::fromRow(['id' => 'lib-1', 'type' => 'series']);
        $this->assertFalse($missing->seriesPerDirectory());
    }
}
