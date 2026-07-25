# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased] - 2026-07-24

### Added

- **DLNA renderers can finally play something: `GET|HEAD /dlna/stream/{mediaItemId}`**
  (updates.md #44 / S52). Until now DLNA *browse* worked while DLNA *playback* could
  not, by construction: every byte-serving route in Phlix demands a session or an
  HMAC-signed URL, and a DLNA renderer is a dumb HTTP client that can present
  neither — it fetches exactly the URL the ContentDirectory advertised. This adds the
  one route that carries no token.
  - **Gated by the DLNA IP allowlist and by nothing else.** The route is registered
    *inside* `loadCdsRoutes()`'s existing middleware group, so
    `DlnaAllowlistMiddleware` (`dlna.allowed_cidrs` / `dlna.restrict_to_lan`,
    default: loopback + RFC1918/ULA/link-local only) is evaluated per request on it,
    exactly as on the SOAP endpoints. An off-allowlist caller gets **403** before the
    handler runs. It is deliberately NOT served from `HttpHandler` like
    `/media/{id}/stream` is — that path dispatches before the router and router
    middleware cannot reach it, so a route built that way would have had **no**
    allowlist enforcement at all.
  - **`dlna.cds_enabled` still ships OFF**, and while it is off the route is not
    registered, so it **404s rather than 403s**. Turning DLNA on is what exposes
    media; nothing changes for an install that leaves it alone. Note that this
    changes what that switch *means*: before 1.7.0 it exposed metadata whose `<res>`
    URLs 404'd, so nothing was playable.
  - **Range requests** (`bytes=A-B`, `bytes=A-`, `bytes=-N`) are honoured so a
    renderer's scrub bar works: 206 + `Content-Range`, an over-long last-byte-pos
    clamped to EOF per RFC 7233 §2.1, an unsatisfiable range **416** with
    `Content-Range: bytes */size`, multi-range falling back to a whole-file 200,
    and `Accept-Ranges: bytes` on every served reply. Bytes stream through the
    Workerman event loop (`withFile()`, chunked above 2 MB), so a multi-GB file never
    lands in worker heap.
  - **Direct play only.** The source file is served as-is; a container this server
    does not recognise is answered **415** rather than bytes under a guessed type. No
    transcode is started, deliberately: the only on-demand pipeline is HLS
    (`.m3u8` + MPEG-TS), which no DLNA renderer speaks, so triggering one would swap
    a dead URL for an unplayable one. Not direct-playable today: `.iso`, `.vob`,
    `.divx`, `.asf`, `.rmvb`, `.mpv`, extension-less files, and all book/audiobook
    items. A listed container whose *codecs* the device cannot decode (HEVC/10-bit,
    AC-3/DTS/TrueHD, PGS subtitles) is still served and will fail on the device —
    withholding those from `<res>` is a follow-up.
  - **Path safety.** The route takes an id, never a path; the id must match
    `/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/` before it is used for anything; the path
    comes only from the `media_items` row and is `realpath()`'d before any filesystem
    access; and a new `LibraryRootJail` requires the canonical result to sit inside a
    configured library root (trailing-separator-exact, so `/media/movies-private`
    never matches `/media/movies`, and canonical-first, so a symlink out of a library
    is refused). It **fails closed** — no resolvable roots denies everything. Every
    refusal is one indistinguishable `404` with no filesystem detail in the body or
    headers; the only deliberate exception is the 415, which a renderer needs told
    apart from "no such object".
  - **The relay tunnel cannot reach any of it.** `RelayRequestDispatcher` now hard-
    denies `/dlna/*`, `/cds/*`, `/scpd/*` and `/description.xml` with a 404.
    `RelayConsumer` stamps `127.0.0.1` as the peer IP on every relayed frame (a
    relayed request has no meaningful TCP peer) and loopback is on the LAN allowlist,
    so without this a request arriving from the internet over the tunnel would have
    been handed the LAN policy and admitted with no token. A DLNA renderer is by
    definition on the local network and never arrives via the relay, so nothing
    legitimate is lost.
  - ⚠️ **Parental controls and per-profile stream limits DO NOT apply to DLNA.**
    There is no signed-in user on a DLNA request, so there is no profile, so there is
    no rating filter and no stream cap to enforce: any device the allowlist permits
    can browse and play content a profile's rating filter would hide in the app. This
    is not new — DLNA browse has never filtered by rating — but this release makes it
    *playable* rather than merely listed. There is no per-profile fix (there is no
    profile); a **server-wide** `dlna.max_rating` excluding over-cap items from DLNA
    browse is the enforceable form and is not implemented yet. Documented at the
    switch in `config/dlna.php`.

- **Unlink an identity, log in *via* a linked identity, and multi-provider
  foundation** (updates.md #55 / S47). Completes the account-linking story started
  in S45 and lays the multi-instance groundwork:
  - **`DELETE /auth/identities/{id}`** (**authenticated**) — a user removes one of
    their OWN linked external identities. The `{id}` is the `id` returned by
    `GET /auth/identities`. Guards:
    - **own-identity-only** — the target is resolved within the caller's own
      identity list (keyed on the trusted session `user_id`), so an id owned by
      another account (or a non-existent one) returns an indistinguishable
      **`404 identity_not_found`**, never a cross-account delete;
    - **never remove your last sign-in method** — a request that would leave the
      account with **no local password AND no other linked identity** is refused
      with **`409 last_sign_in_method`**, so a user cannot lock themselves out.
    The local password and every OTHER linked identity are left untouched. Success
    is `200 {success, message}` (missing/empty id → `400`, unauthenticated → `401`).
  - **Login *via* a linked identity now works** — this **lifts the S45
    limitation**. `UserRepository::findOrCreateByExternalId()` now resolves the
    owning user through the `user_identities` table FIRST (`provider`,
    default-instance `''`, `external_id`), so an identity you LINKED to an existing
    account (S45) can now be used to log in and resolves that existing account
    instead of wrongly creating a duplicate. The legacy `users.provider` /
    `users.external_id` lookup remains as a belt-and-suspenders fallback for any
    un-backfilled row, and the create path keeps the S46 dual-write, so **no
    existing/legacy external user loses login**. New
    `UserRepository::hasLocalPassword()` backs the unlink guard.
  - **Multi-instance provider capability.** `AuthProviderRegistry` now keys on a
    composite INSTANCE KEY (`AuthProviderRegistry::instanceKey()`): the default
    instance (the `''` sentinel, matching `user_identities.provider_instance`) is
    the family name verbatim, and a named instance is `family:instance`. This lets
    two providers of the SAME family (e.g. two OIDC issuers) coexist in one worker's
    registry without the previous duplicate-name throw — the platform-level
    foundation for configuring multiple OIDC/OAuth providers. **No admin UI or
    seeding of named instances ships in this step** (an operator/admin concern for a
    later step); every pre-S47 single-instance caller is behaviour-unchanged because
    the default-instance key equals the family name.
  - **Centralized auth-provider route registration.** New
    `AuthProviderRouteRegistrar` is the single, unit-tested site that wires every
    external-auth route (OIDC authorize/callback + the `/auth/identities` group
    incl. the new unlink), called once from `Application.php`. This closes the S44
    class of bug where a fully-implemented provider (OIDC) shipped with its routes
    never wired.

- **Account linking — attach an OIDC/LDAP identity to your logged-in account**
  (updates.md #55 / S45). An already-signed-in local account can now link an
  external identity and list what is linked. Three new **authenticated** endpoints
  (registered in `Application.php`'s auth group; the current user is read from the
  validated session, never from the request body):
  - **`GET /auth/identities`** — list this user's linked external identities.
    Returns `{identities: [{id, provider, provider_instance, external_id,
    linked_at}]}`. `provider_data` is **deliberately never returned** and no
    provider secrets are exposed.
  - **`GET /auth/identities/link/oidc`** — start linking an OIDC identity. Runs the
    same authorization-code + PKCE flow as OIDC login, but with a server-side
    **link intent**: the initiating user's id is bound into the integrity-protected
    **server-side** OIDC state context (`OidcStateStore` gained an optional
    `context` carrying `intent=link` + `link_user_id`), **not** the client-visible
    `state` param. The existing unauthenticated `GET /auth/oidc/callback` gained a
    **link branch** that, when it sees `intent=link`, attaches the IdP-verified
    `sub` (`external_id = oidc.<sub>`) to `link_user_id` and `302`s back to the
    same-origin app with `?linked=oidc` — **no login session is minted** and the
    callback ignores any client-claimed `external_id`.
  - **`POST /auth/identities/link/ldap`** — link an LDAP identity. Body
    `{username, password}`; only a **successful LDAP bind** links the
    provider-returned `ldap.<dn>` to the current account. A failed bind links
    nothing and returns a single generic `401` (no user-enumeration oracle); a
    genuine directory/config failure returns `503`. Success returns `200`
    `{success, provider, created, message}`.
  - **Security model (the linchpin).** Linking **always** proves control of the
    external identity via the real provider (a claimed identifier is never trusted:
    OIDC via the IdP round-trip + id-token validation, LDAP via a live bind).
    Linking **never** changes your primary login and **never** mints a new session,
    and `users` is never mutated. An external identity already linked to a
    **different** account is rejected with **`409 identity_already_linked`**; an
    identity already linked to the **same** account is an idempotent success
    (`created:false`). The `user_identities` DB UNIQUE index (migration 092) is the
    race backstop — a duplicate-key throw on insert is re-classified by re-reading
    the row and mapped to `409`/idempotent success **only** when the identity
    genuinely exists; a non-duplicate insert failure is re-thrown as a real `5xx`,
    never a mislabeled `409`.
  - **Storage.** A link is a `user_identities` row (S46 / migration 092) for the
    current `user_id`; `provider_instance` is the single-instance sentinel `''`.
  - **Now delivered in S47.** **Unlinking** an identity
    (`DELETE /auth/identities/{id}`) and **logging in *via* a linked identity** (the
    login read path now resolves through `user_identities`) shipped in **S47** (see
    the S47 entry above) — a freshly-linked identity is now usable for login and
    resolves your existing account. The multi-instance registry foundation (non-empty
    `provider_instance`) also landed in S47, though configuring multiple named
    provider instances from the admin console remains a later step.

- **`user_identities` table + multi-identity auth foundation** (updates.md #54 / S46).
  Internal schema plumbing for account-linking and multi-instance external auth —
  **no user-facing behaviour change yet** (login is unchanged; linking arrives in a
  later step). Details:
  - **Migration `092_user_identities.sql`** creates a row-per-identity join table
    `user_identities (id, user_id FK → users ON DELETE CASCADE, provider,
    provider_instance NOT NULL DEFAULT '', external_id, provider_data JSON,
    created_at, updated_at)` with `UNIQUE (provider, provider_instance, external_id)`
    and an index on `user_id`. It **applies automatically** via the migration runner
    on deploy — no manual step. `provider_instance` is `''` (a non-NULL sentinel, so
    the multi-column UNIQUE actually enforces one identity per default instance) for
    all single-instance identities; non-empty instance names are reserved for a later
    multi-instance step.
  - **Backfill** copies every existing external identity from `users` (where
    `external_id IS NOT NULL`) into `user_identities`, **deriving the real provider**
    (legacy `provider='external'` rows become `oidc`/`ldap` from the `external_id`
    prefix; unknown prefixes fall through unchanged, never dropped) and collapsing any
    pre-existing duplicate external accounts to a single identity row via
    `INSERT IGNORE`. It is idempotent (per-user `NOT EXISTS` guard) so re-running after
    a partial failure is safe. Existing external-identity users keep logging in exactly
    as before — the backfill does not touch `users`.
  - **New `Phlix\Auth\UserIdentityRepository`** (create / findByProviderExternalId /
    findByUserId / delete / deleteById), wired via `AuthServicesProvider`.
  - **`UserRepository::findOrCreateByExternalId()` now dual-writes** a matching
    `user_identities` row (default-instance `''`) inside the SAME transaction that
    creates a new external user, so the two stores can never diverge (a failure rolls
    back the `users` row too). `users.provider` / `users.external_id` remain the
    **authoritative login-lookup columns** — repointing login reads onto
    `user_identities` is deferred to a later step. Returning-user (find) logins write
    no new identity row.

- **OIDC & LDAP external login are now wired end-to-end** (updates.md #37 / S44).
  The admin **Integrations → Auth providers** enable/disable toggles for OIDC and
  LDAP were hard-coded `501 Not Implemented` stubs and neither provider had a
  reachable login route. S44 makes both usable:
  - **Enable-state = server settings flags.** `AuthProviderController::enableProvider()`
    / `disableProvider()` now persist `auth.oidc.enabled` / `auth.ldap.enabled` via
    `SettingsRepository` and (de)register the provider in the worker's
    `AuthProviderRegistry` (new `AuthProviderBootstrapper`). Responses are honest:
    `200` with `{enabled, live}`, `409 not_configured` when the provider has no saved
    settings yet, `404 unknown_provider` for a non-toggleable name. Enabled+configured
    providers register per-worker at boot (`start.php`, no boot-time network I/O) and
    **self-heal on the request path**, so enabling takes effect without a full restart.
  - **OIDC authorization-code flow** is served by `OidcCallbackController`, now routed
    via `Router::oidcAuth()` in `Application.php` (unauthenticated):
    `GET /auth/oidc/authorize` starts the flow (PKCE + `state` + `nonce`) and
    `GET /auth/oidc/callback` validates the returned id-token (signature / `iss` /
    `aud` / `exp`), mints a Phlix session, and 302s back to the caller. Register
    `<your-server>/auth/oidc/callback` as the redirect URI at your IdP.
  - **LDAP login** rides the existing `POST /auth/login`: send an `ldap:`-prefixed
    username (e.g. `ldap:jdoe`) plus password to authenticate against the configured
    directory (`AuthController::ldapLogin()` → `AuthManager::loginWithProvider()`).
  - **Async OIDC HTTP.** New `Phlix\Plugins\Oidc\OidcHttpClient` (workerman/http-client
    with a TLS-verifying cURL fallback) replaces blocking `file_get_contents`/`curl_exec`
    in OIDC discovery, token exchange, userinfo, and JWKS fetches so the resident worker
    no longer stalls. LDAP remains a bounded 5 s blocking call (ext-ldap is not
    Swoole-hookable); the limitation is documented in `LdapConnection`.

  Provider config (OIDC `provider_url` / `client_id` / `client_secret` / `scopes`;
  LDAP `host` / `port` / `ssl` / `base_dn` / `bind_dn` / `bind_pw` / `user_filter` /
  `admin_group`) is still edited through the existing OIDC/LDAP admin config UI
  (`OidcAdminController` / `LdapAdminController`, `POST /api/v1/admin/auth-providers/{oidc,ldap}/config`);
  DB-backed provider settings are a future step. See the SSO/external-auth guide in
  phlix-docs.

- **Relay tunnel TLS controls** (updates.md #38 / S38). Three new `config/relay.php`
  keys plus matching environment variables let an operator opt the server↔hub relay
  tunnel into TLS explicitly, independently of the server's public HTTP TLS:
  - `relay_tls` / **`PHLIX_RELAY_TLS`** (default `false`) — when truthy (`1`/`true`),
    the *derived* relay scheme becomes `wss://` and the connection is opened with
    `transport='ssl'`. Mirrors the hub's `HUB_RELAY_TLS` flag; a TLS relay deploy
    requires **both** `PHLIX_RELAY_TLS=1` on the server **and** `HUB_RELAY_TLS=true`
    on the hub.
  - `relay_tls_verify` / **`PHLIX_RELAY_TLS_VERIFY`** (default `true`/secure) — set to
    `0` to accept a self-signed hub relay certificate (`verify_peer=false` +
    `allow_self_signed=true`, mirroring the hub's permissive relay server context).
  - `relay_tls_cafile` / **`PHLIX_RELAY_TLS_CAFILE`** (default
    `/etc/ssl/certs/ca-certificates.crt`) — CA bundle used to verify the hub's relay
    certificate when verification is on.

  An explicit `hub_relay_ws_url` / `PHLIX_RELAY_HUB_WS_URL` remains the
  highest-precedence override (and is no longer overwritten by `withAutoEnable()`);
  when it points at a `wss://` URL it should be paired with `PHLIX_RELAY_TLS=1` so the
  cert/verify keys apply and the start-time mismatch heads-up stays quiet.

- **`RelayStateStore`** (updates.md #38 / S38). New `Phlix\Hub\RelayStateStore`
  persists the relay tunnel's last connect state (connected/active, reconnect attempts,
  active sessions, last disconnect time, last connect-error reason/time) to
  `config/relay-tunnel.state.json` via atomic single-writer writes (unique `*.tmp` +
  `LOCK_EX` + `0600` + `rename()`, mirroring `HubClient::storeEnrollment()`). This is an
  internal groundwork mechanism consumed by the upcoming Network Health work (S40); the
  relay fork writes it on connect/disconnect/reconnect and every connect-failure branch.

- **DLNA inbound IP allowlist** (updates.md #35 / S50). The DLNA
  ContentDirectory (CDS) browse routes — which have NO authentication once
  `dlna.cds_enabled` is on — are now gated by a new
  `Server\Http\Middleware\DlnaAllowlistMiddleware`, wrapped around
  `Application::loadCdsRoutes()`'s route group exactly as `SignedUrlMiddleware`
  wraps the streaming group. Two new `config/dlna.php` keys drive it:
  `allowed_cidrs` (array of CIDRs, default empty) and `restrict_to_lan` (bool,
  default **true**). The client address is resolved via the spoof-resistant
  `Request::getTrustedClientIp()` (the same accessor the rate limiters use), and
  an IPv4-mapped IPv6 peer is collapsed to its IPv4 form before matching. CIDR
  parsing/matching **reuses** `Common\Net\SsrfGuard` rather than re-rolling it
  (its `filterCidrs()`, `ipMatchesAnyCidr()` and `embeddedIpv4()` helpers are now
  public). Policy, and the whole point of the change: an **empty allowlist is
  never "allow all"** — with the defaults, only loopback + RFC1918/ULA/link-local
  may reach the CDS routes and everything else gets a 403; with
  `restrict_to_lan=false` the allowlist becomes the sole gate (an empty list then
  denies everyone). The matching shared-settings schema keys
  (`dlna.allowed_cidrs`, `dlna.restrict_to_lan`) are added in `detain/phlix-shared`
  and surface once that package is released and re-vendored. This step wires only
  the existing CDS routes; the new DLNA stream route (S52) is out of scope.

- **Nav entries hide without a matching library type** (updates.md / S25, Half B).
  The web-ui now consumes `@phlix/ui` **v0.98.22**, which added the optional
  `MenuItem.requiresLibraryType` field plus a fail-closed nav filter (Half A). The
  server SPA menu in `web-ui/src/main.ts` tags its media-type entries with the
  matching **singular** server library `type` ENUM value —
  `Music → 'music'`, `Books → 'book'`, `Audiobooks → 'audiobook'`,
  `Photos → 'photo'` — so each link appears only when a library of that type
  exists (and stays hidden while the library list is still loading). Browse / For
  You / Explore / SyncPlay / Search / Settings / Admin are untouched. The committed
  `public/assets/app/` bundle was rebuilt so the deployed nav reflects the change;
  no backend/API change.

- **Per-library TMDB auto-collections toggle (backend)** (updates.md / S33). TMDB
  box-set auto-collection generation is now switchable **per library** instead of
  running unconditionally on every scan. The flag lives in the existing
  `libraries.options` JSON column as `{"autoCollections": {"enabled": bool}}` (no
  new migration/column) and round-trips through the existing library create/update
  endpoint: `LibraryController` gained an `applyAutoCollections()` handler that
  accepts the flag at the body top level OR nested in `options` (as a bare bool or
  a `{enabled: bool}` map), normalises it to the canonical `{enabled: bool}` shape,
  and — on update — MERGES it into the existing options blob so editing it
  preserves `metadata_priority`/`image_types`/`series_per_directory` (and vice
  versa). `LibraryRow::autoCollectionsEnabled()` reads it, **defaulting to `true`
  when the flag is absent** so existing libraries keep today's behaviour; only an
  explicit stored `false` disables generation. `LibraryRow::toArray()` surfaces the
  effective value under a top-level `auto_collections` block. `MediaScanner::scan()`
  takes a new optional `bool $autoCollectionsEnabled = true` (latched per-scan) and
  gates its per-item `CollectionService::syncCollectionForMovie()` block on it;
  `LibraryManager::scanLibrary()` passes the library's resolved flag. The
  library-edit UI checkbox is a separate deferred UI-lane task.

- **`GET /api/v1/users/me/next-up` — per-user "Next Up" rail** (updates.md #43 /
  S36). The sibling to Continue Watching: where CW lists in-progress items you can
  resume, Next Up returns, for **each series the active profile has STARTED, the
  single next unwatched episode to play**. New pure `Phlix\Media\Library\NextUpSelector`
  (DB-free ordering + next-episode selection — a server-side port of the SPA's
  `episode-order.ts`), new `WatchHistory::getNextUp(string $profileId, int $limit = 20)`
  (resolves `profileId → userId`, Query A = the started series most-recently-touched
  first, Query B = every episode of a series with the user's latest per-episode
  `playback_state`), and the route + handler `WebPortalRouter::getNextUp()` registered
  beside `continue-watching` in the same `AuthMiddleware` group. Optional `?limit=`
  (default 20, clamped 1-50); the candidate-series fan-out is bounded at
  `max(limit × NEXT_UP_SERIES_SCAN_MULTIPLIER(3), NEXT_UP_SERIES_SCAN_FLOOR(50))`
  most-recently-touched series (LIMIT inlined as a validated int — bound LIMIT
  raises 1064 under emulated prepares in this repo). **Selection:** an in-progress
  episode (playing/paused within 0–95% of duration) resumes that episode; a finished
  episode (`stopped` at position 0, or watched ≥95% of duration) advances to the next
  numbered episode, rolling into the next numbered season; a series with all episodes
  watched yields no entry; only numbered seasons are walked (Specials/season-less
  excluded). **[BINDING design]** the watched/in-progress signal is `playback_state`
  ONLY — the `watch_history` table and `user_item_data.watched` flag are deliberately
  NOT consulted (they are write-orphaned / a manual account-level toggle). Response is
  a bare `{items:[...]}` of `MediaItemShaper::shape()`-shaped episode rows (series
  poster resolved, artwork re-signed) with `position_ticks`/`duration_ticks` = 0 (a
  fresh pick) plus added `series_id`/`series_name`; each carries `media_item_id`, the
  key the active-profile parental rating gate post-filters on (over-cap episodes
  dropped for a gated profile; owner unfiltered). `401` unauthenticated, `503` when
  watch-history is unwired, `{items:[]}` when the user has no active profile. The
  season-less (flat, no season row) series edge is invisible to the rail by design
  (the numbered-season-only binding). Home-rail UI is a separate step (S37).

- **`GET /api/v1/media/most-watched` — public "Most Watched" trending rail**
  (updates.md #31 / S31). Exposes the GLOBAL, all-time, cross-user most-watched
  aggregate (the same list the admin Top Media report reads via
  `StatsCollector::getTopMedia()`) to signed-in users as a home-rail. This is a
  server-wide trending rail (most-watched across the WHOLE server), NOT a per-user
  history — a deliberate product decision: everyone sees the same "popular on this
  server" list. New `MostWatchedController::mostWatched()` takes an optional
  `?limit=` (default 20, clamped to the `PageLimit::MAX` ceiling of 100 via
  `Request::queryPageSize()`), hydrates the top IDs with a single
  `ItemRepository::findByIds()` batch (order-preserving; silently drops
  since-deleted rows), and returns the sibling `{items, total, limit, offset}`
  envelope with `MediaItemShaper::shape()`-shaped rows (poster/artwork signed URLs
  re-minted at response time). Registered on the shared `Application` router as a
  STATIC route (matched before `WebPortalRouter`'s parametric `/api/v1/media/{id}`,
  so no shadowing) and gated by `AuthMiddleware` to match the audience of
  `GET /api/v1/media`. The admin-only `getTopMedia()` path
  (`/api/v1/admin/stats/top-media`) is unchanged.

- **`POST /api/v1/sessions/{id}/complete` — explicit playback finish signal**
  (updates.md #30 / S30). Progress ticks (`reportProgress`) only ever leave a
  `playback_state` row in `playing`/`paused`, so without an explicit finish signal a
  finished title lingered in Continue Watching and its watch-time stats never
  finalized (Top Users watch time showed dashes). The new
  `SessionController::completePlayback()` gives `PlaybackController::markAsWatched()`
  / `clearProgress()` their first live caller. Body: `media_item_id: string`
  (required; `400` if missing/empty) and optional `reached_end: bool` (default
  `true`). `reached_end:true` → `markAsWatched()` (row → `stopped`,
  `position_ticks=0`, item leaves Continue Watching, stats recorded as completed —
  finalizes `duration_seconds` + dispatches `PlaybackStopped` for
  webhooks/Last.fm/Most Watched); `false` → `clearProgress()` (deletes the row,
  recorded as not completed). Response `200 {message, reached_end}`; `404` unknown
  session, `403` wrong owner. Auth mirrors the sibling `/progress` route (session
  owner), registered next to `/progress` on the shared `Application` router (served
  by the Workerman `HttpHandler` daemon). The web SPA full player (`Player.vue`) and
  the persistent mini-player POST this on the media `ended` event. **Known
  limitation / follow-up:** native clients (Roku / mobile / Tizen / Windows) do NOT
  send this signal yet, so titles finished on those clients still linger in Continue
  Watching and do not finalize watch-time until each is updated to call `/complete`.

- **`plugins.catalog.channel` — plugin-catalog release channel (`stable` / `dev`).**
  Lets the OFFICIAL first-party plugin catalog (`detain/phlix-plugins`) track
  something other than a hard-pinned release tag, without losing the safe default.
  Adds the `plugins.catalog.channel` setting (`stable` **default** | `dev`) and a
  `GET/PUT /api/v1/admin/plugins/catalog/channel` admin endpoint (admin-gated like
  every sibling plugin route; `PUT` validates the value against `stable|dev` →
  `400 plugin.catalog.channel.invalid`, audits `catalog.channel`). The same
  `{ channel, options: [{ value, label, description, advanced }] }` shape is also
  embedded under `channel` in the `GET /plugins/catalog` response.

  Channel resolution has strict precedence **env > setting > default**:
  1. the `PHLIX_PLUGINS_CATALOG_REF` env override — unchanged, still highest;
  2. `plugins.catalog.channel` — `dev` resolves the official catalog to its moving
     `master` branch (`CatalogSourceResolver::DEV_REF`);
  3. `CatalogSourceResolver::OFFICIAL_PINNED_REF` — the audited pinned tag, used for
     `stable` / default. Any unrecognised or empty value fails **safe to `stable`**
     in three independent layers (`refForChannel()`, `channel()`, `setChannel()`).

  `dev` is labelled **opt-in / advanced** server-side via `PluginCatalogService::channelInfo()`
  (the `dev` option carries `advanced: true` and a description explaining it tracks
  the moving `master` branch); the admin Plugins page renders that metadata verbatim.
  The channel only affects the OFFICIAL catalog and only widens catalog **discovery**
  — operator-added catalogs still resolve at `HEAD`, and **install-time integrity
  verification is unchanged**: per-entry `ref` + `artifactSha256` still gate every
  actual install on BOTH channels (`PluginCatalogService::pinFor()` /
  `PluginLoader::install()` untouched), so `dev` never moves the trust boundary.
  Passing `null` for the new `CatalogSourceResolver::normalize()` `$officialRef`
  argument reproduces the historic env-or-pinned behaviour byte-for-byte, so existing
  callers are unaffected. GitHub-Releases-API "always latest" (updates.md #27 option b)
  is intentionally deferred. Class (a) LIVE, `restart: false`.

- **`metadata.overwrite_existing` — respect hand-corrected metadata on rescan.**
  A metadata (re)match unconditionally did `array_merge($existing, $resolved)` at
  every persist site, so freshly-resolved fields always won and there was no way to
  stop a forced rescan (or an interactive re-apply) from clobbering a hand-corrected
  item. Adds the `metadata.overwrite_existing` boolean setting (phlix-shared v0.41.0),
  `default: true` so behaviour at the default is byte-for-byte identical to before.

  Wired through a new `MetadataOverwritePolicy` (a direct mirror of
  `ArtworkDownloadPolicy`: optional `SettingsRepository`, live `getEffective()` read,
  safe-degrades to overwrite-on) that gates ONE decision point,
  `LibraryMetadataMatcher::shouldSkipOverwrite()`. That helper is consulted at the
  three (re)resolve entry points — `matchItem()`, `matchSeries()` and
  `applyMatchResolved()` — which between them dominate every
  `array_merge($existing, $resolved)` metadata-overwrite site in the class (movie
  item, series root, the interactive AND batch season/episode enrichment sites, and
  all four interactive-apply branches), so a subset cannot silently drift. Class (a)
  LIVE, `restart: false`; the default lives in `config/metadata.php` (not composed
  into `config/server.php`, read via `SettingsRepository`).

  When an admin turns it OFF, an item that has ALREADY been resolved
  (`metadata_refreshed_at` present) is skipped WHOLESALE on a forced rescan and on
  interactive apply — not re-resolved, not re-merged — mirroring the existing
  manual-override short-circuit in `matchItem()`. There is no per-field provenance in
  the pipeline, so "don't overwrite" can only be a whole-item skip. Items never yet
  resolved are still enriched. Wired in DI as a named ctor param
  (`MediaServicesProvider`) so PHP-DI does not skip it; `MetadataOverwritePolicy`
  registered as a factory over the optional settings store.

  New tests assert the CONSEQUENCE across the movie / series-subtree / interactive
  paths: at the default an already-resolved item is still overwritten (rule 7); with
  the switch off it is skipped wholesale, while a never-resolved item is still
  enriched.

- **`POST /api/v1/admin/plugins/{name}/test` is now actually routed.**
  `PluginAdminController::testCredentials()` was fully implemented and reachable by
  nobody — `AdminRoutes::register()` registered every other plugin route and stopped
  short of this one, so every call 404'd. That is why a "Test credentials" button was
  deliberately withheld from `phlix-ui`. The route is now registered alongside its
  siblings, inside the same `/api/v1/admin` group and therefore behind the same
  `AdminMiddleware` gate. Registered **once**, in the registrar both entry points call
  (`Application.php:771` daemon + `public/index.php:213` web portal), so there is no
  dual-entry-point drift.

  New tests assert the **consequence**, not the route table: an anonymous POST gets
  401 (not 404 — proving the route exists *and* is inside the admin group), a known
  non-admin gets 403 *and the plugin's `testCredentials()` is never invoked with the
  submitted secret*, and an admin POST reaches the controller, invokes the plugin with
  the submitted settings, and gets its verdict back in the `{success, message}`
  envelope. Mutation-verified: deleting the registration turns all ten new tests red
  with 404, and moving the route outside the admin group turns both gate tests red.

### Changed

- **DLNA browse now reports the real container MIME for ~20 more formats**
  (updates.md #44 / S52). The extension→MIME table that produced the DIDL
  `<res protocolInfo>` value lived in three places (`LibraryBridge`,
  `ContentDirectory`, and it was about to be copied a fourth time for the stream
  route), which is how the MIME a renderer is told to expect and the `Content-Type`
  the bytes arrive with could silently diverge — a renderer that sees the two
  disagree refuses the item. There is now one table, `Phlix\Dlna\DlnaMimeTypes`, and
  it is a **superset** of the old one, so the advertised `mime_type` changes for
  `mov`, `wmv`, `flv`, `mpg`, `mpeg`, `m2v`, `ts`, `m2ts`, `mts`, `3gp`, `ogv`,
  `m4a`, `m4b`, `opus`, `wma`, `aiff`, `aif`, `oga`, `bmp`, `webp`, `tif`, `tiff`.
  Examples: a `.mov` typed `movie` was advertised `video/mp4` and is now
  `video/quicktime`; a `.m4a` typed `music` was `audio/mpeg` and is now `audio/mp4`.
  The resolution ORDER (explicit `mime_type` → extension → coarse `type` fallback) is
  unchanged. The old values were simply wrong, so this is an improvement, but it is a
  visible change in the Browse response.

### Fixed

- **A `HEAD` on a media route put TWO conflicting `Content-Length` headers on the
  wire** (updates.md #44 / S52 review). Workerman's response encoder
  (`Protocols/Http/Response::__toString()`) appends its own
  `Content-Length: strlen($body)` **unconditionally** and **last**, so a handler that
  correctly set the real size on a bodyless `HEAD` reply emitted
  `Content-Length: <size>` followed by `Content-Length: 0`. RFC 9110 §8.6 makes such a
  message invalid — recipients must reject it, hardened proxies (HAProxy) drop it as a
  request-smuggling defence, and clients disagree about which value wins. DLNA
  renderers probe a resource with `HEAD` *before* they open it, so the reply meant to
  advertise the size was the one that broke. New `Phlix\Server\Workerman\BodylessResponse`
  narrows `__toString()` to leave a caller-supplied `Content-Length` alone on an empty
  body (and delegates to Workerman untouched for everything else). It is reached two
  different ways, because the two affected routes are dispatched by different code:
  `Response::toWorkermanResponse()` selects it for `HEAD` replies **only** — never for
  a GET that merely came out empty, whose stale length would be a keep-alive framing
  desync rather than a fix — which is what fixes `HEAD /dlna/stream/{id}`; the
  pre-existing twin on `HEAD /media/{id}/stream` is fixed by **naming the class
  directly** in `HttpHandler::serveMediaStream()`, which runs *before*
  `Application::dispatch()` and returns Workerman responses, so it never builds a
  Phlix `Response` and has no `headOnly` flag to select on. Every other reply,
  204/304/redirect/416 included, is byte-identical to before. Pinned by assertions on
  the **encoded bytes**, with the expected bytes *derived from* Workerman's own encoder
  so a future dependency bump cannot make the narrowed copy diverge silently — the
  previous tests inspected the header *array*, which cannot observe this defect at all.

- **A route registered for `HEAD` in its own right relied on its handler remembering
  to flag the reply** (S52 post-merge review, finding 1). `Response::$headOnly` is what
  selects the narrowed encoder above, and it was set **only** by
  `Router::dispatchAsHead()` — the GET→HEAD *fallback*. A route registered as
  `match(['GET', 'HEAD'], …)` (which is how the DLNA stream route is registered, so
  that a file-backed body is not suppressed to `Content-Length: 0`) never reaches that
  method, so the router set nothing and correctness depended on each controller setting
  the flag by hand. The one such route today does, but the next one to declare a
  `Content-Length` on its HEAD arm and forget the flag would have shipped
  `Content-Length: 123456789` followed by `Content-Length: 0` again with the whole
  suite green. `Router::dispatch()` now flags **every** response it returns for a
  `HEAD` request (`markHeadOnly()`, the single writer of the flag in the class: both
  route maps, both middleware short-circuits, and the fallback), so the framing is a
  property of the framework rather than a convention. It cannot flag a GET — the method
  test is the whole body — and it is idempotent, so the DLNA controller keeps its own
  explicit set as defence in depth for callers that are not `Router::dispatch()`. No
  live wire bytes change (the only HEAD-registered route already set the flag); pinned
  by `RouterTest` tests that register a HEAD route which does **not** set the flag and
  assert on the **encoded bytes** that exactly one `Content-Length` reaches the wire,
  with a control showing the framework encoder emits two for the same shape.

- **`Router::group()` could leak its middleware onto every later route** if the group
  callback threw. The prefix/middleware restore had no `finally`, and `addRoute()`
  copies the current group middleware onto every route registered afterwards — so one
  throw inside `loadCdsRoutes()` would have silently attached the DLNA **IP
  allowlist** to the ~15 route loaders that run after it, and those endpoints would
  have begun refusing every non-LAN caller. Low probability, wide blast radius; the
  restore is now unconditional and the log call inside that catch is itself guarded.
  Pinned by tests that register a throwing group (flat and nested) and assert both
  that a route registered afterwards carries no leaked middleware and that a nested
  group restores the *outer* prefix and middleware rather than clearing them.

- **"Original" quality was silently unavailable for every HEVC / non-AAC title**
  (updates.md #30 / S49). The v7 ABR ladder FOLDED the `original` rendition out of
  `LadderResult::streamVariants()` whenever a re-encoded (non-copy) Original was the
  same frame at effectively the same BANDWIDTH as the top ladder rung — which is the
  normal outcome for a low-bitrate source, since the ladder caps every rung at the
  source bitrate. `TranscodeManager::writeVodPlaylists()` iterates exactly that list,
  so a folded Original never got a media playlist written at all: `media_voriginal.m3u8`
  simply did not exist, and "Original" was either missing from the quality menu or a
  404 (masked only by a serve-time alias that quietly served the top rung's playlist
  instead). Every job now always publishes a real Original variant:
  - `LadderResult::streamVariants()` always returns `[original, ...renditions]`; the
    fold is gone from the variant list.
  - `TranscodeManager::getJobVariants()` no longer mirrors that fold (its private
    `originalDuplicatesTopRung()` helper is removed), so the client `variants[]`
    payload always advertises `{id: 'original', …}` with its own media-playlist url.
  - The duplicate-BANDWIDTH problem the fold existed to prevent is now solved where
    it belongs — in the master playlist. `writeVodPlaylists()`'s SV-4.6 switchable
    filter (`TranscodeManager::switchableVariants()`, still using
    `Rendition::duplicatesForAbr()`) withholds a variant from the ABR-switchable
    `#EXT-X-STREAM-INF` set in exactly two cases: a stream-COPY variant, always (its
    segment boundaries can drift off the uniform timeline), and a TRANSCODE `original`
    that duplicates the top rung's frame + BANDWIDTH, which is the low-bitrate
    collapse a player would merge into one level. Both still get their own media
    playlist, which is what makes "Original" explicitly selectable.
    **The master's advertised level set is therefore unchanged from v8**: a transcode
    `original` that is NOT such a duplicate stays the master's top level, exactly as
    before — including the large class of sources whose original height matches no
    canonical rung (2.39:1 crops, DCI-2K). An interim revision of this change excluded
    every `original` from the switchable set; that dropped the master's top level for
    those sources (e.g. a 1920×1080 HEVC @8 Mbps master went from `original` at
    10.0 Mbps down to `1080p` at 5.478 Mbps, halving the auto-ABR ceiling) and is NOT
    what shipped. Four tests now pin the full level set — ids + BANDWIDTH +
    RESOLUTION — per source shape (copy / distinct transcode / anamorphic / duplicate).
  - `TranscodeManager::ensurePlaylistRegenerated()` rebuilt its variant list from the
    persisted `renditions` **only**, so an LRU cache eviction destroyed
    `media_voriginal.m3u8` permanently — even for a never-folded stream-COPY Original
    that had been written correctly. It now reproduces the same `original`-first set
    `ensureHlsJob()` wrote. The same method also read audio tracks from a
    `transcode_jobs.audio_tracks` column that has never existed in any migration
    (`JOB_ROW_COLUMNS` cannot select it), so multi-audio jobs regenerated with no
    `#EXT-X-MEDIA` audio group and no `media_a{N}.m3u8` playlists; they are read from
    the persisted ladder JSON now.
  - `TranscodeManager::ensurePlaylistRegenerated()` also now validates each persisted
    rendition id against the same `^[a-z0-9]+$` allowlist the segment serve path uses
    (the id is interpolated into `media_v{id}.m3u8` and into the master's URI), and
    returns `false` instead of writing a master with zero `#EXT-X-STREAM-INF` levels
    when nothing in a corrupt ladder survives that check — such a file would otherwise
    short-circuit every later regeneration attempt and permanently break the job.
  - `JOB_KEY_VERSION` **v8 → v9** so pre-existing jobs (whose directories lack the
    Original playlist) are not reused and age out via the cache sweep. `HlsController`'s
    folded-original serve-time alias is retained for pre-v9 job directories still on
    disk (bounded by the cache sweep's idle TTL, 3 h by default) so already-issued
    signed URLs keep playing; its removal is tracked as part of **S59**.
  - **No client change was required, because the master's advertised level set is
    unchanged** (see above): `quality.ts`/`QualityMenu.vue` resolve "Original" by
    matching a master level of the same height and then load `media_voriginal.m3u8`
    directly, which is precisely the file that now exists. That client gate does have a
    pre-existing blind spot this change neither introduces nor fixes — a stream-COPY
    Original is (as before) never a master level, so when its height matches no level
    and exceeds them all (e.g. a 1920×800 H.264/AAC source against a 720p top rung)
    the menu still hides "Original" even though the playlist is now guaranteed to
    exist. Hardening that gate to key off the server's `variants[]` entry rather than
    level height-matching is tracked as a `phlix-ui` follow-up.

- **External identities were stored with a hardcoded `provider='external'`**
  (updates.md #37 / S44). `UserRepository::findOrCreateByExternalId()` ignored which
  provider actually authenticated, so an OIDC `sub` and an LDAP DN could collide onto
  the same account. It now takes a `$provider` argument (threaded from the
  `AuthResult`), scopes the lookup by `(provider, external_id)`, and stores the real
  provider (`oidc` / `ldap`) — two providers presenting the same identifier map to
  distinct users. This is the foundation S45–S47 (account-linking / multi-identity)
  build on.

- **First OIDC/LDAP login 500'd because `users.password_hash` was `NOT NULL`**
  (updates.md #37 / S44). External (passwordless) users are created with
  `password_hash = NULL`, but the column had never been relaxed since migration 001.
  New migration `091_users_password_hash_nullable.sql` (`ALTER TABLE users MODIFY
  password_hash VARCHAR(255) NULL`) fixes it; it applies automatically via the
  migration runner on upgrade (no manual step). External users with no email/username
  from the IdP are still created — they get a deterministic placeholder derived from
  `(provider, external_id)` so two email-less accounts can't collide on the unique
  `email` / `username` columns.

- **Relay tunnel forced TLS regardless of scheme → silent hang against a plaintext
  hub relay port** (updates.md #38 / S38). `openHubConnection()` previously set
  `transport='ssl'` and attached an SSL context unconditionally, and `RelayConfig`
  derived a `wss://` relay URL for any `https://` hub — so a default deploy tried to
  do a TLS handshake against the hub's plaintext relay listener (`:8802`), which
  produced no `onError`/`onClose` and simply deadlocked. The transport now keys off
  the *resolved URL scheme*: a new pure `RelayConsumer::resolveHubTransport()` helper
  derives `useTls = (scheme === 'wss')`, rewrites `wss://` → `ws://` for the Workerman
  address (wss is not a registered transport), and only `openHubConnection()` when
  `useTls` sets `transport='ssl'` + the SSL context. **The default derived relay
  scheme is now plaintext `ws://`**, matching the hub's plaintext-by-default relay
  listener — a change from the old always-`wss://` behavior. A TLS relay tunnel now
  requires `PHLIX_RELAY_TLS=1` on the server together with `HUB_RELAY_TLS=true` on
  the hub (see the new keys under Added). At boot, if the resolved relay URL is
  `wss://` while `relay_tls` is off, a clear once-per-process `logger.hub` warning
  explains the likely hang and the envs to set.

- **Admin Relay Tunnel Enable/Disable/Ping/Status now reflect the REAL tunnel
  instead of a never-started in-worker object** (updates.md #39 / S39). The four
  `AdminHubController` relay endpoints (`GET /api/v1/admin/remote/relay/status`,
  `POST …/relay/enable`, `…/relay/disable`, `…/relay/ping`) were operating on a
  container-local `RelayConsumer`/`RelayApplication` copy that never runs (the real
  tunnel is the forked `phlix-relay-tunnel` process) and probed liveness with a
  blocking `exec('pgrep -f phlix-relay-tunnel')` + log-scrape inside the event loop.
  That blocking subprocess I/O is **removed entirely** (the endpoints now read the
  cross-process state files `RelayStateStore` maintains — `relay-tunnel.state.json`,
  `hub-heartbeat.state.json`, `relay-control.json`), and the fake `{"success":true}`
  no-ops are gone:
  - **Enable/Disable are an honest, persisted kill-switch, effective on next server
    reload** — not an instant in-process start/stop. Disable writes `disabled:true`
    to `relay-control.json` (which the relay fork honors at boot in addition to the
    `PHLIX_RELAY_DISABLED` env var); Enable clears it. Both return
    `{success, disabled, enrolled, takesEffectOnReload:true, message}`. Enable
    **cannot unset the `PHLIX_RELAY_DISABLED` env var**, so when the env kill-switch
    is set the response stays `disabled:true` with an honest message; a persist
    failure returns `500`.
  - **Status** returns the real persisted `connected`/`active`/`enrolled` plus
    `reconnectAttempts`, `activeSessions`, `lastDisconnectTime`, the last
    `lastConnectError`/`lastConnectErrorAt` reason, the effective `disabled`
    kill-switch, and `updatedAt` (fork-write staleness signal). Back-compat keys
    `endpoint`/`establishedAt` are retained for the current UI.
  - **Ping** reports the persisted latency: `{success, connected, active, latencyMs,
    lastHeartbeatAt, latencySource:"persisted"}`, with `latencyMs:null` = "not
    measured yet" (never a fabricated timing), and returns **`409`** (with
    `lastConnectError`/`lastConnectErrorAt`) when the tunnel is not connected rather
    than pretending to ping.

  Kill-switch single-writer discipline is preserved: `relay-control.json` is written
  only by the HTTP worker, read by the relay fork at boot; the fork writes the two
  state files, the HTTP worker only reads them. Consumed by the reframed
  `@phlix/ui` admin Remote Access panel (v0.98.29).

- **Network Health now reflects real cross-process tunnel/heartbeat state; the
  `/health/network` probe no longer side-effects a heartbeat** (updates.md #40 / S40).
  `Admin\HealthController::relayHealth()` (`GET /api/v1/health/relay`) and
  `networkHealth()` (`GET /api/v1/health/network`) previously read a never-started,
  container-local `RelayConsumer`/`HubClient` copy — so the admin Network Health
  panel always showed offline/0/null even on a healthy, enrolled, connected box.
  Both endpoints now read the persisted cross-process snapshots via `RelayStateStore`
  (`relay-tunnel.state.json` + `hub-heartbeat.state.json`, written by the
  `phlix-relay-tunnel` and `phlix-hub-heartbeat` forks); `relayHealth` adds
  additive `relay.lastConnectError`/`lastConnectErrorAt` and `hub.lastLatencyMs`
  fields, and the heartbeat fork now records real round-trip latency each tick via
  monotonic `hrtime(true)` in `HubClient::performHeartbeatTick()` (lighting up the
  S39 Ping latency). Critically, **`/health/network` is now a cheap, side-effect-free
  reachability probe**: the previous implementation fired a REAL
  `POST /api/v1/servers/{id}/heartbeat` to the hub on **every** poll of the admin
  network-health indicator (mutating hub state and hammering the hub as the poller
  ran); it now just reads the latency/health snapshot the heartbeat fork already
  persists. Status classification is unchanged (`healthy` <100ms, `degraded`
  ≤500ms, otherwise `offline`; `offline` when not enrolled, no successful heartbeat
  yet, or the heartbeat is currently failing). Single-writer discipline preserved —
  only the heartbeat fork arms `HubClient::startHeartbeatLoop()` and writes
  `hub-heartbeat.state.json`. Known follow-up: no `updatedAt`-staleness guard, so a
  stale "healthy" snapshot could persist if the heartbeat fork itself hangs.

- **`CollectionService` empty-TMDB-key smell** (S33). `getOrCreateCollection()` and
  `syncCollectionForMovie()` took a `$tmdbApiKey` argument the bodies never used —
  the scanner passed an empty string (`syncCollectionForMovie($id, '')`). The TMDB
  key is actually resolved by the injected `TmdbProvider` (constructed with the
  configured key), so the dead parameter is removed from both methods.
  `syncCollectionForMovie()` now also skips cleanly (no HTTP, no DB writes, returns
  a no-op success) when `TmdbProvider::hasApiKey()` is false, instead of issuing
  requests that would just fail. TMDB calls continue to flow through the async
  `MetadataHttpClient` — never a new inline/blocking call in the scan path.

### Security

- **OIDC callback hardened against open-redirect / token exfiltration**
  (updates.md #37 / S44). Wiring the previously-dead OIDC flow activated an unchecked
  `redirect_uri`. The callback now allowlists the post-login landing target to a
  **same-origin relative path only** (rejects absolute URLs, `//host`, `/\host`,
  `javascript:`, and any control/CRLF characters) at both `authorize` and the final
  302, so a crafted `redirect_uri` can't phish the flow to an attacker origin. The
  minted session is delivered as **httpOnly + Secure + SameSite=Lax** cookies
  (`phlix_session` / `phlix_refresh`) — tokens are never placed in the URL, query
  string, or access logs.

- **`ldap:`-prefixed logins are rate-limited and no longer enumerate accounts**
  (updates.md #37 / S44). Provider logins (`AuthManager::loginWithProvider()`) now
  share the same per-IP brute-force budget as local login (`checkRateLimit` / `record`
  / `clear`), and a `RateLimitException` maps to `429` (not a misleading `503`).
  Credential failures return a generic `401 "Invalid credentials"` (no user
  enumeration); only genuine config/connection problems (`ldap_error:` prefixed)
  return `503`.

- **Credential-test responses can no longer echo a submitted plaintext credential.**
  `PluginAdminController::testCredentials()` hands operator-supplied secrets to
  third-party plugin code and then relayed that code's message — a returned string, a
  returned `message` field, or a caught exception's `getMessage()` — straight back in
  the JSON body. That is a plaintext-credential leak waiting to happen: HTTP client
  exceptions routinely embed the full request URI, and several provider APIs carry the
  API key as a **query parameter** (OMDb's `?apikey=…` is the in-tree example), so one
  `RequestException` would have put the operator's live key in the response verbatim.

  Every outgoing message now passes through
  `PluginAdminController::redactSubmittedSecrets()`, which replaces each submitted
  credential — literal form plus both URL-encoded spellings, since the value usually
  surfaces inside a URI — with `SettingsMasker::MASK`. A value is redacted when the
  manifest flags that setting `secret: true` (any length) **or** it is at least
  `REDACT_MIN_LENGTH` (8) characters. The second rule is deliberate defence in depth:
  `secret` is plugin-authored advisory metadata, so a manifest that forgets to flag a
  `password`/`token` field must not become a leak. The 8-character floor matches the
  password minimum already enforced elsewhere and keeps short non-credential values
  (`en`, `true`, a port number) readable in diagnostics.

  Tests assert the secret is **absent from the whole response body** — not that some
  masking flag was projected — across the thrown-exception path, the plugin-returned
  `message` path, the URL-encoded path, the not-flagged-but-long path and the
  flagged-but-short path, plus a negative test that a short non-secret value survives.
  Mutation-verified: reverting to the raw `getMessage()`/`message` relay turns five of
  them red with the plaintext key in the body. No credential is written to any log —
  this endpoint emits no audit entry and no log line.

- **Pagination DoS hole closed — an over-large `?limit=` can no longer reach a `LIMIT ?` binding.**
  `Request::queryInt()` performed no bounds checking at all, and an unclamped page size flowed from
  nine list endpoints straight into `LIMIT ?`. Under Workerman the worker process is **resident and
  shared**, so `GET /api/v1/libraries/{id}/items?limit=100000000` was not a big page — it was a
  memory-exhaustion vector able to OOM the process serving every other user. New
  `Phlix\Common\Http\PageLimit` is now the single pagination policy, with a **hard compile-time
  ceiling** (`PageLimit::MAX = 100`) that no configurable default can raise; it is reached through
  the new `Request::queryPageSize()` / `Request::queryOffset()` helpers. `ItemRepository`'s existing
  (and previously only correct) clamp now delegates to it rather than keeping a second copy of the
  bounds. Applied across **both** dispatch paths: `MediaItemController` (`index`, `recentlyAdded`),
  `LibraryController::scanHistory`, `MediaUserDataController::listFavorites`,
  `AdminLiveTvController::listUpcomingRecordings`, and `WebPortalRouter`'s library-items, search,
  similar-items, because-you-watched, music-artists and music-tracks endpoints. `queryInt()` is
  deliberately left unbounded — it serves non-pagination params — which is why pagination must not
  use it. Regression tests assert the clamp per endpoint on both paths.
- **Admin settings API no longer returns secrets in plaintext, and saving no longer wipes them.**
  `GET /api/v1/admin/settings` returned `getEffectiveMany()` raw, so every key flagged
  `"secret": true` in the shared schema (after the v0.24.0 re-vendor: `tmdb.api_key`,
  `lastfm.api_key`, `lastfm.shared_secret`) was shipped to the browser in clear text — present in the XHR body, in
  the DOM, and one "Show" click from being displayed, plus whatever proxy logs and HAR captures
  picked up. Secret values are now replaced with the existing `SettingsMasker::MASK` sentinel (the
  same mechanism the plugin settings path already used), and a new `data.secretStatus` map carries
  `{set, length}` per secret so the UI can still distinguish configured from unconfigured without
  seeing the value. Masking is driven by the schema's `secret` flag, **not** a hardcoded key list, so
  keys that gain the flag in a future `phlix-shared` release are covered automatically.
  Shipped in the same commit: `PUT` now skips any secret key resubmitted as the mask sentinel,
  leaving the stored value untouched — without that guard, fixing the GET alone would have made the
  first Save overwrite all three secrets with the literal `***`.

### Fixed

- **Playback stats now record each item's real `media_items.type` instead of hardcoding `movie`**
  (updates.md #31 / S31). `PlaybackController::dispatchPlaybackStarted()` previously wrote a
  literal `'movie'` type for every playback-start stats event, so Most Watched / Top Media and
  every other type-partitioned stat mis-attributed episodes, tracks, etc. as movies. A new
  private `lookupMediaType()` runs `SELECT type FROM media_items WHERE id = ? LIMIT 1` and records
  the raw stored ENUM value verbatim (no remap — `photo` stays `photo`, honoring the 13-member
  type ENUM), falling back to `'movie'` only when the row is missing/empty (the same default
  `MediaItemShaper::shape()` coerces unknown types to). The lookup runs only on the stats path
  (`statsCollector !== null && userId !== ''`), so a non-stats deployment pays no extra query.

- **`playback_state` progress upserts now update in place instead of inserting a new row every ~15s**
  (updates.md #29 / S29). Every progress tick from `PlaybackController::reportProgress()` (and
  `StreamManager::persistStreamState()`) writes via `INSERT ... ON DUPLICATE KEY UPDATE`, but the
  `playback_state` table only carried its `id` PRIMARY KEY — a fresh random UUID per call — and had no
  key on the upsert's real conflict target, so `ON DUPLICATE KEY` could never fire and each ~15s tick
  inserted a brand-new row, bloating the table and undermining resume / Continue Watching. Adds
  `UNIQUE KEY uq_playback_state_session_media (session_id, media_item_id)` so the existing upserts
  finally update the matching row. No controller change was needed — both upserts already INSERT
  `(id, session_id, media_item_id, ...)` and touch only `position_ticks`/`duration_ticks`/
  `playback_status`/`updated_at` in their `ON DUPLICATE KEY UPDATE`, i.e. they already target exactly
  the new key's columns. Mirrors the migration-072 `path_hash` dedupe-then-constrain precedent:
  `migrations/090_playback_state_session_media_unique.sql` is documentation-only (it splits to zero
  executable statements, reserving the number in the ledger), and the unique key is added out-of-band
  by the new one-time `migrations/cleanup_090.php`, which first merges any pre-existing duplicate
  `(session_id, media_item_id)` groups — keeps the max `updated_at`, tie-break max `id`, batched via
  the new `Phlix\Session\PlaybackStateDeduper` so a large production table drains safely across
  multiple bounded passes — so the `ADD UNIQUE KEY` cannot fail 1062.
  **⚠️ Post-deploy one-time step (like `cleanup_072.php`): after migration `090` is applied, run
  `php migrations/cleanup_090.php` ONCE.** `scripts/install.sh` and the Docker entrypoint do NOT run it
  automatically; until it runs, the unique key does not exist and progress writes keep duplicating.
  The script is idempotent and re-run-safe (the dedupe is a no-op once clean and `addUniqueKey()`
  swallows a duplicate-key-name; re-run it if it reports lingering duplicates).

- **Top Media / Top Users leaderboards no longer show blank rows for deleted items/users**
  (updates.md #14 / S14). The admin dashboard's Top Media list previously rendered play-count
  rows with no title/poster (and Top Users rows with no username) whenever a media item or user
  that had recorded plays was later deleted — the aggregate queries counted the surviving
  `stats_playback_events` rows while the per-row hydrate found nothing to render.
  `StatsCollector::getTopMedia()` / `getTopUsers()` now `INNER JOIN media_items` / `users`
  (`mi ON e.media_item_id = mi.id` / `u ON e.user_id = u.id`), so events whose item or user no
  longer exists are excluded at the query level, mirroring `WatchHistoryService`. A
  defense-in-depth null-skip in `DashboardService::getTopMedia()` / `getTopUsers()`
  (`continue` when `findById()` / `getUsernameById()` returns null) closes the narrow TOCTOU
  window if a row is deleted between the aggregate query and the hydrate. **Decision: orphaned
  rows are hidden**, not shown as a "(deleted item)" placeholder carrying the historical
  play-count. The joins are 1:1 on the PKs, so watch-time / play-count / ordering semantics for
  surviving items and users are unchanged, and the underlying `stats_playback_events` rows are
  retained (purging orphaned stats is a separate maintenance task, S77). New positive coverage in
  `DashboardServiceTest` (null-skip) and `StatsCollectorTest` (INNER JOIN present).

- **DLNA Start/Stop toggle now genuinely enables/disables the ContentDirectory service**
  (updates.md #28 / S28). The admin `POST /api/v1/admin/dlna/start` and `/stop` endpoints
  previously only flipped an in-memory boolean on the single request-worker's `CdsServer` (the SSDP
  announce), which neither registered the browse routes, reached the other worker processes, nor
  survived the next reload — the ContentDirectory routes are registered once per worker at boot in
  `Application::loadCdsRoutes()`, gated on the effective `dlna.cds_enabled` setting. `start()`/
  `stop()` now **persist** `dlna.cds_enabled` via the shared `SettingsRepository`
  (`set(..., 'bool')`, the same store the generic admin settings page writes) and schedule a
  **graceful SIGUSR2 reload** via a new reusable `AdminRestartController::scheduleGracefulReload()`,
  so every worker re-reads the setting at its next `onWorkerStart` and registers or drops the CDS
  routes accordingly. Idempotent — `409` when already in the desired state (no write, no reload);
  `503` when the settings store is unwired; `500` if the persist throws.
  `GET /api/v1/admin/dlna/status` now reports truthfully: `enabled` (persisted intent, read live
  from the store), `running` (whether THIS worker is actually serving the routes right now, frozen
  at its boot), and a new transient `reloadPending` (`enabled !== running`) the SPA can surface as
  "applying…". The reload is a Workerman one-shot timer + `posix_kill` probe — no blocking I/O,
  exit, or static request state in the request path. New `AdminDlnaServerControllerTest` (11 tests)
  and first-ever coverage of `scheduleGracefulReload()` in `AdminRestartControllerTest`.

- **DLNA ContentDirectory SOAP control parsing hardened against DIDL-Lite argument bleed**
  (updates.md #28 / S28). The live control parser `DlnaContentDirectoryController::parseSoapBody`
  (the `POST /dlna/content_directory` path) walked the whole document with `XMLReader`, taking the
  first text value for each localName found at ANY depth — so a same-named element nested inside
  embedded DIDL-Lite metadata (e.g. `<Filter><ObjectID>injected</ObjectID></Filter>`) could bleed
  into a top-level argument. A new namespace-aware, XXE-safe `Phlix\Dlna\SoapArgumentExtractor`
  reads arguments ONLY from the SOAP action element's DIRECT children (XPath `*`, so a wrapper
  element carrying nested metadata contributes no text and is skipped), parsing with `LIBXML_NONET`
  and never `LIBXML_NOENT`/`LIBXML_DTDLOAD` so external entities are never substituted.
  `DlnaServer::parseSoapBody` was de-duplicated onto the same helper (single source of truth, so the
  two parsers cannot diverge). The legacy `/cds/control` handler
  (`CdsControlHandler::parseSoapEnvelope`) was already direct-child scoped and is left unchanged.
  Real Browse/Search envelopes are unaffected; the empty-body `400` and the unknown-action UPnP
  fault are preserved. New `SoapArgumentExtractorTest` (18 tests) plus a regression test asserting a
  nested same-named element does not bleed into the extracted arguments (mutation-verified against
  the old any-descendant walk).

- **CSP `img-src` allowlists the TMDB image CDN so remote poster/backdrop/cast artwork renders**
  (updates.md #1). `SecurityHeaders::contentSecurityPolicy()` previously served artwork loaded
  directly from TMDB only under the default `'self'` policy, so any image not yet cached on our
  origin was blocked by the browser. `img-src` now explicitly names the two TMDB hosts —
  `https://image.tmdb.org` and `https://tmdb.org` (no wildcard) — alongside `'self' data: blob:`.
  This is a **stopgap**: an inline `// TODO(updates.md #47 / S71-S73)` marks it for removal once the
  generic image caching/loader work proxies all remote artwork through our own origin. Single source
  of truth in `SecurityHeaders`, also consumed by the `/app` shell's nonce'd variant; covered by an
  extended `SecurityHeadersTest` asserting both hosts are present and no wildcard is used.

- **The last two dishonest `restart: true` settings are now honest** (closes out the item below,
  which fixed 14 of 16 keys). Bumps `detain/phlix-shared` to **v0.26.0** (43 → 42 settings keys).

  **`hwaccel.probe_timeout` — DELETED, not wired.** It resolved to a config default, so it passed
  every resolvability test, but **no code ever read the effective value** — while the admin UI still
  rendered "requires a server restart to take effect" beside it. Two independent causes, both
  re-verified: `HwaccelRegistry` is built via `getInstance()` with no config
  (`FfmpegRunner.php:1359`) and its `initialize()` hands `HwaccelProbe` only a binary path
  (`HwaccelProbe.php:51`); and `HwAccelConfig::get()` resolved
  `$transcodingConfig['probe_timeout'] ?? $hwaccelBase['probe_timeout']` (`HwAccelConfig.php:103`)
  while `config/transcoding.php:33` always declares the key, so the `hwaccel.*` side could never win.
  Deleted rather than wired (plan §4 rule 10) because wiring is neither cheap nor safe: the real
  timeouts are **two different** hardcoded constants (`ShellTimeout::FFMPEG_TIMEOUT = 10`,
  `::GPU_TOOL_TIMEOUT = 5`) reached through 22 static call sites across seven `VendorProbe` classes;
  the schema permitted `minimum: 0` and coreutils `timeout 0 CMD` means **no timeout**, which would
  let an admin hang a resident worker at boot — precisely what `ShellTimeout` exists to prevent; and
  the shipped `helpText` described a per-file pre-transcode probe that does not exist (the real one
  is a one-time process-wide capability scan). `config/hwaccel_base.php` and `config/transcoding.php`
  keep their `probe_timeout` literals, so the merged array shape is unchanged — it is simply no
  longer an admin setting. Flipping `restart` to `false` was rejected: that would claim the key is
  *live*, which is even less true.

  **`process.<worker>.enabled` (5 keys) — the half-live asymmetry is now disclosed in the UI.** The
  old `helpText` claimed the worker "is not spawned", which is **false**: `start.php` spawns it
  regardless and the gate lives in each worker's `onWorkerStart`, which logs and skips arming the
  poll timer. Since the admin only ever sees `helpText`, all five now state that turning a worker
  OFF applies after a restart; that turning one back ON also applies after a restart *unless* it is
  disabled in the on-disk `config/process.php`, in which case the in-app Restart button (SIGUSR2, a
  graceful reload of the already-executed master) cannot help and the service itself must be
  restarted; and that a worker switched off here still occupies an idle process. No behaviour
  change — the gate is unchanged and correct; only the promise made to the admin was wrong.

  Server-side: the hand-written allow-list lock-in and key-count assertions in
  `tests/Unit/Server/Http/Controllers/Admin/AdminSettingsControllerTest.php` go 43 → 42 (the list is
  deliberately **not** derived from the schema — mutation-verified: re-adding the deleted key to it
  turns the test red). Two tests that used `hwaccel.probe_timeout` merely as a convenient int-typed
  key now use `marker_detection.intro_max_duration`.
  `tests/Unit/Admin/SettingsDefaultResolvabilityTest` still passes with **every** key resolving.
  `docs/dev/settings-restart-gap.md`'s per-key table updated to final reality.

- **`restart: true` settings now actually take effect on restart.** The shared settings schema marks
  16 boot-only keys `"restart": true` and the admin SPA renders "requires a server restart to take
  effect" beside each one — a promise nothing kept. `SettingsRepository::getEffective()` resolves
  `override ?? default`, but almost nothing called it: every other consumer read the boot `$config`
  array (which `start.php` `include`s ONCE in the master and closes over) or `include`d a
  `config/*.php` file straight from disk, and **nothing merged the `server_settings` table into
  either**. Changing one of these settings and restarting — even a full `systemctl restart` — did
  nothing. New `Phlix\Config\EffectiveConfig` loads the persisted overrides once per process and
  overlays them onto the config defaults using the same dotted-key semantics as
  `SettingsRepository` (leading segment = config *file*, remainder = path inside it). It is called
  as `bootstrapAndOverlay($config)` at the top of every `onWorkerStart` in `start.php` (HTTP,
  WebSocket, hub-heartbeat, relay-tunnel, managed workers) **and** in `public/index.php`, before
  `ContainerFactory::create()`, so every DI provider that reads boot config observes the effective
  value with no per-provider wiring; consumers that bypass `$config` by `include`ing a config file
  directly (`HwAccelConfig::get()`, `FfmpegRunner::getTranscodeTimeout()`,
  `Recorder::getTranscodeTimeout()`) now read `EffectiveConfig::file()` instead.
  **Note the recommended design was wrong and was corrected:** overlaying only the boot `$config`
  array covers just 4 of the 16 keys, because dotted keys name config *files* and only
  `server.hls.*` lives in `config/server.php`.
  **15 of 16 keys are now live after a restart or the in-app graceful reload.** Two honest
  exceptions, documented rather than hidden: `hwaccel.probe_timeout` has **no consumer at all**
  (`HwaccelRegistry` is constructed without this config) and is additionally shadowed by
  `config/transcoding.php`'s own always-present `probe_timeout` — it needs a `phlix-shared`
  follow-up to either wire it up or drop the key; and `process.<worker>.enabled` can *disable* a
  worker on reload (the gate moved into the worker's own `onWorkerStart`, which skips arming the
  poll timer and logs) but cannot *enable* one that `config/process.php` disables on disk, because
  the master's spawn loop runs before `Worker::runAll()` and Workerman cannot fork a Worker group
  afterwards. A settings-store failure — DB down, `server_settings` missing on a fresh install —
  degrades to the shipped file defaults rather than crash-looping the worker, and an override is
  applied only where the default already exists, so a malformed or unknown persisted row cannot
  inject a config key. Per-key status table and the reasoning behind both exceptions live in
  `docs/dev/settings-restart-gap.md`, rewritten from "known gap" to reflect the fix.

### Removed

- **`dash_url` dropped from the transcode/status response payloads** (updates.md #11 / S11).
  `POST /api/v1/media/{id}/transcode` and `GET /api/v1/transcode/{jobId}/status` (via
  `TranscodeController::start()` / `::status()`), plus both `TranscodeManager::ensureHlsJob()`
  return arrays (reused-job and fresh-job), previously advertised a
  `dash_url` → `/dash/{job}/manifest.mpd`. Real DASH is **not** produced by the on-demand
  pipeline: `ensureHlsJob()` emits a multi-variant HLS `.ts` ladder and never invokes the CMAF
  DASH muxer (`FfmpegRunner::buildCmafCommand()` / `startCmafTranscode()` exist but are not wired
  into the on-demand flow), so that manifest never exists on disk and every request to the
  advertised URL 404'd. The field is now removed — and the `ensureHlsJob()` `@return` docblock
  narrowed to match — so clients are no longer handed an unresolvable URL. The DASH library
  (`DashStreamer`, `StreamManager`, and the CMAF muxer path) is left untouched, reserved for real
  DASH support tracked as **S56-S60** (updates.md #57). Repo-wide grep confirmed no `dash_url`
  reader remained in `src/`, `tests/`, phlix-ui, or web-ui — no known client depended on the key's
  presence; tests dropped their `dash_url` assertions in lockstep and added absence guards.

- **Dead `Router::dashStreaming()` route removed** (updates.md #28 / S28). The helper wired `/dash/*`
  to `DashController::getMasterManifest`/`getAdaptationSetManifest`/`getSegment` — methods that do
  not exist — and had ZERO callers (grep-clean across `src/`, `tests/`, `public/`). It is deleted.
  The real DASH routes registered in `Application::loadStreamingRoutes()`
  (`GET /dash/{job}/manifest` → `getManifest`, `GET /dash/{job}/{file}` → `serveFile`) and the
  `DashStreamer`/`DashController` library are untouched, reserved for real DASH support tracked as
  **S56-S60** (updates.md #57). An incidental genuinely-dead `$scpdUrls` property + `setupScpdUrls()`
  in `DlnaServer` (surfaced by the SOAP-parser rewrite; `getScpdXml()` reads the services directly
  and never the map) was removed in the same step. A `DashRouteRemovalTest` guards that the dead
  route and phantom controller methods stay gone while the real `getManifest`/`serveFile` remain.

### Added

- **`enum` / `minimum` / `maximum` are now enforced on `PUT /api/v1/admin/settings`.** The endpoint
  previously validated the internal *type* only; the schema's 4 `enum`, 21 `minimum` and 18
  `maximum` constraints were emitted to the UI for display and never checked on write, so every
  bound was cosmetic and trivially bypassed with a direct PUT (`auth.signup_mode` could be set to
  any string, `transcoding.max_concurrent_transcodes` to `0` or `999999`). Coerced values are now
  validated against their own JSON-Schema property sub-schema using `justinrainbow/json-schema`
  (already a dependency, previously unused here), reported through the same `errors{}` map the SPA
  renders as inline per-field errors. The UI's "Auto-detect" accelerator option is the schema's
  empty-string enum member and validates directly — the narrow shim that briefly translated the
  SPA's `"null"` string against a JSON `null` enum member was removed once `phlix-shared` v0.24.0
  swapped that member for `""` (see *Changed* below).
- **`SettingsRepository::hasDefault()`**, distinguishing "the config declares this key and its value
  is null" from "no such config path exists" — which `getDefault()` cannot express. Backs a new
  regression test asserting every schema key resolves to a real config default. That test ran
  quarantined behind a 25-key `PENDING_SHARED_REVENDOR` list while the schema still declared
  unresolvable keys; the v0.24.0 re-vendor removed them all, so the constant and its companion
  staleness test were deleted and the assertion now runs unconditionally over all 40 keys —
  **43 as of the v0.25.0 re-vendor below**, still unconditionally, still with no quarantine.

### Changed

- **Bumped `detain/phlix-shared` to `^0.27.0`; the server-settings schema went 42 keys → 41.**
  `transcoding.include_software_fallback` was deleted upstream as a **consumerless** key —
  the same defect shape as `hwaccel.probe_timeout` in v0.26.0, and the reason
  `SettingsDefaultResolvabilityTest` did not catch either: **resolvable ≠ consumed.** The
  key resolved cleanly to `config/transcoding.php:44` and `HwAccelConfig::get()` copied it
  into the merged hwaccel array, but nothing read the merged value. That array reaches
  exactly two consumers, and neither reads this key: `FfmpegRunner::setConfig()`
  (`Application.php:2971`, `TranscodeServicesProvider.php:149`), which reads precisely
  `tone_mapping_mode` / `prefer_hdr_output` / `preferred_accelerator` / `enabled` /
  `prefer_hardware` and whose `getConfig()` accessor has no caller in `src/`; and
  `HwaccelRegistry`, whose software-fallback branch (`HwaccelRegistry.php:160,206`) reads
  the **separate** `hwaccel.fallback_to_software` key out of `config/hwaccel_base.php`. So
  the toggle was inert in both directions — turning it off never disabled software
  fallback, turning it on never enabled anything.

  Deleted rather than wired (plan §4 rule 10) because the working equivalent already
  exists: `hwaccel.fallback_to_software` is genuinely consumed and is the key to expose if
  a software-fallback toggle is wanted. The `config/transcoding.php` default and the
  `HwAccelConfig::get()` merge line are **retained**, now carrying a "NOT AN ADMIN SETTING"
  comment, so the merged array shape is unchanged for any caller reading it defensively —
  mirroring exactly what v0.26.0 did for `probe_timeout`. The `assertCount` lock-in and its
  dotted-key → internal-type map in `AdminSettingsControllerTest` were updated to 41 and
  remain **deliberately hand-written**: deriving them from the vendored schema would make
  the assertion tautological. Mutation-verified by re-adding the key to the hand-written
  list and confirming the test goes red against the 41-key vendored schema.

- **Bumped `detain/phlix-shared` to `^0.25.0`; the server-settings schema went 53 keys → 40 → 43.**
  The v0.24.0 re-vendor deleted every key that resolved to no config path and had no runtime
  consumer — the `trakt.*` trio, the `subsystem.*`, `database.*` and `hls.*` families, the duplicated
  `transcoding.*` tunables (the surviving equivalents live under `ffmpeg.*`, `server.hls.*` and
  `process.*`), and the unimplemented `metadata.preferred_*` / `metadata.fanart_api_key` / `auth.*`
  extras. Those keys rendered in the admin Settings page, accepted a `PUT`, and reported `null`
  forever; they are now simply absent. The controller derives its allow-list from the schema, so no
  server-side key list needed editing — but the `assertCount(53)` lock-in test and its explicit
  dotted-key → internal-type map were updated to the real count, deliberately kept hand-written so an
  unreviewed schema addition still fails loudly rather than being auto-derived into a no-op.

  **Correction — the `trakt.*` trio should not have been in that sweep, and is restored in
  v0.25.0 (see *Fixed* below).** Unlike the other deletions, those three keys always had a real
  runtime consumer; only their *config default* was unreachable. Deleting them cost a genuine
  admin capability, and the earlier suggestion here to re-add them as `scrobblers.trakt.*` was
  wrong: that prefix does resolve, but renaming would orphan `trakt.*` rows already persisted in
  `server_settings` on live installs.
- **`transcoding.preferred_accelerator`'s "auto-detect" sentinel is now the empty string, not
  `null`.** `config/transcoding.php` defaulted to `getenv('PREFERRED_ACCELERATOR') ?: null` against a
  schema whose auto-detect enum member is `""`, so `GET /api/v1/admin/settings` returned `null`, the
  SPA's `<select>` matched no option and rendered blank, and saving that blank value back failed the
  new enum enforcement. The config default is now `''`. The consumer in `FfmpegRunner` was already
  correct (`is_string($x) && $x !== ''`), so no transcoding behaviour changes. The schema's enum was
  also corrected upstream for accuracy: `nvenc` was dropped (it is an FFmpeg *encoder*, not an
  hwaccel name) and `v4l2` became `v4l2m2m`; `d3d11va` and `dxva2` were added. A new test asserts
  every `enum`-constrained key's config default is one of its declared members, which covers this
  whole class of drift rather than just the one key.

### Fixed

- **Trakt operator credentials are settable from the admin Settings page again.** The v0.24.0
  schema sweep (above) deleted `trakt.client_id`, `trakt.client_secret` and `trakt.redirect_uri`
  because `SettingsRepository::getDefault()` resolves a two-segment key like `trakt.client_id`
  against a FLAT `config/trakt.php`, and the real config lives at `config/scrobblers/trakt.php`.
  The *read* path never broke — `TraktOAuthController::SETTING_KEY_MAP`
  (`src/Server/Http/Controllers/TraktOAuthController.php:63-67`) maps those keys itself, so
  installs with existing overrides kept working — but the admin `PUT` allow-list is derived from
  the schema, so saving any of the three started failing with
  `{"errors":{"trakt.client_id":"Unknown setting key."}}`. Operators were left with env vars and a
  file edit.

  **Fix:** new `config/trakt.php`, a one-line re-export
  (`return require __DIR__ . '/scrobblers/trakt.php';`) using the same idiom `config/hwaccel.php`
  already uses for `config/hwaccel_base.php`. The three keys resolve again under their **original
  flat names** — no rename, no orphaned `server_settings` rows, no read-path change, no migration.
  `config/scrobblers/trakt.php` remains the canonical file; the shim holds no values of its own and
  is asserted to return the identical array.

  They were deliberately **not** renamed to `scrobblers.trakt.*`, even though `getDefault()` now
  walks nested config paths and would resolve that form: `trakt.*` overrides are already persisted
  on live installs and are what the controller reads, so a rename would silently drop working Trakt
  credentials on upgrade.

  Both credential halves are masked (`"secret": true` upstream), matching this schema's existing
  treatment of `lastfm.api_key` — the exact analogue, since Phlix sends the Trakt client_id as the
  `trakt-api-key` header on every request. `trakt.redirect_uri` is a public URL and is not masked.
  The documented secret set grows from a trio to a quintet.

  `"restart": false` on all three is verified rather than assumed:
  `TraktOAuthController::applySettingsOverrides()` (`:414-429`) calls
  `SettingsRepository::getOverride()` at `:421` on every request via `loadConfig()` (`:402`), reached
  from `authorize()` (`:158`), `callback()` (`:226`) and `status()` (`:511`). A saved credential is
  live on the very next request. These are among the few settings for which `restart: false` is
  literally true — the general boot-only override gap remains open and is tracked in
  `docs/dev/settings-restart-gap.md`.

  New `tests/Unit/Server/Http/Controllers/Admin/TraktSettingsEndToEndTest.php` asserts the
  **consequence, not the shape**: that a `PUT` is accepted and persisted, that the saved client_id
  is the one that actually ends up in the Trakt authorize redirect (`status()` flips to
  `configured: true` and `authorize()` goes 503 → 302 with no restart), that the secret never
  appears in a GET or PUT body, and that re-submitting the mask sentinel leaves the *stored*
  credential intact and Trakt still working. Mutation-verified: deleting the shim, removing the keys
  from the vendored schema, and disabling the PUT mask guard each fail it.
- **`POST /api/v1/admin/restart` could not work on any real box.** Three separate defects:
  1. **The PID file nothing wrote.** The controller read `config/server.php`'s
     `worker.pid_file` (`/var/run/phlix/pid`), but `Worker::$pidFile` was never assigned anywhere in
     the repo, so Workerman used its own default (`dirname(start.php)/workerman.start.php.pid`).
     Every call returned HTTP 500 "PID file not found". New `Phlix\Server\Runtime\PidFile` is
     applied from `start.php` before `Worker::runAll()` so the writer and the reader agree; it
     creates the directory when needed and degrades to Workerman's default (with a stderr warning)
     rather than aborting boot if the location is unusable.
  2. **The signal was inverted.** In Workerman, `SIGUSR2` is the *graceful* reload and `SIGUSR1` is
     the *non-graceful* one (`Worker::reload()`: `$sig = getGracefulStop() ? SIGUSR2 : SIGUSR1;`);
     SIGUSR1 stops children immediately and arms a `SIGKILL`. The endpoint sent SIGUSR1 while both
     docblocks asserted the exact opposite. It now sends `SIGUSR2`, and the docblocks are corrected.
  3. **The signal fired mid-request.** `posix_kill()` ran before the `Response` was even built, so
     the caller could get a connection reset instead of the ack — which the SPA renders as "Failed
     to restart server" for a restart that did happen. The handler now pre-flights the target with
     `posix_kill($pid, 0)` (a probe that sends no signal, so a stale PID is still a real 500) and
     defers the actual `SIGUSR2` to a Workerman one-shot timer, so the JSON ack flushes first.

  The response body is `{"success":true,"message":"Restart signal sent"}`. The tests were rewritten
  to exercise the pid-path agreement between `start.php`, `config/server.php` and the DI provider,
  and to assert which signal is sent and that it is deferred — none of which the previous
  `sendSignal()`-stubbing suite covered.
- **`ffmpeg.max_concurrent_transcodes` is no longer a fake setting.**
  `TranscodeManager::__construct()` assigned a bare literal `$this->maxConcurrentTranscodes = 4;` —
  not a constructor parameter, not a config read — so `config/ffmpeg.php`'s value and its admin-UI
  setting (min 1 / max 64 / default 4, whose help text advises "a 16-core CPU with an NVIDIA GPU can
  typically handle 6–8") were both inert. An admin who set 8 still got 4. The ceiling is now a
  constructor parameter resolved by `TranscodeServicesProvider` from the **effective** value (config
  default merged with any `server_settings` override), with `DEFAULT_MAX_CONCURRENT_TRANSCODES` as
  the fallback. A settings-store failure degrades to the config default rather than breaking
  transcoding.
- **`SettingsRepository::getDefault()` can now resolve nested config paths.** The dotted-key rule
  treated the first segment as the entire filename, so `config/scrobblers/trakt.php` — a real,
  shipped config file — was unreachable by any key, and anything pointing at it resolved to a `null`
  default. The file part may now span subdirectories (longest match wins:
  `scrobblers.trakt.client_id` → `config/scrobblers/trakt.php`'s `client_id`). Purely additive — a
  multi-segment file path never resolved before — and the path jail is tightened to validate every
  segment, so no key can escape `config/`.

### Known gaps (documented, NOT fixed)

- **`restart: true` settings still do not take effect on restart.** The restart endpoint now works,
  but a reload cannot apply boot-only settings, because `start.php` `include`s `config/server.php`
  once in the master and nothing ever merges `server_settings` DB overrides into that array — and a
  full `systemctl restart` does not either. This affects every `restart: true` schema key. The fix
  is an architectural change (rewire each consumer to `getEffective()`, or overlay the overrides
  onto `$config` inside `onWorkerStart`) and is deliberately out of scope here. Full write-up,
  including the two candidate designs: **`docs/dev/settings-restart-gap.md`**. Until it lands, do
  not describe a `restart: true` key as taking effect after a restart.
- The restart endpoint has no rate limit and emits no audit-log entry; a reload also cycles the
  SyncPlay WebSocket and DLNA SSDP workers, dropping live sessions, with no warning in the UI.

### Changed

- **Legacy Smarty page UI retired — the `/app` Vue SPA is now the ONLY web UI.** All legacy
  server-rendered (Smarty) portal page routes now issue a **302 redirect** to their `/app` equivalent
  (dispatched in `public/index.php`), and the SPA shell is served for `/app` + `/app/*`. High-level
  redirect map: `/` → `/app`; `/login` → `/app/login`; `/register` → `/app/signup`; `/library` → `/app`;
  `/library/{id}` → `/app/library/{id}`; `/media/{id}` → `/app/media/{id}`; `/player/{id}` →
  `/app/player/{id}`; `/search` → `/app/search`; `/settings` → `/app/settings`; `/admin` →
  `/app/admin/dashboard`; `/admin/plugins` → `/app/admin/plugins`; the **Music** pages
  (`/music`, `/music/album/*`, `/music/artists`, `/music/artist/*`, `/music/tracks`, `/music/player`)
  → `/app/music/*`; **Books** (`/books`, `/books/{id}`, `/books/{id}/read`) → `/app/books/*`;
  **Audiobooks** (`/audiobooks`, `/audiobooks/{id}`, `/audiobooks/{id}/read`) → `/app/audiobooks/*`;
  and **Photos** (`/photo/albums`, `/photo/album/{id}`, `/photo/photo/{id}`, `/photo/slideshow`,
  preserving the `library_id` query string) → `/app/photo/*`. The redirects stay auth-gated where the
  legacy page was, so an unauthenticated browser is bounced to `/app/login` first.

### Removed

- **Deleted the migrated Smarty page-rendering stack.** `PageRenderer`, the media/admin page
  controllers, all `public/templates/**/*.tpl` page templates (auth, home, library, player, music,
  books, audiobooks, photo, search, settings, admin dashboard/plugins/music/lastfm, layouts, partials),
  and the legacy per-page JS/CSS were removed now that the `/app` SPA supersedes them.
  **Retained on the server (still live):** `smarty/smarty` (composer) + `src/Admin/NewsletterGenerator.php`
  + `public/templates/emails/newsletter.tpl` — the **newsletter email** still renders via Smarty;
  `ThemeMiddleware`; `WebPortalRouter` (serves the portal JSON under `/api/v1/*`);
  `SharedUiController` + `ViteAssets` (serve the SPA shell + built bundle); and the legacy **binary**
  API routes (artwork, book cover/download, photo thumbnail/full, media stream). This supersedes the
  earlier "Smarty retirement pending owner verification" note in this changelog.

### Fixed (live production batch)

- **Continue-Watching rail 500 on both clients (MySQL error 1060).** `PlaybackController::getContinueWatching()`
  emitted a duplicate `mi.id AS id` column in its derived table, which MySQL rejected with
  `ERROR 1060 (Duplicate column name 'id')`, 500-ing the CW rail on the SPA and console. The duplicate
  projection was removed.
- **Continue-Watching poster/progress shape for authless clients.** CW rows are shaped via
  `MediaItemShaper` so each carries top-level `id` / `poster_url` / `runtime` (episodes resolve the
  **series** poster); additionally the nested `metadata.poster_url` is **re-minted** at response time so
  the console (which reads the nested metadata) gets a fresh signed artwork URL rather than an expired one.
- **Signed artwork URLs expiring for authless clients (console 401s).** `poster_url`, `poster_srcset`,
  `backdrop_url`, `backdrop_srcset`, and `logo_url` are now **re-signed at response time**
  (`SignedUrl::refreshArtworkUrl()` / `SignedUrl::refreshArtworkSrcset()`), so clients that cannot
  re-authenticate a request (e.g. the console) no longer receive `401`s on artwork whose signature had
  aged out.
- **PHP 8.5 — logging + PDO hardening under coroutines.** The MySQL buffered-query attribute now uses the
  `\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY` class constant (the global `PDO::` alias is deprecated on 8.5),
  `connect()` suppresses the PDO `E_DEPRECATED`, and every Monolog handler is wrapped in
  `WhatFailureGroupHandler` so a concurrent-coroutine `E_DEPRECATED` raised mid-write can no longer crash
  the log write (which had surfaced as fatal request failures during metadata matching).
- **Spurious "circular dependency" 500 from a php-di coroutine race.** `WebPortalRouter` is now **warmed
  once at worker start** (`start.php`), so concurrent coroutines no longer collide on php-di's in-progress
  resolution guard and mis-report a circular dependency.
- **RelayConsumer `connect()` debug logging TypeError.** The connect-path debug log is now null-safe, so a
  synchronous disconnect (null connection) no longer throws `spl_object_id(null)`.
- **Transcode — undecodable 10-bit H.264 on the hwaccel software-fallback path.** When hardware accel is
  enabled but only a software encoder is registered, segments were re-encoded to **10-bit** H.264
  ("High 10") from 10-bit HEVC sources — which no browser can decode. That path now forces browser-safe
  **8-bit** output (`-pix_fmt yuv420p -profile:v high -level 4.1`), matching the pure-software path; true
  hardware encoders keep their own `nv12` 8-bit output. `TranscodeManager::JOB_KEY_VERSION` was bumped
  **v3 → v4** so any cached 10-bit segments are invalidated and regenerated as 8-bit.
- **Transcode — HLS audio now downmixed to stereo AAC for browser compatibility.** A re-encoded HLS
  audio stream from an **AC-3 5.1(side)** source (side channels SL/SR) produced AAC that FFmpeg's native
  encoder tagged with `channel_configuration=0` (a PCE) in the ADTS header, which **hls.js cannot parse** —
  it failed to build the audio MSE `SourceBuffer` and the whole player load errored **even with valid
  8-bit H.264 video**. Re-encoded audio is now forced to **stereo** (`-ac 2`, `channel_configuration=2`,
  universally browser-safe) via the new `FfmpegRunner::browserSafeAudioChannels()` helper, applied on every
  re-encode path (direct-play `audio_codec === 'copy'` passthrough is untouched). `TranscodeManager::JOB_KEY_VERSION`
  was bumped again so any cached surround-AAC segments are invalidated and regenerated as stereo. Together
  with the 8-bit video fix above and the CSP `blob:` allowances below, this made a **10-bit HEVC + AC-3
  5.1** episode actually play in a normal browser (confirmed via headless Chrome under enforced CSP) — browser
  HLS playback requires **all three layers**: 8-bit video **+** stereo AAC audio **+** CSP `blob:` allowances.
- **CSP — SPA now allows `media-src`/`worker-src 'self' blob:` so browser HLS playback works.** The SPA
  Content-Security-Policy (`SecurityHeaders::contentSecurityPolicy()`) added `media-src 'self' blob:` and
  `worker-src 'self' blob:`, required so hls.js can attach its MSE `blob:` object URL to the `<video>`
  element and spawn its `blob:`-sourced transmux Web Worker. Without them a strict browser rejected the
  load with `MEDIA_ELEMENT_ERROR: Media load rejected by URL safety check`, blocking **all**
  HLS/transcoded playback.
- **CSP — `/app` shell inline bootstrap now runs under a per-request script nonce (no `'unsafe-inline'`).**
  The `/app` SPA shell's inline `window.__PHLIX__` bootstrap `<script>` now carries a per-request
  cryptographically-random **nonce**: `SharedUiController` emits `<script nonce="…">` and sets a matching
  CSP built via `SecurityHeaders::contentSecurityPolicy($nonce)` (adds `'nonce-…'` to `script-src`), so the
  inline block executes **without** weakening `script-src` to `'unsafe-inline'`. Non-`/app` responses keep
  the strict default policy (no nonce).
- **Transcode — 480p rung no longer 404s on a 16:9 source (odd-width reject).** A 480p HLS rung scaled
  from a 1920×1080 source computed **853×480** — an **odd width** that libx264 rejects, so the rung 404'd.
  The transcode scale filter now appends `:force_divisible_by=2` so widths round to an even value.
  `TranscodeManager::JOB_KEY_VERSION` was bumped **v5 → v6** to invalidate any cached/failed rungs.

### Fixed

- **WS-A — Continue-Watching now shows poster images and a progress bar in the `/app` SPA.**
  `PlaybackController::getContinueWatching()` (which backs both `GET /api/v1/me/continue-watching` and `GET /api/v1/users/me/continue-watching`) now runs every row through `MediaItemShaper::shape()`, so each entry gains the same top-level fields the SPA `MediaCard` renders: `id` (= **media item id**, so cards navigate to the correct detail page — previously the playback-state id), `poster_url`, `poster_srcset`, `runtime` (minutes), `year`, and `rating`. For **episodes** a two-level `LEFT JOIN` (season → series) resolves the **series** poster (falling back to the season poster, then the episode's own poster) *before* shaping, replacing the TMDB still/screengrab that previously showed. The playback-progress fields `position_ticks` and `duration_ticks` are re-attached at the top level (the SPA `useResumeSync` reads `position_ticks` for the progress bar), and `parent_id`, `media_item_id`, and the full `metadata` map are preserved so the console `fromContinueWatching` mapper and the profile rating-gate (`media_item_id` filter) keep working. `ROW_NUMBER()` de-dup (one row per media item, newest first) is retained. Tests: strengthened unit assertions on the top-level `poster_url`/`runtime`/`id` in `tests/Unit/Session/PlaybackControllerTest.php`, plus a new real-MySQL integration test `tests/Integration/Session/ContinueWatchingIntegrationTest.php` that seeds series/season/episode + movie + duplicate playback rows and asserts the shaped output including the dedup.
- **WS-C — Logging no longer triple-writes to app.log, events.log, plugins.log.**
  Previously every handler attached to every per-channel logger, so each debug/info record was written to `app.log`, `events.log`, and `plugins.log` at once (identical firehoses) and the two subsystem logs were polluted with unrelated records. Handlers in `config/logger.php` are now routed by two optional gates that `StructuredLogger::setupHandlers()` evaluates per channel: `channels` (absent/empty = attach to ALL channels) and `env` (attach only when the named env var is truthy). Resulting routing: `app.log` is the **master firehose** — the untagged `file` handler still captures every channel at debug+ (nothing is lost); `error.log` is the **severity mirror** — untagged too, so all channels at error+ aggregate there; `plugins.log` is scoped to the `PLUGINS` channel only (plugin lifecycle); `events.log` is scoped to the `EVENTS` channel **and** gated behind `PHLIX_DEBUG_EVENTS`, so it stays **empty by default** — event-dispatch records still land in `app.log`, so no diagnostic coverage is lost when the switch is off (this is why an operator sees an empty `events.log` without `PHLIX_DEBUG_EVENTS=1`). Also removed a dead `'default' => 'file'` key from `config/logger.php` that `setupHandlers()` never read (the catch-all comes from the untagged handler, not that key). Routing helpers `handlerAppliesToChannel()` / `handlerEnvEnabled()`; tests in `tests/Unit/Common/Logger/StructuredLoggerTest.php`.

### Security

- **SV-4.15 — per-surface auth rate limiting.** The previously-unlimited auth surfaces are now rate-limited: `register`, `refresh`, WebAuthn login `start`+`finish`, the public JWKS endpoint (`/.well-known/jwks.json`), and WS-connect on `:8097` (only `login` had a limiter before). HTTP surfaces reply **`429 Too Many Requests`** with a `Retry-After` header and body `{"error":"Too Many Requests","code":"rate_limited"}`; WS-connect rejects the handshake in-place (no throw out of the connect hook). Also **fixes a latent bug** where the existing DB-backed `login` limiter tripped with a **500** (uncaught `RateLimitException`) instead of a **429** — both dispatch entrypoints and `public/index.php` now map `RateLimitException` centrally. New operator config: a `rate_limit` block in `config/server.php` with per-surface env overrides `RATE_LIMIT_<SURFACE>_MAX` / `RATE_LIMIT_<SURFACE>_WINDOW`; a `trusted_proxies` setting (env `TRUSTED_PROXIES`, comma-separated IP/CIDR, default loopback-only) used to derive the real client IP from `X-Forwarded-For`/`X-Real-IP` for limiter keys — **it must list your reverse-proxy hops (nginx/HAProxy) or IP-keyed limits will bucket every request under the proxy address**; and a new migration **`085_rate_limit_buckets.sql`** (the shared DB-backed bucket table for the credential-enumeration surfaces — **run migrations on deploy**). Register/refresh/WebAuthn are DB-backed (true-global across all HTTP workers); JWKS and WS-connect are worker-local in-memory (JWKS is cache-frontable; the `:8097` WS worker runs `count=1` so per-worker == global there). `login` keeps its own IP-keyed DB-backed store (migration 074), untouched.

### Added

- **Phase 1 — `AdminSettingsController` now emits `meta` block in GET `/api/v1/admin/settings`.**
  Each key in `data.meta` carries per-setting metadata: `label`, `helpText`, `helpLinks`, `tier`, `group`,
  `enum`, `enumLabels`, `optionHelp`, `minimum`, `maximum`, `default`, `secret`, and `restart`. As
  defensive defaults `helpLinks` resolves to `[]` (empty array) and `tier` resolves to `'standard'` when
  not explicitly set in the schema.
- **Phase 2 — 28 new server settings added to `schemas/server-settings.schema.json` and `config/*.php`.**
  Schema extended with Phase 2A–2C settings: `transcoding.preferred_accelerator`,
  `transcoding.include_software_fallback`, `transcoding.tone_mapping_mode`,
  `transcoding.prefer_hdr_output`, `transcoding.max_concurrent_transcodes`,
  `transcoding.transcode_timeout`, `transcoding.max_concurrent_scan_probes` (Phase 2A);
  `metadata.preferred_language`, `metadata.preferred_country`, `metadata.fanart_api_key`
  (Phase 2B); `database.pool_size`, `database.timeout`, `relay.reconnect_delay`,
  `relay.ping_interval`, `hls.segment_seconds`, `hls.max_concurrent_segments` (Phase 2C).
  All keys are optional with defensive defaults in `config/*.php`.
- **Phase 3 — server tunables added to schema: segment cache limits, stale-job age, global inflight cap.**
  New keys: `transcoding.segment_max_inflight_global`, `transcoding.segment_cache_max_age`,
  `transcoding.segment_cache_max_bytes`, `transcoding.stale_job_max_age`.
- **Phase 4 — subsystem killswitches: scan, plugin auto-update, marker detection, asset jobs, similarity.**
  New keys: `subsystem.library_scan_enabled`, `subsystem.plugin_auto_update_enabled`,
  `subsystem.marker_detection_enabled`, `subsystem.media_asset_jobs_enabled`,
  `subsystem.similarity_enabled` — each a boolean kill-switch to disable heavy background
  subsystems independently.
- **Phase 5 — auth settings: enabled flag, rate limit override, session lifetime.**
  New keys: `auth.enabled`, `auth.rate_limit`, `auth.session_lifetime`. Consumed by
  `AuthManager` to gate auth surfaces and override per-surface rate limits.
- **WS-D — media SPA pages are now reachable under `/app` (Smarty retirement pending owner verification).**
  `web-ui/src/main.ts` now registers routes **and** top-bar nav entries for SPA pages that shipped in
  `@phlix/ui` but were previously unreachable on the server: **Books** (`/app/books`, `/app/books/:id`,
  `/app/books/:id/read`), **Audiobooks** (`/app/audiobooks`, `/app/audiobooks/:id`, `/app/audiobooks/:id/play`),
  **Photos** (`/app/photo/albums`, `/app/photo/album/:id`, `/app/photo/photo/:id`, `/app/photo/slideshow`),
  **Search** (`/app/search`), and the **Music** sub-pages (`/app/music/artists`, `/app/music/artist/:name`,
  `/app/music/album/:name`, `/app/music/tracks`, `/app/music/player`), plus nav links (Music, Books,
  Audiobooks, Photos, Search). The passkey/WebAuthn surface is now a **Security** tab on the SPA Settings
  page (`/app/settings/security`) rather than a standalone page. **Update:** these `/app` pages are now
  verified live and the equivalent Smarty SSR templates + routes have since been **deleted** (see the
  Smarty-retirement entries under *Changed* / *Removed* above); the legacy page paths now 302-redirect to
  `/app`.
- **SV-3.3 — loudness normalization + client capability negotiation.**
  - `config/ffmpeg.php` gains a `loudness` block (`enabled` defaults to **false**) that, when enabled, applies an EBU R128 `loudnorm` audio filter (`I`/`LRA`/`TP`) to **re-encoded** audio on every segment-assembly path (software, hwaccel, and audio-only). **Copy-audio rungs** (e.g. the `original` variant, `-c:a copy`) and **direct-play sessions bypass loudness normalization by design** — you cannot filter a stream that is copied rather than decoded (documented in the config block).
  - The `X-Phlix-Client-Capabilities` request header (a JSON codec-support map, e.g. `{"eac3":false}`) is now honored on the `/playback-info` `direct_play` verdict: a client that declares it cannot decode the item's audio codec is steered to transcode instead of direct play. Absent/empty/invalid header → `direct_play` stays `true` (backward compatible). The verdict keys on the same first (lowest-`stream_index`) audio stream the transcode copy-vs-encode decision uses, so the two agree.
- **SV-1.9 — configurable segment-cache disk-space threshold.** New `hls.min_disk_space_bytes` config key (env `HLS_MIN_DISK_SPACE_BYTES`, default **500 MiB**). When free space on the segment-cache directory falls below the threshold, the server sweeps the cache and returns **`503`** with `Retry-After: 3` instead of failing an encode with `ENOSPC`.
- **Plugin test credentials endpoint.** New `testCredentials` endpoint in `PluginAdminController` allows the admin UI to verify whether plugin credentials (e.g. API keys) are valid before saving them.
- **Plugin `redirect_url` support.** `PluginLoader` now reads and validates the optional `redirect_url` field from plugin manifests, enabling OAuth-style callback URLs in first-party plugins.

### Fixed

- **WS-D — phpstan cast in `WebPortalRouter::getMusicTracks()`.** The music-tracks row mapper cast a
  `mixed` id straight to `string`; it now guards with `is_string`/`is_scalar` before casting (empty string
  otherwise). Behavior is unchanged for the real (string) rows; the fix clears the level-9 error surfaced
  when wiring up the newly-reachable `/app/music/tracks` page.
- **PHP 8.5 compatibility — removed deprecated no-op `curl_close()` / `imagedestroy()` calls.** Both have been no-ops since PHP 8.0 and emit `E_DEPRECATED` under PHP 8.5, which surfaced as fatal log-write failures during TV-series metadata matching (16 `curl_close()` sites across 13 files; 19 `imagedestroy()` sites in `ArtworkStorage`/`AvatarStorage`/`PhotoController`). Behavior is unchanged — the calls did nothing. (`finfo_close()` was already removed previously.)
- **Admin Logs page — removed a redundant `[LEVEL] <datetime>` prefix** that ~37 Monolog call sites (across 6 worker/handler files) hand-built into their message strings. Monolog's `LineFormatter` already emits `[<iso8601>] <channel>.<LEVEL>: <message>`, so the hand-built prefix doubled the level and timestamp on every rendered line; each record is now a single, clean level/timestamp. (`error_log()` sites are untouched — there the prefix is the only metadata.) The client-side Logs renderer overhaul ships in `@phlix/ui` v0.82.0.
- **Router `HEAD` fallback for static-only `GET` routes.** A `HEAD` request now correctly falls back to a purely-static `GET` route even when no parametric `GET` routes are registered (previously the fallback could 404 or warn on a null iteration).
- **Plugin-safe `LoggerInterface` binding fix.** Added `LoggerInterface::class` as an alias to `StructuredLogger::class` in `CoreServicesProvider` so plugins that request `Psr\Log\LoggerInterface` from the container resolve correctly instead of failing to autowire.
- **SV-4.16 — JWKS endpoint 500 on operator-supplied Ed25519 keys.** `GET /.well-known/jwks.json` returned an unhandled **500** whenever `config/hub-server-key.pem` was a standard PKCS#8 Ed25519 key (`-----BEGIN PRIVATE KEY-----`, as produced by `openssl genpkey -algorithm Ed25519`) — `Ed25519KeyManager::parsePem()` only accepted the app's custom `-----BEGIN ED25519 PRIVATE KEY-----` label wrapping a raw libsodium secret. The reader now accepts **both** formats: for PKCS#8 it validates the Ed25519 OID (`1.3.101.112`), extracts the 32-byte seed, and expands it via `sodium_crypto_sign_seed_keypair()` to the 64-byte secret the rest of the code expects (non-Ed25519 PKCS#8 keys are rejected at the OID gate; the native format parses exactly as before). The writer (`buildPem()`) still emits the native format — no key rotation or identity change. As defense-in-depth, `HubClient::getPublicKeysJwk()` now catches any key-load failure, logs it at **ERROR**, and serves a valid RFC 7517 `{"keys":[]}` at HTTP **200** instead of letting the exception escape as a 500 (the `429` rate-limit path is thrown before this and is unaffected).
- **item-5+ — WebSocket connection metrics recorded the loopback proxy IP and invented phantom rows.** The `:8097` S2 connection metrics now record the resolved **real** client IP (derived like the auth surfaces, not the loopback HAProxy peer). Additionally, connections that fire a bare-TCP `onConnect` but never complete the WebSocket handshake (health checks, port scanners, aborted upgrades) no longer produce a phantom `metrics_connections` row mislabeled `kind=http` with a null IP: the server now only touches (via `onClose` and the periodic touch timer) connections it actually opened after the handshake.
- **item-5a — noisy per-segment hardware-accel diagnostics.** The per-segment `[HWACCEL_DEBUG]` transcode logs were emitted at **ERROR** and carried a per-call `debug_backtrace()`; they are now logged at **debug** with the backtrace dropped, so normal hardware-accelerated playback no longer floods `error.log`.
- **item-5c3 — enabled plugins do not re-attach their event listeners after a restart (still OPEN — a first fix was reverted).** `PluginLoader::bootstrapEnabled()` exists but is never called in any resident boot path, so PSR-14 event listeners (e.g. Trakt PUSH scrobbling on `PlaybackStarted`/`PlaybackStopped`) go dead after every restart until a manual re-enable. A first attempt wired `bootstrapEnabled()` into the HTTP + relay `onWorkerStart` (commit `f645edbc`); it was **reverted** (`f3c57fd8`) because several plugins' `onEnable()` perform **blocking network I/O at boot** (e.g. an anidb titles fetch), which stalled worker startup and, combined with a logger-write cascade, left workers half-initialized (surfacing as intermittent request failures on deploy). The correct fix must run plugin bootstrap **without blocking worker startup** (deferred/async, or making `onEnable()` non-blocking) — tracked as a follow-up. The single-shot CGI/FPM `public/index.php` path is unaffected (throwaway per-request container).

### Removed

- **§6 dead-code removals (user-approved).** Removed confirmed-dead code paths with zero runtime callers (verified by repo-wide `grep`/`git grep` before deletion):
  - *Run A:* `FfmpegRunner::killSegmentProcess()`; the `HwaccelCommandBuilder` class and `HwaccelProfileFactory::createCommandBuilder()`; the never-wired webhook async island `WebhookDispatcher::dispatchAsync()` + `sendToWebhookWithBackoff()`; the dead duplicate `src/Server/Http/Controllers/ArtworkController.php` (the live artwork path is `HttpHandler::serveArtwork`); and unused `RequestContext` members (`clearUserId()`, `hasProfileId()`, `clearProfileId()`). The live Hwaccel registry/profile/probe system and `WebhookDispatcher`'s live `dispatch()`/`sendToWebhook()` path are untouched.
  - *Run B:* the dead server gapless/crossfade subsystem — `FfmpegRunner::buildGaplessSegmentCommand()`, `GaplessTranscoder`, `CrossfadeGenerator`, `GaplessPlayer`, and the now-orphaned members of `GaplessPlaybackManager` (`getPlayer()`, `getCrossfadeGenerator()`, `buildCrossfadeCommand()`, `isCrossfadeEnabled()`, `isGaplessEnabled()`, `clearPlayer()`, and later the caller-less `clearCache()`). Gapless/crossfade playback is implemented **100% client-side** in `@phlix/ui` (`useMusicPlayer.ts`), which superseded the reserved server path. `GaplessPlaybackManager` itself is kept — its live `getPreferences()` surface is still used by `MediaItemController`.

### Changed

- **Test-coverage hardening.** Added missing behavioral tests across SV-0.6 (TMDB collections UUID handling), SV-0.7 (marker/intro-detection worker supervision), SV-1.8 (CSRF Origin exact-match), SV-1.9 (ENOSPC guard), SV-4.8 (Router static-map fast path + DI string-handler resolution), and SV-4.12 (stale-job reaper glob), plus the SV-3.3 and SV-4.15 features above — closing "green-but-untested" gaps found during re-audit. No behavior change beyond the fixes listed above.
- **Plugin catalog pinned to v2.1.8.** Bumped `OFFICIAL_PINNED_REF` (the tag of `detain/phlix-plugins` the admin **Plugins** catalog resolves against) to **v2.1.8**, shipping the trakt/musicbrainz/anilist PHP-8.5 `curl_close()` fixes to the catalog listing.

## [SV-0.2] — 2026-07-10

**Reconciled hardware acceleration config sources.** Consolidates conflicting hwaccel settings from multiple config files into a single source of truth.

### Changed

- **`config/hwaccel.php`** is now the single source of truth for hardware acceleration settings, providing the `HwAccelConfig` class with a `get()` method that merges base hwaccel settings with transcoding-specific settings
- **`HwAccelConfig::get()`** merges `config/hwaccel_base.php` (enabled, prefer_hardware, vendor_priority, probe_timeout, test_clip_path, fallback_to_software) with `config/transcoding.php` settings (tone_mapping_mode, preferred_accelerator, prefer_hdr_output, probe_timeout, test_clip_path, include_software_fallback)
- **`config/ffmpeg.php['hwaccel']`** is deprecated and now delegates to `HwAccelConfig::get()` for backward compatibility — runtime code should use `\Phlix\Config\HwAccelConfig::get()` directly
- No more contradictory `enabled` flags between config sources at runtime

## [1.2.2] — 2026-07-10

### Changed
- **Admin SPA bundle rebuilt against `@phlix/ui` v0.79.0** (`web-ui/` pin bumped `v0.78.0`→`v0.79.0`; committed `public/assets/app/` regenerated). Ships the plugin configure/lifecycle UX: persistent enable-failure banner with the server's real reason, secret fields that show set/unset + length (never the value), and rendered field-help links/defaults/"optional" markers. Version `1.2.1`→`1.2.2` (PATCH — bundled asset refresh, no server API change).

## [1.2.1] — 2026-07-10

### Fixed
- **Plugin manifests may now declare per-setting `link`/`link_text` field-help keys.** Bumped `detain/phlix-shared` to `^0.19.0`, whose manifest schema adds those optional keys. Without this, a plugin that shipped a "where to get this value" link in its own `plugin.json` failed install-time validation as "manifest is invalid" (the settings-entry schema is `additionalProperties:false`). The configure form already rendered such links via the server-side overlay; now they can travel in the manifest too.

### Changed
- **Official plugin catalog pin advanced `v2.1.2` → `v2.1.3`** (`CatalogSourceResolver::OFFICIAL_PINNED_REF`): all 8 first-party plugins are repinned to their releases that pair with the 1.2.0 plugin-enable fix (lastfm 1.1.0, opensubtitles 0.2.0, anidb 0.3.0, myanimelist 0.2.0, omdb 0.2.0, trakt 1.2.0, anilist 0.2.0, musicbrainz 0.2.0), so the admin Plugins section installs/updates the enableable versions.
- Version bumped `1.2.0`→`1.2.1` (PATCH).

## [1.2.0] — 2026-07-10

**Plugin enable + configure fixes.** Fixes the long-standing bug where installed plugins could not be enabled and never received their saved settings.

### Fixed

- **Plugins now receive their persisted settings when enabled.** The loader instantiates each plugin entry class through the PSR-11 container (autowiring), then — this is the new part — calls the plugin's settings hook with the persisted `settings_json` map **before** `onEnable()`. A plugin opts in by implementing the new `Phlix\Shared\Plugin\ConfigurableInterface` (`configure(array $settings): void`, phlix-shared v0.18.0) or, transitionally, by exposing a public `configure(array $settings)` method. Previously nothing delivered settings to a plugin instance, so an enabled plugin ran with empty configuration (e.g. OMDb threw "API key not configured" even when a key was saved).
- **Plugins whose constructor requires the settings array can be enabled again.** A plugin whose entry constructor takes the settings array as a required parameter (e.g. `__construct(array $settings)`, as `phlix-plugin-anidb`/`-myanimelist` did) cannot be autowired — PHP-DI cannot guess an `array` value, so `container->get()` threw and enable failed with `plugin.enable.failed`. `PluginLoader` now catches that and retries via the PHP-DI factory, binding the persisted settings to the first `array`-typed required constructor parameter, so such plugins enable without a re-release. When the fallback does not apply (e.g. a scalar-first constructor) the real PHP-DI resolution message is surfaced verbatim in the `PluginEnableException` so the operator sees the actual reason.

### Added

- **Secret settings now report set/unset + length to the configure UI.** `GET /api/v1/admin/plugins/{name}` gains a `secret_status` map (`{ key: { set: bool, length: int } }`) alongside the masked `settings`. Every secret still masks to `***` in `settings` (the value is never sent), but `secret_status` lets the admin UI show whether a secret is actually stored and render a length-appropriate cue — so a saved key is distinguishable from an empty one, and a save is visibly confirmed. `SettingsMasker::secretStatus()`.
- **Server-side plugin field-help overlay.** New `config/plugin_field_help.php` + `Phlix\Plugins\PluginFieldHelp` merge curated labels, richer descriptions, and "where to get this value" links (`link`/`link_text`) over each plugin's manifest settings schema in the configure endpoint, so already-installed plugins show the improved help immediately without waiting for a plugin update. `SettingsMasker::schema()` also passes through `link`/`link_text` when a manifest declares them, so enriched manifests carry the same help natively.

### Changed

- Bumped `detain/phlix-shared` to `^0.18.0` (adds `ConfigurableInterface`).
- Version bumped `1.1.0`→`1.2.0` (MINOR — new backward-compatible functionality; no server↔hub wire-compatibility impact).

## [1.1.0] — 2026-07-08

**Stream Quality + Adaptive Bitrate (ABR) release.** Ships the full server-side pipeline built across this program's Tracks A, S, and D: a source-clamped multi-variant HLS ladder with on-demand per-variant segment encoding and genuine stream-copy passthrough for compatible sources (A1-A7), a client-facing `variants[]`/`quality_ladder` API surface, a batch of performance work spanning job-row/facet caching, streamed segment serving, non-blocking/parallel scan probing, materialized sort/genre indexes, and a validated coroutine DB connection pool (S1-S10), a genre-index risk redesign after a real InnoDB issue surfaced under stress (S7b), and hub proxy improvements for streaming paths (D1-D4). See the sections below for the full accumulated detail. Version bumped `1.0.0`→`1.1.0` (MINOR — entirely new, backward-compatible functionality; no server↔hub wire-compatibility impact). Also fixes a real drift bug found while preparing this release: the `/health` and `/system/info` endpoints hardcoded a stale `'1.0.0'` literal independently of `Phlix\Common\Version::STRING` (the documented single source of truth) — both now reference the constant directly, so a future version bump can no longer silently miss these two endpoints.

### Removed

- **Dead blocking/legacy linear-transcode path removed from `TranscodeManager`/`FfmpegRunner` (Stream Quality/ABR step S10, Track S — pure cleanup, no behavior change; Track S is now FULLY COMPLETE, S1-S10).** The on-demand, seek-aware HLS/ABR pipeline built across A1-A7 and S1-S9 fully superseded the original blocking, single-linear-encode transcode path; this step deletes the now-zero-caller remainder rather than leaving it to bit-rot alongside the live code.
  - **`TranscodeManager`** (2800 → 2467 lines): removed `startTranscode()` (the blocking linear entry point) and its `$activeJobs` in-memory registration/gate, `stopTranscode()`, `cleanupStaleJobs()`, `getActiveTranscodeCount()`, `getMaxConcurrentTranscodes()`, `getTranscodeStatus()`, `normalizeSourceInfo()`, and `normalizeProfile()` (a distinct method from the still-live `QualitySelector::normalizeProfile()`, untouched) — all confirmed to have zero real callers repo-wide, reachable in practice only via test reflection. The `$activeJobs` map itself, plus the unrelated `$globCache`/`GLOB_CACHE_TTL` static glob-memoization it required, are gone too. The still-live on-demand concurrency gate (`$maxConcurrentTranscodes` property + `getRunningJobCount()`) is unaffected.
  - **`FfmpegRunner`** (1495 → 1426 lines): removed `transcode()`, the blocking `proc_open()` + `stream_get_contents()` method that was `startTranscode()`'s only caller. `buildTranscodeCommand()` — public, independently tested, and referenced by `SoftwareProfile.php` as a behavioral-parity contract — was deliberately left fully untouched.
  - **`TranscodeManager`'s constructor lost its `EncodingHelper $encodingHelper` and `string $transcodeDir` parameters** (previously 2nd/3rd positional args) — an internal, DI-resolved constructor, not a public or wire-facing API, so this carries **no client/API impact whatsoever**. Both params became write-only (unread by any surviving method) once `startTranscode()` — their sole reader — was deleted; removing them was forced by static analysis rather than optional tidying. All 5 real construction sites (the DI provider plus 4 test constructors) were updated to match.
  - **`ItemRepository::getExcludingGenres()`** removed (2409 → 2360 lines) — pre-existing dead code (zero callers repo-wide) flagged with an `@todo S10: DELETE` note back in step S7, and carrying its own long-standing param-count bug (a computed `$genrePlaceholders` string that was never interpolated into the query, so the bound values silently over- or under-matched blocked genres). No behavior was relied upon; no test covered it.
  - The in-worker job-row cache's `invalidateJobRowCache()` call-site count drops from 5 to 3 (completion, legacy-failure, reap) now that `startTranscode()`'s and `stopTranscode()`'s sites are gone with them; the `$jobRowCache` and `invalidateJobRowCache()` docblocks were updated to match. One test, `testCancelInvalidatesCachedJobRow()`, was removed since none of the 3 surviving call sites is a behavioral analog for "cancel" — the other 3 sites already have dedicated regression tests.
  - **Deliberately left untouched, so a future reader doesn't mistake completeness gaps for oversights:** `HlsStreamer`/`DashStreamer` and the CMAF `Dash/` subtree — confirmed live (LiveTV relay + DLNA), not the dead code the original cleanup plan assumed; the `chunk-*.m4s` glob inside `countSegments()`/the reaper — kept for backward compatibility with on-disk segment artifacts from pre-rewrite jobs; `FfmpegRunner::buildTranscodeCommand()` — kept as the documented `SoftwareProfile.php` parity contract, with its own dedicated tests; and `Application.php`'s 7 `ConnectionPool::getConnection('mysql')` DI-bypass call sites — assessed and deliberately deferred as a materially different, higher-risk kind of change, not "nearby" to this diff.
  - **New orphan flagged for a future cleanup step (not fixed here, deliberately out of scope):** with `TranscodeManager`'s ctor no longer consuming it, the `EncodingHelper` class and its standalone DI registration are now fully unconsumed by any caller.
  - Full gate green: phpcs/phpstan level 9 clean, the full combined phpunit suite (4,858 tests) green, line coverage 62.34% — a slight *increase* over the post-S9 62.11% baseline, consistent with deleting untested/lightly-tested dead code (smaller denominator, no covered lines lost).

### Added

- **API surface now advertises the multi-variant quality ladder (Stream Quality/ABR step A7) — the client-facing contract this program has built toward since A1, and the step that closes out Track A: the entire server-side ABR pipeline (A1 source-metadata capture → A2 ladder builder → A3 schema → A4 per-rung FFmpeg encode/copy → A5 multi-variant playlists/segments → A6 variant-aware serving → A7 this API surface) is now code-complete end to end.**
  - **New `TranscodeManager::getJobVariants(string $jobId): ?array`** is the single source of truth for "what's playable for this job." It reads the persisted `transcode_jobs.variants` column (A3's schema, populated by A5) and mirrors `LadderResult::streamVariants()`'s dedup rule exactly: a genuine stream-copy "Original" is a real additional highest variant and is prepended, while a non-copy "Original" that merely duplicates the top clamped rung is **not** listed separately (nothing can request it as distinct anyway — see A6's `findRenditionArray()`). Each entry is the flat `Rendition::toArray()` shape (`id`/`label`/`width`/`height`/`bitrate`/`codecs`/`is_original`/`is_copy`/`video_bitrate`) with `url` filled to that variant's own relative, **unsigned** media-playlist path (`/hls/{jobId}/media_v{id}.m3u8`) — the first point in the pipeline any `url` is actually populated (A2 and A5 leave it `null`). Defensive against a missing/empty/corrupt `variants` column: any of those cases returns `null` (a legacy job) rather than throwing.
  - **`POST /api/v1/media/{id}/transcode` (`start()`) and `GET /api/v1/transcode/{jobId}/status`** both gain a `variants` key: the signed variant list from `getJobVariants()` — each `url` signed with the same prefix-scoped `SignedUrl` signer as `master_url`/`hls_url`, via a new private `TranscodeController::signVariantUrls()` — or an explicit `null` for a legacy `variants IS NULL` job (an explicit key, so a client can reliably branch on `!= null` rather than guess from key absence). Fully additive: no existing response key changed or removed.
  - **`GET /api/v1/media/{id}/playback-info` gains a `quality_ladder` key** — a **pre-flight preview** of the ladder a play would produce, built purely from A1's persisted `metadata_json['source']` blob (no `ffprobe` call, no transcode job created). Every entry's `url` is `null`, since nothing is playable yet — a deliberately different key from a real job's `variants[]` (per-item, not per-job). Resolves to `null` when the item lacks usable persisted source metadata (not yet scanned with A1, or missing width/height) — graceful degradation, not an error. Device-profile resolution is byte-identical to `TranscodeController::start()`: an explicit `?profile=` wins, else it is derived from the `X-Phlix-Device-Type` header via new `MediaItemController::mapDeviceTypeToProfile()` (`samsung-tizen`/`tizen`/`roku` → `tv-4k`, `android`/`ios` → `mobile-high`, `windows` → `generic`, anything else/missing → `web`); a controller test asserts the two controllers' mapping tables stay identical so the preview and the real job never disagree on profile.
  - Player-visible quality selection is still ahead — step **E3** in `@phlix/ui` and the native clients (**G4** Roku, **G5** console) — but `@phlix/contracts` (**B1**) can now mirror this exact, shipped response shape instead of a planned one.

### Changed

- **The coroutine DB connection pool (`config/database.php` `connections.mysql.pool_enabled`) is now ON by default (Stream Quality/ABR step S9, Track S — the last-but-one step of Track S, gated per the plan on the throughput/coherence audit below; only cleanup step S10 remains).** Previously every coroutine in a worker shared one physical `PhlixMySQLConnection`, serialised on that connection's own per-connection coroutine mutex — every DB round-trip in the worker, including on the hot HLS segment/playlist and job-status paths, queued behind whichever query happened to be in flight. `PooledMySQLConnection` (a `Workerman\MySQL\Connection`-shaped front that leases a real connection out of a bounded per-worker pool for the life of the current coroutine) has existed since Track S began, deliberately left `pool_enabled=false` pending exactly this validation pass — S1 (job-row cache) and S5 (genre-facet cache) both flagged their own invalidate-on-write coherence proofs as tied to the single-connection-mutex assumption and explicitly called out S9 as the point those proofs would need re-examining. This step is that re-examination, done as a genuine audit rather than a flag flip:
  - **The audit found one real coherence gap and fixed it.** `TranscodeManager`'s in-worker job-row LRU cache (S1) relied on the shared connection's mutex to guarantee a cache-miss `SELECT` and a concurrent status-write `UPDATE` could never interleave; under the pool, a reader and a writer hold *different* physical connections, so a reader's in-flight `SELECT` can now return **after** a writer has already invalidated the cache for that job — without a guard, the reader would re-poison the (TTL-less) cache with its stale pre-write row, and that wrong row (e.g. a completed job still reading back `running`) would never self-correct until the next write to that same job. Fixed with a new per-jobId monotonic invalidation epoch (`$jobRowEpoch`): `invalidateJobRowCache()` bumps a job's epoch on every state write (its 5 existing call sites are unchanged — only its internal behavior changed), and `jobRowEntry()`'s cache-miss path snapshots the epoch immediately before its `SELECT` and only populates the LRU if the epoch is still unchanged when the query returns; a race is served as a one-shot uncached read to that single caller instead of being trusted. The snapshot-compare-populate sequence has no yield point, so it stays atomic under Swoole's cooperative scheduler even though the DB query itself is no longer mutex-serialised. `distinctGenres()`'s TTL+LRU genre-facet cache (S5) was independently re-checked and needed no change: every genre write already calls its own eager `invalidateGenreFacets()` synchronously with no intervening yield, so there is no equivalent miss-vs-invalidate race window for it to fall into.
  - **`PooledMySQLConnection` closed a real delegation gap surfaced by running CI's exact phpunit invocation (not a filtered subset) against real MySQL with the pool defaulted on.** The front previously delegated only `query()`, the `*Trans()` family, `lastInsertId()`, and `closeConnection()` to the coroutine's leased connection — production `src/` code was confirmed (by repo-wide grep) to only ever call `query()`, so this had never surfaced — but `tests/Integration/Media/BrowseIndexUsageTest.php` (an S7 test) calls `->row('EXPLAIN …')`, the one primitive `query()` doesn't special-case for a row-returning statement. Left undelegated, that call fell through to the un-constructed parent `Workerman\MySQL\Connection::row()` (this front deliberately never calls the parent constructor, so it has no socket/settings) and crashed with `SQLSTATE[HY000] [2002] No such file or directory`. Now `row()`, `single()`, and `column()` delegate to the lease exactly like `query()`; the class docblock also now explains why the fluent query-builder (`select()`/`from()`/`where()`/…) is deliberately *not* delegated — its per-instance builder state is incompatible with per-coroutine leasing, and Phlix never uses that form anyway (always `query($sql, $params)`). A repo-wide inventory of every method actually invoked on a `Connection`-typed value confirms the delegated set (`query`, `row`, `single`, `column`, the `*Trans()` family, `lastInsertId`, `closeConnection`) is now complete — nothing else was missed.
  - **Real parallelism proven, not just "didn't crash."** A new real-MySQL integration test (`tests/Integration/Media/Transcoding/PooledConnectionConcurrencyTest.php`, self-skips without Swoole/reachable MySQL) measures 6 coroutines each running `SELECT SLEEP(0.20)` against a `pool_size=6` pool completing in ~0.2s total (peak in-flight = 6) versus ~1.2s at `pool_size=1` — genuine concurrent execution, not an assumption. A second test hammers the same `transcode_jobs` row with 12 readers + 6 writers using the exact UPDATE-then-invalidate shape of all 5 real call sites, across different pooled connections, and asserts no torn/cross-query values, no 2014/"commands out of sync" errors, and — the epoch guard's specific claim — that both a fresh cache read and a direct DB read converge on the final written value once writes stop (the stale-forever bug the epoch guard exists to prevent). A third test drives 36 coroutines over a 4-connection pool to exercise the blocking `acquire()` channel-pop path under exhaustion with no deadlock or leak. A standalone (non-CI-gated) soak script additionally ran 18,000 read/write ops over 161s across 3 rounds with zero errors and full convergence every round.
  - **Fallback preserved and correctly distinguishes "unset" from "explicitly off."** `DB_POOL_ENABLED=0` (or `false`/`no`/`off`) restores the single-connection mutex path (`PhlixMySQLConnection`) exactly as before this step; `pool_size` still defaults to 8 and `pool_size=1` remains a safe, fully-serialised middle ground if a smaller blast radius than a full opt-out is wanted while diagnosing. The config's boolean parsing is deliberately `getenv('DB_POOL_ENABLED') === false ? '1' : getenv('DB_POOL_ENABLED')` inside `filter_var(..., FILTER_VALIDATE_BOOLEAN)`, not the repo's usual `?: '1'` idiom — the latter would treat the *string* `"0"` as PHP-empty and silently fall through to `'1'`, re-enabling the pool and making the documented opt-out unreachable; `=== false` correctly distinguishes a genuinely-unset env var (default on) from an explicit `"0"` (off).
  - The three already-audited coroutine/workerman-mysql traps (positional-bind re-keying, forced `utf8mb4`, emulated+buffered prepares — see `PhlixMySQLConnection`) were re-verified unchanged and correctly in place under every pooled lease; this step did not touch that class.
  - Full CI-equivalent gate green with the pool on: phpcs/phpstan level 9 clean, the full combined suite (4,859 tests) green against real MySQL 8.0.46, line coverage 62.12% (no regression versus prior Track S rounds, ≥40% gate).

- **Bundled `web-ui/` SPA bumped to `@phlix/ui` v0.74.0 (Stream Quality/ABR step F1, Track F).** Brings the player-visible quality selector (`QualityMenu` wired into the control bar, Auto/discrete-rung/Original level switching via the hls.js level API, visual+a11y baselines — steps E1-E5) into the bundle the server serves at `/app/*`. No server-side code change; `web-ui/package.json`'s `@phlix/ui` pin moves `v0.73.1`→`v0.74.0` and `public/assets/app/**` is rebuilt and committed (Vite/Rollup content-hash chunk-name churn only across the whole bundle, not a regression — the non-deterministic-rebuild gotcha already documented against E5/F2).

- **`MediaScanner::scanFlat()` fans out a bounded pool of concurrent ffprobes instead of probing one file at a time, and batches the already-scanned-path lookup into a single query (Stream Quality/ABR step S8, Track S — scan-throughput perf, no API/behavior change; builds directly on S6's non-blocking `FfmpegRunner::probe()`).** S6 made a single `probe()` call non-blocking (yield to the event loop instead of freezing the whole worker), but `scanFlat()` still issued and awaited those probes strictly one file at a time — under-using the very capability S6 added, since nothing stopped several non-blocking probes from running concurrently. Every candidate file in a scan batch also paid its own `ItemRepository::findByPath()` round-trip just to check whether it was already indexed.
  - **New `ItemRepository::findPathsMap(array $paths): array`** replaces the per-file `findByPath()` check with a single `WHERE path IN (?,?,...)` query per batch, returning `[path => hydrated row]` for every path that already exists (missing paths simply absent, not a null entry); empty input short-circuits without querying. Mirrors the existing `findByIds()` pattern.
  - **New `MediaScanner::probeManyConcurrently(array $paths): array`**, gated by the same coroutine-availability guard S6's `runProbeCommand()` uses (`extension_loaded('swoole') && class_exists(Coroutine::class) && Coroutine::getCid() > 0`). Inside a real Swoole coroutine, one `Swoole\Coroutine\Channel` sized to the concurrency cap acts as a semaphore and a SECOND `Swoole\Coroutine\Channel` (sized to the batch count) acts as a "done" signal that the caller pops once per path to join every launched probe — a `WaitGroup`-equivalent join built from two `Channel`s rather than `Swoole\Coroutine\WaitGroup` itself, because PHPStan's bundled swoole stubs (used when `ext-swoole` is absent, e.g. CI's PHPStan job) have no `WaitGroup` stub and fail `analyze --level=9` on it. A probe failure (thrown exception, or `Coroutine::create()` itself refusing to schedule under Swoole's `max_coroutine` ceiling) resolves that one path to `null` and releases its slot/signals done without stranding the pool or aborting siblings. Outside a coroutine (PHPUnit CLI, plain CLI scan scripts) it falls back to the exact pre-S8 sequential probe-per-path loop — behaviorally identical to before S8 in that context.
  - **New config knob `config/ffmpeg.php` → `max_concurrent_scan_probes` (default 4)**, wired through `MediaServicesProvider` into a new `MediaScanner` constructor parameter, mirroring the existing `max_concurrent_transcodes` knob's style/placement. `scanFlat()`'s directory walk is restructured into fixed-size batches (`SCAN_BATCH_SIZE = 200`, deliberately smaller than `DuplicateFinder`'s 500 since each candidate here may hold open a coroutine-pool probe slot) — concurrency is capped independently of batch size, and confined entirely to the read-only, DB-free ffprobe step; the create/dedup/`persistStreams` sequence for each file still runs sequentially, in original candidate order, exactly as before.
  - **Deliberately scoped to `scanFlat()` only — `scanSeriesPerDirectory()`/`scanSeriesDir()` (episode/series directory scans) are left fully sequential, not an oversight.** Those code paths can create a *shared* parent series/season row via `resolveEpisodeParent()` when two files in the same batch are the first to reference that container; making that path concurrent is a genuinely different (find-or-create race) problem than fanning out read-only probes, and is left for a dedicated future step. Documented in `scanFlat()`'s docblock and as an explicit scope note directly in `scanSeriesDir()`'s docblock.
  - Test coverage: +9 tests (`MediaScannerTest`: sequential-fallback-outside-coroutine regression, bounded-concurrency proof via a live in-flight counter, correct per-path result attribution under concurrency, single-probe-failure isolation, the `Coroutine::create()`-refusal edge case (mutation-tested — the fix's absence provably deadlocks), and the `processFile()` precomputed-probe plumbing; `ItemRepositoryTest`: `findPathsMap()`'s empty short-circuit, single-query/placeholder shape, and result keying).

- **Genre filtering moves off the MySQL 8 multi-valued functional index (MVI) introduced by S7's migration 050 onto a normalized `media_item_genres` join table (Stream Quality/ABR step S7b, Track S — risk-driven redesign, no API/behavior change; supersedes only the genre-index portion of S7 — `sort_title`/`content_rating` and their indexes are untouched and were never implicated).** A dedicated stress test (50 rounds × 300 rows = 15,000 rows of create/cascade-delete churn against a prod-version-matched MySQL 8.4.10, modeling ~50 consecutive full rescans of a 300-item library) proved the risk this program's plan had flagged as needing empirical validation was real, not log-only noise: **29,900** `[MY-012869]` InnoDB purge-thread "record not found on update" errors, recurring continuously across 58 distinct one-second buckets spanning the entire run — up from 73 errors in a single small CI round — scaling with churn volume rather than converging to a fixed benign count. That fails the bar for "contained," so the MVI is replaced rather than accepted.
  - **New migration `migrations/051_media_item_genres_join_table.sql`** creates `media_item_genres (media_item_id, genre)` — `PRIMARY KEY (media_item_id, genre)`, `INDEX idx_media_item_genres_genre (genre)`, `FOREIGN KEY (media_item_id) REFERENCES media_items(id) ON DELETE CASCADE` — idempotently backfills it from every existing row's `metadata_json.$.genres` via `INSERT IGNORE ... JSON_TABLE(...)`, then drops `idx_media_items_genres` (the MVI). `metadata_json.$.genres` remains the single canonical source of truth (API responses and `MediaItemShaper` still read it directly, unchanged); the join table is a derived index only, kept in sync by `ItemRepository::insertGenreRows()` (INSERT-only, from `create()`) and `syncGenreRows()` (DELETE-then-insert, from `update()`).
  - **Read paths rewritten from `MEMBER OF`/`JSON_TABLE` to joins/`EXISTS` against the new table:** `getByAllowedGenres()` and `buildFilters()`'s genre predicate now use a correlated `EXISTS (SELECT 1 FROM media_item_genres ...)` (preserving the "allowed genre OR item has no genres at all" semantic), and `distinctGenres()` (S5's TTL+LRU-cached facet scan) now does a plain `JOIN` read instead of unnesting `JSON_TABLE` — the cache-miss SQL changed, the cache mechanism itself did not.
  - **Deliberate case-sensitivity split, not an oversight.** `media_item_genres.genre` is declared `VARCHAR(255) COLLATE utf8mb4_bin` specifically so the filtering predicates (`getByAllowedGenres()`/`buildFilters()`) keep the exact case/accent-**sensitive** exact-match semantics the old `MEMBER OF` predicate had — a plain `_unicode_ci` column would have silently loosened filter matching (caught in review by direct empirical comparison against real MySQL: `'action' MEMBER OF (...)` vs `Action` stored → no match under the old code; `WHERE genre IN ('action')` against a `_unicode_ci` column → a false match). `distinctGenres()` is the one deliberate exception: its facet-list query re-asserts `COLLATE utf8mb4_unicode_ci` explicitly on the selected/ordered column so the returned facet list stays case-insensitive-deduplicated exactly as it was pre-051 (e.g. `"Action"`/`"action"`/`"ACTION"` on the same item still collapse to one facet), independent of the now-case-sensitive storage/filter collation. Neither query-time `COLLATE` override touches the filtering `EXISTS`/`IN` predicates, so `idx_media_item_genres_genre` stays fully index-usable (verified via `EXPLAIN` on real MySQL 8.0.46 and 8.4.10 — no full scan either before or after).
  - `getExcludingGenres()` (pre-existing dead code, S10-owned) left functionally untouched; only its `@todo` reworded since it no longer references the removed `MEMBER OF` form.
  - `scripts/backfill-sort-metadata.php`'s genre-related comment corrected (comment-only) — it previously described the now-removed MVI as auto-deriving genre indexing; genres are now covered by migration 051's own idempotent backfill plus the write-path sync above, with no PHP CLI equivalent needed.
  - Test coverage: `BrowseIndexUsageTest` rewritten to assert (via real `EXPLAIN`) that the rewritten `EXISTS` genre-membership query resolves against `idx_media_item_genres_genre`/`PRIMARY` with no full scan, empirically verified against both MySQL 8.0.46 (CI's pinned image) and 8.4.10 (prod's version); +9 tests overall covering the case-sensitivity/collation split, `insertGenreRows()`/`syncGenreRows()` write-path sync, and the rewritten filter/facet SQL shapes.
  - Re-running this step's own stress harness against the new join-table design (rather than the MVI) is the closing condition for **I3**, the deploy step gated on this redesign shipping clean.

- **Library browse/filter listings materialize the article-stripped sort key and content rating into indexed columns, and genre filtering moves onto a MySQL 8 multi-valued functional index (Stream Quality/ABR step S7, Track S — pure performance/hardening work, no API/behavior or ordering change; pairs with S5's genre-facet cache and S1's job-row cache). Note: the genre-index portion of this step was superseded by S7b above after a stress test proved it carries real InnoDB purge-thread risk under sustained churn — `sort_title`/`content_rating` (the rest of this entry) are unaffected and remain current.** Every library listing previously ordered `ORDER BY <SortTitle::sqlExpression('name') CASE/LOWER/SUBSTRING>` — a per-row function MySQL can never satisfy from an index, forcing a filesort on every page load — and genre/rating filters ran `JSON_CONTAINS(metadata_json, ?, '$.genres')` / `JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.rating'))`, neither of which is index-usable, so both full-scanned `media_items`.
  - **New migration `migrations/050_media_items_sort_indexes.sql`** adds two nullable columns — `sort_title VARCHAR(255)` (the article-stripped sort key) and `content_rating VARCHAR(32)` (copied out of `metadata_json.$.rating`, named distinctly from the per-user `user_item_data.rating` to avoid ambiguity) — plus a composite index `(library_id, type, sort_title, name)` (a superset of the plan's `(library_id, type, sort_title)`: the trailing `name` removes the residual filesort the stable-paging tiebreak would otherwise force; verified with EXPLAIN — `Extra` is filesort-free) and a single-column `(content_rating)` index for rating filters/parental-control range scans. The migration also backfills both columns for existing rows inline (guarded `IS NULL`, so a re-run or a later run of the new CLI below is a no-op) via the exact CASE `SortTitle::sqlExpression()` emits, so historical ordering is reproduced byte-for-byte, not just approximated.
  - **Genres deliberately stay inside `metadata_json.$.genres` — no new join table.** Instead, the migration adds a MySQL 8.0.17+ **multi-valued functional index** (`ADD INDEX ((CAST(metadata_json->'$.genres' AS CHAR(255) ARRAY)))`), and `ItemRepository`'s genre filters (`buildFilters()`, `getByAllowedGenres()`) are rewritten from `JSON_CONTAINS` to `? MEMBER OF (metadata_json->'$.genres')` — index-resolvable, same case/accent-sensitive exact-match semantics as before. This was chosen over a normalized join table specifically to keep **S5's genre-facet cache coherent**: `distinctGenres()`'s `JSON_TABLE` facet scan (and its `invalidateGenreFacets()` TTL+LRU cache) already reads genres from this exact blob, so a join table would create a second genre-storage location that every genre write would need to keep in sync — and that S5 wiring stays completely untouched by this step. No genre backfill is needed either: the index is derived automatically from the existing blob.
  - **`ItemRepository::create()`/`update()`** now populate `sort_title` (via `SortTitle::from()`, whose output is branch-for-branch identical to the retired runtime `SortTitle::sqlExpression()`) and a new `extractContentRating()` helper populates `content_rating` on both the write path and the new backfill CLI, so live writes, the CLI, and the migration's inline backfill can never drift. `update()` mirrors the existing `canonical_key` lockstep pattern: a `name` change re-derives `sort_title`, a `metadata_json` (re)write re-derives `content_rating`, and an explicit caller-supplied value for either column always wins. The runtime `SortTitle::sqlExpression()`/`letterSqlExpression()` calls are fully removed from the query path (`titleOrder()`, the new private `letterExpression()`, `letterCounts()`, `valueBuckets()`) in favor of reading the materialized columns.
  - **New offline CLI `scripts/backfill-sort-metadata.php [--library=<id>] [--limit=<n>]`** — matches step A1's established backfill-script pattern (`updated`/`skipped`/`failed` buckets, skip-if-already-consistent, non-zero exit on any failure) — for populating `sort_title`/`content_rating` on rows that predate this migration on an instance where the inline migration backfill hasn't (yet) run, or was interrupted.
  - **Deploy caveat — read before running migration 050 on a live box.** Unlike this migration's other statements, the multi-valued genre index's `ADD INDEX` is **not** covered by `MigrationRunner::isExpectedIdempotentError()` and will **hard-fail the whole migration** if any existing row has a genre element longer than 255 characters or a `$.genres` value that isn't a JSON array. The migration's leading comment carries a "DEPLOY RUNBOOK NOTE" with copy-paste pre-flight SQL to detect and clean up any such rows before migrating; **the I3 deploy step must read and follow it.**
  - `getExcludingGenres()` — confirmed dead code repo-wide, and the only remaining un-indexed `JSON_CONTAINS`-based genre path — was left unmigrated to avoid scope creep into its pre-existing param-count bug; flagged with an `@todo` for a future cleanup pass.
  - Test coverage: unit coverage on `extractContentRating()`'s shape handling (array/string/missing/malformed), the create/update materialization write paths (mutation-tested), and the rewritten rating/genre SQL; a new self-skipping integration test (`tests/Integration/Media/BrowseIndexUsageTest.php`, mirrors `SortTitleOrderingTest`'s convention) asserts via `EXPLAIN` that the composite index is applicable with no filesort and the genre `MEMBER OF` predicate resolves against the multi-valued index.

- **`FfmpegRunner::probe()` no longer stalls the whole worker, and `ensureHlsJob()` reuses A1's scanned source metadata instead of re-deriving it from a live probe (Stream Quality/ABR step S6, Track S — pure performance/hardening work, no API/behavior change; pairs directly with A1's source-metadata capture).** Previously `probe()` executed ffprobe via a plain `shell_exec()`. The server's coroutine hook mask (`SwooleRuntime::SAFE_HOOK_NAMES`) is a deliberate **allowlist**, not a blocklist, and does not hook `proc_open`/`exec`/`shell_exec` — so that blocking call froze the *entire* Workerman worker (every concurrent connection) for the full duration of the ffprobe process. `probe()` sits on two hot paths: `TranscodeManager::ensureHlsJob()` (every play-start of a non-direct-play title) and the library scanner (per scanned file), so under real load this was a worker-wide stall on every play button press.
  - **Non-blocking dispatch (the core fix).** New private `FfmpegRunner::runProbeCommand()` runs the ffprobe command via `Swoole\Coroutine\System::exec()` whenever it is called inside a genuine coroutine (`Swoole\Coroutine::getCid() > 0`, matching the repo's established idiom) — a native coroutine primitive that yields to the event loop while ffprobe runs, so other connections keep being served, and which works regardless of the curated hook mask (chosen specifically because `proc_open` is *not* hooked here). Outside a coroutine — the CLI scanner/backfill, unit tests, any non-Swoole runtime — it falls back to the original blocking `shell_exec()`, which is correct there since there is no event loop to stall. Both paths run the command through `/bin/sh -c`, so the existing `escapeshellarg()` quoting and `2>/dev/null` redirect behave identically; `probe()`'s JSON parsing and public return shape are byte-for-byte unchanged. This alone benefits every caller of `probe()` (`ensureHlsJob`, the scanner, `SubtitleController`, `TrailerFinder`, `ThemeMediaFinder`, `TrickplayGenerator`, the legacy `startTranscode`), not just the two named hot paths.
  - **Skip the probe as source-of-truth when scanned metadata is fresh.** New `TranscodeManager::sourceMetadataFresh()`/`persistedDurationSeconds()` helpers check whether A1 already persisted real source dimensions (width + height > 0, mirroring the existing `sourceProfileForItem()` gate) and a positive `metadata_json['duration_seconds']`. When fresh, `ensureHlsJob()` takes the source duration straight from the scan — skipping both the probe-derived duration and the now-redundant idempotent `persistProbedDuration()` write — and the ABR ladder is already built from the persisted profile via the pre-existing `sourceProfileForItem()` preference. Crucially, a probe **failure** is now tolerated on this path: previously any `null` probe result threw `"Failed to probe media file"` and refused to play the title; now that failure only degrades the request (no embedded-subtitle sidecars for that request) when the scan already described the source well enough to build the job without it. Without fresh persisted metadata, a `null` probe still throws exactly as before.
  - **Known, deliberately scoped residual: the probe call is still issued even on the fresh-metadata path.** A1 persists only the video + primary-audio summary (`metadata_json['source']` / `media_streams`) — never subtitle stream descriptors — so embedded TEXT-subtitle detection (`SubtitleExtractor::detectTextTracks()`) still needs the live ffprobe stream list. Skipping the probe outright would silently drop embedded subtitles on the HLS path for backfilled items, which is unacceptable, so the probe is retained but is now (a) non-blocking and (b) no longer the source of truth for the ladder/duration and no longer a hard dependency when metadata is fresh. Fully eliminating this residual probe would require persisting subtitle-track descriptors at scan time (touching A1's persisted shape) — left as a small, well-scoped follow-up rather than reopening the twice-reviewed A1 surface here; the code carries an explicit warning comment at the `detectTextTracks()` call site so a future change doesn't remove the probe before that persistence lands.
  - Test coverage: `FfmpegRunnerTest` covers the non-coroutine `shell_exec()` fallback (JSON parse, null-on-missing-binary, null-on-non-JSON-output) and a real-coroutine test (`Swoole\Coroutine\run()`) that exercises the coroutine-exec branch itself — the actual "no worker-wide stall" mechanism, mutation-tested to confirm it genuinely drives that branch rather than silently degrading to the fallback. `TranscodeManagerTest` covers the fresh-metadata duration/ladder skip (persisted value wins over a stale probe value, no redundant UPDATE issued), probe-failure tolerance on the fresh path, and the preserved throw-on-failure behavior when metadata is not fresh.

- **`ItemRepository::distinctGenres()` is now backed by an in-worker TTL+LRU cache (Stream Quality/ABR step S5, Track S — pure performance/hardening work, no API/behavior change; pairs with library work and, per the plan, is a prerequisite for S9's connection-pool validation).** Previously every genre-filter-UI load (`GET /api/v1/media/facets` → `WebPortalRouter::getMediaFacets` → `distinctGenres()`) unnested `metadata_json.$.genres` via a `JSON_TABLE` full scan of `media_items`, even though the genre set only changes when items are scanned or edited.
  - A bounded map (`private array $genreFacetCache`, `GENRE_FACET_CACHE_MAX = 256` entries) caches each `{genres: list<string>, expires_at: int}` result keyed by scope — a library UUID for a scoped call, or the `GENRE_FACET_GLOBAL_KEY` sentinel (`"\0all-libraries"`, chosen so it can never collide with a real UUID) for the unscoped/all-libraries call. TTL is `GENRE_FACET_CACHE_TTL_MS = 300_000` (5 minutes), measured against a new `monotonicMs()` helper (`hrtime(true)`-based, mirroring `TranscodeManager::monotonicMs()` and the repo's documented monotonic-clock convention) so the cache is immune to NTP/DST clock jumps. A fresh hit returns with zero DB access; a miss or expired entry falls through to the unchanged `JSON_TABLE` query and repopulates the cache.
  - **The 256-entry bound is a security control, not a tuning knob.** `WebPortalRouter::getMediaFacets` passes the raw, unvalidated caller-supplied `?libraryId=` query parameter straight into the cache key, and `ItemRepository` is a shared, per-worker-resident singleton (PHP-DI `autowire()`) — so the scope-key space is attacker-influenced. Without a bound, an authenticated caller could force unbounded resident-memory growth by cycling through fabricated library ids. Eviction is **genuine LRU, not FIFO**: both the cache-hit path and the recompute/populate path `unset()` the existing key immediately before reassigning it, which is required because PHP does not otherwise reposition an existing array key on a plain value reassignment — so the coldest, least-recently-*read* scope is evicted at the bound (`array_key_first()`), not merely the least-recently-*written* one. (An earlier round of this change got the recompute-path repositioning wrong — see the regression test below — and review/independent test-engineering passes closed it.)
  - **Invalidation** is wired into every write path in `ItemRepository` that can change the genre set, via a new public `invalidateGenreFacets(?string $libraryId = null)`: `create()` invalidates that item's library scope (`batchCreate()` is covered transitively, since it calls `create()` per item); `update()` invalidates globally (`null`, flushing every scope) but **only** when `metadata_json` is among the written fields — a metadata-free update (e.g. a rename) leaves the cache warm; `delete()` invalidates the item's library scope (or globally, when the library isn't known at the call site); `deleteByLibrary()` invalidates that library's scope. Any library-scoped invalidation also drops the global/all-libraries scope, since it spans every library's genres. A `null` argument always flushes the whole map. Cross-worker note: a scan running in a different worker process cannot reach this worker's in-memory map, so a scanner-driven genre change surfaces to a facet-serving worker after at most one TTL window (5 minutes) — this is the documented purpose of the TTL, not a gap; same-worker writers never observe their own stale cache thanks to the eager invalidation calls above.
  - Test coverage: 9 new unit tests cover cache-hit/miss behavior, independent per-scope caching, every invalidation call site (including the metadata-changed-vs-not `update()` branches), global-vs-library scope invalidation, and the exact LRU eviction-at-bound and stale-recompute-repositioning behaviors (both mutation-tested — reverting either fix makes its regression test fail as expected). New code reached 100% statement coverage.

- **`HttpHandler` gzip-compresses text/JSON/HTML responses, tags content-hashed static assets as immutably cacheable, and measures request duration with `hrtime(true)` (Stream Quality/ABR step S4, Track S — independent quick win, no API/behavior change to any response body; media/streaming responses are untouched).**
  - **`Content-Encoding: gzip`** is applied by a new private `compressResponse()`, wired into the three buffered-response dispatch sites in `__invoke()` (the `Application` router response, the `WebPortalRouter` `/api/*` fallback, and the SSR page-rendering path). It compresses only when the client sent `Accept-Encoding: gzip`, the body is `>= GZIP_MIN_BYTES` (1024 bytes — below a single ~1.5 KB TCP segment, compression buys nothing on the wire while still costing CPU and the ~20-byte gzip envelope), the response isn't already `Content-Encoding`-tagged, gzip (level 6) actually shrinks the body, and — the key safety gate — the Content-Type is on a strict allowlist (`isCompressibleType()`: any `text/*`, plus `application/json`, `application/javascript`, `application/xml`, `application/manifest+json`, `application/ld+json`, `application/rss+xml`, `application/atom+xml`, `image/svg+xml`). On success it sets `Content-Encoding: gzip`, merges `Accept-Encoding` into `Vary` (`mergeVaryAcceptEncoding()`, dedup-safe), and rewrites `Content-Length` to the compressed size (dropping any stale case-variant header first).
  - **Media/streaming responses are never gzipped, verified by two independent guards.** Guard 1: `compressResponse()`'s first check is `$response->filePath !== null` — every HLS/DASH playlist and segment is served via `Response::withFile()` (S3), and direct-play byte ranges/avatars return raw `WorkermanResponse`s that never even reach this method, so a file-backed or already-bypassed response can never be buffered/compressed. Guard 2: the Content-Type allowlist has no video/audio/image/`octet-stream` entry and specifically excludes the HLS `application/vnd.apple.mpegurl`/DASH `application/dash+xml` playlist types (no `+xml`-suffix wildcard that could net `dash+xml`). Either guard alone excludes the entire streaming surface.
  - **`Cache-Control: public, max-age=31536000, immutable`** is now set by `serveStatic()` for any request resolving under the Vite-built `public/assets/app/**` bundle (content-hashed filenames — the bytes for a given URL never change, so a year-long cache with no revalidation is safe). The decision is gated on the **resolved, jailed filesystem path** (`$real`, the same value already used to serve the file and enforce the `publicRoot` jail), not the raw request-path string — a request whose path merely *starts with* `/assets/app/` but traverses outside it via `..` (e.g. `/assets/app/../foo.js`, or a sibling directory like `/assets/appendix/...`) does not get tagged immutable, even though `realpath()` doesn't normalize `..` in the raw string. Non-`/assets/app/` static files (favicon, robots.txt, etc.) are unaffected.
  - **Request-duration timing switched from `microtime(true)` to `hrtime(true)`** (the repo's documented monotonic-clock convention) — `__invoke()` captures `$startTime = hrtime(true)`; `recordRequestMetrics()`'s `$startTime` parameter is now `int` (was `float`) and computes `$elapsedMs` from the nanosecond difference before converting to milliseconds, so a long-running worker's large `hrtime` values keep full sub-millisecond precision and stay immune to system clock adjustments. Purely a precision/monotonicity improvement — the metric remains a duration, not a timestamp — and only this one request-timing site was touched (the repo's many other `microtime(true)`/wall-clock uses are unrelated and intentionally untouched).

- **HLS/DASH segments and playlists now stream through Workerman's event-loop file sender instead of being buffered into worker memory (Stream Quality/ABR step S3, Track S — structural performance fix, no API/behavior regression; pairs with A6's variant-aware serving, and unblocks hub Track D's deferred `D3` streaming pass-through since the origin no longer buffers whole segments).** `TranscodeFileServer::serveJobFile()` (the trait shared by `HlsController`/`DashController`) previously read every playlist/segment body — including every ~1–6 MB `.ts`/`.m4s` HLS/DASH segment — whole via `file_get_contents()` and advertised `Accept-Ranges: bytes` while never actually honouring a `Range` request. Now:
  - **`Response` gained a file-backed mode** — `withFile(string $path, int $offset = 0, int $length = 0): self` plus new `$filePath`/`$fileOffset`/`$fileLength` properties. `toWorkermanResponse()` hands the path straight to Workerman's native `withFile()` (the same event-loop file sender direct-play already used via `serveMediaStream`), which streams the body chunked for files ≥ 2 MB and auto-derives `Content-Length`/`Accept-Ranges: bytes`/`Last-Modified`, plus `Content-Range` + 206 whenever an offset/length window is supplied. **No route registration or handler-construction change was needed** — the dual-entrypoint boundary (`public/index.php` vs `start.php`→`HttpHandler`→`Application::loadStreamingRoutes()`) is untouched, because `Response` itself now carries the file rather than the controllers being lifted out of the router.
  - **Real `Range` support**, via a new shared `TranscodeFileServer::parseRange()`: single ranges `bytes=A-B`/`bytes=A-` → 206 (an over-long `B` is clamped to `$fileSize - 1` per RFC 7233 §2.1 and served, not rejected); suffix ranges `bytes=-N` ("last N bytes") → 206, with an oversized `N` clamped to the whole file; a `start` at/past EOF, `start > end`, or a zero-length suffix → genuinely unsatisfiable → 416 with `Content-Range: bytes */{size}`; a multi-range or otherwise-unparseable header falls back to a whole-file 200 (an RFC-permitted fallback, not a special case the server has to reject).
  - **Conditional GET**: an `If-Modified-Since` matching the file's mtime now short-circuits to 304 — but only for immutable, `Cache-Control: public, max-age=31536000` segments; `no-cache` playlists/manifests (rewritable mid-encode) are never short-circuited.
  - **CGI/FPM fallback parity.** `Response::send()` — the non-Workerman code path, unreachable in production since streaming routes are Workerman-only — gained a private `finalizeFileHeaders()` (computes the identical `Content-Length`/`Accept-Ranges`/`Content-Range` + forced 206 that Workerman's own `withFile()` derives, traced against `vendor/workerman/workerman/src/Protocols/Http.php::encode()`) and a bounded-chunk `streamFileToOutput()`, so a file-backed `Response` degrades gracefully and answers a Range request identically no matter which entrypoint renders it.
  - **Structural resident-memory/GC win.** No segment or playlist body is copied into a PHP string on the request-handling path anymore, so a large concurrent HLS/DASH load no longer pins a full in-memory copy of every in-flight segment inside the resident Workerman worker. (Verified by code inspection — no `file_get_contents()` remains on the segment/playlist serving path; live resident-memory measurement under load is out of scope for this environment.)
  - Fully backward compatible: `HlsController::serveFile()`/`DashController::serveFile()` only gained a `Request $request` parameter threaded through to `serveJobFile()` (needed to read `Range`/`If-Modified-Since`); the S1 job-row cache, S2's per-variant in-flight dedup/global cap → `SegmentBusyException`/503, 404 self-heal, and the signed-URL middleware group are all upstream of `serveJobFile()` and untouched.

- **`TranscodeManager`'s on-demand segment concurrency gate splits its two in-flight checks apart (Stream Quality/ABR step S2, Track S — pure performance/robustness work, no API/behavior change; pairs with S1's job-row cache).** `produceSegment()` gates a new segment launch with two checks, and they no longer share one mechanism:
  - **The per-segment DEDUP check (`segmentEncodeInFlight()`) — hit on every request, since it's what catches a client retry of a slow segment (the routine hls.js first-byte-timeout re-request) and makes it piggyback on the already-running encode instead of spawning a duplicate `ffmpeg` — is now memory-based**, not a filesystem glob. A new in-worker set (`$segmentEncodesInFlight`, keyed by the absolute final segment path, which embeds the `(jobId, variant, index)` tuple) is unioned with a periodically-refreshed cross-worker snapshot (`$globalInFlightSnapshot`), kept fresh by a new `reconcileInFlightSegments()` throttled to at most one glob per second. This removes the `glob('{final}.part-*')` call from the highest-frequency check on the hot retry/seek path entirely.
  - **The global concurrency CAP (`countInFlightSegmentEncodes()`) intentionally remains a real-time, whole-tree `glob()` on every new-launch decision — it was deliberately NOT converted to read from the same memory-based bookkeeping as the dedup check.** An initial version of this step did make the cap memory-based too; review caught that because the cap is enforced independently by each of the 14 HTTP worker processes, a shared ≤1-second-stale snapshot could let the fleet collectively overshoot the advertised ceiling by up to ~14x — and specifically during a seek storm, the exact scenario the cap exists to bound (the same class of cascade the prior on-demand HLS seek-cascade incident hardened against). The fix restored a live glob for the cap check, preserving the original ~100ms `.part-*`-visibility accuracy bound. **This means S2 does not eliminate all filesystem globbing from the segment hot path** — only the dedup/retry check's globbing is gone; the cap-check glob still runs on every actual launch decision (which happens only after the dedup check already found nothing in-flight for that exact segment, so it's reached far less often than the per-request dedup check). The net effect is still a real, strictly-better-or-equal reduction in glob calls for every traffic pattern versus pre-S2 — just narrower in scope than "zero hot-path globbing."
  - `produceSegment()`'s `try` block now starts immediately before the in-flight increment (previously it wrapped only the poll loop), so the `finally` that releases the slot is reached on any throw after the increment — not just a poll-loop failure — closing a leak window a prior version left open.
  - `SegmentBusyException`→503+`Retry-After`, per-`(jobId, variant, index)` isolation, and all other A5/A6 dedup/cap/sweep/HLS-serving behavior are unchanged from every caller's perspective.

- **`TranscodeManager::getJobRow()` is now backed by an in-worker LRU cache (Stream Quality/ABR step S1, Track S — pure performance work, no API/behavior change; pairs with A5/A6's multi-variant on-demand HLS).** Previously every HLS segment/playlist request re-ran a `SELECT *` against `transcode_jobs` under the shared connection's coroutine mutex, even though a job row is written once at creation and thereafter only its terminal `status` changes — the dominant per-segment DB cost on a hot seek/playback path. Now:
  - A bounded map (`JOB_ROW_CACHE_MAX = 256`, oldest-first/MRU eviction) caches the narrowed row keyed by job id, and the parsed `variants` JSON ladder is memoised alongside it so repeat reads (`ensureSegment()`, `getJobVariants()`, …) never re-`json_decode` it — preserving the exact legacy `NULL`-vs-corrupt-JSON distinction A5 relies on.
  - The `SELECT` itself is narrowed from `*` to `JOB_ROW_COLUMNS` — `id, status, input_path, hls_dir, duration_seconds, segment_seconds, segment_params, subtitle_tracks, variants` — the exact union of columns every real call site reads (a new test pins this list against every call site so a future narrowing regression fails loudly instead of silently nulling a field).
  - The cache is invalidated at all 4 sites that mutate a job row: the terminal-status sync in `getJobReadiness()`, `reapStaleRunningJobs()`, `cancelTranscode()`/`stopTranscode()`, and the legacy `startTranscode()` failure path — so a cache hit is always coherent with the last write.
  - No explicit coroutine lock was needed: `TranscodeManager` is confirmed the sole writer of `transcode_jobs` (repo-wide grep), and the shared connection's existing coroutine mutex already serializes the query round-trip on a miss, ruling out a populate-vs-invalidate race under the **current single-connection model**. **This coherence guarantee is tied to that single-connection mutex** — when step **S9** later validates and enables the coroutine DB connection pool, this cache's invalidate-on-write design will need re-examination (parallel connections could interleave a populate with a concurrent write in a way the current mutex prevents); tracked as a known S9 follow-up, not an open issue here.

- **`HlsController::serveFile()` now serves both segment filename shapes of an on-demand HLS job (Stream Quality/ABR step A6) — completes the server-side serving half of the multi-variant feature landed by step A5.** Two `seg-…\.ts` shapes are recognized and both route to the same `TranscodeManager::ensureSegment($jobId, $variant, $index)` (A5's signature), with identical back-pressure/self-heal behavior for either:
  - Legacy unprefixed `seg-NNNNN.ts` → `ensureSegment($jobId, null, $index)` — selects a `variants IS NULL` single-variant job, unchanged from before A5.
  - Multi-variant `seg-v{renditionId}-NNNNN.ts` (e.g. `seg-v1080p-00042.ts`, `seg-voriginal-00007.ts`) → `ensureSegment($jobId, '{renditionId}', $index)`, where `{renditionId}` is matched against a `[a-z0-9]+` allowlist — the fixed set of ids `AbrLadder` produces (`240p`…`2160p`, `original`). The regex is anchored and excludes `.`/`/`/`\`, so it is a defense-in-depth guard that cannot smuggle a path-traversal sequence even before the earlier `isSafeFilename()` gate; a filename that matches neither `seg-…` regex falls through to a plain static-file lookup that 404s (never reaches the transcoder).
  - Either shape gets the same `SegmentBusyException` → `503` + `Retry-After` back-pressure and a `null` result → `404` self-heal (the client retries once the segment materializes) — no behavior divergence between the legacy and multi-variant paths.
  - **Per-variant media playlists needed no controller change.** `media_v{id}.m3u8` (e.g. `media_v1080p.m3u8`), written up front by `TranscodeManager` alongside `master.m3u8`, already served correctly as a plain static file through the existing `serveJobFile()` path — no transcoder call — confirmed by a new test asserting `ensureSegment()` is never invoked for a playlist request.
  - This is the server-side HLS **serving** half of the multi-variant feature; the client-facing API surface that advertises the available `variants[]` list shipped in step **A7** (see above). Player-visible quality selection is step **E3** in `@phlix/ui`.

- **`TranscodeManager` is now a genuine multi-variant HLS pipeline (Stream Quality/ABR step A5) — the actual multi-quality/ABR feature landing for the first time.** Previously every on-demand HLS job served exactly one quality (`master.m3u8` → `media_0.m3u8` → unprefixed `seg-NNNNN.ts`). `ensureHlsJob()` now:
  - Resolves the source's characteristics into a `SourceProfile` — preferring A1's persisted `metadata_json['source']` blob (via the new `sourceProfileForItem()`/`persistedSourceMetadata()`), and only falling back to deriving one from the live `ffprobe` result (`sourceProfileFromProbe()`) for items that predate the A1 backfill — then calls A2's `AbrLadder::build()` to get a `LadderResult` and persists it as JSON in the (A3) `transcode_jobs.variants` column.
  - Publishes a `master.m3u8` listing **every** clamped quality rung plus the "Original" stream-copy passthrough (when the source is HLS-safe H.264/AAC) as separate `#EXT-X-STREAM-INF` entries, highest-first, each with a correct `BANDWIDTH`/`RESOLUTION`/`CODECS` and its own media playlist (`buildMultiVariantMaster()`). Each variant gets its own VOD media playlist — `media_v{id}.m3u8` (e.g. `media_v1080p.m3u8`, `media_voriginal.m3u8`) — with an **identical segment timeline** (count/`EXTINF`/duration) to every other variant, so hls.js can switch rungs at any segment boundary. Segment boundaries were already kept identical across rungs by A4; A5 is what actually exposes multiple rungs to a player.
  - Serves each variant's segments on demand from `ensureSegment(jobId, variant, index)` (now variant-aware): the requested variant id is resolved against the persisted ladder (`findRenditionArray()`), its encode params are derived (`segmentParamsForRendition()` — the copy contract for a genuine passthrough rung, or capped-CRF H.264/AAC with the rung's scale/VBV ceiling/macroblock-derived `-level` otherwise), and the shared tail (`produceSegment()`) writes `seg-v{id}-NNNNN.ts` (still flat in the job directory — the `/hls/{jobId}/{file}` route's `{file}` segment is `[^/]+`, so no `v{id}/` subdirectory is possible). All of the existing on-demand seek-cascade protections — per-segment dedup via `.part-*` temp files, the global in-flight-encode cap → `SegmentBusyException`/503, and the LRU/TTL cache sweep — continue to work unmodified across every variant of every job, because they already glob on the `seg-*`/`{final}.part-*` filename shape rather than assuming a single variant.
  - **Fully backward compatible.** Any transcode job created before this deploy (`transcode_jobs.variants IS NULL`) keeps working exactly as before: `writeVodPlaylists()`/`ensureSegment()` detect the absent `variants` column and fall through to the untouched legacy single-variant path — `master.m3u8` (single `#EXT-X-STREAM-INF`) + unprefixed `media_0.m3u8` + `seg-NNNNN.ts` — byte-identical to pre-A5. Nothing regresses for in-flight or existing jobs.
  - One small call-site change in `HlsController::serveFile()` (passes `null` for the new `$variant` parameter of `ensureSegment()`) kept the legacy unprefixed-segment regex match working at the time; full variant-aware URL parsing (`media_v{V}.m3u8` / `seg-v{V}-NNNNN.ts`) landed in step **A6** (see above). Player-visible quality selection UI is further out still (step **E3** in `@phlix/ui`) — this step was the server-side foundation, not yet user-facing.

- **`FfmpegRunner::buildSegmentCommand()`: per-rung capped-CRF encode + genuine stream-copy passthrough (Stream Quality/ABR step A4) — `FfmpegRunner`-only groundwork at the time; the copy path was DORMANT until step A5 wired a real per-rung/Original decision into `TranscodeManager` (see the A5 entry above — `TranscodeManager::computeSegmentParams()`/`segmentParamsForRendition()` now make that decision for every job created after A5 deployed).** Two segment shapes, selected per-stream from the caller's params:
  - **Capped-CRF transcoded rung (the default, and byte-identical to the exact pre-A4 command when the new keys are omitted).** `maxrate`/`bufsize` (bps ints, sourced from A2's `Rendition::maxrate()`/`Rendition::bufsize()`) are now emitted as `-maxrate`/`-bufsize` alongside the existing `-crf`/`-preset`, giving the quality-driven encode a hard VBV ceiling so a rung's encoded bitrate never exceeds its advertised HLS `BANDWIDTH`. No bare `-b:v` is ever set — it would disable CRF mode; the cap is the `-maxrate`/`-bufsize` pair only, and it is emitted only when the caller supplies both keys. `-force_key_frames`, `-output_ts_offset`, and `-muxdelay`/`-muxpreload` stay byte-identical across every transcoded rung (only scale/bitrate/level differ), so ABR switching between rungs at a segment boundary stays seamless.
  - **Genuine stream-copy passthrough for "Original."** `video_codec === 'copy'` now emits a real `-c:v copy` (previously silently upgraded to a forced `libx264` re-encode) and skips `-force_key_frames`/scale/`-preset`/`-crf`/`-maxrate`/`-bufsize` — a stream copy can't force an arbitrary keyframe, so a copy segment's actual start may drift up to one source GOP length from the nominal boundary; acceptable for a manually-pinned Original variant but exactly why copy is never used for the ABR-switching rungs. `audio_codec === 'copy'` gets the same treatment independently (`-c:a copy`, no `-b:a`/`-ac`), so a mixed video-copy/audio-reencode segment (or the reverse) is fully supported.
  - Fully backward compatible: a caller that never passes `maxrate`/`bufsize`/`video_codec: 'copy'`/`audio_codec: 'copy'` gets the exact pre-A4 CRF-only command.

- **ABR ladder builder + rendition value objects (Stream Quality/ABR step A2) — pure groundwork for the multi-variant HLS master at the time; wired into `TranscodeManager`'s master/media-playlist generation by step A5 (see above).** New `src/Media/Streaming/{AbrLadder,Rendition,SourceProfile,LadderResult}.php`. `AbrLadder::build(SourceProfile $source, string $profileName = 'generic'): LadderResult` is pure and deterministic — no DB, ffprobe, filesystem, clock, or randomness; identical inputs always produce identical output — and returns an ordered, source-clamped H.264 quality ladder (240p…2160p, highest-first) plus an "Original" descriptor, given the source's video/audio characteristics (`SourceProfile`, adaptable from A1's persisted `metadata_json['source']` via `SourceProfile::fromSourceMetadata()`, or constructed directly from a live probe) and a device-profile name looked up in the existing `QualitySelector` (`generic`/`mobile-low`/`mobile-high`/`web`/`tv-4k`). The ladder **consumes** `QualitySelector`'s `max_resolution`/`max_bitrate` device caps as its clamp ceiling — it does not replace or change `QualitySelector` itself, which still governs direct-play-vs-transcode selection. Every rung is clamped so it never upscales past the source resolution, never exceeds the source's own video bitrate when known, and never exceeds the device profile's resolution/bandwidth cap (reserving a 128 kbps AAC allowance + maxrate headroom so the advertised `BANDWIDTH` never exceeds the profile's cap); a rung's width is derived from the source's own aspect ratio (not a fixed 16:9), so anamorphic/DCI/ultrawide sources aren't distorted; a source below the lowest tier (or one squeezed below it by a narrow device-profile width) still yields exactly one clamped rung; unknown source dimensions cap the ladder conservatively at a 1080p 16:9 tier (never 1440p/2160p) and suppress the copy `Original`. Each transcode rung also carries the derived encoder targets step A4 will consume: `-b:v` (`videoBitrate`), `-maxrate` (`Rendition::maxrate()`, ≈1.07× target) and `-bufsize` (`Rendition::bufsize()`, 2×maxrate). `Rendition` mirrors the eventual wire shape `{id,label,width,height,bitrate,codecs,url}` (plan §1 D6) — `url` stays `null` here; step A5 wires the ladder into the transcode pipeline but still leaves `url` `null` (variant playlists are addressed by convention, `media_v{id}.m3u8`); step A7 (see above) is what actually fills `url` in, in a derived array copy of the ladder returned by `TranscodeManager::getJobVariants()` for the API response shape — `Rendition::toArray()` itself still always emits `url: null`.
  - **"Original" (D4): a stream-copy passthrough when the source is HLS-safe (H.264 + AAC) and fits the profile cap — `-c copy`, near-zero CPU, labelled `Original (<source height>p)` — else the top clamped transcode rung, relabelled `Original (best available)`, so the UI's "Original" choice doesn't map onto a duplicate master variant.** `LadderResult::streamVariants()` prepends the copy Original as a genuine extra highest variant when it applies, and omits the non-copy one so `A5` doesn't emit a duplicate `#EXT-X-STREAM-INF`.
  - **H.264 `CODECS` level is chosen by macroblock count (`ceil(w/16) * ceil(h/16)`) against a MaxFS table, not by height alone**, so a rung or copy `Original` whose frame is wider than its height tier's canonical 16:9 shape (2048×1080 DCI-2K, 2560×1080 ultrawide, 2560×1440, 3840×2160, …) advertises a legal, never-under-declared `avc1.*` level — e.g. plain 1920×1080 is L4.1 (`avc1.640029`), but 2048×1080 needs L4.2 (`avc1.64002A`) and 2560×1080 needs L5.0 (`avc1.640032`). **Coordination note for step A4:** the FFmpeg encoder must encode each rung — and the copy path — at this exact per-rung, macroblock-derived level, not a single flat `-level 4.1` applied to every rung, or the encoded stream will silently mismatch the level the master playlist advertises for wide/anamorphic tiers.

### Added

- **Schema: `transcode_jobs.variants` column for the multi-variant ABR ladder (Stream Quality/ABR step A3) — schema-only groundwork, no behavior change yet.** New migration `migrations/049_transcode_jobs_variants.sql` adds a single nullable `variants TEXT` column (`AFTER segment_params`, same additive/idempotent `ALTER TABLE ... ADD COLUMN` style as `047_transcode_jobs_ondemand_columns.sql` — a re-run duplicate-column error is downgraded to a note by `MigrationRunner`). It will carry the resolved ABR ladder as JSON-shaped text (matching `segment_params`'s TEXT convention, since the workerman/mysql driver handles it as a plain string rather than native JSON): `LadderResult::toArray()` from step A2 (`src/Media/Streaming/{AbrLadder,Rendition,LadderResult}.php`) — `{renditions: [Rendition::toArray(), ...highest-first], original: Rendition::toArray()}`, each rendition flat as `{id, label, width, height, bitrate, codecs, url, is_original, is_copy, video_bitrate}`. Nothing read or wrote the column at the time — step A5 (see above) is what builds the ladder in `TranscodeManager::ensureHlsJob()` and persists it here, so the master/media playlists are rebuilt without recomputing the ladder from a live `ffprobe` on every request. Existing rows are unaffected: `variants` is `NULL` for every pre-A5 job, and the pipeline keeps working unmodified via the existing single-variant columns (`profile`, `segment_params`, …) as the fallback path.

### Added

- **Scanner now persists source video/audio technical metadata at scan time (Stream Quality/ABR step A1) — groundwork for the upcoming multi-quality (ABR) streaming ladder.** The single `ffprobe` call the scanner already ran per time-based file (video/movie/episode/audio) now also derives a compact technical summary, stored at `metadata_json['source'] = {width, height, video_codec, video_bitrate, pix_fmt, audio_codec, audio_bitrate}`, so a later ABR ladder builder can pick renditions without re-probing on every playback start. The existing total-duration probe is folded into the same call (no added probing cost). The primary video stream is chosen skipping embedded cover-art / poster streams (`disposition.attached_pic = 1`, common in MKV/MP4/M4V), so a poster's tiny dimensions never masquerade as the source resolution; `video_bitrate` falls back to the whole-file `format.bit_rate` when the video stream itself reports none (common for Matroska). Runs on both the initial-scan and the incremental-rescan path — the rescan path (previously a duration-only backfill) now backfills `source` and the streams below too, for files indexed before this change.
  - **`media_streams` rows (video + primary audio) are now written from the same probe**, via the existing `ItemRepository::addStream()` and a new `ItemRepository::deleteStreamsByItem()`. Replacement is delete-then-insert (idempotent) because the table carries no unique key on `(media_item_id, stream_index)` and would otherwise duplicate rows on every rescan.
  - **New offline CLI `scripts/backfill-source-metadata.php [--library=<id>] [--limit=<n>]`** populates `metadata_json['source']` (+ duration + streams) for items indexed before this change. Idempotent and guarded per item: an item already carrying both a positive duration and a `source` blob is skipped without probing, a probe or write failure on one item is logged and never aborts the run, and the process exits non-zero when any item hard-fails so automation can detect a partial run — failed items are left `source`-less and are picked up again on the next invocation.

### Fixed

- **Transcoded playback (MKV / HEVC / 10-bit) now reports the real length and seeks anywhere.** A file the browser can't direct-play (e.g. a 10-bit HEVC `.mkv`) is transcoded to HLS on demand. The pipeline previously ran a **single linear CMAF encode** (`ffmpeg -f dash … -hls_playlist 1`) that wrote HLS child playlists **incrementally with no `EXT-X-ENDLIST` / `PLAYLIST-TYPE:VOD`** until the whole (minutes-long, software) encode finished. hls.js therefore treated the stream as **live**: `video.duration` only grew as segments arrived, and `seekable` covered only the encoded-so-far region, so seeking past it snapped back. (The dead `playlist_type => 'vod'` param was only read by the unused `-f hls` path, never the `-f dash` path that actually ran.)
  - **`TranscodeManager::ensureHlsJob()` is now on-demand + seek-aware.** On job creation it probes the duration and publishes a **complete VOD playlist immediately** — `master.m3u8` + `media_0.m3u8` listing every segment with its `EXTINF`, `#EXT-X-PLAYLIST-TYPE:VOD`, and a closing `#EXT-X-ENDLIST` — so the player knows the true total length and full seekable range up front. No background A/V encode is launched (subtitle extraction still runs detached). The job is recorded `status='completed'` so the stale-job reaper (which only reaps `running` rows) can't tear it down mid-watch.
  - **Segments transcode on demand.** `HlsController` routes a `seg-NNNNN.ts` request through the new `TranscodeManager::ensureSegment()`, which returns a cached segment or launches `FfmpegRunner::startSegmentEncode()` — an `-ss` fast-seek encode of exactly that segment's window, with a forced keyframe at its start and `-output_ts_offset` anchoring its PTS to the timeline (so segments stitch and a seek lands correctly). The request polls for the atomically-renamed segment with a **coroutine-yielding `usleep`** (`SWOOLE_HOOK_SLEEP` is in the curated hook set) so a waiting request never blocks the worker. Any segment — including one far past what has been produced — is served, so the user can seek anywhere (a ~1–3 s spin-up per uncached seek on CPU). A per-job soft cap bounds concurrent encodes so frantic scrubbing can't fork-bomb ffmpeg.
  - **Migration `047_transcode_jobs_ondemand_columns.sql`** — adds `duration_seconds`, `segment_seconds`, and `segment_params` (JSON) to `transcode_jobs` so any segment can be built later without re-probing. Reuse now requires `segment_params IS NOT NULL`, so a legacy linear-CMAF job left in the table across the upgrade is **not** reused (its live playlist is skipped; a fresh on-demand job is created). DASH manifest generation is dropped from this path — no client consumed it (web, mobile, and Roku all play the HLS `master_url`). No client change is needed: the SPA already detects `.mkv`/HEVC and starts the transcode; it now attaches to a proper seekable VOD stream.
- **Metrics: admin `/api/v1/admin/metrics/*` no longer 500s with a "Couldn't execute method `Error::__toString`" fatal.** The S2 metrics wiring registered the concrete `MetricsRepository` but never bound the read-side `MetricsRepositoryInterface`, while `MetricsController` type-hints the interface and `AdminRoutes` resolves `get(MetricsController::class)` at route registration. PHP-DI then tried to instantiate the interface directly and threw `InvalidDefinition` ("the class is not instantiable"), which the Workerman error handler surfaced as the mangled `Error::__toString` fatal. `MetricsServicesProvider` now aliases `MetricsRepositoryInterface::class => get(MetricsRepository::class)` (reusing the shared concrete singleton); a new regression test asserts the interface resolves.

### Security

- **systemd unit: extra kernel/privilege hardening (phlix-hub parity).** Adds `ProtectKernelTunables`, `ProtectKernelModules`, `ProtectControlGroups`, `ProtectHostname`, `ProtectClock`, `RestrictSUIDSGID`, and `RestrictRealtime` to the generated `[Service]` block, on top of the existing `ProtectSystem=strict`/`ProtectHome`/`NoNewPrivileges`/`PrivateTmp`/`RestrictNamespaces`/`LockPersonality`/`RemoveIPC` set. All are safe for the media server (software transcoding shells out to ffmpeg; optional DVB/DLNA needs neither module loading nor clock/hostname/cgroup writes). Deliberately **not** setting `PrivateDevices` (would hide `/dev/dvb` tuners and `/dev/dri`), `MemoryDenyWriteExecute` (breaks PHP JIT/opcache), or `SystemCallFilter` (Swoole io_uring is syscall-sensitive). Verified with `systemd-analyze verify` and a `systemd-run` sandbox on the host.

### Added

- **Multi-level "Love" for media items (Feature 10).** Builds on the per-user favorites/ratings (E10, below) with a separate 0-3 "Love" axis distinct from `favorite` (boolean) and `rating` (1-10).
  - **Migration `044_user_item_like_level.sql`** — adds `like_level TINYINT NOT NULL DEFAULT 0` to `user_item_data` (`AFTER rating`). `0` = not loved … `3` = most loved. The 0-3 range is enforced in PHP (`UserItemDataRepository::setLikeLevel()` throws `InvalidArgumentException` out of range) — **not** via a DB `CHECK` constraint (consistent with mig 039's PHP-enforced 1-10 rating). Idempotent re-run via the runner's "Duplicate column name" downgrade. **This migration is the W1 deploy trigger (server migration max → 044).**
  - **New endpoint `PUT /api/v1/media/{id}/like`** (body `{level: int 0-3}`, required) → `{message: "Love level saved"}`; `400` on missing/non-integer/out-of-range `level`, `401` unauthenticated, `404` unknown item. Handled by `MediaUserDataController::setLikeLevel()`, validated/coerced exactly like `setRating` (the range is deferred to the repository). Registered once in the `requireAuth` group on `WebPortalRouter` (with a 503-when-unwired delegate) — reachable from **both** HTTP entry points (CGI/web-portal + the Workerman daemon's `HttpHandler`, which forwards `/api/*` 404s to `WebPortalRouter`), exactly as the existing favorite/rating routes do.
  - **`UserItemDataRepository::setLikeLevel(userId, itemId, level)`** — upserts via `INSERT ... ON DUPLICATE KEY UPDATE` (flat positional `?` binding, colon-free), preserving `favorite`/`rating`; `getItemData()` and `getFavorites()` now also select `like_level` (int, default 0 when absent/NULL).
  - **`user_data` block extended (add-only).** `GET /api/v1/media/{id}` and `GET /api/v1/users/me/favorites` now carry `like_level: int` (default 0) alongside `favorite`/`rating`. No existing key disturbed; only detail + favorites-list responses build a `user_data` block (browse/list rows do not).

- **Strip multi-word "noise" suffixes from match titles (Feature 13).** Filename→title cleaning now peels trailing edition/scene markers — `Directors Cut`, `UNCUT & UNRATED`, `ALTERNATE ENDING`, `Extended Cut`, `Remastered`, `YIFY`, `DC`, … — before the title is sent to a metadata provider, so they no longer depress the match hit-rate. New shared `Phlix\Media\Metadata\TitleSuffixStripper` is the single source of truth (longest-phrase-first, end-anchored, word-boundary; a single-token noise word never empties a title; the original filename `raw` is never mutated); both the movie normalizer (`SceneFilenameNormalizer`) and the series parser (`EpisodeFilenameParser::cleanSeries()`) consume it. The effective list is admin-extensible via the new `matching.noise_suffixes` server-setting (replace-not-merge override; an empty override falls back to the code defaults mirrored in `config/matching.php`), wired through `MediaServicesProvider`.

- **Auto-merge / de-duplicate top-level series & movies (Feature 1).** A title-slug variance (separators, year bleed, a flat→per-directory re-scan, a concurrent-scan race) used to silently create a second top-level row for the same title — the "100 episodes + 1 stray episode" symptom — because there is no DB UNIQUE constraint on items. Added:
  - **`Phlix\Media\Library\CanonicalKey`** — a pure normalizer that collapses separator/article/case variance and prefers a matched external id (`imdb:` > `tmdb:` > `title-key:year` > `title-key`).
  - **Prevention at scan time** — `MediaScanner` now resolves a container in the tier order `containerCache → exact path (findByPath) → canonical key (findTopLevelByCanonical)`, reusing an existing container on a canonical hit rather than manufacturing a sibling. Applied to the top-level movie create path too.
  - **`DuplicateFinder`** — pages a library's top-level items in fixed batches, buckets them by canonical key + type, returns groups of size ≥ 2 with the most-descendants member as the primary.
  - **`SeriesMerger`** — `merge(primaryId, duplicateIds): {moved, deleted}`; for a series, re-parents episodes onto the primary's matching season (re-parent-before-delete) then deletes empty shells; for a movie, gap-fills missing metadata (add-only) then deletes the duplicate. Runs inside one real DB transaction; works under both `DB_POOL_ENABLED=0` and `=1` (the ctor takes the base `Workerman\MySQL\Connection`). Per-user playback markers are out of scope: re-parented episodes keep their ids (state survives); deleted shells/duplicate-movie rows lose their own per-user rows via `ON DELETE CASCADE`.
  - **Migration `043_media_items_canonical_key.sql`** — adds a nullable, **non-unique** `canonical_key VARCHAR(191)` column + `(library_id, type, canonical_key)` index (no UNIQUE — historical dupes exist; uniqueness is enforced in app code). `ItemRepository::create()/update()` write the column as the source of truth.
  - **`scripts/dedup-series.php [--library=ID] [--dry-run|--apply]`** — offline backfill that runs `DuplicateFinder` per library and, on `--apply`, calls `SeriesMerger`; dry-run is the default and a re-run after `--apply` reports zero groups (idempotent).
  - **Admin merge API** — `GET /api/v1/admin/libraries/{id}/duplicates` (preview groups) and `POST /api/v1/admin/media/merge` `{primary_id, duplicate_ids[]}` → `{moved, deleted}`, both admin-gated (`Phlix\Server\Http\Controllers\Admin\AdminMergeController`, registered via `AdminRoutes::register()` for both entry points). Validates same-library + same-type, rejects self-merge (`400`), `404` on missing primary, `503` when no transaction-capable connection is bound.

- **Metadata source priority — configurable per-field fallback (Feature 3).** The matching pipeline now normalizes each provider's payload into a canonical field set and resolves each field by walking a configurable source order, taking the first non-empty value (external IDs merged, earlier source wins). Added `Phlix\Media\Metadata\Resolution\{SourceRecord, FieldMappers, PriorityFieldResolver, PriorityConfig}`. The order is driven by the new `metadata.provider_priority` server-setting (per media type; defaults `movie`/`series` = `["tmdb","imdb"]`, `anime` = `["anidb","myanimelist","tvdb","fanart","local"]`) and `metadata.genres_mode` (`first` | `union`, default `first`); defaults live in `config/metadata.php`. `MovieMetadataResolver`/`SeriesMetadataResolver` were refactored onto this resolver behavior-preservingly under the default order (the live series resolver keeps a fixed `['tmdb']` order — making the configured series order take effect is a deliberate future change).

- **Admin metadata-source name endpoint (`GET /api/v1/admin/metadata/sources`, Step 3.6).** New admin-gated, read-only JSON endpoint returning `{sources: string[]}` — the available metadata-source names so the admin SPA's per-media-type priority editor (`metadata.provider_priority`) can list REAL names. The list is the built-in sources (`tmdb`, `imdb`, `tvdb`, `fanart`, `local`, in that stable order) followed by any plugin sources currently registered in `SourceRegistry` (e.g. `anidb` / `myanimelist` when those plugins are enabled), de-duplicated. Handled by new `Phlix\Server\Http\Controllers\Admin\AdminMetadataSourceController` (its only dependency is the container-scoped `SourceRegistry`); registered via `AdminRoutes::register()` so BOTH HTTP entry points (the Workerman daemon `Application` + the `public/index.php` web portal) expose it from one registration, gated by `AdminMiddleware` (401 unauth / 403 non-admin). No DB access, no write.

- **First-class metadata-source plugin contract (`SourceRegistry`, Step 3.5b).** Plugins that provide metadata (e.g. `phlix-plugin-anidb`, `phlix-plugin-myanimelist`) can now register through the shared typed contract `Phlix\Shared\Metadata\MetadataSourceInterface` (shipped in `detain/phlix-shared` v0.15.0) instead of the old brittle `method_exists($manager,'registerProvider')` / FQCN container-sniffing dance. New `Phlix\Media\Metadata\Resolution\SourceRegistry` is a process-scoped registry of `MetadataSourceInterface` instances keyed by `sourceName()`; it is wired as a single container-scoped binding in `MediaServicesProvider`. `PluginLoader::enable()` now `register()`s any enabled plugin entry instance that implements `MetadataSourceInterface`, and `PluginLoader::disable()` `deregister()`s it — a leak-free enable/disable cycle (re-register is idempotent, deregister truly removes). Bumped the `detain/phlix-shared` constraint `^0.14.0` → `^0.15.0`. This step builds the registry + plugin contract only; it does **not** change `MovieMetadataResolver`/`SeriesMetadataResolver` output (the live series resolver keeps its fixed `['tmdb']` order from Step 3.4) — feeding the registry into the resolvers is a later step.

- **`web-ui`: bumped `@phlix/ui` `v0.56.0` → `v0.57.0` and rebuilt the committed SPA bundle (`public/assets/app/`) (Wave 1 bump).** Picks up the favorites wiring (`MediaCard` favorite button, Browse "Favorites" row, Browse/Detail persistence + hydrate), the multi-level **Love** control (`LoveButton.vue` 4-state component on cards + detail), and the player favorite/Love controls (`Player.vue`, `MiniPlayer` favorite toggle, `PlayerPage` hydrate). `package.json`/`package-lock.json` pin the new `v0.57.0` git tag; the Vite bundle was regenerated (now includes `LoveButton-*`). No server PHP changed for the bump.

- **`web-ui`: bumped `@phlix/ui` `v0.55.0` → `v0.56.0` and rebuilt the committed SPA bundle (`public/assets/app/`) (Wave 0 bump).** Picks up the shared admin **Duplicates** page (the UI for the merge API above) and the **Metadata** settings tab's per-media-type source-priority editor. `package.json`/`package-lock.json` pin the new git tag; the Vite bundle was regenerated. No server PHP changed for the bump.

- **Per-user favorites + ratings for media items (E10).** Each user can now mark any media item as a favorite and give it a personal 1-10 rating, persisted server-side. New table `user_item_data(user_id, item_id PK, favorite BOOL, rating INT NULL, updated_at)` (`migrations/039_user_item_data.sql`, FK CASCADE → `users` + `media_items`) and `Phlix\Media\UserItemDataRepository` (flat positional-`?` binding like `UserRepository`/`WatchHistory`; upserts with `INSERT ... ON DUPLICATE KEY UPDATE` per-column so favorite/rating preserve each other; the 1-10 range is enforced in PHP — `setRating()` throws — not via a DB CHECK). Four auth-gated routes on `WebPortalRouter` (handled by `MediaUserDataController`): `POST /api/v1/media/{id}/favorite`, `DELETE /api/v1/media/{id}/favorite`, `PUT /api/v1/media/{id}/rating` (body `{rating: int 1-10|null}`; 400 on non-numeric/out-of-range, 404 if the item is missing), and `DELETE /api/v1/media/{id}/rating` — all return `{message}`. `GET /api/v1/media/{id}` now also carries an **add-only** `user_data: {favorite: bool, rating: int|null}` block (`null` when unauthenticated; `{favorite:false, rating:null}` when authed with no row), leaving every existing detail key untouched. **Favorites/ratings are account-level (keyed on `user_id`, like `user_settings`) — NOT per-profile** like `watch_history`. Registered once on `WebPortalRouter` (not `Application::loadApiRoutes()`) so all three dispatch paths — `public/index.php`, the Workerman daemon's `HttpHandler`, and the relay dispatcher — share it. Existing items have no row until first interacted with, so they simply report `favorite:false, rating:null`; no backfill needed.

### Fixed

- **Docs: `AdminMergeController` docblock corrected.** The merge controller's docblock described the `503` ("merge unavailable") trigger as "the active DB connection is not the transaction-aware `PhlixMySQLConnection`", which was stale after the `SeriesMerger` ctor was widened to the base `Workerman\MySQL\Connection`. It now reads "no transaction-capable base `Connection` bound", reflecting that the merger is wired from whichever connection ships — `PhlixMySQLConnection` (`DB_POOL_ENABLED=0`) or `PooledMySQLConnection` (`DB_POOL_ENABLED=1`). Comment-only change.
- **Hub config is now actually loaded (fixes empty hostname candidates → blank server URL / disabled "Manage").** `config/hub.php` was never wired into the app config, so `HubServicesProvider` fell back to bare defaults — notably `public_url = ''` — and the server advertised no hostname candidates at pairing (`hostname_candidates_json: []`), leaving the hub with no URL for the server and the "Manage" button disabled. `config/server.php` now `require`s `config/hub.php` as its `hub` key (like `ffmpeg`), so the configured `public_url` (`https://<PHLIX_DOMAIN>`) reaches `HubClient`. The hub updates `hostname_candidates_json` from every heartbeat, so existing pairings self-heal on the next heartbeat (no re-pair needed).
- **Manual "Send heartbeat" (admin) no longer fails with cURL "No host part in the URL".** `HubClient::sendHeartbeat()` posts to a relative path via `$this->httpClient`; the heartbeat *loop* swaps that for an enrollment-scoped client, but a direct admin call runs in the HTTP worker where the loop never ran, so it used the empty-base placeholder. `sendHeartbeat()` now (re)builds an enrollment-scoped client from the freshly-loaded enrollment (also picking up a renewed token), leaving test-injected mocks untouched.

- **Hub pairing now completes reliably (server stores enrollment + heartbeat starts without a restart).** Two bugs left a paired server stuck at "not paired / last seen never": (1) the admin SPA's poll→complete flow sent an empty `hubJwksUrl` to `POST /hub/complete`, which required it and returned 400 — so the enrollment was never stored, and because the hub deletes the one-time claim the instant it returns the JWT, re-polling couldn't recover; (2) even once stored, `HubApplication::start()` is one-shot, so the heartbeat loop wouldn't run until a process restart. Now: `hubPoll()` stores the enrollment **server-side** the moment the claim is consumed (atomic, client-independent) and returns `hubJwksUrl`; `hubComplete()` is **idempotent** (returns success when already enrolled instead of 400 on a missing field); and the `phlix-hub-heartbeat` worker **polls for a late-appearing enrollment** (`HubApplication::isEnrolled()`) and starts the heartbeat within ~15s of pairing — no restart needed.

- **Hub pairing now advertises the server's configured public URL.** Under the Workerman daemon `$_SERVER` is empty, so `HubClient::getHostnameCandidates()` reported `[]` and the hub recorded no reachable hostname for the server (blank URL in "My Servers"). The server now advertises its public base URL — derived from `PHLIX_DOMAIN` (set by `scripts/install.sh --domain`) with the scheme from `tls_enabled` — as the preferred hostname candidate during pairing (`config/hub.php` `public_url` → `HubClient`). `install.sh` also now persists `PHLIX_TLS_ENABLED` (from `--tls`/`--no-tls`) so the scheme is correct, and fixes a latent bug where `PHLIX_TLS_ENABLED=0` was ignored (`?: true` treated `'0'` as unset).

- **`PhlixMySQLConnection`: force emulated + buffered prepared statements.** Under the Swoole event loop mysqlnd's socket is coroutine-hooked, so a query yields the coroutine while waiting on the socket. With the parent's **native, unbuffered** prepares each statement keeps per-statement server-side state on that socket which leaks across coroutine yields — wedging the shared connection so the next `prepare()` silently returns `false` (`Call to a member function bindParam() on false`) or params desync (`HY093`) under concurrent requests, even with the per-coroutine query mutex. `connect()` now sets `PDO::ATTR_EMULATE_PREPARES = true` (prepare is client-side only) and `PDO::MYSQL_ATTR_USE_BUFFERED_QUERY = true` (results consumed immediately). Because emulated prepares would otherwise bind params as strings (making `LIMIT ?`/`OFFSET ?` → `LIMIT '50'` → MySQL 1064, and this codebase has many such queries), `execute()` is also overridden to bind each value with its natural PDO type (`int → PARAM_INT`, …), mirroring the parent's prepare/execute + 2006/2013 reconnect. Diagnosed + verified first on phlix-hub (150 concurrent requests → zero corruption; bound/positional/mixed `LIMIT` queries succeed); applied here for parity since this connection class runs the same native-prepare path under the same coroutine runtime. Parameterisation stays injection-safe; charset is utf8mb4.

### Changed

- **`web-ui`: bumped `@phlix/ui` `v0.43.0` → `v0.44.0` and rebuilt the committed SPA bundle (`public/assets/app/`).** Carries the **required companion** to the signed-URL gate (see Security below): the web player now points its direct-play `<video src>` at the server-minted **signed** `stream_url` from `GET /api/v1/media/:id` instead of building a bare `/media/:id/stream` path. Without this bump direct play would `401` once the gate deploys (the SPA holds a `localStorage` token, not a session cookie, and a media element can't attach a `Bearer` header). The hls.js/transcode path already attaches the Bearer token to every segment XHR via `xhrSetup` and is unaffected. **Must deploy together with the gate.** No server application code changed for the bump — `web-ui/package.json`/lockfile pin the new git tag and the Vite bundle was regenerated.

- **`web-ui`: bumped `@phlix/ui` `v0.42.0` → `v0.43.0` and rebuilt the committed SPA bundle (`public/assets/app/`).** Fixes the library grid where the same titles could "stay" on screen while scrolling: the virtual grid measured the scroll offset on a `requestAnimationFrame`-deferred path that stalls under scroll load (rAF is throttled during scrolling, notably on Firefox), freezing the rendered window. The grid now measures synchronously on scroll. No server application code changed — `web-ui/package.json`/lockfile pin the new git tag and the Vite bundle was regenerated.

- **`web-ui`: bumped `@phlix/ui` `v0.41.0` → `v0.42.0` and rebuilt the committed SPA bundle (`public/assets/app/`).** Fixes the A-Z jump rail showing **empty skeleton boxes**: the pre-sized library grid only paged sequentially (append-at-end), so jumping to a letter scrolled to slots whose page was never fetched. The grid now does random-access paging — it loads the page(s) covering the visible window (including after a jump) and places items at their absolute index. No server application code changed — `web-ui/package.json`/lockfile pin the new git tag and the Vite bundle was regenerated.

- **`web-ui`: bumped `@phlix/ui` `v0.40.0` → `v0.41.0` and rebuilt the committed SPA bundle (`public/assets/app/`).** Carries the SPA fix that makes every API client send the logged-in user's Bearer token by default (previously the default token store was a no-op). This is the **required companion** to the media-auth gate above: the SPA now authenticates media/letter-index requests, so requiring auth on those endpoints doesn't break browsing. Must deploy together with the gate (both land on master before `install.sh --update`). No server application code changed for the bump — `web-ui/package.json`/lockfile pin the new git tag and the Vite bundle was regenerated.

- **Media listings now sort & bucket by an article-stripped title — "The Plot" files under P, not T (the title still displays as "The Plot").** Alphabetical browse/library listings and the A-Z jump rail previously ordered and grouped by the raw `name`, so every "The …"/"A …"/"An …" title piled up under T/A. A new `Phlix\Media\Library\SortTitle` derives a sort key by ignoring a single leading article — English `the`/`a`/`an` plus the common Romance/German articles `el`/`la`/`le`/`les`/`los`/`las`/`die`/`der`/`das` (only when the article is a whole word, so "Theory", "Antman" and "Death Race" keep their natural letter). `ItemRepository` applies it to every alphabetical `ORDER BY` (the `/api/v1/media` name sort + its tiebreaks, plus `getByLibrary`/`getByType`/`findByParent`/the rating- & genre-filtered listings) and to the `letter-index` bucket `GROUP BY`, so the rail's cumulative offsets still line up with the grid. The expression is a single source of truth that also powers a new **`sort_title`** field on the media-item API shape (so any client-side sort can agree), and it is **portable + zero-migration**: it uses only `CASE`/`LOWER`/`LEFT`/`SUBSTRING`/`TRIM` with a `COLLATE utf8mb4_bin` prefix test (case-insensitive but accent-sensitive, mirroring PHP `strncasecmp`) — never `REGEXP_REPLACE`, whose case-insensitive form differs between MySQL 8 and MariaDB 10.6 — so it is correct for every existing and future row the instant it deploys, with no schema change or backfill. `date_added`/`year`/`rating`/`runtime` sorts keep their primary key and only gain the article-insensitive alphabetical tiebreak. (Known pre-existing limitation, unchanged here: a title whose first significant letter is accented or non-Latin — e.g. "Élan" — still folds into the rail's `#` bucket while sorting in its locale position, so the rail jump can be slightly off for such titles in multilingual libraries.)

- **`web-ui`: bumped `@phlix/ui` `v0.39.0` → `v0.40.0` and rebuilt the committed SPA bundle (`public/assets/app/`).** Carries the client side of the article-aware sorting above: the `MediaItem.sort_title` field, a `stripLeadingArticle`/`compareByStrippedTitle` helper, and library Browse rails that sort by the article-stripped name (so a "The …"-named library files under its real letter, matching the media listings). The media grid + A-Z rail already reflect the article rule because they render the server's order; this bump keeps the client-sorted surfaces consistent. No server application code changed for the bump — `package.json`/`package-lock.json` pin the new git tag and the Vite bundle was regenerated.

- **`web-ui`: bumped `@phlix/ui` `v0.32.0` → `v0.36.0` and rebuilt the committed SPA bundle (`public/assets/app/`).** Brings the shipped UI work to the served app: **full-width layout** + **clicking a poster opens the info/detail page** (v0.33.0); the **matched/unmatched metadata filter** + **clickable cast** (each cast name opens that title's library filtered to the actor) (v0.34.0); the listing grid **pre-sized to the full result count** with on-demand paging (v0.35.0); and the **A-Z jump rail** on long library listings (v0.36.0, driven by the new `letter-index` endpoint). No server application code changed for the bump — `package.json`/`package-lock.json` pin the new git tag and the Vite bundle was regenerated.

### Security

- **Binary / streaming / feed endpoints now require proof of an authenticated session via short-lived signed URLs (closes the residual `TODO(security)` gap from the media-auth gate below).** The byte-serving routes a `<video>`/`<img>`/`<audio>`/e-reader/native player can't attach a Bearer header to — `/media/{id}/stream`, `/hls/**`, `/dash/**`, `/api/v1/books/{id}/{read,cover,download}`, `/opds/v1.2/**`, `/api/v1/audiobooks/{id}/{read,stream}`, `/api/v1/photo/photos/{id}/{thumbnail,full}` — were reachable by anyone who knew the (UUID) id. They are now gated:
  - **`Phlix\Auth\SignedUrl`** mints/verifies a `?exp=<unix>&sig=<base64url-HMAC-SHA256>` token. The HMAC covers a *canonical resource* (the query-less path) + expiry; for HLS/DASH the resource is the per-job directory prefix (`/hls/{job}`, `/dash/{job}`), so ONE signature on the master-playlist URL authorises every variant playlist, segment and sidecar under it. The signing key comes from `PHLIX_SIGNED_URL_SECRET`, or is derived (domain-separated) from `JWT_SECRET` when unset — so a leaked stream token can't be replayed as a JWT, and vice-versa. Default TTL is 6 h, overridable via `PHLIX_SIGNED_URL_TTL`.
  - **`Phlix\Server\Http\Middleware\SignedUrlMiddleware`** gates those route groups (and an inline equivalent guards `/media/{id}/stream`, which bypasses the router for Workerman `withFile` streaming). It accepts **any** of: an already-authenticated session (`$request->userId` from Bearer **or** the `phlix_session` cookie — so the in-browser player keeps working untouched: hls.js attaches the Bearer token to every segment XHR via `xhrSetup`, and same-origin `<img>`/`<video>` send the cookie automatically), a valid signed-URL token (cookieless/headerless native players, casting, cross-origin), or — for OPDS only — HTTP Basic. Anonymous requests get `401 {code: auth.required}`.
  - **OPDS feeds now support HTTP Basic auth**, because e-reader clients send `Authorization: Basic` (not Bearer) and re-send it on every request. A new session-free `AuthManager::verifyCredentials()` validates the credentials of an *active* account without minting a session; failures return `401` with `WWW-Authenticate: Basic realm="Phlix OPDS"` so the reader prompts. The acquisition `download` link the feed already emitted (`/opds/v1.2/books/{id}/download`) is now actually registered (it was a dead 404), so the whole OPDS flow is authenticated **and** functional.
  - **Minting:** the now-gated JSON detail / transcode endpoints emit the signed URLs in the fields clients already read — `GET /api/v1/media/{id}` (`stream_url`), `getBook`/`readBook` (`cover_url`/`read_url`/`download_url`), `getAudiobook`/`readAudiobook` (`stream_url`/`read_url`), `getPhoto`/`listPhotos`/`getAlbum`/`listAlbums`/`slideshow` (`thumbnail_url`/`full_url`), and `POST /api/v1/media/{id}/transcode` + `…/status` (signed `master_url`/`hls_url`/`dash_url` and signed subtitle track URLs). The photo `slideshow` URLs were also corrected to the real `/api/v1/photo/photos/…` route (they previously pointed at an unregistered `/photo/photos/…` path).
  - The existing JSON listing/search/detail gate (`AuthMiddleware`, below) is **unchanged**. Companion client work lands separately: `@phlix/ui` (the web player consumes the signed `stream_url` and keeps hls.js `xhrSetup`) and the native Roku/Tizen/Windows/mobile clients (consume the server-provided signed URL instead of a bare path).

- **Media & library listing/search endpoints now require a signed-in user (were world-readable).** `GET /api/v1/media` (the SPA's main browse/search), `/api/v1/media/letter-index`, `/api/v1/media/{id}`, `/api/v1/media/{id}/playback`, `/api/v1/libraries{,/{id},/{id}/items}`, the `/api/v1/users/me/*` activity & settings routes, the per-item markers/extras metadata, and the music / books / audiobooks / photos **JSON listing + detail** endpoints had **no auth gate**, so anyone could enumerate a user's entire private library without logging in. A new dependency-free `Phlix\Server\Http\Middleware\AuthMiddleware` (the authenticated-but-not-necessarily-admin counterpart to `AdminMiddleware`) now gates these route groups in BOTH dispatch paths (`WebPortalRouter` for `public/index.php` + the Workerman `HttpHandler`); `$request->userId` is already populated from the Bearer token **or** the `phlix_session` cookie before dispatch, so logged-in SPA and browser-session requests pass while anonymous ones get `401 {code: auth.required}`. **Requires `@phlix/ui` ≥ v0.41.0** (the SPA now sends the token on media requests; older bundles would break browsing). Binary/streaming routes a `<video>`/`<img>`/e-reader can't attach a Bearer header to — `/media/{id}/stream`, `/hls/**`, `/dash/**`, book `cover`/`read`/`download` + OPDS feeds, audiobook `read`/`stream`, photo `thumbnail`/`full` — are **deliberately left open for now** (an item id is an unguessable UUID, only reachable via the now-gated listings) and flagged `TODO(security)` for a follow-up signed-URL / OPDS-Basic-auth pass. Native clients (Roku/Tizen/Windows/mobile) must send their access token on media requests.

- **`web-ui`: bumped `@phlix/ui` to `v0.20.0` — the SPA now validates the session on boot and gates the admin section client-side.** The shared router guard previously treated a token's mere *presence* in `localStorage` as "logged in" (never validating it) and applied no admin-role check, so after a reload a stale/expired token would render every protected route — including the whole `/app/admin/*` console — and the account badge fell back to a generic "A" because the user was never rehydrated. v0.20.0 validates a restored token once via `/auth/me` before the first protected route resolves (clearing it + redirecting to login when invalid) and redirects a logged-in non-admin away from admin routes. The server API already authorized every request (`AdminMiddleware`), so this was client-side broken access control, not data exposure. The committed bundle under `public/assets/app/` was rebuilt; no application code changed.
- **Require admin authentication on theme-media mutation endpoints.** `POST /api/v1/libraries/{id}/theme-media/scan` (`scanThemeMedia`) and `DELETE /api/v1/libraries/{id}/theme-media` (`deleteThemeMedia`) were registered as bare routes with no auth gate, so unauthenticated callers could trigger filesystem scans and delete cached theme media for any library ID. `ThemeMediaController` now carries an optional `AdminMiddleware` (wired in `Application::getThemeMediaController()`, mirroring `LibraryController`) and both mutation methods return `401`/`403` for unauthenticated/non-admin callers before any side effect. The read endpoint (`getThemeMedia`) is unchanged. (Flagged by an external review of an earlier PR; verified still present and fixed here.)

### Fixed

- **"Initiate pairing" (admin → Remote Access) no longer 500s with "Failed to write Ed25519 private key: config/hub-server-key.pem".** The hub-pairing flow persists its runtime state — the server's Ed25519 private key plus `hub-enrollment.json` / `hub-subdomain.json` — into `config/`, but the systemd unit's `ProtectSystem=strict` mounts the install tree read-only **except** the paths in `ReadWritePaths` (which listed `var/`, `.logs`, `templates_c`, … but **not** `config/`), so the key write failed. `scripts/install.sh` now idempotently appends `${INSTALL_PATH}/config` to the unit's `ReadWritePaths` (mirroring the existing `var/` migration) and reloads systemd, so the daemon can persist the pairing key/state. Same root cause + fix shape as the earlier plugin-install `var/` read-only fix. (Applied live to the running unit; this change keeps it across reinstalls/regenerations.)
- **"Initiate pairing" now reaches the hub (fixes "cURL error: URL rejected: No host part in the URL").** After the key-write fix above, pairing got further but then failed: `HubClient::initiatePairing()` / `pollClaimStatus()` passed the operator-entered hub URL only to the *logger*, then called the injected HTTP client with a bare **path** (`/api/v1/server-claims/new`). That injected client is an **empty-base placeholder** (`PHLIX_HUB_URL` is normally unset; the post-enrollment heartbeat/renew loop rebuilds the client from the saved enrollment, but pairing runs *before* enrollment), so cURL received a hostless URL. The two pre-enrollment calls now send the **absolute** `rtrim($hubUrl,'/') . '/api/v1/…'`, and `HttpClient` uses an already-absolute path verbatim (a bare path still resolves against the configured base, and an empty resulting URL now throws instead of reaching cURL). Pairing reaches the hub. Unit tests assert the absolute URL is sent.
- **The "Install plugin" admin form now actually installs a plugin (fixes the silent no-op).** Even after the repo-URL fix, pasting a plugin's GitHub URL still failed: the source downloaded + extracted fine, but the plugin's `plugin.json` was rejected by the manifest schema with *"manifest is invalid: 9 errors"*. `detain/phlix-shared`'s settings schema used `additionalProperties: false` allowing only `type`/`required`/`secret`/`default`, so every real plugin's per-setting **`label`/`description`** (and `type: "boolean"`) failed validation — the install returned a 422 that the SPA surfaced as a generic failure, perceived as "nothing happening". Bumped `detain/phlix-shared` to **v0.9.1**, whose manifest schema permits per-setting `label`/`description` and accepts `integer`/`boolean` as aliases of `int`/`bool`. Verified the anidb + myanimelist manifests now validate against the vendored schema. (`install.sh --update` / `composer install` pulls the new schema; no application code changed.)
- **Cast / actors now populate on movie & TV detail pages, and the actor filter actually matches (fixes empty cast lists).** TMDB cast was fetched but lost or mis-shaped before it reached the UI: the bulk "Match metadata" path (`MovieMetadataResolver::merge()`) dropped `actors` entirely, the interactive "apply match" path stored them as TMDB *objects* (`{name, role, order}`), and TV details never requested credits at all — while the API shaper, the SPA cast chips and the `actors[]` filter all expect a flat list of name strings (so cast lists rendered empty and "filter by actor" matched nothing). A shared `MetadataValue::actorNames()` reduces either shape to a de-duplicated list of names; both movie paths persist names, the TV path now appends `aggregate_credits` (`TmdbProvider::getTvDetails`) and carries the series cast (`formatSeriesDetails`), `MediaItemShaper` normalises on read (so already-stored object data renders without a re-match), and the `ItemRepository` actor filter matches **both** `$.actors[*]` (names) and `$.actors[*].name` (legacy objects). The director is also now carried through the bulk movie path. Re-run "Match metadata" to backfill cast on existing items.
- **HTTP worker no longer segfaults under the Swoole coroutine runtime (`worker[phlix-server-http] exit with status 139`).** `start.php` enabled `Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL)`, which hooks *every* native call — file IO (io_uring), `proc_open`/`shell_exec`/`exec` (the on-demand HLS/CMAF transcode spawns a detached `ffmpeg`) and native curl — and re-drives each on the coroutine scheduler. On the production stack (**PHP 8.5 + Swoole 6.2.1 + kernel 7 with `io_uring` enabled**) those hooks crashed the worker with recurring general-protection faults **inside `swoole.so`** (175 such exits observed in `workerman.log`; `dmesg` traps point at `swoole.so`, and the crashes correlate with transcode spawns in the request path). New `Phlix\Server\Runtime\SwooleRuntime` applies a **curated allowlist** of just the network + sleep hooks (`TCP/UDP/UNIX/UDG/SSL/TLS/STREAM_FUNCTION/SLEEP/SOCKETS`); every other hook — file IO/io_uring, `proc_open`, curl, stdio, and the unnamed blocking-function hook that re-drives `exec`/`shell_exec` (the ffmpeg spawn, the exact trigger) — is excluded **by construction** and runs as an ordinary blocking syscall, while the coroutine MySQL pool + network IO still yield. (An allowlist is used rather than `SWOOLE_HOOK_ALL` minus a blocklist because `SWOOLE_HOOK_BLOCKING_FUNCTION` is not a named constant in Swoole 6.2.1 yet its bit is set in `SWOOLE_HOOK_ALL`, so it can't be subtracted.) Configurable in `config/server.php`: `coroutine.enabled => false` hard-disables the hook (Swoole stays the event loop), and `coroutine.hook_flags` takes an explicit `SWOOLE_HOOK_*` bitmask override. NOTE — the durable fix is to align the runtime: PHP 8.5 + Swoole 6.2.1 + io_uring is bleeding-edge; also consider `swoole.enable_iouring=0` and a Swoole build verified against the installed PHP.
- **The admin "Install plugin" form now accepts a GitHub repository URL (fixes "install failed" on a pasted repo URL).** `HttpInstaller` could only fetch a *direct archive* (`.zip`/`.tar.gz`/`.tgz`) or a `.json` stub, so pasting a repository URL like `https://github.com/detain/phlix-plugin-anidb` — which the admin UI and `plugin:install` CLI both invite — had no recognised extension: the installer downloaded the repo's HTML landing page and threw *"Unsupported plugin source extension"*, surfaced as a generic 422 "install failed". A new `Phlix\Plugins\Installer\SourceUrlResolver` rewrites a git-host repository URL to its default-branch tarball — `https://github.com/OWNER/REPO[.git]` → `https://github.com/OWNER/REPO/archive/HEAD.tar.gz` (and `/tree/BRANCH` → `…/archive/BRANCH.tar.gz`), also accepting the scheme-less `github.com/OWNER/REPO` and `git@github.com:OWNER/REPO.git` SSH forms. `HEAD` resolves to the default branch with no GitHub API call. The resolver runs inside `HttpInstaller::install()` (covering the admin API **and** the CLI, plus recursive `.json` stub `source` fields) and at the `PluginAdminController` scheme-guard boundary, so a scheme-less paste is accepted instead of 400-rejected. Direct archive URLs, `file://` paths, release-asset/`/archive/…` URLs and non-GitHub hosts pass through unchanged (idempotent). Verified end-to-end: the resolved tarball downloads and unpacks to a single root dir with `plugin.json` at the expected depth.
- **The single media-item endpoint now returns the enriched shape (fixes blank covers/overview on the detail & player pages).** `GET /api/v1/media/{id}` (`WebPortalRouter::getMediaItem` and `MediaItemController::show`) returned the raw DB row — no `poster_url`/`poster_srcset`/`genres`/`overview`/`season_number`/`episode_title`/… — while only the *list* endpoint shaped its rows, so the detail page (`/app/media/:id`) and the player hero rendered a blank cover and empty metadata even when the title had TMDB data. The shaping logic was extracted to a shared `Phlix\Media\Library\MediaItemShaper` (`shape()` for list rows, `shapeDetail()` = the schema shape merged over the raw row + `streams`, preserving intro/outro markers, chapters and `metadata`). Both the single-item handlers and the list now use it, so the two entry points can't drift again.
- **On-demand transcode now produces a browser-decodable H.264 stream (fixes "We couldn't prepare a playable version").** The CMAF/HLS encode ran `libx264` with no pixel-format or profile pin, so a 10-bit source (e.g. an HEVC "Main 10" file — common for 1080p TV rips) was re-encoded straight into **10-bit "High 10" H.264**, which no browser's Media Source Extensions / hls.js can decode: the playlist and segments served fine but hls.js raised a fatal decode error and the player fell back to the "can't prepare a playable version" notice. `FfmpegRunner::buildCmafCommand()` / `buildHlsCommand()` now force `-pix_fmt yuv420p -profile:v high -level 4.1` (8-bit 4:2:0 High@4.1, the universally browser-decodable baseline; overridable via `pix_fmt`/`profile`/`level` params) on every software H.264 re-encode. Separately, `TranscodeManager::computeHlsParams()` no longer takes the fast `-c:v copy` remux path for an H.264 *source* that is itself 10-/12-bit or 4:2:2/4:4:4 (`isBrowserSafeH264()` gate) — those are re-encoded to 8-bit instead of passed through unplayable. Verified end-to-end against a live HEVC Main 10 file: output is now `avc1.640029` (High@4.1, `yuv420p`) vs the old undecodable `avc1.6e0028` (High 10).
- **Honor HTTP `Range` requests in audiobook & photo streaming.** `AudiobookController::streamAudiobook()` and `PhotoController::getFull()` read the range header via raw `$request->headers['Range']` array access, but `Request::parseHeaders()` stores header keys upper-cased (`RANGE`), so the mixed-case lookup never matched and every range/seek request silently fell through to a full `200` instead of a `206` partial response. Both now read via the case-insensitive `Request::getHeader('Range')`, restoring seek/resume.

### Added

- **A-Z jump index for the media list — `GET /api/v1/media/letter-index?<same filters>`.** For the default name-ascending sort, returns the absolute item offset of the first title in each first-letter bucket (`#` folds non-alphabetic first characters and is placed first), honoring the SAME filters as `/api/v1/media`. The list endpoint's filter-building was extracted to a shared `ItemRepository::buildFilters()` so the list and the index can't drift. Drives the SPA's A-Z jump rail, which scrolls the pre-sized grid to `offset` (empty buckets are returned with count 0 so the rail can disable them).
- **Live progress for the "Match metadata" run.** `LibraryMetadataMatcher::matchLibrary()` now takes an optional progress callback and reports `processed`/`total` per 100-item batch (`total` = the library's top-level movie+series count). `LibraryScanWorker` forwards it onto the job row — `items_found` = total, `items_updated` = processed — so `GET /api/v1/libraries/{id}/scan-status` exposes a real percentage (`items_updated / items_found`) while a metadata match runs, instead of just `queued → running → completed`. (Scan/rescan jobs still report lifecycle only — `LibraryManager::scanLibrary()/rescanLibrary()` emit no per-item counts yet.)
- **Filter the media list by match status — `GET /api/v1/media?match=matched|unmatched`.** Backed by `media_items.metadata_refreshed_at` (NULL = never metadata-matched), so the UI can surface items still needing a metadata pass (`unmatched`) or already-enriched ones (`matched`). Composes with every other filter and with the `total` count.
- **TV series / season / episode metadata matching (fixes blank series, season & episode info + covers).** `LibraryMetadataMatcher` previously matched only `movie`/`video` items, so every series, season and episode got zero TMDB data — blank posters and empty synopses across all TV. New `SeriesMetadataResolver` (TMDB TV) + `TmdbProvider::searchTv()/getTvDetails()/getTvSeason()` resolve a series by name (+ optional first-air year), persist its poster/backdrop/overview/genres/year, then enrich the whole subtree from one `/tv/{id}/season/{n}` call per season: each season inherits the season (or series) poster + overview, and each episode gets its TMDB title, still, overview, air date and runtime — falling back to the series poster so nothing renders blank. Wired into the existing per-library "Match metadata" run (`SeriesMetadataResolver` injected into `LibraryMetadataMatcher`); `TmdbProvider`'s HTTP client is now injectable for testing. Uses the same admin-managed TMDB key as movie matching (Settings → Metadata).

  - **3.5 SyncPlay group watching UI + theme switcher.** New `/admin/syncplay` SPA page listing SyncPlay groups with Create/Join/Leave actions. New `SyncPlayApi` (`admin-ui/src/api/syncPlay.ts`) + `SyncPlayPage` (`admin-ui/src/pages/SyncPlayPage.tsx`) + 10 Vitest tests. New `SyncPlayController` (`src/Server/Http/Controllers/SyncPlayController.php`) wrapping `SyncPlayManager` with 5 REST endpoints: `GET/POST /api/v1/syncplay/groups`, `GET /api/v1/syncplay/groups/{id}`, `POST /api/v1/syncplay/groups/{id}/join|leave`. Theme switcher added to SSR settings page (`public/templates/settings/index.tpl`) — "Appearance" section with auto-save on change, no Save button required. `UserRepository.updateSettings()` now handles `theme` field writing to `user_profiles.active_theme_id`; `getSettings()` reads it back. `WebPortalRouter.extractSettingsPayload()` adds `theme` to the allow-list. Vitest: 10/10 on `syncPlay.test.ts`; phpstan level 9: pass; phpcs PSR12: pass; PHPUnit Unit: 2696 tests pass.

  - **2.5 Live TV / DVR admin SPA page (`/admin/live-tv`) — 4-section UI (Tuners, Guide/EPG, Recordings, Series Rules) consuming 20 LiveTV API endpoints from step 2.4.** New SPA: `admin-ui/src/pages/LiveTvPage.tsx` (4 collapsible sections — all collapsed by default, expand triggers lazy data load; Schedule Recording modal pre-fills from Guide; Add Rule modal with channel picker loading in parallel with rules; form validation with `form__error` messages). New `admin-ui/src/api/liveTv.ts` (`LiveTvApi`: 20 typed wrappers across 5 resource groups — tuners 5, channels 4, guide 3, recordings 6, seriesRules 5). `useToast()` destructured as `const { push: pushToast } = useToast()` (stable reference). All buttons use `disabled={isLoading} aria-busy={isLoading}` pattern. Defensive optional chaining on all state variable length accesses for React StrictMode compatibility. 32 Vitest tests: 22/22 on `liveTv.test.ts` (100%), 10/10 on `LiveTvPage.test.tsx` (100%), ≥80% on `LiveTvPage.tsx`. PHPStan level 9: pass. PHPCS PSR12: pass. PHPUnit Unit: 2696 tests pass (10 skipped, 7092 assertions). RemoteAccessPage (14/14) confirmed no regression.

  - **2.4 Live TV / DVR REST API (20 admin-gated endpoints).** Tuners (list/get/scan/update/delete), channels (list/get/update/stream), guide (list/refresh + program lookup), recordings (list/get/create/delete/upcoming/by-series), series rules (list/get/create/update/delete). New `AdminLiveTvController` (`src/Server/Http/Controllers/Admin/AdminLiveTvController.php`) — 964 lines, 20 endpoints wired under `AdminMiddleware` in `Application::loadLiveTvAdminRoutes()`. Manager classes (`LiveTvManager`, `ChannelManager`, `GuideManager`, `Recorder`, `SeriesRuleManager`) resolved via `$this->container->get()`. Migration `028_livetv_base.sql` creates 6 `livetv_*` tables with `CREATE TABLE IF NOT EXISTS` (`livetv_tuners`, `livetv_channels`, `livetv_programs`, `livetv_favorites`, `livetv_lineups`, `livetv_lineup_channels`). DVB-T scan deferred (stubbed `DvbtTunerDriver::performChannelScan` not exposed). PHPStan level 9: pass. PHPCS PSR12: pass. PHPUnit Unit: 2696 tests pass (10 skipped, 7092 assertions). No SPA in this step — UI arrives in step 2.5.

  - **2.3 Remote access admin SPA page (`/admin/remote-access`) — hub pairing, subdomain, relay tunnel, port-forward.** New `admin-ui/src/pages/RemoteAccessPage.tsx` (4 collapsible sections: Hub Pairing, Subdomain, Relay Tunnel, Port Forward — all collapsed by default, expand triggers lazy data load). New `admin-ui/src/api/remoteAccess.ts` (`RemoteAccessApi`: 16 typed wrappers across 4 resource groups — `getHubStatus`/`pairHub`/`unenrollHub`/`sendHeartbeat`/`getRelayCandidates`, `getSubdomainStatus`/`claimSubdomain`/`releaseSubdomain`/`updateSubdomain`/`verifySubdomain`, `getRelayStatus`/`enableRelay`/`disableRelay`/`pingRelay`, `getPortForwardStatus`/`togglePortForward`). New `AdminHubController` (`src/Server/Http/Controllers/Admin/AdminHubController.php`) exposing all 16 endpoints wired under `AdminMiddleware` in `Application::loadRemoteAccessRoutes()`. `useToast()` destructured as `const { push: pushToast } = useToast()` (stable reference). `togglePortForward` propagates HTTP 500 with `{ success: false, message: "…" }` as an error toast. Vitest: 22/22 on `remoteAccess.test.ts` (100%), 14/14 on `RemoteAccessPage.test.tsx` (100%), ≥80% on `RemoteAccessPage.tsx`. Overall SPA: 36 passing tests.

  - **Admin SPA: DLNA server status/toggle (step 2.2).** New `admin-ui/src/pages/DlnaServerPage.tsx` (`/admin/dlna-server`) — status card showing green/red running indicator, friendly name, Start and Stop buttons with `aria-busy` loading state and toast feedback (success, error, and info-toast on 409 already-running/stopped no-op). New `admin-ui/src/api/dlnaServer.ts` (`DlnaServerApi`: `getStatus()`/`start()`/`stop()`). New `src/Server/Http/Controllers/Dlna/AdminDlnaServerController.php` exposing `GET /api/v1/admin/dlna/status` (returns `{ running, enabled, friendly_name, uptime_seconds }`), `POST /api/v1/admin/dlna/start`, and `POST /api/v1/admin/dlna/stop`, all wired under `AdminMiddleware` in `Application::loadDlnaAdminRoutes()`. `CdsServer` is injected via `setCdsServer()`; when the container has no `CdsServer` registration the controller returns `enabled: false` gracefully. `useToast()` destructured as `const { push: pushToast } = useToast()` (stable reference). Vitest: 8/8 on `dlnaServer.test.ts` (100%), 10/10 on `DlnaServerPage.test.tsx` (100%), ≥80% on `DlnaServerPage.tsx`. Overall SPA: 18 passing tests.

  - **Admin SPA: Stats & dashboard page (1.6)** — replaced the Phase-0 placeholder with a rich 5-section dashboard at `/admin/dashboard`. **Now Playing** (live list with progress bars, 30s auto-refresh), **Top Users** (30d leaderboard table), **Top Media** (30d ranked list with type badges), **Storage** (breakdown cards by media type + transcode cache), **Recent Activity** (paginated feed with event-type badges). New `admin-ui/src/api/dashboard.ts` (`DashboardApi`: `getNowPlaying`/`getTopUsers`/`getTopMedia`/`getStorage`/`getActivity`) + `admin-ui/src/api/stats.ts` (`StatsApi`: `getPlaybackStats`/`getTopUsers`/`getTopMedia`/`getStorageStats`). Date range filter (7d/30d/90d) affects Top Users/Top Media/Activity. All 5 sections have loading skeletons + empty states. `useToast()` destructured as `const { push: pushToast } = useToast()` (stable reference). No `dangerouslySetInnerHTML`. No new PHP — consumes existing `DashboardController` + `StatsController` endpoints (already wired in `AdminRoutes`). Vitest: 301/302 tests (99.7%); dashboard.ts 100%, stats.ts 100%, DashboardPage.tsx ≥80%.

 - **Admin SPA: Services page (1.4c) — Trakt.tv OAuth connect/disconnect + Last.fm scrobbling connect/disconnect.** New `admin-ui/src/pages/ServicesPage.tsx` (`/admin/services`) — two-card layout: **Trakt.tv** card (connected/not-connected badge, Connect button navigates via `window.location.href` to `/api/v1/oauth/trakt`, Disconnect button POSTs to `/api/v1/admin/services/trakt/disconnect`); **Last.fm** card (connected/not-connected badge, Connect navigates to `/admin/lastfm`, Disconnect POSTs to `/api/v1/admin/services/lastfm/disconnect`). Status polled on mount via `GET /api/v1/admin/services/trakt/status` and `GET /api/v1/admin/services/lastfm/status`. New `admin-ui/src/api/trakt.ts` (`TraktApi`) and `admin-ui/src/api/lastfm.ts` (`LastfmApi`). Backend adds four endpoints to `LastfmController` (`status()`, `apiDisconnect()`) and `TraktOAuthController` (`status()`, `disconnect()`), all wired under `AdminMiddleware`. Last.fm smarty routes remain registered for the connect callback; `client_secret` never leaves the server. Vitest: 100% on `services.test.ts`, `ServicesPage.test.tsx`; 71.42% on `trakt.ts` and `lastfm.ts` (uncovered = `window.location.href` browser redirects, untestable in Node).

 - **Admin SPA: Backup / restore page (1.5) — backup list with create/restore/delete/S3 upload + schedule settings.** New `admin-ui/src/pages/BackupPage.tsx` (`/admin/backup`) — two sections on a single route: **Backup list** card with "Create backup" button (optional label input → `POST /api/v1/admin/backup/create`), a `DataTable` listing all backups (Label/Size/Created/S3 status/Actions columns), per-row Restore modal ("This will overwrite your current data. Continue?" + Cancel/Restore), Delete modal ("Are you sure? This cannot be undone." + Cancel/Delete), and Upload to S3 button (hidden when `is_s3 === true`); **Scheduled backups** card with interval + retention form pre-filled from `GET /api/v1/admin/backup/schedule`, saved via `PUT /api/v1/admin/backup/schedule`, displaying next backup as relative and absolute time. New `admin-ui/src/api/backup.ts` (`BackupApi`) with 7 typed wrappers: `list()`/`create()`/`delete()`/`restore()`/`uploadToS3()`/`getSchedule()`/`updateSchedule()`. `Backup` shape (7 fields: id/label/file_path/size_bytes/checksum_sha256/is_s3/created_at + expires_at null); `BackupSchedule` shape (auto_backup_interval_days/retention_count/next_scheduled_backup/next_scheduled_backup_iso). Backend wires all 7 endpoints in `Application.php` via `loadBackupRoutes()` under `AdminMiddleware`. `useToast()` destructured as `const { push: pushToast } = useToast()` (stable reference, no `useEffect` re-runs). No `dangerouslySetInnerHTML`. Vitest: 100% on `backup.ts` and `BackupPage.test.tsx`; 89.23% on `BackupPage.tsx` (≥80% target). Overall SPA: 95.67% statements, 88.38% branches, 83.12% functions, 95.67% lines.

 - **Admin SPA: Integrations page (step 1.4b) — Arr TRaSH-Guides sync + OIDC/LDAP auth provider config.** New `admin-ui/src/pages/IntegrationsPage.tsx` (`/admin/integrations`) — two sections: **Arr sync** (TRaSH-Guides-compatible Sonarr/Radarr/Bazarr/Prowlarr metadata sync) with last-sync status card, "Sync now" manual-trigger button (30 s timeout guard, spinner during call), and enable/disable auto-sync toggle; **Auth providers** listing OIDC + LDAP with per-provider enable/disable, inline configure forms (OIDC: provider_url/client_id/client_secret/scopes; LDAP: host/port/ssl/base_dn/bind_dn/bind_pw/user_filter/admin_group with show/hide toggles and "Test connection" dry-run), all pre-filled from GET settings on expand. New `admin-ui/src/api/arrSync.ts` (`ArrSyncApi`) with `getStatus()`/`triggerSync()`/`setEnabled()` wrapping the sync controller contract; new `admin-ui/src/api/authProviders.ts` (`AuthProvidersApi` + `OidcApi` + `LdapApi`) wrapping the auth-provider/OIDC/LDAP controller contracts. Secret fields are write-only — GET settings never returns them, and blank POST values are omitted so the server keeps the existing value. Enabled state derived from `configured` boolean the server returns per provider. Vitest coverage: 100% on `arrSync.ts`, `arrSync.test.ts`, `authProviders.ts`, `authProviders.test.ts`, and `IntegrationsPage.test.tsx`; 81.71% on `IntegrationsPage.tsx` (uncovered = defensive error-path guards). Overall SPA: 95.92% statements, 89.23% branches, 82.79% functions, 95.92% lines.

- **Admin SPA: Webhooks page with full CRUD + test (step 1.4a).** New `admin-ui/src/pages/WebhooksPage.tsx` (`/admin/webhooks`) — DataTable listing (name, URL, event-count badge, Edit/Test/Delete row actions), Add/Edit modal (name + URL + secret with Show/Hide + event checkboxes grouped by 5 categories), Delete confirm modal, Test result modal (green/red outcome display). New `admin-ui/src/api/webhooks.ts` (`WebhooksApi`) with `list()`/`create()`/`update()`/`remove()`/`test()` methods. `SUBSCRIBABLE_EVENTS` (7 events) and `WEBHOOK_EVENT_CATEGORIES` are hardcoded in the TS layer; `webhook.test` is excluded from the UI (internal to test). Secret is write-only — GET never returns it; edit form shows empty field with "(unchanged)" placeholder and omits `secret` from PUT when blank. `remove()` handles 204 No Content gracefully by mapping to `{ message: 'Webhook deleted' }`. `test()` parses the actual controller response (`success`/`success_count`/`failure_count`/`failures`) and `WebhooksPage` builds a human-readable message for display. Vitest coverage: 97.29% on `webhooks.ts`, 89.74% on `WebhooksPage.tsx`.

- **Backend: `PUT /api/v1/admin/webhooks/{id}` route for editing webhooks (step 1.4a carry-fix).** `WebhookDispatcher::update(array{name?, url?, events?})` — partial-update method that only writes provided fields, uses a parameterized query, and logs changed fields. `WebhookAdminController::update()` — validates `id` (fail-fast 400), extracts only name/url/events, returns `200 { webhook }` on success. Route wired in `Application.php` alongside the existing index/create/delete/test routes. No new endpoints for the other four operations — those were already registered.

- **Admin SPA: Settings page with 8 group tabs for server configuration (step 1.3).** New `admin-ui/src/pages/SettingsPage.tsx` (`/admin/settings`) renders all 15 allow-listed server settings across 8 tabbed sections (Transcoding, Metadata, Markers, Subtitles, Discovery, Trickplay, Newsletter, Port Forward). No new backend endpoints — the page consumes the 0.5 GET/PUT `/api/v1/admin/settings` contract already shipped in step 0.5. Field types drive the control: `bool` → toggle switch; `int`/`float` → number input with `min`/`max` from schema constraints; `tmdb.api_key` → password input with Show/Hide toggle. Overridden keys (DB-persisted vs. config-file default) display a "custom" badge driven by the `overridden` array in the GET response. Dirty-state gating keeps the Save button disabled when no fields have changed. `PUT /api/v1/admin/settings { settings }` on save; 200 re-renders with refreshed `overridden`; 400 surfaces per-field inline errors; 500 shows an error toast. New `admin-ui/src/api/settings.ts` (`SettingsApi`) wraps the GET/PUT contract with envelope unwrapping; both methods throw `ApiError` on non-2xx. Vitest coverage: 100% on `settings.ts` and `SettingsPage.test.tsx`, 88.16% on `SettingsPage.tsx`.

- **Admin profile management API: list, get, create, update, delete, set-pin, delete-pin endpoints (step 1.2b).** New `src/Server/Http/Controllers/Admin/AdminProfileController.php` with 7 REST endpoints for managing user profiles (`GET /api/v1/admin/users/{userId}/profiles`, `POST /api/v1/admin/users/{userId}/profiles`, `GET /api/v1/admin/profiles/{id}`, `PUT /api/v1/admin/profiles/{id}`, `DELETE /api/v1/admin/profiles/{id}`, `POST /api/v1/admin/profiles/{id}/pin`, `DELETE /api/v1/admin/profiles/{id}/pin`). Routes are registered inside the existing `AdminRoutes` group with `AdminMiddleware` gating. Enforces ≤5 profiles per user (400 when limit reached), validates PIN as exactly 4 or 6 digits (400 for other lengths), and supports clearing PIN via null/empty string. Unit tests cover ~100% of the new controller.

- **Admin user management API: list, get, create, update, delete, set-admin, reset-password endpoints (step 1.2a).** New `src/Server/Http/Controllers/Admin/AdminUserController.php` with 7 REST endpoints for managing server users (`GET /api/v1/admin/users`, `GET /api/v1/admin/users/{id}`, `POST /api/v1/admin/users`, `PUT /api/v1/admin/users/{id}`, `DELETE /api/v1/admin/users/{id}`, `POST /api/v1/admin/users/{id}/set-admin`, `POST /api/v1/admin/users/{id}/reset-password`). Routes are registered inside the existing `AdminRoutes` group with `AdminMiddleware` gating. Passwords are hashed with Argon2ID via `password_hash(PASSWORD_ARGON2ID)`; `reset-password` generates a random 12-character password returned in the response for admin sharing. Last-admin guard prevents deleting or demoting the final admin user; self-delete/self-demotion is blocked. `UserRepository` gained `findAll()`, `delete()`, `countUsers(string $predicate)`, and `emailExists(string $email, ?int $excludeId)` to support the controller. Unit tests cover ~100% of the new controller.

- **Library management admin page (step 1.1c).** New `/admin/libraries` SPA page in the admin console — the first real feature page on top of the 0.4 scaffold. Lists every library (name, type, path count, live scan-status badge) in the shared `DataTable`; an **Add library** modal + form posts `{name, type, paths, options?}` to `POST /api/v1/libraries`; an **Edit** modal pre-fills the same form and `PUT`s `{name, paths}` (the controller ignores `type`, and the form shows it read-only); a **Delete** confirm modal hits `DELETE /api/v1/libraries/{id}`. Path entry uses a new reusable `PathPicker` component driving the 0.6 `GET /api/v1/admin/fs/browse` endpoint (roots → drill-down → select; jailed to the configured `browse_roots`). Per-row **Scan** / **Rescan** buttons consume the **async** 1.1b API: they `POST .../scan|rescan` → `202 {job_id, status: "queued", message}` and the page starts polling `GET .../scan-status` every 2 s for that library (interval period injectable via `pollIntervalMs`). Polling **stops** on a terminal status (`completed`/`failed`) or `null`, and every outstanding interval is cleared on unmount via a `useRef` of per-library timers — no leaked timers, no global mutable state. Progress is **coarse / lifecycle-only** by design (the 1.1b worker leaves `items_*` at `0` and `current_path` at `null`), so the UI renders the status badge + `error` string only and deliberately does **not** draw a fabricated per-file progress bar. A per-library **History** modal loads `GET .../scan-history?limit=20` (server clamps `[1,100]`, newest first) into a `DataTable`. The `book` library type is **deliberately excluded** from the type select: the `libraries.type` ENUM (migration 001) is exactly `movie|series|music|photo|video`, even though `LibraryController::create()` *also* lists `book` in `$validTypes` (a `book` insert would 500 at the DB ENUM — pre-existing backend mismatch, carry-over for a later step). New `admin-ui/src/api/libraries.ts` (`LibrariesApi`) and `admin-ui/src/api/filesystem.ts` (`FilesystemApi`) are typed 1:1 wrappers over the `ApiClient` that unwrap the single-key envelopes (`{libraries}`, `{library}`, `{scan_status}`, `{history}`, fs-browse `{success, data}`) so callers get the bare domain object; non-2xx still throws `ApiError`. `LibrariesPage` adds a sidebar entry under Dashboard and a `<Route path="/libraries">` in `App.tsx`. Architecture note worth knowing: the page destructures the **stable** `push` callback from `useToast()` (`const { push: pushToast } = useToast();`) rather than depending on the whole context value — the provider memoises `[toasts, push, dismiss]`, so depending on the context object re-fires every `useCallback`/`useEffect` on every toast push (which during a scan would consume the next-mocked response as a stray `GET /libraries` and crash `DataTable`). All four test files (`libraries.test.ts`, `filesystem.test.ts`, `PathPicker.test.tsx`, `LibrariesPage.test.tsx`) drive a real `ApiClient` through the `makeFetch` concrete-mock helper against REAL-shaped responses (the 0.4 fabricated-contract lesson). Vitest coverage: **98.73%** statements overall; per file `libraries.ts`/`filesystem.ts` 100%, `PathPicker.tsx` 98.24%, `LibrariesPage.tsx` 95.62% (uncovered ≈ defensively-unreachable guards and `||`-fallback templates). **PHP side untouched** — no controller, route, migration, or worker change; only the committed admin-ui source + the rebuilt `public/assets/admin/` bundle.
- **Async library-scan worker + scan-status/scan-history endpoints (step 1.1b).** Moves library scanning off the HTTP request and onto a Workerman-native managed worker process that drains the 1.1a `library_scan_jobs` queue. New `src/Media/Library/LibraryScanWorker.php` (`Phlix\Media\Library`): `runOnce()` atomically claims the oldest queued job via `ScanJobRepository::claimNext()` (returns `false` when nothing is queued), runs the existing `LibraryManager::scanLibrary()`/`rescanLibrary()` by `type`, then `markCompleted()` on success or `markFailed($jobId, $e->getMessage())` on any `\Throwable` (returns `true` either way — a job was processed); a claimed row missing a usable `id`/`library_id` is defensively logged + skipped. `start(int $pollSeconds)` installs a `Workerman\Timer` that calls `runOnce()` once per tick — **never a blocking `sleep()`** (the legacy `BackgroundDetectorWorker::runLoop()`'s `sleep()` is the resident-memory violation this worker deliberately does not copy); a backlog of N drains in ≤ N ticks. **Progress is coarse by design** — `scanLibrary()`/`rescanLibrary()` return `void` with no counts, so the worker records the honest `queued → running → completed/failed` lifecycle and leaves `items_*` at 0 (no fabricated counts; no scan-internals expansion). New `config/process.php` is the single source of truth for the worker settings (`library-scan` => `enabled`/`count:1`/`poll_seconds:5`) in the conventional Webman filename, but carries PLAIN settings because this app boots through a hand-rolled `start.php` (not `support\App::run()`), so the file is read explicitly rather than auto-consumed by the framework. Two run paths read it: `start.php` now spawns the worker as a managed `count:1` sibling `Worker` under the same `Worker::runAll()` process group (additive + guarded — a worker build failure cannot take down the HTTP workers), and the standalone `scripts/run-library-scan-worker.php` runs it as its own isolated service (e.g. a dedicated systemd unit); running both at once is safe because `claimNext()` is atomic and each worker is `count:1`. `LibraryScanWorker` is autowired in `MediaServicesProvider`. New read endpoints: `GET /api/v1/libraries/{id}/scan-status` → `200 { scan_status: <latest job row|null> }` (a library with no jobs yet is a valid `200` with `null`, not a `404`) and `GET /api/v1/libraries/{id}/scan-history?limit=N` → `200 { history: [<job row>, ...] }` (newest first; `limit` defaults to 20, clamped to `[1,100]` by the repo). Both are admin-gated (least-privilege — `current_path` is a server filesystem path; the 1.1c progress page is admin-only) and `404` on a missing library. Wired in `Application::loadLibraryRoutes()` (now 9 LibraryController routes); the Router compiles `{id}` to `[^/]+` and anchors patterns with `#^...$#`, so the 2-segment `{id}` (show) route cannot match these 3-segment literal paths and vice-versa — no shadowing in either direction regardless of registration order. Verified by unit tests: `LibraryScanWorkerTest` (every `runOnce` branch — scan, rescan, nothing-queued, scan-throws→markFailed, rescan-throws→markFailed, defensive bad-row, unknown-type→scan) and the rewritten/extended `LibraryControllerTest` (scan/rescan enqueue+202 with `scanLibrary`/`rescanLibrary` asserted never-called, scan-status happy/null/404/401, scan-history happy/limit/404/401).
- **Library scan-job data layer (step 1.1a).** A DB-backed store that records the lifecycle of a library scan (`queued → running → completed/failed`) plus its progress counters — the foundation the 1.1b async scan worker writes to and the scan-status/scan-history endpoints read from. **No behaviour change in this step** (no controller/worker is wired yet). New migration `migrations/027_library_scan_jobs.sql` creates the `library_scan_jobs` table (`id` CHAR(36) UUID PK, `library_id` CHAR(36) with `fk_lsj_library` FK → `libraries(id) ON DELETE CASCADE`, `type` ENUM `scan|rescan`, `status` ENUM `queued|running|completed|failed`, `items_found`/`items_added`/`items_updated`/`items_removed` counters, nullable `current_path`/`error`, and `queued_at`/`started_at`/`completed_at` timestamps; `idx_lsj_library`, `idx_lsj_status`, `idx_lsj_library_queued` indexes; `CREATE TABLE IF NOT EXISTS` so the migration runner can replay it idempotently). New `src/Media/Library/ScanJobRepository.php` (`Phlix\Media\Library`) exposes `enqueue()` (inserts a `queued` row; rejects a `type` other than `scan|rescan` with `InvalidArgumentException`), `claimNext()` (atomically claims the oldest `queued` job via a conditional `UPDATE ... WHERE id=? AND status='queued'`, honouring the claim only when the affected-row count is ≥ 1 so a double-claim can't slip through; returns the claimed row or `null` when nothing is queued), `updateProgress()`/`markCompleted()` (write only the recognised `items_*` counters), `markFailed()`, `findById()`, `getLatestForLibrary()` (powers `scan-status`), and `getHistoryForLibrary()` (powers `scan-history`; clamps `$limit` to `[1, 100]`). All access is through the async `Workerman\MySQL\Connection` client with parameterised queries; UUIDs come from the local `generateUuid()` helper; rows are defensively decoded (int counters, nullable timestamps). Autowired in `MediaServicesProvider`. **The `claimNext`/`updateProgress`/`mark*` methods are intentionally unused in this PR — they are consumed by the 1.1b worker.** Verified by unit tests (mocked `Connection`, every method, both `claimNext` branches, the invalid-type reject, the `$limit` clamp) and a real-DB round-trip integration test (`enqueue → claimNext → updateProgress → markCompleted`) that self-skips when no MySQL is reachable.
- **CI: "Admin UI" GitHub Actions workflow builds + Vitest-tests the admin SPA (step 1.0).** New `.github/workflows/admin-ui.yml` (workflow `Admin UI`, single job `Admin UI Build + Test` on `ubuntu-latest`) runs `npm ci → npm run build` (`tsc --noEmit && vite build`) → `npm run test` (`vitest run`) with `working-directory: admin-ui` on every `push`/`pull_request` to `master`/`main`/`develop`. It is **path-filtered** to `admin-ui/**` and the workflow file itself, so PHP-only PRs don't spin up Node while SPA changes (and the open Vite 5→8 dependabot PR #131) do trigger it. Node is pinned to LTS `20` via `actions/setup-node@v4` with npm cache keyed on `admin-ui/package-lock.json`; `actions/checkout@v6` matches the sibling workflows. Least-privilege `permissions: contents: read` (no write scope, no secrets) keeps it safe for fork PRs. This closes the 0.4 carry-over where the SPA build + 55 Vitest tests ran only locally; build is green and 55/55 Vitest tests pass.
- **`bin/phlix` service-wrapper commands (step 0.8b).** Eleven thin console commands built on the 0.8a CLI machinery, each registered on the same `bin/phlix` application: `library:list` (lists all libraries via `LibraryManager::getAllLibraries()`), `library:scan {libraryId} [--rescan]` (`LibraryManager::scanLibrary()` / `rescanLibrary()`), `plugin:list` (`PluginLoader::listInstalled()` with enabled state), `plugin:enable {name}` / `plugin:disable {name}` / `plugin:install {source}` / `plugin:uninstall {name}` (the `PluginLoader` lifecycle — `install` prints the resulting plugin name + version), `backup:create [--label=]` (`BackupManager::createBackup()`, prints id/path/size) / `backup:list` (`BackupManager::listBackups()`), `hwaccel:probe` (`HwaccelProbe::probe()`, renders detected vendors/encoders/codecs), and `user:reset-password {user} [--password=]` (looks the user up by username then email via `UserRepository::findByUsername()`/`findByEmail()`, then `UserRepository::update(['password' => …])` which Argon2ID-hashes — when `--password` is omitted a strong random password is generated with `bin2hex(random_bytes(12))` and printed). Each command takes a per-service factory `callable` and resolves its backing service LAZILY from the PHP-DI container only inside `execute()`, so `php bin/phlix list` still builds NO container and touches NO database; `bin/phlix` wires those factories behind a single memoizing container-provider closure (`$container ??= ContainerFactory::create($config)`) that replicates `start.php`'s config assembly minus the Swoole/worker bootstrap. Commands never `exit()`/`die()`; they `return` `Command::SUCCESS` (0) on success and `Command::FAILURE` (1) on a thrown/“not found” failure (error messages are rendered with `<error>` markup). Verified by one `CommandTester` unit test class per command (mocked services, success + failure paths; `user:reset-password` additionally covers found-by-username, found-by-email fallback, not-found→exit 1, missing-id→exit 1, explicit `--password`, and generated-password).
- **`webman/console` CLI baseline + `bin/phlix migrate` (step 0.8a).** Added `webman/console` and a custom `bin/phlix` executable so the project has a real CLI (closing the long-standing "bin/phlix doesn't exist" gap). Because `webman/console` only auto-discovers commands from an `app/command` directory this repo doesn't have, `bin/phlix` instead explicitly registers `Phlix\Console\Commands\*` instances on a `Webman\Console\Command` application (which extends Symfony's Console Application) and runs it — `php bin/phlix list` shows the commands, `php bin/phlix migrate` runs them. The migration-apply logic that lived inline in `scripts/run-migrations.php` is extracted into a new testable service `src/Common/Database/MigrationRunner.php` (`Phlix\Common\Database`): it discovers `migrations/*.sql` via `glob()`+`sort()`, splits each file into statements (stripping `--` and `/* */` comments so a `;` inside a comment never shreds a statement), runs each via `Workerman\MySQL\Connection::query()`, and downgrades the known idempotent-replay errors (`Duplicate column name` / `Duplicate key name` / `check that column/key exists` / `already exists`) to notes instead of failures. There is **no migration-tracking table** — every file is applied on every run, preserving the apply-all-every-time contract that `docker/docker-entrypoint.sh` and `scripts/install.sh` depend on. The connection is resolved lazily (only inside `run()`), so `bin/phlix list` and command construction work with no database available. `MigrateCommand` (`Phlix\Console\Commands`) renders a human summary and returns exit code `0` on success / `1` when a genuine non-idempotent error occurred. `scripts/run-migrations.php` is now a thin shim that boots the same `MigrationRunner` and prints the same summary with the same exit semantics, so the docker/install callers are unaffected. Verified by unit tests: `MigrationRunnerTest` (mocked `Connection`, temp fixture `.sql` files — sort order, comment-aware splitting, idempotent-downgrade vs genuine-error branches, empty-dir, no-connection-at-construction) and `MigrateCommandTest` (via Symfony `CommandTester` — exit 0 on success/notes, exit 1 on a genuine error). Wrapper commands for the other scripts (`library:*`, `plugin:*`, `backup:*`, `hwaccel:probe`, `user:reset-password`) land in step 0.8b.
- **Admin filesystem-browse endpoint for the library path picker (step 0.6).** New `GET /api/v1/admin/fs/browse?path=…` (`src/Server/Http/Controllers/Admin/FsBrowseController.php`) lists the immediate **subdirectories** of `path` (directories only — never files; no read/write/delete) so a future "add library" UI can offer a path picker. New `config/filesystem.php` defines the `browse_roots` allow-list (default `['/home', '/mnt', '/media', '/data']`) — the security boundary the listing is jailed to (env override deliberately omitted to keep the boundary explicit/auditable). Traversal safety mirrors the canonical `AudiobookController::validateMediaPath()` jail: every candidate path is canonicalised with `realpath()` (which collapses `..` and resolves symlinks) and checked against each root with a trailing-slash prefix test (`$real === $root || str_starts_with($real . '/', $root . '/')`, **never** `str_contains`), so `..` escapes, symlinks pointing outside the jail, and non-allowed roots are all rejected with `403`. Status mapping: empty/absent `path` → `200` returning the configured roots as the entry list (`data.path`/`data.parent` = `null`); `realpath()` fails (non-existent) → `404`; resolves but not a directory → `400`; resolves outside the jail → `403`; valid dir under a root → `200` `{ success, data: { path, parent, entries:[{name,path}] } }` (entries sorted by name, `parent` only when it is itself within the jail else `null`). The route sits in the existing `/api/v1/admin` group registered in `src/Server/Http/Routes/AdminRoutes.php`, gated by `AdminMiddleware` (non-admin callers get a JSON 401/403); bound via a `factory()` in `AdminServicesProvider` that loads `browse_roots` from config (roots that do not `realpath()`-resolve are dropped at construction). **API only — the path-picker / library-management UI lands in Phase 1.1.** Verified by unit tests covering all security paths (traversal/symlink-escape/non-allowed-root → 403, 404, 400, roots-list, parent-within-jail, ctor-drops-non-resolving-root); new-code coverage 91.1% (72/79 statements) — the only uncovered lines are the defensively-unreachable `catch (Throwable)` → 500 and `scandir() === false` arms (a valid, jail-checked, readable directory cannot trip them).
- **Server-wide settings store + admin API (step 0.5).** A DB-backed store so admin settings pages have somewhere to persist (the `config/*.php` files are boot-time / read-only). New migration `migrations/026_server_settings.sql` creates the typed key/value `server_settings` table (`id` CHAR(36) UUID PK, unique `setting_key`, text `setting_value`, `value_type` ENUM `string|int|bool|float|json`, timestamps; `CREATE TABLE IF NOT EXISTS` so the migration runner can replay it idempotently). `src/Admin/SettingsRepository.php` (`Phlix\Admin`) models the runtime contract **config default → DB override → effective value**: the value baked into `config/<file>.php` is the baseline, a row in `server_settings` overrides it, and the effective value is the override when present else the default. Keys are *dotted* — the first segment names the config file and the rest walk the returned array (e.g. `hwaccel.enabled` reads `config/hwaccel.php['enabled']`, `port-forward.port_forwarding.upnp_enabled` walks two levels). Upserts use `INSERT ... ON DUPLICATE KEY UPDATE` (mirrors `UserRepository::updateSettings()`) exclusively through the async `Workerman\MySQL\Connection` client with parameterised queries; the config-file segment is regex-jailed (`^[A-Za-z0-9_-]+$`) against path traversal. New `src/Server/Http/Controllers/Admin/AdminSettingsController.php` exposes `GET /api/v1/admin/settings` (returns `{ success, data: { settings, overridden, types } }` — effective values, the list of overridden keys, and the allow-list type map) and `PUT /api/v1/admin/settings` (body `{ settings: { "<dotted.key>": value, ... } }`) which validates every submitted key against a typed allow-list (`ALLOWED_KEYS`): unknown keys → 400, wrong types → 400, **all-or-nothing** (nothing persists if any key fails), then upserts the overrides. Both routes sit inside the existing `/api/v1/admin` group registered in `src/Server/Http/Routes/AdminRoutes.php`, gated by `AdminMiddleware` (non-admin callers get a JSON 401/403). Persisted overrides **survive a restart** because the DB is the durable store. **API only — the settings UI lands in Phase 1.3.** Validation is inline pending step 0.7's shared `server-settings.schema.json`, which will later replace/back the `ALLOWED_KEYS` map (a `0.7:` seam comment marks the spot). Verified by unit + integration tests (round-trip persist → fresh repository re-read); new-code coverage: `SettingsRepository` 100% (103/103 statements), `AdminSettingsController` 98.8% (the single uncovered line is the defensively-unreachable `json` arm of `valueMatchesType()` — no allow-list key is type `json` yet).
- **Admin SPA scaffold (step 0.4).** A React + TypeScript + Vite admin console now mounts at `/admin` + `/admin/*`, served by the new `src/Server/WebPortal/Controllers/AdminAppController.php` (returns the built `index.html` shell; 503 with an actionable "run `npm run build`" message when the bundle is absent) and gated by the existing `AdminMiddleware::checkAccess()` — a 401 (unauthenticated) or 403 (non-admin) maps to a 302 redirect to `/login`. The SPA source lives in `admin-ui/`; the production bundle is built into `public/assets/admin/` and **committed to the repo** (`admin-ui/node_modules/` is gitignored), so the running Workerman server has **no Node build dependency at runtime**. Dispatch is wired in BOTH entry points (`public/index.php` and `src/Server/Workerman/HttpHandler.php`), placed AFTER the existing `/admin/plugins` + `/admin/dashboard` SSR routes so those keep winning. The typed `ApiClient` (`admin-ui/src/api/client.ts`) reuses the existing JWT mechanism from `public/assets/js/api-client.js` (same `localStorage` keys `access_token`/`refresh_token`/`user`, Bearer header, single-retry-on-401 via `POST /auth/refresh`); `getCurrentUser()` consumes `GET /api/v1/auth/me`, unwrapping its `{ user: {...} }` envelope and normalising the DB `TINYINT` `is_admin` (`1`/`0`) to a real boolean. This is a working shell/scaffold only — nav, router, a typed API client, and shared components (DataTable, Form, Modal, Toast); no feature pages yet (those land in Phase 1). Verified by Vitest (~99% coverage on the new SPA modules) + an `AdminAppControllerTest` (shell 200 / 503-missing / 302-redirect).
- **Bare-metal Swoole + php-uv build (step 0.3).** `scripts/install.sh` and `install/systemd.sh` now compile the Swoole and php-uv extensions from source as part of a fresh install (and on the `scripts/install.sh --update` repair path), giving the step 0.2 coroutine runtime real extensions on Debian/Ubuntu hosts — not just in Docker. The Swoole `./configure` flag set is copied **verbatim** from `docker/Dockerfile.base` (see `docker/README.md` "Swoole build flags" for the per-flag rationale); php-uv is built with `--with-uv`. The apt `-dev` build dependencies (`build-essential autoconf pkg-config git libssl-dev libuv1-dev libbrotli-dev libzstd-dev libnghttp2-dev libpq-dev libsqlite3-dev libc-ares-dev liburing-dev libssh2-1-dev`, plus the version-matched `phpX.Y-dev` for `phpize`) are the Debian translation of the Alpine set. The build is **idempotent**: each step short-circuits via `php -m` when the extension already loads, so re-running the installer never triggers the slow recompile. `--enable-iouring` / `--enable-uring-socket` build on any kernel but only activate at runtime on Linux kernel ≥ 5.6 (older kernels fall back to epoll automatically).
- **Workerman disable-function preflight (step 0.3).** A new preflight in both installers fails loudly and early if `disable_functions` blocks any process-control / posix / socket primitive Workerman needs to fork workers and manage sockets (`pcntl_*`, `posix_*`, `proc_*`, `exec`/`shell_exec`, `stream_socket_*`), with an actionable message pointing the operator at their `php.ini` (and php-fpm pool config) — instead of a cryptic runtime crash after install. Uses an exact-token match (no substring false-positives).
- **Swoole + php-uv loaded in the PHPUnit CI job (step 0.3).** The PHPUnit jobs in `.github/workflows/phpunit.yml` (`test` and `test-server`) now load both extensions — `swoole` via `shivammathur/setup-php` and php-uv via a source-build step — and verify them with `php -m | grep -iE '^(swoole|uv)$'` before the suite runs, so the full test suite exercises the coroutine runtime in CI. CI runs on host runners (not containerized); the existing MySQL service container and coverage steps are unchanged. Neither extension is added as a hard composer platform requirement.
- **Coroutine runtime enabled (step 0.2).** `start.php` and `public/index.php` now set `Worker::$eventLoop = \Workerman\Events\Swoole::class` before any `Worker` instantiation, and call `Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL)` in the master process to enable full coroutine I/O. The code degrades gracefully with a `E_USER_WARNING` when ext-swoole is not yet available.
- **Coroutine micro-bench (step 0.2b).** Added `scripts/bench/coroutine_bench.php` — a small, dependency-free smoke-test that fires N coroutines through `SWOOLE_HOOK_ALL`-hooked `time_nanosleep()` and asserts wall-clock ≤ 1.5× a single-unit run (so concurrent requests demonstrably do not serialize). Exits 0 on pass, 1 on fail, 2 if `ext-swoole` is absent. Local run: serial ≈ 100 ms, concurrent N=4 ≈ 102 ms (≈3.9× speedup over the serialized ≈ 400 ms baseline). The pre-existing `scripts/bench/concurrent_streams.php` still works but needs a live HLS endpoint + media-id and is not CI-friendly.

### Changed

- **`web-ui`: bumped `@phlix/ui` to `v0.19.0` (admin composability + Hub Dashboard).** Behaviour-neutral for the
  server: `buildAdminRoutes()` (no args) still yields the same 16 admin routes/names, so the mounted admin section,
  the `/app/admin/dashboard` landing, and the sidebar order are unchanged. The committed SPA bundle under
  `public/assets/app/` was rebuilt against the new tag. (v0.19.0 makes the shared admin shell composable so the hub
  can mount its own page group; the server keeps the default set. Two unused lazy chunks — `HubDashboardPage` and
  `AuditLogsPage` — are now emitted into the bundle because `@phlix/ui`'s admin registry statically references the
  hub page group; the server router never registers those routes, so the chunks are never fetched at runtime.)
- **WebAuthn/passkeys settings: routed `/settings/security` to `auth/webauthn-settings.tpl`.** Template now loads correctly (fixed broken `layouts/app.tpl` → `layouts/main.tpl`). Inline error messages replace `alert()` calls. Credential IDs are XSS-safe.

- **Settings page: replaced thin-shell form with working GET/PUT form backed by `/api/v1/users/me/settings`.** Streams, bitrate, audio/subtitle language, subtitle mode, and parental control settings now persist correctly.
- **`POST /api/v1/libraries/{id}/scan` and `.../rescan` now enqueue + return `202` instead of scanning inline (step 1.1b).** `LibraryController::scan()`/`rescan()` previously called `LibraryManager::scanLibrary()`/`rescanLibrary()` synchronously inside the HTTP handler (the blocking-HTTP async-rule violation) and returned `200 { message: "Library scan started" }`. They now keep the admin gate + library-existence `404` check, then `ScanJobRepository::enqueue($id, 'scan'|'rescan')` a job and return `202 { job_id, status: "queued", message: "Library scan queued"|"Library rescan queued" }`; the async `LibraryScanWorker` performs the actual scan off the HTTP path. `LibraryController`'s constructor gains a second `ScanJobRepository` parameter (passed in both branches of `Application::getLibraryController()` — the container branch resolves it from DI, the null-container fallback reuses the already-built connection; the pre-existing hardcoded fallback creds are left untouched as a separate follow-up). The CLI `bin/phlix library:scan` is unchanged and stays synchronous.
- **`AdminSettingsController` now derives its editable-settings allow-list from the shared `server-settings.schema.json` (step 0.7).** Bumped `detain/phlix-shared` to `^0.7.0` and replaced the hardcoded `ALLOWED_KEYS` constant — the `// 0.7:` inline-allow-list seam is removed. The dotted-key → internal-type map (the PUT validation source and the GET `types` map) is now loaded once from the vendored schema (located via `Phlix\Shared\Schema\SchemaPaths::serverSettings()`) and cached in a static; each JSON-Schema `type` is mapped to the internal vocabulary (`boolean→bool`, `integer→int`, `number→float`, `string→string`, `array`/`object→json`). The schema declares exactly the same 15 settings keys with the same types, so GET/PUT behaviour and validation are unchanged; a missing/unparseable schema fails safe to an empty allow-list (a new lock-in unit test asserts the derived map equals the expected 15 keys/types so drift or a missing vendored schema is caught loudly). `valueMatchesType()`/`coerce()` and the `SettingsRepository`-only constructor are untouched.
- **Upgraded to Webman 2.2 / Workerman 5.1.** Pinned `workerman/workerman` to `~5.1` and `workerman/webman-framework` to `~2.2` as a prerequisite for coroutine support (step 0.2). No other changes — routing, controllers, and DI wiring remain unchanged.
- **Coroutine-safe per-request state via `support\Context` (step 0.2b).** Audited `src/Server/` for `protected|private|public static $`, `global $`, and `$GLOBALS[…]` carrying per-request data; the audit found **zero offenders** (only PHP's built-in `global $http_response_header;` for `file_get_contents()` exists, outside `src/Server/`). Introduced `Phlix\Server\Http\RequestContext` — a thin typed wrapper around `support\Context` — as the canonical place to publish and read per-request data (today: the authenticated user-id). `AdminMiddleware` now publishes `$request->userId` into the coroutine-local context on a successful admin gate so downstream services can read it without re-passing the `Request`, and explicitly does NOT publish anything on 401/403 paths. New `tests/Unit/Server/Coroutine/ContextIsolationTest.php` proves per-fiber isolation and exercises the `ext-swoole` graceful-fallback branch under a captured error handler. Documented end-to-end in `phlix-docs/docs/dev/coroutine-runtime.md` (eventLoop, hooks, no-static-state rule, `exit`/`die`/`sleep()` ban, contributor checklist).

### Fixed

- **`start.php` Swoole eventLoop assignment used the wrong identifier (step 0.2c cumulative-pass).** The 0.2a PR (#126) shipped `Worker::$eventLoop = \Workerman\Events\Swoole::class`, which raised `Access to undeclared static property Workerman\Worker::$eventLoop` on every `php start.php <subcommand>` invocation (status / stop / restart / reload all crashed). Workerman 5's actual static is `Worker::$eventLoopClass` — `$eventLoop` is an *instance* property used to override the eventLoop on a single Worker. Fixed in `start.php`; added `tests/Unit/Server/Coroutine/EventLoopBootstrapTest.php` to guard against the typo regressing (asserts `$eventLoopClass` exists as a public static, `$eventLoop` exists as an instance, and the literal idiom from `start.php` compiles and assigns without fatal).

### Added

- **Web-portal HTML pages for music, books, audiobooks, and photos.** The Smarty templates under `public/templates/{music,books,audiobooks,photo}/` existed but were never wired to a page route — only `home`, `library`, `auth`, and the admin dashboard rendered. Four SSR controllers now back them (`MusicPageController`, `BookPageController`, `AudiobookPageController`, `PhotoPageController` in `src/Server/WebPortal/Controllers/`), rendering via `PageRenderer::renderTemplate()` and sourcing data from the same managers as the JSON API. `public/index.php` routes the page paths:
  - Music: `/music` (albums), `/music/albums/{name}`, `/music/artists`, `/music/artists/{name}`, `/music/tracks`, `/music/player`.
  - Books: `/books`, `/books/{id}`, `/books/{id}/read`, plus `/books/{id}/cover` and `/books/{id}/download` delegating to `BookController`.
  - Audiobooks: `/audiobooks`, `/audiobooks/{id}`, `/audiobooks/{id}/read`.
  - Photos: `/photo/albums`, `/photo/album/{id}`, `/photo/photo/{id}`, `/photo/slideshow`, plus `/photo/photos/{id}/thumbnail` and `/photo/photos/{id}/full` delegating to `PhotoController`.
  Controllers are registered in `WebPortalServicesProvider`. Covered by unit + Smarty-render tests in `tests/Unit/Server/WebPortal/Controllers/`.

### Fixed

- **Media page templates rendered nothing because of mismatched Smarty block names.** `layouts/main.tpl` and `layouts/player.tpl` only expose a `main` block, but the books/audiobooks templates declared `content` (and the audiobook player `player-content`) and every photo template declared `body` (which would have replaced the entire sidebar layout). All now use `main`. Also fixed a corrupted modifier in `music/artist.tpl` (`|自己不做了:00` → `|default:'0:00'`), a broken `{math}`/bareword duration expression and an unregistered-function `min()` call in `music/tracks.tpl`, and a missing-parenthesis duration modifier in `audiobooks/audiobook.tpl` that emitted a "non-numeric value" warning. `AudiobookPageController` normalizes `metadata.chapters` to an array so the detail/player templates can `count()`/iterate it without a TypeError.
- **Media-library routes now share the `/api/v1` prefix with the rest of the JSON API.** `Application::loadMusicRoutes()`, `loadBookRoutes()`, `loadAudiobookRoutes()`, and `loadPhotoRoutes()` registered their endpoints at the bare root (`/music/...`, `/books/...`, `/audiobooks/...`, `/photo/...`) while `phlix-docs` `reference/api.md` and every other metadata route (auth, media, sessions, collections, libraries, cast, dlna) used `/api/v1`. Clients following the docs hit a 404. All music, book (non-OPDS), audiobook, and photo routes are now mounted under `/api/v1`, matching the docs. OPDS keeps its spec path `/opds/v1.2` (deliberately un-prefixed). The unused `Router::music()/books()/audiobooks()/photo()` convenience helpers were aligned to the same prefix so they no longer document a contradictory layout. Guarded by `RouterMediaRoutesTest` (unit) and `ApplicationTest::testMediaRoutesAreRegisteredUnderApiV1` (integration, asserts the live route table). No client consumed the old paths, so this is not a breaking change in practice.
- Wired four previously-defined-but-orphaned `AuthController` endpoints into `Application::loadApiRoutes()` (Section 1.6a). Each handler existed on the controller but had no route, so requests 404'd: `POST /api/v1/auth/register`, `POST /api/v1/auth/login`, `POST /api/v1/auth/refresh`, `GET /api/v1/auth/me`. The `me` endpoint relies on `$request->userId` being populated by upstream auth middleware (same convention as `/api/v1/me/continue-watching`).
- Replaced the stale `// Placeholder for API routes - will be populated in later phases` comment at the top of `Application::loadApiRoutes()` — the method already wires ~40 routes today. New comment describes the actual API surface (auth, sessions, media, WebAuthn, DLNA/Chromecast/AirPlay/Roku, admin) and points readers at `src/Server/Http/Controllers/`.
- Wired four previously-defined-but-orphaned `MarkerController` endpoints into `Application::loadApiRoutes()` (Section 1.6c). The handlers existed but had no route, so requests 404'd: `GET /api/v1/media/{id}/markers`, `GET /api/v1/media/{id}/markers/intro`, `GET /api/v1/media/{id}/markers/outro`, `GET /api/v1/shows/{id}/markers/bulk`. Resolves the controller from the PSR-11 container with a hand-wired fallback (matches the `getAuthController()` pattern).
- Wired three previously-defined-but-orphaned `ExtrasController` endpoints into `Application::loadApiRoutes()` (Section 1.6c). The handlers existed but had no route, so requests 404'd: `GET /api/v1/media/{id}/extras`, `GET /api/v1/media/{id}/trailers`, `GET /api/v1/media/{id}/extras/other`. Resolves the controller from the PSR-11 container with a hand-wired fallback (matches the `getAuthController()` pattern); `MediaServicesProvider` now binds `TmdbProvider` to a factory that reads the API key from `$appConfig['tmdb']['api_key']` or the `TMDB_API_KEY` env var.
- Added `config/tmdb.php` with a `getenv('TMDB_API_KEY')` default so operators can enable TMDB lookups without code changes.
- **Operator action required:** Set `TMDB_API_KEY` environment variable
  to enable trailer fetching via the new ExtrasController routes.
  Without it, /api/v1/media/{id}/trailers and related endpoints
  return no results from TMDB (local extras cache still works).

### Added (post-O.7 wave 4, G.3)

- Last.fm scrobble plugin (`src/Plugins/Scrobbler/Lastfm/`):
  - `LastfmApi` — Web Service v2 client. Builds `api_sig` per the official rule (alphabetical key+value concat + shared secret + MD5).
  - `LastfmSessionRepository` — per-user session-key store backed by the new `lastfm_sessions` table (migration `023_lastfm_sessions.sql`).
  - `LastfmScrobbler` — PSR-14 listener; subscribes to `phlix.playback.started` (Now Playing) and `phlix.playback.stopped` (scrobble). Enforces Last.fm's official rule: scrobble only when the track is longer than 30 s AND the user listened to more than 50 % of it.
  - `LastfmPlugin` — `\Phlix\Shared\Plugin\LifecycleInterface` entry class; resolves dependencies from the host container on `enable()` and exposes the scrobbler via `subscribedEvents()`.
  - `LastfmConfig` — typed wrapper over `config/lastfm.php`. New config keys default to `LASTFM_API_KEY`, `LASTFM_SHARED_SECRET`, `LASTFM_CALLBACK_URL`, `LASTFM_ENABLED` (env-driven).
  - Admin connect flow: `GET /admin/lastfm`, `GET /admin/lastfm/callback`, `POST /admin/lastfm/disconnect` (`Admin\LastfmController`) plus a Smarty template at `public/templates/admin/lastfm.tpl`.
- New required env vars (only when enabling the plugin): `LASTFM_API_KEY`, `LASTFM_SHARED_SECRET`. Optional: `LASTFM_CALLBACK_URL`, `LASTFM_USERNAME`, `LASTFM_ENABLED`, `LASTFM_SUBMIT_NOW_PLAYING`.

### Moved (post-O.7 wave 4)

- K.3 request UI: moved to phlix-hub (now lives at `/api/v1/me/requests` on the hub, with the admin queue at `/api/v1/admin/requests`). Server no longer exposes `/api/v1/requests`, `/requests` (SSR), `/requests/{id}`, or the `requests` table — those were dropped along with `migrations/016_media_requests.sql`. The hub stores requests against its own `users` table (hub migration `011_media_requests.sql`) and dispatches approvals through Sonarr/Radarr via `Phlix\Shared\Arr` v0.4.0.

### Changed (post-O.7 wave 3)

- Helm chart fleshed out for both `phlix-server` and `phlix-hub` (values + templates: deployment, service, ingress, pvc, configmap, secret, serviceaccount, hpa, NOTES).
- Caddyfile WebSocket headers fixed (`Connection: upgrade` / `Upgrade: websocket` — previously inverted).
- nginx `/media/` location now uses `proxy_request_buffering off` so large client uploads stream through; sensitive-path deny rule tightened to `^/+(...)(/|$)` to defeat double-slash bypass.
- Dockerfile `composer install` no longer suffixed with `|| true` — composer failures now fail the build (default Alpine variant + NVIDIA/Intel HW-accel variants). Path-layout rationale documented in `docker/README.md`.
- CI: added `phlix-hub` build/push job in `.github/workflows/docker.yml`.
- CI: `.github/workflows/release.yml` now verifies `Chart.yaml` `appVersion` and `composer.json` `version` match the release tag, lints + packages charts, and uploads them with the release.

### Removed

- **`src/Chromecast/RemoteCastClient.php` (and its test)** — dead code with zero callers. It was premised on a server-initiated *outbound* "cast over relay" channel that the hub relay does not provide: the relay (`RelayConsumer`) pipes *inbound* client connections to the local HTTP server, so a remote client reaching this server through the hub already lands on the normal `/api/v1/cast/devices/{id}/*` routes, which drive the device via the local `CastApiClient`. Remote casting therefore works through the relay's HTTP pipe with no dedicated client; the throwing `RemoteCastClient` stub was redundant and has been removed.
- `SESSION_HANDOFF.md` (commit 9758a1b, message "upate"): obsolete handoff scratchpad no longer referenced anywhere. No functional change.

### Added (Step L.1)

- Webhook plugin framework for sending events to HTTP endpoints:
  - `WebhookEvent` — event class with eventType, payload, occurredAt, toArray(), getSignature() using HMAC-SHA256
  - `WebhookDispatcher` — registers/unregisters/dispatches webhooks, uses Workerman\MySQL\Connection and Workerman\Timer for async dispatch
  - `DispatchResult` — result class with successCount, failureCount, failures
  - `WebhookPluginInterface` — interface with getName(), getSupportedEvents(), send()
  - `migrations/018_webhooks.sql` — webhooks and webhook_logs tables
  - `WebhookAdminController` — GET/POST/DELETE /api/v1/admin/webhooks, POST test endpoint
  - `config/webhooks.php` — configuration with enabled, timeout, max_retries, parallel_dispatch
  - Unit tests: `WebhookEventTest` (5 tests), `WebhookDispatcherTest` (7 tests)

### Added (Step L.2)

- Notification provider plugins for webhook events:
  - 7 plugins: Discord, Slack, Telegram, Ntfy, Pushover, Apprise, MQTT
  - `AbstractNotificationPlugin` — base class with formatMessage(), getEmbedColor()
  - `WebhookPluginInterface` — getName(), getSupportedEvents(), send()
  - `PluginRegistry` — plugin management with get(), listAll(), register()
  - `config/notifications.php` — all 7 provider configurations
  - Unit tests: DiscordPluginTest (7), SlackPluginTest (6), TelegramPluginTest (6), NtfyPluginTest (7)

### Added (Step L.3)

- Stats collection system for tracking playback, library changes, user activity, and storage:
  - `migrations/019_stats_schema.sql` — 4 tables: stats_playback_events, stats_library_changes, stats_user_activity, stats_storage
  - `StatsCollector` — service with recordPlaybackStart/End, recordLibraryChange, recordUserActivity, recordStorageSnapshot, getPlaybackStats, getTopUsers, getTopMedia
  - `StatsController` — admin API: GET /api/v1/admin/stats/playback, top-users, top-media, storage
  - `PlaybackController` integration — calls StatsCollector on play start/end
  - Unit tests: `StatsCollectorTest` (7 tests)

### Added (Step L.4)

- Admin dashboard with real-time now playing, top users/media leaderboards, storage summary, and recent activity feed:
  - `DashboardService` — aggregation service with getNowPlaying(), getTopUsers(), getTopMedia(), getStorageSummary(), getRecentActivity()
  - `DashboardController` — admin API: GET /api/v1/admin/dashboard/now-playing, top-users, top-media, storage, activity
  - `DASHBOARD_NOW_PLAYING` WebSocket event for live updates
  - `subscribe_dashboard` WebSocket handler to send current now-playing state
  - `public/templates/admin/dashboard.tpl` — Smarty template with Now Playing grid, Top Users/Media tables, Storage usage, Activity feed
  - `PageRenderer::renderDashboard()` — renders dashboard page
  - `/admin/dashboard` route in `public/index.php`
  - Unit tests: `DashboardServiceTest` (5 tests)

### Added (Step L.5)

- Weekly newsletter email system for user engagement:
  - `migrations/020_newsletter.sql` — newsletter_queue table with id, user_id, week_start, status, attempts, last_attempt_at, sent_at, error_message
  - `config/newsletter.php` — configuration with enabled, send_day, send_hour, batch_size, from_email, from_name, subject_template
  - `NewsletterGenerator` — generates email content with watch time, top media, new items using Smarty template
  - `NewsletterSender` — queues and processes newsletter delivery with batch processing and retry logic
  - `public/templates/emails/newsletter.tpl` — responsive HTML email template with watch summary, top 5 media, new items, CTA button, unsubscribe link
  - `Application::startNewsletterTimerIfEnabled()` — Workerman Timer integration for scheduled newsletter delivery
  - Unit tests: `NewsletterGeneratorTest` (4 tests), `NewsletterSenderTest` (5 tests)

### Added (Step L.6)

- Server backup and restore system with local storage, S3-compatible cloud backup, and automatic scheduling:
  - `migrations/021_backups.sql` — backups table with id, label, file_path, size_bytes, checksum_sha256, is_s3, created_at, expires_at
  - `config/backup.php` — configuration with enabled, local_path, retention_count, auto_backup_interval_days, s3 settings
  - `RestoreResult` — result class with success, message, error properties
  - `S3Client` — minimal S3-compatible client using AWS Signature V4 for upload, download, listObjects, deleteObject
  - `BackupManager` — backup creation with mysqldump + tar.gz, restore with checksum verification, S3 upload/download, retention management
  - `BackupController` — 7 admin API endpoints: POST create, GET list, DELETE delete, POST restore, POST upload-s3, GET/PUT schedule
  - `Application::startBackupTimerIfEnabled()` — Workerman Timer integration for scheduled backups
  - Unit tests: `BackupManagerTest` (11 tests), `S3ClientTest` (10 tests)

### Added (Step K.2)

- Bazarr/Prowlarr API clients for subtitle and indexer management:
  - `BazarrClient` — Bazarr API client with getSubtitles(), getSubtitleLanguages(), downloadSubtitle(), getLanguages(), testConnection()
  - `ProwlarrClient` — Prowlarr API client with getIndexers(), getIndexerStats(), getHealth(), triggerReindexerCheck(), testConnection()
  - Extended `config/arr.php` with bazarr and prowlarr sections
  - Unit tests: `BazarrClientTest` (9 tests), `ProwlarrClientTest` (8 tests)

### Added (Step K.1)

- Sonarr/Radarr API clients for media server integration:
  - `ArrClientInterface` — common interface for *arr clients with getQueue(), getQualityProfiles(), getTagList(), testConnection()
  - `SonarrClient` — Sonarr v3 API client with getSeries(), getSeriesById(), getEpisodeFile(), getQueue(), getWantedMissing(), getQualityProfiles(), getTagList(), addSeries(), triggerDownload(), testConnection()
  - `RadarrClient` — Radarr v3 API client with getMovies(), getMovieById(), getQueue(), getQualityProfiles(), getCustomFormats(), getTagList(), addMovie(), triggerDownload(), testConnection()
  - `ArrClientFactory` — factory for creating Sonarr/Radarr clients from config array
  - `config/arr.php` — configuration file for Sonarr/Radarr connection settings
  - Unit tests: `SonarrClientTest` (12 tests), `RadarrClientTest` (11 tests), `ArrClientFactoryTest` (10 tests)

### Added (Step J.6)

- Roku ECP support — send media to Roku devices:
  - `RokuDevice` — Roku device descriptor with deviceId, name, host, port, model, softwareVersion
  - `RokuDiscovery` — discovers Roku devices via mDNS `_ roku-ecnp._tcp.local.` using MdnsDiscovery
  - `RokuEcpClient` — HTTP ECP client with launchChannel(), playMedia(), sendKeypress(), getDeviceInfo(), getPlayerState()
  - `RokuSession` — active Roku session with playMedia()/pause()/play()/stop(), player state polling every 5 seconds via Workerman Timer
  - `RokuManager` — manages Roku sessions, discovers devices, creates sessions, launches media
  - `RemoteRokuClient` — Roku control via relay tunnel (RelayConsumer) for devices behind NAT
  - `RokuController` — HTTP API endpoints:
    - GET /api/v1/roku/devices — list discovered Roku devices
    - POST /api/v1/roku/devices/{id}/send — send media to Roku
    - POST /api/v1/roku/devices/{id}/launch/{channelId} — launch a channel
    - POST /api/v1/roku/devices/{id}/key/{keyName} — send keypress
    - GET /api/v1/roku/devices/{id}/status — get session status
  - `Application` — registered Roku routes in `loadRokuRoutes()`
  - Unit tests: `RokuDeviceTest` (4 tests), `RokuDiscoveryTest` (3 tests), `RokuEcpClientTest` (8 tests), `RokuSessionTest` (7 tests), `RokuManagerTest` (6 tests)

### Added (Step J.5)

- AirPlay 2 support — stream audio to AirPlay 2 devices (Apple TV, HomePod, AirPlay 2-compatible receivers):
  - `AirPlayDevice` — AirPlay device descriptor with deviceId, name, host, port, raopPort, model, supportsVideo
  - `AirPlayDiscovery` — discovers AirPlay devices via mDNS `_airplay._tcp.local.` and `_raop._tcp.local.` using MdnsDiscovery
  - `RaopClient` — RAOP (Real-Time Audio Protocol) client with buildAnnouncePayload(), flush(), getRtpInfo(), getLatency()
  - `AirPlaySession` — active AirPlay session with startStream()/pause()/resume()/stop() and state management
  - `AirPlayManager` — manages AirPlay sessions, discovers devices, creates/retrieves/stops sessions
  - `RemoteAirPlayClient` — AirPlay via relay tunnel (RelayConsumer) for devices behind NAT
  - `AirPlayController` — HTTP API endpoints:
    - GET /api/v1/airplay/devices — list discovered AirPlay devices
    - POST /api/v1/airplay/devices/{id}/stream — start streaming
    - POST /api/v1/airplay/devices/{id}/pause — pause playback
    - POST /api/v1/airplay/devices/{id}/resume — resume playback
    - POST /api/v1/airplay/devices/{id}/stop — stop playback
    - GET /api/v1/airplay/devices/{id}/status — get session status
  - `HlsStreamer` — added `getAirPlayStreamUrl()` for AirPlay-compatible stream URLs
  - `Application` — registered AirPlay routes in `loadAirPlayRoutes()`
  - Unit tests: `AirPlayDeviceTest` (5 tests), `AirPlayDiscoveryTest` (3 tests), `RaopClientTest` (5 tests), `AirPlaySessionTest` (5 tests), `AirPlayManagerTest` (5 tests)

### Added (Step J.4)

- Chromecast support — cast to Chromecast devices via Default Media Receiver:
  - `CastDevice` — Chromecast device descriptor with device ID, name, host, port, model, UUID
  - `CastDiscovery` — discovers Chromecast devices via mDNS `_googlecast._tcp.local.` using MdnsDiscovery
  - `CastApiClient` — HTTP/JSON Cast protocol client with connect(), launchApp(), loadMedia(), sendMediaCommand(), getMediaStatus()
  - `CastSession` — active Chromecast session with play/pause/stop/seek, position polling every 5 seconds via Workerman Timer
  - `CastManager` — manages multiple cast sessions, creates sessions, launches app, loads media
  - `RemoteCastClient` — cast via relay tunnel (RelayConsumer) for Chromecast behind NAT (in progress / not operational — depends on a hub relay-tunnel feature that does not exist yet; the client throws `RuntimeException` rather than silently faking success)
  - `ChromecastController` — HTTP API endpoints:
    - GET /api/v1/cast/devices — list discovered Chromecast devices
    - POST /api/v1/cast/devices/{id}/cast — start casting
    - POST /api/v1/cast/devices/{id}/play — resume playback
    - POST /api/v1/cast/devices/{id}/pause — pause playback
    - POST /api/v1/cast/devices/{id}/stop — stop casting
    - POST /api/v1/cast/devices/{id}/seek — seek to position (ms)
    - GET /api/v1/cast/devices/{id}/status — get session status
  - `HlsStreamer` — added `getCastStreamUrl()` for Chromecast-compatible stream URLs
  - `Application` — registered Chromecast routes in `loadChromecastRoutes()`
  - Default Media Receiver app ID: `CC1AD845`
  - Unit tests: `CastDeviceTest` (4 tests), `CastDiscoveryTest` (4 tests), `CastApiClientTest` (8 tests), `CastSessionTest` (8 tests), `CastManagerTest` (8 tests)

### Added (Step J.3)

- DLNA AVTransport "play to" — send media to DLNA renderers:
  - `RendererDiscovery` — discovers DLNA MediaRenderers via SSDP with `urn:schemas-upnp-org:device:MediaRenderer:1`
  - `RendererControlClient` — HTTP SOAP client for AVTransport control (SetAVTransportURI, Play, Pause, Stop, Seek, GetPositionInfo, GetTransportInfo)
  - `PlayToSession` — active "play to" session with position polling every 5 seconds via Workerman Timer
  - `PlayToManager` — manages multiple play-to sessions, creates RendererControlClient, maps renderer IDs to sessions
  - `RemoteRendererClient` — "play to" via relay tunnel (RelayConsumer) for renderers behind NAT
  - `RendererListController` — HTTP API endpoints:
    - GET /api/v1/dlna/renderers — list discovered renderers
    - POST /api/v1/dlna/renderers/{id}/play — start "play to" session
    - POST /api/v1/dlna/renderers/{id}/pause — pause playback
    - POST /api/v1/dlna/renderers/{id}/stop — stop playback
    - POST /api/v1/dlna/renderers/{id}/seek — seek to position (ticks)
    - GET /api/v1/dlna/renderers/{id}/status — get renderer state
  - `AvTransport` — added `onStateChange()` callbacks and `notifyStateChange()` for observable state changes
  - `PlaybackController` — added `startPlayToSession()` for integrated local + remote playback
  - `Application` — registered DLNA renderer control routes in `loadDlnaRendererRoutes()`
  - Unit tests: `RendererDiscoveryTest` (5 tests), `RendererControlClientTest` (9 tests), `PlayToSessionTest` (11 tests), `PlayToManagerTest` (8 tests)

### Added (Step J.2)

- DLNA ContentDirectory full — browse and search real media library:
  - `LibraryBridge` — bridges `ItemRepository` to `ContentDirectory` for real media data
  - `CdsControlHandler` — HTTP SOAP endpoint for ContentDirectory actions (Browse, Search)
  - `CdsServer` — full DLNA MediaServer with HTTP endpoints: `/description.xml`, `/cds/control`, `/scpd/{service}.xml`
  - `src/Server/Http/Controllers/Dlna/DeviceDescriptionController` — serves `/description.xml`
  - `src/Server/Http/Controllers/Dlna/CdsControlController` — handles CDS SOAP requests
  - `ContentDirectory` — now uses `LibraryBridge` for real library data instead of stubs
  - `DlnaServer` — requires real `ItemRepository` (no stub), supports `setLibraryBridge()`
  - Unit tests: `LibraryBridgeTest` (14 tests), `CdsControlHandlerTest` (10 tests), `CdsServerTest` (13 tests)

### Added (Step J.1)

- SSDP (Simple Service Discovery Protocol) and mDNS (multicast DNS) discovery infrastructure:
  - `SsdpSocket` — raw UDP socket wrapper for SSDP multicast `239.255.255.250:1900`
  - `SsdpDevice` — discovered SSDP device descriptor with `getDeviceId()` and `getBaseUrl()`
  - `SsdpDiscovery` — SSDP discovery service with `discoverDevices()` and `announceServer()`
  - `MdnsSocket` — raw UDP socket wrapper for mDNS multicast `224.0.0.251:5353`
  - `MdnsService` — resolved mDNS service descriptor with `getAddress()`
  - `MdnsDiscovery` — mDNS discovery service with `discoverChromecast()`, `discoverAirPlay()`, `discoverRoku()`
  - `DiscoveryManager` — unified facade combining SSDP and mDNS discovery
  - `DiscoveryServer` — Workerman Timer integration for background discovery
  - `config/discovery.php` — configuration with SSDP/mDNS settings
  - Unit tests: `SsdpSocketTest`, `SsdpDiscoveryTest`, `MdnsSocketTest`, `MdnsDiscoveryTest`, `DiscoveryManagerTest` (12+ tests)
  - `docs/developers/discovery.md` — protocol documentation

### Added (Step I.7)

- Hub relay for remote live TV streams (HLS re-streaming via hub WebSocket tunnel):
  - `HlsRelaySession` — value object for relay session with `sessionId`, `channelId`, `tuneRequestId`, `getMountUrl()`, `getVariantPlaylistUrl()`
  - `HlsRelayManager` — orchestrates relay sessions: `startRelaySession()`, `stopRelaySession()`, `getActiveSessions()`, `getUserSession()`
  - `HlsSegmentPrefetcher` — LRU cache for HLS segments with Workerman Timer-based prefetching (`startPrefetch()`, `stopPrefetch()`, `getSegment()`)
  - `HlsRelaySessionFactory` — factory for building `HlsRelayManager` from config
  - `RelayConsumer` — added `registerMount()` and `unregisterMount()` methods for dynamic path handlers; `dispatchViaMount()` routes `/relay/live/{sessionId}/*` to registered handlers
  - `migrations/015_livetv_relay_sessions.sql` — creates `livetv_relay_sessions` table
  - `config/livetv.php` — added `relay` section with `enabled`, `prefetch_segments`, `max_concurrent_sessions`, `segment_cache_ttl_seconds`, `relay_path_prefix`
  - Unit tests in `tests/Unit/LiveTv/Relay/` (HlsRelaySessionTest, HlsRelayManagerTest, HlsSegmentPrefetcherTest — 26+ tests)
  - `docs/developers/live-relay.md` — architecture docs, session lifecycle, configuration

### Added (Step I.6)

- Comskip commercial detection for live TV recordings with chapter markers:
  - `ComskipIntegration` — wires `ComskipRunner` into recording lifecycle:
    `processRecording()`, `getEdlSegments()`, `markProcessed()`
  - `ComskipLifecycleManager` — queue management with `max_concurrent` enforcement:
    `enqueue()`, `processNext()`, `getPendingCount()`
  - `ChapterMarkerService` — EDL to HLS chapter conversion:
    `toHlsChapters()`, `persistChapters()`, `getChapters()`
  - `migrations/014_livetv_commercials.sql` — adds `commercial_processed_at`,
    `commercial_edl_path`, `commercial_frame_count`, `commercial_duration_seconds`
    to `livetv_recordings`
  - `config/livetv.php` — added `comskip` section with `enabled`, `binary_path`,
    `ini_path`, `output_dir`, `queue_processing`, `max_concurrent`
  - `Recorder` — registers `ComskipLifecycleManager::enqueue()` via `onComplete()`
    callback at construction time
  - Unit tests in `tests/Unit/LiveTv/Recording/` (ComskipIntegrationTest,
    ComskipLifecycleManagerTest, ChapterMarkerServiceTest — 12+ tests)
  - `docs/developers/comskip-live.md` — integration docs, EDL format, config

### Added (Step I.5)

- Scheduled + series DVR recordings. Includes:
  - `SeriesRuleManager` — CRUD for series recording rules; `matchAndSchedule()`
    queries `GuideManager::getUpcomingBySeries()` and schedules unmatched episodes
  - `RecordingDeduplicator` — prevents duplicate recordings via 2-hour window;
    `isDuplicate()`, `getCanonical()`, `resolveDuplicates()`
  - `RecordingScheduler` — priority-based conflict resolution; `processDueRecordings()`
    runs via Workerman timer; `getNextRecording()` for display
  - `RecordingHooksRunner` — async post-recording hook enqueueing
  - `migrations/013_livetv_dvr.sql` — adds `series_rule_id`, `duplicate_group`,
    `pre/post_padding_seconds` to `livetv_recordings`; creates `livetv_series_rules` table
  - `Recorder` — updated `scheduleRecording()` accepts `pre_padding_seconds`,
    `post_padding_seconds`, `series_rule_id`; added `isDuplicate()` method;
    `startRecording()` applies pre-padding (starts recording early)
  - `config/livetv.php` — added `dvr` section with `default_pre_padding_seconds`,
    `default_post_padding_seconds`, `auto_resolution`, `storage_path`,
    `max_storage_bytes`
  - `RecordingHooks` — already wires `ComskipPostProcessor` via `onComplete()` callback
  - Unit tests in `tests/Unit/LiveTv/Recording/` (SeriesRuleManagerTest,
    RecordingDeduplicatorTest, RecordingSchedulerTest — 12+ tests)
  - `docs/developers/dvr.md` — series rules, deduplication, padding,
    conflict resolution, scheduler integration

### Added (Step I.4)

- Schedules Direct EPG integration. Includes:
  - `SdApiClient` — HTTP JSON client for SD API with token auth
    (BASE_URL: https://api.schedulesdirect.tmsglobal.com)
  - `SdLineupHandler` — fetches SD lineups, imports channels via ChannelManager
  - `SdProgramMapper` — maps SD program/schedule data to GuideManager format
  - `SdEpgService` — orchestrates full sync: fetch schedules, programs, upsert to guide
  - `SdEpgServiceFactory` — builds service from config with token caching
  - `config/livetv.php` — added `schedules_direct` section (username,
    password, token_cache_path, lineup_id, sync_hours_ahead, timeout_secs)
  - `LiveTvManager` — wired `SdEpgService` as optional dependency;
    `getSdEpgService()`, `setSdConfig()`, `syncSdEpG()`
  - Unit tests in `tests/Unit/LiveTv/Epg/SchedulesDirect/` (SdApiClientTest,
    SdProgramMapperTest, SdEpgServiceTest — 12 tests total)
  - `docs/developers/schedules-direct.md` — SD API overview, auth, endpoints,
    data model, and config reference

### Added (Step I.3)

- Linux DVB-T USB tuner driver. Includes:
  - `DvbtDevice` — immutable value object for /dev/dvb/ devices
  - `DvbtDeviceScanner` — scans /dev/dvb/ for adapters, reads capabilities
  - `DvbtSignalEngine` — dvbv5-zap integration + FFmpeg ingest URL generation
  - `DvbtTunerDriver` — implements `TunerDriverInterface`
  - `DvbtTunerDriverFactory` — builds driver from `config/livetv.php`
  - `config/livetv.php` — added `dvbt` section
  - `TunerDriverInterface` — updated to accept `DvbtDevice` union type
  - `LiveTvManager` — integrated DvbtTunerDriver via additionalDrivers
  - Unit tests for scanner, signal engine, and driver
  - `docs/developers/dvbt.md` — developer documentation

### Added (Step I.2)

- M3U/XMLTV IPTV tuner driver. Includes:
  - `M3UEntry` — immutable value object for M3U playlist entries
  - `M3UParser` — parses M3U/M3U8 playlists, fetches remote via `parseUrl()`
  - `XmlTvProgramme` — immutable value object for XMLTV programme entries
  - `XmlTvParser` — parses XMLTV format, handles YYYYMMDDHHMMSS times
  - `IptvDevice` — immutable descriptor for IPTV sources
  - `IptvTunerDriver` — implements `TunerDriverInterface` for IPTV
  - `IptvTunerDriverFactory` — builds driver from `config/livetv.php`
  - `config/livetv.php` — added `iptv` section with `sources` array
  - `LiveTvManager` — integrated IPTV alongside HDHomeRun tuners
  - `GuideManager::upsertProgram()` — added `xmltv_id` parameter for IPTV matching
  - Unit tests for `M3UParser`, `XmlTvParser`, `IptvTunerDriver`
  - `docs/developers/iptv.md` — developer documentation

### Added (Step I.1)

- HDHomeRun tuner driver (SSDP discovery + HTTP API). Includes:
  - `TunerDriverInterface` — shared interface for all tuner drivers
  - `HdHomeRunDevice` — immutable value object for discovered devices
  - `HdHomeRunDiscovery` — SSDP M-SEARCH discovery on UDP 1900
  - `HdHomeRunApiClient` — HTTP API client for HDHomeRun devices
  - `HdHomeRunTunerDriver` — concrete driver implementing `TunerDriverInterface`
  - `HdHomeRunTunerDriverFactory` — factory for driver instantiation
  - `LiveTvManager` refactored to use `TunerDriverInterface` (no more `/dev/dvb` references)
  - `config/livetv.php` — LiveTV configuration with HDHomeRun settings
  - Unit tests for `HdHomeRunDiscovery`, `HdHomeRunApiClient`, `HdHomeRunTunerDriver`
  - `docs/developers/hdhomerun.md` — developer documentation

### Added (Step H.6)

- Theme music + theme video auto-play on browse. Includes:
  - `ThemeAudio` — readonly DTO (path, url, duration, format) for audio themes
  - `ThemeVideo` — readonly DTO (path, url, duration, width, height, format) for video backdrops
  - `ThemeMedia` — readonly DTO containing libraryId, audio, video, scannedAt
  - `ThemeMediaFinder` — filesystem scanner for theme.mp3/theme.ogg and backdrop.mp4/backdrop.webm
  - `ThemeMediaRepository` — cache operations (upsert, findByLibraryId, delete)
  - `ThemeMediaController` — 3 REST endpoints:
    - `GET /api/v1/libraries/{id}/theme-media` — get theme media
    - `POST /api/v1/libraries/{id}/theme-media/scan` — trigger rescan
    - `DELETE /api/v1/libraries/{id}/theme-media` — clear cached entry
  - `ThemeMediaStreamController` — 2 streaming endpoints:
    - `GET /stream/theme-media/{libraryId}/audio` — stream theme audio
    - `GET /stream/theme-media/{libraryId}/video` — stream theme video
  - `Migration 008_theme_media.sql` — creates theme_media table
  - `Router::themeMedia()` — registers all theme media routes
  - `library-header.tpl` — theme media player partial with toggle button
  - `theme-media.js` — autoplay handling with browser policy fallback
  - `LibraryManager::scanThemeMedia()` — scans and caches after library scan
  - `PageRenderer::setThemeMediaRepository()` + `renderLibrary()` passes themeMedia to template
  - Unit tests in `tests/Unit/Theming/` (10+ tests)
  - Integration test `tests/Integration/Theming/ThemeMediaScanTest.php`
  - `docs/developers/theme-media.md` — file naming, scanning, streaming, autoplay policy

### Added (Step H.5)

- Trailers and extras with local `Trailers/` folder support. Includes:
  - `Trailer` — readonly DTO (id, mediaItemId, title, source, url, duration, quality, isLocal, filePath)
  - `Extra` — readonly DTO for non-trailer extras (featurette|behind_the_scenes|interview|clip|deleted_scene|trailer)
  - `TrailerFinder` — filesystem scanner for local trailers (same-level and Trailers/ subfolder)
  - `TrailerResolver` — merges local + TMDB trailers, caches in media_extras with 24h TTL
  - `ExtrasRepository` — data access for media_extras table
  - `ExtrasController` — 3 REST endpoints:
    - `GET /api/v1/media/{id}/extras` — full merged list
    - `GET /api/v1/media/{id}/trailers` — trailers only
    - `GET /api/v1/media/{id}/extras/other` — non-trailer extras
  - `Migration 007_media_extras.sql` — creates media_extras table
  - `TmdbProvider::getTrailers()` — fetches trailers from TMDB API
  - `Router::extras()` — registers ExtrasController routes
  - `MediaScanner::hasTrailers()` — detects Trailers/ folders at scan time
  - `FolderWatcher::shouldRescanExtras()` — triggers extras rescan on change
  - Unit tests in `tests/Unit/Media/Extras/` (15 tests)
  - Integration test `tests/Integration/Media/Extras/TrailerScannerTest.php`
  - `docs/developers/trailers-and-extras.md` — naming conventions, API reference, architecture

### Added (Step H.4)

- Trakt.tv scrobble plugin with two-way history sync. Includes:
  - `TraktApi` — OAuth2 PKCE client, scrobble start/pause/stop, history sync
  - `TraktSettings` — per-user settings (tokens, sync prefs, username)
  - `TraktPlugin` — LifecycleInterface entry, subscribes to PlaybackStarted/Stopped/ProgressUpdated
  - `TraktHistorySync` — syncTraktToPhlix() (pull on schedule) and syncPhlixToTrakt() (push on ≥90% completion)
  - `TraktOAuthController` — OAuth callback at GET /api/v1/oauth/trakt/callback
  - `config/scrobblers/trakt.php` — client_id, client_secret, redirect_uri, sync_interval
  - `phlix-plugin-trakt/plugin.json` — scrobbler plugin manifest
  - Unit tests (19 tests across TraktApi, TraktSettings, TraktHistorySync, TraktPlugin)
  - `docs/developers/scrobbler-plugins.md` — scrobbler plugin author guide
- New Router method `traktAuth()` for Trakt OAuth routes

### Added (Step H.3)

- Custom CSS / themes with `ui-theme` plugin type. Includes:
  - `Theme` — readonly theme descriptor (id, name, type, cssUrl, jsUrl,
    thumbnailUrl, version, pluginName, dark).
  - `ThemeRegistry` — central registry with registerBuiltIn(), registerFromPlugin(),
    getTheme(), getAllThemes(), getActiveThemeForUser(), setActiveThemeForUser().
  - `ThemeMiddleware` — HTTP middleware that injects theme CSS/JS into WebPortal
    responses via str_replace on Smarty placeholders.
  - `ThemePluginInterface` — marker interface for ui-theme plugin entry classes.
  - `ThemePreviewController` — renders live theme preview in iframe sandbox at
    GET /portal/theme-preview?id={themeId}.
  - `config/themes.php` — 4 built-in themes (phlix-dark, phlix-light,
    phlix-amoled, phlix-contrast) with CSS and thumbnail assets.
  - Migration `migrations/006_user_theme_settings.sql` — adds active_theme_id
    to user_profiles.
  - UserProfileManager::getActiveThemeId() / setActiveThemeId() for per-profile
    theme preferences.
  - `{$theme_css|raw}` and `{$theme_js|raw}` Smarty placeholders in base.tpl.
  - `var/themes/` runtime directory for extracted plugin themes (gitignored).
  - Unit tests in `tests/Unit/Theming/` (ThemeRegistryTest, ThemeMiddlewareTest — 11 tests).
  - `docs/developers/ui-themes.md` — plugin author guide with CSS variable reference.

### Added (Step H.2)

- Collections — named groups of media items for manual curation
  (bulk-add from search) and rule-based auto-population via smart playlists.
  Includes:
  - `Collection` — readonly entity with id, name, libraryId, smartPlaylistId,
    parentId, sortOrder, timestamps.
  - `CollectionWithItems` — hydrated DTO with collection + hydrated media items.
  - `CollectionRepository` — full CRUD for collections table with parameterized
    Workerman\MySQL\Connection queries.
  - `CollectionItemRepository` — membership CRUD for collection_items with
    sort order support.
  - `CollectionManager` — orchestrator with addItem(), removeItem(),
    bulkAddFromSearch(), getCollectionWithItems(), refreshSmartCollection().
  - `CollectionController` — 9 REST API endpoints:
    GET/POST /api/v1/collections, GET/PUT/DELETE /api/v1/collections/{id},
    POST/DELETE /api/v1/collections/{id}/items/{mediaItemId},
    POST /api/v1/collections/{id}/bulk-add,
    POST /api/v1/collections/{id}/refresh,
    GET /api/v1/libraries/{libraryId}/collections.
  - Migration `migrations/005_collections.sql` — creates collections and
    collection_items tables with proper indexes.
  - Unit tests in `tests/Unit/Collections/` (CollectionRepositoryTest,
    CollectionItemRepositoryTest, CollectionManagerTest — 14 tests).
  - Integration test `tests/Integration/Collections/CollectionCrudTest.php`.
  - `docs/developers/collections.md` — model, API reference, smart sync
    algorithm, integration guide.
  - `Router::collections()` — registers collection routes.
  - `SmartPlaylistRefreshHandler` now calls CollectionManager::refreshSmartCollection()
    for any collection linked to a changed smart playlist.

### Added (Step H.1)

- Smart-playlist rule engine with JSON DSL evaluation at scan time and
  on folder-watch events. Includes:
  - `RuleNode` — immutable AST node (TYPE_AND/OR/NOT/RULE) for rule trees.
  - `RuleOperators` — 11 static operator methods (equals, notEquals, contains,
    notContains, greaterThan, lessThan, between, in, notIn, startsWith, endsWith).
  - `SmartPlaylistEngine` — buildFromDsl(), evaluate(), evaluateOnScan(), toJson()
    for parsing JSON DSL and evaluating media items against rules.
  - `SmartPlaylist` — readonly entity with id, name, libraryId, rulesJson, limit,
    sortBy, sortDesc, timestamps.
  - `SmartPlaylistRepository` — full CRUD for smart_playlists table with
    parameterized Workerman\MySQL\Connection queries.
  - `SmartPlaylistRefreshHandler` — listens to LibraryUpdated events and
    re-evaluates all smart playlists for the changed library.
  - `SmartPlaylistController` — REST API endpoints:
    GET/POST/PUT/DELETE /api/v1/smart-playlists, POST /api/v1/smart-playlists/{id}/preview.
  - `LibraryUpdated` event dispatched by FolderWatcher on content changes.
  - Migration `migrations/004_smart_playlists.sql` — creates smart_playlists table
    with JSON rules column, limit, sort_by, sort_desc fields.
  - Unit tests in `tests/Unit/Playlists/` (RuleNodeTest, RuleOperatorsTest,
    SmartPlaylistEngineTest, SmartPlaylistRepositoryTest, SmartPlaylistTest).
  - Integration test `tests/Integration/Playlists/SmartPlaylistRefreshTest.php`.
  - `docs/developers/smart-playlists.md` — DSL reference, operator list,
    evaluation algorithm, extension guide.
  - `Router::smartPlaylists()` — registers smart playlist routes.
  - `FolderWatcher` now injects EventDispatcherInterface and dispatches
    LibraryUpdated events when changes are detected.
  - MediaServicesProvider registers SmartPlaylistEngine, SmartPlaylistRepository,
    SmartPlaylistRefreshHandler, SmartPlaylistController.

### Added (Step G.6)

- `AudiobookProgress` — Value object for per-user audiobook progress tracking.
  Immutable with position_ms, current_chapter_index, completed_chapters array,
  percent_complete, and last_position_ms for chapter-resume support.
- `AudiobookProgressStore` — Persistence layer using Workerman MySQL for
  audiobook_progress table. Supports getProgress(), saveProgress(), and
  markChapterComplete() operations with composite PK (user_id, audiobook_id).
- `AudiobookScanner` — Extends BookScanner for audiobook-specific scanning.
  - `harvestChapters()` — Pure-PHP M4B chapter extraction via MP4 chpl atom
    parsing (binary string scanning, no external dependencies). Handles 64-bit
    duration values.
  - Returns chapters as metadata_json array with title, start_ms, end_ms,
    and duration_ms fields.
- `AudiobookLibraryManager` — Extends BookLibraryManager for audiobook
  libraries. Orchestrates scanning and progress management. Methods:
  getProgress(), saveProgress(), markChapterComplete(), chapterDuration().
- `AudiobookController` — REST API endpoints for audiobooks:
  - `GET /api/v1/audiobooks` — List audiobooks with pagination
  - `GET /api/v1/audiobooks/{id}` — Get audiobook details with chapters
  - `GET /api/v1/audiobooks/{id}/chapters` — List chapters for an audiobook
  - `GET /api/v1/audiobooks/{id}/progress` — Get user's progress for an audiobook
  - `POST /api/v1/audiobooks/{id}/progress` — Save progress (position, chapter)
  - `GET /api/v1/audiobooks/{id}/stream` — Stream audiobook (chapter + offset)
- `AudiobookLibraryType` — Library type plugin with type `'audiobook'`.
  Returns AudiobookScanner and AudiobookLibraryManager instances.
- Migration `012_audiobook_progress.sql` — Creates audiobook_progress table
  with user_id, audiobook_id, position_ms, current_chapter_index,
  completed_chapters (JSON), percent_complete, last_position_ms, created_at,
  updated_at.
- Smarty templates: `audiobooks/audiobooks.tpl`, `audiobooks/audiobook.tpl`,
  `player/player.tpl`, `audiobooks/partials/audiobook_card.tpl`,
  `audiobooks/partials/chapter_row.tpl` — Audiobook grid view, detail with
  chapter navigation, audio player UI, and chapter list component.
- `public/assets/css/audiobooks.css` — Player styles (play/pause, seek bar,
  volume, chapter list) and grid layout with cover cards.
- `public/assets/js/audiobook-player.js` — Chapter navigation, progress
  persistence every 10 seconds, chapter completion tracking, play/pause controls.
- `docs/libraries/audiobooks.md` — Documentation for supported formats (M4B,
  M4A, MP3), chapter navigation, progress persistence, and streaming.
- Unit tests: AudiobookScannerTest (8 tests), AudiobookProgressStoreTest
  (4 tests), AudiobookLibraryManagerTest (4 tests), AudiobookControllerTest
  (9 tests).
- Router now registers `/api/v1/audiobooks/*` routes.
- LibraryManager routes `'audiobook'` type libraries through AudiobookScanner.

### Added (Step G.5)

- `BookScanner` — Pure-PHP book file scanner for EPUB, PDF, and CBZ formats.
  - `harvestEpub()` — parses EPUB container.xml and content.opf for Dublin Core
    metadata (title, author, publisher, ISBN, language, pub_date, description) and
    extracts cover images.
  - `harvestPdf()` — uses `exif_read_data()` for XMP/EXIF metadata and pure-PHP
    page count extraction.
  - `harvestCbz()` — parses ComicInfo.xml for extended metadata (series, volume,
    authors, page_count) and extracts cover images from ZIP archive.
  - `scanBookLibrary()` — generator that yields book item arrays with metadata.
- `BookLibraryManager` — orchestrates book library scanning, metadata extraction,
  and upsert. Implements `rescanLibrary()` for full pipeline and `upsertBook()`
  for single-file processing.
- `BookLibraryType` — Library type plugin implementing `LibraryTypeInterface`
  with type `'book'`. Returns `BookScanner` and `BookLibraryManager` instances.
- `OpdsFeedBuilder` — builds OPDS 1.2 compliant XML feeds using `DOMDocument`.
  - `buildRootFeed()` — root catalog with links to libraries.
  - `buildNavigationFeed()` — navigation feed listing book libraries.
  - `buildAcquisitionFeed()` — acquisition feed with pagination (?offset=N&limit=N).
  - `buildEntry()` — individual book entries with dc:title, dc:creator,
    opds:link rel=acquisition.
- `BookController` — REST API endpoints for books and OPDS:
  - OPDS: `GET /opds/v1.2`, `GET /opds/v1.2/libraries`, `GET /opds/v1.2/libraries/{id}`
  - Web portal: `GET /books`, `GET /books/{id}`, `GET /books/{id}/cover`,
    `GET /books/{id}/read`, `GET /books/{id}/download`
- Smarty templates: `books/books.tpl`, `books/book.tpl`, `books/reader.tpl`,
  `books/partials/book_card.tpl` — book grid view, book detail with cover
  and metadata, minimal reader stub, book card component.
- `public/assets/css/books.css` — styles for book grid, cover cards,
  reader layout, and theme support (light/sepia/dark).
- `public/assets/js/reader.js` — reader controller with font size controls,
  theme switching, keyboard navigation (←/→).
- `docs/libraries/books.md` — documentation for supported formats, OPDS feed URL,
  third-party client setup (Uboiquity, Komga, Kore, Moon+ Reader), naming
  conventions, metadata fields, reader stub limitations.
- `docs/reference/api.md` — updated with OPDS endpoints and Books API.
- Unit tests: `BookScannerTest` (8 tests), `BookLibraryManagerTest` (2 tests),
  `OpdsFeedBuilderTest` (5 tests), `BookControllerTest` (7 tests).
- Router now registers `/opds/*` and `/books/*` routes.
- LibraryManager routes `'book'` type libraries through BookScanner.
- WebPortalRouter now registers `/books` and `/books/{id}` routes.
- `public/templates/partials/header.tpl` — Added Books nav link.
- LibraryController accepts `'book'` as a valid library type.

### Added (Step G.4)

- `PhotoScanner` — Pure-PHP photo file scanner with EXIF metadata extraction.
  Uses PHP's built-in `exif_read_data()` for JPEG files; graceful fallback
  for PNG/TIFF/WebP/HEIC. Extracts camera_make, camera_model, lens,
  aperture, iso, shutter_speed, focal_length, width, height, orientation,
  date_taken_unix, gps_lat, gps_lng, gps_alt.
- `PhotoLibraryManager` — Orchestrates photo library scanning, EXIF extraction,
  and metadata upsert. Implements `rescanLibrary()` for full pipeline and
  `upsertPhoto()` for single-file processing.
- `PhotoLibraryType` — Library type plugin implementing `LibraryTypeInterface`
  with type `'photo'`. Returns `PhotoScanner` and `PhotoLibraryManager` instances.
- `ExifProvider` — Local EXIF metadata provider that reads from `metadata_json`
  stored on media items. Implements `MetadataProviderInterface`.
- `PhotoController` — REST API endpoints for photo browsing and slideshow:
  - `GET /photo/albums` — list all albums (grouped by date)
  - `GET /photo/albums/{id}` — get specific album with photos
  - `GET /photo/photos` — list all photos
  - `GET /photo/photos/{id}` — photo with full EXIF data
  - `GET /photo/photos/{id}/thumbnail?w=300&h=300&fit=cover` — resized thumbnail
  - `GET /photo/photos/{id}/full` — full-resolution photo
  - `GET /photo/slideshow?album_id=xxx&interval=5` — slideshow data
- Smarty templates: `photo/albums.tpl`, `photo/album.tpl`, `photo/photo.tpl`,
  `photo/slideshow.tpl`, `photo/partials/exif_panel.tpl`,
  `photo/partials/photo_card.tpl` — album grid, photo grid, lightbox view,
  fullscreen slideshow player, EXIF data sidebar.
- `public/assets/css/photo.css` — Styles for album grid, photo grid,
  lightbox, EXIF sidebar, slideshow player.
- `public/assets/js/slideshow.js` — Slideshow controller with auto-advance
  interval, keyboard nav (←/→/Space/Escape), touch/swipe support.
- `docs/libraries/photos.md` — Documentation for supported formats, EXIF
  fields, album organization, API endpoints, thumbnail generation,
  slideshow features, and deferred geotag clustering note.
- Unit tests: `PhotoScannerTest` (12 tests), `PhotoLibraryManagerTest`
  (6 tests), `PhotoControllerTest` (11 tests).
- Router now registers `/photo/*` routes pointing to `PhotoController`.
- LibraryManager routes `'photo'` type libraries through `PhotoLibraryManager`.
- `public/templates/layouts/main.tpl` — Added Photos nav link.

### Added (Step G.3)

- `Phlix\Plugins\Lastfm\Plugin` — In-core Last.fm scrobbler plugin
  implementing the `scrobbler` plugin type. Subscribes to
  `phlix.playback.started` (Now Playing updates) and
  `phlix.playback.stopped` (scrobble submission). Off by default;
  configure `config/lastfm.php` with API credentials to enable.
- `Phlix\Plugins\Lastfm\LastfmApiClient` — Last.fm API v1.2 client
  with HMAC-MD5 signing. Supports `auth.getMobileSession`,
  `track.scrobble`, and `track.updateNowPlaying` endpoints.
- `Phlix\Plugins\Lastfm\ScrobbleData` — Immutable value object for
  scrobble submission (artist, track, timestamp, album, duration,
  MusicBrainz ID).
- `Phlix\Plugins\Lastfm\NowPlayingData` — Immutable value object for
  Now Playing notifications.
- `Phlix\Plugins\Lastfm\LastfmPluginNotConfiguredException` — Thrown
  when API key, secret, or session key is missing.
- `Phlix\Plugins\Lastfm\LastfmScrobbleFailedException` — Thrown when
  Last.fm API returns an error on scrobble/Now Playing.
- `config/lastfm.php` — Default configuration with `enabled` (default
  false), `api_key`, `api_secret`, `session_key`, `username`,
  `submit_now_playing` (default true), and `scrobble_threshold`
  (default 0.5 — scrobble after 50% of track).
- `docs/plugins/developer-guide.md` — Added §14 documenting the
  `scrobbler` plugin type with Last.fm as the reference example.
- `docs/developers/lastfm-plugin.md` — New developer guide covering
  Last.fm API protocol, HMAC-MD5 signing, mobile auth flow,
  scrobble threshold semantics, and full configuration reference.
- Unit tests: `LastfmApiClientTest` (11 tests), `PluginTest` (9 tests).

### Added (Step G.2)

- `AudioScanner` — Pure-PHP audio file scanner with ID3v2 (MP3), Vorbis
  Comment (FLAC/OGG), and MP4 atom (M4A/AAC) tag harvesting. No external
  dependencies required. Never throws; returns partial results on best
  effort.
- `MusicLibraryManager` — Orchestrates music library scanning, tag harvest,
  and metadata enrichment via `MetadataManager`. Implements `rescanLibrary()` for
  full pipeline and `upsertTrack()` for single-file processing.
- `MusicLibraryType` — Library type plugin implementing `LibraryTypeInterface`
  with type `'music'`. Returns `AudioScanner` and `MusicLibraryManager` instances.
- `LibraryTypeInterface` — New interface for library type plugins, allowing
  type-specific scanner and manager instances.
- `MusicController` — REST API endpoints for music browsing:
  - `GET /music/artists` — list all artists
  - `GET /music/artists/{mbid}` — artist detail with albums
  - `GET /music/albums` — list all albums
  - `GET /music/albums/{mbid}` — album detail with tracks
  - `GET /music/tracks` — list all tracks (paginated)
  - `GET /music/tracks/{id}` — single track
  - `GET /music/now-playing` — current playback state
- `Router::music()` — Registers `/music/*` routes pointing to `MusicController`.
- `WebPortalRouter` — Added `/music`, `/music/artists`, `/music/albums`,
  `/music/tracks`, `/music/player` web portal routes.
- Smarty templates — `music/artists.tpl`, `music/artist.tpl`,
  `music/albums.tpl`, `music/album.tpl`, `music/tracks.tpl`,
  `music/player.tpl`, `music/partials/music_card.tpl`.
- `public/assets/css/music.css` — Styles for artist grid, album grid,
  track list, and player bar.
- `public/assets/js/music-player.js` — Music player JavaScript with play,
  pause, seek, next/prev, shuffle, repeat, and queue management.
- `migrations/011_music_library.sql` — Adds 'track' to media_items type enum,
  adds indexes for library_type, artist, album, and genre queries.
- `docs/libraries/music.md` — Developer documentation covering supported
  formats, tag field mapping, naming conventions, scan behavior, and API.
- Unit tests: `AudioScannerTest` (8 tests), `MusicLibraryManagerTest` (8 tests),
  `MusicControllerTest` (13 tests).

### Added (Step G.1)

- `MusicBrainzProvider` — MusicBrainz API v2 metadata provider implementing
  `MetadataProviderInterface`. Supports artist, album, and track search and
  detail retrieval with MusicBrainz-required User-Agent headers and 1 req/sec
  rate limiting via `MusicMetadataProviderTrait`.
- `AudioDbProvider` — AudioDB API v1 metadata provider implementing
  `MetadataProviderInterface`. Supports artist, album, and track search and
  detail retrieval. Degrades gracefully when no API key is configured.
- `MusicMetadataProviderTrait` — shared trait for music providers with
  `rateLimit()` for enforcing request delays and `mbHeaders()` for
  MusicBrainz-required headers.
- `MetadataProviderInterface` — added `MEDIA_TYPE_ALBUM`, `MEDIA_TYPE_ARTIST`,
  `MEDIA_TYPE_TRACK` constants and `getSourceName()` method.
- `MetadataHttpClient` — extended `get()` method to accept optional `$headers`
  parameter for custom request headers.
- `MetadataManager` — updated provider priority to include `audiodb` as fallback
  for music types; added `track` media type support.
- `config/music_providers.php` — new config file with MusicBrainz and AudioDB
  provider settings (rate limits, user-agent, API key, fallback behavior).
- `docs/developers/music-providers.md` — developer documentation covering
  provider architecture, configuration keys, MusicBrainz rate-limit requirements,
  and guide for adding third-party providers.
- Unit tests: `MusicBrainzProviderTest` (10 tests), `AudioDbProviderTest`
  (11 tests) with ≥85% coverage on both providers.

### Added (Step F.5)

- `ComskipRunner` — detects and runs the comskip binary on Live TV recordings;
  `isAvailable()` checks if the binary exists and is executable, `run()` executes
  comskip with a 5-minute timeout and returns the path to the generated .edl file.
- `ComskipEdlParser` — parses comskip EDL (Edit Decision List) files with 3-column
  tab-separated format (start_seconds, end_seconds, scene_type); filters segments
  shorter than `min_commercial_length`; converts to `ChapterMarker[]` DTOs.
- `ComskipPostProcessor` — orchestrator that runs comskip after a recording
  completes, parses the EDL, and stores chapters via `MarkerService::storeChapters()`.
  Idempotent — skips recordings that already have chapters.
- `RecordingHooks::register()` — wires `ComskipPostProcessor` into the `Recorder`
  via the new `onComplete()` callback hook.
- `Recorder::onComplete()` — registers callbacks to fire after a recording stops
  with status COMPLETED; callbacks receive `(string $mediaItemId, string $recordingPath)`.
- `MarkerService::storeChapters()` — persists `ChapterMarker[]` arrays to
  `chapters_json` column via `ItemRepository::updateMarkers()`.
- `config/comskip.php` — comskip binary path, `min_commercial_length` (30s),
  `require_confidence` (0.7), `post_process_immediately` flag, and `edl_output_dir`.
- `docs/advanced/live-tv-comskip.md` — user-facing documentation covering
  comskip installation, configuration, EDL format, and troubleshooting.
- Unit tests: `ComskipRunnerTest` (6 tests), `ComskipEdlParserTest` (12 tests),
  `ComskipPostProcessorTest` (6 tests).

### Added (Step F.4)

- `SkipButtonSpec` — immutable value object with `toArray()` serialization and
  `fromMarkerSet()` factory for client-facing JSON.
- `PlaybackMarkerService` — provides `getFullSpec()` and `getSkipSpec(id, position_ticks)`
  to return position-aware skip button specs.
- `WebPortalRouter::getPlaybackInfo()` — embeds `markers` key with
  `skip_intro_start`, `skip_intro_end`, `skip_outro_start`, `skip_outro_end`
  in the playback info response.
- `docs/reference/skip-button-protocol.md` — full protocol specification for
  client teams implementing skip button UI.
- `docs/clients/skip-button-integration-brief.md` — concise hand-off brief
  for Phase M client integration.
- `docs/reference/api.md` — updated with `GET /api/v1/media/{id}/playback`
  endpoint documentation including `markers` key.
- Unit tests: `SkipButtonSpecTest` (4 tests), `PlaybackMarkerServiceTest` (4 tests).

### Added (Step F.3)

- Marker storage columns and GET API for chapters, intro, and outro markers.
- `migrations/003_marker_columns.sql` — adds `intro_start_seconds`,
  `intro_end_seconds`, `outro_start_seconds`, `outro_end_seconds`,
  `chapters_json` columns to `media_items` table.
- `IntroMarker` / `OutroMarker` / `ChapterMarker` — immutable DTOs for marker
  segments with start/end times, confidence, and optional title.
- `MarkerSet` — aggregate DTO containing intro, outro, and chapters array with
  `hasMarkers()` and `toArray()` methods.
- `MarkerService` — service for reading/promoting markers; reads formal columns
  first, falls back to `metadata_json` candidates; exposes `getMarkers()`,
  `promoteCandidates()`, `promoteShowMarkers()`, and `getShowMarkers()`.
- `MarkerController` — HTTP controller with 4 GET endpoints:
  - `GET /api/v1/media/{id}/markers` — all markers for an item
  - `GET /api/v1/media/{id}/markers/intro` — intro marker only
  - `GET /api/v1/media/{id}/markers/outro` — outro marker only
  - `GET /api/v1/shows/{id}/markers/bulk` — all episode markers for a show
- `Router::markers()` — registers the 4 marker routes.
- `ItemRepository` — added `getIntroMarker()`, `getOutroMarker()`,
  `getChapters()`, and `updateMarkers()` methods for marker column access.
- `docs/reference/api.md` — API reference documentation for marker endpoints.
- Unit tests: `MarkerSetTest` (10 tests), `MarkerServiceTest` (9 tests),
  `MarkerControllerTest` (10 tests).

### Added (Step F.2)

- Intro/outro detection background job system using audio fingerprint clustering.
- `FingerprintClusterer` — Jaccard similarity-based clustering to detect shared
  intro/outro segments across episodes using audio fingerprints.
- `IntroDetectionJob` — orchestrates detection for all episodes of a TV show,
  clusters fingerprints, returns marker candidates.
- `IntroMarkerCandidate` / `OutroMarkerCandidate` — immutable DTOs for detected
  intro/outro segments with start/end times, fingerprint, and confidence score.
- `IntroDetectionResult` — result container for show-level detection results.
- `ClusteringResult` — result container for fingerprint clustering output.
- `StoredMarkers` — parses stored marker candidates from episode metadata.
- `MarkerCandidateRepository` — persists intro/outro candidates to
  `media_items.metadata_json` for consumption by F.3 API.
- `MarkerCandidateStore` — file-based job queue (`/tmp/phlix_marker_jobs/`)
  with one lock file per show being processed.
- `BackgroundDetectorWorker` — queue consumer loop that processes detection
  jobs continuously.
- `scripts/run-marker-detection-worker.php` — CLI entry point for running
  the background worker.
- `config/marker_detection.php` — configuration for intro/max duration,
  similarity threshold (0.85), minimum episodes (3), worker interval.
- `docs/developers/intro-outro-detection.md` — developer documentation
  covering the clustering algorithm, configuration, and usage.
- Unit tests: `IntroDetectionJobTest` (5 tests), `FingerprintClustererTest`
  (12 tests), `MarkerCandidateStoreTest` (10 tests),
  `MarkerCandidateRepositoryTest` (5 tests).

### Added (Step E.6)

- Subtitle burn-in (hardsubbing) pipeline for embedding subtitles directly
  in the video stream — required for players/devices that don't support
  external subtitle tracks (many smart TVs, game consoles, some mobile browsers).
- `SubtitleFormat` — enum with SRT, ASS, SSA, VTT, HDMV formats plus
  `getFfmpegFormat()` and `supportsFontstyle()` methods.
- `SubtitleTrack` — immutable value object with stream index, language code,
  display label, format, and file path.
- `SubtitleStyleOptions` — value object for burn-in styling (font, size,
  primary/outline colors, outline thickness, position, margin) with
  `toAssStyle()` and `toSrtStyle()` methods.
- `SubtitleBurner` — core class for subtitle stream detection, extraction,
  and FFmpeg filter graph generation for burn-in across all vendors.
- `SubtitleBurnerFactory` — factory for creating vendor-specific burners.
- `HwaccelCommandBuilder` — added `setSubtitleTrack()`, `setSubtitleStyle()`,
  and `setSubtitleBurner()` methods; integrates subtitle burn-in filter
  args into hardware transcoding commands.
- `StreamManager` — added `setSubtitleBurnIn()` and `getSubtitleBurnInConfig()`
  methods for configuring subtitle burn-in per streaming session.
- `StreamState` — added `subtitleBurnInIndex` and `forceSubtitleBurnIn` properties.
- `config/subtitles.php` — subtitle configuration with `enabled`, `default_language`,
  `burn_in_by_default`, `extract_to_dir`, and `style` options.
- `config/ffmpeg.php` — added `subtitles` key referencing `config/subtitles.php`.
- `docs/developers/subtitle-processing.md` — developer documentation covering
  soft vs. hard subtitling, vendor burn-in support matrix, styling reference,
  and usage examples.
- Unit tests: `SubtitleFormatTest` (11 tests), `SubtitleTrackTest` (4 tests),
  `SubtitleStyleOptionsTest` (6 tests), `SubtitleBurnerTest` (13 tests).

### Added (Step E.5)

- Trickplay (thumbnail seek / scrub preview) support for video progress bar
  hover preview using DASH-IF / HLS spec-compliant "BIF" (Bitmap Image Format)
  thumbnail grids.
- `TrickplayConfig` — value object with grid dimensions (8×4), thumbnail size
  (160×90px), interval (10s), image format (JPEG/PNG), and quality settings.
- `TrickplayResult` — result container with job ID, interval, grid dimensions,
  image file metadata (byte offsets for byte-range requests), and BIF index XML
  path.
- `TrickplayGenerator` — extracts frames at fixed intervals using FFmpeg batch
  extraction (`generateThumbnailBatch`), assembles frames into grid images via
  FFmpeg `tile` filter, generates BIF index XML with offset/length per thumbnail.
- `TrickplayController` — HTTP handler serving thumbnail grid images and BIF
  index XML with correct `Content-Type` headers.
- `StreamManager` — added `setTrickplay()` and `generateTrickplay()` methods,
  `TrickplayGenerator` and `TrickplayController` properties, and
  `getTrickplayController()` getter.
- `FfmpegRunner` — extended `generateThumbnail()` to accept `int|array` for
  batch extraction, added `generateThumbnailBatch()` for multiple timestamps in
  one command, added `getFfmpegPath()` accessor.
- `Router` — added `trickplay()` route registration for
  `GET /trickplay/{jobId}/thumb-{index}.jpg` and `GET /trickplay/{jobId}/index.xml`.
- `config/trickplay.php` — trickplay configuration with `enabled`, `interval_seconds`,
  `grid_columns`, `grid_rows`, `thumb_width`, `thumb_height`, `image_format`,
  `jpeg_quality`, `storage_dir`.
- `docs/developers/streaming-protocols.md` — added "Trickplay / Thumbnail Seek"
  section documenting BIF format, generation pipeline, configuration, and
  client-side usage.
- Unit tests: `TrickplayConfigTest` (15 tests), `TrickplayResultTest` (9 tests),
  `TrickplayGeneratorTest` (8 tests), `TrickplayControllerTest` (10 tests).

### Added (Step E.4)

- DASH (Dynamic Adaptive Streaming over HTTP) streaming support alongside
  existing HLS implementation.
- `DashStreamer` — DASH manifest generator and segment manager producing
  DASH-IF compliant MPD manifests with SegmentTemplate elements.
- `SegmentTemplate` — value object for DASH segment template handling
  (SegmentTemplate vs. SegmentList for efficient live streaming).
- `AdaptationSet` — value object representing DASH adaptation sets
  (video, audio, text) with codec/bandwidth metadata.
- `DashController` — HTTP endpoints for DASH streaming:
  `GET /dash/{jobId}/manifest.mpd`, `GET /dash/{jobId}/{setId}/manifest.mpd`,
  `GET /dash/{jobId}/{setId}/segment_{n}.m4s`.
- `config/dash.php` — DASH-specific configuration with `enabled`,
  `manifest_refresh_seconds`, `min_buffer_time`, `min_buffer_time_live`,
  `time_shift_buffer_depth`, `default_codecs`.
- `config/ffmpeg.php` — added `dash` key with `enabled`, `segment_dir`,
  `default_codecs`.
- `HlsStreamer` — added `setSegmentContent()` method so segment writer
  can store once and both HLS and DASH streamers reference the same files.
- `StreamManager` — added `DashStreamer` property and `getManifestUrl()`
  method returning HLS or DASH manifest URL based on `$protocol` parameter.
- `Router` — added `dashStreaming()` route registration method.
- `docs/developers/streaming-protocols.md` — documentation covering HLS
  vs. DASH tradeoffs, manifest structure, client-side selection, and usage.
- Unit tests: `DashStreamerTest` (11 tests), `SegmentTemplateTest` (7 tests),
  `AdaptationSetTest` (8 tests).

### Added (Step E.1)

- Hardware acceleration probe system for detecting GPU encoders (NVENC,
  VAAPI, QSV, VideoToolbox, AMF, V4L2) at startup.
- `HwaccelCapability` — immutable value object representing hardware
  encoder capabilities (vendor, encoder/decoder names, supported codecs,
  HDR tone mapping support, resolution/bitrate limits).
- `HwaccelProbe` — runs vendor-specific probes via `ffmpeg -encoders`
  and `ffmpeg -decoders`, aggregates results into a capability map.
- `HwaccelRegistry` — lazy singleton holding probed capabilities;
  `getEncoder()` / `getDecoder()` use vendor priority for best-match
  selection.
- `VendorProbeInterface` + 7 concrete implementations:
  `NvencProbe`, `VaapiProbe`, `QsvProbe`, `VideoToolboxProbe`,
  `AmfProbe`, `V4L2Probe`, `SoftwareProbe` (always-available fallback).
- `config/hwaccel.php` — `enabled`, `prefer_hardware`,
  `vendor_priority`, `probe_timeout`, `test_clip_path`,
  `fallback_to_software` configuration.
- `config/ffmpeg.php` — added `hwaccel` key with `enabled`,
  `prefer_hardware`, `vendor_priority`.
- `FfmpegRunner` — added `HwaccelRegistry` property and
  `probeHardwareAcceleration()` + `buildHwaccelCommand()` methods.
- `docs/developers/hardware-acceleration.md` — architecture overview,
  capability fields, usage examples, and guide for adding new vendors.
- Unit tests: `HwaccelCapabilityTest` (6 tests),
  `HwaccelProbeTest` (9 tests), `HwaccelRegistryTest` (8 tests).
- No user-visible behavior change yet — transcode remains software-only
  until Step E.2 integrates hardware encoding into TranscodeManager.

### Added (Step D.5)

- Hub-side invite-link sharing (D.5). Invite links are generated on
  the hub and grant library access to recipients. Server-side is unchanged;
  library shares are synced via the existing hub heartbeat mechanism.

### Added (Step D.4)

- First-class passkey / WebAuthn support for passwordless login.
  Supports platform authenticators (Touch ID, Windows Hello, Face ID)
  and roaming FIDO2 tokens (YubiKey, etc.).
- `src/Auth/WebAuthn/WebAuthnManager` — orchestrates registration and
  authentication ceremonies; generates cryptographically random
  challenges; validates attestation and assertions.
- `src/Auth/WebAuthn/WebAuthnCredential` — entity for stored credentials
  with VARBINARY credential ID, sign counter, and device metadata.
- `src/Auth/WebAuthn/WebAuthnSettings` — RP configuration (ID, name,
  origin, attestation requirement).
- `src/Auth/WebAuthn/WebAuthnCredentialRepository` — data access for
  `webauthn_credentials` table; implements replay attack detection via
  sign counter validation.
- `src/Auth/WebAuthnProvider` — implements `ProviderInterface` for
  WebAuthn as an auth provider alongside OIDC/LDAP.
- `src/Server/Http/Controllers/WebAuthnController` — HTTP API with
  6 endpoints for registration, authentication, and credential
  management.
- Database migration `migrations/010_webauthn_credentials.sql` —
  creates `webauthn_credentials` table with VARBINARY credential_id
  and foreign key to users.
- Smarty template `public/templates/auth/webauthn-settings.tpl` —
  user-facing passkey management UI.
- Routes wired in `Application::loadApiRoutes()`:
  `POST /api/v1/auth/webauthn/register/options`,
  `POST /api/v1/auth/webauthn/register/verify`,
  `POST /api/v1/auth/webauthn/login/options`,
  `POST /api/v1/auth/webauthn/login/verify`,
  `GET /api/v1/me/webauthn/credentials`,
  `DELETE /api/v1/me/webauthn/credentials/{id}`.
- Composer dependency added: `web-auth/webauthn-lib: ^4.0`.
- Unit tests in `tests/Unit/Auth/WebAuthn/`: `WebAuthnManagerTest`,
  `WebAuthnCredentialTest`, `WebAuthnControllerTest`,
  `WebAuthnProviderTest`.
- Documentation:
  - `docs/plugins/auth-providers.md` — passkeys section added.
  - `docs/reference/api/auth-webauthn.md` — new API endpoint reference.
  - `docs/security/passkeys.md` — user-facing passkey guide.

### Added (Step D.3)

- `phlix-plugin-ldap` — LDAP authentication provider plugin.
  Supports OpenLDAP and Active Directory via the LDAP protocol.
  Includes:
  - `LdapProvider` — implements `ProviderInterface` with bind
    authentication and user attribute mapping.
  - `LdapConnection` — wraps `directorytree/ldaprecord` Connection
    with request-scoped caching per host:port:ssl triple.
  - `UserMapper` — maps LDAP attributes to Phlix user fields
    (uid/sAMAccountName → username, mail → email, displayname/cn →
    display name, jpegPhoto/thumbnailPhoto → avatar_url).
  - `LdapUserInfo` — LDAP-specific user info carrier.
  - `LdapAdminController` — admin API for LDAP settings management
    and test-connection action.
  - Smarty settings form at `templates/ldap-settings.tpl`.
- Routes wired in `AdminRoutes`:
  `GET /api/v1/admin/auth-providers/ldap/config`,
  `POST /api/v1/admin/auth-providers/ldap/config`,
  `POST /api/v1/admin/auth-providers/ldap/test`,
  `GET /api/v1/admin/auth-providers/ldap/schema`.
- Composer dependency added: `directorytree/ldaprecord: ^3.0`.

### Added (Step D.2)

- `phlix-plugin-oidc` — OIDC/OAuth2 authentication provider plugin.
  Supports any OIDC-compliant identity provider (Authelia, Authentik,
  Keycloak, Google, GitHub). Includes:
  - `OidcProvider` — implements `ProviderInterface` with authorization
    code flow and direct API token authentication.
  - `DiscoveryDocument` — cached OIDC discovery document (24 h TTL).
  - `IdTokenValidator` — RS256/RS384/RS512 token validation with
    cached JWKS.
  - `OidcCallbackController` — handles `/auth/oidc/authorize` and
    `/auth/oidc/callback` routes.
  - `OidcAdminController` — admin API for OIDC settings management.
  - Smarty settings form at `templates/oidc-settings.tpl`.
- Routes wired in `Router::oidcAuth()`:
  `GET /auth/oidc/authorize`, `GET /auth/oidc/callback`.
- Admin routes in `AdminRoutes`:
  `GET /api/v1/admin/auth-providers/oidc/config`,
  `POST /api/v1/admin/auth-providers/oidc/config`,
  `GET /api/v1/admin/auth-providers/oidc/schema`.
- Composer dependencies added: `web-token/jwt-framework: ^3.0`,
  `phpseclib/phpseclib: ^3.0`.

### Added (Step D.1)

- `Phlix\Auth\AuthProviderRegistry` — singleton registry holding
  registered {@see \Phlix\Auth\ProviderInterface} instances; resolves
  provider-prefixed usernames to the correct external provider.
- `Phlix\Auth\ProviderManager` — bridges {@see AuthManager} to the
  registry; handles `provider:username` parsing and delegates to either
  an external provider or the standard password-based flow.
- `Phlix\Auth\AuthProviderNotFoundException` — thrown when a
  provider-prefix references an unregistered provider.
- `Phlix\Auth\AuthManager::loginWithProvider()` — authenticates a user
  via an external provider (OIDC, LDAP, SAML, passkey). On first login,
  automatically creates a local user row with `password_hash = NULL`.
- `Phlix\Auth\UserRepository::findByExternalId()`,
  `findOrCreateByExternalId()`, `updateProviderData()` — data access
  for provider-linked accounts.
- `Phlix\Server\Http\Controllers\AuthProviderController` — admin API
  for listing / enabling / disabling providers and retrieving their
  configuration JSON schema.
- Routes wired in `AdminRoutes`:
  `GET /api/v1/admin/auth-providers`,
  `POST /api/v1/admin/auth-providers/{name}/enable`,
  `POST /api/v1/admin/auth-providers/{name}/disable`,
  `GET /api/v1/admin/auth-providers/{name}/config-schema`.
- Migration `009_auth_provider_schema.sql` adds `provider` (VARCHAR 64),
  `external_id` (VARCHAR 255), `provider_data` (JSON) columns to
  `users` table, with indexes `idx_provider` and `idx_external`.
- `detain/phlix-shared:^0.3.0` — new package version with
  `Phlix\Shared\Auth\ProviderInterface`, `AuthResult`, `UserInfo`.
- `docs/plugins/developer-guide.md` — added "Auth Provider Plugins"
  section (Section 13) covering the interface contract, result types,
  manifest, lifecycle hooks, and admin API.
- Unit tests: `AuthResultTest` (5 tests), `UserInfoTest` (6 tests),
  `AuthProviderRegistryTest` (5 tests), `ProviderManagerTest` (8 tests),
  `UserRepositoryExternalIdTest` (5 tests), `AuthProviderControllerTest` (6 tests).

### Added (Step C.9)

- `Phlix\Hub\HubClient::sendHeartbeat()` — now includes `library_count`,
  `total_size_bytes`, and `library-sharing` capability in heartbeat
  payload to advertise library information to the hub.

### Added (Step C.8)

- `Phlix\Hub\SubdomainResult` — DTO for subdomain allocation result with
  subdomain, fqdn, tlsCertPath, and tlsKeyPath fields.
- `Phlix\Hub\SubdomainClient` — client for claiming/releasing subdomains
  from the hub and storing TLS configuration locally.
- `Phlix\Hub\HttpClientInterface::delete()` — added DELETE method for
  subdomain release.
- `Phlix\Hub\HttpClient::delete()` — implements DELETE method.
- `Phlix\Hub\HubClient::getHttpClient()` — exposes HTTP client for use
  by SubdomainClient.
- `scripts/claim-subdomain.php` — CLI script for claiming a subdomain.
- `config/hub.php` — added `subdomain_auto_claim`, `tls_enabled`,
  `domain` configuration options.
- `docs/dev/tls-certificates.md` — guide covering TLS setup, certificate
  sources (hub-provisioned vs self-signed), and security considerations.
- `docs/reference/env-vars.md` — added `PHLIX_SUBDOMAIN_AUTO_CLAIM`,
  `PHLIX_TLS_ENABLED`, `PHLIX_DOMAIN` environment variables.

### Added (Step C.7)

- `Phlix\Network\UpnpIgdClient` — UPnP-IGD client using raw sockets.
  SSDP M-SEARCH discovery on `239.255.255.250:1900`, SOAP
  `AddPortMapping` / `GetExternalIPAddress` / `DeletePortMapping`
  actions for automatic port forwarding on compatible routers.
- `Phlix\Network\StunClient` — RFC 5389 STUN client for discovering
  the server's public IP address and testing port accessibility via
  TCP connect probe.
- `Phlix\Network\NatPmpClient` — RFC 6886 NAT-PMP client for Apple
  AirPort routers and other NAT-PMP-compatible gateways.
- `Phlix\Network\PortForwardService` — orchestrator that tries UPnP
  first, then NAT-PMP, then STUN for IP detection; falls back to
  manual port-forward instructions; stores result to
  `config/port-forward.json`.
- `scripts/port-forward.php` — CLI with `status`, `enable`,
  `disable`, `info`, and `help` commands.
- `src/Common\Container\Providers\NetworkServicesProvider` — registers
  `UpnpIgdClient`, `StunClient`, `NatPmpClient`, and
  `PortForwardService` in the PHP-DI container.
- `config/port-forward.php` — `PHLIX_PORT_FORWARD_AUTO`,
  `PHLIX_EXTERNAL_PORT`, `PHLIX_EXTERNAL_HTTP_PORT`,
  `PHLIX_EXTERNAL_HTTPS_PORT`, `PHLIX_UPNP_ENABLED`,
  `PHLIX_STUN_SERVER`, `PHLIX_STUN_PORT` configuration.
- `docs/hub/remote-access.md` — end-user guide covering UPnP, NAT-PMP,
  STUN, manual port forwarding setup, and troubleshooting.
- `docs/hub-admin/network.md` — hub admin guide covering port forwarding
  configuration, firewall rules, and network requirements.
- `docs/reference/env-vars.md` — documents port-forwarding and STUN
  environment variables.
- `docs/reference/cli.md` — documents `port-forward.php` CLI commands.
- Unit tests: `UpnpIgdClientTest` (5 tests), `StunClientTest` (8 tests),
  `NatPmpClientTest` (6 tests), `PortForwardServiceTest` (9 tests),
  `PortForwardScriptTest` (5 tests).

### Changed (Step C.7)

- `Phlix\Hub\HubClient` now injects `PortForwardService` and calls
  `discoverHostnameCandidates()` to augment heartbeat hostname
  candidates with LAN IP, mDNS, and public IP endpoints when available.
- `Phlix\Common\Container\ContainerFactory::defaultProviders()` now
  registers `NetworkServicesProvider`.

### Added (Step C.6)

- `Phlix\Hub\RelayMessageFramer` — binary framing for HTTP-over-WebSocket
  tunnel. Wire format: `[1-byte type][4-byte seq][4-byte payload_len][payload]`.
  Types: HTTP_REQUEST (1), HTTP_RESPONSE (2), PING (3), PONG (4).
  All payloads are JSON.
- `Phlix\Hub\RelayFrame` — immutable parsed frame DTO with accessors
  (`isRequest()`, `isResponse()`, `isPing()`, `isPong()`).
- `Phlix\Hub\RelayConfig` — relay tunnel configuration from environment
  variables (`PHLIX_RELAY_ENABLED`, `PHLIX_RELAY_HUB_URL`,
  `PHLIX_RELAY_TUNNEL_HOSTNAME`, etc.).
- `Phlix\Hub\RelayConsumer` — server-side Workerman consumer that opens a
  persistent WSS connection to the hub, receives framed HTTP requests,
  dispatches them to the local router, and sends responses back over the
  tunnel. Implements auto-reconnect with configurable delay and
  keep-alive ping/pong.
- `Phlix\Hub\RelayApplication` — thin Workerman Worker entry point
  (`text://` protocol, timer-driven) wrapping `RelayConsumer`.
- `config/relay.php` — `PHLIX_RELAY_ENABLED`, `PHLIX_RELAY_HUB_URL`,
  `PHLIX_RELAY_TUNNEL_HOSTNAME`, `PHLIX_RELAY_RECONNECT_DELAY`,
  `PHLIX_RELAY_PING_INTERVAL`, `PHLIX_RELAY_PING_TIMEOUT`.
- `config/hub.php` — added `relay` capability to heartbeat payload.
- `docs/dev/relay-protocol.md` — wire protocol reference for the
  HTTP-over-WebSocket relay tunnel.
- `docs/reference/env-vars.md` — documents relay env vars.
- Unit tests: `RelayMessageFramerTest` (13 tests covering frame round-trips,
  ping/pong, invalid/incomplete frames), `RelayConsumerTest` (11 tests
  covering config, routing, connection state).

### Changed (Step C.6)

- `Phlix\Hub\HubClient::sendHeartbeat()` now advertises `relay`
  in the server capabilities list.
- `Phlix\Server\Core\Application` now starts `RelayApplication`
  automatically when `config/hub-enrollment.json` exists and
  `PHLIX_RELAY_ENABLED=true`.
- `Phlix\Common\Container\Providers\HubServicesProvider` now registers
  `RelayConfig`, `RelayMessageFramer`, `RelayConsumer`, and
  `RelayApplication` in the PHP-DI container.

### Added (Step C.2)

- `Phlix\Hub\HubClient` — server-side orchestrator for server↔hub pairing,
  heartbeat loop, re-enrollment, and JWKS exposure. Implements the protocol
  defined in `docs/dev/pairing-protocol.md`.
- `Phlix\Hub\Ed25519KeyManager` — generates, stores, loads, and rotates
  Ed25519 keypairs (libsodium `sodium_crypto_sign_*`). Key stored at
  `config/hub-server-key.pem` (mode 0600). Key ID is SHA-256 first 8 bytes
  of the public key (base64url).
- `Phlix\Hub\HttpClient` — cURL-based HTTP client for hub API communication.
  Always sends `Accept-Phlix-Protocol: v1` header.
- `Phlix\Hub\HubApplication` — thin Workerman Worker wrapper for the
  background heartbeat loop (`text://` protocol, timer-driven).
- `Phlix\Server\Http\Controllers\HubJwksController` — serves
  `GET /.well-known/jwks.json` with the server's Ed25519 JWK(s).
  Cache-Control: public, max-age=3600.
- `scripts/pair-with-hub.php` — CLI pairing script. Initiates claim request,
  displays claim code, polls until claimed, stores enrollment, starts
  heartbeat loop.
- `config/hub.php` — hub subsystem configuration (`PHLIX_HUB_URL`,
  `PHLIX_HUB_HEARTBEAT_INTERVAL`, key/enrollment paths).
- `Phlix\Common\Container\Providers\HubServicesProvider` — registers
  Ed25519KeyManager, HubClient, HubJwksController, HubApplication in
  the PHP-DI container.
- `docs/reference/api/hub-jwks.yaml` — OpenAPI 3.0 spec for
  `/.well-known/jwks.json`.
- `docs/reference/cli.md` — documents `php scripts/pair-with-hub.php`.
- `docs/reference/env-vars.md` — documents `PHLIX_HUB_URL`,
  `PHLIX_HUB_ENROLLMENT_TOKEN`, `PHLIX_HUB_HEARTBEAT_INTERVAL`.

### Changed (Step C.2)

- `src/Server/Core/Application` now starts the hub heartbeat background
  worker automatically when `config/hub-enrollment.json` exists.
- `src/Common\Container\ContainerFactory` now wires `HubServicesProvider`
  into the default provider list.

### Added (Step C.5)

- `Phlix\Hub\HubJwtValidator` — validates JWTs issued by the Phlix Hub
  using the hub's JWKS. Supports Ed25519 signature verification via
  `sodium_crypto_sign_verify_detached`, automatic JWKS caching with TTL,
  and key rotation (refetches JWKS once on unknown `kid`).
- `Phlix\Hub\HubUserClaims` — immutable DTO for extracted hub JWT claims
  (`userId`, `serverId`, `subject`, `issuer`, `expiresAt`, `scope`).
- `Phlix\Hub\JwksCache` — in-memory JWKS cache with TTL support.
- `Phlix\Hub\HttpClientFactory` — factory for creating HTTP clients used
  by `HubJwtValidator` to fetch JWKS (enables testability).
- `Phlix\Server\Http\Middleware\HubJwtMiddleware` — validates hub JWTs on
  routes that support hub-mediated access. Populates `$request->hubUser`
  with `HubUserClaims` on success; returns 401 on invalid/expired tokens.
- `Phlix\Server\Http\Controllers\HubTokenController` — exchanges a hub JWT
  for a server-issued session token via `POST /api/v1/auth/hub-token`.
  Provides backward compatibility for older clients that present a hub
  JWT to get a server session token.
- `Phlix\Server\Http\Request::$hubUser` — new property holding
  `HubUserClaims` when the request was authenticated via hub JWT.
- `config/hub.php` — added `hub_jwks_url` key (`PHLIX_HUB_JWKS_URL`
  env var) for the hub's JWKS endpoint.
- `docs/reference/env-vars.md` — documents `PHLIX_HUB_JWKS_URL`.
- Unit tests: `HubJwtValidatorTest`, `HubUserClaimsTest`,
  `JwksCacheTest`, `HubJwtMiddlewareTest` (18 new tests).

### Changed (Step C.5)

- `Phlix\Common\Container\Providers\HubServicesProvider` now registers
  `HubJwtValidator`, `HubTokenController`, `HubJwtMiddleware`,
  `HttpClientFactory`, and `JwksCache`.
- `Phlix\Server\Core\Application` now registers the
  `POST /api/v1/auth/hub-token` route.

## [0.11.0] — 2026-05-17

### Changed

- Repository moved from `github.com/detain/phlix` to
  `github.com/detain/phlix-server`. The local working directory stays
  `/home/sites/phlix` per the expansion plan; only the `origin` remote
  URL changes. Update your local clone with
  `git remote set-url origin git@github.com:detain/phlix-server.git`.
  The old `detain/phlix` repo is archived (B.4b) with a README pointing
  at the new home.
- Refactored to depend on `detain/phlix-shared:^0.2`. The
  `LifecycleInterface`, manifest DTOs, event DTOs, and `EventNameMap`
  now live in the shared package. Old FQCNs
  (`Phlix\Plugins\Contract\LifecycleInterface`,
  `Phlix\Plugins\Manifest`, `Phlix\Plugins\ManifestType`,
  `Phlix\Plugins\ManifestValidationError`,
  `Phlix\Plugins\EventNameMap`, `Phlix\Common\Events\*`) remain as
  deprecated aliases through 0.11.x; removed in 0.12.0.
- Manifest schema validation extracted to
  `Phlix\Plugins\Manifest\ManifestSchema`.

### Added

- Composer require on `detain/phlix-shared:^0.2.0` via a VCS
  repositories entry.
- `src/Plugins/AliasCompatShim.php` registers the 16 `class_alias`
  entries for the moved classes.
- Three-line interface bridge at
  `src/Plugins/Contract/LifecycleInterface.php` (extends the shared
  interface — `class_alias` doesn't work for interfaces).

- Complete plugin developer documentation
  ([`docs/plugins/developer-guide.md`](docs/plugins/developer-guide.md))
  covering plugin types, manifest, lifecycle, event subscription,
  settings, signing, packaging, local testing, and publishing — plus a
  matching server-internals reference for contributors extending the
  loader ([`docs/dev/plugin-sdk.md`](docs/dev/plugin-sdk.md)). Phase A
  is now functionally complete; the plugin system is ready for
  external authors. `docs/plugins/install-from-catalog.md` rewritten
  to set expectations about the catalog's Phase L delivery; README
  promotes the developer guide and the reference plugin.
- Plugin manifest specification (`docs/plugins/manifest.md`,
  `docs/plugins/manifest.schema.json`) and the
  `Phlix\Plugins\Manifest` value object that parses and validates
  `plugin.json` files. The eleven plugin types from
  `PHLIX_EXPANSION_PLAN.md` §5 are codified as the
  `Phlix\Plugins\ManifestType` enum. No loader yet — see Step A.4.
  Adds `justinrainbow/json-schema:^5.2` as a runtime dependency.
- PSR-11 dependency injection container (PHP-DI). Application services are
  now auto-wired; the legacy ConnectionPool / LoggerFactory statics remain
  for backwards compatibility but are wrapped behind container bindings.
- `phpstan/phpstan` (level 9) and `squizlabs/php_codesniffer` (PSR-12) added
  as require-dev so the documented "minimum bar" is actually enforceable.
  A `phpstan-baseline.neon` absorbs pre-existing errors so new code is held
  to the bar without forcing a repo-wide refactor.
- `docs/dev/architecture-server.md` and `docs/reference/env-vars.md`.
- PSR-14 event dispatcher (Crell\Tukio). Playback, library-scan, and
  auth lifecycle events are now published from `PlaybackController`,
  `MediaScanner`, and `AuthManager`; plugins will be able to subscribe in
  Phase A.4. Twelve typed `readonly` event DTOs ship in
  `src/Common/Events/`. New env var `PHLIX_DEBUG_EVENTS` and `events`
  log channel. Canonical catalog in `docs/dev/event-reference.md`.
- Plugin loader (`Phlix\Plugins\PluginLoader`) with the full
  install / enable / disable / uninstall lifecycle. Plugins can be
  installed from a URL (HTTPS + `file://` by default; HTTP behind
  `PHLIX_PLUGINS_ALLOW_HTTP=1`) or from a local directory; each plugin
  gets its own Composer-resolved `vendor/` tree under
  `var/plugins/<name>/`. The lifecycle contract lives in
  `Phlix\Plugins\Contract\LifecycleInterface` (temporary home — moves to
  `Phlix\Shared\Plugin` in B.1). New table `plugins` (migration
  `migrations/003_plugins.sql`). New `plugins` log channel and config
  key. New env vars: `PHLIX_PLUGINS_ALLOW_HTTP`,
  `PHLIX_PLUGINS_REQUIRE_SIGNATURE`, `PHLIX_PLUGINS_COMPOSER_TIMEOUT`.
  Adds `symfony/process:^7.0`.
  See `docs/plugins/developer-guide.md` for the lifecycle diagram and
  a sample `LifecycleInterface` implementation.
- Plugin admin UI at `/admin/plugins` and JSON API under
  `/api/v1/admin/plugins/*` (list / install / enable / disable /
  uninstall). All routes gated by a new `AdminMiddleware` that reads
  the new `users.is_admin` flag (migration `004_admin_user_flag.sql`).
  The first user registered after the migration is auto-promoted to
  admin; subsequent users default to `is_admin = 0`. Adds runtime
  Composer dep `smarty/smarty:^4.0` (already used at runtime; now
  declared). OpenAPI spec at `docs/reference/api/admin-plugins.yaml`;
  end-user docs at `docs/plugins/install-from-url.md`. Editable
  settings UI deferred to a later phase — A.5 renders settings
  read-only with `secret: true` fields masked.
- Reference plugin
  [`phlix-plugin-example`](https://github.com/detain/phlix-plugin-example)
  — the first community-shaped Phlix plugin, published as its own
  public GitHub repo. Implements
  `Phlix\Plugins\Contract\LifecycleInterface` as a
  `metadata-provider` that returns `['title' => 'Hello, World']` for a
  fixed fixture path, and ships unsigned by design as the canonical
  fork-as-starter template for plugin authors. Installable through the
  A.5 admin UI by pasting
  `https://raw.githubusercontent.com/detain/phlix-plugin-example/main/plugin.json`
  into **Install from URL**. Server-side wiring: new fixture
  `tests/fixtures/plugins/example-manifest.json` mirrors the published
  manifest so the loader's URL-install test can use a `file://` URL,
  and `docs/plugins/install-from-url.md` /
  `docs/plugins/trusted-plugin-list.md` now reference the live
  example URL.

### Deprecated

- `Phlix\Server\Core\Application::getInstance()` — resolve services from
  the PSR-11 container instead. Slated for removal in Phase B.
