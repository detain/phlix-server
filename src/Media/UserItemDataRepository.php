<?php

declare(strict_types=1);

namespace Phlix\Media;

use Phlix\Auth\Dto\UserRow;
use Phlix\Common\Util\RowMap;
use Workerman\MySQL\Connection;

/**
 * Per-user favorites + ratings data access for media items (E10).
 *
 * Persists, for each (user, media item) pair, whether the user has favorited
 * the item, an optional personal rating in the inclusive range 1-10, and a love
 * level in the inclusive range 0-3 (a separate axis from favorite/rating). Backs
 * the favorite/rating/like endpoints on
 * {@see \Phlix\Server\WebPortal\WebPortalRouter} and the `user_data` block on
 * the media-detail response.
 *
 * Data access mirrors {@see \Phlix\Auth\UserRepository} /
 * {@see \Phlix\Auth\WatchHistory} exactly: a single
 * {@see \Workerman\MySQL\Connection} with positional `?` placeholders bound via
 * a flat ordered array (`$db->query($sql, [$a, $b])`). Upserts use
 * `INSERT ... ON DUPLICATE KEY UPDATE`, the convention used by
 * {@see \Phlix\Auth\UserRepository::updateSettings()}.
 *
 * @author Phlix Team
 * @version 1.0.0
 *
 * @property Connection $db Database connection instance
 */
class UserItemDataRepository
{
    /** Lowest permitted personal rating (inclusive). */
    public const MIN_RATING = 1;

    /** Highest permitted personal rating (inclusive). */
    public const MAX_RATING = 10;

    /** Lowest permitted love level (inclusive; 0 = not loved). */
    public const MIN_LIKE = 0;

    /** Highest permitted love level (inclusive; 3 = most loved). */
    public const MAX_LIKE = 3;

    /** @var Connection Database connection for MySQL queries */
    private Connection $db;

