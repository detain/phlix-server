# Subtitle Processing

**Scope**: Developer documentation for subtitle processing in Phlex Media Server

This document covers subtitle detection, extraction, soft-subtitling (external tracks), and hard-subtitling (burn-in) for devices that do not support external subtitle tracks.

---

## Overview

Phlex supports two subtitle rendering modes:

| Mode | Description | Use Case |
|------|-------------|----------|
| **Soft Subtitles** | External `.srt` / `.ass` / `.vtt` file served alongside video | Smart TVs, computers, mobile devices with full player support |
| **Hard Subtitles (Burn-in)** | Subtitles embedded directly in video stream | Devices that don't support external tracks (some smart TVs, game consoles, browsers) |

---

## Supported Formats

| Format | FFmpeg Codec | Font Styling | Notes |
|--------|-------------|--------------|-------|
| SRT | `srt` | Limited | Most compatible, no advanced styling |
| ASS/SSA | `ass` | Full | Advanced styling, positioned text, custom fonts |
| VTT | `webvtt` | Limited | Web-oriented |
| HDMV/PGS | `copy` | None | Blu-ray bitmap subtitles - copied only |

---

## Architecture

### Core Classes

```
src/Media/Transcoding/Subtitles/
├── SubtitleFormat.php          # Enum: SRT, ASS, SSA, VTT, HDMV
├── SubtitleTrack.php            # Immutable metadata for a subtitle track
├── SubtitleStyleOptions.php     # Styling options (font, size, color, position)
├── SubtitleBurner.php          # Main class for burn-in filter generation
└── SubtitleBurnerFactory.php   # Factory for vendor-specific burners
```

### Flow

1. **Detection**: `SubtitleBurner::detectSubtitleTracks($probeResult)` parses ffprobe output
2. **Extraction**: `SubtitleBurner::extractSubtitle($input, $index, $output)` extracts to file
3. **Burn-in**: `SubtitleBurner::getBurnInArgs($track, $vendor, $style)` generates FFmpeg args

---

## Hardware Vendor Support Matrix

| Vendor | Hardware Burn-in | Notes |
|--------|-----------------|-------|
| **NVENC** | No | Software `subtitles=` filter + `hwupload` |
| **VAAPI** | Limited | Uses `overlay_vaapi` in filter graph |
| **QSV** | Limited | `vpp submodule=subtitle` (limited support) |
| **VideoToolbox** | No | Software fallback only |
| **AMF** | No | Software fallback only |
| **V4L2** | No | Software fallback only |
| **Software** | Full | Full `libass` support |

### NVENC Special Handling

NVENC has no native subtitle support. The filter chain is:

```
subtitles=file.ass,hwupload=extra_hw_frames=4
```

This renders subtitles in software, then uploads the frames to GPU for encoding.

### VAAPI Handling

VAAPI uses `overlay_vaapi` for subtitle compositing:

```
-vaapi_device /dev/dri/renderD128 -vf overlay_vaapi,format=nv12
```

---

## Configuration

### config/subtitles.php

```php
return [
    'enabled' => true,
    'default_language' => 'eng',
    'burn_in_by_default' => false,  // true = burn unless disabled
    'extract_to_dir' => '/var/subtitles',
    'style' => [
        'font_name' => 'Arial',
        'font_size' => 24,
        'primary_color' => '&H00FFFFFF',  // ARGB white
        'outline_color' => '&H00000000', // ARGB black outline
        'outline_thickness' => 2,
        'position' => 'bottom',
        'margin' => 10,
    ],
];
```

---

## Usage

### Detecting Subtitle Tracks

```php
use Phlex\Media\Transcoding\Subtitles\SubtitleBurner;
use Phlex\Media\Transcoding\FfmpegRunner;

$ffmpeg = new FfmpegRunner();
$burner = new SubtitleBurner($ffmpeg);

$probeResult = $ffmpeg->probe('/path/to/video.mkv');
$tracks = $burner->detectSubtitleTracks($probeResult);

foreach ($tracks as $track) {
    echo "Track {$track->index}: {$track->label} ({$track->format->value})\n";
}
```

### Extracting a Subtitle Track

```php
$success = $burner->extractSubtitle(
    '/path/to/video.mkv',
    2,                  // stream index
    '/var/subtitles/eng.srt'
);
```

### Integrating with HwaccelCommandBuilder

```php
use Phlex\Media\Transcoding\Hwaccel\HwaccelCommandBuilder;
use Phlex\Media\Transcoding\Subtitles\SubtitleBurner;
use Phlex\Media\Transcoding\Subtitles\SubtitleStyleOptions;

$burner = new SubtitleBurner($ffmpeg);
$subtitleTrack = new SubtitleTrack(
    index: '2',
    language: 'eng',
    label: 'English',
    format: SubtitleFormat::SRT,
    path: '/var/subtitles/eng.srt'
);

$builder = new HwaccelCommandBuilder($profile, $capability, 'medium');
$builder
    ->setInput('/path/to/video.mkv')
    ->setOutput('/path/to/output.mp4')
    ->setSubtitleTrack($subtitleTrack)
    ->setSubtitleStyle(new SubtitleStyleOptions(font_size: 28))
    ->setSubtitleBurner($burner);

$cmd = $builder->build();
```

### Setting Burn-in Options via StreamManager

```php
$streamManager->setSubtitleBurnIn($streamId, subtitleIndex: 2, force: true);
```

---

## FFmpeg Filter Reference

### Software (libass)

```bash
# SRT with style
ffmpeg -i input.mkv -vf "subtitles=subs.srt:force_style='FontName=Arial,FontSize=24'" output.mp4

# ASS/SSA (inherits styles from file)
ffmpeg -i input.mkv -vf "ass=subs.ass" output.mp4
```

### VAAPI

```bash
ffmpeg -hwaccel vaapi -vaapi_device /dev/dri/renderD128 \
  -i input.mkv \
  -vf "overlay_vaapi,format=nv12" \
  -c:v h264_vaapi output.mp4
```

### NVENC (Software + Upload)

```bash
ffmpeg -i input.mkv \
  -vf "subtitles=subs.ass,hwupload=extra_hw_frames=4" \
  -c:v h264_nvenc output.mp4
```

---

## Client Considerations

When `burn_in_by_default: false` (default), soft subtitles are preferred and the player handles rendering. When `true`, all transcodes include burned-in subtitles unless explicitly disabled.

**Device-specific recommendations:**

- **Smart TVs (Samsung, LG, Sony)**: Enable burn-in - many don't support external tracks
- **Game consoles (PlayStation, Xbox)**: Enable burn-in
- **Mobile browsers**: Prefer soft subtitles (better quality, no re-encode)
- **Desktop browsers**: Prefer soft subtitles
- **Chromecast**: Prefer soft subtitles (unless casting to non-smart TV)

---

## See Also

- [FFmpeg Subtitles Filter Documentation](https://ffmpeg.org/ffmpeg-all.html#subtitles-1)
- [ASS/SSA Format Specification](https://www.python.org/) (Advanced Substation Alpha)
- [WebVTT Standard](https://www.w3.org/TR/webvtt/)
