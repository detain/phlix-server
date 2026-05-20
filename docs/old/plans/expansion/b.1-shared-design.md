# Step B.1 — Design `phlex-shared` package

**Phase:** B (Repo Split & Migration)
**Step:** B.1
**Depends on:** A.7
**Review:** No (per master plan §3)
**Target repo:** detain/phlex (local: /home/sites/phlex) — plans-only step
**Estimated subagent type:** feature-dev:code-architect (fallback: oac:coder-agent)

> **Note:** This file is the retrospective record of Step B.1. The work it
> describes — creating the Phase B per-step plan files (B.2 through B.7
> plus the six review templates and the three metadata-only steps) AND
> designing the contents of the `phlex-shared` Composer package that B.2
> will scaffold and B.3 will populate — was executed when this file (and
> its sixteen sibling files) were committed. A reviewer who wants to
> verify B.1 simply checks that the seventeen files listed in §3 exist
> on `master`.
>
> **B.1 ships zero `src/` changes.** No `Phlex\Plugins\Contract\LifecycleInterface`
> is moved, no `Phlex\Common\Events\*` is renamed. The MOVE happens in
> B.3, against this design. B.2 ships scaffolding only.

## 1. Goal

Pin down two things so the rest of Phase B is mechanical:

1. **The Phase B per-step plan files.** Phase A ended with A.7 landed but
   `plans/expansion/b.*.md` does not yet exist. The supervisor (which reads
   only `PHLEX_EXPANSION_PLAN.md` §3) cannot spawn B.2 without a plan file
   to point a subagent at. B.1 closes that gap, mirroring the role A.0
   played at the start of Phase A.
2. **The contents of `phlex-shared` v0.2.0.** The empty
   `detain/phlex-shared` repo already exists (created 2026-05-17). B.2
   scaffolds it with composer skeleton + CI + a single `Phlex\Shared\Version`
   marker class and tags v0.1.0. B.3 then **moves** real interfaces and
   DTOs out of `phlex` and into `phlex-shared`, releases v0.2.0, and points
   `phlex` at the new package via a Composer `require`. To make B.3
   mechanical the design — every namespace, every class, every deprecation
   shim — is locked here.

After B.1 lands, Phase B subagents only need to "follow the plan file".
Zero decisions are deferred to implementation.

## 2. Context (what already exists)

Read first, do not modify:

- `/home/sites/phlex/PHLEX_EXPANSION_PLAN.md` §0.2 (critical rules), §0.4
  (test + doc bar), §2 (repo inventory — `detain/phlex-shared` is
  pre-created empty), §3 Phase B rows (B.1–B.10), §4.1 / §4.2 / §4.3
  (what stays vs. moves), §11.4 (git ritual), §11.6 (Phase B is
  sequential), §14.3 (phlex-shared description + 19 topic tags).
- `/home/sites/phlex/SESSION_HANDOFF.md` — Phase A summary. Decisions
  #2 (EventNameMap maps aliases ↔ FQCNs), #5 (LifecycleInterface lives
  temporarily at `Phlex\Plugins\Contract\LifecycleInterface`; final home
  is `Phlex\Shared\Plugin\LifecycleInterface`), #1 (PHP-DI 7 container),
  #4 (PluginLoader API surface).
- `/home/sites/phlex/plans/expansion/a.0-bootstrap.md` — template for
  the **retrospective design-step plan file** this file follows.
- `/home/sites/phlex/plans/expansion/a.1-di-container.md`,
  `a.2-event-dispatcher.md`, `a.5-plugin-admin-ui.md`,
  `a.6-sample-plugin.md` — templates for **implementation step plan
  files**.
- `/home/sites/phlex/plans/expansion/a.1-di-container-review.md`,
  `a.2-event-dispatcher-review.md` — templates for **review plan
  files** (kept minimal).
- `/home/sites/phlex/src/Plugins/Contract/LifecycleInterface.php` —
  the interface that moves to `phlex-shared` in B.3. Note its docblock
  already declares "Temporary home — moves to
  `Phlex\Shared\Plugin\LifecycleInterface` in Step B.1."
- `/home/sites/phlex/src/Plugins/Manifest.php`,
  `/home/sites/phlex/src/Plugins/ManifestType.php`,
  `/home/sites/phlex/src/Plugins/ManifestValidationError.php` — the
  DTO-shaped pieces that go to shared; the JSON-Schema validator
  (`validate()` method on `Manifest` plus its `loadSchema`/`mapSchemaError`
  helpers) stays in `phlex-server` because it consumes a non-shared
  schema file under `docs/plugins/`.
- `/home/sites/phlex/src/Plugins/EventNameMap.php` — pure FQCN table,
  no runtime state, moves wholesale.
- `/home/sites/phlex/src/Common/Events/AbstractEvent.php` plus the
  twelve concrete event DTOs under `Playback/`, `Library/`, `Auth/` —
  all readonly, all immutable, no I/O, all move.
- `/home/sites/phlex/src/Common/Events/EventDispatcherFactory.php`,
  `/home/sites/phlex/src/Common/Events/ListenerRegistry.php`,
  `/home/sites/phlex/src/Common/Events/StructuredLoggerPsrAdapter.php`
  — Tukio-specific wiring; **stays in `phlex-server`** (depends on
  `crell/tukio`, which `phlex-shared` must not require).
- `/home/sites/phlex/src/Auth/JwtHandler.php` — current concrete
  encoder/decoder (HS256, `iss=phlex`, 1h access / 7d refresh). Stays
  in `phlex-server`. **B.1 designs a new value object
  `Phlex\Shared\Auth\JwtClaims` capturing the claim shape** so
  `phlex-hub` (Phase C.5) can mint tokens the server validates without
  importing the server's `JwtHandler`.
