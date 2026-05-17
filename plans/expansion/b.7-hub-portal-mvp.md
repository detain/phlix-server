# Step B.7 — Hub: signup / login / dashboard MVP

**Phase:** B (Repo Split & Migration)
**Step:** B.7
**Depends on:** B.6
**Review:** Yes — see `b.7-hub-portal-mvp-review.md`
**Target repo:** `detain/phlex-hub` (local: `/home/sites/phlex-hub/`).
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Build the minimum hub portal that lets a brand-new user sign up, log
in, and reach an authenticated `/my-servers` dashboard page (which is
empty — Phase C.4 fills it).

Concretely, after B.7 lands:

- HTTP routes: `GET /signup`, `POST /signup`, `GET /login`,
  `POST /login`, `POST /logout`, `GET /my-servers`, plus a JSON API
  surface `/api/v1/auth/*` and `/api/v1/me`.
- Smarty templates: `layouts/base.tpl`, `auth/signup.tpl`,
  `auth/login.tpl`, `home/my-servers.tpl`.
- A working JWT-based auth flow:
  - Signup hashes the password with Argon2ID, inserts a `users` row,
    issues an access + refresh JWT.
  - Login validates, issues access + refresh.
  - Logout invalidates the refresh token (cookie or DB-row revoke
    — see §4 step 5 for the decision).
  - Protected route middleware reads the access JWT from cookie or
    `Authorization: Bearer`, validates, populates `$request->user`.
- `JwtClaims` from `phlex-shared` consumed for the payload shape
  (B.3 already shipped `Phlex\Shared\Auth\JwtClaims`; B.7 wires
  it in).
- `AdminMiddleware` ported from `phlex-server` — admin-only routes
  guarded.
- `AuditLogger` ported — every auth action logged.

The MVP **does not** include: server claim flow (Phase C.3),
"My Servers" populating with real data (Phase C.4), shared-with-me
(C.9), or webhooks (L.1). It's the foundation those phases sit on.

## 2. Context (what already exists)

- After B.6: schema in place with `users`, `servers`, etc.
- After B.3: `Phlex\Shared\Auth\JwtClaims` available via composer.
- `/home/sites/phlex/src/Auth/`:
  - `JwtHandler.php` — HS256, issuer `phlex`, 1h access / 7d refresh.
    Hub copies + tweaks: issuer `phlex-hub`, audience `hub`.
  - `UserRepository.php` — Workerman MySQL queries.
    Hub copies + tweaks: drop `username` rate limiting, add
    `is_admin` per migration 001.
  - `AuthManager.php` — orchestrates register/login/refresh, calls
    AuditLogger. Hub copies + drops the Profile/WatchHistory bits.
  - `AuditLogger.php` — security event sink. Hub copies wholesale.
  - `AdminMiddleware.php` (added in A.5) — admin-role guard. Hub
    copies wholesale.
- `/home/sites/phlex/src/Server/WebPortal/`:
  - `PageRenderer.php` — Smarty wrapper.
  - `WebPortalRouter.php` — `/api/v1/libraries` etc.
  - `public/templates/` — Smarty templates with `{extends}` +
    `{include}` patterns and the `|escape:'html'` convention.
  Hub copies the PageRenderer and templates patterns.
- `phlex-shared` v0.2.0:
  - `Phlex\Shared\Auth\JwtClaims` — the value object hub-side
    `JwtHandler` deserializes into.

## 3. Scope — files to create / modify

All paths inside `/home/sites/phlex-hub/`.

### Create

- `src/Auth/JwtHandler.php` — copy of phlex-server's, tweaked:
  - `iss` = `'phlex-hub'`.
  - `aud` defaults to `'hub'` for hub-issued user-session tokens.
  - `validateToken()` returns a `Phlex\Shared\Auth\JwtClaims`
    instance, not an array, via `JwtClaims::fromPayload()`.
  - `createAccessToken(string $userId, array $extraClaims = []): string`
    accepts an optional `scope` parameter that gets stamped into the
    payload.
