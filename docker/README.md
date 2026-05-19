# Phlex Server Docker Images

This directory contains the three Dockerfile variants Phlex ships:

| Variant | Base image | Purpose | PHP path layout |
|---|---|---|---|
| `Dockerfile` | `php:8.3-fpm-alpine` | Default, software transcoding only | `/usr/local/etc/php/conf.d/zz-phlex.ini` (Alpine canonical) |
| `Dockerfile.nvidia` | `nvidia/cuda:12.4.0-runtime-ubuntu22.04` | NVIDIA NVENC/NVDEC HW accel | `/etc/php/8.3/fpm/conf.d/99-phlex.ini` + symlink to Alpine path |
| `Dockerfile.intel` | `ubuntu:22.04` | Intel QuickSync / VAAPI HW accel | `/etc/php/8.3/fpm/conf.d/99-phlex.ini` + symlink to Alpine path |

## Why the path layouts differ

The default image inherits from Docker's official `php:8.3-fpm-alpine`, which
places PHP config under `/usr/local/etc/php/conf.d/` — the canonical layout
documented in the upstream `php` image.

The NVIDIA variant must inherit from `nvidia/cuda:*-ubuntu22.04` because the
CUDA runtime is only distributed for glibc-based distributions (Ubuntu/Debian).
The Intel variant must inherit from `ubuntu:22.04` because the
`intel-media-va-driver-non-free` package is only available on Debian/Ubuntu.

Neither HW-accel base image can use the upstream `php` image as a base, so
they install PHP via the Debian package layout (`/etc/php/8.3/fpm/`).

To keep operator-facing paths consistent across all three variants, the HW-accel
images symlink the Alpine-canonical path to their Debian-layout file:

```dockerfile
ln -sf /etc/php/8.3/fpm/conf.d/99-phlex.ini /usr/local/etc/php/conf.d/zz-phlex.ini
```

This means tooling, documentation, and `docker exec` commands can target a
single canonical PHP-config location regardless of which variant is running.

## Composer install policy

All three Dockerfiles run:

```dockerfile
RUN composer install --no-dev --optimize-autoloader --ignore-platform-reqs
```

**Composer failures fail the build.** The previous `|| true` suffix was removed
so CI surfaces missing/incompatible dependencies instead of producing a broken
image. `--ignore-platform-reqs` is retained because the build environment may
not have every runtime extension installed (it is fine — extensions are
installed earlier in the Dockerfile and verified at container start).

## Building locally

```bash
docker build -f docker/Dockerfile        -t phlex-server:latest .
docker build -f docker/Dockerfile.nvidia -t phlex-server:nvidia .
docker build -f docker/Dockerfile.intel  -t phlex-server:intel .
```

CI builds all three from `.github/workflows/docker.yml`.
