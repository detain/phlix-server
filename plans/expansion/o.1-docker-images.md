# Step O.1 — Docker Images: `phlex-server`, `phlex-hub`

**Phase:** O (Deployment / DevOps / Release)
**Step:** O.1
**Depends on:** B.7 (Hub portal MVP)
**Review:** Yes — see `o.1-docker-images-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Create **Docker images** for `phlex-server` and `phlex-hub` with hardware-acceleration variants (nvidia, intel).

## 2. Context (what already exists)

Read first:

- `composer.json` — PHP dependencies.
- `config/` — existing config structure.
- `src/Server/Core/Application.php` — Workerman entry point.
- `public/index.php` — HTTP entry point.
- `scripts/` — existing CLI scripts.

## 3. Scope — files to create

### Docker files

#### `docker/Dockerfile` (base PHP image)

```dockerfile
FROM php:8.3-fpm-alpine

# Install system deps and PHP extensions
RUN apk add --no-cache \
    nginx \
   Supervisord \
    mysql-client \
    libzip-dev \
    oniguruma-dev \
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

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Configure PHP
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-phlex.ini

# Configure nginx
COPY docker/nginx.conf /etc/nginx/http.d/default.conf

# Configure Supervisord
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Create app directory
RUN mkdir -p /var/www/html /var/phlex/{config,data,logs,backups} \
    && chown -R nobody:nobody /var/www/html /var/phlex

WORKDIR /var/www/html

# Copy application
COPY . /var/www/html/

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader --ignore-platform-reqs

EXPOSE 80 443

CMD ["sh", "/docker-entrypoint.sh"]
```

#### `docker/Dockerfile.nvidia` (NVIDIA GPU support)

```dockerfile
FROM nvidia/cuda:12.4.0-runtime-ubuntu22.04

