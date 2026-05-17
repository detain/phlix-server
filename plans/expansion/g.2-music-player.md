# Step G.2 — Music player route + ID3/MP4 tag harvest

**Phase:** G (Music / Photos / Books / Audiobooks)
**Step:** G.2
**Depends on:** G.1
**Review:** Yes — see `g.2-music-player-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Wire music library scanning with ID3v2 / MP4 tag harvesting, add
`/music/*` HTTP routes (artists, albums, tracks, now-playing), and
render the first music player views in the web portal so a user can
browse their music collection. This step covers the complete back-end
flow: media scan → tag parse → metadata lookup → API routes → front-end
views. G.3 (Last.fm) builds on this by scrobbling what G.2 plays.

## 2. Context (what already exists)

- `src/Media/Library/MediaScanner.php` — existing scanner; parses
  `S01E02` and `(2020)` patterns for video; will be extended to handle
  audio file patterns (`trackTitle.mp3`, `01 - trackTitle.flac`).
- `src/Media/Metadata/MetadataManager.php` — after G.1, already has
  music providers wired. G.2 connects the scanner to it.
- `src/Server/Http/Router.php` — existing router; music routes will be
  added here.
- `src/Server/WebPortal/WebPortalRouter.php` — web portal routes;
  music views registered here.
- `public/templates/` — existing Smarty templates; music templates
  will be added.
- `PHLEX_EXPANSION_PLAN.md` §1 — "Music providers + music player route"
  is **Missing** after G.1 is done.
- `PHLEX_EXPANSION_PLAN.md` §2 Phase G table — G.2 is the player route
  + tag harvest step.

## 3. Scope — files to create / modify

### Create

#### New classes

- `src/Media/Library/AudioScanner.php` — extends `MediaScanner` for
  audio files; handles: FLAC ( Vorbis comments), MP3 (ID3v2.3/2.4),
  M4A/AAC (MP4 atoms), OGG (Vorbis comment). Uses `getID3()` library or
  inline parsing:

  ```php
  class AudioScanner extends MediaScanner
  {
      /** Returns tag metadata for an audio file. Never throws; returns [] on failure. */
      public function harvestTags(string $path): array {}
      // {
      //   title, artist, album, album_artist, year, genre,
      //   track_number, disc_number, duration_secs, bitrate,
      //   sample_rate, channels, composer, comment
      // }

      /** Scan a music library folder and yield media item rows. */
      public function scanMusicLibrary(string $library_path): \Generator {}
  }
  ```

- `src/Media/Library/MusicLibraryManager.php` — orchestrates music
  library scan + tag harvest + metadata enrichment:

  ```php
  class MusicLibraryManager
  {
      public function __construct(
          private readonly AudioScanner $scanner,
          private readonly MetadataManager $metadata,
          private readonly ItemRepository $item_repo,
          private readonly ?LoggerInterface $logger = null,
      ) {}

      /** Full rescan of a music library: harvest tags → lookup metadata → upsert items. */
      public function rescanLibrary(string $library_id): ScanResult {}

      /** Upsert a single track by path. */
      public function upsertTrack(string $library_id, string $path): ?MediaItem {}
  }
  ```

- `src/Server/Http/Controllers/MusicController.php` — music API
  endpoints:

  ```php
  class MusicController
  {
      // GET /music/artists
      public function listArtists(Request $req): Response {}

      // GET /music/artists/{mbid}
      public function getArtist(Request $req, array $params): Response {}

      // GET /music/albums
      public function listAlbums(Request $req): Response {}

      // GET /music/albums/{mbid}
      public function getAlbum(Request $req, array $params): Response {}

      // GET /music/tracks
      public function listTracks(Request $req): Response {}

      // GET /music/tracks/{id}
      public function getTrack(Request $req, array $params): Response {}

      // GET /music/now-playing
      public function nowPlaying(Request $req): Response {}
  }
  ```

- `src/Media/Music/MusicLibraryType.php` — library type plugin
  registration (hook: `library.type`):

  ```php
  final class MusicLibraryType implements LibraryTypeInterface
  {
      public const TYPE = 'music';
      public function getType(): string {}
      public function getLabel(): string {}
      public function getScanner(): AudioScanner {}
  }
  ```

- `public/templates/music/` directory with:
  - `artists.tpl` — grid of artist cards
  - `artist.tpl` — artist detail with album list
  - `albums.tpl` — grid of album cards
  - `album.tpl` — album detail with track listing
  - `tracks.tpl` — searchable track list
  - `player.tpl` — embedded music player (album art, progress, controls)
  - `partials/music_card.tpl` — reusable music card partial

- `public/assets/css/music.css` — styles for music views (artist grid,
  album grid, track list, player bar).
- `public/assets/js/music-player.js` — music player JS (play, pause,
  seek, next, prev, queue management).

- `migrations/00X_music_library.sql` — schema additions for music:

  ```sql
  -- media_items already handles this via metadata_json
  -- Add library type enum if not using string:
  -- ALTER TABLE libraries ADD COLUMN library_type ENUM('video','music','photo','book','audiobook') DEFAULT 'video';
  ```

- `tests/unit/Media/Library/AudioScannerTest.php`
- `tests/unit/Media/Library/MusicLibraryManagerTest.php`
- `tests/unit/Server/Http/Controllers/MusicControllerTest.php`

#### Documentation

- `docs/libraries/music.md` — new doc: supported formats, tag fields,
  naming conventions, how rescans work, metadata provider priority.

### Modify

- `src/Media/Library/MediaScanner.php` — add `scanAudioFile()`,
  detect audio file by extension; delegate to `AudioScanner`.
- `src/Media/Library/LibraryManager.php` — detect library type
  `music`; route to `MusicLibraryManager` instead of video scanner.
- `src/Server/Http/Router.php` — register `/music/*` routes pointing
  to `MusicController`.
- `src/Server/WebPortal/WebPortalRouter.php` — register
  `/music/artists`, `/music/albums`, `/music/tracks` web portal routes.
- `public/templates/layouts/header.tpl` — add music nav link.
- `composer.json` — add `getID3/getid3` (~1.4 MB, no native ext
  required) for robust tag parsing. If the maintainer prefers pure-PHP
  ID3 parsing, document the decision in the approach section.

### Delete

- None.

## 4. Approach

1. **Pre-flight.** Clean master on `/home/sites/phlex`. Branch:
   `git checkout -b g.2-music-player`.
2. **ID3 tag library decision.** Evaluate `getID3` vs. pure-PHP ID3v2
   parser. Choose based on: no non-pure-PHP extensions required,
   composer-installable, actively maintained. Document the choice in
   approach (inline comment).
3. **AudioScanner.** Pure-PHP tag parser for FLAC/Vorbis, MP3/ID3v2,
   M4A/MP4, OGG/Vorbis. Never throws; returns partial results on best
   effort.
4. **MusicLibraryManager.** Orchestrates scan → tag harvest → metadata
   enrichment → ItemRepository upsert.
5. **MusicController.** REST endpoints for artists, albums, tracks,
   now-playing. Returns JSON; follows the chained `Response` pattern.
6. **LibraryManager integration.** Detect `library_type = 'music'`;
   route to `MusicLibraryManager`.
7. **Web portal.** Smarty templates for artists, albums, tracks,
   player. CSS for grid/list layouts. JS for player controls.
8. **Database.** Migration adds `library_type` column to `libraries`
   table (or confirms string-based type is sufficient — verify first).
9. **Tests.** Write all 3 test files per §5.
10. **Verification bar** (§0.4 minimum bar).
11. **Docs.**
12. **Commit + PR + merge.**

## 5. Tests (REQUIRED — §0.4 minimum bar)

Unit tests (coverage ≥ 85 % on every new class):

1. `AudioScannerTest::test_harvest_tags_flac`
2. `AudioScannerTest::test_harvest_tags_mp3_id3v2`
3. `AudioScannerTest::test_harvest_tags_m4a`
4. `AudioScannerTest::test_harvest_tags_returns_partial_on_failure`
5. `AudioScannerTest::test_scan_music_library_yields_items`
6. `MusicLibraryManagerTest::test_rescan_library_calls_scanner`
7. `MusicLibraryManagerTest::test_upsert_track_stores_tags`
8. `MusicLibraryManagerTest::test_upsert_track_enriches_via_metadata_manager`
9. `MusicControllerTest::test_list_artists_returns_json`
10. `MusicControllerTest::test_get_artist_returns_404_when_not_found`
11. `MusicControllerTest::test_list_albums_returns_json`
12. `MusicControllerTest::test_get_album_returns_json_with_tracks`
13. `MusicControllerTest::test_list_tracks_returns_json`
14. `MusicControllerTest::test_now_playing_returns_current_session`

**Coverage target:** `AudioScanner` ≥ 85 %, `MusicLibraryManager` ≥ 85 %.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **"New library type (music/photos/books/audiobooks)"** →
  `docs/libraries/music.md` (new): supported formats, tag field mapping,
  naming conventions, scan/rescan behavior.
- **"Public HTTP API"** → API routes added; docs reference updated in
  Phase N.21 (auto-generated from PHP attrs).
- **"Anything"** → `docs/libraries/music.md` covers all new public
  classes with `@since 0.14.0`.
- **"User-visible behavior change"** → CHANGELOG entry (music library
  browsing + playback added).

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] `AudioScanner::harvestTags()` returns a structured array with all
      documented fields; returns `[]` on parse failure without throwing.
- [ ] `AudioScanner::scanMusicLibrary()` yields media item arrays.
- [ ] `MusicLibraryManager::rescanLibrary()` orchestrates full pipeline.
- [ ] `MusicLibraryManager::upsertTrack()` stores audio metadata in DB.
- [ ] `MusicController` serves `/music/artists`, `/music/albums`,
      `/music/tracks`, `/music/now-playing` with JSON responses.
- [ ] `GET /music/artists/{mbid}` returns artist detail with albums.
- [ ] `GET /music/albums/{mbid}` returns album detail with track listing.
- [ ] `MusicLibraryType` is registered as library type `'music'`.
- [ ] `LibraryManager` routes music-type libraries to
      `MusicLibraryManager`.
- [ ] Smarty templates exist for artists, albums, tracks, player.
- [ ] `./vendor/bin/phpunit` — green; ≥ 14 new tests.
- [ ] Coverage of `AudioScanner` ≥ 85 %, `MusicLibraryManager` ≥ 85 %.
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — zero new errors.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] `docs/libraries/music.md` written.
- [ ] CHANGELOG entry added.
- [ ] Git ritual §8 executed; postcondition checks PASS.

## 8. Git ritual (copy of master plan §11.4)

```bash
# ─── 0. PRECONDITION ───
cd /home/sites/phlex
git status --short
git branch --show-current
git pull --ff-only origin master

# ─── 1. Branch ───
git checkout -b g.2-music-player

# ─── 2. Do the work ───

# ─── 3. Verify ───
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'AudioScanner|MusicLibraryManager|MusicController'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync ───
git add -A

# ─── 5. Commit ───
git commit -m "Step G.2: music player route + ID3/MP4 tag harvest"

# ─── 6. CRITICAL ───
unset GITHUB_TOKEN

# ─── 7. PR + merge ───
gh pr create \
  --title "Step G.2: music player route + ID3/MP4 tag harvest" \
  --body  "Adds AudioScanner (FLAC/MP3/M4A/OGG tag harvest), MusicLibraryManager, MusicController (/music/* routes), Smarty music views, music.css, music-player.js. Part of Phase G (Step G.2 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch

# ─── 8. Return to master ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION ───
git status --short
git branch --show-current
git log --oneline -1
git branch --list 'g.2-*'
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs `g.2-music-player-review.md`.

Non-obvious points:
- The tag parser is pure-PHP (no ffmpeg/ffprobe dependency) for maximum
  portability; it reads only the tag frames at file end/head.
- `scanMusicLibrary()` is a Generator to avoid loading 10 000 tracks into
  memory on a single rescan.
- The `getID3` library decision (composer vs. inline) must be documented
  in the commit message and in the approach section above.
