-- SV-4.9: Migration ledger — tracks which schema migrations have been applied.
--
-- This table records which migration files have been applied so the runner can
-- skip them on subsequent boots. The idempotent duplicate-key/duplicate-column
-- error handling remains the primary safety net; this ledger is additive — it
-- does NOT remove the existing error-squelch behavior.
--
-- ## Rewrite-class migrations
--
-- A rewrite-class migration completely replaces a table's data model. It cannot
-- be applied incrementally and must run atomically. Examples:
--   - A column split (e.g. media_items.source → media_streams rows)
--   - A JSON blob denormalization (e.g. metadata_json['source'] column added)
--   - A stored-generated-column migration with dependent indexes
--
-- When writing a rewrite-class migration:
--   1. Wrap the entire transform in a single transaction (BEGIN...COMMIT).
--   2. Before BEGIN, verify the precondition:
--        - Check the target column/table does NOT exist
--        - OR check schema_migrations does NOT have this migration recorded
--   3. The runner AUTOMATICALLY records the file in schema_migrations after a
--      clean apply (INSERT ... ON DUPLICATE KEY UPDATE with the file's MD5), so
--      a migration does not need to INSERT its own row. If it does (e.g. to gate
--      a precondition), the runner's own record — keyed on the file's checksum —
--      wins via ON DUPLICATE KEY UPDATE.
--   4. Document the rewrite in this header so future operators understand
--      what state the migration assumes.
--
-- ## Design
--
-- - `name`: migration filename without .sql (PRIMARY KEY).
-- - `applied_at`: UNIX timestamp at time of application.
-- - `checksum`: MD5 of the .sql file at application time. If the file's MD5
--   no longer matches at the next boot, the runner logs a warning — the
--   operator may have legitimately edited a migration for a hotfix, but the
--   divergence should be visible.
--
-- No `down` column — downgrades are new forward-only migration files that
-- reverse the change (e.g. 077_undo_076_*.sql), each recorded by the runner.
--
-- @see \Phlix\Common\Database\MigrationRunner::run() consults AND records this
--      table (skips a recorded+checksum-matching file, warns + re-applies on a
--      checksum divergence, records each cleanly-applied file). The runner
--      also bootstrap-creates this table before its first read, so it does not
--      rely on `076` running first. Invoked by both scripts/run-migrations.php
--      and `bin/phlix migrate`.

CREATE TABLE IF NOT EXISTS schema_migrations (
    name        VARCHAR(255) NOT NULL,
    applied_at  INT UNSIGNED NOT NULL,
    checksum    CHAR(32) NOT NULL,
    PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
