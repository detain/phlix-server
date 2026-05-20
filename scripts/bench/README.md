# Phlex Streaming Benchmark Suite

Performance benchmarking tools for Phlex Media Server v1.0 streaming capabilities.

## Overview

This directory contains CLI tools to benchmark Phlex's streaming performance against the v1.0 release criteria defined in the [Phlex Expansion Plan §13](https://github.com/detain/phlex-server/blob/master/PHLEX_EXPANSION_PLAN.md#section-13-v10-criteria).

## v1.0 Pass Criteria

```
50+ concurrent 1080p direct-play from a 4-vCPU server
5+ concurrent 1080p→720p hwaccel transcode from a 4-vCPU+GPU server
```

## Prerequisites

### Hardware Requirements

| Criterion | Minimum | Recommended |
|-----------|---------|------------|
| CPU | 4 cores | 8+ cores |
| RAM | 8 GB | 16+ GB |
| GPU | None (direct-play) | NVIDIA GPU with NVENC, Intel VAAPI, or AMD AMF |
| Storage | SSD recommended | NVMe SSD |

**Direct-play criterion (50+ concurrent 1080p):** CPU is the primary bottleneck. A modern 4-core server can typically handle 50+ direct-play sessions since the work is mostly I/O-bound.

**Transcode criterion (5+ concurrent 1080p→720p hwaccel):** GPU is the primary bottleneck. Software encoding (libx264) on 4 cores can handle 2-3 concurrent transcodes. Hardware acceleration (NVENC/VAAPI/QSV) is required to reach 5+ concurrent.

### Software Requirements

- PHP 8.3+
- Extensions: `pcntl`, `curl`, `json`
- FFmpeg with hardware acceleration support (for transcode benchmarks)
- Media files in the library (see Test Media Setup below)

### Test Media Library Setup

For accurate benchmarks, your test library should contain:

1. **At least one 1080p H.264 media file** (5+ GB recommended for sustained streaming)
2. **Valid metadata in Phlex's database** so the `/api/v1/media/{id}` endpoint returns the file path

Example file naming conventions Phlex recognizes:
```
Movies/
  Movie Name (2024)/Movie Name (2024).mkv
TV Shows/
  Show Name/Season 01/Show Name - S01E01.mkv
```

The media item must be:
- Added to a library in Phlex
- Scanned and indexed
- Accessible via the API (`GET /api/v1/media/{id}`)

## Benchmark Scripts

### 1. concurrent_streams.php

Measures maximum concurrent 1080p direct-play streams.

**Usage:**
```bash
php scripts/bench/concurrent_streams.php \
  --media-id=<uuid> \
  --streams=50 \
  --server=http://localhost:8096 \
  --quality=1080p \
  --timeout=30
```

**Arguments:**
| Argument | Required | Default | Description |
|----------|----------|--------|-------------|
| `--media-id` | Yes | - | UUID of media item to stream |
| `--streams` | Yes | - | Number of concurrent streams (max: 500) |
| `--server` | No | http://localhost:8096 | Base server URL |
| `--timeout` | No | 30 | Request timeout per stream (seconds) |
| `--duration` | No | 60 | How long to sustain each stream (seconds) |
| `--quality` | No | 1080p | Quality profile: 1080p, 720p, 480p |
| `--help` | No | - | Show help message |

**Example Output:**
```json
{
  "benchmark": "concurrent_streams",
  "version": "1.0.0",
  "timestamp": "2026-05-19T12:00:00Z",
  "config": {
    "media_id": "abc-123",
    "server": "http://localhost:8096",
    "quality": "1080p",
    "num_streams": 50
  },
  "results": {
    "total_streams": 50,
    "successful": 48,
    "failed": 2,
    "success_rate": 0.96,
    "avg_response_time_ms": 145.3,
    "streams_sustained": 48
  },
  "pass": true,
  "criterion": "50+ concurrent 1080p direct-play"
}
```

**To run the §13 criterion test:**
```bash
# Get a media ID from your library
MEDIA_ID=$(curl -s http://localhost:8096/api/v1/libraries | jq -r '.libraries[0].items[0].id')

# Run the benchmark
php scripts/bench/concurrent_streams.php \
  --media-id="$MEDIA_ID" \
  --streams=50 \
  --server=http://localhost:8096
```

---

### 2. transcode_throughput.php

Measures concurrent 1080p→720p hardware-accelerated transcodes.

**Usage:**
```bash
php scripts/bench/transcode_throughput.php \
  --media-id=<uuid> \
  --transcodes=5 \
  --server=http://localhost:8096 \
  --vendor=nvenc \
  --quality=medium
```

**Arguments:**
| Argument | Required | Default | Description |
|----------|----------|--------|-------------|
| `--media-id` | Yes | - | UUID of media item to transcode |
| `--transcodes` | Yes | - | Number of concurrent transcodes (max: 50) |
| `--server` | No | http://localhost:8096 | Base server URL |
| `--output-dir` | No | /tmp/bench_transcodes | Directory for output files |
| `--timeout` | No | 300 | Maximum time per transcode (seconds) |
| `--vendor` | No | auto | Hardware vendor: nvenc, vaapi, qsv, videotoolbox, amf, v4l2, auto |
| `--quality` | No | medium | Quality preset: ultra, high, medium, low |
| `--help` | No | - | Show help message |

