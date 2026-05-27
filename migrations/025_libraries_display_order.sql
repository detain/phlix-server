-- Adds the display_order column referenced by LibraryManager::getAllLibraries()
-- ("SELECT * FROM libraries ORDER BY display_order, name"). The original
-- `libraries` table in 001_initial_schema.sql never had this column; the
-- ORDER BY was added to the code without a matching schema migration.
--
-- Default 0 puts existing rows in insertion-order until they're reordered
-- via the admin UI.

ALTER TABLE libraries ADD COLUMN display_order INT NOT NULL DEFAULT 0;
ALTER TABLE libraries ADD INDEX idx_display_order (display_order);
