# Step A.7 — Plugin developer documentation

**Phase:** A (Plugin Foundation & DI)
**Step:** A.7
**Depends on:** A.6
**Review:** No (per master plan §3)
**Target repo:** detain/phlex (local: /home/sites/phlex)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Close Phase A by writing the **complete** plugin developer guide. After
A.7 lands, a new contributor can read one doc tree and ship a plugin
end-to-end: pick a type, write the manifest, implement the lifecycle
interface, subscribe to events, package, sign (optional), publish a
URL, install via the admin UI. Phase A is internally consistent and
ready for the rest of the plan to build on.

A.7 also produces `docs/dev/plugin-sdk.md` — the **server-internals**
counterpart of the developer guide, for contributors who want to
extend the loader itself.

## 2. Context (what already exists)

After A.6:

- `Phlex\Plugins\Manifest` and `ManifestType` (A.3).
- `Phlex\Plugins\PluginLoader` and `Contract\LifecycleInterface` (A.4).
- `/admin/plugins` UI (A.5).
- `phlex-plugin-example` reference repo (A.6).
- `docs/plugins/manifest.md`, `docs/plugins/manifest.schema.json`,
  `docs/plugins/developer-guide.md` (stubbed in A.3, expanded in A.4
  and A.6), `docs/plugins/install-from-url.md`,
  `docs/plugins/install-from-catalog.md`,
  `docs/plugins/trusted-plugin-list.md`.
- `docs/dev/event-reference.md` (A.2).
- `docs/dev/architecture-server.md` (A.1).

A.7's job is to take the already-existing stubs and grow them into a
coherent doc tree that an outside developer can read top-to-bottom.

## 3. Scope — files to create / modify

### Create

- `docs/dev/plugin-sdk.md` — internals reference for contributors
  modifying the loader itself. Sections:
  - "Where to look": file map of `src/Plugins/**` with one-line
    descriptions.
  - "Adding a new plugin type": walks through extending
    `ManifestType` + adding any type-specific dispatch path.
  - "Adding a new event": ties to A.2 (`Common/Events/**`) and to the
    `EventNameMap` table in A.4.
  - "Loader extension points": where to subclass / decorate
    `HttpInstaller`, `ComposerRunner`, `PluginRepository`.

### Modify (substantial expansion of A.3/A.4/A.6 stubs)

- `docs/plugins/developer-guide.md` — restructure to a polished long-form
  guide. Final ToC:
  1. **What plugins are** — one-paragraph overview and the
     eleven types table (copied from master plan §5).
  2. **Lifecycle** — install → enable → disable → uninstall, with a
     mermaid sequence diagram.
  3. **Manifest** — links into `docs/plugins/manifest.md`; reproduces
     the §5 example.
  4. **Implementing `LifecycleInterface`** — copy the interface
     contract verbatim from
     `src/Plugins/Contract/LifecycleInterface.php` (with a footnote
     about the B.1 move).
  5. **Subscribing to events** — the `subscribedEvents()` pattern;
     points readers at `docs/dev/event-reference.md`.
  6. **Settings** — manifest `settings` block, secret-vs-plain,
     defaults; notes that A.5 ships read-only settings and editable
     settings come in a later phase.
  7. **Packaging** — what files go in your repo, what `composer.json`
     needs to declare, how the per-plugin vendor dir works.
  8. **Signing** — sha256 format, generating a signature, the
     `PHLEX_PLUGINS_ALLOW_UNSIGNED` env var, the rationale for
     per-author keys + community allowlist (master plan §10
     decision 4).
  9. **Distribution** — hosting on GitHub, raw URL form, future
     in-product catalog.
  10. **Walkthrough: `phlex-plugin-example`** — already added in
      A.6; A.7 polishes it.
  11. **FAQ / troubleshooting** — common error codes, how to read
      `.logs/events.log` when `PHLEX_DEBUG_EVENTS=1`.
- `docs/plugins/install-from-url.md` — final polish: end-user-facing
  copy, screenshots (if the A.6 manual smoke captured any).
- `README.md` — promote plugin docs in the "Status" / "Docs" section:
  add a line `* Plugin developer guide:
  docs/plugins/developer-guide.md`.
- `CHANGELOG.md` — `Added: complete plugin developer documentation
  (docs/plugins/developer-guide.md, docs/dev/plugin-sdk.md). Phase A
  is now functionally complete; the plugin system is ready for
  external authors.`
- `AGENTS.md` / `CLAUDE.md` — Caliber regenerates.

### Delete

- None.

## 4. Approach

1. **Audit existing stubs.** Read every file under `docs/plugins/` and
   `docs/dev/` that earlier Phase A steps touched. Note duplication
   (e.g., the manifest example may appear in both `manifest.md` and
   `developer-guide.md` — keep the version in `manifest.md` as the
   source, link from `developer-guide.md`).
