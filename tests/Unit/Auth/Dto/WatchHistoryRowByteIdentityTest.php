<?php

/**
 * Phlix media server component: Dto.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth\Dto;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Phlix\Auth\Dto\WatchHistoryRow;
use Phlix\Common\Util\RowMap;

/**
 * E-09 byte-identity pin for WatchHistoryRow::fromRow.
 *
 * This DTO carried its OWN coercion helpers (asString/nullableString/
 * asInt/asFloat) — the scalar-CAST family where 55 becomes "55" and a
 * non-scalar nullable column becomes '' rather than null. The migration
 * moved those contracts onto RowMap's named accessors; this oracle
 * re-expresses the legacy body verbatim (helpers inlined) and compares
 * EVERY public property plus the full toArray() wire structure against
 * the migrated code on each input class.
 */
class WatchHistoryRowByteIdentityTest extends TestCase
{
    /** Legacy private helpers, transcribed. */
    private static function legacyAsString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }
        return '';
    }

    private static function legacyNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        return self::legacyAsString($value);
    }

    private static function legacyAsInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function legacyAsFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    /** The full legacy fromRow body, verbatim. */
    private static function legacyFromRow(array $row): WatchHistoryRow
    {
        $durationRaw = $row['duration_ticks'] ?? null;
        $duration = is_numeric($durationRaw) ? (int) $durationRaw : null;

        $mediaName = isset($row['media_name']) && is_string($row['media_name'])
            ? $row['media_name'] : null;
        $mediaType = isset($row['media_type']) && is_string($row['media_type'])
            ? $row['media_type'] : null;

        $metadata = [];
        if (array_key_exists('metadata_json', $row)) {
            $metadata = RowMap::fromMixed(
                is_array($row['metadata_json'])
                    ? $row['metadata_json']
                    : (is_string($row['metadata_json']) && $row['metadata_json'] !== ''
                        ? json_decode($row['metadata_json'], true)
                        : null)
            );
        }

        return new WatchHistoryRow(
            id: self::legacyAsString($row['id'] ?? ''),
            profileId: self::legacyAsString($row['profile_id'] ?? ''),
            mediaItemId: self::legacyAsString($row['media_item_id'] ?? ''),
            positionTicks: self::legacyAsInt($row['position_ticks'] ?? 0),
            durationTicks: $duration,
            playbackStatus: self::legacyAsString($row['playback_status'] ?? ''),
            progressPercent: self::legacyAsFloat($row['progress_percent'] ?? 0),
            lastWatchedAt: self::legacyAsString($row['last_watched_at'] ?? ''),
            createdAt: self::legacyNullableString($row['created_at'] ?? null),
            completedAt: self::legacyNullableString($row['completed_at'] ?? null),
            mediaName: $mediaName,
            mediaType: $mediaType,
            metadata: $metadata,
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function rowProvider(): array
    {
        return [
            'full joined row' => [[
                'id' => 'wh-1', 'profile_id' => 'p-1', 'media_item_id' => 'm-1',
                'position_ticks' => 12345, 'duration_ticks' => 67890,
                'playback_status' => 'playing', 'progress_percent' => 18.18,
                'last_watched_at' => '2026-05-19 12:00:00',
                'created_at' => '2026-05-18 12:00:00', 'completed_at' => null,
                'media_name' => 'My Movie', 'media_type' => 'movie',
                'metadata_json' => '{"poster_url":"/p.jpg","thumbnail_url":"/t.jpg"}',
            ]],
            'driver string numerics' => [[
                'id' => 'wh-2', 'profile_id' => 'p-1', 'media_item_id' => 'm-1',
                'position_ticks' => '500', 'duration_ticks' => '10000',
                'playback_status' => 'playing', 'progress_percent' => '5.0',
                'last_watched_at' => '2026-05-19', 'created_at' => null, 'completed_at' => null,
            ]],
            'scalar-cast family (this DTO 55→"55" where strict DTOs emit 0)' => [[
                'id' => 55, 'profile_id' => 1.25, 'media_item_id' => true,
                'position_ticks' => '7.9', 'duration_ticks' => '8.1',
                'playback_status' => 99, 'progress_percent' => '0',
                'last_watched_at' => 1000, 'created_at' => 2000, 'completed_at' => ['a'],
            ]],
            'non-scalar nullable created_at becomes empty string not null' => [[
                'id' => 'w', 'profile_id' => 'p', 'media_item_id' => 'm',
                'position_ticks' => 0, 'duration_ticks' => null, 'playback_status' => 'paused',
                'progress_percent' => 'abc', 'last_watched_at' => '',
                'created_at' => [], 'completed_at' => new \stdClass(),
            ]],
            'joined columns with wrong types' => [[
                'id' => 'w', 'profile_id' => 'p', 'media_item_id' => 'm',
                'position_ticks' => 0, 'duration_ticks' => 'x', 'playback_status' => 'paused',
                'progress_percent' => 0, 'last_watched_at' => '',
                'created_at' => null, 'completed_at' => null,
                'media_name' => 33, 'media_type' => null, 'metadata_json' => 44,
            ]],
            'pre-decoded metadata array passthrough' => [[
                'id' => 'w', 'profile_id' => 'p', 'media_item_id' => 'm',
                'position_ticks' => 0, 'duration_ticks' => null, 'playback_status' => 'paused',
                'progress_percent' => 0, 'last_watched_at' => '',
                'created_at' => null, 'completed_at' => null,
                'media_name' => 'Show', 'media_type' => 'episode',
                'metadata_json' => ['poster_url' => '/x.jpg'],
            ]],
            'empty metadata_json string' => [[
                'id' => 'w', 'profile_id' => 'p', 'media_item_id' => 'm',
                'position_ticks' => 0, 'duration_ticks' => null, 'playback_status' => 'paused',
                'progress_percent' => 0, 'last_watched_at' => '',
                'created_at' => null, 'completed_at' => null,
                'media_name' => 'Show', 'media_type' => 'episode', 'metadata_json' => '',
            ]],
            'malformed metadata json' => [[
                'id' => 'w', 'profile_id' => 'p', 'media_item_id' => 'm',
                'position_ticks' => 0, 'duration_ticks' => null, 'playback_status' => 'paused',
                'progress_percent' => 0, 'last_watched_at' => '',
                'created_at' => null, 'completed_at' => null,
                'media_name' => 'Show', 'media_type' => 'episode', 'metadata_json' => '{invalid',
            ]],
            'entirely empty row' => [[]],
        ];
    }

    #[DataProvider('rowProvider')]
    public function testEveryPublicPropertyMatchesTheLegacyHydration(array $row): void
    {
        $legacy = self::legacyFromRow($row);
        $actual = WatchHistoryRow::fromRow($row);

        foreach (
            [
            'id', 'profileId', 'mediaItemId', 'positionTicks', 'durationTicks',
            'playbackStatus', 'progressPercent', 'lastWatchedAt', 'createdAt',
            'completedAt', 'mediaName', 'mediaType', 'metadata',
            ] as $property
        ) {
            $this->assertSame(
                $legacy->{$property},
                $actual->{$property},
                "property {$property} drifted"
            );
        }
    }

    #[DataProvider('rowProvider')]
    public function testFullWireStructureMatchesLegacy(array $row): void
    {
        $this->assertSame(
            self::legacyFromRow($row)->toArray(),
            WatchHistoryRow::fromRow($row)->toArray()
        );
    }
}
