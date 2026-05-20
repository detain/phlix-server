# Step A.1 — PSR-11 dependency injection container

**Phase:** A (Plugin Foundation & DI)
**Step:** A.1
**Depends on:** A.0
**Review:** Yes — see `a.1-di-container-review.md`
**Target repo:** detain/phlex (local: /home/sites/phlex)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Replace the hardcoded `new ClassName(...)` bootstrap in `public/index.php`
and `src/Server/Core/Application.php` with a PSR-11 container. Every service
the server needs (DB connection, loggers, AuthManager, repositories,
metadata manager, playback controller, session manager, HLS streamer)
becomes resolvable through `$container->get(SomeClass::class)`. Constructor
auto-wiring removes the boilerplate that makes the current bootstrap
fragile (look at the half-broken `$scanner ?? null` line in
`public/index.php`). This is the prerequisite for Phase A.2's event
dispatcher (registered as a container singleton) and for Phase A.4's plugin
loader (which receives the container to expose to plugin lifecycle hooks).

## 2. Context (what already exists)

Read first, do not modify until §4:

- `/home/sites/phlex/composer.json` — current deps (Workerman, Monolog,
  PHPUnit, Mockery). No PSR-11 implementation present.
- `/home/sites/phlex/public/index.php` — the hardcoded bootstrap. Note the
  broken `$libraryManager = new LibraryManager($db, $scanner ?? null,
  $watcher ?? null);` line. A.1 fixes that by wiring scanner + watcher in
  the container.
- `/home/sites/phlex/src/Server/Core/Application.php` — singleton with a
  `getInstance()` method and a constructor that takes a config path.
- `/home/sites/phlex/src/Common/Database/ConnectionPool.php` — static
  `init()` and `getConnection('mysql')`. The container must wrap this so
  the connection can be injected as a typed parameter, not pulled from a
  static.
- `/home/sites/phlex/src/Common/Logger/LoggerFactory.php` and
  `/home/sites/phlex/src/Common/Logger/LogChannels.php` — channelled loggers
  fetched by string key.
- `/home/sites/phlex/src/Auth/{JwtHandler,UserRepository,AuthManager,
  UserProfileManager,WatchHistory}.php` — concrete classes the container
  must wire.
- `/home/sites/phlex/src/Media/Library/{LibraryManager,ItemRepository,
  MediaScanner,FolderWatcher}.php` and
  `/home/sites/phlex/src/Media/Metadata/MetadataManager.php` and
  `/home/sites/phlex/src/Media/Streaming/HlsStreamer.php` and
  `/home/sites/phlex/src/Session/{SessionManager,PlaybackController}.php`
  — additional concrete classes.

## 3. Scope — files to create / modify

### Create

- `src/Common/Container/ContainerFactory.php` — single entry point
  `ContainerFactory::create(array $config): \Psr\Container\ContainerInterface`.
- `src/Common/Container/ServiceProviderInterface.php` — internal contract
  (`register(\DI\ContainerBuilder $builder): void`), tagged `@internal`.
- `src/Common/Container/Providers/CoreServicesProvider.php` — registers
  database, logger factory.
- `src/Common/Container/Providers/AuthServicesProvider.php` — registers
  JwtHandler, UserRepository, AuthManager, UserProfileManager.
- `src/Common/Container/Providers/MediaServicesProvider.php` — registers
  ItemRepository, MediaScanner, FolderWatcher, LibraryManager,
  MetadataManager, HlsStreamer.
- `src/Common/Container/Providers/SessionServicesProvider.php` — registers
  SessionManager, PlaybackController.
- `tests/unit/Common/Container/ContainerFactoryTest.php` — unit tests
  (see §5).
- `tests/unit/Common/Container/Providers/CoreServicesProviderTest.php` —
  smoke test for the core provider.
- `docs/dev/architecture-server.md` — new doc (see §6).
- `docs/reference/env-vars.md` — new doc (see §6).

### Modify

- `composer.json` — add `php-di/php-di: ^7.0` and `psr/container: ^2.0`
  (PHP-DI 7 transitively requires `psr/container` ^2; require it
  explicitly so test doubles can type-hint against the PSR interface
  without depending on a transitive resolution).
- `composer.lock` — regenerate.
- `src/Server/Core/Application.php` — accept a
  `\Psr\Container\ContainerInterface` in the constructor (still backed by
  the existing config-path constructor for backwards compatibility — see
  §4 step 4). Keep `getInstance()` for now; A.1 deprecates it with
  `@deprecated since 0.10.0, use $container->get(Application::class)`.
- `public/index.php` — replace the long `new X(...)` chain with
  `$container = ContainerFactory::create(include __DIR__ . '/../config/server.php');`
  and resolve `Application` + `PageRenderer` from the container.
- `CHANGELOG.md` — add line under "Unreleased": `Added: PSR-11 dependency
  injection container (PHP-DI). Application services are now auto-wired;
  the legacy ConnectionPool / LoggerFactory statics remain for backwards
  compatibility but are wrapped behind container bindings.`
