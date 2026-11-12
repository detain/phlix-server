<?php

/**
 * Phlix media server component: Auth.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Auth;

use Phlix\Common\Database\WriteResult;
use Phlix\Common\Uuid;
use Phlix\Common\Util\RowMap;
use Workerman\MySQL\Connection;

/**
 * Session-family store for the JWT auth path (CP-029 / D-1a).
 *
 * ## What this is, and what it deliberately is not
 *
 * One row in `auth_token_sessions` is one LOGIN ceremony — a family. Every
 * access/refresh pair minted from that ceremony (including every later
 * rotation of the same refresh chain) carries the family id as the `sid` JWT
 * claim. Revoking one row ends exactly one device's chain; the account-wide
 * bound stays where it always was: the per-user watermark
 * (`users.tokens_not_valid_after`, see {@see \Phlix\Auth\AuthManager::revokeUserTokens()}),
 * which this class does not replace and must never be asked to duplicate.
 *
 * `auth_refresh_grants` is the theft sensor: every MINTED refresh token
 * (identified by its `jti`) gets a row. Rotation marks the presented token
 * consumed. A replay of a consumed token outside the grace window is
 * indistinguishable from theft — the family dies. A replay inside the window
 * is the benign multi-tab race the CP-005 grace rule exists for — it rotates
 * on without revoking anything.
 *
 * This is NOT the legacy `sessions` device registry behind
 * `/api/v1/sessions` ({@see \Phlix\Session\SessionManager}). That table has
 * existed since migration 001 and the JWT path never consulted it; reviving
 * it would entangle playback-state FKs with the auth hot path. These two
 * tables are new, minimal, and wired into exactly one consumer: AuthManager.
 *
 * ## Live-read law (CP-024 lineage, de573d6e)
 *
 * Every method here issues its query directly against the connection. There is
 * deliberately NO in-memory cache in this class: a resident worker that
 * memoized `isSessionActive()` would turn a per-device revocation into a stale-cache
 * window on exactly the security decision it exists to make immediate. The
 * repository layer stays a pure projection of the database clock, matching the
 * single-layer-cache doctrine on {@see UserRepository::getAuthState()}.
 *
 * @see \Phlix\Auth\AuthManager The only reader/writer from the token path.
 *
 * @since CP-029 (D-1a session-family revocation)
 */
class TokenSessionRepository
{
    /**
     * How long a grant row may outlive its token's expiry before the sweep
     * removes it (D1a parity cure 2026-11-11, mirrors hub semantics). The theft
     * sensor only ever needs the row while the token itself still verifies —
     * `refreshToken()` refuses an expired token on crypto grounds before the
     * grant is consulted — so the grace window is forensic headroom, not a
     * correctness requirement.
     */
    private const PRUNE_GRACE_SECONDS = 86400;

    /** Rows removed per sweep arm, per write — bounds each DELETE (the estate's single-phase prune idiom). */
    private const PRUNE_BATCH_SIZE = 1000;

    /**
     * Retention for grant rows that predate lifetime stamping (expires_at
     * NULL): a full year after created_at, far past any real token TTL.
     */
    private const UNSTAMPED_RETENTION_SECONDS = 31536000;

    /**
     * How long a REVOKED family row outlives its revocation before the
     * per-user sweep removes it (SRV-S16). Derived, not chosen: every grant
     * the family ever minted expires by revoked_at + the longest refresh TTL
     * the policy can bake ({@see TokenTtlPolicy::MAX_REFRESH_TTL}), and the
     * grant sweep retires it PRUNE_GRACE_SECONDS later — so by this horizon
     * the family's stamped grants are already gone and the ordered sweep
     * only ever meets unstamped stragglers. Deleting earlier would still be
     * fail-closed (a missing family/grant refuses exactly like a revoked
     * one), but would throw away audit evidence for nothing.
     */
    private const REVOKED_RETENTION_SECONDS = TokenTtlPolicy::MAX_REFRESH_TTL + self::PRUNE_GRACE_SECONDS;

    /**
     * Ceiling for {@see listActiveSessions()}. Capability-neutral: revoking a
     * family never requires seeing it in the list, so the honest bound trades
     * an unbounded per-request read for a capped page the UI cannot outgrow.
     */
    public const MAX_LISTED_SESSIONS = 200;

