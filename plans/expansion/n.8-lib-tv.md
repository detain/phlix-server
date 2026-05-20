# Step N.8 — Library Setup: TV Shows

**Phase:** N (End-User Documentation)
**Step:** N.8
**Depends on:** N.6 (first-run wizard)
**Review:** No
**Target repo:** phlex-server (local: /home/sites/phlex/)

## 1. Goal

Write the TV shows library setup guide at `docs/libraries/tv-shows.md`, covering naming conventions (season/episode, absolute, air-date), multi-version episodes, specials, episode title parsing, metadata source priority (TVDB → Fanart.tv → TMDB → local NFO), scanner behavior for show vs. movie detection, and troubleshooting.

## 2. Context (what already exists)

- `docs/libraries/` directory exists — N.6 (or prior) created the docs platform and library index
- `docs/libraries/README.md` indexes all library-type guides (movies, tv-shows, music, etc.)
- The §7 docs tree layout specifies `docs/libraries/tv-shows.md`
- Branch `n.8-lib-tv` will be cut from `master` after N.6 merges
- The scanner and metadata infrastructure is already implemented in `src/Media/Library/MediaScanner.php` and `src/Media/Metadata/`
- N.7 (movies library guide) is the sibling reference; follow the same §7 doc layout with TL;DR, numbered sections, shell blocks, what-can-go-wrong, and next-steps

## 3. Scope

### Create

- `docs/libraries/tv-shows.md` — TV shows library setup guide

### Modify

- `docs/libraries/README.md` (only if it needs the TV shows guide added to the index)

## 4. Doc content outline

### TL;DR (one screen)
- Short paragraph: what a TV library is, what Phlex needs to find and organize your episodes
- Quick-command one-liner for the impatient (drop files → season/episode structure → scan library → browse in UI)
- Supported formats reminder (mkv, mp4, ts, etc.)

### 1. Folder and file naming conventions

#### 1a. Standard season/episode format (recommended)
```
/TV/Show Name/Season 01/Show Name S01E01.episode-title.ext
```
- `Season 01/` folder (not `Season 1/` — scanner requires zero-padded two-digit season number)
- Filename includes `S01E01` episode code — zero-padded season and episode numbers
- `.` or `-` separator between show name and episode code is accepted
- Episode title after the episode code is parsed and displayed in the UI

#### 1b. Compact flat-file format
```
/TV/Show Name/S01E01.ext
```
- No season folder; all episodes of a show in the same directory
- Scanner detects show boundaries by shared show name prefix

#### 1c. Absolute episode numbering
```
/TV/Show Name/1x01.ext
```
- Some anime and older series use absolute numbering instead of season/episode
- `1x01` means episode 1 of the first season (or season 0 depending on provider)
- Also supported: `/TV/Show Name/Episode 001.ext`

#### 1d. Air-date naming
```
/TV/Show Name/Show Name - 2012-03-02.ext
```
- Episode identified by original broadcast date
- Useful for shows where episode order is more stable than numbering
- Scanner tries to match `YYYY-MM-DD` suffix against TVDB air-date records

#### 1e. Multi-version episodes
```
/TV/Show Name (2020)/S01E01 - director's-cut.ext
/TV/Show Name (2020)/S01E01 - unrated.ext
/TV/Show Name (2020)/S01E01 - 4k-restored.ext
```
- Parentheses show name disambiguates from other shows with the same name (different production year)
- Version tag after episode code creates a separate library entry per version
- Each version gets its own playback state and resume position

### 2. Specials and bonus episodes

#### Season 00
```
/TV/Show Name/Season 00/Show Name S00E01.pilot.ext
```
- Special episodes go in `Season 00/` (preferred) or `Specials/`
- Scanned as part of the show, listed in the Specials season in the UI
- Follows same `S00Exx` naming as standard episodes

#### Specials folder alias
```
/TV/Show Name/Specials/S00E01.ext
```
- `Specials/` is accepted as an alias for `Season 00/`
- Both are merged into the same specials grouping in the UI

### 3. Episode title parsing from filename

The scanner extracts the episode title from the filename after the `S01E01` token:

| Filename | Extracted Title |
|----------|-----------------|
| `Show Name S01E01.ext` | (none — uses metadata title) |
| `Show Name S01E01.Pilot.ext` | "Pilot" |
| `Show Name S01E01 - The Pilot.ext` | "The Pilot" |
| `Show Name - 2012-03-02.ext` | (none — uses air-date metadata) |

- Separator between episode code and title: `.`, `-`, or space
- Title is shown in the episode list in the UI
- If no title in filename, metadata-supplied title is used

### 4. Metadata sources and priority

The scanner and metadata manager use this priority order (highest to lowest):

1. **Local NFO** — `show.nfo` at show root, `episode.nfo` alongside episode file
2. **TVDB** — primary TV series metadata provider; Episode and season data
3. **Fanart.tv** — show logos, clearart, background images (used in UI chrome)
4. **TMDB** — fallback for TV series; limited episode-level data but strong show-level data
5. **Local file name** — parsed season/episode numbers and title as fallback

