# Step H.4 — Trakt scrobble plugin (OAuth + two-way sync)

**Phase:** H (Smart Features)
**Step:** H.4
**Depends on:** G.3
**Review:** Yes — see `h.4-trakt-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Implement `phlex-plugin-trakt` as a first-class in-core plugin: OAuth
login with Trakt.tv, scrobble playback start/stop/progress to Trakt,
and two-way watch-history sync (Trakt ← Phlex on scrobble; Trakt → Phlex
via periodic pull). The plugin subscribes to `PlaybackStarted`,
`PlaybackStopped`, and `PlaybackProgressUpdated` events (A.2); the sync
scheduler runs every 30 minutes (configurable). A companion plugin
package ships in `phlex-plugin-trakt/` as a reference for plugin authors.

## 2. Context (what already exists)

- `src/Plugins/PluginLoader.php` (A.4) — plugin lifecycle.
- `src/Plugins/Manifest.php` (A.3) — manifest parsing.
- `src/Common/Events/ListenerRegistry.php` (A.2) — event dispatch.
- `src/Auth/AuthManager.php` — OAuth flow helpers (pattern from G.3
  Last.fm plugin).
- `src/Media/Session/PlaybackController.php` — playback state; emits
  events.
- `src/Common/Events/Playback/PlaybackStarted.php` (A.2) — event class.
- `src/Common/Events/Playback/PlaybackStopped.php` (A.2).
- `src/Common/Events/Playback/PlaybackProgressUpdated.php` (A.2).
- `src/Media/Session/WatchHistory.php` (B.3) — local watch history.
- `PHLEX_EXPANSION_PLAN.md` §2 Phase G table — G.3 is the Last.fm
  scrobble plugin that H.4 follows as a template.
- `PHLEX_EXPANSION_PLAN.md` §5 Plugin types table — `scrobbler` type.

Existing patterns to follow:

- `src/Plugins/Scrobbler/Lastfm/LastfmPlugin.php` (G.3) — reference
  implementation for `scrobbler` plugin type. H.4 mirrors the same
  structure but for Trakt's API and OAuth endpoints.
- OAuth config in `config/plugins.php` or a dedicated
  `config/scrobblers.php` (following the per-provider config pattern
  from G.1).

## 3. Scope — files to create / modify

### Create

#### New plugin package (`src/Plugins/Scrobbler/Trakt/` or standalone plugin)

- `src/Plugins/Scrobbler/Trakt/TraktPlugin.php` — entry class:

  ```php
  class TraktPlugin implements LifecycleInterface
  {
      public function __construct(
          private readonly TraktApi $api,
          private readonly TraktSettings $settings,
          private readonly WatchHistory $watchHistory,
          private readonly ListenerRegistry $events,
      ) {}

      public function onEnable(ContainerInterface $c): void {}
      // Subscribes to PlaybackStarted, PlaybackStopped, PlaybackProgressUpdated

      public function onDisable(): void {}
      // Unsubscribes all listeners

      public function subscribedEvents(): array {}
      // Returns [
      //   PlaybackStarted::class       => 'onPlaybackStarted',
      //   PlaybackStopped::class       => 'onPlaybackStopped',
      //   PlaybackProgressUpdated::class => 'onPlaybackProgressUpdated',
      // ]

      public function onPlaybackStarted(PlaybackStarted $event): void {}
      public function onPlaybackStopped(PlaybackStopped $event): void {}
      public function onPlaybackProgressUpdated(PlaybackProgressUpdated $event): void {}

      public function getSettings(): TraktSettings {}
      public function setAccessToken(string $token): void {}
      public function setRefreshToken(string $token): void {}
  }
  ```

- `src/Plugins/Scrobbler/Trakt/TraktApi.php` — Trakt.tv API v3 client:

  ```php
  class TraktApi
  {
      public function __construct(
          private readonly HttpClient $http,
          private readonly string $clientId,
          private readonly string $clientSecret,
      ) {}

      // OAuth
      public function getAuthUrl(string $state): string {}
      public function exchangeCode(string $code): array {}
      // { access_token, refresh_token, expires_in }

      public function refreshAccessToken(string $refreshToken): array {}

      // Scrobble
      public function scrobbleStart(MediaItem $item, int $progress): array {}
      public function scrobblePause(MediaItem $item, int $progress): array {}
      public function scrobbleStop(MediaItem $item, int $progress): array {}

      // History sync
      public function getWatchedHistory(string $userId, int $page = 1): array {}
      public function addToHistory(MediaItem $item, \DateTimeImmutable $watchedAt): array {}
  }
  ```

- `src/Plugins/Scrobbler/Trakt/TraktSettings.php` — per-user settings:

  ```php
  class TraktSettings
  {
      public function __construct(
          public readonly ?string $accessToken  = null,
          public readonly ?string $refreshToken = null,
          public readonly ?int $expiresAt      = null,
          public readonly bool $syncEnabled     = true,
          public readonly int $syncIntervalMinutes = 30,
          public readonly bool $scrobbleEnabled    = true,
          public readonly string $username        = '',
      ) {}
  }
  ```

- `src/Plugins/Scrobbler/Trakt/TraktHistorySync.php` — scheduled sync
  worker:

  ```php
  class TraktHistorySync
  {
      public function __construct(
          private readonly TraktApi $api,
          private readonly WatchHistory $watchHistory,
          private readonly TraktSettings $settings,
      ) {}

      public function syncTraktToPhlex(): void {}
      // Pulls Trakt watch history; writes to local WatchHistory
      // for items not already at ≥ 90% complete.

      public function syncPhlexToTrakt(): void {}
      // Pushes local WatchHistory entries (≥ 90%) to Trakt.
  }
  ```

- `src/Plugins/Scrobbler/Trakt/TraktPluginInstaller.php` — extends the
  A.4 plugin loader with Trakt-specific OAuth redirect handling.

- `config/scrobblers/trakt.php` — default config:

  ```php
  return [
      'client_id'     => '',   // user registers at trakt.tv/apps
      'client_secret' => '',
      'redirect_uri'  => 'https://your-server.com/api/v1/oauth/trakt/callback',
      'sync_interval' => 30,   // minutes
  ];
  ```

- `tests/unit/Plugins/Scrobbler/Trakt/TraktApiTest.php`
- `tests/unit/Plugins/Scrobbler/Trakt/TraktSettingsTest.php`
- `tests/unit/Plugins/Scrobbler/Trakt/TraktHistorySyncTest.php`
- `tests/unit/Plugins/Scrobbler/Trakt/TraktPluginTest.php`

#### New plugin (standalone package — `phlex-plugin-trakt/`)

- `phlex-plugin-trakt/plugin.json` — scrobbler plugin manifest:
  ```json
  {
    "name": "phlex-plugin-trakt",
    "version": "1.0.0",
    "phlex_min_server_version": "0.14.0",
    "type": "scrobbler",
    "entry": "Phlex\\Plugins\\Scrobbler\\Trakt\\TraktPlugin",
    "events": [
      "phlex.playback.started",
      "phlex.playback.stopped",
      "phlex.playback.progress"
    ]
  }
  ```

- `phlex-plugin-trakt/README.md` — setup guide for users.

#### Documentation

- `docs/developers/scrobbler-plugins.md` — explains `scrobbler` plugin
  contract: required events, OAuth flow, settings shape.
- `docs/plugins/developer-guide.md` (already updated in A.7) — add
  Trakt as a worked example.

### Modify

- `src/Server/Http/Router.php` — add OAuth callback route:
  `GET /api/v1/oauth/trakt` → `TraktOAuthController`.
- `src/Server/Http/Controllers/TraktOAuthController.php` — handles OAuth
  redirect, exchanges code for tokens, stores in plugin settings.
- `src/Plugins/PluginLoader.php` (A.4) — register the plugin as an
  installable `scrobbler` plugin (the manifest `type` field routes it
  correctly).
- `CHANGELOG.md` — `Added: Trakt.tv scrobble plugin (H.4). OAuth
  connect, scrobble on playback start/stop/progress, two-way
  watch-history sync. Ships as phlex-plugin-trakt.`

### Delete

- None.

## 4. Approach

1. **Pre-flight.** Clean master on `/home/sites/phlex`. Branch:
   `git checkout -b h.4-trakt`.
2. **TraktApi.** Implements Trakt v3 API + OAuth2 PKCE (Trakt uses
   plain OAuth2, not OpenID Connect). Handles token refresh automatically
   when receiving 401 from any API call.
3. **Settings + plugin entry.** `TraktSettings` stores tokens and prefs;
   `TraktPlugin` subscribes to three playback events and calls the API.
4. **Scrobble semantics:**
   - `PlaybackStarted` → `scrobbleStart()` (Trakt `start` action).
   - `PlaybackProgressUpdated` (fired every 30s by `player.js`) →
     `scrobblePause()` (Trakt `pause` action). Only when
     `scrobbleEnabled = true`.
   - `PlaybackStopped` → `scrobbleStop()` (Trakt `stop` action);
     includes final `progress` value.
   Trakt uses a 3-state scrobble protocol (start/pause/stop); our events
   map directly.
5. **History sync.** Two directions:
   - **Trakt → Phlex**: `syncTraktToPhlex()` runs on a schedule
     (cron every 30 min); compares Trakt's watched episodes/movies
     against local `watch_history`; writes new entries for anything
     watched on Trakt but not yet ≥ 90% on Phlex.
   - **Phlex → Trakt**: `syncPhlexToTrakt()` runs after every
     `PlaybackStopped` where the item reached ≥ 90%; calls
     `addToHistory()` so Trakt gets credit for the watch.
   This is the "two-way" sync described in the spec.
6. **OAuth controller.** `GET /api/v1/oauth/trakt` initiates PKCE flow;
   `GET /api/v1/oauth/trakt/callback` handles the redirect, exchanges
   code, stores tokens in `TraktSettings` (serialised to plugin config
   JSON).
7. **Plugin manifest.** Write `phlex-plugin-trakt/plugin.json` so the
   plugin is installable via URL or catalog.
8. **Tests.** Unit + integration per §5.
9. **Verification bar** (§0.4 minimum bar).
10. **Docs.**
11. **Commit + PR + merge.**

## 5. Tests (REQUIRED — §0.4 minimum bar)

Unit tests (coverage ≥ 85 % on every new class):

`TraktApiTest`:
1. `test_get_auth_url_contains_expected_params`
2. `test_exchange_code_returns_tokens`
3. `test_refresh_access_token_returns_new_tokens`
4. `test_scrobble_start_posts_correct_payload`
5. `test_scrobble_pause_posts_correct_payload`
6. `test_scrobble_stop_posts_correct_payload`
7. `test_get_watched_history_returns_array`
8. `test_add_to_history_posts_correct_payload`
9. `test_401_triggers_token_refresh_and_retry`

`TraktSettingsTest`:
10. `test_constructor_stores_all_values`
11. `test_default_values`

`TraktHistorySyncTest`:
12. `test_sync_trakt_to_phlex_writes_missing_entries`
13. `test_sync_phlex_to_trakt_pushes_completed_items`
14. `test_sync_skips_items_below_90_percent`

`TraktPluginTest`:
15. `test_on_enable_subscribes_to_playback_events`
16. `test_on_disable_unsubscribes`
17. `test_on_playback_started_calls_scrobble_start`
18. `test_on_playback_stopped_calls_scrobble_stop`
19. `test_on_playback_progress_calls_scrobble_pause`

**Coverage target:** `TraktApi` ≥ 85 %, `TraktSettings` ≥ 85 %,
`TraktHistorySync` ≥ 85 %, `TraktPlugin` ≥ 85 %.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **"The plugin API"** → `docs/developers/scrobbler-plugins.md` (new)
  covers the scrobbler contract, events, OAuth, settings shape.
- **"User-visible behavior change"** → CHANGELOG entry.
- **"New public class/method"** → PHPDoc with `@since 0.14.0`.

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] `TraktApi` implements full OAuth2 PKCE flow with token refresh.
- [ ] `TraktPlugin::onPlaybackStarted()` → `scrobbleStart()`.
- [ ] `TraktPlugin::onPlaybackStopped()` → `scrobbleStop()`.
- [ ] `TraktPlugin::onPlaybackProgressUpdated()` → `scrobblePause()`.
- [ ] `TraktHistorySync::syncTraktToPhlex()` pulls Trakt history → Phlex.
- [ ] `TraktHistorySync::syncPhlexToTrakt()` pushes completed watches →
      Trakt.
- [ ] OAuth callback at `/api/v1/oauth/trakt/callback` stores tokens.
- [ ] `config/scrobblers/trakt.php` with all required keys.
- [ ] `phlex-plugin-trakt/plugin.json` exists and is valid.
- [ ] `./vendor/bin/phpunit` — green; ≥ 19 new tests.
- [ ] Coverage of each new class ≥ 85 %.
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — zero new errors.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] `docs/developers/scrobbler-plugins.md` written.
- [ ] CHANGELOG entry added.
- [ ] Git ritual §8 executed; postcondition checks PASS.

## 8. Git ritual (copy of master plan §11.4)

```bash
# ─── 0. PRECONDITION ───
cd /home/sites/phlex
git status --short
git branch --show-current
git pull --ff-only origin master