- `src/Auth/UserRepository.php` — copy + tweaks. Drops the
  email-uniqueness check that's now handled by the unique index
  (just catches the duplicate-key exception).
- `src/Auth/AuthManager.php` — orchestrator. Methods: `register()`,
  `login()`, `refresh()`, `logout()`, `getCurrentUser()`. Dispatches
  PSR-14 events `UserCreated`, `UserLoggedIn`, `UserLoggedOut` (now
  `\Phlex\Shared\Events\Auth\*`).
- `src/Auth/AuditLogger.php` — copy from `phlex-server` (drops the
  plugin-specific `logPluginAction()` since the hub has no plugins
  yet).
- `src/Http/Middleware/AuthMiddleware.php` — reads the access JWT
  from cookie or header, validates via `JwtHandler`, populates
  `$request->user` (a hydrated `UserRepository->findById($claims->sub)`).
- `src/Http/Middleware/AdminMiddleware.php` — copy from
  `phlex-server`. Requires `$request->user->isAdmin === true`.
- `src/Http/Controllers/AuthController.php` — POST `/signup`,
  `/login`, `/logout`, and the JSON API counterparts under
  `/api/v1/auth/*`.
- `src/Http/Controllers/PageController.php` — GET `/signup`,
  `/login`, `/my-servers` — renders Smarty templates.
- `src/Http/Controllers/MeController.php` — GET `/api/v1/me` —
  returns `JwtClaims->toPayload()` of the current user.
- `src/Common/WebPortal/PageRenderer.php` — Smarty wrapper, copied.
- `src/Common/Container/Providers/AuthServicesProvider.php` —
  registers `JwtHandler`, `UserRepository`, `AuthManager`,
  `AuditLogger`.
- `src/Common/Container/Providers/HttpServicesProvider.php` —
  registers `Router`, the four controllers, the two middleware
  classes.
- `public/templates/layouts/base.tpl` — minimal Smarty layout: site
  title, navbar with login/logout, `{block}` for content. Uses
  `|escape:'html'` per the A.5 convention.
- `public/templates/auth/signup.tpl`, `auth/login.tpl` — forms.
- `public/templates/home/my-servers.tpl` — empty-state page: "You
  haven't claimed any servers yet. Claim one from your local Phlex
  install."
- `tests/unit/Auth/JwtHandlerTest.php` — token issue + validate
  round-trip; returns `JwtClaims`; expired token returns null.
- `tests/unit/Auth/UserRepositoryTest.php` — `findByUsername`,
  `findByEmail`, `insert` (mocks `Workerman\MySQL\Connection`).
- `tests/unit/Auth/AuthManagerTest.php` — register, login (success +
  bad password), refresh, logout. Mocks Repository + Logger.
- `tests/unit/Http/Middleware/AuthMiddlewareTest.php` — valid token
  populates `user`; missing / expired / mismatched-iss returns 401.
- `tests/unit/Http/Middleware/AdminMiddlewareTest.php` —
  admin-flag gate.
- `tests/unit/Http/Controllers/AuthControllerTest.php` — happy paths.
- `tests/integration/Auth/SignupLoginFlowTest.php` — end-to-end:
  POST `/signup`, then POST `/login`, then GET `/my-servers` (with
  the cookie) returns 200; GET `/my-servers` without the cookie
  returns 302 → `/login`. **Skipped** if no test DB env is
  configured.
- `docs/hub/signup-login.md` — end-user guide.
- `docs/dev/architecture-hub.md` — expand the stub from B.5:
  document the request lifecycle, auth flow, and the cross-link to
  `phlex-shared`'s `JwtClaims`.
- `docs/reference/api/hub-auth.yaml` — OpenAPI for `/api/v1/auth/*`
  and `/api/v1/me`.

### Modify

