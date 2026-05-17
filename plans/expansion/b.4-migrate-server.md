# Step B.4 — Migrate `phlex` code to `detain/phlex-server`

**Phase:** B (Repo Split & Migration)
**Step:** B.4
**Depends on:** B.3
**Review:** Yes — see `b.4-migrate-server-review.md`
**Target repo:** `detain/phlex` (origin BEFORE this step) →
`detain/phlex-server` (origin AFTER this step). Local working
directory stays `/home/sites/phlex` per master plan §2 ("Local dir
naming").
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

> **CRITICAL — do NOT run `gh repo create`.** The repository
> `detain/phlex-server` was pre-created **empty** on 2026-05-16. B.4
> repoints the existing `/home/sites/phlex` working directory's
> `origin` remote at `detain/phlex-server` and pushes the full history
> (master + every branch + every tag) there. The old `detain/phlex`
> remote is NOT touched in B.4 — archiving it is B.4b's job.

## 1. Goal

After B.4 lands:

- `/home/sites/phlex/.git/config` has `[remote "origin"] url =
  git@github.com:detain/phlex-server.git`.
- `detain/phlex-server` on GitHub holds the **full** history of
  `detain/phlex` — every commit, every branch, every tag.
- The local working tree is the same set of files; **no source code
  moved**. Only documentation, README badges, clone URLs in docs,
  Caliber config, and CI workflow remote-references update.
- `master` on `detain/phlex-server` matches `master` on the old
  `detain/phlex` byte-for-byte (modulo the doc updates landed in this
  PR).
- `detain/phlex` still exists as a remote that humans / scripts can
  push to, but is **stale** — B.4b makes it irrelevant.

The on-disk directory stays `/home/sites/phlex` for continuity. Master
plan §2 explicitly defers the optional rename to step B.10.

## 2. Context (what already exists)

- `detain/phlex` — the live repo containing every commit through B.3.
- `detain/phlex-server` — pre-created empty repo (2026-05-16). Public.
- `/home/sites/phlex` — the working tree. After B.3:
  - `composer.json` already requires `detain/phlex-shared:^0.2`.
  - Origin is `git@github.com:detain/phlex.git`.
  - Caliber pre-commit hook is installed and assumes the
    `detain/phlex` remote in its config.
- `PHLEX_EXPANSION_PLAN.md` §2 — explicit instructions on this step.
- Pre-existing files that mention `detain/phlex` as the live repo and
  need updating: `README.md`, `AGENTS.md`, `CLAUDE.md`,
  `IMPLEMENTATION_PLAN.md`, `SUPERVISOR_PLAN.md`,
  `PHLEX_EXPANSION_PLAN.md`, `SESSION_HANDOFF.md`,
  `CALIBER_LEARNINGS.md`, `docs/**/*.md`, every `plans/expansion/*.md`
  step file, the CI workflow under `.github/workflows/`, and the
  CODEOWNERS file (if present).

## 3. Scope — files to create / modify

### Create

- None. B.4 is a remote-migration step.

### Modify

- `.git/config` (via `git remote set-url`):
  ```ini
  [remote "origin"]
      url = git@github.com:detain/phlex-server.git
      fetch = +refs/heads/*:refs/remotes/origin/*
  ```
- `README.md` — every URL of the form
  `github.com/detain/phlex/...` becomes `github.com/detain/phlex-server/...`.
  Add a "Migrated from `detain/phlex` on 2026-05-XX" line in the
  "About" section. Update CI badges, license badges, packagist
  badges to the new repo URL.
- `composer.json` — if a `homepage` or `support` URL points at
  `github.com/detain/phlex`, update to `phlex-server`.
- `.github/workflows/ci.yml` — if any job references the old repo
  URL (e.g., `actions/checkout` with explicit `repository:`), update.
  Usually `checkout@v4` doesn't need this; double-check.
- `.github/CODEOWNERS` — if present, update path references that may
  hardcode the old repo name (usually unnecessary).
- `.github/ISSUE_TEMPLATE/*.md` and `.github/PULL_REQUEST_TEMPLATE.md`
  — update any cross-references.
- `docs/**/*.md` — search & replace
  `github.com/detain/phlex/` → `github.com/detain/phlex-server/`.
  Also update bare `detain/phlex` mentions where they refer to a clone
  URL, NOT where they refer to the historical (pre-B.4) repo name
  for context.
- `AGENTS.md`, `CLAUDE.md` — clone-URL examples and "Repo" section
  metadata. Caliber will regenerate these too, but the subagent
  pre-edits to avoid the hook fighting with stale URLs.
- `SESSION_HANDOFF.md` — the "Repos involved" table updates: row
  `detain/phlex` is marked as "Archived after B.4b" with a redirect
  note; row `detain/phlex-server` is updated from "Empty, pre-created"
  to "Active master = (B.4 commit)".
- `PHLEX_EXPANSION_PLAN.md` §2 "Repo inventory" table — update the
  status of `detain/phlex` row and `detain/phlex-server` row.
- `IMPLEMENTATION_PLAN.md`, `SUPERVISOR_PLAN.md` — update any
  remote-URL references.
- `CALIBER_LEARNINGS.md` — if it embeds the remote URL anywhere
  (likely not, but check).
- `plans/expansion/*.md` — every step plan file under the expansion
  tree. Most refer to the working DIRECTORY `/home/sites/phlex`
  (unchanged) rather than the remote URL, but search to confirm.
- Caliber config (e.g., `.caliber.json` or `.caliber/config.yml`) —
  if it embeds the remote URL, update it. Then run
  `caliber refresh` so AGENTS.md/CLAUDE.md are regenerated from the
  new config and the hook doesn't fight the subagent's edits at
  commit time.
- `CHANGELOG.md` — entry:
  ```markdown
  ## [Unreleased]
  ### Changed
  - Repository moved from `github.com/detain/phlex` to `github.com/detain/phlex-server`. The local working directory stays `/home/sites/phlex` per the expansion plan; only the `origin` remote URL changes. Update your local clone with `git remote set-url origin git@github.com:detain/phlex-server.git`.
  ```

### Delete

- None.

## 4. Approach

> **Order is critical.** Get the push to `detain/phlex-server` working
> BEFORE editing docs — if the push fails (e.g., SSH-key issue), you
> want to discover that early without a half-edited doc tree.

1. **Pre-flight.**
   ```bash
   cd /home/sites/phlex
   git status --short                   # MUST be empty (CALIBER_LEARNINGS.md diff OK)
   git branch --show-current            # MUST be 'master'
   git pull --ff-only origin master
   git remote -v                        # confirm origin is detain/phlex
   gh repo view detain/phlex-server --json isEmpty,defaultBranchRef
   # MUST show: isEmpty=true (or defaultBranchRef=null). If false, stop and
   # report — somebody has already pushed.
   ```
2. **Branch.** B.4 runs on a feature branch like every other step.
   ```bash
   git checkout -b b.4-migrate-server
   ```
3. **Add a second remote** pointing at `phlex-server`, push history
   to it WITHOUT changing origin yet. This lets us roll back trivially
   if anything goes sideways.
   ```bash
   unset GITHUB_TOKEN
   git remote add phlex-server git@github.com:detain/phlex-server.git
   git push phlex-server master                       # push current master
   git push phlex-server --tags                       # push every tag
   git push phlex-server 'refs/heads/*:refs/heads/*'  # push every branch
   ```
   Wait for each push to complete. If `phlex-server` rejects (e.g.,
   non-fast-forward), STOP and report — somebody pushed first.
4. **Verify the new repo is populated.**
   ```bash
   gh repo view detain/phlex-server --json defaultBranchRef,pushedAt
   gh api repos/detain/phlex-server/branches --jq '.[].name'
   gh api repos/detain/phlex-server/tags     --jq '.[].name'
   ```
   Default branch should now show as `master`. Branch and tag lists
   should match `detain/phlex`.
5. **Set `master` as the default branch on `phlex-server`.**
   ```bash
   gh repo edit detain/phlex-server --default-branch master
   ```
6. **Repoint origin.**
   ```bash
   git remote remove phlex-server
   git remote set-url origin git@github.com:detain/phlex-server.git
   git remote -v                        # MUST show detain/phlex-server
   git fetch origin
   git branch --set-upstream-to=origin/master master
   ```
7. **Do the doc + config edits** listed in §3 "Modify". Use a
   recursive `grep -rln 'github.com/detain/phlex\b' .` to find every
   reference (the `\b` boundary excludes `phlex-server`,
   `phlex-shared`, `phlex-hub`, `phlex-plugin-example`). Update each.
8. **Update Caliber config** if it has the remote URL. Then run
   `caliber refresh` so the agent files regenerate; stage the
   resulting diff.
9. **Verification.** Same §0.4 minimum bar as any other implementation
   step.
10. **Commit + PR + merge.** The PR is against the **new**
    `detain/phlex-server` origin. Workflow runs of the post-merge
    master are the first CI runs on the new remote.

## 5. Tests (REQUIRED — §0.4 minimum bar)

B.4 introduces no executable code, so no new PHPUnit tests are
added. The full verification bar still runs to prove no regression:

- `./vendor/bin/phpunit` — must remain green.
- `./vendor/bin/phpstan analyze src/ --level=9` — must remain `[OK] No errors`.
- `./vendor/bin/phpcs --standard=PSR12 src/` — must remain clean.
- `./vendor/bin/psalm --no-progress` — must remain clean.
- `find src -name '*.php' -exec php -l {} \;` — no syntax errors.

**Integration boundary:** the remote-migration crosses GitHub API and
git-remote boundaries. Manual smoke test the supervisor performs after
B.4 lands:

```bash
unset GITHUB_TOKEN
git clone git@github.com:detain/phlex-server.git /tmp/phlex-server-smoke
cd /tmp/phlex-server-smoke
test -f composer.json && grep -q '"detain/phlex-shared"' composer.json && echo "clone smoke OK"
rm -rf /tmp/phlex-server-smoke
```

Documented as a manual check in the review template, not a unit test.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **"User-visible behavior change"** → not exactly user-visible, but
  contributor-visible. `CHANGELOG.md` entry per §3.
- **"Anything"** → `README.md` updated extensively (badges + clone
  URLs + repo name).
- **Developer docs** → every `docs/dev/*.md` and `docs/**/*.md` that
  references the old clone URL.

PHPDoc — N/A.

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] **No `gh repo create detain/phlex-server` was invoked.**
- [ ] `gh repo view detain/phlex-server --json pushedAt` shows a
      recent push (the post-B.4 master).
