<?php

/**
 * Phlix media server component: SyncPlay.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Session\SyncPlay;

use Phlix\Common\Database\ConnectionPool;
use Workerman\MySQL\Connection;

/**
 * SyncPlaySnapshotService - Manages SyncPlay group state snapshots in the database.
 *
 * This service provides a read-only view of SyncPlay group state for HTTP/REST
 * workers. The authoritative state lives in the WS worker's SyncPlayManager
 * (count=1 process). After each mutation, the WS worker publishes a lightweight
 * snapshot to this table. REST controllers read from this snapshot instead of
 * creating their own ephemeral SyncPlayManager instances.
 *
 * ## Architecture
 *
 * - WS worker (count=1): Owns the authoritative SyncPlayManager. Calls
 *   publishSnapshot() after every mutation (createGroup, joinGroup, leaveGroup,
 *   playback commands) to update the shared snapshot table.
 * - HTTP workers (count=14): Stateless. Use this service to read group state
 *   for REST API responses (listGroups, getGroupState).
 *
 * ## Snapshot Contents
 *
 * Each snapshot contains:
 * - Lightweight fields for list queries (id, name, member_count, etc.)
 * - Full serialized_state (GroupState::serialize()) for detailed group reads
 *
 * @package Phlix\Session\SyncPlay
 * @since 3.5
 */
class SyncPlaySnapshotService
{
    /** @var Connection|null Lazy DB connection */
    private ?Connection $db = null;