- `src/Application.php` — wire the new routes:
  ```php
  $router->get ('/signup',  $container->get(PageController::class));
  $router->post('/signup',  $container->get(AuthController::class));
  $router->get ('/login',   $container->get(PageController::class));
  $router->post('/login',   $container->get(AuthController::class));
  $router->post('/logout',  $container->get(AuthController::class));

  $router->group('/api/v1/auth', function ($r) use ($container) { /* ... */ });

  $router->group('/my-servers', function ($r) use ($container) {
      $r->get('/', $container->get(PageController::class));
  }, [AuthMiddleware::class]);

  $router->get('/api/v1/me', $container->get(MeController::class), [AuthMiddleware::class]);
  ```
- `src/Common/Container/ContainerFactory.php` — add the two new
  providers to `defaultProviders()`.
- `config/logger.php` — add `AUDIT` channel writing to
  `.logs/audit.log`.
- `src/Common/Logger/LogChannels.php` — add `public const AUDIT =
  'audit';`.
- `composer.json` — no changes (all deps already in via B.5).
- `docs/reference/env-vars.md` — add `JWT_SECRET` (hub-side; required
  in prod), `JWT_ACCESS_TTL`, `JWT_REFRESH_TTL`.
- `CHANGELOG.md` entry:
  ```markdown
  ## [Unreleased]
  ### Added
  - User signup, login, logout, and `/my-servers` dashboard MVP.
  - JWT auth using the shared `Phlex\Shared\Auth\JwtClaims` shape.
  - `AuthMiddleware` and `AdminMiddleware`.
  - `AuditLogger` (audit channel writing to `.logs/audit.log`).
  - PSR-14 dispatch for `UserCreated`, `UserLoggedIn`, `UserLoggedOut` events using the shared FQCNs.
  ```
- `README.md` — Quick-start now ends with: `Visit
  http://localhost:8800/signup to create your first account.`

### Delete

- None.

## 4. Approach

1. **Pre-flight.** Clean master on `/home/sites/phlex-hub`.
2. **Branch:** `git checkout -b b.7-hub-portal-mvp`.
3. **Port the Auth code** from `phlex-server` with the tweaks listed
   in §3. Each file: copy + edit namespace + edit imports + adjust
   for hub-specific defaults (issuer `phlex-hub`, no profiles, no
   parental controls).
4. **Wire `JwtClaims`.** The server-side `JwtHandler` continues to
   return arrays today; the hub's `JwtHandler` returns
   `JwtClaims`. This is the point we prove the shared DTO design
   from B.1 §4.4 works.
5. **Logout strategy.** Two options:
   - **(A)** Client-side cookie clear only. Refresh tokens are
     issued but the hub doesn't track them for revocation.
   - **(B)** Server-side `revoked_refresh_tokens` table; logout
     inserts the `jti` of the current refresh token.
   **B.7 picks (A) for MVP.** It's simpler and matches the
   phlex-server pattern. (B) is a Phase L hardening task. Document
   this choice in `docs/dev/architecture-hub.md`.
