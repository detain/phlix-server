-- Migration: 039_user_item_data.sql
-- Description: Per-user favorites + ratings for media items (E10).
--
-- The server had no per-user "favorite" flag or personal rating until now. This
-- table records, for each (user, media item) pair, whether the user has marked
-- the item as a favorite and an optional personal rating (1-10). It backs:
--   * POST   /api/v1/media/{id}/favorite   -- mark favorite
--   * DELETE /api/v1/media/{id}/favorite   -- un-favorite
--   * PUT    /api/v1/media/{id}/rating     -- set/clear personal rating
--   * DELETE /api/v1/media/{id}/rating     -- clear personal rating
--   * GET    /api/v1/media/{id}            -- now carries a `user_data` block
-- via src/Media/UserItemDataRepository.php.
--
-- Keyed per USER (not per profile): favorites/ratings follow the account, like
-- user_settings, rather than the per-profile watch_history. The (user_id,
-- item_id) pair is the PRIMARY KEY so the repository upserts with
-- INSERT ... ON DUPLICATE KEY UPDATE (the convention used by user_settings and
-- AudiobookProgressStore).
--
-- The 1-10 rating range is enforced in PHP (UserItemDataRepository::setRating
-- throws InvalidArgumentException) rather than with a DB CHECK constraint, to
-- avoid MySQL-version inconsistency (older MySQL silently ignores CHECK).
-- `rating` is nullable: NULL means "no personal rating".
--
-- Both foreign keys cascade on delete so removing a user or a media item also
-- removes their per-user item data (no orphan rows). deleteByItem() is also
-- called explicitly when a media item is removed.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS is safe to re-run, matching the
-- apply-all-every-time contract of scripts/run-migrations.php. Each statement is
-- split on `;` by the runner, so keep them separate and free of semicolons in
-- literals/comments.

CREATE TABLE IF NOT EXISTS user_item_data (
    user_id     CHAR(36)  NOT NULL,
    item_id     CHAR(36)  NOT NULL,
    favorite    BOOLEAN   NOT NULL DEFAULT FALSE,
    rating      INT       NULL,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, item_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES media_items(id) ON DELETE CASCADE,
    INDEX idx_user_updated   (user_id, updated_at),
    INDEX idx_item_favorite  (item_id, favorite)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