# Install PHP 8.3 and dependencies
RUN apt-get update && apt-get install -y \
    php8.3-fpm \
    php8.3-cli \
    php8.3-mysql \
    php8.3-zip \
    php8.3-gd \
    php8.3-json \
    php8.3-fileinfo \
    php8.3-bcmath \
    nginx \
    supervisor \
    ffmpeg \
    && rm -rf /var/lib/apt/lists/*

# Set up PHP-FPM
COPY docker/php-fpm-pool.conf /etc/php/8.3/fpm/pool.d/www.conf

# ... rest similar to base Dockerfile
```

#### `docker/Dockerfile.intel` (Intel QuickSync support)

```dockerfile
FROM ubuntu:22.04

# Install PHP 8.3, FFMpeg with QSV, and dependencies
RUN apt-get update && apt-get install -y \
    php8.3-fpm \
    php8.3-cli \
    php8.3-mysql \
    php8.3-zip \
    php8.3-gd \
    php8.3-json \
    php8.3-fileinfo \
    php8.3-bcmath \
    nginx \
    supervisor \
    ffmpeg \
    intel-media-va-driver-non-free \
    && rm -rf /var/lib/apt/lists/*
```

#### `docker/docker-entrypoint.sh`

```bash
#!/bin/sh
set -e

echo "Starting Phlex Media Server..."

# Run database migrations if needed
if [ -n "${PHLEX_DATABASE_HOST}" ]; then
    php /var/www/html/scripts/run-migrations.php
fi

# Start supervisord
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
```

#### `docker/php.ini`

```ini
[php]
memory_limit = 256M
upload_max_filesize = 100M
post_max_size = 100M
max_execution_time = 300
max_input_time = 300

[opcache]
opcache.enable = 1
opcache.memory_consumption = 128
opcache.interned_strings_buffer = 8
opcache.max_accelerated_files = 10000
opcache.validate_timestamps = 0
opcache.save_comments = 1

[date]
date.timezone = UTC
```

#### `docker/nginx.conf`

```nginx
server {
    listen 80;
    server_name _;
    root /var/www/html/public;
    index index.php index.html;

    # Gzip compression
    gzip on;
    gzip_vary on;
    gzip_min_length 1024;
    gzip_types text/plain text/css application/json application/javascript text/xml application/xml;

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;

    # Max upload size for HLS segments and media
    client_max_body_size 100M;

    # Timeouts for long-running transcodes
    proxy_read_timeout 86400s;
    proxy_send_timeout 86400s;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_buffers 16 256k;
        fastcgi_buffer_size 256k;
        fastcgi_busy_buffers_size 256k;
    }

    # WebSocket support
    location /ws {
        proxy_pass http://127.0.0.1:9000;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_read_timeout 86400s;
    }

    # HLS streaming segments
    location ~ \.m3u8$ {
        add_header Cache-Control "no-cache";
        add_header Access-Control-Allow-Origin "*";
    }

    location ~ \.ts$ {
        add_header Cache-Control "public, max-age=31536000";
        add_header Access-Control-Allow-Origin "*";
    }

    # Deny access to sensitive files
    location ~ /\. {
        deny all;
    }

    location ~ ^/(config|src|vendor|tests|migrations)/ {
        deny all;
    }
}
```

#### `docker/supervisord.conf`

```ini
[supervisord]
nodaemon=true
user=root
logfile=/var/phlex/logs/supervisord.log
pidfile=/var/run/supervisord.pid
loglevel=info

[program:php-fpm]
command=php-fpm -F
autostart=true
autorestart=true
stdout_logfile=/var/phlex/logs/php-fpm.log
stderr_logfile=/var/phlex/logs/php-fpm-error.log
user=nobody

[program:nginx]
command=nginx -g "daemon off;"
autostart=true
autorestart=true
stdout_logfile=/var/phlex/logs/nginx.log
stderr_logfile=/var/phlex/logs/nginx-error.log

[program:workerman]
command=php /var/www/html/public/index.php start
autostart=true
autorestart=true
stdout_logfile=/var/phlex/logs/workerman.log
stderr_logfile=/var/phlex/logs/workerman-error.log
user=nobody
numprocs=1
```

#### `docker/.dockerignore`

```
.git
.gitignore
.coverage
.phpunit.cache
coverage/
coverage-report/
vendor/
node_modules/
*.md
docker-compose*.yml
.dockerignore
Dockerfile*
.env*
phpstan*.neon*
phpunit.xml*
.git/
tests/
docs/
plans/
```

#### `docker-compose.yml` (for testing the Docker image)

```yaml
version: '3.8'

services:
  phlex:
    build:
      context: .
      dockerfile: docker/Dockerfile
    ports:
      - "8080:80"
    environment:
      - PHLEX_DATABASE_HOST=mysql
      - PHLEX_DATABASE_PORT=3306
      - PHLEX_DATABASE_NAME=phlex
      - PHLEX_DATABASE_USER=phlex
      - PHLEX_DATABASE_PASSWORD=phlex_secret
    volumes:
      - phlex_config:/var/phlex/config
      - phlex_data:/var/phlex/data
      - phlex_backups:/var/phlex/backups
      - media_library:/media
    depends_on:
      mysql:
        condition: service_healthy
    restart: unless-stopped

  mysql:
    image: mysql:8.0
    environment:
      MYSQL_ROOT_PASSWORD: root
      MYSQL_DATABASE: phlex
      MYSQL_USER: phlex
      MYSQL_PASSWORD: phlex_secret
    volumes:
      - mysql_data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost"]
      interval: 10s
      timeout: 5s
      retries: 5

volumes:
  phlex_config:
  phlex_data:
  phlex_backups:
  media_library:
  mysql_data:
```

## 4. Approach

1. Branch from master: `git checkout -b o.1-docker-images`.
2. Create `docker/` directory structure.
3. Create base `Dockerfile` with PHP 8.3, nginx, Supervisord.
4. Create hardware-accelerated variants (`Dockerfile.nvidia`, `Dockerfile.intel`).
5. Create `docker-entrypoint.sh` for startup and migrations.
6. Create `nginx.conf` with WebSocket and HLS streaming support.
7. Create `supervisord.conf` to manage php-fpm, nginx, and workerman.
8. Create `php.ini` with opcache and size limits.
9. Create `docker-compose.yml` for local testing.
10. Test the Docker build locally.
11. Write tests for Docker configuration.
12. Verify: PHPStan level 9, PHPCS clean.
13. Commit + PR + merge.

## 5. Tests (REQUIRED — minimum bar)

1. `DockerfileTest::test_dockerfile_syntax_valid`
2. `DockerfileTest::test_dockerignore_excludes_vendor`
3. `DockerfileTest::test_nginx_config_syntax_valid`
4. `DockerConfigTest::test_compose_file_valid`
5. `DockerConfigTest::test_environment_variables_documented`

## 6. Acceptance Criteria

- [ ] Base `Dockerfile` builds successfully with PHP 8.3, nginx, Supervisord.
- [ ] `docker/Dockerfile.nvidia` variant includes CUDA support.
- [ ] `docker/Dockerfile.intel` variant includes QSV support.
- [ ] `nginx.conf` handles WebSocket upgrades at `/ws`.
- [ ] `nginx.conf` serves HLS `.m3u8` and `.ts` files with proper caching headers.
- [ ] `supervisord.conf` manages php-fpm, nginx, and workerman processes.
- [ ] `docker-entrypoint.sh` runs migrations on startup if database is configured.
- [ ] `docker-compose.yml` starts phlex-server and mysql for local testing.
- [ ] All Docker files follow best practices (non-root user, minimal layers, .dockerignore).
- [ ] PHPStan level 9 clean.
- [ ] PHPCS PSR-12 clean.

## 7. Git ritual

```bash
cd /home/sites/phlex
git checkout master && git pull --ff-only origin master
git checkout -b o.1-docker-images
# ... implement ...
./vendor/bin/phpstan analyze docker/ --level=9 --no-progress
./vendor/bin/phpcs --standard=PSR12 docker/
docker build -t phlex-test -f docker/Dockerfile .
docker-compose -f docker-compose.yml config --quiet
git add -A
git commit -m "Step O.1: Docker images for phlex-server"
unset GITHUB_TOKEN
gh pr create --title "Step O.1: Docker images for phlex-server" --body "..."
gh pr merge --squash --delete-branch
git checkout master && git pull --ff-only origin master
```

## 8. Reviewer hand-off

Review = Yes. Reviewer runs `o.1-docker-images-review.md`.

(End of file - total 287 lines)