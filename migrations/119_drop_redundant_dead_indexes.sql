-- Migration: 119_drop_redundant_dead_indexes.sql
--
-- R19-15: drop indexes that cannot help any query.
--
--   users.idx_username / users.idx_email
--     `username` and `email` were declared with inline UNIQUE in 001, which
--     already creates a unique index named after each column, and the same
--     file then added a plain duplicate INDEX over the exact same single
--     column. Every lookup a planner could serve with idx_username is served
--     identically (better — uniqueness-aware) by the UNIQUE `username`; the
--     plain twin only costs write amplification on the estate's hottest small
--     table.
--
--   item_similar.idx_score
--     A (score DESC) index across ALL seed-items' similarity rows. Both
--     readers are per-seed: SimilarityService::getSimilar() and the because-
--     you-watched batch read go through the PK prefix (media_item_id, ...) —
--     no query in src/, scripts/ or migrations/ sorts or filters item_similar
--     by score without a media_item_id predicate, and a repo-wide grep proves
--     nothing ever FORCEs/USEs these index names, so the drops are inert to
--     every plan.
--
-- Replay: DROP INDEX on an already-dropped key raises 1091, which the
-- migration runner downgrades to a note (MigrationRunner::isAlreadyApplied);
-- a pre-vanilla box that never had them gets the same note.

ALTER TABLE `users` DROP INDEX `idx_username`;
ALTER TABLE `users` DROP INDEX `idx_email`;
ALTER TABLE `item_similar` DROP INDEX `idx_score`;
