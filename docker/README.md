# Phlix Server Docker Images

This directory contains the three Dockerfile variants Phlix ships:

| Variant | Base image | Purpose | PHP path layout |
|---|---|---|---|
| `Dockerfile` | `ghcr.io/detain/phlix-base` (`php:8.3-cli-alpine`) | Default, software transcoding only | `/usr/local/etc/php/conf.d/zz-phlix.ini` (Alpine canonical) |
| `Dockerfile.nvidia` | `nvidia/cuda:12.9.2-runtime-ubuntu24.04` | NVIDIA NVENC/NVDEC HW accel | `/etc/php/8.3/cli/conf.d/99-phlix.ini` + symlink to Alpine path |
| `Dockerfile.intel` | `ubuntu:24.04` | Intel QuickSync / VAAPI HW accel | `/etc/php/8.3/cli/conf.d/99-phlix.ini` + symlink to Alpine path |

> The shared base was `php:8.3-fpm-alpine` + `apk add nginx` until S163. A
> `-fpm` base carries the `php-fpm` binary **and** `EXPOSE 9000` whatever the
> package list says, so the Alpine image kept shipping both while three unit
> tests asserted it shipped neither — they simply did not scan
> `Dockerfile.base`. It is now `php:8.3-cli-alpine`, nginx is gone, and
> `allDockerfileProvider()` in `tests/Unit/Docker/DockerEntrypointTest.php`
> covers every `docker/Dockerfile*` in the repo, with a test that fails if a
> new one is added outside the net.

All three land on **PHP 8.3**, matching CI. Production runs **8.5** — that gap
is deliberate and recorded, not accidental; no gate currently runs the engine
production runs.

## Serving model — the Workerman daemon, and nothing else

