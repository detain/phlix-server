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
