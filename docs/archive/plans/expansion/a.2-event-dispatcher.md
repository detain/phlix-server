# Step A.2 — PSR-14 event dispatcher

**Phase:** A (Plugin Foundation & DI)
**Step:** A.2
**Depends on:** A.1
**Review:** Yes — see `a.2-event-dispatcher-review.md`
**Target repo:** detain/phlex (local: /home/sites/phlex)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Introduce a PSR-14 event dispatcher so the rest of the server can publish
named events (playback started/stopped, library scan completed, user
created, etc.) and so Phase A.4's plugin loader can subscribe plugins'
listeners with no further plumbing. Without this step, every plugin would
need its own ad-hoc hook mechanism. After this step lands, a plugin
declares `"events": ["phlex.playback.started"]` in its manifest and the
loader wires the listener to the central dispatcher.

This step also writes the canonical **event reference** doc — the single
catalog of every event the server can fire, its payload shape, and its
typical listener. Plugin authors will read this doc.

## 2. Context (what already exists)

- After A.1 lands: `src/Common/Container/ContainerFactory.php` and the
  four provider classes. A.2 adds a fifth provider for the dispatcher.
- `/home/sites/phlex/src/Common/Events/` exists but contains only
  `.gitkeep`. This is where event DTOs and the dispatcher factory live.
- `/home/sites/phlex/src/Session/PlaybackController.php` — playback
  lifecycle, the obvious first dispatch site for `PlaybackStarted` etc.
- `/home/sites/phlex/src/Media/Library/MediaScanner.php` — second
  dispatch site (`LibraryScanStarted`, `LibraryScanCompleted`,
  `MediaItemAdded`).
- `/home/sites/phlex/src/Auth/AuthManager.php` — third dispatch site
  (`UserCreated`, `UserLoggedIn`, `UserLoggedOut`).
- `/home/sites/phlex/composer.json` — needs new dependencies for PSR-14.

## 3. Scope — files to create / modify

### Create

- `src/Common/Events/EventDispatcherFactory.php` — builds a Tukio
  `Dispatcher` + `ProviderBuilder` and returns the
  `Psr\EventDispatcher\EventDispatcherInterface`.
- `src/Common/Events/ListenerRegistry.php` — thin facade over the Tukio
  provider that plugins talk to (so they don't pin to Tukio types).
  Methods: `subscribe(string $eventClass, callable $listener,
  ?int $priority = null)`, `unsubscribe(string $eventClass, callable
  $listener)`.
- `src/Common/Events/AbstractEvent.php` — readonly base class with
  `public readonly int $timestamp` (UNIX seconds) set in the constructor.
  All concrete events extend this.
- `src/Common/Events/Playback/PlaybackStarted.php` — readonly DTO with
  `string $sessionId, string $userId, string $mediaItemId, string
  $deviceId, int $positionTicks`.
- `src/Common/Events/Playback/PlaybackPaused.php`
- `src/Common/Events/Playback/PlaybackResumed.php`
- `src/Common/Events/Playback/PlaybackStopped.php` — adds `int
  $finalPositionTicks, bool $reachedEnd`.
- `src/Common/Events/Library/LibraryScanStarted.php` — `string
  $libraryId, string $libraryName, string $path`.
- `src/Common/Events/Library/LibraryScanCompleted.php` — `string
  $libraryId, int $itemsAdded, int $itemsUpdated, int $itemsRemoved, int
  $durationMs`.
- `src/Common/Events/Library/MediaItemAdded.php` — `string $mediaItemId,
  string $libraryId, string $path, string $type`.
- `src/Common/Events/Library/MediaItemUpdated.php` — `string $mediaItemId,
  array $changedFields`.
- `src/Common/Events/Library/MediaItemRemoved.php` — `string $mediaItemId,
  string $libraryId`.
- `src/Common/Events/Auth/UserCreated.php` — `string $userId, string
  $username, string $email`.
- `src/Common/Events/Auth/UserLoggedIn.php` — `string $userId, string
  $sessionId, string $ipAddress, string $userAgent`.
- `src/Common/Events/Auth/UserLoggedOut.php` — `string $userId, string
  $sessionId, string $reason` ("explicit" | "expired" | "revoked").
- `src/Common/Container/Providers/EventDispatcherProvider.php` — wires
  `EventDispatcherInterface`, `ListenerRegistry`, and (when set) a debug
  decorator that logs every dispatch via the `EVENTS` log channel.
- `src/Common/Logger/LogChannels.php` — **modify** to add `public const
  EVENTS = 'events';`.
- `tests/Unit/Common/Events/EventDispatcherFactoryTest.php`
- `tests/Unit/Common/Events/ListenerRegistryTest.php`
- `tests/Unit/Common/Events/Playback/PlaybackStartedTest.php` — DTO smoke.
- `tests/Unit/Common/Events/Library/LibraryScanCompletedTest.php` — DTO smoke.
- `tests/Integration/Events/DispatchSmokeTest.php` — wires a stub listener
  into the real container and asserts an event dispatched from
  `PlaybackController` reaches it.
- `docs/dev/event-reference.md` — canonical event catalog.

### Modify

- `composer.json` — add `crell/tukio: ^2.0` and
  `psr/event-dispatcher: ^1.0`.
- `composer.lock` — regenerate.
- `config/logger.php` — register the new `events` channel (rotating file
  `.logs/events.log`).
- `src/Session/PlaybackController.php` — accept
  `EventDispatcherInterface` in the constructor; dispatch
  `PlaybackStarted` / `PlaybackPaused` / `PlaybackResumed` /
  `PlaybackStopped` from the existing lifecycle methods. Container
  binding update is automatic via autowiring.
- `src/Media/Library/MediaScanner.php` — accept
  `EventDispatcherInterface`; dispatch the three library events from the
  scan flow.
- `src/Auth/AuthManager.php` — accept `EventDispatcherInterface`;
  dispatch the three auth events from `register()` / `login()` /
  `logout()`.
- `src/Common/Container/ContainerFactory.php` — register the new
  `EventDispatcherProvider`.
- `CHANGELOG.md` — `Added: PSR-14 event dispatcher (Tukio). Playback,
  library scan, and auth lifecycle events are now published; plugins can
  subscribe in Phase A.4.`
- `AGENTS.md` / `CLAUDE.md` — Caliber regenerates the architecture
  section.

### Delete

- `src/Common/Events/.gitkeep` — replaced by real files.

## 4. Approach

1. **Pick the dispatcher.** Use `crell/tukio:^2.0`.
   - Rationale: written by Larry Garfield (the PSR-14 spec author),
     stable since 2020, MIT, zero magic, designed for typed event
     classes. The `ProviderBuilder` API matches our "subscribe by event
     class FQCN" model perfectly. Alternatives (Symfony EventDispatcher
     with `symfony/event-dispatcher-contracts`) carry more weight and
     don't add value for our use case.
   - Requires PHP 8.1+, matches `composer.json`.