- `/home/sites/phlex/composer.json` — note the current require list.
  `phlex-shared`'s composer.json must NOT inherit `workerman/*`,
  `monolog/*`, `crell/tukio`, `smarty/*`, `php-di/*` —  only the
  framework-neutral PSRs.

## 3. Scope — files to create / modify

### Create

This step creates **seventeen** files under `/home/sites/phlex/plans/expansion/`:

| File | Type | Role |
|---|---|---|
| `b.1-shared-design.md` | Retrospective design-step plan | This file. |
| `b.2-shared-create.md` | Implementation | Clone empty `detain/phlex-shared`; scaffold composer package + namespace skeleton + CI; tag v0.1.0. Scaffolding only. |
| `b.2-shared-create-review.md` | Review template | Re-verify B.2 acceptance. |
| `b.2a-shared-metadata.md` | Implementation (no review) | Apply description + 19 topic tags from master plan §14.3 via `gh repo edit`. |
| `b.3-shared-consume.md` | Implementation | Move interfaces/DTOs into phlex-shared; bump to v0.2.0; refactor `phlex` to consume via composer; ship one-release deprecation aliases. |
| `b.3-shared-consume-review.md` | Review template | Re-verify B.3 acceptance. |
| `b.4-migrate-server.md` | Implementation | `git remote set-url origin → phlex-server`; push master + branches + tags; update README + docs URLs. |
| `b.4-migrate-server-review.md` | Review template | Re-verify B.4 acceptance. |
| `b.4a-server-metadata.md` | Implementation (no review) | Apply phlex-server description + 19 topics. |
| `b.4b-archive-old.md` | Implementation (no review, **irreversible**) | Replace README on old `detain/phlex` with redirect; `gh repo archive`. Requires user confirmation. |
| `b.5-hub-scaffold.md` | Implementation | Clone empty `detain/phlex-hub`; scaffold Workerman HTTP/WS skeleton with phlex-shared dep; CI. |
| `b.5-hub-scaffold-review.md` | Review template | Re-verify B.5 acceptance. |
| `b.5a-hub-metadata.md` | Implementation (no review) | Apply phlex-hub description + 19 topics. |
| `b.6-hub-schema.md` | Implementation | Write hub migrations 001–005 + `scripts/run-migrations.php`. |
| `b.6-hub-schema-review.md` | Review template | Re-verify B.6 acceptance. |
| `b.7-hub-portal-mvp.md` | Implementation | Signup/login + JWT endpoints + empty `/my-servers` dashboard + AdminMiddleware + AuditLogger ports. |
| `b.7-hub-portal-mvp-review.md` | Review template | Re-verify B.7 acceptance. |

(B.10 — optional local-dir rename — is intentionally **not** written here.
It's listed in master plan §3 with `Review = Yes` and marked optional. If
it's ever scheduled, its plan file gets written by whichever supervisor
schedules it.)

### Modify

- None. B.1 is plans-only.

### Delete

- None.

## 4. Approach — the design content

### 4.1 What moves to `phlex-shared` (the canonical table)

Every row below is a decision locked here so B.3 doesn't re-litigate.

