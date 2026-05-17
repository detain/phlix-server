# Review — Step B.4 (Migrate origin to `detain/phlex-server`)

The implementation has been merged. Re-verify without modifying code.

## 1. Re-read

- `plans/expansion/b.4-migrate-server.md`
- Diff of the squashed commit:
  ```bash
  cd /home/sites/phlex
  git show --stat HEAD
  git log -1 --format=%H
  ```

## 2. Verify the remote migration succeeded

```bash
cd /home/sites/phlex
git remote -v
# MUST show:
#   origin  git@github.com:detain/phlex-server.git (fetch)
#   origin  git@github.com:detain/phlex-server.git (push)

unset GITHUB_TOKEN
gh repo view detain/phlex-server --json defaultBranchRef,pushedAt
# defaultBranchRef.name MUST be 'master'

# Branch parity:
diff <(gh api repos/detain/phlex-server/branches --jq '.[].name' | sort) \
     <(git branch --format='%(refname:short)' | sort)
# MUST be empty diff (every local branch is on the remote)

# Tag parity:
diff <(gh api repos/detain/phlex-server/tags --jq '.[].name' | sort) \
     <(git tag | sort)
# MUST be empty diff
```

## 3. Verify `detain/phlex` was NOT touched

B.4 explicitly leaves the OLD repo alone. B.4b is the archival step.

```bash
unset GITHUB_TOKEN
gh repo view detain/phlex --json pushedAt,isArchived
# isArchived MUST be false (B.4b hasn't run yet)
# pushedAt should be older than detain/phlex-server's pushedAt (no
# new pushes during B.4)
```

## 4. Re-run the §0.4 minimum bar

```bash
cd /home/sites/phlex
./vendor/bin/phpunit
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
./vendor/bin/psalm --no-progress
find src -name '*.php' -exec php -l {} \; 2>&1 | grep -v 'No syntax errors'
```

Each must succeed.

## 5. Verify acceptance criteria

Walk every checkbox from §7 of `b.4-migrate-server.md`. For each:

- README badges and clone-URL refs updated?
  ```bash
  grep -E 'github\.com/detain/phlex(/|\b)' README.md \
    | grep -v 'phlex-server\|phlex-shared\|phlex-hub\|phlex-plugin-example'
  # MUST report only intentional historical references in the migration note.
  ```
- composer.json homepage updated (if previously set)?
  ```bash
  jq '.homepage, .support' composer.json
  ```
- `CHANGELOG.md` has the B.4 entry?
  ```bash
  grep -A4 'Repository moved' CHANGELOG.md
  ```
- No src/ files changed?
  ```bash
  git show --stat HEAD -- src/   # MUST be empty
  git show --stat HEAD -- tests/ # MUST be empty
  ```

Report PASS / FAIL per criterion with a one-line reason.

## 6. Smoke test: clone the new repo from scratch

```bash
cd /tmp
unset GITHUB_TOKEN
git clone git@github.com:detain/phlex-server.git phlex-server-smoke
cd phlex-server-smoke
composer install
./vendor/bin/phpunit          # MUST be green
cd /tmp
rm -rf phlex-server-smoke
```

If this fails, B.4 left the new repo broken — recommend revert.

## 7. Verify postconditions

```bash
cd /home/sites/phlex
git status --short                          # MUST be empty (CALIBER_LEARNINGS.md OK)
git branch --show-current                   # MUST be 'master'
git log --oneline -1                        # MUST match the B.4 squashed commit
git branch --list 'b.4-*'                   # MUST be empty
git remote get-url origin                   # MUST be git@github.com:detain/phlex-server.git
```

CI on the new origin:

```bash
gh run list --repo detain/phlex-server --branch master --limit 1 --json conclusion
# MUST show conclusion=success
```

## 8. Report

PASS / FAIL with one-line reason per criterion. Do not modify code.
If the post-migration `phpunit` regresses, recommend revert of the
B.4 PR and re-attempt (a partial migration is salvageable because the
push to phlex-server is idempotent — only the doc + config commit
needs redoing).
