# Review — Step B.7 (Hub signup/login/dashboard MVP)

The implementation has been merged into `detain/phlex-hub`. Re-verify
without modifying code.

## 1. Re-read

- `plans/expansion/b.7-hub-portal-mvp.md`
- `plans/expansion/b.1-shared-design.md` §4.4 (the `JwtClaims` shape
  the hub `JwtHandler` consumes — must match)
- Diff of the squashed commit:
  ```bash
  cd /home/sites/phlex-hub
  git show --stat HEAD
  ```

## 2. Re-run the §0.4 minimum bar

```bash
cd /home/sites/phlex-hub
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'Auth|Middleware|Controllers'   # ≥ 85 %
./vendor/bin/phpstan analyze --no-progress
./vendor/bin/phpcs --standard=PSR12 src/
./vendor/bin/psalm --no-progress
find src -name '*.php' -exec php -l {} \; 2>&1 | grep -v 'No syntax errors'
```

Test count MUST grow by at least 24.

## 3. Verify `JwtClaims` is consumed (not duplicated)

```bash
cd /home/sites/phlex-hub
grep -nE 'use Phlex\\Shared\\Auth\\JwtClaims' src/Auth/JwtHandler.php
# MUST match — the hub-side JwtHandler must import the shared DTO.
grep -nE 'JwtClaims::fromPayload\(' src/Auth/JwtHandler.php
# MUST match — the deserialization site.
grep -nE 'return.*JwtClaims' src/Auth/JwtHandler.php
# MUST match — validateToken returns ?JwtClaims, not array.
```

## 4. Manual end-to-end smoke (requires test DB)

```bash
cd /home/sites/phlex-hub
if [ -n "$HUB_TEST_DB_NAME" ]; then
  php scripts/run-migrations.php
  php public/index.php start >/tmp/hub-review.log 2>&1 &
  HUB_PID=$!
  sleep 2

  # Signup
  curl -i -s -X POST http://localhost:8800/api/v1/auth/signup \
       -H 'Content-Type: application/x-www-form-urlencoded' \
       --data 'username=review&email=review@example.com&password=review-password-12345' \
       | tee /tmp/hub-signup-out.txt
  grep -q '"access"' /tmp/hub-signup-out.txt && echo "signup OK" || echo "signup FAIL"

  # Extract access token
  ACCESS=$(grep -oE '"access":"[^"]+' /tmp/hub-signup-out.txt | cut -d'"' -f4)

  # Hit /api/v1/me with the token
  curl -i -s -H "Authorization: Bearer $ACCESS" http://localhost:8800/api/v1/me \
       | tee /tmp/hub-me-out.txt
  grep -q '"sub"' /tmp/hub-me-out.txt && echo "me OK" || echo "me FAIL"

  # /my-servers without the cookie should redirect
  curl -i -s http://localhost:8800/my-servers | head -3
  # Expect HTTP 302 to /login

  kill $HUB_PID
else
  echo "Skipped: no HUB_TEST_DB_* env"
fi
```

## 5. Verify acceptance criteria

Walk every checkbox from §7 of `b.7-hub-portal-mvp.md`. For each:

- All files in §3 "Create" exist?
- `/api/v1/auth/*` and `/api/v1/me` routes wired in `src/Application.php`?
  ```bash
  grep -E '/api/v1/auth|/api/v1/me|/signup|/login|/logout|/my-servers' src/Application.php
  ```
- First-user auto-promotion test passes?
  ```bash
  ./vendor/bin/phpunit --filter test_register_auto_promotes_first_user_to_admin
  ```
- AdminMiddleware blocks non-admin requests?
  ```bash
  ./vendor/bin/phpunit --filter AdminMiddlewareTest
  ```

Report PASS / FAIL per criterion with a one-line reason.

## 6. Verify §0.4 doc deliverables

```bash
git show --stat HEAD -- docs/hub/signup-login.md
git show --stat HEAD -- docs/dev/architecture-hub.md
git show --stat HEAD -- docs/reference/env-vars.md
git show --stat HEAD -- docs/reference/api/hub-auth.yaml
git show --stat HEAD -- CHANGELOG.md
git show --stat HEAD -- README.md
```

Each must appear in the diff. `docs/reference/env-vars.md` must
document `JWT_SECRET`, `JWT_ACCESS_TTL`, `JWT_REFRESH_TTL`.

## 7. Verify postconditions

```bash
cd /home/sites/phlex-hub
git status --short                          # MUST be empty
git branch --show-current                   # MUST be 'master'
git log --oneline -1                        # MUST match B.7 squash commit
git branch --list 'b.7-*'                   # MUST be empty
gh run list --repo detain/phlex-hub --branch master --limit 1 --json conclusion
# MUST show conclusion=success
```

## 8. Report

PASS / FAIL with one-line reason per criterion. Do not modify code.
If `JwtClaims` is not the return type of `validateToken`, FAIL the
review — that's the entire point of the cross-repo shared package.
Recommend a "Step B.7 fixup" subagent rather than a full revert.
