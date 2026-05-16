# Review — Step A.2 (PSR-14 event dispatcher)

The implementation has been merged. Re-verify without modifying code.

## 1. Re-read

- `plans/expansion/a.2-event-dispatcher.md`
- Diff of the squashed commit:
  ```bash
  git show --stat HEAD
  git log -1 --format=%H
  ```

## 2. Re-run the §0.4 minimum bar

```bash
cd /home/sites/phlex
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'Common/Events'   # confirm ≥ 85 %
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name '*.php' -exec php -l {} \; 2>&1 | grep -v 'No syntax errors'
```

## 3. Verify acceptance criteria

Walk every checkbox from §7 of `a.2-event-dispatcher.md`. For each:

- All event DTOs present and `readonly`?
  ```bash
  find src/Common/Events -name '*.php' -type f
  grep -l 'readonly' src/Common/Events/Playback/*.php
  ```
- Dispatch sites wired into the three target classes?
  ```bash
  git show HEAD -- src/Session/PlaybackController.php | grep -E '(dispatch|PlaybackStarted)'
  git show HEAD -- src/Media/Library/MediaScanner.php | grep -E '(dispatch|LibraryScan)'
  git show HEAD -- src/Auth/AuthManager.php | grep -E '(dispatch|User(Created|LoggedIn|LoggedOut))'
  ```
- `composer.json` declares Tukio and PSR-14?
  ```bash
  jq -r '.require | to_entries[] | select(.key | test("tukio|event-dispatcher"))' composer.json
  ```

PASS / FAIL each.

## 4. Verify §0.4 doc deliverables

```bash
git show --stat HEAD -- docs/dev/event-reference.md
git show --stat HEAD -- docs/reference/env-vars.md
git show --stat HEAD -- CHANGELOG.md
git show --stat HEAD -- README.md
```

Open `docs/dev/event-reference.md`; confirm every event class shipped in
`src/Common/Events/` has a row in the table. Confirm the manifest aliases
follow the `phlex.<area>.<verb>` convention named in master plan §5.

## 5. Verify postconditions

```bash
git status --short                          # MUST be empty
git branch --show-current                   # MUST be 'master'
git log --oneline -1                        # MUST match the A.2 squashed commit
git branch --list 'a.2-*'                   # MUST be empty
```

## 6. Report

PASS / FAIL with one-line reason per criterion. Do not modify code. If
FAILed, recommend a follow-up fixup subagent or a revert + re-run.
