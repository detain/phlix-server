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

EXPOSE 80 443

CMD ["sh", "/docker-entrypoint.sh"]