A container runs exactly one program: `php /var/www/html/start.php start`, under
supervisord. **There is no nginx and no php-fpm in the image.** That mirrors
production, where `scripts/install.sh` points HAProxy straight at
`127.0.0.1:${HTTP_PORT}` ("WebSocket upgrade is detected per-request; both REST
and WS traffic share the single HTTP port on the Workerman side"), and Workerman
serves the `/app` SPA shell and all static assets itself
(`HttpHandler::serveStatic()`). TLS and host routing belong to whatever fronts
the container.

Ports — exactly what `start.php` binds:

| Port | Worker | Traffic |
|---|---|---|
| `8096` | `phlix-server-http` (count 14) | REST, the `/app` SPA, static assets, per-request WS upgrade. **This is the port to front.** |
| `8097` | `phlix-server-ws` (count 1) | The SyncPlay WebSocket worker. |

Nothing listens on 80 or 443. If you are migrating from an older compose file,
change `"<host>:80"` to `"<host>:8096"`.

### Verifying an image actually runs

`docker build` never executes `CMD`, `ENTRYPOINT` or `HEALTHCHECK`, so a green
build proves nothing about the runtime path — for the whole life of these images
supervisord started `public/index.php` (the one-shot CGI front controller)
instead of `start.php`, and no container had ever served a request. Boot it:

```bash
scripts/docker-boot-smoke.sh docker/Dockerfile        phlix-boot:alpine
scripts/docker-boot-smoke.sh docker/Dockerfile.intel  phlix-boot:intel
scripts/docker-boot-smoke.sh docker/Dockerfile.nvidia phlix-boot:nvidia
```

That script builds the image (rebuilding the shared base first, because the
pipeline is two-stage), runs it against a throwaway MySQL on a private network,
and makes 11 assertions: `/health` 200s; the migration step reported success;
the schema really is there when reached with the application's own credentials;
every supervisord program is `RUNNING`; the application AND its Workerman
workers hold their pids across a 90 s window; `start.php` is the running
process and no CGI/FPM/nginx process is; `:8097` answers both inside the
container and through the published port mapping; the SPA shell and its
immutable assets are served; the container reaches `healthy` and its
HEALTHCHECK start period is short enough for `unhealthy` to be reachable;
`composer check-platform-reqs` is clean; and — destructively, last — a program
driven into FATAL kills the container with a **non-zero** exit code.

It then checks **itself**: every assertion is registered by name and a check
that produced no verdict fails the run. That guard exists because a check that
silently never executed once let this gate print `ALL ASSERTIONS PASSED`
against a broken image.

CI runs the same script in the `docker-boot-gate` job.

### Configuration the container needs

`docker/docker-entrypoint.sh` maps the names every documented deployment sets
onto the names the application actually reads:

| You set | The app reads |
|---|---|
| `PHLIX_DATABASE_HOST/PORT/NAME/USER/PASSWORD` | `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USER` / `DB_PASSWORD` |
| `PHLIX_SECRET_KEY` | `JWT_SECRET` |

`start.php` refuses to boot without a JWT signing key. If neither `JWT_SECRET`
nor `PHLIX_SECRET_KEY` is set, the entrypoint generates one and persists it to
`/var/phlix/config/jwt_secret` so it survives restarts — **mount
`/var/phlix/config`**, or every restart invalidates all sessions and signed
media URLs (the entrypoint says so loudly if it cannot write the file).

## Base image (shared)

The slow part of the default Alpine build is compiling **Swoole** and **php-uv**
from source. That work — plus the OS packages, PHP, and Composer — now lives in
a separate **base image**, `docker/Dockerfile.base`, published as
`ghcr.io/detain/phlix-base`. The default `Dockerfile` (and the Hub repo's
`Dockerfile`) only carry the cheap application layers and start with:

```dockerfile
ARG PHLIX_BASE_IMAGE=ghcr.io/detain/phlix-base:latest
FROM ${PHLIX_BASE_IMAGE}
```

**Why:** editing the application Dockerfile (php config, composer steps,
source copy) no longer recompiles Swoole/UV — Docker pulls the prebuilt base
instead. The same base is reused by both **phlix-server** and **phlix-hub**, so
the extensions are compiled once instead of once per image.

**Who builds it:** the `docker-base` job in `.github/workflows/docker.yml`
builds and pushes the base (multi-arch). It runs on every workflow run but the
Swoole/UV compile layers are cached (GHA + registry), so it is a fast cache hit
unless `Dockerfile.base` actually changes. The `phlix-server` and `phlix-hub`
build jobs `needs: docker-base`, so the base is always fresh before they pull
it. The hub image is built by *this* (server) repo's workflow, so the base lives
here even though hub consumes it.

> The `ghcr.io/detain/phlix-base` package should be **public** (or readable by
> the workflow's `GITHUB_TOKEN`) so pull-request builds can pull it — PR runs
> build the base but do not push it, and fall back to the last published
> `:latest`.

> Scope note: only the Alpine `Dockerfile` uses the base image. `Dockerfile.nvidia`
> and `Dockerfile.intel` install PHP from apt and do **not** compile Swoole/UV,
> so they have no slow-compile problem and are left unchanged.

## Why the path layouts differ

The default image inherits from Docker's official `php:8.3-cli-alpine`, which
places PHP config under `/usr/local/etc/php/conf.d/` — the canonical layout
documented in the upstream `php` image.

The NVIDIA variant must inherit from `nvidia/cuda:*-ubuntu24.04` because the
CUDA runtime is only distributed for glibc-based distributions (Ubuntu/Debian).
The Intel variant must inherit from `ubuntu:24.04` because the
`intel-media-va-driver-non-free` package is only available on Debian/Ubuntu.

Neither HW-accel base image can use the upstream `php` image as a base, so
they install PHP via the Debian package layout (`/etc/php/8.3/cli/` — the CLI
scan dir, because the daemon is a CLI process; it used to be written to the
`fpm/` dir, which the application never reads, so every setting in
`docker/php.ini` was inert).

To keep operator-facing paths consistent across all three variants, the HW-accel
images symlink the Alpine-canonical path to their Debian-layout file:

```dockerfile
ln -sf /etc/php/8.3/cli/conf.d/99-phlix.ini /usr/local/etc/php/conf.d/zz-phlix.ini
```

This means tooling, documentation, and `docker exec` commands can target a
single canonical PHP-config location regardless of which variant is running.

## Composer install policy

All three Dockerfiles install dependencies in **two layers** so the vendor
tree caches across builds and is **not** invalidated by source-only edits:

```dockerfile
# Layer 1 — invalidated only when composer.{json,lock} change.
COPY composer.json composer.lock /var/www/html/
RUN composer install --no-dev --prefer-dist --no-scripts --no-autoloader

# Layer 2 — invalidated on every source edit, but cheap (no network).
COPY . /var/www/html/
RUN composer dump-autoload --no-dev --optimize
```

(That is `docker/Dockerfile`, the two-layer one. `Dockerfile.intel` and
`Dockerfile.nvidia` run a single `composer install --no-dev
--optimize-autoloader` after the source copy.)

**Practical consequence for contributors:** touching any file under `src/`,
`public/`, `config/`, or `migrations/` does NOT re-run `composer install`
and does NOT rebuild the swoole/uv layers below it. Touching
`composer.json` or `composer.lock` re-runs `composer install` and
everything downstream. The slow layers in this image are swoole and uv
(both compiled from source) — keep those at the top so they cache too.

**Composer failures fail the build.** The previous `|| true` suffix was removed
so CI surfaces missing/incompatible dependencies instead of producing a broken
image.

**`--ignore-platform-reqs` is gone from all three Dockerfiles (S163), and a
unit test keeps it out.** It was described here as harmless ("extensions are
installed earlier in the Dockerfile and verified at container start") and it was
not: `ext-ldap` is a HARD `composer.json` requirement, it was absent from every
image, the flag masked it at build time, and nothing verified it at container
start either — no container had ever started. `composer check-platform-reqs`
inside the built image is now an assertion in `scripts/docker-boot-smoke.sh`.

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
  builds require ZTS PHP, and the upstream `php:8.3-cli-alpine` image is
  NTS. Mixing NTS PHP with thread-enabled swoole crashes at module
  init. If a future image switches to ZTS PHP, these can be revisited.
- `--enable-swoole-stdext` — replaces parts of PHP's `Standard`
  extension with coroutine versions. Considered experimental upstream
  and not safe in a general-purpose image (breaks third-party
  extensions that hook the same functions).

## Building locally

The default image needs the base image present. Either pull the published base
or build it once (the slow step — only needed when `Dockerfile.base` changes):

```bash
# Option A — pull the published base
docker pull ghcr.io/detain/phlix-base:latest

# Option B — build the base locally (compiles Swoole + UV, slow)
docker build -f docker/Dockerfile.base -t ghcr.io/detain/phlix-base:latest .
```

Then the application images build fast (no recompile):

```bash
docker build -f docker/Dockerfile        -t phlix-server:latest .
docker build -f docker/Dockerfile.nvidia -t phlix-server:nvidia .
docker build -f docker/Dockerfile.intel  -t phlix-server:intel .
```

To build against a base other than `ghcr.io/detain/phlix-base:latest`, pass
`--build-arg PHLIX_BASE_IMAGE=<ref>`. `docker compose` users can run
`docker compose build phlix-base` then `docker compose build phlix`.
(`Dockerfile.nvidia` / `Dockerfile.intel` do not use the base image.)

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
  The upstream `php:8.3-cli-alpine` image uses `docker-php-ext-install`
  (or a hand-written `.ini` under `/usr/local/etc/php/conf.d/`) to wire
  extensions in — `phpenmod` does not exist on Alpine and shells out to
  it will fail with `command not found`.
- **Use the `-dev` variant of every C library** swoole/uv/php-ext-* link
  against. Alpine ships runtime `.so` files in `<lib>` and headers in
  `<lib>-dev`; without the latter, `./configure` silently skips features
  (e.g. dropping `--enable-iouring` rather than failing). Every
  `apk add` line for swoole in the Dockerfile must mirror a flag above.