| Source FQCN (today, in `phlex`) | Source file | Target FQCN (in `phlex-shared` v0.2.0) | Target file | Deprecation strategy | Test impact |
|---|---|---|---|---|---|
| `Phlex\Plugins\Contract\LifecycleInterface` | `src/Plugins/Contract/LifecycleInterface.php` | `Phlex\Shared\Plugin\LifecycleInterface` | `src/Plugin/LifecycleInterface.php` | Keep the old interface in `phlex` for ONE minor release as a tiny shim: `interface LifecycleInterface extends \Phlex\Shared\Plugin\LifecycleInterface {}` with `@deprecated since 0.11.0 — extend \Phlex\Shared\Plugin\LifecycleInterface instead. Will be removed in 0.12.0.` Rationale: `phlex-plugin-example` v0.1.0 implements the old interface; an `extends` bridge keeps `instanceof` checks transitive in both directions. `class_alias` does not work for interfaces; a sub-interface does. | `tests/unit/Plugins/PluginLoaderTest`, `tests/Fixtures/Plugins/fixture-plugin/Plugin.php`, every test that mocks LifecycleInterface — update `use` statement to `Phlex\Shared\Plugin\LifecycleInterface`. The deprecated shim keeps existing imports green. |
| `Phlex\Plugins\Manifest` (value-object surface only — public readonly props, `fromJson`, `fromArray`, `toArray`, `manifestType`) | `src/Plugins/Manifest.php` | `Phlex\Shared\Plugin\Manifest` | `src/Plugin/Manifest.php` | Split, do not bridge. The validator (`validate()`, `loadSchema()`, `mapSchemaError()`, `SCHEMA_RELATIVE_PATH`, `KNOWN_TOP_LEVEL_KEYS`, the `justinrainbow/json-schema` import) **stays** in `phlex` as a new class `Phlex\Plugins\Manifest\ManifestSchema::validate(Manifest $manifest): list<ManifestValidationError>`. Rationale: the JSON Schema lives at `docs/plugins/manifest.schema.json` inside `phlex` (per A.3 decision) and `justinrainbow/json-schema` is not framework-neutral enough for shared. Keep a `Phlex\Plugins\Manifest` class as a `@deprecated` thin wrapper that re-exports `Phlex\Shared\Plugin\Manifest` constructors via static factories AND exposes `->validate()` by delegating to the new `ManifestSchema`. | `tests/unit/Plugins/ManifestTest` splits into `tests/unit/Plugin/ManifestTest` (DTO behavior, in phlex-shared) + `tests/unit/Plugins/ManifestSchemaTest` (validation, in phlex). |
| `Phlex\Plugins\ManifestType` (enum) | `src/Plugins/ManifestType.php` | `Phlex\Shared\Plugin\ManifestType` | `src/Plugin/ManifestType.php` | Move wholesale. Add an `@deprecated` empty enum at the old path **NO** — backed enums can't be aliased; instead leave the old file deleted and require a one-line `use` swap in `phlex`. Plugins that referenced the old FQCN: only the `phlex-plugin-example` plugin used `ManifestType` in its own manifest validation — but plugins read the manifest as JSON, not as a PHP enum, so no plugin code actually depends on this FQCN. **No shim needed.** | `tests/unit/Plugins/ManifestTypeTest` → `tests/unit/Plugin/ManifestTypeTest`. |
| `Phlex\Plugins\ManifestValidationError` (DTO) | `src/Plugins/ManifestValidationError.php` | `Phlex\Shared\Plugin\ManifestValidationError` | `src/Plugin/ManifestValidationError.php` | Move wholesale. Same rationale as `ManifestType` — only `Manifest::validate()` produces these, and that lives in `phlex`'s `ManifestSchema` after the split. Internal type; no plugin author has referenced it. **No shim.** | `tests/unit/Plugins/ManifestValidationErrorTest` → `tests/unit/Plugin/ManifestValidationErrorTest`. |
| `Phlex\Plugins\EventNameMap` (pure FQCN table) | `src/Plugins/EventNameMap.php` | `Phlex\Shared\Plugin\EventNameMap` | `src/Plugin/EventNameMap.php` | Move wholesale. The class is `final` with private constructor and three static methods. Internal lookup, called from `PluginLoader` (lives in `phlex`). A deprecated `Phlex\Plugins\EventNameMap extends \Phlex\Shared\Plugin\EventNameMap` shim is NOT possible because the constructor is `private final` — instead leave a class_alias at the old path: `class_alias(\Phlex\Shared\Plugin\EventNameMap::class, \Phlex\Plugins\EventNameMap::class);` registered from `phlex/composer.json#autoload.files`. `@deprecated since 0.11.0` notice goes in `docs/plugins/developer-guide.md` since the class itself can no longer carry a docblock at the old path. | `tests/unit/Plugins/EventNameMapTest` → `tests/unit/Plugin/EventNameMapTest`. |
| `Phlex\Common\Events\AbstractEvent` (readonly base) | `src/Common/Events/AbstractEvent.php` | `Phlex\Shared\Events\AbstractEvent` | `src/Events/AbstractEvent.php` | All twelve concrete events extend this. Move the base first, then move children. Old path becomes a `class_alias(\Phlex\Shared\Events\AbstractEvent::class, \Phlex\Common\Events\AbstractEvent::class);` for one release. | All `tests/unit/Common/Events/*` adjust the `use` statement. The PSR-14 dispatcher (`EventDispatcherFactory`, `ListenerRegistry`) **continues to live at `Phlex\Common\Events\*` in `phlex`** — they're Tukio wiring, not DTOs. |
| `Phlex\Common\Events\Playback\PlaybackStarted` | `src/Common/Events/Playback/PlaybackStarted.php` | `Phlex\Shared\Events\Playback\PlaybackStarted` | `src/Events/Playback/PlaybackStarted.php` | `class_alias` shim at the old path for one release. | Update `EventNameMap`'s `use` (which itself moves), update dispatcher tests' `use`. |
| `Phlex\Common\Events\Playback\PlaybackPaused` | `src/Common/Events/Playback/PlaybackPaused.php` | `Phlex\Shared\Events\Playback\PlaybackPaused` | `src/Events/Playback/PlaybackPaused.php` | `class_alias`. | Same. |
| `Phlex\Common\Events\Playback\PlaybackResumed` | `src/Common/Events/Playback/PlaybackResumed.php` | `Phlex\Shared\Events\Playback\PlaybackResumed` | `src/Events/Playback/PlaybackResumed.php` | `class_alias`. | Same. |
| `Phlex\Common\Events\Playback\PlaybackStopped` | `src/Common/Events/Playback/PlaybackStopped.php` | `Phlex\Shared\Events\Playback\PlaybackStopped` | `src/Events/Playback/PlaybackStopped.php` | `class_alias`. | Same. |
| `Phlex\Common\Events\Library\LibraryScanStarted` | `src/Common/Events/Library/LibraryScanStarted.php` | `Phlex\Shared\Events\Library\LibraryScanStarted` | `src/Events/Library/LibraryScanStarted.php` | `class_alias`. | Same. |
| `Phlex\Common\Events\Library\LibraryScanCompleted` | `src/Common/Events/Library/LibraryScanCompleted.php` | `Phlex\Shared\Events\Library\LibraryScanCompleted` | `src/Events/Library/LibraryScanCompleted.php` | `class_alias`. | Same. |
| `Phlex\Common\Events\Library\MediaItemAdded` | `src/Common/Events/Library/MediaItemAdded.php` | `Phlex\Shared\Events\Library\MediaItemAdded` | `src/Events/Library/MediaItemAdded.php` | `class_alias`. | Same. |
| `Phlex\Common\Events\Library\MediaItemUpdated` | `src/Common/Events/Library/MediaItemUpdated.php` | `Phlex\Shared\Events\Library\MediaItemUpdated` | `src/Events/Library/MediaItemUpdated.php` | `class_alias`. | Same. |
| `Phlex\Common\Events\Library\MediaItemRemoved` | `src/Common/Events/Library/MediaItemRemoved.php` | `Phlex\Shared\Events\Library\MediaItemRemoved` | `src/Events/Library/MediaItemRemoved.php` | `class_alias`. | Same. |
| `Phlex\Common\Events\Auth\UserCreated` | `src/Common/Events/Auth/UserCreated.php` | `Phlex\Shared\Events\Auth\UserCreated` | `src/Events/Auth/UserCreated.php` | `class_alias`. | Same. |
| `Phlex\Common\Events\Auth\UserLoggedIn` | `src/Common/Events/Auth/UserLoggedIn.php` | `Phlex\Shared\Events\Auth\UserLoggedIn` | `src/Events/Auth/UserLoggedIn.php` | `class_alias`. | Same. |
| `Phlex\Common\Events\Auth\UserLoggedOut` | `src/Common/Events/Auth/UserLoggedOut.php` | `Phlex\Shared\Events\Auth\UserLoggedOut` | `src/Events/Auth/UserLoggedOut.php` | `class_alias`. | Same. |
| **NEW — does not exist in `phlex` today** | — | `Phlex\Shared\Auth\JwtClaims` | `src/Auth/JwtClaims.php` | New class, no shim. Value object capturing the **shape** of the access/refresh token payload `JwtHandler` emits today. Phase C.5 (delegated auth) consumes this so the hub can mint tokens; this lets the server-side `JwtHandler` validate hub-minted tokens by deserializing into the same shape without two divergent payload definitions. **See §4.4 for the exact field list.** | New: `tests/unit/Auth/JwtClaimsTest` in `phlex-shared`. Server-side `JwtHandlerTest` is **not** touched in B.3 — `JwtHandler` keeps its current array-based payload internally; adopting `JwtClaims` is Phase C.5's job. |
| **NEW — Phase C placeholders, ship as empty interfaces/abstracts** | — | `Phlex\Shared\Hub\ClaimRequest` (final readonly DTO) | `src/Hub/ClaimRequest.php` | New, no shim. Shape locked in §4.5. | Phase C.1 wires it; B.3 ships the empty DTO with no real consumer. Smoke test asserts the readonly props compile and the constructor accepts the typed payload. |
| **NEW — Phase C placeholders** | — | `Phlex\Shared\Hub\ClaimResponse` (final readonly DTO) | `src/Hub/ClaimResponse.php` | New, no shim. Shape in §4.5. | Same. |
| **NEW — Phase C placeholders** | — | `Phlex\Shared\Hub\ServerInfoDto` (final readonly DTO) | `src/Hub/ServerInfoDto.php` | New, no shim. Shape in §4.5. | Same. |
| **NEW — Phase C placeholders** | — | `Phlex\Shared\Hub\HeartbeatDto` (final readonly DTO) | `src/Hub/HeartbeatDto.php` | New, no shim. Shape in §4.5. | Same. |
| **RESERVED — Phase K.1** | — | `Phlex\Shared\Arr\*` (Sonarr / Radarr / Bazarr / Prowlarr typed clients) | `src/Arr/.gitkeep` | NOT shipped in v0.2.0. Reserve the namespace + directory with a `.gitkeep` so K.1's first commit lands cleanly. | None in v0.2.0. |

