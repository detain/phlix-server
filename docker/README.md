# Phlix Server Docker Images

This directory contains the three Dockerfile variants Phlix ships:

| Variant | Base image | Purpose | PHP path layout |
|---|---|---|---|
| `Dockerfile` | `php:8.3-fpm-alpine` | Default, software transcoding only | `/usr/local/etc/php/conf.d/zz-phlix.ini` (Alpine canonical) |
| `Dockerfile.nvidia` | `nvidia/cuda:12.4.0-runtime-ubuntu22.04` | NVIDIA NVENC/NVDEC HW accel | `/etc/php/8.3/fpm/conf.d/99-phlix.ini` + symlink to Alpine path |
| `Dockerfile.intel` | `ubuntu:22.04` | Intel QuickSync / VAAPI HW accel | `/etc/php/8.3/fpm/conf.d/99-phlix.ini` + symlink to Alpine path |

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
ln -sf /etc/php/8.3/fpm/conf.d/99-phlix.ini /usr/local/etc/php/conf.d/zz-phlix.ini
```

This means tooling, documentation, and `docker exec` commands can target a
single canonical PHP-config location regardless of which variant is running.

## Composer install policy

All three Dockerfiles install dependencies in **two layers** so the vendor
tree caches across builds and is **not** invalidated by source-only edits:

```dockerfile
# Layer 1 — invalidated only when composer.{json,lock} change.
COPY composer.json composer.lock /var/www/html/
RUN composer install --no-dev --prefer-dist --no-scripts --no-autoloader \
                     --ignore-platform-reqs

# Layer 2 — invalidated on every source edit, but cheap (no network).
COPY . /var/www/html/
RUN composer dump-autoload --no-dev --optimize
```

**Practical consequence for contributors:** touching any file under `src/`,
`public/`, `config/`, or `migrations/` does NOT re-run `composer install`
and does NOT rebuild the swoole/uv layers below it. Touching
`composer.json` or `composer.lock` re-runs `composer install` and
everything downstream. The slow layers in this image are swoole and uv
(both compiled from source) — keep those at the top so they cache too.

**Composer failures fail the build.** The previous `|| true` suffix was removed
so CI surfaces missing/incompatible dependencies instead of producing a broken
image. `--ignore-platform-reqs` is retained because the build environment may
not have every runtime extension installed (it is fine — extensions are
installed earlier in the Dockerfile and verified at container start).

## Swoole build flags

`docker/Dockerfile` compiles swoole from source against the Alpine
runtime. The compile-time `./configure` flags are intentional — do not
change them without reading this section first.

| Flag | Enables | Runtime requirement |
|---|---|---|
| `--enable-swoole` | Core coroutine runtime | — |
| `--enable-sockets` | PHP `sockets` ext integration (also installed via `docker-php-ext-install sockets` first; sockets headers must exist when swoole is compiled) | — |
| `--enable-mysqlnd` | Coroutine MySQL client | — |
| `--enable-swoole-curl` | Coroutine-friendly `curl_*` hooks | `apk add curl-dev` |
| `--enable-cares` | Async DNS via c-ares | `apk add c-ares-dev` |
| `--enable-swoole-pgsql` | Coroutine PostgreSQL client | `apk add postgresql-dev` |
| `--enable-swoole-sqlite` | Coroutine SQLite client | `apk add sqlite-dev` |
| `--with-openssl-dir=/usr` | TLS in coroutine contexts | `apk add openssl-dev` |
| `--with-nghttp2-dir=/usr` | HTTP/2 client/server | `apk add nghttp2-dev` |
| `--enable-zstd` | zstd compression for the HTTP server | `apk add zstd-dev` |
| `--enable-brotli` | brotli compression for the HTTP server | `apk add brotli-dev` |
| `--enable-swoole-coro-time` | Per-coroutine CPU-time accounting | — |
| `--enable-iouring` | io_uring-backed event loop (faster I/O on modern kernels) | **Linux kernel 5.6+ at runtime**; `apk add liburing-dev` at build time |
| `--enable-uring-socket` | Use io_uring for socket I/O too | Same kernel requirement as `--enable-iouring` |
| `--with-swoole-ssh2` | Coroutine SSH/SFTP client | `apk add libssh2-dev` |
| `--enable-swoole-ftp` | Coroutine FTP client (over SSL) | OpenSSL (already pulled in) |

**io_uring caveat.** The image will still *build* on any kernel, but
swoole's io_uring code paths only activate when running on kernel
**5.6 or newer**. Older kernels silently fall back to epoll. If you
deploy on a host running RHEL 7 / Ubuntu 18.04 / similar EOL kernels,
the io_uring flags are dead code — they don't hurt, but expect no
perf benefit there.

**Flags we intentionally do NOT pass:**

- `--enable-swoole-thread` / `--enable-thread-context` — threaded swoole
  builds require ZTS PHP, and the upstream `php:8.3-fpm-alpine` image is
  NTS. Mixing NTS PHP with thread-enabled swoole crashes at module
  init. If a future image switches to ZTS PHP, these can be revisited.
- `--enable-swoole-stdext` — replaces parts of PHP's `Standard`
  extension with coroutine versions. Considered experimental upstream
  and not safe in a general-purpose image (breaks third-party
  extensions that hook the same functions).

## Building locally

```bash
docker build -f docker/Dockerfile        -t phlix-server:latest .
docker build -f docker/Dockerfile.nvidia -t phlix-server:nvidia .
docker build -f docker/Dockerfile.intel  -t phlix-server:intel .
```

CI builds all three from `.github/workflows/docker.yml`. Build cache
uses both GitHub-Actions storage **and** the registry image itself:

```yaml
cache-from: type=gha,type=registry,ref=<image>:<tag>
cache-to:   type=gha,type=registry,ref=<image>:<tag>,mode=max
```

`mode=max` exports every intermediate layer (not just the final image),
which is what makes the swoole/uv layers reusable across PR builds.

## Alpine quirks

- **No `phpenmod`.** That helper ships with the Debian `php` packages.
  The upstream `php:8.3-fpm-alpine` image uses `docker-php-ext-install`
  (or a hand-written `.ini` under `/usr/local/etc/php/conf.d/`) to wire
  extensions in — `phpenmod` does not exist on Alpine and shells out to
  it will fail with `command not found`.
- **Use the `-dev` variant of every C library** swoole/uv/php-ext-* link
  against. Alpine ships runtime `.so` files in `<lib>` and headers in
  `<lib>-dev`; without the latter, `./configure` silently skips features
  (e.g. dropping `--enable-iouring` rather than failing). Every
  `apk add` line for swoole in the Dockerfile must mirror a flag above.
