# Step M.2 — Tizen: Hub-Mode

**Phase:** M (Client Hub-Mode)
**Step:** M.2
**Depends on:** C.9, M.0
**Review:** Yes — see `m.2-tizen-hub-review.md`
**Target repo:** `phlex-tizen-client` (local: `/home/sites/phlex-tizen-client/`)
**Stack:** Vanilla JavaScript + Webpack
**Estimated subagent type:** general-purpose (non-PHP repo)

## 1. Goal

Add hub-mode to `phlex-tizen-client` (Vanilla JS + Webpack) so users can:

1. Toggle hub-mode on/off in settings
2. Sign in to the hub and authenticate
3. See and select from their claimed servers
4. Route all API calls via direct-LAN or hub-relay

## 2. Context (what already exists)

- `PHLEX_EXPANSION_PLAN.md` §6 — pairing protocol, JWT delegation, relay tunnel
- `docs/dev/pairing-protocol.md` — server↔hub pairing spec
- `docs/dev/relay-protocol.md` — WS reverse-tunnel framing
- `plans/expansion/m.0-phase-m.md` — Phase M claim-flow contract
- `/home/sites/phlex-tizen-client/` — Tizen JS codebase
- Existing `src/config.js` — server URL configuration via `window.PHLEX_SERVER_URL`
- Existing `src/api.js` — direct-to-server API calls

## 3. Scope

All paths inside `/home/sites/phlex-tizen-client/`.

### Create / Modify

#### 1. Hub Config Module

- `src/hub/hubConfig.js`:
  ```javascript
  // Hub mode configuration and state
  const HubConfig = {
    hubUrl: null,          // e.g., 'https://hub.example.com'
    session: null,         // { accessToken, refreshToken, expiresAt, userId }
    servers: [],           // [{ serverId, serverName, version, status, hostname, relayHostname }]
    activeServerId: null,
    connectionMode: 'direct',  // 'direct' | 'relay'

    // Resolve effective server URL
    getEffectiveUrl(path) {
      if (!this.activeServerId || !this.servers.length) {
        return window.PHLEX_SERVER_URL + path;
      }
      const server = this.servers.find(s => s.serverId === this.activeServerId);
      if (this.connectionMode === 'relay' && server?.relayHostname) {
        return `${this.hubUrl}/api/v1/relay/${server.serverId}${path}`;
      }
      return (server?.hostname || window.PHLEX_SERVER_URL) + path;
    },

    // Get auth header for current mode
    getAuthHeader() {
      if (!this.session) return null;
      return `Bearer ${this.session.accessToken}`;
    }
  };
  ```

#### 2. Hub Auth API

- `src/hub/hubApi.js`:
  ```javascript
  // Hub API calls
  const HubApi = {
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

    async listServers(session) {
      const res = await fetch(`${HubConfig.hubUrl}/api/v1/me/servers`, {
        headers: { 'Authorization': `Bearer ${session.accessToken}` }
      });
      if (!res.ok) throw new Error(`List servers failed: ${res.status}`);
      const data = await res.json();
      return data.servers || [];
    },

    async refresh(refreshToken) {
      const res = await fetch(`${HubConfig.hubUrl}/api/v1/auth/refresh`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ refresh_token: refreshToken })
      });
      if (!res.ok) throw new Error(`Refresh failed: ${res.status}`);
      const data = await res.json();
      return {
        accessToken: data.access_token,
        refreshToken: data.refresh_token,
        expiresAt: Date.now() + data.expires_in * 1000,
        userId: HubConfig.session?.userId
      };
    }
  };
  ```

#### 3. Hub Mode Toggle (Settings)

- Modify `src/views/settings.js` or `src/views/SettingsView.js`:
  - Add "Hub Mode" section:
    - "Enable Hub Mode" toggle
    - "Hub URL" input (when enabling)
    - "Username" + "Password" inputs for hub login
    - "Signed in as [user] — [hub-url]" display
    - "Claimed Servers" list with radio selection
    - "Connection Mode" radio: Direct / Via Hub Relay
    - "Sign Out" button

#### 4. Hub-Aware API Client

- Modify `src/api.js` or create `src/hubAwareApi.js`:
  - Replace direct `window.PHLEX_SERVER_URL` references with `HubConfig.getEffectiveUrl(path)`
  - Inject `Authorization` header via `HubConfig.getAuthHeader()`
  - Inject `X-Server-Id` header for relay calls

#### 5. Persistent Storage

- Use Tizen's `tizen.storage` or `localStorage`:
  - Store `hubUrl`, `session` (accessToken, refreshToken), `activeServerId`
  - Restore on app launch

#### 6. Unit Tests

- `tests/unit/hub/hubConfig.test.js`:
  - `test_getEffectiveUrl_direct_mode`
  - `test_getEffectiveUrl_relay_mode`
  - `test_getAuthHeader_returns_bearer`

- `tests/unit/hub/hubApi.test.js`:
  - `test_signIn_returns_session`
  - `test_listServers_returns_array`

- `tests/unit/hub/hubAwareApi.test.js`:
  - `test_routes_via_hubConfig_getEffectiveUrl`
  - `test_injects_auth_header`

### Documentation

- Update `README.md` with hub-mode feature list

## 4. Approach

1. Read `plans/expansion/m.0-phase-m.md` for the claim-flow contract
2. Read `src/config.js` and `src/api.js` to understand existing patterns
3. Branch: `git checkout -b m.2-tizen-hub`
4. Implement hub config + API modules
5. Add hub mode UI to settings
6. Make API client hub-aware
7. Write tests (or add to existing test file if present)
8. Verification: toolchain in `package.json`
9. Commit + PR + merge
10. Return to master

## 5. Tests (REQUIRED)

Coverage ≥ 80 % on new modules (`src/hub/`).

1. `hubConfig` — effective URL resolution, auth header
2. `hubApi` — signIn, listServers, refresh
3. `hubAwareApi` — routing and header injection

## 6. Acceptance criteria

- [ ] Hub mode toggle appears in settings
- [ ] User can sign in to hub
- [ ] User sees list of claimed servers
- [ ] User can select active server
- [ ] Toggle between direct and relay modes works
- [ ] API calls route correctly in both modes
- [ ] Session persists across app restart
- [ ] Toolchain tests pass (check `package.json` for test command)
- [ ] README updated
- [ ] Git ritual executed; postcondition checks PASS

## 7. Git ritual

```bash
cd /home/sites/phlex-tizen-client
git status --short
git branch --show-current    # MUST be 'master'
git checkout -b m.2-tizen-hub

# Do the work

git add src/hub/ src/views/ src/api.js tests/ README.md
git status --short

# Run repo test command (check package.json)
npm test 2>&1 | tail -15

git commit -m "M.2: add hub-mode to Tizen client (Vanilla JS)"

unset GITHUB_TOKEN
git push -u origin m.2-tizen-hub
gh pr create \
  --title "M.2: Tizen hub-mode — hub toggle, server picker, relay-aware API" \
  --body "Implements hub-mode for phlex-tizen-client per plans/expansion/m.2-tizen-hub.md"
gh pr merge --squash --delete-branch

git checkout master && git pull
git branch -d m.2-tizen-hub
git log --oneline -1
git branch --list 'm.2-*'   # MUST be empty
```