**Stays in `phlex`** (NOT moved by B.3):

- `Phlex\Common\Events\EventDispatcherFactory` — Tukio wiring, depends on `crell/tukio`.
- `Phlex\Common\Events\ListenerRegistry` — Tukio `OrderedProviderInterface`.
- `Phlex\Common\Events\StructuredLoggerPsrAdapter` — Monolog adapter.
- `Phlex\Plugins\PluginLoader` — depends on `Phlex\Common\Container` (PHP-DI), `AuditLogger`, `LoggerFactory`, file I/O, `PharData`.
- `Phlex\Plugins\Installer\*`, `Phlex\Plugins\Repository\*`, `Phlex\Plugins\Signature\*`, `Phlex\Plugins\Util\*`, `Phlex\Plugins\Exception\*`, `Phlex\Plugins\SettingsMasker`, `Phlex\Plugins\InstalledPlugin` — host-side runtime.
- `Phlex\Plugins\Manifest\ManifestSchema` (new in B.3) — JSON-Schema validator, depends on `justinrainbow/json-schema` and the bundled schema file under `docs/plugins/`.
- `Phlex\Auth\JwtHandler`, `JwtClaimsExtractor` (if it exists), `AuthManager`, `UserRepository`, `UserProfileManager`, `WatchHistory` — server-side concrete auth runtime.
- Everything under `Phlex\Common\Database`, `Phlex\Common\Logger`, `Phlex\Server`, `Phlex\Media`, `Phlex\Session`, `Phlex\LiveTv`, `Phlex\Dlna` — runtime, host-specific.

### 4.2 `phlex-shared` package layout (what B.2 scaffolds + what B.3 fills in)

**`composer.json`** (final shape after B.3 — B.2 ships a strict subset, see §4.6):

```json
{
    "name": "detain/phlex-shared",
    "description": "Shared interfaces, DTOs, event names, and protocol types used by both phlex-server and phlex-hub. Composer-installable, PHP 8.3+, zero I/O.",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": "^8.3",
        "psr/container": "^2.0",
        "psr/event-dispatcher": "^1.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.0",
        "phpstan/phpstan": "^2.0",
        "squizlabs/php_codesniffer": "^3.10",
        "vimeo/psalm": "^5.0"
    },
    "autoload": {
        "psr-4": {
            "Phlex\\Shared\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Phlex\\Shared\\Tests\\": "tests/"
        }
    },
    "minimum-stability": "stable",
    "config": {
        "optimize-autoloader": true,
        "sort-packages": true
    },
    "scripts": {
        "test": "phpunit",
        "stan": "phpstan analyze --no-progress",
        "cs": "phpcs --standard=PSR12 src/",
        "psalm": "psalm --no-progress"
    }
}
```

