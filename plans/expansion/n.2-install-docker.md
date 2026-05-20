# Step N.2 — Install phlex-server via Docker + docker-compose

**Phase:** N (End-User Documentation)
**Step:** N.2
**Depends on:** O.1 (Docker images — already merged)
**Review:** No (doc-only step)
**Target repo:** detain/phlex-server (local: /home/sites/phlex/)

## 1. Goal

Write the Docker installation guide at `docs/install/docker.md`, covering Docker image variants, docker-compose quick-start, volume mounts, environment variables, NVIDIA Docker runtime for hardware acceleration, port exposure, and three docker-compose.yml examples (server-only, server+hub, full-stack with reverse proxy).

## 2. Context (what already exists)

- `docker/Dockerfile` — base image from O.1 (`detain/phlex-server:latest`)
- `docker/examples/` — three docker-compose example stacks from O.2: `server-only/`, `server-hub/`, `full-stack/`
- `docs/install/linux.md` — Linux install guide from N.1 (sibling doc, uses same §7 layout)
- `docs/install/` directory and index created in N.0

## 3. Scope

### Create

- `docs/install/docker.md` — Docker installation guide

## 4. Doc content outline

### TL;DR (one screen)

- One-paragraph description: what phlex-server is, that this guide installs it via Docker in ~10 min
- Minimum requirements: Docker 20.10+, docker-compose v2 recommended, 2 CPU / 4 GB RAM
- Quick one-liner for the impatient:
  ```bash
  # 1-line spin-up (requires docker and docker-compose)
  curl -sSL https://raw.githubusercontent.com/detain/phlex-server/master/docker/examples/server-only/docker-compose.yml | \
    PHLEX_DB_PASSWORD=$(openssl rand -hex 16) \
    PHLEX_SECRET_KEY=$(openssl rand -hex 32) \
    docker-compose -f - up -d
  ```
- Image tags table: `detain/phlex-server:latest` (default), `:nvidia` (GPU/hwaccel), `:intel` (Quicksync)

### 1. Supported Docker variants

| Image tag | Use case | Hardware |
|----------|----------|----------|
| `detain/phlex-server:latest` | Generic x86_64, no HWaccel | Any 64-bit |
| `detain/phlex-server:nvidia` | NVIDIA GPU transcoding | NVIDIA GPU with driver 525+ |
| `detain/phlex-server:intel` | Intel Quick Sync Video | Intel CPUs with Quicksync (Gen 8+) |

Note: screenshots TBD — text-first guide, screenshots will be added in a follow-up.

### 2. Prerequisites

#### Install Docker Engine (Ubuntu/Debian)
```bash
sudo apt update
sudo apt install -y ca-certificates curl
sudo install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | \
  sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] \
  https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | \
  sudo tee /etc/apt/sources.list.d/docker.list
sudo apt update
sudo apt install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin
sudo usermod -aG docker $USER
newgrp docker
```

#### Install docker-compose v2 (standalone)
```bash
sudo curl -L "https://github.com/docker/compose/releases/download/v2.24.0/docker-compose-$(uname -s)-$(uname -m)" \
  -o /usr/local/bin/docker-compose
sudo chmod +x /usr/local/bin/docker-compose
docker-compose --version
```

### 3. Quick-start (server-only)

```bash
mkdir -p ~/phlex && cd ~/phlex
curl -O https://raw.githubusercontent.com/detain/phlex-server/master/docker/examples/server-only/docker-compose.yml
cp .env.example .env  # copy and edit
# Edit .env: set PHLEX_DB_PASSWORD, PHLEX_SECRET_KEY, TZ, PLEX_UID, PLEX_GID
docker-compose up -d
```

#### .env configuration reference

```bash
# Required
PHLEX_DB_PASSWORD=change_me_generate_with_openssl    # openssl rand -hex 16
PHLEX_SECRET_KEY=change_me_generate_with_openssl       # openssl rand -hex 32

# Optional — defaults shown
TZ=UTC
PLEX_UID=1000
PLEX_GID=1000
PHLEX_LOG_LEVEL=info
PHLEX_PORT=32400
```

### 4. Volume mounts

| Host path | Container path | Purpose |
|-----------|---------------|---------|
| `phlex_config` (named volume) | `/var/phlex/config` | Application config |
| `phlex_data` (named volume) | `/var/phlex/data` | Media database, caches |
| `phlex_backups` (named volume) | `/var/phlex/backups` | Automatic backups |
| `phlex_logs` (named volume) | `/var/phlex/logs` | Log files |
| `/path/to/media` | `/media:ro` | Read-only media library mount |

To bind-mount a host media directory:
```bash
# In docker-compose.yml, replace the volume entry:
- /mnt/mediavault/movies:/media:ro
```

### 5. Hardware transcoding

#### NVIDIA GPU (nvidia image tag)