- [ ] `gh api repos/detain/phlex-server/branches` lists every branch
      that existed on `detain/phlex` before B.4.
- [ ] `gh api repos/detain/phlex-server/tags` lists every tag.
- [ ] `git remote -v` in `/home/sites/phlex` shows
      `git@github.com:detain/phlex-server.git` for both `(fetch)` and
      `(push)` of `origin`.
- [ ] `gh repo view detain/phlex-server --json defaultBranchRef --jq
      .defaultBranchRef.name` reports `master`.
- [ ] **`detain/phlex` (the old repo) was NOT modified, archived, or
      renamed by B.4.** That's B.4b's job.
- [ ] No source code under `src/` changed. (Diff only in `*.md`,
      `*.yml`, `.git/config`, possibly `composer.json` if homepage
      URL was set.)
- [ ] All `grep -rln 'github.com/detain/phlex\b'` hits (excluding
      `phlex-server`, `phlex-shared`, `phlex-hub`,
      `phlex-plugin-example`) are zero, or are documented as
      intentional historical references in `SESSION_HANDOFF.md` /
      `PHLEX_EXPANSION_PLAN.md` §1.
- [ ] `./vendor/bin/phpunit` — green.
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — `[OK] No errors`.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] `./vendor/bin/psalm --no-progress` — clean.
- [ ] `find src -name '*.php' -exec php -l {} \;` — no syntax errors.
- [ ] `CHANGELOG.md` has the B.4 entry.
- [ ] Caliber pre-commit hook ran; if it touched agent files, the
      diff is staged.
