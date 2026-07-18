-- Migration 083: Expand parental-control content-rating ENUMs with US TV ratings
--
-- Phase C (certification) natively expands the rating vocabulary from the seven
-- MPAA movie ratings (G/PG/PG-13/R/NC-17/X/UNRATED) to also include the US TV
-- ratings, interleaved on a single ascending-restrictiveness scale (see
-- Phlix\Media\Library\ContentRating):
--
--   rank 0: G, TV-Y, TV-G   rank 1: TV-Y7      rank 2: PG, TV-PG
--   rank 3: PG-13, TV-14    rank 4: R, TV-MA   rank 5: NC-17
--   rank 6: X               rank 7: UNRATED
--
-- The two ENUM columns below cap a profile's / account's allowed content. The
-- new TV values are APPENDED after the existing ones so every already-stored
-- value keeps its ENUM ordinal (MySQL stores ENUMs by index) and no data is
-- rewritten. `NR` ("Not Rated") is deliberately NOT added: it is normalized to
-- `UNRATED` on ingest, so it can never be stored.
--
-- MODIFY COLUMN to the full target definition is idempotent — re-running yields
-- the identical column definition. The existing DEFAULT 'R' is preserved.
--
-- NOTE: the profile-level cap lives on `profile_settings.content_rating`
-- (queried by UserProfileManager), not on `user_profiles`.

ALTER TABLE profile_settings
    MODIFY content_rating
        ENUM('G','PG','PG-13','R','NC-17','X','UNRATED','TV-Y','TV-G','TV-Y7','TV-PG','TV-14','TV-MA')
        DEFAULT 'R';

ALTER TABLE user_settings
    MODIFY default_content_rating
        ENUM('G','PG','PG-13','R','NC-17','X','UNRATED','TV-Y','TV-G','TV-Y7','TV-PG','TV-14','TV-MA')
        DEFAULT 'R';