2. **Factory.** `EventDispatcherFactory::create(?Logger $debug = null):
   EventDispatcherInterface` builds a Tukio `ProviderBuilder`, builds a
   `Dispatcher`, optionally wraps it in a `DebugDispatcher` decorator
   that logs every event class + dispatch microtime to the `events`
   channel. Decorator is gated on env `PHLEX_DEBUG_EVENTS=1`.
3. **`ListenerRegistry`.** Owns the `OrderedProviderInterface` instance
   from Tukio. `subscribe()` calls
   `$provider->addListener($listener, $priority)`. Throws
   `\InvalidArgumentException` on duplicate
   `(eventClass, listener)` pairs. `unsubscribe()` finds the listener
   reference and removes it; idempotent on missing pairs (logs a
   warning, doesn't throw — plugins disabling cleanly is more important
   than strict bookkeeping).
4. **Event DTOs.** All extend `AbstractEvent` (readonly). Properties are
   `public readonly` typed. Constructor sets `$this->timestamp = time()`.
   No setters, no mutators — PSR-14 events are immutable by convention.
   Each event class has a class-level docblock describing **fired by**
   and **typical listener** — these strings are pulled into the
   `docs/dev/event-reference.md` table.
5. **Dispatch sites.** Surgical patches to `PlaybackController`,
   `MediaScanner`, `AuthManager`. Each gets one new constructor
   parameter; existing call sites already resolve via the container after
   A.1, so no other code changes.
6. **Naming.** PSR-14 dispatch is by class identity, not by string
   topic. The string topics in §5 of the master plan
   (`phlex.playback.started` etc.) are **manifest-side aliases** —
   Phase A.4's plugin loader maps them to FQCNs via a static table in
   `Phlex\Plugins\EventNameMap` (to be created in A.4, not here). A.2
   only ships the FQCN-based dispatch.
7. **PHPDoc.** Class docblocks include `@since 0.10.0`, `@package`,
   "Fired by", "Typical listener" sections. The event-reference doc
   generator (Phase N) will scrape these sections.

## 5. Tests (REQUIRED — §0.4 minimum bar)

Unit tests:

1. `EventDispatcherFactoryTest::test_create_returns_psr_dispatcher` —
   instance of `Psr\EventDispatcher\EventDispatcherInterface`.
2. `EventDispatcherFactoryTest::test_debug_decorator_logs_when_env_set` —
   set `PHLEX_DEBUG_EVENTS=1`, dispatch a fixture event, assert the
   logger received a record.
3. `EventDispatcherFactoryTest::test_no_debug_decorator_by_default` —
   logger receives nothing.
4. `ListenerRegistryTest::test_subscribe_then_dispatch_invokes_listener` —
   stub listener counts invocations.
5. `ListenerRegistryTest::test_priority_orders_listeners` — higher
   priority runs first.
