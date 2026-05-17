# Skip Button Protocol

## Overview

The skip button protocol defines how the server communicates intro/outro skip boundaries to clients. The server provides start/end timestamps; the client decides when to show the button and what action to take when clicked (typically seek to `end` position).

## Protocol Design Principles

1. **Server-controlled data, client-controlled behavior**: The server provides marker boundaries; clients decide when to render UI and what to do on click.
2. **Position-aware filtering**: `getSkipSpec(id, position_ticks)` lets the server filter which buttons are "currently relevant" at the viewer's exact playback position. This is useful for live streams where a viewer may have started mid-episode.
3. **Graceful degradation**: If no markers are detected, all fields are `null` and clients should hide skip buttons entirely.

## JSON Shape

The `markers` object appears inside the `playback_info` response from `GET /api/v1/media/{id}/playback`:

```json
{
  "playback_info": {
    "id": "abc123",
    "name": "Episode Title",
    "type": "episode",
    "media_sources": [...],
    "markers": {
      "skip_intro_start": 10,
      "skip_intro_end": 90,
      "skip_outro_start": 2340,
      "skip_outro_end": 2520
    }
  }
}
```

### Field Descriptions

| Field | Type | Description |
|-------|------|-------------|
| `skip_intro_start` | `int\|null` | Intro segment start time in seconds from beginning. `null` if no intro detected. |
| `skip_intro_end` | `int\|null` | Intro segment end time in seconds from beginning. `null` if no intro detected. |
| `skip_outro_start` | `int\|null` | Outro segment start time in seconds from beginning. `null` if no outro detected. |
| `skip_outro_end` | `int\|null` | Outro segment end time in seconds from beginning. `null` if no outro detected. |

## Client Behavior

### Showing/Hiding Buttons

- **Intro button**: Show when current playback position is between `skip_intro_start` and `skip_intro_end` (inclusive).
- **Outro button**: Show when current playback position is between `skip_outro_start` and `skip_outro_end` (inclusive).
- If a field is `null`, do not show the corresponding button.

### Button Actions

- **Intro skip button**: When clicked, seek playback to `skip_intro_end`.
- **Outro skip button**: When clicked, seek playback to `skip_outro_end`.

### Position Tracking

- Clients should update button visibility as playback position changes.
- Use the `position_ticks` value (if available) to call `getSkipSpec(id, position_ticks)` to get position-filtered markers.

## Server Implementation

### Classes

- `SkipButtonSpec` (`src/Media/Markers/SkipButtonSpec.php`) — Immutable value object with `toArray()` serialization and `fromMarkerSet()` factory.
- `PlaybackMarkerService` (`src/Media/Markers/PlaybackMarkerService.php`) — Provides `getFullSpec()` and `getSkipSpec(id, position_ticks)`.

### Endpoint

- `GET /api/v1/media/{id}/playback` — Returns `playback_info` object with `markers` key.

## Version

Introduced in version 0.12.0 (Phase F, Step F.4).
