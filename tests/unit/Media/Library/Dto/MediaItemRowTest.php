<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Media\Library\Dto;

use Phlex\Media\Library\Dto\MediaItemRow;
use PHPUnit\Framework\TestCase;

/**
 * Smoke tests for the MediaItemRow DTO.
 */
class MediaItemRowTest extends TestCase
{
    public function testFromRowDecodesMetadataJson(): void
    {
        $row = MediaItemRow::fromRow([
            'id' => 'item-1',
            'library_id' => 'lib-1',
            'name' => 'Track Name',
            'type' => 'track',
            'path' => '/music/track.mp3',
            'metadata_json' => '{"artist":"Artist","year":2020}',
        ]);

        $this->assertSame('item-1', $row->id);
        $this->assertSame('lib-1', $row->libraryId);
        $this->assertSame('Track Name', $row->name);
        $this->assertSame('track', $row->type);
        $this->assertSame('/music/track.mp3', $row->path);
        $this->assertSame('Artist', $row->metadata['artist']);
        $this->assertSame(2020, $row->metadata['year']);
    }

    public function testFromRowAcceptsAlreadyDecodedMetadata(): void
    {
        $row = MediaItemRow::fromRow([
            'id' => 'item-2',
            'metadata' => ['title' => 'T'],
        ]);

        $this->assertSame('T', $row->metadata['title']);
    }

    public function testFromRowToleratesMissingColumns(): void
    {
        $row = MediaItemRow::fromRow([]);

        $this->assertSame('', $row->id);
        $this->assertSame('', $row->libraryId);
        $this->assertSame([], $row->metadata);
    }

    public function testToArrayContainsDecodedMetadata(): void
    {
        $row = MediaItemRow::fromRow([
            'id' => 'item-1',
            'metadata_json' => '{"a":1}',
        ]);

        $arr = $row->toArray();
        $this->assertSame(['a' => 1], $arr['metadata']);
    }
}
