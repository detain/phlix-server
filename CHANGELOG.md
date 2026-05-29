# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Security

- **Require admin authentication on theme-media mutation endpoints.** `POST /api/v1/libraries/{id}/theme-media/scan` (`scanThemeMedia`) and `DELETE /api/v1/libraries/{id}/theme-media` (`deleteThemeMedia`) were registered as bare routes with no auth gate, so unauthenticated callers could trigger filesystem scans and delete cached theme media for any library ID. `ThemeMediaController` now carries an optional `AdminMiddleware` (wired in `Application::getThemeMediaController()`, mirroring `LibraryController`) and both mutation methods return `401`/`403` for unauthenticated/non-admin callers before any side effect. The read endpoint (`getThemeMedia`) is unchanged. (Flagged by an external review of an earlier PR; verified still present and fixed here.)

### Fixed

- **Honor HTTP `Range` requests in audiobook & photo streaming.** `AudiobookController::streamAudiobook()` and `PhotoController::getFull()` read the range header via raw `$request->headers['Range']` array access, but `Request::parseHeaders()` stores header keys upper-cased (`RANGE`), so the mixed-case lookup never matched and every range/seek request silently fell through to a full `200` instead of a `206` partial response. Both now read via the case-insensitive `Request::getHeader('Range')`, restoring seek/resume.

### Added

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
