# Phlex Expansion Plan — Repo Split, Central Hub, Plugins, Feature Parity, End-User Docs

**Version:** 1.0
**Date:** 2026-05-16
**Audience:** Supervisor AI orchestrating subagents to extend Phlex from a standalone media server into a full Plex/Emby/Jellyfin-class product family.
**Companion docs:** `SUPERVISOR_PLAN.md` (original Phases 1–7), `IMPLEMENTATION_PLAN.md`, `AGENTS.md`, `PHLEX_MEDIA_SERVER_TECHNICAL_SPEC.md`.

---

## 0. Read this first

### 0.1 What changed vs. SUPERVISOR_PLAN.md

The original plan delivered a **single-repo standalone media server**. This plan extends that work into:

1. A **two-repo product** — `phlex-server` (local media server, what `/home/sites/phlex` is today) plus a brand-new **`phlex-hub`** (the cloud directory + relay that makes Plex's "sign in from anywhere and play your home media" UX work).
2. A **plugin system** so the community can extend metadata, auth, notifications, libraries, UI, and clients without forking.
3. **Feature parity** with Plex/Emby/Jellyfin on the items users most loudly miss: hardware transcode + HDR tone-map, intro/outro skip, SSO/OIDC/LDAP, smart playlists, Trakt/Last.fm scrobble, Tautulli-class analytics, OPDS/audiobooks, IPTV, *arr integration, notifications.
4. **First-class end-user documentation** so a non-developer can install a server, claim it to a hub, point it at a NAS, and watch on a Roku in under 30 minutes.

### 0.2 Critical rules (apply to every step)

1. **Always `unset GITHUB_TOKEN`** before any `gh` command. Each step plan repeats this in its git ritual.
2. **Every step ends on master with the merged PR already pulled.** Working tree clean, branch deleted, `git status` reports nothing pending. The next subagent must find the repo in this state — no exceptions. See §11.4 for the exact ritual.
3. **One focused task per subagent** — never bundle steps. Subagents have small context windows; long plans cause truncation and missed acceptance criteria.
4. **Every step plan ends with the same git ritual** (see §11.4 below): branch → commit → PR → merge → master pull. Caliber-refresh check goes BEFORE the commit (see CLAUDE.md "Caliber" section).
5. **Tests are step deliverables, not optional.** See §0.4 for the per-step testing + documentation minimum bar — both apply to EVERY implementation step.
6. **Database: `Workerman\MySQL\Connection` only.** Never PDO or mysqli. Always parameterized.
7. **PSR-12 + `declare(strict_types=1)` everywhere.** `phpstan` level 9, `phpcs --standard=PSR12`.
8. **The supervisor does NOT read the per-step plan files.** Supervisor reads only this file's tables, spawns a subagent per row pointed at the linked plan, waits, then spawns the review subagent if `Review = Yes`.
9. **After each step's coding subagent, spawn a review subagent** (description: `Review Step X.Y`, prompt: `Read /home/sites/phlex/plans/expansion/<step>-review.md and verify completion`). Review plans are minimal templates — they re-run tests, lint, and re-check the acceptance criteria.
10. **Subagent type guidance:**
   - Use `feature-dev:code-architect` for the design step at the top of each phase.
   - Use `oac:coder-agent` (or `general-purpose` if OAC is not installed) for implementation steps.
   - Use `feature-dev:code-reviewer` or `oac:code-reviewer` for review steps.
   - Use `Explore` for read-only fact-finding spawned mid-step.
11. **Use OAC skills if available** (`oac:context-discovery` once per session, `oac:debugger` on test failures, `oac:verification-before-completion` before claiming any step done). If OAC is not installed, the principles still apply manually.

### 0.3 What "done" looks like for this entire plan

- `phlex-server` repo (already created as `detain/phlex-server`) holds the local media server code migrated from `detain/phlex`, can run with **or without** a hub.
- `phlex-hub` repo (already created as `detain/phlex-hub`) runs at `phlex.example.com`, accepts user signup, accepts server claim codes, shows each user a list of their claimed servers, brokers auth, optionally relays traffic.
- A community plugin can be installed from a `plugin.json` manifest URL and add a new metadata provider, notification target, library type, or auth provider without server restart.
- Five clients (mobile, Roku, Tizen, Windows, web) work in **both** modes: direct-to-server LAN and hub-mediated remote.
- `docs.phlex.media` (or `/home/sites/phlex/docs/`) renders end-user, developer, AND hub-admin manuals covering install → first scan → first stream → remote access → hub operation.

### 0.4 Testing + documentation are step deliverables, not optional

**Every implementation step must hit ALL of these before it's claimable as done. Reviewers reject steps that skip any.**

**Tests (per step):**
- New code is covered by unit tests written in the same step. Coverage of new classes/methods must be ≥ 85 %.
- Where the step touches an integration boundary (DB, HTTP, WS, FFmpeg, FS watcher, hub pairing, plugin loader), an **integration test** must accompany the unit tests. Use `Workerman\MySQL\Connection` mocks for unit; use a sqlite or test-DB harness for integration (the step plan specifies which).
- For hub↔server interaction steps (Phase C onward), an **end-to-end test** spins both processes and validates the protocol on the wire.
- `./vendor/bin/phpunit` must pass green. No skipped tests except where the step plan explicitly authorizes a skip with a documented reason.
- `./vendor/bin/phpstan analyze src/ --level=9` must pass with **zero new errors** vs. master.
- `./vendor/bin/phpcs --standard=PSR12 src/` clean.
- `find src -name "*.php" -exec php -l {} \;` reports no syntax errors.
- Whenever a new directory of features is introduced (e.g., `src/Hub/`, `src/Plugins/`), a `tests/unit/<MatchingNamespace>/` directory and at least one smoke test must be created in the same step.

**PHPDoc + code comments (per step):**
- Every new public class has a class-level docblock with: short summary, longer description, `@package`, `@since`.
- Every new public method has `@param`, `@return`, `@throws` covering all paths.
- Internal `@internal` tag on non-API helpers so phpstan / IDEs treat them appropriately.
- Inline comments allowed only where the code's intent isn't obvious — never narrate WHAT it does, only WHY.
- Any TODO must reference a GitHub issue number; bare TODOs are rejected in review.

**Documentation (per step):**
A step is incomplete if it ships code but no doc. Each step plan must end with a checklist that includes — at minimum — the documentation items below that apply. Reviewer checks each box.

| If the step touches… | Then it must update… |
|---|---|
| Public HTTP/WS API | `docs/reference/api/` (OpenAPI source) + regenerated `docs/reference/api.md` |
| A configurable env var or `config/*.php` key | `docs/reference/env-vars.md` and `docs/reference/config-files.md` |
| A CLI command (`scripts/*.php`, console binaries) | `docs/reference/cli.md` |
| A new library type (music/photos/books/audiobooks) | `docs/libraries/<type>.md` |
| Hub functionality (Phase B+) | `docs/hub/*.md` (end-user) AND `docs/hub-admin/*.md` (operator) |
| The plugin API | `docs/plugins/developer-guide.md` |
| User-visible behavior change | `docs/first-run.md` or relevant page, plus a `CHANGELOG.md` line |
| Anything | The repo `README.md` "Status" badge / feature list, if applicable |

**Three audiences, three doc trees** (all maintained continuously, never as a Phase N afterthought):

1. **End-user docs** (`docs/`) — for the person installing and using a Phlex server / hub / client. Plain language, screenshots, copy-paste shell, troubleshooting.
2. **Developer docs** (`docs/dev/` or `DEVELOPER.md` per repo) — for contributors and plugin authors. Architecture diagrams, namespace map, event reference, plugin SDK.
3. **Hub-admin docs** (`docs/hub-admin/`) — for operators running a `phlex-hub` instance for themselves or a community. Capacity planning, relay bandwidth tuning, abuse handling, GDPR/data retention, backup, scaling.

**The Phase N tasks are not "go write all the docs at the end." They are "produce the polished published site from the docs that have been continuously updated each step."** If a step ships without its doc updates, it goes back to the implementation queue — not forward to Phase N.

---

## 1. Current-state inventory (what exists today)

Verified via repo survey on 2026-05-16. Do not assume this is the state when you read it later — re-run the inventory subagent (§11.2 template) before starting Phase A.

| Area | Status |
|------|--------|
| Workerman HTTP + WS server, router, controllers (Auth, Hls, Library, MediaItem, Session) | **Present** |
| `Phlex\Auth\*` — JwtHandler, UserRepository, AuthManager, UserProfileManager, parental controls, WatchHistory | **Present** |
| `Phlex\Media\Library\*` — LibraryManager, MediaScanner (S01E02 + year parse), FolderWatcher, ItemRepository | **Present** |
| `Phlex\Media\Metadata\*` — TmdbProvider, TvdbProvider, FanartProvider, LocalNfoProvider, 24h cache | **Present** |
| `Phlex\Media\Streaming\*` — HlsStreamer, QualitySelector (5 profiles), FfmpegRunner | **Present** |
| `Phlex\Session\*` — SessionManager, PlaybackController, SyncPlay with NTP-style TimeSync | **Present** |
| `Phlex\Common\Database\ConnectionPool` + QueryBuilder, StructuredLogger, AuditLogger | **Present** |
| `Phlex\Server\WebPortal\*` — Smarty templates, WebPortalRouter, PageRenderer (LOCAL portal only) | **Present** |
| `src/LiveTv/*` — ChannelManager, GuideManager, Recorder | **Framework only** (no tuner drivers) |
| `src/Dlna/*` — ContentDirectory, AvTransport, DeviceRegistry, DlnaDevice | **Framework only** (no SSDP/SOAP wired) |
| `src/Common/Cache/`, `src/Common/Events/` | **`.gitkeep` only** (empty) |
| Plugin / extension / hook system | **Missing** |
| DI container | **Missing** (hardcoded bootstrap) |
| Hub registration, claim code, remote-auth delegation | **Missing** |
| TCP/WS relay tunnel, UPnP-IGD helper, public-hostname claim | **Missing** |
| Hardware transcode profiles (NVENC/VAAPI/QSV/VideoToolbox/AMF), HDR tone-map | **Missing** |
| Intro/outro skip, scene markers, Chromaprint | **Missing** |
| Smart playlists, collections (rule-based), custom CSS themes | **Missing** |
| SSO/OIDC/LDAP/SAML/WebAuthn auth providers | **Missing** |
| Trakt + Last.fm scrobble, *arr integration, Jellyseerr-class request UI | **Missing** |
| Tautulli-class analytics + newsletter | **Missing** |
| Webhook/Discord/Slack/Telegram/ntfy/Apprise/MQTT notifications | **Missing** |
| Music providers (MusicBrainz, AudioDB) + music player route | **Missing** |
| Photos library + EXIF + slideshow | **Missing** |
| Books/comics + EPUB/PDF/CBZ + OPDS feed | **Missing** |
| Audiobooks (M4B chapter handling, multi-file series) | **Missing** |
| Trailers, extras, theme music, theme video | **Missing** |
| mDNS/SSDP local discovery broadcast | **Missing** |
| Chromecast / AirPlay 2 / Roku ECP "play to" | **Missing** |
| End-user docs, install guides, troubleshooting, FAQ, API reference site | **Missing** |
| Docker / docker-compose / Kubernetes / systemd / nginx templates | **Missing** |
| CI for cross-platform builds | **Partial** (GH Actions exists but coverage-check was disabled) |

| Sibling client repo | Stack | Status | Server discovery |
|---------------------|-------|--------|------------------|
| `/home/sites/phlex-mobile-client` | React Native + TS + Zustand | **Substantial** (~2.7k LoC, login/browse/play/search/CW/downloads) | Configurable URL (user-entered) |
| `/home/sites/phlex-tizen-client` | Vanilla JS + Webpack | **Substantial** (~4.2k LoC, full playback + audio/sub tracks + resume) | `window.PHLEX_SERVER_URL` env |
| `/home/sites/phlex-roku-client` | BrightScript / SceneGraph | **Partial** (~1.7k LoC, login + browse + HLS playback) | Roku Storage key `server_url` |
| `/home/sites/phlex-windows-client` | Electron + React + Vite | **Partial** (~1.6k LoC, browse + player + tray + media keys) | `VITE_PHLEX_SERVER_URL` env |

**None of the clients currently know what a hub is.** Phase M (client hub-mode) is therefore non-skippable if remote access is a product goal.

---

## 2. Target architecture

```
                                ┌──────────────────────────────────────────┐
                                │           phlex-hub  (NEW REPO)          │
                                │  ──────────────────────────────────────  │
                                │  • Public web portal (sign-in/up)        │
                                │  • Server registry (claim codes)         │
                                │  • Federated identity (delegated JWT)    │
                                │  • Optional relay (WS reverse tunnel)    │
                                │  • Aggregated dashboard (now-playing,    │
                                │    "my servers", "shared with me")       │
                                │  • Plugin host (notifications,           │
                                │    Jellyseerr-class request UI)          │
                                └──────────────┬───────────────────────────┘
                                               │  HTTPS + signed JWT + WS
                                               │
        ┌──────────────────────────────────────┼──────────────────────────────────────┐
        │                                      │                                      │
┌───────▼────────┐                    ┌────────▼───────┐                    ┌─────────▼──────┐
│ phlex-server   │                    │ phlex-server   │                    │ phlex-server   │
│ (renamed       │                    │ (Alice's home) │                    │ (Bob's NAS)    │
│   current dir) │                    │                │                    │                │
│                │                    │                │                    │                │
│ • Library scan │                    │ • LAN + claim  │                    │ • LAN + claim  │
│ • Transcode    │                    │ • HW accel     │                    │ • HW accel     │
│ • HLS / DASH   │                    │ • SyncPlay     │                    │ • SyncPlay     │
│ • Plugin host  │                    │ • Plugin host  │                    │ • Plugin host  │
└───────┬────────┘                    └────────┬───────┘                    └────────┬───────┘
        │                                      │                                     │
        │       direct LAN                     │       direct LAN                    │  direct LAN
        │       OR hub-relay                   │       OR hub-relay                  │  OR hub-relay
        │                                      │                                     │
┌───────▼────────┬─────────────────┬───────────▼────────┬──────────────────┬─────────▼──────┐
│ phlex-mobile   │   phlex-tizen   │    phlex-roku      │  phlex-windows   │   web portal   │
│   (RN)         │   (Vanilla JS)  │    (BrightScript)  │  (Electron)      │ (server-side)  │
└────────────────┴─────────────────┴────────────────────┴──────────────────┴────────────────┘
```

**Repo inventory (verified 2026-05-16):**

| Repo | State | Action |
|------|-------|--------|
| `detain/phlex` (current) | Has all existing code | **Migrate** code to `detain/phlex-server`, then archive this repo with a README pointing to the new home |
| `detain/phlex-server` | **Already exists, empty, public** (created 2026-05-16) | Initial push happens in step B.4; metadata (description + 19 topic tags) set in B.4a |
| `detain/phlex-hub` | **Already exists, empty, public** (created 2026-05-16) | Scaffolded in step B.5; metadata set in B.5a |
| `detain/phlex-shared` | **Already exists, empty, public** (created 2026-05-17) | Scaffolded in step B.2 — clone the existing empty repo, push initial commit; do NOT `gh repo create`. Metadata set in B.2a |
| `detain/phlex-docs` | Does not exist yet | Created in step N.0 if the docs site lives in its own repo; otherwise docs stay in `docs/` inside `phlex-server` and `phlex-hub` |

**Local dir naming:** the working dir stays `/home/sites/phlex` for continuity (CLAUDE.md, AGENTS.md, every existing reference). Only the **remote URL** changes (`git remote set-url origin git@github.com:detain/phlex-server.git`). Optional follow-up rename of the on-disk directory is documented as a separate, low-priority step (B.10) — do it only if it provides real value.

**Why keep the local dir name:** it avoids breaking every absolute path in plan files, CI configs, Caliber output, OAC context discovery, the supervisor's working assumptions, and any open editor windows.

---

## 3. Phase index (supervisor reads this table)

Each row = one subagent spawn. The supervisor does **not** read the right-most "subagent prompt" content — it spawns a subagent with `prompt: "Read /home/sites/phlex/plans/expansion/<step>.md and follow it. Work in /home/sites/phlex/ (or the relevant repo)."`

Step files are to be created by the first action of each phase's design step. The very first step of the entire plan (A.0) creates the `plans/expansion/` directory and writes A.1–A.7's step files; subsequent design steps do the same for their phase.

| # | Step | Plan file | Review | Depends on | One-liner |
|---|------|-----------|--------|-----------|-----------|
| **Phase A — Plugin Foundation & DI** *(must come first so hub can extend server)* |
| A.0 | Bootstrap expansion plans dir | `plans/expansion/a.0-bootstrap.md` | No | — | Create `plans/expansion/` and write step files A.1–A.7 |
| A.1 | DI container | `plans/expansion/a.1-di-container.md` | Yes | A.0 | Introduce a PSR-11 container (PHP-DI or league/container); refactor `Application` to resolve services |
| A.2 | Event dispatcher | `plans/expansion/a.2-event-dispatcher.md` | Yes | A.1 | PSR-14 dispatcher; named events for playback/library/auth/scan/scrobble |
| A.3 | Plugin manifest spec | `plans/expansion/a.3-plugin-manifest.md` | Yes | A.2 | `plugin.json` schema (name, version, type, hooks, requires, signature) |
| A.4 | Plugin loader + lifecycle | `plans/expansion/a.4-plugin-loader.md` | Yes | A.3 | install/enable/disable/uninstall; sandboxed `vendor/` per plugin |
| A.5 | Plugin admin UI | `plans/expansion/a.5-plugin-admin-ui.md` | Yes | A.4 | Smarty + JSON API; install from URL; enable/disable toggles |
| A.6 | Sample plugin | `plans/expansion/a.6-sample-plugin.md` | No | A.5 | "Hello world" metadata provider published as `phlex-plugin-example` |
| A.7 | Plugin developer docs | `plans/expansion/a.7-plugin-docs.md` | No | A.6 | `docs/plugin-development.md` — types, hooks, manifest reference |
| **Phase B — Repo Split & Migration** |
| B.1 | Design `phlex-shared` package | `plans/expansion/b.1-shared-design.md` | No | A.7 | Decide what's shared: interfaces, DTOs, event names, JWT claim shape, pairing protocol DTOs |
| B.2 | Scaffold `detain/phlex-shared` from existing empty repo + initial v0.1.0 | `plans/expansion/b.2-shared-create.md` | Yes | B.1 | Clone existing empty `detain/phlex-shared`, push initial commit, tag v0.1.0, publish to Packagist (or git-vendor for now). Do NOT `gh repo create` — repo already exists empty (created 2026-05-17). |
| B.2a | Set `phlex-shared` description + 19 topic tags | `plans/expansion/b.2a-shared-metadata.md` | No | B.2 | Apply description and topics from §14 via `gh repo edit` |
| B.3 | Refactor `phlex` to depend on `phlex-shared` | `plans/expansion/b.3-shared-consume.md` | Yes | B.2 | Composer require; move interfaces; tests stay green |
| B.4 | Migrate code to `detain/phlex-server` | `plans/expansion/b.4-migrate-server.md` | Yes | B.3 | `git remote set-url origin git@github.com:detain/phlex-server.git`; push master + all branches + tags; update README badges + clone URLs; update Caliber config |
| B.4a | Set `phlex-server` description + 19 topic tags | `plans/expansion/b.4a-server-metadata.md` | No | B.4 | Apply description and topics from §14 via `gh repo edit` |
| B.4b | Archive old `detain/phlex` with redirect README | `plans/expansion/b.4b-archive-old.md` | No | B.4a | Replace README on `detain/phlex` with a "moved to detain/phlex-server" notice; `gh repo archive detain/phlex` |
| B.5 | Scaffold `detain/phlex-hub` from existing empty repo | `plans/expansion/b.5-hub-scaffold.md` | Yes | B.4 | Clone empty `detain/phlex-hub`; copy `phlex-shared`-based skeleton: composer, Workerman HTTP/WS, JWT, DB pool, logger, dirs for `Hub`, `Webhooks`, `Plugins`; first commit + push to master |
| B.5a | Set `phlex-hub` description + 19 topic tags | `plans/expansion/b.5a-hub-metadata.md` | No | B.5 | Apply description and topics from §14 via `gh repo edit` |
| B.6 | Hub DB schema + migrations | `plans/expansion/b.6-hub-schema.md` | Yes | B.5 | `users`, `servers`, `server_claims`, `shared_libraries`, `relay_sessions`, `webhooks` |
| B.7 | Hub: signup/login/dashboard MVP | `plans/expansion/b.7-hub-portal-mvp.md` | Yes | B.6 | Reuse the auth code from `phlex-shared`; render "my servers" empty state |
| B.10 | *(optional)* Rename local dir `/home/sites/phlex` → `phlex-server` | `plans/expansion/b.10-local-rename.md` | Yes | B.7 | Only if a clear win; update CLAUDE.md, AGENTS.md, every plan file's absolute paths, Caliber config |
| **Phase C — Server↔Hub Pairing & Remote Access** |
| C.1 | Pairing protocol design | `plans/expansion/c.1-pairing-design.md` | No | B.7 | Claim-code flow (server prints code, user pastes on hub); HMAC-signed enrollment; rotation policy |
| C.2 | Server-side: HubClient | `plans/expansion/c.2-server-hubclient.md` | Yes | C.1 | New `Phlex\Hub\HubClient` — POST `/api/v1/server-claim`, heartbeat loop, refresh, deregister |
| C.3 | Hub-side: server registry endpoints | `plans/expansion/c.3-hub-registry.md` | Yes | C.1 | POST `/api/v1/server-claims`, `/api/v1/servers/{id}/heartbeat`, `/api/v1/servers/{id}/info` |
| C.4 | Hub: "My Servers" dashboard | `plans/expansion/c.4-hub-my-servers.md` | Yes | C.3 | List servers, last-seen, version, claimable libraries |
| C.5 | Delegated auth: hub issues server JWT | `plans/expansion/c.5-delegated-auth.md` | Yes | C.4 | Server publishes JWKS; hub mints JWT signed with hub key trusted by server |
| C.6 | Relay tunnel: WS reverse proxy | `plans/expansion/c.6-relay-tunnel.md` | Yes | C.5 | Server opens persistent WS to hub; hub multiplexes inbound client requests over it |
| C.7 | UPnP-IGD + manual port-forward helper | `plans/expansion/c.7-port-forward.md` | Yes | C.6 | Server tries UPnP first, surfaces manual instructions if not available |
| C.8 | Public hostname claim (`*.phlex.media`) | `plans/expansion/c.8-public-hostname.md` | Yes | C.7 | Hub gives each server a hub-side subdomain w/ Let's Encrypt or relay-TLS |
| C.9 | Hub: shared libraries (friends/family) | `plans/expansion/c.9-shared-libraries.md` | Yes | C.8 | User A grants user B access to a library on A's server, B sees it in hub dashboard |
| **Phase D — Hub-grade Auth (SSO / OIDC / LDAP / passkeys)** |
| D.1 | Auth provider plugin interface | `plans/expansion/d.1-auth-provider-iface.md` | Yes | C.9 | `Phlex\Auth\ProviderInterface` in shared package; plugin slot |
| D.2 | OIDC / OAuth provider plugin | `plans/expansion/d.2-oidc-plugin.md` | Yes | D.1 | `phlex-plugin-oidc` — Authelia/Authentik/Keycloak/Google/GitHub |
| D.3 | LDAP provider plugin | `plans/expansion/d.3-ldap-plugin.md` | Yes | D.1 | `phlex-plugin-ldap` — homelab/business directories |
| D.4 | Passkeys / WebAuthn | `plans/expansion/d.4-webauthn.md` | Yes | D.1 | First-class, in core; per-user passkey registration |
| D.5 | Invite-link sharing | `plans/expansion/d.5-invite-links.md` | Yes | C.9 | Hub-issued single-use links with optional expiry + library scope |
| **Phase E — Hardware Transcoding + Advanced Streaming** |
| E.1 | Hwaccel probe & profile registry | `plans/expansion/e.1-hwaccel-probe.md` | Yes | A.7 | Probe NVENC/VAAPI/QSV/VideoToolbox/AMF/V4L2; persist results |
| E.2 | Hwaccel encoder profiles | `plans/expansion/e.2-hwaccel-profiles.md` | Yes | E.1 | Build per-vendor FFmpeg arg sets; fall back to libx264/libx265 |
| E.3 | HDR→SDR hardware tone-mapping | `plans/expansion/e.3-hdr-tonemap.md` | Yes | E.2 | Per-vendor filter chains (Plex's #1 complaint area — get this right) |
| E.4 | DASH output alongside HLS | `plans/expansion/e.4-dash.md` | Yes | E.2 | Reuse segmenter; emit `.mpd` |
| E.5 | Trickplay / BIF thumbnail seek | `plans/expansion/e.5-trickplay.md` | Yes | E.2 | Generate sprite sheets + `.bif`; serve `/Items/{id}/Trickplay/{w}` |
| E.6 | Subtitle burn-in pipeline | `plans/expansion/e.6-subtitle-burnin.md` | Yes | E.2 | PGS/ASS/SSA burn-in when client can't render |
| **Phase F — Skip-Intro, Skip-Outro, Scene Markers** |
| F.1 | Chromaprint integration | `plans/expansion/f.1-chromaprint.md` | Yes | E.6 | FFI or shelled `fpcalc`; fingerprint store schema |
| F.2 | Intro/outro detection job | `plans/expansion/f.2-intro-detect.md` | Yes | F.1 | Background queue worker; per-show fingerprint clustering |
| F.3 | Marker storage + API | `plans/expansion/f.3-markers-api.md` | Yes | F.2 | `chapters`, `intro_marker`, `outro_marker` columns; GET endpoints |
| F.4 | Player UI: skip button protocol | `plans/expansion/f.4-skip-protocol.md` | Yes | F.3 | Spec returned to clients; client repos consume in Phase M |
| F.5 | Comskip for Live TV recordings | `plans/expansion/f.5-comskip.md` | Yes | F.3 | EDL file ingestion when DVR finishes |
| **Phase G — Music / Photos / Books / Audiobooks** |
| G.1 | MusicBrainz + AudioDB providers | `plans/expansion/g.1-music-providers.md` | Yes | A.4 | Plugin-shaped, ship in-core |
| G.2 | Music player route + ID3/MP4 tag harvest | `plans/expansion/g.2-music-player.md` | Yes | G.1 | `/music/*` routes, artists/albums/tracks views |
| G.3 | Last.fm scrobble plugin | `plans/expansion/g.3-lastfm.md` | Yes | G.2 | Event subscriber on playback events |
| G.4 | Photos: EXIF + slideshow | `plans/expansion/g.4-photos.md` | Yes | A.4 | Library type `photo`, geotag clustering deferred |
| G.5 | Books: EPUB/PDF/CBZ + OPDS feed | `plans/expansion/g.5-books.md` | Yes | A.4 | Reader stub + OPDS at `/opds/v1.2` |
| G.6 | Audiobooks (M4B chapters, multi-file) | `plans/expansion/g.6-audiobooks.md` | Yes | G.5 | Chapter-aware progress; Plex Audiobook agent parity |
| **Phase H — Smart Features** |
| H.1 | Smart-playlist rule engine | `plans/expansion/h.1-smart-playlists.md` | Yes | A.4 | Builder UI + JSON rule DSL; auto-update via folder-watch event |
| H.2 | Collections (manual + rule-based) | `plans/expansion/h.2-collections.md` | Yes | H.1 | Curator UX; bulk-add from search |
| H.3 | Custom CSS / themes | `plans/expansion/h.3-themes.md` | Yes | A.5 | Theme registry shipped as plugin type `ui-theme` |
| H.4 | Trakt scrobble plugin | `plans/expansion/h.4-trakt.md` | Yes | G.3 | OAuth, scrobble + history sync (two-way) |
| H.5 | Trailers + extras | `plans/expansion/h.5-trailers.md` | Yes | A.4 | Local `Trailers/` + TMDB trailers; `-trailer.mkv` naming convention |
| H.6 | Theme music + theme video | `plans/expansion/h.6-theme-media.md` | Yes | H.5 | Auto-play on browse if `theme.mp3` / `backdrop.mp4` present |
| **Phase I — Live TV / DVR / IPTV** |
| I.1 | HDHomeRun tuner driver | `plans/expansion/i.1-hdhomerun.md` | Yes | A.4 | Plugin shape; in-core for usability |
| I.2 | M3U / XMLTV IPTV tuner | `plans/expansion/i.2-iptv.md` | Yes | I.1 | Plays/records w/o xTeVe |
| I.3 | USB DVB-T driver (Linux) | `plans/expansion/i.3-dvbt.md` | Yes | I.1 | v4l2-ctl/dvb-tools wrap |
| I.4 | Schedules Direct EPG | `plans/expansion/i.4-epg.md` | Yes | I.1 | Plus XMLTV import |
| I.5 | Scheduled & series recordings | `plans/expansion/i.5-dvr.md` | Yes | I.4 | Conflict resolver |
| I.6 | Commercial skip via Comskip | `plans/expansion/i.6-comskip-live.md` | Yes | F.5 | EDL on recordings |
| I.7 | Re-stream HLS to remote via hub relay | `plans/expansion/i.7-live-remote.md` | Yes | I.5, C.6 | Live TV works from outside the home |
| **Phase J — DLNA / Cast / Discovery** |
| J.1 | SSDP + mDNS broadcast + listener | `plans/expansion/j.1-discovery.md` | Yes | A.7 | Clients on LAN find server with zero config |
| J.2 | DLNA ContentDirectory full | `plans/expansion/j.2-dlna-cds.md` | Yes | J.1 | Build out the stub in `src/Dlna/` |
| J.3 | DLNA AVTransport "play to" | `plans/expansion/j.3-dlna-play-to.md` | Yes | J.2 | Cast to LG/Samsung DLNA renderers |
| J.4 | Chromecast (Default Media Receiver) | `plans/expansion/j.4-chromecast.md` | Yes | J.1 | Server-side cast session manager |
| J.5 | AirPlay 2 | `plans/expansion/j.5-airplay.md` | Yes | J.1 | mDNS announce + RAOP |
| J.6 | Roku ECP "send to Roku" | `plans/expansion/j.6-roku-ecp.md` | Yes | J.1 | LAN ECP control |
| **Phase K — *arr / Request Integration** |
| K.1 | Sonarr / Radarr API clients (in `phlex-shared`) | `plans/expansion/k.1-arr-clients.md` | Yes | A.7 | Typed clients in shared package |
| K.2 | Bazarr + Prowlarr clients | `plans/expansion/k.2-bazarr-prowlarr.md` | Yes | K.1 | Subtitles + indexers |
| K.3 | Jellyseerr-class request UI on the hub | `plans/expansion/k.3-request-ui.md` | Yes | K.1 | Lives in `phlex-hub`; per-user request quotas |
| K.4 | TRaSH-Guides custom-format sync | `plans/expansion/k.4-trash-guides.md` | Yes | K.1 | Background updater + opt-in profiles |
| **Phase L — Analytics, Notifications, Backup** |
| L.1 | Webhook plugin framework | `plans/expansion/l.1-webhook-framework.md` | Yes | A.4 | Handlebars templates; per-event subscriptions |
| L.2 | Discord/Slack/Telegram/ntfy/Pushover/Apprise/MQTT plugins | `plans/expansion/l.2-notification-plugins.md` | Yes | L.1 | Each its own plugin repo, all listed in core catalog |
| L.3 | Stats schema + collectors | `plans/expansion/l.3-stats-schema.md` | Yes | A.2 | `play_events`, `bandwidth_samples`, `transcode_events` |
| L.4 | Now-playing + top users/media dashboard | `plans/expansion/l.4-stats-dashboard.md` | Yes | L.3 | Tautulli-class UI in WebPortal |
| L.5 | Weekly newsletter email | `plans/expansion/l.5-newsletter.md` | Yes | L.4 | SMTP, opt-in, plugin-overridable templates |
| L.6 | Server backup & restore | `plans/expansion/l.6-backup-restore.md` | Yes | L.3 | DB + config + metadata images; cron + manual; one-click restore |
| **Phase M — Client Hub-Mode & Feature Parity** *(four independent repos; parallelizable)* |
| M.1 | Mobile: hub-mode (sign into hub, list servers) | `plans/expansion/m.1-mobile-hub.md` | Yes | C.9 | Run in `phlex-mobile-client` |
| M.2 | Tizen: hub-mode | `plans/expansion/m.2-tizen-hub.md` | Yes | C.9 | Run in `phlex-tizen-client` |
| M.3 | Roku: hub-mode | `plans/expansion/m.3-roku-hub.md` | Yes | C.9 | Run in `phlex-roku-client` |
| M.4 | Windows: hub-mode | `plans/expansion/m.4-windows-hub.md` | Yes | C.9 | Run in `phlex-windows-client` |
| M.5 | Offline downloads (per client) | `plans/expansion/m.5-offline.md` | Yes | M.1–M.4 | Mobile-priority, then Windows |
| M.6 | SyncPlay on every client | `plans/expansion/m.6-syncplay-clients.md` | Yes | M.1–M.4 | TimeSync API exists server-side already |
| M.7 | Intro-skip button in every player | `plans/expansion/m.7-skip-clients.md` | Yes | F.4, M.1–M.4 | Consume marker API |
| M.8 | Android TV + Apple TV apps (new repos) | `plans/expansion/m.8-new-platforms.md` | Yes | M.1 | Earmark — may defer to v2 |
| **Phase N — End-User Documentation** *(can start once C is green)* |
| N.0 | Choose docs platform + repo layout | `plans/expansion/n.0-docs-platform.md` | No | C.9 | Recommend VitePress in `phlex-docs` repo; or `docs/` subdir in `phlex-server` |
| N.1 | Install — Linux (apt, rpm, source) | `plans/expansion/n.1-install-linux.md` | Yes | N.0 | systemd unit included |
| N.2 | Install — Docker + docker-compose | `plans/expansion/n.2-install-docker.md` | Yes | O.1 | Variant images for HW accel |
| N.3 | Install — Windows | `plans/expansion/n.3-install-windows.md` | Yes | N.0 | XAMPP / WAMP / WSL options |
| N.4 | Install — macOS | `plans/expansion/n.4-install-macos.md` | Yes | N.0 | Brew + native |
| N.5 | Install — Kubernetes | `plans/expansion/n.5-install-k8s.md` | Yes | O.3 | Helm chart in `phlex-helm` |
| N.6 | First-run wizard walkthrough | `plans/expansion/n.6-first-run.md` | Yes | N.0 | Screenshots, end-to-end |
| N.7 | Library setup: movies | `plans/expansion/n.7-lib-movies.md` | Yes | N.6 | Naming conventions, NFO sidecar |
| N.8 | Library setup: TV shows | `plans/expansion/n.8-lib-tv.md` | Yes | N.6 | Season/episode naming, multi-version |
| N.9 | Library setup: music | `plans/expansion/n.9-lib-music.md` | Yes | G.2 | Tagging tools, classical, compilation albums |
| N.10 | Library setup: photos / books / audiobooks | `plans/expansion/n.10-lib-pba.md` | Yes | G.6 | Per-type setup |
| N.11 | Hub: claim your server (end-user) | `plans/expansion/n.11-hub-claim.md` | Yes | C.9 | Screenshots from claim flow |
| N.12 | Hub: share with friends/family | `plans/expansion/n.12-hub-share.md` | Yes | C.9 | Permissions model |
| N.13 | Clients: install per platform | `plans/expansion/n.13-clients-install.md` | Yes | M.4 | Five client guides |
| N.14 | Plugins: install from URL or catalog | `plans/expansion/n.14-plugins-install.md` | Yes | A.7 | Distinct from developer docs |
| N.15 | Hardware transcoding setup | `plans/expansion/n.15-hwaccel-setup.md` | Yes | E.3 | Per-vendor; driver checklist |
| N.16 | Live TV setup | `plans/expansion/n.16-livetv-setup.md` | Yes | I.5 | Tuner selection, EPG account |
| N.17 | Remote access without the hub | `plans/expansion/n.17-remote-no-hub.md` | Yes | C.8 | Cloudflare Tunnel / WireGuard / Tailscale |
| N.18 | Backup & restore | `plans/expansion/n.18-backup-restore.md` | Yes | L.6 | What to back up, how to restore |
| N.19 | Troubleshooting & FAQ | `plans/expansion/n.19-troubleshooting.md` | Yes | N.18 | Common errors, log locations |
| N.20 | Admin reference (env vars, CLI, config files) | `plans/expansion/n.20-admin-reference.md` | Yes | N.18 | Generated where possible |
| N.21 | API reference (OpenAPI/Swagger) | `plans/expansion/n.21-api-reference.md` | Yes | C.9 | Auto-generated from PHP attrs |
| N.22 | Privacy & security guide | `plans/expansion/n.22-privacy-security.md` | Yes | N.0 | Telemetry off-by-default; what hub does/doesn't see |
| N.23 | Contributing guide | `plans/expansion/n.23-contributing.md` | No | N.0 | For both server, hub, clients, plugins |
| N.24 | Developer guide — server | `plans/expansion/n.24-dev-server.md` | Yes | N.0 | Architecture, namespaces, event map, test harness, debug recipes |
| N.25 | Developer guide — hub | `plans/expansion/n.25-dev-hub.md` | Yes | N.0 | Hub-specific architecture, pairing protocol internals, relay design |
| N.26 | Developer guide — plugin SDK | `plans/expansion/n.26-dev-plugins.md` | Yes | A.7 | Manifest, lifecycle, hooks, sample-plugin walkthrough, packaging |
| N.27 | Hub-admin: install & first boot | `plans/expansion/n.27-hub-admin-install.md` | Yes | B.7 | Operating a hub instance: install, env vars, TLS, first user, claim flow QA |
| N.28 | Hub-admin: capacity & relay bandwidth | `plans/expansion/n.28-hub-admin-capacity.md` | Yes | C.6 | Sizing, relay caps, fair-use policy, throttle config |
| N.29 | Hub-admin: abuse, GDPR, takedowns | `plans/expansion/n.29-hub-admin-abuse.md` | Yes | C.9 | DMCA workflow, GDPR data export/delete, suspending a server, audit log review |
| N.30 | Hub-admin: scaling, backups, DR | `plans/expansion/n.30-hub-admin-scaling.md` | Yes | L.6 | Multi-region, DB backups, restore drill, failover playbook |
| N.31 | Hub-admin: monitoring & alerting | `plans/expansion/n.31-hub-admin-monitoring.md` | Yes | L.3 | Metrics export, dashboards, alert rules |
| N.32 | Hub-admin: federation & inter-hub policy | `plans/expansion/n.32-hub-admin-federation.md` | No | C.9 | Decide if/how multiple hubs talk; expectations doc even if v1 is single-hub |
| **Phase O — Deployment / DevOps / Release** |
| O.1 | Docker images: `phlex-server`, `phlex-hub` | `plans/expansion/o.1-docker-images.md` | Yes | B.7 | HW-accel variants (nvidia, intel) |
| O.2 | docker-compose example stacks | `plans/expansion/o.2-compose.md` | Yes | O.1 | Server-only, server+hub, full stack with traefik |
| O.3 | Kubernetes Helm chart | `plans/expansion/o.3-k8s-helm.md` | Yes | O.1 | `phlex-helm` repo |
| O.4 | systemd unit files | `plans/expansion/o.4-systemd.md` | Yes | N.1 | For non-container installs |
| O.5 | nginx + Caddy reverse-proxy templates | `plans/expansion/o.5-rp-templates.md` | Yes | O.4 | TLS, WS upgrade, large-file streaming |
| O.6 | CI: test + build + publish | `plans/expansion/o.6-ci.md` | Yes | O.1 | Re-enable coverage-check that was removed in commit 01fa91b |
| O.7 | Release process & versioning | `plans/expansion/o.7-release.md` | Yes | O.6 | SemVer; hub/server compat matrix |
| **Phase P — Phase-end Audit & v1.0** |
| P.1 | Security audit | `plans/expansion/p.1-security-audit.md` | No | O.7 | External-pen-test brief; OWASP top 10 checklist |
| P.2 | Performance benchmarks | `plans/expansion/p.2-bench.md` | No | O.7 | Concurrent streams, transcode throughput |
| P.3 | v1.0 release of phlex-server, phlex-hub, phlex-shared | `plans/expansion/p.3-release.md` | No | P.1, P.2 | Tagged, signed, Docker pushed |
| P.4 | Public announcement | `plans/expansion/p.4-announce.md` | No | P.3 | Blog post, HN, r/selfhosted |

**Step count:** 95 implementation steps + ~85 review steps. Spread realistically over 6–9 months at a sustainable pace; a multi-supervisor swarm with parallel phases can fit M, N, and O concurrently to compress the back half.

---

## 4. Repo split — exactly what moves where

### 4.1 `phlex-server` (renamed from `phlex`)

Stays the local media server. Keeps **everything currently in `src/`** except things that get extracted into `phlex-shared` in Phase B.

Adds across this plan:
- `src/Hub/HubClient.php` — outbound pairing/heartbeat (Phase C)
- `src/Hub/RelayConsumer.php` — WS reverse-tunnel client (C.6)
- `src/Plugins/*` — manifest reader, loader, lifecycle (Phase A)
- `src/Common/Events/*` — PSR-14 dispatcher (A.2)
- `src/Common/Container/*` — PSR-11 container glue (A.1)
- `src/Media/Hwaccel/*` — probe + profiles (Phase E)
- `src/Media/Markers/*` — Chromaprint + intro/outro store (Phase F)
- `src/Music/`, `src/Photos/`, `src/Books/` — new library types (Phase G)
- `src/Notifications/*` — webhook framework (L.1)
- `src/Analytics/*` — collectors (L.3)
- `src/Backup/*` — DB + config exporter (L.6)

### 4.2 `phlex-hub` (new repo)

Brand-new Workerman app. **Reuses** from `phlex-shared`: `JwtHandler` shape, `UserRepository` shape (separate hub DB), DB pool, structured logger, audit logger. **Does not** include: library scanning, transcoding, FFmpeg, HLS, DLNA, Live TV.

Owns:
- `/api/v1/server-claims` (server side calls in)
- `/api/v1/servers/{id}/heartbeat`, `/info`, `/disconnect`
- `/api/v1/servers/{id}/relay` (WS endpoint for reverse tunnel)
- `/api/v1/users/{id}/servers` (list user's claimed servers)
- `/api/v1/users/{id}/shared` (libraries shared with this user)
- `/api/v1/requests/*` (Jellyseerr-class)
- `/api/v1/webhooks/*` (centralized notification delivery for users that don't want to expose their server)
- Web portal: signup, login, "My Servers", "Shared with me", "Requests"

### 4.3 `phlex-shared` (new Composer package)

Pure typed interfaces and DTOs. **No I/O, no Workerman dependency**, so both server and hub stay independent.

Includes:
- `Phlex\Shared\Auth\JwtClaims` (issuer, audience, scope)
- `Phlex\Shared\Auth\ProviderInterface` (D.1)
- `Phlex\Shared\Hub\ClaimRequest`, `ClaimResponse`, `ServerInfoDto`, `HeartbeatDto`
- `Phlex\Shared\Hub\RelayProtocol` (framing constants)
- `Phlex\Shared\Plugin\ManifestInterface`, `LifecycleInterface`
- `Phlex\Shared\Events\PlaybackStarted`, `LibraryScanned`, `UserCreated`, etc.
- `Phlex\Shared\Arr\SonarrClient`, `RadarrClient`, `BazarrClient`, `ProwlarrClient` (K.1)

### 4.4 What stays in each client repo

No code moves between clients. Each client repo gets a Phase M step that adds **hub-mode** alongside its existing direct-connect mode.

---

## 5. Plugin system at a glance (Phase A output)

```json
{
  "name": "phlex-plugin-lastfm",
  "version": "1.0.0",
  "phlex_min_server_version": "0.10.0",
  "type": "scrobbler",
  "entry": "Phlex\\Plugins\\Lastfm\\Plugin",
  "events": [
    "phlex.playback.started",
    "phlex.playback.stopped"
  ],
  "settings": {
    "api_key": { "type": "string", "required": true, "secret": true },
    "api_secret": { "type": "string", "required": true, "secret": true }
  },
  "signature": "sha256:..."
}
```

**Plugin types** (extensible — each is a registered slot the loader knows about):

| Type | Use case |
|------|----------|
| `metadata-provider` | Movie/TV/music/book metadata (TMDB-like, AniDB, MusicBrainz, OPF) |
| `subtitle-provider` | OpenSubtitles, Addic7ed |
| `auth-provider` | OIDC, LDAP, SAML, custom |
| `library-type` | New library kinds beyond core (e.g., comics, lectures) |
| `notifier` | Discord/Slack/Telegram/etc. |
| `scrobbler` | Trakt, Last.fm, MAL |
| `tuner` | HDHomeRun, IPTV, DVB |
| `transcoder-hook` | Pre/post FFmpeg filter injection |
| `ui-theme` | CSS/JS bundle injected into WebPortal |
| `arr-integration` | Sonarr/Radarr/Bazarr/Prowlarr clients (Phase K bundles into core) |
| `analytics-sink` | Push to InfluxDB, Prometheus, Loki |

**Lifecycle:** install (download + verify signature) → enable (load + subscribe to events + register routes) → disable (unsubscribe, keep config) → uninstall (remove vendor dir + config).

**Hooks come from the PSR-14 dispatcher** (A.2). The plugin loader subscribes the plugin's declared event handlers to the dispatcher on enable. No magic — just classes implementing `Psr\EventDispatcher\ListenerProviderInterface` outputs.

---

## 6. Hub ↔ server pairing protocol (Phase C overview)

```
1. User installs phlex-server, opens local admin UI.
2. Admin clicks "Connect to hub" → server POSTs to https://hub/api/v1/server-claims/new
     body: { server_name, public_keys.jwk, version, hostname_candidates }
   server gets back: { claim_code: "ABCD-1234", expires_in: 600 }
3. Server displays claim_code on screen and via CLI.
4. User logs into hub, clicks "Claim server", pastes ABCD-1234.
   Hub atomically:
     - validates code
     - associates server with user
     - returns enrollment JWT (signed by hub) + hub_jwks_url
5. Server stores enrollment JWT + hub_jwks_url; starts heartbeat loop every 60s.
6. Server publishes its own JWKS at /.well-known/jwks.json.
7. Hub mints user-session JWTs containing user_id + server_id audience.
8. Client receives such JWT from hub, presents to server. Server validates against hub_jwks_url.
```

**Relay (C.6)** kicks in when the client cannot reach the server directly. Server opens persistent WSS to hub. Client requests addressed to `https://<server-id>.phlex.media/*` land at hub, get HTTP-framed over the WSS, hit server, response returns the same way. Bandwidth-priced; admin can disable. Standard reverse-tunnel pattern (frp/ngrok/cloudflared style).

---

## 7. Documentation tree (three audiences, all maintained continuously)

```
docs/
├── README.md                  # landing page: pick your path (end user / dev / hub admin)
├── install/                   # END-USER
│   ├── linux.md
│   ├── docker.md
│   ├── windows.md
│   ├── macos.md
│   └── kubernetes.md
├── first-run.md
├── libraries/
│   ├── overview.md
│   ├── movies.md              # naming conventions, NFO, multi-version
│   ├── tv-shows.md
│   ├── music.md
│   ├── photos.md
│   ├── books.md
│   └── audiobooks.md
├── hub/                       # END-USER (using a hub)
│   ├── what-is-the-hub.md
│   ├── claim-server.md
│   ├── share-with-friends.md
│   └── self-host-the-hub.md
├── clients/
│   ├── mobile.md
│   ├── tizen.md
│   ├── roku.md
│   ├── windows.md
│   └── web.md
├── plugins/
│   ├── install-from-catalog.md
│   ├── install-from-url.md
│   └── trusted-plugin-list.md
├── advanced/
│   ├── hardware-transcoding.md
│   ├── live-tv.md
│   ├── remote-access-without-hub.md
│   ├── reverse-proxy.md
│   ├── backup-restore.md
│   └── arr-integration.md
├── reference/
│   ├── env-vars.md
│   ├── config-files.md
│   ├── cli.md
│   └── api/                    # auto-generated OpenAPI
├── privacy-security.md
├── troubleshooting.md
├── faq.md
├── dev/                        # DEVELOPER
│   ├── architecture-server.md
│   ├── architecture-hub.md
│   ├── pairing-protocol.md
│   ├── event-reference.md      # every dispatched event + payload
│   ├── plugin-sdk.md           # for plugin authors
│   ├── test-harness.md
│   ├── debug-recipes.md
│   ├── release-process.md
│   └── contributing.md
└── hub-admin/                  # HUB ADMIN (operators)
    ├── install.md              # standing up a hub
    ├── first-boot.md           # first admin user, TLS, claim flow QA
    ├── capacity-planning.md    # users / servers / relay BW sizing
    ├── relay-tuning.md         # rate limits, fair-use, throttle config
    ├── abuse-handling.md       # DMCA, takedowns, suspending servers
    ├── gdpr-data-rights.md     # data export, delete, retention
    ├── monitoring-alerting.md  # metrics export, dashboards
    ├── scaling.md              # multi-region, sharding
    ├── backup-restore.md       # full DR playbook
    ├── federation-policy.md    # inter-hub expectations (even if v1 = single hub)
    └── audit-log.md            # what's logged, retention, querying
```

The three trees (end-user / `dev/` / `hub-admin/`) are populated continuously by per-step doc deliverables (§0.4). Phase N tasks polish the published site, not author from scratch.

Each doc page must contain (Phase N rules):
- A one-screen "TL;DR" at the top
- Screenshots from a fresh install (re-generate per release)
- Copy-pasteable shell blocks
- A "What can go wrong" section with three most-common failure modes
- A "Next steps" section linking to two related docs

---

## 8. Feature gap map (where each gap is addressed)

Cross-reference for "where in the plan does Phlex finally get X". Drawn from the competitor research and the current-state inventory.

| Gap | Phase | Steps |
|-----|-------|-------|
| Hub registration / claim flow | C | C.1–C.4 |
| Remote access via relay (no port forward) | C | C.5–C.8 |
| Friend / family library sharing | C | C.9 |
| Plugin system | A | A.1–A.7 |
| OIDC / OAuth SSO | D | D.2 |
| LDAP | D | D.3 |
| Passkeys / WebAuthn | D | D.4 |
| Hardware transcode (NVENC/VAAPI/QSV/VideoToolbox/AMF) | E | E.1–E.2 |
| HDR→SDR hardware tone-map | E | E.3 |
| DASH output | E | E.4 |
| Trickplay (BIF) thumbnail seek | E | E.5 |
| Subtitle burn-in for PGS/ASS | E | E.6 |
| Intro/outro skip via Chromaprint | F | F.1–F.4 |
| Comskip for Live TV | F | F.5 |
| Music library + MusicBrainz | G | G.1–G.2 |
| Last.fm scrobble | G | G.3 |
| Photos + EXIF + slideshow | G | G.4 |
| Books + OPDS | G | G.5 |
| Audiobooks (M4B chapters) | G | G.6 |
| Smart playlists / collections | H | H.1–H.2 |
| Custom CSS / themes | H | H.3 |
| Trakt scrobble | H | H.4 |
| Trailers + extras + theme media | H | H.5–H.6 |
| HDHomeRun + IPTV + DVB-T tuners | I | I.1–I.3 |
| Schedules Direct EPG | I | I.4 |
| Scheduled + series DVR | I | I.5 |
| Remote Live TV (re-stream over hub) | I | I.7 |
| SSDP / mDNS LAN discovery | J | J.1 |
| DLNA full | J | J.2–J.3 |
| Chromecast / AirPlay / Roku ECP | J | J.4–J.6 |
| Sonarr / Radarr / Bazarr / Prowlarr clients | K | K.1–K.2 |
| Jellyseerr-class request UI | K | K.3 |
| TRaSH-Guides custom-format sync | K | K.4 |
| Webhook + Discord/Slack/Telegram/ntfy/etc. | L | L.1–L.2 |
| Tautulli-class stats + newsletter | L | L.3–L.5 |
| Server backup/restore | L | L.6 |
| Offline downloads on every client | M | M.5 |
| SyncPlay on every client | M | M.6 |
| Android TV / Apple TV apps | M | M.8 (earmarked) |
| End-user docs site | N | All |
| Docker / compose / k8s / systemd / nginx | O | O.1–O.5 |

Every gap in the competitor research now has a home in the plan.

---

## 9. Notes on dual-use of the existing `IMPLEMENTATION_PLAN.md` / `SUPERVISOR_PLAN.md`

- Treat the original Phases 1–7 as **largely complete** (verify with the `Inventory` subagent — §11.2 — before starting Phase A). Anything still red from Phase 1–7 gets cleaned up under a one-off "Stabilize" pass *before* A.1 starts. Do not skip.
- The new plan **explicitly does not renumber** the original phases. The original is Phases 1–7; this plan is Phases A–P. No collisions.
- Plan files for this expansion live under `plans/expansion/`, not `plans/phase-{8..N}/`, to keep the namespaces obviously separate.

---

## 10. Risks & open decisions

1. **Hub hosting cost.** Running the relay tier costs bandwidth. Decide upfront whether to (a) charge a fee like Plex Pass, (b) require self-hosted hub, (c) accept donations, or (d) cap free relay at e.g. 1 Mbps like Plex. Recommend (b) + (d): community can self-host, public hub at low cap.
2. **Trademark/branding.** "Phlex" is close to "Plex" — risk of legal pushback. Phase B.4 (rename) is a good moment to revisit.
3. **Hub-server protocol compatibility.** Once C.1 ships and the hub is public, breaking the pairing protocol is painful. Version it from day one: `Accept-Phlex-Protocol: v1`. Add `phlex_min_server_version` to plugin manifests AND to hub.
4. **Plugin signing trust model.** Decide whether to use a central key, multiple keys (per author), or unsigned-with-warning. Recommend per-author keys with a community-curated allowlist for the in-product catalog; unsigned plugins allowed only via "Install from URL" with a scary warning.
5. **Client app distribution.** Each client repo is independent; each needs its own release/store pipeline (App Store, Play, Roku Channel Store, Tizen Store, Microsoft Store). Plan capacity in Phase M and N.13.
6. **Database divergence between hub and server.** Both currently use Workerman MySQL. Hub may eventually outgrow MySQL (relay scaling, cross-region). Treat the schema as the boundary; keep the connection layer pluggable in `phlex-shared`.
7. **Caliber pre-commit hook** must be propagated to `phlex-hub` and `phlex-shared` (Phase B). Forgetting this drops the CLAUDE.md / AGENTS.md sync.

---

## 11. Subagent workflow (the supervisor's playbook)

### 11.1 Session start

Once per session, before spawning any subagent:

```
1. Read this file (PHLEX_EXPANSION_PLAN.md) and pick the next unblocked row.
2. Confirm the step file exists at the linked path.
   If not, the step file is created by an earlier "design" step — go back and do that first.
3. Confirm the repo state (git status clean, on master, pulled) for the target repo.
4. If using OAC: skip context-discovery if already run this session.
```

### 11.2 Inventory subagent (run once at the very start, then again at the start of every phase)

```
description: Inventory phlex repo state
subagent_type: Explore
prompt: |
  Re-run the survey from PHLEX_EXPANSION_PLAN.md §1 against the current repo
  state at /home/sites/phlex (and /home/sites/phlex-hub, /home/sites/phlex-shared
  if they exist). Report a delta table: what's been added, what's been removed,
  what's broken since the last inventory. Under 600 words.
```

### 11.3 Implementation subagent template

```
description: Execute Step <X.Y>
subagent_type: oac:coder-agent     # or general-purpose if OAC isn't installed
prompt: |
  Read and follow the plan at /home/sites/phlex/plans/expansion/<step>.md.
  Work in <target repo path>.

  Acceptance criteria are at the bottom of the plan file — every box must be
  checked. Tests are deliverables, not optional. Use the OAC skill
  `verification-before-completion` if available.

  Follow the git ritual in §11.4 of /home/sites/phlex/PHLEX_EXPANSION_PLAN.md
  exactly. DO NOT skip the `unset GITHUB_TOKEN` step. DO NOT amend commits.

  If you hit a blocker, stop and report — don't invent workarounds.
```

### 11.4 Git ritual (must appear in every step plan, executed in this exact order)

```bash
# ─── 0. PRECONDITION: confirm we're starting from clean master ───
cd <repo>                                  # /home/sites/phlex for server work
                                            # cloned hub/shared dirs for those repos
git status --short                          # MUST be empty; if not, stop and report
git branch --show-current                   # MUST be 'master'; if not, stop and report
git pull --ff-only origin master

# ─── 1. Branch ───
git checkout -b <step-id>-<slug>

# ─── 2. Do the work; add tests; update docs (§0.4); add PHPDocs ───
# (subagent's implementation goes here)

# ─── 3. Verify (§0.4 minimum bar) ───
./vendor/bin/phpunit                                   # green, no skips
./vendor/bin/phpunit --coverage-text                   # NEW classes ≥ 85 % covered
./vendor/bin/phpstan analyze src/ --level=9            # zero new errors vs. master
./vendor/bin/phpcs --standard=PSR12 src/               # clean
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"   # nothing

# ─── 4. Caliber sync (only if hook isn't installed) ───
if ! grep -q "caliber" .git/hooks/pre-commit 2>/dev/null; then
  caliber refresh
fi
git add -A          # includes any updated AGENTS.md / CLAUDE.md / docs/*

# ─── 5. Commit — NEW commit, NEVER --amend ───
git commit -m "Step <X.Y>: <short description>"

# ─── 6. CRITICAL: drop env-injected token before using gh ───
unset GITHUB_TOKEN

# ─── 7. PR, auto-merge, branch delete ───
gh pr create \
  --title "Step <X.Y>: <description>" \
  --body  "Implements step <X.Y> of PHLEX_EXPANSION_PLAN.md."
gh pr merge --squash --delete-branch

# ─── 8. Return to master with merged PR pulled — REQUIRED END STATE ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION assertions (subagent reports these) ───
git status --short                          # MUST be empty
git branch --show-current                   # MUST be 'master'
git log --oneline -1                        # MUST show the new squashed commit
git branch --list <step-id>-*               # MUST be empty (branch was deleted)
```

**The subagent's final report MUST quote the output of step 9.** If any of the four postcondition checks fail, the step is not done — the next subagent will refuse to start. The supervisor verifies the report before moving on.

If the PR doesn't merge cleanly (failing required check, conflict introduced by a parallel step landing first), the subagent rebases on master, re-runs verification, force-pushes the branch, and re-attempts the merge. If it still fails twice, stop and report — the supervisor decides whether to abort or escalate.

### 11.5 Review subagent template

```
description: Review Step <X.Y>
subagent_type: feature-dev:code-reviewer      # or oac:code-reviewer
prompt: |
  Read /home/sites/phlex/plans/expansion/<step>-review.md and the step plan
  it points at. Re-run the acceptance checks and tests. Report PASS/FAIL with
  a one-line reason per criterion. Do not modify code — just report.
```

### 11.6 Parallelism rules

- Phase A is strictly sequential (A.1 → A.7).
- Phase B is sequential.
- Phase C is sequential.
- Phase D steps D.2 / D.3 / D.4 can run in parallel after D.1.
- Phase E steps E.1 → E.2 must run sequentially, then E.3 / E.4 / E.5 / E.6 can parallel.
- Phase F is sequential.
- Phase G steps G.1+G.2+G.3 (music chain) run sequentially; G.4 / G.5 / G.6 can run in parallel afterwards.
- Phase H steps can mostly parallel (H.1, H.2, H.3, H.4, H.5).
- Phase I is sequential except I.1 / I.2 / I.3 can parallel.
- Phase J: J.1 first, then J.2–J.6 parallel.
- Phase K: K.1 first, then K.2 / K.3 / K.4 parallel.
- Phase L is sequential.
- Phase M: M.1 / M.2 / M.3 / M.4 fully parallel (4 different repos); M.5–M.7 parallel after.
- Phase N: N.0 first; then all docs steps parallel.
- Phase O: O.1 first, then O.2–O.5 parallel; O.6, O.7 sequential.

When spawning parallel subagents, do it in a **single message** with multiple `Agent` tool calls so they actually run concurrently.

---

## 12. First action for the supervisor reading this file

```
1. Spawn the Inventory subagent (§11.2) and confirm the §1 current-state table
   still reflects reality. Patch §1 if it doesn't.
2. Spawn the Step A.0 implementation subagent (§11.3 template) pointed at
   plans/expansion/a.0-bootstrap.md.
   (Note: A.0 will itself create that file along with A.1–A.7. The supervisor's
   prompt should explicitly tell A.0 to write its OWN plan file plus A.1–A.7
   step files into plans/expansion/, since they don't exist yet.)
3. Run the Step A.0 review subagent.
4. Then march down the §3 table row by row, respecting the §11.6 parallelism rules.
```

Per session expected cost: A.0 + A.1 + a review or two — small. Subsequent sessions pick up wherever §3 was left off; commit history tells the truth.

---

## 13. Definition of v1.0

The product ships v1.0 when **all of**:

- [ ] `phlex-server`, `phlex-hub`, `phlex-shared` are tagged v1.0.0 on `detain/*`
- [ ] All three GitHub repos have description + 19 topics applied (verified via §14.4)
- [ ] All four existing clients have hub-mode shipped (Phase M.1–M.4)
- [ ] At least 3 plugins are published as separate repos and listed in the in-product catalog (Last.fm, Discord notifications, OIDC)
- [ ] HW transcode + HDR tone-map works on NVENC and VAAPI (Phase E.3 verified on real hardware)
- [ ] Intro skip works on at least one show end-to-end (Phase F)
- [ ] **All three doc trees are complete and published:**
  - [ ] End-user docs cover install → first-scan → first-stream → hub-claim → remote-stream → backup → troubleshooting
  - [ ] Developer docs cover architecture (server + hub), event reference, plugin SDK, test harness, release process
  - [ ] Hub-admin docs cover install → first-boot → capacity → relay tuning → abuse/GDPR → monitoring → scaling → backup-restore → federation policy
- [ ] Test coverage: overall ≥ 80 %, every new class introduced after Phase A ≥ 85 %, integration tests for every hub↔server interaction
- [ ] PHPDoc on every public class and method across `phlex-server`, `phlex-hub`, `phlex-shared`
- [ ] Docker images for `phlex-server` and `phlex-hub` are on a public registry, including HW-accel variants
- [ ] Security audit (P.1) has zero high-severity findings outstanding
- [ ] Bench (P.2) demonstrates 50+ concurrent 1080p direct-play streams from a 4-vCPU server, 5+ concurrent 1080p→720p hwaccel transcodes
- [ ] At least one external contributor has shipped a community plugin

After v1.0: backlog grows organically from the in-product catalog and community plugin authors.

---

## 14. Appendix — Repo metadata (descriptions + topic tags)

Apply via `gh repo edit` in steps B.2a / B.4a / B.5a. Each repo gets exactly 19 topic tags (GitHub's per-repo cap is 20; one slot is reserved for a later `phlex-plugin` add).

### 14.1 `detain/phlex-server`

**Description (≤ 140 chars):**

> `Self-hosted media server in PHP 8 / Workerman. HLS+DASH, hardware transcoding, live TV, SyncPlay, plugins. A Plex/Jellyfin alternative.`

**19 topic tags:**

```
media-server
self-hosted
plex
jellyfin
emby
php
php8
workerman
streaming
hls
transcoding
ffmpeg
video-streaming
media-library
home-theater
dlna
live-tv
dvr
syncplay
```

**Apply:**

```bash
unset GITHUB_TOKEN
gh repo edit detain/phlex-server \
  --description "Self-hosted media server in PHP 8 / Workerman. HLS+DASH, hardware transcoding, live TV, SyncPlay, plugins. A Plex/Jellyfin alternative." \
  --homepage "https://phlex.media" \
  --add-topic media-server \
  --add-topic self-hosted \
  --add-topic plex \
  --add-topic jellyfin \
  --add-topic emby \
  --add-topic php \
  --add-topic php8 \
  --add-topic workerman \
  --add-topic streaming \
  --add-topic hls \
  --add-topic transcoding \
  --add-topic ffmpeg \
  --add-topic video-streaming \
  --add-topic media-library \
  --add-topic home-theater \
  --add-topic dlna \
  --add-topic live-tv \
  --add-topic dvr \
  --add-topic syncplay
```

### 14.2 `detain/phlex-hub`

**Description (≤ 140 chars):**

> `Central cloud directory + reverse-tunnel relay for Phlex media servers. Sign in once, reach any of your servers from anywhere. Self-hostable.`

**19 topic tags:**

```
media-server
media-hub
self-hosted
plex
jellyfin
emby
php
php8
workerman
remote-access
reverse-tunnel
relay
sso
oidc
ldap
dashboard
webhooks
jwt
websocket
```

**Apply:**

```bash
unset GITHUB_TOKEN
gh repo edit detain/phlex-hub \
  --description "Central cloud directory + reverse-tunnel relay for Phlex media servers. Sign in once, reach any of your servers from anywhere. Self-hostable." \
  --homepage "https://phlex.media" \
  --add-topic media-server \
  --add-topic media-hub \
  --add-topic self-hosted \
  --add-topic plex \
  --add-topic jellyfin \
  --add-topic emby \
  --add-topic php \
  --add-topic php8 \
  --add-topic workerman \
  --add-topic remote-access \
  --add-topic reverse-tunnel \
  --add-topic relay \
  --add-topic sso \
  --add-topic oidc \
  --add-topic ldap \
  --add-topic dashboard \
  --add-topic webhooks \
  --add-topic jwt \
  --add-topic websocket
```

### 14.3 `detain/phlex-shared` (already exists, empty; scaffolded in B.2)

**Description (≤ 140 chars):**

> `Shared interfaces, DTOs, event names, and protocol types used by both phlex-server and phlex-hub. Composer-installable, PHP 8.3+, zero I/O.`

**19 topic tags:**

```
php
php8
composer-package
psr-7
psr-11
psr-14
dto
interfaces
shared-library
media-server
jwt
oauth2
oidc
event-dispatcher
plugin-api
typed-php
strict-types
library
sdk
```

**Apply:**

```bash
unset GITHUB_TOKEN
gh repo edit detain/phlex-shared \
  --description "Shared interfaces, DTOs, event names, and protocol types used by both phlex-server and phlex-hub. Composer-installable, PHP 8.3+, zero I/O." \
  --homepage "https://phlex.media" \
  --add-topic php \
  --add-topic php8 \
  --add-topic composer-package \
  --add-topic psr-7 \
  --add-topic psr-11 \
  --add-topic psr-14 \
  --add-topic dto \
  --add-topic interfaces \
  --add-topic shared-library \
  --add-topic media-server \
  --add-topic jwt \
  --add-topic oauth2 \
  --add-topic oidc \
  --add-topic event-dispatcher \
  --add-topic plugin-api \
  --add-topic typed-php \
  --add-topic strict-types \
  --add-topic library \
  --add-topic sdk
```

### 14.4 Verifying the metadata after apply

```bash
unset GITHUB_TOKEN
gh repo view detain/phlex-server --json name,description,homepageUrl,repositoryTopics
gh repo view detain/phlex-hub    --json name,description,homepageUrl,repositoryTopics
gh repo view detain/phlex-shared --json name,description,homepageUrl,repositoryTopics
```

Each must report the expected description and 19 topics. The review subagent for B.2a / B.4a / B.5a runs these checks.

### 14.5 Topic tag rationale (for future expansion)

GitHub topics drive discoverability via the `topics:` search filter and the topic landing pages (e.g., github.com/topics/media-server). Tag categories used here:

| Category | server | hub | shared |
|---|---|---|---|
| Product class | media-server, home-theater | media-server, media-hub, dashboard | shared-library, library, sdk |
| Stack | php, php8, workerman | php, php8, workerman | php, php8, composer-package |
| Comparison / SEO | plex, jellyfin, emby, self-hosted | plex, jellyfin, emby, self-hosted | — |
| Technical surface | hls, transcoding, ffmpeg, video-streaming, media-library, dlna, live-tv, dvr, syncplay, streaming | remote-access, reverse-tunnel, relay, jwt, websocket, webhooks | psr-7, psr-11, psr-14, dto, interfaces, jwt, oauth2, oidc, event-dispatcher, plugin-api, typed-php, strict-types |
| Auth/identity | — | sso, oidc, ldap | jwt, oauth2, oidc |

When v1 plugin work lands, reserve slot 20 on each repo for `phlex-plugin` (or a more specific tag like `media-server-plugin`) so the catalog discovery story is consistent.

---

**End of PHLEX_EXPANSION_PLAN.md**
