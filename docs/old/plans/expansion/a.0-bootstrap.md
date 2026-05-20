# Step A.0 — Bootstrap expansion plans directory

**Phase:** A (Plugin Foundation & DI)
**Step:** A.0
**Depends on:** —
**Review:** No
**Target repo:** detain/phlex (local: /home/sites/phlex)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

> **Note:** This file is the retrospective record of Step A.0. The work it
> describes — creating `plans/expansion/` and authoring step files A.1–A.7 plus
> the five review templates — was executed when this file (and its twelve
> sibling files) were committed. A reviewer who wants to verify A.0 simply
> checks that the thirteen files listed in §3 exist on `master`.

## 1. Goal

Bring `plans/expansion/` into existence and seed it with every per-step plan
file the Phase A subagents will read. Without these files there is no
machine-readable contract between the supervisor (which only reads
`PHLEX_EXPANSION_PLAN.md` §3) and the implementation subagents (which only
read their per-step plan file). A.0 is the meta-step that closes that gap.

## 2. Context (what already exists)

- `/home/sites/phlex/PHLEX_EXPANSION_PLAN.md` — master plan, the source of truth.
  Read §0.2 critical rules, §0.4 testing+docs minimum bar, §3 Phase A rows,
  §4.1 phlex-server additions, §5 plugin system, §11.3 subagent template,
  §11.4 git ritual, §11.5 review template, §11.6 parallelism rules.
- `/home/sites/phlex/CLAUDE.md`, `/home/sites/phlex/AGENTS.md` — project
  conventions (PSR-12, strict types, Workerman MySQL only, structured logger,
  AuditLogger, Caliber hook).
- `/home/sites/phlex/composer.json` — PHP `>=8.1`, Workerman 5, Monolog 3,
  PHPUnit 10, Mockery 1.6. No PSR-11 / PSR-14 packages yet.
- `/home/sites/phlex/src/Server/Core/Application.php`, `/home/sites/phlex/public/index.php`
  — current hardcoded bootstrap that A.1 will refactor into a container.
- `/home/sites/phlex/plans/phase-{1..7}/` — existing per-step plan style;
  the expansion plans use a tighter template defined in this file.

## 3. Scope — files to create / modify

### Create

- `plans/expansion/a.0-bootstrap.md` — this file.
- `plans/expansion/a.1-di-container.md` — DI container step plan.
- `plans/expansion/a.1-di-container-review.md` — review template for A.1.
- `plans/expansion/a.2-event-dispatcher.md` — PSR-14 dispatcher step plan.
- `plans/expansion/a.2-event-dispatcher-review.md` — review template for A.2.
- `plans/expansion/a.3-plugin-manifest.md` — `plugin.json` schema step plan.
- `plans/expansion/a.3-plugin-manifest-review.md` — review template for A.3.
- `plans/expansion/a.4-plugin-loader.md` — plugin lifecycle step plan.
- `plans/expansion/a.4-plugin-loader-review.md` — review template for A.4.
- `plans/expansion/a.5-plugin-admin-ui.md` — admin UI + JSON API step plan.
- `plans/expansion/a.5-plugin-admin-ui-review.md` — review template for A.5.
- `plans/expansion/a.6-sample-plugin.md` — `phlex-plugin-example` step plan.
- `plans/expansion/a.7-plugin-docs.md` — plugin developer docs step plan.

### Modify

- None. A.0 is a plans-only step.

### Delete

- None.

## 4. Approach

The subagent that owns A.0:

1. Re-reads §3 of `PHLEX_EXPANSION_PLAN.md` to confirm the eight Phase A rows
   are unchanged from when this plan was authored. If the master plan has
   been re-drafted, stops and reports.
2. Creates `plans/expansion/` (the directory does not exist at A.0 start).
3. Writes the thirteen files listed in §3, in the structure described in the
   supervisor prompt for A.0 (Goal · Context · Scope · Approach · Tests · Docs
   · Acceptance · Git ritual · Reviewer hand-off when Review = Yes).
4. Per-step content specifics for A.1 through A.7 follow the supervisor's
   own briefing — concrete composer packages named with rationale, concrete
   classes named, concrete test cases listed, concrete doc files named.
5. Runs the §0.4 verification bar (phpunit / phpstan / phpcs / php -l) even
   though no `src/` files are touched, to prove A.0 has not regressed the
   tree.
