<?php

/**
 * Phlix media server component: OAuth2.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\OAuth2;

use Phlix\Common\Uuid;
use Workerman\MySQL\Connection;

/**
 * DB-backed implementation of {@see OAuth2StateStore}.
 *
 * Stores PKCE state entries in the unified `oauth_state_store` table (migration
 * 048) instead of `$_SESSION`, which leaks/races across Workerman workers and
 * requests (see CLAUDE.md "Workerman Session Model"). The `provider` column tags
 * the owning provider family (e.g. `github`), so a single table serves OIDC,
 * GitHub, Trakt, Last.fm, etc. without key collisions.
 *
 * Each entry has a TTL to prevent stale-state accumulation; expired entries are
 * swept in batches on each {@see consume()} call. Mirrors
 * {@see \Phlix\Plugins\Oidc\DbOidcStateStore} minus the OIDC `nonce`.
 *
 * @package Phlix\Plugins\OAuth2
 * @since 0.102.0
 */
final class DbOAuth2StateStore implements OAuth2StateStore
{
    /** Default TTL for state entries in seconds (10 minutes). */
    private const int TTL_SECONDS = 600;

    /** Number of expired entries to delete per cleanup batch. */
    private const int CLEANUP_BATCH_SIZE = 100;

    private Connection $db;
    private string $provider;
    private int $ttlSeconds;

    /**
     * @param Connection $db         Workerman MySQL connection instance.
     * @param string     $provider   Provider family tag (e.g. `github`) written to
     *                               the `oauth_state_store.provider` column.
     * @param int        $ttlSeconds Optional TTL in seconds (default 600).
     */
    public function __construct(Connection $db, string $provider, int $ttlSeconds = self::TTL_SECONDS)
    {
        $this->db = $db;
        $this->provider = $provider;
        $this->ttlSeconds = $ttlSeconds > 0 ? $ttlSeconds : self::TTL_SECONDS;
    }

    /**
     * Persist a PKCE verifier keyed by the state value.
     *
     * @param array<string, mixed>|null $context Optional server-side context
     *                                          (e.g. a link intent) stored in the
     *                                          same `data` JSON and returned by
     *                                          {@see consume()}. Never exposed to
     *                                          the client.
     *
     * @throws \RuntimeException If the database insert fails.
     */
    public function put(string $state, string $codeVerifier, ?array $context = null): void
    {
        $id = Uuid::v4();
        $expiresAt = time() + $this->ttlSeconds;
        $payload = [
            'code_verifier' => $codeVerifier,
        ];
        if ($context !== null && $context !== []) {
            $payload['context'] = $context;
        }
        $data = json_encode($payload);

        $result = $this->db->query(
            "INSERT INTO oauth_state_store (id, provider, state_value, data, expires_at)
             VALUES (?, ?, ?, ?, FROM_UNIXTIME(?))",
            [$id, $this->provider, $state, $data, $expiresAt],
        );

        if ($result === false) {
            throw new \RuntimeException('Failed to persist OAuth2 state to database');
        }
    }

    /**
     * One-shot lookup and deletion of the entry for a given state.
     *
     * @return array{code_verifier: string, context?: array<string, mixed>}|null
     */
    public function consume(string $state): ?array
    {
        $entry = $this->fetchAndDelete($state);
        $this->cleanupExpiredEntries();

        return $entry;
    }

    /**
     * Fetch the entry and delete it atomically within a transaction.
     *
     * @return array{code_verifier: string, context?: array<string, mixed>}|null
     */
    private function fetchAndDelete(string $state): ?array
    {
        $this->db->beginTrans();
        try {
            $result = $this->db->query(
                "SELECT data FROM oauth_state_store WHERE provider = ? AND state_value = ? AND expires_at > NOW()",
                [$this->provider, $state],
            );

            if (!is_array($result) || !isset($result[0]) || !is_array($result[0])) {
                $this->db->rollBackTrans();
                return null;
            }

            /** @var array<string, mixed> $row */
            $row = $result[0];

            $deleteResult = $this->db->query(
                "DELETE FROM oauth_state_store WHERE provider = ? AND state_value = ?",
                [$this->provider, $state],
            );

            if ($deleteResult === false) {
                $this->db->rollBackTrans();
                return null;
            }

            $this->db->commitTrans();

            $data = is_string($row['data'] ?? null) ? json_decode((string) $row['data'], true) : null;
            if (!is_array($data)) {
                return null;
            }

            $codeVerifier = is_string($data['code_verifier'] ?? null) ? $data['code_verifier'] : '';
            if ($codeVerifier === '') {
                return null;
            }

            $entry = ['code_verifier' => $codeVerifier];
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
     */
    private function cleanupExpiredEntries(): void
    {
        $this->db->query(
            "DELETE FROM oauth_state_store WHERE expires_at <= NOW() LIMIT ?",
            [self::CLEANUP_BATCH_SIZE],
        );
    }
}
