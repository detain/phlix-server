# Step P.4 — v1.0 Announcement

**Phase:** P (Phase-end Audit & v1.0)
**Step:** P.4
**Depends on:** P.3 (v1.0 release tagging)
**Review:** No (announcement step)
**Status:** PENDING

## 1. Goal

Announce Phlex v1.0.0 across all channels simultaneously on 2026-05-19, coordinating timing across blog, social media, GitHub, and community platforms.

## 2. Prerequisites

Before any announcement publish:
- [ ] `v1.0.0` tags pushed on `detain/phlex-server`, `detain/phlex-hub`, `detain/phlex-shared`
- [ ] Docker images available on GHCR (`ghcr.io/detain/phlex-server`, `ghcr.io/detain/phlex-hub`)
- [ ] CHANGELOG.md updated in all three repos
- [ ] GitHub Releases created from tags (drafted, not published — see timing below)
- [ ] Blog post approved and scheduled
- [ ] Security findings documented (P.1 findings in `plans/expansion/p.1-findings.md`)

**Hardware-dependent items that are NOT ready for launch day claims:**
- HDR tone-mapping (requires real GPU — marked as `PENDING HARDWARE` in §13)
- Hardware transcode benchmarks (requires real hardware — marked as `PENDING HARDWARE` in §13)
- External contributor plugin (unverified — marked `UNVERIFIED` in §13)

These must NOT appear as ship claims in announcement copy. They may appear as "coming soon" if desired.

---

## 3. Announcement Channels & Timing

| Channel | Publish Time (UTC) | Responsible | Status |
|---------|-------------------|-------------|--------|
| Blog post | 2026-05-19 09:00 | — | PENDING |
| GitHub Releases (all 3) | 2026-05-19 09:00 | — | PENDING |
| Twitter/X | 2026-05-19 09:05 | — | PENDING |
| Mastodon | 2026-05-19 09:05 | — | PENDING |
| Hacker News (r/selfhosted) | 2026-05-19 10:00 | — | PENDING |
| Discord announcement | 2026-05-19 09:00 | — | PENDING |
| Forums (selfhosted.games, etc.) | 2026-05-19 11:00 | — | PENDING |

**Note:** HN submission requires 30-60 min of preparation and may get held by mod queue. Submit at 10:00 UTC to aim for morning ranking.

---

## 4. Channel Copy Templates

### 4.1 Blog Post

**URL:** `https://phlex.media/blog/phlex-v1-0-released`

**Frontmatter:**
```
title: "Phlex Media Server v1.0.0 — Built for the Self-Hosted Community"
date: 2026-05-19
author: Phlex Team
excerpt: "After months of development, security auditing, and community feedback, we're releasing Phlex v1.0.0. Here's what's in it — and what hardware you'll need for the advanced features."
```

**Body (blog phlex v1.0):**

