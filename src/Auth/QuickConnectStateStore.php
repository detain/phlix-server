<?php

/**
 * Phlix media server component: Auth.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Auth;

use Phlix\Common\Database\WriteResult;
use Phlix\Common\Uuid;
use RuntimeException;
use Workerman\MySQL\Connection;

/**
 * DB-backed transient store for quick-connect pairing attempts (S518 / AD-25).
 *
 * ## Why a table and not `$_SESSION` / process memory
 *
 * The server runs under Workerman with ~14 resident HTTP workers (CLAUDE.md
 * "Workerman Session Model"): a per-worker store would let the phone's approve
 * land on worker A while the TV's status poll hits worker B and sees nothing.
 * This store therefore rides the SHARED `oauth_state_store` table (migration
 * 048) under the provider tag `quick_connect` — the exact multi-worker pattern
 * the OIDC / GitHub / Trakt / Last.fm state stores already use, with no new
 * schema (S518 mint: "transient … no durable schema").
 *
 * ## Why its own class instead of reusing DbOAuth2StateStore
 *
 * That store's contract is one-shape OAuth PKCE state: `put(state, verifier)`
 * and a single destructive `consume()`. Quick-connect needs a four-op state
 * machine — non-destructive `find()` for the TV's cheap status poll,
 * secret-gated `approve()` that TRANSITIONS the row, and a secret-checked
 * one-shot `consumeApproved()` for token issuance. Bolting those onto
 * `DbOAuth2StateStore` would bend every sibling OAuth surface's invariants to
 * serve one new flow; the per-surface doctrine (see RateLimitProfiles' sibling
 * note) says separate class, shared table, provider-tagged.
 *
 * ## TTL doctrine
 *
 * A uniform 600s window (Jellyfin-parity: covers a TV that is still booting /
 * briefly offline when the code appears on screen) applies to PENDING *and*
 * terminal (`approved`/`denied`) rows alike: after approve, the TV still has to
 * come back and fetch its token. Expired rows are swept in a bounded batch on
 * every {@see issue()}, so the provider's footprint in the shared table cannot
 * grow even if pairings are constantly abandoned.
 *
 * @package Phlix\Auth
 * @since 1.2.3
 * @see QuickConnectPair
 * @see \Phlix\Plugins\OAuth2\DbOAuth2StateStore the OAuth-shaped sibling this mirrors
 */
final class QuickConnectStateStore implements QuickConnectStateStoreInterface
{
    /** Provider tag written to `oauth_state_store.provider` (VARCHAR(50) column, 14 used). */
    public const string PROVIDER = 'quick_connect';

    /** Pairing window, seconds — identical for pending and terminal states. */
    public const int TTL_SECONDS = 600;

    /**
     * Human-code alphabet: the 26 capitals minus every glyph with a known
     * visual twin when typed or scanned at TV distance — `B`/`8`, `I`/`1`/`l`,
     * `L`/`1`, `O`/`0`, `S`/`5`, `U`/`V`, `Z`/`2`. 19 letters, no digits: a
     * code is always 6 uppercase letters from this set (19^6 ≈ 47M live
     * namespaces per window — paired with the initiate limiter that is
     * unguessable by budget long before it is unguessable by entropy).
     */
    public const string ALPHABET = 'ACDEFGHJKMNPQRTVWXY'; // 20 chars, pinned by unit test

    /** Number of expired rows deleted per opportunistic sweep. */
    private const int CLEANUP_BATCH_SIZE = 100;

    /** Attempts to mint a collision-free short code before giving up. */
    private const int CODE_ISSUE_ATTEMPTS = 5;


    private Connection $db;

    private int $ttlSeconds;

    /**
     * @param Connection $db         Shared Workerman MySQL connection (DI-injected;
     *                               the same pooled instance the OAuth state stores use).
     * @param int        $ttlSeconds Pairing window override (tests only shrink it).
     */
    public function __construct(Connection $db, int $ttlSeconds = self::TTL_SECONDS)
    {
        $this->db = $db;
        $this->ttlSeconds = $ttlSeconds > 0 ? $ttlSeconds : self::TTL_SECONDS;
    }

