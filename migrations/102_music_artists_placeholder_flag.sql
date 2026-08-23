-- Migration: 102_music_artists_placeholder_flag.sql
-- Description: Add the structural placeholder-artist flag to `music_artists` and
--              widen `uk_name` so a REAL artist of the same display name can
--              coexist with the placeholder row (S331).
--
-- ## Why this column exists
--
-- S331 reverses the music scanner's "unknown artist" POLICY: an album whose
-- artist tag is absent/empty/`Unknown Artist` is no longer discarded whole — it
-- is ingested under a structural placeholder artist (display label
-- `[Unknown Artist]`). The placeholder MUST NOT be a bare magic string that a
-- real tag can impersonate: an album genuinely tagged `[Unknown Artist]` must
-- resolve to its OWN artist row, never the untagged bucket.
--
-- `is_placeholder` is the structural marker. The scanner detects untagged-ness
-- by the ABSENCE of an artist tag (never by name-matching the token), resolves
-- the placeholder BY ID through a lookup filtered on `is_placeholder = 1`, and
-- `upsertArtist()`'s natural-key lookup filters `is_placeholder = 0` — so a real
-- artist whose name equals the display token can never be returned in place of
-- the placeholder, or vice versa.
--
-- ## Why the unique key changes
--
-- `music_artists.name` was `UNIQUE` (migration 065, `uk_name`). The placeholder
-- row's display name IS the token, and a REAL artist of the same name is a
-- legitimate row — the old key made the two mutually exclusive, which is exactly
-- the impersonation hazard. The composite `(name, is_placeholder)` keeps the
-- find-or-create contract for real artists (a name is still unique among
-- `is_placeholder = 0` rows — the column defaults to 0, so every existing row
-- keeps its old guarantee byte-for-byte) while allowing ONE placeholder row per
-- display name.
--
-- Idempotent: a replay raises 1060 (duplicate column) and 1061/1091 (duplicate
-- /missing index) — all in the runner's idempotent set, so re-running is a
-- success and the file stays in the ledger.
--
-- ## Operator action
--
-- A SCAN (or rescan) of each music library is REQUIRED after deploying this
-- migration: albums the old policy discarded were never written to the database,
-- and no backfill can reconstruct them without re-reading the audio files. The
-- migration itself only adds the column — it touches no rows.

ALTER TABLE `music_artists`
    ADD COLUMN `is_placeholder` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = structural placeholder artist for untagged albums (S331); referenced by id, never by name-matching' AFTER `sort_name`,
    DROP INDEX `uk_name`,
    ADD UNIQUE KEY `uk_name` (`name`, `is_placeholder`);