```
# Phlex Media Server v1.0.0 — Built for the Self-Hosted Community

After months of development, security auditing, and community feedback, we're thrilled to announce the release of **Phlex Media Server v1.0.0**.

## What's New

### Plugin System
Phlex now ships with a full plugin architecture. Install plugins from the admin UI, configure them via manifest files, and extend functionality without forking the core. Three official plugins are available at launch:
- **Last.fm scrobbling** — scrobble plays to your Last.fm profile
- **Discord presence** — set rich Discord status with current playing media
- **OIDC provider** — SSO via OpenID Connect

### Hub Pairing & Remote Access
Connect your Phlex clients (Roku, Tizen, Windows, iOS) to your server via a secure WebSocket relay tunnel. No port forwarding required. Share access with family members through the hub admin panel.

### Hardware Transcode Support
Phlex supports hardware-accelerated transcoding via:
- NVIDIA NVENC
- Intel Quick Sync Video (QSV)
- AMD VAAPI/AMF
- Apple VideoToolbox
- Linux VAAPI

**Note:** HDR→SDR tone-mapping requires compatible GPU hardware (NVIDIA Turing+ or equivalent AMD). Benchmark performance data will be published once we have hardware in hand.

### Authentication
Full multi-provider auth at launch:
- **OIDC/OpenID Connect** — SSO via any OIDC-compliant provider
- **LDAP** — integrate with existing directory services
- **WebAuthn** — passwordless authentication via security keys
- Traditional username/password with Argon2ID hashing

### Developer Experience
- Full REST API with OpenAPI 3.0 specification
- WebSocket API for real-time events
- Plugin SDK with typed manifest schema
- Comprehensive developer documentation

## What Requires Hardware (Not Shipped Claims)

The following features exist in code and will work with proper hardware, but we cannot ship benchmark numbers or guarantee tone-mapping without real GPU hardware in our test environment:
- HDR→SDR tone-mapping (NVIDIA Turing+ or equivalent AMD)
- Hardware transcode performance benchmarks

## Security

We conducted a full OWASP Top 10 audit before this release. All high and critical findings are documented with remediation in our security findings document. We follow responsible disclosure practices.

## Getting Started

```bash
docker pull ghcr.io/detain/phlex-server
docker run -d \
  --name phlex \
  -e PLEX_CLAIM=your-claim-token \
  -v /path/to/media:/media \
  -p 32400:32400 \
  ghcr.io/detain/phlex-server
```

Or [deploy via Helm](https://github.com/detain/phlex-helm) on Kubernetes.

## Thank You

v1.0.0 is the result of work across 7 phases and dozens of contributors. Thank you to everyone who tested, filed issues, and provided feedback.

— The Phlex Team

*Discuss on [r/selfhosted](https://www.reddit.com/r/selfhosted) or [Discord](https://discord.gg/phlex).*
```

---

### 4.2 GitHub Release Notes

**phlex-server:**

```
## Phlex Media Server v1.0.0

**Initial stable release.** Tag: `v1.0.0`

### What's New
- Plugin system with manifest-driven lifecycle
- Hub pairing and remote access via WS relay tunnel
- Hardware transcode support (NVENC, VAAPI, QSV, VideoToolbox, AMF)
- HDR→SDR tone-mapping (hardware-dependent — see below)
- Intro/skip markers via Chromaprint fingerprinting
- OIDC, LDAP, WebAuthn authentication providers
- Jellyseerr-class request UI (on hub)
- Docker + Kubernetes Helm deployment
- Docker Hub: `detain/phlex-server`

### Hardware-Dependent Features
HDR→SDR tone-mapping and hardware transcode benchmarks require real GPU hardware (NVIDIA Turing+ or equivalent AMD). These features exist in code and will work with compatible hardware, but launch-day benchmarks are pending hardware availability.

### Security
Full OWASP Top 10 audit conducted. Findings documented at `plans/expansion/p.1-findings.md`.

### Upgrade Notes
See [UPGRADE.md](UPGRADE.md) for migration from pre-1.0 releases.
```

**phlex-hub:** Mirror structure with hub-specific features (hub admin, web portal).

**phlex-shared:** Focus on library updates, DTOs, shared contracts.

---

### 4.3 Twitter/X

**Character-limited announcement (280 chars):**

```
🛸 Phlex Media Server v1.0.0 is out.

Plugin system. Hub pairing. HW transcode (NVENC/VAAPI/QSV). OIDC + LDAP + WebAuthn. Docker + K8s. Built for self-hosters.

docker pull ghcr.io/detain/phlex-server

https://phlex.media/blog/phlex-v1-0-released
```

**Alt variant (softer):**

```
After months of work: Phlex v1.0.0 is here.

Self-hosted media server with plugin architecture, remote access via hub pairing, hardware transcoding, and the auth providers you'd actually want (OIDC, LDAP, WebAuthn).

https://phlex.media
```

---

### 4.4 Mastodon

