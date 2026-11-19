<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth\Dto;

use Phlix\Auth\Dto\UserProfileRow;
use PHPUnit\Framework\TestCase;

/**
 * Smoke tests for the UserProfileRow DTO.
 */
class UserProfileRowTest extends TestCase
{
    public function testFromRowHydratesCoreColumns(): void
    {
        $row = UserProfileRow::fromRow([
            'id' => 'prof-1',
            'user_id' => 'u-1',
            'name' => 'Alice',
            'avatar_url' => 'https://x/a.jpg',
            'is_active' => 1,
            'is_admin' => 0,
            'created_at' => '2026-05-18',
            'updated_at' => '2026-05-19',
        ]);

        $this->assertSame('prof-1', $row->id);
        $this->assertSame('u-1', $row->userId);
        $this->assertSame('Alice', $row->name);
        $this->assertSame('https://x/a.jpg', $row->avatarUrl);
        $this->assertTrue($row->isActive);
        $this->assertFalse($row->isAdmin);
        $this->assertNull($row->settings);
    }

    public function testFromRowAttachesSettingsWhenJoined(): void
    {
        $row = UserProfileRow::fromRow([
            'id' => 'prof-1',
            'user_id' => 'u-1',
            'name' => 'Kids',
            'avatar_url' => null,
            'is_active' => true,
            'is_admin' => false,
            'created_at' => null,
            'updated_at' => null,
            'content_rating' => 'G',
            'pin_required_for_admin' => 1,
            'max_daily_watch_time' => '3600',
            'allow_unrated' => 0,
            'allowed_genres' => '["Animation","Family"]',
            'blocked_genres' => null,
        ]);

        $this->assertNotNull($row->settings);
        $this->assertSame('G', $row->settings['content_rating']);
        $this->assertTrue($row->settings['pin_required_for_admin']);
        $this->assertSame(3600, $row->settings['max_daily_watch_time']);
        $this->assertFalse($row->settings['allow_unrated']);
        $this->assertSame(['Animation', 'Family'], $row->settings['allowed_genres'] ?? null);
        $this->assertArrayNotHasKey('blocked_genres', $row->settings);
    }

    public function testToArrayMatchesShape(): void
    {
        $row = UserProfileRow::fromRow([
            'id' => 'p-1',
            'user_id' => 'u-1',
            'name' => 'Alice',
            'is_active' => true,
            'is_admin' => false,
            'content_rating' => 'R',
            'allow_unrated' => true,
        ]);

        /** @var array<string, mixed> $arr */
        $arr = $row->toArray();
        $this->assertSame('p-1', $arr['id']);
        $this->assertArrayHasKey('settings', $arr);
        $settings = $arr['settings'];
        $this->assertIsArray($settings);
        $this->assertSame('R', $settings['content_rating']);
    }

    public function testFromRowToleratesMissingColumns(): void
    {
        $row = UserProfileRow::fromRow([]);

        $this->assertSame('', $row->id);
        $this->assertNull($row->avatarUrl);
        $this->assertNull($row->settings);
    }

