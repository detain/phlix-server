# Step A.5 — Plugin admin UI (Smarty + JSON API)

**Phase:** A (Plugin Foundation & DI)
**Step:** A.5
**Depends on:** A.4
**Review:** Yes — see `a.5-plugin-admin-ui-review.md`
**Target repo:** detain/phlex (local: /home/sites/phlex)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Surface the A.4 loader through the web portal. After A.5 lands:

1. An admin user (role-checked) lands on `/admin/plugins` and sees a
   table of installed plugins with their version, type, and
   enable/disable toggle.
2. From the same page they can paste a `plugin.json` URL to install a
   new plugin; the form POSTs to `/api/v1/admin/plugins/install`.
3. Every enable / disable / install / uninstall action writes an entry
   to the AuditLogger so security events are traceable.

This step does **not** ship the in-product catalog (curated plugin
list) — that lives in the hub (Phase B+). A.5 ships only "install from
URL", which is the lowest-friction path for early plugin authors and
the only flow needed before the hub exists.

## 2. Context (what already exists)

After A.4:

- `Phlex\Plugins\PluginLoader` with the public surface
  (`install`/`enable`/`disable`/`uninstall`/`listInstalled`).
- `Phlex\Plugins\InstalledPlugin` DTO.
- `migrations/003_plugins.sql` schema.
- `var/plugins/` runtime dir (git-ignored).

From earlier:

- `src/Server/Http/{Router,Request,Response}.php`.
- `src/Server/Http/Controllers/AuthController.php` — pattern for an HTTP
  controller. Returns chained `(new Response())->status(...)->json([...])`.
- `src/Server/WebPortal/PageRenderer.php` and
  `public/templates/{layouts,partials}/*.tpl` — the Smarty pattern.
- `src/Common/Logger/AuditLogger.php` — for the security log.
- `Phlex\Auth\UserRepository` — currently has no "role" column.
  **Important:** A.5 introduces a minimal admin check by reading a new
  column `users.is_admin` (tinyint(1) default 0). The first user
  created (via existing register flow) becomes admin automatically;
  subsequent users default to non-admin. This is the minimum viable
  admin gating until Phase D ships the real RBAC.

## 3. Scope — files to create / modify

### Create

- `src/Server/Http/Controllers/PluginAdminController.php` —
  - `GET  /api/v1/admin/plugins` → `[InstalledPlugin]` JSON.
  - `POST /api/v1/admin/plugins/install` body `{url: string}` →
    installed `Manifest` JSON, 201 on success.
  - `POST /api/v1/admin/plugins/{name}/enable` → 200.
  - `POST /api/v1/admin/plugins/{name}/disable` → 200.
  - `DELETE /api/v1/admin/plugins/{name}` → 204.
  - All routes require admin (see §4).
- `src/Server/Http/Middleware/AdminMiddleware.php` — checks
  `$request->userId` resolves to an admin user via
  `UserRepository::findAdminById($id)`.
- `src/Server/Http/Routes/AdminRoutes.php` — registers the routes
  under the `/api/v1/admin` prefix with `AdminMiddleware`.
- `src/Server/WebPortal/Controllers/PluginAdminPageController.php` —
  renders the Smarty page; consumes the loader directly for SSR.
- `public/templates/admin/layout.tpl` — base admin layout.
- `public/templates/admin/plugins/index.tpl` — table view + install
  form.
- `public/templates/admin/plugins/detail.tpl` — per-plugin settings
  view (read-only in A.5; A.6's example plugin doesn't need writable
  settings, so the editable settings form is deferred to D-or-later).
- `public/templates/admin/plugins/install.tpl` — standalone install
  form (used when JS is disabled — the index page also embeds the
  form inline).
- `public/assets/js/admin/plugins.js` — small JS that hits the JSON
  API to do enable/disable without full page reload.
- `migrations/004_admin_user_flag.sql`:
  ```sql
  ALTER TABLE users ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0;
  -- Promote the oldest user (id minimum by created_at) to admin so the
  -- existing single-user installs keep working.
  UPDATE users SET is_admin = 1
   WHERE id = (SELECT id FROM (SELECT id FROM users
                                  ORDER BY created_at ASC LIMIT 1) t);
  ```
- `tests/Unit/Server/Http/Controllers/PluginAdminControllerTest.php`
- `tests/Unit/Server/Http/Middleware/AdminMiddlewareTest.php`
- `tests/Integration/Plugins/AdminRoutesTest.php` — boots a router with
  a mock loader, exercises every endpoint, asserts response shape and
  AuditLogger calls.

