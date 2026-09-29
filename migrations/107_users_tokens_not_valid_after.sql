-- Migration: 107_users_tokens_not_valid_after.sql
-- Description: per-user token-revocation watermark (M-1, security audit 2026-09-29).
--
-- WHY THIS COLUMN EXISTS:
-- The jti claim on every refresh token was minted for exactly this purpose — a
-- server-side revocation denylist — but nothing ever checked it, so `logout`
-- was a cookie wipe and a password change left every already-issued JWT pair
-- valid until natural expiry. This column is the per-user half of the denylist:
-- "no token ISSUED AT OR BEFORE this instant may authenticate any more."
--
-- Semantics (enforced by \Phlix\Auth\AuthManager on both the access-token and
-- refresh-token paths, alongside the 5s-cached status check):
--   * NULL                    → no revocation has ever been recorded; every
--                               correctly-signed, unexpired token is honoured
--                               (byte-identical to pre-migration behaviour).
--   * DATETIME (UTC-agnostic  → all tokens whose `iat` is <= the watermark are
--     server clock, same      rejected. Explicit logout, password change/reset
--     basis as NOW())         and admin disable/delete bump it to NOW().
--
-- Granularity is per-USER, not per-device: bumping kills every outstanding
-- pair for the account ("log out everywhere"), which is the documented
-- behaviour of the logout surface today and the correct blast radius for a
-- password change or a disabled account. A per-device revocation tier stays
-- reserved for the jti denylist if a future session-scoped logout needs it.
--
-- The read rides the same hot-path lookup as `status`
-- ({@see \Phlix\Auth\UserRepository::getAuthState()}) so revocation checking
-- costs zero extra queries per request.
--
-- Idempotent: ALTER TABLE IF NOT EXISTS is not valid MySQL, so guard with the
-- established INFORMATION_SCHEMA + PREPARE pattern (see migration 045).

SET @dbname = DATABASE();
SET @preparedStatement = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'users' AND COLUMN_NAME = 'tokens_not_valid_after') = 0,
    'ALTER TABLE users ADD COLUMN tokens_not_valid_after DATETIME NULL DEFAULT NULL',
    'SELECT 1'
));
PREPARE addColumnIfNotExists FROM @preparedStatement;
EXECUTE addColumnIfNotExists;
DEALLOCATE PREPARE addColumnIfNotExists;
