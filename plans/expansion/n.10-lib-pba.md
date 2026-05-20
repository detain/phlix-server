# Step N.10 — Library Setup: Photos, Books, Audiobooks

**Phase:** N (End-User Documentation)
**Step:** N.10
**Depends on:** G.6 (audiobooks — already merged in Wave 4), G.5 (books), G.4 (photos)
**Review:** No (doc-only step)
**Target repo:** phlex-server (local: /home/sites/phlex/)

## 1. Goal

Write the photos, books, and audiobooks library setup guides at `docs/libraries/photos.md`, `docs/libraries/books.md`, and `docs/libraries/audiobooks.md`, using the §7 one-screen layout (TL;DR → shell blocks → what-can-go-wrong → next-steps).

## 2. Context (what already exists)

- `docs/libraries/` directory and `docs/libraries/README.md` index exist (created by prior docs steps)
- Branch `n.10-lib-pba` will be cut from `master` after all G.4/G.5/G.6 merges are stable
- Photos (G.4), books (G.5), and audiobooks (G.6) feature implementations are already merged
- The §7 docs tree layout specifies all three doc pages
- Reference format: `n.7-lib-movies.md` is the most recent library setup doc and uses the same §7 layout

## 3. Approach: three files vs. one combined guide

**Decision:** Create three separate files — one per library type.

| Rationale | Source |
|-----------|--------|
| Each library type has completely different format support, quirks, and failure modes;读者的上下文切换成本最低 | `ref:n.10-lib-pba` |
| Photos has a slideshow feature with no equivalent in books/audiobooks; OPDS feed is books-specific; chapter navigation is audiobook-specific | `ref:n.10-lib-pba` |
| Separate files allow parallel doc updates (e.g., adding a new photo format) without merge conflicts | `ref:n.10-lib-pba` |
| `n.7-lib-movies.md` established the precedent for one-library-per-file in this phase | `ref:n.7-lib-movies` |

## 4. Scope — files to create

### New files

- `docs/libraries/photos.md` — Photos library setup guide
- `docs/libraries/books.md` — Books library setup guide
- `docs/libraries/audiobooks.md` — Audiobooks library setup guide

### Modify

- `docs/libraries/README.md` — add photos, books, audiobooks to the library index (if not already added by implementation steps)

## 5. Per-file content outline

### 5a. `docs/libraries/photos.md`

#### TL;DR
One paragraph: what a photos library is, what Phlex extracts from photos, how slideshow works.

#### Supported file formats
Table: Format | Extension | Notes
JPEG, PNG, GIF, WebP, TIFF, RAW (CR2, NEF, ARW)
Note: RAW formats depend on camera vendor SDK availability on the server.

#### EXIF metadata extraction
- Camera model, lens, ISO, exposure — displayed in photo detail view
- GPS coordinates — displayed on a mini-map if present
- Date taken — used to sort and group photos

#### Folder organization
- Year/Year-Month folder structure: `2024/2024-05-vacation/IMG_0001.jpg`
- Scanner uses folder names as album names when no metadata album tag is present

#### Slideshow settings (UI)
- Interval: 3 s / 5 s / 10 s / 30 s
- Transition: fade / slide / zoom / none
- Shuffle mode toggle

#### What can go wrong

**EXIF GPS not stripped (privacy)**
- Symptom: Sharing a photo library or generating a shared link exposes the exact GPS coordinates where photos were taken
- Cause: GPS is embedded in EXIF by most smartphones; Phlex preserves all EXIF by default
- Fix: Run `exiftool -gps:all= *.jpg` on the library folder before adding to Phlex; or enable "Strip GPS on import" in library settings

**Unsupported RAW format**
- Symptom: RAW files from newer camera models appear as black thumbnails or fail to generate a thumbnail
- Cause: Camera vendor (e.g., Canon CR3, Nikon NEF) may require a newer `libraw` or proprietary decoder
- Fix: Convert to DNG (Adobe Digital Negative) format, which has universal support; or convert to high-quality JPEG for the library

**Very large files causing OOM**
- Symptom: Scanner worker crashes with out-of-memory when processing a 200 MB+ TIFF or stacked RAW burst
- Cause: Whole-file loading into memory for thumbnail generation
- Fix: Pre-downscale extremely large files before adding to the library; increase `memory_limit` in PHP-FPM for the scanner process

