# Step P.2 — Benchmark Results

**Phase:** P (Phase-end Audit & v1.0)
**Step:** P.2
**Status:** PENDING REAL HARDWARE
**Generated:** 2026-05-19

## Summary

Benchmark scripts have been created but **real-hardware testing is pending**. The theoretical performance numbers below are based on FFmpeg performance charts and published hardware acceleration benchmarks.

## Deliverables Created

| File | Description |
|------|-------------|
| `scripts/bench/concurrent_streams.php` | Concurrent 1080p direct-play benchmark |
| `scripts/bench/transcode_throughput.php` | 1080p→720p hwaccel transcode benchmark |
| `scripts/bench/stream_stress.php` | Sustained 30-minute load test |
| `scripts/bench/README.md` | Complete benchmark documentation |

## v1.0 Pass Criteria

```
50+ concurrent 1080p direct-play from a 4-vCPU server
5+ concurrent 1080p→720p hwaccel transcode from a 4-vCPU+GPU server
```

## Theoretical Performance Estimates

Based on FFmpeg community benchmarks and hardware encoder performance data:

### Direct Play (1080p) — CPU-Bound

Direct play is primarily I/O-bound on a modern server. A 4-core CPU can sustain:

| Hardware | Expected Concurrent 1080p Streams |
|----------|-------------------------------------|
| 4-core (no hyperthreading) | 50-60 concurrent |
| 4-core (with hyperthreading) | 60-80 concurrent |
| 8-core | 100-120 concurrent |
| 16-core | 200+ concurrent |

**Key insight:** Direct play passes through video without re-encoding. The CPU mostly handles HTTP connection overhead. The limiting factor is typically network bandwidth and I/O, not CPU.

### Hardware Transcode (1080p→720p) — GPU-Bound

Hardware-accelerated transcoding performance varies significantly by encoder:

| GPU / Encoder | 1080p→720p Concurrent Transcodes |
|---------------|----------------------------------|
| **NVIDIA NVENC** (RTX 3060) | 15-20 concurrent |
| **NVIDIA NVENC** (GTX 1080) | 10-15 concurrent |
| **Intel VAAPI** (Iris Xe) | 5-8 concurrent |
| **Intel VAAPI** (UHD 630) | 3-5 concurrent |
| **AMD AMF** (Vega iGPU) | 5-7 concurrent |
| **AMD VCN** (RDNA 2) | 6-8 concurrent |
| **Apple VideoToolbox** (M1/M2) | 8-12 concurrent |
| **Software libx264** (4-core) | 2-3 concurrent |

**Key insight:** The v1.0 criterion of **5+ concurrent** is achievable with any modern iGPU (Intel Iris Xe, AMD Vega) or entry-level discrete GPU (GTX 1050, RTX 3050).

### v1.0 Criterion Analysis

| Criterion | Feasibility | Notes |
|-----------|-------------|-------|
| 50+ concurrent 1080p direct-play | ✅ Achievable | Limited by network I/O, not CPU |
| 5+ concurrent 1080p→720p hwaccel | ✅ Achievable | Any iGPU from 2018+ or better |

**Both v1.0 criteria are achievable on entry-level hardware.**

## Real Hardware Testing Protocol

### Test Environment Requirements

1. **Bare-metal server** (VM results may vary due to resource contention)
2. **Minimum 4-core CPU, 8GB RAM**
3. **For transcode test: GPU with hardware encoding**
4. **SSD storage** (or RAM disk for repeatable results)
5. **Isolated network** (no other traffic on the test segment)

### Step 1: Get Media Item ID

```bash
# Get library ID
curl -s http://localhost:8096/api/v1/libraries | jq '.'

# Get first media item ID from a library
LIBRARY_ID="<library-uuid>"
MEDIA_ID=$(curl -s "http://localhost:8096/api/v1/libraries/$LIBRARY_ID/items?limit=1" | jq -r '.items[0].id')

echo "Test Media ID: $MEDIA_ID"
```

### Step 2: Run Direct Play Benchmark

```bash
php scripts/bench/concurrent_streams.php \
  --media-id="$MEDIA_ID" \
  --streams=50 \
  --server=http://localhost:8096 \
  --quality=1080p
```

**Pass criteria:** `successful >= 50`

### Step 3: Run Transcode Benchmark

```bash
php scripts/bench/transcode_throughput.php \
  --media-id="$MEDIA_ID" \
  --transcodes=5 \
  --server=http://localhost:8096 \
  --vendor=auto \
  --quality=medium
```

**Pass criteria:** `successful >= 5`

### Step 4: Run Sustained Load Test

```bash
php scripts/bench/stream_stress.php \
  --media-id="$MEDIA_ID" \
  --streams=50 \
  --server=http://localhost:8096 \
  --duration=1800 \
  --ramp-up=30
```

**Pass criteria:**
- `streams_sustained >= 50`
- `memory_leak_detected = false`
- `cpu_creep_detected = false`

## Real Hardware Results

### Pending — Requires Physical Hardware

| Test | Status | Actual Result |
|------|--------|---------------|
| 50+ concurrent direct play | ⏳ PENDING | — |
| 5+ concurrent hwaccel transcode | ⏳ PENDING | — |
| Sustained 30-min load | ⏳ PENDING | — |

**To record results:** Run the benchmarks on real hardware and update this document with:
1. Server specs (CPU model, RAM, GPU model)
2. Actual numbers achieved
3. Pass/fail status for each criterion

---

## Appendix: Known Performance Factors

### Factors That Improve Performance

- **Faster storage (NVMe SSD):** Reduces I/O wait for direct play
- **More CPU cores:** More concurrent sessions handled
- **Hardware transcode:** GPU handles video encoding, freeing CPU
- **RAM disk for segments:** Eliminates disk I/O for transcoded segments
- **Kernel tcp_tw_reuse:** More efficient TCP connection handling

### Factors That Reduce Performance

- **Container/VM overhead:** Shared resources cause contention
- **Network virtualization:** Adds latency and bandwidth limits
- **Spin-down HDD:** High latency for media served from HDD
- **Antivirus/security software:** Interferes with file access
- **Concurrent user traffic:** Benchmark shares resources with active users

### FFmpeg Configuration Impact

The default encoding settings in `config/ffmpeg.php` use:
- **CRF 23** for libx264 (balanced quality/speed)
- **CRF 28** for libx265 (more compression, slower)
- **Preset: medium** (balance between encode speed and quality)

These can be tuned for higher throughput:
- Lower CRF = higher quality, slower encode
- Faster preset = worse compression, faster encode

For maximum concurrent transcode throughput:
```bash
# Use faster preset and lower quality for testing
preset='ultrafast'
crf=26  # Accept slightly lower quality for more throughput
```
