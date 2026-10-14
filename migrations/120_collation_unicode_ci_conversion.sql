-- Migration: 120_collation_unicode_ci_conversion.sql
--
-- R19-09: finish the collation drift these ten tables inherited.
--
-- The estate schema is utf8mb4 / utf8mb4_unicode_ci. Ten later migrations
-- created their tables with `DEFAULT CHARSET=utf8mb4` but NO COLLATE clause,
-- so on MySQL 8 they silently took the server-default utf8mb4_0900_ai_ci.
-- Mixed collations are not just untidy: a JOIN or comparison between a drifted
-- column and a unicode_ci column raises "Illegal mix of collations" or forces
-- the optimiser to coerce per-row (unindexable). The drifted set (table ->
-- creating migration):
--
--   audiobook_progress          (012)
--   network_health_log          (057)
--   access_schedules            (061)
--   profile_access_schedule     (061)
--   profile_tags                (062)
--   user_recommendations        (062)
--   profile_stream_limits       (063)
--   active_streams              (063)
--   book_progress               (075)
--   schema_migrations           (076)
--
-- All ten are small adjunct tables (progress trackers, schedules, tags, the
-- migration ledger itself); none carries a FOREIGN KEY in either direction, so
-- no referential-child rebuild rides along. network_health_log and
-- user_recommendations are the two that can actually grow (log rows / 100 recs
-- per user) — they are still orders of magnitude below media_items scale.
--
-- Cost, stated honestly: ALTER TABLE ... CONVERT TO CHARACTER SET is a table
-- REBUILD (ALGORITHM=COPY for charset changes InnoDB cannot do in-place when
-- collation affects stored ordering; MySQL will pick the most online algorithm
-- it can), not an instant metadata flip. On these row counts that is seconds;
-- run as part of a normal deploy, not against a billion-row table (which is
-- why media_items/library tables were already unicode_ci and need nothing).
--
-- One theoretical data risk: profile_tags.uk_profile_tag is UNIQUE over
-- user-authored text. A case-insensitive-to-case-insensitive recollation
-- (_0900_ai_ci -> unicode_ci) does not make new case-folding equalities (both
-- are case- and accent-insensitive), so an existing 1062 collision cannot
-- newly appear; a rename conflict would require an accent pair the two
-- collations treat differently, which is not reachable by this direction of
-- conversion.
--
-- Replay: CONVERT TO on an already-converted table is legal and a content
-- no-op; MySQL re-runs it without error, so no squelch is needed.

ALTER TABLE `audiobook_progress` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `network_health_log` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `access_schedules` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `profile_access_schedule` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `profile_tags` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `user_recommendations` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `profile_stream_limits` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `active_streams` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `book_progress` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `schema_migrations` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
