# Phlix Reverse-Proxy Templates

This directory contains reverse-proxy configurations for nginx and Caddy to front Phlix Media Server.

## Why a Reverse Proxy?

- TLS termination (HTTPS)
- TLS fronting for the WebSocket worker (as its own TCP route — see Features)
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
sudo cp reverse-proxy/nginx/phlix.conf /etc/nginx/sites-available/
```

2. Edit `/etc/nginx/sites-available/phlix.conf`:
   - Set `server_name` to your domain
   - Update SSL certificate paths
   - Update upstream backend address

3. Enable and reload:
```bash
sudo ln -s /etc/nginx/sites-available/phlix.conf /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

### SSL Certificate

For Let's Encrypt:
```bash
sudo certbot --nginx -d phlix.example.com
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

### WebSocket Support — separate TCP route, NOT a proxied path
The SyncPlay WebSocket server is its own plaintext listener on **:8097**
(`start.php` binds `websocket://0.0.0.0:8097`); the HTTP worker on **:8096**
has zero WebSocket-upgrade logic. Both templates therefore deliberately
omit any `/ws` location/handler pointing at the HTTP upstream — such a rule
accepts the TCP connection, answers the Upgrade handshake with plain HTTP,
and every WS client fails to connect.

Expose :8097 as its own TCP route instead:

- publish/forward port 8097 (firewall, LB, or host networking) and point WS
  clients at `ws://<host>:8097/` — or
- terminate TLS on a dedicated listener (Caddy site block / HAProxy TCP
  frontend — see `deploy/haproxy.cfg`) forwarding to `127.0.0.1:8097`.

Use long read/write timeouts (WS connections are idle-long by design; the
templates use 24h) and no path routing (the worker speaks the WS handshake
at any path; auth rides the `?token=` query parameter).

> **Future note:** a same-origin `wss://` path is being designed alongside a
> client-side `wsUrl` seam in `@phlix/ui` (sibling lane). When that ships,
> these templates will gain the real upgrade routing. Until then, do not add
> `/ws` blocks against :8096 — they cannot work.

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
nginx: 10 req/s (`limit_req zone=api_limit`) applied server-wide. A second
`stream_limit` zone (30 req/s) is declared in the template but intentionally
not attached to any streaming location — applying it to HLS segments risks
starving bursty multi-variant playback; attach it yourself (`limit_req zone=stream_limit …`)
inside the `.ts`/`.m3u8` locations if your threat model wants it.

### Gzip Compression
Both configs enable gzip compression for text-based responses.

## Media Streaming

Phlix streams media in HLS (`.m3u8` + `.ts`) and DASH (`.mpd`) formats. The reverse-proxy configs optimize these:

| Format | Cache | CORS |
|--------|-------|------|
| HLS Playlist (.m3u8) | No cache | Yes |
| HLS Segment (.ts) | 1 year | Yes |
| DASH Manifest (.mpd) | No cache | Yes |

## Backend Configuration

The templates proxy to the HTTP worker's real port, **8096** (`start.php:203`).
Update the upstream backend address if Phlix is not running on localhost:8096:

```
upstream phlix_backend {
    server 192.168.1.100:8096;
}
```

The WebSocket worker (:8097) is NOT part of this upstream — see
"WebSocket Support" above.

## Troubleshooting

### 502 Bad Gateway

Check if Phlix is running:
```bash
curl http://localhost:8096/health
```

### WebSocket not working

The WS server is NOT behind the HTTP vhost. Check the :8097 listener itself:
```bash
# 101 = a real handshake succeeded; 400 = listener alive but the probe was
# not a WS upgrade (Workerman rejects plain HTTP on :8097 with 400).
curl -s -o /dev/null -w '%{http_code}\n' \
  -H "Connection: Upgrade" -H "Upgrade: websocket" \
  -H "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==" \
  -H "Sec-WebSocket-Version: 13" http://127.0.0.1:8097/
```
If that works but clients still fail, you are missing the separate TCP route
for :8097 (see "WebSocket Support" above) — no amount of `proxy_set_header
Upgrade` on the :8096 vhost will help, because :8096 does not speak WS.

### Slow streaming

Check the streaming buffer settings in the config.
