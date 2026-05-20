# Step P.2 — Performance Benchmarks

**Phase:** P (Phase-end Audit & v1.0)
**Step:** P.2
**Depends on:** O.7 (Release process)
**Review:** No (benchmark tooling only)
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** general-purpose

## 1. Goal

Create benchmark tooling to measure Phlex's streaming performance. Document methodology for real-hardware testing. If real hardware is unavailable, report theoretical numbers and mark as "pending real hardware".

## 2. Context

Read first:
- `PHLEX_EXPANSION_PLAN.md` §13 v1.0 criteria: "50+ concurrent 1080p direct-play, 5+ concurrent 1080p→720p hwaccel"
- `config/ffmpeg.php` — existing transcode profiles
- `src/Media/Streaming/` — HlsStreamer, QualitySelector, StreamManager
- `src/Media/Transcoding/` — FfmpegRunner, EncodingHelper

## 3. Scope

### 3.1 Benchmark Scripts (scripts/bench/)

Create `scripts/bench/` directory with:

**`concurrent_streams.php`** — measures max concurrent direct-play streams
- Spawn N concurrent GET requests to /api/v1/media/{id}/stream
- Measure: streams that maintain ≥2Mbps without rebuffering
- Report: N succeeded / N failed / CPU / memory at each step

**`transcode_throughput.php`** — measures concurrent hardware-accelerated transcodes
- Launch N concurrent 1080p→720p transcodes using hwaccel
- Measure: time to first byte, frame rate, CPU/GPU utilization
- Report: N succeeded / N failed / avg frame rate at each step

**`stream_stress.php`** — sustained load test
- Run 50+ concurrent direct-play sessions for 30 minutes
- Measure: memory leak detection, CPU creep, dropped frames

### 3.2 README (scripts/bench/README.md)

Document:
- Hardware requirements (minimum 4-core CPU, HW accel GPU preferred)
- Test media library setup (specific file sizes/formats)
- How to run each benchmark
- Expected pass criteria per §13
- Known limitations if running in VM/container

### 3.3 Theoretical Numbers (if no real hardware)

Based on FFmpeg performance charts and known HW accel capabilities:
- Intel QSV: ~8-10 concurrent 1080p→720p on 4-core i7
- NVENC: ~15-20 concurrent 1080p→720p on RTX 3060
- VAAPI: ~5-8 concurrent 1080p→720p on AMD Vega iGPU
- Direct play: limited by network bandwidth, not CPU (~50+ from 4-core)

## 4. v1.0 Pass Criteria (from §13)

```
50+ concurrent 1080p direct-play from a 4-vCPU server
5+ concurrent 1080p→720p hwaccel transcode from a 4-vCPU+GPU server
```

## 5. Deliverables

- `scripts/bench/concurrent_streams.php`
- `scripts/bench/transcode_throughput.php`
- `scripts/bench/stream_stress.php`
- `scripts/bench/README.md`
- `plans/expansion/p.2-bench-results.md` (results or "pending hardware" declaration)

## 6. Acceptance Criteria

- [ ] All three benchmark scripts exist and are runnable
- [ ] README documents hardware requirements clearly
- [ ] Scripts output structured results (JSON or CSV)
- [ ] If real hardware unavailable: report explains why and provides theoretical baseline
- [ ] Real-hardware test results (or "pending") documented in p.2-bench-results.md

## 7. Git ritual

```bash
cd /home/sites/phlex
git checkout master && git pull --ff-only origin master
git checkout -b p.2-benchmarks
# ... create benchmark tooling ...
git add scripts/bench/
git commit -m "Step P.2: add streaming benchmark tooling"
unset GITHUB_TOKEN
gh pr create --title "Step P.2: streaming benchmark tooling" --body "Benchmark scripts for v1.0 performance criteria"
gh pr merge --squash --delete-branch
git checkout master && git pull --ff-only origin master
```
