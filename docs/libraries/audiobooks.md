# Audiobooks Library

**Phase:** G (Music / Photos / Books / Audiobooks)
**Step:** G.6
**Since:** 0.18.0

Audiobook library support provides chapter-aware playback with per-user progress tracking, similar to Plex's audiobook agent.

## Supported Formats

| Format | Extension | Chapter Support | Notes |
|--------|----------|----------------|-------|
| M4B (AAC) | `.m4b` | ✅ MP4 `chpl` atom | Primary audiobook format |
| M4A (AAC) | `.m4a` | ✅ MP4 `chpl` atom | Often used for audiobooks |
| MP3 | `.mp3` | ⚠️ ID3v2 CMT2/CHAP | Basic support |

## Chapter Extraction

### M4B/M4A (MP4)

Chapters are extracted from the MP4 `chpl` (chapter list) atom, which is a binary structure containing:
- Chapter title
- Start time (milliseconds)
- End time (milliseconds)
- Implicit duration (end - start)

The parser uses pure PHP binary string unpacking - no external libraries required.

### MP3 (ID3v2)

MP3 chapter extraction uses ID3v2 CMT2 and CHAP frames. This is a future enhancement; current implementation returns no chapters for MP3 files.

## Progress Tracking

Per-user progress is stored in the `audiobook_progress` table with:
- `user_id` + `audiobook_id` as composite primary key
- `position_ms`: Current position within the chapter (milliseconds)
- `current_chapter_index`: 0-based index of current chapter
- `completed_chapters`: JSON array of completed chapter positions
- `percent_complete`: Overall completion percentage
- `last_played_at`: Unix timestamp

Progress is saved every 10 seconds via `POST /audiobooks/{id}/progress`.

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/audiobooks` | List all audiobooks |
| GET | `/audiobooks/{id}` | Get audiobook with chapters |
| GET | `/audiobooks/{id}/chapters` | Get chapter list |
| GET | `/audiobooks/{id}/progress` | Get user's progress |
| POST | `/audiobooks/{id}/progress` | Save progress |
| GET | `/audiobooks/{id}/read` | HTML player page |
| GET | `/audiobooks/{id}/stream?chapter=N&offset=MS` | Stream with chapter resume |

## Naming Conventions for Multi-file Series

Audiobook series with multiple files follow the same naming convention as TV shows:

```
The Wheel of Time - Book 01 - The Eye of the World.m4b
The Wheel of Time - Book 02 - The Great Hunt.m4b
```

The scanner does not currently support multi-file series detection (G.6 handles single-file M4B).

## Player Controls

The audiobook player supports:

| Control | Action |
|---------|--------|
| Space | Play/Pause |
| ← | Skip back 10 seconds |
| → | Skip forward 10 seconds |
| Shift+← | Previous chapter |
| Shift+→ | Next chapter |
| P | Previous chapter |
| N | Next chapter |

## Web Portal

Access audiobooks at:
- Library view: `/audiobooks`
- Detail view: `/audiobooks/{id}`
- Player: `/audiobooks/{id}/read`

## Configuration

Audiobook library type is registered via `AudiobookLibraryType` which implements `LibraryTypeInterface`.

```php
// In config/plugins.php or library type registry
use Phlix\Media\Music\AudiobookLibraryType;

$registry->register(new AudiobookLibraryType());
```

## Database Schema

```sql
CREATE TABLE audiobook_progress (
    user_id       CHAR(36) NOT NULL,
    audiobook_id  CHAR(36) NOT NULL,
    position_ms   INT UNSIGNED NOT NULL DEFAULT 0,
    current_chapter_index SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    completed_chapters JSON NOT NULL DEFAULT '[]',
    percent_complete DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    last_played_at INT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, audiobook_id),
    INDEX (audiobook_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```
