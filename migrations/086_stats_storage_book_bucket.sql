-- Migration 086: Add the `book` bucket to stats_storage.media_type.
--
-- WHY: the storage snapshot folds the 13-member `media_items.type` ENUM down
-- into the coarse buckets `stats_storage.media_type` accepts. Migration 019
-- created that column as ENUM('movie','series','music','photo') — which left
-- `book` and `audiobook` rows with no destination at all, so the snapshot
-- dropped them on the floor and the admin dashboard silently under-reported
-- total library size once a book/audiobook library was populated.
--
-- Widening the ENUM (rather than mis-filing books under `music`) keeps the
-- dashboard honest: audiobooks are book-shelf content that happens to be
-- audio-encoded, and counting their bytes as Music produces a wrong number
-- that *looks* right, which is worse than a visibly missing one.
--
-- SCOPE: additive only. Existing rows are untouched, and every currently
-- stored value remains valid, so this is safe to replay and safe to run
-- against a live table without rewriting existing data.
--
-- CONSUMER: `Phlix\Stats\StorageSnapshotHelper::TYPE_TO_BUCKET` is the single
-- source of truth for the fold and must stay in lockstep with this ENUM.

ALTER TABLE stats_storage
    MODIFY COLUMN media_type ENUM('movie', 'series', 'music', 'photo', 'book') NOT NULL;
