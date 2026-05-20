# Step M.4 — Windows: Hub-Mode

**Phase:** M (Client Hub-Mode)
**Step:** M.4
**Depends on:** C.9, M.0
**Review:** Yes — see `m.4-windows-hub-review.md`
**Target repo:** `phlex-windows-client` (local: `/home/sites/phlex-windows-client/`)
**Stack:** Electron + React + Vite
**Estimated subagent type:** general-purpose (non-PHP repo)

## 1. Goal

Add hub-mode to `phlex-windows-client` (Electron + React + Vite) so users can:

1. Configure hub URL in settings
2. Sign in to hub and authenticate
3. Switch between claimed servers
4. Route all API calls via direct-LAN or hub-relay
5. Persist hub configuration across sessions

## 2. Context (what already exists)

- `PHLEX_EXPANSION_PLAN.md` §6 — pairing protocol, JWT delegation, relay tunnel
- `docs/dev/pairing-protocol.md` — server↔hub pairing spec
- `docs/dev/relay-protocol.md` — WS reverse-tunnel framing
- `plans/expansion/m.0-phase-m.md` — Phase M claim-flow contract
- `/home/sites/phlex-windows-client/` — Electron codebase
- Existing config via `VITE_PHLEX_SERVER_URL` env var
- Existing React components in `src/`
- Existing Electron main process config handling

## 3. Scope

All paths inside `/home/sites/phlex-windows-client/`.

### Create / Modify

#### 1. Hub Service (TypeScript)

- `src/hub/HubService.ts`:
  ```typescript
  interface HubSession {
    accessToken: string;
    refreshToken: string;
    expiresAt: number;  // Unix timestamp ms
    userId: string;
  }

  interface HubServer {
    serverId: string;
    serverName: string;
    version: string;
    status: 'online' | 'offline';
    hostname: string;
    relayHostname?: string;
    capabilities: string[];
  }

  interface HubService {
    signIn(hubUrl: string, username: string, password: string): Promise<HubSession>;
    refresh(refreshToken: string): Promise<HubSession>;
    listServers(session: HubSession): Promise<HubServer[]>;
    signOut(): void;
  }

  const hubService: HubService = {
    async signIn(hubUrl, username, password) {
      const res = await fetch(`${hubUrl}/api/v1/auth/login`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username, password })
      });
      if (!res.ok) throw new Error(`Hub auth failed: ${res.status}`);
      const data = await res.json();
      return {
        accessToken: data.access_token,
        refreshToken: data.refresh_token,
        expiresAt: Date.now() + data.expires_in * 1000,
        userId: data.user_id
      };
    },

    async refresh(refreshToken) { /* similar pattern */ },
    async listServers(session) { /* GET /api/v1/me/servers */ },
    signOut() { /* clear stored session */ }
  };
  ```

#### 2. Hub Store (Zustand or React Context)

- `src/store/hubStore.ts`:
  ```typescript
  interface HubState {
    hubUrl: string | null;
    session: HubSession | null;
    servers: HubServer[];
    activeServerId: string | null;
    connectionMode: 'direct' | 'relay';
    effectiveServerUrl: string;

    // Actions
    signIn: (username: string, password: string) => Promise<void>;
    signOut: () => void;
    refreshSession: () => Promise<void>;
    fetchServers: () => Promise<void>;
    setActiveServer: (serverId: string) => void;
    setConnectionMode: (mode: 'direct' | 'relay') => void;
  }
  ```
- Persist to electron-store or safeStorage for security

#### 3. Hub-Aware API Client

- Modify `src/api/` or create `src/api/hubAwareClient.ts`:
  - When hub session is active, use `HubState.effectiveServerUrl`
  - Direct: `servers[activeServerId].hostname`
  - Relay: `${hubUrl}/api/v1/relay/${serverId}/${path}`
  - Inject `Authorization: Bearer <hub-session-accessToken>` header
  - Inject `X-Server-Id: <serverId>` header for relay

#### 4. Hub Settings UI

- `src/renderer/components/HubSettings.tsx`:
  - Hub URL input
  - Hub sign-in form (username/password)
  - Signed-in state display
  - Server list with active indicator
  - Connection mode toggle (Direct / Relay)
  - Sign out button

- Modify `src/renderer/App.tsx` or settings to include HubSettings

#### 5. Electron Main Process

- `electron/main.ts` or config handler:
  - Store hub config (hubUrl, session, activeServerId) in electron-store
  - Pass hub state to renderer via IPC
  - Handle hub URL configuration

#### 6. Unit Tests

- `src/__tests__/hub/HubService.test.ts`:
  - `test_signIn_returns_session`
  - `test_listServers_returns_array`
  - `test_refresh_renews_token`

- `src/__tests__/hub/hubStore.test.ts`:
  - `test_signIn_sets_session_and_fetches_servers`
  - `test_setActiveServer_updates_effectiveUrl`

### Documentation

- Update `README.md` with hub-mode feature
- Add note about `PHLEX_HUB_URL` env var or in-app configuration

## 4. Approach

1. Read `plans/expansion/m.0-phase-m.md` for the claim-flow contract
2. Explore `src/` and `electron/` to understand existing patterns
3. Branch: `git checkout -b m.4-windows-hub`
4. Implement HubService + hub store
5. Modify API client to be hub-aware
6. Add HubSettings component
7. Update Electron main process for persistence
8. Write tests
9. Verification: `npm test`, TypeScript check
10. Commit + PR + merge
11. Return to master

## 5. Tests (REQUIRED)

Coverage ≥ 85 % on new hub modules.

1. `HubService` — signIn, listServers, refresh, signOut
2. `HubState` — all actions and state transitions

## 6. Acceptance criteria

- [ ] Hub URL configurable (env var or in-app)
- [ ] User can sign in to hub
- [ ] User sees list of claimed servers
- [ ] User can switch active server
- [ ] Toggle between direct and relay modes
- [ ] All API calls route correctly in both modes
- [ ] Hub session persists across app restarts
- [ ] `npm test` — green
- [ ] TypeScript — zero errors
- [ ] README updated
- [ ] Git ritual executed; postcondition checks PASS

## 7. Git ritual

```bash
cd /home/sites/phlex-windows-client
git status --short
git branch --show-current    # MUST be 'master'
git checkout -b m.4-windows-hub

# Do the work

git add src/hub/ src/store/ src/api/ src/renderer/components/HubSettings.tsx \
        electron/ src/__tests__/ README.md
git status --short

npm test 2>&1 | tail -15
npx tsc --noEmit 2>&1 | tail -5

git commit -m "M.4: add hub-mode to Windows client (Electron + React)"

unset GITHUB_TOKEN
git push -u origin m.4-windows-hub
gh pr create \
  --title "M.4: Windows hub-mode — hub URL config, server switcher, relay routing" \
  --body "Implements hub-mode for phlex-windows-client per plans/expansion/m.4-windows-hub.md"
gh pr merge --squash --delete-branch

git checkout master && git pull
git branch -d m.4-windows-hub
git log --oneline -1
git branch --list 'm.4-*'   # MUST be empty
```
