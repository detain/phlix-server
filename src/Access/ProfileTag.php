<?php

/**
 * Phlix media server component: Access.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Access;

/**
 * Represents a tag restriction for a profile.
 *
 * A tag can be either blocked (items with this tag are hidden) or allowed
 * (only items with this tag are shown). This enables per-profile content
 * filtering based on media item tags stored in metadata_json.
 *
 * @package Phlix\Access
 */
final class ProfileTag
{
    /**
     * Tag type constant for blocked tags.
     */
    public const TYPE_BLOCKED = 'blocked';

    /**
     * Tag type constant for allowed tags.
     */
    public const TYPE_ALLOWED = 'allowed';

    /**
     * Create a new ProfileTag instance.
     *
     * @param int    $id        Unique identifier for the profile tag.
     * @param string $profileId The profile (CHAR(36) UUID) this tag belongs to.
     * @param string $tag       The tag string (e.g., 'violence', 'nudity').
     * @param string $tagType   Either 'blocked' or 'allowed'.
     */
    public function __construct(
        public readonly int $id,
        public readonly string $profileId,
        public readonly string $tag,
        public readonly string $tagType,
    ) {
    }

    /**
     * Create a ProfileTag from a database row.
     *
     * @param array<string, mixed> $row Raw database row with keys:
     *                                   id, profile_id, tag, tag_type.
     *
     * @return self
     */
    public static function fromRow(array $row): self
    {
        $id = isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : 0;
        // `profile_tags.profile_id` is CHAR(36) — a `user_profiles.id` UUID
        // (see the explicit "FIX:" note at the head of migration 062). It used
        // to be narrowed with `is_numeric()` + `(int)`, which no UUID can pass,
        // so EVERY hydrated tag carried `profileId === 0` and shipped
        // `profile_id: 0` in `toArray()`. Narrow with `is_string()` — the same
        // way {@see ProfileStreamLimit::fromRow()} already does.
        $profileId = isset($row['profile_id']) && is_string($row['profile_id']) ? $row['profile_id'] : '';
        $tag = isset($row['tag']) && is_string($row['tag']) ? $row['tag'] : '';
        $tagType = isset($row['tag_type']) && is_string($row['tag_type']) ? $row['tag_type'] : self::TYPE_BLOCKED;

        return new self(
            id: $id,
            profileId: $profileId,
            tag: $tag,
            tagType: $tagType,
        );
    }

    /**
     * Check if this is a blocked tag.
     *
     * @return bool True if the tag type is 'blocked'.
     */
    public function isBlocked(): bool
    {
        return $this->tagType === self::TYPE_BLOCKED;
    }

    /**
     * Check if this is an allowed tag.
     *
     * @return bool True if the tag type is 'allowed'.
     */
    public function isAllowed(): bool
    {
        return $this->tagType === self::TYPE_ALLOWED;
    }

    /**
     * Convert the profile tag to an array representation.
     *
     * @return array{
     *     id: int,
     *     profile_id: string,
     *     tag: string,
     *     tag_type: string
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'profile_id' => $this->profileId,
            'tag' => $this->tag,
            'tag_type' => $this->tagType,
        ];
    }
}
