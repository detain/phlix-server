# Step N.28 — Hub-Admin Capacity Planning Guide

**Phase:** N (End-User Documentation)
**Step:** N.28
**Depends on:** C.6 (relay tunnel — already merged)
**Review:** No (doc-only step)
**Target repo:** phlex-hub (local: /home/sites/phlex-hub/)

## 1. Goal

Write the hub-admin capacity planning guide at `docs/hub-admin/capacity-planning.md`, using the §7 one-screen layout (TL;DR → sizing → relay architecture → config knobs → fair-use → what-can-go-wrong → next-steps).

## 2. Context

- No capacity planning guide currently exists in `docs/hub-admin/`
- Branch `n.28-hub-admin-capacity` will be cut from `master`
- This is a doc-only step — no feature implementation changes
- The §7 docs tree layout specifies the `docs/hub-admin/capacity-planning.md` page
- Reference format: `n.22-privacy-security.md` uses the same §7 layout with what-can-go-wrong sections
- C.6 (relay tunnel) and C.8 (public hostname) are already merged and provide the technical foundation

## 3. Scope

### New file

- `docs/hub-admin/capacity-planning.md` — Hub-admin capacity planning guide

## 4. Content outline

### TL;DR

One paragraph: Phlex Hub is lightweight by design — a single relay connection per enrolled server, with bandwidth math that's simple and predictable. Small deployments need only 2 vCPU / 4 GB RAM; large deployments scale to 8 vCPU / 16 GB RAM. Relay bandwidth is the primary cost driver: each concurrent remote stream uses 2–8 Mbps depending on quality settings. Use the tiered sizing guide below to pick the right hardware, then tune `HUB_MAX_RELAY_SESSIONS` and the per-session rate cap to match your user's actual demand.

End with shell block showing the five config knobs and their defaults:
```bash
# Required: tune these in your hub environment or config file
HUB_RELAY_ENABLED=true
HUB_MAX_RELAY_SESSIONS=100          # max concurrent relay sessions across all servers
HUB_RELAY_RATE_LIMIT_PER_SESSION_Mbps=20   # max Mbps per individual relay session
HUB_MAX_SERVERS=1000                 # max enrolled servers per hub instance
HUB_MAX_USERS=10000                  # max hub users
```

---

### Hardware sizing guide

Three tiers as shell blocks with copy-paste-ready specs:

**Small (≤10 servers, ≤50 users)**
```bash
# Suitable for home labs, small teams, or proof-of-concept
2 vCPU
4 GB RAM
20 GB SSD
~100 Mbps uplink (supports ~12 concurrent 8 Mbps relay streams)
```
- Relay session estimate: 2–8 Mbps per stream; 10–15 users watching remotely at once
- Use case: personal use with family or small friend group

**Medium (≤50 servers, ≤500 users)**
```bash
# Suitable for a team or community of active users
4 vCPU
8 GB RAM
50 GB SSD
~500 Mbps uplink (supports ~60 concurrent 8 Mbps relay streams)
```
- Relay session estimate: supports 50–100 concurrent remote streams
- Use case: office or club with shared media library; multiple simultaneous viewers

**Large (≤200 servers, ≤5000 users)**
```bash
# Suitable for organizations, communities, or high-traffic public hubs
8 vCPU
16 GB RAM
100 GB NVMe
~1 Gbps uplink (supports ~125 concurrent 8 Mbps relay streams)
```
- Relay session estimate: supports 200+ concurrent remote streams at full quality
- Use case: large community or organization with power users and high concurrent viewership