#### NFO file formats

Show-level (`show.nfo` alongside the show root folder):
```xml
<tvshow>
  <title>Show Name</title>
  <year>2020</year>
  <tvdbid>81249</tvdbid>
  <tmdbid>123456</tmdbid>
</tvshow>
```

Episode-level (`episode.nfo` alongside the video file):
```xml
<episodedetails>
  <title>Episode Title</title>
  <season>1</season>
  <episode>1</episode>
  <aired>2020-01-15</aired>
  <plot>Episode plot text.</plot>
</episodedetails>
```

- `tvdbid` is the primary remote lookup key — if present, scanner uses it directly
- `tmdbid` used as fallback when TVDB ID is not available
- 24-hour cache on all remote metadata; manual refresh via "Refresh Metadata" button

### 5. Library scan behavior

#### Show vs. movie detection
Phlex's scanner (`MediaScanner.php`) uses filename patterns to decide whether a file belongs in a TV library or a movie library:

| Pattern | Classification | Example |
|---------|--------------|---------|
| `S01E01`, `1x01` in filename | TV episode | `Show Name S01E01.mkv` |
| `(Year)` only, no episode code | Movie | `Avatar (2009).mkv` |
| `Season 00/` or `Specials/` folder | TV (specials) | `Season 00/` |
| `/TV/Show Name/` flat directory | TV show | All files in show folder |
| Mixed — both episode code AND year in title | TV (episode number wins) | `Show (2020) S01E01.mkv` |

- Adding a library in the UI: select type (Movies or TV Shows) — scanner behavior is further constrained by library type
- If a file matches the wrong library type, it is logged and skipped with a warning in the scan log

#### Scan triggering
- Manual scan: "Scan Library" button in the UI for the library
- Folder watcher: enabled per-library; detects `mtime` changes and queues an incremental scan
- Initial scan: full recursive scan of library root on first add

#### Incremental re-scan
- mtime-based checksum — only files whose modification time changed since last scan are re-processed
- Show-level metadata (poster, fanart) only re-fetched when `metadata_refreshed_at` is older than 24 h

### 6. Content rating and parental controls
- Each user profile has an assigned rating filter (G / PG / PG-13 / R / NC-17 / X / UNRATED)
- TV shows carry a content rating from TVDB metadata; library items above the profile's filter are hidden from that profile's library view
- TV ratings are mapped to the same scale as movies (MPAA equivalent)
- Rating filter can be changed per-profile in Settings → Profiles

### What can go wrong

#### Incorrect season folder naming
- Symptom: Episodes show under a separate show entry, or are not grouped at all
- Cause: Using `Season 1/` instead of `Season 01/` — scanner requires zero-padded two-digit season numbers; `Season 1/` is treated as a different folder type
- Fix: Rename all season folders to `Season 01`, `Season 02`, etc. (zero-padded). Re-scan the library
- Also: `Season 00` is correct for specials — not `Season 0` or `Specials/Season 00/`

#### Episode number conflicts
- Symptom: One episode file plays when clicking a different episode; episode list shows wrong titles
- Cause: Two episodes with the same `S01E01` code in the same show directory (e.g., from a multi-version setup where version tags are missing)
- Fix: Add a version tag to distinguish files: `Show Name S01E01 - directors-cut.mkv` vs. `Show Name S01E01.mkv`. Verify each episode code is unique per show

#### Metadata not matching (TVDB vs. TMDB ID mismatch)
- Symptom: Wrong show information appears (e.g., a different show with the same name); or no metadata found despite correct episode numbers
- Cause: TVDB and TMDB have different IDs for some shows; if scanner matched against the wrong provider initially, metadata may be incorrect or missing. Also: country-specific show variants (e.g., "The Office" UK vs. US) can cross-match incorrectly
- Fix: Create a `show.nfo` in the show root with the correct `tvdbid` (or `tmdbid` as fallback) to lock metadata to the correct provider record. Re-scan to apply

#### Duplicate shows from different paths
- Symptom: The same show appears twice (or more) in the library, each with partial episode lists
- Cause: Adding the same show root folder via two different library paths (e.g., `/data/TV/Show Name` and `/data/TV/Show Name (US)` pointing to the same files via symlink or copy)
- Fix: Use a single library path per show. If using multiple libraries, ensure no symlinks or folder aliases create duplicate scan targets. Run "Empty Library" then re-scan with a single path per show

### Next steps
- [Movies library setup](docs/libraries/movies.md) — film organization, NFO metadata, extras handling
- [Music library setup](docs/libraries/music.md) — album art, FLAC/MP3 tagging, compiled artist albums
- [DLNA / Play To](docs/dlna/play-to.md) — stream TV episodes to DLNA-compatible TVs and devices