Install NVIDIA Docker runtime first:
```bash
distribution=$(. /etc/os-release &&echo "$ID$VERSION_ID")
curl -sL https://nvidia.github.io/nvidia-docker/gpgkey | sudo apt-key add -
curl -sL https://nvidia.github.io/nvidia-docker/$distribution/nvidia-docker.list | \
  sudo tee /etc/apt/sources.list.d/nvidia-docker.list
sudo apt update && sudo apt install -y nvidia-container-toolkit
sudo systemctl restart docker
```

Use the nvidia image and add runtime to compose:
```bash
image: ghcr.io/detain/phlex-server:nvidia
# docker-compose.yml must include:
deploy:
  resources:
    reservations:
      devices:
        - driver: nvidia
          count: 1
          capabilities: [gpu, video]
```

#### Intel Quick Sync (intel image tag)

```bash
image: ghcr.io/detain/phlex-server:intel
```

No special runtime needed; the container automatically detects Quicksync devices via `/dev/dri`.

### 6. Port reference

| Port | Protocol | Service |
|------|----------|---------|
| 32400 | TCP | HTTP web interface |
| 1900 | UDP | DLNA discovery |

```bash
# Verify ports are free before starting
sudo ss -tlnp | grep -E '32400|1900'
```

### 7. Example stacks

> **Tip:** All example stacks below are also available in `docker/examples/` in the repository. Copy the directory that matches your use case and edit `.env`.

#### 7a. Server-only (minimal)

Single phlex-server + MySQL container, local access only.
See full file: `docker/examples/server-only/docker-compose.yml`

```bash
curl -O https://raw.githubusercontent.com/detain/phlex-server/master/docker/examples/server-only/docker-compose.yml
```

#### 7b. Server + Hub (remote access)

Adds phlex-hub relay service for remote access behind NAT.
See full file: `docker/examples/server-hub/docker-compose.yml`

#### 7c. Full-stack with Traefik (production)

Traefik reverse proxy handling HTTPS, WebSocket relay, and Letsecrypt certificates.
See full file: `docker/examples/full-stack/docker-compose.yml`

```bash
mkdir -p ~/phlex/full-stack/traefik
curl -O https://raw.githubusercontent.com/detain/phlex-server/master/docker/examples/full-stack/docker-compose.yml
curl -O https://raw.githubusercontent.com/detain/phlex-server/master/docker/examples/full-stack/traefik/traefik.yml
curl -O https://raw.githubusercontent.com/detain/phlex-server/master/docker/examples/full-stack/traefik/dynamic.yml
```

### 8. Verify the install

```bash
# Check container is running
docker ps | grep phlex-server

# Check logs
docker-compose logs -f phlex-server

# Test HTTP endpoint
curl -I http://localhost:32400
# Expected: HTTP 200

# Access web UI
open http://localhost:32400
```

### What can go wrong

#### Docker not installed or wrong version

- Symptom: `docker: command not found`, or `docker-compose: command not found`
- Fix (no Docker): Follow step 2 above to install Docker Engine + docker-compose plugin
- Fix (docker-compose standalone): Install `docker-compose` binary separately as shown above
- Verify: `docker --version` (min 20.10) and `docker-compose --version` (min v2.0) or `docker compose version`

#### Volume permission errors

- Symptom: `Permission denied` accessing `/var/phlex/config` or media files, or "cannot create file" errors in logs
- Cause: UID/GID mismatch between host user and container's `www-data` (UID 33 typically)
- Fix: Set `PLEX_UID` and `PLEX_GID` in `.env` to match the host user that owns the media directories
  ```bash
  PLEX_UID=$(id -u)
  PLEX_GID=$(id -g)
  ```
- Verify: `docker-compose exec phlex-server id` shows correct UID/GID

#### Port already in use

- Symptom: `bind(): Address already in use` on `0.0.0.0:32400` or `1900`
- Fix: Find and stop the conflicting process: `sudo ss -tlnp | grep 32400`, then `sudo kill <PID>`
  Or change the mapped port in `docker-compose.yml`:
  ```yaml
  ports:
    - "32401:80"   # change host port 32401 instead of 32400
  ```
- For DLNA port 1900/UDP: set `PHLEX_DLNA_PORT=0` to disable DLNA if another service uses it

#### NVIDIA runtime not configured

- Symptom: Transcoding falls back to software encoding despite NVIDIA GPU present; logs show `GPU not available`
- Cause: `nvidia-container-toolkit` not installed, or `nvidia` runtime not enabled in Docker
- Fix: Install `nvidia-container-toolkit` and add `"default-runtime": "nvidia"` to `/etc/docker/daemon.json`:
  ```json
  {
    "default-runtime": "nvidia",
    "runtimes": {
      "nvidia": {
        "path": "nvidia-container-runtime",
        "runtimeArgs": []
      }
    }
  }
  ```
  Then `sudo systemctl restart docker`
- Verify: `docker run --rm --gpus all nvidia/cuda:11.0-base nvidia-smi` — should print GPU info

### Next steps

- [First-run wizard](docs/first-run.md) — complete the browser-based setup at `http://your-server:32400`
- [Hardware transcoding](docs/hardware-transcoding.md) — configure NVENC/VAAPI/Quicksync for better transcoding performance
- [Linux install](docs/install/linux.md) — alternative install method without containers
