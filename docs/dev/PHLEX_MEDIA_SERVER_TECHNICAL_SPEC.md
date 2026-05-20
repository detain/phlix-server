# Phlix Media Server - Comprehensive Technical Specification

**Document Version:** 2.0  
**Last Updated:** 2026-05-14  
**Technology Stack:** PHP 8.2+ / Workerman 5.x / Webman  

---

## Table of Contents

1. [System Architecture Overview](#1-system-architecture-overview)
2. [Media Processing Pipeline](#2-media-processing-pipeline)
3. [Transcoding Engine](#3-transcoding-engine)
4. [Streaming Protocols](#4-streaming-protocols)
5. [Device Profile System](#5-device-profile-system)
6. [Session Management](#6-session-management)
7. [API Endpoints](#7-api-endpoints)
8. [Database Schema](#8-database-schema)
9. [Implementation Details](#9-implementation-details)

---

## 1. System Architecture Overview

### 1.1 High-Level Component Diagram

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                              CLIENTS                                         │
├─────────────┬─────────────┬─────────────┬─────────────┬─────────────┬───────┤
│   Web UI    │  Samsung    │    Roku     │   Windows   │    iOS/     │ DLNA  │
│             │  Tizen TV   │   TV        │   Desktop   │   Android   │       │
└──────┬──────┴──────┬──────┴──────┬──────┴──────┬──────┴──────┬──────┴───┬───┘
       │             │             │             │             │          │
       └─────────────┴─────────────┴─────────────┴─────────────┴──────────┘
                                     │
                           ┌─────────▼─────────┐
                           │   API Gateway     │
                           │   (Workerman)     │
                           │   Port: 8096      │
                           └─────────┬─────────┘
                                     │
         ┌───────────────────────────┼───────────────────────────┐
         │                           │                           │
   ┌─────▼─────┐             ┌───────▼──────┐             ┌──────▼──────┐
   │  Media    │             │   Stream     │             │   Auth &    │
   │  Server   │             │   Control    │             │   Session   │
   │  Node 1   │      ...    │   Node N     │             │   Server    │
   └─────┬─────┘             └───────┬──────┘             └──────┬──────┘
         │                           │                           │
         └───────────────────────────┼───────────────────────────┘
                                     │
                    ┌────────────────▼────────────────┐
                    │       Shared Storage Layer       │
                    │  ┌─────────────┬─────────────┐  │
                    │  │  Database   │   Media     │  │
                    │  │  (MySQL)    │   Files     │  │
                    │  └─────────────┴─────────────┘  │
                    └─────────────────────────────────┘
```

### 1.2 Component Responsibilities

| Component | Responsibility | Technology |
|-----------|---------------|------------|
| API Gateway | HTTP/WebSocket request handling, routing, rate limiting | Workerman HTTP |
| Media Server | Media library management, metadata, file scanning | PHP 8.2+ |
| Stream Control | Stream state management, segment delivery | Workerman |
| Transcode Engine | FFmpeg process management, codec conversion | FFmpeg + PHP |
| Auth Server | JWT generation, session tracking, API keys | PHP |
| Database | User data, media metadata, playback state | MySQL 8.0 |
| File Storage | Original media files, transcoded segments | Local/Network FS |

### 1.3 Request Flow: Video Playback

```
Client                    Phlix Server                    Components
  │                             │                              │
  │──── GET /Items/{id} ───────>│                              │
  │                             │──── Query DB ───────────────>│ Database
  │                             │<─── Item metadata ───────────│
  │<─── Item + PlaybackInfo ────│                              │
  │                             │                              │
  │──── POST /Sessions/Play ───>│                              │
  │                             │──── Create Session ─────────>│ Session Manager
  │                             │──── Check Device Profile ────>│ Profile System
  │                             │                              │
  │                             │ IF Transcode Required:       │
  │                             │──── Spawn FFmpeg ───────────>│ Transcode Engine
  │                             │<──── Transcode PID ──────────│
  │                             │                              │
  │<─── Playback URL ───────────│                              │
  │                             │                              │
  │====== HLS/Direct Stream =====│                              │
  │                             │                              │
  │  (Segment requests cycle)    │                              │
  │                             │                              │
  │──── POST /Playstate ────────│                              │
  │  (Progress updates)          │──── Update DB ─────────────>│ Session Manager
  │                             │                              │
```

---

## 2. Media Processing Pipeline

### 2.1 Media Scanning Flow

```
┌─────────────────────────────────────────────────────────────────┐
│                     MEDIA SCANNING PIPELINE                      │
└─────────────────────────────────────────────────────────────────┘

Start: New folder added to library
         │
         ▼
┌─────────────────────────────────────────────────────────────────┐
│  Step 1: Directory Traversal                                      │
│  - RecursiveIteratorIterator scans all files                     │
│  - Skip hidden files (starting with .)                           │
│  - Skip system files (*.part, *.tmp, *_unpack)                   │
│  - File extension whitelist per library type                     │
└─────────────────────────────────────────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────────────────────────────────┐
│  Step 2: File Type Detection                                     │
│  - Movies: mkv, mp4, avi, mov, wmv, flv, webm, m4v, mpg, mpeg   │
│  - Series: Same as movies + ts (transport stream)                │
│  - Music: mp3, flac, aac, ogg, wav, m4a, wma, alac, opus        │
│  - Photos: jpg, jpeg, png, gif, bmp, webp, tiff                  │
└─────────────────────────────────────────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────────────────────────────────┐
│  Step 3: Naming Parser (Emby.Naming)                             │
│                                                                 │
│  Movie Pattern:                                                  │
│  {Name} ({Year})/{Name} ({Year}).ext                            │
│  {Name}.{Year}.ext                                              │
│  {Name}.ext                                                      │
│                                                                 │
│  Series Pattern:                                                 │
│  {Series}/Season {N}/{Series} S{N}E{M}.ext                      │
│  {Series}/{Series}.S{N}E{M}.ext                                  │
│  {Series} - S{N}E{M} - {EpisodeTitle}.ext                        │
│                                                                 │
│  Audio Pattern:                                                  │
│  {Artist}/{Album}/{Track} - {Title}.ext                          │
│  {Album}/{Artist} - {Title}.ext                                  │
└─────────────────────────────────────────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────────────────────────────────┐
│  Step 4: Metadata Extraction (FFprobe)                          │
│                                                                 │
│  Video Stream:                                                   │
│  - codec_name, width, height, bitrate                           │
│  - duration, fps, color_space, hdr_type                         │
│  - rotation, profile (e.g., "high", "main")                     │
│                                                                 │
│  Audio Stream:                                                   │
│  - codec_name, channel_layout, sample_rate                      │
│  - bitrate, language, number of streams                         │
│                                                                 │
│  Subtitle Stream:                                                │
│  - codec_name, language, is_external                            │
│  - codec_profile (e.g., "srt", "ass", "pgs")                    │
└─────────────────────────────────────────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────────────────────────────────┐
│  Step 5: External Metadata Fetch (TMDB/TVDB/FanArt)             │
│                                                                 │
│  For Movies:                                                     │
│  1. Search TMDB by filename (fuzzy match)                       │
│  2. Get movie details (overview, genres, cast)                  │
│  3. Get images (posters, backdrops, logos)                      │
│  4. Get trailer URL (YouTube/Vimeo)                             │
│                                                                 │
│  For Series:                                                     │
│  1. Search TVDB by series name                                  │
│  2. Get all seasons and episodes                                │
│  3. Match episodes by season/episode number                     │
│  4. Get episode details and images                              │
│  5. Get series artwork                                          │
└─────────────────────────────────────────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────────────────────────────────┐
│  Step 6: Media Item Creation                                     │
│                                                                 │
│  Database Operations:                                            │
│  1. INSERT INTO media_items (id, library_id, name, type, path) │
│  2. INSERT INTO media_streams (media_item_id, stream_index, ...) │
│  3. INSERT INTO item_images (item_id, image_type, url)          │
│  4. INSERT INTO item_genres (item_id, genre)                    │
│  5. INSERT INTO item_actors (item_id, actor, role)              │
│                                                                 │
│  File System:                                                    │
│  - Create .ignore file if not present                           │
│  - Generate thumbnails (for videos)                             │
│  - Extract subtitles (if external)                              │
└─────────────────────────────────────────────────────────────────┘
         │
         ▼
End: Library updated, UI notified via WebSocket
```

### 2.2 Metadata Provider Configuration

```php
// config/metadata.php
return [
    'providers' => [
        'tmdb' => [
            'enabled' => true,
            'api_key' => env('TMDB_API_KEY'),
            'base_url' => 'https://api.themoviedb.org/3',
            'image_base_url' => 'https://image.tmdb.org/t/p',
            'timeout' => 10,
            'cache_ttl' => 86400 * 7, // 7 days
            'languages' => ['en-US', 'en'],
            'priority' => 1,
        ],
        'tvdb' => [
            'enabled' => true,
            'api_key' => env('TVDB_API_KEY'),
            'base_url' => 'https://api.thetvdb.com',
            'timeout' => 10,
            'cache_ttl' => 86400 * 7,
            'languages' => ['en'],
            'priority' => 2,
        ],
        'fanart' => [
            'enabled' => true,
            'api_key' => env('FANART_API_KEY'),
            'base_url' => 'https://webservice.fanart.tv/v3',
            'timeout' => 10,
            'cache_ttl' => 86400 * 7,
            'priority' => 3,
        ],
        'local' => [
            'enabled' => true,
            'nfo' => true,        // Read .nfo files
            'poster' => true,     // Read local posters
            'fanart' => true,     // Read local fanart
            'subtitles' => true,  // Match subtitle files
        ],
    ],
    'options' => [
        'refresh_on_import' => false,
        'auto_credits_poster' => true,
        'auto_fanart_backdrop' => true,
        'image_max_width' => 1920,
        'metadata_level' => 'basic', // basic, full, premium
    ],
];
```

---

## 3. Transcoding Engine

### 3.1 Transcode Decision Matrix

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                    TRANSCODE DECISION FLOW                                    │
└─────────────────────────────────────────────────────────────────────────────┘

Client requests playback
         │
         ▼
┌─────────────────────────────────────────────────────────────────┐
│  Get Device Profile                                              │
│  - Identify client type (Web, Mobile, TV, etc.)                 │
│  - Get codec capabilities from profile                           │
│  - Get max resolution and bitrate                                │
└─────────────────────────────────────────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────────────────────────────────┐
│  Probe Source File (FFprobe)                                     │
│                                                                 │
│  ffprobe -v quiet \                                              │
│    -print_format json \                                          │
│    -show_format \                                                │
│    -show_streams \                                               │
│    /path/to/media.mkv                                            │
│                                                                 │
│  Returns:                                                        │
│  {                                                               │
│    "streams": [                                                  │
│      {                                                           │
│        "codec_type": "video",                                    │
│        "codec_name": "hevc",                                     │
│        "width": 3840,                                            │
│        "height": 2160,                                           │
│        "bit_rate": 50000000,                                     │
│        "color_space": "bt2020nc",                                │
│        "color_transfer": "smpte2084",   // HDR10                 │
│        "profile": "main 10",                                     │
│        "r_frame_rate": "60/1"                                    │
│      },                                                          │
│      {                                                           │
│        "codec_type": "audio",                                    │
│        "codec_name": "truehd",                                   │
│        "channels": 8,                                            │
│        "bit_rate": 4000000,                                      │
│        "language": "eng"                                         │
│      }                                                           │
│    ],                                                            │
│    "format": {                                                   │
│      "duration": "7200.000"                                      │
│    }                                                             │
│  }                                                               │
└─────────────────────────────────────────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────────────────────────────────┐
│  Can Client Direct Play?                                         │
│                                                                 │
│  Checklist:                                                      │
│  □ Container supported? (mp4, mkv, webm)                        │
│  □ Video codec supported? (h264, hevc, vp9, av1)                │
│  □ Audio codec supported? (aac, mp3, ac3, eac3)                 │
│  □ Resolution <= max? (e.g., 1080p for mobile)                  │
│  □ Bitrate <= max? (e.g., 10 Mbps for web)                      │
│  □ HDR tone mapping needed? (bt2020 -> srgb)                    │
│                                                                 │
│  IF ALL YES: Direct Play (no transcoding)                        │
│  IF ANY NO:  Transcode required                                  │
└─────────────────────────────────────────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────────────────────────────────┐
│  Select Transcoding Profile                                      │
│                                                                 │
│  Based on device and source:                                     │
│                                                                 │
│  Low-end Mobile:                                                 │
│  - Video: H.264, 480p, 1500 kbps                                │
│  - Audio: AAC, 128 kbps, Stereo                                 │
│  - Container: MP4 ( fragmentation disabled)                     │
│                                                                 │
│  High-end Mobile/Tablet:                                         │
│  - Video: H.264/H.265, 720p, 4000 kbps                         │
│  - Audio: AAC, 192 kbps, Stereo                                 │
│  - Container: MP4 ( fragmentation enabled)                      │
│                                                                 │
│  Smart TV (General):                                             │
│  - Video: H.264, 1080p, 8000 kbps                               │
│  - Audio: AAC, 256 kbps, 5.1 if source supports                 │
│  - Container: TS                                                 │
│                                                                 │
│  Smart TV (4K HDR):                                              │
│  - Video: H.265, 2160p, 20000 kbps, HDR10                       │
│  - Audio: AC3/EAC3, 640 kbps, 5.1                               │
│  - Container: TS                                                 │
│                                                                 │
│  Web Browser:                                                    │
│  - Video: H.264 (Safari) or VP9/AV1 (Chrome/Firefox)           │
│  - Audio: AAC/MP3, 128 kbps                                     │
│  - Container: MP4 or WebM                                        │
│  - Protocol: HLS (Safari) or DASH (Chrome/Firefox)             │
└─────────────────────────────────────────────────────────────────┘
```

### 3.2 FFmpeg Command Templates

#### 3.2.1 H.264 Transcoding (General Purpose)

```bash
# Standard H.264 transcode with AAC audio
ffmpeg -y \
  -hide_banner \
  -loglevel error \
  -i "${INPUT_FILE}" \
  -c:v libx264 \
  -preset medium \
  -crf 23 \
  -profile:v high \
  -level:v 4.2 \
  -pix_fmt yuv420p \
  -vf "scale=${OUTPUT_WIDTH}:${OUTPUT_HEIGHT}:force_original_aspect_ratio=decrease,pad=${OUTPUT_WIDTH}:${OUTPUT_HEIGHT}:(ow-iw)/2:(oh-ih)/2" \
  -c:a aac \
  -b:a 192k \
  -ac 2 \
  -ar 48000 \
  -movflags +faststart \
  -f mp4 \
  -threads 0 \
  "${OUTPUT_FILE}"

# Parameters explained:
# -preset medium: encoding speed vs quality tradeoff (ultrafast -> placebo)
# -crf 23: constant quality (0=lossless, 51=worst, 18-23 typically used)
# -profile:v high: broadest compatibility
# -level:v 4.2: max resolution 4K, max bitrate 62.5 Mbps
# -pix_fmt yuv420p: convert to 8-bit YUV for compatibility
# -vf scale: resize and pad to exact resolution
# -movflags +faststart: enable streaming
# -threads 0: auto-detect CPU cores
```

#### 3.2.2 H.265/HEVC Transcoding (4K/HDR)

```bash
# H.265 transcode for 4K HDR content
ffmpeg -y \
  -hide_banner \
  -loglevel error \
  -i "${INPUT_FILE}" \
  -c:v libx265 \
  -preset medium \
  -crf 28 \
  -profile:v main10 \
  -pix_fmt yuv420p10le \
  -vf "scale=${OUTPUT_WIDTH}:${OUTPUT_HEIGHT}:force_original_aspect_ratio=decrease" \
  -color_primaries bt2020 \
  -color_trc smpte2084 \
  -colorspace bt2020nc \
  -c:a aac \
  -b:a 256k \
  -ac 6 \
  -ar 48000 \
  -f mpegts \
  -threads 0 \
  "${OUTPUT_FILE}"

# HDR10 tone mapping (for HDR10 to SDR conversion)
ffmpeg -y \
  -hide_banner \
  -loglevel error \
  -i "${INPUT_HDR_FILE}" \
  -c:v libx265 \
  -preset medium \
  -crf 23 \
  -profile:v main10 \
  -pix_fmt yuv420p10le \
  -vf "zscale=t=bt2020nc:p=bt2020:min=bt2020,scale=format=yuv420p" \
  -c:a aac \
  -b:a 192k \
  -ac 2 \
  -f mpegts \
  -threads 0 \
  "${OUTPUT_SDR_FILE}"
```

#### 3.2.3 VP9 Transcoding (WebM/Chrome/Firefox)

```bash
# VP9 transcode for Chrome/Firefox
ffmpeg -y \
  -hide_banner \
  -loglevel error \
  -i "${INPUT_FILE}" \
  -c:v libvpx-vp9 \
  -b:v 4000k \
  -crf 31 \
  -deadline good \
  -cpu-used 2 \
  -vf "scale=${OUTPUT_WIDTH}:${OUTPUT_HEIGHT}:force_original_aspect_ratio=decrease" \
  -c:a libopus \
  -b:a 128k \
  -ar 48000 \
  -f webm \
  "${OUTPUT_FILE}"

# VP9 with HDR support
ffmpeg -y \
  -hide_banner \
  -loglevel error \
  -i "${INPUT_FILE}" \
  -c:v libvpx-vp9 \
  -b:v 8000k \
  -crf 30 \
  -row-mt 1 \
  -vf "scale=3840:2160:force_original_aspect_ratio=decrease" \
  -c:a libopus \
  -b:a 256k \
  -f webm \
  "${OUTPUT_FILE}"
```

#### 3.2.4 Audio Only Transcoding

```bash
# AAC audio for streaming
ffmpeg -y \
  -hide_banner \
  -loglevel error \
  -i "${INPUT_FILE}" \
  -vn \
  -c:a aac \
  -b:a 128k \
  -ar 44100 \
  -ac 2 \
  -f mp4 \
  "${OUTPUT_FILE}"

# MP3 fallback
ffmpeg -y \
  -hide_banner \
  -loglevel error \
  -i "${INPUT_FILE}" \
  -vn \
  -c:a libmp3lame \
  -b:a 192k \
  -ar 44100 \
  -ac 2 \
  -f mp3 \
  "${OUTPUT_FILE}"

# FLAC (lossless)
ffmpeg -y \
  -hide_banner \
  -loglevel error \
  -i "${INPUT_FILE}" \
  -vn \
  -c:a flac \
  -compression_level 8 \
  -f flac \
  "${OUTPUT_FILE}"
```

#### 3.2.5 Subtitle Burn-in

```bash
# Burn SRT subtitles into video
ffmpeg -y \
  -hide_banner \
  -loglevel error \
  -i "${INPUT_FILE}" \
  -vf "subtitles=${SUBTITLE_FILE}:force_style='FontName=Arial,FontSize=24,PrimaryColour=&H00FFFFFF&,OutlineColour=&H00000000&,Outline=2'" \
  -c:v libx264 \
  -preset medium \
  -crf 23 \
  -c:a copy \
  -movflags +faststart \
  -f mp4 \
  "${OUTPUT_FILE}"

# Burn ASS subtitles (with advanced styling)
ffmpeg -y \
  -hide_banner \
  -loglevel error \
  -i "${INPUT_FILE}" \
  -vf "ass=${ASS_FILE}" \
  -c:v libx264 \
  -preset medium \
  -crf 23 \
  -c:a copy \
  -f mp4 \
  "${OUTPUT_FILE}"
```

#### 3.2.6 Thumbnail Extraction

```bash
# Extract frame at specific timestamp
ffmpeg -y \
  -hide_banner \
  -loglevel error \
  -i "${INPUT_FILE}" \
  -ss 00:05:00 \
  -vframes 1 \
  -q:v 2 \
  -f image2 \
  "${THUMBNAIL_FILE}"

# Extract multiple thumbnails for spritesheet
ffmpeg -y \
  -hide_banner \
  -loglevel error \
  -i "${INPUT_FILE}" \
  -vf "fps=1/10,scale=320:-1" \
  -q:v 5 \
  "${SPRITE_FILE_%03d}.jpg"

# Generate sprite sheet (for seek preview)
ffmpeg -y \
  -hide_banner \
  -loglevel error \
  -i "${INPUT_FILE}" \
  -vf "fps=1/10,scale=160:-1,tile=10x10" \
  -q:v 5 \
  -frames:v 1 \
  "${SPRITESHEET_FILE}"
```

### 3.3 HLS Segmenting

#### 3.3.1 HLS Segmentation Command

```bash
# Generate HLS segments with multiple quality levels
ffmpeg -y \
  -hide_banner \
  -loglevel error \
  -i "${INPUT_FILE}" \
  -c:v libx264 \
  -c:a aac \
  -preset medium \
  -crf 23 \
  -sc_threshold 0 \
  -g 60 \
  -keyint_min 60 \
  -hls_time 6 \
  -hls_playlist_type vod \
  -hls_segment_filename "/var/transcodes/${JOB_ID}/segment_%v_%03d.ts" \
  -master_pl_name "playlist.m3u8" \
  -map v:0 -map v:0 -map v:0 \
  -map a:0 -map a:0 -map a:0 \
  -b:v:0 5000000 -maxrate:v:0 5350000 -bufsize:v:0 7500000 \
  -b:v:1 2500000 -maxrate:v:1 2675000 -bufsize:v:1 3750000 \
  -b:v:2 1000000 -maxrate:v:2 1070000 -bufsize:v:2 1500000 \
  -b:a:0 192k -maxrate:a:0 206k \
  -b:a:1 128k -maxrate:a:1 137k \
  -b:a:2 96k -maxrate:a:2 103k \
  -var_stream_map "v:0,a:0 v:1,a:1 v:2,a:2" \
  "/var/transcodes/${JOB_ID}/stream_%v.m3u8"

# This creates:
# /var/transcodes/${JOB_ID}/
#   playlist.m3u8           (master playlist)
#   stream_0.m3u8           (1080p variant)
#   stream_0_%03d.ts        (1080p segments)
#   stream_1.m3u8           (720p variant)
#   stream_1_%03d.ts        (720p segments)
#   stream_2.m3u8           (480p variant)
#   stream_2_%03d.ts        (480p segments)
```

#### 3.3.2 Master Playlist Structure

```m3u8
#EXTM3U
#EXT-X-VERSION:3
#EXT-X-STREAM-INF:BANDWIDTH=5350000,RESOLUTION=1920x1080,NAME="1080p"
stream_0.m3u8
#EXT-X-STREAM-INF:BANDWIDTH=2675000,RESOLUTION=1280x720,NAME="720p"
stream_1.m3u8
#EXT-X-STREAM-INF:BANDWIDTH=1070000,RESOLUTION=854x480,NAME="480p"
stream_2.m3u8
```

#### 3.3.3 Variant Playlist Structure

```m3u8
#EXTM3U
#EXT-X-VERSION:3
#EXT-X-TARGETDURATION:6
#EXT-X-MEDIA-SEQUENCE:0
#EXT-X-PLAYLIST-TYPE:VOD
#EXTINF:6.00000,
segment_0_001.ts
#EXTINF:6.00000,
segment_0_002.ts
#EXTINF:6.00000,
segment_0_003.ts
...
#EXTINF:4.00000,
segment_0_120.ts
#EXT-X-ENDLIST
```

### 3.4 Transcode Process Management

```php
// src/Media/Transcoding/TranscodeProcess.php

namespace Phlix\Media\Transcoding;

class TranscodeProcess
{
    private int $pid;
    private string $jobId;
    private string $inputPath;
    private string $outputPath;
    private string $command;
    private float $startTime;
    private float $progress = 0;
    private string $status = 'starting'; // starting, running, completing, completed, failed, cancelled
    private ?int $exitCode = null;
    private array $ffmpegOutput = [];

    public function __construct(
        string $jobId,
        string $inputPath,
        string $outputPath,
        string $command
    ) {
        $this->jobId = $jobId;
        $this->inputPath = $inputPath;
        $this->outputPath = $outputPath;
        $this->command = $command;
        $this->startTime = microtime(true);
    }

    public function start(): bool
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $this->process = proc_open($this->command, $descriptorSpec, $pipes);

        if (!is_resource($this->process)) {
            $this->status = 'failed';
            return false;
        }

        $this->pid = proc_get_status($this->process)['pid'];
        $this->status = 'running';

        // Start progress monitoring in async stream
        $this->startProgressMonitor($pipes[1]);

        return true;
    }

    private function startProgressMonitor($stdout): void
    {
        // Non-blocking read of FFmpeg progress output
        stream_set_blocking($stdout, false);

        $progressThread = new \Fiber(function () use ($stdout) {
            $duration = $this->getDuration();

            while (!feof($stdout)) {
                $line = fgets($stdout);
                if ($line === false) {
                    \Fiber::suspend();
                    continue;
                }

                // Parse time=hh:mm:ss.ms format
                if (preg_match('/time=(\d+):(\d+):(\d+\.\d+)/', $line, $matches)) {
                    $currentTime = (int)$matches[1] * 3600 + (int)$matches[2] * 60 + (float)$matches[3];
                    $this->progress = $duration > 0 ? ($currentTime / $duration) * 100 : 0;

                    // Emit progress event
                    EventEmitter::emit('transcode_progress', [
                        'job_id' => $this->jobId,
                        'progress' => $this->progress,
                        'current_time' => $currentTime,
                        'duration' => $duration,
                    ]);
                }

                \Fiber::suspend(); // Yield to event loop
            }
        });

        $progressThread->start();
    }

    public function getDuration(): float
    {
        // Use FFprobe to get duration (cached)
        static $duration = null;

        if ($duration === null) {
            $cmd = sprintf(
                'ffprobe -v quiet -print_format json -show_format %s 2>/dev/null',
                escapeshellarg($this->inputPath)
            );

            $output = shell_exec($cmd);
            $data = json_decode($output, true);
            $duration = (float)($data['format']['duration'] ?? 0);
        }

        return $duration;
    }

    public function stop(): void
    {
        if ($this->status !== 'running') {
            return;
        }

        $this->status = 'cancelled';

        // SIGTERM for graceful shutdown
        proc_terminate($this->process, SIGTERM);

        // Wait up to 5 seconds
        for ($i = 0; $i < 50; $i++) {
            $status = proc_get_status($this->process);
            if (!$status['running']) {
                break;
            }
            usleep(100000);
        }

        // Force kill if still running
        if ($status['running']) {
            proc_terminate($this->process, SIGKILL);
        }

        $this->exitCode = proc_close($this->process);
    }

    public function isComplete(): bool
    {
        return in_array($this->status, ['completed', 'failed', 'cancelled']);
    }

    public function getInfo(): array
    {
        return [
            'job_id' => $this->jobId,
            'pid' => $this->pid,
            'status' => $this->status,
            'progress' => $this->progress,
            'input_path' => $this->inputPath,
            'output_path' => $this->outputPath,
            'start_time' => $this->startTime,
            'elapsed_seconds' => microtime(true) - $this->startTime,
            'exit_code' => $this->exitCode,
        ];
    }
}
```

### 3.5 TranscodeManager Implementation

```php
// src/Media/Transcoding/TranscodeManager.php

namespace Phlix\Media\Transcoding;

use Phlix\Common\Database\Connection;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Streaming\StreamState;

class TranscodeManager
{
    private Connection $db;
    private FfmpegRunner $ffmpeg;
    private EncodingHelper $encodingHelper;
    private string $transcodeDir;
    private string $segmentDir;
    private array $activeJobs = [];
    private array $deviceProfiles = [];
    private StructuredLogger $logger;

    private const MAX_CONCURRENT_TRANSCODES = 4;
    private const TRANSCODE_TIMEOUT = 7200; // 2 hours
    private const CLEANUP_INTERVAL = 300; // 5 minutes
    private const MAX_TRANSCODE_AGE = 3600; // 1 hour

    public function __construct(
        Connection $db,
        FfmpegRunner $ffmpeg,
        EncodingHelper $encodingHelper,
        string $transcodeDir,
        string $segmentDir,
        StructuredLogger $logger
    ) {
        $this->db = $db;
        $this->ffmpeg = $ffmpeg;
        $this->encodingHelper = $encodingHelper;
        $this->transcodeDir = $transcodeDir;
        $this->segmentDir = $segmentDir;
        $this->logger = $logger;

        // Load device profiles
        $this->loadDeviceProfiles();

        // Start cleanup timer
        Timer::add(self::CLEANUP_INTERVAL, function () {
            $this->cleanupStaleTranscodes();
        });

        // Register signal handlers for graceful shutdown
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'shutdown']);
            pcntl_signal(SIGCHLD, [$this, 'handleChildExit']);
        }
    }

    private function loadDeviceProfiles(): void
    {
        $profiles = include __DIR__ . '/../../config/device_profiles.php';
        foreach ($profiles as $name => $config) {
            $this->deviceProfiles[$name] = DeviceProfile::fromArray($config);
        }
    }

    public function startTranscode(StreamState $state, array $options = []): string
    {
        // Check concurrent transcode limit
        $activeCount = count(array_filter($this->activeJobs, fn($j) => $j->status === 'running'));
        if ($activeCount >= self::MAX_CONCURRENT_TRANSCODES) {
            // Wait in queue
            $this->waitForTranscodeSlot();
        }

        $jobId = $this->generateUuid();
        $item = $this->getMediaItem($state->mediaItemId);

        // Determine output path
        $outputDir = "{$this->transcodeDir}/{$jobId}";
        mkdir($outputDir, 0755, true);

        // Get device profile
        $profileName = $options['device_profile'] ?? 'generic';
        $profile = $this->deviceProfiles[$profileName] ?? $this->deviceProfiles['generic'];

        // Probe source
        $sourceInfo = $this->ffmpeg->probe($item['path']);

        // Determine encoding parameters
        $encodingParams = $this->encodingHelper->getEncodingParams($sourceInfo, $profile, $options);

        // Create output file path
        $container = $encodingParams['container'] ?? 'ts';
        $outputPath = "{$outputDir}/output.{$container}";

        // Build FFmpeg command
        $command = $this->buildFfmpegCommand($item['path'], $outputPath, $encodingParams);

        // Create process
        $process = new TranscodeProcess($jobId, $item['path'], $outputPath, $command);
        $process->start();

        // Store job info
        $this->activeJobs[$jobId] = $process;

        // Store in database for recovery
        $this->db->query(
            "INSERT INTO transcode_jobs (id, stream_state_id, input_path, output_path, status, started_at)
             VALUES (?, ?, ?, ?, ?, NOW())",
            [$jobId, $state->id, $item['path'], $outputPath, 'running']
        );

        $this->logger->info('Transcode started', [
            'job_id' => $jobId,
            'item_id' => $state->mediaItemId,
            'profile' => $profileName,
            'command' => $command,
        ]);

        // If HLS streaming, also create HLS segments
        if ($encodingParams['protocol'] === 'hls') {
            $hlsPath = $this->startHlsSegmenting($jobId, $item['path'], $profile, $options);
            $this->activeJobs[$jobId]->hlsPath = $hlsPath;
        }

        return $outputPath;
    }

    private function buildFfmpegCommand(string $inputPath, string $outputPath, array $params): string
    {
        $cmd = 'ffmpeg -y -hide_banner -loglevel error';

        // Input
        $cmd .= ' -i ' . escapeshellarg($inputPath);

        // Video codec
        if (isset($params['video_codec'])) {
            $cmd .= ' -c:v ' . $params['video_codec'];

            // Codec-specific options
            switch ($params['video_codec']) {
                case 'libx264':
                    $cmd .= ' -preset ' . ($params['preset'] ?? 'medium');
                    $cmd .= ' -crf ' . ($params['crf'] ?? 23);
                    $cmd .= ' -profile:v ' . ($params['profile'] ?? 'high');
                    $cmd .= ' -level:v ' . ($params['level'] ?? '4.2');
                    break;

                case 'libx265':
                    $cmd .= ' -preset ' . ($params['preset'] ?? 'medium');
                    $cmd .= ' -crf ' . ($params['crf'] ?? 28);
                    $cmd .= ' -profile:v ' . ($params['profile'] ?? 'main10');
                    break;

                case 'libvpx-vp9':
                    $cmd .= ' -b:v ' . ($params['video_bitrate'] ?? '4000k');
                    $cmd .= ' -crf ' . ($params['crf'] ?? 31);
                    $cmd .= ' -deadline good -cpu-used 2';
                    break;
            }
        }

        // Video filters
        if (!empty($params['video_filters'])) {
            $cmd .= ' -vf "' . implode(',', $params['video_filters']) . '"';
        }

        // Resolution
        if (isset($params['width']) && isset($params['height'])) {
            $scaleFilter = "scale={$params['width']}:{$params['height']}:force_original_aspect_ratio=decrease";
            if (!empty($params['pad'])) {
                $scaleFilter .= ",pad={$params['width']}:{$params['height']}:(ow-iw)/2:(oh-ih)/2";
            }
            $cmd .= ' -vf "' . $scaleFilter . '"';
        }

        // HDR metadata
        if (!empty($params['color_primaries'])) {
            $cmd .= ' -color_primaries ' . $params['color_primaries'];
        }
        if (!empty($params['color_trc'])) {
            $cmd .= ' -color_trc ' . $params['color_trc'];
        }
        if (!empty($params['colorspace'])) {
            $cmd .= ' -colorspace ' . $params['colorspace'];
        }

        // Audio codec
        if (isset($params['audio_codec'])) {
            $cmd .= ' -c:a ' . $params['audio_codec'];
            $cmd .= ' -b:a ' . ($params['audio_bitrate'] ?? '128k');
            $cmd .= ' -ar ' . ($params['audio_sample_rate'] ?? 48000);

            if (isset($params['audio_channels'])) {
                $cmd .= ' -ac ' . $params['audio_channels'];
            }
        }

        // Subtitle handling
        if (!empty($params['burn_subtitle'])) {
            $cmd .= ' -vf "subtitles=' . escapeshellarg($params['subtitle_path']) . '"';
        }

        // Output format
        if (!empty($params['format'])) {
            $cmd .= ' -f ' . $params['format'];
        }

        // Container-specific flags
        if (($params['container'] ?? '') === 'mp4') {
            $cmd .= ' -movflags +faststart';
        }

        // Performance
        $cmd .= ' -threads 0';

        // Output
        $cmd .= ' ' . escapeshellarg($outputPath);

        return $cmd;
    }

    private function startHlsSegmenting(string $jobId, string $inputPath, DeviceProfile $profile, array $options): string
    {
        $outputDir = "{$this->segmentDir}/{$jobId}";
        mkdir($outputDir, 0755, true);

        // Get quality levels
        $qualityLevels = $this->encodingHelper->getHlsQualityLevels($profile, $options);

        $cmd = 'ffmpeg -y -hide_banner -loglevel error';
        $cmd .= ' -i ' . escapeshellarg($inputPath);

        // Video codec
        $cmd .= ' -c:v libx264 -preset medium -crf 23 -sc_threshold 0';
        $cmd .= ' -g 60 -keyint_min 60 -hls_time 6';
        $cmd .= ' -hls_playlist_type vod';

        // Map streams
        $streamMap = [];
        foreach ($qualityLevels as $i => $level) {
            $cmd .= sprintf(
                ' -map v:0 -map a:0 -b:v:%d %d -maxrate:v:%d %d -bufsize:v:%d %d',
                $i, $level['bitrate'], $i, $level['maxrate'], $i, $level['bufsize']
            );
            $streamMap[] = "v:{$i},a:{$i}";
        }
        $cmd .= ' -var_stream_map "' . implode(' ', $streamMap) . '"';

        // Output pattern
        $cmd .= ' -hls_segment_filename "' . $outputDir . '/segment_%v_%03d.ts"';
        $cmd .= ' "' . $outputDir . '/stream_%v.m3u8"';

        // Start process
        $process = proc_open($cmd, [[], ['pipe', 'w'], ['pipe', 'w']], $pipes);

        // Create master playlist
        $masterPlaylist = "#EXTM3U\n#EXT-X-VERSION:3\n";
        foreach ($qualityLevels as $i => $level) {
            $masterPlaylist .= sprintf(
                "#EXT-X-STREAM-INF:BANDWIDTH=%d,RESOLUTION=%s,NAME=\"%s\"\nstream_%d.m3u8\n",
                $level['maxrate'],
                $level['resolution'],
                $level['name'],
                $i
            );
        }
        file_put_contents($outputDir . '/playlist.m3u8', $masterPlaylist);

        return $outputDir;
    }

    public function stopTranscode(string $jobId): void
    {
        if (!isset($this->activeJobs[$jobId])) {
            return;
        }

        $job = $this->activeJobs[$jobId];
        $job->stop();

        // Update database
        $this->db->query(
            "UPDATE transcode_jobs SET status = 'cancelled', completed_at = NOW() WHERE id = ?",
            [$jobId]
        );

        // Cleanup files
        $this->removeDirectory(dirname($job->outputPath));

        unset($this->activeJobs[$jobId]);

        $this->logger->info('Transcode cancelled', ['job_id' => $jobId]);
    }

    private function cleanupStaleTranscodes(): void
    {
        $now = time();

        foreach ($this->activeJobs as $jobId => $job) {
            $age = $now - $job->startTime;

            // Force stop if too old
            if ($age > self::MAX_TRANSCODE_AGE && $job->status === 'running') {
                $this->logger->warning('Forcing stale transcode to stop', [
                    'job_id' => $jobId,
                    'age_seconds' => $age,
                ]);
                $this->stopTranscode($jobId);
            }
        }

        // Also cleanup orphaned directories
        $this->cleanupOrphanedDirectories();
    }

    private function cleanupOrphanedDirectories(): void
    {
        $activeDirs = array_map(
            fn($job) => dirname($job->outputPath),
            $this->activeJobs
        );

        $transcodeDirs = glob($this->transcodeDir . '/*', GLOB_ONLYDIR);
        foreach ($transcodeDirs as $dir) {
            if (!in_array($dir, $activeDirs)) {
                $this->removeDirectory($dir);
            }
        }
    }

    public function getActiveTranscodes(): array
    {
        return array_map(fn($job) => $job->getInfo(), $this->activeJobs);
    }

    public function getTranscodeStatus(string $jobId): ?array
    {
        return $this->activeJobs[$jobId]?->getInfo();
    }
}
```

---

## 4. Streaming Protocols

### 4.1 Supported Protocols

| Protocol | Description | Browser Support | Native Playback |
|----------|-------------|-----------------|-----------------|
| HLS | HTTP Live Streaming (m3u8) | Safari, Edge | Yes |
| DASH | Dynamic Adaptive Streaming | Chrome, Firefox | Via MSE |
| Progressive | Direct file download | All | Yes (after load) |
| WebSocket | Low-latency real-time | All | Via library |
| DLNA | UPnP/DLNA protocol | No | Via device |

### 4.2 HLS Implementation

```php
// src/Media/Streaming/HlsStreamer.php

namespace Phlix\Media\Streaming;

class HlsStreamer
{
    private string $baseUrl;
    private TranscodeManager $transcodeManager;
    private Connection $db;

    public function __construct(
        string $baseUrl,
        TranscodeManager $transcodeManager,
        Connection $db
    ) {
        $this->baseUrl = $baseUrl;
        $this->transcodeManager = $transcodeManager;
        $this->db = $db;
    }

    /**
     * Generate playback info for HLS streaming
     */
    public function getPlaybackInfo(string $itemId, string $sessionId, array $options = []): array
    {
        $item = $this->getMediaItem($itemId);
        $session = $this->getSession($sessionId);

        // Get or create transcode job
        $transcodeJob = $this->getOrCreateTranscodeJob($itemId, $sessionId, $options);

        // Generate/determine stream URL
        if ($transcodeJob['status'] === 'running') {
            // Return HLS playlist URL
            $playlistUrl = "{$this->baseUrl}/hls/{$transcodeJob['id']}/playlist.m3u8";

            return [
                'method' => 'transcode',
                'protocol' => 'HLS',
                'container' => 'mpegts',
                'url' => $playlistUrl,
                'transcode_job_id' => $transcodeJob['id'],
                'stream_info' => [
                    'bitrate' => $this->getTargetBitrate($options),
                    'resolution' => $this->getTargetResolution($options),
                    'codec' => 'h264',
                ],
            ];
        }

        // Direct play
        return [
            'method' => 'direct',
            'protocol' => 'HTTP',
            'container' => $item['container'],
            'url' => "{$this->baseUrl}/media/{$itemId}/stream",
            'supports_seeking' => true,
        ];
    }

    /**
     * Handle HLS segment request
     */
    public function getSegment(string $jobId, int $segmentNumber, ?int $variant = null): Response
    {
        $job = $this->transcodeManager->getTranscodeStatus($jobId);

        if (!$job || !isset($job['hlsPath'])) {
            return new Response(404, [], 'Segment not found');
        }

        $segmentPath = $job['hlsPath'];

        // If variant specified, use that subdirectory
        if ($variant !== null) {
            $segmentPath .= "/stream_{$variant}_{$segmentNumber}.ts";
        } else {
            // Find in any variant
            $found = false;
            foreach (glob($job['hlsPath'] . '/segment_*_' . sprintf('%03d', $segmentNumber) . '.ts') as $file) {
                $segmentPath = $file;
                $found = true;
                break;
            }
            if (!$found) {
                return new Response(404, [], 'Segment not found');
            }
        }

        if (!file_exists($segmentPath)) {
            return new Response(404, [], 'Segment file not found');
        }

        return new Response(200, [
            'Content-Type' => 'video/mp2t',
            'Cache-Control' => 'public, max-age=31536000',
        ], file_get_contents($segmentPath));
    }

    /**
     * Get master HLS playlist
     */
    public function getMasterPlaylist(string $jobId): Response
    {
        $job = $this->transcodeManager->getTranscodeStatus($jobId);

        if (!$job || !isset($job['hlsPath'])) {
            return new Response(404, [], 'Playlist not found');
        }

        $playlistPath = $job['hlsPath'] . '/playlist.m3u8';

        if (!file_exists($playlistPath)) {
            return new Response(404, [], 'Playlist not found');
        }

        $content = file_get_contents($playlistPath);

        // Modify URLs to point to our segment endpoint
        $content = preg_replace(
            '/stream_(\d+)\.m3u8/',
            "/hls/{$jobId}/variant/$1/playlist.m3u8",
            $content
        );

        return new Response(200, [
            'Content-Type' => 'application/vnd.apple.mpegurl',
            'Cache-Control' => 'no-cache',
        ], $content);
    }

    /**
     * Get variant playlist (specific quality level)
     */
    public function getVariantPlaylist(string $jobId, int $variant): Response
    {
        $job = $this->transcodeManager->getTranscodeStatus($jobId);

        if (!$job || !isset($job['hlsPath'])) {
            return new Response(404, [], 'Playlist not found');
        }

        $playlistPath = "{$job['hlsPath']}/stream_{$variant}.m3u8";

        if (!file_exists($playlistPath)) {
            return new Response(404, [], 'Variant playlist not found');
        }

        $content = file_get_contents($playlistPath);

        // Modify segment URLs to point to our segment endpoint
        $content = preg_replace(
            '/segment_\d+_(\d+)\.ts/',
            "/hls/{$jobId}/segment/{$variant}/$1.ts",
            $content
        );

        return new Response(200, [
            'Content-Type' => 'application/vnd.apple.mpegurl',
            'Cache-Control' => 'no-cache',
        ], $content);
    }
}
```

### 4.3 Direct File Streaming

```php
// src/Media/Streaming/DirectStreamer.php

namespace Phlix\Media\Streaming;

class DirectStreamer
{
    private string $mediaBasePath;
    private Connection $db;

    public function __construct(string $mediaBasePath, Connection $db)
    {
        $this->mediaBasePath = $mediaBasePath;
        $this->db = $db;
    }

    /**
     * Handle direct file streaming with range support
     */
    public function stream(string $itemId, Request $request): Response
    {
        $item = $this->getMediaItem($itemId);

        if (!$item) {
            return new Response(404, [], 'Media not found');
        }

        $filePath = $this->mediaBasePath . '/' . $item['path'];

        if (!file_exists($filePath)) {
            return new Response(404, [], 'File not found');
        }

        $fileSize = filesize($filePath);
        $contentType = $this->getMimeType($item['container']);

        // Check for Range header
        $range = $request->getHeader('Range');

        if ($range) {
            return $this->handleRangeRequest($filePath, $fileSize, $contentType, $range);
        }

        // Full file request
        return $this->handleFullRequest($filePath, $fileSize, $contentType);
    }

    private function handleRangeRequest(
        string $filePath,
        int $fileSize,
        string $contentType,
        string $rangeHeader
    ): Response {
        // Parse Range header: bytes=start-end
        preg_match('/bytes=(\d+)-(\d*)/', $rangeHeader, $matches);

        $start = (int)$matches[1];
        $end = !empty($matches[2]) ? (int)$matches[2] : $fileSize - 1;

        // Validate range
        if ($start > $end || $start >= $fileSize) {
            return new Response(416, [
                'Content-Range' => "bytes */{$fileSize}",
            ], 'Range Not Satisfiable');
        }

        // Read range
        $length = $end - $start + 1;
        $handle = fopen($filePath, 'rb');
        fseek($handle, $start);
        $data = fread($handle, $length);
        fclose($handle);

        return new Response(206, [
            'Content-Type' => $contentType,
            'Content-Length' => $length,
            'Content-Range' => "bytes {$start}-{$end}/{$fileSize}",
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'public, max-age=31536000',
        ], $data);
    }

    private function handleFullRequest(
        string $filePath,
        int $fileSize,
        string $contentType
    ): Response {
        // For small files or initial load, send full file
        return new Response(200, [
            'Content-Type' => $contentType,
            'Content-Length' => $fileSize,
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'public, max-age=31536000',
        ], file_get_contents($filePath));
    }

    private function getMimeType(string $container): string
    {
        return match ($container) {
            'mkv' => 'video/x-matroska',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'ts' => 'video/mp2t',
            'avi' => 'video/x-msvideo',
            'mov' => 'video/quicktime',
            'wmv' => 'video/x-ms-wmv',
            'm4v' => 'video/x-m4v',
            default => 'application/octet-stream',
        };
    }
}
```

---

## 5. Device Profile System

### 5.1 Device Profile Structure

```php
// src/Media/Streaming/DeviceProfile.php

namespace Phlix\Media\Streaming;

class DeviceProfile
{
    public string $name;
    public string $id;
    public array $supportedMediaTypes = ['Video', 'Audio', 'Photo'];
    public int $maxStreamingBitrate = 100000000; // 100 Mbps
    public int $maxStaticBitrate = 100000000;
    public array $directPlayProfiles = [];
    public array $transcodingProfiles = [];
    public array $containerProfiles = [];
    public array $codecProfiles = [];

    public static function fromArray(array $data): self
    {
        $profile = new self();
        $profile->name = $data['Name'] ?? 'Unknown';
        $profile->id = $data['Id'] ?? uniqid('profile_');
        $profile->maxStreamingBitrate = $data['MaxStreamingBitrate'] ?? 100000000;
        $profile->maxStaticBitrate = $data['MaxStaticBitrate'] ?? 100000000;
        $profile->supportedMediaTypes = $data['SupportedMediaTypes'] ?? ['Video', 'Audio', 'Photo'];
        $profile->directPlayProfiles = $data['DirectPlayProfiles'] ?? [];
        $profile->transcodingProfiles = $data['TranscodingProfiles'] ?? [];
        $profile->containerProfiles = $data['ContainerProfiles'] ?? [];
        $profile->codecProfiles = $data['CodecProfiles'] ?? [];
        return $profile;
    }

    public function canDirectPlay(array $mediaSource): bool
    {
        // Check container
        $container = $mediaSource['container'] ?? '';
        if (!$this->isContainerSupported($container)) {
            return false;
        }

        // Check video codec
        $videoCodec = $mediaSource['video_codec'] ?? '';
        if (!$this->isVideoCodecSupported($videoCodec)) {
            return false;
        }

        // Check audio codec
        $audioCodec = $mediaSource['audio_codec'] ?? '';
        if (!$this->isAudioCodecSupported($audioCodec)) {
            return false;
        }

        // Check bitrate
        $bitrate = $mediaSource['bitrate'] ?? 0;
        if ($bitrate > $this->maxStreamingBitrate) {
            return false;
        }

        // Check resolution
        $width = $mediaSource['width'] ?? 0;
        $height = $mediaSource['height'] ?? 0;
        if (!$this->isResolutionSupported($width, $height)) {
            return false;
        }

        return true;
    }

    private function isContainerSupported(string $container): bool
    {
        foreach ($this->directPlayProfiles as $profile) {
            if ($profile['Type'] !== 'Video') continue;

            $containers = array_map('trim', explode(',', strtolower($profile['Container'] ?? '')));
            if (in_array(strtolower($container), $containers)) {
                return true;
            }
        }
        return false;
    }

    private function isVideoCodecSupported(string $codec): bool
    {
        foreach ($this->directPlayProfiles as $profile) {
            if ($profile['Type'] !== 'Video') continue;

            $codecs = array_map('trim', explode(',', strtolower($profile['VideoCodec'] ?? '')));
            if (empty($profile['VideoCodec']) || in_array(strtolower($codec), $codecs)) {
                return true;
            }
        }
        return false;
    }

    private function isAudioCodecSupported(string $codec): bool
    {
        foreach ($this->directPlayProfiles as $profile) {
            if ($profile['Type'] !== 'Video') continue;

            $codecs = array_map('trim', explode(',', strtolower($profile['AudioCodec'] ?? '')));
            if (empty($profile['AudioCodec']) || in_array(strtolower($codec), $codecs)) {
                return true;
            }
        }
        return false;
    }

    private function isResolutionSupported(int $width, int $height): bool
    {
        $maxResolution = $this->getMaxResolution();
        return $width <= $maxResolution['width'] && $height <= $maxResolution['height'];
    }

    public function getMaxResolution(): array
    {
        // Calculate based on max bitrate
        if ($this->maxStreamingBitrate >= 50000000) {
            return ['width' => 3840, 'height' => 2160]; // 4K
        } elseif ($this->maxStreamingBitrate >= 20000000) {
            return ['width' => 1920, 'height' => 1080]; // 1080p
        } elseif ($this->maxStreamingBitrate >= 8000000) {
            return ['width' => 1280, 'height' => 720]; // 720p
        } else {
            return ['width' => 854, 'height' => 480]; // 480p
        }
    }
}
```

### 5.2 Device Profile Definitions

```php
// config/device_profiles.php

return [
    // Generic web browser
    'web' => [
        'Name' => 'Web Browser',
        'MaxStreamingBitrate' => 100000000,
        'MaxStaticBitrate' => 100000000,
        'SupportedMediaTypes' => ['Video', 'Audio', 'Photo'],
        'DirectPlayProfiles' => [
            [
                'Container' => 'mp4,m4v,mkv,webm',
                'Type' => 'Video',
                'VideoCodec' => 'h264,hevc,vp9,av1',
                'AudioCodec' => 'aac,mp3,ac3,eac3,opus,flac',
            ],
            [
                'Container' => 'mp3,flac,aac,ogg,webma,wav',
                'Type' => 'Audio',
            ],
        ],
        'TranscodingProfiles' => [
            [
                'Container' => 'mp4',
                'Type' => 'Video',
                'VideoCodec' => 'h264',
                'AudioCodec' => 'aac',
            ],
            [
                'Container' => 'webm',
                'Type' => 'Video',
                'VideoCodec' => 'vp9',
                'AudioCodec' => 'opus',
            ],
        ],
    ],

    // Samsung Tizen Smart TV
    'samsung-tizen' => [
        'Name' => 'Samsung Tizen TV',
        'MaxStreamingBitrate' => 80000000,
        'MaxStaticBitrate' => 80000000,
        'SupportedMediaTypes' => ['Video', 'Audio'],
        'DirectPlayProfiles' => [
            [
                'Container' => 'mkv,mp4,webm',
                'Type' => 'Video',
                'VideoCodec' => 'h264,hevc,vp9',
                'AudioCodec' => 'aac,ac3,eac3,dts,flac',
            ],
        ],
        'TranscodingProfiles' => [
            [
                'Container' => 'ts',
                'Type' => 'Video',
                'VideoCodec' => 'h264',
                'AudioCodec' => 'aac,ac3',
            ],
            [
                'Container' => 'mp4',
                'Type' => 'Video',
                'VideoCodec' => 'h264',
                'AudioCodec' => 'aac',
            ],
        ],
        'CodecProfiles' => [
            [
                'Type' => 'Video',
                'Codec' => 'h264',
                'Conditions' => [
                    ['Condition' => 'HeightEqual', 'Value' => '1080', 'IsRequired' => false],
                    ['Condition' => 'WidthEqual', 'Value' => '1920', 'IsRequired' => false],
                ],
            ],
        ],
    ],

    // Roku TV
    'roku' => [
        'Name' => 'Roku',
        'MaxStreamingBitrate' => 30000000,
        'MaxStaticBitrate' => 30000000,
        'SupportedMediaTypes' => ['Video', 'Audio'],
        'DirectPlayProfiles' => [
            [
                'Container' => 'mp4,m4v,mkv',
                'Type' => 'Video',
                'VideoCodec' => 'h264,hevc',
                'AudioCodec' => 'aac,ac3,eac3,mp3,pcm',
            ],
        ],
        'TranscodingProfiles' => [
            [
                'Container' => 'ts',
                'Type' => 'Video',
                'VideoCodec' => 'h264',
                'AudioCodec' => 'aac,ac3',
            ],
        ],
    ],

    // iOS Safari
    'ios-safari' => [
        'Name' => 'iOS Safari',
        'MaxStreamingBitrate' => 20000000,
        'MaxStaticBitrate' => 20000000,
        'SupportedMediaTypes' => ['Video', 'Audio'],
        'DirectPlayProfiles' => [
            [
                'Container' => 'mp4,m4v',
                'Type' => 'Video',
                'VideoCodec' => 'h264,hevc',
                'AudioCodec' => 'aac,ac3',
            ],
        ],
        'TranscodingProfiles' => [
            [
                'Container' => 'ts',
                'Type' => 'Video',
                'VideoCodec' => 'h264',
                'AudioCodec' => 'aac',
            ],
        ],
    ],

    // Android
    'android' => [
        'Name' => 'Android',
        'MaxStreamingBitrate' => 40000000,
        'MaxStaticBitrate' => 40000000,
        'SupportedMediaTypes' => ['Video', 'Audio', 'Photo'],
        'DirectPlayProfiles' => [
            [
                'Container' => 'mkv,mp4,webm,3gp',
                'Type' => 'Video',
                'VideoCodec' => 'h264,h265,vp9,av1',
                'AudioCodec' => 'aac,mp3,ac3,eac3,opus,flac',
            ],
        ],
        'TranscodingProfiles' => [
            [
                'Container' => 'mp4',
                'Type' => 'Video',
                'VideoCodec' => 'h264',
                'AudioCodec' => 'aac',
            ],
        ],
    ],

    // Generic low-end mobile
    'mobile-low' => [
        'Name' => 'Low-end Mobile',
        'MaxStreamingBitrate' => 1500000,
        'MaxStaticBitrate' => 5000000,
        'SupportedMediaTypes' => ['Video', 'Audio'],
        'DirectPlayProfiles' => [
            [
                'Container' => 'mp4,m4v',
                'Type' => 'Video',
                'VideoCodec' => 'h264',
                'AudioCodec' => 'aac,mp3',
            ],
        ],
        'TranscodingProfiles' => [
            [
                'Container' => 'mp4',
                'Type' => 'Video',
                'VideoCodec' => 'h264',
                'AudioCodec' => 'aac',
            ],
        ],
    ],

    // Windows Desktop (direct file access)
    'windows' => [
        'Name' => 'Windows Desktop',
        'MaxStreamingBitrate' => 100000000,
        'MaxStaticBitrate' => 100000000,
        'SupportedMediaTypes' => ['Video', 'Audio', 'Photo'],
        'DirectPlayProfiles' => [
            [
                'Container' => '*',
                'Type' => 'Video',
                'VideoCodec' => '*',
                'AudioCodec' => '*',
            ],
            [
                'Container' => '*',
                'Type' => 'Audio',
            ],
        ],
    ],

    // Generic
    'generic' => [
        'Name' => 'Generic',
        'MaxStreamingBitrate' => 100000000,
        'MaxStaticBitrate' => 100000000,
        'SupportedMediaTypes' => ['Video', 'Audio', 'Photo'],
        'DirectPlayProfiles' => [
            [
                'Container' => 'mkv,mp4,avi,mov,wmv',
                'Type' => 'Video',
                'VideoCodec' => 'h264',
                'AudioCodec' => 'aac,mp3,ac3',
            ],
        ],
        'TranscodingProfiles' => [
            [
                'Container' => 'ts',
                'Type' => 'Video',
                'VideoCodec' => 'h264',
                'AudioCodec' => 'aac',
            ],
        ],
    ],
];
```

---

## 6. Session Management

### 6.1 Session Manager Implementation

```php
// src/Session/SessionManager.php

namespace Phlix\Session;

use Phlix\Common\Database\Connection;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Streaming\StreamManager;

class SessionManager
{
    private Connection $db;
    private StreamManager $streamManager;
    private StructuredLogger $logger;

    /** @var Session[] */
    private array $activeSessions = [];

    /** @var string[] WebSocket connections per session */
    private array $websocketConnections = [];

    public const EVENT_PLAYBACK_START = 'PlaybackStart';
    public const EVENT_PLAYBACK_STOP = 'PlaybackStopped';
    public const EVENT_PLAYBACK_PROGRESS = 'PlaybackProgress';
    public const EVENT_SESSION_NEW = 'SessionNew';
    public const EVENT_SESSION_END = 'SessionEnd';

    public function __construct(
        Connection $db,
        StreamManager $streamManager,
        StructuredLogger $logger
    ) {
        $this->db = $db;
        $this->streamManager = $streamManager;
        $this->logger = $logger;

        // Load active sessions from database
        $this->loadActiveSessions();
    }

    private function loadActiveSessions(): void
    {
        $sessions = $this->db->query(
            "SELECT s.*, u.username, u.display_name
             FROM sessions s
             JOIN users u ON s.user_id = u.id
             WHERE s.last_activity > DATE_SUB(NOW(), INTERVAL 1 HOUR)
             ORDER BY s.created_at DESC"
        );

        foreach ($sessions as $row) {
            $this->activeSessions[$row['id']] = new Session($row);
        }
    }

    public function createSession(string $userId, array $deviceInfo): string
    {
        $sessionId = $this->generateUuid();
        $deviceId = $deviceInfo['device_id'] ?? $this->generateDeviceId();
        $deviceName = $deviceInfo['device_name'] ?? 'Unknown Device';
        $deviceType = $deviceInfo['device_type'] ?? 'web';

        // Check for existing session with same device
        $existing = $this->db->query(
            "SELECT id FROM sessions WHERE user_id = ? AND device_id = ? AND last_activity > DATE_SUB(NOW(), INTERVAL 5 MINUTE)",
            [$userId, $deviceId]
        )[0] ?? null;

        if ($existing) {
            // Reactivate existing session
            $this->db->query(
                "UPDATE sessions SET last_activity = NOW() WHERE id = ?",
                [$existing['id']]
            );
            return $existing['id'];
        }

        // Create new session
        $this->db->query(
            "INSERT INTO sessions (id, user_id, device_id, device_name, device_type, created_at, last_activity)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())",
            [$sessionId, $userId, $deviceId, $deviceName, $deviceType]
        );

        // Get user info
        $user = $this->db->query("SELECT username, display_name FROM users WHERE id = ?", [$userId])[0];

        // Create in-memory session
        $session = new Session([
            'id' => $sessionId,
            'user_id' => $userId,
            'device_id' => $deviceId,
            'device_name' => $deviceName,
            'device_type' => $deviceType,
            'username' => $user['username'],
            'display_name' => $user['display_name'] ?? $user['username'],
            'created_at' => time(),
            'last_activity' => time(),
        ]);

        $this->activeSessions[$sessionId] = $session;

        $this->logger->info('Session created', [
            'session_id' => $sessionId,
            'user_id' => $userId,
            'device_id' => $deviceId,
        ]);

        // Emit event
        $this->emitEvent(self::EVENT_SESSION_NEW, $session->toArray());

        return $sessionId;
    }

    public function getSession(string $sessionId): ?Session
    {
        return $this->activeSessions[$sessionId] ?? null;
    }

    public function getUserSessions(string $userId): array
    {
        return array_filter(
            $this->activeSessions,
            fn(Session $s) => $s->userId === $userId
        );
    }

    public function getSessionByDeviceId(string $deviceId): ?Session
    {
        foreach ($this->activeSessions as $session) {
            if ($session->deviceId === $deviceId) {
                return $session;
            }
        }
        return null;
    }

    public function updateActivity(string $sessionId): void
    {
        if (!isset($this->activeSessions[$sessionId])) {
            return;
        }

        $this->activeSessions[$sessionId]->lastActivity = time();

        // Debounced database update
        $this->debouncedDbUpdate($sessionId);
    }

    public function endSession(string $sessionId): void
    {
        if (!isset($this->activeSessions[$sessionId])) {
            return;
        }

        $session = $this->activeSessions[$sessionId];

        // Stop any active playback
        if ($session->playbackState) {
            $this->streamManager->stopStream($session->playbackState['stream_id']);
        }

        // Close WebSocket connection if exists
        if (isset($this->websocketConnections[$sessionId])) {
            $this->websocketConnections[$sessionId]->close();
            unset($this->websocketConnections[$sessionId]);
        }

        // Remove from active sessions
        unset($this->activeSessions[$sessionId]);

        // Delete from database
        $this->db->query("DELETE FROM sessions WHERE id = ?", [$sessionId]);

        $this->logger->info('Session ended', ['session_id' => $sessionId]);

        // Emit event
        $this->emitEvent(self::EVENT_SESSION_END, ['session_id' => $sessionId]);
    }

    public function setPlaybackState(string $sessionId, array $playbackState): void
    {
        if (!isset($this->activeSessions[$sessionId])) {
            return;
        }

        $previousState = $this->activeSessions[$sessionId]->playbackState;
        $this->activeSessions[$sessionId]->playbackState = $playbackState;

        // Persist to database
        $this->db->query(
            "INSERT INTO playback_state (id, session_id, media_item_id, position_ticks, duration_ticks, playback_status, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                position_ticks = VALUES(position_ticks),
                duration_ticks = VALUES(duration_ticks),
                playback_status = VALUES(playback_status),
                updated_at = NOW()",
            [
                $this->generateUuid(),
                $sessionId,
                $playbackState['media_item_id'] ?? null,
                $playbackState['position_ticks'] ?? 0,
                $playbackState['duration_ticks'] ?? 0,
                $playbackState['status'] ?? 'stopped',
            ]
        );

        // Emit events
        if ($previousState === null && $playbackState !== null) {
            $this->emitEvent(self::EVENT_PLAYBACK_START, $playbackState);
        } elseif ($previousState !== null && $playbackState === null) {
            $this->emitEvent(self::EVENT_PLAYBACK_STOP, $previousState);
        } elseif ($playbackState !== null) {
            $this->emitEvent(self::EVENT_PLAYBACK_PROGRESS, $playbackState);
        }

        // Broadcast to user's other sessions
        $session = $this->activeSessions[$sessionId];
        $this->broadcastToUserSessions(
            $session->userId,
            $sessionId,
            'PlaybackStateChanged',
            $playbackState
        );
    }

    public function registerWebSocket(string $sessionId, $connection): void
    {
        $this->websocketConnections[$sessionId] = $connection;
    }

    public function broadcastToUserSessions(string $userId, string $excludeSessionId, string $event, array $data): void
    {
        $userSessions = $this->getUserSessions($userId);

        foreach ($userSessions as $session) {
            if ($session->id === $excludeSessionId) {
                continue;
            }

            if (isset($this->websocketConnections[$session->id])) {
                $message = json_encode([
                    'type' => $event,
                    'data' => $data,
                    'timestamp' => time(),
                ]);

                $this->websocketConnections[$session->id]->send($message);
            }
        }
    }

    public function getActiveSessionCount(): int
    {
        return count($this->activeSessions);
    }

    public function getAllActiveSessions(): array
    {
        return array_values($this->activeSessions);
    }

    private function emitEvent(string $event, array $data): void
    {
        // Store in event queue for async processing
        $this->db->query(
            "INSERT INTO session_events (event_type, event_data, created_at) VALUES (?, ?, NOW())",
            [$event, json_encode($data)]
        );

        // Also emit via WebSocket to admin sessions
        $adminSessions = array_filter(
            $this->activeSessions,
            fn(Session $s) => $s->userId === 'admin' // Or check user permissions
        );

        $message = json_encode([
            'type' => 'SessionEvent',
            'event' => $event,
            'data' => $data,
            'timestamp' => time(),
        ]);

        foreach ($adminSessions as $session) {
            if (isset($this->websocketConnections[$session->id])) {
                $this->websocketConnections[$session->id]->send($message);
            }
        }
    }
}
```

### 6.2 Session Class

```php
// src/Session/Session.php

namespace Phlix\Session;

class Session
{
    public string $id;
    public string $userId;
    public string $deviceId;
    public string $deviceName;
    public string $deviceType;
    public string $username;
    public string $displayName;
    public int $createdAt;
    public int $lastActivity;
    public ?array $playbackState = null;

    public function __construct(array $data)
    {
        $this->id = $data['id'];
        $this->userId = $data['user_id'];
        $this->deviceId = $data['device_id'];
        $this->deviceName = $data['device_name'] ?? 'Unknown';
        $this->deviceType = $data['device_type'] ?? 'web';
        $this->username = $data['username'] ?? '';
        $this->displayName = $data['display_name'] ?? $this->username;
        $this->createdAt = $data['created_at'] ?? time();
        $this->lastActivity = $data['last_activity'] ?? time();

        if (isset($data['playback_state'])) {
            $this->playbackState = is_array($data['playback_state'])
                ? $data['playback_state']
                : json_decode($data['playback_state'], true);
        }
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->userId,
            'device_id' => $this->deviceId,
            'device_name' => $this->deviceName,
            'device_type' => $this->deviceType,
            'username' => $this->username,
            'display_name' => $this->displayName,
            'created_at' => $this->createdAt,
            'last_activity' => $this->lastActivity,
            'playback_state' => $this->playbackState,
        ];
    }

    public function isActive(): bool
    {
        return (time() - $this->lastActivity) < 300; // 5 minutes
    }

    public function getElapsedTime(): int
    {
        return time() - $this->createdAt;
    }

    public function getIdleTime(): int
    {
        return time() - $this->lastActivity;
    }
}
```

---

## 7. API Endpoints

### 7.1 Authentication Endpoints

| Method | Path | Description |
|--------|------|-------------|
| POST | /auth/login | Authenticate user, returns JWT |
| POST | /auth/register | Create new user account |
| POST | /auth/logout | Invalidate session |
| POST | /auth/refresh | Refresh JWT token |
| POST | /auth/password-reset | Request password reset |
| POST | /auth/password-reset/confirm | Complete password reset |

#### POST /auth/login

**Request:**
```json
{
    "username": "user@example.com",
    "password": "secretpassword",
    "device_id": "device-uuid",
    "device_name": "Chrome on Windows",
    "device_type": "web"
}
```

**Response (200):**
```json
{
    "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
    "user": {
        "id": "user-uuid",
        "username": "user@example.com",
        "display_name": "John Doe"
    },
    "session_id": "session-uuid"
}
```

### 7.2 Session Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET | /Sessions | List active sessions for current user |
| POST | /Sessions | Create new session |
| GET | /Sessions/{id} | Get session details |
| DELETE | /Sessions/{id} | End session |
| POST | /Sessions/{id}/Playback | Start playback in session |
| POST | /Sessions/{id}/Playstate | Update play state |
| GET | /Sessions/{id}/Viewing | Get viewing activity |

#### POST /Sessions/Play

**Request:**
```json
{
    "item_id": "media-uuid",
    "start_position_ticks": 0,
    "media_source_id": "source-uuid",
    "audio_stream_index": 0,
    "subtitle_stream_index": -1,
    "play_method": "transcode",
    "device_profile": "samsung-tizen"
}
```

**Response (200):**
```json
{
    "session_id": "session-uuid",
    "playback_info": {
        "method": "transcode",
        "protocol": "HLS",
        "container": "mpegts",
        "url": "https://server/hls/job-id/playlist.m3u8",
        "transcode_job_id": "job-uuid",
        "stream_info": {
            "bitrate": 5000000,
            "resolution": "1920x1080",
            "codec": "h264"
        }
    },
    "media_item": {
        "id": "media-uuid",
        "name": "Movie Title",
        "type": "Movie",
        "run_time_ticks": 72000000000
    }
}
```

### 7.3 Playback Control Endpoints

| Method | Path | Description |
|--------|------|-------------|
| POST | /Playstate | Control playback (pause, stop, seek) |
| GET | /Videos/{id}/stream | Direct stream file |
| GET | /Videos/{id}/live.m3u8 | HLS playlist |
| GET | /Videos/{id}/main.m3u8 | Static HLS playlist |
| GET | /Videos/{id}//master.m3u8 | Master HLS playlist |

#### POST /Playstate

**Request:**
```json
{
    "session_id": "session-uuid",
    "command": "seek",
    "data": {
        "position_ticks": 36000000000
    }
}
```

**Commands:**
- `play` - Start/resume playback
- `pause` - Pause playback
- `stop` - Stop playback
- `seek` - Seek to position
- `next` - Next item in queue
- `previous` - Previous item in queue

### 7.4 Library Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET | /Library/VirtualFolders | List all libraries |
| POST | /Library/VirtualFolders | Create library |
| DELETE | /Library/VirtualFolders/{id} | Delete library |
| POST | /Library/VirtualFolders/{id}/refresh | Scan library |
| GET | /Library/Options | Get library options |
| PUT | /Library/Options | Update library options |

### 7.5 Item Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET | /Items | Get items with filters |
| GET | /Items/{id} | Get single item |
| GET | /Items/{id}/PlaybackInfo | Get playback info |
| GET | /Items/{id}/Images | Get image URLs |
| POST | /Items/{id}/UserData | Update user data |
| DELETE | /Items/{id} | Delete item |

#### GET /Items

**Query Parameters:**
- `parentId` - Parent folder ID
- `type` - Filter by type (Movie, Series, etc.)
- `limit` - Max results (default 100)
- `startIndex` - Offset for pagination
- `sortBy` - Sort field
- `sortOrder` - asc or desc
- `search` - Search query
- `genre` - Filter by genre
- `year` - Filter by year
- `ids` - Specific IDs to retrieve

### 7.6 User Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET | /Users | List all users (admin) |
| GET | /Users/Me | Get current user |
| PUT | /Users/Me | Update current user |
| GET | /Users/{id} | Get user by ID (admin) |
| POST | /Users | Create user (admin) |
| DELETE | /Users/{id} | Delete user (admin) |
| GET | /Users/{id}/Views | Get user's library views |

---

## 8. Database Schema

```sql
-- Users table
CREATE TABLE users (
    id CHAR(36) PRIMARY KEY,
    username VARCHAR(255) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(255),
    primary_server_id CHAR(36),
    is_admin BOOLEAN DEFAULT FALSE,
    is_hidden BOOLEAN DEFAULT FALSE,
    max_streams INT DEFAULT 0,
    max_bitrate INT DEFAULT 0,
    policy_json JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    INDEX idx_username (username),
    INDEX idx_email (email)
);

-- User settings
CREATE TABLE user_settings (
    user_id CHAR(36) PRIMARY KEY,
    max_streams INT DEFAULT 3,
    max_bitrate INT DEFAULT 100000000,
    preferred_audio_language VARCHAR(10) DEFAULT 'en',
    preferred_subtitle_language VARCHAR(10) DEFAULT 'en',
    subtitle_mode ENUM('always', 'only_foreign', 'none') DEFAULT 'only_foreign',
    audio_boost INT DEFAULT 100,
    video_quality_id VARCHAR(50) DEFAULT 'auto',
    media_folders_json JSON,
   内向な FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Media items
CREATE TABLE media_items (
    id CHAR(36) PRIMARY KEY,
    library_id CHAR(36) NOT NULL,
    parent_id CHAR(36),
    guid VARCHAR(500),
    imdb_id VARCHAR(50),
    tmdb_id INT,
    tvdb_id INT,
    name VARCHAR(500) NOT NULL,
    original_title VARCHAR(500),
    path VARCHAR(1000) NOT NULL,
    path_hash VARCHAR(64),
    metadata_language VARCHAR(10) DEFAULT 'en',
    metadata_country_code VARCHAR(10) DEFAULT 'US',
    type ENUM('movie', 'series', 'season', 'episode', 'music', 'album', 'artist', 'video', 'audio', 'book', 'photo', 'collection', 'folder', 'playlist', 'boxset') NOT NULL,
    container VARCHAR(50),
    format_name VARCHAR(50),
    format_version VARCHAR(50),
    size BIGINT DEFAULT 0,
    duration_ticks BIGINT,
    container_size BIGINT,
    video_size BIGINT,
    bitrate INT,
    video_codec VARCHAR(50),
    audio_codec VARCHAR(50),
    video_width INT,
    video_height INT,
    video_scan_type VARCHAR(20),
    video_framerate FLOAT,
    video_layer INT,
    video_bitrate INT,
    audio_bitrate INT,
    audio_channels INT,
    audio_sample_rate INT,
    audio_layout VARCHAR(50),
    is_3d BOOLEAN DEFAULT FALSE,
    is_hd BOOLEAN DEFAULT TRUE,
    is_sd BOOLEAN DEFAULT FALSE,
    is_hdr BOOLEAN DEFAULT FALSE,
    hdr_format VARCHAR(50),
    production_year INT,
    premiered DATE,
    official_rating VARCHAR(50),
    overview TEXT,
    short_overview TEXT,
    tagline VARCHAR(500),
    votes INT,
    rating FLOAT,
    cumulative_rating FLOAT,
    imdb_rating FLOAT,
    metadata_json JSON,
    images_json JSON,
    providers_json JSON,
    series_name VARCHAR(500),
    series_id CHAR(36),
    season_id CHAR(36),
    season_number INT,
    episode_number INT,
    season_display_order INT DEFAULT 1,
    episode_display_order INT DEFAULT 1,
    album VARCHAR(500),
    artist VARCHAR(500),
    album_artist VARCHAR(500),
    track_number INT,
    disc_number INT,
    disc_subtitle VARCHAR(500),
    chapters_json JSON,
    date_created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    date_modified TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    date_last_refreshed TIMESTAMP,
    FOREIGN KEY (library_id) REFERENCES libraries(id) ON DELETE CASCADE,
    INDEX idx_parent (parent_id),
    INDEX idx_type (type),
    INDEX idx_series (series_id),
    INDEX idx_tmdb (tmdb_id),
    INDEX idx_tvdb (tvdb_id),
    INDEX idx_path_hash (path_hash),
    FULLTEXT idx_name (name),
    FULLTEXT idx_overview (overview)
);

-- Media streams
CREATE TABLE media_streams (
    id CHAR(36) PRIMARY KEY,
    media_item_id CHAR(36) NOT NULL,
    stream_index INT NOT NULL,
    stream_type ENUM('video', 'audio', 'subtitle', 'chapter') NOT NULL,
    codec VARCHAR(50),
    codec_tag VARCHAR(20),
    language VARCHAR(10),
    language_tag VARCHAR(10),
    display_title VARCHAR(200),
    is_external BOOLEAN DEFAULT FALSE,
    is_default BOOLEAN DEFAULT FALSE,
    is_forced BOOLEAN DEFAULT FALSE,
    is_complex BOOLEAN DEFAULT FALSE,
    is_hearing_impaired BOOLEAN DEFAULT FALSE,
    is_text_subtitles_capable BOOLEAN DEFAULT TRUE,
    bitrate INT,
    channels INT,
    channel_layout VARCHAR(50),
    sample_rate INT,
    bits_per_sample INT,
    profile VARCHAR(50),
    level VARCHAR(20),
    width INT,
    height INT,
    aspect_ratio VARCHAR(20),
    pixel_format VARCHAR(30),
    color_space VARCHAR(30),
    color_primaries VARCHAR(30),
    color_transfer VARCHAR(30),
    duration_ticks BIGINT,
    external_url VARCHAR(1000),
    external_subtitle_id CHAR(36),
    FOREIGN KEY (media_item_id) REFERENCES media_items(id) ON DELETE CASCADE,
    INDEX idx_media_item (media_item_id),
    INDEX idx_stream_type (stream_type),
    INDEX idx_language (language)
);

-- Libraries
CREATE TABLE libraries (
    id CHAR(36) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    type ENUM('movie', 'series', 'music', 'photo', 'video', 'book') NOT NULL,
    collection_type VARCHAR(50),
    paths_json JSON NOT NULL,
    refresh_mode ENUM('none', 'once', 'daily', 'weekly') DEFAULT 'once',
    refresh_interval INT DEFAULT 1440,
    last_refreshed TIMESTAMP,
    auto_refresh_interval INT,
    auto_refresh_times_json JSON,
    refresh_new_items BOOLEAN DEFAULT TRUE,
    is_hidden BOOLEAN DEFAULT FALSE,
    display_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Sessions
CREATE TABLE sessions (
    id CHAR(36) PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    device_id VARCHAR(255) NOT NULL,
    device_name VARCHAR(255),
    device_type VARCHAR(50),
    device_operating_system VARCHAR(100),
    browser_name VARCHAR(100),
    browser_version VARCHAR(50),
    capabilities_json JSON,
    ip_address VARCHAR(50),
    endpoint_client VARCHAR(50),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_device (device_id),
    INDEX idx_last_activity (last_activity)
);

-- Playback state
CREATE TABLE playback_state (
    id CHAR(36) PRIMARY KEY,
    session_id CHAR(36) NOT NULL,
    media_item_id CHAR(36) NOT NULL,
    position_ticks BIGINT DEFAULT 0,
    duration_ticks BIGINT,
    playback_status ENUM('playing', 'paused', 'stopped', 'idle') DEFAULT 'idle',
    audio_stream_index INT,
    subtitle_stream_index INT,
    volume_level INT DEFAULT 100,
    playback_rate FLOAT DEFAULT 1.0,
    repeat_mode ENUM('none', 'one', 'all') DEFAULT 'none',
    is_muted BOOLEAN DEFAULT FALSE,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (media_item_id) REFERENCES media_items(id) ON DELETE CASCADE,
    UNIQUE KEY unique_session_playback (session_id)
);

-- Transcode jobs
CREATE TABLE transcode_jobs (
    id CHAR(36) PRIMARY KEY,
    stream_state_id CHAR(36),
    session_id CHAR(36),
    media_item_id CHAR(36) NOT NULL,
    input_path VARCHAR(1000) NOT NULL,
    output_path VARCHAR(1000) NOT NULL,
    status ENUM('queued', 'running', 'completed', 'failed', 'cancelled') DEFAULT 'queued',
    progress_percent FLOAT DEFAULT 0,
    current_position_ticks BIGINT DEFAULT 0,
    error_message TEXT,
    process_id INT,
    started_at TIMESTAMP,
    completed_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_media_item (media_item_id)
);

-- API keys
CREATE TABLE api_keys (
    id CHAR(36) PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    name VARCHAR(255),
    key_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    last_used_at TIMESTAMP,
    is_active BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_key_hash (key_hash)
);

-- User data (watched, favorite, etc.)
CREATE TABLE user_data (
    id CHAR(36) PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    media_item_id CHAR(36) NOT NULL,
    is_watched BOOLEAN DEFAULT FALSE,
    is_favorite BOOLEAN DEFAULT FALSE,
    is_liked BOOLEAN DEFAULT NULL,
    playback_position_ticks BIGINT DEFAULT 0,
    playback_position_version INT DEFAULT 0,
    external_version_key VARCHAR(255),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (media_item_id) REFERENCES media_items(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_item (user_id, media_item_id)
);

-- Activity log
CREATE TABLE activity_log (
    id CHAR(36) PRIMARY KEY,
    user_id CHAR(36),
    event_type VARCHAR(100) NOT NULL,
    severity ENUM('debug', 'info', 'warn', 'error') DEFAULT 'info',
    short_description VARCHAR(500),
    details_json JSON,
    ip_address VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_event_type (event_type),
    INDEX idx_created_at (created_at)
);
```

---

## 9. Implementation Details

### 9.1 EncodingHelper Class

```php
// src/Media/Transcoding/EncodingHelper.php

namespace Phlix\Media\Transcoding;

use Phlix\Media\Streaming\DeviceProfile;

class EncodingHelper
{
    private array $qualityPresets = [
        'ultra_low' => ['width' => 426, 'height' => 240, 'bitrate' => 400000, 'audio_bitrate' => 64000],
        'low' => ['width' => 640, 'height' => 360, 'bitrate' => 800000, 'audio_bitrate' => 96000],
        'medium' => ['width' => 854, 'height' => 480, 'bitrate' => 1400000, 'audio_bitrate' => 128000],
        'high' => ['width' => 1280, 'height' => 720, 'bitrate' => 3000000, 'audio_bitrate' => 192000],
        'full_hd' => ['width' => 1920, 'height' => 1080, 'bitrate' => 5000000, 'audio_bitrate' => 256000],
        'uhd_4k' => ['width' => 3840, 'height' => 2160, 'bitrate' => 20000000, 'audio_bitrate' => 384000],
    ];

    public function getEncodingParams(array $sourceInfo, DeviceProfile $profile, array $options = []): array
    {
        $videoStream = $this->getVideoStream($sourceInfo);
        $audioStream = $this->getAudioStream($sourceInfo);
        $subtitleStream = $this->getSubtitleStream($sourceInfo);

        $targetQuality = $this->determineTargetQuality($videoStream, $profile);
        $params = [];

        // Determine if we need to transcode
        $needsVideoTranscode = $this->needsVideoTranscode($videoStream, $profile, $targetQuality);
        $needsAudioTranscode = $this->needsAudioTranscode($audioStream, $profile);
        $needsSubtitleBurn = $this->needsSubtitleBurn($subtitleStream, $profile, $options);

        // Container
        if ($needsVideoTranscode || $needsAudioTranscode) {
            $params['container'] = $this->selectContainer($profile, $options);
            $params['format'] = $params['container'] === 'mp4' ? 'mp4' : ($params['container'] === 'webm' ? 'webm' : 'mpegts');
        } else {
            $params['container'] = $this->getOriginalContainer($sourceInfo);
        }

        // Video encoding
        if ($needsVideoTranscode) {
            $params['video_codec'] = $this->selectVideoCodec($profile, $videoStream, $options);
            $params['width'] = $targetQuality['width'];
            $params['height'] = $targetQuality['height'];
            $params['video_bitrate'] = $targetQuality['bitrate'];

            // Add video filters
            $params['video_filters'] = [];

            // Scale filter (already handled by width/height)
            if ($videoStream['width'] !== $params['width'] || $videoStream['height'] !== $params['height']) {
                // May need padding for aspect ratio
                $params['pad'] = true;
            }

            // HDR tone mapping
            if ($this->isHdr($videoStream) && !$this->canPlayHdr($profile)) {
                $params['video_filters'][] = 'tone_map';
                $params['color_primaries'] = 'bt709';
                $params['color_trc'] = 'bt709';
                $params['colorspace'] = 'bt709';
            } elseif ($this->isHdr($videoStream)) {
                // Keep HDR
                $params['color_primaries'] = $videoStream['color_primaries'] ?? 'bt2020';
                $params['color_trc'] = $videoStream['color_transfer'] ?? 'smpte2084';
                $params['colorspace'] = $videoStream['colorspace'] ?? 'bt2020nc';
            }

            // Codec-specific settings
            $params['preset'] = $this->getPreset($options);
            $params['crf'] = $this->getCrf($params['video_codec']);
        } else {
            // Copy video stream
            $params['video_codec'] = 'copy';
        }

        // Audio encoding
        if ($needsAudioTranscode) {
            $params['audio_codec'] = $this->selectAudioCodec($profile, $audioStream);
            $params['audio_bitrate'] = $targetQuality['audio_bitrate'];
            $params['audio_channels'] = $this->getAudioChannels($profile, $audioStream);
            $params['audio_sample_rate'] = 48000;
        } else {
            $params['audio_codec'] = 'copy';
        }

        // Subtitle handling
        if ($needsSubtitleBurn && $subtitleStream) {
            $params['burn_subtitle'] = true;
            $params['subtitle_path'] = $subtitleStream['path'];
            // If burning subtitles, we need to transcode video
            if ($params['video_codec'] === 'copy') {
                $params['video_codec'] = 'libx264';
                $params['width'] = $targetQuality['width'];
                $params['height'] = $targetQuality['height'];
                $params['video_bitrate'] = $targetQuality['bitrate'];
            }
        }

        $params['protocol'] = $this->determineProtocol($profile, $params);

        return $params;
    }

    private function getVideoStream(array $sourceInfo): array
    {
        foreach ($sourceInfo['streams'] ?? [] as $stream) {
            if ($stream['codec_type'] === 'video') {
                return $stream;
            }
        }
        return [];
    }

    private function getAudioStream(array $sourceInfo): array
    {
        foreach ($sourceInfo['streams'] ?? [] as $stream) {
            if ($stream['codec_type'] === 'audio') {
                return $stream;
            }
        }
        return [];
    }

    private function getSubtitleStream(array $sourceInfo): ?array
    {
        foreach ($sourceInfo['streams'] ?? [] as $stream) {
            if ($stream['codec_type'] === 'subtitle') {
                return $stream;
            }
        }
        return null;
    }

    private function determineTargetQuality(array $videoStream, DeviceProfile $profile): array
    {
        $sourceWidth = $videoStream['width'] ?? 1920;
        $sourceHeight = $videoStream['height'] ?? 1080;
        $sourceBitrate = $videoStream['bitrate'] ?? 10000000;
        $sourceFps = $this->parseFrameRate($videoStream['r_frame_rate'] ?? '30/1');

        $maxResolution = $profile->getMaxResolution();
        $maxBitrate = $profile->maxStreamingBitrate;

        // Determine target quality level
        $targetHeight = min($sourceHeight, $maxResolution['height']);

        // Find appropriate quality preset
        foreach ($this->qualityPresets as $preset) {
            if ($preset['height'] === $targetHeight) {
                $quality = $preset;
                break;
            }
        }

        if (!isset($quality)) {
            // Interpolate or use next lower preset
            $quality = $this->qualityPresets['medium'];
        }

        // Adjust bitrate if source is lower
        if ($sourceBitrate < $quality['bitrate']) {
            $quality['bitrate'] = min($sourceBitrate, $maxBitrate);
        }

        // Cap at profile max
        $quality['bitrate'] = min($quality['bitrate'], $maxBitrate);

        // Adjust for frame rate (higher fps needs more bandwidth)
        if ($sourceFps > 30) {
            $quality['bitrate'] = (int)($quality['bitrate'] * 1.3);
        }

        return $quality;
    }

    private function needsVideoTranscode(array $videoStream, DeviceProfile $profile, array $targetQuality): bool
    {
        // Check if profile supports direct playback
        $videoCodec = $videoStream['codec_name'] ?? '';
        $container = $videoStream['codec_tag'] ?? '';

        // Check codec support
        if (!$this->isVideoCodecSupported($videoCodec, $profile)) {
            return true;
        }

        // Check resolution
        if (($videoStream['width'] ?? 0) > $profile->getMaxResolution()['width']) {
            return true;
        }

        // Check bitrate
        if (($videoStream['bitrate'] ?? 0) > $profile->maxStreamingBitrate) {
            return true;
        }

        // Check HDR support
        if ($this->isHdr($videoStream) && !$this->canPlayHdr($profile)) {
            return true;
        }

        // Check if we need to resize
        if (($videoStream['width'] ?? 0) > $targetQuality['width']) {
            return true;
        }

        return false;
    }

    private function needsAudioTranscode(array $audioStream, DeviceProfile $profile): bool
    {
        $audioCodec = $audioStream['codec_name'] ?? '';

        // Certain codecs need transcoding for compatibility
        $unsupportedCodecs = ['truehd', 'dtshd', 'dtsma', 'atmos'];
        if (in_array(strtolower($audioCodec), $unsupportedCodecs)) {
            return true;
        }

        // Check channel count
        $maxChannels = $this->getMaxAudioChannels($profile);
        if (($audioStream['channels'] ?? 2) > $maxChannels) {
            return true;
        }

        return false;
    }

    private function needsSubtitleBurn(?array $subtitleStream, DeviceProfile $profile, array $options): bool
    {
        if (!$subtitleStream) {
            return false;
        }

        // Check if we should burn subtitles
        $forceBurn = $options['subtitle_method'] === 'burn';
        $externalSub = $subtitleStream['is_external'] ?? false;

        // Burn if forced, or if external subtitles on certain devices
        if ($forceBurn) {
            return true;
        }

        // Some devices don't support external subtitles well
        if ($externalSub && $this->shouldBurnExternalSubtitles($profile)) {
            return true;
        }

        return false;
    }

    private function shouldBurnExternalSubtitles(DeviceProfile $profile): bool
    {
        // Devices that need burned subtitles
        $burnRequired = ['samsung-tizen', 'roku', 'lg-webos', 'android-low'];

        return in_array($profile->id, $burnRequired);
    }

    private function isVideoCodecSupported(string $codec, DeviceProfile $profile): bool
    {
        foreach ($profile->directPlayProfiles as $dp) {
            if ($dp['Type'] !== 'Video') continue;

            $codecs = array_map('trim', explode(',', strtolower($dp['VideoCodec'] ?? '')));
            if (empty($dp['VideoCodec']) || $dp['VideoCodec'] === '*' || in_array(strtolower($codec), $codecs)) {
                return true;
            }
        }
        return false;
    }

    private function isHdr(array $videoStream): bool
    {
        $colorTransfer = $videoStream['color_transfer'] ?? '';
        $colorPrimaries = $videoStream['color_primaries'] ?? '';

        $hdrTransfers = ['smpte2084', 'smpte2086', 'bt2020c', 'arib-std-b67'];
        $hdrPrimaries = ['bt2020nc', 'bt2020c'];

        return in_array($colorTransfer, $hdrTransfers) || in_array($colorPrimaries, $hdrPrimaries);
    }

    private function canPlayHdr(DeviceProfile $profile): bool
    {
        // HDR profiles should be able to handle HDR
        $hdrProfiles = ['samsung-tizen', 'lg-webos', 'firetv', 'android-tv'];
        return in_array($profile->id, $hdrProfiles);
    }

    private function selectVideoCodec(DeviceProfile $profile, array $videoStream, array $options): string
    {
        // Prefer H.264 for compatibility
        if ($this->canPlayHevc($profile) && $this->isHevcBeneficial($videoStream)) {
            return 'libx265';
        }

        // VP9 for web
        if ($profile->id === 'web' && $this->canPlayVp9()) {
            return 'libvpx-vp9';
        }

        // Default to H.264
        return 'libx264';
    }

    private function canPlayHevc(DeviceProfile $profile): bool
    {
        $hevcProfiles = ['samsung-tizen', 'lg-webos', 'firetv', 'apple-tv', 'ios-safari', 'android'];
        return in_array($profile->id, $hevcProfiles);
    }

    private function isHevcBeneficial(array $videoStream): bool
    {
        // HEVC beneficial for 4K or HDR content
        $width = $videoStream['width'] ?? 0;
        $height = $videoStream['height'] ?? 0;
        $isHdr = $this->isHdr($videoStream);
        $is4k = $width >= 3840 || $height >= 2160;

        return $is4k || $isHdr;
    }

    private function canPlayVp9(): bool
    {
        // Assumed from modern browsers
        return true;
    }

    private function selectAudioCodec(DeviceProfile $profile, array $audioStream): string
    {
        // Prefer AAC for compatibility
        $audioCodec = $audioStream['codec_name'] ?? '';

        // Keep original for passthrough if supported
        $passthroughCodecs = ['aac', 'mp3', 'ac3', 'eac3'];
        if (in_array(strtolower($audioCodec), $passthroughCodecs)) {
            return 'copy';
        }

        return 'aac';
    }

    private function selectContainer(DeviceProfile $profile, array $options): string
    {
        // HLS for streaming
        if (($options['protocol'] ?? '') === 'hls' || $this->isBrowserClient($profile)) {
            return 'ts';
        }

        // MP4 for progressive download
        return 'mp4';
    }

    private function isBrowserClient(DeviceProfile $profile): bool
    {
        return $profile->id === 'web';
    }

    private function getAudioChannels(DeviceProfile $profile, array $audioStream): int
    {
        $maxChannels = $this->getMaxAudioChannels($profile);
        $sourceChannels = $audioStream['channels'] ?? 2;

        return min($maxChannels, $sourceChannels);
    }

    private function getMaxAudioChannels(DeviceProfile $profile): int
    {
        // Default to stereo
        $maxChannels = 2;

        // Some profiles support surround sound
        $surroundProfiles = ['samsung-tizen', 'lg-webos', 'roku', 'apple-tv', 'firetv', 'android-tv'];
        if (in_array($profile->id, $surroundProfiles)) {
            $maxChannels = 6; // 5.1
        }

        // Check profile settings
        foreach ($profile->directPlayProfiles as $dp) {
            if ($dp['Type'] === 'Video' && isset($dp['AudioChannels'])) {
                $maxChannels = max($maxChannels, (int)$dp['AudioChannels']);
            }
        }

        return $maxChannels;
    }

    private function getPreset(array $options): string
    {
        // Speed vs quality tradeoff
        $speed = $options['encoding_speed'] ?? 'medium';

        return match ($speed) {
            'fastest' => 'ultrafast',
            'fast' => 'veryfast',
            'medium' => 'medium',
            'slow' => 'slow',
            'slowest' => 'veryslow',
            default => 'medium',
        };
    }

    private function getCrf(string $codec): int
    {
        // Lower CRF = higher quality = larger file
        return match ($codec) {
            'libx264' => 23,
            'libx265' => 28,
            'libvpx-vp9' => 31,
            default => 23,
        };
    }

    private function determineProtocol(DeviceProfile $profile, array $params): string
    {
        // HLS for most streaming scenarios
        if ($params['video_codec'] !== 'copy' || $params['audio_codec'] !== 'copy') {
            return 'hls';
        }

        // Check if HLS specifically requested
        if (($params['protocol_hint'] ?? '') === 'hls') {
            return 'hls';
        }

        // Progressive for direct play
        return 'http';
    }

    private function getOriginalContainer(array $sourceInfo): string
    {
        $format = $sourceInfo['format'] ?? [];
        $container = $format['format_name'] ?? '';

        return match ($container) {
            'matroska' => 'mkv',
            'mov,mp4,m4a,3gp,3g2,mj2' => 'mp4',
            'webm' => 'webm',
            'mpegts' => 'ts',
            'avi' => 'avi',
            'asf' => 'wmv',
            default => $container,
        };
    }

    private function parseFrameRate(string $fps): float
    {
        if (preg_match('/^(\d+)\/(\d+)$/', $fps, $matches)) {
            return $matches[1] / $matches[2];
        }
        return (float)$fps;
    }

    public function getHlsQualityLevels(DeviceProfile $profile, array $options = []): array
    {
        $maxBitrate = $profile->maxStreamingBitrate;

        // Generate quality levels
        $levels = [];

        if ($maxBitrate >= 20000000) {
            // 4K
            $levels[] = [
                'index' => 0,
                'name' => '4K',
                'bitrate' => 20000000,
                'maxrate' => 21400000,
                'bufsize' => 30000000,
                'resolution' => '3840x2160',
                'width' => 3840,
                'height' => 2160,
            ];
        }

        if ($maxBitrate >= 8000000) {
            // 1080p
            $levels[] = [
                'index' => $maxBitrate >= 20000000 ? 1 : 0,
                'name' => '1080p',
                'bitrate' => 5000000,
                'maxrate' => 5350000,
                'bufsize' => 7500000,
                'resolution' => '1920x1080',
                'width' => 1920,
                'height' => 1080,
            ];
        }

        if ($maxBitrate >= 3000000) {
            // 720p
            $index = ($maxBitrate >= 20000000) ? 2 : ($maxBitrate >= 8000000 ? 1 : 0);
            $levels[] = [
                'index' => $index,
                'name' => '720p',
                'bitrate' => 2500000,
                'maxrate' => 2675000,
                'bufsize' => 3750000,
                'resolution' => '1280x720',
                'width' => 1280,
                'height' => 720,
            ];
        }

        if ($maxBitrate >= 1000000) {
            // 480p
            $index = count($levels);
            $levels[] = [
                'index' => $index,
                'name' => '480p',
                'bitrate' => 1000000,
                'maxrate' => 1070000,
                'bufsize' => 1500000,
                'resolution' => '854x480',
                'width' => 854,
                'height' => 480,
            ];
        }

        return $levels;
    }
}
```

---

This completes the comprehensive Media Server technical specification. This document covers:

1. **System Architecture** - Full component diagram and request flow
2. **Media Processing Pipeline** - Detailed scanning, metadata, and processing flow
3. **Transcoding Engine** - Complete FFmpeg commands, process management, and decision matrix
4. **Streaming Protocols** - HLS, Direct, and segment delivery implementation
5. **Device Profile System** - Complete profile definitions for all platforms
6. **Session Management** - Full session lifecycle and playback state tracking
7. **API Endpoints** - Complete REST API specification
8. **Database Schema** - Full SQL schema with all tables
9. **Implementation Details** - Complete EncodingHelper with all logic

Would you like me to create the separate client platform plans as well?