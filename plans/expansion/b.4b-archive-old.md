# Step B.4b — Archive old `detain/phlex` with a redirect README

**Phase:** B (Repo Split & Migration)
**Step:** B.4b
**Depends on:** B.4a (and transitively B.4)
**Review:** No (per master plan §3)
**Target repo:** `detain/phlex` (the OLD repo — NOT
`detain/phlex-server`). The supervisor clones it into a fresh
temporary working directory; **do not reuse `/home/sites/phlex`**,
whose origin is now `detain/phlex-server` per B.4.
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

> # ⚠️ IRREVERSIBLE ACTIONS — read this section before running anything ⚠️
>
> This step performs **two irreversible operations** on
> `detain/phlex`:
>
> 1. A force-push that overwrites the repo's `master` with a minimal
>    "this repo has moved" tree. The pre-B.4b history is **NOT
>    destroyed** — the existing branches and tags remain reachable on
>    the remote — but `master` will no longer match the working code.
> 2. `gh repo archive detain/phlex`. **An archived GitHub repository
>    is read-only.** It cannot accept new pushes, issues, PRs, or
>    releases. Un-archiving is possible (a few clicks in the Settings
>    page) but is a manual operator step that the subagent CANNOT
>    perform.
>
> **Before the subagent runs, the supervisor MUST gather explicit
> user confirmation.** The subagent's first action is to print a
> warning matching this paragraph and pause for the supervisor's
> "proceed" / "abort" reply. The subagent **does not infer
> confirmation** from the harness; the supervisor types the magic
> word.
>
> If confirmation is not given, the subagent stops short of pushing
> anything and reports the blocker. No state is changed.

## 1. Goal

After B.4b lands:

- The `detain/phlex` GitHub repo is **archived**.
- The repo's `master` branch contains only a `README.md` that says
  "This repository has moved to https://github.com/detain/phlex-server"
  plus a `LICENSE` file (unchanged).
- The pre-B.4b commit history is still reachable via the `pre-b4b`
  tag and via every named branch (untouched).
- Users who land on `github.com/detain/phlex` see the redirect notice
  prominently. Trying to clone still works (the repo is readable);
  trying to push fails (archived).

This step finalizes the repo split so the community can't confuse
the old empty-shell repo with the active project.

## 2. Context (what already exists)

- `detain/phlex` after B.4: still has the full history through B.3,
  same `master` as `detain/phlex-server` had at B.4's push moment.
  Not archived.
- `detain/phlex-server` after B.4a: active, has all the history,
  description and 19 topics applied, master is the canonical branch.
- `/home/sites/phlex` is repointed at `detain/phlex-server`. **Do not
  use it for B.4b**; you'd push the redirect README to the wrong
  place.

## 3. Scope — files to create / modify

All paths below are inside a **fresh temporary clone of
`detain/phlex`**, e.g. `/tmp/phlex-archive/`.

### Create

- `README.md` — replace the existing content with this exact text:
  ```markdown
  # This repository has moved

  **Phlex** now lives at: [github.com/detain/phlex-server](https://github.com/detain/phlex-server)

  This repository (`detain/phlex`) is archived and no longer receives
  updates. The full commit history has been migrated; please update
  your remotes:

  ```bash
  git remote set-url origin git@github.com:detain/phlex-server.git
  ```

  Related repositories:

  - **[detain/phlex-server](https://github.com/detain/phlex-server)** —
    the local media server (this code, renamed).
  - **[detain/phlex-hub](https://github.com/detain/phlex-hub)** —
    the central directory + reverse-tunnel relay.
  - **[detain/phlex-shared](https://github.com/detain/phlex-shared)** —
    interfaces and DTOs shared between server and hub.

  Issues, PRs, and releases should now be filed against
  `detain/phlex-server`. The pre-archive history is preserved on
  this repo under the `pre-b4b` tag if anyone needs to reference it.

  Archived: 2026-05-XX.
  ```

### Modify

- None — everything except `README.md` and `LICENSE` is removed by
  the rewrite.

### Delete

- Every other tracked path. The new master tree contains exactly two
  files: `README.md` and `LICENSE`. Use `git rm` per file rather than
  `git rm -r .` so the operator sees each deletion in the commit
  message.

## 4. Approach