    /** @var Connection Database connection (Workerman MySQL, same handle family as UserRepository) */
    private Connection $db;

    /**
     * @param Connection $db Live MySQL connection.
     */
    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * Mint a new login family and return its id (the future `sid` claim).
     *
     * The row is written BEFORE the tokens are signed, so a minted token can
     * never reference a family the database does not have (fail-closed read
     * path would reject the very first request otherwise). Device columns are
     * optional display metadata — clients that do not send any (the OIDC
     * callbacks, WebAuthn finish) honestly show NULL in the session list.
     *
     * The insert first runs {@see pruneRevokedFamilies()} for the same user —
     * the login ceremony is the only thing that grows this table, so sweeping
     * the account's aged-revoked families at that moment keeps it bounded
     * without a cron (SRV-S16).
     *
     * @param string      $userId     Owner of the family.
     * @param string|null $deviceType Display metadata ('tv', 'mobile', …) or null.
     * @param string|null $deviceName Display metadata, untrusted, length-capped
     *                                by the VARCHAR(255) column.
     *
     * @return string The 36-char UUID family id.
     */
    public function createSession(string $userId, ?string $deviceType = null, ?string $deviceName = null): string
    {
        $this->pruneRevokedFamilies($userId);
        $sessionId = Uuid::v4();

        $this->db->query(
            'INSERT INTO auth_token_sessions (id, user_id, device_type, device_name)'
            . ' VALUES (?, ?, ?, ?)',
            [$sessionId, $userId, $deviceType, $deviceName]
        );

        return $sessionId;
    }

    /**
     * Is this family still alive? Single PK read, per call, uncached by design.
     *
     * Fail-closed truth table: a missing row returns false exactly like a
     * revoked one — a `sid` this store has never seen cannot authenticate
     * (whether the row was pruned, never applied, or the database moved under
     * the token, the honest answer to "is this live?" is no).
     *
     * @param string $sessionId Family id from the `sid` claim.
     *
     * @return bool True only for a row whose status is literally 'active'.
     */
    public function isSessionActive(string $sessionId): bool
    {
        $rows = RowMap::listFromMixed($this->db->query(
            "SELECT status FROM auth_token_sessions WHERE id = ?",
            [$sessionId]
        ));

        $status = $rows[0]['status'] ?? null;

        return is_string($status) && $status === 'active';
    }

    /**
     * Read the presented refresh token's grant state in ONE joined query.
     *
     * The returned shape is the whole reuse-detection decision table, computed
     * on the DATABASE clock (same basis the `consumed_at` write uses — a
     * PHP-side clock here would make the grace window two clocks wide):
     *
     *  - null                → unknown jti (never minted or the store moved):
     *                          callers MUST fail closed.
     *  - consumed=false      → live token: rotate it.
     *  - consumed=true,
     *    withinGrace=true   → replay inside the grace window: benign multi-tab
     *                          race, rotate WITHOUT revoking the family.
     *  - consumed=true,
     *    withinGrace=false → REUSE DETECTED: revoke the family, audit, reject.
     *  - sessionStatus      → the owning family's status, joined in the same
     *                          read so a revoked family short-circuits before
     *                          any rotation is honored (no two-query TOCTOU).
     *
     * @param string $jti          The refresh token's `jti` claim (32 hex chars).
     * @param int    $graceSeconds Width of the grace window, supplied by the
     *                             caller's design constant (CP-005's ten seconds)
     *                             so one number rules both the in-process memo
     *                             and this store.
     *
     * @return array{jti: string, sessionId: string, sessionStatus: string,
     *     consumed: bool, withinGrace: bool}|null
     */
    public function findGrant(string $jti, int $graceSeconds): ?array
    {
        $rows = RowMap::listFromMixed($this->db->query(
            'SELECT g.jti, g.session_id, s.status AS session_status,'
            . ' (g.consumed_at IS NOT NULL) AS consumed,'
            . ' (g.consumed_at >= DATE_SUB(NOW(), INTERVAL ' . $graceSeconds . ' SECOND)) AS within_grace'
            . ' FROM auth_refresh_grants g'
            . ' JOIN auth_token_sessions s ON s.id = g.session_id'
            . ' WHERE g.jti = ?',
            [$jti]
        ));

        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            return null;
        }