**Hardware Detection:**
The script auto-detects available hardware acceleration:
- `nvidia-smi` → NVIDIA GPU → h264_nvenc
- `/dev/dri/renderD*` → VAAPI → h264_vaapi
- Fallback → software libx264

**Example Output:**
```json
{
  "benchmark": "transcode_throughput",
  "version": "1.0.0",
  "timestamp": "2026-05-19T12:00:00Z",
  "config": {
    "media_id": "abc-123",
    "encoder": "h264_nvenc",
    "vendor": "nvenc",
    "num_transcodes": 5
  },
  "results": {
    "total_transcodes": 5,
    "successful": 5,
    "failed": 0,
    "success_rate": 1.0,
    "avg_time_to_first_byte_ms": 234.5,
    "avg_frame_rate_fps": 28.3,
    "concurrent_processes_peak": 5
  },
  "system": {
    "gpu_vendor": "NVIDIA",
    "encoder_detected": "h264_nvenc",
    "gpu_usage_pct": 89.2
  },
  "pass": true,
  "criterion": "5+ concurrent 1080p→720p hwaccel"
}
```

**To run the §13 criterion test:**
```bash
# Get a media ID from your library
MEDIA_ID=$(curl -s http://localhost:8096/api/v1/libraries | jq -r '.libraries[0].items[0].id')

# Run the benchmark
php scripts/bench/transcode_throughput.php \
  --media-id="$MEDIA_ID" \
  --transcodes=5 \
  --server=http://localhost:8096 \
  --vendor=auto \
  --quality=medium
```

---

### 3. stream_stress.php

Sustained load test to detect memory leaks, CPU creep, and degradation over time.

**Usage:**
```bash
php scripts/bench/stream_stress.php \
  --media-id=<uuid> \
  --streams=50 \
  --server=http://localhost:8096 \
  --duration=1800 \
  --ramp-up=30
```

**Arguments:**
| Argument | Required | Default | Description |
|----------|----------|--------|-------------|
| `--media-id` | Yes | - | UUID of media item to stream |
| `--streams` | Yes | - | Number of concurrent streams (max: 200) |
| `--server` | No | http://localhost:8096 | Base server URL |
| `--duration` | No | 1800 | Test duration in seconds (default: 30 min, max: 7200) |
| `--interval` | No | 60 | Health check interval (seconds) |
| `--timeout` | No | 30 | Request timeout per stream (seconds) |
| `--quality` | No | 1080p | Quality profile: 1080p, 720p, 480p |
| `--ramp-up` | No | 30 | Gradual stream ramp-up period (seconds) |
| `--help` | No | - | Show help message |

**Detection Algorithms:**

- **Memory Leak:** Memory grows >50% over test duration without CPU increase
- **CPU Creep:** Average CPU increases >20 percentage points over successive 5-minute windows
- **Dropped Streams:** Detected via health check timeouts

**Example Output:**
```json
{
  "benchmark": "stream_stress",
  "version": "1.0.0",
  "timestamp": "2026-05-19T12:00:00Z",
  "config": {
    "media_id": "abc-123",
    "num_streams": 50,
    "duration_seconds": 1800,
    "quality": "1080p"
  },
  "results": {
    "total_streams": 50,
    "streams_sustained": 48,
    "streams_dropped": 2,
    "memory_leak_detected": false,
    "cpu_creep_detected": false,
    "memory_growth_pct": 8.3,
    "cpu_trend_pct": 2.1
  },
  "pass": true,
  "criterion": "50+ concurrent 1080p direct-play for 30 minutes"
}
```

**To run the §13 criterion test:**
```bash
# Get a media ID from your library
MEDIA_ID=$(curl -s http://localhost:8096/api/v1/libraries | jq -r '.libraries[0].items[0].id')

# Run 30-minute sustained test
php scripts/bench/stream_stress.php \
  --media-id="$MEDIA_ID" \
  --streams=50 \
  --server=http://localhost:8096 \
  --duration=1800 \
  --ramp-up=30
```

---

## Known Limitations for VM/Container Testing

### Resource Contention

Running benchmarks inside a VM or container can produce misleading results due to:

1. **Virtualized CPU scheduling:** Host load affects guest performance unpredictably
2. **Memory overcommit:** Balloon drivers and swap can inflate memory metrics
3. **Network virtualization:** Virtual network interfaces add latency and limit bandwidth
4. **GPU passthrough required for hardware transcoding:** Without PCI passthrough, VAAPI/NVENC won't be available inside a container

### Recommendations for Accurate Results

| Environment | Recommendation |
|------------|----------------|
| Bare metal | Ideal for all benchmarks |
| VM (local) | Disable hyperthreading, pin vCPUs, reserve memory |
| VM (cloud) | Use dedicated/hosted instances (c5.2xlarge, etc.) |
| Container | Not recommended for hardware transcoding; OK for direct-play |
| Nested virtualization | Avoid — results are unreliable |