**Notes on the composer.json:**

- `"php": "^8.3"` — matches the master plan and avoids the `>=8.1` legacy
  constraint in `phlex`'s composer.json. `phlex-shared` is greenfield and
  picks the modern floor.
- **NO** `workerman/*` — shared must be Workerman-agnostic so `phlex-hub`
  can ship a non-Workerman variant in the future if needed.
- **NO** `crell/tukio`, **NO** `php-di/php-di` — those are concrete PSR
  implementations; shared declares only the PSR interfaces. Hosts pick
  the implementation.
- **NO** `monolog/monolog` — logging is host-side.
- **NO** `justinrainbow/json-schema` — manifest validation stays host-side
  per §4.1.
- `vimeo/psalm` in dev deps — matches `phlex`'s CI for cross-tool parity.

**`src/` layout** (final, after B.3):

```
src/
├── Version.php                       # shipped in B.2 v0.1.0 as the only file
├── Auth/
│   └── JwtClaims.php                 # B.3 adds; consumed by Phase C.5
├── Events/
│   ├── AbstractEvent.php
│   ├── Auth/
│   │   ├── UserCreated.php
│   │   ├── UserLoggedIn.php
│   │   └── UserLoggedOut.php
│   ├── Library/
│   │   ├── LibraryScanCompleted.php
│   │   ├── LibraryScanStarted.php
│   │   ├── MediaItemAdded.php
│   │   ├── MediaItemRemoved.php
│   │   └── MediaItemUpdated.php
│   └── Playback/
│       ├── PlaybackPaused.php
│       ├── PlaybackResumed.php
│       ├── PlaybackStarted.php
│       └── PlaybackStopped.php
├── Plugin/
│   ├── EventNameMap.php
│   ├── LifecycleInterface.php
│   ├── Manifest.php
│   ├── ManifestType.php
│   └── ManifestValidationError.php
├── Hub/
│   ├── ClaimRequest.php              # B.3 ships empty DTO; Phase C.1+ wires
│   ├── ClaimResponse.php             # B.3 ships empty DTO; Phase C.1+ wires
│   ├── HeartbeatDto.php              # B.3 ships empty DTO; Phase C.2 wires
│   └── ServerInfoDto.php             # B.3 ships empty DTO; Phase C.3 wires
└── Arr/
    └── .gitkeep                      # reserved for Phase K.1
```

**`tests/` layout** (mirrors `src/`):

```
tests/
├── VersionTest.php                   # shipped in B.2 v0.1.0
├── Auth/
│   └── JwtClaimsTest.php             # B.3
├── Events/
│   ├── AbstractEventTest.php
│   ├── Auth/
│   │   └── UserCreatedTest.php       # one smoke; others mirror in B.3
│   ├── Library/
│   │   └── LibraryScanCompletedTest.php
│   └── Playback/
│       └── PlaybackStartedTest.php
├── Plugin/
│   ├── EventNameMapTest.php
│   ├── LifecycleInterfaceTest.php    # contract check (interface method signatures)
│   ├── ManifestTest.php              # DTO behavior — fromJson / fromArray / toArray / manifestType
│   ├── ManifestTypeTest.php
│   └── ManifestValidationErrorTest.php
└── Hub/
    ├── ClaimRequestTest.php
    ├── ClaimResponseTest.php
    ├── HeartbeatDtoTest.php
    └── ServerInfoDtoTest.php
```

### 4.3 CI on the `phlex-shared` repo

