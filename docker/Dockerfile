FROM php:8.3-fpm-alpine

# Install system deps and PHP extensions
RUN apk add --no-cache \
    nginx \
    supervisor \
    mysql-client \
    libzip-dev \
    oniguruma-dev \
    curl \
    bash \
    tar \
    gzip \
    && docker-php-ext-install \
        pdo \
        pdo_mysql \
        zip \
        json \
        gd \
        fileinfo \
        pcntl \
        posix \
        bcmath \
    && rm -rf /var/cache/apk/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-phlex.ini

COPY docker/nginx.conf /etc/nginx/http.d/default.conf

COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

RUN mkdir -p /var/www/html /var/phlex/{config,data,logs,backups} \
    && chown -R nobody:nobody /var/www/html /var/phlex

WORKDIR /var/www/html

COPY . /var/www/html/

RUN composer install --no-dev --optimize-autoloader --ignore-platform-reqs || true

EXPOSE 80 443

CMD ["sh", "/docker-entrypoint.sh"]
