<?php

declare(strict_types=1);

namespace Phlex\Auth\Dto;

use Phlex\Common\Util\RowMap;

/**
 * Typed value object representing a hydrated `user_profiles` row.
 *
 * Optionally enriched with JOINed `profile_settings` columns (content
 * rating, PIN-required flag, allowed/blocked genres, allow_unrated).
 *
 * @author Phlex Development Team
 * @version 1.0.0
 * @description Strongly-typed user-profile row from DB hydration.
 * @since Wave 5b-J
 */
final class UserProfileRow
{
    /**
     * @param array{
     *     content_rating: string,
     *     pin_required_for_admin: bool,
     *     max_daily_watch_time: int,
     *     allow_unrated: bool,
     *     allowed_genres?: array<int, mixed>,
     *     blocked_genres?: array<int, mixed>,
     * }|null $settings
     */
    public function __construct(
        public readonly string $id,
        public readonly string $userId,
        public readonly string $name,
        public readonly ?string $avatarUrl,
        public readonly bool $isActive,
        public readonly bool $isAdmin,
        public readonly ?string $createdAt,
        public readonly ?string $updatedAt,
        public readonly ?array $settings,
    ) {
    }

    /**
     * Hydrate from a raw DB row.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $settings = null;
        if (isset($row['content_rating'])) {
            $settings = [
                'content_rating' => self::asString($row['content_rating']),
                'pin_required_for_admin' => self::asBool($row['pin_required_for_admin'] ?? false),
                'max_daily_watch_time' => self::asInt($row['max_daily_watch_time'] ?? 0),
                'allow_unrated' => self::asBool($row['allow_unrated'] ?? true),
            ];

            $allowed = self::decodeGenres($row['allowed_genres'] ?? null);
            if ($allowed !== null) {
                $settings['allowed_genres'] = $allowed;
            }
            $blocked = self::decodeGenres($row['blocked_genres'] ?? null);
            if ($blocked !== null) {
                $settings['blocked_genres'] = $blocked;
            }
        }

        return new self(
            id: self::asString($row['id'] ?? ''),
            userId: self::asString($row['user_id'] ?? ''),
            name: self::asString($row['name'] ?? ''),
            avatarUrl: self::nullableString($row['avatar_url'] ?? null),
            isActive: self::asBool($row['is_active'] ?? false),
            isAdmin: self::asBool($row['is_admin'] ?? false),
            createdAt: self::nullableString($row['created_at'] ?? null),
            updatedAt: self::nullableString($row['updated_at'] ?? null),
            settings: $settings,
        );
    }

    /**
     * @return array{
     *     id: string,
     *     user_id: string,
     *     name: string,
     *     avatar_url: string|null,
     *     is_active: bool,
     *     is_admin: bool,
     *     created_at: string|null,
     *     updated_at: string|null,
     *     settings?: array{
     *         content_rating: string,
     *         pin_required_for_admin: bool,
     *         max_daily_watch_time: int,
     *         allow_unrated: bool,
     *         allowed_genres?: array<int, mixed>,
     *         blocked_genres?: array<int, mixed>,
     *     },
     * }
     */
    public function toArray(): array
    {
        $arr = [
            'id' => $this->id,
            'user_id' => $this->userId,
            'name' => $this->name,
            'avatar_url' => $this->avatarUrl,
            'is_active' => $this->isActive,
            'is_admin' => $this->isAdmin,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
        if ($this->settings !== null) {
            $arr['settings'] = $this->settings;
        }
        return $arr;
    }

    /**
     * Decode a genres JSON column. Returns null when input is null/empty/invalid.
     *
     * @return array<int, mixed>|null
     */
    private static function decodeGenres(mixed $value): ?array
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }
        if (is_array($value)) {
            return array_values(RowMap::fromMixed($value)) !== []
                ? self::asListOfMixed($value) : self::asListOfMixed($value);
        }
        if (!is_string($value)) {
            return null;
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return null;
        }
        return self::asListOfMixed($decoded);
    }

    /**
     * @param array<mixed, mixed> $arr
     * @return array<int, mixed>
     */
    private static function asListOfMixed(array $arr): array
    {
        return array_values($arr);
    }

    private static function asString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }
        return '';
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        return self::asString($value);
    }

    private static function asInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function asBool(mixed $value): bool
    {
        return (bool) $value;
    }
}
