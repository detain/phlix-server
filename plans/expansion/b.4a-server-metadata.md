# Step B.4a — Set `detain/phlex-server` description + 19 topic tags

**Phase:** B (Repo Split & Migration)
**Step:** B.4a
**Depends on:** B.4
**Review:** No (per master plan §3)
**Target repo:** `detain/phlex-server` (no local clone required —
`gh repo edit` operates over the API).
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Apply the description and 19 topic tags to `detain/phlex-server` so the
repository is discoverable via GitHub's topic search (e.g.
github.com/topics/plex). Content is taken verbatim from master plan
§14.1.

Same shape as B.2a, different repo + different topics.

## 2. Context (what already exists)

- `detain/phlex-server` after B.4: contains the full history of the
  old `detain/phlex` repo plus the B.4 doc-update commit. Default
  branch is `master`. Description + topics not yet set.
- `PHLEX_EXPANSION_PLAN.md` §14.1 — canonical description + 19 topic
  tag list. **B.4a copies that block verbatim.**
- `PHLEX_EXPANSION_PLAN.md` §14.4 — verification command.

## 3. Scope — files to create / modify

### Create / Modify / Delete

- None. B.4a is GitHub-config only.

## 4. Approach

1. **Drop the harness token.**
2. **Apply description + 19 topics** (verbatim from master plan §14.1):
   ```bash
   unset GITHUB_TOKEN
   gh repo edit detain/phlex-server \
     --description "Self-hosted media server in PHP 8 / Workerman. HLS+DASH, hardware transcoding, live TV, SyncPlay, plugins. A Plex/Jellyfin alternative." \
     --homepage "https://phlex.media" \
     --add-topic media-server \
     --add-topic self-hosted \
     --add-topic plex \
     --add-topic jellyfin \
     --add-topic emby \
     --add-topic php \
     --add-topic php8 \
     --add-topic workerman \
     --add-topic streaming \
     --add-topic hls \
     --add-topic transcoding \
     --add-topic ffmpeg \
     --add-topic video-streaming \
     --add-topic media-library \
     --add-topic home-theater \
     --add-topic dlna \
     --add-topic live-tv \
     --add-topic dvr \
     --add-topic syncplay
   ```
3. **Verify** with the §14.4 read-back:
   ```bash
   unset GITHUB_TOKEN
   gh repo view detain/phlex-server --json name,description,homepageUrl,repositoryTopics
   ```
   Description must match §14.1 byte-for-byte. `repositoryTopics`
   length must be 19.
4. **No commit.** B.4a is GitHub-config only.

## 5. Tests (REQUIRED — §0.4 minimum bar)

No source code changes → no PHPUnit tests added. The verification bar
is **not** re-run; nothing in either repo's source tree changed. The
supervisor confirms the GitHub API state matches master plan §14.1.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **"Anything"** → N/A; B.4a affects only GitHub repo settings.
- **CHANGELOG** → N/A.

PHPDoc — N/A.

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] `unset GITHUB_TOKEN` was issued before each `gh` call.
- [ ] `gh repo edit detain/phlex-server --description "..."` succeeded.
- [ ] All 19 `--add-topic` flags applied (verbatim from master plan
      §14.1).
- [ ] `gh repo view detain/phlex-server --json
      name,description,homepageUrl,repositoryTopics` output is
      captured and quoted in the subagent's final report. Description
      matches §14.1 byte-for-byte; `homepageUrl` is
      `https://phlex.media`; `repositoryTopics` contains exactly 19
      entries, all matching §14.1.
- [ ] No git changes were made in `/home/sites/phlex`. `git status
      --short` empty (CALIBER_LEARNINGS.md OK).

## 8. Git ritual (N/A — config-only step)

```bash
# ─── 0. PRECONDITION ───
cd /home/sites/phlex
git status --short                          # MUST be empty (CALIBER_LEARNINGS.md OK)
git branch --show-current                   # MUST be 'master'
git remote get-url origin                   # MUST be git@github.com:detain/phlex-server.git (B.4 already ran)

# ─── 1. Apply metadata ───
unset GITHUB_TOKEN
gh repo edit detain/phlex-server \
  --description "Self-hosted media server in PHP 8 / Workerman. HLS+DASH, hardware transcoding, live TV, SyncPlay, plugins. A Plex/Jellyfin alternative." \
  --homepage "https://phlex.media" \
  --add-topic media-server \
  --add-topic self-hosted \
  --add-topic plex \
  --add-topic jellyfin \
  --add-topic emby \
  --add-topic php \
  --add-topic php8 \
  --add-topic workerman \
  --add-topic streaming \
  --add-topic hls \
  --add-topic transcoding \
  --add-topic ffmpeg \
  --add-topic video-streaming \
  --add-topic media-library \
  --add-topic home-theater \
  --add-topic dlna \
  --add-topic live-tv \
  --add-topic dvr \
  --add-topic syncplay

# ─── 2. Verify ───
gh repo view detain/phlex-server --json name,description,homepageUrl,repositoryTopics
# Subagent quotes this output in its report.

# ─── 3. POSTCONDITION ───
# No git state to check; the API read-back is the deliverable.
```

## 9. Reviewer hand-off

Review = No in §3 of the master plan. The next step (B.4b) does not
strictly depend on B.4a's metadata; the supervisor may choose to
spot-check via the §14.4 command before spawning B.4b.
