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

    /**
     * metadataPriority() parses a well-formed per-type map (from a JSON string
     * options column), trimming source names and preserving order.
     */
    public function testMetadataPriorityParsesWellFormedMap(): void
    {
        $row = LibraryRow::fromRow([
            'id' => 'lib-1',
            'type' => 'movie',
            'options' => '{"metadata_priority":{"movie":["imdb"," tmdb "],"series":["tmdb"]}}',
        ]);

        $this->assertSame(
            ['movie' => ['imdb', 'tmdb'], 'series' => ['tmdb']],
            $row->metadataPriority(),
        );
    }

    /**
     * metadataPriority() returns null when the key is absent entirely.
     */
    public function testMetadataPriorityNullWhenAbsent(): void
    {
        $row = LibraryRow::fromRow([
            'id' => 'lib-1',
            'type' => 'movie',
            'options' => ['scan_interval' => 3600],
        ]);

        $this->assertNull($row->metadataPriority());
    }

    /**
     * metadataPriority() returns null for a range of malformed shapes.
     *
     * @dataProvider malformedMetadataPriorityProvider
     */
    public function testMetadataPriorityNullForMalformedMap(mixed $value): void
    {
        $row = LibraryRow::fromRow([
            'id' => 'lib-1',
            'type' => 'movie',
            'options' => ['metadata_priority' => $value],
        ]);

        $this->assertNull($row->metadataPriority());
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function malformedMetadataPriorityProvider(): array
    {
        return [
            'empty map' => [[]],
            'not an array (string)' => ['tmdb'],
            'value not a list' => [['movie' => 'tmdb']],
            'value list has non-string' => [['movie' => ['tmdb', 5]]],
            'value list has blank string' => [['movie' => ['tmdb', '  ']]],
            'value list empty' => [['movie' => []]],
            'blank type key' => [['' => ['tmdb']]],
            'numeric type key' => [[0 => ['tmdb']]],
        ];
    }

    /**
     * toArray() surfaces metadata_priority as a top-level key: the decoded map
     * when well-formed, else null (so index/show responses carry it).
     */
    public function testToArraySurfacesMetadataPriority(): void
    {
        $withOverride = LibraryRow::fromRow([
            'id' => 'lib-1',
            'type' => 'movie',
            'options' => ['metadata_priority' => ['movie' => ['imdb', 'tmdb']]],
        ]);
        $arr = $withOverride->toArray();
        $this->assertArrayHasKey('metadata_priority', $arr);
        $this->assertSame(['movie' => ['imdb', 'tmdb']], $arr['metadata_priority']);

        $withoutOverride = LibraryRow::fromRow([
            'id' => 'lib-2',
            'type' => 'movie',
            'options' => ['scan_interval' => 3600],
        ]);
        $arr2 = $withoutOverride->toArray();
        $this->assertArrayHasKey('metadata_priority', $arr2);
        $this->assertNull($arr2['metadata_priority']);
    }

    /**
     * toArray() surfaces an `image_types` block with the FULL available
     * catalogue plus the library's enabled selection (M5).
     */
    public function testToArraySurfacesImageTypesSelection(): void
    {
        $row = LibraryRow::fromRow([
            'id' => 'lib-1',
            'type' => 'movie',
            'options' => '{"image_types":{"poster":true,"backdrop":false,"logo":true}}',
        ]);
        $arr = $row->toArray();

        $this->assertArrayHasKey('image_types', $arr);
        $imageTypes = is_array($arr['image_types'] ?? null) ? $arr['image_types'] : [];
        $this->assertArrayHasKey('available', $imageTypes);
        $this->assertArrayHasKey('enabled', $imageTypes);

        // Every canonical type is offered in `available`.
        $available = is_array($imageTypes['available'] ?? null) ? $imageTypes['available'] : [];
        $availableTypes = array_map(
            static fn (mixed $e): string => is_array($e) && is_string($e['type'] ?? null) ? $e['type'] : '',
            $available
        );
        $this->assertContains('poster', $availableTypes);
        $this->assertContains('character_art', $availableTypes);

        // `enabled` reflects the stored selection (catalogue-ordered).
        $this->assertSame(['poster', 'logo'], $imageTypes['enabled']);
    }

    /**
     * A library with NO stored image_types selection falls back to the defaults
     * in `image_types.enabled` (no migration needed).
     */
    public function testToArrayImageTypesFallsBackToDefaults(): void
    {
        $row = LibraryRow::fromRow([
            'id' => 'lib-2',
            'type' => 'movie',
            'options' => '{"scan_interval":3600}',
        ]);
        $arr = $row->toArray();

        $imageTypes = is_array($arr['image_types'] ?? null) ? $arr['image_types'] : [];
        $this->assertSame(
            ['poster', 'backdrop', 'logo', 'banner', 'thumb', 'season_poster', 'episode_still'],
            $imageTypes['enabled']
        );
    }

    /**
     * S33: an ABSENT autoCollections flag defaults to enabled (true) — the
     * backward-compatible default that preserves today's unconditional
     * auto-collection generation for un-migrated libraries.
     */
    public function testAutoCollectionsEnabledDefaultsTrueWhenFlagAbsent(): void
    {
        $row = LibraryRow::fromRow([
            'id' => 'lib-1',
            'type' => 'movie',
            'options' => '{"scan_interval":3600}',
        ]);

        $this->assertTrue($row->autoCollectionsEnabled());
    }

    /**
     * S33: an explicit stored `{"autoCollections":{"enabled":false}}` disables
     * generation.
     */
    public function testAutoCollectionsEnabledReadsExplicitFalse(): void
    {
        $row = LibraryRow::fromRow([
            'id' => 'lib-1',
            'type' => 'movie',
            'options' => '{"autoCollections":{"enabled":false}}',
        ]);

        $this->assertFalse($row->autoCollectionsEnabled());
    }

    /**
     * S33: an explicit stored `{"enabled":true}` reads as enabled, and a range of
     * truthy encodings ("1"/"true"/1) all coerce to true.
     *
     * @dataProvider truthyAutoCollectionsProvider
     */
    public function testAutoCollectionsEnabledReadsTruthyValues(mixed $value): void
    {
        $row = LibraryRow::fromRow([
            'id' => 'lib-1',
            'type' => 'movie',
            'options' => ['autoCollections' => ['enabled' => $value]],
        ]);

        $this->assertTrue($row->autoCollectionsEnabled());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function truthyAutoCollectionsProvider(): array
    {
        return [
            'bool true' => [true],
            'int 1' => [1],
            'string 1' => ['1'],
            'string true' => ['true'],
            'string on' => ['on'],
        ];
    }

    /**
     * S33: a malformed autoCollections block (not a `{enabled:...}` map) falls back
     * to the enabled default rather than misreading as disabled.
     */
    public function testAutoCollectionsEnabledDefaultsTrueForMalformedBlock(): void
    {
        $row = LibraryRow::fromRow([
            'id' => 'lib-1',
            'type' => 'movie',
            'options' => ['autoCollections' => 'nonsense'],
        ]);

        $this->assertTrue($row->autoCollectionsEnabled());
    }

    /**
     * S33: toArray() surfaces the EFFECTIVE toggle under a top-level
     * `auto_collections` block, defaulting to enabled for un-set libraries and
     * reflecting an explicit stored `false`.
     */
    public function testToArraySurfacesEffectiveAutoCollectionsToggle(): void
    {
        $enabledByDefault = LibraryRow::fromRow([
            'id' => 'lib-1',
            'type' => 'movie',
            'options' => '{"scan_interval":3600}',
        ])->toArray();
        $this->assertSame(['enabled' => true], $enabledByDefault['auto_collections']);

        $explicitlyOff = LibraryRow::fromRow([
            'id' => 'lib-2',
            'type' => 'movie',
            'options' => '{"autoCollections":{"enabled":false}}',
        ])->toArray();
        $this->assertSame(['enabled' => false], $explicitlyOff['auto_collections']);
    }
}
