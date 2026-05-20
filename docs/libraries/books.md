# Books Library Documentation

**Since:** 0.17.0

The books library provides support for electronic book formats (EPUB, PDF, CBZ) with OPDS 1.2 feed generation for third-party client compatibility.

## Supported Formats

| Format | Extension | Metadata Extraction | Notes |
|--------|-----------|-------------------|-------|
| EPUB | epub | ✅ Full | Parses content.opf for title, author, publisher, ISBN, language, publication date, description |
| PDF | pdf | ✅ Basic | Uses exif_read_data for XMP/EXIF metadata; page count extraction |
| CBZ (Comic Book Archive) | cbz | ✅ Full | Parses ComicInfo.xml for series, volume, author; extracts cover image |

## OPDS Feed URL

The OPDS feed is available at `/opds/v1.2`. This follows the OPDS 1.2 specification and is compatible with:

- **Uboiquity** (macOS/Windows/Linux)
- **Komga** (self-hosted)
- **Kore** (Android)
- **Moon+ Reader** (Android)
- **Apple Books** (iOS/macOS) — via third-party OPDS integration

## OPDS Endpoints

### Root Feed
```
GET /opds/v1.2
```
Returns the root OPDS catalog with links to libraries.

### Libraries Navigation
```
GET /opds/v1.2/libraries
```
Returns a navigation feed listing all book libraries.

### Library Acquisition Feed
```
GET /opds/v1.2/libraries/{library_id}?offset=0&limit=50
```
Returns an acquisition feed listing all books in a library with pagination support.

## Web Portal Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/books` | GET | List all books |
| `/books/{id}` | GET | Get book details |
| `/books/{id}/cover` | GET | Get cover image |
| `/books/{id}/read` | GET | Open reader stub |
| `/books/{id}/download` | GET | Download book file |

## Naming Conventions

Books should be named to facilitate metadata extraction:

### EPUB
```
Author - Title.epub
Title (Year).epub
```

### PDF
```
Author - Title.pdf
Title.pdf
```

### CBZ (Comics)
```
Series Name v01.cbz
Series Name 2020 Issue #01.cbz
```

## Metadata Fields Extracted

### EPUB
| Field | Description |
|-------|-------------|
| `title` | Book title from dc:title |
| `author` | Creator/author from dc:creator |
| `publisher` | Publisher from dc:publisher |
| `isbn` | ISBN from dc:identifier |
| `language` | Language code from dc:language |
| `pub_date` | Publication date from dc:date |
| `description` | Description from dc:description |
| `cover_path` | Path to extracted cover image |

### PDF
| Field | Description |
|-------|-------------|
| `title` | Document title |
| `author` | Document author |
| `subject` | Document subject |
| `keywords` | Document keywords |
| `creator` | Creator application |
| `producer` | PDF producer |
| `creation_date` | Creation date |
| `page_count` | Number of pages |

### CBZ
| Field | Description |
|-------|-------------|
| `title` | Comic title |
| `series` | Series name |
| `volume` | Volume number |
| `authors` | Writer/artist names |
| `page_count` | Number of pages |
| `cover_page` | Cover page index |
| `cover_path` | Path to extracted cover |

## Reader Stub

The built-in reader stub at `/books/{id}/read` provides:

- Paginated HTML view (intentionally minimal)
- Font size controls (A- / A+)
- Theme switching (light / sepia / dark)
- Keyboard navigation (← / →)

**Note:** Full EPUB rendering with text flow is planned for a future release. The current reader stub displays metadata and book information.

## Third-Party OPDS Client Setup

### Uboiquity (Recommended for desktop)
1. File → Add Catalog
2. Enter OPDS feed URL: `http://your-server:8080/opds/v1.2`
3. Browse and download books

### Komga
1. Add server with your Phlix URL
2. Libraries are automatically discovered via OPDS

### Moon+ Reader
1. Menu → Online catalog
2. Add custom catalog
3. Enter OPDS feed URL
