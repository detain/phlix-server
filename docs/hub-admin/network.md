# Network Configuration (Hub Admin Guide)

Guide for hub administrators configuring network settings for Phlix server deployments.

## Port Forwarding

Phlix Media Server supports automatic port forwarding via UPnP-IGD and NAT-PMP to enable direct client connections without relay tunnel.

### Enabling Automatic Port Forwarding

In `config/port-forward.php`:

```php
<?php
return [
    'port_forwarding' => [
        'auto' => true,         // Set to false to disable
        'port' => 32400,       // Port for direct access
        'upnp_enabled' => true, // Set to false to skip UPnP
    ],
];
```

Or via environment variables:

```bash
PHLIX_PORT_FORWARD_AUTO=1
PHLIX_EXTERNAL_PORT=32400
PHLIX_UPNP_ENABLED=1
```

### Checking Server Connectivity

To verify a server's network accessibility:

```bash
php scripts/port-forward.php status
php scripts/port-forward.php info
```

These commands show:
- Current port forwarding status
- Local and public IP addresses
- Port accessibility (open/filtered/blocked)
- UPnP IGD discovery result
- Hostname candidates for client connections

### Relay Tunnel as Fallback

When automatic port forwarding fails or is unavailable, the relay tunnel provides connectivity:

```bash
PHLIX_RELAY_ENABLED=1
PHLIX_RELAY_HUB_URL=wss://hub.example.com/api/v1/servers/{id}/relay
```

See `docs/dev/relay-protocol.md` for relay tunnel protocol details.

## Network Requirements

### Outbound

| Destination           | Port | Protocol | Purpose |
| --------------------- | ---- | -------- | ------- |
| `stun.l.google.com`   | 19302 | UDP | STUN public IP discovery |
| Your Phlix Hub URL    | 443  | TCP | Hub heartbeat and relay |

### Inbound

| Port  | Protocol | Purpose |
| ------| -------- | ------- |
| 32400 | TCP | Media streaming and web portal (direct access) |

## Firewall Configuration

If your server is behind a firewall, ensure:

1. **Inbound TCP 32400** — Media streaming and web portal access
2. **Outbound UDP 19302** — STUN binding requests
3. **Outbound TCP 443** — Hub API and relay tunnel

### UFW Example

```bash
ufw allow 32400/tcp comment 'Phlix Media Server'
ufw allow out 19302/udp comment 'STUN'
ufw allow out 443/tcp comment 'Phlix Hub'
```

### firewalld Example

```bash
firewall-cmd --permanent --add-port=32400/tcp
firewall-cmd --permanent --add-port=19302/udp
firewall-cmd --permanent --add-port=443/tcp
firewall-cmd --reload
```

## Multi-Server Setups

Each server instance requires its own port forwarding rule and unique external port:

```php
// Server 1
'port_forwarding' => ['port' => 32400]

// Server 2
'port_forwarding' => ['port' => 32401]
```

Clients connect to `http://<server-public-ip>:<port>` directly.

## See Also

- `docs/hub/remote-access.md` — End-user guide for setting up direct access
- `docs/dev/relay-protocol.md` — Relay tunnel protocol reference
- `php scripts/port-forward.php help` — Port forwarding CLI commands