6. **Smarty templates.** Use the same `|escape:'html'` convention
   from phlex-server (A.5 lesson #7 in SESSION_HANDOFF.md). No
   global escape toggle. CSRF deliberately not implemented for the
   MVP — JWT-Bearer auth header isn't auto-attached cross-origin, so
   the typical CSRF vector is closed (per A.5's documented stance).
   Document the choice in `docs/hub-admin/security.md` (stub if no
   real file).
7. **AdminMiddleware bootstrap.** Same as `phlex-server`: the first
   user to register is auto-promoted to admin via a DB transaction
   on the `users` insert. This matches SESSION_HANDOFF.md decision
   #7.
8. **Write the tests.** Mock the DB connection with
   `$this->createMock(Workerman\MySQL\Connection::class)`. Integration
   test boots the Workerman app with `$loop = false` (one-shot
   request mode) against a transient test DB.
9. **Verification bar.**
10. **Doc updates.**
11. **Commit + PR + merge.**

## 5. Tests (REQUIRED — §0.4 minimum bar)

Unit tests — coverage ≥ 85 % on every new file:

1. `JwtHandlerTest::test_create_access_token_round_trips_jwt_claims`.
2. `JwtHandlerTest::test_validate_expired_token_returns_null`.
3. `JwtHandlerTest::test_validate_wrong_iss_returns_null`.
4. `JwtHandlerTest::test_validate_wrong_aud_returns_null`.
5. `JwtHandlerTest::test_scope_round_trips_through_claims`.
6. `UserRepositoryTest::test_find_by_email_returns_user_record`.
7. `UserRepositoryTest::test_find_by_email_returns_null_when_missing`.
8. `UserRepositoryTest::test_insert_user_returns_inserted_id`.
9. `UserRepositoryTest::test_insert_user_duplicate_email_throws`.
10. `AuthManagerTest::test_register_creates_user_and_dispatches_user_created_event`.
11. `AuthManagerTest::test_register_auto_promotes_first_user_to_admin`.
12. `AuthManagerTest::test_login_validates_password_and_returns_tokens`.
13. `AuthManagerTest::test_login_with_bad_password_returns_null_and_audits`.
14. `AuthManagerTest::test_refresh_issues_new_access_token`.
15. `AuthMiddlewareTest::test_missing_token_returns_401_for_api_route`.
16. `AuthMiddlewareTest::test_missing_token_redirects_for_page_route`.
17. `AuthMiddlewareTest::test_valid_token_populates_request_user`.
18. `AuthMiddlewareTest::test_expired_token_returns_401`.
19. `AdminMiddlewareTest::test_non_admin_returns_403`.
20. `AdminMiddlewareTest::test_admin_passes_through`.
21. `AuthControllerTest::test_signup_creates_user_and_sets_cookies`.
22. `AuthControllerTest::test_login_success_sets_cookies`.
23. `AuthControllerTest::test_logout_clears_cookies`.

Integration test:

24. `SignupLoginFlowTest::test_end_to_end_signup_then_login_then_protected_route`
    — exercises the full chain against a transient test DB.

**Coverage target:** ≥ 85 % on `src/Auth/`, `src/Http/Middleware/`,
`src/Http/Controllers/`.

**Integration boundary:** signup → login → protected route crosses
the DB + HTTP + JWT layers. The integration test above satisfies
the §0.4 requirement.

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **Public HTTP/WS API** → `docs/reference/api/hub-auth.yaml`
  (OpenAPI source) + an autogenerated `docs/reference/api.md` line.
- **"A configurable env var or `config/*.php` key"** →
  `docs/reference/env-vars.md` updated with `JWT_SECRET`,
  `JWT_ACCESS_TTL`, `JWT_REFRESH_TTL`.
- **Hub functionality (Phase B+)** → end-user
  `docs/hub/signup-login.md` AND developer
  `docs/dev/architecture-hub.md` (expanded from B.5's stub).
- **"User-visible behavior change"** → first user-visible feature on
  the hub; CHANGELOG entry per §3.
- **"Anything"** → README Status updates to "scaffolding +
  signup/login MVP".

PHPDoc per §0.4 on every new public class/method. `JwtClaims`-aware
classes carry pointers to `Phlex\Shared\Auth\JwtClaims` for
cross-repo readers.

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] All files listed in §3 "Create" exist and pass linting.
- [ ] All files listed in §3 "Modify" updated per the description.
- [ ] `JwtHandler::validateToken()` returns
      `?Phlex\Shared\Auth\JwtClaims`, not an array.
- [ ] `AuthMiddleware` populates `$request->user` from the claims'
      `sub` and `UserRepository::findById()`.
- [ ] First-user auto-promotion to admin works (the
      `AuthManagerTest::test_register_auto_promotes_first_user_to_admin`
      test passes).
- [ ] `./vendor/bin/phpunit` — green; ≥ 24 new tests; **all** unit
      tests pass without skips; integration test skipped only when
      no test DB env is set (and the skip reason is documented).
- [ ] Coverage of `src/Auth/`, `src/Http/Middleware/`,
      `src/Http/Controllers/` ≥ 85 % each.
- [ ] `./vendor/bin/phpstan analyze --no-progress` at level 9 —
      `[OK] No errors`.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] `./vendor/bin/psalm --no-progress` — clean.
- [ ] `find src -name '*.php' -exec php -l {} \;` — no syntax
      errors.
- [ ] `php public/index.php start` boots; manual curl smoke:
      ```bash
      curl -i -X POST http://localhost:8800/api/v1/auth/signup \
           -d 'username=alice&email=a@example.com&password=correct-horse-battery-staple'
      # Expect 201 with access + refresh JWTs in body or cookies.
      curl -i -X POST http://localhost:8800/api/v1/auth/login \
           -d 'email=a@example.com&password=correct-horse-battery-staple'
      # Expect 200 with new JWTs.
      ```
- [ ] PHPDoc on every new public class/method.
- [ ] `docs/hub/signup-login.md`,
      `docs/dev/architecture-hub.md`,
      `docs/reference/env-vars.md`,
      `docs/reference/api/hub-auth.yaml` all updated.
- [ ] CHANGELOG.md has the B.7 entry.
- [ ] Git ritual §8 executed; postcondition checks PASS.

## 8. Git ritual (copy of master plan §11.4, targeting the hub repo)

```bash
# ─── 0. PRECONDITION ───
cd /home/sites/phlex-hub
git status --short                          # MUST be empty
git branch --show-current                   # MUST be 'master'
git pull --ff-only origin master

# ─── 1. Branch ───
git checkout -b b.7-hub-portal-mvp

# ─── 2. Do the work — write every file in §3 ───

# ─── 3. Verify (§0.4 minimum bar) ───
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'Auth|Middleware|Controllers'
./vendor/bin/phpstan analyze --no-progress
./vendor/bin/phpcs --standard=PSR12 src/
./vendor/bin/psalm --no-progress
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# Boot smoke (with a test DB available)
if [ -n "$HUB_TEST_DB_NAME" ]; then
  php scripts/run-migrations.php
  php public/index.php start >/tmp/hub-b7-smoke.log 2>&1 &
  HUB_PID=$!
  sleep 2
  curl -s -X POST http://localhost:8800/api/v1/auth/signup \
       -H 'Content-Type: application/x-www-form-urlencoded' \
       -d 'username=smoke&email=smoke@example.com&password=correct-horse-battery-staple' \
       | grep -q '"access"'
  RC=$?
  kill $HUB_PID
  test $RC -eq 0 || { echo "STOP: signup smoke failed"; cat /tmp/hub-b7-smoke.log; exit 1; }
fi

# ─── 4. (Caliber not yet on this repo) ───
git add -A

# ─── 5. Commit ───
git commit -m "Step B.7: hub signup/login/dashboard MVP; consume JwtClaims from phlex-shared"

# ─── 6. CRITICAL ───
unset GITHUB_TOKEN

# ─── 7. PR + merge ───
gh pr create \
  --title "Step B.7: hub signup/login/dashboard MVP" \
  --body  "Adds /signup, /login, /logout, /my-servers, /api/v1/auth/*, /api/v1/me; AuthMiddleware + AdminMiddleware; AuditLogger; consumes Phlex\\Shared\\Auth\\JwtClaims. Phase B portal complete. Implements step B.7 of PHLEX_EXPANSION_PLAN.md (run inside detain/phlex-hub)."
gh pr merge --squash --delete-branch

# ─── 8. Return to master ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION ───
git status --short                          # MUST be empty
git branch --show-current                   # MUST be 'master'
git log --oneline -1                        # MUST show the new squashed commit
git branch --list 'b.7-*'                   # MUST be empty
gh run list --repo detain/phlex-hub --branch master --limit 1 --json conclusion | grep '"conclusion":"success"'
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs `b.7-hub-portal-mvp-review.md`. Two
non-obvious points the reviewer should specifically check:

1. **`JwtClaims` is actually returned**, not an array. Grep
   `validateToken` in `src/Auth/JwtHandler.php` for `JwtClaims`.
2. **First-user auto-promotion works.** Without it the hub has no
   admin and `AdminMiddleware` blocks every admin route forever.