### Modify

- `src/Auth/UserRepository.php` — add `findAdminById(string $id):
  ?array` returning the user array or null if not admin.
- `src/Auth/AuthManager.php` — register() flow: if the new user is the
  first ever, promote to admin. (Wrap in a single transaction.)
- `src/Server/Http/Router.php` — confirm `group($prefix, $cb,
  [$middleware])` works as documented; if missing, add it (it is
  documented in AGENTS.md so should already be present — verify, don't
  re-implement).
- `public/index.php` — wire the `AdminRoutes` registrar into the
  container-built application.
- `public/templates/partials/admin-nav.tpl` — new partial for the
  admin layout's sidebar; links Plugins (more entries arrive in
  later phases).
- `CHANGELOG.md` — `Added: plugin admin UI at /admin/plugins. JSON API
  under /api/v1/admin/plugins/* with admin role enforcement (new
  users.is_admin column, migration 004). The first registered user is
  auto-promoted to admin.`
- `AGENTS.md` / `CLAUDE.md` — Caliber regenerates.

### Delete

- None.

## 4. Approach

1. **Admin gating.** Run migration 004 first. Verify the post-migration
   state: one admin promoted, all other users still non-admin.
   `UserRepository::findAdminById()` checks `is_admin = 1` and returns
   the row or `null`. `AdminMiddleware` runs after the existing JWT
   middleware so `$request->userId` is set; if it isn't or
   `findAdminById($id)` returns null, respond `403 Forbidden` JSON.
2. **Controllers.** `PluginAdminController` takes the loader and the
   audit logger as constructor params (resolved via container
   autowiring). Each action:
   - Validates input (`url` for install; route param `name` everywhere
     else).
   - Calls the loader; catches `PluginInstallException`,
     `PluginEnableException`, `PluginNotFoundException` and translates
     to 400 / 422 / 404 with JSON `{error, code, fields?}` shape.
   - Always emits an `AuditLogger::log()` entry with action
     (`plugin.install`, `plugin.enable`, etc.), actor user id, and
     plugin name.
3. **Smarty page.** `PluginAdminPageController::index()` calls
   `$loader->listInstalled()` server-side, assigns to the template,
   renders `admin/plugins/index.tpl`. JS progressively enhances the
   enable/disable buttons.
4. **AdminRoutes registrar.** Returns a callable that takes the router
   and registers the group:
   ```php
   $router->group('/api/v1/admin', function (Router $r) use ($container) {
       $controller = $container->get(PluginAdminController::class);
       $r->get   ('/plugins',                 [$controller, 'index']);
       $r->post  ('/plugins/install',         [$controller, 'install']);
       $r->post  ('/plugins/{name}/enable',   [$controller, 'enable']);
       $r->post  ('/plugins/{name}/disable',  [$controller, 'disable']);
       $r->delete('/plugins/{name}',          [$controller, 'uninstall']);
   }, [$container->get(AdminMiddleware::class)]);
   ```
5. **JS file** is intentionally tiny — fetch with the same JWT the
   user already has; reload the table on success. No framework, vanilla
   ES2023 in line with the existing `public/assets/js/*.js` style.
6. **No editable settings form yet.** The detail page renders settings
   read-only with their `secret: true` values masked. Editable
   settings (which need encrypted storage and a typed form generator)
   are out of scope for A.5 and tracked as a follow-up issue
   referenced in the CHANGELOG.

## 5. Tests (REQUIRED — §0.4 minimum bar)

`PluginAdminControllerTest`:

1. `test_index_returns_plugin_list_as_json`.
2. `test_install_returns_201_with_manifest_on_success`.
3. `test_install_returns_400_on_missing_url`.
4. `test_install_returns_422_on_invalid_manifest_with_field_errors`.
5. `test_enable_returns_200_and_calls_loader`.
6. `test_enable_returns_404_when_plugin_not_found`.
7. `test_disable_returns_200`.
8. `test_uninstall_returns_204`.
9. `test_every_action_logs_to_audit_logger`.

`AdminMiddlewareTest`:

10. `test_passes_through_admin_user`.
11. `test_returns_403_for_non_admin_user`.
12. `test_returns_401_when_no_user_id_on_request`.

**Integration test** (`AdminRoutesTest`, in
`tests/Integration/Plugins/`):

