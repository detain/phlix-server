-- Migration: 113_sessions_last_activity_index.sql
--
-- R19-03: index backing the session-idle scans.
--
-- SessionManager::cleanupStaleSessions() and ::getOnlineUsers() both range-scan
-- `sessions.last_activity` (SELECT id ... WHERE last_activity < / > cutoff).
-- The table only carried idx_user(user_id) and idx_device(device_id), so every
-- idle sweep was a full table scan. The cutoffs themselves are now computed by
-- the DATABASE clock (DATE_SUB(NOW(), INTERVAL ? SECOND)) so they cannot drift
-- against the NOW()-written column — see SessionManager for the clock law.
--
-- Replay: the ADD INDEX raises duplicate-key (1061) on re-run, which the
-- migration runner downgrades to a note (MigrationRunner::isAlreadyApplied).

ALTER TABLE `sessions` ADD INDEX `idx_sessions_last_activity` (`last_activity`);
