# Step A.6 — Sample plugin (`phlex-plugin-example`)

**Phase:** A (Plugin Foundation & DI)
**Step:** A.6
**Depends on:** A.5
**Review:** No (per master plan §3 — A.6 has `Review = No`)
**Target repo:** **NEW** `detain/phlex-plugin-example` (local:
`/home/sites/phlex-plugin-example/`) plus an install smoke into
`/home/sites/phlex` (the server).
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Prove the Phase A foundation works end-to-end by shipping a real
community-shaped plugin: `phlex-plugin-example`. It's a
`metadata-provider` that, when asked for a movie at a known fixture
path, returns `['title' => 'Hello, World']`. The plugin is published as
its own GitHub repo so plugin authors have a working template to copy.

Acceptance is that the plugin can be installed into the local Phlex
server via the A.5 admin UI's "Install from URL" form, enabled, and the
fixture metadata appears.

## 2. Context (what already exists)

After A.5:

- `Phlex\Plugins\PluginLoader` accepts URLs and local directories.
- `Phlex\Plugins\Contract\LifecycleInterface` (temporary home —
  scheduled to move to `phlex-shared` in B.1).
- The admin UI at `/admin/plugins`.
- `Phlex\Media\Metadata\MetadataProviderInterface` — the contract a
  metadata-provider plugin needs to implement.
- The fixture plugin at `tests/Fixtures/Plugins/fixture-plugin/` —
  A.6's `phlex-plugin-example` is **production-shaped** version of that
  test fixture.

## 3. Scope — files to create / modify

### Create — in the NEW external repo `/home/sites/phlex-plugin-example/`

- `composer.json`:
  ```json
  {
    "name": "phlex/plugin-example",
    "type": "phlex-plugin",
    "description": "Hello-world metadata provider — reference plugin for Phlex authors.",
    "license": "MIT",
    "require": {
      "php": ">=8.1"
    },
    "autoload": {
      "psr-4": {
        "Phlex\\PluginExample\\": "src/"
      }
    }
  }
  ```
- `plugin.json`:
  ```json
  {
    "name": "phlex-plugin-example",
    "version": "0.1.0",
    "phlex_min_server_version": "0.10.0",
    "type": "metadata-provider",
    "entry": "Phlex\\PluginExample\\HelloMetadataProvider",
    "events": [],
    "settings": {},
    "signature": null
  }
  ```
- `src/HelloMetadataProvider.php` — implements
  `Phlex\Plugins\Contract\LifecycleInterface` AND
  `Phlex\Media\Metadata\MetadataProviderInterface`. The
  `fetchMovieMetadata($path)` method returns
  `['title' => 'Hello, World', 'provider' => 'phlex-plugin-example']`
  when `$path` matches the fixture path; null otherwise.
