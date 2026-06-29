-- Migration: 042_syncplay_snapshots.sql
-- Description: Add SyncPlay snapshots table for SP5 - single shared SyncPlayManager
--
-- SP5 consolidates the SyncPlayManager so REST API endpoints read the same state
-- as the WS worker on :8097. The WS worker (count=1) is the authoritative owner
-- of SyncPlay state. After each mutation, it publishes a lightweight snapshot to
-- this table. REST controllers read from this snapshot instead of creating their
-- own ephemeral SyncPlayManager instances.
--
-- The snapshot contains just enough state for REST read operations (listGroups,
-- getGroupState) without requiring the full GroupState deserialization overhead.
-- The serialized_state column holds the full GroupState::serialize() output for
-- any consumer that needs detailed group information.
--
-- Architecture:
--   - WS worker: publishes snapshots on every mutation (createGroup, joinGroup,
--     leaveGroup, playback commands)
--   - REST workers: read snapshots from this table (read-only view of WS state)
--   - Mutations from REST are deprecated for now - clients should use WS directly
--
-- Cleanup: snapshots for empty groups (member_count=0) are deleted by the WS
-- worker's cleanupStaleGroups() which also calls removeGroup().
--
-- Idempotent: CREATE TABLE IF NOT EXISTS handles re-runs.

CREATE TABLE IF NOT EXISTS syncplay_snapshots (
    group_id CHAR(36) NOT NULL PRIMARY KEY COMMENT 'Group ID (e.g., sp_xxx)',
    group_name VARCHAR(255) NOT NULL COMMENT 'Display name of the group',
    member_count INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of members in group',
    has_password TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether group is password protected',
    current_media_id VARCHAR(255) NULL COMMENT 'Currently playing media item ID',
    is_playing TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether media is currently playing',
    playback_position INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Current playback position in ms',
    playback_state VARCHAR(32) NOT NULL DEFAULT 'stopped' COMMENT 'Current playback state',
    serialized_state JSON NOT NULL COMMENT 'Full GroupState::serialize() output',
    updated_at INT UNSIGNED NOT NULL COMMENT 'Unix timestamp of last update',
    INDEX idx_syncplay_snapshots_updated (updated_at),
    INDEX idx_syncplay_snapshots_member_count (member_count)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;