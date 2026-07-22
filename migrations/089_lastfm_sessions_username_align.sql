-- Migration 089: align `lastfm_sessions` with the catalog Last.fm plugin schema
-- (Wave 2 plugin consolidation).
--
-- The embedded Last.fm code that used to live in phlix-server (removed in the
-- consolidation) created `lastfm_sessions` WITHOUT the `username` / `expires_at`
-- columns. The catalog `phlix-plugin-lastfm` LastfmSessionRepository::save()
-- INSERTs a `username` column, so a box whose table pre-dates the consolidation
-- would fail an OAuth connect with "Unknown column 'username'". The plugin's own
-- migration uses CREATE TABLE IF NOT EXISTS, so it can NOT backfill a table that
-- already exists — hence this host migration does the alignment at deploy time.
--
-- Idempotent across every install state:
--   * CREATE TABLE IF NOT EXISTS builds the full (catalog-matching) schema on a
--     fresh box and is a silent no-op when the table already exists.
--   * The ADD COLUMN / ADD INDEX clauses backfill a pre-existing table that
--     lacks them. On a replay (or the fresh box just created above) each clause
--     raises duplicate-column (1060) / duplicate-key (1061), which the migration
--     runner downgrades to a note (see 077 + MigrationRunner::isAlreadyApplied).
-- One clause per statement — MySQL 8 rejects `IF NOT EXISTS` on ADD COLUMN/INDEX.

CREATE TABLE IF NOT EXISTS `lastfm_sessions` (
    `user_id`       VARCHAR(36)  NOT NULL COMMENT 'Phlix user UUID',
    `session_key`   VARCHAR(64)  NOT NULL COMMENT 'Last.fm session key from auth.getSession',
    `username`      VARCHAR(64)  NOT NULL DEFAULT '' COMMENT 'Last.fm username (for display)',
    `connected_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the user authorised access',
    `expires_at`    DATETIME     NULL COMMENT 'When the session expires (null = does not expire)',
    PRIMARY KEY (`user_id`),
    KEY `idx_lastfm_sessions_username` (`username`),
    KEY `idx_lastfm_sessions_connected_at` (`connected_at`)
)
ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `lastfm_sessions` ADD COLUMN `username` VARCHAR(64) NOT NULL DEFAULT '' COMMENT 'Last.fm username (for display)' AFTER `session_key`;
ALTER TABLE `lastfm_sessions` ADD COLUMN `expires_at` DATETIME NULL COMMENT 'When the session expires (null = does not expire)' AFTER `connected_at`;
ALTER TABLE `lastfm_sessions` ADD INDEX `idx_lastfm_sessions_username` (`username`);
ALTER TABLE `lastfm_sessions` ADD INDEX `idx_lastfm_sessions_connected_at` (`connected_at`);
