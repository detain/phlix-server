# Step B.2 — Scaffold `detain/phlex-shared` v0.1.0

**Phase:** B (Repo Split & Migration)
**Step:** B.2
**Depends on:** B.1
**Review:** Yes — see `b.2-shared-create-review.md`
**Target repo:** `detain/phlex-shared` (freshly cloned into
`/home/sites/phlex-shared/` — see §4 step 1). NOT the local
`/home/sites/phlex` working directory.
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

> **CRITICAL — do NOT run `gh repo create`.** The repository
> `detain/phlex-shared` was pre-created **empty** on 2026-05-17. B.2
> clones the existing empty repo, pushes the initial commit, and tags
> `v0.1.0`. Running `gh repo create` will error with "name already
> exists" and is otherwise a no-op — but if the harness's GITHUB_TOKEN
> has elevated permissions it could disrupt existing metadata. Just
> clone and push.

## 1. Goal

Stand up `detain/phlex-shared` as a real Composer package on disk with:

- A working `composer.json` that resolves cleanly (`composer install`
  produces zero errors).
- A namespace skeleton (`Phlex\Shared\` PSR-4 → `src/`).
- One real class: `Phlex\Shared\Version` (single `public const VERSION
  = '0.1.0'`), as a placeholder so the package is non-empty and the CI
  pipeline has something to compile and test.
- The 5-check CI workflow (`.github/workflows/ci.yml`): composer-validate,
  phpcs PSR-12, phpstan 2.x level 9, psalm v5, security audit. PHPUnit
  also wired but with the single `VersionTest`.
- Pushed first commit on `master`, tagged `v0.1.0`.

After B.2, the empty `detain/phlex-shared` repo on GitHub holds a
package that consumers **could** require via Composer (with a VCS
repository entry). Real interfaces and DTOs do NOT land in B.2 — they
land in B.3 against the design in `b.1-shared-design.md`.

## 2. Context (what already exists)

- `detain/phlex-shared` on GitHub — public, empty (no commits, no
  branches, no README). Pre-created 2026-05-17.
- `/home/sites/phlex/plans/expansion/b.1-shared-design.md` — the
  canonical design. §4.2 (package layout), §4.3 (CI), §4.6 (v0.1.0 vs.
  v0.2.0 split).
- `/home/sites/phlex/composer.json` — reference for the dev-dep
  versions to mirror (phpstan ^2.0, phpunit ^10.0, phpcs ^3.10,
  vimeo/psalm ^5.0).
- `/home/sites/phlex/.github/workflows/` (if present) — reference for
  the CI workflow shape (5 jobs, same as `phlex`).

## 3. Scope — files to create / modify

All paths below are inside the **NEW** working directory
`/home/sites/phlex-shared/` (the cloned empty repo).

### Create

- `composer.json` — final shape:
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
      "autoload": { "psr-4": { "Phlex\\Shared\\": "src/" } },
      "autoload-dev": { "psr-4": { "Phlex\\Shared\\Tests\\": "tests/" } },
      "minimum-stability": "stable",
      "config": { "optimize-autoloader": true, "sort-packages": true },
      "scripts": {
          "test": "phpunit",
          "stan": "phpstan analyze --no-progress",
          "cs": "phpcs --standard=PSR12 src/",
          "psalm": "psalm --no-progress"
      }
  }
  ```
- `src/Version.php` — see §4 step 4 for full content.
- `tests/VersionTest.php` — see §5.
- `phpunit.xml` — bootstrap `vendor/autoload.php`, test suite
  `tests/`, coverage `src/`.
- `phpstan.neon.dist` — level 9, paths `src/` + `tests/`,
  bootstrapFiles `vendor/autoload.php`. **No baseline** — green from
  day 1.
- `phpcs.xml.dist` — PSR-12 standard, paths `src/`.
- `psalm.xml` — errorLevel 1, paths `src/` (+ skip `tests/` until B.3
  bulks up tests).
- `.gitignore` — `/vendor/`, `/composer.lock` (the typical
  library-not-app pattern), `/.phpunit.cache/`, `/coverage-report/`,
  `/coverage.xml`, `/build/`.
- `.editorconfig` — copy from `phlex`.
- `LICENSE` — MIT, copyright "Joe Huss / Phlex Project".
- `README.md` — short:
  - One-paragraph description.
  - "Status: v0.1.0 — scaffolding. v0.2.0 (Step B.3 of
    PHLEX_EXPANSION_PLAN) ships the real interfaces and DTOs."
  - Install via Composer (with VCS repository snippet from
    b.1-shared-design.md §4.7).
  - PHP 8.3+ requirement.
  - Link back to `detain/phlex-server` and `detain/phlex-hub` once
    those exist.
  - License: MIT.
- `AGENTS.md` — short stub: package conventions (PSR-12, strict types,
  PHP 8.3+, zero I/O, zero Workerman dependency, framework-neutral
  PSRs only). Points readers at b.1-shared-design.md in the `phlex`
  repo for the layout rationale.