### Interpreting Results

**Pass criteria assume bare-metal or dedicated cloud instances.** Results from shared environments may show lower concurrency limits due to resource contention.

For VM testing, apply a correction factor:
- Direct-play: ~80% of bare-metal expected throughput
- Hardware transcode: ~60% of bare-metal (GPU scheduling overhead)

---

## Running All Benchmarks (Complete v1.0 Test Suite)

```bash
#!/bin/bash
# Complete v1.0 Performance Benchmark Suite

set -e

SERVER="${PHLEX_SERVER:-http://localhost:8096}"

echo "=========================================="
echo "Phlex v1.0 Performance Benchmark Suite"
echo "=========================================="
echo ""

# Get a test media ID
MEDIA_ID=$(curl -s "$SERVER/api/v1/libraries" | jq -r '.libraries[0].items[0].id')

if [ -z "$MEDIA_ID" ] || [ "$MEDIA_ID" = "null" ]; then
    echo "Error: Could not find a media item to test"
    exit 1
fi

echo "Test Media ID: $MEDIA_ID"
echo ""

# 1. Direct Play Concurrent Streams
echo "=========================================="
echo "Benchmark 1: Concurrent Direct Play"
echo "=========================================="
php scripts/bench/concurrent_streams.php \
  --media-id="$MEDIA_ID" \
  --streams=50 \
  --server="$SERVER"

echo ""

# 2. Hardware Transcode Throughput
echo "=========================================="
echo "Benchmark 2: Hardware Transcode Throughput"
echo "=========================================="
php scripts/bench/transcode_throughput.php \
  --media-id="$MEDIA_ID" \
  --transcodes=5 \
  --server="$SERVER" \
  --vendor=auto

echo ""

# 3. Sustained Load Test
echo "=========================================="
echo "Benchmark 3: Sustained Load (30 minutes)"
echo "=========================================="
php scripts/bench/stream_stress.php \
  --media-id="$MEDIA_ID" \
  --streams=50 \
  --server="$SERVER" \
  --duration=1800

echo ""
echo "=========================================="
echo "Benchmark Suite Complete"
echo "=========================================="
```

---

## Theoretical Performance Baselines

Based on FFmpeg performance charts and known hardware acceleration capabilities:

| Hardware Configuration | Direct Play (1080p) | Transcode (1080p→720p hwaccel) |
|-----------------------|---------------------|-------------------------------|
| 4-core CPU (no GPU) | 50-70 concurrent | 2-3 concurrent (software) |
| 4-core + NVIDIA GPU | 50+ concurrent | 8-15 concurrent (NVENC) |
| 4-core + Intel iGPU | 50+ concurrent | 5-8 concurrent (VAAPI) |
| 4-core + AMD iGPU | 50+ concurrent | 5-7 concurrent (AMF) |
| 8-core + NVIDIA GPU | 100+ concurrent | 15-20 concurrent (NVENC) |

**Note:** These are theoretical estimates. Actual performance depends on:
- Media file bitrate and codec
- Network bandwidth (for direct play)
- Storage I/O speed
- OS scheduling latency
- FFmpeg optimization flags

---

## Interpreting Results

### Pass/Fail Criteria

| Criterion | Pass | Fail |
|-----------|------|------|
| Direct Play | ≥50 successful concurrent streams | <50 successful streams |
| Hardware Transcode | ≥5 successful concurrent transcodes | <5 successful transcodes |
| Sustained Load | No memory leak, no CPU creep, ≥50 sustained | Either condition detected |

### Common Failure Modes

1. **"Connection reset" or "Timeout" errors:**
   - Server overwhelmed; reduce concurrency
   - Check CPU/memory under load

2. **High failure rate on transcode:**
   - GPU not detected; check driver installation
   - Verify FFmpeg was compiled with hardware support

3. **Memory leak detected:**
   - Bug in Phlex streaming code
   - Check for properly cleaned-up transcode processes

4. **CPU creep detected:**
   - Process pool not properly scaling down
   - Possible thread/memory leak in request handling

---

## Troubleshooting

### "Could not retrieve media path from server"

The media item doesn't exist in Phlex's database. Make sure:
1. The media file is in a watched library folder
2. Library scan has completed (`POST /api/v1/libraries/{id}/scan`)
3. The media item ID is correct

### "Failed to initialize curl"

PHP curl extension is not installed:
```bash
# Debian/Ubuntu
sudo apt-get install php-curl

# Alpine
apk add php-curl

# RHEL/CentOS
yum install php-curl
```

### "pcntl extension is required"

The pcntl extension is only available on Unix-like systems. For Windows testing, use Windows Subsystem for Linux (WSL) or run benchmarks from a container.

### "No hardware encoder found" (transcode benchmark)

1. Check if FFmpeg supports the encoder:
   ```bash
   ffmpeg -encoders | grep -E 'nvenc|vaapi|qsv'
   ```

2. For NVIDIA: Install NVIDIA drivers and check `nvidia-smi`

3. For VAAPI: Check `/dev/dri/renderD128` exists
   ```bash
   ls -la /dev/dri/
   ```
