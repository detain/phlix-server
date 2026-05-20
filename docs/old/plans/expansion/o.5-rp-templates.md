# Step O.5 — nginx + Caddy Reverse-Proxy Templates

**Phase:** O (Deployment / DevOps / Release)
**Step:** O.5
**Depends on:** O.4 (systemd unit files)
**Review:** Yes — see `o.5-rp-templates-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Create **nginx and Caddy reverse-proxy templates** with TLS, WebSocket upgrade support, and optimized settings for large media file streaming.

## 2. Context (what already exists)

Read first:

- `docker/nginx.conf` — existing nginx configuration from O.1.

## 3. Scope — files to create

### `reverse-proxy/nginx/` directory

#### `reverse-proxy/nginx/phlex.conf`

```nginx
# Upstream phlex server
upstream phlex_backend {
    server 127.0.0.1:8080;
    keepalive 32;
}

# Rate limiting zone
limit_req_zone $binary_remote_addr zone=api_limit:10m rate=10r/s;
limit_req_zone $binary_remote_addr zone=stream_limit:10m rate=30r/s;
limit_conn_zone $binary_remote_addr zone=addr;

server {
    listen 80;
    server_name phlex.example.com;

    # Redirect to HTTPS
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    server_name phlex.example.com;

    # SSL Configuration
    ssl_certificate /etc/letsencrypt/live/phlex.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/phlex.example.com/privkey.pem;
    ssl_trusted_certificate /etc/letsencrypt/live/phlex.example.com/chain.pem;

    # Modern SSL configuration
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384;
    ssl_prefer_server_ciphers off;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 1d;
    ssl_session_tickets off;

    # HSTS
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains; preload" always;

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Permissions-Policy "camera=(), microphone=(), geolocation=()";

    # Gzip compression
    gzip on;
    gzip_vary on;
    gzip_proxied any;
    gzip_comp_level 6;
    gzip_types text/plain text/css text/xml application/json application/javascript application/rss+xml application/atom+xml image/svg+xml application/xml;
    gzip_min_length 256;

    # Max upload size (100MB for HLS segment uploads)
    client_max_body_size 100M;

    # Timeouts for long-running transcodes and HLS streaming
    proxy_read_timeout 86400s;
    proxy_send_timeout 86400s;
    proxy_connect_timeout 60s;
    proxy_buffering off;

    # Proxy buffers
    proxy_buffer_size 128k;
    proxy_buffers 8 256k;
    proxy_busy_buffers_size 256k;

    # Rate limiting
    limit_req zone=api_limit burst=20 nodelay;
    limit_conn addr 50;

    # Cache control for static assets
    location ~* \.(jpg|jpeg|png|gif|ico|css|js|svg|woff|woff2|ttf|eot)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        access_log off;
    }

    # HLS streaming - master playlist
    location ~ \.m3u8$ {
        proxy_pass http://phlex_backend;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;

        add_header Cache-Control "no-cache, no-store, must-revalidate";
        add_header Access-Control-Allow-Origin "*";
        add_header Access-Control-Allow-Methods "GET, OPTIONS";
        add_header Access-Control-Allow-Headers "Range, Content-Type, Authorization";

        proxy_cache off;
        expires -1;
    }

    # HLS streaming - video segments
    location ~ \.ts$ {
        proxy_pass http://phlex_backend;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;

        # Long caching for segments
        add_header Cache-Control "public, max-age=31536000, immutable";
        add_header Access-Control-Allow-Origin "*";

        # Don't cache, stream directly
        proxy_buffering off;
        expires 1y;
    }

    # DASH streaming
    location ~ \.mpd$ {
        proxy_pass http://phlex_backend;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;

        add_header Cache-Control "no-cache, no-store, must-revalidate";
        add_header Access-Control-Allow-Origin "*";
    }

    # WebSocket support
    location /ws {
        proxy_pass http://phlex_backend;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;

        # WebSocket timeouts
        proxy_read_timeout 86400s;
        proxy_send_timeout 86400s;

        # Disable buffering for WebSocket
        proxy_buffering off;
        proxy_cache off;
    }

    # Media file streaming (direct file access)
    location /media/ {
        proxy_pass http://phlex_backend;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;

        # Enable range requests for seeking
        proxy_range off;
        max_ranges 1;

        # Streaming buffers
        proxy_buffer_size 1M;
        proxy_buffers 4 1M;
    }

    # API endpoints
    location /api/ {
        proxy_pass http://phlex_backend;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;

        # CORS headers for API
        add_header Access-Control-Allow-Origin "*";
        add_header Access-Control-Allow-Methods "GET, POST, PUT, DELETE, OPTIONS";
        add_header Access-Control-Allow-Headers "Authorization, Content-Type, X-Requested-With";

        if ($request_method = 'OPTIONS') {
            add_header Access-Control-Allow-Origin "*";
            add_header Access-Control-Allow-Methods "GET, POST, PUT, DELETE, OPTIONS";
            add_header Access-Control-Allow-Headers "Authorization, Content-Type, X-Requested-With";
            add_header Access-Control-Max-Age 86400;
            add_header Content-Length 0;
            add_header Content-Type text/plain;
            return 204;
        }
    }

    # Health check endpoint
    location /health {
        proxy_pass http://phlex_backend;
        proxy_set_header Host $host;
        access_log off;
    }

    # Default location - serve PHP via proxy
    location / {
        proxy_pass http://phlex_backend;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    # Deny access to sensitive paths
    location ~ ^/(\.env|config|vendor|src|tests|migrations)/ {
        deny all;
        return 404;
    }

    # Logs
    access_log /var/log/nginx/phlex-access.log;
    error_log /var/log/nginx/phlex-error.log;
}
```

#### `reverse-proxy/nginx/ssl-options.conf`

```nginx
# SSL optimization options
ssl_session_timeout 1d;
ssl_session_cache shared:SSL:50m;
ssl_session_tickets off;

