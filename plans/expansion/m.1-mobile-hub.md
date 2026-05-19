# Step M.1 — Mobile: Hub-Mode

**Phase:** M (Client Hub-Mode)
**Step:** M.1
**Depends on:** C.9, M.0
**Review:** Yes — see `m.1-mobile-hub-review.md`
**Target repo:** `phlex-mobile-client` (local: `/home/sites/phlex-mobile-client/`)
**Stack:** React Native + TypeScript + Zustand
**Estimated subagent type:** general-purpose (non-PHP repo)

## 1. Goal

Add hub-mode to `phlex-mobile-client` (React Native + Zustand) so users
can:

1. Sign in to the hub (authenticate with hub JWT)
2. See a list of their claimed servers from the hub API
3. Switch the active server (direct-LAN or hub-relay)
4. Have all API calls route correctly in both modes

## 2. Context (what already exists)

- `PHLEX_EXPANSION_PLAN.md` §6 — pairing protocol, JWT delegation, relay tunnel
- `docs/dev/pairing-protocol.md` — server↔hub pairing spec
- `docs/dev/relay-protocol.md` — WS reverse-tunnel framing
- `plans/expansion/m.0-phase-m.md` — Phase M claim-flow contract
- `/home/sites/phlex-mobile-client/` — React Native codebase
- Existing Zustand store (`src/store/`) — auth, settings, server config
- Existing API client (`src/api/`) — direct-to-server calls

## 3. Scope

All paths inside `/home/sites/phlex-mobile-client/`.

### Create / Modify

#### 1. Hub Auth Service

- `src/hub/HubAuthService.ts`:
  ```typescript
  interface HubAuthService {
    // Sign in to hub with username/password, returns hub JWT
    signIn(hubUrl: string, username: string, password: string): Promise<HubSession>;
    // Refresh the hub JWT
    refresh(refreshToken: string): Promise<HubSession>;
    // Get list of user's claimed servers
    listServers(session: HubSession): Promise<HubServer[]>;
    // Sign out (invalidate hub session locally)
    signOut(): void;
  }

  interface HubSession {
    accessToken: string;      // JWT for hub API calls
    refreshToken: string;
    expiresAt: number;         // Unix timestamp
    userId: string;
  }

  interface HubServer {
    serverId: string;
    serverName: string;
    version: string;
    status: 'online' | 'offline';
    hostname: string;          // preferred direct-LAN URL
    relayHostname?: string;  // hub-relay URL if available
    capabilities: string[];
  }
  ```

#### 2. Hub Mode Store (Zustand)

- `src/store/hubStore.ts`:
  ```typescript
  interface HubState {
    // Hub connection
    hubUrl: string | null;
    session: HubSession | null;
    servers: HubServer[];
    activeServerId: string | null;

    // Connection mode
    connectionMode: 'direct' | 'relay';
    effectiveServerUrl: string;  // resolved from activeServer + mode

    // Actions
    signInToHub: (hubUrl: string, username: string, password: string) => Promise<void>;
    signOutOfHub: () => void;
    refreshHubSession: () => Promise<void>;
    fetchServers: () => Promise<void>;
    setActiveServer: (serverId: string) => void;
    setConnectionMode: (mode: 'direct' | 'relay') => void;
  }
  ```

  Persisted to `AsyncStorage` (jwt, refreshToken, activeServerId).

#### 3. Hub-Aware API Client

- Modify `src/api/client.ts` (or create `src/api/hubAwareClient.ts`):
  - When `hubStore.effectiveServerUrl` is set, route all API calls
    through the hub-relay endpoint: `https://hub.example.com/api/v1/relay/<server-id>/<path>`
  - When direct, use `hubStore.servers[activeServerId].hostname`
  - Inject `Authorization: Bearer <hub-session-jwt>` header on hub calls
  - Inject `X-Server-Id: <server-id>` header on relay calls

#### 4. Settings Screen Updates

- `src/screens/SettingsScreen.tsx` — add hub section:
  - "Hub URL" input (or "Sign in to Hub" if not signed in)
  - "Signed in as [user] — [hub-url]" (when signed in)
  - "Claimed Servers" list with active-server indicator
  - "Connection Mode" toggle: Direct / Via Hub Relay
  - "Sign Out of Hub" button

