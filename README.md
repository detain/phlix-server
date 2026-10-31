# Phlix Media Server

[![PHPUnit](https://github.com/detain/phlix-server/actions/workflows/phpunit.yml/badge.svg)](https://github.com/detain/phlix-server/actions/workflows/phpunit.yml)
[![Coding Standards](https://github.com/detain/phlix-server/actions/workflows/coding-standards.yml/badge.svg)](https://github.com/detain/phlix-server/actions/workflows/coding-standards.yml)
[![Web UI](https://github.com/detain/phlix-server/actions/workflows/web-ui.yml/badge.svg)](https://github.com/detain/phlix-server/actions/workflows/web-ui.yml)
[![codecov](https://codecov.io/gh/detain/phlix-server/graph/badge.svg)](https://codecov.io/gh/detain/phlix-server)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777bb4?logo=php&logoColor=white)](https://www.php.net/)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%209-brightgreen)](https://phpstan.org/)
[![Code style](https://img.shields.io/badge/code%20style-PSR--12-blueviolet)](https://www.php-fig.org/psr/psr-12/)

A comprehensive media server platform built with PHP 8.3+, featuring real-time WebSocket communication, HTTP REST APIs, and support for multiple client platforms including Roku, Samsung Tizen, and Windows.

> **Repository moved 2026-05-17:** this codebase migrated from `github.com/detain/phlix` to [`github.com/detain/phlix-server`](https://github.com/detain/phlix-server) as part of the Phase B repo split. Update existing local clones with `git remote set-url origin git@github.com:detain/phlix-server.git`. The old repo is being archived in step B.4b.

## Overview

Phlix Media Server provides a complete media management and streaming solution:

- **Media Library Management**: Organize and browse media collections with automatic scanning
- **User Authentication**: JWT-based auth with refresh tokens
- **Real-time SyncPlay**: Watch content together with friends
- **Live TV Support**: DVR and guide integration
- **DLNA Streaming**: Standard protocol support for compatible devices
- **Transcoding**: On-the-fly media conversion via FFmpeg with automatic quality selection
- **HLS Streaming**: Adaptive bitrate streaming for web clients with multi-quality playlists. Transcoded
  output is normalized for browser playback: 8-bit H.264 video, **stereo AAC audio** (surround layouts like
  AC-3 5.1(side) are downmixed — a `channel_configuration=0` PCE otherwise breaks hls.js), and even scale
  widths (`force_divisible_by=2`)
- **WebSocket Events**: Real-time progress and notification delivery
- **Backups**: Archives combining a full MySQL dump with the server's config, data, and SSL files, with count-based retention, optional S3 replication, and automatic scheduling — admin API, `bin/phlix` and an admin-only `/app` Backup page; see [Backups (admin-managed)](#backups-admin-managed)
- **Themes (appearance)**: Three built-in token-map themes (`nocturne` / `daylight` / `midnight`) plus plugin-contributed themes, served by a signed-in catalogue API; selection is per-user and validated live — no clearing path exists; see [Themes (choosing an appearance)](#themes-choosing-an-appearance)
- **Multi-Source Metadata**: Automatic metadata fetching from TMDB (movies), TVDB (TV series), Fanart.tv (artwork), and local NFO files with provider fallback. The 24-hour cache freshness window is tunable (`metadata.cache_ttl_hours`), and a minimum title/year match-confidence gate for accepted provider matches (`metadata.min_match_confidence`, default `0` = gate off) — both admin-editable and read live per lookup
- **Content Filtering**: Parental controls with rating and genre-based filtering

## Architecture

```
src/
├── Server/
│   ├── Core/           # Application bootstrap and core
│   ├── Http/            # HTTP REST API layer
│   │   ├── Controllers/ # Request handlers
│   │   ├── Request.php  # HTTP request representation
│   │   ├── Response.php # HTTP response builder
│   │   └── Router.php  # Route dispatching
│   ├── WebSocket/       # Real-time communication
│   │   ├── Connection.php      # Client connection wrapper
│   │   ├── ConnectionPool.php  # Connection management
│   │   ├── MessageHandler.php  # Event routing
│   │   ├── WebSocketServer.php # Server implementation
│   │   └── WebSocketEvents.php # Event type constants
│   └── WebPortal/       # Web portal
│       ├── WebPortalRouter.php # REST API for the /app Vue SPA (JSON only)
│       └── Controllers/SharedUiController.php # serves the /app SPA shell (+ ViteAssets.php)
├── Session/            # Playback session management
├── Media/              # Media library and metadata
│   ├── Library/        # Library management (LibraryManager, ItemRepository, MediaScanner)
│   ├── Metadata/      # Metadata fetching (TMDB, TVDB, Fanart, NFO providers)
│   ├── Transcoding/    # FFmpeg transcoding (FfmpegRunner, TranscodeManager, EncodeSettings)
│   └── Streaming/      # HLS streaming with adaptive bitrate
├── Auth/               # Authentication services
└── Common/             # Shared utilities

public/                 # no front controller — start.php serves everything (see below)
├── templates/emails/   # Smarty newsletter email template (only remaining .tpl)
└── assets/             # Static assets (css, js, images) + assets/app/ (built Vue SPA bundle)
```

## Requirements

- **PHP**: 8.3 or higher
- **MySQL**: 8.0.13 or higher (latest 8.0 patch release recommended), or MariaDB 10.6+
  (The 8.0.13 floor is real, not conservatism: migrations such as 012 use expression
  defaults (`DEFAULT ('[]')`), which MySQL only accepts from 8.0.13 — MariaDB has
  allowed expression defaults since 10.2.)
  (On MariaDB — and on MySQL below 8.0.13/8.0.17 — the MySQL-only functional /
  multi-valued index statements in migrations 011 and 050 are skipped with an
  operator-visible note via the `@phlix-require-mysql` dialect gate in
  `src/Common/Database/MigrationRunner.php`. They are performance-only, and 051
  supersedes the multi-valued one with a join table MariaDB builds natively, so
  no query or feature is lost — only those specific index scans stay slower.)
- **Workerman**: 5.0+ (bundled via Composer)
- **FFmpeg**: For transcoding (optional)

Every environment variable the server process reads is inventoried in the definitive census —
[Environment variables](#environment-variables) — with defaults, secrets, and restart semantics.

## Features

### Foundation
- **PSR-11 DI container (PHP-DI 7)**: auto-wired services with provider-based
  composition; see [phlix-docs / dev / architecture-server](https://detain.github.io/phlix-docs/dev/architecture-server).
- **PSR-14 event dispatcher (Tukio)**: playback, library-scan, and auth
  lifecycle events with typed `readonly` DTOs. Plugins subscribe by event
  class FQCN; see [phlix-docs / dev / event-reference](https://detain.github.io/phlix-docs/dev/event-reference).
- **Plugin system**: install / enable / disable / uninstall lifecycle,
  sandboxed per-plugin `vendor/` directories, signature-checked
  manifests, and PSR-14 event subscription via
  `Phlix\Shared\Plugin\LifecycleInterface` (the
  `Phlix\Plugins\Contract\LifecycleInterface` FQCN remains a
  deprecated bridge through 0.11.x). Installing is not enabling: install
  only stages the plugin (files + sandboxed autoload), **enable** is what
  activates its registration (routes, event subscriptions, settings), and
  disabling is the first troubleshooting step when a plugin misbehaves.
  **Plugin developer guide:**
  [phlix-docs / plugins / developer-guide](https://detain.github.io/phlix-docs/plugins/developer-guide).
  Server-internals reference for contributors extending the loader:
  [phlix-docs / dev / plugin-sdk](https://detain.github.io/phlix-docs/dev/plugin-sdk). Reference
  plugin: [`detain/phlix-plugin-example`](https://github.com/detain/phlix-plugin-example).
- **Shared interfaces / DTOs in `detain/phlix-shared`**: framework-neutral
  Composer package shared with `phlix-hub`. `Phlix\Shared\Plugin\*`,
  `Phlix\Shared\Events\*`, `Phlix\Shared\Auth\JwtClaims`,
  `Phlix\Shared\Metadata\MetadataSourceInterface` (the typed metadata-source
  plugin contract, since `phlix-shared` 0.15.0), and
  `Phlix\Shared\Hub\*` DTOs live there since `phlix-server` 0.11.0.
- **Library matching & de-duplication**: filename→title cleaning strips
  multi-word noise suffixes (`Directors Cut`, `YIFY`, …) before metadata
  matching (admin-tunable via `matching.noise_suffixes`); a canonical-key
  resolver prevents duplicate top-level series/movies at scan time; and the
  admin **Duplicates** page + `scripts/dedup-series.php` merge historical
  duplicates. Metadata source order is configurable per media type via the
  `metadata.provider_priority` setting. See
  [phlix-docs / admin / library-management](https://detain.github.io/phlix-docs/admin/library-management)
  and [phlix-docs / admin / server-settings](https://detain.github.io/phlix-docs/admin/server-settings).

### Web Portal
- **Web portal (`/app`) — the only web UI**: The web portal is the shared `@phlix/ui` Vue SPA, served
  at `/app` (SPA shell via `SharedUiController` + `ViteAssets`; built bundle under
  `public/assets/app/`). Admins work in the **admin section (`/app/admin`)**, an
  admin-gated route family of the same SPA — "web portal (`/app`)" and "admin section
  (`/app/admin`)" are this README's canonical pair for the browser surfaces. Routes +
  nav are registered in `web-ui/src/main.ts`, covering **Browse/Library**, **Player**, **Music** sub-pages
  (`/app/music/*`), **Books** (`/app/books/*`), **Audiobooks** (`/app/audiobooks/*`), **Photos**
  (`/app/photo/*`), **Search** (`/app/search`), **Settings** (incl. the **Security**/passkey tab at
  `/app/settings/security`), and the admin section (`/app/admin/*`).
- **Legacy Smarty page rendering removed**: The old server-rendered (Smarty) portal pages —
  `PageRenderer`, the page controllers, and all `public/templates/**/*.tpl` page templates — have been
  **deleted**. Their old paths now **302-redirect** to the `/app` equivalent (e.g. `/login` →
  `/app/login`, `/library` → `/app`, `/player/{id}` → `/app/player/{id}`, `/music|/books|/audiobooks|/photo`
  → their `/app` pages), dispatched in `src/Server/Workerman/HttpHandler.php` (the served daemon; the pre-S171 `public/index.php` copy of that logic was unserved and deleted). Smarty (`smarty/smarty`) is retained on the
  server **only** for the newsletter email (`src/Admin/NewsletterGenerator.php` +
  `public/templates/emails/newsletter.tpl`).
- **REST API Endpoints**: library browsing, media info, user data, collections, casting, SyncPlay and
  admin surfaces. The [HTTP Endpoints](#http-endpoints) table below is the curated operator view;
  **`openapi.yaml` is the exhaustive list** — a machine-checked bijection with the composed route
  registry, CI-enforced by `OpenapiSpecCurrencyTest`
- **JWT Authentication**: Integrated token-based auth with refresh support
- **Responsive Design**: CSS-first approach with utility classes
- **JavaScript Client**: ApiClient helper with auth, library, and player helpers
- **Continue Watching**: Track and display in-progress media
- **Library Browser**: Browse media by library with item counts
- **Collections & Playlists**: Member-owned media lists with a `created_by` visibility model — see
  [Collections and Playlists](#collections-and-playlists-member-owned-lists)

### Authentication & Security
- **JWT-based Authentication**: Stateless auth with access tokens (1 hour TTL) and refresh tokens (7 days TTL)
- **Signup Approval Queue**: new self-service registrations wait for an admin by default — see [Signup approval gate (adding household users)](#signup-approval-gate-adding-household-users)
- **External SSO — OIDC, LDAP & GitHub**: Optional external login backends, toggled per-provider from the admin **Integrations → Auth providers** page. Flows, endpoints and config pointers: [External SSO](#external-sso).
- **Auth-method lock-out guards**: a settings write or provider disable that would leave every sign-in method off — or an active administrator with no usable factor — is refused with `422` and a machine-readable reason, and nothing is persisted. Rules, reason tokens and recovery: [Auth-method lock-out guards](#auth-method-lock-out-guards).
- **Secure Password Hashing**: Argon2ID for password storage
- **Multi-Device Sessions**: Track and manage sessions across devices
- **User Profiles**: Multiple profiles per account with parental controls
  - Up to 5 profiles per user account
  - Profile-specific content rating restrictions (G, PG, PG-13, R, NC-17, X, UNRATED)
  - PIN protection (4 or 6 digits) for profile settings
  - Genre-based filtering (allowed/blocked genre lists)
  - Daily watch time limits per profile — **stored, not enforced**: the
    `max_daily_watch_time` setting persists through the profile settings API,
    but no playback path reads it (and there is no live per-profile watch-time
    ledger to measure against), so a configured cap never restricts anything
- **Content Rating Filters**: Age-based access restrictions
- **Audit Logging**: Complete security event logging
- **Security Headers / CSP**: Every response carries `X-Content-Type-Options`, `X-Frame-Options`, HSTS,
  and a strict Content-Security-Policy (`SecurityHeaders`). The SPA CSP allows `media-src`/`worker-src
  'self' blob:` so hls.js can attach its MSE `blob:` object URL and transmux Web Worker (required for
  browser HLS playback), and the `/app` shell's inline bootstrap `<script>` runs under a **per-request
  script nonce** rather than `'unsafe-inline'`; non-`/app` responses keep the strict default policy.
  `img-src` additionally allowlists the two TMDB image CDN hosts (`https://image.tmdb.org` and
  `https://tmdb.org`, no wildcard) so poster/backdrop/cast artwork served directly from TMDB renders
  rather than being blocked. This is a **stopgap** until the generic image caching/loader work
  (updates.md #47 / S71-S73) proxies all remote artwork through our own origin, at which point the
  explicit TMDB hosts are removed.

### External SSO

Optional OpenID Connect, LDAP and GitHub OAuth2 login backends, toggled per-provider from the
admin **Integrations → Auth providers** page (persisted as the `auth.oidc.enabled` /
`auth.ldap.enabled` / `auth.github.enabled` server settings). An external identity always
resolves to an existing account — it never silently creates a duplicate — and the auth provider
registry supports multiple instances of the same family (e.g. two OIDC issuers; configuring
named instances from the admin section is a later step).

- **OIDC** — authorization-code flow with PKCE + `state` + `nonce` and id-token validation:
  `GET /auth/oidc/authorize` → `GET /auth/oidc/callback`.
- **GitHub** — plain OAuth2 + PKCE, with no OIDC discovery and no `id_token`:
  `GET /auth/github/authorize` → `GET /auth/github/callback`. The user is identified from
  `GET https://api.github.com/user` (default scopes `read:user user:email`) and keyed on
  GitHub's stable numeric id (`github.<id>`) — never the renameable login, never the e-mail.
- **LDAP** — no separate endpoints. It rides `POST /auth/login` with an `ldap:`-prefixed
  username (e.g. `ldap:jdoe`).
- **Session hardening** — sessions are delivered as httpOnly + Secure + SameSite=Lax cookies.
  The OIDC/GitHub post-login redirect is allowlisted to same-origin paths only. The OAuth
  `state` is additionally bound to the browser that started the flow by a short-lived HttpOnly
  correlation cookie (`phlix_oauth_oidc` / `phlix_oauth_github`).
- **Account linking** — an already-signed-in user can attach an external identity to their
  account. `GET /auth/identities` lists linked identities; `GET /auth/identities/link/oidc` and
  `GET /auth/identities/link/github` start a verified OAuth round-trip link;
  `POST /auth/identities/link/ldap` links via a live LDAP bind;
  `POST /auth/identities/link/hub` links a hub identity from a hub JWT
  (body `{hub_token}`) validated against the enrolled hub;
  `DELETE /auth/identities/{id}`
  unlinks one (own identity only, refused with `409` if it would remove your last sign-in
  method). Linking always proves control of the identity and never mints a new session; an
  identity already linked to another account is rejected with `409`. A linked identity is then
  usable for login.

Config pointers:

- **Provider configuration is DB-backed** (migration `093_plugin_settings.sql`, table
  `plugin_settings`) rather than a per-plugin `settings.json`; an existing file is imported
  once, automatically, on first read — no operator action on upgrade. The OIDC and GitHub
  settings saves **preserve any optional key absent from the request body** (only an
  explicitly empty value clears one), so a partial payload cannot wipe a field it does not
  know about. (The LDAP save still rebuilds its document from the body alone apart from
  `bind_pw` — send it the complete map.)
- **The provider `redirect_uri` is always absolute and is pinned to `PHLIX_DOMAIN`.** Register
  `https://<your PHLIX_DOMAIN>/auth/oidc/callback` (or `/auth/github/callback`) at the IdP /
  OAuth App. If neither `PHLIX_DOMAIN` nor an absolute per-provider `redirect_uri` setting is
  configured, `/auth/{provider}/authorize` **fails closed** with `503
  callback_url_not_configured`. See [Environment variables](#environment-variables) and the
  SSO/external-auth guide in phlix-docs for the full setup, including the GitHub provider
  (which has admin **API** endpoints but no admin-section page yet).

### Auth-method lock-out guards

Sign-in enablement lives in five `server_settings` toggles — `auth.password.enabled`,
`auth.webauthn.enabled`, `auth.oidc.enabled`, `auth.ldap.enabled`, `auth.github.enabled` —
written from the admin settings API (`PUT /api/v1/admin/settings`) and the integrations
page (`POST /api/v1/admin/auth-providers/{name}/disable`). Both surfaces run the same
guard **before persisting anything**: a write-set that would lock the install out is
refused with `422` and **zero persistence** (the toggle rows, the batched non-auth keys
in the same request, everything rolls back together — the settings you had are exactly
the settings you keep; there is nothing to undo after a refusal).

- **Rule R1 — never all-off.** The proposed state must keep at least one of the five
  methods enabled. Refusal reason: `all_methods_disabled`.
- **Rule R2 — never lock an admin out.** The proposed state must leave every **active
  administrator** at least one usable sign-in factor: a password while password sign-in
  stays on, a registered passkey while WebAuthn stays on, or a linked identity for an
  external provider that stays on *and* remains configured. Refusal reason:
  `admin_lockout`. Because any toggle change re-runs this probe, disabling the one
  method that was somebody's only factor is caught from either direction.

The 422 body carries the machine-readable token in `reason` plus a human `message`, so an
integrating UI can react precisely instead of parsing prose:

| `reason` | What it means | What to do |
|---|---|---|
| `all_methods_disabled` | Your write would switch all five sign-in methods off | Keep at least one method on. If you are mid-migration (e.g. moving users to SSO), enable the replacement provider **and** verify admins have linked identities *before* disabling the old method. |
| `admin_lockout` | At least one active admin would be left with no usable factor (message carries the count) | That admin must gain a factor first — sign in as them (or ask them) and register a passkey, set a password, or link the provider — or keep the method you were about to disable switched on for them. |

Two honest edges, both deliberate:

- **The toggles are sign-in-factor switches, not account-creation switches.** With
  `password` off but `auth.signup_mode` still open, fresh accounts keep being minted and
  can sign in — closing signup (`auth.signup_mode = disabled`) is the control for that.
- **Credential management stays available as an escape hatch.** Turning `webauthn` off
  refuses passkey *sign-ins and enrolment*, but listing and deleting your own registered
  passkeys keeps working, so a user can always clean up stale credentials.

If the table is ever forced all-off **outside** the API (direct SQL, a restored backup),
the read path falls back to password-only sign-in so the install stays reachable, and
logs the loud event `auth.methods_all_disabled_fallback` (once per worker). That fallback
is **never persisted** — your data keeps showing the emergency instead of the server
quietly rewriting it. Full envelope shapes and the serialization law:
`docs/dev/AUTH_METHOD_LOCKOUT.md`.

### Signup approval gate (adding household users)

The shipped out-of-the-box behavior **is an approval queue**: `config/auth.php` declares
`'signup_mode' => 'approval'`, and the resolver fails safe to `approval` when the setting
is unreadable or holds an unexpected value (`AuthManager::resolveSignupMode()`). Nobody
slips into a fresh server just because it is reachable.

- **First user** — registering on an empty server ALWAYS creates an **active admin**,
  whatever the mode, so a fresh install bootstraps without an out-of-band step.
- **Everyone else (approval mode — the default)** — the account lands with
  `status='pending'` and **no tokens are issued**: the API replies `202`
  `{"status":"pending","message":"Your account is awaiting administrator approval.","user":null}`
  and the browser flow is redirected back to `/login` carrying that notice. A pending member
  **cannot log in**: correct credentials still answer `403` with
  `code: "auth.account_pending"` (and the OPDS HTTP-Basic path refuses them silently).
- **The admin acts** — approve, disable or reject from the **Users** page of the `/app`
  admin SPA, over REST (`POST /api/v1/admin/users/{id}/approve|disable|reject`;
  `GET /api/v1/admin/users?status=pending` is the queue — see the table under
  [HTTP Endpoints](#http-endpoints)), or — with no web session at all — from the CLI
  (`user:list --status=pending`, `user:approve`, `user:reject`; see
  [Bad days: recovery from the CLI](#bad-days-recovery-from-the-cli)). Approving flips
  the account to `active` so the member can sign in; disabling or rejecting revokes any
  issued tokens as the row is updated or removed.
- **Changing the mode** — `auth.signup_mode` is a server setting like the rest
  (`PUT /api/v1/admin/settings`, allow-list-backed) and is re-read per registration, so
  `open` / `approval` / `disabled` takes effect live, no restart. `open` creates the
  account active and hands back tokens immediately (`201`); `disabled` refuses every
  non-first registration with `403` `auth.signups_disabled` and creates nothing.
- **One honest asymmetry** — a **first login through an external provider**
  (OIDC/GitHub/LDAP) auto-creates the account `active`: it arrives on the provider login
  path, not the registration gate. Keeping provider sign-in enabled is therefore its own
  form of open signup — set that toggle with the household policy in mind, not as a
  substitute for `signup_mode`.

### SyncPlay - Group Watching
- **Synchronized Playback**: Watch content together with friends across devices with sub-second sync accuracy
- **Host-Controlled Playback**: Only the host can control play/pause/seek; all members receive synchronized commands
- **NTP-Style Time Sync**: Network time synchronization with latency compensation and drift correction
- **In-Group Chat**: Real-time messaging with typing indicators and message history
- **Playback Queue**: Host-managed queue with media info (title, thumbnail), capped at 1000 items per group (`GroupState::MAX_QUEUE_SIZE`); an add past the cap is refused with the `syncplay.queue_limit_exceeded` error code
- **Host Election**: Automatic host election when current host leaves (oldest member becomes host)
- **Password Protection**: Optional password protection for private watch parties
- **Position Tolerance**: The sync tolerance window is fixed at 2000 ms (`GroupState::POSITION_TOLERANCE`) to prevent excessive seeking; there is no config key, admin setting, or env for it, and the constructor seams on `GroupState`/`SyncPlayManager` are test-only — no shipped caller passes them
- **Naming layers**: the same concept is named per layer — on the **wire** it is a
  *group* (`/api/v1/syncplay/groups`, the `syncplay_create_group` event); in the
  **server internals** older prose says *room* (the `SyncPlayManager`/`GroupState`
  docblocks in `src/Session/SyncPlay/`); in **user-facing copy** it is a
  *watch party* (the feature line above). A one-noun consolidation is an open
  owner decision — until it lands, this map is the translation table between
  the three vocabularies.

### Session Management
- **Device Sessions**: Track authenticated devices with activity timestamps
- **Playback Progress**: Resume where you left off across sessions
- **Continue Watching**: Track items in progress per profile
- **Completion**: Client-reported via `POST /api/v1/sessions/{id}/complete` — `reached_end: true`
  (the default) marks the item watched and removes it from Continue Watching; `false` clears the
  resume point. The web SPA player fires it on the media `ended` event; native clients do not call
  it yet. Continue Watching and Next Up are derived from the `playback_state` rows written by
  progress reporting — the server applies no automatic progress-threshold completion detection and
  keeps no per-profile watch-time statistics

### Collections and Playlists (member-owned lists)

Collections are user-curated media lists — the feature clients call **Playlists**. The HTTP surface,
the `created_by` ownership model and the OpenAPI paths all shipped before this paragraph existed;
everything below is read from the code at this tip. Routes are registered in
`Application::loadCollectionRoutes()` (`src/Server/Core/Application.php:2190-2218`) under
`[AuthMiddleware]`, served by `src/Server/Http/Controllers/CollectionController.php`; each registration's
exact line is cited in the [HTTP Endpoints](#http-endpoints) table rows below.

- **CRUD loop**: `POST /api/v1/collections` creates — body `{name, library_id}` are both required
  non-empty strings (`400` otherwise), with optional `smart_playlist_id`/`parent_id`/`sort_order` —
  and returns `201 {collection}` (`create()`, `CollectionController.php:116-163`). **`POST /api/v1/playlists`
  is an alias of that same registration** (`Application.php:2210`) — there is no separate playlist table.
  Reads: `GET /api/v1/collections` (visible-to-actor list), `GET /api/v1/collections/{id}`
  (`{collection, items, total}`), `GET /api/v1/libraries/{libraryId}/collections` (list scoped to one
  library). Mutation: `PUT /api/v1/collections/{id}` partial-merges the metadata, `DELETE` removes the
  collection, `POST`/`DELETE /api/v1/collections/{id}/items/{mediaItemId}` add/remove one item, and
  `POST .../bulk-add` takes `{media_item_ids: [...]}` (required non-empty, else `400`) reporting
  `{message, added_count}`.
- **Ownership / visibility (`created_by`)**: migration `112_collections_created_by.sql` added the
  nullable column + `idx_col_owner` and backfilled pre-owner legacy rows — COALESCE(earliest active
  admin, earliest user of any status) — so nothing was orphaned; with no users at all rows stay `NULL`.
  Members **see** their own rows plus legacy `created_by = NULL` rows; **active** admins
  (`is_admin = 1 AND status = 'active'` — never the soft flag alone, and an unwired repository fails
  closed to non-admin) see everything (`actorSees()`, `CollectionController.php:558`). **Writes are
  stricter**: only the row's owner may update/delete/add-items/refresh; foreign and legacy-NULL rows
  are admin-only (`actorManages()`, `:573`). `PUT` never reads `created_by` from the body — ownership
  is immutable, a rename never re-stamps the anchor (`:264-266`).
- **404-not-403 rationale**: every ownership miss answers the byte-identical
  `404 {error: "Collection not found"}` that a genuinely absent id produces (`collectionNotFound()`,
  `:585`; class law `:52-54`). A distinct `403` would make the endpoint an existence oracle — probes
  could enumerate which collection ids exist by the 403-vs-404 delta — so refusal is made
  indistinguishable from absence, mirroring the syncplay room-visibility precedent the docblock cites.
- **Smart collections**: a collection carrying a `smart_playlist_id` is *smart* — its membership is
  materialized in `collection_items` and re-evaluated against the playlist's rules after library scans
  (`SmartPlaylistRefreshSubscriber`, autowired at `MediaServicesProvider.php:899`, subscribed by the
  call pinned in `start.php`'s library-scan worker by `SmartPlaylistRefreshWiringTest`), or on demand
  via `POST /api/v1/collections/{id}/refresh` (`400` "Collection is not
  a smart collection" on a plain row, `200 {message}` on a smart one). Honest gap: **the backing
  rule-sets have no live HTTP CRUD** — the `/api/v1/smart-playlists` registration helper sits
  uncalled in `Router.php` (never invoked; pinned by `SmartPlaylistRegistrationGuardTest`), so a
  `smart_playlist_id` must reference a pre-existing `smart_playlists` row created out-of-band.
- **UI truth at this tip**: the served web SPA bundle (pinned `@phlix/ui` v0.99.10 in
  `web-ui/package.json:15`) exposes the **admin** Collections page (`/app/admin/collections`); the
  member-facing `/app/playlists` page lives in `@phlix/ui` master and lands in this server's bundle
  only at the next web-ui re-pin. Either way clients code against the REST contract above —
  `openapi.yaml` carries all paths (`/api/v1/collections*` from `:5738`) and
  `OpenapiSpecCurrencyTest` machine-checks the route↔spec bijection.

### Live TV & DVR (admin-managed)
LiveTV ships as operator tooling. The entire control plane — tuners, channels, guide,
recordings, and series rules — is served under `/api/v1/admin/livetv` behind admin auth,
and the web SPA exposes it on an admin-only page (`/app/admin/livetv`); there is no
member-facing LiveTV page, channel browse, recording-schedule, or settings surface.
The only non-admin LiveTV routes are stream legs, served under
the same signed-URL/stream middleware as HLS/DASH:
`GET /livetv/recording/{id}/stream` (DVR recording playback, re-checked against the
requester's profile rating cap via the recording's linked library item) and
`GET /livetv/timeshift/{sessionId}/...` (the live tuner's rolling window — session rows
carry only a channel id with no library linkage, so these are rating-gated by nothing, by
design).

Shipped capabilities (driven through the admin API — except recording storage, which is
config-file driven and has no HTTP surface):
- **Multi-Tuner Support**: DVB-T, HDHomeRun, and IPTV tuner drivers; DVB-S, DVB-C, and
  ATSC are reserved type constants only — no drivers exist for them
- **Channel Scanning**: Automatic discovery of broadcast services
- **Electronic Program Guide**: Program info with categories, filtered by channel and
  time range
- **DVR Scheduling**: Schedule recordings with priority management
- **Time-Shifting**: Rolling-HLS buffer of the over-air window; seeking within it is
  inherent to HLS and handled by the player — the server serves the buffer and implements
  no cursor seeking of its own
- **Storage Management**: Used-space tracking and the total recording-size limit are
  enforced internally by the scheduler; configured in `config/livetv.php`
  (`storage_path`, `max_storage_bytes`)

Not yet exposed: per-user channel lineups and personal channel favorites exist only in the
service layer (`ChannelManager` over the `livetv_lineups`/`livetv_favorites` tables); no
HTTP routes or UI back them yet, so members cannot favorite channels or build lineups.

### Backups (admin-managed)
The backup subsystem (`src/Admin/BackupManager.php`) ships as operator tooling: each backup is
one `tar.gz` archive combining a full MySQL dump with the server's files, stored under a local
directory and optionally replicated to S3-compatible object storage. The surface is threefold:
the admin API (`/api/v1/admin/backup/*`, all gated by `AdminMiddleware`), two `bin/phlix` console
commands, and an admin-only **Backup page** in the `/app` SPA (`/app/admin/backup`, shipped in
the vendored `@phlix/ui` bundle — it lists and creates archives and drives restore, S3 upload
and schedule editing over the same seven endpoints below).

All behavior is driven by one standalone config file, `config/backup.php` — it is *not* required
into the `config/server.php` array; each consumer (`BackupManager`, the schedule endpoints, the
timer boot) includes it directly:

| Key | Default | Meaning |
|-----|---------|---------|
| `enabled` | `true` | Master switch; also gates the automatic-backup timer |
| `local_path` | `/var/phlix/backups` | Where archives are stored (created on demand) |
| `retention_count` | `5` | How many of the most recent backups to keep — pruning is by count only |
| `auto_backup_interval_days` | `7` | Days between automatic backups; `0` disables scheduling |
| `s3.enabled` / `s3.bucket` / `s3.region` / `s3.access_key` / `s3.secret_key` / `s3.endpoint` / `s3.prefix` | off; region `us-east-1`; prefix `backups/` | Optional S3-compatible destination; empty `endpoint` means AWS, set it for MinIO/Backblaze-style hosts |

**What an archive holds** (`{label_}backup_<UTC-timestamp>.tar.gz`): a full database dump
(`mysqldump --single-transaction --quick --lock-tables=false`, run shell-less with the password
supplied only via `MYSQL_PWD` so it never enters a world-readable process argv, and success
requires both exit 0 *and* mysqldump's `-- Dump completed` trailer — a truncated dump fails loudly
instead of becoming a silently useless "successful" backup), every `*.php` file under `config/`
(recursively, so nested files like `config/scrobblers/trakt.php` are captured), the `data/`
directory, and SSL certificates from the standard locations if present.

**Retention** runs after every create: backups beyond the newest `retention_count` are deleted —
local file or S3 object plus the `backups` table row. The `expires_at` column is listed by the
API but nothing ever writes it: time-based expiry does not exist.

**Automatic backups** run in the dedicated `count=1` background-timer worker (see `start.php`
section 4c-bis): one catch-up check 5 minutes after boot plus a daily 24-hour check thereafter.
The decision is idempotent — an archive is only created once `most recent backup row +
auto_backup_interval_days` lies in the past (an empty `backups` table schedules immediately), so
restart churn cannot double-back-up. Auto archives carry the label `auto`.

**Restore** (`POST /api/v1/admin/backup/{id}/restore`) is synchronous and checksum-gated: the
archive's SHA-256 is recomputed and must match the stored value before anything is touched (S3
rows are downloaded to a temp file first). It imports `database.sql` via the `mysql` client and
copies the archive's config files back into `config/` (entries that resolve outside `config/` are
skipped with a warning). Two honest boundaries: the `data/` and SSL files inside the archive have
**no restore path** — recover those from the tarball manually — and restore rewrites files only,
so running workers keep their boot-loaded config until you restart.

**S3 upload** (`POST /api/v1/admin/backup/{id}/upload-s3`) pushes a local archive to the bucket
and then rewrites the `backups` row to point at `s3://bucket/key` with `is_s3` set — the local
file is *not* deleted, and from that moment it is no longer tracked by retention (clean it up
yourself). There is no endpoint to pull an S3 row back to local storage (the internal
`downloadFromS3()` has no caller; only restore reaches into the bucket).

Registration detail worth knowing: the seven routes are registered twice on the same router —
the legacy `Application::loadBackupRoutes()` first, then the typed `AdminRoutes::register()` with
the identical paths and handler pairs. The Router stores routes in a method+path-keyed map, so
the second registration overwrites the first, and both resolve the same container-singleton
controller — behavior is identical either way; the `AdminRoutes` entries are what actually serve.

### Themes (choosing an appearance)

The server ships three built-in token-map themes — `nocturne`, `daylight`, `midnight` — and takes
plugin-contributed themes on top. Appearance is **per-user**, and the whole moving parts surface is
a read-only catalogue plus one field on the user-settings endpoint.

**The catalogue (read-only).** `GET /api/v1/themes` answers `{"themes": [ … ]}` — the whole list;
`GET /api/v1/themes/{id}` answers `{"theme": { … }}`, or `404` `{"error":"Theme not found"}`. Both
are registered in `src/Server/WebPortal/WebPortalRouter.php` inside the `AuthMiddleware` group —
sign-in is required because the list names the theme plugins installed on *this* server, which is
plugin-fingerprinting material for an anonymous visitor — and served by
`src/Server/Http/Controllers/ThemesController.php`. Every entry has passed the host token sanitiser
(`ThemeTokenValidator`) whether the host or a plugin supplied it, and carries the wire shape
`{"id", "name", "dark", "extends", "tokens", "source", "builtIn"}`. `builtIn` is load-bearing, not
decorative: the SPA applies a built-in by setting `data-theme` on `<html>` (its real values live in
the shipped stylesheet), while a plugin theme is applied token-by-token — and `tokens` is a theme's
*own* declared map, not a flattened one, so a theme with a non-null `extends` is layered over its
base by the client (every base this server knows about is in the list). Order is deterministic —
built-ins first in `BuiltInThemes::IDS` (colors.css declaration) order, then plugin ids sorted —
and a built-in can never be shadowed by a plugin (the validator refuses reserved ids outright, and
the merge keeps the left operand on a clash). If the theming controller is not wired the endpoints
answer `503` loudly — an empty list would masquerade as "no plugin themes installed" and hide a
broken container wiring indefinitely.

**Selecting a theme.** `PUT /api/v1/users/me/settings` with `{"theme": "<id>"}`. The value is
validated against `BuiltInThemes::IDS` ∪ `ThemeSourceRegistry` — the three built-ins plus exactly
the plugin themes *currently registered* on this server (`src/Auth/UserRepository.php`,
`validateThemeId()`). It fails loud and fails **before** any write: an unknown id, an empty string,
or any non-string (including `null`) throws ahead of the query, so a rejected payload changes zero
rows, and the endpoint answers `400` with the flat `{"error": …}` envelope naming the valid themes.
The pre-S85 names (`phlix-light` / `phlix-dark` / `phlix-amoled` / `phlix-contrast`) are fossils of
the deleted stylesheet-URL theme system: they are not themes any more, and submitting one 400s like
any unknown id.

**Clearing is not supported (v1 law).** There is deliberately **no API path that resets a user's
theme back to unset.** A `NULL` in the column means "this user has never chosen" — a write-once
state, read by design: `GET /api/v1/users/me/settings` *omits* the `theme` key entirely until the
first selection, and clients fall back to their own default (the SPA carries the three built-ins in
its own bundle, so a signed-out page themes itself with zero fetches). Sending `"theme": null` is
therefore not a clear — it 400s like any unknown value. Switching to another theme is the only
change that exists. The playback-preferences save (`PUT /api/v1/me/playback/preferences`) never
touches the theme either: it drops the stored value from its re-pass before writing, so an unrelated
preferences save cannot turn stored history into a 400.

**Install → enable → select (plugin themes).** Installing a theme plugin changes nothing for
anyone. Registration is driven by **enable**: `PluginLoader::enable()` calls
`ThemeSourceRegistry::register()` on the container singleton the catalogue lists from (and disable
removes it again); registration is all-or-nothing fail-closed — one invalid token rejects the whole
enable rather than half-registering it. So three independent steps are required, and the estate's
classic "installed a theme plugin and nothing changed" symptom is skipping the second or the third:
install, then **enable** (this is what puts the plugin's ids into the catalogue and into the
writable set above), then each user **selects** one — enabling does not switch anybody over.
Selection is per-user. Honest edge, by design: ids are validated at *write* time, not continuously,
so a stored selection whose plugin was later disabled remains what `GET` reports (it reads as a
plain string from the column) until the user selects something that currently exists; re-selecting
the disabled theme's id 400s in the meantime.

## Installation

### One-line install (Ubuntu/Debian)

On a fresh Ubuntu/Debian host, [`scripts/install.sh`](scripts/install.sh) does the whole thing:
system packages (PHP 8.3+, MySQL, ffmpeg), a dedicated `phlix` system user, MySQL database +
user, application code, env file at `/etc/phlix/env`, generated `PHLIX_SECRET_KEY`, database
migrations, a systemd `phlix-server` service, and an HAProxy reverse proxy with an
auto-renewing Let's Encrypt certificate.

> The installer also compiles the **Swoole + php-uv** extensions from source (the coroutine
> runtime Workerman uses), idempotently skipping the build when they already load, and runs a
> `disable_functions` preflight — see
> [Swoole & php-uv on Linux](https://detain.github.io/phlix-docs/install/linux#swoole-php-uv-coroutine-runtime).

```bash
curl -fsSL https://raw.githubusercontent.com/detain/phlix-server/master/scripts/install.sh | sudo bash
```

Provision HTTPS in the same run by passing your domain and a Let's Encrypt contact email:

```bash
curl -fsSL https://raw.githubusercontent.com/detain/phlix-server/master/scripts/install.sh \
  | sudo bash -s -- --domain phlix.example.com --admin-email you@example.com
```

The script prompts for the install path, database user/password, and hostname when run in a
terminal (with sensible defaults), and runs **fully unattended** when piped or given `-y`. Run
`sudo bash scripts/install.sh --help` for every flag. Default ports: HTTP on `:8096` behind
HAProxy on `:80`/`:443`; DLNA discovery on `1900/udp`.

### Install flags

`sudo bash scripts/install.sh --help` lists every option. The most useful:

| Flag | Effect |
|---|---|
| `--domain HOST` | Public hostname for the server (enables TLS when paired with `--admin-email`) |
| `--admin-email EMAIL` | Email registered with Let's Encrypt |
| `--db-name`, `--db-user`, `--db-pass`, `--db-host`, `--db-port` | MySQL identity (random password if `--db-pass` omitted). The installer writes these to `/etc/phlix/env`; at runtime `config/database.php` reads all of `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USER` / `DB_PASSWORD` from the environment — see [Environment variables](#environment-variables). |
| `--http-port PORT` | HTTP listen port (default `8096`) |
| `--tmdb-api-key KEY` | TMDB API key for metadata (optional, recorded in `/etc/phlix/env`) |
| `--hub-url URL` | `PHLIX_HUB_URL` for hub relay (optional) |
| `--service-user USER` | System user to run as (default `phlix` — dedicated system account, created if missing) |
| `--branch NAME` | Git branch or tag to install (default `master`) |
| `--repo URL` | Git repository URL (default `detain/phlix-server`) |
| `--tls` / `--no-tls` | Force or skip Let's Encrypt + HAProxy TLS |
| `--no-proxy` | Skip the managed HAProxy entirely (use your own reverse proxy). In non-interactive mode this must be paired with `--i-know-plaintext-internet-facing` — a no-TLS public deploy needs that explicit typed ack too (interactive runs are prompted instead) |
| `--update` | Pull new code + run migrations on an existing install (preserves env + secrets) |
| `--uninstall` | Remove the install — interactive prompts before each destructive step |
| `--purge` | With `--uninstall`, also drop the DB, delete the Let's Encrypt cert, wipe `/var/phlix`, and remove the dedicated system user |
| `-y`, `--non-interactive` | Never prompt; use defaults/flags |
| `--interactive` | Force prompts even when piped |

### Updating an existing install

The same `scripts/install.sh` updates an in-place install **without rotating any secrets**. It
reads the existing `/etc/phlix/env` (so `DB_PASSWORD` and `PHLIX_SECRET_KEY` are preserved),
pulls the latest code, refreshes Composer dependencies, runs migrations, and restarts the
service:

```bash
sudo bash /var/www/phlix/scripts/install.sh --update -y
```

Or via the one-liner, which works regardless of where the server was installed because
`--update` discovers the install path from the systemd unit. **This is the exact string the
admin section's update banner offers for copy-to-clipboard** (`config/updates.php`'s
`update_command`, surfaced by `GET /api/v1/admin/updates/status`) — the server never runs it
for you:

```bash
curl -fsSL https://raw.githubusercontent.com/detain/phlix-server/master/scripts/install.sh \
  | sudo bash -s -- --update -y
```

Pin to a specific tag or branch with `--branch`:

```bash
sudo bash /var/www/phlix/scripts/install.sh --update --branch v0.2.0 -y
```

`--update` discovers the install path from the systemd unit's `WorkingDirectory`, fetches code
as the install dir owner (so it doesn't trip Git's CVE-2022-24765 dubious-ownership check),
runs `composer install --no-dev --optimize-autoloader`, clears `templates_c/`, runs
`scripts/run-migrations.php`, restarts `phlix-server`, and curl-checks `/health`. It
deliberately leaves the env file, MySQL grants, HAProxy config, and Let's Encrypt cert alone.

Before upgrading across several versions, skim [`CHANGELOG.md`](CHANGELOG.md) for every release
you cross: each release section opens with **Upgrade notes** — breaking changes and the
operator actions the deploy must take, or `None.` when the release is drop-in — and
pre-convention releases carry their deploy-critical warnings inline instead
(S331's bold `MIGRATION ANSWER` line shows that older shape). Anything that needs doing beyond the automatic
`scripts/run-migrations.php` step above (a rescan, a new required env var) is named there.

### Uninstalling

`scripts/install.sh --uninstall` removes an existing install. It is **interactive by default**
and prompts separately before each destructive step. The MySQL database, the `/var/phlix` data
directory, and the Let's Encrypt certificate are **kept** unless you opt in:

```bash
sudo bash /var/www/phlix/scripts/install.sh --uninstall
```

Add `--purge` to also drop the database (and user), wipe `/var/phlix` (config, library cache,
backups), and delete the Let's Encrypt certificate via `certbot delete`. Combine with `-y` for
a fully unattended teardown:

```bash
sudo bash /var/www/phlix/scripts/install.sh --uninstall --purge -y
```

What it removes when present:

1. The `phlix-server` systemd unit (`stop`, `disable`, remove file, `daemon-reload`).
2. HAProxy fragment at `/etc/haproxy/phlix-managed/phlix-server.cfg.fragment`, and
   `/etc/haproxy/haproxy.cfg` is rebuilt. If phlix-hub is still installed, its frontend +
   backend stay. If phlix-server was the last Phlix project, the pre-Phlix snapshot at
   `/etc/haproxy/haproxy.cfg.pre-phlix.bak` is restored (or `haproxy.cfg` is removed and
   haproxy is stopped + disabled if no snapshot exists).
3. The combined PEM at `/etc/haproxy/certs/<domain>.pem`.
4. `/etc/cron.d/phlix-server-certbot` and the certbot deploy hook.
5. The Let's Encrypt cert via `certbot delete` — only with `--purge` or interactive confirm.
6. The MySQL database + user — only with `--purge` or interactive confirm.
7. The install dir (`/var/www/phlix` by default; system paths refused).
8. `/var/phlix` (config, library cache, backups) — only with `--purge` or interactive confirm.
9. `/var/log/phlix` and `/var/run/phlix`.
10. `/etc/phlix/env` (env file).
11. The dedicated system user `phlix` via `userdel` — only with `--purge` or interactive
    confirm. Refuses to touch shared OS accounts (`www-data`, `root`, etc.). Cross-detects
    phlix-hub's systemd unit and refuses to remove a user that's still being used by it.

System packages (`php-*`, `mysql-server`, `ffmpeg`, `haproxy`, `certbot`) and `ufw` rules are
left in place — `sudo apt remove …` / `sudo ufw delete …` to remove them.

### Running alongside phlix-hub on the same server

Both installers can share a single HAProxy instance — they auto-merge into one
`/etc/haproxy/haproxy.cfg`. Just run both installers normally; the second one detects the
first's fragment and rebuilds a combined config that routes by `Host:` header.

```bash
# 1. Install phlix-hub first (with TLS).
curl -fsSL https://raw.githubusercontent.com/detain/phlix-hub/master/scripts/install.sh \
  | sudo bash -s -- --domain hub.example.com --admin-email you@example.com -y

# 2. Install phlix-server, also with TLS, on a different hostname.
curl -fsSL https://raw.githubusercontent.com/detain/phlix-server/master/scripts/install.sh \
  | sudo bash -s -- --domain phlix.example.com --admin-email you@example.com -y
```

After both finish, `/etc/haproxy/haproxy.cfg` looks like:

```haproxy
# phlix-managed: rebuilt by phlix install scripts — do not edit
...
frontend fe_https
    bind :443 ssl crt /etc/haproxy/certs/
    http-request set-header X-Forwarded-Proto https

    # --- phlix-hub ---
    acl is_phlix_hub_host hdr(host) -i hub.example.com
    use_backend be_hub_client_relay if is_phlix_hub_host { path_beg /client/ }
    use_backend be_hub if is_phlix_hub_host

    # --- phlix-server ---
    acl is_phlix_server_host hdr(host) -i phlix.example.com
    use_backend be_phlix_server if is_phlix_server_host
    ...
```

**How the merge works.** Each install drops a fragment at
`/etc/haproxy/phlix-managed/<project>.cfg.fragment` with `fe_http`, `fe_https`, and `backends`
sections. A rebuilder function then assembles the final `haproxy.cfg` from every fragment it
finds. HAProxy's `crt /etc/haproxy/certs/` directive auto-loads every `.pem` in that directory
and picks the right one per SNI hostname.

The first install snapshots any pre-Phlix `haproxy.cfg` to
`/etc/haproxy/haproxy.cfg.pre-phlix.bak`.

**Uninstall behavior**: `--uninstall` removes only that project's fragment and rebuilds. If
other Phlix projects remain, their frontend stays untouched. When the **last** Phlix project
is uninstalled, the rebuilder restores the pre-Phlix snapshot (or removes `haproxy.cfg`
outright if there was no pre-Phlix config) and stops/disables `haproxy`.

The **hub server-tunnel port** (`:8802`) is a separate listener — servers connect to that port
directly. Open it on the firewall but don't put it behind the HAProxy 80/443 frontend.

If you'd rather use your own reverse proxy (nginx, Caddy, Traefik, etc.) instead of the
managed HAProxy, pass `--no-proxy` to either install script. Each service then listens on its
own port (8096 for phlix-server, 8800 for phlix-hub) and you point your proxy at those.

Everything else is already namespaced: env files (`/etc/phlix-hub.env` vs `/etc/phlix/env`),
systemd units (`phlix-hub.service` vs `phlix-server.service`), install dirs (`/opt/phlix-hub`
vs `/var/www/phlix`), service users (`www-data` vs `phlix`), MySQL DBs (`phlix_hub` vs
`phlix`), backend ports (8800/8802/8803 vs 8096), and certbot artifacts.

### Manual install (from source)

```bash
# Clone the repository
git clone https://github.com/detain/phlix-server.git
cd phlix-server

# Install dependencies
composer install

# Provision the database the shipped defaults expect: database `phlix`
# and MySQL user `phlix` (config/database.php defaults). This mirrors the
# create-half of scripts/install.sh's own SQL — run as MySQL root over the
# local socket; the grant list is exactly what migrations + runtime need.
# Both host rows matter: a TCP connect to 127.0.0.1 is matched as
# 'localhost' when reverse DNS resolves it (default Ubuntu behavior).
# Replace your_strong_password here and below — the two must match.
sudo mysql <<'SQL'
CREATE DATABASE IF NOT EXISTS `phlix` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'phlix'@'127.0.0.1' IDENTIFIED BY 'your_strong_password';
CREATE USER IF NOT EXISTS 'phlix'@'localhost' IDENTIFIED BY 'your_strong_password';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES ON `phlix`.* TO 'phlix'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES ON `phlix`.* TO 'phlix'@'localhost';
FLUSH PRIVILEGES;
SQL

# Export the credentials — do NOT one-line-prefix them. `FOO=bar php ...`
# sets the variable for that one command only, and config/database.php
# falls back to an EMPTY password (`getenv('DB_PASSWORD') ?: ''`) for
# every later process. start.php additionally refuses to boot at all
# without JWT_SECRET: it signs the JWTs and derives the media signed-URL
# key, so an empty, shipped-default (`default-secret-change-me`), or
# publicly-known placeholder value is fatal on every path, and under 32
# bytes is fatal outside development. `openssl rand -hex 32` (64 chars)
# passes every check; setting PHLIX_ENV=dev relaxes ONLY the length
# floor, never the non-empty rule.
export DB_PASSWORD=your_strong_password
export JWT_SECRET=$(openssl rand -hex 32)

# Run database migrations (reads config/database.php; password from DB_PASSWORD env var)
php bin/phlix migrate    # or: php scripts/run-migrations.php

# Start the server (HTTP :8096 + WebSocket :8097 workers; ports from config/server.php)
php start.php start
```

The `export` lines cover this shell only. For a durable service the values must
reach the process environment the same way the managed path does it:
`scripts/install.sh` writes them (plus `PHLIX_ENV=production`) to
`/etc/phlix/env`, which the systemd unit loads as `EnvironmentFile=`. The PHP
runtime never reads that file by itself — `scripts/bootstrap_env.php` loads it for
the maintenance scripts under `scripts/` and for the hand-run `bin/phlix` CLI,
never overriding already-set variables (M27 — so both documented migration
paths see `/etc/phlix/env` alike) —
so a hand-run `php start.php start` outside a login shell that exports the variables
connects with no password and refuses to boot.
Every key, default, secret flag and restart semantics lives in the [environment
variable census](#environment-variables) below; `DB_PASSWORD` + `JWT_SECRET` are
its "most operators only set" minimum.

## Configuration

Configuration is managed via PHP files in `config/`. The main file is
`config/server.php` — listener binds, the HTTP worker pool, the coroutine
runtime, HLS, the SyncPlay bridge, rate limits, trusted proxies — and it
`require`s the subsystem files (`ffmpeg`, `hub`, `relay`, `livetv`,
`theme_music`, `newsletter`, `artwork`, `metrics`, `folder_watch`) into the
same array. Shape only; every key and its env seam is
documented in the file itself:

```php
// config/server.php
return [
    'server' => [
        'name' => 'Phlix Media Server',
        'host' => '0.0.0.0',
        'port' => 8096,
    ],
    'websocket' => [
        'host' => '0.0.0.0',
        'port' => 8097,
    ],
    // 'worker', 'process', 'coroutine', 'hls', 'syncplay_bridge',
    // 'rate_limit', 'trusted_proxies', ... and the required subsystem files.
];
```

**Database credentials are not a `config/server.php` key.** The connection is
loaded from `config/database.php` (wired as `db_config_path` by `start.php`),
which reads every parameter — `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USER`
/ `DB_PASSWORD` — from the environment, with the defaults shown in the file.
`install.sh` writes them to `/etc/phlix/env`; see the
[Database & migrations](#database--migrations) census below.

### Environment variables

This is the **definitive census** (R14-05): every environment variable the
server process reads, grouped by subsystem. The list was built by grepping
every `getenv()`, `$_ENV[]` and constant-mediated read in `src/`, `config/`,
`start.php`, `bin/` and `scripts/` — if a knob is not here, the PHP runtime
does not read it. Most operators only set `DB_PASSWORD` and `JWT_SECRET`;
everything else has a safe default. "Secret?" marks values that must never be
logged or committed. The "Restart" column (R14-20) uses four values: **yes** =
read once at boot (bind, container/config construction) — a new value applies
only after restart; **per-proc** = read on first use inside each worker
process then memoized — effectively restart-scoped; **live** = `getenv()` on
every use; **n/a** = consumed by the CLI/shell layer, not the daemon. See
[What requires a restart](#what-requires-a-restart) below.

*Where these ticket-style IDs come from:* shorthand like `(R14-05)`, `(S152)`,
`SV-4.15` or `updates.md #47` sprinkled through this README and the code
comments references internal Phlix estate review/fix tracker tickets (numbered
audit waves and the phlix-docs updates log) — provenance annotations for
maintainers, not resolvable from this repo alone.

#### Database

| Variable | Default | Secret? | Restart | Consumed by |
|----------|---------|---------|---------|-------------|
| `PHLIX_DATABASE_{HOST,PORT,NAME,USER,PASSWORD}` | — | (secret for PASSWORD) | n/a (shell) | `docker/docker-entrypoint.sh` alias → `DB_*` (the `DB_*` rows live in [Database & migrations](#database--migrations); compose/helm examples set the `PHLIX_DATABASE_*` spelling — the PHP runtime never reads it) |

#### Listening sockets & processes

| Variable | Default | Secret? | Restart | Consumed by |
|----------|---------|---------|---------|-------------|
| `PHLIX_HTTP_HOST` | `0.0.0.0` (`server.host`) | no | yes | `start.php` via `ListenEndpoints` (R14-06) |
| `PHLIX_HTTP_PORT` | `8096` (`server.port`) | no | yes | same; also feeds the DLNA-advertised port |
| `PHLIX_HTTP_WORKER_COUNT` | `14` (`worker.count`; `auto` → 14) | no | yes | same |
| `PHLIX_WS_HOST` | `0.0.0.0` (`websocket.host`) | no | yes | same (WebSocket worker bind) |
| `PHLIX_WS_PORT` | `8097` (`websocket.port`) | no | yes | same |

#### Logging

| Variable | Default | Secret? | Restart | Consumed by |
|----------|---------|---------|---------|-------------|
| `PHLIX_LOG_STDOUT` | off (`0`) | no | yes | `config/logger.php` — truthy (`1`/`true`/`yes`/`on`) ADDS a `php://stdout` sink for `docker logs`/`journalctl`; file handlers keep working (R14-09) |
| `PHLIX_LOG_LEVEL` | per-handler (`file` info / `error` error / `events`+`plugins` debug) | no | yes | `StructuredLogger::createHandler()` lowers/raises every handler's floor; PSR-3 names, case-insensitive, `warn` accepted; unknown value fails SOFT to shipped defaults (R3-08) |
| `PHLIX_DEBUG_EVENTS` | `0` (off) | no | yes | truthy wraps the PSR-14 dispatcher in a debug decorator AND enables the `events` log handler (`.logs/events.log`). Leave off in production; see `config/logger.php` |

#### File-backed job queues

| Variable | Default | Secret? | Restart | Consumed by |
|----------|---------|---------|---------|-------------|
| `PHLIX_JOB_QUEUE_ROOT` | `<repo>/var/jobs` | no | yes | `JobQueuePaths` — one root for all five queues (R14-14); deliberately NOT `/tmp`, which `PrivateTmp=true` sandboxes per-service |
| `PHLIX_MARKER_JOBS_DIR` | `<root>/marker` | no | yes | marker-detection queue dir (beats `PHLIX_JOB_QUEUE_ROOT`) |
| `PHLIX_MEDIA_ASSET_JOBS_DIR` | `<root>/media_asset` | no | yes | chapter-thumbnail/trickplay queue dir |
| `PHLIX_COLLECTION_JOBS_DIR` | `<root>/collection` | no | yes | TMDB box-set sync queue dir |
| `PHLIX_METADATA_WRITE_JOBS_DIR` | `<root>/metadata_write` | no | yes | metadata write-back queue dir |
| `PHLIX_SIMILARITY_JOBS_DIR` | `<root>/similarity` | no | yes | similarity-computation queue dir |

#### Database & migrations

| Variable | Default | Secret? | Restart | Consumed by |
|----------|---------|---------|---------|-------------|
| `DB_HOST` | `127.0.0.1` | no | yes | `config/database.php` |
| `DB_PORT` | `3306` | no | yes | `config/database.php` |
| `DB_DATABASE` (alias `DB_NAME`) | `phlix` | no | yes | `config/database.php` (`DB_DATABASE` wins) |
| `DB_USER` (alias `DB_USERNAME`) | `phlix` | no | yes | `config/database.php` (`DB_USER` wins) |
| `DB_PASSWORD` | `` (empty) | **yes** | yes | `config/database.php` |
| `DB_POOL_ENABLED` | `1` (on) | no | yes | `config/database.php` → `PooledMySQLConnection` fleet |
| `DB_POOL_SIZE` | `8` | no | yes | `config/database.php` |
| `PHLIX_MIGRATIONS_DIR` | `migrations/` | no | n/a (CLI) | `bin/phlix`, `scripts/run-migrations.php` |
| `PHLIX_MIGRATIONS_STRICT` | off | no | n/a (boot) | **docker-entrypoint shell only** — truthy inverts the migration-failure boot-continue into refuse-to-start; the PHP runtime never reads it |

#### Auth & secrets

| Variable | Default | Secret? | Restart | Consumed by |
|----------|---------|---------|---------|-------------|
| `JWT_SECRET` | dev placeholder — **refuses to boot** unconfigured in production shape | **yes** | yes | `AuthServicesProvider` (pre-fork assert), `SignedUrl` fallback |
| `PHLIX_SECRET_KEY` | — | **yes** | n/a (shell) | **docker-entrypoint alias** → exported as `JWT_SECRET` before the daemon starts; the PHP runtime never reads this name |
| `PHLIX_SIGNED_URL_SECRET` | derives from `JWT_SECRET` (domain-separated) | **yes** | live | `src/Auth/SignedUrl.php` |
| `PHLIX_SIGNED_URL_TTL` | `21600` s (6 h) | no | live | `src/Auth/SignedUrl.php` |
| `PHLIX_COOKIE_INSECURE` | off | no | live | `AuthController` + OIDC/GitHub callback controllers — drops `Secure` on session cookies for plain-HTTP dev (never in production) |
| `PHLIX_TLS_ENABLED` | off | no | yes | `config/hub.php` — https vs wss scheme composition |

#### Hub relay (server → hub tunnels)

| Variable | Default | Secret? | Restart | Consumed by |
|----------|---------|---------|---------|-------------|
| `PHLIX_RELAY_ENABLED` | off | no | yes | `src/Hub/RelayConfig.php` |
| `PHLIX_RELAY_DISABLED` | off | no | yes | kill-switch honored by `RelayTunnelBoot`/`RelayStateStore`/admin hub controller even when config says enabled |
| `PHLIX_RELAY_HUB_URL` / `PHLIX_RELAY_HUB_WS_URL` | _unset_ | no | yes | `RelayConfig.php` |
| `PHLIX_RELAY_LOCAL_HTTP_ADDRESS` | _unset_ | no | yes | `RelayConfig.php` |
| `PHLIX_RELAY_PING_INTERVAL` / `PHLIX_RELAY_PING_TIMEOUT` | `30` / `10` s | no | yes | `RelayConfig.php` |
| `PHLIX_RELAY_RECONNECT_DELAY` / `PHLIX_RELAY_RECONNECT_MAX_DELAY` / `PHLIX_RELAY_RECONNECT_JITTER_FACTOR` | `5` / `300` s / `0.3` | no | yes | `RelayConfig.php` |
| `PHLIX_RELAY_REQUIRE_TLS` | off (`false`) | no | yes | `RelayConfig.php` — when on, refuses non-`wss://` relay dials outright (fail-closed); off (the default) is warn-only — a plaintext tunnel still dials, with a connect-time impersonation warning — so production installs should set `1` |
| `PHLIX_RELAY_ALLOW_PLAINTEXT` | off (`false`) | no | yes | `RelayConfig.php` — opt-in ACK (M5) that silences the connect-time plaintext-tunnel impersonation warning; it changes no behavior, and `PHLIX_RELAY_REQUIRE_TLS` still refuses non-`wss://` dials regardless |
| `PHLIX_RELAY_TLS` | off | no | yes | `RelayConfig.php` |
| `PHLIX_RELAY_TLS_CAFILE` / `PHLIX_RELAY_TLS_VERIFY` | system CA / on (verify) | CA path | yes | `RelayConfig.php`, `src/Hub/HttpClient.php` |
| `PHLIX_RELAY_TUNNEL_HOSTNAME` | _unset_ | no | yes | `RelayConfig.php` |
| `PHLIX_HUB_URL` | _unset_ | no | yes | `config/hub.php` |
| `PHLIX_HUB_JWKS_URL` | derived from `PHLIX_HUB_URL` | no | yes | `config/hub.php` |
| `PHLIX_HUB_HEARTBEAT_INTERVAL` | `60` s | no | yes | `config/hub.php` |
| `PHLIX_HUB_ENROLLMENT_RENEWAL_THRESHOLD` | `86400` s | no | yes | `config/hub.php` |
| `PHLIX_HUB_TLS_VERIFY` / `PHLIX_HUB_TLS_CAFILE` | on / system CA | no | yes | `src/Hub/HttpClient.php` |
| `PHLIX_SUBDOMAIN_AUTO_CLAIM` | on (unless explicitly set off) | no | yes | `config/hub.php` — enforced by `scripts/claim-subdomain.php` (it refuses to claim when off); off with `0`/`false`/`off`/`no`/empty, on with `1`/`true`/`on`/`yes`, an unrecognised value fails toward off (same tolerant rule as `PHLIX_TLS_ENABLED`; before minors M-SERVER-72 an explicit `0` was silently treated as unset) |

#### SyncPlay write-through bridge

| Variable | Default | Secret? | Restart | Consumed by |
|----------|---------|---------|---------|-------------|
| `SYNCPLAY_BRIDGE` | on (only literal `0` disables) | no | yes | `config/server.php` |
| `SYNCPLAY_BRIDGE_SOCKET` | `<repo>/var/syncplay-bridge.sock` | no | yes | `config/server.php` |
| `SYNCPLAY_BRIDGE_TIMEOUT_MS` | `250` | no | yes | `config/server.php` |
| `PHLIX_SYNCPLAY_BRIDGE_TOKEN` | _unset_ | **yes** | yes | `config/server.php` + `src/Session/SyncPlay/SyncPlayBridge.php` |

#### HLS & transcoding

| Variable | Default | Secret? | Restart | Consumed by |
|----------|---------|---------|---------|-------------|
| `HLS_MAX_CONCURRENT_SEGMENTS` | `8` | no | yes | `config/server.php` → `hls.` |
| `HLS_CACHE_MAX_BYTES` | `8 GiB` | no | yes | `config/server.php` → `hls.` |
| `HLS_CACHE_MAX_AGE` | `10800` s | no | yes | `config/server.php` → `hls.` |
| `HLS_MIN_DISK_SPACE_BYTES` | `524288000` (500 MiB) | no | yes | `config/server.php` → `hls.` — free-space floor; below it the segment cache is swept and encodes answer `503` + `Retry-After: 3` instead of `ENOSPC` |
| `PHLIX_HLS_RELAY_MAX_BUFFER_SECONDS` / `PHLIX_HLS_RELAY_MAX_SEGMENTS` | see `config/hls_relay.php` | no | yes | `config/hls_relay.php` |
| `PREFERRED_ACCELERATOR` | `` (auto) | no | yes | `config/transcoding.php` |
| `TONE_MAPPING_MODE` | `none` | no | yes | `config/transcoding.php` |
| `PREFER_HDR_OUTPUT` | `false` | no | yes | `config/transcoding.php` |

#### Storage paths

| Variable | Default | Secret? | Restart | Consumed by |
|----------|---------|---------|---------|-------------|
| `PHLIX_LIBRARY_ROOTS` | see `src/Common/Fs/LibraryRootGuard.php` | no | per-proc | library path jail — read on first use per worker process, then memoized (restart to change mid-flight) |
| `PHLIX_BROWSE_ROOTS` | see `config/filesystem.php` | no | yes | filesystem-browser allowlist (config load at boot) |
| `ARTWORK_STORAGE_PATH` | `/var/artwork` | no | yes | `config/artwork.php` (config load at boot) |
| `SUBTITLE_STORAGE_PATH` | `/var/subtitles` | no | yes | `config/subtitles.php` (config load at boot) |
| `IMDB_CACHE_DIR` | see `config/imdb.php` | no | yes | `config/imdb.php` |
| `PHLIX_THEME_MUSIC_ENABLED` | on (`true`) | no | yes | `config/theme_music.php` — master switch for theme-music resolution (`metadata_json.theme_audio_url` at match time) |
| `PHLIX_THEME_MUSIC_SOURCE` | `local_then_plex` | no | yes | `config/theme_music.php` — `local_then_plex` \| `local_only` \| `off`; unrecognized value falls back to the default |
| `PHLIX_THEME_MUSIC_TIMEOUT` | `8` s | no | yes | `config/theme_music.php` — Plex-archive fetch timeout (positive integer; anything else keeps the default) |
| `PHLIX_THEME_MUSIC_CACHE_DIR` | see `config/theme_music.php` | no | yes | `config/theme_music.php` |

#### Metadata providers & scrobblers

| Variable | Default | Secret? | Restart | Consumed by |
|----------|---------|---------|---------|-------------|
| `TMDB_API_KEY` | _unset_ | **yes** | live | `MediaServicesProvider`, `TmdbApiKeyResolver` (DB admin-setting wins) |
| `IMDB_ENABLED` | off | no | yes | `config/imdb.php` (config load at boot) |
| `TRAKT_CLIENT_ID` / `TRAKT_CLIENT_SECRET` | `` | **yes** (secret) | yes (master switch) | `config/scrobblers/trakt.php` — empty client id = Trakt subsystem off at boot |
| `TRAKT_REDIRECT_URI` | derived from `PHLIX_DOMAIN` | no | yes | `config/scrobblers/trakt.php` |
| `TRAKT_SYNC_INTERVAL` | `30` min (min 5, max 1440) | no | yes | `config/scrobblers/trakt.php` (config load at boot) |
| `TRAKT_TOKEN_ENCRYPTION_KEY` | `` | **yes** | yes | `config/scrobblers/trakt.php` + `Application` — required to persist user OAuth tokens |
| `LASTFM_ENABLED` | `0` (off) | no | yes | `config/lastfm.php` |
| `LASTFM_API_KEY` / `LASTFM_SHARED_SECRET` | `` | **yes** | yes | `config/lastfm.php` (`LASTFM_SHARED_SECRET` feeds both `shared_secret` and `api_secret`) |
| `LASTFM_CALLBACK_URL` / `LASTFM_SESSION_KEY` / `LASTFM_USERNAME` | `` | secret-capable | yes | `config/lastfm.php` |
| `LASTFM_SUBMIT_NOW_PLAYING` | `1` (on) | no | yes | `config/lastfm.php` (config load at boot) |

#### Plugins

| Variable | Default | Secret? | Restart | Consumed by |
|----------|---------|---------|---------|-------------|
| `COMPOSER_BIN` | `composer` on PATH | no | live | `PluginsProvider`, `ComposerRunner`, audit scripts |
| `PHLIX_PLUGINS_COMPOSER_TIMEOUT` | see `PluginsProvider` | no | live | plugin install/update composer runs |
| `PHLIX_PLUGINS_REQUIRE_SIGNATURE` | see `PluginsProvider` | no | live | plugin install signature gate |
| `PHLIX_PLUGINS_ALLOW_UNVERIFIED` | off | no | live | `PluginLoader` — allow loading unsigned plugins (dev only) |
| `PHLIX_PLUGINS_TRUSTED_SIGNATURES` / `PHLIX_PLUGINS_TRUSTED_SIGNATURES_FILE` | _unset_ | no | live | `TrustedSignaturesConfig` — inline list / PEM file of trusted keys |
| `PHLIX_PLUGINS_CATALOG_REF` | branch/tag per `CatalogSourceResolver` | no | live | catalog pin override |
| `PHLIX_PLUGINS_ALLOW_HTTP` | off | no | live | `HttpInstaller` — allow http:// plugin download URLs (dev only) |
| `PHLIX_GITHUB_TOKEN` (fallback `GITHUB_TOKEN`, `GH_TOKEN`) | _unset_ | **yes** | live | `ComposerRunner` — private-repo plugin fetches |
| `USER` | — | no | live | `PluginLoader::currentProcessUser()` — permission-aware enable-diagnostics only, and only when ext-posix is absent (posix uid/pwuid wins when available); `unknown` if unset |

#### HTTP surface, limits & misc

| Variable | Default | Secret? | Restart | Consumed by |
|----------|---------|---------|---------|-------------|
| `PHLIX_CORS_ALLOWED_ORIGINS` | _unset_ | no | live | `CorsManager` — comma-separated origin allowlist |
| `PHLIX_DOMAIN` | _unset_ | no | live | `config/hub.php` composition + the OAuth2/OIDC `redirect_uri` Host allowlist: `/auth/{oidc,github}/authorize` derives `<scheme>://<Host><path>` only when `Host` equals this value; unset/malformed ⇒ fail-closed `503 callback_url_not_configured` (absolute per-provider `redirect_uri` is the escape hatch) |
| `TRUSTED_PROXIES` | loopback (`127.0.0.1`, `::1`) | no | live | `TrustedProxyResolver` — CIDR list of proxy hops allowed to assert `X-Forwarded-For`; unlisted non-loopback proxies bucket every request under the proxy address. REST reads it on every request (a fresh resolver per `Request::getTrustedClientIp()`); the WS-connect path memoizes one resolver per worker (`WebSocketServer`), so there a changed value applies at the next worker boot |
| `RATE_LIMIT_<SURFACE>_MAX` / `RATE_LIMIT_<SURFACE>_WINDOW` | per-surface | no | yes | `config/server.php` → `rate_limit`; `<SURFACE>` ∈ `REGISTER`, `REFRESH`, `WEBAUTHN_START`, `WEBAUTHN_FINISH`, `PIN_VERIFY`, `JWKS`, `WS_CONNECT`, `QUICK_CONNECT_INITIATE`, `QUICK_CONNECT_STATUS`, `QUICK_CONNECT_APPROVE`, `TELEMETRY_HEARTBEAT` (the full set is `RateLimitProfiles`' profile map — a new surface there without a row here is a doc gap) |
| `PHLIX_SSRF_ALLOW_CIDRS` | _unset_ (deny-all private) | no | per-proc | `src/Common/Net/SsrfGuard.php` — extra trusted CIDRs for outbound fetches (e.g. LAN metadata hosts); read on first guard use per worker process, then memoized |
| `PHLIX_METRICS_ENABLED` | `1` | no | yes | `config/metrics.php` (per-worker boot gate) |
| `PHLIX_METRICS_FLUSH_INTERVAL` / `PHLIX_METRICS_BUCKET_SECONDS` / `PHLIX_METRICS_RETENTION_DAYS` / `PHLIX_METRICS_CONNECTION_TTL` / `PHLIX_METRICS_ROUTE_CAP` | `5` / `10` s / `7` d / `15` s / `200` | no | yes | `config/metrics.php` |
| `PHLIX_UPDATES_CHECK_ENABLED` / `PHLIX_UPDATES_MARKER_URL` / `PHLIX_UPDATES_COMMAND` / `PHLIX_UPDATES_POLL_SECONDS` / `PHLIX_UPDATES_TIMEOUT_SECONDS` | see `config/updates.php` | no | yes | `config/updates.php` (update-check timer) |
| `PHLIX_CONTAINER_COMPILE` | off | no | yes | `ContainerFactory` (pre-fork compile) + `EventServicesProvider` (per-worker warm) — compile the PHP-DI container to `var/cache/` |
| `PHLIX_ENV` | `production` | no | yes | `AuthServicesProvider` dev-shape acceptance, `scripts/bootstrap_env.php` |
| `PHLIX_ENV_FILE` | `/etc/phlix/env` | no | n/a (CLI) | `scripts/bootstrap_env.php` |

#### Read via `$_ENV` only — caveat

`config/playback.php` and `config/port-forward.php` read their knobs through
`$_ENV[...]`, **not** `getenv()`. With PHP's common `variables_order = "GPCS"`
(CLI, systemd, most images) `$_ENV` is never populated from the process
environment, so these take their config-file defaults regardless of what you
export. They are effective only where `variables_order` includes `E` (or the
SAPI pre-populates `$_ENV`).

| Variable | Config default | Purpose |
|----------|----------------|---------|
| `PHLIX_CROSSFADE_DURATION` / `PHLIX_CROSSFADE_FADE_OUT` / `PHLIX_CROSSFADE_FADE_IN` | `0` / `0.3` / `0.3` | audio crossfade (disabled by default) |
| `PHLIX_UPNP_ENABLED` | `true` | UPnP IGD discovery |
| `PHLIX_PORT_FORWARD_AUTO` | `true` | automatic NAT-PMP/UPnP pinholing |
| `PHLIX_EXTERNAL_PORT` / `PHLIX_EXTERNAL_HTTP_PORT` / `PHLIX_EXTERNAL_HTTPS_PORT` | `32400` / `8080` / `8443` | advertised external ports |
| `PHLIX_STUN_SERVER` / `PHLIX_STUN_PORT` | `stun.l.google.com` / `19302` | STUN reachability probe |

#### Documented elsewhere, NOT implemented here

These names appear in historical CHANGELOG entries and/or external docs but
have **zero read sites** anywhere in this repository — do not expect setting
them to do anything: `PHLIX_HUB_ENROLLMENT_TOKEN`, `PHLIX_PUBLIC_URL`,
`PHLIX_HWACCEL`.

### What requires a restart

R14-20. Every knob above marked "Restart" is read **once** and changing it in
the process environment has no effect until the unit/container restarts. The
mechanism itself — the graceful SIGUSR2 reload, and exactly what a reload can
and cannot re-read — is written up in
[docs/dev/settings-restart-gap.md](docs/dev/settings-restart-gap.md); read it
before promising that restarting makes a specific setting take effect. An
operator with UI access can trigger that same graceful reload without a shell:
`POST /api/v1/admin/restart` (admin-only) answers with a JSON ack first and
cycles the workers after the response flushes. The short list of why, grouped
by the mechanism that reads it early:

- **Bound before the first fork (`start.php` master):** `PHLIX_HTTP_*`,
  `PHLIX_WS_*` (listen URIs + counts), `PHLIX_CONTAINER_COMPILE` (the compiled
  container is shared through fork inheritance).
- **Per-worker construction at boot:** `PHLIX_LOG_LEVEL` / `PHLIX_LOG_STDOUT` /
  `PHLIX_DEBUG_EVENTS` (handler graph), `DB_POOL_*` and all `DB_*`
  (`ConnectionPool::init()`), `PHLIX_METRICS_*` (collector per worker),
  the SyncPlay bridge sockets, Trakt/Last.fm **master switches**
  (`TRAKT_CLIENT_ID` empty and `LASTFM_ENABLED` decide whether the subsystem
  exists at all), `PHLIX_ENV`, relay/hub wiring (`PHLIX_RELAY_*`,
  `PHLIX_HUB_*`, `PHLIX_TLS_ENABLED`), DLNA `enabled`/`cds_enabled` (the
  `:1900` listener and SSDP advertisement are built at boot).
- **Config-load paths:** the job-queue dirs (`PHLIX_JOB_QUEUE_ROOT`,
  `PHLIX_*_JOBS_DIR`) — a daemon and a standalone worker started at different
  times with different values will not share a queue until both restart.
- **Rate-limit profiles:** `RATE_LIMIT_*` is boot-frozen — `config/server.php`
  reads the values at config load, the PHP-DI limiter profiles are constructed
  per worker, and `start.php` warms the `WS_CONNECT` profile at boot (with
  `PHLIX_CONTAINER_COMPILE` the config is baked into the pre-fork compiled
  container). `RateLimitProfiles` itself reads no proxy env, and no proxy
  setting is frozen here.

Everything else (metadata keys like `metadata.*`, `PHLIX_SIGNED_URL_*`,
`PHLIX_CORS_ALLOWED_ORIGINS`, and the plugin gates read at each
composer/install invocation) is resolved at runtime and applies to the next
request/job without a restart. `TRUSTED_PROXIES` is runtime as well, with one
split: the REST path builds a fresh `TrustedProxyResolver` on every request
(`Request::getTrustedClientIp()`), so there a change applies at the next
request, while the WS-connect path memoizes one resolver per worker
(`WebSocketServer`), so there the change applies at the next worker boot. The
two per-process memos (`PHLIX_SSRF_ALLOW_CIDRS`, `PHLIX_LIBRARY_ROOTS`) apply on
the next worker boot rather than the next request.

> **Rate limiting.** The auth surfaces above (`register` / `refresh` / WebAuthn
> `start`+`finish` / public JWKS / WS-connect on `:8097`) are rate-limited.
> HTTP surfaces reply `429 Too Many Requests` + `Retry-After` with body
> `{"error":"Too Many Requests","code":"rate_limited"}`; WS-connect rejects the
> handshake. `login` keeps its own DB-backed IP limiter (migration 074).

> **Loudness normalization.** `config/ffmpeg.php` → `loudness` (disabled by
> default) applies an EBU R128 `loudnorm` filter to **re-encoded** audio when
> enabled. Copy-audio rungs (the `original` variant) and direct-play sessions
> are not normalized — you cannot filter a copied (non-decoded) stream.

> **Migration required on deploy.** SV-4.15 adds
> `migrations/085_rate_limit_buckets.sql` (the shared DB-backed rate-limit bucket
> table). Run `php bin/phlix migrate` (or `php scripts/run-migrations.php`) after
> pulling — migrations are idempotent, so re-running is safe.

> **No one-time cleanup needed for `media_items` any more (S152).**
> `migrations/096_path_hash_unique_index.sql` adds
> `UNIQUE KEY idx_media_items_library_path_hash (library_id, path_hash)` as part of the
> ordinary migration chain, so a fresh install gets the path-dedupe constraint from
> `php bin/phlix migrate` alone. Before it, migration 072 deferred the index to
> `migrations/cleanup_072.php` and migration 087 dropped it, so an install nobody
> hand-finalized had **no** duplicate-path protection — and every scanner path lookup
> lost the `const` plan. The migration is idempotent and refuses to touch a table that
> still holds duplicates, failing instead with a message that names the remedy: run
> `php migrations/cleanup_072.php` once to merge them, then re-run migrations. That
> script now owns **de-duplication only**.

> **No one-time cleanup needed for `playback_state` any more (S156).**
> `migrations/097_playback_state_unique_key.sql` adds
> `UNIQUE KEY uq_playback_state_session_media (session_id, media_item_id)` as part of the
> ordinary migration chain, so a fresh install gets the progress-upsert constraint from
> `php bin/phlix migrate` (or `php scripts/run-migrations.php`) alone. Before it,
> migration 090 (S29, updates.md #29) reserved the number but carried **no executable
> statement at all** and deferred the key to the manual `migrations/cleanup_090.php`,
> which neither `scripts/install.sh` nor the Docker entrypoint nor either migrate command
> ever calls — so an install nobody hand-finalized had **no**
> `(session_id, media_item_id)` constraint, the `ON DUPLICATE KEY UPDATE` in
> `PlaybackController::reportProgress()` / `StreamManager::persistStreamState()` could
> never fire, and every ~15 s progress tick inserted a new row instead of updating one
> (finished episodes never left Continue Watching and `playback_state` grew without
> bound). The migration is idempotent and refuses to touch a table that still holds
> duplicate rows, failing instead with a message that names the remedy: run
> `php migrations/cleanup_090.php` once to merge them, then re-run migrations. That
> script now owns **de-duplication only** — it is needed only on an existing install that
> accumulated duplicate rows while the key was missing, never on a fresh one.

> **Migration AND rescan required on deploy (S331).** Music albums with no artist
> tag are no longer discarded whole — they are ingested under a structural
> placeholder artist whose display label is `[Unknown Artist]`.
> `migrations/102_music_artists_placeholder_flag.sql` adds
> `music_artists.is_placeholder` and widens `uk_name` to `(name, is_placeholder)`,
> so a REAL album genuinely tagged `[Unknown Artist]` stays a distinct artist
> (`is_placeholder = 0`) from the untagged bucket (`is_placeholder = 1`). Run
> `php bin/phlix migrate` (or `php scripts/run-migrations.php`) after pulling —
> migrations are idempotent, so re-running is safe. ⚠ **A scan (or rescan) of each
> music library is ALSO required**: albums the old policy discarded were never
> written to the database, and no backfill can reconstruct them without re-reading
> the audio files — the migration itself only adds the column and touches no rows.
> The scan summary key `skipped_no_artist` is renamed `placeholder_artist_files`
> (untagged albums are now indexed, not skipped).

## API Reference

### HTTP Endpoints

Curated operator view of the main surfaces. **`openapi.yaml` is the exhaustive
list** — its `paths` are a machine-checked bijection with the composed route
registry (`OpenapiSpecCurrencyTest`), including each route's exact middleware
chain (`x-phlix-middleware`). A route missing below is documentation debt, not
absence; a route missing from `openapi.yaml` turns CI red.

| Method | Path | Description |
|--------|------|-------------|
| GET | `/health` | Health check |
| GET | `/system/info` | Server information |
| POST | `/api/v1/auth/register` | User registration — subject to the signup gate: `201` + tokens when the account is created active (`open` mode, or the always-admin first user), `202` + `{"status":"pending","user":null}` with no tokens in the shipped default `approval` mode, `403` `auth.signups_disabled` in `disabled` mode — see [Signup approval gate](#signup-approval-gate-adding-household-users) |
| POST | `/api/v1/auth/login` | User login |
| POST | `/api/v1/auth/refresh` | Token refresh |
| GET | `/api/v1/auth/me` | Current user profile |
| POST | `/api/v1/sessions` | Create a device session — body `{device_id: string (required), device_name?, device_type?}` → `201 {session_id}`; `400` missing `device_id`, `401` unauthenticated |
| GET | `/api/v1/me/sessions` | List the authenticated user's sessions |
| DELETE | `/api/v1/sessions/{id}` | End a session |
| POST | `/api/v1/sessions/{id}/progress` | Report playback progress |
| GET | `/api/v1/sessions/{id}/progress` | Get playback state |
| POST | `/api/v1/sessions/{id}/complete` | Signal that playback finished — body `{media_item_id: string (required), reached_end?: bool = true}`; `reached_end:true` marks the item watched (leaves Continue Watching, finalizes watch-time/stats), `false` clears the resume point. Auth mirrors `/progress` (session owner). `→ {message, reached_end}`; `404` unknown session, `403` wrong owner, `400` missing `media_item_id`. The web SPA player + mini-player POST this on the media `ended` event; native clients do not call it yet |
| GET | `/api/v1/users/me/continue-watching` (also `/api/v1/me/continue-watching`) | Continue-Watching list for the authenticated user — `{items:[...]}` of `MediaItemShaper`-shaped rows with top-level `id` (= media item id, for detail navigation), `poster_url`/`poster_srcset` (series poster for episodes, falling back season → own), `runtime` (minutes), `year`, `rating`, plus re-attached `position_ticks`/`duration_ticks` (SPA resume) and preserved `media_item_id`, `parent_id`, `metadata` (console/rating-gate compatibility) |
| GET | `/api/v1/users/me/next-up` | "Next Up" rail — for each series the active profile has STARTED, the single next unwatched episode to play (sibling to Continue Watching). Optional `?limit=` (default 20, clamped 1-50; the server scans up to `max(limit×3, 50)` started series). Auth required (`AuthMiddleware`). Returns `{items:[...]}` of `MediaItemShaper`-shaped episode rows (series poster resolved) with `position_ticks`/`duration_ticks`=0 plus added `series_id`/`series_name`; `media_item_id` carried for the rating-gate post-filter. Watched/in-progress signal is `playback_state` ONLY (not `user_item_data.watched` / `watch_history`); only numbered seasons are walked (Specials excluded); all-watched series yield no entry. `401` unauth, `503` if watch-history unwired, `{items:[]}` when no active profile |
| GET | `/api/v1/media/facets` | Distinct, sorted genre facet list for the media filter UI (`?libraryId=<uuid>` to scope) → `{genres: string[]}` |
| GET | `/api/v1/media/most-watched` | GLOBAL "Most Watched" trending rail — the media items most-watched across the WHOLE server (not per-user), reusing the same all-time cross-user aggregate as the admin Top Media report, ordered by play count. Optional `?limit=` (default 20, clamped to 100). Auth required (`AuthMiddleware`, same audience as `GET /api/v1/media`). Returns `{items, total, limit, offset}` with `MediaItemShaper`-shaped items (poster/artwork signed URLs re-minted at response time) |
| GET | `/api/v1/media/{id}` | Media item detail — includes `user_data: {favorite, rating, like_level}` when authenticated (`null` otherwise) |
| POST | `/api/v1/media/{id}/favorite` | Mark a media item as the user's favorite — auth required |
| DELETE | `/api/v1/media/{id}/favorite` | Remove a media item from the user's favorites — auth required |
| PUT | `/api/v1/media/{id}/rating` | Set the user's personal rating (body `{rating: int 1-10\|null}`; `null` clears) — auth required |
| DELETE | `/api/v1/media/{id}/rating` | Clear the user's personal rating — auth required |
| PUT | `/api/v1/media/{id}/like` | Set the user's thumbs value (body `{level: int -2..2}`, required; `-2`=strongly dislike, `-1`=dislike, `0`=not set, `1`=like, `2`=love) — auth required |
| GET | `/api/v1/users/me/favorites` | List the user's favorited items (shaped media items + `user_data` incl. `like_level`; `?limit=1-100&offset`) — auth required |
| GET | `/api/v1/themes` | Theme catalogue — built-ins (`nocturne`/`daylight`/`midnight`) + enabled plugin themes, every entry sanitiser-passed, deterministic order, `{"themes":[...]}`; `503` when theming is unwired — auth required; see [Themes (choosing an appearance)](#themes-choosing-an-appearance) |
| GET | `/api/v1/themes/{id}` | One theme as `{"theme": {...}}`, `404` `{"error":"Theme not found"}` for an unknown id — auth required |
| GET | `/api/v1/users/me/settings` | The user's settings; the `theme` key is present ONLY once a theme was ever chosen (no-clearing v1 law — `NULL` reads as "key omitted", clients fall back to their own default) — auth required |
| PUT | `/api/v1/users/me/settings` | Update the user's settings; a submitted `theme` is validated against built-ins ∪ live plugin registry BEFORE any write — unknown/non-string/`null` → `400` flat `{"error"}` with zero rows changed (clearing has no API path) — auth required |
| GET | `/api/v1/collections` | Collections visible to the actor — own rows + legacy `created_by=NULL` (active admins: all) — auth required (`AuthMiddleware`; `Application.php:2196`) — see [Collections and Playlists](#collections-and-playlists-member-owned-lists) |
| GET | `/api/v1/collections/{id}` | One collection with its items → `{collection, items, total}`; unknown **or** not visible to the actor → byte-identical `404 {error:"Collection not found"}` (`:2197`) |
| GET | `/api/v1/libraries/{libraryId}/collections` | Collections scoped to one library, same visibility law → `{collections:[...]}` (`:2200`) |
| POST | `/api/v1/collections` | Create a collection — body `{name: string (required non-empty), library_id: string (required non-empty), smart_playlist_id?, parent_id?, sort_order?}` → `201 {collection}` with `created_by` stamped to the actor server-side; `400` on a missing/invalid `name`/`library_id` (`:2203`). **`POST /api/v1/playlists` (`:2210`) is an alias to this same handler** — no separate playlist table |
| PUT | `/api/v1/collections/{id}` | Partial-merge update of name/description/sort fields — owner or (for foreign/legacy-NULL rows) active admin; `created_by` is never read from the body (ownership immutable); miss → `404` as absence (`:2204`) |
| DELETE | `/api/v1/collections/{id}` | Delete a collection — owner or active admin → `200 {message:"Collection deleted successfully"}`; miss → `404` (`:2205`) |
| POST | `/api/v1/collections/{id}/items/{mediaItemId}` | Add one item to the collection — owner-or-admin gate; `404` identical to absence on any visibility miss (`:2213`) |
| DELETE | `/api/v1/collections/{id}/items/{mediaItemId}` | Remove one item — same gate (`:2214`) |
| POST | `/api/v1/collections/{id}/bulk-add` | Add many items — body `{media_item_ids: string[] (required, non-empty)}` → `{message, added_count}`; `400` missing/empty; owner-or-admin gate (`:2217`) |
| POST | `/api/v1/collections/{id}/refresh` | Re-evaluate a **smart** collection (one carrying `smart_playlist_id`) against its playlist rules → `200 {message:"Smart collection refreshed"}`; `400` "Collection is not a smart collection" on a plain row; owner-or-admin (`:2218`) |
| POST | `/api/v1/libraries` | Create a library — body `{name: string (required), type: movie\|series\|music\|audiobook\|photo\|book\|video (required), paths: string[] (required, non-empty), options?, series_per_directory?, metadata_priority?}`; each path is stat-checked before anything reaches the DB; `400` on missing/invalid fields (`valid_types` echoed for a bad type) — admin (enforced in-handler, not route-level middleware) |
| GET/PUT/DELETE | `/api/v1/libraries/{id}` | Read / partial-update / remove one library (update re-validates submitted `paths` through the same gate as create) — admin (in-handler) |
| POST | `/api/v1/libraries/{id}/scan` · `/rescan` · `/refresh-metadata` · `/match-metadata` | Queue the add-library initial scan / a full rescan / a metadata re-fetch / a metadata re-match — admin (in-handler); progress via `GET /api/v1/libraries/{id}/scan-history` · `/scan-status` |
| POST | `/api/v1/libraries/{id}/clear-artwork` · `/clear-metadata` · `/prune` · `/regenerate-assets` · `/delete-all` | Library maintenance operations (drop cached artwork / drop metadata / prune vanished files / rebuild derived assets / remove all items) — admin (in-handler) |
| GET | `/api/v1/airplay/devices` · `/api/v1/cast/devices` · `/api/v1/roku/devices` · `/api/v1/dlna/renderers` | Enumerate reachable cast targets per protocol — auth required (`AuthMiddleware`; airplay/cast/roku additionally `CastingEnabledMiddleware`) |
| GET/POST | `/api/v1/airplay/devices/{id}/status` · `/stream` · `/pause` · `/resume` · `/stop` | AirPlay device state and playback control — auth + `CastingEnabledMiddleware` |
| GET/POST | `/api/v1/cast/devices/{id}/status` · `/cast` · `/play` · `/pause` · `/seek` · `/stop` | Chromecast device state and playback control — auth + `CastingEnabledMiddleware` |
| GET/POST | `/api/v1/roku/devices/{id}/status` · `/send` · `/launch/{channelId}` · `/key/{keyName}` | Roku device state, key/EPIT send, channel launch — auth + `CastingEnabledMiddleware` |
| GET/POST | `/api/v1/dlna/renderers/{id}/status` · `/play` · `/pause` · `/seek` · `/stop` | DLNA/UPnP renderer state and transport control — auth required |
| POST | `/api/v1/auth/quick-connect/initiate` | Start a TV quick-connect pairing (returns the short code and its polling URL) — public, rate-limited |
| GET | `/api/v1/auth/quick-connect/{code}/status` | The TV's poll endpoint until a signed-in device approves the pairing — public, rate-limited |
| POST | `/api/v1/auth/quick-connect/{code}/approve` | An authenticated device approves a pending pairing and binds it to its account — auth required |
| POST | `/api/v1/auth/quick-connect/{code}/token` | The TV redeems an approved pairing for a token pair — public, one-shot |
| POST | `/api/v1/auth/hub-token` | Exchange a hub-issued JWT (`{hub_token}`) for a server session token (`{server_session_token}`) — the backward-compatibility path for clients that do not speak hub JWTs; `503` `hub.not_enrolled`, `400` `hub.token_required`, `401` `hub.jwt_invalid` |
| GET | `/opds/v1.2` · `/opds/v1.2/libraries` · `/opds/v1.2/libraries/{id}` · `/opds/v1.2/books/{id}/cover` · `/opds/v1.2/books/{id}/download` | OPDS 1.2 catalog of the book libraries for e-readers — `SignedUrlMiddleware`: access rides a signed URL, not a bearer header |
| GET | `/api/v1/admin/services/{trakt,lastfm}/status` | Per-provider link/quota status as the admin panel sees it — admin-only (`POST /api/v1/admin/services/{trakt,lastfm}/disconnect` unlinks; the user-side connect flows are `GET /api/v1/oauth/{trakt,lastfm}` with `GET .../callback`) |
| GET | `/api/v1/admin/settings` | Effective server settings (config default + DB override) — admin-only |
| PUT | `/api/v1/admin/settings` | Persist server-setting overrides — admin-only. A write-set touching the five `auth.<method>.enabled` toggles is guard-checked first: refused transitions answer `422` + machine `reason` and persist **nothing** (see [Auth-method lock-out guards](#auth-method-lock-out-guards)) |
| GET | `/api/v1/admin/fs/browse` | List subdirectories under allowed roots (library path picker) — admin-only |
| GET | `/api/v1/admin/users` | List all users — optional `?status=pending\|active\|disabled` cohort filter (`pending` is the signup queue) — admin-only |
| GET | `/api/v1/admin/users/{id}` | Get a specific user — admin-only |
| POST | `/api/v1/admin/users` | Create a new user — admin-only |
| PUT | `/api/v1/admin/users/{id}` | Update a user (username, email, password) — admin-only |
| DELETE | `/api/v1/admin/users/{id}` | Delete a user — admin-only |
| POST | `/api/v1/admin/users/{id}/set-admin` | Promote or demote a user — admin-only |
| POST | `/api/v1/admin/users/{id}/reset-password` | Issue a **forced password change**: stores a hashed reset token server-side, sets `must_change_password`, and revokes the account's sessions — the response carries only `{message}`; **no new password is returned**. No shipped surface delivers or consumes the token, so until an operator intervenes the account cannot sign in **with its password** or refresh its tokens — passkey (WebAuthn) and provider sign-in do not consult the flag ([Bad days: recovery from the CLI](#bad-days-recovery-from-the-cli)) — admin-only |
| POST | `/api/v1/admin/users/{id}/approve` | Approve a pending signup (`status` → `active`); `200 {message}`, `404` unknown id — admin-only |
| POST | `/api/v1/admin/users/{id}/disable` | Disable an account — revokes its tokens; `400` when it is your own account or the last admin — admin-only |
| POST | `/api/v1/admin/users/{id}/reject` | Reject a still-pending signup (hard delete); `400` if the account is not pending — admin-only |
| GET | `/auth/oidc/authorize` · `/auth/github/authorize` | Start an external login (**unauthenticated**). Redirects to the IdP / GitHub with PKCE + `state`, sets the browser-binding correlation cookie, and stores the resolved absolute `redirect_uri` in the server-side state. `503` with `provider_not_configured` in the JSON body's `error` field (no `code` key) when the provider is not enabled+configured; `503` with `callback_url_not_configured` in `error` when no absolute callback URL can be resolved (see `PHLIX_DOMAIN`) |
| GET | `/auth/oidc/callback` · `/auth/github/callback` | Provider redirect target (**unauthenticated**). One-shot `state` consume, correlation-cookie check (`403` on mismatch), token exchange with the *same* `redirect_uri` replayed from state, then session cookies + a `302` to the same-origin `redirect_uri`. Also handles the account-link branch |
| GET | `/auth/identities` | List the current user's linked external identities — auth required |
| GET | `/auth/identities/link/oidc` · `/auth/identities/link/github` | Start a verified OAuth link onto the current account — auth required |
| POST | `/auth/identities/link/ldap` | Link an LDAP identity via a live bind (body `{username, password}`) — auth required |
| POST | `/auth/identities/link/hub` | Link a hub identity from a hub JWT (body `{hub_token}`) verified against the enrolled hub — auth required; `503` `hub.not_enrolled` when this server has no hub enrollment, `400` `hub.token_required` on a missing token, `401` `hub.jwt_invalid` on a bad/expired one |
| DELETE | `/auth/identities/{id}` | Unlink one of the current user's identities (`404` if not yours; `409` with `last_sign_in_method` in the JSON body's `error` field, no `code` key, if it is your last) — auth required |
| GET | `/api/v1/admin/auth-providers` | List registered auth providers — admin-only |
| POST | `/api/v1/admin/auth-providers/{name}/enable` \| `/disable` | Enable/disable `oidc`, `ldap` or `github` (`409` with `not_configured` in the JSON body's `error` field — plus the provider `name` — no `code` key; if it has no saved config). `disable` runs the same lock-out guard as `PUT /api/v1/admin/settings`: a provider that is some active admin's only factor (or the last method standing) cannot be switched off — `422` + machine `reason`, nothing persisted (see [Auth-method lock-out guards](#auth-method-lock-out-guards)) — admin-only |
| GET/POST | `/api/v1/admin/auth-providers/oidc/config` | Read / save OIDC config (`provider_url`, `client_id`, `client_secret` write-only, `scopes`, `redirect_uri`). An absent optional key is **preserved**, not cleared — admin-only |
| GET/POST | `/api/v1/admin/auth-providers/ldap/config` | Read / save LDAP config; `POST .../ldap/test` dry-runs a bind — admin-only |
| GET/POST | `/api/v1/admin/auth-providers/github/config` | Read / save GitHub config (`client_id`, `client_secret` write-only, `scopes`, `redirect_uri`); `configured` means id **and** secret are present. An absent optional key is **preserved** — admin-only |
| GET | `/api/v1/admin/auth-providers/{oidc,ldap,github}/schema` | JSON schema for the provider's config form — admin-only |
| POST | `/api/v1/admin/backup/create` | Create a backup archive now — optional body `{label: string}` (non-string `label` → `400`); `data` carries `backup_id`, `file_path`, `size_bytes`; failure → `500` — admin-only |
| GET | `/api/v1/admin/backup/list` | All backups newest-first — rows carry `id`, `label`, `file_path`, `size_bytes`, `checksum_sha256`, `is_s3`, `created_at`, `expires_at` (the latter is never written; always `null`) — admin-only |
| DELETE | `/api/v1/admin/backup/{id}` | Delete one backup (local file or S3 object plus its row); `404` unknown id — admin-only |
| POST | `/api/v1/admin/backup/{id}/restore` | Synchronous restore — SHA-256 verified before anything is touched; re-imports the DB dump and the config files (not `data/`/SSL); failure incl. unknown id → `500` with the reason — admin-only |
| POST | `/api/v1/admin/backup/{id}/upload-s3` | Push a still-local archive to S3 and mark the row `is_s3` (local file is left on disk, untracked); `500` when S3 is not configured or the upload fails — admin-only |
| GET | `/api/v1/admin/backup/schedule` | Effective schedule — `auto_backup_interval_days` + `retention_count` re-read from `config/backup.php` per request, plus `next_scheduled_backup` (unix, `null` when scheduling is off) and its ISO form — admin-only |
| PUT | `/api/v1/admin/backup/schedule` | Update schedule — body `{auto_backup_interval_days?: int ≥ 0, retention_count?: int ≥ 1}` (non-numeric/negative/`<1` → `400`); rewrites `config/backup.php` (comments are lost); the running auto-backup timer adopts the new values at its next restart — admin-only |

**Rate limiting.** `POST /auth/register`, `POST /auth/refresh`, the WebAuthn
login `start`/`finish` endpoints, and the public JWKS endpoint reply
`429 Too Many Requests` + `Retry-After` (body
`{"error":"Too Many Requests","code":"rate_limited"}`) when a caller exceeds the
per-surface limit. Tune via `RATE_LIMIT_*` (see [Environment variables](#environment-variables)).

**Auth-method lock-out guard (`422`).** `PUT /api/v1/admin/settings` (when the write-set
touches any of the five `auth.<method>.enabled` toggles) and
`POST /api/v1/admin/auth-providers/{name}/disable` answer
`422 {"success":false,"error":"Validation failed","errors":{"auth.<method>.enabled":"<message>"},"reason":"<token>","message":"<message>"}`
when the proposed state would lock the install out, and persist nothing. The `reason`
token is the stable machine-readable field (the per-key `errors` map covers form
rendering; `message` is operator prose):

| `reason` value | Enforced rule | What the caller should do |
|---|---|---|
| `all_methods_disabled` | R1 — at least one sign-in method must stay enabled | Keep one method on; enable/verify the replacement before disabling the old one. |
| `admin_lockout` | R2/R2-bis — an active admin would lose their last usable factor | Have that admin gain a factor first (password, passkey, or a linked+configured provider identity), or leave the method on. |

Neither token is a new wire *error code* — no `code` channel is involved; `reason` is a
response-body field the guard has always shipped. Semantics, envelopes and recovery in
[Auth-method lock-out guards](#auth-method-lock-out-guards) and
`docs/dev/AUTH_METHOD_LOCKOUT.md`.

**JWKS key format.** `GET /.well-known/jwks.json` serves this server's Ed25519
public key from `config/hub-server-key.pem`. The reader accepts **both** the
app's native `-----BEGIN ED25519 PRIVATE KEY-----` format **and** a standard
PKCS#8 Ed25519 key (`-----BEGIN PRIVATE KEY-----`, e.g. from
`openssl genpkey -algorithm Ed25519`). If the key cannot be loaded, the endpoint
degrades to a valid empty keyset (`{"keys":[]}`, HTTP 200) and logs an error
rather than returning a 500.

**Client capability negotiation.** Send an `X-Phlix-Client-Capabilities` request
header (a JSON codec-support map, e.g. `{"eac3":false}`) on playback-info
requests. When present, the `direct_play` verdict is set from whether the client
can decode the item's audio codec; absent/empty header keeps the previous
always-`true` behavior.

### WebSocket Events

Event names are defined as constants in `src/Server/WebSocket/WebSocketEvents.php`
(the full catalogue — 26 wire events — is below; `SYNCPLAY_PREFIX` in the same
file is a name-building helper, not an event).

**Connection Events:**
- `connected` - Fired when a client successfully connects
- `disconnected` - Fired when a client disconnects from the server
- `client_disconnected` - Broadcast when an authenticated client disconnects

**Authentication Events:**
- `auth_request` - Client requests authentication
- `auth_success` - Authentication was successful
- `auth_failure` - Authentication failed

**Session Events:**
- `session_start` - A new playback session has started
- `session_end` - A playback session has ended
- `session_join` - Client joined a session
- `session_leave` - Client left a session

**Playback Events:**
- `playback_start` - Playback started
- `playback_pause` - Playback paused
- `playback_stop` - Playback stopped
- `playback_progress` - Playback progress update
- `playback_seek` - Playback seek performed

**SyncPlay Events:**
- `syncplay_create_group` - Request to create a SyncPlay group
- `syncplay_join_group` - Request to join a SyncPlay group
- `syncplay_leave_group` - Request to leave a SyncPlay group
- `syncplay_sync_state` - SyncPlay state synchronization
- `syncplay_sync_request` - SyncPlay sync request

**General Events:**
- `error` - General error event
- `ping` - Ping request
- `pong` - Pong response
- `notification` - General notification
- `library_updated` - Library content has been updated
- `dashboard_now_playing` - Dashboard now-playing update broadcast
- `subscribe_dashboard` - Client subscribes to dashboard now-playing updates

## Development

### Running Tests

```bash
# Run all tests
./vendor/bin/phpunit

# Run with coverage
./vendor/bin/phpunit --coverage-html coverage-report

# Run specific test suite
./vendor/bin/phpunit --testsuite Unit
./vendor/bin/phpunit --testsuite Integration
./vendor/bin/phpunit --testsuite E2E
```

The `E2E` suite is the browser / hardware-encoder tail (`tests/E2E/`):
playback through the real controller stack, HLS/fMP4 fixtures, hwaccel
probes. It is serial-only by design — under load `HwaccelE2ETest` contends
for the encoder and the multicast-receive classes misfire — so run it
plainly, never under the parallel seam below.

**Real-database integration tests self-skip.** Tests under `tests/Integration/`
that need a live MySQL probe the configured host first and skip when it is
unreachable, so `./vendor/bin/phpunit` is green on a machine with no database —
it just reports more skips. To actually exercise them, point the suite at a
throwaway MySQL that has had `php scripts/run-migrations.php` applied:

```bash
DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=phlix_test DB_USER=root DB_PASSWORD=root \
  ./vendor/bin/phpunit --no-coverage
```

CI does exactly this (`.github/workflows/phpunit.yml` provisions a `mysql:8.0`
service and runs the migrations before the suite), so these tests are not
decorative. Note that the network-dependent group is excluded by `phpunit.xml`
on purpose: `Phlix\Plugins\OAuth2\OAuth2HttpClient` (the live OAuth2 transport)
is driven by a fake in every provider test and is effectively uncovered — closing
that gap needs a live HTTP fixture.

**CI does not run a single `phpunit` invocation, and neither should you for a
full local pass.** The `phpunit.yml` job drives the two fast suites through
paraunit with per-worker isolation: `bash scripts/parallel/run-suite.sh
<Unit|Integration> <workers> [shards]` (Unit at parallel 8 — it touches no
database; Integration at parallel 8 against eight clone schemas, because two
workers sharing one schema corrupt each other's row counts). The clones come
from `PHLIX_TEST_DB_SHARDS=8 ./scripts/parallel-test-db.sh create|drop`, whose
template is the `$DB_DATABASE` schema migrated exactly as CI migrates it. Both
scripts work locally and are the supported parallel seam; `run-suite.sh`
deliberately refuses `E2E` (serial-tail law above, and its `Serialized`
multicast group lives in `phpunit-parallel.xml`). The serial commands above
remain correct for single files and small slices — the seams are opt-in.

#### Comparing skip sets between two runs — never compare the COUNT

Never compare the `Skipped: N` count between two runs — this repo has legitimate
environment-dependent skips, and a moved count cannot distinguish "a test started
skipping" from "a skipped test became a failure" from "a test disappeared". Compare
the NAME SETS instead: `phpunit.xml` sets `displayDetailsOnSkippedTests="true"`, so
`./vendor/bin/phpunit 2>&1 | scripts/skipped-test-names.sh > /tmp/after.txt` before
and after your change, then `comm -3 /tmp/before.txt /tmp/after.txt` (left column:
tests that dropped out of the skip set; right column: tests that joined it). The
script never hands over an empty set silently — each non-zero exit names its cause,
and denominators go to stderr. The deep contract (exit-code table, the `--testdox`
and probe-job exceptions, the S352 re-derived KNOWN LIMITS) lives in
[docs/dev/skipped-test-names.md](docs/dev/skipped-test-names.md).

**Keep the suite hermetic w.r.t. the environment.** `PHLIX_DOMAIN` changes real
behavior (it is the OAuth callback-URL allowlist — see
[Environment variables](#environment-variables)), so every test that depends on
it sets it in `setUp()` and restores the ambient value in `tearDown()`, and the
"no allowlist" cases pass `''` explicitly instead of relying on the env being
unset. Running the whole suite with and without `PHLIX_DOMAIN` exported yields
identical per-test assertion counts; if you add a test that reads an env var,
restore it.

### Code Standards

This project follows PSR-12 coding standards and uses static analysis tools:

```bash
# Check code style
./vendor/bin/phpcs --standard=PSR12 src/

# Run static analysis
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/psalm --show-info=false
```

Both are CI gates (`.github/workflows/coding-standards.yml`). Psalm is configured
by `psalm.xml` at `errorLevel="5"`; there is **no** `psalm-baseline.xml` and the
workflow fails if one appears. `--show-info=false` matches what CI runs — without
it Psalm additionally prints ~1400 informational suggestions that are not gated.

**Psalm needs PHP >= 8.3.16.** Psalm 6.x aborts at startup on anything older
(`Psalm requires a PHP version ">= 8.3.16"`), which includes the 8.3.6 that some
dev boxes ship. CI is unaffected — `shivammathur/setup-php` with `php-version:
'8.3'` installs the newest 8.3 patch. If your local PHP is too old, run it in a
container instead of upgrading the box:

```bash
docker build -t phlix-psalm - <<'EOF'
FROM php:8.3-cli
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN install-php-extensions mysqli pdo_mysql gd zip fileinfo sockets pcntl posix ldap ffi curl swoole
EOF

docker run --rm --network host -v "$PWD:/app" -w /app -u "$(id -u):$(id -g)" -e HOME=/tmp \
  phlix-psalm ./vendor/bin/psalm --show-info=false --no-progress
```

The extension list is not optional and mirrors the `extensions:` list in the
Psalm CI job: Psalm resolves classes and constants from the extensions that are
actually loaded, so a bare `php:8.3-cli` reports 140 phantom errors
(`Swoole\Coroutine\Channel`, `Socket`, `AF_INET`, `ZipArchive`, `GdImage`, `FFI`, …)
that do not exist on a properly configured runner.

### CLI

Administrative tasks are exposed through the `bin/phlix` command-line tool (built on `webman/console` / Symfony Console):

```bash
php bin/phlix list       # list every available command
php bin/phlix migrate    # apply migrations/*.sql against config/database.php
php bin/phlix media:dedupe-paths           # preview duplicate-path merges (dry-run)
php bin/phlix media:dedupe-paths --apply   # merge duplicate media items sharing a path
php bin/phlix backup:create --label=pre-upgrade   # create a backup archive now (--label optional)
php bin/phlix backup:list                          # table of stored backups, newest first
```

`php bin/phlix migrate` is the supported equivalent of `php scripts/run-migrations.php`. Both delegate to the same `Phlix\Common\Database\MigrationRunner` service, and both bootstrap the managed env file the same way — each requires `scripts/bootstrap_env.php` right after the Composer autoloader (default `/etc/phlix/env`, override with `PHLIX_ENV_FILE`; already-set variables are never clobbered), so on an install.sh-managed box neither command needs `DB_PASSWORD` exported by hand (M27). Re-running is safe. Applied files are recorded in a `schema_migrations` ledger and skipped while their checksum matches. When a file does re-run, its statements tolerate the replay: they use `IF NOT EXISTS` where MySQL supports it, and duplicate-column/duplicate-key errors are downgraded to notes. The downgrade is necessary because MySQL 8 has no `IF NOT EXISTS` on `ADD COLUMN`/`ADD INDEX`. The complete set of commands the tool actually ships is tabulated at the end of this section.

**Numbering law.** Migration files are `NNN_snake_case_description.sql` with a
zero-padded three-digit prefix; the runner discovers them with `glob() + sort()`
— plain lexicographic order, which equals numeric order at that fixed width
(`MigrationRunner::discoverMigrationFiles()` mirrors the script's behavior). **Gaps are
legal and historical** — a retired number is never reused, new work takes the
next free one (the current tree has real gaps, e.g. `016`, `099`), and one
legacy name deviates by design: `012a_livetv_recordings.sql` sorts between `012`
and `013`. The `cleanup_NNN.php` files are one-shot data cleanups, not schema
migrations. What every shipped table is for, and which migration creates it, is
catalogued in [docs/data-model.md](docs/data-model.md).

**Exit codes (S159).** Both commands exit `0` when every statement applied, or failed only with an idempotent "already applied" error. They exit `1` when at least one genuine, non-idempotent statement error was recorded. A failing run does **not** stop at the first error — later files are still attempted. But the failing file is deliberately left out of the ledger, so the next run retries it. Consequences worth knowing:

- `scripts/install.sh` runs under `set -euo pipefail`, so a failed migration **aborts** the install/update before the service is restarted.
- `docker/docker-entrypoint.sh` prints a loud `PHLIX-MIGRATION-FAILURE` banner to stderr and, by default, **still starts the container** (a crash-looping media server is worse than a degraded one). Set `PHLIX_MIGRATIONS_STRICT=1` to make the container refuse to start instead — note the **shipped images already set this to 1** (`docker/Dockerfile`, `Dockerfile.intel`), so a deploy from the published images refuses to boot on a failed migration unless you explicitly override it; see [docker/README.md](docker/README.md). It also refuses to start when migrations could not run at all (no database host configured, or `scripts/run-migrations.php` missing).
- `PHLIX_MIGRATIONS_DIR` overrides the directory that is scanned for `*.sql` (default `migrations/`). Honoured by **both** commands.

⚠ **"Idempotent" is a closed list, not "anything a replay raises".** The squelch is decided on the MySQL **error number**, never on the exception text. The driver prefixes the whole failing statement to its message, so matching a phrase like `already exists` would grep the migration's own SQL — an earlier version did, and it reclassified hard failures as successes. The idempotent set is exactly four errors:

1. `1050` — table already exists.
2. `1060` — duplicate column.
3. `1061` — duplicate key name.
4. `1091` — can't drop; check that the column/key exists.

Each names an object that can only be the one the failing statement was creating: a table in this schema, or a column/index, which are *table*-local.

Everything else fails the run, **including several shapes a re-run can legitimately produce** — write the migration so they never fire:

| Shape | MySQL | Write this instead |
|---|---|---|
| Seed row inserted twice | `1062` duplicate entry | `INSERT IGNORE` / `INSERT … ON DUPLICATE KEY UPDATE` |
| `ALTER TABLE … ADD PRIMARY KEY` replayed | `1068` multiple primary key defined | guard it, or add the PK in `CREATE TABLE` |
| `… CONSTRAINT <name> FOREIGN KEY` / `CHECK` replayed | `1826` / `3822` duplicate constraint name | guard the `ADD CONSTRAINT` (check `information_schema`, or `DROP … IF EXISTS` first) and give every constraint a schema-unique name |
| `DROP TABLE`/`CREATE TRIGGER`/`CREATE FUNCTION` replayed | `1051` / `1359` / `1304` | use the `IF EXISTS` / `IF NOT EXISTS` form those statements accept |

`1062` in particular is deliberate: it is also what `ADD UNIQUE` raises when the *existing data* violates the new constraint, which is the most valuable failure a migration can report. `1068` names no object, so a replay and a genuine "a different primary key is already there" are indistinguishable.

`1826`/`3822` were **considered for the idempotent set and rejected** — do not add them. Foreign-key and CHECK-constraint names are unique **per schema**, not per table, so the object that already holds the name can belong to a *completely different* table, and the statement that failed can be a `CREATE TABLE` that consequently created **nothing**. Squelching them let such a file exit 0 *and* enter `schema_migrations`, so it was never retried — worse than no squelch at all.

**Exit codes: `library:scan` (S347).** `php bin/phlix library:scan <libraryId>` (with or
without `--rescan`) returns one of:

| exit | meaning |
| --- | --- |
| **0** | scan (or rescan) completed; every file was indexed |
| **1** | scan did not run — unknown library id, the manager threw, or an already-queued/running job refused the scan |
| **2** | Symfony's `INVALID` (invalid input/usage) — deliberately **NOT** used, so "you typed the arguments wrong" and "your library lost files" never share a number |
| **3** | scan completed but N file(s) could not be indexed (the lossy warning is on stderr; each file is named in `.logs/error.log`) |

The same table is printed by `php bin/phlix library:scan --help`. `3` (not `2`) is the
"completed but lost files" code because `2` is Symfony's reserved invalid-input code — a
wrapper must be able to tell a scan that never ran from one that lost files.

**Command reference — the 20 commands `bin/phlix` ships.** `php bin/phlix list` prints this
live from the binary. Every command runs as a one-shot PHP process that reads the configured
database directly — no admin session, no HTTP surface, and no running server is required
(the DI container and DB connection are built lazily; `list` works with no database at all).
User commands accept a username or an email wherever `<user>` appears.

| Command | Arguments / options | What it does |
|---|---|---|
| `migrate` | — | Apply `migrations/*.sql` (idempotent; exit codes above) |
| `library:list` | — | List all configured media libraries |
| `library:scan` | `<libraryId>`; `--rescan`, `--force` | Scan (or, with `--rescan`, re-read every file of) a library; `--force` starts even when a scan job is already queued/running |
| `media:dedupe-paths` | `--dry-run` (default), `--apply`, `--batch-size=N` | Find/merge duplicate media items sharing a filesystem path (default 500 per batch) |
| `user:list` | `--status=pending\|active\|disabled`, `--json` | List accounts, optionally one status cohort |
| `user:create` | `<username>`; `--email=`, `--password=` (required), `--display-name=`, `--json` | Create an account (username 3–50 chars; password Argon2ID-hashed at rest). There is no admin flag — promote afterwards |
| `user:promote` | `<user>`; `--revoke`, `--json` | Toggle the admin flag (`--revoke` demotes; the last admin cannot be demoted) |
| `user:reset-password` | `<user>`; `--password=` | Reset an account's password; prints a strong generated one when `--password` is omitted. Writes the password **hash** and stamps `tokens_not_valid_after` (every live session's tokens are revoked with the write) — but it does not clear `must_change_password` (see *Locked by the admin `reset-password` endpoint* below) |
| `user:approve` | `<user>`; `--json` | Move a pending account to `active` |
| `user:reject` | `<user>`; `--json` | Reject a still-pending signup (hard delete) |
| `user:disable` | `<user>`; `--json` | Disable an account (last admin is protected) |
| `user:delete` | `<user>`; `--force`, `--json` | Delete an account; `--force` skips only the confirmation prompt — the last-admin guard is absolute and is **not** bypassed by it |
| `plugin:list` | — | List installed plugins and their enabled state |
| `plugin:install` | `<source>` | Install a plugin from a source URL (HTTPS, or `file://` for local sources) |
| `plugin:enable` / `plugin:disable` | `<name>` | Enable an installed plugin / disable an enabled one, by manifest name |
| `plugin:uninstall` | `<name>` | Uninstall a plugin by manifest name |
| `backup:create` | `--label=` | Create a server backup archive (configured in `config/backup.php`) |
| `backup:list` | — | List stored server backups |
| `hwaccel:probe` | — | Probe for available hardware-acceleration encoders |

The `user:*` commands all take `--json` except `user:reset-password`, which is the one
command without a machine-readable mode.

#### Bad days: recovery from the CLI

These are the scenarios where the admin section itself is the thing you cannot reach. The CLI
talks to the database directly, so it works when web login does not.

**Forgotten admin password / locked out of the web portal.**

```bash
php bin/phlix user:reset-password admin            # generates and prints a new password
php bin/phlix user:reset-password admin --password='pick-one'
```

The reset re-hashes through the same `UserRepository` write path the HTTP admin surface
uses — and, like every password write on that path, it stamps `tokens_not_valid_after`, so the
account's live sessions are revoked with the reset. One honest caveat: it resets the stored
password **hash** — auth-method enablement is separate server settings (the `auth.*.enabled`
toggles behind the admin Auth providers page; see "External SSO" above). If the password login
method itself was disabled, a fresh hash alone will not open the door, and the CLI exposes no
settings toggle — reach the setting via the admin section whenever any admin can still sign in, or a
direct database write when none can.

**Locked by the admin `reset-password` endpoint.** `POST /api/v1/admin/users/{id}/reset-password`
is a force-change-and-revoke button, not a reset the affected user can complete: it stores the
hashed reset token, sets `must_change_password`, and kills the account's sessions — and **no
shipped endpoint delivers or consumes that token**. While the flag is set the refusal covers the
password path only, and the two legs answer differently: a **password login** is refused by
`PasswordChangeRequiredException` — no HTTP boundary maps that exception yet, so the served path
answers a bare `500 Internal Server Error` (the HTTP error log names the exception class); the
distinct `403`-with-code envelope the exception was written for is NOT shipped. A token
**refresh** is refused by a plain `InvalidArgumentException`, which `AuthController::refresh`
catches and answers `401` + `{error}` (no code field). Neither passkey nor provider sign-in is
gated by the latch: `WebAuthnController::finishAuthentication` mints a full session without
consulting `must_change_password` (WebAuthn ships **enabled** by default in `config/auth.php`),
and `AuthManager::loginWithProvider` never checks it either. Full recovery is two steps: set a
new password, then clear the latch — which has no CLI or API clear path (the completion flow
never shipped), so it is a direct database write, the same last resort as the auth-toggle
recovery below:

```bash
php bin/phlix user:reset-password alice --password='pick-one'   # new password hash
```

```sql
UPDATE users SET must_change_password = 0 WHERE id = '<uuid>';  -- re-open sign-in
```

**Auth-method lock-out (`422`) — nothing to undo, and the true last resort.** A write the
guard refused persists NOTHING: your settings are exactly what they were before the
attempt, so recovery from a refusal is just not repeating it — the `reason` in the body
(`all_methods_disabled` / `admin_lockout`) says which rule bit and
[Auth-method lock-out guards](#auth-method-lock-out-guards) says what to do instead. The
guarded APIs cannot reach a locked install, so the only way into that state is a write
that bypassed them (direct SQL, a restored backup). Then:

```sql
-- Re-open password sign-in from the database (the API is behind the locked door):
UPDATE server_settings SET setting_value = '1'
 WHERE setting_key = 'auth.password.enabled';
```

(or `DELETE FROM server_settings WHERE setting_key IN (...)` for toggles you want back at
their config-file defaults — note the externals `oidc`/`ldap`/`github` default to *off*
when their row is absent, while `password`/`webauthn` default to *on*). Sign-in then works
again as soon as the running workers' next settings read lands — the toggles are LIVE per
decision, no restart needed. And if the forced state ever reads as all-five-off, the
server self-helps to password-only sign-in and logs the loud event
`auth.methods_all_disabled_fallback` (once per worker) without rewriting your data — grep
the audit log for it to confirm how the state arrived. Once inside, fix the toggles from
the admin section; the guards will keep the corrected state safe from then on.

**No admin exists yet (from-source install).** There is no bootstrap flag; create, then
promote:

```bash
php bin/phlix user:create admin --email=admin@example.com --password='pick-one'
php bin/phlix user:promote admin
```

Promotion has no guard to pass — it only ever adds an administrator. The last-admin
invariant protects the other direction: no CLI path (including `user:delete --force`) can
remove, disable or demote the final administrator, which is deliberate — zero admins is
exactly the lockout those guards exist to prevent.

**A plugin is misbehaving.** See what is installed and take one out without the UI:

```bash
php bin/phlix plugin:list
php bin/phlix plugin:disable <manifest-name>
```

**Account triage.** `php bin/phlix user:list --status=pending` to see the signup queue,
then `user:approve <user>` or `user:reject <user>`. For a rogue member, `user:disable`
is the usual answer (reversible); reserve `user:delete` for hard removal.

**Before risky surgery.** `php bin/phlix backup:create --label='pre-migration'`;
`backup:list` shows what is on disk. Configured schedules and retention live in
`config/backup.php`.

### Admin Section (`/app/admin`)

There is **no separate admin app**. An earlier React + Vite admin console (`admin-ui/`, served at
`/admin` by `AdminAppController`, built into `public/assets/admin/`, gated by an `admin-ui.yml`
workflow) was **decommissioned in this repo**: the app was removed in `6d1f43fe` (2026-06-01,
"feat(C8.1): decommission React admin") and its workflow replaced on 2026-06-26 by the **Web UI**
workflow (`7caad461`, now `.github/workflows/web-ui.yml`, building `web-ui/`). Nothing named
`admin-ui/` ships in this repository today.

The admin section (`/app/admin`) is part of the same `@phlix/ui` Vue SPA described under
[Web Portal](#web-portal) — source in `web-ui/`, bundle served from `public/assets/app/`, routes
registered in `web-ui/src/main.ts` (the shell's **Admin** entry renders only for
`useAuthStore().isAdmin`; the admin API itself is gated server-side regardless). The surviving
legacy page paths `/admin/plugins*` and `/admin/dashboard` 302-redirect to their `/app/admin/*`
equivalents in `src/Server/Workerman/HttpHandler.php`.

Full operator + contributor docs live on the
[phlix-docs](https://detain.github.io/phlix-docs) site — see in particular
[admin/server-settings](https://detain.github.io/phlix-docs/admin/server-settings) and
[admin/library-management](https://detain.github.io/phlix-docs/admin/library-management).

### Git Workflow

1. Create a feature branch: `git checkout -b feature/my-feature`
2. Make changes and commit: `git commit -am 'Add new feature'`
3. Push to remote: `git push origin feature/my-feature`
4. Create Pull Request on GitHub
5. After review, merge via squash-merge

## Troubleshooting

Symptom-first triage. The two universal log surfaces: the server's own
application log files under `<install>/.logs/` (`app.log`, `error.log`,
`plugins.log`, and `events.log` when `PHLIX_DEBUG_EVENTS` is on — see
[Environment variables](#environment-variables)), and stdout/stderr, which on
a systemd install goes to the journal (`journalctl -u phlix-server`).

### Diagnostic endpoints

Before digging through logs, read the machine's own reports. The two
`health` rails answer for any authenticated member (`AuthMiddleware`); the
admin pair requires an admin (`AdminMiddleware`):

| Method | Path | What it shows |
|--------|------|---------------|
| GET | `/api/v1/health/relay` | hub relay tunnel state — `connected`/`active`, reconnect attempts, active sessions, last disconnect and connect error; reads are staleness-gated, so a frozen state file from a dead worker never reads as healthy (S40) |
| GET | `/api/v1/health/network` | hub heartbeat latency snapshot — `latencyMs` plus `status` (`healthy` < 100 ms, `degraded` 100–500 ms, `offline` above 500 ms or when the snapshot is stale, the heartbeat is failing, or the server is unenrolled) |
| GET | `/api/v1/admin/logs` · `/api/v1/admin/logs/tail?file=app.log&lines=N` · `/api/v1/admin/logs/tail-all?lines=N` | list the `*.log` files in the configured log dir, tail one, or tail every file merged into one stream with each line tagged by its source — `file` is reduced to its basename, so it cannot traverse |
| POST | `/api/v1/admin/maintenance/reap-transcode-jobs` | run the transcode reaper by hand (S77) — body `{older_than_seconds: int}`; fails wedged running jobs so the queue drains |

The bare `/health` route stays open (no auth) for infrastructure probes —
Docker `HEALTHCHECK`, k8s liveness and `scripts/docker-boot-smoke.sh`
target it, never the gated `/api/v1/health/*` rails.

### Web playback buffers or fails to start

Playback that the server must re-encode depends on ffmpeg and the HLS/transcode
pipeline ([HLS & transcoding](#hls--transcoding) for the tunables). Check
`.logs/app.log` and the journal for ffmpeg failures or "all coroutines are
asleep" aborts, confirm `ffmpeg` is installed and reachable by the service
user, and look at accelerator selection (`PREFERRED_ACCELERATOR`). Direct play
of codec-compatible files bypasses this pipeline entirely — if one device
buffers while others do not, suspect its capability negotiation
(`X-Phlix-Client-Capabilities`) rather than the server.

### A SyncPlay / watch group gets stuck

Groups are host-managed over the SyncPlay WebSocket on port `8097` (the
`:8097` + token endpoint law; see [SyncPlay - Group Watching](#syncplay---group-watching)).
A member that silently stops following usually lost its socket and did not
re-join. Have the member leave and re-join: on a successful join the server
sends the current group state (`syncplay_group_state`) directly to the joiner,
so a healthy re-join re-synchronizes it. The admin section (`/app/admin`)
shows live group state, and `.logs/events.log` (with
`PHLIX_DEBUG_EVENTS` on) records the command flow.

### A Live TV recording is missing

Recordings and their scheduler state live behind the admin Live TV surface:
`GET /api/v1/admin/livetv/recordings` (plus `/recordings/upcoming` and per-series
listing — see [Live TV & DVR (admin-managed)](#live-tv--dvr-admin-managed)).
A scheduled-but-absent recording means the recorder job failed: check
`.logs/app.log` and the journal around the program start, the tuner's health via
`GET /api/v1/admin/livetv/tuners`, and the recording storage path's free space
(the DVR data dir — same storage discipline as [Backups (admin-managed)](#backups-admin-managed)).

### A cast device is not found

Device enumeration is per protocol: `GET /api/v1/cast/devices` (Chromecast),
`/api/v1/airplay/devices`, `/api/v1/roku/devices`, `/api/v1/dlna/renderers`.
Discovery (SSDP/mDNS) listeners are built at boot, so a network change after
start needs a service restart; the SSDP advertisement binds `:1900`. Casting
routes sit behind `CastingEnabledMiddleware` — confirm the subsystem is enabled
(`casting.chromecast.enabled` and siblings) before suspecting discovery.

### A webhook never fires

There are two webhook surfaces with separate stores: the legacy endpoint CRUD
(`GET/POST/PUT/DELETE /api/v1/admin/webhooks`, `POST /api/v1/admin/webhooks/{id}/test`
— `webhooks`/`webhook_logs` tables) and the V2 subscription system
(`GET/POST /api/v1/admin/webhooks/subscriptions`, `GET /api/v1/admin/webhooks/deliveries`
for per-event delivery history — `webhook_subscriptions`/`webhook_deliveries`).
Use `{id}/test` (legacy) or check `/deliveries?event_id=…` (V2) to see whether
the event never triggered or the delivery failed, and tail `.logs/` for the
dispatcher's HTTP errors. Note the two subsystems differ in payload signing
(see the `src/Webhooks/` docblocks).

### Locked out of admin settings

The auth-method lock-out guards refuse changes that would leave no way back in;
the full decision table is in [Auth-method lock-out guards](#auth-method-lock-out-guards).
If passwords are gone entirely, CLI recovery is the escape hatch
([Bad days: recovery from the CLI](#bad-days-recovery-from-the-cli)).

## Contributing

1. Fork the repository
2. Create your feature branch
3. Ensure all tests pass (`./vendor/bin/phpunit`)
4. Follow PSR-12 coding standards
5. Submit a pull request

## License

[Mozilla Public License 2.0](LICENSE) — see [`LICENSE`](LICENSE) for the full text.

MPL-2.0 is **per-file weak copyleft**. In short:

- You can use Phlix Server in a larger project, including a commercial or
  closed-source one.
- If you **modify** a file that is part of Phlix Server, you must make that
  file's source available under the MPL-2.0.
- New files you add alongside it can be licensed however you like.

"Phlix" is a trademark — see [`TRADEMARK.md`](TRADEMARK.md) for what that does
and doesn't restrict.

### Third-party components

Phlix Server depends on two LGPL-3.0 libraries — [Smarty](https://www.smarty.net/)
(`smarty/smarty`) and [Tukio](https://github.com/Crell/Tukio) (`crell/tukio`) — plus
a number of MIT-licensed packages. MPL-2.0 lists the GPL family as Secondary
Licenses (MPL-2.0 §1.12, §3.3), so those are compatible. If you redistribute
Phlix Server, you must still meet each dependency's own terms; `composer licenses`
lists them.

## Support

For issues and feature requests, please use the GitHub issue tracker.

---

For detailed development documentation, see the
[phlix-docs](https://detain.github.io/phlix-docs) site — the in-tree `DEVELOPER.md` and the
`docs/archive/` plan set were retired into it (`77e681fd`, "Remove docs directory after
phlix-docs merge").
