<?php

/**
 * Phlix media server component: Media.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media;

use Phlix\Auth\Dto\UserRow;
use Phlix\Auth\ProfileNotOwnedException;
use Phlix\Auth\UserProfileManager;
use Phlix\Common\Util\RowMap;
use Workerman\MySQL\Connection;

/**
 * Per-PROFILE favorites + ratings data access for media items (E10, re-scoped by S79).
 *
 * Persists, for each (profile, media item) pair, whether the profile has
 * favorited the item, an optional personal rating in the inclusive range 1-10,
 * and a like level on the signed thumbs axis in the inclusive range −2..2
 * (−2 = strongly dislike, −1 = dislike, 0 = not set, 1 = like, 2 = love; a
 * separate axis from favorite/rating). Backs the favorite/rating/like endpoints
 * on {@see \Phlix\Server\WebPortal\WebPortalRouter} and the `user_data` block on
 * the media-detail response.
 *
 * ## S79 — this used to be account-level, and the change is not optional
 *
 * Migration `039_user_item_data.sql` deliberately keyed this table on `user_id`
 * alone, so every profile on an account shared one set of favorites. Migration
 * `100_user_item_data_profile_id.sql` adds `profile_id`, backfills every existing
 * row under the account's active-or-first profile, and widens the primary key to
 * `(user_id, profile_id, item_id)`.
 *
 * ⚠ `profile_id` is `NOT NULL` **with no default**, so the pre-S79 upserts here —
 * which named only `(user_id, item_id, …)` — now fail outright with MySQL error
 * 1364, *Field 'profile_id' doesn't have a default value*. Shipping migration 100
 * without this class's change would silently break every favorite, rating, like
 * and watched write in the product. Verified against MySQL 8.0.46 under the
 * default `STRICT_TRANS_TABLES` sql_mode.
 *
 * ## How the profile is chosen
 *
 * Every method takes an optional `$profileId`. It is resolved — never trusted —
 * through {@see UserProfileManager::resolveProfileIdForUser()}, which verifies
 * that a supplied id belongs to `$userId` and otherwise falls back to the
 * account's active-or-first profile using the same ordering migration 100's
 * backfill used. Passing `null` therefore reproduces the pre-S79 behaviour
 * exactly for a single-profile account, which is every account immediately after
 * migration 100 has run.
 *
 * Data access mirrors {@see \Phlix\Auth\UserRepository} /
 * {@see \Phlix\Auth\WatchHistory} exactly: a single
 * {@see \Workerman\MySQL\Connection} with positional `?` placeholders bound via
 * a flat ordered array (`$db->query($sql, [$a, $b])`). Upserts use
 * `INSERT ... ON DUPLICATE KEY UPDATE`, the convention used by
 * {@see \Phlix\Auth\UserRepository::updateSettings()}.
 *
 * @author Phlix Team
 * @version 2.0.0
 *
 * @property Connection $db Database connection instance
 */
class UserItemDataRepository
{
    /** Lowest permitted personal rating (inclusive). */
    public const MIN_RATING = 1;

    /** Highest permitted personal rating (inclusive). */
    public const MAX_RATING = 10;

    /** Lowest permitted like level (inclusive; −2 = strongly dislike) on the thumbs axis. */
    public const MIN_LIKE = -2;

    /** Highest permitted like level (inclusive; 2 = love) on the thumbs axis. */
    public const MAX_LIKE = 2;

    /** @var Connection Database connection for MySQL queries */
    private Connection $db;

    /**
     * Resolves and ownership-checks the profile every row is scoped to.
     *
     * ⚠ REQUIRED, never `?UserProfileManager $x = null`. PHP-DI's `autowire()`
     * silently skips optional constructor parameters, which would leave this null
     * and turn every write into a fatal — and the reads into an unscoped leak
     * across profiles. See `AuthServicesProvider`'s note on the same trap for
     * `UserProfileManager::$settings`.
     *
     * @var UserProfileManager
     */
    private UserProfileManager $profiles;

