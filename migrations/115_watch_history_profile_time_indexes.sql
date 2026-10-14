-- Migration: 115_watch_history_profile_time_indexes.sql
--
-- R19-06: composite indexes for the per-profile history reads.
--
-- Every list read in WatchHistory filters on profile_id and orders by a time
-- column — getHistory()/getContinueWatching() by last_watched_at DESC,
-- getRecentlyCompleted() by completed_at DESC — and getTodayWatchTime() range-
-- scans last_watched_at for one profile. The table carried idx_profile
-- (profile_id alone), idx_last_watched (time alone, cross-profile) and the
-- UNIQUE idx_profile_media, so each read located the profile's rows via
-- idx_profile and then FILESORTED them. These two composites serve the
-- WHERE profile_id = ? ORDER BY <time> DESC shape directly (the single-column
-- prefix also covers plain profile_id equality, making idx_profile a redundant
-- prefix — left in place deliberately, same reasoning as migration 114).
--
-- Replay: each ADD INDEX raises duplicate-key (1061) on re-run, which the
-- migration runner downgrades to a note (MigrationRunner::isAlreadyApplied).

ALTER TABLE `watch_history` ADD INDEX `idx_watch_history_profile_last` (`profile_id`, `last_watched_at`);
ALTER TABLE `watch_history` ADD INDEX `idx_watch_history_profile_completed` (`profile_id`, `completed_at`);