        // Parse-don't-validate at the SQL boundary: the SELECT above fixes the
        // column list, the computed flags are MySQL 0/1 integers, and RowMap
        // has already proven every row is an array — the shape annotation is
        // the trust boundary, the same narrowing idiom
        // DbLoginRateLimitStore::check() ships.
        /** @var array{jti: string, session_id: string, session_status: string, consumed: int, within_grace: int} $grant */
        $grant = $row;

        return [
            'jti' => $grant['jti'],
            'sessionId' => $grant['session_id'],
            'sessionStatus' => $grant['session_status'],
            'consumed' => $grant['consumed'] === 1,
            'withinGrace' => $grant['within_grace'] === 1,
        ];
    }

    /**
     * Register a freshly minted refresh token as a grant of this family.
     *
     * Written at MINT time (not first use): a token that is signed, delivered
     * and never registered would be indistinguishable from an unknown forged
     * jti on presentation, so the row must exist the moment the bytes exist.
     *
     * The insert first runs {@see pruneExpired()} — the write-time sweep that
     * keeps the ledger bounded without a cron (D1a parity cure 2026-11-11,
     * hub-consistent semantics on the server's own bounded-DELETE idiom).
     *
     * @param string   $jti       The new refresh token's `jti`.
     * @param string   $sessionId Family that owns it.
     * @param int|null $expiresAt The token's OWN baked `exp` (unix seconds),
     *                            stamped so the row can never disagree with
     *                            the token it indexes; NULL for pre-cure
     *                            callers and grandfathered rows.
     *
     * @return void
     */
    public function recordGrant(string $jti, string $sessionId, ?int $expiresAt = null): void
    {
        $this->pruneExpired();
        $this->db->query(
            'INSERT INTO auth_refresh_grants (jti, session_id, expires_at) VALUES (?, ?, FROM_UNIXTIME(?))',
            [$jti, $sessionId, $expiresAt]
        );
    }

    /**
     * Sweep retired grants out of the ledger (bounded, two arms).
     *
     * Arm 1 — stamped rows whose token expired and has been dead past the
     * grace window. A PRESENTATION of such a token is refused by
     * {@see \Phlix\Auth\AuthManager::refreshToken()} on crypto grounds
     * (validateToken's own exp check) BEFORE any grant lookup, so deleting the
     * row cannot mask a theft signal.
     * Arm 2 — unstamped rows (pre-cure era) aged a full year past created_at.
     *
     * A CONSUMED-but-unexpired grant is kept by both arms — that row is the
     * theft sensor itself (replay adjudication needs it). Best-effort in the
     * estate's janitor posture (QuickConnectStateStore::purgeExpired): the
     * sweep runs on the write path and is never load-bearing for the auth
     * decision.
     *
     * @return void
     */
    public function pruneExpired(): void
    {
        try {
            $this->db->query(
                'DELETE FROM auth_refresh_grants WHERE expires_at < NOW() - INTERVAL '
                . self::PRUNE_GRACE_SECONDS . ' SECOND LIMIT ?',
                [self::PRUNE_BATCH_SIZE]
            );
            $this->db->query(
                'DELETE FROM auth_refresh_grants WHERE expires_at IS NULL'
                . ' AND created_at < NOW() - INTERVAL '
                . self::UNSTAMPED_RETENTION_SECONDS . ' SECOND LIMIT ?',
                [self::PRUNE_BATCH_SIZE]
            );
        } catch (\Throwable) {
            // The sweep is janitorial, never load-bearing. The INSERT that
            // follows on the write path is validated by its own caller
            // (a throw there is the real failure), so swallowing only here
            // cannot leave an unregistered grant silently.
        }
    }

    /**
     * Sweep ONE user's aged-revoked families out of the store (SRV-S16).
     *
     * A revoked family is terminal — nothing ever reactivates it — so once
     * {@see self::REVOKED_RETENTION_SECONDS} have passed since `revoked_at`
     * its row is pure residue. Deleting it is fail-closed by construction:
     * {@see isSessionActive()} answers false for a missing row exactly as for
     * a revoked one, and {@see findGrant()} answers null (refused as
     * 'refresh_grant_unknown') once the grants are gone.
     *
     * ## Ordered, bounded, FK-respecting
     *
     * `auth_refresh_grants.session_id` cascades on delete, but a cascade is an
     * UNBOUNDED fan-out hidden inside one statement. The sweep therefore runs
     * in order instead of leaning on it:
     *
     *  1. pick up to PRUNE_BATCH_SIZE aged-revoked family ids of this user;
     *  2. delete their grants (LIMIT PRUNE_BATCH_SIZE);
     *  3. delete only those picked families that no longer hold any grant —
     *     a family whose grants overflowed the batch survives to the next
     *     sweep, so no statement ever removes more than one batch of rows.
     *
     * ## Why per-user (measured, SRV-S16)
     *
     * There is no index on (status, revoked_at); a global arm measured ~100ms
     * of full scan per sweep at 100k families on MySQL 8.0. Scoped to the
     * login's own user, step 1 rides `idx_ats_user_status` (migration 127)
     * and costs nothing measurable — so no migration is added. The bound
     * holds because only the login ceremony grows the table: every account
     * that keeps creating families keeps sweeping its own residue, and
     * account deletion cascades the rest.
     *
     * Best-effort in the same janitor posture as {@see pruneExpired()}: it is
     * never load-bearing for the auth decision, so a failure is swallowed.
     *
     * @param string $userId Owner whose aged-revoked families are swept.
     *
     * @return void
     */
    public function pruneRevokedFamilies(string $userId): void
    {
        try {
            $rows = RowMap::listFromMixed($this->db->query(
                "SELECT id FROM auth_token_sessions WHERE user_id = ? AND status = 'revoked'"
                . ' AND revoked_at < NOW() - INTERVAL ' . self::REVOKED_RETENTION_SECONDS . ' SECOND'
                . ' LIMIT ' . self::PRUNE_BATCH_SIZE,
                [$userId]
            ));

            $ids = [];
            foreach ($rows as $row) {
                if (is_string($row['id'] ?? null)) {
                    $ids[] = $row['id'];
                }
            }
            if ($ids === []) {
                return;
            }

            $in = implode(', ', array_fill(0, count($ids), '?'));
            $this->db->query(
                'DELETE FROM auth_refresh_grants WHERE session_id IN (' . $in . ')'
                . ' LIMIT ' . self::PRUNE_BATCH_SIZE,
                $ids
            );
            $this->db->query(
                'DELETE FROM auth_token_sessions WHERE id IN (' . $in . ")"
                . " AND status = 'revoked'"
                . ' AND NOT EXISTS (SELECT 1 FROM auth_refresh_grants g WHERE g.session_id = auth_token_sessions.id)',
                $ids
            );
        } catch (\Throwable) {
            // Janitorial, never load-bearing: the createSession INSERT that
            // follows is validated by its own caller, so a sweep failure only
            // leaves residue for the next login to retire.
        }
    }

    /**
     * Mark the presented refresh token as used (rotation consumed it), and
     * say whether THIS call is the one that consumed it (SRV-S16).
     *
     * The `AND consumed_at IS NULL` guard makes the write the atomic arbiter
     * between concurrent rotations of one token: exactly one UPDATE flips the
     * row. Every loser — and every answer the statement cannot vouch for —
     * returns false, and the caller re-adjudicates through the reuse decision
     * table ({@see \Phlix\Auth\AuthManager} lost-race arm) rather than
     * minting on a consumption it never made.
     *
     * Return table ({@see WriteResult}): an `UPDATE` answers an int rowCount.
     * `WriteResult::wroteNothing()` (null — the reformat trap — or false) and
     * an int 0 (another writer flipped the row first, or the grant is gone)
     * both read as "not consumed by this call".
     *
     * @param string $jti Presented token's `jti`.
     *
     * @return bool True only when this call flipped consumed_at.
     */
    public function markGrantConsumed(string $jti): bool
    {
        $result = $this->db->query(
            'UPDATE auth_refresh_grants SET consumed_at = NOW() WHERE jti = ? AND consumed_at IS NULL',
            [$jti]
        );

        if (WriteResult::wroteNothing($result)) {
            return false;
        }

        // An UPDATE answers an int rowCount: 0 = this call flipped nothing.
        return $result !== 0;
    }

    /**
     * Advance the family's last-used watermark (display metadata for the
     * session list ONLY — deliberately not on the access-token hot path,
     * which stays a pure read; see the register's hot-path note).
     *
     * @param string $sessionId Family id.
     *
     * @return void
     */
    public function touchSession(string $sessionId): void
    {
        $this->db->query(
            'UPDATE auth_token_sessions SET last_used_at = NOW() WHERE id = ?',
            [$sessionId]
        );
    }

    /**
     * End exactly one family (per-device sign-out, reuse revocation).
     *
     * The watermark is NOT touched: per-device semantics are the whole point
     * of this capability. Idempotent at the SQL level (`AND status = 'active'`)
     * so a double-revoke rewrites neither the reason nor the timestamp.
     *
     * @param string $sessionId Family id to revoke.
     * @param string $reason    Audit context ('logout', 'refresh_token_reuse',
     *                          'user_revoked' …) — free-form, never a wire code.
     *
     * @return void
     */
    public function revokeSession(string $sessionId, string $reason): void
    {
        $this->db->query(
            "UPDATE auth_token_sessions SET status = 'revoked', revoke_reason = ?, revoked_at = NOW()"
            . " WHERE id = ? AND status = 'active'",
            [$reason, $sessionId]
        );
    }

    /**
     * End EVERY family of one user (called from the watermark choke point so
     * the nuclear option keeps exactly its current blast radius on both paths
     * — every bump trigger also strands nothing in the new store).
     *
     * @param string $userId User whose families all die.
     * @param string $reason Audit context carried into `revoke_reason`.
     *
     * @return void
     */
    public function revokeAllForUser(string $userId, string $reason): void
    {
        $this->db->query(
            "UPDATE auth_token_sessions SET status = 'revoked', revoke_reason = ?, revoked_at = NOW()"
            . " WHERE user_id = ? AND status = 'active'",
            [$reason, $userId]
        );
    }

    /**
     * Does this family belong to this user? Ownership gate for the revoke
     * endpoint (a foreign family id must be indistinguishable from a missing
     * one — same 404, never a 403 oracle).
     *
     * @param string $sessionId Family id from the path.
     * @param string $userId    Authenticated requester.
     *
     * @return bool
     */
    public function sessionBelongsToUser(string $sessionId, string $userId): bool
    {
        $rows = RowMap::listFromMixed($this->db->query(
            'SELECT id FROM auth_token_sessions WHERE id = ? AND user_id = ?',
            [$sessionId, $userId]
        ));

        return ($rows[0]['id'] ?? null) !== null;
    }

    /**
     * The user's still-alive families, newest activity first — the payload
     * behind GET /api/v1/auth/sessions.
     *
     * Deliberately active-only: a revoked family has already done its job (the
     * client saw the 204); resurrecting it in the list would invite "revive"
     * questions this schema has no answer for.
     *
     * Bounded at MAX_LISTED_SESSIONS (D1a parity cure): the newest activity
     * wins the page. Capability-neutral — DELETE /auth/sessions/{id} takes an
     * id directly and never consults this list, so an off-page family is still
     * revocable (its id lives in the client's own state and the JWT `sid`).
     *
     * @param string $userId Owner.
     *
     * @return list<array{id: string, created_at: string, last_used_at: string,
     *     device_type: string|null, device_name: string|null}>
     */
    public function listActiveSessions(string $userId): array
    {
        $rows = RowMap::listFromMixed($this->db->query(
            'SELECT id, created_at, last_used_at, device_type, device_name'
            . " FROM auth_token_sessions WHERE user_id = ? AND status = 'active'"
            . ' ORDER BY last_used_at DESC'
            . ' LIMIT ' . self::MAX_LISTED_SESSIONS,
            [$userId]
        ));

        $sessions = [];
        foreach ($rows as $raw) {
            // Shape proven by the SELECT's fixed column list (same boundary
            // narrowing as findGrant()); timestamps arrive as MySQL
            // DATETIME->string, the two device columns are the only NULLs.
            /** @var array{id: string, created_at: string, last_used_at: string, device_type: string|null, device_name: string|null} $row */
            $row = $raw;
            $sessions[] = [
                'id' => $row['id'],
                'created_at' => $row['created_at'],
                'last_used_at' => $row['last_used_at'],
                'device_type' => isset($row['device_type']) && is_string($row['device_type'])
                    ? $row['device_type'] : null,
                'device_name' => isset($row['device_name']) && is_string($row['device_name'])
                    ? $row['device_name'] : null,
            ];
        }

        return $sessions;
    }
}
