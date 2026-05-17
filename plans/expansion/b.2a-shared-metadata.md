# Step B.2a — Set `detain/phlex-shared` description + 19 topic tags

**Phase:** B (Repo Split & Migration)
**Step:** B.2a
**Depends on:** B.2
**Review:** No (per master plan §3)
**Target repo:** `detain/phlex-shared` (no local clone required — `gh
repo edit` operates over the API).
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Apply the description and 19 topic tags to `detain/phlex-shared` so the
repository is discoverable via GitHub's topic search (e.g.
github.com/topics/composer-package). Content is taken verbatim from
master plan §14.3.

This is a "config-only" step — no source files change.

## 2. Context (what already exists)

- `detain/phlex-shared` after B.2: public, master pushed, v0.1.0 tagged,
  CI green. Description + topics not yet set (B.2 deliberately
  deferred this so the metadata is its own auditable PR).
- `PHLEX_EXPANSION_PLAN.md` §14.3 — canonical description + 19 topic
  tag list. **B.2a copies that block verbatim.**
- `PHLEX_EXPANSION_PLAN.md` §14.4 — the verification command.

## 3. Scope — files to create / modify

### Create

- None. B.2a does not write files; it issues `gh` calls.

### Modify

- None. B.2a does not modify files.

### Delete

- None.

## 4. Approach

1. **Drop the harness token** so the `gh` call uses local auth.
2. **Apply description + 19 topics** in one `gh repo edit` invocation
   (verbatim from master plan §14.3):
   ```bash
   unset GITHUB_TOKEN
   gh repo edit detain/phlex-shared \
     --description "Shared interfaces, DTOs, event names, and protocol types used by both phlex-server and phlex-hub. Composer-installable, PHP 8.3+, zero I/O." \
     --homepage "https://phlex.media" \
     --add-topic php \
     --add-topic php8 \
     --add-topic composer-package \
     --add-topic psr-7 \
     --add-topic psr-11 \
     --add-topic psr-14 \
     --add-topic dto \
     --add-topic interfaces \
     --add-topic shared-library \
     --add-topic media-server \
     --add-topic jwt \
     --add-topic oauth2 \
     --add-topic oidc \
     --add-topic event-dispatcher \
     --add-topic plugin-api \
     --add-topic typed-php \
     --add-topic strict-types \
     --add-topic library \
     --add-topic sdk
   ```
3. **Verify** with the §14.4 read-back:
   ```bash
   unset GITHUB_TOKEN
   gh repo view detain/phlex-shared --json name,description,homepageUrl,repositoryTopics
   ```
   The response MUST include:
   - `description` = the long string above.
   - `homepageUrl` = `https://phlex.media`.
   - `repositoryTopics.nodes` array of length 19, containing every
     topic listed in the apply command.
4. **No commit.** B.2a is GitHub-config only; no source PR. The
   "postcondition" checks for this step assert the API state, not git
   state.

## 5. Tests (REQUIRED — §0.4 minimum bar)

No source code changes → no PHPUnit tests added. The verification bar
is **not** re-run because nothing in `phlex` or `phlex-shared`'s source
tree changed. The supervisor confirms the GitHub API state matches
master plan §14.3.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **"Anything"** → N/A; B.2a affects only GitHub repo settings,
  invisible to anyone consuming the package.
- **CHANGELOG** → N/A; metadata application is not a code change.

PHPDoc — N/A; no PHP files touched.

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] `unset GITHUB_TOKEN` was issued before each `gh` call.
- [ ] `gh repo edit detain/phlex-shared --description "..."` succeeded
      (exit code 0).
- [ ] Each of the 19 `--add-topic` flags was applied (verbatim from
      master plan §14.3).
- [ ] `gh repo view detain/phlex-shared --json name,description,homepageUrl,repositoryTopics`
      output is captured and quoted in the subagent's final report. The
      description matches §14.3 byte-for-byte; `homepageUrl` is
      `https://phlex.media`; `repositoryTopics` contains exactly 19
      entries, all matching §14.3.
- [ ] No git changes were made in either `/home/sites/phlex` or
      `/home/sites/phlex-shared`. `git status --short` empty in both.

## 8. Git ritual (N/A — config-only step)

There is **no** PR for B.2a. The "ritual" is just the verification
command above. The subagent's final report must include the JSON
output verbatim so the supervisor can cross-check against master plan
§14.3.

For consistency with the per-step plan template, here is the no-op
ritual that the subagent runs:

```bash
# ─── 0. PRECONDITION: clean state in both repos ───
cd /home/sites/phlex
git status --short                          # MUST be empty (CALIBER_LEARNINGS.md diff OK if hook un-staged)
git branch --show-current                   # MUST be 'master'

cd /home/sites/phlex-shared  # only if cloned locally — otherwise skip
git status --short                          # MUST be empty
git branch --show-current                   # MUST be 'master'

# ─── 1. Apply metadata ───
unset GITHUB_TOKEN
gh repo edit detain/phlex-shared \
  --description "Shared interfaces, DTOs, event names, and protocol types used by both phlex-server and phlex-hub. Composer-installable, PHP 8.3+, zero I/O." \
  --homepage "https://phlex.media" \
  --add-topic php \
  --add-topic php8 \
  --add-topic composer-package \
  --add-topic psr-7 \
  --add-topic psr-11 \
  --add-topic psr-14 \
  --add-topic dto \
  --add-topic interfaces \
  --add-topic shared-library \
  --add-topic media-server \
  --add-topic jwt \
  --add-topic oauth2 \
  --add-topic oidc \
  --add-topic event-dispatcher \
  --add-topic plugin-api \
  --add-topic typed-php \
  --add-topic strict-types \
  --add-topic library \
  --add-topic sdk

# ─── 2. Verify ───
gh repo view detain/phlex-shared --json name,description,homepageUrl,repositoryTopics
# Subagent quotes this output in its report.

# ─── 3. POSTCONDITION ───
# (no git state to check; the report is the deliverable)
```

## 9. Reviewer hand-off

Review = No in §3 of the master plan. The next step (B.3) implicitly
verifies B.2a by consuming `phlex-shared` from Composer — at which
point if the topics were wrong, nothing breaks. The supervisor may
choose to spot-check via the §14.4 command before spawning B.3.
