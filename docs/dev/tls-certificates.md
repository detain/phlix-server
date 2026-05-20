# TLS Certificates

## Overview

Phlix Media Server supports TLS certificates for secure HTTPS communication.
When a server is enrolled with a hub that has subdomain allocation (C.8),
the server can obtain a TLS certificate for its public hostname.

## Certificate Sources

### 1. Hub-Provisioned Certificates (Recommended)

When a server enrolls with a hub that supports subdomain allocation,
the hub provisions a Let's Encrypt certificate for the server's subdomain
(e.g., `abc12345.phlix.media`).

**Flow:**
1. Server enrolls with hub (C.3)
2. Server claims subdomain via `POST /api/v1/servers/{id}/subdomain`
3. Hub provisions Let's Encrypt certificate via ACME DNS-01 challenge
4. Hub returns certificate paths in the response
5. Server stores certificates locally

**Certificate Storage:**
- Certificate: `config/tls/{subdomain}.phlix.media/fullchain.pem`
- Private key: `config/tls/{subdomain}.phlix.media/privkey.pem`

**Renewal:**
- Hub automatically renews certificates 60 days before expiry
- Server fetches updated certificates on next enrollment refresh

### 2. Self-Signed Certificates

For development or environments without hub subdomain allocation,
self-signed certificates can be used.

**Generation:**
```bash
openssl req -x509 -newkey rsa:2048 -keyout key.pem -out cert.pem -days 365 -nodes
```

**Configuration:**
```php
// config/hub.php
return [
    'tls_enabled' => true,
    'tls_cert_path' => __DIR__ . '/tls/cert.pem',
    'tls_key_path' => __DIR__ . '/tls/key.pem',
];
```

## Environment Variables

| Variable         | Default | Description |
| ---------------- | ------- | ----------- |
| `PHLIX_TLS_ENABLED` | `1`   | Enable TLS for the server |
| `PHLIX_DOMAIN`   | `phlix.media` | Base domain for subdomains |

## Certificate Scripts

### Claim Subdomain

```bash
php scripts/claim-subdomain.php
```

Output:
```
Allocated subdomain: abc12345.phlix.media
Certificate: /home/phlix/config/tls/abc12345.phlix.media.crt
Key: /home/phlix/config/tls/abc12345.phlix.media.key
```

## Security Considerations

- Private keys should have restricted permissions (0600)
- Certificates are stored in `config/tls/` which should be backupped
- Let's Encrypt certificates expire after 90 days
- Hub-managed certificates are automatically renewed
- Self-signed certificates should only be used in development
