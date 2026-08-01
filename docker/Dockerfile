# ============================================================================
# Phlix Server — application image (Alpine)
# ----------------------------------------------------------------------------
# Builds on the shared base image (ghcr.io/detain/phlix-base) which already
# contains PHP + Swoole + UV + Composer. Only the cheap application layers live
# here, so editing this file does NOT recompile the PHP extensions.
#
# PHLIX_BASE_IMAGE can be overridden to pin a digest or to point at a
# locally-built base (see docker/Dockerfile.base and docker/README.md).
# ============================================================================
ARG PHLIX_BASE_IMAGE=ghcr.io/detain/phlix-base:latest
FROM ${PHLIX_BASE_IMAGE}

# PHP overrides (Alpine layout — official php:* image uses /usr/local/etc/php/conf.d/)
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-phlix.ini

COPY docker/nginx.conf /etc/nginx/http.d/default.conf

COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# NB: the paths are written out. Docker runs `RUN` through `/bin/sh`, which on
# Alpine is busybox ash and does NOT do brace expansion, so the original
# `/var/phlix/{config,data,logs,backups}` created ONE directory with that
# literal name. Nothing noticed while the CMD below was unreachable; the moment
# the entrypoint was revived (see the COPY near the end of this file) the
# container reached `exec supervisord` and died with "The directory named as
# part of the path /var/phlix/logs/supervisord.log does not exist", exit 2.
# A compose deployment masked it because it volume-mounts those four paths.
# Dockerfile.intel / Dockerfile.nvidia already spell them out — this matches.
RUN mkdir -p /var/www/html \
        /var/phlix/config /var/phlix/data /var/phlix/logs /var/phlix/backups \
    && chown -R nobody:nobody /var/www/html /var/phlix

WORKDIR /var/www/html

# Composer install in two stages so the vendor layer caches across builds —
# only invalidated when composer.{json,lock} change, not on every source edit.
COPY composer.json composer.lock /var/www/html/
RUN composer install --no-dev --prefer-dist --no-scripts --no-autoloader --ignore-platform-reqs

COPY . /var/www/html/

RUN composer dump-autoload --no-dev --optimize

# The CMD below names `/docker-entrypoint.sh`. `COPY . /var/www/html/` lands
# the file at /var/www/html/docker/docker-entrypoint.sh, NOT at `/`, so without
# this line the CMD resolved to nothing and no container ever ran the
# entrypoint — broken since the file's first commit (c2127f91) and invisible
# because `docker build` never executes CMD. S159 review finding 4.
# tests/Unit/Docker/DockerEntrypointTest.php asserts this pair.
COPY docker/docker-entrypoint.sh /docker-entrypoint.sh

EXPOSE 80 443

CMD ["sh", "/docker-entrypoint.sh"]
