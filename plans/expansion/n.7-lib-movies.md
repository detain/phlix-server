# Step N.7 — Library Setup: Movies

**Phase:** N (End-User Documentation)
**Step:** N.7
**Depends on:** N.6 (first-run wizard)
**Review:** No
**Target repo:** phlex-server (local: /home/sites/phlex/)

## 1. Goal

Write the movies library setup guide at `docs/libraries/movies.md`, covering naming conventions, NFO sidecar files, supported formats, metadata sources, scanner behavior, and troubleshooting.

## 2. Context (what already exists)

- `docs/libraries/` directory exists — N.6 (or prior) created the docs platform and library index
- `docs/libraries/README.md` indexes all library-type guides (movies, tv, music, etc.)
- The §7 docs tree layout specifies `docs/libraries/movies.md`
- Branch `n.7-lib-movies` will be cut from `master` after N.6 merges
- The scanner and metadata infrastructure is already implemented in `src/Media/Library/MediaScanner.php` and `src/Media/Metadata/`

## 3. Scope

### Create

- `docs/libraries/movies.md` — Movies library setup guide

### Modify

- `docs/libraries/README.md` (only if it needs the movies guide added to the index)

## 4. Doc content outline

### TL;DR (one screen)
- Short paragraph: what a movies library is, what Phlex needs to find and organize your files
- Quick-command one-liner for the impatient (drop files → scan library → browse in UI)

### 1. Supported file formats
- Table: Format | Extension | Notes
- mkv, mp4, avi, mov, m4v, wmv, ts
- Mention that container-agnostic detection is used; codec matters more than container for playback compatibility

### 2. Naming conventions

#### 2a. Flat-file naming
- `Movie Name (Year).ext` — e.g., `Avatar (2009).mkv`
- Year in parentheses is the primary identifier the scanner uses to match metadata

#### 2b. Folder-based naming
- `Movie Name, The (Year)/` folder with the video file inside
- e.g., `The Matrix (1999)/Matrix, The.mp4`
- Handles articles ("The", "A", "An") sort-prefixed at end of folder name

#### 2c. Multi-version movies
- `Movie Name (Year) - directors-cut.ext`
- `Movie Name (Year) - extended.ext`
- `Movie Name (Year) - 4k-restored.ext`
- Each version appears as a separate entry in the library

#### 2d. Disc folder structure (optional)
- `Movie Name (Year)/` containing `movie.mkv` + `trailer.mkv` + `extras/` subfolder

### 3. Extras and trailers
- `-trailer.mkv`, `-sample.mkv` are excluded from the main library count but scanned into the item
- `-extras/` folder scanned as associated extras for the nearest parent movie
- Naming convention within extras folder is free-form (scanner uses parent context)

### 4. NFO sidecar files (Kodi-style metadata)
- `movie.nfo` alongside the video file
- Example content:
  ```xml
  <movie>
    <title>Avatar</title>
    <year>2009</year>
    <tmdbid>241</tmdbid>
    <plot>...</plot>
  </movie>
  ```
- `tmdbid` field is the primary lookup key; if absent, falls back to title+year match against TMDB
- Local NFO overrides remote metadata when `metadata_source` is set to `local` in library config

### 5. Metadata sources and priority
- TMDB (default, free account at themoviedb.org)
- TVDB (fallback)
- Local NFO (highest priority when present)
- 24-hour cache on remote metadata to avoid rate limiting
- Manual refresh via UI ("Refresh Metadata" button on any item)

### 6. Library scan behavior
- How phlex-scanner distinguishes movies from TV episodes:
  - Movies: no `S01E02` style episode pattern in filename
  - Movies: `(Year)` pattern in title, year 1900–current+5
  - TV episodes: detected by `Season X / Episode Y` or `S00E00` patterns
- Scan triggered: manually from UI, or via folder watcher on file-system changes
- mtime-based checksum for incremental re-scans (only changed files re-processed)

### 7. Content rating and parental controls
- Each user profile has an assigned rating filter (G / PG / PG-13 / R / NC-17 / X / UNRATED)
- Movies rated above the profile's filter are hidden from that profile's library view
- Rating is pulled from TMDB/TVDb metadata; NFO-sourced items fall back to UNRATED
- Profile rating can be changed in Settings → Profiles

### What can go wrong

#### Duplicate entries / split library
- Symptom: Same movie appears 2–4 times in the library after a rescan
- Cause: Mixing flat-file and folder-based naming for the same movie; inconsistent year in title
- Fix: Deduplicate naming — pick one style per library. Run "Empty Library" then re-scan

#### Metadata not found / wrong match
- Symptom: Movie shows as "Unknown Title" or matches the wrong film
- Cause: Year mismatch between filename and TMDB record; special characters in title break parsing
- Fix: Rename file to `Movie Name (Year).ext`, or create a `movie.nfo` with the correct `tmdbid`

#### NFO file ignored
- Symptom: Local metadata (custom poster, plot, genre tags) not appearing in UI
- Cause: NFO not named `movie.nfo` (case-sensitive on Linux), or NFO contains malformed XML
- Fix: Verify filename is exactly `movie.nfo` and valid XML. Validate at [xmlvalidation.com](https://www.xmlvalidation.com)

#### Metadata fetch failure / rate limit
- Symptom: New movies scan but show "No metadata" despite correct naming
- Cause: TMDB API rate limit (40 req/s, 4 req/s for unauthenticated), or network unreachable
- Fix: Wait 10s and click "Refresh Metadata" on the item. Add TMDB API key in library settings for higher rate limit

#### Parental control content leaking through
- Symptom: Restricted content visible on a child profile
- Cause: Profile rating filter set too high, or media item has UNRATED metadata (not filtered)
- Fix: Lower profile rating in Settings → Profiles. In UI, mark the item manually with a content rating

### Next steps
- [TV library setup](docs/libraries/tv.md) — episode naming conventions, season/folder structure, season art
- [Music library setup](docs/libraries/music.md) — album art, FLAC/MP3 tagging, compiled artist albums
- [DLNA / Play To](docs/dlna/play-to.md) — stream movies to DLNA-compatible TVs and devices
