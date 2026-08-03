<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Catalog;

use Phlix\Plugins\Catalog\CatalogEntry;
use Phlix\Plugins\Catalog\CatalogEntryValidationException;
use PHPUnit\Framework\TestCase;

final class CatalogEntryTest extends TestCase
{
    public function test_hydrates_a_full_entry(): void
    {
        $entry = CatalogEntry::fromArray([
            'name'        => 'phlix-plugin-anidb',
            'title'       => 'AniDB',
            'type'        => 'metadata-provider',
            'summary'     => 'Anime metadata from AniDB.',
            'description' => 'Long description.',
            'repo'        => 'https://github.com/detain/phlix-plugin-anidb',
            'author'      => 'detain',
            'tags'        => ['anime', 'metadata', ''],
        ]);

        self::assertNotNull($entry);
        self::assertSame('phlix-plugin-anidb', $entry->name);
        self::assertSame('AniDB', $entry->title);
        self::assertSame('metadata-provider', $entry->type);
        self::assertSame('Anime metadata from AniDB.', $entry->summary);
        self::assertSame('Long description.', $entry->description);
        self::assertSame('https://github.com/detain/phlix-plugin-anidb', $entry->repo);
        self::assertSame('detain', $entry->author);
        // Blank tags are dropped.
        self::assertSame(['anime', 'metadata'], $entry->tags);
    }

    public function test_title_falls_back_to_name_and_optionals_default_empty(): void
    {
        $entry = CatalogEntry::fromArray([
            'name' => 'phlix-plugin-x',
            'repo' => 'https://github.com/detain/phlix-plugin-x',
        ]);

        self::assertNotNull($entry);
        self::assertSame('phlix-plugin-x', $entry->title);
        self::assertSame('', $entry->type);
        self::assertSame('', $entry->summary);
        self::assertSame([], $entry->tags);
    }

    /**
     * @dataProvider invalidEntries
     *
     * @param array<array-key, mixed>|string $raw
     */
    public function test_returns_null_for_invalid_entries(array|string $raw): void
    {
        self::assertNull(CatalogEntry::fromArray($raw));
    }

    /**
     * @return array<string, array{0: array<array-key, mixed>|string}>
     */
    public static function invalidEntries(): array
    {
        return [
            'not an array'   => ['phlix-plugin-anidb'],
            'missing name'   => [['repo' => 'https://github.com/x/y']],
            'missing repo'   => [['name' => 'phlix-plugin-anidb']],
            'blank name'     => [['name' => '   ', 'repo' => 'https://github.com/x/y']],
            'non-string name' => [['name' => 123, 'repo' => 'https://github.com/x/y']],
        ];
    }

    /**
     * @dataProvider invalidPluginNames
     *
     * @param string $name
     */
    public function test_throws_for_plugin_name_not_matching_pattern(string $name): void
    {
        $this->expectException(CatalogEntryValidationException::class);
        $this->expectExceptionMessage('does not match the required pattern "phlix-plugin-…"');

        CatalogEntry::fromArray([
            'name' => $name,
            'repo' => 'https://github.com/detain/phlix-plugin-x',
        ]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidPluginNames(): array
    {
        return [
            'no prefix'          => ['anidb'],
            'wrong prefix'       => ['my-plugin-anidb'],
            'uppercase prefix'   => ['Phlix-plugin-anidb'],
            'just prefix'        => ['phlix-plugin-'],
            'extra text before'  => ['x-phlix-plugin-anidb'],
        ];
    }

    public function test_to_array_round_trips(): void
    {
        $data = [
            'name'        => 'phlix-plugin-trakt',
            'title'       => 'Trakt',
            'type'        => 'scrobbler',
            'summary'     => 'Scrobble to Trakt.',
            'description' => '',
            'repo'        => 'https://github.com/detain/phlix-plugin-trakt',
            'author'      => 'detain',
            'tags'        => ['trakt'],
        ];

        $entry = CatalogEntry::fromArray($data);
        self::assertNotNull($entry);
        // toArray() now also carries the trust metadata (empty + unverified for
        // a v1 / un-pinned entry like this one).
        self::assertSame($data + [
            'ref'            => '',
            'artifactSha256' => '',
            'version'        => '',
            'verified'       => false,
        ], $entry->toArray());
    }

    public function test_hydrates_and_round_trips_pin_metadata(): void
    {
        $ref = str_repeat('a', 40);
        $sha = str_repeat('b', 64);
        $data = [
            'name'           => 'phlix-plugin-anidb',
            'title'          => 'AniDB',
            'type'           => 'metadata-provider',
            'summary'        => 'Anime metadata.',
            'description'    => 'Long.',
            'repo'           => 'https://github.com/detain/phlix-plugin-anidb',
            'author'         => 'detain',
            'tags'           => ['anime'],
            'ref'            => $ref,
            'artifactSha256' => $sha,
            'version'        => '1.2.3',
        ];

        $entry = CatalogEntry::fromArray($data);
        self::assertNotNull($entry);
        self::assertSame($ref, $entry->ref);
        self::assertSame($sha, $entry->artifactSha256);
        self::assertSame('1.2.3', $entry->version);
        self::assertTrue($entry->verified());
        self::assertSame($data + ['verified' => true], $entry->toArray());
    }

    public function test_uppercase_hex_pin_is_lowercased(): void
    {
        $entry = CatalogEntry::fromArray([
            'name'           => 'phlix-plugin-x',
            'repo'           => 'https://github.com/detain/phlix-plugin-x',
            'ref'            => strtoupper(str_repeat('a', 40)),
            'artifactSha256' => strtoupper(str_repeat('b', 64)),
        ]);

        self::assertNotNull($entry);
        self::assertSame(str_repeat('a', 40), $entry->ref);
        self::assertSame(str_repeat('b', 64), $entry->artifactSha256);
        self::assertTrue($entry->verified());
    }

    /**
     * @dataProvider malformedPins
     */
    public function test_malformed_pins_are_coerced_to_unpinned(string $field, mixed $value): void
    {
        $entry = CatalogEntry::fromArray([
            'name'  => 'phlix-plugin-x',
            'repo'  => 'https://github.com/detain/phlix-plugin-x',
            $field  => $value,
        ]);

        self::assertNotNull($entry);
        // A malformed pin reads as "un-pinned" (empty), never as a trusted pin.
        self::assertSame('', $field === 'ref' ? $entry->ref : $entry->artifactSha256);
        self::assertFalse($entry->verified());
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function malformedPins(): array
    {
        return [
            'ref too short'      => ['ref', str_repeat('a', 39)],
            'ref too long'       => ['ref', str_repeat('a', 41)],
            'ref non-hex'        => ['ref', str_repeat('g', 40)],
            'ref non-string'     => ['ref', 12345],
            'sha256 too short'   => ['artifactSha256', str_repeat('b', 63)],
            'sha256 too long'    => ['artifactSha256', str_repeat('b', 65)],
            'sha256 non-hex'     => ['artifactSha256', str_repeat('z', 64)],
        ];
    }
}
