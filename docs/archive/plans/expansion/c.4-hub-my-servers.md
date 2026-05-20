# Step C.4 — Hub: "My Servers" dashboard

**Phase:** C (Server↔Hub Pairing & Remote Access)
**Step:** C.4
**Depends on:** C.3
**Review:** Yes — see `c.4-hub-my-servers-review.md`
**Target repo:** `detain/phlex-hub` (local: `/home/sites/phlex-hub/`)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Build the hub-side "My Servers" dashboard page that:

1. Shows the logged-in user a list of all their claimed servers
2. Displays server name, version, status (online/offline), last-seen
3. Shows hostname candidates (for direct access) and relay status
4. Provides "Claim a Server" UI that links to the pairing flow
5. Provides "Remove Server" action with confirmation
6. Shows library counts and server capabilities from heartbeat data

## 2. Context (what already exists)

- After C.3: hub registry endpoints at `/api/v1/servers/{id}/*` working
- After B.7: hub portal scaffolded with Auth, UserRepository, JWT,
  Smarty templates, `PageController`, `PageRenderer`
- `/home/sites/phlex-hub/src/Hub/ServerInfoHandler.php` — from C.3,
  returns `ServerInfoDto` for a user
- `/home/sites/phlex-hub/public/templates/home/my-servers.tpl` — the empty
  stub left from B.7

## 3. Scope — files to create / modify

All paths inside `/home/sites/phlex-hub/`.

### Create

#### Server List API

- `src/Server/Http/Controllers/ServerListController.php` — JSON API
  for the My Servers page:

  ```
  GET /api/v1/me/servers
  Authorization: Bearer <user-session-jwt>

  Response: { "servers": [ServerInfoDto, ...] }
  ```

  Returns all `ServerInfoDto` entries for the authenticated user via
  `ServerInfoHandler::getServersForUser()`.

- `src/Server/Http/Controllers/ServerManageController.php` — manage
  a specific server:

  ```
  DELETE /api/v1/me/servers/{id}
  Authorization: Bearer <user-session-jwt>

  Response: 204 No Content
  ```

  Checks that `$serverId` belongs to the authenticated user before
  deleting. Returns `403` if not the owner.

  ```
  GET /api/v1/me/servers/{id}/access-info
  Authorization: Bearer <user-session-jwt>

  Response: {
    "server_id": "...",
    "direct_url": "https://192.168.1.100:32400",
    "relay_url": "https://abc123.phlex.media",
    "relay_active": false
  }
  ```

  Returns the best available access URL for the client app to use.
  Prefers direct URL if `hostname_candidates` contains a publicly
  reachable address; falls back to relay URL.

#### New Smarty Templates

- `public/templates/home/my-servers.tpl` — rewrite the B.7 stub with
  full content:

  ```smarty
  {extends file="layouts/base.tpl"}

  {block name="content"}
  <div class="my-servers">
    <h1>My Servers</h1>

    <div class="server-list">
      {foreach $servers as $server}
        <div class="server-card" data-server-id="{$server.serverId}">
          <div class="server-status status-{$server.status}"></div>
          <div class="server-info">
            <h2>{$server.serverName|escape:'html'}</h2>
            <p class="server-version">Phlex {$server.version|escape:'html'}</p>
            <p class="server-last-seen">
              {if $server.lastSeenAt}
                Last seen: {$server.lastSeenAt|date_format:'%Y-%m-%d %H:%M'}
              {else}
                Never connected
              {/if}
            </p>
            <p class="server-hostnames">
              {foreach $server.hostnameCandidates as $hostname}
                <code>{$hostname|escape:'html'}</code>
              {/foreach}
            </p>
            {if $server.capabilities}
              <ul class="server-capabilities">
                {foreach $server.capabilities as $cap}
                  <li class="capability cap-{$cap|escape:'html'}">{$cap|escape:'html'}</li>
                {/foreach}
              </ul>
            {/if}
            <div class="server-actions">
              <button class="btn-remove" data-server-id="{$server.serverId}">Remove</button>
            </div>
          </div>
        </div>
      {foreachelse}
        <div class="empty-state">
          <p>You haven't claimed any servers yet.</p>
          <p>To get started, run <code>php scripts/pair-with-hub.php</code>
             on your Phlex server and enter the claim code below.</p>
        </div>
      {/foreach}
    </div>
  </div>
  {/block}
  ```

