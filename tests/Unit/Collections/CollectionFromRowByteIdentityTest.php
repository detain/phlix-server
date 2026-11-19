<?php

/**
 * Phlix media server component: Collections.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Collections;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Phlix\Collections\Collection;

/**
 * E-09 byte-identity pin for Collection::fromRow.
 *
 * The legacy body hand-rolled nine narrowings (six strict is_string,
 * one strict-nullable trio, one numeric-coercive sort_order compound).
 * This test re-expresses each legacy expression VERBATIM as an oracle
 * alongside the migrated accessor output, so the accessor migration is
 * proven byte-identical for every input class: real value, empty string,
 * explicit NULL, missing key, wrong type, and the numeric-string family.
 *
 * The 'now' date default is Collection's own legacy policy, now an
 * EXPLICIT argument at the call site — pinned by clock proximity rather
 * than baked-in timestamps so the oracle stays race-free.
 */
class CollectionFromRowByteIdentityTest extends TestCase
{
    /**
     * The legacy narrowing block, copied verbatim from the pre-E-09
     * body of Collection::fromRow(). If anyone edits either side, the
     * comparison tests below fail — that is the pin's teeth.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function legacyToArray(array $row): array
    {
        $id = is_string($row['id'] ?? null) ? $row['id'] : '';
        $name = is_string($row['name'] ?? null) ? $row['name'] : '';
        $libraryId = is_string($row['library_id'] ?? null) ? $row['library_id'] : '';
        $smartPlaylistId = is_string($row['smart_playlist_id'] ?? null) ? $row['smart_playlist_id'] : null;
        $parentId = is_string($row['parent_id'] ?? null) ? $row['parent_id'] : null;
        $sortOrderRaw = $row['sort_order'] ?? null;
        $sortOrder = is_int($sortOrderRaw)
            ? $sortOrderRaw
            : (is_numeric($sortOrderRaw) ? (int)$sortOrderRaw : 0);
        $createdAt = is_string($row['created_at'] ?? null) ? $row['created_at'] : 'now';
        $updatedAt = is_string($row['updated_at'] ?? null) ? $row['updated_at'] : 'now';
        $createdBy = is_string($row['created_by'] ?? null) ? $row['created_by'] : null;

        return [
            'id' => $id,
            'name' => $name,
            'library_id' => $libraryId,
            'smart_playlist_id' => $smartPlaylistId,
            'parent_id' => $parentId,
            'sort_order' => $sortOrder,
            'is_smart' => $smartPlaylistId !== null,
            'created_at' => (new \DateTimeImmutable($createdAt))->format('Y-m-d H:i:s'),
            'updated_at' => (new \DateTimeImmutable($updatedAt))->format('Y-m-d H:i:s'),
            'created_by' => $createdBy,
        ];
    }

    /**
     * Deterministic input rows (explicit parseable date strings).
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function deterministicRowProvider(): array
    {
        $dates = ['created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-02-02 11:00:00'];

        return [
            'full real row' => [[
                'id' => 'col-1', 'name' => 'Docs', 'library_id' => 'lib-1',
                'smart_playlist_id' => null, 'parent_id' => 'col-0', 'sort_order' => 3,
                'created_by' => 'user-9',
            ] + $dates],
            'smart collection + numeric-string sort' => [[
                'id' => 'col-2', 'name' => 'S', 'library_id' => 'lib-1',
                'smart_playlist_id' => 'sp-1', 'parent_id' => null, 'sort_order' => '7',
                'created_by' => null,
            ] + $dates],
            'float sort truncates' => [[
                'id' => 'col-3', 'name' => 'F', 'library_id' => 'lib-1', 'sort_order' => 4.9,
            ] + $dates],
            'float-string sort truncates' => [[
                'id' => 'col-3', 'name' => 'F', 'library_id' => 'lib-1', 'sort_order' => '7.9',
            ] + $dates],
            'garbage sort falls to zero' => [[
                'id' => 'col-4', 'name' => 'G', 'library_id' => 'lib-1', 'sort_order' => 'abc',
            ] + $dates],
            'bool sort falls to zero' => [[
                'id' => 'col-5', 'name' => 'B', 'library_id' => 'lib-1', 'sort_order' => true,
            ] + $dates],
            'zero sort survives (default would too)' => [[
                'id' => 'col-6', 'name' => 'Z', 'library_id' => 'lib-1', 'sort_order' => 0,
            ] + $dates],
            'string ids hydrate as ints are rejected' => [[
                'id' => 12345, 'name' => 999, 'library_id' => ['x'],
                'smart_playlist_id' => 55, 'parent_id' => 66, 'created_by' => 77,
                'sort_order' => 1,
            ] + $dates],
            'explicit empty strings hydrate as-is' => [[
                'id' => '', 'name' => '', 'library_id' => '',
                'smart_playlist_id' => '', 'parent_id' => '', 'created_by' => '',
                'sort_order' => '0',
            ] + $dates],
            'nulls hydrate to defaults' => [[
                'id' => null, 'name' => null, 'library_id' => null,
                'smart_playlist_id' => null, 'parent_id' => null, 'created_by' => null,
            ] + $dates],
            'empty row' => [$dates],
            'exponent-notation numeric string' => [[
                'id' => 'col-7', 'name' => 'E', 'library_id' => 'lib-1', 'sort_order' => '1e3',
            ] + $dates],
            'hex-looking string is not numeric' => [[
                'id' => 'col-8', 'name' => 'H', 'library_id' => 'lib-1', 'sort_order' => '0x1A',
            ] + $dates],
        ];
    }

    #[DataProvider('deterministicRowProvider')]
    public function testFromRowOutputIsByteIdenticalToTheLegacyNarrowing(array $row): void
    {
        $this->assertSame(self::legacyToArray($row), Collection::fromRow($row)->toArray());
    }

    public function testMissingDatesDefaultToNowPerExplicitPolicy(): void
    {
        $collection = Collection::fromRow(['id' => 'c', 'name' => 'n', 'library_id' => 'l']);
        $arr = $collection->toArray();

        $now = new \DateTimeImmutable();
        foreach (['created_at', 'updated_at'] as $key) {
            $parsed = new \DateTimeImmutable($arr[$key]);
            $this->assertLessThanOrEqual(5, abs($now->getTimestamp() - $parsed->getTimestamp()), $key);
        }
    }

    public function testPreMigrationFixturesWithoutCreatedByColumnHydrateNull(): void
    {
        // A pre-112 fixture: the column simply does not exist in the row.
        $row = [
            'id' => 'c1', 'name' => 'n', 'library_id' => 'l', 'sort_order' => 1,
            'created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-01-02 10:00:00',
        ];

        $this->assertNull(Collection::fromRow($row)->toArray()['created_by']);
        $this->assertSame(self::legacyToArray($row), Collection::fromRow($row)->toArray());
    }

    public function testNonStringCreatedByHydratesNullNotCast(): void
    {
        // STRICT nullableStr discipline: an int created_by must NOT cast
        // to '77' (that would be silent harmonization toward the
        // WatchHistoryRow scalar-cast family).
        $row = Collection::fromRow([
            'id' => 'c', 'name' => 'n', 'library_id' => 'l', 'created_by' => 77,
            'created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-01-02 10:00:00',
        ])->toArray();
        $this->assertNull($row['created_by']);
    }
}
