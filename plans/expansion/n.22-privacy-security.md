# Step N.22 — Privacy & Security Guide

**Phase:** N (End-User Documentation)
**Step:** N.22
**Depends on:** N.0 (docs platform)
**Review:** No (doc-only step)
**Target repo:** phlex-server (local: /home/sites/phlex/)

## 1. Goal

Write the privacy & security guide at `docs/privacy-security.md`, using the §7 one-screen layout (TL;DR → telemetry/local-data → hub-visibility → hardening → what-can-go-wrong → next-steps).

## 2. Context

- No privacy or security guide currently exists in `docs/`
- Branch `n.22-privacy-security` will be cut from `master`
- This is a doc-only step — no feature implementation changes
- The §7 docs tree layout specifies the `docs/privacy-security.md` page
- Reference format: `n.19-troubleshooting.md` uses the same §7 layout with what-can-go-wrong sections

## 3. Scope

### New file

- `docs/privacy-security.md` — Privacy & security guide

## 4. Content outline

### TL;DR

One paragraph: Phlex is privacy-first. No telemetry, no analytics, no third-party data sharing. Media stays on your hardware. Hub relay is end-to-end encrypted. The guide below explains exactly what is and is not collected, what the hub can and cannot see, and how to harden your deployment.

End with shell block:
```bash
# Verify no external network calls are made by the server (drop all egress except DNS/80/443)
# Example iptables rule (run on host):
iptables -A OUTPUT -p udp --dport 53 -j ACCEPT
iptables -A OUTPUT -p tcp --dport 80 -j ACCEPT
iptables -A OUTPUT -p tcp --dport 443 -j ACCEPT
iptables -A OUTPUT -j DROP
```

---

### What is collected (local only)

**Watch history per profile (local DB)**
- Stored in `playback_state` / `watch_history` tables in the local MySQL DB
- Tied to user profile; not associated with any external identity
- Never leaves the server unless the user explicitly exports it

**Server logs (local file)**
- Written to `.logs/` directory on the local filesystem
- Rotated via Monolog rotating file handler
- Not sent to any external log aggregator by default

**What is NOT collected**
- No viewing habits sent to any third party
- No device IDs shared externally
- No media filenames transmitted anywhere
- No analytics, crash reports, or usage telemetry

---

### Hub data visibility

**Hub sees:**
- User email (used for account identity and server claim status)
- Server claim status (claimed / unclaimed)
- Server version string (for compatibility checks)
- Relay session metadata: WebSocket frame timing, connection duration, session token (NOT media content or filenames)

**Hub does NOT see:**
- Media filenames or folder structure
- Playback history or watch history
- Media content or stream content
- Library metadata (genres, descriptions, actors)
- Any local DB content

**Hub relay encryption:**
- WebSocket frames between server and hub are end-to-end encrypted
- Hub terminates the TLS connection and acts as a relay — it cannot decrypt the WebSocket payload
- The hub only sees encrypted binary frames and connection metadata (IP address, timing)

---

### Security hardening checklist

#### Change JWT_SECRET immediately
- Ships with a default `JWT_SECRET` in `config/server.php` / environment
- Anyone who knows the default can forge valid JWTs and access the server
- Fix: Set a strong, random secret at first run:
```bash
# Generate a cryptographically secure secret
openssl rand -hex 32
# Add to environment or config/server.php as JWT_SECRET=<value>
```

