# Step B.3 — Refactor `phlex` to depend on `phlex-shared`

**Phase:** B (Repo Split & Migration)
**Step:** B.3
**Depends on:** B.2 (and implicitly B.2a — but the topic tags don't gate
the Composer consume)
**Review:** Yes — see `b.3-shared-consume-review.md`
**Target repo:** **TWO repos in one PR-pair:**
- `detain/phlex-shared` (local: `/home/sites/phlex-shared/`) — bump
  to v0.2.0 with the moved interfaces/DTOs.
- `detain/phlex` (local: `/home/sites/phlex`) — add the Composer
  require, switch internal imports, ship the one-release deprecation
  shims.

The two repos are touched sequentially: first `phlex-shared` is
updated and tagged v0.2.0, **then** `phlex` is refactored to consume
the new tag. There is one PR against each repo.

**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

> **Largest step in Phase B.** Touches ~30 source files and ~30 test
> files across two repos. Follow the design in
> `b.1-shared-design.md` §4.1 verbatim — every move and every shim is
> pre-decided. If you find an ambiguity, stop and report; do not
> improvise.

## 1. Goal

Move the framework-neutral pieces of `phlex` (interfaces, DTOs, event
classes, the JWT claim shape, the hub protocol DTO placeholders) into
`phlex-shared` and have `phlex` consume them via Composer — without
breaking any of the 667 existing tests.

Concretely: after B.3 lands,

1. `phlex-shared` v0.2.0 ships:
   - `Phlex\Shared\Plugin\LifecycleInterface` (moved)
   - `Phlex\Shared\Plugin\Manifest` + `ManifestType` + `ManifestValidationError` + `EventNameMap` (moved)
   - `Phlex\Shared\Events\AbstractEvent` + 12 concrete events (moved)
   - `Phlex\Shared\Auth\JwtClaims` (new)
   - `Phlex\Shared\Hub\{ClaimRequest, ClaimResponse, ServerInfoDto, HeartbeatDto}` (new)
   - `Phlex\Shared\Arr\.gitkeep` (reserved for K.1)
   - Tests for all of the above.
2. `phlex` has:
   - A Composer `require` on `detain/phlex-shared:^0.2` (via VCS
     repository).
   - Internal `use` statements updated to the new `Phlex\Shared\*` FQCNs.
   - One-release deprecation shims so out-of-tree consumers (the
     existing `phlex-plugin-example`) keep working:
     - `Phlex\Plugins\Contract\LifecycleInterface` becomes
       `interface LifecycleInterface extends \Phlex\Shared\Plugin\LifecycleInterface {}`
       with a `@deprecated` docblock.
     - `Phlex\Plugins\EventNameMap`, `ManifestType`, `ManifestValidationError`
       are registered as `class_alias` entries in
       `composer.json#autoload.files`.
     - `Phlex\Plugins\Manifest` becomes a `@deprecated` wrapper that
       extends `Phlex\Shared\Plugin\Manifest` and adds back the
       `validate()` method (now delegating to
       `Phlex\Plugins\Manifest\ManifestSchema`).
     - All twelve `Phlex\Common\Events\*` event DTOs are registered as
       `class_alias` entries in the same `autoload.files` shim.
   - `Phlex\Plugins\Manifest\ManifestSchema` — a new class extracted
     from the old `Manifest::validate()` body, owning the JSON-Schema
     coupling.
3. All tests still green (`./vendor/bin/phpunit` reports 667+
   passing).

## 2. Context (what already exists)

- After B.2: `detain/phlex-shared` master = v0.1.0 scaffolding,
  `src/Version.php` is the only class.
- `plans/expansion/b.1-shared-design.md` — **the** reference. §4.1 is
  the move table, §4.4 spells out `JwtClaims`, §4.5 spells out the
  four hub DTOs, §4.7 has the VCS-repository composer snippet, §4.8
  enumerates the doc updates.
- `detain/phlex-plugin-example` v0.1.0 (live on GitHub) implements
  `Phlex\Plugins\Contract\LifecycleInterface`. The deprecation shim
  must keep it working — verified by the existing
  `tests/Integration/Plugins/SamplePluginSmokeTest.php`.

## 3. Scope — files to create / modify

### Inside `detain/phlex-shared` (v0.2.0)

#### Create

- `src/Plugin/LifecycleInterface.php` — moved from
  `phlex/src/Plugins/Contract/LifecycleInterface.php`. Same body,
  namespace becomes `Phlex\Shared\Plugin`. Update the docblock to
  remove the "Temporary home — moves to..." paragraph.
- `src/Plugin/Manifest.php` — moved from `phlex/src/Plugins/Manifest.php`,
  **DTO portion only**. Delete the `validate()`, `loadSchema()`,
  `mapSchemaError()` methods and the `SCHEMA_RELATIVE_PATH` constant.
  Delete the `justinrainbow/json-schema` and `Phlex\Plugins\Exception`
  imports. Keep `fromJson`, `fromArray`, `toArray`, `manifestType`,
  and every readonly property.
