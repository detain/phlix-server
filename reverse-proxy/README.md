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

### DASH Streaming
`.mpd` files are handled with no-cache headers.

### Security Headers
- X-Frame-Options
- X-Content-Type-Options
- X-XSS-Protection
- HSTS
- Referrer-Policy
- Permissions-Policy

### Rate Limiting
nginx: 10 req/s for API, 30 req/s for streaming

### Gzip Compression
Both configs enable gzip compression for text-based responses.

## Media Streaming

Phlex streams media in HLS (`.m3u8` + `.ts`) and DASH (`.mpd`) formats. The reverse-proxy configs optimize these:

| Format | Cache | CORS |
|--------|-------|------|
| HLS Playlist (.m3u8) | No cache | Yes |
| HLS Segment (.ts) | 1 year | Yes |
| DASH Manifest (.mpd) | No cache | Yes |

## Backend Configuration

Update the upstream backend address if Phlex is not running on localhost:8080:

```
upstream phlex_backend {
    server 192.168.1.100:8080;
}
```

## Troubleshooting

### 502 Bad Gateway

Check if Phlex is running:
```bash
curl http://localhost:8080/health
```

### WebSocket not working

Ensure the WebSocket headers are being passed:
```nginx
proxy_set_header Upgrade $http_upgrade;
proxy_set_header Connection "upgrade";
```

### Slow streaming

Check the streaming buffer settings in the config.
