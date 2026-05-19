<?php

declare(strict_types=1);

namespace Phlex\Plugins\Scrobbler\Lastfm;

use Workerman\MySQL\Connection;

/**
 * DB-backed store for per-user Last.fm session keys.
 *
 * Each row in `lastfm_sessions` ties one Phlex `user_id` to one Last.fm
 * `session_key` and the timestamp the user authorised access. Session
 * keys do not expire on the Last.fm side unless the user revokes them
 * from their account settings.
 *
 * @package Phlex\Plugins\Scrobbler\Lastfm
 * @since 0.15.0
 */
class LastfmSessionRepository
{
    /**
     * @param Connection $db Workerman MySQL connection (positional params).
     */
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Look up the persisted Last.fm session for a Phlex user.
     *
     * @param string $userId Phlex user UUID.
     *
     * @return array{user_id: string, session_key: string, connected_at: string}|null
     *         Session row or null when the user has not connected Last.fm.
     */
    public function findByUserId(string $userId): ?array
    {
        $rows = $this->db->query(
            'SELECT user_id, session_key, connected_at FROM lastfm_sessions WHERE user_id = ?',
            [$userId]
        );
        if (!is_array($rows) || count($rows) === 0) {
            return null;
        }
        $row = $rows[0];
        if (!is_array($row)) {
            return null;
        }
        $userIdVal = $row['user_id'] ?? null;
        $sessionKey = $row['session_key'] ?? null;
        $connectedAt = $row['connected_at'] ?? null;
        if (!is_string($userIdVal) || !is_string($sessionKey) || !is_string($connectedAt)) {
            return null;
        }
        return [
            'user_id'      => $userIdVal,
            'session_key'  => $sessionKey,
            'connected_at' => $connectedAt,
        ];
    }

    /**
     * Persist (or refresh) a user's Last.fm session key.
     *
     * Uses an `INSERT ... ON DUPLICATE KEY UPDATE` so reconnecting silently
     * replaces the previous key.
     *
     * @param string $userId     Phlex user UUID.
     * @param string $sessionKey Session key returned by `auth.getSession`.
     */
    public function save(string $userId, string $sessionKey): void
    {
        $this->db->query(
            'INSERT INTO lastfm_sessions (user_id, session_key, connected_at)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE session_key = VALUES(session_key),
                                     connected_at = VALUES(connected_at)',
            [$userId, $sessionKey]
        );
    }

    /**
     * Remove a user's Last.fm session — used when the user disconnects.
     *
     * @param string $userId Phlex user UUID.
     */
    public function delete(string $userId): void
    {
        $this->db->query('DELETE FROM lastfm_sessions WHERE user_id = ?', [$userId]);
    }
}
