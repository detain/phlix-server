# ============================================================================
# Phlix Server — Alpine-based with Swoole + UV extensions
# ----------------------------------------------------------------------------
FROM php:8.3-fpm-alpine

# Install system deps and PHP extensions
RUN apk add --no-cache \
    nginx \
    supervisor \
    mysql-client \
    libzip-dev \
    oniguruma-dev \
    libpng-dev \
    curl \
    curl-dev \
    bash \
    tar \
    gzip \
    unzip \
    7zip \
    # Build tools for extensions
    cmake \
    autoconf \
    file \
    gcc \
    g++ \
    make \
    re2c \
    git \
    linux-headers \
    # Swoole dependencies
    openssl-dev \
    libuv-dev \
    brotli-dev \
    zstd-dev \
    nghttp2-dev \
    postgresql-dev \
    sqlite-dev \
    c-ares-dev \
    liburing-dev \
    libssh2-dev \
    && docker-php-ext-install \
        pdo \
        pdo_mysql \
        zip \
        gd \
        fileinfo \
        pcntl \
        posix \
        bcmath \
        sockets \
    && rm -rf /var/cache/apk/* /tmp/pear

# Build and install swoole
RUN git clone --depth=1 https://github.com/swoole/swoole-src.git /tmp/swoole \
    && cd /tmp/swoole \
    && phpize \
    && ./configure \
        --enable-swoole \
        --enable-sockets \
        --enable-mysqlnd \
        --enable-swoole-curl \
        --enable-cares \
        --enable-swoole-pgsql \
        --with-openssl-dir=/usr \
        --with-nghttp2-dir=/usr \
        --enable-swoole-sqlite \
        --enable-swoole-coro-time \
        --enable-zstd \
        --enable-brotli \
        --enable-iouring \
        --enable-uring-socket \
        --with-swoole-ssh2 \
        --enable-swoole-ftp \
    && make -j$(nproc) \
    && make install \
    && echo "extension=swoole.so" > /usr/local/etc/php/conf.d/docker-php-ext-swoole.ini \
    && cd / && rm -rf /tmp/swoole

# Build and install uv (php-uv)
RUN git clone --depth=1 https://github.com/bwoebi/php-uv.git /tmp/php-uv \
    && cd /tmp/php-uv \
    && phpize \
    && ./configure --with-uv \
    && make -j$(nproc) \
    && make install \
    && echo "extension=uv.so" > /usr/local/etc/php/conf.d/docker-php-ext-uv.ini \
    && cd / && rm -rf /tmp/php-uv

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

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
