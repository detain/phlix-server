-- Migration: 117_stats_read_and_retention_indexes.sql
--
-- R19-10: the four secondary indexes for the two stats tables that shipped
-- with a PRIMARY KEY only (migrations/019_stats_schema.sql) and have been
-- full-scanning ever since:
--
--   stats_library_changes.changed_at
--     DashboardService::getRecentLibraryEvents() reads
--     ORDER BY changed_at DESC LIMIT ?; the retention sweeper
--     (StatsCollector::sweepRetention()) range-scans changed_at < cutoff.
--
--   stats_user_activity.occurred_at
--     Same dual role for the activity table: the sweeper's cutoff scan.
--
--   stats_user_activity.(activity_type, occurred_at)
--     DashboardService::getRecentAuthEvents() filters
--     activity_type IN ('login','logout') ORDER BY occurred_at DESC LIMIT ?
--     and NewsletterGenerator::getRecipientUserIds() filters
--     activity_type = 'login' AND occurred_at >= DATE_SUB(NOW(), ...). The
--     composite serves both: equality/range on the leading column, sorted on
--     the second.
--
--   stats_user_activity.user_id
--     Per-account history lookups and future account-deletion cascades; the
--     table has no FK on user_id (019 kept the stats tables reference-free on
--     purpose), so nothing else indexes it.
--
-- Replay: each ADD INDEX raises duplicate-key (1061) on re-run, which the
-- migration runner downgrades to a note (MigrationRunner::isAlreadyApplied).

ALTER TABLE `stats_library_changes` ADD INDEX `idx_stats_library_changes_changed_at` (`changed_at`);
ALTER TABLE `stats_user_activity` ADD INDEX `idx_stats_user_activity_occurred_at` (`occurred_at`);
ALTER TABLE `stats_user_activity` ADD INDEX `idx_stats_user_activity_type_occurred` (`activity_type`, `occurred_at`);
ALTER TABLE `stats_user_activity` ADD INDEX `idx_stats_user_activity_user_id` (`user_id`);