- `src/Plugin/ManifestType.php` — moved from
  `phlex/src/Plugins/ManifestType.php`. Same body.
- `src/Plugin/ManifestValidationError.php` — moved from
  `phlex/src/Plugins/ManifestValidationError.php`. Same body.
- `src/Plugin/EventNameMap.php` — moved from
  `phlex/src/Plugins/EventNameMap.php`. Update the `use` lines so the
  event FQCNs resolve to the new `Phlex\Shared\Events\*` namespaces.
- `src/Events/AbstractEvent.php` — moved from
  `phlex/src/Common/Events/AbstractEvent.php`. Same body, new namespace.
- `src/Events/Playback/{PlaybackStarted, PlaybackPaused, PlaybackResumed, PlaybackStopped}.php` — moved.
- `src/Events/Library/{LibraryScanStarted, LibraryScanCompleted, MediaItemAdded, MediaItemUpdated, MediaItemRemoved}.php` — moved.
- `src/Events/Auth/{UserCreated, UserLoggedIn, UserLoggedOut}.php` — moved.
- `src/Auth/JwtClaims.php` — **new**, per b.1-shared-design.md §4.4.
  Implements `fromPayload`, `toPayload`, `isExpired`, `hasScope`.
- `src/Hub/ClaimRequest.php` — **new**, per b.1-shared-design.md §4.5.
- `src/Hub/ClaimResponse.php` — **new**, per b.1-shared-design.md §4.5.
- `src/Hub/ServerInfoDto.php` — **new**, per b.1-shared-design.md §4.5.
- `src/Hub/HeartbeatDto.php` — **new**, per b.1-shared-design.md §4.5.
- `src/Arr/.gitkeep` — reserved for Phase K.1.
- `tests/Plugin/LifecycleInterfaceTest.php` — reflection-based: assert
  the interface declares `onEnable`, `onDisable`, `subscribedEvents`
  with the expected signatures.