- `CHANGELOG.md`:
  ```markdown
  # Changelog

  All notable changes to `detain/phlex-shared` are documented here.

  This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

  ## [Unreleased]

  ## [0.1.0] — 2026-05-XX

  ### Added
  - Initial release: composer package scaffolding, `Phlex\Shared\Version` marker class, CI workflow.
  - Real interfaces and DTOs land in v0.2.0 per `plans/expansion/b.1-shared-design.md` in `detain/phlex`.
  ```
- `.github/workflows/ci.yml` — see §4 step 5 for full content.

### Modify

- None — the repo is empty before B.2 starts.

### Delete

- None — the repo is empty.

## 4. Approach

1. **Clone the existing empty repo** into `/home/sites/phlex-shared/`.
   The repo has no branches yet, so `git clone` will warn but succeed.
   ```bash
   cd /home/sites/
   unset GITHUB_TOKEN   # used for clone authentication via SSH; the harness token isn't needed
   git clone git@github.com:detain/phlex-shared.git
   cd /home/sites/phlex-shared
   git checkout -b master   # repo has no default branch yet
   ```
2. **Confirm the repo is truly empty.** `ls -la` should show only
   `.git/`. If anything else is there, stop and report — someone has
   already started B.2 (or pre-populated the repo).
3. **Write all files** listed in §3 "Create". Use the exact text from
   b.1-shared-design.md §4.2 for `composer.json`.
4. **Write `src/Version.php`** with this content:
   ```php
   <?php

   declare(strict_types=1);

   namespace Phlex\Shared;

   /**
    * Compile-time-constant package version marker.
    *
    * This class exists so `phlex-shared` v0.1.0 has a non-empty src/
    * tree — every CI tool, PHPStan, Psalm, and PHPUnit needs at least
    * one source file to chew on. Real interfaces and DTOs land in
    * v0.2.0 (Step B.3 of PHLEX_EXPANSION_PLAN.md).
    *
    * Keep this in sync with the git tag and the CHANGELOG entry.
    *
    * @package Phlex\Shared
    * @since 0.1.0
    */
   final class Version
   {
       /**
        * Current package version (semver).
        *
        * @var non-empty-string
        */
       public const VERSION = '0.1.0';

       /** Prevent instantiation — static marker only. */
       private function __construct()
       {
       }
   }
   ```
5. **Write `.github/workflows/ci.yml`** with the 5-check matrix.
   Triggers: `push` to master, `pull_request`. PHP 8.3 only. Jobs:
   `composer-validate`, `phpcs`, `phpstan`, `psalm`, `phpunit`,
   `composer-audit`. Each job runs `composer install --no-dev=false`
   except `composer-audit` which uses `--no-dev`.
6. **Resolve composer.** Run `composer install` locally. Verify it
   succeeds with zero advisories.
7. **Run the local verification bar** before pushing:
   ```bash
   ./vendor/bin/phpunit
   ./vendor/bin/phpstan analyze --no-progress
   ./vendor/bin/phpcs --standard=PSR12 src/
   ./vendor/bin/psalm --no-progress
   find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
   composer validate --strict
   composer audit --no-dev
   ```
   Each must succeed. If any fail, stop and report.
8. **Commit + push + tag.**
   ```bash
   git add -A
   git commit -m "Initial release v0.1.0: phlex-shared package scaffolding"
   git push -u origin master
   git tag -a v0.1.0 -m "v0.1.0 — initial scaffolding"
   git push origin v0.1.0
   ```
9. **Verify CI on the first PR.** Since this is the initial commit on
   master, there is no PR to open — push directly. Wait for the CI
   workflow to run on the master push and confirm all 5 checks pass.
   Use `gh run watch` (after `unset GITHUB_TOKEN`).
10. **Server-side: zero changes.** B.2 does NOT touch `/home/sites/phlex`.
    There is no PR against `detain/phlex` for B.2. The git ritual
    below is **against the new `phlex-shared` repo**, not against
    `phlex`.

## 5. Tests (REQUIRED — §0.4 minimum bar)

In the new `phlex-shared` repo:

1. `Phlex\Shared\Tests\VersionTest::test_version_is_valid_semver` — uses
   `preg_match('/^\d+\.\d+\.\d+(-[a-z0-9\.\-]+)?$/', Version::VERSION)`.
2. `Phlex\Shared\Tests\VersionTest::test_version_matches_composer_json`
   — reads `composer.json`, asserts the `extra.branch-alias` (if any)
   or the README's stated version line matches `Version::VERSION`.
   (Composer doesn't store the version inside its own `composer.json`
   for library packages — `composer info` reads from the git tag — so
   this test cross-checks the `CHANGELOG.md` heading instead.)
3. `Phlex\Shared\Tests\VersionTest::test_constructor_is_private` — uses
   reflection to assert the constructor is `private`, preventing
   instantiation.

**Coverage target:** ≥ 85 % on `src/Version.php`. Trivially achieved
with the three tests above (the single class is fully exercised).

