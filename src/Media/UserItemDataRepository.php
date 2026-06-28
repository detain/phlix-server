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
 * the item and an optional personal rating in the inclusive range 1-10. Backs
 * the favorite/rating endpoints on
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
     * @return array{favorite: bool, rating: int|null}|null The user's data for
     *         the item, or null when no row exists (the user has never
     *         favorited or rated it).
     */
    public function getItemData(string $userId, string $itemId): ?array
    {
        $result = $this->db->query(
            "SELECT favorite, rating FROM user_item_data WHERE user_id = ? AND item_id = ?",
            [$userId, $itemId]
        );

        $row = UserRow::firstFromMixed($result);
        if ($row === null) {
            return null;
        }

        return [
            'favorite' => (bool) UserRow::int($row, 'favorite', 0),
            'rating' => $this->coerceRating($row['rating'] ?? null),
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