#### 5. Server Switcher Component

- `src/components/ServerSwitcher.tsx`:
  - Dropdown/modal showing all claimed servers
  - Active server highlighted
  - Status indicator (online/offline)
  - Tap to switch active server

#### 6. Unit Tests

- `src/__tests__/hub/HubAuthService.test.ts`:
  - `test_signIn_returns_session`
  - `test_refresh_renews_token`
  - `test_listServers_returns_server_list`
  - `test_signOut_clears_session`

- `src/__tests__/hub/hubStore.test.ts`:
  - `test_signInToHub_sets_session_and_fetches_servers`
  - `test_setActiveServer_updates_effectiveUrl`
  - `test_setConnectionMode_toggles_direct_vs_relay`

- `src/__tests__/hub/HubAwareClient.test.ts`:
  - `test_direct_mode_uses_server_hostname`
  - `test_relay_mode_uses_hub_relay_url`
  - `test_injects_authorization_header`
  - `test_injects_server_id_header_for_relay`

### Documentation

- Update `README.md` in the mobile client repo with hub-mode feature
- Add `docs/hub-mode.md` if the repo has a docs folder
- Document env var / configuration for hub URL

## 4. Approach

1. Read `plans/expansion/m.0-phase-m.md` for the claim-flow contract
2. Read `docs/dev/pairing-protocol.md` §8 (User-Session JWT)
3. Read `docs/dev/relay-protocol.md` for relay wire format
4. Explore `src/store/` and `src/api/` to understand existing patterns
5. Branch: `git checkout -b m.1-mobile-hub`
6. Implement hub store + service
7. Modify API client to be hub-aware
8. Add UI (settings + server switcher)
9. Write tests
10. Verification: `npm test`, TypeScript lint
11. Commit + PR + merge
12. Return to master

## 5. Tests (REQUIRED)

Coverage ≥ 85 % on new files (`src/hub/`, `src/store/hubStore.ts`).

1. `HubAuthService` — signIn, refresh, listServers, signOut
2. `HubState` — signInToHub, setActiveServer, setConnectionMode, signOutOfHub
3. `HubAwareClient` — direct vs relay routing, header injection
4. Error handling: invalid hub URL, expired refresh token, network failure

## 6. Acceptance criteria

- [ ] User can sign in to hub with username/password
- [ ] User sees list of their claimed servers
- [ ] User can switch active server
- [ ] Active server shown in UI (e.g., server name in header)
- [ ] Toggle between direct-LAN and hub-relay modes
- [ ] All API calls route correctly in both modes
- [ ] Hub session persisted across app restarts (secure storage)
- [ ] `npm test` — green
- [ ] TypeScript — zero errors
- [ ] README updated with hub-mode feature
- [ ] Git ritual executed; postcondition checks PASS

## 7. Git ritual

```bash
cd /home/sites/phlex-mobile-client
git status --short                       # MUST be empty
git branch --show-current                # MUST be 'master'
git checkout -b m.1-mobile-hub

# Do the work (implement, test, doc)

git add src/hub/ src/store/hubStore.ts src/api/hubAwareClient.ts \
        src/screens/SettingsScreen.tsx src/components/ServerSwitcher.tsx \
        src/__tests__/ README.md
git status --short

# npm test (or repo test command)
npm test 2>&1 | tail -20

# Commit
git commit -m "M.1: add hub-mode to mobile client (React Native + Zustand)"

unset GITHUB_TOKEN
git push -u origin m.1-mobile-hub
gh pr create \
  --title "M.1: mobile hub-mode — sign in to hub, list servers, switch active server" \
  --body "Implements hub-mode for phlex-mobile-client per plans/expansion/m.1-mobile-hub.md"
gh pr merge --squash --delete-branch

git checkout master && git pull
git branch -d m.1-mobile-hub
git log --oneline -1
git branch --list 'm.1-*'   # MUST be empty
```

## 8. Verification bar

```bash
npm test 2>&1 | tail -10
npx tsc --noEmit 2>&1 | tail -5
```
