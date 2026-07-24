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

CREATE TABLE IF NOT EXISTS plugin_settings (
    plugin_name VARCHAR(64) NOT NULL PRIMARY KEY,
    settings_json JSON NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