- `tests/Plugin/ManifestTest.php` — DTO behavior: fromJson, fromArray
  round-trip, toArray, manifestType resolution. (Validator behavior is
  tested in `phlex`'s ManifestSchemaTest.)
- `tests/Plugin/ManifestTypeTest.php` — enum cases.
- `tests/Plugin/ManifestValidationErrorTest.php` — constructor smoke.
- `tests/Plugin/EventNameMapTest.php` — 12 alias↔FQCN round-trips.
- `tests/Events/AbstractEventTest.php` — timestamp set in constructor.
- `tests/Events/Playback/PlaybackStartedTest.php` — readonly props.
- `tests/Events/Library/LibraryScanCompletedTest.php` — constructor.
- `tests/Events/Auth/UserCreatedTest.php` — constructor.
- `tests/Auth/JwtClaimsTest.php` — full coverage of `fromPayload`
  (tolerant), `toPayload` (round-trip), `isExpired`, `hasScope`,
  required-field exceptions.
- `tests/Hub/ClaimRequestTest.php` — fromPayload + toPayload round-trip.
- `tests/Hub/ClaimResponseTest.php` — same.
- `tests/Hub/ServerInfoDtoTest.php` — same.
- `tests/Hub/HeartbeatDtoTest.php` — same.

#### Modify

- `src/Version.php` — bump `VERSION` to `'0.2.0'`.
- `composer.json` — already correct from B.2; no changes needed (the
  PSR-4 prefix already covers the new namespaces).
- `phpstan.neon.dist` — add the new namespaces (already covered if
  paths include `src/`; no changes needed).
- `CHANGELOG.md` — add entry:
  ```markdown
  ## [0.2.0] — 2026-05-XX

  ### Added
  - `Phlex\Shared\Plugin\LifecycleInterface` — moved from `Phlex\Plugins\Contract\LifecycleInterface` in `phlex-server`.
  - `Phlex\Shared\Plugin\{Manifest,ManifestType,ManifestValidationError,EventNameMap}` — moved from `Phlex\Plugins\*` in `phlex-server`. Validator logic stays in `phlex-server` (`Phlex\Plugins\Manifest\ManifestSchema`).
  - `Phlex\Shared\Events\{AbstractEvent, Playback\*, Library\*, Auth\*}` — moved from `Phlex\Common\Events\*` in `phlex-server` (the 12 readonly event DTOs). PSR-14 dispatcher wiring stays in `phlex-server`.
  - `Phlex\Shared\Auth\JwtClaims` — new value object capturing the Phlex JWT payload shape; consumed by `phlex-hub` starting Phase C.5.
  - `Phlex\Shared\Hub\{ClaimRequest,ClaimResponse,ServerInfoDto,HeartbeatDto}` — new placeholder DTOs for the hub claim/heartbeat protocol; consumed by `phlex-hub` starting Phase C.1.
  - `Phlex\Shared\Arr\.gitkeep` — namespace reserved for Phase K.1's `Sonarr`/`Radarr`/etc. typed clients.
  ```

#### Delete

- None — v0.2.0 adds files; nothing in v0.1.0 disappears.

#### Tag

- `v0.2.0` — pushed to `detain/phlex-shared`.

### Inside `detain/phlex` (consume the new package)

#### Create

- `src/Plugins/Manifest/ManifestSchema.php` — extracted from the old
  `Manifest::validate()` method. Class signature:
  ```php
  namespace Phlex\Plugins\Manifest;

  use Phlex\Shared\Plugin\Manifest;
  use Phlex\Shared\Plugin\ManifestValidationError;

  final class ManifestSchema
  {
      /** @return list<ManifestValidationError> */
      public function validate(Manifest $manifest): array { /* ex-Manifest::validate body */ }
  }
  ```
  Bring along the private static helpers `loadSchema`,
  `resolveSchemaPath`, `mapSchemaError`, and the
  `KNOWN_TOP_LEVEL_KEYS` + `SCHEMA_RELATIVE_PATH` constants. The
  validator now operates on a `Phlex\Shared\Plugin\Manifest` instance
  (with its raw-data accessor), not on a `$this`-internal field.
- `src/Plugins/Manifest/UnknownFieldsAccessor.php` — small helper or
  `Manifest::unknownFields()` accessor added on the shared `Manifest`
  class (whichever is simpler; the shared Manifest needs to expose
  `rawData` and `unknownFields` to the validator). **Prefer adding
  `Manifest::getRawData(): array` and `Manifest::getUnknownFields():
  list<string>` accessors in `phlex-shared` rather than introducing a
  separate helper class.**
- `src/Plugins/AliasCompatShim.php` — issues the `class_alias` calls
  for the moved event DTOs (12 entries) plus
  `EventNameMap`, `ManifestType`, `ManifestValidationError`. The
  twelve event-DTO aliases register `Phlex\Common\Events\…` → the
  new `Phlex\Shared\Events\…` FQCN. Registered via
  `composer.json#autoload.files`. The file is **not** in PSR-4
  namespace tree — it's a side-effect script.
- `tests/Unit/Plugins/AliasCompatShimTest.php` — for each aliased
  FQCN, assert `class_exists(...)` returns true, then assert
  `(new ReflectionClass(...))->getName() === <Phlex\Shared\…>` to
  confirm the alias resolves.
- `tests/Unit/Plugins/Manifest/ManifestSchemaTest.php` — split out
  the validator tests from the old `ManifestTest`. Same assertions,
  new collaborator.

#### Modify

- `composer.json`:
  - Add `"repositories": [{"type": "vcs", "url": "git@github.com:detain/phlex-shared.git"}]`.
  - Add `"detain/phlex-shared": "^0.2"` to `require`.
  - Add `"autoload.files": ["src/Plugins/AliasCompatShim.php"]`.
  - Bump the `phlex/media-server` version line in `extra` if any
    (probably not present — skip).
- `composer.lock` — regenerate via `composer update detain/phlex-shared`.
- `src/Plugins/Contract/LifecycleInterface.php` — replace body with:
  ```php
  <?php
  declare(strict_types=1);
  namespace Phlex\Plugins\Contract;

  /**
   * @deprecated since 0.11.0 — extend \Phlex\Shared\Plugin\LifecycleInterface instead. Will be removed in 0.12.0.
   * @see \Phlex\Shared\Plugin\LifecycleInterface
   */
  interface LifecycleInterface extends \Phlex\Shared\Plugin\LifecycleInterface
  {
  }
  ```
- `src/Plugins/Manifest.php` — replace body with a `@deprecated`
  wrapper:
  ```php
  /**
   * @deprecated since 0.11.0 — use \Phlex\Shared\Plugin\Manifest. For validation, use \Phlex\Plugins\Manifest\ManifestSchema. Will be removed in 0.12.0.
   */
  final class Manifest extends \Phlex\Shared\Plugin\Manifest
  {
      public function validate(): array
      {
          return (new Manifest\ManifestSchema())->validate($this);
      }
  }
  ```
  Note: the `extends` is only legal if `Phlex\Shared\Plugin\Manifest`
  is **not** `final`. The shared `Manifest` therefore must drop the
  `final` modifier. Document this explicitly in the shared package's
  `Manifest` class docblock with a note that subclassing is reserved
  for the `phlex-server` deprecation shim and discouraged otherwise.
- `src/Plugins/ManifestType.php`, `src/Plugins/ManifestValidationError.php`,
  `src/Plugins/EventNameMap.php` — **delete** these files; the
  `class_alias` entries in `AliasCompatShim.php` cover them.
- `src/Common/Events/AbstractEvent.php` and all twelve concrete
  event DTOs under `src/Common/Events/{Playback,Library,Auth}/` —
  **delete** these files; the `class_alias` entries cover them. **Do
  NOT delete** `EventDispatcherFactory.php`, `ListenerRegistry.php`,
  or `StructuredLoggerPsrAdapter.php` — those stay (they're Tukio
  wiring).
- `src/Plugins/PluginLoader.php` — update `use` from
  `Phlex\Plugins\Contract\LifecycleInterface` to
  `Phlex\Shared\Plugin\LifecycleInterface`. Update `use
  Phlex\Plugins\EventNameMap` to `Phlex\Shared\Plugin\EventNameMap`.
  Update `use Phlex\Plugins\ManifestType` to
  `Phlex\Shared\Plugin\ManifestType`.
- `src/Plugins/InstalledPlugin.php` — update `use Phlex\Plugins\Manifest`
  to `Phlex\Shared\Plugin\Manifest`. Update `ManifestType` use.
- `src/Plugins/Repository/PluginRepository.php` — same `use` updates.
- `src/Plugins/Installer/*.php` — same `use` updates as needed.
- `src/Plugins/Signature/SignatureVerifier.php` — same.
- `src/Plugins/Util/*.php` — same.
- `src/Plugins/Exception/*.php` — same (these reference
  `ManifestValidationError`).
- `src/Plugins/SettingsMasker.php` — same.
- `src/Common/Events/EventDispatcherFactory.php` — update
  `use Phlex\Common\Events\AbstractEvent` to
  `Phlex\Shared\Events\AbstractEvent` (if used).
- `src/Common/Events/ListenerRegistry.php` — same.
- `src/Session/PlaybackController.php` — `use Phlex\Common\Events\Playback\PlaybackStarted` becomes `use Phlex\Shared\Events\Playback\PlaybackStarted`. Same for the three other playback events.
- `src/Media/Library/MediaScanner.php` — update `use` for the five library events.
- `src/Auth/AuthManager.php` — update `use` for the three user events.
- `tests/Unit/Plugins/**/*.php` — every test that imports
  `Phlex\Plugins\Contract\LifecycleInterface`,
  `Phlex\Plugins\Manifest`, `Phlex\Plugins\ManifestType`,
  `Phlex\Plugins\ManifestValidationError`, `Phlex\Plugins\EventNameMap`
  must update its `use`.
- `tests/Unit/Common/Events/**/*.php` — every test that imports a
  `Phlex\Common\Events\*` event class must update its `use`.
- `tests/Fixtures/Plugins/fixture-plugin/Plugin.php` — keeps the old
  `Phlex\Plugins\Contract\LifecycleInterface` import to prove the
  deprecation shim works (the shim's whole purpose). Add a comment
  noting this is intentional.
- `phpstan-baseline.neon` — every entry whose `path:` points at a
  deleted file must be removed (12 event files + 4 plugins files = 16
  candidate paths). Re-run baseline check after edits; if PHPStan
  reports new errors in the moved code, fix the new code rather than
  growing the baseline.
- `psalm.xml` — if it has `<file>` exclusions for the moved files,
  remove them.
- `docs/dev/event-reference.md` — every FQCN column
  `Phlex\Common\Events\…` becomes `Phlex\Shared\Events\…`. The
  manifest-alias column is unchanged.
- `docs/plugins/developer-guide.md` — every code example's `use`
  line updates. Add a new "Migrating from 0.10.x" section:
  ```markdown
  ## Migrating from 0.10.x

  As of `phlex-server` 0.11.0, the framework-neutral plugin contract
  has moved to the `detain/phlex-shared` Composer package.

  - `Phlex\Plugins\Contract\LifecycleInterface` → `Phlex\Shared\Plugin\LifecycleInterface`
  - `Phlex\Common\Events\Playback\PlaybackStarted` → `Phlex\Shared\Events\Playback\PlaybackStarted`
  - … (full table)

  The 0.10.x FQCNs continue to work in 0.11.x as deprecated aliases.
  They are removed in 0.12.0. Update your `use` statements to the
  new FQCNs at your earliest convenience.
  ```
- `docs/plugins/manifest.md` — every code example's `use` line.
- `docs/dev/plugin-sdk.md` — the file map of `src/Plugins/**` updates:
  five moved files disappear, `Manifest/ManifestSchema.php` and
  `AliasCompatShim.php` appear.
- `docs/dev/architecture-server.md` — append a short "Dependencies
  → detain/phlex-shared" section pointing at the shared package's
  README.
- `CHANGELOG.md` — entry:
  ```markdown
  ## [0.11.0] — 2026-05-XX
  ### Changed
  - Refactored to depend on `detain/phlex-shared:^0.2`. The
    `LifecycleInterface`, manifest DTOs, and event DTOs now live in
    the shared package. Old FQCNs (`Phlex\Plugins\Contract\LifecycleInterface`,
    `Phlex\Plugins\Manifest`, `Phlex\Plugins\ManifestType`,
    `Phlex\Plugins\ManifestValidationError`, `Phlex\Plugins\EventNameMap`,
    `Phlex\Common\Events\*`) remain as deprecated aliases through
    0.11.x; removed in 0.12.0.
  - Manifest schema validation extracted to `Phlex\Plugins\Manifest\ManifestSchema`.
  ### Added
  - Composer require on `detain/phlex-shared:^0.2`.
  ```
- `README.md` — "Status" feature list gets a new bullet `* Shared
  interfaces / DTOs in detain/phlex-shared`.
- `AGENTS.md`, `CLAUDE.md` — Caliber regenerates these. Stage the
  diff.

#### Delete

- `src/Plugins/ManifestType.php`
- `src/Plugins/ManifestValidationError.php`
- `src/Plugins/EventNameMap.php`
- `src/Common/Events/AbstractEvent.php`
- `src/Common/Events/Playback/PlaybackStarted.php`
- `src/Common/Events/Playback/PlaybackPaused.php`
- `src/Common/Events/Playback/PlaybackResumed.php`
- `src/Common/Events/Playback/PlaybackStopped.php`
- `src/Common/Events/Library/LibraryScanStarted.php`
- `src/Common/Events/Library/LibraryScanCompleted.php`
- `src/Common/Events/Library/MediaItemAdded.php`
- `src/Common/Events/Library/MediaItemUpdated.php`
- `src/Common/Events/Library/MediaItemRemoved.php`
- `src/Common/Events/Auth/UserCreated.php`
- `src/Common/Events/Auth/UserLoggedIn.php`
- `src/Common/Events/Auth/UserLoggedOut.php`

(Sixteen files total. Each is `class_alias`-replaced via
`AliasCompatShim.php`.)

## 4. Approach

**Order matters.** Do `phlex-shared` first, tag v0.2.0, push, **then**
update `phlex` to consume it.

1. **Phase 4.A — `phlex-shared` v0.2.0.**
   1. `cd /home/sites/phlex-shared`. Confirm clean master at v0.1.0.
   2. `git checkout -b b.3-shared-v0.2`.
   3. **Move** every file listed under "Inside phlex-shared / Create"
      using `git mv` from a temporary checkout of `phlex` (or just
      copy + edit; the source files are 30s SLOC each, no real
      history to preserve). For each file, update the `namespace`
      declaration and remove the "Temporary home..." paragraphs.
   4. **Add the new files**: `JwtClaims.php`, the four `Hub/*` DTOs,
      `Arr/.gitkeep`. Write them per b.1-shared-design.md §4.4 and
      §4.5.
   5. **Drop `final`** from the new shared `Manifest` class so
      `phlex`'s deprecation wrapper can extend it. Document why in
      the class docblock.
   6. **Bump `Version::VERSION`** to `'0.2.0'`.
   7. **Add accessors** to the shared `Manifest`:
      `public function getRawData(): array` and `public function
      getUnknownFields(): array` so `ManifestSchema` (in `phlex`)
      can do its work without touching private state. These were
      private fields on the old `Manifest`; promote them to readonly
      properties or expose via these accessors.
   8. **Update CHANGELOG.md** per §3.
   9. **Write the tests** listed under "Inside phlex-shared / Create".
      Mirror the existing tests in `phlex`'s `tests/Unit/Plugins/` and
      `tests/Unit/Common/Events/`, adapting namespaces.
   10. **Run the verification bar inside `phlex-shared`:**
       ```bash
       composer install
       ./vendor/bin/phpunit
       ./vendor/bin/phpstan analyze --no-progress
       ./vendor/bin/phpcs --standard=PSR12 src/
       ./vendor/bin/psalm --no-progress
       composer validate --strict
       composer audit --no-dev
       ```
   11. **PR + merge + tag.** Open a PR on `detain/phlex-shared`,
       wait for CI green, squash-merge. Then on master:
       ```bash
       git checkout master
       git pull --ff-only origin master
       git tag -a v0.2.0 -m "v0.2.0 — Plugin contracts, Events, JwtClaims, Hub DTOs (Step B.3)"
       git push origin v0.2.0
       ```
   12. **Verify** the tag is on the remote:
       ```bash
       git ls-remote --tags origin | grep refs/tags/v0.2.0
       ```

2. **Phase 4.B — `phlex` consumes v0.2.0.**
   1. `cd /home/sites/phlex`. Confirm clean master.
   2. `git checkout -b b.3-shared-consume`.
   3. **Edit `composer.json`**: add the VCS repository entry, the
      `detain/phlex-shared: ^0.2` require, and the `autoload.files`
      entry. Run `composer update detain/phlex-shared` to lock at
      v0.2.0.
   4. **Write `src/Plugins/AliasCompatShim.php`.** Each alias entry
      looks like:
      ```php
      if (!class_exists(\Phlex\Common\Events\Playback\PlaybackStarted::class, false)) {
          class_alias(
              \Phlex\Shared\Events\Playback\PlaybackStarted::class,
              \Phlex\Common\Events\Playback\PlaybackStarted::class,
          );
      }
      ```
      Plus 11 more for the other event DTOs, plus three more for
      `EventNameMap`, `ManifestType`, `ManifestValidationError`. The
      `AbstractEvent` is also aliased. Total: 17 `class_alias` calls
      in this file.
   5. **Rewrite `Phlex\Plugins\Contract\LifecycleInterface`** as a
      3-line shim per §3.
   6. **Rewrite `Phlex\Plugins\Manifest`** as a `@deprecated` wrapper
      per §3.
   7. **Extract `Phlex\Plugins\Manifest\ManifestSchema`** with the
      validator body from the old `Manifest::validate()`.
   8. **Delete** the sixteen files listed in §3 "Delete".
   9. **Update `use` statements** across the source tree. For each
      moved class, run `grep -rln "Phlex\\\\Plugins\\\\Contract\\\\LifecycleInterface" src/ tests/` (and the equivalent for the other classes) and update each match. Tooling:
      ```bash
      # Plan-recommended pattern, NOT a one-liner — review every diff.
      for src in \
          "Phlex\\Plugins\\Contract\\LifecycleInterface=Phlex\\Shared\\Plugin\\LifecycleInterface" \
          "Phlex\\Plugins\\EventNameMap=Phlex\\Shared\\Plugin\\EventNameMap" \
          "Phlex\\Plugins\\ManifestType=Phlex\\Shared\\Plugin\\ManifestType" \
          "Phlex\\Plugins\\ManifestValidationError=Phlex\\Shared\\Plugin\\ManifestValidationError" \
          "Phlex\\Common\\Events\\AbstractEvent=Phlex\\Shared\\Events\\AbstractEvent" \
          "Phlex\\Common\\Events\\Playback\\PlaybackStarted=Phlex\\Shared\\Events\\Playback\\PlaybackStarted" \
          ...; do
        ...
      done
      ```
      But: **only update `use` lines, NOT fixture plugins**. The
      `tests/Fixtures/Plugins/fixture-plugin/Plugin.php` file
      intentionally keeps the old import to test the shim.
   10. **Refactor `Manifest` consumers** to call
       `(new ManifestSchema())->validate($manifest)` instead of
       `$manifest->validate()`. Or, given the wrapper class still
       exposes `validate()`, optionally leave consumers untouched —
       but for fresh code in `phlex` proper, prefer the explicit
       `ManifestSchema`. (The wrapper exists for downstream
       compatibility, not for in-tree use.)
   11. **Update `phpstan-baseline.neon`**: delete entries for the
       sixteen deleted files. Run `./vendor/bin/phpstan analyze
       --no-progress` and address any new errors in the moved code
       directly. **Do NOT add new baseline entries.**
   12. **Run the verification bar:**
       ```bash
       ./vendor/bin/phpunit
       ./vendor/bin/phpunit --coverage-text | grep -E 'Plugins|Events|Auth'
       ./vendor/bin/phpstan analyze src/ --level=9
       ./vendor/bin/phpcs --standard=PSR12 src/
       ./vendor/bin/psalm --no-progress
       find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
       ```
   13. **Run the sample plugin smoke test** to verify the
       deprecation shim:
       ```bash
       ./vendor/bin/phpunit tests/Integration/Plugins/SamplePluginSmokeTest.php
       ```
       This test imports `phlex-plugin-example` which implements
       `Phlex\Plugins\Contract\LifecycleInterface` (the deprecated
       alias). If the shim works, the test still passes.
   14. **Update docs** per §3.
   15. **Update CHANGELOG.md and README.md** per §3.
   16. **Caliber sync** (hook active).
   17. **Commit + PR + merge + master pull** per the standard ritual.

## 5. Tests (REQUIRED — §0.4 minimum bar)

**Inside `phlex-shared`:**

- All new tests under `tests/Plugin/`, `tests/Events/`, `tests/Auth/`,
  `tests/Hub/` — ≥ 85 % coverage on `src/Plugin/`, `src/Events/`,
  `src/Auth/`, `src/Hub/`.
- The existing `tests/VersionTest.php` updated to assert
  `Version::VERSION === '0.2.0'`.
- New tests for `JwtClaims::fromPayload` (8 cases:
  required-field-missing throws, optional-field-missing tolerates,
  type-mismatch throws, full round-trip, expired check, scope check,
  audience validation, type validation).

**Inside `phlex`:**

- `tests/Unit/Plugins/AliasCompatShimTest.php` — for each of the 17
  aliases, assert:
  - `class_exists('Phlex\Common\Events\Playback\PlaybackStarted', true)` is `true`.
  - `(new ReflectionClass(...))->getName() === 'Phlex\Shared\Events\Playback\PlaybackStarted'` (the alias resolves to the real class).
  - `is_a('Phlex\Common\Events\Playback\PlaybackStarted', 'Phlex\Shared\Events\AbstractEvent', true)` is `true`.
- `tests/Unit/Plugins/Manifest/ManifestSchemaTest.php` — port the
  old `Manifest::validate()` tests from `ManifestTest`. Same
  assertions.
- `tests/Integration/Plugins/LifecycleShimTest.php` — define a
  fixture class that implements
  `\Phlex\Plugins\Contract\LifecycleInterface` (the deprecated
  alias). Assert that
  `is_a($fixture, \Phlex\Shared\Plugin\LifecycleInterface::class)`
  is true. This guards the `extends` bridge.
- The existing `SamplePluginSmokeTest` must remain green without
  modification (it exercises the shim's whole reason for existing).

**Coverage target:** ≥ 85 % on `src/Plugins/AliasCompatShim.php`,
`src/Plugins/Contract/LifecycleInterface.php` (the shim — trivial),
`src/Plugins/Manifest.php` (the deprecated wrapper),
`src/Plugins/Manifest/ManifestSchema.php` (the extracted validator).

**Integration boundary:** the deprecation shim crosses a Composer
boundary (between `phlex` and `phlex-shared`). The `LifecycleShimTest`
and `SamplePluginSmokeTest` together satisfy the §0.4 integration
test requirement.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **"Public HTTP/WS API"** → N/A; B.3 is internal refactor.
- **"The plugin API"** → `docs/plugins/developer-guide.md` and
  `docs/plugins/manifest.md` updated per §3.
- **Developer docs** → `docs/dev/event-reference.md`,
  `docs/dev/plugin-sdk.md`, `docs/dev/architecture-server.md` updated
  per §3.
- **"User-visible behavior change"** → arguably "no" since users see
  no behavior delta. **But** the deprecation aliases ARE
  user-visible to plugin authors, so `CHANGELOG.md` line is required.
- **"Anything"** → `README.md` Status feature list — one new bullet.

PHPDoc per §0.4 on every new public class/method (new ones live in
`phlex-shared`). The deprecation shims in `phlex` carry `@deprecated
since 0.11.0` PHPDoc with a pointer to the new FQCN.

## 7. Acceptance criteria (subagent checks every box before claiming done)

**`phlex-shared` side:**

- [ ] `Version::VERSION` is `'0.2.0'`.
- [ ] All 23 source files listed in §3 "Create" exist with the
      expected namespaces and bodies.
- [ ] `src/Arr/.gitkeep` exists.
- [ ] All tests under `tests/Plugin/`, `tests/Events/`, `tests/Auth/`,
      `tests/Hub/` pass.
- [ ] `composer install && composer validate --strict && composer audit --no-dev` — clean.
- [ ] `./vendor/bin/phpunit` — green.
- [ ] `./vendor/bin/phpstan analyze --no-progress` — `[OK] No errors`.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] `./vendor/bin/psalm --no-progress` — clean.
- [ ] `find src -name '*.php' -exec php -l {} \;` — no syntax errors.
- [ ] `CHANGELOG.md` has the 0.2.0 entry.
- [ ] Tag `v0.2.0` pushed to `detain/phlex-shared`.
- [ ] GitHub Actions CI green on the v0.2.0 PR and on the tag push.

**`phlex` side:**

- [ ] `composer.json` declares `"detain/phlex-shared": "^0.2"` and the
      VCS repository.
- [ ] `composer.lock` shows `detain/phlex-shared` at `^0.2.0`.
- [ ] `composer.json` declares `"autoload.files": ["src/Plugins/AliasCompatShim.php"]`.
- [ ] All sixteen files listed in §3 "Delete" are gone.
- [ ] `src/Plugins/Contract/LifecycleInterface.php` is the 3-line
      shim extending the shared interface.
- [ ] `src/Plugins/Manifest.php` is the `@deprecated` wrapper
      extending the shared `Manifest`.
- [ ] `src/Plugins/Manifest/ManifestSchema.php` exists and owns the
      JSON-Schema validator.
- [ ] `src/Plugins/AliasCompatShim.php` exists with the 17 alias
      registrations.
- [ ] `tests/Fixtures/Plugins/fixture-plugin/Plugin.php` still
      implements `Phlex\Plugins\Contract\LifecycleInterface` (the
      deprecated alias) — proves the shim works.
- [ ] `./vendor/bin/phpunit` — green, no skips. **At least 667
      tests still pass.** (Test count may grow with new
      AliasCompatShimTest, ManifestSchemaTest, LifecycleShimTest —
      that's fine; the rule is no test count *decrease* without an
      explicit deletion rationale.)
- [ ] Coverage of `src/Plugins/AliasCompatShim.php` is ≥ 85 %.
- [ ] Coverage of `src/Plugins/Manifest/ManifestSchema.php` is ≥ 85 %.
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — `[OK] No errors`.
      No new baseline entries.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] `./vendor/bin/psalm --no-progress` — clean.
- [ ] `find src -name '*.php' -exec php -l {} \;` — no syntax errors.
- [ ] PHPDoc on every new public class/method.
- [ ] `docs/dev/event-reference.md`, `docs/plugins/developer-guide.md`,
      `docs/plugins/manifest.md`, `docs/dev/plugin-sdk.md`,
      `docs/dev/architecture-server.md` all updated.
- [ ] `CHANGELOG.md` has the 0.11.0 entry.
- [ ] Caliber pre-commit hook ran on the `phlex` commit.
- [ ] Git ritual §8 below executed; postcondition checks PASS for
      `phlex`. (For `phlex-shared`, the analogous checks were
      validated above.)

## 8. Git ritual (copy of master plan §11.4 — TWO repos, two PRs)

### 8.A — `phlex-shared` v0.2.0 PR

```bash
# ─── 0. PRECONDITION ───
cd /home/sites/phlex-shared
git status --short                          # MUST be empty
git branch --show-current                   # MUST be 'master'
git pull --ff-only origin master

# ─── 1. Branch ───
git checkout -b b.3-shared-v0.2

# ─── 2. Do the work — write every file in §3 "Inside phlex-shared / Create" ───

# ─── 3. Verify (§0.4 minimum bar) ───
composer install
./vendor/bin/phpunit
./vendor/bin/phpstan analyze --no-progress
./vendor/bin/phpcs --standard=PSR12 src/
./vendor/bin/psalm --no-progress
composer validate --strict
composer audit --no-dev
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. (Caliber not installed here yet) ───
git add -A

# ─── 5. Commit ───
git commit -m "Step B.3 (shared half): add Plugin/Events/Auth/Hub namespaces; bump to v0.2.0"

# ─── 6. CRITICAL ───
unset GITHUB_TOKEN

# ─── 7. PR + merge ───
gh pr create \
  --title "Step B.3 (shared half): v0.2.0 — Plugin/Events/Auth/Hub" \
  --body  "Adds Phlex\\Shared\\Plugin\\*, Events\\*, Auth\\JwtClaims, Hub\\* per plans/expansion/b.1-shared-design.md §4.1. The phlex-server PR (separate, against detain/phlex) consumes this release."
gh pr merge --squash --delete-branch

# ─── 8. Pull master + tag v0.2.0 ───
git checkout master
git pull --ff-only origin master
git tag -a v0.2.0 -m "v0.2.0 — Plugin/Events/Auth/Hub namespaces (Step B.3)"
git push origin v0.2.0

# ─── 9. POSTCONDITION (shared) ───
git status --short                          # MUST be empty
git branch --show-current                   # MUST be 'master'
git log --oneline -1                        # MUST show the new squashed commit
git branch --list 'b.3-*'                   # MUST be empty
git tag -l 'v0.2.0'                         # MUST list v0.2.0
gh run list --branch master --limit 1 --json conclusion | grep '"conclusion":"success"'
```

### 8.B — `phlex` consume PR (runs AFTER 8.A succeeds)

```bash
# ─── 0. PRECONDITION ───
cd /home/sites/phlex
git status --short                          # MUST be empty (CALIBER_LEARNINGS.md diff OK)
git branch --show-current                   # MUST be 'master'
git pull --ff-only origin master

# ─── 1. Branch ───
git checkout -b b.3-shared-consume

# ─── 2. Do the work — composer require, AliasCompatShim, deprecation shims, delete moved files, update use statements, update docs ───

# ─── 3. Verify (§0.4 minimum bar) ───
composer update detain/phlex-shared
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text | grep -E 'Plugins|Events|Auth'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
./vendor/bin/psalm --no-progress
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
./vendor/bin/phpunit tests/Integration/Plugins/SamplePluginSmokeTest.php  # shim guard

# ─── 4. Caliber sync (hook active on /home/sites/phlex) ───
git add -A

# ─── 5. Commit — NEW commit, NEVER --amend ───
git commit -m "Step B.3 (server half): consume detain/phlex-shared ^0.2; deprecate moved FQCNs"

# ─── 6. CRITICAL ───
unset GITHUB_TOKEN

# ─── 7. PR + merge ───
gh pr create \
  --title "Step B.3 (server half): consume detain/phlex-shared ^0.2" \
  --body  "Moves LifecycleInterface, manifest DTOs, event DTOs, EventNameMap into detain/phlex-shared (v0.2.0, landed in a sibling PR). Adds class_alias and interface-extends deprecation shims for one release. Extracts ManifestSchema validator. All 667 tests stay green. Implements step B.3 of PHLEX_EXPANSION_PLAN.md."
gh pr merge --squash --delete-branch

# ─── 8. Return to master ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION (server) ───
git status --short                          # MUST be empty (CALIBER_LEARNINGS.md diff OK)
git branch --show-current                   # MUST be 'master'
git log --oneline -1                        # MUST show the new squashed commit
git branch --list 'b.3-*'                   # MUST be empty
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs `b.3-shared-consume-review.md` which
covers both repos. The reviewer additionally cross-checks the
`AliasCompatShim.php` content against b.1-shared-design.md §4.1 to
confirm every `class_alias` listed in the move table is present.

Two things to watch in review:

1. **Shim correctness.** A failing `LifecycleShimTest` means the
   `extends` bridge is broken — that immediately breaks the
   pre-published `phlex-plugin-example` v0.1.0. Roll back the PR
   rather than ship a half-broken shim.
2. **Composer VCS resolution.** If `composer update detain/phlex-shared`
   fails on the reviewer's machine due to SSH auth, that's a host
   issue, not a B.3 failure. The CI run is authoritative.