- `public/templates/home/claim-server.tpl` — the "Claim a Server" page:

  ```smarty
  {extends file="layouts/base.tpl"}

  {block name="content"}
  <div class="claim-server">
    <h1>Claim a Server</h1>
    <form id="claim-form" method="post" action="/api/v1/server-claims/claim">
      <label for="claim_code">Claim Code</label>
      <input type="text" id="claim_code" name="claim_code"
             placeholder="ABCD-1234" pattern="[A-Z2-9]{4}-[A-Z2-9]{4}"
             maxlength="9" required autocomplete="off" />
      <button type="submit">Claim Server</button>
    </form>
    <div id="claim-result"></div>
  </div>
  {/block}
  ```

- `public/templates/partials/server-card.tpl` — partial for a single
  server card (extracted from my-servers.tpl for reusability)

#### JavaScript (Client-Side)

- `public/assets/js/my-servers.js` — interactive behavior for the My
  Servers page:

  ```javascript
  window.PhlexApp = window.PhlexApp || {};

  window.PhlexApp.MyServersPage = {
    init() {
      // Remove server button handler
      document.querySelectorAll('.btn-remove').forEach(btn => {
        btn.addEventListener('click', this.handleRemove.bind(this));
      });
    },

    async handleRemove(e) {
      const serverId = e.target.dataset.serverId;
      if (!confirm('Remove this server? This cannot be undone.')) return;

      const resp = await fetch(`/api/v1/me/servers/${serverId}`, {
        method: 'DELETE',
        headers: { 'Authorization': `Bearer ${getAccessToken()}` }
      });

      if (resp.ok) {
        e.target.closest('.server-card').remove();
      } else {
        alert('Failed to remove server');
      }
    }
  };

  document.addEventListener('DOMContentLoaded', () => {
    window.PhlexApp.MyServersPage.init();
  });
  ```

#### Server Removal Confirmation

- The "Remove" button on each server card triggers a confirmation dialog
  before sending `DELETE /api/v1/me/servers/{id}`.
- Successful removal removes the card from the DOM with a fade animation.
- Empty state is shown if all servers are removed.

#### Unit Tests

- `tests/Unit/Server/Http/Controllers/ServerListControllerTest.php`
- `tests/Unit/Server/Http/Controllers/ServerManageControllerTest.php`

#### Documentation

- `docs/hub/my-servers.md` — end-user guide for the My Servers page
- `docs/hub/claim-server.md` — end-user guide for the claim flow

### Modify

- `src/Server/Http/Router.php` — add new routes:

  ```php
  // JSON API
  $router->get ('/api/v1/me/servers',           ServerListController::class,  [AuthMiddleware::class]);
  $router->delete('/api/v1/me/servers/{id}',     ServerManageController::class, [AuthMiddleware::class]);
  $router->get ('/api/v1/me/servers/{id}/access-info', ServerManageController::class, [AuthMiddleware::class]);

  // Page routes
  $router->get('/my-servers',  PageController::class);
  $router->get('/claim-server', PageController::class);  // serves claim-server.tpl
  ```

- `src/Server/Http/Controllers/PageController.php` — add handlers for
  `GET /my-servers` (renders `my-servers.tpl` with `servers` data) and
  `GET /claim-server`

- `src/Common/Container/Providers/HttpServicesProvider.php` — register
  `ServerListController`, `ServerManageController`

- `public/assets/css/app.css` — add styles for `.server-card`,
  `.server-status`, `.server-capabilities`, `.empty-state`

- `CHANGELOG.md` entry

### Delete

- None.

## 4. Approach

