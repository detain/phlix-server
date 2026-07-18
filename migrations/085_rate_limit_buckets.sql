-- Migration: 085_rate_limit_buckets.sql
-- Description: Shared, DB-backed per-surface rate-limit buckets (SV-4.15 sub-step e).
--
-- Problem: the worker-local in-memory RateLimiter counts INDEPENDENTLY on each
--   HTTP worker, so the real brute-force budget is roughly `max × workers`
--   (~14× with 14 workers). That is a genuine weakening on the surfaces where
--   it matters (register / refresh / WebAuthn ceremonies), which need TRUE
--   global enforcement across every resident worker.
--
-- Solution: a central table with one row per OPAQUE bucket key and a TTL-based
--   expiry, so all workers share one counter per key. Written by
--   `Phlix\Common\RateLimit\DbRateLimiter` via an atomic
--   `INSERT … ON DUPLICATE KEY UPDATE`, and kept bounded by a lazy batch sweep.
--
-- Distinct from migration 074's `login_rate_limit` (ip VARCHAR(45) PK), which
--   stays UNCHANGED and continues to back `login` via DbLoginRateLimitStore.
--   This table is keyed by an OPAQUE `rate_key` string (e.g. `register:<ip>`,
--   `webauthn_start:<user>`) so a single table serves every non-login surface.
--
-- Schema:
--   - rate_key:   opaque bucket key, VARCHAR(191) (utf8mb4 index-length safe)
--   - attempts:   number of attempts recorded in the current window
--   - reset_at:   Unix timestamp when the window expires
--   - created_at: Unix timestamp when the bucket row was first created
--
-- Uses CREATE TABLE IF NOT EXISTS so it is safe to re-run: the MigrationRunner
-- applies all migrations every time (no tracking table) and downgrades
-- duplicate-table/column/key errors to idempotent notes.

CREATE TABLE IF NOT EXISTS rate_limit_buckets (
    rate_key   VARCHAR(191) NOT NULL,
    attempts   INT UNSIGNED NOT NULL DEFAULT 0,
    reset_at   INT UNSIGNED NOT NULL,
    created_at INT UNSIGNED NOT NULL,
    PRIMARY KEY (rate_key),
    INDEX idx_reset_at (reset_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
