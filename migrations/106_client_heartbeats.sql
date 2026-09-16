-- Migration: 106_client_heartbeats.sql
-- Description: landing table for the AD-27 consent-gated CLIENT telemetry
--              heartbeat (S518) — one UPSERTed row per consenting client
--              instance, no user/IP/PII columns, no per-tick history.
--
-- WHY THIS TABLE EXISTS (the mint's "no durable schema unless re-derived" test,
-- re-derived against live source, 2026-09-16):
--
--   * `server_heartbeats` is the WRONG AUDIENCE and structurally unusable: it is
--     the enrolled-SERVER's hub telemetry with `server_id CHAR(36) NOT NULL` +
--     FK to `servers(id) ON DELETE CASCADE`. A pairing-less smart-TV client has
--     no server row to name, and inventing one per device is a PII/ownership lie.
--   * `stats_user_activity` requires a non-null user id — recording anonymous
--     pre-pairing ticks there would join "device census" to "account" rows the
--     consent explicitly does NOT cover.
--   * `oauth_state_store` (the S518 quick-connect carrier) is TTL-transient by
--     contract — heartbeats must persist across restarts or the fleet census
--     resets to empty every deploy, defeating the point (TN-4 firmware floor).
--
-- RETENTION POSTURE (this doubles as it): the PRIMARY KEY is the device's own
-- opaque instance id and the write path is INSERT..ON DUPLICATE KEY UPDATE, so
-- the table is BOUNDED BY FLEET SIZE. There is no append growth to reap;
-- stale rows simply stop refreshing `last_seen_at`. A reader that wants
-- "active in the last 30 days" filters on `last_seen_at` — never add a
-- per-tick history column to this table, it voids the boundedness argument.
--
-- Privacy invariant for future edits: NO column here may hold user ids, IPs,
-- hostnames, or anything the tier-3 opt-in consent text ("version, platform and
-- build info for this installation") does not cover.

CREATE TABLE IF NOT EXISTS client_heartbeats (
    instance_id CHAR(64) NOT NULL,
    version VARCHAR(32) NOT NULL,
    client_type VARCHAR(32) NOT NULL,
    build_token VARCHAR(64) NOT NULL DEFAULT '',
    first_seen_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    PRIMARY KEY (instance_id),
    KEY ix_client_heartbeats_last_seen (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