# Modern cipher suite
ssl_protocols TLSv1.2 TLSv1.3;
ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384;
ssl_prefer_server_ciphers off;

# OCSP Stapling
ssl_stapling on;
ssl_stapling_verify on;
resolver 8.8.8.8 8.8.4.4 valid=300s;
resolver_timeout 5s;
```

### `reverse-proxy/caddy/` directory

#### `reverse-proxy/caddy/Caddyfile`

```bash
# Phlex Media Server Caddyfile

phlex.example.com {
    # PHP backend
    reverse_proxy localhost:8080 {
        # Enable WebSocket support
        transport http {
            versions h2c h2 1.1
        }
    }

    # HLS streaming optimization
    handle_path *.m3u8* {
        reverse_proxy localhost:8080 {
            header_down Cache-Control "no-cache, no-store, must-revalidate"
            header_down Access-Control-Allow-Origin "*"
        }
    }

    # Video segments - long cache
    handle_path *.ts* {
        reverse_proxy localhost:8080 {
            header_down Cache-Control "public, max-age=31536000"
            header_down Access-Control-Allow-Origin "*"
        }
    }

    # DASH manifest
    handle_path *.mpd* {
        reverse_proxy localhost:8080 {
            header_down Cache-Control "no-cache"
        }
    }

    # WebSocket endpoint
    handle /ws* {
        reverse_proxy localhost:8080 {
            header_up Upgrade {header.Connection}
            header_up Connection {header.Upgrade}
        }
    }

    # Media files
    handle /media/* {
        reverse_proxy localhost:8080 {
            max_buffer_size 1MB
        }
    }

    # API with CORS
    handle /api/* {
        reverse_proxy localhost:8080 {
            header Access-Control-Allow-Origin "*"
            header Access-Control-Allow-Methods "GET, POST, PUT, DELETE, OPTIONS"
            header Access-Control-Allow-Headers "Authorization, Content-Type, X-Requested-With"
        }
    }

    # Security headers
    header {
        X-Frame-Options "SAMEORIGIN"
        X-Content-Type-Options "nosniff"
        X-XSS-Protection "1; mode=block"
        Referrer-Policy "strict-origin-when-cross-origin"
        Permissions-Policy "camera=(), microphone=(), geolocation=()"
        Strict-Transport-Security "max-age=31536000; includeSubDomains; preload"
    }

    # Logging
    log {
        output file /var/log/caddy/phlex.log
    }

    # Gzip compression
    encode gzip zstd
}

# Alternative: HTTP-only with Let's Encrypt auto-HTTPS
phlex.local {
    reverse_proxy localhost:8080
    log {
        output file /var/log/caddy/phlex-local.log
    }
    encode gzip
}
```

#### `reverse-proxy/caddy/Caddyfile禽`

```bash
# Phlex Media Server with Docker integration

phlex.example.com {
    # Use Docker DNS for container-to-container communication
    import docker-resolver

    reverse_proxy localhost:8080

    # TLS configuration
    tls admin@phlex.example.com {
        issuer acme
        dns cloudflare {env.CLOUDFLARE_API_TOKEN}
    }

    # HSTS
    header {
        Strict-Transport-Security "max-age=31536000; includeSubDomains"
    }

    encode gzip zstd
}

# Import Cloudflare DNS resolver for Docker
(docker-resolver) {
    handle /ws* {
        reverse_proxy localhost:8080 {
            header_up Connection {header.Upgrade}
            header_up Upgrade {header.Connection}
        }
    }
}
```

### `reverse-proxy/install-nginx.sh`

```bash
#!/bin/bash
set -e

echo "Installing Phlex nginx reverse-proxy configuration..."

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    echo "Please run as root or with sudo"
    exit 1
fi

# Detect OS
if [ -f /etc/debian_version ]; then
    PKG_MANAGER="apt-get"
    NGINX_SITES="/etc/nginx/sites-available"
    NGINX_ENABLED="/etc/nginx/sites-enabled"
elif [ -f /etc/redhat-release ]; then
    PKG_MANAGER="yum"
    NGINX_SITES="/etc/nginx/conf.d"
    NGINX_ENABLED="/etc/nginx/conf.d"
else
    echo "Unsupported OS"
    exit 1
fi

# Install nginx
echo "Installing nginx..."
$PKG_MANAGER update -qq
$PKG_MANAGER install -y nginx

# Install configuration
echo "Installing configuration..."
install -m 644 reverse-proxy/nginx/phlex.conf $NGINX_SITES/phlex.conf
install -m 644 reverse-proxy/nginx/ssl-options.conf /etc/nginx/snippets/phlex-ssl.conf

# Enable site
if [ -d $NGINX_ENABLED ]; then
    ln -sf $NGINX_SITES/phlex.conf $NGINX_ENABLED/
fi

# Test configuration
echo "Testing nginx configuration..."
nginx -t

# Reload nginx
echo "Reloading nginx..."
systemctl reload nginx

echo ""
echo "Nginx configuration installed!"
echo ""
echo "IMPORTANT: Update /etc/nginx/sites-available/phlex.conf with:"
echo "  - Your server_name"
echo "  - Path to your SSL certificates"
echo "  - Backend server address"
echo ""
echo "Then run: sudo systemctl reload nginx"
```

### `reverse-proxy/install-caddy.sh`

```bash
#!/bin/bash
set -e

echo "Installing Phlex Caddy reverse-proxy configuration..."

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    echo "Please run as root or with sudo"
    exit 1
fi

# Install Caddy
echo "Installing Caddy..."
apt-get update -qq
apt-get install -y debian-keyring debian-archive-keyring apt-transport-https curl
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | tee /etc/apt/sources.list.d/caddy-stable.list
apt-get update -qq
apt-get install -y caddy

# Install Caddyfile
echo "Installing Caddyfile..."
install -m 644 reverse-proxy/caddy/Caddyfile /etc/caddy/Caddyfile

# Reload Caddy
echo "Reloading Caddy..."
systemctl reload caddy

echo ""
echo "Caddy configuration installed!"
echo ""
echo "IMPORTANT: Update /etc/caddy/Caddyfile with:"
echo "  - Your domain"
echo "  - TLS email"
echo "  - Backend server address"
echo ""
echo "Then run: sudo systemctl reload caddy"
```

### `reverse-proxy/README.md`

```markdown
# Phlex Reverse-Proxy Templates

This directory contains reverse-proxy configurations for nginx and Caddy to front Phlex Media Server.

## Why a Reverse Proxy?

- TLS termination (HTTPS)
- WebSocket support for real-time features
- HTTP/2 for multiplexed connections
- Gzip compression
- Rate limiting
- Static file caching
- Load balancing (future)

## nginx

### Installation

```bash
sudo bash reverse-proxy/install-nginx.sh
```

### Manual Installation

1. Copy configuration:
```bash
sudo cp reverse-proxy/nginx/phlex.conf /etc/nginx/sites-available/
sudo cp reverse-proxy/nginx/ssl-options.conf /etc/nginx/snippets/
```

2. Edit `/etc/nginx/sites-available/phlex.conf`:
   - Set `server_name` to your domain
   - Update SSL certificate paths
   - Update upstream backend address

3. Enable and reload:
```bash
sudo ln -s /etc/nginx/sites-available/phlex.conf /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

### SSL Certificate

For Let's Encrypt:
```bash
sudo certbot --nginx -d phlex.example.com
```

## Caddy

Caddy handles HTTPS automatically with Let's Encrypt.

### Installation

```bash
sudo bash reverse-proxy/install-caddy.sh
```

### Manual Installation

1. Copy Caddyfile:
```bash
sudo cp reverse-proxy/caddy/Caddyfile /etc/caddy/
```

2. Edit `/etc/caddy/Caddyfile`:
   - Set your domain
   - Update TLS email
   - Update backend address

3. Reload:
```bash
sudo systemctl reload caddy
```

## Features

### WebSocket Support
Both configs proxy `/ws` to the Workerman WebSocket server.

### HLS Streaming
`.m3u8` and `.ts` files have optimized caching and CORS headers.

### Security Headers
- X-Frame-Options
- X-Content-Type-Options
- X-XSS-Protection
- HSTS
- Referrer-Policy

### Rate Limiting
nginx: 10 req/s for API, 30 req/s for streaming
```

## 4. Approach

1. Branch from master: `git checkout -b o.5-rp-templates`.
2. Create `reverse-proxy/nginx/` directory.
3. Create `phlex.conf` with full reverse-proxy configuration.
4. Create `ssl-options.conf` with SSL optimization settings.
5. Create `reverse-proxy/caddy/` directory.
6. Create `Caddyfile` with equivalent Caddy configuration.
7. Create installer scripts for both.
8. Create `README.md` documentation.
9. Validate nginx syntax with `nginx -t`.
10. Validate Caddyfile syntax with `caddy validate`.
11. Write tests for configuration validation.
12. Verify: PHPStan level 9, PHPCS clean.
13. Commit + PR + merge.

## 5. Tests (REQUIRED — minimum bar)

1. `ReverseProxyTest::test_nginx_config_syntax_valid`
2. `ReverseProxyTest::test_nginx_ssl_options_valid`
3. `ReverseProxyTest::test_caddyfile_syntax_valid`
4. `ReverseProxyTest::test_install_scripts_executable`

## 6. Acceptance Criteria

- [ ] nginx config passes `nginx -t`.
- [ ] Caddyfile passes `caddy validate`.
- [ ] Both configs support WebSocket at `/ws`.
- [ ] Both configs handle HLS `.m3u8` and `.ts` files.
- [ ] Both configs include security headers.
- [ ] nginx config includes rate limiting.
- [ ] nginx config includes gzip compression.
- [ ] Caddy config handles TLS automatically.
- [ ] Installer scripts work on Debian/Ubuntu.
- [ ] README documents all features.
- [ ] PHPStan level 9 clean.
- [ ] PHPCS PSR-12 clean.

## 7. Git ritual

```bash
cd /home/sites/phlex
git checkout master && git pull --ff-only origin master
git checkout -b o.5-rp-templates
# ... implement ...
nginx -t reverse-proxy/nginx/phlex.conf
caddy validate --config reverse-proxy/caddy/Caddyfile
./vendor/bin/phpstan analyze reverse-proxy/ --level=9 --no-progress
./vendor/bin/phpcs --standard=PSR12 reverse-proxy/
git add -A
git commit -m "Step O.5: nginx + Caddy reverse-proxy templates"
unset GITHUB_TOKEN
gh pr create --title "Step O.5: nginx + Caddy reverse-proxy templates" --body "..."
gh pr merge --squash --delete-branch
git checkout master && git pull --ff-only origin master
```

## 8. Reviewer hand-off

Review = Yes. Reviewer runs `o.5-rp-templates-review.md`.

(End of file - total 309 lines)