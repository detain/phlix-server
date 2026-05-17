# Streaming Protocols

Phlex Media Server supports two adaptive streaming protocols: **HLS** (HTTP Live Streaming) and **DASH** (Dynamic Adaptive Streaming over HTTP). Both protocols enable adaptive bitrate streaming, allowing clients to select appropriate quality levels based on network conditions and device capabilities.

## Overview

| Feature | HLS | DASH |
|---------|-----|------|
| Developed by | Apple | DASH-IF |
| Manifest format | `.m3u8` playlist | `.mpd` XML |
| Segment format | `.ts` (MPEG-TS) | `.m4s` (MPEG-4) |
| Browser support | Native Safari, limited | Native support via MSE |
| Codec support | H.264/AAC | H.264/AAC, H.265/AAC |
| Low-latency mode | HLS v4 | DASH-CMAF |

## When to Use Each Protocol

### HLS (HTTP Live Streaming)

**Best for:**
- Apple ecosystem (iOS, Safari, tvOS)
- Broad compatibility with legacy devices
- Simpler implementation when targeting primarily Apple devices
- Live streaming with moderate latency requirements

**Characteristics:**
- Master playlist (`playlist.m3u8`) lists all quality variants
- Variant playlists (`stream_N.m3u8`) list segments for each quality
- Segments are `.ts` container format
- Native support in Safari; requires MediaSource Extensions for other browsers

### DASH (Dynamic Adaptive Streaming over HTTP)

**Best for:**
- Cross-platform web applications using MSE
- Lower latency requirements (DASH-CMAF mode)
- Complex adaptive scenarios with multiple subtitle/audio tracks
- Standards-compliant implementations

**Characteristics:**
- MPD (Media Presentation Description) is an XML manifest
- Uses SegmentTemplate for efficient segment addressing
- Segments are `.m4s` (MPEG-4 container) format
- Excellent browser support via MediaSource Extensions

## Manifest Structure

### HLS Master Playlist

```
#EXTM3U
#EXT-X-VERSION:3
#EXT-X-STREAM-INF:BANDWIDTH=5000000,RESOLUTION=1920x1080,NAME="1080p"
stream_0.m3u8
#EXT-X-STREAM-INF:BANDWIDTH=2500000,RESOLUTION=1280x720,NAME="720p"
stream_1.m3u8
```

### DASH MPD (Media Presentation Description)

```xml
<?xml version="1.0" encoding="UTF-8"?>
<MPD xmlns="urn:mpeg:dash:schema:mpd:2011"
     profiles="urn:mpeg:dash:profile:isoff-live:2011"
     type="static"
     minBufferTime="PT2S">
  <Period id="1" duration="PT0H1M0S">
    <AdaptationSet id="1" contentType="video" bandwidth="5000000">
      <Representation id="video-1080" codecs="avc1.64001f"
                       width="1920" height="1080" bandwidth="5000000">
        <SegmentTemplate media="$RepresentationID$_$Number%05d$.m4s"
                         initialization="$RepresentationID$_init.m4s"
                         startNumber="1" duration="6000"/>
      </Representation>
    </AdaptationSet>
    <AdaptationSet id="2" contentType="audio" bandwidth="128000">
      <Representation id="audio-en" codecs="mp4a.40.2"
                       audioSamplingRate="48000" bandwidth="128000">
        <SegmentTemplate media="$RepresentationID$_$Number%05d$.m4s"
                         initialization="$RepresentationID$_init.m4s"
                         startNumber="1" duration="6000"/>
      </Representation>
    </AdaptationSet>
  </Period>
</MPD>
```

## Client-Side Selection

### JavaScript Example (DASH)

```javascript
// Using dash.js
const player = dashjs.MediaPlayer().create();
player.initialize(document.querySelector('#video'), manifestUrl, true);
```

### JavaScript Example (HLS)

```javascript
// Using hls.js
const hls = new Hls();
hls.loadSource(playlistUrl);
hls.attachMedia(document.querySelector('#video'));
```

### Automatic Selection Strategy

1. **Detect browser capabilities** - Check for MediaSource Extensions support
2. **Platform detection** - Prioritize HLS on Safari/iOS, DASH elsewhere
3. **Use DASH-IF guidelines** for cross-platform applications
4. **Consider latency requirements** - DASH-CMAF for low-latency

## Server-Side Implementation

### Class Architecture

```
StreamManager
├── HlsStreamer     → generates .m3u8 playlists + .ts segments
└── DashStreamer    → generates .mpd manifests + .m4s segments
```

Both streamers share the same segment storage (transcode pipeline writes segments once). The appropriate streamer is selected based on the client's requested protocol.

### Routes

| Endpoint | Protocol | Description |
|---------|----------|-------------|
| `GET /hls/{jobId}/playlist.m3u8` | HLS | Master playlist |
| `GET /hls/{jobId}/stream_{n}.m3u8` | HLS | Variant playlist |
| `GET /hls/{jobId}/{variant}/segment_{n}.ts` | HLS | TS segment |
| `GET /dash/{jobId}/manifest.mpd` | DASH | Master manifest |
| `GET /dash/{jobId}/{setId}/manifest.mpd` | DASH | Adaptation set manifest |
| `GET /dash/{jobId}/{setId}/segment_{n}.m4s` | DASH | M4S segment |