1. **Stop and request confirmation.** The subagent's first emitted
   line is the warning block from the front matter of this file plus:
   "About to force-push `detain/phlex:master` and run `gh repo
   archive`. Reply `proceed` to continue or `abort` to stop." Wait.
2. **Only continue if the supervisor replied `proceed`.**
3. **Fresh clone the old repo** into a temp directory.
   ```bash
   cd /tmp
   unset GITHUB_TOKEN
   git clone git@github.com:detain/phlex.git phlex-archive
   cd /tmp/phlex-archive
   git status --short                     # MUST be empty
   git branch --show-current              # MUST be 'master'
   ```
4. **Tag the pre-archive state.**
   ```bash
   git tag -a pre-b4b -m "Last commit on detain/phlex before B.4b archival. Active development continues on detain/phlex-server."
   git push origin pre-b4b
   ```
5. **Build the redirect tree on a branch.**
   ```bash
   git checkout -b b.4b-archive
   ```
6. **Write the new `README.md`** with the exact text from §3 "Create".
7. **Remove every other tracked path** EXCEPT `LICENSE`.
   ```bash
   # List every tracked path:
   git ls-files | grep -vE '^(README\.md|LICENSE)$' > /tmp/files-to-remove.txt
   xargs -a /tmp/files-to-remove.txt git rm
   ```
   Verify: `git ls-files` MUST list exactly `LICENSE` and `README.md`.
8. **Commit + force-push to master.**
   ```bash
   git add README.md
   git commit -m "Archive: this repo has moved to detain/phlex-server"
   git push --force origin b.4b-archive:master
   ```
   The `--force` is intentional and necessary; the new master tree
   shares no ancestor with the old master. **This is the irreversible
   step #1.**
9. **Verify the redirect README is the new master.**
   ```bash
   unset GITHUB_TOKEN
   gh repo view detain/phlex --json defaultBranchRef
   curl -sL https://raw.githubusercontent.com/detain/phlex/master/README.md | head -5
   # MUST show "# This repository has moved"
   ```
10. **Archive the repo.**
    ```bash
    unset GITHUB_TOKEN
    gh repo archive detain/phlex --yes
    ```
    **This is the irreversible step #2.**
11. **Verify archival.**
    ```bash
    gh repo view detain/phlex --json isArchived
    # MUST show "isArchived":true
    ```
12. **Cleanup the temp dir.**
    ```bash
    cd /tmp
    rm -rf phlex-archive
    ```
13. **No PR against `detain/phlex-server`.** B.4b doesn't touch
    `/home/sites/phlex`'s working tree — there is no server-side
    commit. The "git ritual" below is just a no-op record of how the
    state machine ended up.

## 5. Tests (REQUIRED — §0.4 minimum bar)

No source code changes → no PHPUnit tests added. The verification bar
is **not** re-run; nothing in `/home/sites/phlex/src/` changed.

Manual smoke (the subagent reports the output):

```bash
unset GITHUB_TOKEN
gh repo view detain/phlex --json isArchived,description
# isArchived MUST be true
curl -sI https://github.com/detain/phlex 2>/dev/null | head -3
# (sanity check that the repo still answers, just archived)
```

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **"Anything"** → the new `README.md` on `detain/phlex` IS the doc
  deliverable.
- **CHANGELOG** → not applicable to `detain/phlex` (whose changelog
  is now effectively closed). On `detain/phlex-server`, the B.4 entry
  already covers the move; no additional CHANGELOG line for B.4b.

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] **Explicit operator confirmation was obtained** before any
      irreversible action.
- [ ] `pre-b4b` tag pushed to `detain/phlex`.
- [ ] `detain/phlex:master` HEAD shows only `README.md` + `LICENSE`.
- [ ] The new `README.md` content matches §3 "Create" byte-for-byte
      (modulo the date stamp).
- [ ] `gh repo view detain/phlex --json isArchived` reports
      `isArchived: true`.
- [ ] `gh repo view detain/phlex-server --json isArchived` reports
      `isArchived: false` (sanity check that we archived the right
      repo).
- [ ] `/home/sites/phlex` was NOT modified. `git status --short` is
      empty (CALIBER_LEARNINGS.md OK).
- [ ] The `/tmp/phlex-archive/` temp directory was cleaned up.

## 8. Git ritual (N/A — destructive operation on a non-local repo)

The standard PR-based ritual does **not** apply to B.4b. The
"postcondition" is the archival state on GitHub plus the absence of
changes in `/home/sites/phlex`. The subagent's report must include:

- The exact text the supervisor replied to the confirmation prompt
  ("proceed" expected).
- The output of `gh repo view detain/phlex --json
  isArchived,description,defaultBranchRef`.
- The output of `git ls-files` on the archived repo (MUST be
  `LICENSE` and `README.md` only).
- Output of `git status --short` and `git branch --show-current`
  in `/home/sites/phlex` (both as expected; this repo wasn't
  touched).

If confirmation was NOT obtained, the subagent reports that, has not
performed any action, and stops. The supervisor decides whether to
abort or re-spawn with confirmation.

## 9. Reviewer hand-off

Review = No in §3 of the master plan. There is no review template
paired with B.4b. The next step (B.5) implicitly verifies that B.4b
did not accidentally archive the wrong repo by being able to clone
`detain/phlex-hub` (which is unaffected).

If something went wrong (wrong repo archived, redirect README
malformed, master force-pushed to garbage), un-archiving is **the
operator's job**, not a subagent's, and is followed by re-running
B.4b. The supervisor escalates.
