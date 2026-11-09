-- Migration: 126_login_rate_limit_account.sql
-- Description: Per-ACCOUNT login rate limit window alongside the per-IP store.
--
-- Problem (SH-017, secaudit finding): the brute-force budget lived ONLY on the
-- client IP (migration 074). An attacker spread across many source IPs — a
-- botnet, or simply a dynamic pool — gets a fresh 5-attempt window per IP and
-- can grind one account's password indefinitely without ever hitting a limit.
--
-- Solution: a second window keyed by the SUBMITTED IDENTIFIER (username/email,
-- normalized trim+lowercase by the caller), charged by the very same code path
-- that charges the IP. The account budget follows the victim, not the source.
--
-- Schema mirrors login_rate_limit (074) exactly, substituting `identifier`
-- (VARCHAR(255): usernames and emails are far under this; the column width is
-- the storage bound, not a validation bound) for `ip`.
--
-- Known tradeoff (accepted, mirroring the IP ladder's own posture): one user
-- typo-flooding their OWN account locks that account's window for the TTL
-- (self-DoS), and a malicious actor CAN lock someone else's login for 15
-- minutes per burst. The envelope is bounded (900s window, no persistence),
-- the 429 carries Retry-After, and the alternative — IP-only budgeting —
-- silently forfeits the primary brute-force defense. Same posture is standard
-- for this ladder (see {@see \Phlix\Auth\DbLoginRateLimitStore} class doc).

CREATE TABLE IF NOT EXISTS login_rate_limit_account (
    identifier VARCHAR(255) PRIMARY KEY,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    reset_at INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reset_at (reset_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