```
Phlex Media Server v1.0.0 🛸

New release of the self-hosted media server. Here's what's new:

✅ Plugin system with 3 launch plugins (Last.fm, Discord, OIDC)
✅ Hub pairing for remote access (no port forwarding)
✅ Hardware transcode: NVENC, VAAPI, QSV, VideoToolbox, AMF
✅ Auth: OIDC, LDAP, WebAuthn, password login
✅ Docker + Kubernetes Helm
✅ Full REST + WebSocket API with OpenAPI 3.0

⚠️ HDR tone-mapping and hw transcode benchmarks require real GPU hardware (pending)

https://phlex.media/blog/phlex-v1-0-released
```

**Tags:** `#selfhosted` `#Phlex` `#OpenSource` `#MediaServer`

---

### 4.5 Hacker News / r/selfhosted

**HN submission title (under 80 chars):**

```
Phlex Media Server v1.0.0 — self-hosted media with plugin architecture
```

**r/selfhosted post body:**

```
Phlex Media Server v1.0.0 is out. We built this for the self-hosted community — no subscription, no cloud dependency.

What's new in v1.0.0:

- **Plugin system** — 3 plugins at launch (Last.fm, Discord, OIDC). Install from admin UI.
- **Hub pairing** — connect Roku/Tizen/Windows/iOS clients via WebSocket relay tunnel. No port forwarding.
- **Hardware transcoding** — NVENC, VAAPI, QSV, VideoToolbox, AMF. HDR tone-mapping in code (requires GPU hardware).
- **Auth** — OIDC, LDAP, WebAuthn + Argon2ID password login.
- **Deploy** — Docker image on GHCR, Helm chart for Kubernetes.

We're honest about what's hardware-dependent: HDR tone-mapping and transcode benchmarks require real GPU. Everything else is tested and documented.

Blog: https://phlex.media/blog/phlex-v1-0-released
GitHub: https://github.com/detain/phlex-server
Discuss: https://discord.gg/phlex
```

**Submission rules notes:**
- Do NOT include "Show HN:" prefix (use "Show" after posting as comment)
- Do NOT ask for upvotes
- Engage with comments within 30 min of posting
- Be available to answer questions about architecture, security, and licensing

---

### 4.6 Discord Announcement

**Channel:** `#announcements`

```
📢 Phlex Media Server v1.0.0 is out!

https://phlex.media/blog/phlex-v1-0-released

What's new:
• Plugin system — Last.fm, Discord, OIDC plugins at launch
• Hub pairing — remote client access via WS relay tunnel
• Hardware transcode (NVENC/VAAPI/QSV/VideoToolbox/AMF)
• Auth: OIDC, LDAP, WebAuthn + password login
• Docker + K8s Helm

⚠️ HDR tone-mapping requires compatible GPU (HW pending)

Docker: docker pull ghcr.io/detain/phlex-server
GitHub: https://github.com/detain/phlex-server
```

---

### 4.7 Forums (selfhosted.games, reddit.com/r/selfhosted, etc.)

**Subject:** `Phlex Media Server v1.0.0 — self-hosted media server with plugin architecture`

**Body (similar to r/selfhosted post above — see §4.5)**

Cross-post to:
- `reddit.com/r/selfhosted` (link to HN if already live, else same body as text post)
- `forum.selfhosted.games` (same body)
- `reddit.com/r/jellyfin` (for awareness — Phlex is Jellyfin-compatible API)

---

## 5. Execution Steps

### Step P.4.1 — Pre-flight (T-2 days, 2026-05-17)

- [ ] Confirm v1.0.0 tags are pushed on all 3 repos
- [ ] Confirm GHCR images are built and tagged
- [ ] Draft GitHub Releases (save as draft — do NOT publish)
- [ ] Draft blog post (do NOT publish)
- [ ] Set up scheduled posts in Buffer/Hootsuite for social if using

### Step P.4.2 — GitHub Release Creation (T-1 day, 2026-05-18)

