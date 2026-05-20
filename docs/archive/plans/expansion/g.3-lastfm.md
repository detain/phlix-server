# Step G.3 — Last.fm scrobble plugin

**Phase:** G (Music / Photos / Books / Audiobooks)
**Step:** G.3
**Depends on:** G.2
**Review:** Yes — see `g.3-lastfm-review.md`
**Target repo:** `detain/phlex-server` (local: `/home/sites/phlex/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Implement a Last.fm scrobble plugin that subscribes to playback events
dispatched by the PSR-14 event dispatcher and posts scrobble data to
the Last.fm `track.scrobble` API on track completion. The plugin is
shaped exactly like a community plugin (entry class in
`src/Plugins/Lastfm/`) but ships in-core as a reference implementation of
the `scrobbler` plugin type.

## 2. Context (what already exists)

- `src/Common/Events/EventDispatcher.php` — PSR-14 dispatcher wired in
  Phase A.2; dispatches `phlex.playback.started`, `phlex.playback.stopped`,
  `phlex.playback.progress` events.
- `src/Session/PlaybackController.php` — after G.2, emits
  `playback.started` / `playback.stopped` events.
- `PHLEX_EXPANSION_PLAN.md` §2 Phase G table — G.3 is the Last.fm scrobble
  step.
- `PHLEX_EXPANSION_PLAN.md` §5 Plugin system — plugin type `scrobbler`
  listens to `phlex.playback.started` and `phlex.playback.stopped`.
- `docs/plugin-development.md` (from A.7) — documents plugin lifecycle
  and event subscriber pattern.

## 3. Scope — files to create / modify

### Create

#### New classes

- `src/Plugins/Lastfm/Plugin.php` — plugin entry class:

  ```php
  class Plugin implements PluginInterface, EventSubscriberInterface
  {
      public const PLUGIN_TYPE = 'scrobbler';

      public function __construct(
          private readonly LastfmApiClient $api,
          private readonly SessionManager $sessions,
          private readonly ?LoggerInterface $logger = null,
      ) {}

      public static function getSubscribedEvents(): array {}
      // ['phlex.playback.stopped' => 'onPlaybackStopped']

      public function onPlaybackStopped(PlaybackStopped $event): void {}
      // - Fetch session, calculate submission hash
      // - Call Last.fm track.scrobble API
      // - Log success/failure

      public function getPluginType(): string {}
      public function getPluginName(): string {}
  }
  ```

- `src/Plugins/Lastfm/LastfmApiClient.php` — Last.fm API v1.2 client:

  ```php
  class LastfmApiClient
  {
      public function __construct(
          private readonly string $api_key,
          private readonly string $api_secret,
          private readonly ?LoggerInterface $logger = null,
      ) {}

      /** Authenticate with Last.fm using username + password hash. Returns session key. */
      public function getMobileSession(string $username, string $password_hash): string {}

      /** Validate a session key. */
      public function validateSession(string $session_key): bool {}

      /** Submit a scrobble. Returns true on success. */
      public function scrobble(ScrobbleData $data): bool {}

      /** Update Now Playing status. */
      public function nowPlaying(NowPlayingData $data): bool {}
  }
  ```

- `src/Plugins/Lastfm/ScrobbleData.php` — value object for scrobble
  submission:

  ```php
  final class ScrobbleData
  {
      public function __construct(
          public readonly string $artist_name,
          public readonly string $track_title,
          public readonly int $timestamp_unix,
          public readonly ?string $album_name = null,
          public readonly ?int $track_number = null,
          public readonly ?int $duration_secs = null,
          public readonly ?string $mbid = null,        // MusicBrainz recording ID
      ) {}
  }
  ```

- `src/Plugins/Lastfm/NowPlayingData.php` — value object for Now Playing:

  ```php
  final class NowPlayingData
  {
      public function __construct(
          public readonly string $artist_name,
          public readonly string $track_title,
          public readonly ?string $album_name = null,
          public readonly ?int $duration_secs = null,
          public readonly ?string $mbid = null,
      ) {}
  }
  ```

- `src/Plugins/Lastfm/LastfmPluginNotConfiguredException.php`
- `src/Plugins/Lastfm/LastfmScrobbleFailedException.php`

- `config/lastfm.php` — default config:

  ```php
  return [
      'enabled'         => false,       // must be explicitly enabled
      'api_key'         => '',
      'api_secret'      => '',
      'session_key'     => '',           // stored after user auth
      'username'        => '',
      'submit_now_playing' => true,     // send np.update along with scrobble
      'scrobble_threshold' => 0.5,       // fraction of track duration that triggers scrobble
  ];
  ```

- `tests/Unit/Plugins/Lastfm/LastfmApiClientTest.php`
- `tests/Unit/Plugins/Lastfm/PluginTest.php`

#### Documentation

- `docs/plugins/developer-guide.md` — already exists from A.7; add a
  section on `scrobbler` plugin type with Last.fm as reference example.
- `docs/libraries/music.md` — already exists from G.2; add a section on
  Last.fm scrobbling: how to enable, what data is sent, scrobble
  threshold setting.

### Modify

- `src/Server/Core/Application.php` — after plugin system is wired,
  the plugin loader calls `PluginInterface::onEnable()` on all installed
  plugins. No direct Last.fm wiring here — everything goes through the
  plugin loader.
- `config/plugins.php` — add `lastfm` entry to the catalog pointing to
  `Phlex\Plugins\Lastfm\Plugin`.
- `composer.json` — no new runtime dependencies.

### Delete

- None.

## 4. Approach

1. **Pre-flight.** Clean master on `/home/sites/phlex`. Branch:
   `git checkout -b g.3-lastfm`.
2. **Value objects first.** Write `ScrobbleData` and `NowPlayingData`
   with full PHPDoc and `@since 0.15.0`.
3. **API client.** Implement `LastfmApiClient` with HMAC-MD5 signature
   generation per Last.fm spec. Include `getMobileSession` (mobile auth
   flow, no web OAuth redirect), `validateSession`, `scrobble`,
   `nowPlaying`.
4. **Plugin class.** Implement `PluginInterface` + `EventSubscriberInterface`.
   `onPlaybackStopped` extracts `artist`, `track`, `timestamp` from the
   `PlaybackStopped` event, builds `ScrobbleData`, calls `scrobble()`.
   Before first scrobble, validates the stored session key.
5. **Exception classes.** `LastfmPluginNotConfiguredException` (thrown
   when api_key/secret are empty), `LastfmScrobbleFailedException`
   (thrown when API returns non-OK status).
6. **Config.** Write `config/lastfm.php`.
7. **Tests.** Write both test files per §5. Mock `LastfmApiClient` and
   `SessionManager`; use `EventDispatcher` mock to fire events.
8. **Verification bar** (§0.4 minimum bar).
9. **Docs.**
10. **Commit + PR + merge.**

## 5. Tests (REQUIRED — §0.4 minimum bar)

Unit tests (coverage ≥ 85 % on every new class):

1. `LastfmApiClientTest::test_get_mobile_session_returns_session_key`
2. `LastfmApiClientTest::test_validate_session_true_for_valid_key`
3. `LastfmApiClientTest::test_validate_session_false_for_invalid_key`
4. `LastfmApiClientTest::test_scrobble_returns_true_on_success`
5. `LastfmApiClientTest::test_scrobble_returns_false_on_api_error`
6. `LastfmApiClientTest::test_now_playing_returns_true_on_success`
7. `PluginTest::test_get_subscribed_events_returns_playback_stopped`
8. `PluginTest::test_on_playback_stopped_calls_scrobble`
9. `PluginTest::test_on_playback_stopped_does_nothing_when_not_configured`
10. `PluginTest::test_on_playback_stopped_does_nothing_when_disabled`
11. `PluginTest::test_get_plugin_type_returns_scrobbler`
12. `PluginTest::test_scrobble_threshold_respected`

**Coverage target:** `LastfmApiClient` ≥ 85 %, `Plugin` ≥ 85 %.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **"The plugin API"** → `docs/plugins/developer-guide.md` update
  (add scrobbler type section with Last.fm as worked example).
- **"Anything"** → `docs/developers/lastfm-plugin.md` (new) covers
  Last.fm API, scrobble protocol, session key storage, threshold
  config.
- **"New public class/method"** → all new public classes get PHPDoc with
  `@since 0.15.0`.
- **"User-visible behavior change"** → CHANGELOG entry (Last.fm
  scrobbling available as plugin; off by default).

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] `ScrobbleData` and `NowPlayingData` are immutable value objects.
- [ ] `LastfmApiClient::getMobileSession()` calls the correct endpoint
      with correct HMAC-MD5 signature.
- [ ] `LastfmApiClient::scrobble()` builds correct POST body and parses
      response correctly.
- [ ] `LastfmApiClient::nowPlaying()` calls `track.updateNowPlaying`.
- [ ] `Plugin::onPlaybackStopped()` is called when `phlex.playback.stopped`
      event is dispatched.
- [ ] `Plugin::onPlaybackStopped()` only scrobbles when:
      - plugin is `enabled` in config
      - `api_key`, `api_secret`, `session_key` are non-empty
      - track duration × `scrobble_threshold` ≤ actual play duration
- [ ] `config/lastfm.php` exists with all documented keys.
- [ ] `./vendor/bin/phpunit` — green; ≥ 12 new tests.
- [ ] Coverage of `LastfmApiClient` ≥ 85 %, `Plugin` ≥ 85 %.
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — zero new errors.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] `docs/plugins/developer-guide.md` updated with scrobbler type.
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
git checkout -b g.3-lastfm

# ─── 2. Do the work ───

# ─── 3. Verify ───
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'LastfmApiClient|Plugin'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync ───
git add -A

# ─── 5. Commit ───
git commit -m "Step G.3: Last.fm scrobble plugin"

# ─── 6. CRITICAL ───
unset GITHUB_TOKEN

# ─── 7. PR + merge ───
gh pr create \
  --title "Step G.3: Last.fm scrobble plugin" \
  --body  "Adds Last.fm scrobble plugin (Plugin, LastfmApiClient, ScrobbleData, NowPlayingData, config/lastfm.php). Subscribes to phlex.playback.stopped events. Part of Phase G (Step G.3 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch

# ─── 8. Return to master ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION ───
git status --short
git branch --show-current
git log --oneline -1
git branch --list 'g.3-*'
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs `g.3-lastfm-review.md`.

Non-obvious points:
- Last.fm scrobbling requires a session key obtained via mobile auth
  (username + password hash, not OAuth web flow). The session key is
  stored in `config/lastfm.php` after first successful auth.
- The scrobble threshold (`scrobble_threshold = 0.5`) means a track is
  only scrobbled if the user listened to at least 50% of it.
- `nowPlaying` is submitted on `playback.started` (if `submit_now_playing`
  is true) so the user's Last.fm profile shows what they're listening to
  in real-time, separate from the scrobble which fires on stop.
