-- Migration: 026_server_settings.sql
-- Description: Server-wide settings store (Step 0.5).
--
-- A typed key/value table that backs the admin settings pages (Phase 1.3).
-- Today the `config/*.php` files are boot-time/read-only; this table lets an
-- admin persist *overrides* for a curated allow-list of settings without
-- editing files on disk. The runtime merge is: config value = default, a row
-- here = override (see Phlix\Admin\SettingsRepository::getEffective()).
--
-- `setting_key` is dotted (`<configFile>.<nested.path>`, e.g. `hwaccel.enabled`)
-- and carries a UNIQUE constraint so the repository's
-- `INSERT ... ON DUPLICATE KEY UPDATE` upsert is well-defined. `value_type`
-- records how `setting_value` (always stored as text) should be decoded back
-- into a PHP scalar/array: string | int | bool | float | json.
--
-- Idempotent: `CREATE TABLE IF NOT EXISTS` means the migration runner can
-- replay this file safely (the runner also downgrades duplicate-object errors
-- to notes — see scripts/run-migrations.php).

CREATE TABLE IF NOT EXISTS `server_settings` (
    `id` CHAR(36) NOT NULL PRIMARY KEY COMMENT 'UUID identifier',
    `setting_key` VARCHAR(191) NOT NULL COMMENT 'Dotted setting key, e.g. hwaccel.enabled',
    `setting_value` TEXT NOT NULL COMMENT 'Override value, serialised as text per value_type',
    `value_type` ENUM('string', 'int', 'bool', 'float', 'json') NOT NULL DEFAULT 'string'
        COMMENT 'How setting_value is decoded back into a PHP value',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Record creation timestamp',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        COMMENT 'Last-modified timestamp',
    UNIQUE KEY `uq_server_settings_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
