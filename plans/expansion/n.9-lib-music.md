# Step N.9 — Music library setup guide

**Phase:** N (End-User Documentation)
**Step:** N.9
**Depends on:** G.2 (music player — already merged in Wave 4)
**Review:** No (doc-only step)
**Target repo:** detain/phlex-server (local: `/home/sites/phlex/`)
**One-liner:** Library setup for music (tagging tools, classical, compilation albums)

---

## Goal

Write the user-facing music library setup guide at `docs/libraries/music.md`, replacing the existing technical reference with an end-user guide that covers tagging workflow, supported formats, classical music conventions, compilation albums, and troubleshooting.

---

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| G.2 shipped `AudioScanner` + `MusicLibraryManager` + music API routes | G.2 implementation is the stable foundation for this guide | `ref:g.2-music-player` (merged Wave 4) |
| Replace existing `docs/libraries/music.md` technical doc, not create new file | The existing page needs to become a user guide per §7 layout | N.0 docs platform decision |
| Mp3tag (Windows/macOS/Wine) and MusicBrainz Picard (cross-platform) as the two recommended tools | Both are free, widely used, and support the tag fields Phlex needs | G.2 §2 context + general music-library best practices |
| COMPILATION=1 + album artist "Various Artists" for compilation albums | This is the de facto standard recognized by all media servers including Phlex | G.2 tag field mapping context |
| ALBUM ARTIST for classical music composer/conductor distinction | Phlex stores `album_artist`; for classical, composer and conductor are placed in ALBUM ARTIST per convention | G.2 §2 + MusicBrainz conventions |
| Folder structure is fallback, tags are primary | AudioScanner harvests tags first; falls back to path-based naming when tags are absent | G.2 AudioScanner design decision |
| Artist normalization strips leading "The " for sorting | Scanners normalize artist names; "The Beatles" sorts as "Beatles" | G.2 naming conventions context |
| Three failure scenarios: missing tags → Unknown Artist/Album, missing album art embedding, compilation not detected, duplicate artists from capitalization | These are the four most common end-user pain points with music libraries | G.2 implementation + music library ops experience |

---

## Phase 1: Draft music library setup guide [IN PROGRESS]

- [ ] **1.1** Read existing `docs/libraries/music.md` in full to avoid duplicating content already covered
- [ ] **1.2** Read G.2 plan (`plans/expansion/g.2-music-player.md`) for accurate technical details on what the scanner supports
- [ ] **1.3** Draft `docs/libraries/music.md` — new user-facing guide (see §2 Content Outline below)
- [ ] **1.4** Self-review against §7 layout requirements: TL;DR, shell blocks, what-can-go-wrong (3 failures), next-steps

---

## Phase 2: Verification [PENDING]

