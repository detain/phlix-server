# Review — Step B.6 (Hub DB schema + migrations)

The implementation has been merged into `detain/phlex-hub`. Re-verify
without modifying code.

## 1. Re-read

- `plans/expansion/b.6-hub-schema.md`
- Diff of the squashed commit:
  ```bash
  cd /home/sites/phlex-hub
  git show --stat HEAD
  ```

## 2. Re-run the §0.4 minimum bar

```bash
cd /home/sites/phlex-hub
./vendor/bin/phpunit
./vendor/bin/phpstan analyze --no-progress
./vendor/bin/phpcs --standard=PSR12 src/ scripts/
./vendor/bin/psalm --no-progress
find src scripts -name '*.php' -exec php -l {} \; 2>&1 | grep -v 'No syntax errors'
```

## 3. Verify the five migration files exist with the expected tables

```bash
ls /home/sites/phlex-hub/migrations/
# MUST list: 001_users.sql, 002_servers.sql, 003_shared_libraries.sql,
#            004_relay_sessions.sql, 005_webhooks.sql
# MUST NOT list: 001_placeholder.sql

for f in /home/sites/phlex-hub/migrations/*.sql; do
  grep -oE '^CREATE TABLE [a-z_]+' "$f"
done
# MUST list every table from §3 of the step plan: users, servers,
# server_claims, server_heartbeats, shared_libraries, relay_sessions, webhooks
```

## 4. Verify schema doc

```bash
cat /home/sites/phlex-hub/docs/dev/schema.md | head -40
# MUST contain a mermaid ER diagram and a section per table.
grep -c '^## ' /home/sites/phlex-hub/docs/dev/schema.md
# MUST be >= 7 (one section per table)
```

## 5. Migrate to a fresh test DB (if available)

```bash
# If HUB_TEST_DB_* is configured, run against it:
cd /home/sites/phlex-hub
if [ -n "$HUB_TEST_DB_NAME" ]; then
  php scripts/run-migrations.php
  # Then connect to the DB and run:
  #   SHOW TABLES;
  # MUST list: migrations, users, servers, server_claims,
  #            server_heartbeats, shared_libraries,
  #            relay_sessions, webhooks (8 tables)
else
  echo "Skipped: no HUB_TEST_DB_* env"
fi
```

## 6. Verify acceptance criteria

Walk every checkbox from §7 of `b.6-hub-schema.md`. Report PASS / FAIL.

## 7. Verify §0.4 doc deliverables

```bash
git show --stat HEAD -- docs/dev/schema.md
git show --stat HEAD -- CHANGELOG.md
```

Each must appear in the diff.

## 8. Verify postconditions

```bash
cd /home/sites/phlex-hub
git status --short                          # MUST be empty
git branch --show-current                   # MUST be 'master'
git log --oneline -1                        # MUST match B.6 squash commit
git branch --list 'b.6-*'                   # MUST be empty
gh run list --repo detain/phlex-hub --branch master --limit 1 --json conclusion
# MUST show conclusion=success
```

## 9. Report

PASS / FAIL with one-line reason per criterion. If any FAIL,
recommend a "Step B.6 fixup" subagent. The reviewer never edits the
codebase directly.