**Scaling horizontally**
- If RAM pressure is the bottleneck (too many concurrent relay sessions), add a second hub instance behind a load balancer
- Relay sessions are stateful (WS channels within a server's persistent connection) — sticky sessions are required
- Coordinate session affinity via `HUB_MAX_SERVERS` per instance to split server enrollment evenly

---

### Relay architecture (how bandwidth works)

**Connection topology**
- Each enrolled server opens one persistent WSS connection to the hub (always-on, not per-session)
- The hub multiplexes inbound client requests over that single WSS using HTTP-framed messages
- Each relay session = one virtual WS channel within the server's persistent connection
- Bandwidth at the hub = sum of all active relay session bitrates (not one connection per session)

**Latency overhead**
- Relay adds ~1–3 RTT overhead vs. a direct LAN connection
- For geographically distant servers, deploy the hub in the region closest to the majority of users
- WSS persistent connection eliminates connection setup latency per relay session

**Bandwidth math**
```bash
# Quick relay bandwidth estimator
# Replace with your expected concurrent streams and quality setting
concurrent_streams=20
stream_quality_mbps=6   # 2 (low) / 6 (medium) / 8 (high) / vary by library
total_mbps=$((concurrent_streams * stream_quality_mbps))
echo "Estimated hub uplink needed: ${total_mbps} Mbps"
# Add 20% headroom for protocol overhead
headroom_mbps=$((total_mbps * 120 / 100))
echo "With headroom: ${headroom_mbps} Mbps"
```

**Direct LAN vs relay**
- Streams on the same LAN go directly server → client (no hub involvement, no bandwidth cost)
- Only remote streams (client not on LAN) traverse the hub relay
- This means hub bandwidth cost scales with remote viewership, not total viewership

---

### Config knobs

Full reference table for operators:

| Variable | Default | Description |
|---|---|---|
| `HUB_RELAY_ENABLED` | `true` | Set to `false` to disable relay entirely (servers still enroll but no relay traffic flows) |
| `HUB_MAX_RELAY_SESSIONS` | `100` | Max concurrent relay sessions across all enrolled servers |
| `HUB_RELAY_RATE_LIMIT_PER_SESSION_Mbps` | `20` | Max Mbps allowed per individual relay session (throttling, not capping quality) |
| `HUB_MAX_SERVERS` | `1000` | Max enrolled servers per hub instance |
| `HUB_MAX_USERS` | `10000` | Max hub users |

**Tuning `HUB_MAX_RELAY_SESSIONS`**
```bash
# Monitor active relay sessions in the hub admin panel or via logs
# If sessions hit the cap, users see connection failures or queueing
# Increase HUB_MAX_RELAY_SESSIONS or scale horizontally to add a second hub

# Example: split server enrollment across two hub instances
# Hub-1: HUB_MAX_SERVERS=500
# Hub-2: HUB_MAX_SERVERS=500
```

**Tuning per-session rate limit**
```bash
# Default 20 Mbps supports 1080p high-quality streams comfortably
# Lower to 8 Mbps for constrained uplinks or free-tier server owners
# Raise to 50+ only if you have high-bandwidth servers and 4K HDR streams
HUB_RELAY_RATE_LIMIT_PER_SESSION_Mbps=8   # budget uplink or free tier
HUB_RELAY_RATE_LIMIT_PER_SESSION_Mbps=20  # default — 1080p high quality
HUB_RELAY_RATE_LIMIT_PER_SESSION_Mbps=50  # 4K HDR streams
```

---

### Fair-use policy

**Anonymous / unclaimed servers**
- Anonymous users cannot use relay (must claim a server and accept Terms of Service)
- Enforced via `HUB_RELAY_ENABLED=false` for unclaimed sessions at the hub layer

**Free tier**
```bash
HUB_MAX_SERVERS=3              # max 3 enrolled servers
HUB_MAX_USERS=5               # max 5 hub users
HUB_MAX_RELAY_SESSIONS=2      # max 2 concurrent relay streams total
HUB_RELAY_RATE_LIMIT_PER_SESSION_Mbps=2    # 2 Mbps cap (480p–720p)
```
- Relay abuse threshold: servers exceeding 100 GB/month relay traffic are flagged for review
- Review action: hub admin contacts server owner; may suspend relay or upgrade to paid tier

**Paid tier**
```bash
HUB_MAX_SERVERS=unlimited
HUB_MAX_USERS=unlimited
HUB_MAX_RELAY_SESSIONS=10     # max 10 concurrent relay streams
HUB_RELAY_RATE_LIMIT_PER_SESSION_Mbps=20  # 20 Mbps cap (1080p high quality)
```

**Abuse detection**
```bash
# Hub operators: monitor relay traffic per enrolled server
# Query the hub's session logs for servers with >100 GB/month
# Flag in admin panel; trigger review workflow
SELECT server_id, SUM(bytes_transferred) AS total_gb
FROM relay_session_logs
WHERE month = CURRENT_MONTH
GROUP BY server_id
HAVING total_gb > 100;
```

---

### What can go wrong

**Hub OOM under load (too many concurrent relay sessions)**
- Symptom: Hub process crashes; all relay sessions drop; servers show "relay unavailable"
- Cause: `HUB_MAX_RELAY_SESSIONS` set too high for available RAM; each WS channel consumes ~10–50 MB depending on stream bitrate and buffer size
- Fix: Reduce `HUB_MAX_RELAY_SESSIONS`; if already conservative, add RAM or deploy a second hub instance with a load balancer in front; enable sticky sessions so server connections always route to the same hub

**Relay latency high (geographic distance)**
- Symptom: Remote playback is noticeably slower than LAN; 5–15 second startup delay; frequent buffering
- Cause: Hub deployed far from most enrolled servers or their users; relay adds 1–3 RTT of overhead
- Fix: Deploy a hub instance in the geographic region closest to the majority of servers and users; for globally distributed deployments, consider regional hub instances with a DNS-based routing layer

**Bandwidth cap hit (relay streams throttled)**
- Symptom: Users on free or low-bandwidth plans see constant buffering; streams start then stall; hub logs show rate-limit drops
- Cause: `HUB_RELAY_RATE_LIMIT_PER_SESSION_Mbps` set too low for the stream quality demanded by users; or uplink saturation at the server
- Fix: Increase per-session rate limit if the server's uplink supports it; warn users that high-quality 4K streams require a higher rate cap; consider upgrading free-tier servers to paid tier if they consistently hit limits

---

### Next steps

- [Hub claim and enrollment](docs/hub-admin/enrollment.md) — enrolling your first server with the hub
- [Relay tunnel deep-dive](docs/hub-admin/relay-tunnel.md) — how the WSS relay actually works
- [Hub admin panel reference](docs/hub-admin/panel-reference.md) — configuring and monitoring the hub
- [Hub sharing and access control](docs/hub-share.md) — managing user access to your hub
- [Troubleshooting](docs/troubleshooting.md) — diagnose relay, bandwidth, and connection issues

## 5. Git ritual

```bash
# ─── 0. PRECONDITION ───
cd /home/sites/phlex-hub
git status --short
git branch --show-current
git pull --ff-only origin master

# ─── 1. Branch ───
git checkout -b n.28-hub-admin-capacity

# ─── 2. Do the work ───
# Create docs/hub-admin/capacity-planning.md following the §7 one-screen layout
# TL;DR → sizing tiers → relay architecture → config knobs → fair-use policy
# → what-can-go-wrong (3 failures) → next-steps

# ─── 3. Verify ───
# Verify file has TL;DR, 3 sizing tier blocks, relay architecture section with
# bandwidth math shell block, config knobs table, fair-use policy with tier blocks,
# what-can-go-wrong (3 failures), and next-steps links

# ─── 4. Caliber sync ───
git add -A

# ─── 5. Commit ───
git commit -m "Step N.28: Hub-admin capacity planning guide"

# ─── 6. CRITICAL ───
unset GITHUB_TOKEN

# ─── 7. PR + merge ───
gh pr create \
  --title "Step N.28: Hub-admin capacity planning guide" \
  --body  "Adds docs/hub-admin/capacity-planning.md following the §7 one-screen layout (TL;DR, sizing tiers, relay architecture, config knobs, fair-use policy, what-can-go-wrong, next-steps). Covers hub hardware sizing (small/medium/large), relay bandwidth math, HUB_MAX_RELAY_SESSIONS/rate-limit config knobs, free/paid tier fair-use policy, and three common failure modes. Part of Phase N (Step N.28 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch

# ─── 8. Return to master ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION ───
git status --short
git branch --show-current
git log --oneline -1
git branch -d n.28-hub-admin-capacity
```
