# Comskip Integration for Live TV Recordings

**Phase:** F (Skip-Intro, Skip-Outro, Scene Markers)
**Feature:** Automatic commercial detection and chapter storage for Live TV recordings
**Since:** 0.12.0

## Overview

When a Live TV recording completes, Phlex can automatically run [Comskip](https://github.com/erikkaashoek/Comskip) to detect commercial breaks and store the detected segments as chapters. This gives users automatic commercial skip on their DVR recordings with zero additional configuration.

## What is Comskip?

Comskip is a third-party, open-source commercial detector for Linux, Windows, and macOS. It analyzes video files frame-by-frame to identify commercial segments based on multiple heuristics including:

- Scene change detection
- Silent audio intervals
- Aspect ratio changes
- Black frames

**Important:** Comskip is **not** bundled with Phlex. You must install it separately on your system.

## Installation

### Linux (Ubuntu/Debian)

```bash
# Clone and build from source
sudo apt-get install build-essential cmake ffmpeg
git clone https://github.com/erikkaashoek/Comskip.git
cd Comskip
mkdir build && cd build
cmake ..
make
sudo make install
```

The binary will be installed to `/usr/bin/comskip` by default.

### macOS

```bash
# Using Homebrew
brew install comskip
```

### Windows

Download the binary from the Comskip website or build from source using MSYS2 or WSL.

## Configuration

Comskip settings are in `config/comskip.php`:

```php
return [
    // Enable or disable comskip processing
    'enabled' => true,

    // Path to the comskip binary
    'comskip_path' => '/usr/bin/comskip',

    // Minimum commercial length in seconds (ignore shorter segments)
    'min_commercial_length' => 30,

    // Confidence threshold (0.0 - 1.0)
    // Segments below this confidence are ignored
    'require_confidence' => 0.7,

    // Run immediately after recording completes
    'post_process_immediately' => true,

    // EDL output directory (null = same dir as recording)
    'edl_output_dir' => null,
];
```

## How It Works

1. **Recording Completes**: When a Live TV recording finishes, the `Recorder` triggers post-complete callbacks.

2. **Comskip Detection**: If enabled, `ComskipRunner` checks if the binary is available and runs it on the recording file.

3. **EDL Parsing**: Comskip outputs an EDL (Edit Decision List) file with detected commercial segments.

4. **Chapter Storage**: `ComskipEdlParser` converts the EDL to `ChapterMarker` DTOs and stores them in `chapters_json`.

## EDL Format

Comskip EDL files use 3 tab-separated columns:

```
start_seconds  end_seconds  scene_type
```

**Scene types:**
- `0` = Cut (commercial segment)
- `1` = Mute
- `2` = Scene change
- `3` = Commercial (primary detection type)

Example:
```
0.0      45.0     3
120.0    180.0    3
360.0    420.0    0
```

## Troubleshooting

### Comskip not found

If you see `Comskip is not available at path: /usr/bin/comskip`:

1. Verify comskip is installed: `which comskip`
2. Verify it's executable: `ls -l $(which comskip)`
3. Update `config/comskip.php` with the correct path

### No chapters detected

1. Check the comskip log output in `storage/logs/`
2. Try lowering `min_commercial_length` (e.g., 20 seconds)
3. Verify the recording has clear commercial breaks (not all recordings do)
4. Try adjusting comskip's detection parameters in a custom ini file

### Performance

Comskip analysis can take several minutes for long recordings. This runs in the background and doesn't block the recording process.

## Technical Details

- **Hook System**: Uses `Recorder::onComplete()` callback mechanism
- **Idempotency**: If chapters already exist for a recording, comskip is skipped
- **Error Handling**: Errors in comskip processing don't affect the recording status
- **Storage**: Chapters stored in `media_items.chapters_json` column

## API

### POST /api/v1/livetv/recordings/{id}/process-comskip

Manually trigger comskip processing for a completed recording.

**Response:**
```json
{
  "success": true,
  "chapters_found": 5
}
```
