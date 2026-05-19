# Step M.5 — Offline Downloads

**Phase:** M (Client Hub-Mode)
**Step:** M.5
**Depends on:** M.1–M.4
**Review:** Yes — see `m.5-offline-review.md`
**Target repos:** `phlex-mobile-client` (primary), `phlex-windows-client` (secondary)

## 1. Goal

Add offline download capability to the mobile and Windows clients so users can:

1. Download media items (episodes, movies) for offline playback
2. Manage a download queue and view download progress
3. Resume interrupted downloads
4. Play downloaded content offline (no network required)
5. Delete downloaded content to free storage

Priority: mobile first, Windows second.

## 2. Context (what already exists)

- `PHLEX_EXPANSION_PLAN.md` §3 row M.5 — offline downloads
- `plans/expansion/m.1-mobile-hub.md` — mobile hub-mode (M.1)
- `plans/expansion/m.4-windows-hub.md` — Windows hub-mode (M.4)
- `docs/dev/relay-protocol.md` — relay tunnel for remote downloads
- Mobile: existing download infrastructure (if any), media player
- Windows: existing download infrastructure (if any), media player

## 3. Scope

### Mobile (phlex-mobile-client)

#### Create / Modify

1. **Download Service** — `src/services/DownloadService.ts`:
   - Queue downloads (media item ID + quality)
   - Background download with progress tracking
   - Resume interrupted downloads (range requests)
   - Store in app's documents directory

2. **Download Store** — `src/store/downloadStore.ts` (Zustand):
   - Download queue state
   - Progress tracking per item
   - Pause/resume/cancel actions
   - Persisted to AsyncStorage

3. **Download Manager UI** — `src/screens/DownloadsScreen.tsx`:
   - List of queued/in-progress/completed downloads
   - Progress bars
   - Pause/resume/cancel buttons
   - Delete downloaded item
   - Storage usage display

4. **Offline Playback** — Modify player to detect offline mode
   and play from local storage instead of streaming

#### Key API Calls

```
GET /api/v1/media/{id}/download
  → Returns direct file URL or manifest URL for download

GET /api/v1/media/{id}/playback
  → With offline flag, returns local file path if downloaded
```

### Windows (phlex-windows-client)

Same pattern as mobile but:
- Store downloads in user's Documents folder or AppData
- Use Electron's download manager or node-based download
- Slightly different UI patterns (WPF-like or React)

## 4. Approach

1. Read M.1 and M.4 plan files for hub-mode context
2. Explore existing media player implementation in each repo
3. Implement download service + store per repo
4. Add download UI to the app
5. Implement offline playback routing
6. Write tests
7. Verify toolchain
8. Commit + PR + merge per repo

## 5. Tests (REQUIRED)

1. Download queue — add, pause, resume, cancel, remove
2. Download progress tracking
3. Resume interrupted download
4. Storage management (delete, usage calculation)
5. Offline playback detection and routing

## 6. Acceptance criteria

- [ ] User can tap "Download" on any media item
- [ ] Download appears in downloads list with progress
- [ ] User can pause/resume/cancel download
- [ ] Downloaded content plays offline
- [ ] User can delete downloaded content
- [ ] Queue persists across app restarts
- [ ] `npm test` — green
- [ ] TypeScript — zero errors (or BrightScript equivalent)
- [ ] Git ritual executed per repo

## 7. Git ritual (per repo)

Mobile:
```bash
cd /home/sites/phlex-mobile-client
git checkout -b m.5-offline
# implement, test, doc
git add src/services/DownloadService.ts src/store/downloadStore.ts \
        src/screens/DownloadsScreen.tsx src/__tests__/ README.md
git commit -m "M.5: add offline downloads to mobile client"
unset GITHUB_TOKEN
git push -u origin m.5-offline
gh pr create --title "M.5: mobile offline downloads"
gh pr merge --squash --delete-branch
git checkout master && git pull
git branch -d m.5-offline
```

Windows: similar pattern in `phlex-windows-client/`.
