# Phlex Media Server API Reference

## Marker Endpoints

### GET /api/v1/media/{id}/markers

Returns all markers (intro, outro, chapters) for a media item.

**Parameters:**
- `id` (path) — Media item ID

**Response 200:**
```json
{
  "intro": {
    "start": 0,
    "end": 90,
    "confidence": 85
  },
  "outro": {
    "start": 2310,
    "end": 2400,
    "confidence": 80
  },
  "chapters": [
    { "start": 0, "end": 90, "title": "Intro" },
    { "start": 90, "end": 300, "title": "Chapter 1" }
  ]
}
```

**Notes:**
- `intro` and `outro` are `null` if no marker is detected
- `chapters` is an empty array if no chapters are defined
- Read from formal marker columns first, falls back to metadata_json candidates

---

### GET /api/v1/media/{id}/markers/intro

Returns the intro marker for a media item.

**Parameters:**
- `id` (path) — Media item ID

**Response 200:**
```json
{
  "start": 0,
  "end": 90,
  "confidence": 85
}
```

**Response 404:** Intro marker not found for this media item

---

### GET /api/v1/media/{id}/markers/outro

Returns the outro marker for a media item.

**Parameters:**
- `id` (path) — Media item ID

**Response 200:**
```json
{
  "start": 2310,
  "end": 2400,
  "confidence": 80
}
```

**Response 404:** Outro marker not found for this media item

---

### GET /api/v1/shows/{id}/markers/bulk

Returns markers for all episodes of a show.

**Parameters:**
- `id` (path) — Show/series media item ID

**Response 200:**
```json
{
  "show_id": "show-123",
  "episodes": [
    {
      "id": "ep-1",
      "name": "Episode 1",
      "markers": {
        "intro": { "start": 0, "end": 90, "confidence": 85 },
        "outro": null,
        "chapters": []
      }
    }
  ]
}
```

**Notes:**
- Episodes are enumerated via `parent_id` relationship
- Introduced in Step F.3 (v0.12.0)

---

## Playback Endpoints

### GET /api/v1/media/{id}/playback

Returns playback information including stream URL and skip button markers.

**Parameters:**
- `id` (path) — Media item ID

**Response 200:**
```json
{
  "playback_info": {
    "id": "abc123",
    "name": "S1E01 - The Beginning",
    "type": "episode",
    "media_sources": [
      {
        "id": "default",
        "container": "mkv",
        "path": "/mnt/media/shows/show1/s01e01.mkv",
        "direct_play": true
      }
    ],
    "markers": {
      "skip_intro_start": 10,
      "skip_intro_end": 90,
      "skip_outro_start": 2340,
      "skip_outro_end": 2520
    }
  }
}
```

**Fields:**
- `markers.skip_intro_start` (int|null) — Intro start in seconds, null if no intro
- `markers.skip_intro_end` (int|null) — Intro end in seconds, null if no intro
- `markers.skip_outro_start` (int|null) — Outro start in seconds, null if no outro
- `markers.skip_outro_end` (int|null) — Outro end in seconds, null if no outro

**Notes:**
- Clients should show "Skip Intro" button when position is between `skip_intro_start` and `skip_intro_end`
- Clients should show "Skip Outro" button when position is between `skip_outro_start` and `skip_outro_end`
- Clicking a skip button should seek to the corresponding `_end` position
- Marker fields are `null` when no marker is detected
- Introduced in Step F.4 (v0.12.0)

---

## Marker Data Model

### IntroMarker / OutroMarker

| Field | Type | Description |
|-------|------|-------------|
| `start` | int | Start time in seconds |
| `end` | int | End time in seconds |
| `confidence` | int | Detection confidence 0-100 |

### ChapterMarker

| Field | Type | Description |
|-------|------|-------------|
| `start` | int | Chapter start time in seconds |
| `end` | int | Chapter end time in seconds |
| `title` | string\|null | Optional chapter title |

---

## Database Storage

Markers are stored in `media_items` table columns:

- `intro_start_seconds` — INT UNSIGNED NULL
- `intro_end_seconds` — INT UNSIGNED NULL
- `outro_start_seconds` — INT UNSIGNED NULL
- `outro_end_seconds` — INT UNSIGNED NULL
- `chapters_json` — JSON NULL

Before formal column population, markers are cached in `metadata_json` as:
- `intro_candidate` — `{ start_seconds, end_seconds, fingerprint, confidence }`
- `outro_candidate` — `{ start_seconds, end_seconds, fingerprint, confidence }`

Use `MarkerService.promoteCandidates()` to migrate candidates to formal columns.

---

## OPDS Feed Endpoints (Book Library)

OPDS 1.2 compliant feeds for third-party OPDS client integration.

### GET /opds/v1.2

Returns the root OPDS catalog feed.

**Response 200:**
```xml
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom" xmlns:opds="http://opds-spec.org/2010/catalog">
  <title>Phlex Library</title>
  <updated>2024-01-15T10:30:00Z</updated>
  <id>urn:phlex:library:root</id>
  <link rel="self" href="http://localhost:8080/opds/v1.2" type="application/atom+xml;profile=opds-catalog"/>
  <link rel="alternate" href="http://localhost:8080/opds/v1.2/libraries" type="application/atom+xml;profile=opds-catalog;kind=navigation"/>
</feed>
```

### GET /opds/v1.2/libraries

Returns a navigation feed listing all book libraries.

**Response 200:** OPDS Atom XML with navigation links to library acquisition feeds.

### GET /opds/v1.2/libraries/{id}

Returns an acquisition feed listing all books in a library.

**Parameters:**
- `id` (path) — Library ID
- `offset` (query) — Pagination offset (default: 0)
- `limit` (query) — Maximum items per page (default: 50, max: 100)

**Response 200:** OPDS Atom XML with book entries, pagination links (previous/next).

---

## Book Endpoints

### GET /books

Returns a list of all books.

**Query parameters:**
- `library_id` (optional) — Filter by library
- `limit` (optional) — Maximum items (default: 50)
- `offset` (optional) — Pagination offset (default: 0)

**Response 200:**
```json
{
  "books": [
    {
      "id": "book-123",
      "name": "Book Title",
      "type": "book",
      "path": "/path/to/book.epub",
      "metadata": {
        "title": "Book Title",
        "author": "Author Name"
      }
    }
  ],
  "limit": 50,
  "offset": 0
}
```

### GET /books/{id}

Returns a single book by ID.

**Parameters:**
- `id` (path) — Book ID

**Response 200:**
```json
{
  "book": {
    "id": "book-123",
    "name": "Book Title",
    "type": "book",
    "path": "/path/to/book.epub",
    "metadata": {}
  }
}
```

**Response 404:** Book not found

### GET /books/{id}/cover

Returns the book's cover image.

**Response 200:** JPEG/PNG image with appropriate Content-Type header.

**Response 404:** Cover not found or book not found

### GET /books/{id}/download

Returns the book file for download.

**Response 200:** Book file with Content-Disposition: attachment header.
- EPUB: `application/epub+zip`
- PDF: `application/pdf`
- CBZ: `application/vnd.comicbook+zip`

**Response 404:** File not found
