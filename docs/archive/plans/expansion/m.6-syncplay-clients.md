# Step M.6 — SyncPlay on Every Client

**Phase:** M (Client Hub-Mode)
**Step:** M.6
**Depends on:** M.1–M.4
**Review:** Yes — see `m.6-syncplay-review.md`
**Target repos:** All 4 clients (phlex-mobile-client, phlex-tizen-client,
                   phlex-roku-client, phlex-windows-client)

## 1. Goal

Implement SyncPlay client-side on all 4 client apps, consuming the
server's existing TimeSync API (`src/Session/SyncPlay/TimeSync.php`).
SyncPlay lets multiple users watch the same content together remotely,
staying in sync without manual timestamp coordination.

## 2. Context (what already exists)

- `PHLEX_EXPANSION_PLAN.md` §3 row M.6 — SyncPlay on every client
- `src/Session/SyncPlay/TimeSync.php` — NTP-style weighted-mean offset
  (OFFSET_SAMPLE_COUNT=5)
- `src/Session/SyncPlay/` — existing server-side SyncPlay implementation
- `docs/dev/pairing-protocol.md` — pairing protocol (no SyncPlay-specific
  changes needed)
- M.1–M.4 plan files — hub-mode implementations in each client

## 3. Scope

### SyncPlay Protocol (per client)

#### WebSocket Events (consumed from server)

```
syncplay.time_sync         — TimeSync response (offset calculation)
syncplay.group_state       — Current group state (who's in, positions)
syncplay.playback_update   — Play/pause/seek from any member
syncplay.member_joined     — New member joined group
syncplay.member_left      — Member left group
```

#### Client Actions (sent to server)

```
syncplay.join_group        — Join a SyncPlay group
syncplay.leave_group       — Leave current group
syncplay.playback_command  — Play, pause, seek command
syncplay.report_position  — Periodic position report (every 30s)
syncplay.request_time_sync — Request TimeSync calculation
```

#### TimeSync Implementation

Client must implement NTP-style time synchronization:
1. Send `syncplay.request_time_sync` with local `t1`
2. Server responds with `syncplay.time_sync` containing `t1`, `t2`, `t3`
3. Client computes: `offset = (t2 - t1 - (t3 - t2)) / 2`
4. Keep rolling average of last 5 samples (OFFSET_SAMPLE_COUNT)
5. Use `adjustedTime = Date.now() + averageOffset` for position comparisons

### Per-Client Implementation

#### Mobile (React Native)

- `src/syncplay/SyncPlayService.ts`:
  - WebSocket connection to server
  - TimeSync with rolling offset average
  - Group state management
  - Playback command dispatch
- `src/store/syncplayStore.ts`:
  - Current group, members, sync state
  - Play/pause/seek actions
- UI: SyncPlay button in player, member list overlay

#### Tizen (Vanilla JS)

- `src/syncplay/SyncPlayService.js`:
  - WebSocket connection
  - TimeSync implementation
  - Event handlers for group state
- UI: SyncPlay toggle in player controls

#### Roku (BrightScript)

- `src/syncplay/SyncPlay.brs`:
  - WebSocket (roku-web-socket or similar)
  - TimeSync logic
- UI: SyncPlay node in player overlay

#### Windows (Electron)

- `src/syncplay/SyncPlayService.ts`:
  - WebSocket connection
  - TimeSync implementation
  - Group state store (Zustand)
- UI: SyncPlay panel component

## 4. Approach

1. Read `src/Session/SyncPlay/` to understand server-side protocol
2. Read skip-button-integration-brief (for marker protocol reference)
3. Per client: implement SyncPlayService with TimeSync
4. Per client: add UI for joining groups and sync controls
5. Per client: integrate with existing player (play/pause/seek sync)
6. Write tests
7. Verify per-client toolchain
8. Commit + PR + merge per repo (can be parallel across all 4)

## 5. Tests (REQUIRED per client)

1. TimeSync offset calculation
2. Join/leave group
3. Playback command dispatch and receipt
4. Member join/leave notifications
5. Offline detection and reconnection

## 6. Acceptance criteria

- [ ] User can create/join a SyncPlay group
- [ ] User sees other members in the group
- [ ] Play/pause/seek is synchronized across all members
- [ ] TimeSync offset is calculated and applied
- [ ] Member join/leave notifications shown
- [ ] SyncPlay works over both direct-LAN and hub-relay modes
- [ ] Toolchain tests pass per client
- [ ] Git ritual executed per repo

## 7. Git ritual (per client)

Example for mobile — repeat pattern for tizen, roku, windows:

```bash
cd /home/sites/phlex-mobile-client
git checkout -b m.6-syncplay
# implement, test, doc
git add src/syncplay/ src/store/syncplayStore.ts \
        src/screens/PlayerScreen.tsx src/__tests__/
git commit -m "M.6: add SyncPlay to mobile client"
unset GITHUB_TOKEN
git push -u origin m.6-syncplay
gh pr create --title "M.6: mobile SyncPlay client"
gh pr merge --squash --delete-branch
git checkout master && git pull
git branch -d m.6-syncplay
```
