<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library\Dto;

use Phlix\Media\Library\Dto\MediaItemRow;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

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

    /**
     * E-09 cohort 5a: pins the RowMap fold of MediaItemRow::fromRow against a
     * verbatim transcription of the pre-fold ??-guarded strict ternaries plus
     * the untouched metadata-decode legs (ca91c293 shape). The whole hydrated
     * row is compared, not only the folded scalar legs.
     */
    public function testFromRowDecodeIsByteIdenticalToLegacyAcrossVectors(): void
    {
        $decode = new ReflectionMethod(MediaItemRow::class, 'decodeMetadata');
        $decode->setAccessible(true);

        /** @var list<array<string, mixed>> $vectors */
        $vectors = [
            ['id' => 'item-1', 'library_id' => 'lib-1', 'name' => 'Dune', 'type' => 'movie', 'path' => '/m/d.mkv'],
            ['id' => null, 'library_id' => null, 'name' => null, 'type' => null, 'path' => null],
            ['id' => 7, 'library_id' => 1.5, 'name' => true, 'type' => ['x'], 'path' => 'p'],
            ['id' => '', 'library_id' => '', 'name' => '', 'type' => '', 'path' => ''],
            ['name' => 'Partial only'],
            ['metadata' => '{"a":1}'],
            ['metadata_json' => '{"b":2}'],
            ['metadata' => 'not-json', 'metadata_json' => '{"c":3}'],
            ['metadata' => ['kept' => 1]],
        ];

        foreach ($vectors as $row) {
            $decoded = MediaItemRow::fromRow($row);

            // Verbatim from the ca91c293 tree.
            $id = is_string($row['id'] ?? null) ? $row['id'] : '';
            $libraryId = is_string($row['library_id'] ?? null) ? $row['library_id'] : '';
            $name = is_string($row['name'] ?? null) ? $row['name'] : '';
            $type = is_string($row['type'] ?? null) ? $row['type'] : '';
            $path = is_string($row['path'] ?? null) ? $row['path'] : '';
            /** @var array<string, mixed> $metadata */
            $metadata = $decode->invoke(null, $row['metadata'] ?? ($row['metadata_json'] ?? null));
            $raw = $row;
            $raw['metadata'] = $metadata;
            $legacy = new MediaItemRow($id, $libraryId, $name, $type, $path, $metadata, $raw);

            $this->assertSame($legacy->id, $decoded->id);
            $this->assertSame($legacy->libraryId, $decoded->libraryId);
            $this->assertSame($legacy->name, $decoded->name);
            $this->assertSame($legacy->type, $decoded->type);
            $this->assertSame($legacy->path, $decoded->path);
            $this->assertSame($legacy->metadata, $decoded->metadata);
            $this->assertSame($legacy->toArray(), $decoded->toArray());
        }
    }
}