    /**
     * Create a new UserItemDataRepository instance.
     *
     * @param Connection         $db       Workerman MySQL connection instance.
     * @param UserProfileManager $profiles Profile scope resolver (S79). Required.
     */
    public function __construct(Connection $db, UserProfileManager $profiles)
    {
        $this->db = $db;
        $this->profiles = $profiles;
    }

    /**
     * Read the profile's favorite/rating data for a single media item.
     *
     * @param string      $userId    User UUID.
     * @param string      $itemId    Media item UUID.
     * @param string|null $profileId Profile UUID to scope to, or null for the
     *                               account's active/first profile.
     *
     * @return array{favorite: bool, rating: int|null, like_level: int, watched: bool}|null The
     *         profile's data for the item, or null when no row exists (the profile
     *         has never favorited, rated, loved, or watched it). `like_level` is 0
     *         when the column is NULL; `watched` is false when the column is NULL.
     *
     * @throws ProfileNotOwnedException When `$profileId` is not owned by `$userId`.
     */
    public function getItemData(string $userId, string $itemId, ?string $profileId = null): ?array
    {
        $scope = $this->scope($userId, $profileId);

        $result = $this->db->query(
            "SELECT favorite, rating, like_level, watched FROM user_item_data
             WHERE user_id = ? AND profile_id = ? AND item_id = ?",
            [$userId, $scope, $itemId]
        );

        $row = UserRow::firstFromMixed($result);
        if ($row === null) {
            return null;
        }

        return [
            'favorite' => (bool) UserRow::int($row, 'favorite', 0),
            'rating' => $this->coerceRating($row['rating'] ?? null),
            'like_level' => UserRow::int($row, 'like_level', 0),
            // NULL/absent watched → false (the column is nullable; migration 045).
            'watched' => (bool) UserRow::int($row, 'watched', 0),
        ];
    }

    /**
     * Set (or clear) the favorite flag for a profile/item pair.
     *
     * Upserts so that toggling a favorite on an item the profile has only rated
     * (or vice versa) preserves the other column.
     *
     * @param string      $userId    User UUID.
     * @param string      $itemId    Media item UUID.
     * @param bool        $favorite  Whether the item should be a favorite.
     * @param string|null $profileId Profile UUID to scope to, or null for the
     *                               account's active/first profile.
     *
     * @return void
     *
     * @throws ProfileNotOwnedException When `$profileId` is not owned by `$userId`.
     */
    public function setFavorite(string $userId, string $itemId, bool $favorite, ?string $profileId = null): void
    {
        $this->db->query(
            "INSERT INTO user_item_data (user_id, profile_id, item_id, favorite)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE favorite = VALUES(favorite)",
            [$userId, $this->scope($userId, $profileId), $itemId, $favorite ? 1 : 0]
        );
    }

