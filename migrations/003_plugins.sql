-- Phlex Media Server — Plugin loader (Step A.4)
-- Tracks plugins that have been installed via Phlex Plugins PluginLoader.
-- One row per installed plugin keyed on the manifest name. The enabled
-- flag drives the auto-enable bootstrap that runs at container build
-- time. manifest_json stores the full parsed manifest so the loader can
-- rehydrate it without re-reading the on-disk plugin.json file.

CREATE TABLE IF NOT EXISTS plugins (
    id CHAR(36) NOT NULL PRIMARY KEY,
    name VARCHAR(64) NOT NULL UNIQUE,
    version VARCHAR(32) NOT NULL,
    type VARCHAR(32) NOT NULL,
    entry VARCHAR(255) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    installed_at DATETIME NOT NULL,
    settings_json JSON NULL,
    manifest_json JSON NOT NULL,
    INDEX idx_plugins_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
