-- Migration 069: Add user_id to media_markers for ownership tracking
--
-- P3-S1: Enables per-user marker ownership so deleteMarker() can verify that
-- the authenticated user owns the marker before allowing deletion.
--
-- - user_id: CHAR(36) nullable — references users.id. NULL for legacy markers
--   (created before this migration) which are treated as system-owned and
--   deletable by any authenticated user.
--
-- Backwards compatibility: existing markers have user_id = NULL (system markers).
-- New markers created via createMarker() always have user_id set from context.

ALTER TABLE media_markers
    ADD COLUMN user_id CHAR(36) NULL COMMENT 'Owning user (NULL = legacy system marker)' AFTER label,
    ADD INDEX idx_user_id (user_id);