    /**
     * Publish a snapshot for a single group.
     *
     * Called by the WS worker after each mutation. Upserts the snapshot row.
     *
     * @param GroupState $group The group state to snapshot
     * @return void
     */
    public function publishGroup(GroupState $group): void
    {
        $db = $this->getDb();

        $state = $group->serialize();
        $memberCount = $group->getMemberCount();

        // Build lightweight fields from the GroupState object
        $groupId = $group->getId();
        $groupName = $group->getName();
        $hasPassword = $group->hasPassword() ? 1 : 0;
        $currentMediaId = $group->getCurrentMediaId();
        $isPlaying = $group->isPlaying() ? 1 : 0;
        $playbackPosition = $group->getPlaybackPosition();
        $playbackState = $group->getPlaybackState();
        $updatedAt = time();
        $serializedState = json_encode($state, JSON_THROW_ON_ERROR);

        // Upsert: replace existing row
        $db->query(
            "INSERT INTO syncplay_snapshots
                (group_id, group_name, member_count, has_password, current_media_id,
                 is_playing, playback_position, playback_state, serialized_state, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                group_name = VALUES(group_name),
                member_count = VALUES(member_count),
                has_password = VALUES(has_password),
                current_media_id = VALUES(current_media_id),
                is_playing = VALUES(is_playing),
                playback_position = VALUES(playback_position),
                playback_state = VALUES(playback_state),
                serialized_state = VALUES(serialized_state),
                updated_at = VALUES(updated_at)",
            [
                $groupId,
                $groupName,
                $memberCount,
                $hasPassword,
                $currentMediaId,
                $isPlaying,
                $playbackPosition,
                $playbackState,
                $serializedState,
                $updatedAt,
            ]
        );
    }

    /**
     * Remove a group snapshot.
     *
     * Called when a group is deleted (empty group timeout, explicit leave of last member).
     *
     * @param string $groupId The group ID to remove
     * @return void
     */
    public function removeGroup(string $groupId): void
    {
        $db = $this->getDb();
        $db->query("DELETE FROM syncplay_snapshots WHERE group_id = ?", [$groupId]);
    }

    /**
     * List all group snapshots (lightweight summary).
     *
     * Returns the lightweight fields needed for listGroups REST responses.
     *
     * @return array<int, array{id: string, name: string, member_count: int, has_password: bool,
     *     current_media: string|null, is_playing: bool}> Group summaries
     */
    public function listGroups(): array
    {
        $db = $this->getDb();

        /** @var array<array<string, mixed>> $rows */
        $rows = $db->query(
            "SELECT group_id, group_name, member_count, has_password,
                    current_media_id, is_playing
             FROM syncplay_snapshots
             ORDER BY updated_at DESC"
        );

        $groups = [];
        foreach ($rows as $row) {
            /** @var array<string, mixed> $row */
            $memberCount = $row['member_count'];
            $hasPassword = $row['has_password'];
            $isPlaying = $row['is_playing'];
            $currentMediaId = $row['current_media_id'];
            $groupId = $row['group_id'];
            $groupName = $row['group_name'];

            $groups[] = [
                'id' => is_string($groupId) ? $groupId : '',
                'name' => is_string($groupName) ? $groupName : '',
                'member_count' => is_numeric($memberCount) ? (int) $memberCount : 0,
                'has_password' => (bool) $hasPassword,
                'current_media' => is_string($currentMediaId) ? $currentMediaId : null,
                'is_playing' => (bool) $isPlaying,
            ];
        }

        return $groups;
    }

    /**
     * Get a single group snapshot by ID.
     *
     * Returns the full serialized state, deserialized into the same format
     * that GroupState::getState() returns.
     *
     * @param string $groupId The group ID to fetch
     * @return array<string, mixed>|null The full group state or null if not found
     */
    public function getGroupState(string $groupId): ?array
    {
        $db = $this->getDb();

        /** @var array<array<string, mixed>> $rows */
        $rows = $db->query(
            "SELECT serialized_state FROM syncplay_snapshots WHERE group_id = ?",
            [$groupId]
        );

        if (empty($rows)) {
            return null;
        }

        /** @var array<string, mixed> $firstRow */
        $firstRow = $rows[0];
        $serialized = $firstRow['serialized_state'] ?? null;
        if (!is_string($serialized)) {
            return null;
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($serialized, true, 512, JSON_THROW_ON_ERROR);
            return GroupState::deserialize($data)->getState();
        } catch (\Throwable) {
            // If deserialization fails, fall back to raw snapshot fields
            return $this->getRawSnapshot($groupId);
        }
    }

    /**
     * Get raw snapshot fields without full deserialization.
     *
     * Fallback when serialized_state cannot be deserialized.
     *
     * @param string $groupId The group ID
     * @return array<string, mixed>|null Raw snapshot or null
     */
    private function getRawSnapshot(string $groupId): ?array
    {
        $db = $this->getDb();

        /** @var array<array<string, mixed>> $rows */
        $rows = $db->query(
            "SELECT * FROM syncplay_snapshots WHERE group_id = ?",
            [$groupId]
        );

        if (empty($rows)) {
            return null;
        }

        /** @var array<string, mixed> $row */
        $row = $rows[0];

        // Extract typed values with safe defaults
        $memberCount = $row['member_count'];
        $currentMediaId = $row['current_media_id'];
        $playbackPosition = $row['playback_position'];
        $updatedAt = $row['updated_at'];
        $groupId = $row['group_id'];
        $groupName = $row['group_name'];
        $playbackState = $row['playback_state'];

        return [
            'group_id' => is_string($groupId) ? $groupId : '',
            'group_name' => is_string($groupName) ? $groupName : '',
            'member_count' => is_numeric($memberCount) ? (int) $memberCount : 0,
            'members' => [], // Not available in lightweight snapshot
            'host_id' => null,
            'current_media_id' => is_string($currentMediaId) ? $currentMediaId : null,
            'current_media_duration' => 0,
            'playback_position' => is_numeric($playbackPosition) ? (int) $playbackPosition : 0,
            'playback_state' => is_string($playbackState) ? $playbackState : 'stopped',
            'queue' => [],
            'created_at' => is_numeric($updatedAt) ? (int) $updatedAt : 0,
            'last_activity_at' => is_numeric($updatedAt) ? (int) $updatedAt : 0,
        ];
    }

    /**
     * Get the database connection (lazy initialization).
     *
     * @return Connection
     */
    private function getDb(): Connection
    {
        if ($this->db === null) {
            $this->db = ConnectionPool::getConnection('mysql');
        }

        return $this->db;
    }
}
