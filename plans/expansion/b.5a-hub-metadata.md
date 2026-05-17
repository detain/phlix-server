# Step B.5a — Set `detain/phlex-hub` description + 19 topic tags

**Phase:** B (Repo Split & Migration)
**Step:** B.5a
**Depends on:** B.5
**Review:** No (per master plan §3)
**Target repo:** `detain/phlex-hub` (no local clone required — `gh
repo edit` operates over the API).
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Apply the description and 19 topic tags to `detain/phlex-hub` so the
repository is discoverable via GitHub's topic search (e.g.
github.com/topics/reverse-tunnel). Content is taken verbatim from
master plan §14.2.

Same shape as B.2a and B.4a, different repo + different topics.

## 2. Context (what already exists)

- `detain/phlex-hub` after B.5: scaffolded with first commit on
  master, CI green. Description + topics not yet set.
- `PHLEX_EXPANSION_PLAN.md` §14.2 — canonical description + 19
  topic tag list. **B.5a copies that block verbatim.**
- `PHLEX_EXPANSION_PLAN.md` §14.4 — verification command.

## 3. Scope — files to create / modify

### Create / Modify / Delete

- None. B.5a is GitHub-config only.

## 4. Approach

1. **Drop the harness token.**
2. **Apply description + 19 topics** (verbatim from master plan §14.2):
   ```bash
   unset GITHUB_TOKEN
   gh repo edit detain/phlex-hub \
     --description "Central cloud directory + reverse-tunnel relay for Phlex media servers. Sign in once, reach any of your servers from anywhere. Self-hostable." \
     --homepage "https://phlex.media" \
     --add-topic media-server \
     --add-topic media-hub \
     --add-topic self-hosted \
     --add-topic plex \
     --add-topic jellyfin \
     --add-topic emby \
     --add-topic php \
     --add-topic php8 \
     --add-topic workerman \
     --add-topic remote-access \
     --add-topic reverse-tunnel \
     --add-topic relay \
     --add-topic sso \
     --add-topic oidc \
     --add-topic ldap \
     --add-topic dashboard \
     --add-topic webhooks \
     --add-topic jwt \
     --add-topic websocket
   ```
3. **Verify** with the §14.4 read-back:
   ```bash
   unset GITHUB_TOKEN
   gh repo view detain/phlex-hub --json name,description,homepageUrl,repositoryTopics
   ```
   Description must match §14.2 byte-for-byte. `repositoryTopics`
   length must be 19.
4. **No commit.** B.5a is GitHub-config only.

## 5. Tests (REQUIRED — §0.4 minimum bar)

No source code changes → no PHPUnit tests added. Verification bar
not re-run.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

- N/A. Repo settings only.

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] `unset GITHUB_TOKEN` was issued before each `gh` call.
- [ ] `gh repo edit detain/phlex-hub --description "..."` succeeded.
- [ ] All 19 `--add-topic` flags applied (verbatim from master plan
      §14.2).
- [ ] `gh repo view detain/phlex-hub --json
      name,description,homepageUrl,repositoryTopics` output is
      captured in the subagent's final report. Description matches
      §14.2 byte-for-byte; `homepageUrl` is `https://phlex.media`;
      `repositoryTopics` contains exactly 19 entries, all matching
      §14.2.
- [ ] No git changes were made in any local repo.

## 8. Git ritual (N/A — config-only step)

```bash
# ─── 0. PRECONDITION ───
cd /home/sites/phlex
git status --short                          # CALIBER_LEARNINGS.md OK
git branch --show-current                   # MUST be 'master'

# ─── 1. Apply metadata ───
unset GITHUB_TOKEN
gh repo edit detain/phlex-hub \
  --description "Central cloud directory + reverse-tunnel relay for Phlex media servers. Sign in once, reach any of your servers from anywhere. Self-hostable." \
  --homepage "https://phlex.media" \
  --add-topic media-server \
  --add-topic media-hub \
  --add-topic self-hosted \
  --add-topic plex \
  --add-topic jellyfin \
  --add-topic emby \
  --add-topic php \
  --add-topic php8 \
  --add-topic workerman \
  --add-topic remote-access \
  --add-topic reverse-tunnel \
  --add-topic relay \
  --add-topic sso \
  --add-topic oidc \
  --add-topic ldap \
  --add-topic dashboard \
  --add-topic webhooks \
  --add-topic jwt \
  --add-topic websocket

# ─── 2. Verify ───
gh repo view detain/phlex-hub --json name,description,homepageUrl,repositoryTopics

# ─── 3. POSTCONDITION ───
# No git state to check; the API read-back is the deliverable.
```

## 9. Reviewer hand-off

Review = No in §3 of the master plan. The next step (B.6) does not
strictly depend on B.5a's metadata; the supervisor may spot-check
via the §14.4 command before spawning B.6.
