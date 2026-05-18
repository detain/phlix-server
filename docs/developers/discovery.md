# SSDP + mDNS Discovery

**Phase:** J (DLNA / Cast / Discovery)
**Since:** 0.12.0

## Overview

Phlex uses two network discovery protocols to detect devices on the local network:

- **SSDP (Simple Service Discovery Protocol)** — UDP multicast to `239.255.255.250:1900`
- **mDNS (multicast DNS / Bonjour/Avahi)** — UDP multicast to `224.0.0.251:5353`

## SSDP (UPnP/DLNA Discovery)

### Protocol Details

SSDP is part of the UPnP protocol suite and uses HTTP-like messages over UDP.

**Multicast Address:** `239.255.255.250`
**Port:** `1900`

### Message Types

#### M-SEARCH (Discovery Request)

```
M-SEARCH * HTTP/1.1
HOST: 239.255.255.250:1900
MAN: "ssdp:discover"
MX: 3
ST: urn:schemas-upnp-org:device:MediaServer:1
USER-AGENT: Phlex/1.0
```

#### NOTIFY (Announcement)

```
NOTIFY * HTTP/1.1
HOST: 239.255.255.250:1900
NT: urn:schemas-upnp-org:device:MediaServer:1
USN: uuid:phlex-server-{id}::urn:schemas-upnp-org:device:MediaServer:1
LOCATION: http://{ip}:{port}
SERVER: Phlex/1.0 UPnP/1.0
CACHE-CONTROL: max-age=1800
```

### Search Targets (ST)

| Device Type | Search Target |
|-------------|---------------|
| All UPnP devices | `urn:schemas-upnp-org:device:*` |
| MediaServer | `urn:schemas-upnp-org:device:MediaServer:1` |
| MediaRenderer | `urn:schemas-upnp-org:device:MediaRenderer:1` |
| ContentDirectory | `urn:schemas-upnp-org:service:ContentDirectory:1` |
| ConnectionManager | `urn:schemas-upnp-org:service:ConnectionManager:1` |
| AVTransport | `urn:schemas-upnp-org:service:AVTransport:1` |

## mDNS (Bonjour/Avahi Discovery)

### Protocol Details

mDNS uses DNS-like packets on the local network without a central DNS server.

**Multicast Address:** `224.0.0.251`
**Port:** `5353`

### Service Types

| Service | Type String |
|---------|-------------|
| Google Cast/Chromecast | `_googlecast._tcp.local.` |
| AirPlay 2 | `_airplay._tcp.local.` |
| AirPlay Audio | `_raop._tcp.local.` |
| Roku ECP | `_ roku-ecnp._tcp.local.` |

### DNS Record Types

| Type | Number | Purpose |
|------|--------|---------|
| PTR | 12 | Service discovery (pointer to instance) |
| SRV | 33 | Service location (host + port) |
| TXT | 16 | Service metadata (key-value pairs) |
| A | 1 | IPv4 address |
| AAAA | 28 | IPv6 address |

## Architecture

### Classes

```
src/Discovery/
├── Ssdp/
│   ├── SsdpSocket.php       # Raw UDP socket for SSDP
│   ├── SsdpDevice.php      # Discovered device descriptor
│   └── SsdpDiscovery.php    # SSDP discovery service
├── Mdns/
│   ├── MdnsSocket.php       # Raw UDP socket for mDNS
│   ├── MdnsService.php      # Resolved service descriptor
│   └── MdnsDiscovery.php    # mDNS discovery service
├── DiscoveryManager.php      # Unified facade
└── DiscoveryServer.php       # Workerman Timer integration
```

### DiscoveryManager

The `DiscoveryManager` provides a unified interface for all discovery operations:

```php
$manager->discoverDlnaServers();      // SSDP MediaServers
$manager->discoverDlnaRenderers();    // SSDP MediaRenderers
$manager->discoverChromecastDevices(); // mDNS Chromecast
$manager->discoverAirPlayDevices();    // mDNS AirPlay
$manager->discoverRokuDevices();       // mDNS Roku
$manager->announcePhlexServer();       // Both SSDP + mDNS
```

## Configuration

`config/discovery.php`:

```php
return [
    'ssdp' => [
        'enabled' => true,
        'announce_interval_secs' => 600,  // SSDP NOTIFY interval
        'discovery_timeout_secs' => 5,
    ],
    'mdns' => [
        'enabled' => true,
        'discovery_timeout_secs' => 5,
    ],
    'discovery_port' => 8200,  // Phlex server port
];
```

## Integration

### Workerman Timer

`DiscoveryServer` uses Workerman's `Timer` to periodically refresh device lists:

- SSDP discovery: every 60 seconds
- mDNS discovery: every 30 seconds

### Start/Stop

```php
$discoveryServer = $container->get(\Phlex\Discovery\DiscoveryServer::class);
$discoveryServer->start();

// Later...
$discoveryServer->stop();
```

### Device Registry

Discovered devices are stored in `Dlna\DeviceRegistry` for use by other Phase J components (AVTransport, etc.).

## Socket Options

Both SSDP and mDNS sockets use these multicast options:

| Option | SSDP | mDNS |
|--------|------|------|
| Multicast TTL | 1 | 255 |
| Loopback | Enabled | Enabled |
| Reuse Address | Yes | Yes |
| Receive Timeout | Configurable | Configurable |

## Error Handling

All socket operations are wrapped in try-catch blocks:

- Network errors return empty arrays
- Invalid responses are skipped
- Errors are logged via PSR-3 logger

## Testing

Run unit tests:

```bash
./vendor/bin/phpunit tests/unit/Discovery/
```

Coverage targets:
- `SsdpSocket` ≥ 85%
- `MdnsSocket` ≥ 85%
- `SsdpDiscovery` ≥ 80%
- `MdnsDiscovery` ≥ 80%
- `DiscoveryManager` ≥ 80%