    /**
     * E-09 cohort-3 byte-identity oracle (SRV-ROWMAP): the pre-migration
     * fromRow() body with its private asString/nullableString/asInt/asBool
     * helpers re-expressed VERBATIM, compared against the live code over a
     * type-class + settings/genres corpus. Green against BOTH the legacy
     * helpers (characterization, log ROWMAP-C3-0) and the migrated RowMap
     * accessor bodies (regression) — wire output may not move. decodeGenres
     * stays in the DTO across the migration, so the oracle calls the REAL
     * one only for the KEPT block's inputs (it is not a narrowing being
     * migrated); every migrated column is exercised per type-class.
     */
    public function testUserProfileRowFromRowRemainsByteIdenticalToLegacyHelpers(): void
    {
        $scalars = [
            'string' => 'abc',
            'empty-string' => '',
            'space' => ' ',
            'int-5' => 5,
            'int-0' => 0,
            'float-7.9' => 7.9,
            'bool-true' => true,
            'bool-false' => false,
            'array' => ['x'],
            'numeric-string' => '12',
            'numeric-string-0' => '0',
            'hex-string' => '0x1A',
            'exp-string' => '1e3',
        ];

        $columns = [
            'id',
            'user_id',
            'name',
            'avatar_url',
            'is_active',
            'is_admin',
            'created_at',
            'updated_at',
            'content_rating',
            'pin_required_for_admin',
            'max_daily_watch_time',
            'allow_unrated',
        ];

        $rows = ['missing-all' => []];
        foreach ($columns as $col) {
            $rows['null:' . $col] = [$col => null];
            foreach ($scalars as $label => $value) {
                $rows[$col . ':' . $label] = [$col => $value];
            }
        }
        $rows['settings-full'] = [
            'id' => 'p1',
            'user_id' => 'u1',
            'name' => 'N',
            'content_rating' => 'PG-13',
            'pin_required_for_admin' => '1',
            'max_daily_watch_time' => '90',
            'allow_unrated' => 0,
            'allowed_genres' => '["action","drama"]',
            'blocked_genres' => '[]',
        ];
        $rows['settings-garbage-genres'] = [
            'content_rating' => 'R',
            'allowed_genres' => 'not-json',
            'blocked_genres' => '{"a":1}',
        ];
        $rows['genres-array-passthrough'] = [
            'content_rating' => null,
            'allowed_genres' => ['b', 'a'],
            'blocked_genres' => ['k' => 'v'],
        ];
        $rows['genres-empty-string'] = [
            'content_rating' => 'G',
            'allowed_genres' => '',
            'blocked_genres' => false,
        ];
        $rows['pin-null-vs-missing'] = [
            'content_rating' => 'G',
            'pin_required_for_admin' => null,
            'allow_unrated' => null,
        ];

        foreach ($rows as $label => $row) {
            $this->assertSame(
                self::legacyUserProfileRowArray($row),
                UserProfileRow::fromRow($row)->toArray(),
                'profile row [' . $label . ']'
            );
        }
    }

    /**
     * Verbatim pre-migration body (helpers inlined as they were defined on
     * the class at base 016ea759). decodeGenres is delegated to the REAL
     * class method via reflection: it was NOT part of the migration.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function legacyUserProfileRowArray(array $row): array
    {
        $asString = static function (mixed $value): string {
            if (is_string($value)) {
                return $value;
            }
            if (is_int($value) || is_float($value) || is_bool($value)) {
                return (string) $value;
            }

            return '';
        };
        $nullableString = static function (mixed $value) use ($asString): ?string {
            if ($value === null) {
                return null;
            }

            return $asString($value);
        };
        $asInt = static fn (mixed $value): int => is_numeric($value) ? (int) $value : 0;
        $asBool = static fn (mixed $value): bool => (bool) $value;

        $decodeGenres = static function (mixed $value): ?array {
            $method = new \ReflectionMethod(UserProfileRow::class, 'decodeGenres');
            $method->setAccessible(true);

            /** @var array<int, mixed>|null $decoded */
            $decoded = $method->invoke(null, $value);

            return $decoded;
        };

        $settings = null;
        if (isset($row['content_rating'])) {
            $settings = [
                'content_rating' => $asString($row['content_rating']),
                'pin_required_for_admin' => $asBool($row['pin_required_for_admin'] ?? false),
                'max_daily_watch_time' => $asInt($row['max_daily_watch_time'] ?? 0),
                'allow_unrated' => $asBool($row['allow_unrated'] ?? true),
            ];

            $allowed = $decodeGenres($row['allowed_genres'] ?? null);
            if ($allowed !== null) {
                $settings['allowed_genres'] = $allowed;
            }
            $blocked = $decodeGenres($row['blocked_genres'] ?? null);
            if ($blocked !== null) {
                $settings['blocked_genres'] = $blocked;
            }
        }

        return (new UserProfileRow(
            id: $asString($row['id'] ?? ''),
            userId: $asString($row['user_id'] ?? ''),
            name: $asString($row['name'] ?? ''),
            avatarUrl: $nullableString($row['avatar_url'] ?? null),
            isActive: $asBool($row['is_active'] ?? false),
            isAdmin: $asBool($row['is_admin'] ?? false),
            createdAt: $nullableString($row['created_at'] ?? null),
            updatedAt: $nullableString($row['updated_at'] ?? null),
            settings: $settings,
        ))->toArray();
    }
}