#### Next steps
- [Books library setup](docs/libraries/books.md) — EPUB, PDF, OPDS feed
- [Audiobooks library setup](docs/libraries/audiobooks.md) — M4B chapters, resume playback
- [Live TV library setup](docs/libraries/tv.md) — broadcast TV and DVR

---

### 5b. `docs/libraries/books.md`

#### TL;DR
One paragraph: what a books library is, OPDS feed URL, how to access from mobile readers.

#### Supported file formats
Table: Format | Extension | Notes
EPUB, PDF, CBZ (comic book archive)

#### OPDS feed — how to access from mobile
- Feed URL: `https://your-phlex.example.com/opds/v1.2`
- How to add in third-party OPDS clients:
  - **Komga**: Settings → OPDS feeds → Add → paste URL → authenticate with Phlex username + password
  - **Uboiquty**: Library → Add → OPDS feed → paste URL
  - **Moon+ Reader**: Menu → Add catalog → OPDS → paste URL
- OPDS catalog supports browsing by library, searching by title/author

#### Naming conventions
- Flat-file: `Author - Title.ext` or `Title.ext`
- Scanner extracts author and title from filename when no embedded metadata is present
- CBZ folders: `Series Name Vol 01.cbz` works; scanner uses folder name as series name

#### Metadata extraction
- EPUB: reads `content.opf` for title, author, ISBN, language, cover image
- PDF: `exif_read_data()` for author, title, subject; page count from PDF trailer
- CBZ: first JPEG found becomes cover; `ComicInfo.xml` parsed if present (title, volume, writers)

#### What can go wrong

**DRM-protected files not supported**
- Symptom: Book downloads successfully but displays as blank pages or "DRM Error" in the reader
- Cause: Adobe ADEPT, Amazon Kindle, or Readium LCP DRM encryption present in EPUB/PDF
- Fix: Remove DRM using Calibre's plugin or `DeDRM` tools before adding files to the library; legal requirement: you must own the original ebook

**PDF without metadata**
- Symptom: PDF appears in library as "Unknown Title" with no author or cover
- Cause: PDF was scanned as images (no OCRed text layer) and lacks EXIF/XMP metadata
- Fix: Embed metadata using `exiftool -Title="Book Title" -Author="Author Name" file.pdf`; or add a cover.jpg alongside the PDF with the same base name

**Corrupt EPUB**
- Symptom: EPUB downloads but client shows "Parse Error" or blank content
- Cause: Malformed `content.opf`, invalid ` mimetype` entry, or ZIP structure issues
- Fix: Re-pack the EPUB: `zip -X -T book.epub mimetype content.opf ...`; or open and re-save in Calibre which auto-repairs common EPUB issues

#### Next steps
- [Audiobooks library setup](docs/libraries/audiobooks.md) — M4B chapters, resume playback
- [Photos library setup](docs/libraries/photos.md) — EXIF, slideshow, GPS privacy
- [DLNA / Play To](docs/dlna/play-to.md) — stream books to compatible e-ink readers

---

### 5c. `docs/libraries/audiobooks.md`

#### TL;DR
One paragraph: what an audiobooks library is, M4B chapter awareness, resume from last position.

#### Supported file formats
Table: Format | Extension | Notes
M4B (chapter-aware), MP3 (legacy), FLAC (lossless)

#### Chapter navigation (M4B)
- Chapters are detected from the M4B atom structure (`chpl` atom)
- Chapter titles are displayed in the player scrubber timeline as clickable markers
- Clicking a chapter marker skips to that chapter's start time
- MP3 legacy files: chapter markers are not available; progress shown as percentage only

#### Progress tracking and resume
- Phlex tracks playback position in 5-second heartbeats
- When resuming: if position is at 90 % or beyond (i.e., within the last 10 %), Phlex marks the item as "Completed" and does not offer resume — the user must explicitly mark as "In Progress" to continue
- Progress syncs across devices via the user account

#### Multi-file series handling
- Scanner detects series by matching `Book Title - Part 1.m4b`, `Book Title - Part 2.m4b`
- Files are grouped as a single audiobook entry with multiple parts
- Part titles are inferred from filename suffix after ` - Part ` or ` - Disc `
- Playback proceeds automatically from Part 1 into Part 2 when Part 1 ends

