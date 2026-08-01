# ============================================================================
# Phlix Server — application image (Alpine)
# ----------------------------------------------------------------------------
# Builds on the shared base image (ghcr.io/detain/phlix-base) which already
# contains PHP + Swoole + UV + Composer. Only the cheap application layers live
# here, so editing this file does NOT recompile the PHP extensions.
#
# PHLIX_BASE_IMAGE can be overridden to pin a digest or to point at a
# locally-built base (see docker/Dockerfile.base and docker/README.md).
#
# SERVING MODEL (S163): this image runs the Workerman daemon (`start.php`) and
# NOTHING else — no nginx, no php-fpm. See the header of docker/supervisord.conf
# for the evidence and the history. `scripts/docker-boot-smoke.sh` boots the
# built image and asserts it actually serves; `docker build` alone never
# executes CMD, ENTRYPOINT or HEALTHCHECK and therefore proves nothing.
# ============================================================================
ARG PHLIX_BASE_IMAGE=ghcr.io/detain/phlix-base:latest
FROM ${PHLIX_BASE_IMAGE}

# PHP overrides (Alpine layout — official php:* image uses /usr/local/etc/php/conf.d/,
# which is scanned by EVERY SAPI including the CLI the daemon runs under).
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-phlix.ini

COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# supervisord is started with an explicit `-c`, but a bare `supervisorctl` reads
# its OWN default search path — which is why `supervisorctl status` answered
# "unix:///run/supervisord.sock no such file" while the container reported Up,
# i.e. the one command an operator reaches for was useless. Point both distro
# defaults at the same file.
RUN mkdir -p /etc/supervisor \
    && ln -sf /etc/supervisor/conf.d/supervisord.conf /etc/supervisord.conf \
    && ln -sf /etc/supervisor/conf.d/supervisord.conf /etc/supervisor/supervisord.conf

# NB: the paths are written out. Docker runs `RUN` through `/bin/sh`, which on
# Alpine is busybox ash and does NOT do brace expansion, so the original
# `/var/phlix/{config,data,logs,backups}` created ONE directory with that
# literal name. Nothing noticed while the CMD below was unreachable; the moment
# the entrypoint was revived (see the COPY near the end of this file) the
# container reached `exec supervisord` and died with "The directory named as
# part of the path /var/phlix/logs/supervisord.log does not exist", exit 2.
# A compose deployment masked it because it volume-mounts those four paths.
# Dockerfile.intel / Dockerfile.nvidia already spell them out — this matches.
#
# /var/run/phlix is `worker.pid_file`'s directory in config/server.php. The
# daemon runs as `nobody`, which cannot create it under /var/run, so without
# this PidFile::apply() silently falls back to Workerman's default path and
# POST /api/v1/admin/restart 500s. (Same rationale as the systemd unit's
# RuntimeDirectory=phlix.)
RUN mkdir -p /var/www/html \
        /var/phlix/config /var/phlix/data /var/phlix/logs /var/phlix/backups \
        /var/run/phlix \
    && chown -R nobody:nobody /var/www/html /var/phlix /var/run/phlix

WORKDIR /var/www/html

# Composer install in two stages so the vendor layer caches across builds —
# only invalidated when composer.{json,lock} change, not on every source edit.
#
# `--ignore-platform-reqs` is deliberately NOT passed: it masked ext-ldap (a
# hard composer.json requirement) being absent from every image, so the build
# stayed green while `composer check-platform-reqs` failed inside the shipped
# container. Without the flag a missing extension fails the BUILD instead.
COPY composer.json composer.lock /var/www/html/
RUN composer install --no-dev --prefer-dist --no-scripts --no-autoloader

COPY . /var/www/html/

RUN composer dump-autoload --no-dev --optimize \
    && mkdir -p /var/www/html/.logs /var/www/html/templates_c /var/www/html/var \
    && chown -R nobody:nobody /var/www/html

# The CMD below names `/docker-entrypoint.sh`. `COPY . /var/www/html/` lands
# the file at /var/www/html/docker/docker-entrypoint.sh, NOT at `/`, so without
# this line the CMD resolved to nothing and no container ever ran the
# entrypoint — broken since the file's first commit (c2127f91) and invisible
# because `docker build` never executes CMD. S159 review finding 4.
# tests/Unit/Docker/DockerEntrypointTest.php asserts this pair.
COPY docker/docker-entrypoint.sh /docker-entrypoint.sh

# The ports the DAEMON actually binds (start.php): 8096 = HTTP (REST + SPA +
# static + per-request WS upgrade, the port HAProxy fronts in production),
# 8097 = the SyncPlay WebSocket worker. The previous `EXPOSE 80 443` described
# the nginx that is now gone; nothing has ever listened on 443.
EXPOSE 8096 8097

# Without this, a container in which the application never started reported
# `Up 22 minutes` while 502'ing every request — which is exactly how the S163
# outage stayed invisible. /health is served by the daemon itself
# (Application::loadRoutes), so it is only reachable when the app is really up.
# start-period must be long enough that a first boot's migration chain does
# not flap the container to `unhealthy`, and SHORT enough that `unhealthy` is
# actually reachable while anyone is watching. 180s failed the second test:
# it outlived an entire boot-gate run, so the state could never go bad and
# `healthy` meant nothing (S163 review F1). Migrations run BEFORE supervisord
# starts the daemon, and the daemon itself serves ~20s after that, so 90s is
# ample in steady state; a slow first migration merely delays `healthy`,
# because failures inside the start period are not counted.
# scripts/docker-boot-smoke.sh asserts this stays <= MAX_START_PERIOD.
HEALTHCHECK --interval=30s --timeout=5s --start-period=90s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8096/health || exit 1

CMD ["sh", "/docker-entrypoint.sh"]
