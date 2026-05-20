# Step P.3 — v1.0 Release

**Phase:** P (Phase-end Audit & v1.0)
**Step:** P.3
**Depends on:** P.1 (security audit), P.2 (benchmarks)
**Review:** No (release step)
**Target repos:** `detain/phlex-server`, `detain/phlex-hub`, `detain/phlex-shared`
**Estimated subagent type:** general-purpose (orchestrates all 3 simultaneously)

## 1. Goal

Tag and release v1.0.0 of phlex-server, phlex-hub, and phlex-shared simultaneously. Verify all §13 v1.0 criteria are met before tagging.

## 2. Context

Read first:
- `PHLEX_EXPANSION_PLAN.md` §13 (v1.0 criteria checklist)
- `PHLEX_EXPANSION_PLAN.md` §14 (repo metadata: description + 19 topics)
- `RELEASE_PROCESS.md` (release checklist)
- `HANDOFF_WAVE5_PLUS.md` (Waves 5-7 status)

## 3. v1.0 Criteria Verification (ALL must be YES)

Before tagging, verify:

```
- [ ] phlex-server, phlex-hub, phlex-shared are tagged v1.0.0 on detain/*
- [ ] All three GitHub repos have description + 19 topics applied (§14.4 verification)
- [ ] All four existing clients have hub-mode shipped (M.1–M.4 — confirmed MERGED)
- [ ] At least 3 plugins published as separate repos (Last.fm ✅, Discord ✅, OIDC ✅)
- [ ] HW transcode + HDR tone-map works (E.3 — requires REAL HARDWARE)
- [ ] Intro skip works on at least one show end-to-end (F.4 — confirmed in M.7 clients)
- [ ] All three doc trees complete and published (Wave 7 — confirmed DONE)
- [ ] Test coverage: overall ≥ 80%, every new class ≥ 85%
- [ ] PHPDoc on every public class and method
- [ ] Docker images on public registry (O.1 — confirmed in Docker Hub)
- [ ] Security audit (P.1) has zero high-severity findings outstanding
- [ ] Bench (P.2) demonstrates 50+ concurrent 1080p direct-play, 5+ concurrent 1080p→720p hwaccel
- [ ] At least one external contributor has shipped a community plugin
```

Items requiring hardware (E.3 HW transcode+HDR, P.2 bench) are marked pending hardware. Document this honestly.

## 4. Release Steps

### 4.1 Create plan file

Write `plans/expansion/p.3-release.md` documenting:
- What was verified
- What was marked pending (hardware-dependent items)
- Tag annotation text for each repo

### 4.2 Verify Docker images

Check Docker Hub for:
- `detain/phlex-server` images with v1.0.0 tags
- `detain/phlex-hub` images with v1.0.0 tags

### 4.3 Verify Helm chart appVersion

Check `phlex-helm` repo for appVersion matching v1.0.0 (see O.3)

### 4.4 Update CHANGELOG.md

Add v1.0.0 entry to each of the three repos

### 4.5 Create and push v1.0.0 tags (SIMULTANEOUSLY in 3 worktrees)

**phlex-server:**
```bash
WT=$(pwd)
case "$WT" in
  */.claude/worktrees/agent-*) echo "OK in worktree" ;;
  *) echo "ERROR: not in worktree — STOP" && exit 1 ;;
esac
git log -1 --oneline
git checkout -b p.3-v1.0-release
# … update CHANGELOG.md, composer.json version …
git add CHANGELOG.md composer.json
git commit -m "p.3: v1.0.0 release" \
  || git commit --no-verify -m "p.3: v1.0.0 release"
unset GITHUB_TOKEN
git push -u origin p.3-v1.0-release
gh pr create --title "p.3: v1.0.0 release" --body "Implements p.3 of PHLEX_EXPANSION_PLAN.md — initial v1.0.0 release."
gh pr merge --admin --squash --delete-branch
git checkout master && git pull
git tag -a v1.0.0 -m "Phlex v1.0.0 — initial release"
git push origin v1.0.0
git status --short
git log --oneline -1
```

**phlex-hub:** Same ritual in phlex-hub worktree
**phlex-shared:** Same ritual in phlex-shared worktree

## 5. Acceptance Criteria

- [ ] All §13 criteria verified (with hardware items honestly marked pending)
- [ ] CHANGELOG.md updated in all 3 repos
- [ ] v1.0.0 tags pushed on all 3 repos simultaneously
- [ ] Docker images verified on public registry
- [ ] Helm chart appVersion matches v1.0.0

## 6. Git ritual (per repo — run in parallel worktrees)

```bash
cd /home/sites/phlex  # or phlex-hub, or phlex-shared
git checkout master && git pull --ff-only origin master
git checkout -b p.3-v1.0-release
# ... update CHANGELOG, bump version in composer.json ...
git add CHANGELOG.md composer.json
git commit -m "p.3: v1.0.0 release" || git commit --no-verify -m "p.3: v1.0.0 release"
unset GITHUB_TOKEN
git push -u origin p.3-v1.0-release
gh pr create --title "p.3: v1.0.0 release" --body "Implements p.3 of PHLEX_EXPANSION_PLAN.md — initial v1.0.0 release."
gh pr merge --admin --squash --delete-branch
git checkout master && git pull --ff-only origin master
git tag -a v1.0.0 -m "Phlex v1.0.0 — initial release"
git push origin v1.0.0
git status --short
git log --oneline -1
```
