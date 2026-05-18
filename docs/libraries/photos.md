# Photos Library Documentation

**Since:** 0.16.0

The photos library provides photo browsing with EXIF metadata extraction, album organization, and slideshow functionality.

## Supported Formats

The photo library supports the following image formats:

| Format | Extension | EXIF Support | Notes |
|--------|-----------|-------------|-------|
| JPEG   | jpg, jpeg | ✅ Full      | Best for photos; EXIF extraction via `exif_read_data()` |
| PNG    | png       | ❌ Basic     | Dimensions only; no camera metadata |
| TIFF   | tiff, tif | ❌ Basic     | Dimensions only |
| WebP   | webp      | ❌ Basic     | Dimensions only |
| HEIC   | heic, heif| ⚠️ Limited  | Requires ImageMagick extension; graceful fallback if unavailable |

## EXIF Fields Extracted

When scanning photos, the following EXIF metadata is extracted and stored in each media item's `metadata_json`:

| Field | Type | Description |
|-------|------|-------------|
| `camera_make` | string | Camera manufacturer (e.g., "Canon", "Nikon") |
| `camera_model` | string | Camera model (e.g., "EOS 5D Mark IV") |
| `lens` | string | Lens model (if available in EXIF) |
| `aperture` | string | Aperture value (e.g., "f/2.8") |
| `iso` | int | ISO sensitivity |
| `shutter_speed` | string | Exposure time (e.g., "1/250") |
| `focal_length` | string | Focal length (e.g., "50mm") |
| `width` | int | Image width in pixels |
| `height` | int | Image height in pixels |
| `orientation` | int | EXIF orientation code (1-8) |
| `orientation_name` | string | Human-readable orientation |
| `date_taken_unix` | int | Unix timestamp when photo was taken |
| `gps_lat` | float | GPS latitude coordinate |
| `gps_lng` | float | GPS longitude coordinate |
| `gps_alt` | float | GPS altitude in meters |

## Album Organization

Photos are automatically grouped into albums based on the date they were taken:

- **Date-based albums**: Photos are grouped by `YYYY-MM-DD` of `date_taken_unix`
- Albums are sorted with most recent first
- Each album shows a cover photo and photo count

## API Endpoints

### Albums

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/photo/albums` | List all albums (grouped by date) |
| GET | `/photo/albums/{id}` | Get specific album with photos |

### Photos

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/photo/photos` | List all photos |
| GET | `/photo/photos/{id}` | Get photo with full EXIF data |
| GET | `/photo/photos/{id}/thumbnail?w=300&h=300&fit=cover` | Get resized thumbnail |
| GET | `/photo/photos/{id}/full` | Get full-resolution photo |

### Slideshow

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/photo/slideshow?album_id=xxx&interval=5` | Get slideshow data |

## Thumbnail Generation

Thumbnails are generated on-demand using PHP's GD library:

- Default size: 300×300 pixels
- Fit modes: `cover` (crop to fill) or `contain` (letterbox)
- Thumbnails are served with `Cache-Control: public, max-age=86400`
- Full photos use `Cache-Control: public, max-age=31536000` (1 year)

## Slideshow Player

The slideshow player features:

- **Auto-advance**: Configurable interval (default 5 seconds)
- **Keyboard controls**: Arrow keys (←/→), Space (play/pause), Escape (exit)
- **Touch/swipe**: Swipe left/right on mobile devices
- **Thumbnail strip**: Quick navigation at bottom
- **Caption display**: Shows camera info and date

## Deferred Features

The following features are planned for future releases:

- **Geotag clustering / map view**: Photos with GPS coordinates will be plottable on a map
- **Thumbnail caching**: Disk-based cache for generated thumbnails
- **Photo editing**: Basic editing (rotate, crop)
- **Sharing**: Generate shareable links for photos/albums
- **Backup integration**: Export photos to cloud storage

## Configuration

No special configuration is required for the photos library. The following optional settings may be added in `config/server.php`:

```php
// Future photo library settings (not yet implemented)
'photo' => [
    'thumbnail_size' => 300,
    'slideshow_interval' => 5,
    'enable_map_view' => false, // Future: Enable geotag clustering
],
```

## Known Limitations

1. **HEIC/HEIF support**: Requires ImageMagick extension; falls back to basic metadata if unavailable
2. **No video support**: Video files in photo libraries are ignored
3. **No map view**: GPS coordinates are stored but no map UI exists yet
4. **No thumbnail cache**: Thumbnails are regenerated on each request
5. **No face detection**: People/face tagging not supported
