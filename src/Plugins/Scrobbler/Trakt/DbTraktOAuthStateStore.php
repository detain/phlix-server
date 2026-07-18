<?php

/**
 * Phlix media server component: Trakt.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Trakt;

use Phlix\Common\Uuid;
use Workerman\MySQL\Connection;

/**
 * DB-backed implementation of {@see TraktOAuthStateStore}.
 *
 * Stores Trakt OAuth PKCE state entries in a database table instead of $_SESSION,
 * which causes state leakage between Workerman workers/requests.
 *
 * Uses the unified `oauth_state_store` table with provider = 'trakt' and
 * data JSON containing {code_verifier}.
 *
 * Each entry has a 10-minute TTL to prevent stale state accumulation.
 * Expired entries are cleaned up on each {@see consume()} call.
 *
 * @since 0.16.0
 */
final class DbTraktOAuthStateStore implements TraktOAuthStateStore
{
    /**
     * Provider identifier for Trakt in the unified oauth_state_store table.
     */
    private const string PROVIDER = 'trakt';

    /**
     * Default TTL for state entries in seconds (10 minutes).
     */
    private const int TTL_SECONDS = 600;

    /**
     * Number of expired entries to delete per cleanup batch.
     */
    private const int CLEANUP_BATCH_SIZE = 100;

    private Connection $db;
    private int $ttlSeconds;

    /**
     * Create a new DbTraktOAuthStateStore instance.
     *
     * @param Connection $db Workerman MySQL connection instance
     * @param int        $ttlSeconds Optional TTL in seconds (default: 600 = 10 minutes)
     */
    public function __construct(Connection $db, int $ttlSeconds = self::TTL_SECONDS)
    {
        $this->db = $db;
        $this->ttlSeconds = $ttlSeconds > 0 ? $ttlSeconds : self::TTL_SECONDS;
    }

    /**
     * Persist a PKCE code verifier keyed by the state value.
     *
     * @param string $state       Unique state identifier
     * @param string $codeVerifier PKCE code verifier
     *
     * @throws \RuntimeException If database insert fails
     */
    public function put(string $state, string $codeVerifier): void
    {
        $id = Uuid::v4();
        $expiresAt = time() + $this->ttlSeconds;
        $data = json_encode(['code_verifier' => $codeVerifier]);

        $result = $this->db->query(
            "INSERT INTO oauth_state_store (id, provider, state_value, data, expires_at)
             VALUES (?, ?, ?, ?, FROM_UNIXTIME(?))",
            [$id, self::PROVIDER, $state, $data, $expiresAt]
        );

        if ($result === false) {
            throw new \RuntimeException('Failed to persist Trakt OAuth state to database');
        }
    }

    /**
     * One-shot lookup and deletion of the entry for a given state.
     *
     * Returns null if the state was never issued, has already been consumed,
     * or has expired (TTL exceeded).
     *
     * Cleans up expired entries in batches to prevent table bloat.
     *
     * @param string $state State value to consume
     *
     * @return string|null The code verifier if found and consumed, null otherwise
     */
    public function consume(string $state): ?string
    {
        // Atomic consume: SELECT within transaction, then DELETE
        $verifier = $this->fetchAndDelete($state);
        if ($verifier === null) {
            $this->cleanupExpiredEntries();
            return null;
        }

        $this->cleanupExpiredEntries();

        return $verifier;
    }

    /**
     * Fetch the entry and delete it atomically within a transaction.
     *
     * @param string $state State value to look up
     *
     * @return string|null The code verifier if found, null otherwise
     */
    private function fetchAndDelete(string $state): ?string
    {
        $this->db->beginTrans();
        try {
            $result = $this->db->query(
                "SELECT data FROM oauth_state_store WHERE provider = ? AND state_value = ? AND expires_at > NOW()",
                [self::PROVIDER, $state]
            );

            if (!is_array($result) || !isset($result[0]) || !is_array($result[0])) {
                $this->db->rollBackTrans();
                return null;
            }

            /** @var array<string, string> $row */
            $row = $result[0];

            $deleteResult = $this->db->query(
                "DELETE FROM oauth_state_store WHERE provider = ? AND state_value = ?",
                [self::PROVIDER, $state]
            );

            if ($deleteResult === false) {
                $this->db->rollBackTrans();
                return null;
            }

            $this->db->commitTrans();

            $data = is_string($row['data'] ?? null) ? json_decode($row['data'], true) : null;
            if (!is_array($data)) {
                return null;
            }

            $codeVerifier = is_string($data['code_verifier'] ?? null) ? $data['code_verifier'] : '';

            if ($codeVerifier === '') {
                return null;
            }

            return $codeVerifier;
        } catch (\Throwable) {
            $this->db->rollBackTrans();
            return null;
        }
    }

    /**
     * Delete expired entries in batches to prevent table bloat.
     *
     * Called after each {@see consume()} to lazily clean up stale entries.
     * This approach avoids adding overhead to every put() call.
     */
    private function cleanupExpiredEntries(): void
    {
        $this->db->query(
            "DELETE FROM oauth_state_store WHERE expires_at <= NOW() LIMIT ?",
            [self::CLEANUP_BATCH_SIZE]
        );
    }
}