### Getting the Correct Manifest URL

```php
use Phlex\Media\Streaming\StreamManager;

// $protocol is 'hls' or 'dash'
$manifestUrl = $streamManager->getManifestUrl($jobId, $protocol);
```

## Segment Format Details

### MPEG-2 Transport Stream (.ts)

- Container: MPEG-2 TS (older, wider support)
- Video codec: H.264/AVC
- Audio codec: AAC-LC
- Typical segment duration: 6-10 seconds

### MPEG-4 Fragmented (.m4s)

- Container: ISO Base Media File Format (MPEG-4)
- Video codec: H.264/AVC or H.265/HEVC
- Audio codec: AAC-LC
- Typical segment duration: 2-6 seconds for low-latency
- Supports CMAF (Common Media Application Format) for ultra-low latency

## Configuration

### FFmpeg (config/ffmpeg.php)

```php
'dash' => [
    'enabled' => true,
    'segment_dir' => '/var/segments',
    'default_codecs' => [
        'video' => 'avc1.64001f',   // H.264 High Profile Level 3.1
        'audio' => 'mp4a.40.2',     // AAC-LC
    ],
],
```

### DASH-Specific (config/dash.php)

```php
'enabled' => true,
'manifest_refresh_seconds' => 30,
'min_buffer_time' => 'PT2S',           // 2 seconds
'min_buffer_time_live' => 'PT10S',    // 10 seconds for live
'time_shift_buffer_depth' => 'PT30M', // 30 minutes DVR window
```

## Further Reading

- [HLS RFC 8216](https://datatracker.ietf.org/doc/html/rfc8216)
- [DASH-IF Implementation Guidelines](https://dashif.org/docs/DASH-IF-IOP-v4.0.pdf)
- [MediaSource Extensions API](https://developer.mozilla.org/en-US/docs/Web/API/MediaSource)
- [dash.js Reference](https://github.com/Dash-Industry-Forum/dash.js)
- [hls.js Reference](https://github.com/video-dev/hls.js)

## Trickplay / Thumbnail Seek

Trickplay (also called "scrub preview" or "thumbnail seek") allows users to preview a video by hovering over the progress bar and seeing thumbnail images at regular intervals.

### Overview

| Feature | Description |
|---------|-------------|
| Format | DASH-IF / HLS spec-compliant "BIF" (Bitmap Image Format) |
| Grid layout | 8×4 (32 thumbnails per grid image, configurable) |
| Thumbnail size | 160×90 pixels (configurable) |
| Interval | 10 seconds between thumbnails (configurable) |
| Image format | JPEG or PNG with quality settings |

### How It Works

1. **Generation** — After transcoding completes, `TrickplayGenerator` extracts frames at fixed intervals using FFmpeg batch extraction
2. **Grid Assembly** — Frames are assembled into grid images using FFmpeg's `tile` filter (e.g., `tile=8x4:margin=2:padding=3`)
3. **Index Generation** — A BIF index XML maps each thumbnail index to its time position and byte offset in the grid file
4. **Serving** — `TrickplayController` serves grid images and the index XML with correct `Content-Type` headers

### BIF Index Format

```xml
<ThumbList>
  <Thumbs>
    <Thumb index="0" time="0" offset="0" length="4096"/>
    <Thumb index="1" time="10" offset="4096" length="4096"/>
    <Thumb index="2" time="20" offset="8192" length="4096"/>
    ...
  </Thumbs>
</ThumbList>
```

The `offset` and `length` attributes enable byte-range requests, allowing clients to download only the portion of the grid image needed for a single thumbnail.

### Server-Side Implementation

```
StreamManager
├── HlsStreamer          → generates .m3u8 playlists + .ts segments
├── DashStreamer        → generates .mpd manifests + .m4s segments
└── TrickplayGenerator → generates BIF thumbnail grids + index XML
```

### Routes

| Endpoint | Description |
|---------|-------------|
| `GET /trickplay/{jobId}/thumb-{index}.jpg` | Thumbnail grid image |
| `GET /trickplay/{jobId}/index.xml` | BIF index XML |

### Configuration

```php
// config/trickplay.php
[
    'enabled' => true,
    'interval_seconds' => 10,
    'grid_columns' => 8,
    'grid_rows' => 4,
    'thumb_width' => 160,
    'thumb_height' => 90,
    'image_format' => 'jpeg',
    'jpeg_quality' => 72,
    'storage_dir' => '/var/trickplay',
]
```

### FFmpeg Extension

`FfmpegRunner::generateThumbnail()` now supports batch extraction:

```php
// Single thumbnail
$runner->generateThumbnail('/video.mkv', '/thumb.jpg', 30);

// Multiple thumbnails (batch)
$runner->generateThumbnailBatch('/video.mkv', [0, 10, 20, 30], '/output/dir');
```

### Class Architecture

- `TrickplayConfig` — Value object with grid dimensions, thumbnail size, interval, format
- `TrickplayResult` — Result container with job ID, image file metadata, index XML path
- `TrickplayGenerator` — Extracts frames, assembles grids, generates BIF index XML
- `TrickplayController` — HTTP handler for serving thumbnails and index with byte-range support
