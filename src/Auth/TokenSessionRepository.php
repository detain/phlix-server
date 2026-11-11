<?php

/**
 * Phlix media server component: Auth.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Auth;

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
     * @param string      $userId     Owner of the family.
     * @param string|null $deviceType Display metadata ('tv', 'mobile', …) or null.
     * @param string|null $deviceName Display metadata, untrusted, length-capped
     *                                by the VARCHAR(255) column.
     *
     * @return string The 36-char UUID family id.
     */
    public function createSession(string $userId, ?string $deviceType = null, ?string $deviceName = null): string
    {
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
     * @param string $jti       The new refresh token's `jti`.
     * @param string $sessionId Family that owns it.
     *
     * @return void
     */
    public function recordGrant(string $jti, string $sessionId): void
    {
        $this->db->query(
            'INSERT INTO auth_refresh_grants (jti, session_id) VALUES (?, ?)',
            [$jti, $sessionId]
        );
    }

    /**
     * Mark the presented refresh token as used (rotation consumed it).
     *
     * @param string $jti Presented token's `jti`.
     *
     * @return void
     */
    public function markGrantConsumed(string $jti): void
    {
        $this->db->query(
            'UPDATE auth_refresh_grants SET consumed_at = NOW() WHERE jti = ? AND consumed_at IS NULL',
            [$jti]
        );
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
            . ' ORDER BY last_used_at DESC',
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
