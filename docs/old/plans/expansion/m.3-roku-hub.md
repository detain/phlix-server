# Step M.3 — Roku: Hub-Mode

**Phase:** M (Client Hub-Mode)
**Step:** M.3
**Depends on:** C.9, M.0
**Review:** Yes — see `m.3-roku-hub-review.md`
**Target repo:** `phlex-roku-client` (local: `/home/sites/phlex-roku-client/`)
**Stack:** BrightScript / SceneGraph
**Estimated subagent type:** general-purpose (non-PHP repo)

## 1. Goal

Add hub-mode to `phlex-roku-client` (BrightScript) so users can:

1. Enter hub URL in settings
2. Authenticate with hub credentials
3. See and switch between claimed servers
4. Route all playback through direct-LAN or hub-relay
5. Play HLS streams in relay mode (relay-aware HLS playback)

## 2. Context (what already exists)

- `PHLEX_EXPANSION_PLAN.md` §6 — pairing protocol, JWT delegation, relay tunnel
- `docs/dev/pairing-protocol.md` — server↔hub pairing spec
- `docs/dev/relay-protocol.md` — WS reverse-tunnel framing
- `plans/expansion/m.0-phase-m.md` — Phase M claim-flow contract
- `/home/sites/phlex-roku-client/` — BrightScript codebase
- Existing `RokuStorage` for server URL persistence
- Existing HLS playback implementation

## 3. Scope

All paths inside `/home/sites/phlex-roku-client/`.

### Create / Modify

#### 1. Hub Authentication (BrightScript)

- `src/hub/HubAuth.brs`:
  ```brightscript
  ' Hub authentication and session management
  ' Uses RokuStorage for persistence

  function hubSignIn(hubUrl as String, username as String, password as String) as Boolean
    request = {
      url: hubUrl + "/api/v1/auth/login",
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: { username: username, password: password }
    }
    response = fetch(request)
    if response.status <> 200 then return false

    session = {
      accessToken: response.body.access_token,
      refreshToken: response.body.refresh_token,
      expiresAt: response.body.expires_in,
      userId: response.body.user_id
    }

    HubSessionSet(session)
    return true
  end function

  function hubListServers() as Object
    session = HubSessionGet()
    if session = invalid then return []

    response = fetch({
      url: m.hubUrl + "/api/v1/me/servers",
      headers: { "Authorization": "Bearer " + session.accessToken }
    })
    if response.status <> 200 then return []

    return response.body.servers
  end function

  function HubSessionSet(session as Object)
    ' Persist to RokuStorage
    store("hub_session", session)
  end function

  function HubSessionGet() as Object
    return getStorage("hub_session")
  end function
  ```

#### 2. Hub Config Module

- `src/hub/HubConfig.brs`:
  ```brightscript
  ' Hub mode configuration
  ' m.hubUrl must be set before use

  function HubGetEffectiveUrl(path as String) as String
    activeServer = m.activeServer
    if activeServer = invalid then
      return m.serverUrl + path
    end if

    if m.connectionMode = "relay" and activeServer.DoesExist("relayHostname") then
      return m.hubUrl + "/api/v1/relay/" + activeServer.serverId + path
    end if

    return activeServer.hostname + path
  end function

  function HubGetAuthHeader() as String
    session = HubSessionGet()
    if session = invalid then return ""
    return "Bearer " + session.accessToken
  end function
  ```

#### 3. Hub Settings Screen

- Modify or create `src/views/HubSettings.brs`:
  - "Hub URL" text field
  - "Username" + "Password" fields
  - "Sign In" button
  - "Sign Out" button
  - "Claimed Servers" list (fetched from hub API)
  - Server selection (radio or checkmark)
  - Connection mode: Direct / Relay (radio)

#### 4. Relay-Aware HLS Playback

- Modify `src/player/HlsPlayer.brs`:
  - When `HubConfig.connectionMode = "relay"`, use `HubGetEffectiveUrl()` for HLS manifest
  - Inject `Authorization` and `X-Server-Id` headers for relay requests
  - For relay HLS, the manifest URL goes through the hub relay endpoint

#### 5. Persistent Storage

- Use existing `RokuStorage` pattern (key-value store)
- Keys: `hub_url`, `hub_session`, `active_server_id`, `connection_mode`

#### 6. Unit / Integration Tests

- `tests/hub/HubAuth.test.brs`:
  - `test_signIn_returns_true_on_success`
  - `test_signIn_returns_false_on_failure`
  - `test_listServers_returns_array`

- `tests/hub/HubConfig.test.brs`:
  - `test_getEffectiveUrl_direct_mode`
  - `test_getEffectiveUrl_relay_mode`

### Documentation

- Update `README.md` with hub-mode feature list
- Document how to configure hub URL in Roku settings

## 4. Approach

1. Read `plans/expansion/m.0-phase-m.md` for the claim-flow contract
2. Explore existing BrightScript patterns (storage, API calls, screens)
3. Branch: `git checkout -b m.3-roku-hub`
4. Implement HubAuth and HubConfig modules
5. Add hub settings screen
6. Make HLS player relay-aware
7. Write tests
8. Verification: run BrightScript linter or tests if present
9. Commit + PR + merge
10. Return to master

## 5. Tests (REQUIRED)

1. `HubAuth` — signIn success/failure, listServers
2. `HubConfig` — effective URL resolution for direct and relay modes

## 6. Acceptance criteria

- [ ] Hub URL configurable in settings
- [ ] User can sign in to hub with credentials
- [ ] User sees list of claimed servers
- [ ] User can select active server
- [ ] Connection mode toggle (direct/relay) works
- [ ] HLS playback works in relay mode
- [ ] Session persists across app restart (via RokuStorage)
- [ ] Tests pass (check `Makefile` or test runner)
- [ ] README updated
- [ ] Git ritual executed; postcondition checks PASS

## 7. Git ritual

```bash
cd /home/sites/phlex-roku-client
git status --short
git branch --show-current    # MUST be 'master'
git checkout -b m.3-roku-hub

# Do the work

git add src/hub/ src/views/ src/player/ tests/ README.md
git status --short

# Run BrightScript tests (check Makefile or test runner)
make test 2>&1 | tail -20

git commit -m "M.3: add hub-mode to Roku client (BrightScript)"

unset GITHUB_TOKEN
git push -u origin m.3-roku-hub
gh pr create \
  --title "M.3: Roku hub-mode — hub auth, server picker, relay-aware HLS" \
  --body "Implements hub-mode for phlex-roku-client per plans/expansion/m.3-roku-hub.md"
gh pr merge --squash --delete-branch

git checkout master && git pull
git branch -d m.3-roku-hub
git log --oneline -1
git branch --list 'm.3-*'   # MUST be empty
```
