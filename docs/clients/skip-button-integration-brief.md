# Skip Button Integration Brief — Phase M

**For client repo developers** | Server Phase F.4 | Version 0.12.0

---

## What Is This?

The server now provides skip button boundaries in the playback info response. Your client (phlex-mobile-client, phlex-roku-client, phlex-tizen-client, phlex-windows-client) should use these to render "Skip Intro" and "Skip Outro" buttons.

## Quick Start

### 1. Fetch Playback Info

```
GET /api/v1/media/{media_item_id}/playback
```

Response includes a `markers` object:

```json
{
  "playback_info": {
    "id": "abc123",
    "name": "S1E01 - The Beginning",
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

### 2. Render Buttons

| Condition | Action |
|-----------|--------|
| `skip_intro_start` is not null AND position is between start/end | Show "Skip Intro" button |
| `skip_outro_start` is not null AND position is between start/end | Show "Skip Outro" button |
| Field is `null` | Do not show corresponding button |

### 3. Handle Button Press

- **Skip Intro** → Seek to `skip_intro_end` position
- **Skip Outro** → Seek to `skip_outro_end` position

## Full Specification

See [docs/reference/skip-button-protocol.md](../reference/skip-button-protocol.md) for the complete protocol documentation.

## Timeline

- **Phase F.4** (current): Server implements skip button protocol
- **Phase M** (upcoming): Client teams implement UI using this protocol

## Questions?

Reach out to the server team or refer to the full protocol spec.