6. Commits, opens a PR via `gh`, squash-merges, returns to clean master.

## 5. Tests (REQUIRED — §0.4 minimum bar)

A.0 introduces no executable code, so no new PHPUnit tests are added.
However, the full verification bar still runs:

- `./vendor/bin/phpunit` — must remain green (no regressions).
- `./vendor/bin/phpstan analyze src/ --level=9` — must report no new errors
  vs. master. (Pre-existing errors in the tree are noted in the final report
  but do not block A.0.)
- `./vendor/bin/phpcs --standard=PSR12 src/` — same.
- `find src -name '*.php' -exec php -l {} \;` — must report no syntax errors.

Coverage check is skipped — A.0 adds no classes to cover.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Per §0.4, the matrix rows that apply to A.0:

- **"Anything"** row: update the repo `README.md` "Status" / feature list — N/A,
  A.0 is invisible to end users.
- **"User-visible behavior change"** row: not applicable; add **no**
  `CHANGELOG.md` line for A.0 since the change is purely scaffolding for the
  next eight steps. A.1 is the first step that adds a user-visible entry
  (a CHANGELOG line `Added: PSR-11 dependency injection container`).

PHPDoc requirements do not apply — no PHP files are added or modified.

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] `plans/expansion/` exists.
- [ ] All thirteen files listed in §3 exist with non-trivial content
      (≥ 100 lines each; review templates may be shorter).
- [ ] Each step file embeds the §11.4 git ritual verbatim with the step's
      slug substituted.
- [ ] Each step file with `Review = Yes` in §3 of the master plan references
      its review template by name.
- [ ] `./vendor/bin/phpunit` — green, no skips.
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — no NEW errors vs. master
      (note pre-existing errors in the final report; do not fix them in A.0).
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — no NEW errors vs. master.
- [ ] `find src -name '*.php' -exec php -l {} \;` — no syntax errors.
- [ ] Caliber pre-commit hook is verified active (`grep -q "caliber"
      .git/hooks/pre-commit`); the hook synced agent configs during the
      commit.
- [ ] Git ritual §8 executed; postcondition checks all PASS.

## 8. Git ritual (copy of master plan §11.4)

```bash
# ─── 0. PRECONDITION: confirm we're starting from clean master ───
cd /home/sites/phlex
git status --short                          # MUST be empty; if not, stop and report
git branch --show-current                   # MUST be 'master'; if not, stop and report
git pull --ff-only origin master

# ─── 1. Branch ───
git checkout -b a.0-bootstrap

# ─── 2. Do the work; add tests; update docs (§0.4); add PHPDocs ───
# (write plans/expansion/a.0 through plans/expansion/a.7 + 5 review templates)

# ─── 3. Verify (§0.4 minimum bar) ───
./vendor/bin/phpunit                                   # green, no skips
./vendor/bin/phpunit --coverage-text                   # N/A — A.0 adds no classes
./vendor/bin/phpstan analyze src/ --level=9            # zero NEW errors vs. master
./vendor/bin/phpcs --standard=PSR12 src/               # zero NEW errors vs. master
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync (hook is installed at /home/sites/phlex — it runs on commit) ───
git add -A

# ─── 5. Commit — NEW commit, NEVER --amend ───
git commit -m "Step A.0: bootstrap plans/expansion/ with Phase A step files"

# ─── 6. CRITICAL: drop env-injected token before using gh ───
unset GITHUB_TOKEN

# ─── 7. PR, auto-merge, branch delete ───
gh pr create \
  --title "Step A.0: bootstrap expansion plans directory" \
  --body  "Creates plans/expansion/ and writes step files a.0 through a.7 plus review templates for a.1 through a.5. Implements step A.0 of PHLEX_EXPANSION_PLAN.md."
gh pr merge --squash --delete-branch

# ─── 8. Return to master with merged PR pulled — REQUIRED END STATE ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION assertions (subagent reports these) ───
git status --short                          # MUST be empty
git branch --show-current                   # MUST be 'master'
git log --oneline -1                        # MUST show the new squashed commit
git branch --list 'a.0-*'                   # MUST be empty (branch was deleted)
```

## 9. Reviewer hand-off

Review = No in §3. No review template is paired with A.0; the reviewer for A.1
is the first to read this directory and implicitly confirms A.0 by being able
to read `a.1-di-container.md`.
