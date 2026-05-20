# Step M.7 — Intro-Skip Button in Every Client

**Phase:** M (Client Hub-Mode)
**Step:** M.7
**Depends on:** F.4, M.1–M.4
**Review:** Yes — see `m.7-skip-clients-review.md`
**Target repos:** All 4 clients (phlex-mobile-client, phlex-tizen-client,
                   phlex-roku-client, phlex-windows-client)

## 1. Goal

Implement Skip Intro and Skip Outro buttons in all 4 client players,
consuming the server's skip-button protocol published in Phase F.4
(`docs/clients/skip-button-integration-brief.md`).

When a user is watching a show episode and playback position enters the
intro marker range, display "Skip Intro" button. When position enters the
outro marker range, display "Skip Outro" button. Tapping seeks to the
marker end position.

## 2. Context (what already exists)

- `PHLEX_EXPANSION_PLAN.md` §3 row M.7 — intro-skip on every client
- `docs/clients/skip-button-integration-brief.md` — client-side contract
- `docs/reference/skip-button-protocol.md` — full protocol spec
- `src/Media/Markers/` — server-side marker storage (Phase F.3)
- `src/Server/Http/Controllers/MediaItemController.php` — serves
  `/api/v1/media/{id}/playback` which includes markers in response
- M.1–M.4 plan files — hub-mode implementations

## 3. Protocol Reference

### Fetch Playback Info

```
GET /api/v1/media/{media_item_id}/playback
```

Response includes `markers` object:

```json
{
  "playback_info": {
    "markers": {
      "skip_intro_start": 10,
      "skip_intro_end": 90,
      "skip_outro_start": 2340,
      "skip_outro_end": 2520
    }
  }
}
```

### Button Behavior

| Condition | Action |
|---|---|
| `skip_intro_start` not null AND position between start/end | Show "Skip Intro" |
| `skip_outro_start` not null AND position between start/end | Show "Skip Outro" |
| Field is `null` | Do not show button |

### Button Actions

- **Skip Intro** → Seek to `skip_intro_end`
- **Skip Outro** → Seek to `skip_outro_end`

## 4. Scope

### Per-Client Implementation

#### Mobile (React Native)

- `src/player/SkipButton.tsx`:
  - Props: `marker: { start: number, end: number } | null`, `onSkip: () => void`
  - Shows "Skip Intro" or "Skip Outro" button
  - Auto-shows when playback position enters marker range
- Modify `src/player/PlayerScreen.tsx`:
  - Fetch markers from `/api/v1/media/{id}/playback`
  - Track playback position
  - Show SkipButton component when in marker range
- Hook: `useSkipMarkers(mediaId, position) => { skipIntro, skipOutro }`

#### Tizen (Vanilla JS)

- `src/player/SkipButton.js`:
  - HTML/CSS button element
  - Visibility toggled based on playback position
- Modify player component to:
  - Fetch markers from playback info
  - Track position, show button when in marker range
  - Handle click → seek

#### Roku (BrightScript)

- `src/player/SkipButton.brs`:
  - SceneGraph node (Poster or Label with timer)
  - Show/hide based on position
- Modify player to:
  - Extract markers from playback response
  - Track position with timer
  - Toggle button visibility
  - Handle click → seek to marker end

#### Windows (Electron)

- `src/player/components/SkipButton.tsx`:
  - React component with styled button
  - Position tracking via player state
- Modify player component or overlay:
  - Fetch markers from playback info
  - Show button in marker range
  - Handle click → seek

## 5. Approach

1. Read `docs/clients/skip-button-integration-brief.md`
2. Read `docs/reference/skip-button-protocol.md`
3. Per client: implement SkipButton component
4. Per client: integrate with existing player
5. Per client: handle marker range detection and auto-show
6. Write tests (skip button visibility logic)
7. Verify per-client toolchain
8. Commit + PR + merge per repo (can be parallel)

## 6. Tests (REQUIRED per client)

1. Button shows when position is within intro marker range
2. Button hides when position is outside marker range
3. Button shows outro marker when in outro range
4. Seek action fires correct marker end position
5. Null markers don't show any button

## 7. Acceptance criteria

- [ ] "Skip Intro" button appears during intro marker range
- [ ] "Skip Outro" button appears during outro marker range
- [ ] Tapping "Skip Intro" seeks to skip_intro_end
- [ ] Tapping "Skip Outro" seeks to skip_outro_end
- [ ] Buttons auto-hide when outside marker ranges
- [ ] Null markers result in no button shown
- [ ] Works with both direct-LAN and hub-relay playback
- [ ] Toolchain tests pass per client
- [ ] Git ritual executed per repo

## 8. Git ritual (per client)

Example for mobile:

```bash
cd /home/sites/phlex-mobile-client
git checkout -b m.7-skip-button
# implement, test, doc
git add src/player/SkipButton.tsx src/player/PlayerScreen.tsx \
        src/__tests__/
git commit -m "M.7: add Skip Intro/Outro buttons to mobile player"
unset GITHUB_TOKEN
git push -u origin m.7-skip-button
gh pr create --title "M.7: mobile skip-intro button"
gh pr merge --squash --delete-branch
git checkout master && git pull
git branch -d m.7-skip-button
```