- `AGENTS.md` and `CLAUDE.md` — Caliber will regenerate the architecture
  block to mention `src/Common/Container/`. Subagent stages the diff after
  the Caliber pre-commit hook runs.

### Delete

- None.

## 4. Approach

1. **Pick the container.** Use `php-di/php-di:^7.0`.
   - Rationale: PSR-11 compliant, attribute-based autowiring (`#[Inject]`)
     compatible with PHP 8.3, zero-config wiring of typed constructors,
     huge ecosystem familiarity, MIT licensed. `league/container` is the
     close runner-up but requires more boilerplate per binding.
   - PHP-DI 7 requires PHP 8.1+, matching `composer.json`'s constraint.
2. **Composer.** `composer require php-di/php-di:^7.0 psr/container:^2.0`.
   Confirm `composer.lock` regenerates cleanly.
3. **Build the factory.** `ContainerFactory::create()` constructs a
   `DI\ContainerBuilder`, enables autowiring (`useAutowiring(true)`),
   enables attribute parsing (`useAttributes(true)`), calls each
   `ServiceProviderInterface::register()`, and returns
   `$builder->build()`. Cache compilation is gated behind a
   `PHLEX_CONTAINER_COMPILE` env var (off by default for dev; on for
   production to write to `var/cache/container/`).
4. **Provider bindings.** Each provider registers concrete classes as
   singletons keyed by FQCN. Key bindings:
    - `Workerman\MySQL\Connection::class` → factory:
      `fn() => ConnectionPool::getConnection('mysql')`. (Keep the static
      ConnectionPool — A.1 wraps it, does not replace it; the eventual
      replacement is a separate step in Phase B.)
    - `Phlex\Common\Logger\LoggerFactory::class` → static instance from
      `LoggerFactory::init()`.
    - One factory per `LogChannels::*` channel exposed via the helper
      `LoggerFactory::get($channel)` — wired into AuthManager/etc.
      constructors via PHP-DI's `DI\get('logger.auth')` style references.
    - `Phlex\Auth\JwtHandler::class` → factory reading `JWT_SECRET` from
      env (default still the old "default-secret-change-me" for parity
      with `public/index.php`).
    - All other concrete classes: rely on autowiring.
5. **Refactor `Application`.** Add a second constructor signature that
   accepts `ContainerInterface $container, array $config`. Wrap the old
   signature in a `static fromConfigPath(string $configPath): self` named
   constructor that calls `ContainerFactory::create()`. Deprecate
   `getInstance()` with a `@deprecated` PHPDoc tag.
6. **Refactor `public/index.php`.** Replace lines 24–90 with:
   ```php
   $config = include __DIR__ . '/../config/server.php';
   $config['db_config_path']     = __DIR__ . '/../config/database.php';
   $config['logger_config_path'] = __DIR__ . '/../config/logger.php';
   $container = ContainerFactory::create($config);
   $request   = Request::fromGlobals();
   // ... auth handled via $container->get(AuthManager::class)
   ```
   The fix for the broken `LibraryManager` line falls out naturally —
   `MediaScanner` and `FolderWatcher` become container bindings, so
   `LibraryManager`'s autowired constructor receives real instances.
7. **PHPDoc.** Every new public class and method gets a docblock per
   §0.4 (summary, long description, `@param`, `@return`, `@throws`,
   `@since 0.10.0`, `@package Phlex\Common\Container`).
8. **`@internal`.** `ServiceProviderInterface` and the individual
   provider classes get `@internal` — third-party plugins talk to the
   container via the PSR-11 interface, not these helpers.
9. **Caliber.** Pre-commit hook is active. It will regenerate AGENTS.md /
   CLAUDE.md to add the new namespace; the subagent stages the resulting
   diff before pushing.

## 5. Tests (REQUIRED — §0.4 minimum bar)

Unit tests under `tests/unit/Common/Container/`. Use PHPUnit 10 +
`createMock(\Workerman\MySQL\Connection::class)` per AGENTS.md convention.

Required tests on `ContainerFactoryTest`:

1. `test_create_returns_psr_container` — `create([])` returns an instance of
   `\Psr\Container\ContainerInterface`.
2. `test_resolves_jwt_handler_with_env_secret` — set `JWT_SECRET=xyz`,
   resolve `JwtHandler`, assert internal secret matches.
3. `test_resolves_jwt_handler_with_default_secret_when_env_missing` —
   verifies fallback string.
4. `test_resolves_auth_manager_with_dependencies_wired` — resolves
   `AuthManager`, asserts it received a `JwtHandler` and a logger.
5. `test_resolves_singleton_returns_same_instance` — two `get()` calls for
   `LibraryManager` return the same object.
6. `test_get_unknown_id_throws_psr_not_found_exception` — must throw
   `\Psr\Container\NotFoundExceptionInterface`.
7. `test_get_with_circular_dependency_throws` — sanity check on DI's own
   detection (use a fixture pair in `tests/Fixtures/Container/`).
8. `test_db_connection_factory_resolves_via_connection_pool` — mock the
   static (via partial isolation or a test config) and assert the binding
   returns the expected mock.

