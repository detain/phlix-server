# Step B.5 — Scaffold `detain/phlex-hub`

**Phase:** B (Repo Split & Migration)
**Step:** B.5
**Depends on:** B.4 (transitively B.3 — needs `phlex-shared` v0.2.0
on Packagist-via-VCS)
**Review:** Yes — see `b.5-hub-scaffold-review.md`
**Target repo:** `detain/phlex-hub` (freshly cloned into
`/home/sites/phlex-hub/`).
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

> **CRITICAL — do NOT run `gh repo create`.** The repository
> `detain/phlex-hub` was pre-created **empty** on 2026-05-16. B.5
> clones the existing empty repo, scaffolds a minimal Workerman
> HTTP+WS application that requires `detain/phlex-shared`, pushes the
> first commit on master.

## 1. Goal

Stand up `detain/phlex-hub` as a real Workerman 5 application with:

- `composer.json` requiring `detain/phlex-shared:^0.2`, plus Workerman,
  Monolog, Smarty, the same PSR-11 / PSR-14 implementations the server
  uses (PHP-DI 7, Crell/Tukio).
- Namespace `Phlex\Hub\` → `src/`.
- A minimum-viable Application bootstrap copied and reduced from
  `phlex-server/src/Server/Core/Application.php`.
- Config files: `config/server.php`, `config/database.php`,
  `config/logger.php`.
- A `migrations/` directory with a placeholder `001_initial.sql`
  (real migrations land in B.6).
- A `public/index.php` entry point that starts a single Workerman
  HTTP worker on `HUB_PORT=8800` and serves `/health` returning JSON.
- A `.github/workflows/ci.yml` running the 5-check matrix
  (composer-validate, phpcs PSR-12, phpstan 2.x level 9, psalm v5,
  security audit) + phpunit.
- `README.md`, `LICENSE` (MIT), `AGENTS.md` stub, `CHANGELOG.md` with
  the 0.1.0 entry.
- First commit pushed to `master`.

After B.5, `detain/phlex-hub` has a working "hello world" hub process
that `php public/index.php` boots, plus the namespace scaffolding
B.6 and B.7 will fill in.

## 2. Context (what already exists)

- `detain/phlex-hub` on GitHub: public, empty (no commits, no
  branches). Pre-created 2026-05-16.
- `detain/phlex-shared` v0.2.0 (after B.3) — provides the
  `Phlex\Shared\Auth\JwtClaims`, `Phlex\Shared\Hub\*` DTOs the hub
  consumes.
- `/home/sites/phlex/src/Server/Core/Application.php` — reference
  for the bootstrap shape (not copied wholesale; the hub's bootstrap
  is simpler).
- `/home/sites/phlex/config/server.php`,
  `/home/sites/phlex/config/database.php`,
  `/home/sites/phlex/config/logger.php` — reference shapes.
- `/home/sites/phlex/.github/workflows/ci.yml` (if present) —
  reference for the CI workflow shape.

## 3. Scope — files to create / modify

All paths below are inside the **NEW** working directory
`/home/sites/phlex-hub/`.

### Create

- `composer.json`:
  ```json
  {
      "name": "detain/phlex-hub",
      "description": "Central cloud directory + reverse-tunnel relay for Phlex media servers. Sign in once, reach any of your servers from anywhere. Self-hostable.",
      "type": "project",
      "license": "MIT",
      "require": {
          "php": "^8.3",
          "detain/phlex-shared": "^0.2",
          "crell/tukio": "^2.0",
          "monolog/monolog": "^3.0",
          "php-di/php-di": "^7.0",
          "psr/container": "^2.0",
          "psr/event-dispatcher": "^1.0",
          "smarty/smarty": "^4.0",
          "workerman/mysql": "^1.0",
          "workerman/workerman": "^5.0"
      },
      "require-dev": {
          "mockery/mockery": "^1.6",
          "phpstan/phpstan": "^2.0",
          "phpunit/phpunit": "^10.0",
          "squizlabs/php_codesniffer": "^3.10",
          "vimeo/psalm": "^5.0"
      },
      "repositories": [
          { "type": "vcs", "url": "git@github.com:detain/phlex-shared.git" }
      ],
      "autoload": { "psr-4": { "Phlex\\Hub\\": "src/" } },
      "autoload-dev": { "psr-4": { "Phlex\\Hub\\Tests\\": "tests/" } },
      "config": { "optimize-autoloader": true, "sort-packages": true },
      "scripts": {
          "test": "phpunit",
          "stan": "phpstan analyze --no-progress",
          "cs": "phpcs --standard=PSR12 src/",
          "psalm": "psalm --no-progress",
          "start": "php public/index.php start"
      }
  }
  ```
  Note: `repositories` uses the VCS pattern from b.1-shared-design.md
  §4.7 because `phlex-shared` isn't on Packagist yet.
- `src/Application.php` — minimal Workerman bootstrap. Constructor
  takes a `ContainerInterface`. `boot()` method starts the HTTP
  worker. ~80 LoC.
- `src/Health/HealthController.php` — single method `__invoke(): array`
  returning `['status' => 'ok', 'service' => 'phlex-hub', 'version' => Version::VERSION, 'phlexShared' => \Phlex\Shared\Version::VERSION, 'timestamp' => time()]`.
- `src/Version.php` — `final class Version { public const VERSION = '0.1.0'; }` (same shape as `phlex-shared/src/Version.php`).
- `src/Common/Container/ContainerFactory.php` — minimal PHP-DI 7
  container builder. Reduced from `phlex-server`'s version.
- `src/Common/Container/Providers/CoreServicesProvider.php` —
  registers DB + logger.
- `src/Common/Database/ConnectionPool.php` — static `init()` +
  `getConnection('mysql')`. Copied from `phlex-server` (it's only
  ~50 LoC).
- `src/Common/Logger/LoggerFactory.php`,
  `src/Common/Logger/LogChannels.php`,
  `src/Common/Logger/StructuredLogger.php` — copied from
  `phlex-server`. Log channel constants: `HTTP`, `WEBSOCKET`,
  `AUTH`, `HUB`, `RELAY`. (B.7 adds `AUDIT`.)
- `src/Http/Request.php`, `src/Http/Response.php`,
  `src/Http/Router.php` — copied from `phlex-server/src/Server/Http/`.
  Each is ~100 LoC; copy-and-reduce.
- `public/index.php` — entry point. Builds the container, instantiates
  `Application`, calls `Application::boot()`. Mounts
  `GET /health` → `HealthController`.
- `config/server.php`:
  ```php
  <?php
  return [
      'host'          => getenv('HUB_HOST') ?: '0.0.0.0',
      'port'          => (int) (getenv('HUB_PORT') ?: 8800),
      'workers'       => (int) (getenv('HUB_WORKERS') ?: 2),
      'workerman_log' => getenv('HUB_WORKERMAN_LOG') ?: __DIR__ . '/../.logs/workerman.log',
  ];
  ```
- `config/database.php`:
  ```php
  <?php
  return [
      'mysql' => [
          'host'     => getenv('HUB_DB_HOST') ?: '127.0.0.1',
          'port'     => (int) (getenv('HUB_DB_PORT') ?: 3306),
          'user'     => getenv('HUB_DB_USER') ?: 'phlex_hub',
          'password' => getenv('HUB_DB_PASSWORD') ?: 'phlex_hub',
          'database' => getenv('HUB_DB_NAME') ?: 'phlex_hub',
      ],
  ];
  ```
- `config/logger.php` — same shape as `phlex-server`'s, with the new
  channels.
- `migrations/.gitkeep` — B.6 writes the real migrations.
- `migrations/001_placeholder.sql`:
  ```sql
  -- Placeholder migration. B.6 replaces this with the real 001_users.sql.
  -- This file exists so the migration runner has a non-empty directory.
  SELECT 1;
  ```
- `scripts/run-migrations.php` — placeholder ported from
  `phlex-server`. B.6 expands.
- `tests/Health/HealthControllerTest.php` — calls `__invoke()`,
  asserts the array has the expected keys.
- `tests/VersionTest.php` — asserts `Version::VERSION` is `'0.1.0'`.
- `phpunit.xml`, `phpstan.neon.dist`, `phpcs.xml.dist`, `psalm.xml` —
  same shape as `phlex-shared`'s.
- `.gitignore` — `/vendor/`, `/composer.lock` (apps DO lock — but
  the typical Workerman convention is to gitignore `composer.lock`
  in libraries and check it in for apps; since `phlex-hub` is an
  app, **check `composer.lock` IN, not gitignore**), `/.logs/`,
  `/.phpunit.cache/`, `/coverage-report/`, `/coverage.xml`,
  `/templates_c/`, `/var/`.
- `.editorconfig` — copy from `phlex-server`.
- `LICENSE` — MIT, copyright "Joe Huss / Phlex Project".
- `README.md`:
  ```markdown
  # phlex-hub

  Central cloud directory + reverse-tunnel relay for Phlex media servers.
  Sign in once, reach any of your servers from anywhere. Self-hostable.

  Status: **scaffolding (v0.1.0)**. B.6 (DB schema) and B.7 (signup/login
  MVP) land next.

  ## Quick start (dev)

  ```bash
  composer install
  php scripts/run-migrations.php       # placeholder until B.6
  php public/index.php start
  curl http://localhost:8800/health    # => {"status":"ok",...}
  ```

  ## Related repos

  - **[detain/phlex-server](https://github.com/detain/phlex-server)** — local media server.
  - **[detain/phlex-shared](https://github.com/detain/phlex-shared)** — shared interfaces, DTOs.

  License: MIT.
  ```
- `AGENTS.md` — short stub: hub conventions (PSR-12, strict types,
  PHP 8.3+, Workerman 5, MUST use `Workerman\MySQL\Connection`,
  PHP-DI 7 container, Tukio dispatcher, shared package for
  cross-repo types). Points readers at b.1-shared-design.md in
  the `phlex-server` repo for design context.
- `CHANGELOG.md`:
  ```markdown
  # Changelog

  All notable changes to `detain/phlex-hub` are documented here.

  This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

  ## [Unreleased]

  ## [0.1.0] — 2026-05-XX

  ### Added
  - Initial scaffolding: Workerman 5 HTTP application, PSR-11 container, structured logger, `/health` endpoint.
  - Composer dependency on `detain/phlex-shared:^0.2`.
  - 5-check CI workflow (composer-validate, phpcs PSR-12, phpstan 2.x level 9, psalm v5, security audit) + phpunit.
  - DB schema and migrations land in B.6. Signup/login MVP lands in B.7.
  ```
- `.github/workflows/ci.yml` — 5-check matrix + phpunit. Triggers:
  `push` to master, `pull_request`. PHP 8.3 only.

### Modify

- None — the repo is empty before B.5 starts.

### Delete

- None.

## 4. Approach

1. **Clone the existing empty repo.**
   ```bash
   cd /home/sites/
   unset GITHUB_TOKEN
   git clone git@github.com:detain/phlex-hub.git
   cd /home/sites/phlex-hub
   git checkout -b master
   ls -la                                       # MUST show only .git/
   ```
2. **Write the composer.json** with the VCS repository entry for
   `phlex-shared`.
3. **Run `composer install`.** This resolves the VCS dep against
   `detain/phlex-shared`'s v0.2.0 tag. If it fails (e.g., shared
   package not yet tagged), stop and report — B.3 didn't complete.
4. **Copy + reduce** the bootstrap files from `phlex-server`:
   - Container factory (one provider, not four).
   - Database ConnectionPool (no changes).
   - Logger factory + channels (rename channels for the hub context:
     drop `MEDIA`, `STREAMING`, `SESSION` — add `HUB`, `RELAY`).
   - HTTP Request/Response/Router (no changes).
5. **Write `src/Application.php`.** ~80 LoC. Skeleton:
   ```php
   final class Application
   {
       public function __construct(
           private readonly ContainerInterface $container,
           private readonly array $config,
       ) {}

       public function boot(): void
       {
           $worker = new \Workerman\Worker(
               sprintf('http://%s:%d', $this->config['host'], $this->config['port']),
           );
           $worker->count = $this->config['workers'];
           $worker->name  = 'phlex-hub-http';
           $worker->onMessage = function ($connection, \Workerman\Protocols\Http\Request $request) {
               $response = $this->container->get(Router::class)->dispatch(Request::fromWorkerman($request));
               $connection->send($response->toWorkermanResponse());
           };
           \Workerman\Worker::runAll();
       }
   }
   ```
6. **Write `src/Health/HealthController.php`** with the simple
   `__invoke()` per §3.
7. **Wire the route** in `public/index.php`:
   ```php
   $router->get('/health', $container->get(HealthController::class));
   $app = $container->get(Application::class);
   $app->boot();
   ```
8. **Write tests.** `HealthControllerTest::test_invoke_returns_ok` —
   calls `__invoke()`, asserts the result array. `VersionTest` —
   asserts the constant value.
9. **Run the verification bar inside `/home/sites/phlex-hub`:**
   ```bash
   composer install
   ./vendor/bin/phpunit
   ./vendor/bin/phpstan analyze --no-progress
   ./vendor/bin/phpcs --standard=PSR12 src/
   ./vendor/bin/psalm --no-progress
   composer validate --strict
   composer audit --no-dev
   find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
   ```
10. **Boot smoke.** Launch `php public/index.php start` in the
    background, wait 2s, `curl -s http://localhost:8800/health` —
    must return a JSON body with `"status":"ok"`. Kill the process.
11. **Commit + push.**
12. **Verify CI green** on the first master push.

## 5. Tests (REQUIRED — §0.4 minimum bar)

Unit tests:

1. `HealthControllerTest::test_invoke_returns_ok_status` — the
   returned array has `status === 'ok'`.
2. `HealthControllerTest::test_invoke_includes_versions` — has
   `'service'`, `'version'`, `'phlexShared'`.
3. `HealthControllerTest::test_invoke_includes_timestamp` —
   `'timestamp'` is a recent UNIX seconds value.
4. `VersionTest::test_version_is_valid_semver`.
5. `VersionTest::test_version_matches_changelog`.

Integration test:

6. `tests/integration/BootstrapTest::test_container_boots_health_controller`
   — builds the container against a fake config, resolves
   `HealthController`, calls `__invoke()`. **Skipped** if the
   container resolution requires a live DB connection (it shouldn't
   for the health controller, but document the skip if it does).

**Coverage target:** ≥ 85 % on `src/Health/`, `src/Version.php`.
The Container / HTTP / Logger / Database classes were copied from
`phlex-server` and are already well-tested there; B.5 ports the
tests too, gated at the same ≥ 85 % bar.

**Integration boundary:** Workerman HTTP worker bootstrap. The boot
smoke in §4 step 10 satisfies the §0.4 integration requirement,
documented in the review template.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply (all in the new `phlex-hub` repo):

- **"Anything"** → `README.md` is the package's landing page.
- **CHANGELOG** → `CHANGELOG.md` ships with the 0.1.0 entry.
- **Hub functionality (Phase B+)** → start a `docs/` directory with a
  `docs/dev/architecture-hub.md` placeholder pointing readers at
  b.1-shared-design.md in the server repo for the cross-repo design
  context. End-user `docs/hub/*.md` content waits for B.7.
- **"A configurable env var or `config/*.php` key"** → create
  `docs/reference/env-vars.md` listing `HUB_HOST`, `HUB_PORT`,
  `HUB_WORKERS`, `HUB_DB_HOST`, `HUB_DB_PORT`, `HUB_DB_USER`,
  `HUB_DB_PASSWORD`, `HUB_DB_NAME`, `HUB_WORKERMAN_LOG`.

PHPDoc per §0.4 on every new public class/method.

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] **No `gh repo create` was invoked.**
- [ ] `/home/sites/phlex-hub/` exists and contains every file listed
      in §3 "Create".
- [ ] `composer install` succeeds and locks
      `detain/phlex-shared` at `^0.2.0`.
- [ ] `composer validate --strict` — clean.
- [ ] `composer audit --no-dev` — no advisories.
- [ ] `./vendor/bin/phpunit` — green, no skips.
- [ ] Coverage of `src/Health/` and `src/Version.php` ≥ 85 %.
- [ ] `./vendor/bin/phpstan analyze --no-progress` at level 9 —
      `[OK] No errors`.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] `./vendor/bin/psalm --no-progress` — clean.
