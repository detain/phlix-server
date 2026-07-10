<?php

/**
 * Phlix media server component: Access.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Access;

use Workerman\MySQL\Connection;

/**
 * Service for managing and querying profile tags.
 *
 * Provides methods to retrieve, create, update, and delete tag restrictions
 * for profiles. Tags can be either blocked (excluded from browse) or allowed
 * (included in browse when an allow-list is present).
 *
 * @package Phlix\Access
 */
final class ProfileTagService
{
    /**
     * Create a new ProfileTagService instance.
     *
     * @param Connection $db Database connection for accessing profile tag data.
     */
    public function __construct(
        private readonly Connection $db,
    ) {
    }

    /**
     * Get all blocked tags for a profile.
     *
     * @param string $profileId The profile ID (UUID) to get blocked tags for.
     *
     * @return list<string> List of blocked tag strings.
     */
    public function getBlockedTags(string $profileId): array
    {
        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT tag FROM profile_tags WHERE profile_id = ? AND tag_type = ?',
            [$profileId, ProfileTag::TYPE_BLOCKED],
        );

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $tags = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['tag']) && is_string($row['tag'])) {
                $tags[] = $row['tag'];
            }
        }

        return $tags;
    }

    /**
     * Get all allowed tags for a profile.
     *
     * @param string $profileId The profile ID (UUID) to get allowed tags for.
     *
     * @return list<string> List of allowed tag strings.
     */
    public function getAllowedTags(string $profileId): array
    {
        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT tag FROM profile_tags WHERE profile_id = ? AND tag_type = ?',
            [$profileId, ProfileTag::TYPE_ALLOWED],
        );

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $tags = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['tag']) && is_string($row['tag'])) {
                $tags[] = $row['tag'];
            }
        }

        return $tags;
    }

    /**
     * Check if a tag is blocked for a profile.
     *
     * Returns true if:
     * - The tag is explicitly blocked, OR
     * - An allowed list exists AND the tag is NOT in it
     *
     * Returns false when:
     * - No restrictions exist (no blocked and no allowed tags)
     * - The tag is in the allowed list (and no blocked tags override it)
     *
     * @param string $profileId The profile ID (UUID) to check.
     * @param string $tag       The tag to check.
     *
     * @return bool True if the tag should be blocked, false otherwise.
     */
    public function isTagBlocked(string $profileId, string $tag): bool
    {
        // First check if the tag is explicitly blocked
        /** @var array<array<string, mixed>> $blockedRows */
        $blockedRows = $this->db->query(
            'SELECT 1 FROM profile_tags WHERE profile_id = ? AND tag = ? AND tag_type = ? LIMIT 1',
            [$profileId, $tag, ProfileTag::TYPE_BLOCKED],
        );

        if (is_array($blockedRows) && count($blockedRows) > 0) {
            return true;
        }

        // Check if there's an allowed list - if so, everything not in it is blocked
        /** @var array<array<string, mixed>> $allowedRows */
        $allowedRows = $this->db->query(
            'SELECT tag FROM profile_tags WHERE profile_id = ? AND tag_type = ?',
            [$profileId, ProfileTag::TYPE_ALLOWED],
        );

        if (is_array($allowedRows) && $allowedRows !== []) {
            // There is an allowed list - check if our tag is in it
            foreach ($allowedRows as $row) {
                if (is_array($row) && isset($row['tag']) && $row['tag'] === $tag) {
                    return false; // Tag is allowed
                }
            }
            return true; // Tag not in allowed list
        }

        // No restrictions at all
        return false;
    }

    /**
     * Set a tag for a profile (upsert - adds or updates).
     *
     * @param string $profileId The profile ID (UUID).
     * @param string $tag       The tag string.
     * @param string $type      The tag type ('blocked' or 'allowed').
     *
     * @return int The ID of the created/updated profile tag.
     */
    public function setTag(string $profileId, string $tag, string $type): int
    {
        // Validate type
        if ($type !== ProfileTag::TYPE_BLOCKED && $type !== ProfileTag::TYPE_ALLOWED) {
            throw new \InvalidArgumentException('Invalid tag type: ' . $type);
        }

        // Upsert: insert or update on duplicate key
        $this->db->query(
            'INSERT INTO profile_tags (profile_id, tag, tag_type) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE tag = VALUES(tag), tag_type = VALUES(tag_type)',
            [$profileId, $tag, $type],
        );

        /** @var int */
        return (int) $this->db->lastInsertId();
    }

    /**
     * Remove a specific tag for a profile.
     *
     * @param string $profileId The profile ID (UUID).
     * @param string $tag       The tag string to remove.
     * @param string $type      The tag type ('blocked' or 'allowed').
     *
     * @return bool True if a tag was removed.
     */
    public function removeTag(string $profileId, string $tag, string $type): bool
    {
        $result = $this->db->query(
            'DELETE FROM profile_tags WHERE profile_id = ? AND tag = ? AND tag_type = ?',
            [$profileId, $tag, $type],
        );

        return $result !== false;
    }

    /**
     * Clear all tags of a specific type for a profile.
     *
     * @param string $profileId The profile ID (UUID).
     * @param string $type      The tag type to clear ('blocked' or 'allowed').
     *
     * @return bool True if any tags were cleared.
     */
    public function clearTags(string $profileId, string $type): bool
    {
        // Validate type
        if ($type !== ProfileTag::TYPE_BLOCKED && $type !== ProfileTag::TYPE_ALLOWED) {
            throw new \InvalidArgumentException('Invalid tag type: ' . $type);
        }

        $result = $this->db->query(
            'DELETE FROM profile_tags WHERE profile_id = ? AND tag_type = ?',
            [$profileId, $type],
        );

        return $result !== false;
    }

    /**
     * Get all tags for a profile.
     *
     * @param string $profileId The profile ID (UUID).
     *
     * @return list<ProfileTag> List of all ProfileTag objects for the profile.
     */
    public function getTagsForProfile(string $profileId): array
    {
        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT * FROM profile_tags WHERE profile_id = ? ORDER BY created_at DESC',
            [$profileId],
        );

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $tags = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $tags[] = ProfileTag::fromRow($row);
            }
        }

        return $tags;
    }

    /**
     * Get a profile tag by its ID.
     *
     * @param int $tagId The profile tag ID to retrieve.
     *
     * @return ProfileTag|null The profile tag, or null if not found.
     */
    public function getTagById(int $tagId): ?ProfileTag
    {
        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT * FROM profile_tags WHERE id = ?',
            [$tagId],
        );

        if (!is_array($rows) || $rows === [] || !is_array($rows[0])) {
            return null;
        }

        /** @var array<string, mixed> $firstRow */
        $firstRow = $rows[0];
        return ProfileTag::fromRow($firstRow);
    }

    /**
     * Delete a profile tag by its ID.
     *
     * @param int $tagId The profile tag ID to delete.
     *
     * @return bool True if the tag was deleted.
     */
    public function deleteTag(int $tagId): bool
    {
        $result = $this->db->query(
            'DELETE FROM profile_tags WHERE id = ?',
            [$tagId],
        );

        return $result !== false;
    }
}
