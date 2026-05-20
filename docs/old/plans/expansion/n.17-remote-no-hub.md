---
status: not-started
phase: N
updated: 2026-05-19
---

# Step N.17 — Remote Access WITHOUT the Hub (Cloudflare Tunnel, WireGuard, Tailscale)

**Phase:** N (End-User Documentation)
**Step:** N.17
**Depends on:** C.8 (public hostname — already merged)
**Review:** No (doc-only step)
**Target repo:** phlex-server (local: `/home/sites/phlex/`)
**One-liner:** Remote access WITHOUT the hub (Cloudflare Tunnel, WireGuard, Tailscale)

---

## Goal

Write the user-facing guide at `docs/advanced/remote-access-without-hub.md` covering three alternative methods to access a Phlex server remotely without using the hub relay: Cloudflare Tunnel (recommended), WireGuard VPN, and Tailscale VPN. Also covers manual port forwarding as a last resort.

---

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| §7 layout: TL;DR, shell blocks, what-can-go-wrong (3 failures), next-steps | Required structure for all Phase N end-user guides per PHLEX_EXPANSION_PLAN.md §7 | N.0 docs platform decision |
| Three main options: Cloudflare Tunnel, WireGuard, Tailscale | Cover the three most common self-host remote access patterns — reverse proxy, full VPN, and managed VPN | Industry best practice for self-hosters |
| Cloudflare Tunnel is recommended | No port forwarding, free, reliable, encrypted — easiest for most users | C.7/C.8 context |
| Port forwarding documented as last resort | Not recommended due to carrier-grade NAT, dynamic IPs, security risks | C.7 port-forward plan |
| What-can-go-wrong covers 4 distinct failures | Cloudflare token expiry, WireGuard UDP firewall, Tailscale re-auth, carrier-grade NAT | End-user ops experience |

---

## Phase 1: Draft `docs/advanced/remote-access-without-hub.md` [IN PROGRESS]

- [ ] **1.1** Read C.7 plan (`plans/expansion/c.7-port-forward.md`) and C.8 plan (`plans/expansion/c.8-public-hostname.md`) for context on remote access and hostname infrastructure
- [ ] **1.2** Check if `docs/advanced/remote-access-without-hub.md` already exists to avoid duplicating content
- [ ] **1.3** Draft `docs/advanced/remote-access-without-hub.md` (see §2 Content Outline below)
- [ ] **1.4** Self-review against §7 layout requirements: TL;DR, shell blocks, what-can-go-wrong (≥3 failures), next-steps

---

## Phase 2: Verification [PENDING]

- [ ] **2.1** Confirm all four §7 required sections are present (TL;DR, shell blocks, what-can-go-wrong, next-steps)
- [ ] **2.2** Confirm Cloudflare Tunnel install/auth/create/run/dns commands are accurate
- [ ] **2.3** Confirm WireGuard server and client config steps are accurate
- [ ] **2.4** Confirm Tailscale install, authenticate, and access steps are accurate (including Funnel option)
- [ ] **2.5** Confirm port forwarding fallback instructions are accurate (UPnP, manual, DDNS, public IP check)
- [ ] **2.6** Confirm "what can go wrong" covers ≥ 3 distinct failures with diagnostic commands
- [ ] **2.7** Proofread for clarity, accuracy, and tone suitable for end users (not developers)

---

## Phase 3: Commit [PENDING]

- [ ] **3.1** Branch: `git checkout -b n.17-remote-no-hub`
- [ ] **3.2** Commit: `git add docs/advanced/remote-access-without-hub.md && git commit -m "Step N.17: remote access without hub guide (end-user docs)"`
- [ ] **3.3** PR: `gh pr create --title "Step N.17: remote access without hub guide" --body "Writes docs/advanced/remote-access-without-hub.md covering Cloudflare Tunnel, WireGuard VPN, Tailscale VPN, and port forwarding fallback for remote access without the hub relay. Part of Phase N (Step N.17 of PHLEX_EXPANSION_PLAN.md)."`
- [ ] **3.4** Merge: `gh pr merge --squash --delete-branch`
- [ ] **3.5** Return to master: `git checkout master && git pull --ff-only origin master`

---

## §2 Content Outline for `docs/advanced/remote-access-without-hub.md`

### TL;DR

One-paragraph summary: why you might want remote access without the hub (privacy, avoiding third-party relay, no subscription required), what this guide covers (three methods: Cloudflare Tunnel, WireGuard, Tailscale, plus port forwarding as last resort), and the 30-second version: the hub relay is the easiest path — if you need to go without it, Cloudflare Tunnel is the recommended self-hosted option.

### 1. Why Access Without the Hub?

Explain the trade-offs:
- **Privacy**: All traffic stays between you and your server; nothing goes through Phlex's relay infrastructure
- **No third-party relay**: Removes the hub from the connection path entirely
- **Avoid subscription fees**: Hub relay may have usage limits or subscription tiers; self-hosted alternatives are free
- **Lower latency**: Direct connection can be faster than relay for geographically close clients