Same 5-check matrix as `phlex` (per SESSION_HANDOFF.md decision #9):

| Job | Tool | Command | Status if green |
|---|---|---|---|
| Composer Validation | `composer` | `composer validate --strict` | clean |
| PHP CodeSniffer | `phpcs` | `phpcs --standard=PSR12 src/` | 0 errors |
| PHPStan | `phpstan` 2.x | `phpstan analyze --no-progress` at level 9 | `[OK] No errors` |
| Psalm | `vimeo/psalm` v5 | `psalm --no-progress` | clean |
| Security Audit | `composer audit` | `composer audit --no-dev` | no advisories |

PHPUnit is **not** a CI job in B.2 v0.1.0 because the only class is
`Phlex\Shared\Version` and the unit test trivially passes — but the
workflow file already wires it as a job so B.3 doesn't need to touch CI.

Workflow file: `.github/workflows/ci.yml`. Triggers: `push` to master and
`pull_request`. PHP 8.3 matrix only (no 8.4 yet — keeps the matrix tight).

### 4.4 `Phlex\Shared\Auth\JwtClaims` — exact shape

The value object Phase C.5 consumes. Designed so `JwtHandler` (server-side)
and the hub's token minter both produce the same payload structure.

```php
<?php

declare(strict_types=1);

namespace Phlex\Shared\Auth;

/**
 * Immutable claim shape for Phlex JWTs (access and refresh).
 *
 * Captures the payload `Phlex\Auth\JwtHandler::createAccessToken()` and
 * `createRefreshToken()` produce today, plus the additional `aud` and
 * `scope` fields the hub will emit starting in Phase C.5.
 *
 * Phase C.5 wires `JwtHandler::validateToken()` to deserialize the
 * decoded payload into this DTO so server and hub share one definition
 * of "what's in a Phlex JWT".
 *
 * @package Phlex\Shared\Auth
 * @since 0.2.0
 */
final class JwtClaims
{
    public const ISS_PHLEX = 'phlex';
    public const AUD_SERVER = 'server';
    public const AUD_HUB    = 'hub';
    public const AUD_CLIENT = 'client';
    public const TYPE_ACCESS  = 'access';
    public const TYPE_REFRESH = 'refresh';

    /**
     * @param string         $iss   Issuer. `phlex` for server-minted, `phlex-hub` for hub-minted.
     * @param string         $aud   Audience. One of self::AUD_*.
     * @param string         $sub   Subject — user UUID.
     * @param int            $iat   Issued-at, UNIX seconds.
     * @param int            $exp   Expires-at, UNIX seconds.
     * @param int|null       $nbf   Not-before, UNIX seconds. Null when unset.
     * @param string         $type  Token kind. One of self::TYPE_*.
     * @param string|null    $jti   Refresh-only token identifier. Null on access tokens.
     * @param list<string>   $scope Permissions list (e.g. `["library:read","playback:write"]`). Empty when unscoped.
     * @param string|null    $serverId Optional server UUID for hub-minted client tokens. Null on server-minted.
     */
    public function __construct(
        public readonly string $iss,
        public readonly string $aud,
        public readonly string $sub,
        public readonly int $iat,
        public readonly int $exp,
        public readonly ?int $nbf,
        public readonly string $type,
        public readonly ?string $jti,
        public readonly array $scope,
        public readonly ?string $serverId,
    ) {
    }

    /** Build from the array shape `JwtHandler::validateToken()` returns today. */
    public static function fromPayload(array $payload): self { /* ... */ }

    /** Serialize to the array shape `JwtHandler::encode()` expects today. */
    public function toPayload(): array { /* ... */ }

    public function isExpired(?int $now = null): bool { /* ... */ }
    public function hasScope(string $scope): bool { /* ... */ }
}
```

Field rationale:

- `iss` / `aud` / `sub` / `iat` / `exp` / `nbf` — RFC 7519 standard.
- `type` — already present in today's `JwtHandler` payload.
- `jti` — already present on refresh tokens. Optional on access.
- `scope` — NEW. Reserved for Phase C.5; today's tokens have no
  per-permission scope, but the field exists so the DTO doesn't grow
  shape in C.5 (cheaper to ship the empty array now than to
  re-deprecate a missing field).
- `serverId` — NEW. Reserved for Phase C.5: when the hub mints a token
  for a client to use against a specific user's server, it pins the
  audience to that `serverId`. The server checks
  `$claims->serverId === $this->ownServerId` during validation.

`fromPayload()` is tolerant: missing `nbf` → null, missing `jti` → null,
missing `scope` → empty array, missing `serverId` → null. Required
fields (`iss`, `aud`, `sub`, `iat`, `exp`, `type`) throw
`\InvalidArgumentException` when absent or wrong type.

### 4.5 Hub DTO placeholder shapes — locked here, implemented in Phase C

These ship in `phlex-shared` v0.2.0 as **fully-formed readonly DTOs with
defined fields**. No consumer yet — Phase C.1 (hub registry endpoints)
and C.2 (server's HubClient) wire them. By locking the shapes now, B.3
ships them empty-but-typed and Phase C subagents pick them up without
having to invent the protocol on the fly.

#### `Phlex\Shared\Hub\ClaimRequest`

Server → Hub at the start of the claim flow. Master plan §6 step 2.

```php
final class ClaimRequest
{
    /**
     * @param string             $serverName         Operator-chosen friendly name (e.g. "Alice's NAS").
     * @param string             $version            Server semver.
     * @param array<string,mixed> $publicKeysJwk     JWKS the server publishes for hub-minted token validation.
     * @param list<string>       $hostnameCandidates Hostnames/IPs the server thinks it's reachable at (for relay-or-direct decisions).
     * @param string             $protocolVersion    Spec version — start at "v1"; check via Accept-Phlex-Protocol header.
     */
    public function __construct(
        public readonly string $serverName,
        public readonly string $version,
        public readonly array $publicKeysJwk,
        public readonly array $hostnameCandidates,
        public readonly string $protocolVersion,
    ) {}

    public static function fromPayload(array $payload): self { /* validates required fields */ }
    public function toPayload(): array { /* JSON-serializable map */ }
}
```

#### `Phlex\Shared\Hub\ClaimResponse`

Hub → Server response to `ClaimRequest`. Master plan §6 step 2.

```php
final class ClaimResponse
{
    /**
     * @param string $claimCode   Human-friendly code like "ABCD-1234" the operator pastes on the hub portal.
     * @param int    $expiresIn   Seconds the claim code is valid (master plan says 600).
     * @param string $claimId     UUID — opaque token the server stores so it can poll claim status.
     * @param string $hubBaseUrl  Where the server should send heartbeats once enrolled.
     */
    public function __construct(
        public readonly string $claimCode,
        public readonly int $expiresIn,
        public readonly string $claimId,
        public readonly string $hubBaseUrl,
    ) {}

    public static function fromPayload(array $payload): self { /* ... */ }
    public function toPayload(): array { /* ... */ }
}
```

#### `Phlex\Shared\Hub\ServerInfoDto`

Hub-side projection of an enrolled server, returned from
`GET /api/v1/users/{id}/servers` (Phase C.4 dashboard).

```php
final class ServerInfoDto
{
    /**
     * @param string         $serverId      UUID minted by the hub on successful claim.
     * @param string         $userId        Owner UUID.
     * @param string         $serverName    From the original ClaimRequest.
     * @param string         $version       Server semver, refreshed on heartbeat.
     * @param int|null       $lastSeenAt    UNIX seconds. Null when never reached out.
     * @param string         $status        One of "online" | "offline" | "claiming" | "disabled".
     * @param list<string>   $hostnameCandidates Last known reachable hostnames.
     * @param bool           $relayActive   Whether a WSS reverse tunnel is currently open (Phase C.6).
     */
    public function __construct(
        public readonly string $serverId,
        public readonly string $userId,
        public readonly string $serverName,
        public readonly string $version,
        public readonly ?int $lastSeenAt,
        public readonly string $status,
        public readonly array $hostnameCandidates,
        public readonly bool $relayActive,
    ) {}

    public static function fromPayload(array $payload): self { /* ... */ }
    public function toPayload(): array { /* ... */ }
}
```

#### `Phlex\Shared\Hub\HeartbeatDto`

Server → Hub every ~60s once enrolled. Master plan §6 step 5.

```php
final class HeartbeatDto
{
    /**
     * @param string         $serverId         Server UUID minted by the hub.
     * @param string         $version          Current server semver.
     * @param int            $timestamp        UNIX seconds at heartbeat send time.
     * @param int            $uptimeSeconds    How long the server process has been running.
     * @param int            $activeSessions   Concurrent playback session count.
     * @param int            $activeTranscodes Concurrent transcode count.
     * @param list<string>   $hostnameCandidates Reachable hostnames discovered since last heartbeat (UPnP/manual config).
     */
    public function __construct(
        public readonly string $serverId,
        public readonly string $version,
        public readonly int $timestamp,
        public readonly int $uptimeSeconds,
        public readonly int $activeSessions,
        public readonly int $activeTranscodes,
        public readonly array $hostnameCandidates,
    ) {}

    public static function fromPayload(array $payload): self { /* ... */ }
    public function toPayload(): array { /* ... */ }
}
```

**B.3 ships these four DTOs with the fields above, full PHPDoc, full
`fromPayload`/`toPayload` round-trip, and one smoke test per class.** No
consumer in `phlex-server` references them after B.3 — they wait for
Phase C.

### 4.6 What B.2 ships (v0.1.0) vs. what B.3 adds (v0.2.0)

B.2 deliberately ships only **scaffolding** so the actual code-move
mistakes in B.3 don't get tangled with packaging mistakes:

**B.2 v0.1.0:**

- `composer.json` — same shape as §4.2 but with only the runtime `php`
  + `psr/container` + `psr/event-dispatcher` requires; dev deps already
  in.
- `src/Version.php` — single class:
  ```php
  namespace Phlex\Shared;
  final class Version
  {
      public const VERSION = '0.1.0';
      private function __construct() {}
  }
  ```
- `tests/VersionTest.php` — asserts `Version::VERSION` is a valid semver
  string and matches `composer.json`'s tag.
- `phpunit.xml`, `phpstan.neon.dist`, `phpcs.xml.dist`, `psalm.xml`,
  `.gitignore`, `.editorconfig`, `LICENSE` (MIT), `README.md`, `AGENTS.md`
  stub, `CHANGELOG.md` ("Initial release"), `.github/workflows/ci.yml`.
- One git tag: `v0.1.0`, pushed to `detain/phlex-shared`.
- **No** real interfaces or DTOs.

**B.3 v0.2.0:**

- All twenty-three classes listed in §4.1 row 1–22 (plus the `Arr/.gitkeep`).
- All tests listed in §4.2.
- `Version::VERSION` bumped to `'0.2.0'`.
- `CHANGELOG.md` line: `0.2.0 — Add Plugin, Events, Auth, Hub
  namespaces. Moved from phlex-server.`
- Tagged `v0.2.0`.
- Inside `phlex` (the consumer):
  - `composer require detain/phlex-shared:^0.2` — added via a VCS
    repository entry in `phlex/composer.json` until phlex-shared
    publishes to Packagist (which is a separate, post-v1.0 task).
  - All `Phlex\Common\Events\*` event DTOs and `Phlex\Plugins\EventNameMap`
    deleted from `src/`; references switched to the new FQCNs via `use`.
  - `Phlex\Plugins\Contract\LifecycleInterface` becomes a 3-line shim
    that extends `Phlex\Shared\Plugin\LifecycleInterface` for one
    release.
  - `Phlex\Plugins\Manifest` becomes a `@deprecated` wrapper around
    `Phlex\Shared\Plugin\Manifest`; the validator extracted to
    `Phlex\Plugins\Manifest\ManifestSchema`.
  - `composer.json#autoload.files` registers a 3-line alias file
    that issues `class_alias` calls for the moved DTOs.
  - All tests still green.

### 4.7 Composer VCS-repository snippet for consumers

Until `detain/phlex-shared` publishes to Packagist (post-v1.0), both
`phlex` (in B.3) and `phlex-hub` (in B.5) consume it via a Composer
`repositories` entry. The exact snippet is repeated in B.3 and B.5
plan files; locked here so they match:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "git@github.com:detain/phlex-shared.git"
        }
    ],
    "require": {
        "detain/phlex-shared": "^0.2"
    }
}
```

Packagist publish is a future task (likely Phase O); calling it out
in B.1 keeps Phase B sequential and avoids dragging Packagist OAuth
setup into the middle of a repo split.

### 4.8 Documentation impact (what B.3 must touch)

For B.3 to be self-consistent, the doc updates are:

- `docs/dev/event-reference.md` — every FQCN column changes from
  `Phlex\Common\Events\…` to `Phlex\Shared\Events\…`. Manifest alias
  column unchanged (aliases are stable contracts).
- `docs/plugins/developer-guide.md` — every `use
  Phlex\Plugins\Contract\LifecycleInterface;` example becomes
  `use Phlex\Shared\Plugin\LifecycleInterface;`. Add a "Migrating from
  0.10.x" section that documents the one-release deprecation alias.
- `docs/plugins/manifest.md` — Phase A authored this against
  `Phlex\Plugins\ManifestType`. Update the FQCN references.
- `docs/dev/plugin-sdk.md` — internal map of `src/Plugins/**` — the
  five moved files disappear from the table; the new
  `ManifestSchema.php` appears.
- `docs/dev/architecture-server.md` — add a short "Dependencies →
  phlex-shared" subsection pointing readers at the new package.
- `CHANGELOG.md` — line: `0.11.0 — Refactored to depend on
  detain/phlex-shared. LifecycleInterface, manifest DTOs, event DTOs,
  and EventNameMap now live in the shared package. Old FQCNs kept as
  deprecated aliases through 0.11.x; removed in 0.12.0.`
- `README.md` — Status feature list adds: `* Shared interfaces /
  DTOs in detain/phlex-shared` (one bullet).

## 5. Tests (REQUIRED — §0.4 minimum bar)

B.1 introduces no executable code, so no new PHPUnit tests are added.
However, the full verification bar still runs (per a.0-bootstrap.md
template):

- `./vendor/bin/phpunit` — must remain green (no regressions; the
  pre-existing 667 tests / 1623 assertions must pass).
- `./vendor/bin/phpstan analyze src/ --level=9` — must report
  `[OK] No errors`.
- `./vendor/bin/phpcs --standard=PSR12 src/` — must remain clean.
- `find src -name '*.php' -exec php -l {} \;` — no syntax errors.

Coverage check is skipped — B.1 adds no classes to cover.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Per §0.4, the matrix rows that apply to B.1:

- **"Anything"** row — update the repo `README.md` "Status" / feature
  list — N/A, B.1 is invisible to end users (plans-only).
- **"User-visible behavior change"** row — not applicable; add **no**
  `CHANGELOG.md` line for B.1 since the change is purely scaffolding
  for the next seven steps. B.3 is the first step that adds a
  user-visible entry.

PHPDoc requirements do not apply — no PHP files are added or modified.

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] All seventeen files listed in §3 exist with non-trivial content
      (≥ 80 lines for implementation step files, ≥ 50 lines for review
      templates, ≥ 30 lines for metadata-only step files).
- [ ] Each implementation step file embeds the §11.4 git ritual
      verbatim with the step's slug and target repo substituted.
- [ ] Each step file with `Review = Yes` in master plan §3 references
      its review template by name in §9 "Reviewer hand-off".
- [ ] B.2, B.4, B.5 step files contain the explicit instruction
      "do NOT run `gh repo create` — the repo already exists empty".
- [ ] B.4b's plan loudly flags `gh repo archive` as **irreversible**
      and requires explicit user confirmation gathered by the supervisor.
- [ ] B.6 and B.7 step files reference the Composer VCS repository
      snippet from §4.7 (or `composer require detain/phlex-shared`).
- [ ] `b.1-shared-design.md` (this file) contains the WHAT-MOVES-WHERE
      table from §4.1 with one row per moved/created class.
- [ ] `./vendor/bin/phpunit` — green, no skips.
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — `[OK] No errors`.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] `find src -name '*.php' -exec php -l {} \;` — no syntax errors.
- [ ] Caliber pre-commit hook is verified active before commit; the
      hook synced agent configs during the commit.
- [ ] Git ritual §8 below executed; postcondition checks all PASS.

## 8. Git ritual (copy of master plan §11.4)

```bash
# ─── 0. PRECONDITION: confirm we're starting from clean master ───
cd /home/sites/phlex
git status --short                          # MUST be empty (caliber-staged diffs OK if hook is staged)
git branch --show-current                   # MUST be 'master'
git pull --ff-only origin master

# ─── 1. Branch ───
git checkout -b b.1-shared-design

# ─── 2. Do the work; add tests; update docs (§0.4); add PHPDocs ───
# (write the sixteen plans/expansion/b.*.md files)

# ─── 3. Verify (§0.4 minimum bar) ───
./vendor/bin/phpunit                                   # green, no skips
./vendor/bin/phpstan analyze src/ --level=9            # [OK] No errors
./vendor/bin/phpcs --standard=PSR12 src/               # clean
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync (hook active — runs on commit) ───
git add -A

# ─── 5. Commit — NEW commit, NEVER --amend ───
git commit -m "Step B.1: design phlex-shared package + Phase B step plan files"

# ─── 6. CRITICAL: drop env-injected token before using gh ───
unset GITHUB_TOKEN

# ─── 7. PR, auto-merge, branch delete ───
gh pr create \
  --title "Step B.1: design phlex-shared package + Phase B step plan files" \
  --body  "Creates plans/expansion/b.{1..7}*.md (17 files) — the design for the phlex-shared Composer package plus the per-step plan files Phase B subagents will read. Plans-only step; no src/ changes. Implements step B.1 of PHLEX_EXPANSION_PLAN.md."
gh pr merge --squash --delete-branch

# ─── 8. Return to master with merged PR pulled — REQUIRED END STATE ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION assertions (subagent reports these) ───
git status --short                          # MUST be empty
git branch --show-current                   # MUST be 'master'
git log --oneline -1                        # MUST show the new squashed commit
git branch --list 'b.1-*'                   # MUST be empty (branch was deleted)
```

## 9. Reviewer hand-off

Review = No in §3 of the master plan. There is no review template
paired with B.1. The reviewer for B.2 is the first to read this
directory and implicitly confirms B.1 by being able to read
`b.2-shared-create.md`.
