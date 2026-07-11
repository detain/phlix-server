-- Migration: 074_login_rate_limit.sql
-- Description: Central login rate limit store to replace the per-worker static array.
--
-- Problem: The previous static array `$rateLimitStore` in AuthManager was:
--   1. Per-worker (×14 multiplication with 14 workers = 14× brute-force budget)
--   2. Unbounded (IP rotation attack could grow it indefinitely)
--
-- Solution: A central database table with TTL-based expiry. All workers share
-- the same store, so the rate limit budget is unified and bounded by the TTL.
--
-- Schema mirrors the oauth_state_store pattern for consistency:
--   - ip: client IP address ( VARCHAR(45) for IPv6 )
--   - attempts: number of failed attempts in current window
--   - reset_at: Unix timestamp when the window expires
--   - created_at: when the entry was first created

CREATE TABLE IF NOT EXISTS login_rate_limit (
    ip VARCHAR(45) PRIMARY KEY,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    reset_at INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reset_at (reset_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