13. `test_install_then_enable_then_disable_then_uninstall_via_http` —
    boots the real router + container with a mocked
    `HttpInstaller` (so no actual download happens) and exercises the
    full HTTP flow against a fixture URL pointing at
    `tests/Fixtures/Plugins/fixture-plugin/`. Asserts every
    side-effect on the DB and the audit log file.

**Coverage target:** ≥ 85 % on `src/Server/Http/Controllers/PluginAdminController.php`,
`src/Server/Http/Middleware/AdminMiddleware.php`,
`src/Server/WebPortal/Controllers/PluginAdminPageController.php`.

**Smarty templates** don't carry executable coverage; covered by the
controller tests rendering the template into a buffer.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **"Public HTTP/WS API"** → add to `docs/reference/api/` (the OpenAPI
  source — create `docs/reference/api/admin-plugins.yaml` even if the
  rest of the OpenAPI tree doesn't exist yet; document each of the
  five endpoints with request/response shapes). Also regenerate
  `docs/reference/api.md` (or seed it if absent).
- **"The plugin API"** → expand
  `docs/plugins/developer-guide.md` with a "Distributing your plugin"
  subsection: how the install-from-URL flow works.
- **End-user docs** → create `docs/plugins/install-from-url.md` and
  `docs/plugins/install-from-catalog.md` (the latter is a stub that
  says "catalog ships in Phase C with the hub"). And
  `docs/plugins/trusted-plugin-list.md` — empty stub for the eventual
  curated list.
- **"Anything"** → `README.md` Status: `* Admin UI for plugin
  install/enable/disable (Phase A.5).`
- **CHANGELOG** → already in §3 Modify.

PHPDoc per §0.4 on every new public class/method.

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] All §3 "Create" files exist.
- [ ] All §3 "Modify" files updated.
- [ ] Migration 004 runs cleanly; the previously-existing user (if
      any) is now `is_admin = 1`; new users default to `is_admin = 0`
      unless they are the first ever.
- [ ] AdminMiddleware blocks non-admins (403) and missing users (401).
- [ ] `./vendor/bin/phpunit` — green.
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — zero new errors.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] `find src -name '*.php' -exec php -l {} \;` — no syntax errors.
- [ ] Coverage of new admin controller / middleware / page controller
      ≥ 85 %.
- [ ] PHPDoc on every new public class/method.
- [ ] OpenAPI `docs/reference/api/admin-plugins.yaml` exists and
      validates as YAML.
- [ ] `docs/plugins/install-from-url.md` exists with copy-pasteable
      steps.
- [ ] CHANGELOG.md updated.
- [ ] Caliber pre-commit hook ran; regenerated agent files staged.
- [ ] Git ritual §8 below executed; postcondition checks PASS.

## 8. Git ritual (copy of master plan §11.4)

```bash
# ─── 0. PRECONDITION: confirm we're starting from clean master ───
cd /home/sites/phlex
git status --short                          # MUST be empty
git branch --show-current                   # MUST be 'master'
git pull --ff-only origin master

# ─── 1. Branch ───
git checkout -b a.5-plugin-admin-ui

# ─── 2. Do the work; add tests; update docs (§0.4); add PHPDocs ───

# ─── 3. Verify (§0.4 minimum bar) ───
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text | grep -E 'PluginAdmin|AdminMiddleware'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync (hook active) ───
git add -A

# ─── 5. Commit — NEW commit, NEVER --amend ───
git commit -m "Step A.5: plugin admin UI + JSON API + admin role gating"

# ─── 6. CRITICAL: drop env-injected token before using gh ───
unset GITHUB_TOKEN

# ─── 7. PR, auto-merge, branch delete ───
gh pr create \
  --title "Step A.5: plugin admin UI" \
  --body  "Adds /admin/plugins Smarty UI and /api/v1/admin/plugins/* JSON API, gated by a new AdminMiddleware that reads users.is_admin (migration 004). Implements step A.5 of PHLEX_EXPANSION_PLAN.md."
gh pr merge --squash --delete-branch

# ─── 8. Return to master with merged PR pulled — REQUIRED END STATE ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION assertions (subagent reports these) ───
git status --short                          # MUST be empty
git branch --show-current                   # MUST be 'master'
git log --oneline -1                        # MUST show the new squashed commit
git branch --list 'a.5-*'                   # MUST be empty
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs `a.5-plugin-admin-ui-review.md`. Reviewer
must additionally smoke-test the UI by browsing to `/admin/plugins`
via `curl` with a forged admin JWT (test fixtures live under
`tests/Fixtures/Auth/`).
