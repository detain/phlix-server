-- CP-029 / D-1a (COOKIE-DESIGN.md §5, owner ruling 2026-11-10 "do it"):
-- session-family revocation store for the JWT auth path.
--
-- Two facts the estate has been honest about since the stateless design shipped:
-- the per-user watermark (users.tokens_not_valid_after) is a NUCLEAR option —
-- bumping it kills every token of the account, everywhere, and there is no way
-- to sign one device out, and refresh-token theft was undetectable (§5(c)).
-- This migration adds the missing capability underneath both token paths:
--
--   auth_token_sessions  — one row per LOGIN ceremony (a "family"). Access and
--                          refresh JWTs minted from now on carry its id as the
--                          `sid` claim. Revoking one row ends exactly one
--                          device's chain; revoking the family on token REUSE
--                          ends that device's lineage and nothing else; the
--                          watermark stays untouched as the account-wide
--                          last-man-standing bound (pin-tested zero-drift).
--   auth_refresh_grants  — one row per MINTED refresh token (its `jti` is the
--                          primary key). Rotation marks the presented token
--                          consumed; a replay of a consumed jti OUTSIDE the
--                          grace window is the reuse signal and revokes the
--                          family. A replay INSIDE the window (<= 10s, the
--                          CP-005 design constant mirrored at query time) is
--                          treated as the benign multi-tab race the grace rule
--                          exists for: it rotates on, it does NOT revoke.
--
-- Legacy tokens (no `sid` claim) are structurally outside these tables: the
-- read path consults this store only when the claim is present, so pre-127
-- sessions keep honoring exactly the watermark path they honor today.
--
-- Idempotent by construction (CREATE TABLE IF NOT EXISTS): replaying the file
-- against a database that already carries both tables is a no-op.
--
-- @copyright 2026 Joe Huss <detain@interserver.net>
-- @license   MPL-2.0

CREATE TABLE IF NOT EXISTS auth_token_sessions (
    id CHAR(36) NOT NULL PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    status ENUM('active', 'revoked') NOT NULL DEFAULT 'active',
    revoke_reason VARCHAR(64) NULL,
    device_type VARCHAR(50) NULL,
    device_name VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_ats_user (user_id),
    INDEX idx_ats_user_status (user_id, status),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_refresh_grants (
    jti CHAR(32) NOT NULL PRIMARY KEY,
    session_id CHAR(36) NOT NULL,
    consumed_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_arg_session (session_id),
    INDEX idx_arg_consumed (consumed_at),
    FOREIGN KEY (session_id) REFERENCES auth_token_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