- `tests/HelloMetadataProviderTest.php` — PHPUnit 10 smoke test.
- `README.md` — short:
  - What it does
  - How to install (point at server's `/admin/plugins`)
  - How to fork as a starter for your own plugin
  - Link back to `docs/plugins/developer-guide.md` in `detain/phlex`.
- `LICENSE` — MIT.
- `.gitignore` — `/vendor/`, `/composer.lock` (libraries don't lock).
- `.github/workflows/test.yml` — runs `composer install && vendor/bin/phpunit`
  on PRs.

### Create — in the EXISTING server repo `/home/sites/phlex/`

- `tests/Integration/Plugins/SamplePluginSmokeTest.php` — installs
  `/home/sites/phlex-plugin-example/` via
  `PluginLoader::installFromDirectory()`, enables it, calls
  `MetadataManager::fetchMovieMetadata($fixturePath)`, asserts the
  returned `title` is `Hello, World`. Skipped if
  `/home/sites/phlex-plugin-example/` does not exist (CI without the
  sibling checkout).

### Modify — server repo

- `CHANGELOG.md` — `Added: reference plugin phlex-plugin-example,
  hosted at github.com/detain/phlex-plugin-example. Installs via
  /admin/plugins. Demonstrates the metadata-provider plugin type.`
- `docs/plugins/developer-guide.md` — append a "Walkthrough: the
  example plugin" section that points readers at the new repo.

### Delete

- None.

## 4. Approach

> **CRITICAL — irreversible action.** This step creates a public GitHub
> repository (`gh repo create detain/phlex-plugin-example --public`).
> Creating a public repo is **not reversible** by the subagent (deleting
> a repo is a manual operator step in GitHub Settings). Before running
> `gh repo create`, the subagent **MUST**:
>
> 1. Print the planned command to its session output.
> 2. Pause and report back to the supervisor: "**CONFIRM REQUIRED** —
>    about to create public repo `detain/phlex-plugin-example`. Reply
>    `proceed` to continue or `abort` to stop."
> 3. Only proceed after explicit operator confirmation.
>
> The subagent does this regardless of whether the loop runs
> unattended; the supervisor is responsible for confirming. Without
> confirmation the subagent stops short of `gh repo create` and reports
> the blocker — files in `/home/sites/phlex-plugin-example/` may still
> be written locally (idempotent), but no push happens.

Sequence (assuming confirmation):

1. **Local scaffold first.** Create
   `/home/sites/phlex-plugin-example/` and write every file listed in
   the external-repo section. Run `composer install` to confirm it
   resolves cleanly. Run `vendor/bin/phpunit` to confirm the local
   smoke test passes.
2. **Pause for operator confirmation per the CRITICAL block above.**
3. **Create the GitHub repo.**
   ```bash
   cd /home/sites/phlex-plugin-example
   git init -b master
   git add -A
   git commit -m "Initial commit: phlex-plugin-example v0.1.0"
   unset GITHUB_TOKEN
   gh repo create detain/phlex-plugin-example --public \
       --description "Hello-world metadata provider — reference plugin for Phlex authors." \
       --source . --push
   gh repo edit detain/phlex-plugin-example \
       --add-topic phlex \
       --add-topic phlex-plugin \
       --add-topic metadata-provider \
       --add-topic media-server \
       --add-topic self-hosted
   ```
4. **Server-side smoke.** Back in `/home/sites/phlex`, run
   `tests/Integration/Plugins/SamplePluginSmokeTest.php` against the
   local checkout (the test points at the sibling dir). If the suite
   is green, proceed.
5. **Manual UI smoke.** Optional but recommended: actually browse to
   `/admin/plugins` in a dev server, paste the URL
   `https://raw.githubusercontent.com/detain/phlex-plugin-example/master/plugin.json`,
   confirm install/enable works in the browser. Capture a screenshot
   for `docs/plugins/install-from-url.md` (annotated; redact session
   tokens).
6. **CHANGELOG + dev-guide update on the server.**
7. **Server-side commit + PR + merge — the standard ritual in §8.**
   The external-repo work has already been pushed in step 3 and is
   not part of the server PR.

## 5. Tests (REQUIRED — §0.4 minimum bar)

Server-side:

1. `SamplePluginSmokeTest::test_install_from_local_directory_succeeds`
   — `PluginLoader::installFromDirectory('/home/sites/phlex-plugin-example')`
   returns the expected manifest; assertion includes `name`,
   `version`, `type === ManifestType::MetadataProvider`.
2. `SamplePluginSmokeTest::test_enable_subscribes_no_events_for_metadata_only_plugin`
   — confirms an empty `events` array doesn't crash the loader.
3. `SamplePluginSmokeTest::test_metadata_manager_returns_hello_world_for_fixture_path`
   — drives the `MetadataManager` flow end-to-end (skips if the
   sibling repo isn't checked out).

Plugin-side (in the external repo):

4. `HelloMetadataProviderTest::test_returns_hello_world_for_known_fixture_path`.
5. `HelloMetadataProviderTest::test_returns_null_for_unknown_path`.

**Coverage target:** ≥ 85 % on
`/home/sites/phlex-plugin-example/src/**` (run from inside that repo)
and on the new `SamplePluginSmokeTest` file (which is trivially 100 %
covered by being a test file itself — coverage gate applies to
`src/`, not `tests/`).

**Integration boundary:** the smoke test exercises the loader against a
real on-disk plugin and the MetadataManager — both already covered by
earlier-step tests, so A.6's contribution is the end-to-end
composition.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **"The plugin API"** → `docs/plugins/developer-guide.md` gets a
  "Walkthrough: the example plugin" section. Plugin-side
  `README.md` mirrors the same content for plugin readers.
- **End-user docs** → if the manual UI smoke was performed, add the
  captured screenshots to `docs/plugins/install-from-url.md` (created
  in A.5; A.6 enriches it).
- **"Anything"** → `README.md` Status: `* Reference plugin
  phlex-plugin-example — first community-shaped Phlex plugin.`
- **CHANGELOG** → already in §3 Modify.

PHPDoc per §0.4 on every public class/method in
`HelloMetadataProvider`. The class docblock cites the plugin developer
guide as the canonical reference.

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] Operator confirmation was obtained before
      `gh repo create detain/phlex-plugin-example` ran (or the step
      stopped short of repo creation and reported the blocker).
- [ ] `/home/sites/phlex-plugin-example/` exists locally and matches
      §3 "Create — external repo" file list.
- [ ] If the repo was created, `gh repo view detain/phlex-plugin-example`
      reports a public repo with the expected description and topics.
- [ ] Server-side `SamplePluginSmokeTest` passes (or is documented as
      skipped because the sibling repo isn't checked out in CI).
- [ ] Plugin-side `vendor/bin/phpunit` passes.
- [ ] Server-side `./vendor/bin/phpunit` — green.
- [ ] Server-side `./vendor/bin/phpstan analyze src/ --level=9` — zero
      new errors.
- [ ] Server-side `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] Server-side `find src -name '*.php' -exec php -l {} \;` — no
      syntax errors.
- [ ] PHPDoc on every public class/method in
      `phlex-plugin-example/src/`.
- [ ] CHANGELOG.md updated in the server repo.
- [ ] `docs/plugins/developer-guide.md` updated.
- [ ] Caliber pre-commit hook ran on the server-side commit.
- [ ] Git ritual §8 below executed; postcondition checks PASS.

## 8. Git ritual (copy of master plan §11.4, server side)

```bash
# ─── 0. PRECONDITION: confirm we're starting from clean master ───
cd /home/sites/phlex
git status --short                          # MUST be empty
git branch --show-current                   # MUST be 'master'
git pull --ff-only origin master

# ─── 1. Branch ───
git checkout -b a.6-sample-plugin

# ─── 2. Do the work; add tests; update docs (§0.4); add PHPDocs ───
# (work in /home/sites/phlex-plugin-example FIRST per §4, request
#  operator confirmation, then return here for the server-side changes)

# ─── 3. Verify (§0.4 minimum bar) ───
./vendor/bin/phpunit
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync (hook active) ───
git add -A

# ─── 5. Commit — NEW commit, NEVER --amend ───
git commit -m "Step A.6: add sample plugin smoke test and dev-guide walkthrough"

# ─── 6. CRITICAL: drop env-injected token before using gh ───
unset GITHUB_TOKEN

# ─── 7. PR, auto-merge, branch delete ───
gh pr create \
  --title "Step A.6: reference plugin phlex-plugin-example" \
  --body  "Adds the end-to-end sample plugin smoke test and docs/plugins/developer-guide.md walkthrough. The plugin itself lives at detain/phlex-plugin-example. Implements step A.6 of PHLEX_EXPANSION_PLAN.md."
gh pr merge --squash --delete-branch

# ─── 8. Return to master with merged PR pulled — REQUIRED END STATE ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION assertions (subagent reports these) ───
git status --short                          # MUST be empty
git branch --show-current                   # MUST be 'master'
git log --oneline -1                        # MUST show the new squashed commit
git branch --list 'a.6-*'                   # MUST be empty
```

## 9. Reviewer hand-off

Review = No in §3 of the master plan. There is no review template paired
with A.6. The next step (A.7) implicitly verifies A.6 by referencing it
in the developer guide.

If the operator-confirmation gate was hit and not granted, A.6 ends
with the local files written under `/home/sites/phlex-plugin-example/`
but **no** external GitHub repo and **no** server-side PR. The
supervisor decides whether to abort the step entirely or to re-run with
confirmation.
