# Linux DVB-T USB Tuner Driver

## Overview

The DVB-T (Digital Video Broadcasting — Terrestrial) USB tuner driver enables ingestion of terrestrial TV streams from Linux DVB-T USB dongles. It interfaces with kernel DVB devices via `/dev/dvb/` and produces transport stream URLs for FFmpeg HLS packaging.

## Supported Hardware

- RTL2832U-based USB dongles (e.g., ezcap, FlightAware Pro Stick)
- Other chipsets with Linux DVB-T driver support
- Any device exposing `/dev/dvb/adapter*/frontend*`

## Architecture

```
LiveTvManager
├── HdHomeRunTunerDriver (SSDP discovery + HTTP API)
├── IptvTunerDriver (M3U playlist + XMLTV)
└── DvbtTunerDriver (Linux DVB API via /dev/dvb)
    ├── DvbtDevice (device descriptor)
    ├── DvbtDeviceScanner (/dev/dvb/ scanner)
    ├── DvbtSignalEngine (dvbv5-zap + FFmpeg)
    └── DvbtTunerDriverFactory (config builder)
```

## Linux DVB API

The Linux DVB API provides access to digital TV hardware through device nodes in `/dev/dvb/`:

### Device Structure

```
/dev/dvb/
├── adapter0/
│   ├── demux0       # Demultiplexer device
│   ├── dvr0         # Digital Video Recording device (for streaming)
│   ├── frontend0    # Tuner/frontend device
│   └── net0        # Network device (for DVR input)
├── adapter1/
│   └── ...
```

### Frontend Device

The `frontendN` device controls the tuner:
- Tunes to specific frequencies
- Reports signal strength, SNR, BER
- Provides access to transport stream via `/dev/dvb/adapterX/dvrN`

## Configuration

Add DVB-T configuration to `config/livetv.php`:

```php
'livetv' => [
    // ... existing hdhomerun config ...

    'dvbt' => [
        'enabled' => true,
        'ffmpeg_path' => '/usr/bin/ffmpeg',
        'dvbv5_zap_path' => '/usr/bin/dvbv5-zap',
        'default_modulation' => 'auto',
        'default_bandwidth_mhz' => 8,
    ],
],
```

### Configuration Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `enabled` | bool | `true` | Enable DVB-T tuner support |
| `ffmpeg_path` | string | `/usr/bin/ffmpeg` | Path to FFmpeg binary |
| `dvbv5_zap_path` | string | `/usr/bin/dvbv5-zap` | Path to dvbv5-zap binary |
| `default_modulation` | string | `auto` | Default modulation type |
| `default_bandwidth_mhz` | int | `8` | Default bandwidth in MHz |

## Modulation Types

DVB-T supports several modulation schemes:

| Modulation | Description | Typical Use |
|-----------|-------------|-------------|
| `QPSK` | Quadrature Phase-Shift Keying | Hierarchical modulation |
| `QAM64` | 64-state Quadrature Amplitude Modulation | Standard definition |
| `QAM256` | 256-state Quadrature Amplitude Modulation | High definition |
| `DVB-T` | COFDM with QPSK/QAM64/QAM256 | Legacy standard |
| `DVB-T2` | Extended COFDM | HD/SD with improved efficiency |
| `auto` | Let the driver decide | Recommended default |

## Frequency Ranges

DVB-T operates in VHF (174-230 MHz) and UHF (470-862 MHz) bands:

### European UHF Channel Frequencies

| Channel | Frequency (MHz) | Channel | Frequency (MHz) |
|--------|----------------|--------|----------------|
| 21 | 474 | 46 | 674 |
| 22 | 482 | 47 | 682 |
| 23 | 490 | 48 | 690 |
| 24 | 498 | 49 | 698 |
| 25 | 506 | 50 | 706 |
| ... | ... | ... | ... |
| 45 | 666 | 69 | 858 |

## dvbv5-zap Usage

The `dvbv5-zap` tool is the standard Linux DVB tuning utility:

```bash
# Tune to 474 MHz with auto modulation
dvbv5-zap -a 0 -f 474000000 -m auto -c /dev/null -d 0 -o output.ts

# Options:
#   -a <adapter>    Adapter index (0, 1, ...)
#   -f <freq>      Frequency in Hz
#   -m <mod>       Modulation (auto, QAM64, QAM256, QPSK, DVB-T, DVB-T2)
#   -c <config>    Config file (use /dev/null for defaults)
#   -d <frontend>  Frontend index
#   -o <output>   Output file or pipe
```

### Reading Signal Status

Signal information is available via sysfs:

```bash
# Signal strength (0-65535)
cat /sys/class/dvb/dvb0.frontend0/signal_strength

# SNR (0-65535)
cat /sys/class/dvb/dvb0.frontend0/snr

# Bit error rate
cat /sys/class/dvb/dvb0.frontend0/ber

# Uncorrected blocks
cat /sys/class/dvb/dvb0.frontend0/ucblocks
```

## FFmpeg HLS Packaging