- [ ] Git ritual §8 below executed; postcondition checks PASS.
- [ ] CI on `detain/phlex-server` ran on the master push and reported
      all 5 checks green.

## 8. Git ritual (copy of master plan §11.4, adapted for the remote migration)

```bash
# ─── 0. PRECONDITION ───
cd /home/sites/phlex
git status --short                          # MUST be empty (CALIBER_LEARNINGS.md OK)
git branch --show-current                   # MUST be 'master'
git pull --ff-only origin master            # origin still detain/phlex at this point

# Pre-flight: confirm phlex-server is empty.
unset GITHUB_TOKEN
gh repo view detain/phlex-server --json isEmpty,defaultBranchRef
# MUST show isEmpty=true or defaultBranchRef=null.

# ─── 1. Branch ───
git checkout -b b.4-migrate-server

# ─── 2. Do the work ───
#   2.1 Push history to phlex-server via a TEMPORARY phlex-server remote
unset GITHUB_TOKEN
git remote add phlex-server git@github.com:detain/phlex-server.git
git push phlex-server master
git push phlex-server --tags
git push phlex-server 'refs/heads/*:refs/heads/*'

#   2.2 Verify
gh repo view detain/phlex-server --json defaultBranchRef
gh repo edit detain/phlex-server --default-branch master

#   2.3 Repoint origin (the destructive bit)
git remote remove phlex-server
git remote set-url origin git@github.com:detain/phlex-server.git
git fetch origin
git branch --set-upstream-to=origin/master master
git remote -v   # MUST show detain/phlex-server for fetch + push

#   2.4 Edit docs, CI workflows, Caliber config (per §3)
#   (implementation here)

# ─── 3. Verify ───
./vendor/bin/phpunit
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
./vendor/bin/psalm --no-progress
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
grep -rln 'github.com/detain/phlex\b' . --include='*.md' --include='*.yml' --include='*.json' \
  | grep -v '/vendor/' | grep -v '/node_modules/' | grep -v '\.git/'
# MUST report only intentional historical references (e.g., SESSION_HANDOFF.md
# row labeled "OLD remote, archived in B.4b").

# ─── 4. Caliber sync (hook active) ───
git add -A

# ─── 5. Commit — NEW commit, NEVER --amend ───
git commit -m "Step B.4: migrate origin to detain/phlex-server; update docs and badges"

# ─── 6. CRITICAL ───
unset GITHUB_TOKEN

# ─── 7. PR + merge — PR is against the NEW origin (detain/phlex-server) ───
gh pr create \
  --title "Step B.4: migrate origin to detain/phlex-server" \
  --body  "Repoints the local repo's origin to detain/phlex-server (already pushed in step 2.1), updates README badges and clone URLs across docs and Caliber config. Source code under src/ unchanged. Implements step B.4 of PHLEX_EXPANSION_PLAN.md. The old detain/phlex remote is NOT touched in this step — see B.4b for archival."
gh pr merge --squash --delete-branch

# ─── 8. Return to master with merged PR pulled — REQUIRED END STATE ───
git checkout master
git pull --ff-only origin master            # origin is now detain/phlex-server

# ─── 9. POSTCONDITION assertions ───
git status --short                          # MUST be empty (CALIBER_LEARNINGS.md OK)
git branch --show-current                   # MUST be 'master'
git log --oneline -1                        # MUST show the new squashed commit
git branch --list 'b.4-*'                   # MUST be empty
git remote get-url origin                   # MUST be git@github.com:detain/phlex-server.git

# CI verification on the new origin:
gh run list --repo detain/phlex-server --branch master --limit 1 --json conclusion
# MUST show conclusion=success
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs `b.4-migrate-server-review.md`. The
reviewer additionally:

- Clones `detain/phlex-server` to `/tmp/` and confirms it builds and
  tests green (smoke test from §5).
- Cross-references that NO B.4 commit touched `detain/phlex` (the
  old repo). The reviewer will see an old `master` tip on
  `detain/phlex` that's now one commit BEHIND `detain/phlex-server` —
  that's expected; B.4b cleans this up.