1. **Pre-flight.** Clean master on `/home/sites/phlex-hub`.
2. **Branch:** `git checkout -b c.4-hub-my-servers`.
3. **Write controllers** — `ServerListController` and
   `ServerManageController` with their JSON API endpoints.
4. **Wire routes** in Router.
5. **Rewrite `my-servers.tpl`** — replace the B.7 stub with real content
   that loops over `$servers`.
6. **Write `claim-server.tpl`** — the server claim entry page.
7. **Write `my-servers.js`** — client-side remove-server behavior.
8. **Add CSS styles** for server cards.
9. **Write tests.**
10. **Verification bar.**
11. **Doc updates.**
12. **Commit + PR + merge.**

## 5. Tests (REQUIRED — §0.4 minimum bar)

Unit tests (coverage ≥ 85 % on new controllers):

1. `ServerListControllerTest::test_returns_servers_for_authenticated_user`
2. `ServerListControllerTest::test_returns_empty_array_when_no_servers`
3. `ServerListControllerTest::test_unauthenticated_returns_401`
4. `ServerManageControllerTest::test_delete_owned_server_returns_204`
5. `ServerManageControllerTest::test_delete_other_users_server_returns_403`
6. `ServerManageControllerTest::test_get_access_info_returns_best_url`
7. `ServerManageControllerTest::test_get_access_info_prefers_direct`

**Coverage target:** `src/Server/Http/Controllers/ServerListController`,
`ServerManageController` ≥ 85 %.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **Hub functionality** → `docs/hub/my-servers.md` (new),
  `docs/hub/claim-server.md` (new)
- **User-visible behavior change** → CHANGELOG entry

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] `GET /api/v1/me/servers` returns the authenticated user's servers
      as `ServerInfoDto` array
- [ ] `DELETE /api/v1/me/servers/{id}` removes the server only if owned by
      the user
- [ ] `GET /api/v1/me/servers/{id}/access-info` returns the best available
      access URL
- [ ] `my-servers.tpl` renders all server data (name, version, status,
      last-seen, hostnames, capabilities)
- [ ] Empty state shown when user has no servers
- [ ] "Remove" button triggers confirmation and removes server on confirm
- [ ] `claim-server.tpl` form submits to correct endpoint
- [ ] `./vendor/bin/phpunit` — green; ≥ 7 new tests
- [ ] Coverage of new controllers ≥ 85 %
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — `[OK] No errors`
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean
- [ ] `docs/hub/my-servers.md` and `docs/hub/claim-server.md` created
- [ ] CHANGELOG entry added
- [ ] Git ritual §8 executed; postcondition checks PASS

## 8. Git ritual (copy of master plan §11.4, targeting hub repo)

```bash
# ─── 0. PRECONDITION ───
cd /home/sites/phlex-hub
git status --short
git branch --show-current
git pull --ff-only origin master

# ─── 1. Branch ───
git checkout -b c.4-hub-my-servers

# ─── 2. Do the work ───

# ─── 3. Verify ───
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'Server'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync ───
git add -A

# ─── 5. Commit ───
git commit -m "Step C.4: hub My Servers dashboard and server management API"

# ─── 6. CRITICAL ───
unset GITHUB_TOKEN

# ─── 7. PR + merge ───
gh pr create \
  --title "Step C.4: hub My Servers dashboard and server management API" \
  --body  "Implements GET /api/v1/me/servers, DELETE /api/v1/me/servers/{id}, My Servers Smarty page, claim-server page. Part of Phase C (Step C.4 of PHLEX_EXPANSION_PLAN.md)."
gh pr merge --squash --delete-branch

# ─── 8. Return to master ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION ───
git status --short
git branch --show-current
git log --oneline -1
git branch --list 'c.4-*'
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs `c.4-hub-my-servers-review.md`.

Non-obvious point: `access-info` must **not** expose relay URL if relay
is not active (Phase C.6). The `relay_active` field comes from the
heartbeat's `ServerInfoDto.status` or a separate relay session table
(added in C.6).
