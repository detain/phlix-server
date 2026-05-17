# Hardware Acceleration Guide

**Since:** 0.11.0

## Overview

Phlex Media Server supports hardware-accelerated transcoding via GPU encoders. The hardware acceleration system automatically detects available encoders (NVENC, VAAPI, QSV, VideoToolbox, AMF, V4L2) and provides a unified interface for selecting the best encoder for a given codec.

## Architecture

### Components

1. **HwaccelCapability** — Value object representing a hardware accelerator's capabilities
2. **HwaccelProbe** — Runs vendor-specific detection probes
3. **HwaccelRegistry** — Singleton holding probed capabilities
4. **Vendor Probes** — Each vendor (NVENC, VAAPI, etc.) has its own probe class

### Vendor Priority

Hardware vendors are prioritized for fallback selection. Lower values = higher priority:

```php
vendor_priority => [
    'nvenc' => 0,        // NVIDIA GPU (fastest, best quality)
    'vaapi' => 1,         // Linux VAAPI (Intel/AMD)
    'qsv' => 2,          // Intel Quick Sync
    'videotoolbox' => 3, // macOS VideoToolbox
    'amf' => 4,          // AMD GPU
    'v4l2' => 5,         // Video4Linux2 (limited)
]
```

## HwaccelCapability Fields

| Field | Type | Description |
|-------|------|-------------|
| `vendor` | string | Vendor identifier (e.g., 'nvenc', 'vaapi') |
| `encoder` | string | FFmpeg encoder name (e.g., 'h264_nvenc') |
| `decoder` | string | FFmpeg decoder name (e.g., 'hevc_cuvid') |
| `supports_hdr_tone_mapping` | bool | Whether HDR tone mapping is supported |
| `supported_codecs` | string[] | List of supported codecs |
| `supported_profiles` | string[] | List of supported profiles |
| `max_resolution_w` | int | Maximum width in pixels |
| `max_resolution_h` | int | Maximum height in pixels |
| `max_bitrate` | int | Maximum bitrate in bits per second |
| `extra_args` | array | Vendor-specific additional FFmpeg args |

## Usage

### Automatic Encoder Selection

```php
use Phlex\Media\Transcoding\Hwaccel\HwaccelRegistry;

// Get the best encoder for a codec
$capability = HwaccelRegistry::getInstance()->getEncoder('h264');

if ($capability !== null) {
    echo "Using encoder: " . $capability->encoder;
    echo "Vendor: " . $capability->vendor;
}
```

### With FfmpegRunner

```php
use Phlex\Media\Transcoding\FfmpegRunner;

// Probe hardware acceleration at startup
$runner = new FfmpegRunner();
$runner->probeHardwareAcceleration();

// Build a hardware-accelerated transcode command
$cmd = $runner->buildHwaccelCommand(
    inputPath: '/path/to/input.mkv',
    outputPath: '/path/to/output.mp4',
    codec: 'h264',
    params: ['crf' => 23]
);
```

### HDR Transcoding

```php
// Get encoder with HDR tone mapping support
$capability = HwaccelRegistry::getInstance()->getEncoder(
    'hevc',
    require_hdr_tone_map: true
);

if ($capability !== null && $capability->supports_hdr_tone_mapping) {
    // Use this encoder for HDR content
}
```

## Adding a New Vendor

1. Create a new class implementing `VendorProbeInterface` in `src/Media/Transcoding/Hwaccel/VendorProbe/`
2. Implement the 5 methods: `getVendorName()`, `isAvailable()`, `probe()`, `runAcceptanceTest()`
3. Register the probe in `HwaccelProbe::__construct()`
4. Add vendor priority in `config/hwaccel.php`

Example:

```php
namespace Phlex\Media\Transcoding\Hwaccel\VendorProbe;

use Phlex\Media\Transcoding\Hwaccel\HwaccelCapability;
use Phlex\Media\Transcoding\Hwaccel\VendorProbeInterface;
use Psr\Log\LoggerInterface;

class NewVendorProbe implements VendorProbeInterface
{
    private const VENDOR_NAME = 'newvendor';

    public function getVendorName(): string
    {
        return self::VENDOR_NAME;
    }

    public function isAvailable(): bool
    {
        // Detection logic
        return file_exists('/some/vendor/device');
    }

    public function probe(string $ffmpeg_path, ?LoggerInterface $logger = null): ?HwaccelCapability
    {
        if (!$this->isAvailable()) {
            return null;
        }

        // Return capability based on detection
        return new HwaccelCapability(
            vendor: self::VENDOR_NAME,
            encoder: 'h264_newvendor',
            decoder: 'hevc_newvendor',
            supports_hdr_tone_mapping: true,
            supported_codecs: ['h264', 'hevc'],
            supported_profiles: ['main', 'high'],
            max_resolution_w: 3840,
            max_resolution_h: 2160,
            max_bitrate: 40000000,
        );
    }

    public function runAcceptanceTest(string $ffmpeg_path, string $test_clip_path, ?LoggerInterface $logger = null): bool
    {
        // Run actual encode test
        return true;
    }
}
```

## Configuration

See `config/hwaccel.php` for configuration options:

- `enabled` — Enable/disable hardware acceleration
- `prefer_hardware` — Prefer hardware over software
- `vendor_priority` — Vendor fallback order
- `probe_timeout` — Timeout for probe operations
- `test_clip_path` — Path for acceptance test clip
- `fallback_to_software` — Allow software fallback

## Detection Methods by Vendor

| Vendor | Detection Method |
|--------|------------------|
| NVENC | `nvidia-smi` command |
| VAAPI | `/dev/dri` devices + `vainfo` |
| QSV | `vainfo` with Intel GPU |
| VideoToolbox | macOS + system_profiler |
| AMF | `vainfo` with AMD GPU |
| V4L2 | `/dev/media*` devices |
| Software | Always available (libx264) |

## Requirements

- FFmpeg compiled with hardware acceleration support
- Appropriate drivers/gpu for the vendor
- See [FFmpeg HWAccel Documentation](https://trac.ffmpeg.org/wiki/HWAccelIntro)
