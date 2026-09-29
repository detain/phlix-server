<?php

/**
 * Phlix media server component: WebAuthn.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Auth\WebAuthn;

use Phlix\Common\Database\WriteResult;
use Phlix\Common\Uuid;
use RuntimeException;
use Workerman\MySQL\Connection;

/**
 * DB-backed one-shot store for WebAuthn challenges (M-2c/L-5, security audit
 * 2026-09-29).
 *
 * WHY NOT PROCESS MEMORY (the bug this replaces): the manager kept issued
 * challenges in two per-worker arrays. Under the 14-worker HTTP pool the
 * `POST` finish almost always lands on a worker that never issued the
 * challenge — registration/authentication could only ever succeed by a 1-in-14
 * accident — and abandoned ceremonies leaked their entries into the resident
 * worker forever. A shared, TTL-expiring table is the only correct shape here.
 *
 * It rides the same `oauth_state_store` table (migration 048) the quick-connect
 * and OAuth state stores use — provider-tagged `webauthn_challenge`, state
 * column carrying the base64url challenge itself (the secret), data column
 * carrying `{scope, principal}`. The challenge is high-entropy (256-bit) so
 * storing it verbatim as the lookup key is safe; the row's existence IS the
 * proof that this server issued it within the TTL.
 *
 * ONE-SHOT CONSUME: `consume()` locks the row (`SELECT … FOR UPDATE`), verifies
 * scope + principal binding, and DELETES it inside one transaction — a replayed
 * finish (the same challenge presented twice) finds no row and fails. A
 * ceremony therefore costs exactly one successful finish, matching the WebAuthn
 * spec's single-use challenge requirement.
 *
 * TTL is short (5 minutes) on purpose: a ceremony that has not completed by
 * then is dead — the user walked away or the authenticator timed out — and a
 * long-lived challenge is a long-lived replay budget.
 *
 * @package Phlix\Auth\WebAuthn
 * @see \Phlix\Auth\QuickConnectStateStore the sibling whose table pattern this mirrors
 */
final class WebAuthnChallengeStore
{
    /** Provider tag written to `oauth_state_store.provider` (VARCHAR(50), 18 used). */
    public const string PROVIDER = 'webauthn_challenge';

    /** Ceremony window, seconds. */
    public const int TTL_SECONDS = 300;

    /** Scope tag for registration ceremonies. */
    public const string SCOPE_REGISTER = 'register';

    /** Scope tag for authentication ceremonies. */
    public const string SCOPE_AUTHENTICATE = 'authenticate';

    /** Number of expired rows deleted per opportunistic sweep. */
    private const int CLEANUP_BATCH_SIZE = 100;

    private Connection $db;

    private int $ttlSeconds;

    /**
     * @param Connection $db         Shared Workerman MySQL connection.
     * @param int        $ttlSeconds Ceremony window override (tests only shrink it).
     */
    public function __construct(Connection $db, int $ttlSeconds = self::TTL_SECONDS)
    {
        $this->db = $db;
        $this->ttlSeconds = $ttlSeconds > 0 ? $ttlSeconds : self::TTL_SECONDS;
    }

    /**
     * Issue a challenge bound to (scope, principal), sweeping expired rows first.
     *
     * @param string $challenge Base64url challenge string (≤43 chars for a
     *                          32-byte challenge — comfortably inside the
     *                          VARCHAR(255) state column).
     * @param string $scope     One of {@see self::SCOPE_REGISTER} / {@see self::SCOPE_AUTHENTICATE}.
     * @param string $principal User id (registration) or username (authentication).
     *
     * @throws RuntimeException When the row could not be persisted — a caller
     *                          must never hand out a challenge the store does
     *                          not hold (fail closed, sibling doctrine).
     */
    public function issue(string $challenge, string $scope, string $principal): void
    {
        $this->purgeExpired();

        $data = json_encode([
            'scope' => $scope,
            'principal' => $principal,
        ], JSON_THROW_ON_ERROR);

        $result = $this->db->query(
            'INSERT INTO oauth_state_store (id, provider, state_value, data, expires_at)'
            . ' VALUES (?, ?, ?, ?, FROM_UNIXTIME(?))',
            [Uuid::v4(), self::PROVIDER, $challenge, $data, time() + $this->ttlSeconds],
        );

        // Throwing client + null-for-zero-rows contract per WriteResult docs:
        // anything but a written row means the ceremony cannot complete.
        if (WriteResult::wroteNothing($result)) {
            throw new RuntimeException('WebAuthn challenge could not be persisted');
        }
    }

    /**
     * One-shot consume: the row must exist, be live, and match scope + principal;
     * it is deleted under a row lock before this returns true.
     *
     * @return bool True when THIS call consumed the challenge; false for
     *              unknown/expired/misbound (the caller maps all three to one
     *              generic invalid-challenge error — no oracle into which
     *              ceremony state a stolen challenge string is in).
     */
    public function consume(string $challenge, string $scope, string $principal): bool
    {
        if ($challenge === '') {
            return false;
        }

        $this->db->beginTrans();

        try {
            $rows = $this->db->query(
                'SELECT data FROM oauth_state_store'
                . ' WHERE provider = ? AND state_value = ? AND expires_at > NOW() FOR UPDATE',
                [self::PROVIDER, $challenge],
            );
            if (!is_array($rows) || $rows === []) {
                $this->db->rollBackTrans();
                return false;
            }

            $first = $rows[0] ?? null;
            $raw = is_array($first) ? ($first['data'] ?? null) : null;
            $data = is_string($raw) ? json_decode($raw, true) : null;
            if (
                !is_array($data)
                || ($data['scope'] ?? null) !== $scope
                || !is_string($data['principal'] ?? null)
                || !hash_equals($data['principal'], $principal)
            ) {
                $this->db->rollBackTrans();
                return false;
            }

            $deleted = $this->db->query(
                'DELETE FROM oauth_state_store WHERE provider = ? AND state_value = ?',
                [self::PROVIDER, $challenge],
            );
            // The lock above guarantees this DELETE runs against a live row; a
            // 0 rowcount here would mean the row vanished under our own lock —
            // treat it as a miss rather than trusting a half-state.
            if (!is_int($deleted) || $deleted !== 1) {
                $this->db->rollBackTrans();
                return false;
            }

            $this->db->commitTrans();
            return true;
        } catch (RuntimeException $e) {
            try {
                $this->db->rollBackTrans();
            } catch (RuntimeException) {
                // The original failure is the reportable one; a rollback
                // complaint after it cannot change the outcome.
            }
            throw $e;
        }
    }

    /**
     * Bounded opportunistic sweep of this provider's expired rows.
     */
    private function purgeExpired(): void
    {
        $this->db->query(
            'DELETE FROM oauth_state_store WHERE provider = ? AND expires_at <= NOW() LIMIT '
            . self::CLEANUP_BATCH_SIZE,
            [self::PROVIDER],
        );
    }
}
