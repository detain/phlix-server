-- Step: IMDb alternate/localized titles (akas)
-- Creates the `imdb_title_akas` table that holds a local copy of the useful
-- subset of IMDb's free daily `title.akas.tsv.gz` dataset (alternate + localized
-- titles). It widens metadata MATCHING for files whose on-disk title differs
-- from the canonical primaryTitle (foreign titles, transliterations, alternate
-- spellings). The importer (ImdbDatasetImporter) UPSERTs into it and ImdbLookup
-- queries `normalized_title` for a conservative exact-match fallback.
--
-- Bounding / filtering (applied by the importer, documented here):
--   * Only akas whose `tconst` is one of the movie-type titles kept in
--     `imdb_titles` are stored — akas for series/episodes/shorts are dropped.
--   * Rows whose normalized aka equals the title's normalized primaryTitle are
--     skipped (pure duplicates add no matching value); region/language variants
--     are kept.
--
-- `normalized_title` is byte-faithful with `imdb_titles.normalized_title`
-- (ImdbDatasetImporter::normalizeTitle) so both tables share the lookup key.
--
-- Uses CREATE TABLE IF NOT EXISTS so it is safe to re-run (the migration runner
-- applies all migrations every time, with no tracking table).

CREATE TABLE IF NOT EXISTS imdb_title_akas (
    tconst            VARCHAR(16)       NOT NULL,
    ordering          SMALLINT UNSIGNED NOT NULL,
    title             VARCHAR(512)      NOT NULL,
    normalized_title  VARCHAR(255)      NOT NULL,
    region            VARCHAR(8)        NULL,
    language          VARCHAR(8)        NULL,
    is_original_title TINYINT(1)        NOT NULL DEFAULT 0,
    PRIMARY KEY (tconst, ordering),
    INDEX idx_akas_norm (normalized_title),
    INDEX idx_akas_tconst (tconst)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
