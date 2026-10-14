-- Migration: 116_active_streams_session_index.sql
--
-- R19-07: index for the session_id-only stream lifecycle queries.
--
-- StreamSessionService keys its heartbeat/teardown/existence checks on
-- session_id alone:
--   UPDATE active_streams SET last_heartbeat_at = NOW() WHERE session_id = ?
--   DELETE FROM active_streams WHERE session_id = ?
--   SELECT 1 FROM active_streams WHERE session_id = ?
-- The only composite carrying session_id was idx_profile_session
-- (profile_id, session_id) — session_id is its SECOND column, so none of these
-- queries can use it (leftmost-prefix rule) and each was a full scan of a
-- table touched on every playback heartbeat.
--
-- Replay: the ADD INDEX raises duplicate-key (1061) on re-run, which the
-- migration runner downgrades to a note (MigrationRunner::isAlreadyApplied).

ALTER TABLE `active_streams` ADD INDEX `idx_active_streams_session` (`session_id`);