#### Use TLS (reverse proxy with valid cert)
- HTTP port 32400 transmits credentials in clear text when not behind TLS
- Fix: Put a reverse proxy (nginx, Caddy, Traefik) in front of Phlex with a valid TLS certificate (Let's Encrypt recommended):
```bash
# Example Caddyfile snippet
phlex.example.com {
  reverse_proxy localhost:32400
  tls admin@example.com
}
```

#### Firewall: only expose what is needed
- Default: expose 32400 (HTTP API) + 1900 (DLNA, optional)
- All other ports should be blocked from ingress
```bash
# Allow only HTTP and optional DLNA
ufw allow 32400/tcp comment "Phlex HTTP API"
ufw allow 1900/udp comment "DLNA discovery (optional)"
ufw enable
```

#### Disable DLNA if not used
- DLNA discovery broadcasts on port 1900/UDP to the local network
- If no DLNA/play-to clients are used, disable it to reduce attack surface:
```bash
# In config/server.php or via UI:
' dlna' => ['enabled' => false]
```

#### Strong admin password
- Passwords hashed with Argon2ID (12 MiB memory, 3 iterations, 4 parallelism)
- Minimum recommended: 12+ characters, mixed case, digits, symbols
- Never reuse your hub account password for the server admin account

#### Hub claim: cryptographic validation
- Server validates hub JWT using hub's JWKS endpoint — no shared secret
- The hub cannot impersonate a server and servers cannot impersonate each other via the hub
- Verify the hub's JWKS URL in `config/server.php` under the hub integration section

---

### Remote access privacy

**VPN / blockchain remote access**
- Traffic stays off the public internet when using VPN or blockchain-based remote access
- No port forwarding required; connection is outbound from server to VPN/blockchain relay
- Content is encrypted end-to-end; relay sees only encrypted tunnel metadata

**Cloud transcoding**
- Disabled by default — all transcoding is local to the server hardware
- No media is sent to a cloud service for transcoding or analysis

**Media files**
- Always remain on the user's own hardware
- No media is uploaded to any external service

---

### What can go wrong

**Default JWT_SECRET in production**
- Symptom: Unauthorized users can create valid JWT tokens and access all API endpoints, including admin functions
- Cause: Production deployment left the default `JWT_SECRET` value unchanged
- Fix: Immediately set a strong random secret; rotate all existing sessions by restarting the server; enable an audit log to identify any unauthorized access that may have occurred

**Exposed port 32400 without TLS**
- Symptom: Login credentials, session tokens, and media streaming data are visible in clear text on the network
- Cause: Reverse proxy or TLS termination not configured; direct HTTP access to port 32400
- Fix: Configure a TLS-terminating reverse proxy; force all clients to use HTTPS; revoke affected sessions and force re-authentication

**Hub account compromised (same password as server)**
- Symptom: Attacker uses hub credentials to access the server admin panel, or uses server credentials to access the hub
- Cause: Password reuse between hub account and server admin account; no MFA on hub account
- Fix: Use different passwords for hub account and server admin account; enable MFA on the hub account; audit recent sessions in the server audit log

**Exposed port 1900 (DLNA) without network isolation**
- Symptom: DLNA clients on the local network can discover and request media from the server without authentication
- Cause: Port 1900/UDP open to the local network without authentication; no network segmentation
- Fix: Disable DLNA if unused; if needed, restrict to a dedicated VLAN with firewall rules that only allow known DLNA clients

**No egress filtering (phoning home)**
- Symptom: Server makes outbound connections to unknown external IPs (metadata providers, update checks, etc.)
- Cause: Egress not restricted; metadata refresh or update checker making external calls
- Fix: Apply strict egress rules (only DNS/80/443 outbound); disable metadata auto-refresh if privacy-sensitive; verify with `tcpdump -i eth0 -n 'ip and tcp' -A | grep -v 'your-phlex'`

---

### Next steps

- [First-run setup](docs/first-run.md) — initial server configuration and TLS setup
- [Hub claim and setup](docs/hub-claim.md) — understanding what the hub can and cannot do
- [Remote access without hub](docs/remote-no-hub.md) — VPN/blockchain-based remote access options
- [Troubleshooting](docs/troubleshooting.md) — diagnose connection and access issues

## 5. Git ritual

```bash
# ─── 0. PRECONDITION ───
cd /home/sites/phlex
git status --short
git branch --show-current
git pull --ff-only origin master

# ─── 1. Branch ───
git checkout -b n.22-privacy-security

# ─── 2. Do the work ───
# Create docs/privacy-security.md following the §7 one-screen layout
# TL;DR → telemetry/local-data → hub-visibility → hardening checklist
# → what-can-go-wrong (3+ failures) → next-steps

# ─── 3. Verify ───
# Verify file has TL;DR, telemetry section, hub visibility section,
# security hardening checklist with shell blocks, what-can-go-wrong (3 failures),
# and next-steps links

# ─── 4. Caliber sync ───
git add -A

# ─── 5. Commit ───
git commit -m "Step N.22: Privacy & security guide"

# ─── 6. CRITICAL ───
unset GITHUB_TOKEN

# ─── 7. PR + merge ───
gh pr create \
  --title "Step N.22: Privacy & security guide" \
  --body  "Adds docs/privacy-security.md following the §7 one-screen layout (TL;DR, telemetry, hub visibility, hardening checklist, what-can-go-wrong, next-steps). Part of Phase N (Step N.22 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch

# ─── 8. Return to master ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION ───
git status --short
git branch --show-current
git log --oneline -1
git branch -d n.22-privacy-security
```