#### Cover art
- M4B: cover embedded in `covr` atom (ID3v2 / mp4 cover); extracted automatically
- MP3: `cover.jpg` or `folder.jpg` in same directory as fallback
- FLAC: `METADATA_BLOCK_PICTURE` Vorbis comment picture block
- If no cover found: a generated placeholder is shown

#### What can go wrong

**M4B without chapter markers**
- Symptom: Audiobook plays fine but scrubber shows no chapter markers; entire file is one long track
- Cause: M4B was encoded without chapter data (e.g., converted from a single MP3 without re-importing chapter markers)
- Fix: Re-encode with a tool that preserves chapters (e.g., Apple Books, Audible, or `ffmpeg -i input.mp3 -c:a copy -movflags +use_metadata_flags output.m4b`); or use a chapter editor to add markers

**Progress not saved (90 % completion gating)**
- Symptom: After listening for a while, closing the player and reopening shows no progress and marks the book as "Completed"
- Cause: Playback reached or exceeded 90 % of total duration; Phlex's completion threshold auto-marks as done
- Fix: If still listening past 90 %, manually set the item back to "In Progress" in the library UI; progress up to that point was saved at the last 5-second heartbeat

**Missing cover art**
- Symptom: Audiobook appears in the library with a blank/default placeholder cover
- Cause: No embedded cover in the M4B/MP3/FLAC file, and no `cover.jpg` alongside the file
- Fix: Embed cover using FFmpeg: `ffmpeg -i input.m4b -i cover.jpg -c:a copy -c:v copy -attach cover.jpg -metadata:s:v title="Cover" output.m4b`; or place a `cover.jpg` (max 1 MB, max 2500×2500 px) in the same folder as the audiobook file

#### Next steps
- [Books library setup](docs/libraries/books.md) — EPUB, PDF, OPDS feed
- [Photos library setup](docs/libraries/photos.md) — EXIF, slideshow, GPS privacy
- [Music library setup](docs/libraries/music.md) — album art, FLAC/MP3 tagging

## 6. Shell commands for TL;DR sections

Each TL;DR section ends with a quick-command shell block (one-liner copy-paste for the impatient user):

**Photos:**
```bash
# Scan a photos library after dropping files in place
# The scanner picks up Year/Year-Month folder structure automatically
curl -X POST "https://your-phlex.example.com/api/v1/libraries/{library_id}/scan" \
  -H "Authorization: Bearer $PHLEX_TOKEN"
```

**Books:**
```bash
# Verify OPDS feed is accessible (returns XML)
curl -H "Authorization: Bearer $PHLEX_TOKEN" \
  "https://your-phlex.example.com/opds/v1.2" | head -20
```

**Audiobooks:**
```bash
# Resume an audiobook from last position
curl -X POST "https://your-phlex.example.com/api/v1.2/playback/resume" \
  -H "Authorization: Bearer $PHLEX_TOKEN" \
  -d '{"media_id": "{media_id}"}'
```

## 7. Git ritual

```bash
# ─── 0. PRECONDITION ───
cd /home/sites/phlex
git status --short
git branch --show-current
git pull --ff-only origin master

# ─── 1. Branch ───
git checkout -b n.10-lib-pba

# ─── 2. Do the work ───
# Create docs/libraries/photos.md
# Create docs/libraries/books.md
# Create docs/libraries/audiobooks.md
# Update docs/libraries/README.md index if needed

# ─── 3. Verify ───
# Verify all three files exist and have TL;DR, format table,
# what-can-go-wrong (3 failures each), and next-steps sections
# Verify shell blocks are valid curl commands with correct endpoints

# ─── 4. Caliber sync ───
git add -A

# ─── 5. Commit ───
git commit -m "Step N.10: Library setup docs — photos, books, audiobooks"

# ─── 6. CRITICAL ───
unset GITHUB_TOKEN

# ─── 7. PR + merge ───
gh pr create \
  --title "Step N.10: Library setup docs for photos, books, audiobooks" \
  --body  "Adds docs/libraries/photos.md, docs/libraries/books.md, docs/libraries/audiobooks.md following the §7 one-screen layout (TL;DR, format table, what-can-go-wrong, next-steps). Part of Phase N (Step N.10 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch

# ─── 8. Return to master ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION ───
git status --short
git branch --show-current
git log --oneline -1
git branch -d n.10-lib-pba
```
