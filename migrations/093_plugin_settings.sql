-- Migration: 093_plugin_settings.sql
-- Description: DB-backed settings store for the bundled auth-provider plugins
-- (OIDC / LDAP / GitHub). S48 replaces each plugin's hand-rolled `settings.json`
-- with a row here so provider configuration persists in the DATABASE, not a file
-- on disk. Resident-memory Workerman workers may run on a read-only/ephemeral
-- filesystem, and a per-plugin settings.json is invisible to the other workers,
-- so a shared DB row is the correct home. One row per plugin, keyed by name.
--
-- NOTE: this is DELIBERATELY not the catalog `plugins` table (migration 003).
-- The bundled auth providers are registered through AuthProviderBootstrapper's
-- `auth.<name>.enabled` server-setting flags, NOT the PluginLoader bootstrap
-- pipeline. Giving them catalog rows would double-register the provider
-- (PluginLoader::bootstrapEnabled() -> onEnable() -> registerProvider() AND
-- AuthProviderBootstrapper::registerEnabledProviders()) and expose bundled
-- providers in the plugin catalog UI as if they were uninstallable downloads.
-- A dedicated key/value settings table is the contained, low-risk DB store.
--
-- IDEMPOTENT, and it has to be. MigrationRunner keeps a `schema_migrations`
-- checksum ledger, so a normal deploy skips this file after the first apply — but
-- two real paths bypass that ledger: a raw `mysql < 093_plugin_settings.sql`
-- replay, and a database whose ledger row was lost/deleted (or restored from a
-- backup taken before it). `CREATE TABLE IF NOT EXISTS` makes both a no-op that
-- PRESERVES existing rows rather than an error or a wipe. Verified end to end on a
-- scratch MySQL 8.0 (S48 TestEngineer, 2026-07-25): applied on top of master head,
-- re-applied twice through the runner, replayed raw with the ledger bypassed
-- (`Note 1050: Table 'plugin_settings' already exists`, exit 0), and re-applied
-- with the ledger row deleted — 0 errors each time, and a pre-existing settings row
-- still present afterwards. Asserted in CI-runnable form by
-- PluginSettingsRealDbIntegrationTest::testMigration093IsIdempotentAndPreservesRows;
-- changing this to a bare `CREATE TABLE` turns 9 tests RED.
--
-- Do NOT add a destructive statement (DROP/TRUNCATE/ALTER … DROP COLUMN) to this
-- file: on the ledger-bypassed paths above it would run against a populated table
-- and destroy live provider credentials.

CREATE TABLE IF NOT EXISTS plugin_settings (
    plugin_name VARCHAR(64) NOT NULL PRIMARY KEY,
    settings_json JSON NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