- [ ] `find src -name '*.php' -exec php -l {} \;` — no syntax errors.
- [ ] `php public/index.php start` boots; `curl
      http://localhost:8800/health` returns `{"status":"ok",...}`.
- [ ] PHPDoc on every new public class/method.
- [ ] `README.md`, `AGENTS.md`, `CHANGELOG.md` exist and are
      non-trivial.
- [ ] `docs/reference/env-vars.md` documents every `HUB_*` env var.
- [ ] First commit pushed to `detain/phlex-hub:master`.
- [ ] GitHub Actions CI workflow ran on the master push and all 5
      checks reported green.
- [ ] No changes were made in `/home/sites/phlex` or
      `/home/sites/phlex-shared`. `git status --short` empty in both
      (CALIBER_LEARNINGS.md OK on `phlex`).

## 8. Git ritual (copy of master plan §11.4, adapted for the new repo)

The standard ritual targets the new `phlex-hub` repo. Like B.2, this
is an initial push to an empty repo — there is no PR, push direct to
master.

```bash
# ─── 0. PRECONDITION ───
test ! -d /home/sites/phlex-hub || { echo "STOP: /home/sites/phlex-hub already exists"; exit 1; }

# ─── 1. Clone ───
cd /home/sites/
unset GITHUB_TOKEN
git clone git@github.com:detain/phlex-hub.git
cd /home/sites/phlex-hub
git checkout -b master
ls -la                                       # MUST show only .git/

# ─── 2. Do the work — write every file in §3 ───

# ─── 3. Verify (§0.4 minimum bar) ───
composer install
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'Health|Version'   # ≥ 85 %
./vendor/bin/phpstan analyze --no-progress
./vendor/bin/phpcs --standard=PSR12 src/
./vendor/bin/psalm --no-progress
composer validate --strict
composer audit --no-dev
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# Boot smoke:
php public/index.php start >/tmp/hub-smoke.log 2>&1 &
HUB_PID=$!
sleep 2
curl -s http://localhost:8800/health | grep -q '"status":"ok"'
RC=$?
kill $HUB_PID
test $RC -eq 0 || { echo "STOP: boot smoke failed"; cat /tmp/hub-smoke.log; exit 1; }

# ─── 4. Caliber not yet installed on this repo ───
git add -A

# ─── 5. Commit ───
git commit -m "Initial release v0.1.0: phlex-hub scaffolding (Workerman, container, /health)"

# ─── 6. CRITICAL ───
unset GITHUB_TOKEN

# ─── 7. Push to master ───
git push -u origin master

# ─── 8. Verify CI on the master push ───
gh run list --repo detain/phlex-hub --branch master --limit 1
gh run watch                                # MUST complete green (all 5 + phpunit)

# ─── 9. POSTCONDITION ───
cd /home/sites/phlex-hub
git status --short                          # MUST be empty
git branch --show-current                   # MUST be 'master'
git log --oneline -1                        # MUST show the initial commit
gh run list --repo detain/phlex-hub --branch master --limit 1 --json conclusion | grep '"conclusion":"success"'

# Also verify NO impact on /home/sites/phlex or /home/sites/phlex-shared:
cd /home/sites/phlex
git status --short                          # SHOULD match pre-B.5 state
cd /home/sites/phlex-shared 2>/dev/null && git status --short
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs `b.5-hub-scaffold-review.md`. The
reviewer additionally confirms `gh repo view detain/phlex-hub --json
defaultBranchRef,isEmpty` shows the new master and non-empty state.
