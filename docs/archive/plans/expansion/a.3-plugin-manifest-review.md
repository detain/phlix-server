# Review — Step A.3 (plugin manifest specification)

The implementation has been merged. Re-verify without modifying code.

## 1. Re-read

- `plans/expansion/a.3-plugin-manifest.md`
- Diff of the squashed commit:
  ```bash
  git show --stat HEAD
  git log -1 --format=%H
  ```

## 2. Re-run the §0.4 minimum bar

```bash
cd /home/sites/phlex
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text 2>/dev/null | grep -E 'Plugins'   # ≥ 85 %
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name '*.php' -exec php -l {} \; 2>&1 | grep -v 'No syntax errors'
```

Additionally lint the JSON Schema against the happy-path fixtures:

```bash
npx -y ajv-cli validate -s docs/plugins/manifest.schema.json \
                          -d tests/Fixtures/Plugins/valid-lastfm.json
npx -y ajv-cli validate -s docs/plugins/manifest.schema.json \
                          -d tests/Fixtures/Plugins/valid-oidc.json
```

Both must print `valid`.

## 3. Verify acceptance criteria

Walk every checkbox from §7 of `a.3-plugin-manifest.md`. For each:

- Enum cases match master plan §5?
  ```bash
  grep -E 'case ' src/Plugins/ManifestType.php
  ```
  Expected eleven cases — confirm one-for-one against the §5 table.
- Fixtures present?
  ```bash
  ls tests/Fixtures/Plugins/
  ```
- Manifest value object is immutable (`readonly` properties)?
  ```bash
  grep 'public readonly' src/Plugins/Manifest.php
  ```

PASS / FAIL each.

## 4. Verify §0.4 doc deliverables

```bash
git show --stat HEAD -- docs/plugins/manifest.md
git show --stat HEAD -- docs/plugins/manifest.schema.json
git show --stat HEAD -- docs/plugins/developer-guide.md
git show --stat HEAD -- CHANGELOG.md
```

Open `docs/plugins/manifest.md`; confirm the master plan §5 example
appears verbatim somewhere in the doc and that every schema field has a
short description.

## 5. Verify postconditions

```bash
git status --short                          # MUST be empty
git branch --show-current                   # MUST be 'master'
git log --oneline -1                        # MUST match the A.3 squashed commit
git branch --list 'a.3-*'                   # MUST be empty
```

## 6. Report

PASS / FAIL with one-line reason per criterion. Do not modify code.