2. **Restructure `developer-guide.md`** per the ToC in §3. Each
   section ends with a "See also" link to the next-deeper doc
   (manifest.md, event-reference.md, etc.).
3. **Author `docs/dev/plugin-sdk.md`** from a contributor's mental
   model: "I want to add a new plugin type / event / installer". Each
   recipe is a short numbered list referencing concrete files and
   tests to copy.
4. **Mermaid diagram** for the lifecycle: install → enable → disable →
   uninstall, with the DB and dispatcher as participants. Use GitHub-
   flavored mermaid (renders natively on github.com).
5. **No code changes.** A.7 is doc-only. The §0.4 minimum bar still
   runs; nothing in `src/` should change, so all checks should pass
   unchanged.
6. **Cross-link audit.** Every doc page links the next; the landing
   page (`docs/plugins/developer-guide.md`) is the entry point.
   Confirm with a quick `grep` that no `docs/plugins/*.md` is orphaned.

## 5. Tests (REQUIRED — §0.4 minimum bar)

A.7 introduces no executable code. The verification bar still runs:

- `./vendor/bin/phpunit` — must remain green.
- `./vendor/bin/phpstan analyze src/ --level=9` — no NEW errors.
- `./vendor/bin/phpcs --standard=PSR12 src/` — no NEW errors.
- `find src -name '*.php' -exec php -l {} \;` — no syntax errors.

Additional doc-side verification (optional but recommended; do
**not** add as a hard gate because the markdownlint config is not
established yet):

- `npx -y markdownlint-cli2 docs/plugins/*.md docs/dev/plugin-sdk.md`
  — fix any errors it reports.
- `npx -y @mermaid-js/mermaid-cli -i docs/plugins/developer-guide.md
  -o /tmp/lifecycle.svg` — the mermaid block must render.

Coverage check skipped — A.7 adds no classes.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

This step **is** the documentation deliverable for Phase A. Matrix rows
that apply (all are the doc itself):

- **"The plugin API"** → `docs/plugins/developer-guide.md` is the
  finished version.
- **Developer docs** → `docs/dev/plugin-sdk.md` is new.
- **"Anything"** → `README.md` updated as in §3.
- **CHANGELOG** → already in §3.

PHPDoc requirements: N/A (no PHP changes).

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] `docs/plugins/developer-guide.md` contains all eleven sections
      listed in §3.
- [ ] `docs/dev/plugin-sdk.md` exists and contains the four sections
      listed in §3.
- [ ] No `docs/plugins/*.md` file is orphaned (every page is linked
      from at least one other page).
- [ ] The eleven plugin types table matches master plan §5 exactly
      (machine-checkable: `diff <(grep -E '^\| ' docs/plugins/developer-guide.md
      | grep -E 'metadata-provider|subtitle-provider|auth-provider|library-type|notifier|scrobbler|tuner|transcoder-hook|ui-theme|arr-integration|analytics-sink')
      <(grep -E '^\| ' PHLEX_EXPANSION_PLAN.md | grep -E 'metadata-provider|...')`).
- [ ] Mermaid lifecycle diagram renders.
- [ ] `./vendor/bin/phpunit` — green.
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — no new errors.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] `find src -name '*.php' -exec php -l {} \;` — no syntax errors.
- [ ] README.md "Status" or "Docs" section links the developer guide.
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
git checkout -b a.7-plugin-docs

# ─── 2. Do the work; update docs; no src/ changes ───

# ─── 3. Verify (§0.4 minimum bar) ───
./vendor/bin/phpunit
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync (hook active) ───
git add -A

# ─── 5. Commit — NEW commit, NEVER --amend ───
git commit -m "Step A.7: complete plugin developer documentation"

# ─── 6. CRITICAL: drop env-injected token before using gh ───
unset GITHUB_TOKEN

# ─── 7. PR, auto-merge, branch delete ───
gh pr create \
  --title "Step A.7: plugin developer documentation" \
  --body  "Polishes docs/plugins/developer-guide.md and adds docs/dev/plugin-sdk.md, completing Phase A. The plugin system is ready for external authors. Implements step A.7 of PHLEX_EXPANSION_PLAN.md."
gh pr merge --squash --delete-branch

# ─── 8. Return to master with merged PR pulled — REQUIRED END STATE ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION assertions (subagent reports these) ───
git status --short                          # MUST be empty
git branch --show-current                   # MUST be 'master'
git log --oneline -1                        # MUST show the new squashed commit
git branch --list 'a.7-*'                   # MUST be empty
```

## 9. Reviewer hand-off

Review = No in §3. The next phase (B.1, design `phlex-shared`) implicitly
re-reads the Phase A docs to plan the contracts that move into the
shared package; if A.7 is incomplete, B.1 will surface that.