    /**
     * Set (or clear) the user's personal rating for an item.
     *
     * @param string      $userId    User UUID.
     * @param string      $itemId    Media item UUID.
     * @param int|null    $rating    Rating in the inclusive range 1-10, or null to
     *                               clear the rating.
     * @param string|null $profileId Profile UUID to scope to, or null for the
     *                               account's active/first profile.
     *
     * @return void
     *
     * @throws \InvalidArgumentException When $rating is non-null and outside the
     *         inclusive range {@see self::MIN_RATING}-{@see self::MAX_RATING}.
     * @throws ProfileNotOwnedException When `$profileId` is not owned by `$userId`.
     */
    public function setRating(string $userId, string $itemId, ?int $rating, ?string $profileId = null): void
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
            "INSERT INTO user_item_data (user_id, profile_id, item_id, rating)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE rating = VALUES(rating)",
            [$userId, $this->scope($userId, $profileId), $itemId, $rating]
        );
    }

    /**
     * Set the user's like level for an item.
     *
     * Like is a separate axis from favorite (bool) and rating (1-10): a signed
     * TINYINT on the thumbs axis in the inclusive range −2..2 (−2 = strongly
     * dislike, −1 = dislike, 0 = not set, 1 = like, 2 = love). Upserts so that
     * setting the like level preserves the favorite/rating columns. The −2..2
     * range is enforced here in PHP (mirroring {@see self::setRating()}'s 1-10
     * enforcement); the DB column has no CHECK constraint.
     *
     * @param string      $userId    User UUID.
     * @param string      $itemId    Media item UUID.
     * @param int         $level     Like level in the inclusive range
     *                               {@see self::MIN_LIKE}..{@see self::MAX_LIKE} (−2..2).
     * @param string|null $profileId Profile UUID to scope to, or null for the
     *                               account's active/first profile.
     *
     * @return void
     *
     * @throws \InvalidArgumentException When $level is outside the inclusive
     *         range {@see self::MIN_LIKE}..{@see self::MAX_LIKE}.
     * @throws ProfileNotOwnedException When `$profileId` is not owned by `$userId`.
     */
    public function setLikeLevel(string $userId, string $itemId, int $level, ?string $profileId = null): void
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
            "INSERT INTO user_item_data (user_id, profile_id, item_id, like_level)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE like_level = VALUES(like_level)",
            [$userId, $this->scope($userId, $profileId), $itemId, $level]
        );
    }

    /**
     * List a profile's favorited media items, most-recently-updated first.
     *
     * Joins media_items so callers can render the favorites screen without a
     * follow-up per-item lookup. Items that have been rated but NOT favorited
     * are excluded.
     *
     * @param string      $userId    User UUID.
     * @param int         $limit     Max rows to return.
     * @param int         $offset    Rows to skip for pagination.
     * @param string|null $profileId Profile UUID to scope to, or null for the
     *                               account's active/first profile.
     *
     * @return list<array<string, mixed>> Joined favorite rows (each carrying the
     *         media item's id/name/type/metadata_json plus rating, like_level and watched).
     *
     * @throws ProfileNotOwnedException When `$profileId` is not owned by `$userId`.
     */
    public function getFavorites(
        string $userId,
        int $limit = 50,
        int $offset = 0,
        ?string $profileId = null
    ): array {
        $result = $this->db->query(
            "SELECT uid.item_id, uid.rating, uid.like_level, uid.watched, uid.updated_at,
                    mi.id AS media_item_id, mi.name AS media_name,
                    mi.type AS media_type, mi.metadata_json
             FROM user_item_data uid
             JOIN media_items mi ON uid.item_id = mi.id
             WHERE uid.user_id = ? AND uid.profile_id = ? AND uid.favorite = 1
             ORDER BY uid.updated_at DESC
             LIMIT ? OFFSET ?",
            [$userId, $this->scope($userId, $profileId), $limit, $offset]
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
     * Set the "watched" flag for a user/item pair.
     *
     * This persists in the `user_item_data` table (the same row used for
     * favorites/ratings). A separate `setWatched` upsert is used so that
     * toggling the watched state does not disturb the user's favorite/rating.
     *
     * @param string      $userId    User UUID.
     * @param string      $itemId    Media item UUID.
     * @param bool        $watched   Whether the item has been watched.
     * @param string|null $profileId Profile UUID to scope to, or null for the
     *                               account's active/first profile.
     *
     * @return void
     *
     * @throws ProfileNotOwnedException When `$profileId` is not owned by `$userId`.
     */
    public function setWatched(string $userId, string $itemId, bool $watched, ?string $profileId = null): void
    {
        $this->db->query(
            "INSERT INTO user_item_data (user_id, profile_id, item_id, watched)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE watched = VALUES(watched)",
            [$userId, $this->scope($userId, $profileId), $itemId, $watched ? 1 : 0]
        );
    }

    /**
     * Resolve the profile every statement in this class is scoped to.
     *
     * A thin, single-purpose delegate so the ownership check cannot be forgotten
     * on one method: every read and every write in this class routes through it,
     * and none of them ever interpolates a caller-supplied `profile_id` directly.
     *
     * @param string      $userId    Authenticated account UUID.
     * @param string|null $profileId Caller-supplied profile UUID, or null.
     *
     * @return string A profile UUID guaranteed to belong to `$userId`.
     *
     * @throws ProfileNotOwnedException When `$profileId` is not owned by `$userId`.
     */
    private function scope(string $userId, ?string $profileId): string
    {
        return $this->profiles->resolveProfileIdForUser($userId, $profileId);
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