- [ ] **2.1** Confirm all four §7 required sections are present (TL;DR, shell blocks, what-can-go-wrong, next-steps)
- [ ] **2.2** Confirm all tagging-tool instructions (Mp3tag, Picard) are accurate for current versions
- [ ] **2.3** Confirm supported format list matches G.2 implementation (`mp3`, `flac`, `m4a`, `ogg`, `wav`, `aiff`)
- [ ] **2.4** Confirm "what can go wrong" covers exactly 3 distinct failures with shell-friendly diagnostic commands
- [ ] **2.5** Run `./vendor/bin/phpcs --standard=PSR12 src/` — zero errors (no PHP changes, but verify docs don't break build)
- [ ] **2.6** Proofread for clarity, accuracy, and tone suitable for end users (not developers)

---

## Phase 3: Commit [PENDING]

- [ ] **3.1** Branch: `git checkout -b n.9-lib-music`
- [ ] **3.2** Commit: `git add docs/libraries/music.md && git commit -m "Step N.9: music library setup guide (end-user docs)"`
- [ ] **3.3** PR: `gh pr create --title "Step N.9: music library setup guide" --body "Writes docs/libraries/music.md as an end-user setup guide covering tagging tools (Mp3tag, Picard), essential tags, classical music conventions, compilation albums, and 3 common failure scenarios. Part of Phase N (Step N.9 of PHLEX_EXPANSION_PLAN.md)."`
- [ ] **3.4** Merge: `gh pr merge --squash --delete-branch`
- [ ] **3.5** Return to master: `git checkout master && git pull --ff-only origin master`

---

## §2 Content Outline for `docs/libraries/music.md`

### TL;DR
One-paragraph summary: what Phlex does with music, what this guide covers (getting tags right so your library looks correct), and the 30-second version: install a tagger → tag your files → add library to Phlex → rescan.

### 1. Before You Begin
- Minimum requirements: audio files in `mp3`, `flac`, `m4a`, `ogg`, `wav`, or `aiff`
- Recommend using a dedicated tagging tool rather than filename-based metadata
- Brief note on why tags matter more than filenames for Phlex

### 2. Tagging Tools

#### Mp3tag (Windows/macOS/Wine)
```bash
# Install Mp3tag (https://www.mp3tag.de/en/)
# OR on Linux via Wine:
sudo apt install wine
wine mp3tag-setup.exe
```
Cover basic workflow:
- Drag folder into Mp3tag
- Select all tracks → set Title, Artist, Album, Year, Track, Disc, Genre
- Save (F5 or toolbar button)
- Export as front cover: right-click album art → "Export cover" → save as `cover.jpg` next to files

#### MusicBrainz Picard (cross-platform, recommended)
```bash
# Linux
sudo apt install picard

# macOS / Windows: download from https://picard.musicbrainz.org/
```
Cover:
- Preferences → Filers → organize files: `{artist}/{album}/{track} - {title}`
- Scan folder or drag files into Picard
- Right-click → "Lookup" (fetches from MusicBrainz via acoustic fingerprint)
- Right-click → "Save" (writes tags to files)
- Picard auto-embeds album art from MusicBrainz

### 3. Essential Tags Reference

Shell-friendly reference table:

| Tag | What to put in it | Example |
|-----|-----------------|---------|
| `title` | Track name | "Bohemian Rhapsody" |
| `artist` | Performer or band | "Queen" |
| `album` | Album name | "A Night at the Opera" |
| `album_artist` | Artist of the album as a whole | "Queen" (same as artist for solo albums) |
| `year` | Release year (4 digits) | "1975" |
| `track` | Track number | "5" or "05" |
| `disc` | Disc number for multi-disc albums | "1" or "1/2" |
| `genre` | Genre (free text or MusicBrainz) | "Rock" |
| `album art` | Embedded cover image (min 500×500 px) | cover.jpg embedded in all tracks |

### 4. Classical Music

For classical albums, use `album_artist` to carry the composer when different from the performing artist:

```bash
# Example folder structure for a classical album:
/Classical/Beethoven, Ludwig van/Symphony No. 9 (1988)/
# In Picard or Mp3tag:
#   artist       = "Berliner Philharmoniker"
#   album_artist = "Beethoven, Ludwig van"  (composer drives alphabetical sort)
#   conductor    = "Herbert von Karajan"  (custom tag, optional)
#   orchestra    = "Berliner Philharmoniker" (custom tag, optional)
```

Classical sorting: set `album_artist` to the composer's name so Beethoven entries sort under "B", not "B" (for the orchestra).

### 5. Compilation Albums (Various Artists)

For albums with tracks from multiple artists (soundtracks, "Now That's What I Call Music", etc.):

```bash
# In Mp3tag or Picard:
#   album_artist = "Various Artists"
#   COMPILATION  = 1  (ID3v2 frame TYER / Vorbis comment COMPILATION)
```

Without `COMPILATION=1`, Phlex will list each track under its individual artist rather than grouping under the album.

### 6. Folder Structure vs Tags

Phlex reads **tags first**. Folder structure is a fallback when tags are missing or ambiguous:

```bash
# Preferred (tag-based — tags are source of truth):
/music/Queen/A Night at the Opera/05 - Bohemian Rhapsody.mp3

# Acceptable fallback (path-based metadata when tags are absent):
/music/Queen - A Night at the Opera/05 - Bohemian Rhapsody.mp3
```

Set `album_artist` explicitly rather than relying on folder names to avoid "Unknown Artist" in Phlex.

### 7. Adding Your Library to Phlex

```bash
# Via the web portal:
# 1. Go to Libraries → Add Library → Type: Music
# 2. Point to your music root folder
# 3. Click "Scan" — Phlex will harvest tags and show artists/albums

# Via CLI rescan:
php scripts/run-migrations.php   # already done on upgrade
# Rescan is automatic; manual trigger via web portal library settings
```

### 8. What Can Go Wrong

#### Failure 1: "Unknown Artist" / "Unknown Album" in Phlex

**Symptom:** Library shows "Unknown Artist" for every track, or albums appear with no metadata.

**Diagnosis:**
```bash
# Check what Phlex sees in the database (or inspect a file's tags):
# With Mp3tag: open the file, hover over each tag field — if blank, the tag is missing
# With ffprobe (for a quick tag peek):
ffprobe -v quiet -show_format -show_streams "/path/to/track.mp3" 2>&1 | grep -E "tag:|artist|album|title"
```

**Fix:** Open files in Mp3tag or Picard, fill in `artist`, `album`, `title`, then re-scan the library in Phlex.

---

#### Failure 2: Album art not showing in Phlex

**Symptom:** Album lists are correct but no cover art appears in Phlex's UI.

**Diagnosis:**
```bash
# Check if album art is embedded in the file (not just a separate cover.jpg):
# In Mp3tag: look for a red/apple icon next to the track — indicates embedded art
# With ffprobe:
ffprobe -v quiet -show_format "/path/to/track.mp3" 2>&1 | grep -i "cover"

# cover.jpg must be:
#   - Named exactly "cover.jpg" (or front.jpg, album.jpg)
#   - Minimum 500×500 pixels
#   - Embedded in every track file (not just stored in the folder)
```

**Fix:** In Mp3tag: right-click album art → "Export cover" → save as `cover.jpg` in the album folder, then re-import into all tracks via "Actions → Import cover to files". In Picard: covers are embedded automatically on "Save".

---

#### Failure 3: Compilation album tracks spread across multiple artists instead of grouped

**Symptom:** A "Now That's What I Call Music" album shows each track under its own artist in Phlex, making the album hard to find.

**Diagnosis:**
```bash
# Check for COMPILATION flag:
# In Mp3tag: view the file's properties — look for "Compilation" field
# With ffprobe:
ffprobe -v quiet -show_format "/path/to/track.mp3" 2>&1 | grep -i "compilation"
# (ffprobe may not show it; use a tag viewer like taglib or mid3v3)
mid3v3 "/path/to/track.mp3" 2>/dev/null | grep -i COMPILATION
```

**Fix:** In Mp3tag or Picard, set `album_artist = "Various Artists"` and `COMPILATION = 1` on every track in the compilation. Re-scan the library.

---

#### Failure 4 (bonus): Duplicate artists from capitalization differences

**Symptom:** "queen", "Queen", and "QUEEN" appear as three separate artists in Phlex.

**Diagnosis:**
```bash
# Check artist tag capitalization across files:
mid3v3 "/path/track1.mp3" 2>/dev/null | grep "^artist="
mid3v3 "/path/track2.mp3" 2>/dev/null | grep "^artist="
```

**Fix:** Use Picard's "Cluster" feature to group similar tracks, then multi-edit to normalize capitalization. In Mp3tag: select all tracks for an artist → right-click artist field → "Format value" → enter normalized name. Phlex normalizes for display but the source tags should be consistent.

### 9. Next Steps

- [Music player](../users/player.md) — browse and play your music in Phlex
- [Smart playlists](../users/playlists.md) — auto-generated playlists by genre, decade, or listening history
- [Hardware transcoding](../admin/transcoding.md) — transcode FLAC to MP3 for bandwidth-limited clients
- [DLNA / Play To](../users/dlna.md) — stream music to DLNA-enabled speakers and receivers