6. `ListenerRegistryTest::test_unsubscribe_removes_listener` — after
   unsubscribe, listener is not invoked.
7. `ListenerRegistryTest::test_duplicate_subscribe_throws` —
   `\InvalidArgumentException`.
8. `PlaybackStartedTest::test_immutable_fields` — `readonly` properties
   reject reassignment (catch `Error`).
9. `LibraryScanCompletedTest::test_constructs_with_expected_payload` —
   roundtrip the typed payload.

Integration test:

10. `DispatchSmokeTest::test_playback_started_dispatch_reaches_listener` —
    build the real container, register a spy listener via
    `ListenerRegistry`, drive `PlaybackController::startPlayback()` with
    mocked DB, assert the spy fired once with the expected payload.

**Coverage target:** ≥ 85 % on `src/Common/Events/**`.

**Integration boundary:** event dispatch is in-process, no I/O. The smoke
test above satisfies the §0.4 integration requirement.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **"Anything"** → `README.md` adds a feature bullet `* PSR-14 event
  dispatcher (Tukio) — playback, library, auth events`.
- **"A configurable env var or `config/*.php` key"** → append
  `PHLEX_DEBUG_EVENTS` (`0` / `1`, default `0`) to
  `docs/reference/env-vars.md`.
- **Developer docs** → create `docs/dev/event-reference.md` with a table:
  | Event FQCN | Manifest alias | Payload fields | Fired by | Typical listener |
  |---|---|---|---|---|
  | `Phlex\Common\Events\Playback\PlaybackStarted` | `phlex.playback.started` | sessionId, userId, mediaItemId, deviceId, positionTicks | `Phlex\Session\PlaybackController::startPlayback()` | Scrobble plugins, analytics collector |
  | … one row per event class … |
  Plus a short "How to subscribe" code sample using `ListenerRegistry`.
- **CHANGELOG** → already in §3 Modify.

PHPDoc per §0.4 on every new public class/method, including the
"Fired by" / "Typical listener" sections on event DTOs.

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] All files listed in §3 "Create" exist.
- [ ] All files listed in §3 "Modify" updated as described.
- [ ] `composer.json` declares `crell/tukio:^2.0` and
      `psr/event-dispatcher:^1.0`.
- [ ] `src/Common/Events/.gitkeep` removed.
- [ ] `./vendor/bin/phpunit` — green, no skips.
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — zero new errors.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] `find src -name '*.php' -exec php -l {} \;` — no syntax errors.
- [ ] Coverage of `src/Common/Events/**` ≥ 85 %.
- [ ] PHPDoc on every new public class/method.
- [ ] `docs/dev/event-reference.md` lists every event class shipped in §3.
- [ ] `docs/reference/env-vars.md` documents `PHLEX_DEBUG_EVENTS`.
- [ ] CHANGELOG.md updated.
- [ ] Caliber pre-commit hook ran; regenerated agent files staged.
- [ ] Git ritual §8 below executed; postcondition checks PASS.

## 8. Git ritual (copy of master plan §11.4)

```bash
# ─── 0. PRECONDITION: confirm we're starting from clean master ───
cd /home/sites/phlex
git status --short                          # MUST be empty
git branch --show-current                   # MUST be 'master'
git pull --ff-only origin master

# ─── 1. Branch ───
git checkout -b a.2-event-dispatcher

# ─── 2. Do the work; add tests; update docs (§0.4); add PHPDocs ───

# ─── 3. Verify (§0.4 minimum bar) ───
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text | grep 'Common/Events'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync (hook active) ───
git add -A

# ─── 5. Commit — NEW commit, NEVER --amend ───
git commit -m "Step A.2: add PSR-14 event dispatcher (Tukio) and named events"

# ─── 6. CRITICAL: drop env-injected token before using gh ───
unset GITHUB_TOKEN

# ─── 7. PR, auto-merge, branch delete ───
gh pr create \
  --title "Step A.2: PSR-14 event dispatcher + named events" \
  --body  "Adds Crell\\Tukio as the PSR-14 implementation, twelve typed event DTOs (playback, library, auth), a ListenerRegistry facade, and dispatch sites in PlaybackController/MediaScanner/AuthManager. Implements step A.2 of PHLEX_EXPANSION_PLAN.md."
gh pr merge --squash --delete-branch

# ─── 8. Return to master with merged PR pulled — REQUIRED END STATE ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION assertions (subagent reports these) ───
git status --short                          # MUST be empty
git branch --show-current                   # MUST be 'master'
git log --oneline -1                        # MUST show the new squashed commit
git branch --list 'a.2-*'                   # MUST be empty
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs `a.2-event-dispatcher-review.md`. The doc
catalog (`docs/dev/event-reference.md`) must list every shipped event;
reviewer cross-checks against `src/Common/Events/**` with
`find src/Common/Events -name '*.php' -not -name 'AbstractEvent.php' -not -name 'EventDispatcherFactory.php' -not -name 'ListenerRegistry.php'`.
