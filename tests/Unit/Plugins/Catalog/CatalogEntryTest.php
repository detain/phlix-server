<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Catalog;

use Phlix\Plugins\Catalog\CatalogEntry;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Plugins\Catalog\CatalogEntry
 */
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
        self::assertSame($data, $entry->toArray());
    }
}
