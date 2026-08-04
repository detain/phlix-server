<?php

/**
 * Phlix media server component: Oidc.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Oidc;

use Phlix\Common\Database\WriteResult;
use Phlix\Common\Uuid;
use Workerman\MySQL\Connection;

/**
 * DB-backed implementation of {@see OidcStateStore}.
 *
 * Stores OIDC PKCE state entries in a database table instead of $_SESSION,
 * which causes state leakage between Workerman workers/requests.
 *
 * Uses the unified `oauth_state_store` table with provider = 'oidc' and
 * data JSON containing {code_verifier, nonce}.
 *
 * Each entry has a 10-minute TTL to prevent stale state accumulation.
 * Expired entries are cleaned up on each {@see consume()} call.
 *
 * @since 0.16.0
 */
final class DbOidcStateStore implements OidcStateStore
{
    /**
     * Provider identifier for OIDC in the unified oauth_state_store table.
     */
    private const string PROVIDER = 'oidc';

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
     * Create a new DbOidcStateStore instance.
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
     * Persist a PKCE verifier and the matching nonce keyed by the state value.
     *
     * @param string $state       Unique state identifier
     * @param string $codeVerifier PKCE code verifier
     * @param string $nonce        OIDC ID token nonce
     * @param array<string, mixed>|null $context Optional server-side context
     *                                          (e.g. S45 link intent) stored in
     *                                          the same `data` JSON and returned
     *                                          verbatim by {@see consume()}. Never
     *                                          exposed to the client.
     *
     * @throws \RuntimeException If database insert fails
     */
    public function put(string $state, string $codeVerifier, string $nonce, ?array $context = null): void
    {
        $id = Uuid::v4();
        $expiresAt = time() + $this->ttlSeconds;
        $payload = [
            'code_verifier' => $codeVerifier,
            'nonce' => $nonce,
        ];
        if ($context !== null && $context !== []) {
            $payload['context'] = $context;
        }
        $data = json_encode($payload);

        $result = $this->db->query(
            "INSERT INTO oauth_state_store (id, provider, state_value, data, expires_at)
             VALUES (?, ?, ?, ?, FROM_UNIXTIME(?))",
            [$id, self::PROVIDER, $state, $data, $expiresAt]
        );

        // This was `$result === false`, which could never fire: the client
        // THROWS on a real error and has no `return false` at all. `null` — a
        // zero-row INSERT, or an unrecognised leading keyword after a reformat
        // — is the falsy value it does return, and an unpersisted state must
        // not be handed to the caller as if it had been stored. See
        // {@see WriteResult} for the measured return table and both traps.
        if (WriteResult::wroteNothing($result)) {
            throw new \RuntimeException('Failed to persist OIDC state to database');
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
     * @return array{code_verifier: string, nonce: string, context?: array<string, mixed>}|null
     *         `context` is passed straight through from fetchAndDelete(); it was
     *         missing from this shape, so the declared type contradicted what the
     *         method returns.
     */
    public function consume(string $state): ?array
    {
        $entry = $this->fetchAndDelete($state);
        if ($entry === null) {
            $this->cleanupExpiredEntries();
            return null;
        }

        $this->cleanupExpiredEntries();

        return $entry;
    }

    /**
     * Fetch the entry and delete it atomically within a transaction.
     *
     * @param string $state State value to look up
     *
     * @return array{code_verifier: string, nonce: string, context?: array<string, mixed>}|null
     */
    private function fetchAndDelete(string $state): ?array
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

            // ⚠ FAIL-CLOSED BUT NARROW, AND THE NARROWNESS IS DELIBERATE.
            // For a `delete` the client returns `rowCount()` — an int — so
            // neither `false` (the shape this replaced) nor `null` is
            // reachable for the statement as written. The arm that CAN fire is
            // `null`, and only if this SQL is reformatted so that `DELETE`
            // stops being the first space-delimited token ({@see WriteResult}
            // trap 3), at which point "we cannot tell whether the row was
            // deleted" must not result in handing out a one-shot verifier.
            //
            // It does NOT catch `rowCount() === 0` — a concurrent consumer
            // deleting the row between our non-locking SELECT and this DELETE,
            // i.e. a double-consume of one-shot state. Treating that as a
            // refusal is a behaviour change in an auth path and needs its own
            // real-MySQL proof; S131 measured it and deliberately left it.
            if (WriteResult::wroteNothing($deleteResult)) {
                $this->db->rollBackTrans();
                return null;
            }

            $this->db->commitTrans();

            $data = is_string($row['data'] ?? null) ? json_decode($row['data'], true) : null;
            if (!is_array($data)) {
                return null;
            }

            $codeVerifier = is_string($data['code_verifier'] ?? null) ? $data['code_verifier'] : '';
            $nonce = is_string($data['nonce'] ?? null) ? $data['nonce'] : '';

            if ($codeVerifier === '') {
                $this->db->rollBackTrans();
                return null;
            }

            $entry = [
                'code_verifier' => $codeVerifier,
                'nonce' => $nonce,
            ];
            if (isset($data['context']) && is_array($data['context'])) {
                /** @var array<string, mixed> $context */
                $context = $data['context'];
                $entry['context'] = $context;
            }

            return $entry;
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