Note: The hub relay (C.6) remains the easiest setup. These alternatives require more configuration but give you full control.

### 2. Option 1: Cloudflare Tunnel (Recommended)

Cloudflare Tunnel (formerly `cloudflared`) creates a secure reverse proxy from your server to Cloudflare's edge — no open ports needed.

#### Install cloudflared:

```bash
curl -L https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-amd64 -o /usr/local/bin/cloudflared && chmod +x /usr/local/bin/cloudflared
```

#### Authenticate with Cloudflare:

```bash
cloudflared tunnel login
```

This opens a browser window to authenticate with your Cloudflare account and authorize the tunnel.

#### Create a tunnel:

```bash
cloudflared tunnel create phlex
```

Save the tunnel credentials file (shown in output — typically at `~/.cloudflared/{tunnel-id}.json`).

#### Configure the tunnel:

Create or edit `~/.cloudflared/config.yml`:

```yaml
tunnel: <your-tunnel-id>
credentials-file: /root/.cloudflared/<your-tunnel-id>.json

ingress:
  - hostname: server.yourdomain.com
    service: http://localhost:32400
  - service: http_status:404
```

Replace `<your-tunnel-id>` with the ID from the create step and `server.yourdomain.com` with your desired subdomain (must be a domain you control in Cloudflare).

#### Run the tunnel:

```bash
cloudflared tunnel run phlex
```

For production, run as a systemd service:

```bash
cloudflared service install
```

#### Route DNS:

```bash
cloudflared tunnel route dns phlex server.yourdomain.com
```

This creates a CNAME record in Cloudflare pointing to your tunnel.

#### Access your server:

Visit `https://server.yourdomain.com` — Cloudflare handles TLS and proxies requests to your server on port 32400.

### 3. Option 2: WireGuard (VPN)

WireGuard creates a full VPN tunnel. All traffic (not just HTTP) routes through the VPN. Higher security but requires VPN app on clients.

#### Server setup:

```bash
# Install WireGuard
apt install wireguard

# Generate server keypair
cd /etc/wireguard
umask 077
wg genkey > server_private.key
wg pubkey < server_private.key > server_public.key

# Create server config /etc/wireguard/wg0.conf
[Interface]
Address = 10.0.0.1/24
ListenPort = 51820
PrivateKey = <contents of server_private.key>
PostUp = iptables -A FORWARD -i wg0 -j ACCEPT; iptables -t nat -A POSTROUTING -o eth0 -j MASQUERADE
PostDown = iptables -D FORWARD -i wg0 -j ACCEPT; iptables -t nat -D POSTROUTING -o eth0 -j MASQUERADE

# Enable IP forwarding
echo "net.ipv4.ip_forward=1" >> /etc/sysctl.conf
sysctl -p
```

#### Generate client keys:

```bash
wg genkey > client_private.key
wg pubkey < client_private.key > client_public.key
```

#### Add client peer to server config:

Add to `/etc/wireguard/wg0.conf`:

```
[Peer]
PublicKey = <contents of client_public.key>
AllowedIPs = 10.0.0.2/32
```

#### Start WireGuard:

```bash
systemctl enable wg-quick@wg0
systemctl start wg-quick@wg0
```

#### Client config (export to client):

```ini
[Interface]
PrivateKey = <contents of client_private.key>
Address = 10.0.0.2/24
DNS = 1.1.1.1

[Peer]
PublicKey = <contents of server_public.key>
Endpoint = your-server-public-ip:51820
AllowedIPs = 0.0.0.0/0  # Route all traffic through VPN
PersistentKeepalive = 25
```

Import this config into the WireGuard app on the client device. Connect before accessing Phlex.

#### Access Phlex:

With the VPN active, access your server at `http://10.0.0.1:32400` (VPN tunnel address) or by LAN IP if on the same network.

### 4. Option 3: Tailscale (Simplest VPN)

Tailscale is a managed VPN that handles NAT traversal automatically. Easiest setup but requires a Tailscale account (free tier available).

#### Install Tailscale:

```bash
curl -fsSL https://tailscale.com/install.sh | sh
```

#### Authenticate:

```bash
tailscale up --accept-routes
```

This opens a browser for authentication. After auth, your device joins your tailnet.

#### Access Phlex:

Once connected, access your server at:

```
https://phlexMachineName.tailcale.mesh:32400
```

Replace `phlexMachineName` with the hostname of your Phlex server (run `hostname` on the server to find it).

#### Tailscale Funnel (public HTTP without port forwarding):

To make Phlex publicly accessible via your tailnet without port forwarding:

```bash
# Enable Funnel on port 32400
tailscale funnel 32400

# Check Funnel status
tailscale funnel status
```

Funnel exposes `https://phlexMachineName.tailcale.mesh:32400` to the public internet via Tailscale's relay — no router port forwarding needed.

### 5. Option 4: Port Forwarding (Last Resort)

Manual port forwarding is the fallback when VPN and tunnel solutions aren't available. Note: this method has significant limitations (see What Can Go Wrong).

