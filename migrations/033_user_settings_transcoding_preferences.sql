-- Migration: 033_user_settings_transcoding_preferences.sql
-- Description: Add the `transcoding_preferences` column to user_settings.
--
-- UserRepository::updateSettings() (src/Auth/UserRepository.php lines 445-466)
-- builds an INSERT ... ON DUPLICATE KEY UPDATE upsert whose column list is
-- assembled at runtime. When the caller supplies a `transcoding_preferences`
-- array it appends 'transcoding_preferences' to both the INSERT column list and
-- the ON DUPLICATE KEY UPDATE clause and stores json_encode() of the value. The
-- original user_settings table (001_initial_schema.sql, widened by 002 with
-- default_content_rating) never defined the column, so saving settings with a
-- transcoding_preferences payload failed at runtime with:
--   SQLSTATE[42S22]: Column not found: 1054 Unknown column 'transcoding_preferences'
-- This path is reachable from the portal API: WebPortalRouter's settings
-- allow-list (src/Server/WebPortal/WebPortalRouter.php line 909) forwards the
-- 'transcoding_preferences' key straight from the request body into
-- updateSettings(). (The read path, getSettings() line 372, uses SELECT * and a
-- guarded isset()/json_decode(), so reads silently no-op when the column is
-- absent -- only the write 500s, matching the symptom.)
--
-- Stored as JSON: the value is json_encode() of a transcoding-options array on
-- write and json_decode()'d on read. NULL means the user has never saved any
-- transcoding preferences. Mirrors the nullable-JSON convention already used by
-- users.provider_data (migration 009) and media_items.metadata_json (001).
--
-- NOTE: this is a bare `JSON NULL` with NO explicit DEFAULT clause. MySQL 8
-- rejects a literal DEFAULT on JSON/TEXT/BLOB columns with error 1101 ("can't
-- have a default value"), which the migration runner would NOT downgrade (it is
-- not a duplicate-column/duplicate-key error) -- so an explicit `DEFAULT NULL`
-- here would hard-fail and the column would never be created. A nullable column
-- implicitly defaults to NULL regardless.
--
-- Idempotent: re-running ADD COLUMN raises "Duplicate column name", which the
-- migration runner downgrades to a note (see src/Common/Database/MigrationRunner.php).

-- NOTE: keep this statement free of semicolons inside string literals. The
-- migration runner strips comments then splits on `;` (see MigrationRunner::
-- splitStatements), so a `;` inside the COMMENT text would shred the ALTER.
ALTER TABLE user_settings
    ADD COLUMN transcoding_preferences JSON NULL
        COMMENT 'Per-user transcoding options as JSON written by UserRepository updateSettings, NULL means never set'
        AFTER default_content_rating;
