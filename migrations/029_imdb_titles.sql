-- Step: IMDb Data Layer
-- Creates the `imdb_titles` table that holds a local copy of IMDb's free daily
-- datasets (title.basics.tsv.gz joined with title.ratings.tsv.gz), filtered to
-- movie-type titles. This is the foundation for movie metadata matching; the
-- importer (ImdbDatasetImporter) UPSERTs into it and ImdbLookup queries it.
--
-- Uses CREATE TABLE IF NOT EXISTS so it is safe to re-run (the migration runner
-- applies all migrations every time, with no tracking table).

CREATE TABLE IF NOT EXISTS imdb_titles (
    tconst            VARCHAR(16)       NOT NULL,
    primary_title     VARCHAR(512)      NOT NULL,
    original_title    VARCHAR(512)      NULL,
    normalized_title  VARCHAR(255)      NOT NULL,
    title_type        VARCHAR(20)       NOT NULL,
    start_year        SMALLINT UNSIGNED NULL,
    genres            VARCHAR(255)      NULL,
    runtime_minutes   INT UNSIGNED      NULL,
    average_rating    DECIMAL(3,1)      NULL,
    num_votes         INT UNSIGNED      NULL,
    PRIMARY KEY (tconst),
    INDEX idx_norm_year (normalized_title, start_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