- [ ] Create GitHub Release for `detain/phlex-server` from `v1.0.0` tag (save as draft)
- [ ] Create GitHub Release for `detain/phlex-hub` from `v1.0.0` tag (save as draft)
- [ ] Create GitHub Release for `detain/phlex-shared` from `v1.0.0` tag (save as draft)
- [ ] Verify release notes render correctly
- [ ] Verify download links point to correct assets

### Step P.4.3 — Publish Sequence (2026-05-19, 09:00 UTC)

Publish in this order (5 min between each):

**09:00** — Blog post goes live
- [ ] Publish blog post at phlex.media
- [ ] Confirm site is accessible

**09:00** — Discord announcement
- [ ] Post to #announcements channel
- [ ] Pin message

**09:00** — GitHub Releases (publish simultaneously)
- [ ] Publish `detain/phlex-server` v1.0.0 release
- [ ] Publish `detain/phlex-hub` v1.0.0 release
- [ ] Publish `detain/phlex-shared` v1.0.0 release

**09:05** — Twitter/X
- [ ] Post announcement tweet
- [ ] Post alt variant if engagement is slow

**09:05** — Mastodon
- [ ] Post toot with tags

**10:00** — Hacker News
- [ ] Submit to HN (use "Show" after posting)
- [ ] Monitor for 2 hours — reply to all comments
- [ ] Post to r/selfhosted

**11:00** — Other forums
- [ ] Post to selfhosted.games forum
- [ ] Post to r/jellyfin (awareness)
- [ ] Post to any other relevant communities

---

## 6. Verification Checklist

After all publish actions:

- [ ] Blog post loads at expected URL with correct title and content
- [ ] GitHub Releases visible on all 3 repo landing pages
- [ ] Docker images pull successfully: `docker pull ghcr.io/detain/phlex-server:v1.0.0`
- [ ] Twitter/X post visible and link resolves correctly
- [ ] Mastodon post visible with correct tags
- [ ] HN submission appears in new submissions (not in pending queue too long)
- [ ] r/selfhosted post visible
- [ ] Discord announcement pinned
- [ ] All links in posts resolve to correct destinations
- [ ] No broken images or 404 resources in blog post
- [ ] No claims made about HDR tone-map or hw transcode benchmarks (hardware-dependent)
- [ ] Security findings link is included or referenced
- [ ] No exaggerated performance claims (50+ direct play, benchmarks — pending hardware)
- [ ] Docker Hub reference is accurate (detain/phlex-server, not "latest" confusion)

---

## 7. Response Guidance

When engaging with community comments:

| Question | Response |
|----------|----------|
| "How does this compare to Jellyfin/Plex?" | Honest comparison — Phlex has plugin architecture, different auth options, Hub for remote access. Direct play is equivalent. Transcode depends on hardware. |
| "HDR tone-mapping?" | Feature exists in code, requires NVIDIA Turing+ or equivalent AMD GPU. We don't have hardware benchmarks yet — will publish when we do. |
| "Why PHP?" | Media library, transcode pipeline, and WebSocket relay are PHP on Workerman. FastCGI/cached opcache delivers good performance. Plugin system leverages PHP ecosystem. |
| "Is this production ready?" | v1.0.0 has passed security audit with documented findings and remediation. Code coverage is 80%+ on new classes. We recommend reviewing the security findings doc before deploying. |
| "Enterprise features?" | v1.0 is focused on self-hosted use cases. LDAP and OIDC plugins are available. Scale testing and clustering are future roadmap items. |
| "Contributing?" | See `CONTRIBUTING.md` and our plugin SDK docs. We welcome issues and PRs. |

---

## 8. Notes

- **2026-05-19:** Announcement day. All channels targeted for same-day coverage.
- Hardware-dependent items (HDR, benchmarks) are honestly marked pending. Do not invent numbers.
- Security findings (from P.1) are documented — reference but do not sensationalize.
- HN timing: 10:00 UTC targets morning in US, early afternoon in Europe. Watch for mod queue.
- Discord pin should be refreshed after a few days once activity moves to general channels.