The transport stream from DVB-T is repackaged to HLS for web playback:

```bash
# Read from DVR device and output HLS
ffmpeg -i /dev/dvb/adapter0/dvr0 \
  -c:v libx264 -c:a aac \
  -f hls -hls_time 4 -hls_list_size 6 \
  /var/www/html/livetv/stream.m3u8
```

## Class Reference

### DvbtDevice

Immutable descriptor for a DVB-T device:

```php
$device = new DvbtDevice(
    adapterPath: '/dev/dvb/adapter0',
    adapterIndex: 0,
    frontendIndex: 0,
    modulation: 'auto',
    frequencyMin: 470000000,
    frequencyMax: 862000000
);

// Get frontend device path
$frontendPath = $device->getFrontendPath();  // /dev/dvb/adapter0/frontend0

// Get DVR device path for streaming
$dvrPath = $device->getDvrPath();  // /dev/dvb/adapter0/dvr0

// Check if frequency is supported
$isSupported = $device->isFrequencySupported(474000000);
```

### DvbtDeviceScanner

Scans `/dev/dvb/` for available adapters:

```php
$scanner = new DvbtDeviceScanner($logger);
$devices = $scanner->scan();

foreach ($devices as $device) {
    echo "Found: {$device->adapterPath}\n";
}
```

### DvbtSignalEngine

Handles tuning and streaming:

```php
$engine = new DvbtSignalEngine(
    ffmpegPath: '/usr/bin/ffmpeg',
    dvbv5ZapPath: '/usr/bin/dvbv5-zap',
    logger: $logger
);

// Tune to frequency and get ingest URL
$ingestUrl = $engine->tune($device, 474000000, 'auto');

// Get stream URL for channel number
$streamUrl = $engine->getStreamUrl($device, 1);

// Probe signal strength
$signal = $engine->getSignalStrength($device);
// Returns: ['signal' => 50000, 'snr' => 45000, 'ber' => 0, 'ucblocks' => 0]
```

### DvbtTunerDriver

Implements `TunerDriverInterface` for LiveTvManager integration:

```php
$driver = DvbtTunerDriverFactory::build($config, $logger);

// Discover devices
$devices = $driver->discoverDevices();

// Get stream URL
$streamUrl = $driver->getStreamUrl($device, 21);  // Channel 21 = 474 MHz
```

### DvbtTunerDriverFactory

Factory for creating driver instances:

```php
// Build from config
$driver = DvbtTunerDriverFactory::build($livetvConfig, $logger);

// Build with explicit paths
$driver = DvbtTunerDriverFactory::buildDefault(
    ffmpegPath: '/usr/local/bin/ffmpeg',
    dvbv5ZapPath: '/usr/local/bin/dvbv5-zap',
    logger: $logger
);
```

## Integration with LiveTvManager

LiveTvManager automatically discovers and registers DVB-T tuners:

```php
$livetvConfig = include 'config/livetv.php';

// Create DVB-T driver if enabled
$dvbtDriver = DvbtTunerDriverFactory::build($livetvConfig, $logger);

// Create HDHomeRun driver (primary)
$hdhomerunDriver = HdHomeRunTunerDriverFactory::build($livetvConfig, $logger);

// Create LiveTvManager with multiple drivers
$manager = new LiveTvManager(
    $db,
    $channelManager,
    $guideManager,
    $recorder,
    $hdhomerunDriver,           // Primary driver
    $logger,
    [$dvbtDriver, $iptvDriver] // Additional drivers
);

// Discover all tuners
$tuners = $manager->discoverTuners();
```

## Database Schema

The `livetv_tuners` table stores tuner information with type `dvb_t`:

| Column | Type | Description |
|--------|------|-------------|
| tuner_id | VARCHAR(36) | Unique identifier (e.g., `dvbt_0_0`) |
| name | VARCHAR(255) | Display name |
| type | VARCHAR(20) | Tuner type (`dvb_t`, `hdhomerun`, `iptv`) |
| status | VARCHAR(20) | Current status |
| capabilities | JSON | Tuner capabilities including frequency range |
| discovered_at | DATETIME | Discovery timestamp |

## Error Handling

The driver handles common error cases:

- **No /dev/dvb**: Returns empty device list, logs warning
- **dvbv5-zap not found**: Returns direct DVR device path as fallback
- **Tuning failed**: Throws `RuntimeException`
- **Signal lost**: Returns 0 values for signal metrics

## Testing

Run the DVB-T tuner tests:

```bash
./vendor/bin/phpunit tests/unit/LiveTv/Tuners/Dvbt/
```

## See Also

- [HDHomeRun Tuner Driver](hdhomerun.md)
- [IPTV Tuner Driver](iptv.md)
- [Live TV Configuration](../reference/livetv.md)
- [Channel Manager](../reference/channel-manager.md)
- [Linux DVB API Documentation](https://www.kernel.org/doc/html/latest/userspace-api/media/index.html)
- [dvbv5-zap man page](https://manpages.debian.org/buster/dvb-tools/dvbv5-zap.1.en.html)