**Integration boundary:** none — `phlex-shared` is interface-only. The
integration step happens in B.3 when `phlex` requires this package.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply (note: all docs live in the new `phlex-shared`
repo, not in `phlex`):

- **"Anything"** → `README.md` in `phlex-shared` is the package's
  landing page; it states "Status: v0.1.0 — scaffolding".
- **CHANGELOG** → `CHANGELOG.md` in `phlex-shared` ships with the 0.1.0
  entry per §3.
- **Developer docs** → N/A in B.2 — there's no public API to document
  yet beyond `Phlex\Shared\Version`. Developer docs land in B.3.

PHPDoc per §0.4 on every public class/method. `Version` has a class
docblock with `@package` and `@since`; the `VERSION` const carries a
short comment; the constructor has a one-line `@internal`.

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] **No `gh repo create` was invoked.** The pre-existing empty repo
      was cloned.
- [ ] `/home/sites/phlex-shared/` exists and contains every file
      listed in §3 "Create".
- [ ] `composer install` in `/home/sites/phlex-shared/` succeeds with
      zero advisories.
- [ ] `./vendor/bin/phpunit` — green, no skips.
- [ ] `./vendor/bin/phpunit --coverage-text` — `src/Version.php` ≥ 85 %.
- [ ] `./vendor/bin/phpstan analyze --no-progress` — `[OK] No errors`.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] `./vendor/bin/psalm --no-progress` — clean.
- [ ] `composer validate --strict` — clean.
- [ ] `composer audit --no-dev` — no advisories.
- [ ] `find src -name '*.php' -exec php -l {} \;` — no syntax errors.
- [ ] First commit pushed to `detain/phlex-shared:master`.
- [ ] Tag `v0.1.0` pushed.
- [ ] GitHub Actions CI workflow ran on the master push and all 5
      checks reported green (`gh run list --limit 1`).
- [ ] No changes were made in `/home/sites/phlex/`. `git status` in
      `/home/sites/phlex` shows the pre-existing CALIBER_LEARNINGS.md
      diff if any, otherwise empty.

## 8. Git ritual (copy of master plan §11.4, adapted for the new repo)

The standard ritual targets the new `phlex-shared` repo, **not**
`phlex`. There is no PR — this is the initial push to an empty repo,
so push direct to master and tag.

```bash
# ─── 0. PRECONDITION: confirm /home/sites/phlex-shared does not yet exist locally ───
test ! -d /home/sites/phlex-shared || { echo "STOP: /home/sites/phlex-shared already exists"; exit 1; }

# ─── 1. Clone the empty repo ───
cd /home/sites/
unset GITHUB_TOKEN                          # let SSH handle auth
git clone git@github.com:detain/phlex-shared.git
cd /home/sites/phlex-shared
git checkout -b master                      # repo has no default branch yet
ls -la                                       # MUST show only .git/

# ─── 2. Do the work — write every file in §3 ───
# (implementation per §4)

# ─── 3. Verify (§0.4 minimum bar, run inside /home/sites/phlex-shared) ───
composer install
./vendor/bin/phpunit
./vendor/bin/phpstan analyze --no-progress
./vendor/bin/phpcs --standard=PSR12 src/
./vendor/bin/psalm --no-progress
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
composer validate --strict
composer audit --no-dev

# ─── 4. Caliber sync — N/A — the new repo doesn't have a Caliber hook yet ───
# (a future plan step may install Caliber on phlex-shared; not B.2's job)
git add -A

# ─── 5. Commit — NEW commit, NEVER --amend ───
git commit -m "Initial release v0.1.0: phlex-shared package scaffolding"

# ─── 6. CRITICAL: drop env-injected token before using gh ───
unset GITHUB_TOKEN

# ─── 7. Push to master + tag ───
git push -u origin master
git tag -a v0.1.0 -m "v0.1.0 — initial scaffolding"
git push origin v0.1.0

# ─── 8. Verify CI on the master push ───
gh run list --limit 1                       # MUST show the new run
gh run watch                                # MUST complete green (all 5 checks)

# ─── 9. POSTCONDITION assertions (subagent reports these) ───
cd /home/sites/phlex-shared
git status --short                          # MUST be empty
git branch --show-current                   # MUST be 'master'
git log --oneline -1                        # MUST show the new initial commit
git tag -l 'v0.1.0'                         # MUST list v0.1.0
gh run list --branch master --limit 1 --json conclusion | grep -q '"conclusion":"success"'
# MUST exit 0 — last CI run was successful

# Also verify NO impact on /home/sites/phlex:
cd /home/sites/phlex
git status --short                          # SHOULD match pre-B.2 state (CALIBER_LEARNINGS.md diff at most)
git branch --show-current                   # MUST be 'master'
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs `b.2-shared-create-review.md`. The
reviewer additionally confirms via `gh repo view detain/phlex-shared
--json defaultBranchRef,isEmpty,pushedAt` that the repo is no longer
empty and that `master` is the default branch.
