# Plan N.15 — Hardware Transcoding Setup

- **Step:** N.15
- **Phase:** N (End-User Documentation)
- **Depends on:** E.3 (HDR tone-map — already merged)
- **Review:** No (doc-only step)
- **Target:** `docs/advanced/hardware-transcoding.md`

## Goal

Write the hardware transcoding end-user guide at `docs/advanced/hardware-transcoding.md`, following the §7 layout: TL;DR → shell blocks → per-vendor driver checklist → what-can-go-wrong (3 failures) → next-steps.

## Prerequisite context (from E.1, E.2, E.3)

- Probe command: `php public/index.php hwaccel:probe` (diagnostic entry point)
- NVIDIA (NVENC + CUDA): nvidia-driver + nvidia-container-runtime + jellyfin-ffmpeg (stock FFmpeg lacks NVENC). Check: `nvidia-smi`. Env: `HWACCEL=nvidia` or `FFMPEG_HWACCEL=-hwaccel cuda`
- Intel (VAAPI + Quicksync): intel-media-driver + vainfo. Check: `vainfo | grep -E 'VAEntrypointEncSlice'`. Env: `HWACCEL=vaapi`. DRI: `/dev/dri/renderD128`
- AMD (VAAPI + VCN): amdgpu-driver + rocm. Check: `vainfo | grep 'VAEntrypointEncSlice'`. Env: `HWACCEL=vaapi`
- Apple Silicon (VideoToolbox): macOS only. Check: `VTDecoderHealthModelExist` / system_profiler
- HDR tone-map (NVIDIA): `FFMPEG_HWACCEL=-hwaccel cuda -vf tonemap_opencl=...`

## §7 layout tasks

### 1. TL;DR section
One-paragraph summary: hardware transcoding offloads encode/decode to GPU, required for 4K/HDR streams, supported vendors are NVIDIA/Intel/AMD/AppleSilicon. State what must be true before the guide is useful (driver installed, device visible, jellyfin-ffmpeg in use).

### 2. Shell blocks — `hwaccel:probe` + per-vendor verification commands
- `php public/index.php hwaccel:probe` output interpretation (what each field means)
- Per-vendor check commands:
  ```bash
  # NVIDIA
  nvidia-smi
  # Intel
  vainfo | grep -E 'VAEntrypointEncSlice'
  ls -la /dev/dri/renderD128
  # AMD
  vainfo | grep 'VAEntrypointEncSlice'
  # Apple Silicon
  system_profiler SPHardwareRAIDTool | grep -i VideoToolbox
  ```

### 3. Per-vendor driver checklist
One checklist per vendor (NVIDIA / Intel / AMD / Apple Silicon). Each item in the format:

```
[x] GPU physically installed and visible
[x] Correct driver installed (nvidia-driver | intel-media-driver | amdgpu-pro)
[x] Required device file present (/dev/dri/* or /dev/nvidia*)
[x] vainfo / nvidia-smi confirms encode capability
[x] jellyfin-ffmpeg (or patched ffmpeg) installed — stock ffmpeg will NOT work
[x] PHLEX_HWACCEL env var set correctly (nvidia | vaapi | videotoolbox)
[x] Transcode quality selector shows "Hardware" option
```

Add a note that `jellyfin-ffmpeg` is required because stock distro FFmpeg is compiled without hardware support.

### 4. Environment variable reference table
| Vendor | Env var | Value | FFmpeg override |
|---|---|---|---|
| NVIDIA | `PHLEX_HWACCEL` | `nvidia` | `FFMPEG_HWACCEL=-hwaccel cuda` |
| Intel | `PHLEX_HWACCEL` | `vaapi` | `FFMPEG_HWACCEL=-hwaccel vaapi` |
| AMD | `PHLEX_HWACCEL` | `vaapi` | `FFMPEG_HWACCEL=-hwaccel vaapi` |
| Apple Silicon | `PHLEX_HWACCEL` | `videotoolbox` | (macOS only) |

### 5. What-can-go-wrong (3 failure modes)

**Failure 1 — FFmpeg has no hardware support**
- Symptom: transcode falls back to software, "Hardware" option absent from quality selector
- Cause: stock distro ffmpeg compiled without NVENC/VAAPI/QSV
- Fix: replace with jellyfin-ffmpeg; verify with `ffmpeg -encoders 2>&1 | grep -E 'nvenc|vaapi'`

**Failure 2 — VAAPI device permission denied**
- Symptom: transcode fails with permission error, dmesg shows /dev/dri/renderD128 access denied
- Cause: user not in `video` group
- Fix: `sudo usermod -aG video phlex && sudo systemctl restart phlex`

**Failure 3 — NVIDIA GPU not visible inside container**
- Symptom: `nvidia-smi` works on host but fails inside container; transcode falls back to software
- Cause: nvidia-container-runtime not configured in docker/podman compose
- Fix: add `runtime: nvidia` to compose file, or set `NVIDIA_VISIBLE_DEVICES=all` env var

### 6. Next-steps
Link to E.3 (HDR tone-map) for NVIDIA HDR content; link to quality selector docs; link to FFmpeg transcoding tuning (E.2).

## Deliverable

File: `docs/advanced/hardware-transcoding.md`

## No code changes required — doc-only step.
