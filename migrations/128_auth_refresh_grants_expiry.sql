-- Migration: 128_auth_refresh_grants_expiry.sql
-- Description: D1a parity cure — lifetime stamp + sweep index on the refresh-grant
-- ledger (owner ruling 2026-11-11, hub twin 3096c53e shipped the same semantics).
--
-- WHY THIS COLUMN EXISTS:
-- Migration 127 made refresh-token theft detectable: every minted refresh token
-- registers a `jti` grant row, and presenting a CONSUMED grant revokes the whole
-- family (§5(c)). But nothing ever deleted a grant row. A consumed token that is
-- simply never stolen leaves its row behind forever — one insert per rotation,
-- per device, per day, unbounded; the family FK cascade only fires when the
-- session itself dies. And `listActiveSessions` had no ceiling: an account with
-- thousands of families paid for all of them on every settings-page load.
--
-- THE CURE, in two halves:
--   1. `expires_at` — the grant stores the refresh token's OWN baked expiry
--      (the JWT `exp` claim the mint already reads back), so the row can never
--      disagree with the token it indexes. A sweep arm can then drop rows whose
--      tokens have expired AND been dead for a grace window (86400s below — the
--      theft sensor only needs the grant while the token itself still verifies).
--   2. `idx_arg_expires_at` — the sweep's WHERE arm needs it; without the index
--      the write-time prune degenerates into a table scan under load.
--
-- Rows minted BEFORE this migration carry NULL (the column is nullable by
-- design — no backfill fiction: we do not know those tokens' exps). They age
-- out through the unstamped arm at UNSTAMPED_RETENTION_SECONDS (31536000s =
-- 365 days after created_at), a full year of forensic headroom.
--
-- Idempotent: ALTER TABLE IF NOT EXISTS is not valid MySQL, so both statements
-- guard with the established INFORMATION_SCHEMA + PREPARE pattern (see 107 for
-- the column probe, 096 for the STATISTICS-based index probe); re-runs execute
-- `SELECT 1` and record nothing.

SET @dbname = DATABASE();
SET @preparedStatement = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'auth_refresh_grants' AND COLUMN_NAME = 'expires_at') = 0,
    'ALTER TABLE auth_refresh_grants ADD COLUMN expires_at TIMESTAMP NULL DEFAULT NULL',
    'SELECT 1'
));
PREPARE addColumnIfNotExists FROM @preparedStatement;
EXECUTE addColumnIfNotExists;
DEALLOCATE PREPARE addColumnIfNotExists;

SET @preparedStatement = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'auth_refresh_grants' AND INDEX_NAME = 'idx_arg_expires_at') = 0,
    'ALTER TABLE auth_refresh_grants ADD INDEX idx_arg_expires_at (expires_at)',
    'SELECT 1'
));
PREPARE addIndexIfNotExists FROM @preparedStatement;
EXECUTE addIndexIfNotExists;
DEALLOCATE PREPARE addIndexIfNotExists;