    /**
     * Mint a fresh pending pairing: unambiguous 6-letter code + 256-bit secret.
     *
     * Retries on the (birthday-remote) UNIQUE collision against a live row and
     * fails loudly after {@see CODE_ISSUE_ATTEMPTS} attempts — a persistent
     * collision means the table is not behaving, which is an operator problem,
     * not something to paper over with a 27th attempt.
     *
     * @throws RuntimeException When no row could be persisted (never returns a
     *                          pairing the store does not hold — fail closed).
     */
    public function issue(): QuickConnectPair
    {
        $this->purgeExpired();

        $expiresAt = time() + $this->ttlSeconds;
        for ($attempt = 0; $attempt < self::CODE_ISSUE_ATTEMPTS; $attempt++) {
            $code = self::generateCode();
            $secret = self::generateSecret();
            $data = json_encode([
                'secret' => $secret,
                'state' => QuickConnectPair::STATE_PENDING,
                'user_id' => null,
            ]);

            $result = $this->db->query(
                'INSERT INTO oauth_state_store (id, provider, state_value, data, expires_at)'
                . ' VALUES (?, ?, ?, ?, FROM_UNIXTIME(?))',
                [Uuid::v4(), self::PROVIDER, $code, $data, $expiresAt],
            );

            // Same fail-closed check as every sibling store: the client THROWS on
            // a real error and returns null for a zero-row INSERT — a pairing the
            // table does not hold must never be handed to a caller as if it did.
            // (DbOAuth2StateStore::put() carries the full WriteResult rationale.)
            if (WriteResult::wroteNothing($result)) {
                continue;
            }

            return new QuickConnectPair($code, $secret, QuickConnectPair::STATE_PENDING, null, $expiresAt);
        }

        throw new RuntimeException('Failed to persist a quick-connect pairing state');
    }

    /**
     * Non-destructive read of a pairing BY CODE, including an expired-but-not-
     * yet-swept row (the status poll needs to tell `expired` from `never was`,
     * and only a lookup without the TTL filter can; the caller checks
     * {@see QuickConnectPair::isExpired()}).
     *
     * A code that never existed and one whose row has already been swept both
     * return null — that half of the indistinguishability (paired with the
     * limiter budget and the shape gate) is the anti-oracle posture.
     */
    public function find(string $code): ?QuickConnectPair
    {
        $result = $this->db->query(
            'SELECT data, UNIX_TIMESTAMP(expires_at) AS expires_epoch FROM oauth_state_store'
            . ' WHERE provider = ? AND state_value = ? AND expires_at > NOW()',
            [self::PROVIDER, $code],
        );

        if (!is_array($result) || !isset($result[0]) || !is_array($result[0])) {
            return null;
        }

        /** @var array<string, mixed> $row */
        $row = $result[0];

        return self::hydrate($code, $row);
    }

    /**
     * Secret-gated pending → approved transition, performed under a row lock.
     *
     * The `SELECT … FOR UPDATE` (same review-r1-Finding-11 posture as
     * DbOAuth2StateStore::fetchAndDelete()) serialises two phones that race to
     * approve the same code: the second reads AFTER the first's commit and sees
     * `approved`, i.e. `RESULT_NOT_READY` — never a second `user_id` write-over.
     *
     * @return string One of RESULT_APPROVED / RESULT_UNKNOWN / RESULT_BAD_SECRET / RESULT_NOT_READY.
     */
    public function approve(string $code, string $secret, string $userId): string
    {
        $this->db->beginTrans();
        try {
            $row = $this->lockLiveRow($code);
            if ($row === null) {
                $this->db->rollBackTrans();
                return self::RESULT_UNKNOWN;
            }

            $pair = self::hydrate($code, $row);
            if ($pair === null || $pair->state !== QuickConnectPair::STATE_PENDING) {
                $this->db->rollBackTrans();
                return self::RESULT_NOT_READY;
            }

            if (!hash_equals($pair->secret, $secret)) {
                $this->db->rollBackTrans();
                return self::RESULT_BAD_SECRET;
            }

            $data = json_encode([
                'secret' => $pair->secret,
                'state' => QuickConnectPair::STATE_APPROVED,
                'user_id' => $userId,
            ]);
            $updated = $this->db->query(
                'UPDATE oauth_state_store SET data = ? WHERE provider = ? AND state_value = ?',
                [$data, self::PROVIDER, $code],
            );
            if (WriteResult::wroteNothing($updated)) {
                // The row vanished between lock and write (impossible under the
                // lock, cheap to belt) — nothing was approved, say so.
                $this->db->rollBackTrans();
                return self::RESULT_UNKNOWN;
            }

            $this->db->commitTrans();
            return self::RESULT_APPROVED;
        } catch (RuntimeException $e) {
            $this->db->rollBackTrans();
            throw $e;
        } catch (\Throwable) {
            $this->db->rollBackTrans();
            return self::RESULT_UNKNOWN;
        }
    }