#### Method A: UPnP (Automatic)

If your router supports UPnP:

```bash
# Check if UPnP is available
# Run the connectivity check script from C.7:
php scripts/check-connectivity.php

# Look for "UPnP IGD: Found" in output
# If found, the port may already be mapped automatically
```

#### Method B: Manual Port Forward

1. Find your server's LAN IP:
```bash
hostname -I | awk '{print $1}'
```

2. Log into your router (typically `http://192.168.1.1` or `http://192.168.0.1`)

3. Find the port forwarding / NAT / firewall section

4. Add a forward: external port `32400` → `<your-server-lan-ip>:32400` (TCP)

5. Save and apply

#### Method C: Dynamic DNS (for changing public IPs)

If your ISP gives you a dynamic (changing) public IP, use Dynamic DNS:

```bash
# Using Cloudflare API for DDNS
# Install ddns.sh or use a cron job:
# */5 * * * * curl -s "https://api.cloudflare.com/client/v4/zones/<zone-id>/dns_records/<record-id>" \
#   -X PUT -H "Authorization: Bearer <your-api-token>" \
#   -H "Content-Type: application/json" \
#   --data '{"data":"'"$(curl -s ifconfig.me)"'"}'
```

Or use a noip.com dynamic update script.

#### Check your public IP:

```bash
curl ifconfig.me
# or
curl icanhazip.com
```

Users outside your network then access: `http://<your-public-ip>:32400`

### 6. What Can Go Wrong

#### Failure 1: Cloudflare Tunnel token expired or revoked

**Symptom:** Tunnel connection drops; `cloudflared tunnel run` shows authentication errors.

**Diagnosis:**
```bash
# Check tunnel status:
cloudflared tunnel list

# Check tunnel logs:
journalctl -u cloudflared -n 50

# Test tunnel connectivity:
cloudflared tunnel ingress validate
```

**Fix:** Re-authenticate the tunnel:
```bash
cloudflared tunnel login
cloudflared tunnel run phlex
```

If credentials were revoked, recreate the tunnel:
```bash
cloudflared tunnel delete phlex
cloudflared tunnel create phlex
cloudflared tunnel route dns phlex server.yourdomain.com
cloudflared tunnel run phlex
```

---

#### Failure 2: WireGuard — firewall blocking UDP 51820

**Symptom:** Client connects but no traffic passes; `wg` shows "handshake did not complete."

**Diagnosis:**
```bash
# On the server, check if UDP 51820 is open:
ss -ulnp | grep 51820

# Check firewall rules:
iptables -L -n | grep 51820
ufw status
```

**Fix:**
```bash
# Allow UDP 51820 through firewall:
ufw allow 51820/udp

# Or for iptables:
iptables -A INPUT -p udp --dport 51820 -j ACCEPT

# On the client, check that AllowedIPs includes 0.0.0.0/0 (for full tunnel)
# and that Endpoint is correctly set to your server's public IP
```

Also verify the client has `PersistentKeepalive = 25` to maintain the connection through NAT.

---

#### Failure 3: Tailscale — device not showing up in tailnet

**Symptom:** Cannot reach server via `phlexMachineName.tailcale.mesh:32400`; device missing from Tailscale admin console.

**Diagnosis:**
```bash
# Check Tailscale status on server:
tailscale status

# Verify IP address:
tailscale ip -4

# Check if Funnel is enabled:
tailscale funnel status
```

**Fix:** Re-authenticate the device:
```bash
# Log out and back in:
tailscale logout
tailscale up --accept-routes

# If using Funnel, re-enable:
tailscale funnel 32400
```

Also ensure both client and server are on the same Tailscale network (same auth key or same organization).

---

#### Failure 4: Port forwarding — carrier-grade NAT (CGNAT)

**Symptom:** Port forwarding is configured but external connection fails; `curl ifconfig.me` shows an IP in the 100.x.x.x–100.127.x.x range.

**Diagnosis:**
```bash
# Check public IP range:
curl ifconfig.me

# If the IP starts with 100., you are behind CGNAT
# Try testing with an external port checker:
# Visit https://canyouseeme.org from outside your network
```

**Fix:** CGNAT cannot be worked around with port forwarding. Options:
1. **Use Cloudflare Tunnel or Tailscale** instead — these bypass CGNAT entirely
2. **Request a public IP** from your ISP (some offer this as a business service)
3. **Use a VPN** (WireGuard with a VPS relay) to tunnel out of CGNAT

### 7. Next Steps

- [Remote Access via the Hub](../hub/remote-access-via-hub.md) — the easiest remote access option using the hub relay
- [Claim Your Server's Public Hostname](../hub/claim-server.md) — set up `*.phlex.media` subdomain for your server
- [Self-Host the Hub](../hub/self-host-the-hub.md) — run your own hub instance for full control over authentication and relay
- [Server Connectivity Checklist](../advanced/server-connectivity.md) — verify your server is correctly configured for remote access
