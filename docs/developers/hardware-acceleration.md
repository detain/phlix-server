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

## Encoding Profiles

Each hardware vendor has a dedicated encoder profile class implementing `HwaccelEncoderProfileInterface`. These profiles map abstract quality levels to concrete FFmpeg encoder flags.

### Profile Classes

| Vendor | Class | Encoder | Notes |
|--------|-------|---------|-------|
| NVIDIA | `NvencProfile` | `h264_nvenc`, `hevc_nvenc` | Preset p1-p7, zerolatency tune |
| VAAPI | `VaapiProfile` | `h264_vaapi`, `hevc_vaapi` | CQP/VBR rate control |
| QSV | `QsvProfile` | `h264_qsv`, `hevc_qsv`, `av1_qsv` | Look-ahead support |
| VideoToolbox | `VideoToolboxProfile` | `h264_videotoolbox`, `hevc_videotoolbox` | macOS only |
| AMF | `AmfProfile` | `h264_amf`, `hevc_amf` | AMD GPUs |
| V4L2 | `V4L2Profile` | `h264_v4l2m2m`, `hevc_v4l2m2m` | Linux kernel API |
| Software | `SoftwareProfile` | `libx264`, `libx265` | CPU fallback |

### Quality Level Mapping

Each profile supports four quality levels with associated bitrate and preset settings:

```php
// Example: NVENC quality levels
'ultra'  => ['bitrate' => 8000000, 'preset' => 'p3', 'bframes' => 0],
'high'    => ['bitrate' => 5000000, 'preset' => 'p4', 'bframes' => 0],
'medium'  => ['bitrate' => 2500000, 'preset' => 'p5', 'bframes' => 0],
'low'     => ['bitrate' => 1000000, 'preset' => 'p6', 'bframes' => 0],
```

### Using the Profile Factory

```php
use Phlex\Media\Transcoding\Hwaccel\HwaccelRegistry;
use Phlex\Media\Transcoding\Hwaccel\HwaccelProfileFactory;

// Get the best profile for a vendor+codec combination
$registry = HwaccelRegistry::getInstance();
$factory = new HwaccelProfileFactory($registry);

$profile = $factory->getProfile('nvenc', 'h264');
$builder = $factory->createCommandBuilder('nvenc', 'h264', 'high');
```

### Using the Command Builder

```php
use Phlex\Media\Transcoding\Hwaccel\HwaccelCommandBuilder;
use Phlex\Media\Transcoding\Hwaccel\Profiles\NvencProfile;
use Phlex\Media\Transcoding\Hwaccel\HwaccelCapability;

$capability = new HwaccelCapability(
    vendor: 'nvenc',
    encoder: 'h264_nvenc',
    decoder: 'h264_cuvid',
    supports_hdr_tone_mapping: true,
    supported_codecs: ['h264', 'hevc'],
    supported_profiles: ['baseline', 'main', 'high'],
    max_resolution_w: 3840,
    max_resolution_h: 2160,
    max_bitrate: 50000000,
);

$cmd = (new HwaccelCommandBuilder(new NvencProfile(), $capability, 'high'))
    ->setInput('/input.mkv')
    ->setOutput('/output.mp4')
    ->setVideoCodec('h264')
    ->setBitrate(5000000)
    ->setResolution(1920, 1080)
    ->build();
```

### Max Concurrent Encodes

| Vendor | Max Concurrent | Notes |
|--------|----------------|-------|
| NVENC | 3 | Per GPU |
| VAAPI | 4 | Per GPU |
| QSV | 6 | Per GPU |
| VideoToolbox | 0 | Unlimited (Apple Silicon) |
| AMF | 2 | Per GPU |
| V4L2 | 1 | Limited hardware |
| Software | 0 | CPU-bound |

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
