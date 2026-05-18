# Theme Media (H.6)

**Phase:** H (Smart Features)
**Step:** H.6
**Since:** 0.14.0

## Overview

Theme media allows automatic playback of theme music (`theme.mp3`) and theme video (`backdrop.mp4`) when users browse a library in the WebPortal. Theme media files are placed alongside the library folder at the root level.

## File Naming

### Library-Level Theme Media

Place theme files at the root of your library path:

```
/Movies/
  theme.mp3          ← Library-level audio theme
  backdrop.mp4       ← Library-level video backdrop
  Avatar (2009)/
    Avatar (2009).mkv
```

### Series-Level Theme Media (TV Shows)

For TV shows, theme files can be placed at the series level to apply to all episodes:

```
/TV Shows/
  The Crown/
    theme.mp3        ← Series-level theme (applies to all episodes)
    backdrop.mp4     ← Series-level backdrop
    Season 1/
      The Crown S01E01.mkv
```

### Supported File Formats

| Type | Extensions | Description |
|------|------------|-------------|
| Audio | `theme.mp3`, `theme.ogg`, `theme.mp4` | Theme music (played with audio element) |
| Video | `backdrop.mp4`, `backdrop.webm` | Theme video (played muted as background, ≥1080px viewports only) |

## How Scanning Works

1. **Library Scan:** When a library is scanned (via `LibraryManager::scanLibrary()`), theme media is automatically discovered by `ThemeMediaFinder`.
2. **Cache:** Discovered theme media is cached in the `theme_media` table to avoid re-scanning on every request.
3. **Manual Rescan:** Users can trigger a rescan via `POST /api/v1/libraries/{id}/theme-media/scan`.

## API Endpoints

### Get Theme Media

```
GET /api/v1/libraries/{id}/theme-media
```

Returns theme media metadata for a library:

```json
{
  "library_id": "abc-123",
  "audio": {
    "path": "/mnt/media/Movies/theme.mp3",
    "url": "/stream/theme-media/audio?path=...",
    "duration": 180,
    "format": "mp3"
  },
  "video": {
    "path": "/mnt/media/Movies/backdrop.mp4",
    "url": "/stream/theme-media/video?path=...",
    "duration": 60,
    "width": 1920,
    "height": 1080,
    "format": "mp4"
  },
  "has_theme": true,
  "scanned_at": "2026-01-15T10:30:00+00:00"
}
```

### Trigger Rescan

```
POST /api/v1/libraries/{id}/theme-media/scan
```

Triggers a filesystem rescan and updates the cache.

### Delete Theme Media Cache

```
DELETE /api/v1/libraries/{id}/theme-media
```

Removes the cached theme media entry (files remain on disk).

### Stream Audio

```
GET /stream/theme-media/{libraryId}/audio
```

Streams the theme audio file. Supports range requests for seeking.

### Stream Video

```
GET /stream/theme-media/{libraryId}/video
```

Streams the theme video file. Supports range requests for seeking.

## WebPortal Integration

### Template Variables

When rendering the library page, the following variable is available:

| Variable | Type | Description |
|----------|------|-------------|
| `$themeMedia` | `ThemeMedia\|null` | Cached theme media for the library |

### Example Template Usage

```smarty
{extends file="layouts/main.tpl"}
{block name="content"}
  {include file="partials/library-header.tpl"}

  <div class="library-items">
    {foreach from=$items item=item}
      <div class="media-card">{$item.name}</div>
    {/foreach}
  </div>
{/block}
```

### JavaScript (theme-media.js)

The `theme-media.js` script handles:

1. **Autoplay with Policy Handling:** Uses `play().catch()` to handle browser autoplay blocking. Shows an overlay prompting user interaction to enable theme music.

2. **Audio Playback:** Plays `theme.mp3` as `<audio autoplay loop>` on first user interaction with the library page.

3. **Video Backdrop:** If `backdrop.mp4` exists and viewport ≥ 1080px, plays it muted as a fixed background behind the library header.

4. **Toggle Button:** Provides a toggle button to enable/disable theme music manually.

## Database Schema

```sql
CREATE TABLE theme_media (
    library_id CHAR(36) NOT NULL PRIMARY KEY,
    audio_path VARCHAR(1024) NULL,
    audio_url VARCHAR(512) NULL,
    audio_duration INT NULL,
    audio_format VARCHAR(8) NULL,
    video_path VARCHAR(1024) NULL,
    video_url VARCHAR(512) NULL,
    video_duration INT NULL,
    video_width INT NULL,
    video_height INT NULL,
    video_format VARCHAR(8) NULL,
    scanned_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## Implementation Details

### ThemeMediaFinder

Mirrors the `TrailerFinder` pattern from H.5:
- Scans library root for `theme.mp3`, `theme.ogg`, `theme.mp4` (audio priority: mp3 > ogg > mp4)
- Scans for `backdrop.mp4`, `backdrop.webm` (video priority: mp4 > webm)
- Uses `FFprobe` to extract duration and dimensions (if available)

### ThemeMediaRepository

Simple cache operations:
- `upsert()` — Insert or update on duplicate key
- `findByLibraryId()` — Retrieve cached theme media
- `deleteByLibraryId()` — Remove cached entry

### Stream Controller

- Uses `readfile()` for direct file serving (no transcoding)
- Returns correct `Content-Type` headers based on format
- Supports HTTP Range requests for audio/video seeking
- No HLS segmentation needed (theme clips are short < 60s)

## Autoplay Policy Handling

Browsers block autoplay of audio. The `theme-media.js` handles this:

```javascript
audio.play().then(() => {
  hideAutoplayOverlay();
}).catch(() => {
  // Autoplay blocked - show overlay
  showAutoplayOverlay();
  setupClickToEnable(audio, player);
});
```

The overlay displays "Tap to enable theme music" and is removed on first user interaction.

## Backdrop Video Constraints

The backdrop video:
- Plays **muted** (no audio)
- Only plays on **viewports ≥ 1080px** wide
- Is positioned fixed behind the library header
- Loops continuously
- Has reduced opacity (0.6) to not distract from library content
