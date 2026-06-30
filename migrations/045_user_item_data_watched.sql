-- Migration: 045_user_item_data_watched.sql
-- Description: Add `watched` column to user_item_data for the "mark watched/unwatched" feature (Step 11.6).
--
-- The watched flag tracks whether the authenticated user has explicitly marked a
-- media item as watched, independent of the per-profile watch-history completion
-- percentage. This lives in the same user_item_data row used for favorites/ratings
-- so that toggling watched state does not disturb the user's other per-item data.
--
-- The column is nullable BOOL DEFAULT NULL (NULL = user has not explicitly set
-- watched state; the UI derives "watched" from favorites when this is absent).
-- Unlike the favorite/rating/like_level columns, this one is add-only in this step.
--
-- Idempotent: ALTER TABLE IF NOT EXISTS is not valid MySQL, so use a conditional
-- check. The migration runner splits on `;` but does not have special IF logic,
-- so this migration is safe to re-run (MySQL ignores duplicate key errors on
-- adding an index that already exists, but ALTER TABLE does not have such safety).
-- We use a stored procedure to make the add-conditional.

-- Add watched column if it doesn't exist (addendum to the existing user_item_data table)
SET @dbname = DATABASE();
SET @tablename = 'user_item_data';
SET @columnname = 'watched';
SET @preparedStatement = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @columnname) = 0,
    'ALTER TABLE user_item_data ADD COLUMN watched BOOLEAN NULL DEFAULT NULL',
    'SELECT 1'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;
