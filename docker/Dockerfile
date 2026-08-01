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

RUN mkdir -p /var/www/html /var/phlix/{config,data,logs,backups} \
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