# ─── 1. Branch ───
git checkout -b h.4-trakt

# ─── 2. Do the work ───

# ─── 3. Verify ───
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'TraktApi|TraktSettings|TraktHistorySync|TraktPlugin'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync ───
git add -A

# ─── 5. Commit ───
git commit -m "Step H.4: Trakt scrobble plugin with two-way history sync"

# ─── 6. CRITICAL ───
unset GITHUB_TOKEN

# ─── 7. PR + merge ───
gh pr create \
  --title "Step H.4: Trakt scrobble plugin with two-way sync" \
  --body  "Adds TraktApi, TraktPlugin, TraktSettings, TraktHistorySync, TraktOAuthController. OAuth login, scrobble on start/pause/stop, two-way watch-history sync. Ships as phlex-plugin-trakt. Part of Phase H (Step H.4 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch

# ─── 8. Return to master ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION ───
git status --short
git branch --show-current
git log --oneline -1
git branch --list 'h.4-*'
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs `h.4-trakt-review.md`.

Non-obvious points:
- Trakt's scrobble API is a 3-state model (start/pause/stop); our
  events map cleanly but the sync direction (start vs pause) matters —
  the plugin only calls `scrobblePause` on `PlaybackProgressUpdated`,
  not on every progress tick (throttled by `player.js` to 30s
  intervals).
- Two-way history sync uses a last-write-wins approach based on
  `watchedAt` timestamp; conflicts are resolved by whichever side has
  the most recent timestamp.
- OAuth uses PKCE because Trakt.tv requires it for all public clients.
