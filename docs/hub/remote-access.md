# Remote Access Without Relay Tunnel

Phlex Media Server supports direct internet access to your media library without requiring the relay tunnel. This guide explains how to set up and verify direct connectivity.

## Overview

When your server is reachable from the internet directly (via port forwarding on your router), clients can connect straight to your server rather than going through the relay tunnel. This typically provides:

- Lower latency for playback
- Higher quality streams (no transcoding at relay)
- Reduced infrastructure dependency

## How Port Forwarding Works

### UPnP-IGD (Universal Plug and Play - Internet Gateway Device)

UPnP-IGD allows devices on your LAN to automatically configure port mappings on your router. Phlex uses this to:

1. **Discovery** — Sends an SSDP M-SEARCH multicast to `239.255.255.250:1900` to find UPnP-capable routers.
2. **External IP detection** — Queries the router's `GetExternalIPAddress` SOAP action.
3. **Port mapping** — Calls `AddPortMapping` to open the configured port (default: 32400) on the router.

### NAT-PMP (NAT Port Mapping Protocol)

For Apple AirPort routers and other NAT-PMP-compatible devices, Phlex falls back to RFC 6886 NAT-PMP:

1. Sends a public address request to the router's LAN IP on UDP port 5350.
2. Uses the response to determine the external IP.
3. Sends a map request to open the port.

### STUN (Session Traversal Utilities for NAT)

STUN (RFC 5389) is used to:

1. **Discover your public IP** — Sends a binding request to `stun.l.google.com:19302` (configurable) and reads the XOR-MAPPED-ADDRESS from the response.
2. **Test port accessibility** — Attempts a TCP connection to your public IP on the configured port to verify the mapping is working.

## Setting Up Port Forwarding

### Automatic (Recommended)

Run the port-forward script with `enable`:

```bash
php scripts/port-forward.php enable
```

If UPnP is available on your router, the script will automatically open the port and report the public endpoint.

### Manual Configuration

If automatic discovery fails, you can configure port forwarding manually:

1. Log in to your router's admin panel (typically `http://192.168.1.1` or `http://192.168.0.1`).
2. Find **Port Forwarding**, **NAT**, or **Firewall** settings.
3. Create a new rule:

   | Field     | Value                 |
   | --------- | --------------------- |
   | Protocol  | TCP                   |
   | Ext Port  | 32400 (or your choice) |
   | Int Port  | 32400                 |
   | Int IP    | Your server's LAN IP  |

4. Save and apply. Your router may need a restart.

To find your server's LAN IP, run:

```bash
php scripts/port-forward.php info
```

### Verify the Port is Open

After configuring, verify your port is accessible:

```bash
php scripts/port-forward.php info
```

Look for the `Port 32400 on <public-ip>` line. It should show `OPEN` if the mapping succeeded.

You can also use an external port checker like [you-get-signal.com](https://www.yougetsignal.com/tools/open-ports/).

## Troubleshooting

### UPnP Discovery Fails

- **Cause:** Router does not support UPnP-IGD or has it disabled.
- **Fix:** Enable UPnP in your router's settings, or use manual port forwarding.

### Port Shows BLOCKED/FILTERED

- **Cause:** Router did not apply the port mapping, or a firewall is blocking the port.
- **Fix:** Verify the port forwarding rule is active in your router. Check that your server's firewall allows incoming connections on the configured port.

### STUN Returns No Public IP

- **Cause:** Firewall or symmetric NAT preventing STUN from working.
- **Fix:** Use manual port forwarding and ensure your router supports UPnP or NAT-PMP.

## Using with the Hub

When direct access is available, your server includes hostname candidates in heartbeats to the hub:

- `http://<lan-ip>:32400` — Local network access
- `http://phlex.local:32400` — mDNS/local hostname
- `http://<public-ip>:32400` — Direct internet access (when port is open)

The hub uses these candidates to determine the best connection method for clients.

## See Also

- `docs/hub-admin/network.md` — Network configuration for hub administrators
- `php scripts/port-forward.php help` — All port-forward commands
- `docs/dev/relay-protocol.md` — Relay tunnel protocol (fallback when direct access is unavailable)