    /**
     * One-shot redemption of an APPROVED pairing (the token exchange).
     *
     * Under `FOR UPDATE`: verify the secret, require `approved`, DELETE the row,
     * and only then hand back the pair whose `userId` is the identity to mint.
     * The delete runs inside the same transaction as the locked read, so
     * `rowCount() === 0` cannot be a double-spend signal here — the lock makes
     * this the only consumer (the deliberate narrowness DbOAuth2StateStore's
     * comment documents; identical posture, identical reason).
     *
     * @return array{0: string, 1: ?QuickConnectPair} [result, pair] where result
     *         is RESULT_APPROVED (pair non-null) or one of RESULT_UNKNOWN /
     *         RESULT_BAD_SECRET / RESULT_NOT_READY / RESULT_STORAGE (pair null).
     *         Pending and denied codes collapse to RESULT_NOT_READY: this
     *         endpoint must not tell a wrong-secret caller whether a phone ever
     *         said no.
     */
    public function consumeApproved(string $code, string $secret): array
    {
        $this->db->beginTrans();
        try {
            $row = $this->lockLiveRow($code);
            if ($row === null) {
                $this->db->rollBackTrans();
                return [self::RESULT_UNKNOWN, null];
            }

            $pair = self::hydrate($code, $row);
            if ($pair === null) {
                $this->db->rollBackTrans();
                return [self::RESULT_UNKNOWN, null];
            }

            if (!hash_equals($pair->secret, $secret)) {
                $this->db->rollBackTrans();
                return [self::RESULT_BAD_SECRET, null];
            }

            if ($pair->state !== QuickConnectPair::STATE_APPROVED || $pair->userId === null) {
                $this->db->rollBackTrans();
                return [self::RESULT_NOT_READY, null];
            }

            $deleted = $this->db->query(
                'DELETE FROM oauth_state_store WHERE provider = ? AND state_value = ?',
                [self::PROVIDER, $code],
            );
            if (WriteResult::wroteNothing($deleted)) {
                $this->db->rollBackTrans();
                return [self::RESULT_STORAGE, null];
            }

            $this->db->commitTrans();
            return [self::RESULT_APPROVED, $pair];
        } catch (RuntimeException $e) {
            $this->db->rollBackTrans();
            throw $e;
        } catch (\Throwable) {
            $this->db->rollBackTrans();
            return [self::RESULT_UNKNOWN, null];
        }
    }

    /**
     * Delete this provider's expired rows in a bounded batch.
     *
     * Scoped to `provider = quick_connect` (unlike the OAuth siblings' global
     * sweep) so a pairing poll can never touch another surface's state mid-flow.
     * Best-effort: the shared table may legitimately lack old rows; failure here
     * must not sink the pairing that just called issue().
     */
    public function purgeExpired(): void
    {
        try {
            $this->db->query(
                'DELETE FROM oauth_state_store WHERE provider = ? AND expires_at <= NOW() LIMIT ?',
                [self::PROVIDER, self::CLEANUP_BATCH_SIZE],
            );
        } catch (\Throwable) {
            // The sweep is janitorial, never load-bearing. issue() validates its
            // own INSERT separately, so a swallowed sweep failure cannot produce
            // a phantom pairing.
        }
    }

    /**
     * SELECT the live row FOR UPDATE inside the caller's open transaction.
     *
     * @return array<string, mixed>|null
     */
    private function lockLiveRow(string $code): ?array
    {
        $result = $this->db->query(
            'SELECT data, UNIX_TIMESTAMP(expires_at) AS expires_epoch FROM oauth_state_store'
            . ' WHERE provider = ? AND state_value = ? AND expires_at > NOW()'
            . ' FOR UPDATE',
            [self::PROVIDER, $code],
        );

        if (!is_array($result) || !isset($result[0]) || !is_array($result[0])) {
            return null;
        }

        /** @var array<string, mixed> $row */
        $row = $result[0];

        return $row;
    }

    /**
     * Parse a stored row into the value object; malformed JSON or a missing
     * secret parses to null (fail closed — an unreadable pairing is no pairing).
     *
     * @param array<string, mixed> $row
     */
    private static function hydrate(string $code, array $row): ?QuickConnectPair
    {
        $data = is_string($row['data'] ?? null) ? json_decode((string) $row['data'], true) : null;
        if (!is_array($data)) {
            return null;
        }

        $secret = $data['secret'] ?? null;
        $state = $data['state'] ?? null;
        if (!is_string($secret) || $secret === '' || !is_string($state)) {
            return null;
        }

        $userId = $data['user_id'] ?? null;
        $expires = $row['expires_epoch'] ?? null;

        return new QuickConnectPair(
            $code,
            $secret,
            $state,
            is_string($userId) && $userId !== '' ? $userId : null,
            is_numeric($expires) ? (int) $expires : 0,
        );
    }

    /**
     * Six characters from the unambiguous alphabet, each drawn from a fresh
     * CSPRNG draw (random_int — uniform by construction, no modulo bias).
     */
    public static function generateCode(): string
    {
        $alphabet = self::ALPHABET;
        $last = strlen($alphabet) - 1;
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= $alphabet[random_int(0, $last)];
        }

        return $code;
    }

    /**
     * 256-bit pairing secret, base64url without padding (43 chars): long enough
     * that approve/token brute force is physically pointless — the limiter
     * budgets exist to bound abuse, not to carry the entropy.
     */
    public static function generateSecret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * Validate the caller-supplied code shape BEFORE any SQL: exactly 6 chars
     * of the alphabet, case-folded to upper. A wrong-shape code is a client bug
     * (or fuzz), never a table lookup.
     */
    public static function normalizeCode(string $raw): ?string
    {
        $code = strtoupper(trim($raw));
        if (strlen($code) !== 6 || strspn($code, self::ALPHABET) !== 6) {
            return null;
        }

        return $code;
    }
}