    /**
     * Create a new UserItemDataRepository instance.
     *
     * @param Connection $db Workerman MySQL connection instance
     */
    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * Read the per-user favorite/rating data for a single media item.
     *
     * @param string $userId User UUID.
     * @param string $itemId Media item UUID.
     *
     * @return array{favorite: bool, rating: int|null, like_level: int}|null The
     *         user's data for the item, or null when no row exists (the user has
     *         never favorited, rated, or loved it). `like_level` is 0 when the
     *         column is NULL.
     */
    public function getItemData(string $userId, string $itemId): ?array
    {
        $result = $this->db->query(
            "SELECT favorite, rating, like_level FROM user_item_data WHERE user_id = ? AND item_id = ?",
            [$userId, $itemId]
        );

        $row = UserRow::firstFromMixed($result);
        if ($row === null) {
            return null;
        }

        return [
            'favorite' => (bool) UserRow::int($row, 'favorite', 0),
            'rating' => $this->coerceRating($row['rating'] ?? null),
            'like_level' => UserRow::int($row, 'like_level', 0),
        ];
    }

    /**
     * Set (or clear) the favorite flag for a user/item pair.
     *
     * Upserts so that toggling a favorite on an item the user has only rated
     * (or vice versa) preserves the other column.
     *
     * @param string $userId   User UUID.
     * @param string $itemId   Media item UUID.
     * @param bool   $favorite Whether the item should be a favorite.
     *
     * @return void
     */
    public function setFavorite(string $userId, string $itemId, bool $favorite): void
    {
        $this->db->query(
            "INSERT INTO user_item_data (user_id, item_id, favorite)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE favorite = VALUES(favorite)",
            [$userId, $itemId, $favorite ? 1 : 0]
        );
    }

    /**
     * Set (or clear) the user's personal rating for an item.
     *
     * @param string   $userId User UUID.
     * @param string   $itemId Media item UUID.
     * @param int|null $rating Rating in the inclusive range 1-10, or null to
     *                         clear the rating.
     *
     * @return void
     *
     * @throws \InvalidArgumentException When $rating is non-null and outside the
     *         inclusive range {@see self::MIN_RATING}-{@see self::MAX_RATING}.
     */
    public function setRating(string $userId, string $itemId, ?int $rating): void
    {
        if ($rating !== null && ($rating < self::MIN_RATING || $rating > self::MAX_RATING)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'rating must be between %d and %d (inclusive) or null, got %d',
                    self::MIN_RATING,
                    self::MAX_RATING,
                    $rating
                )
            );
        }

        $this->db->query(
            "INSERT INTO user_item_data (user_id, item_id, rating)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE rating = VALUES(rating)",
            [$userId, $itemId, $rating]
        );
    }

    /**
     * Set the user's love level for an item.
     *
     * Love is a separate axis from favorite (bool) and rating (1-10): a TINYINT
     * in the inclusive range 0-3 (0 = not loved … 3 = most loved). Upserts so
     * that setting the love level preserves the favorite/rating columns. The
     * 0-3 range is enforced here in PHP (mirroring {@see self::setRating()}'s
     * 1-10 enforcement); the DB column has no CHECK constraint.
     *
     * @param string $userId User UUID.
     * @param string $itemId Media item UUID.
     * @param int    $level  Love level in the inclusive range
     *                       {@see self::MIN_LIKE}-{@see self::MAX_LIKE}.
     *
     * @return void
     *
     * @throws \InvalidArgumentException When $level is outside the inclusive
     *         range {@see self::MIN_LIKE}-{@see self::MAX_LIKE}.
     */
    public function setLikeLevel(string $userId, string $itemId, int $level): void
    {
        if ($level < self::MIN_LIKE || $level > self::MAX_LIKE) {
            throw new \InvalidArgumentException(
                sprintf(
                    'like_level must be between %d and %d (inclusive), got %d',
                    self::MIN_LIKE,
                    self::MAX_LIKE,
                    $level
                )
            );
        }

        $this->db->query(
            "INSERT INTO user_item_data (user_id, item_id, like_level)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE like_level = VALUES(like_level)",
            [$userId, $itemId, $level]
        );
    }

    /**
     * List a user's favorited media items, most-recently-updated first.
     *
     * Joins media_items so callers can render the favorites screen without a
     * follow-up per-item lookup. Items that have been rated but NOT favorited
     * are excluded.
     *
     * @param string $userId User UUID.
     * @param int    $limit  Max rows to return.
     * @param int    $offset Rows to skip for pagination.
     *
     * @return list<array<string, mixed>> Joined favorite rows (each carrying the
     *         media item's id/name/type/metadata_json plus rating).
     */
    public function getFavorites(string $userId, int $limit = 50, int $offset = 0): array
    {
        $result = $this->db->query(
            "SELECT uid.item_id, uid.rating, uid.updated_at,
                    mi.id AS media_item_id, mi.name AS media_name,
                    mi.type AS media_type, mi.metadata_json
             FROM user_item_data uid
             JOIN media_items mi ON uid.item_id = mi.id
             WHERE uid.user_id = ? AND uid.favorite = 1
             ORDER BY uid.updated_at DESC
             LIMIT ? OFFSET ?",
            [$userId, $limit, $offset]
        );

        return RowMap::listFromMixed($result);
    }

    /**
     * Delete all per-user data for a media item.
     *
     * Called when a media item is removed so no orphaned favorite/rating rows
     * survive (the ON DELETE CASCADE foreign key covers DB-level deletes; this
     * is the explicit application-level path).
     *
     * @param string $itemId Media item UUID.
     *
     * @return void
     */
    public function deleteByItem(string $itemId): void
    {
        $this->db->query(
            "DELETE FROM user_item_data WHERE item_id = ?",
            [$itemId]
        );
    }

    /**
     * Coerce a raw `rating` column value into an int or null.
     *
     * @param mixed $value Raw column value (string from the driver, int, or null).
     *
     * @return int|null Null when the column is NULL/non-numeric, else the int.
     */
    private function coerceRating(mixed $value): ?int
    {
        if ($value === null || !is_numeric($value)) {
            return null;
        }
        return (int) $value;
    }
}