Required test on `CoreServicesProviderTest`:

1. `test_register_adds_logger_and_db_definitions` — register against a
   spy `ContainerBuilder` and assert the expected definitions exist.

**Coverage target:** ≥ 85 % on `src/Common/Container/**`. Verify via
`./vendor/bin/phpunit --coverage-text | grep 'Common/Container'`.

**Integration test:** A.1 touches the bootstrap boundary. Add
`tests/integration/Container/BootstrapTest.php` that includes
`public/index.php`'s replacement logic against a sqlite-backed config
fixture and confirms `Application` boots without throwing. Skip the test
if `pdo_sqlite` is missing (document the skip in the test message).

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **"A configurable env var or `config/*.php` key"** → create
  `docs/reference/env-vars.md` and add:
  - `PHLEX_CONTAINER_COMPILE` (`0` / `1`, default `0`) — enables PHP-DI
    compiled-container cache at `var/cache/container/`.
  - `JWT_SECRET` — already used, formally documented here.
- **"Anything"** → update `README.md` "Status" section with a new bullet
  `* PSR-11 DI container (PHP-DI 7) — auto-wired services` under the
  feature list.
- **Developer docs** → create `docs/dev/architecture-server.md` with a
  short "Bootstrap & container" section explaining `ContainerFactory`,
  the four providers, and how to add a new binding (point at
  `ServiceProviderInterface`).
- **CHANGELOG** → already covered in §3 Modify.

PHPDoc on every new public class/method per §0.4 — class summary, longer
description, `@package`, `@since 0.10.0`, plus `@param`, `@return`,
`@throws` on each method. Providers' `register()` methods get
`@internal`.

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] All files listed in §3 "Create" exist with the described
      responsibility.
- [ ] All files listed in §3 "Modify" updated as described.
- [ ] `composer.json` declares `php-di/php-di:^7.0` and `psr/container:^2.0`.
- [ ] `composer install` succeeds in CI's PHP 8.3 image.
- [ ] `./vendor/bin/phpunit` — green, no skips (except the documented
      sqlite skip if applicable).
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — zero new errors.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] `find src -name '*.php' -exec php -l {} \;` — no syntax errors.
- [ ] Coverage of `src/Common/Container/**` ≥ 85 %.
- [ ] PHPDoc on every new public class/method (`@since 0.10.0`).
- [ ] Docs from §6 updated/created.
- [ ] CHANGELOG.md updated with the line in §3.
- [ ] Caliber pre-commit hook ran on commit (verified via `grep -q
      "caliber" .git/hooks/pre-commit` before the commit); the regenerated
      `AGENTS.md` / `CLAUDE.md` / `.claude/` etc. were included in the
      committed diff.
- [ ] Git ritual §8 below executed; postcondition checks PASS.

## 8. Git ritual (copy of master plan §11.4)

```bash
# ─── 0. PRECONDITION: confirm we're starting from clean master ───
cd /home/sites/phlex
git status --short                          # MUST be empty; if not, stop and report
git branch --show-current                   # MUST be 'master'; if not, stop and report
git pull --ff-only origin master

# ─── 1. Branch ───
git checkout -b a.1-di-container

# ─── 2. Do the work; add tests; update docs (§0.4); add PHPDocs ───
# (implement per §4 above)

# ─── 3. Verify (§0.4 minimum bar) ───
./vendor/bin/phpunit                                   # green, no skips
./vendor/bin/phpunit --coverage-text | grep 'Common/Container'   # ≥ 85 %
./vendor/bin/phpstan analyze src/ --level=9            # zero new errors vs. master
./vendor/bin/phpcs --standard=PSR12 src/               # clean
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync (hook active — runs on commit) ───
git add -A

# ─── 5. Commit — NEW commit, NEVER --amend ───
git commit -m "Step A.1: introduce PSR-11 DI container (PHP-DI 7)"

# ─── 6. CRITICAL: drop env-injected token before using gh ───
unset GITHUB_TOKEN

# ─── 7. PR, auto-merge, branch delete ───
gh pr create \
  --title "Step A.1: introduce PSR-11 DI container" \
  --body  "Adds PHP-DI 7 as the PSR-11 implementation, four service providers, and refactors public/index.php + Application to resolve services from the container. Implements step A.1 of PHLEX_EXPANSION_PLAN.md."
gh pr merge --squash --delete-branch

# ─── 8. Return to master with merged PR pulled — REQUIRED END STATE ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION assertions (subagent reports these) ───
git status --short                          # MUST be empty
git branch --show-current                   # MUST be 'master'
git log --oneline -1                        # MUST show the new squashed commit
git branch --list 'a.1-*'                   # MUST be empty (branch was deleted)
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs the smoke commands in
`a.1-di-container-review.md` §2:

```bash
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'Container|Providers'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name '*.php' -exec php -l {} \; 2>&1 | grep -v 'No syntax errors'
```

Reviewer additionally spins up `php public/index.php` against the test
config to confirm the refactored bootstrap survives an end-to-end request.
