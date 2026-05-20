# Step A.3 — Plugin manifest specification

**Phase:** A (Plugin Foundation & DI)
**Step:** A.3
**Depends on:** A.2
**Review:** Yes — see `a.3-plugin-manifest-review.md`
**Target repo:** detain/phlex (local: /home/sites/phlex)
**Estimated subagent type:** oac:coder-agent (fallback: general-purpose)

## 1. Goal

Ship the **specification** for `plugin.json` — what it looks like, what
fields are required, what enum values are legal — plus the PHP value
object that parses and validates it. This step is pure spec + parser.
There is no loader yet (A.4 owns that), no admin UI (A.5), no example
plugin (A.6). After this step the plugin manifest format is frozen
enough for a developer to start authoring one.

The manifest definition needs to live in two forms:

1. **A JSON Schema** at `docs/plugins/manifest.schema.json` — a
   machine-readable contract that IDEs and external tools can lint
   against.
2. **A human-readable doc** at `docs/plugins/manifest.md` — written for
   the plugin developer audience.

Both are kept in sync by A.3 and continue to be the source of truth for
A.4 / A.5 / A.7.

## 2. Context (what already exists)

- `PHLEX_EXPANSION_PLAN.md` §5 — the canonical manifest example and the
  plugin types table. A.3 codifies §5 into the schema and a parser.
- After A.2: event class FQCNs and manifest aliases
  (`phlex.<area>.<verb>`). A.3's `events` array uses the manifest
  aliases; the alias-to-FQCN mapping happens in A.4.
- No `src/Plugins/` directory yet. A.3 creates it.
- No `docs/plugins/` directory yet. A.3 creates it.
- `composer.json` after A.2 already has `psr/container` and
  `psr/event-dispatcher`. A.3 adds a JSON schema validator dep.

## 3. Scope — files to create / modify

### Create

- `src/Plugins/Manifest.php` — immutable value object. Static
  `fromJson(string $json): self`, static `fromArray(array $data): self`,
  `toArray(): array`, `validate(): array $errors` (returns empty array
  when valid). Properties (all `public readonly`):
  - `string $name` — kebab-case, prefixed `phlex-plugin-`.
  - `string $version` — semver.
  - `string $phlexMinServerVersion` — semver.
  - `string $type` — one of the eleven enum values from §5.
  - `string $entry` — FQCN of the plugin entry class.
  - `array $events` — list of manifest aliases (e.g.,
    `phlex.playback.started`).
  - `array $settings` — keyed map; value shape:
    `{type, required, secret, default}`.
  - `?string $signature` — `sha256:<hex>` or null.
- `src/Plugins/ManifestType.php` — enum class. Cases: `MetadataProvider`,
  `SubtitleProvider`, `AuthProvider`, `LibraryType`, `Notifier`,
  `Scrobbler`, `Tuner`, `TranscoderHook`, `UiTheme`, `ArrIntegration`,
  `AnalyticsSink`. `value` for each is the kebab-case form
  (`metadata-provider` etc.).
- `src/Plugins/ManifestValidationError.php` — DTO holding `string
  $field, string $code, string $message`.
- `src/Plugins/Exception/InvalidManifestException.php` — thrown by
  `Manifest::fromJson()` on parse errors only (validation errors are
  returned via `validate()` so callers can show all errors at once).
- `tests/Unit/Plugins/ManifestTest.php` — see §5.
- `tests/Unit/Plugins/ManifestTypeTest.php` — enum smoke.
- `tests/Fixtures/Plugins/valid-lastfm.json` — happy-path fixture.
- `tests/Fixtures/Plugins/valid-oidc.json` — second happy-path fixture.
- `tests/Fixtures/Plugins/invalid-missing-name.json` — error fixture.
- `tests/Fixtures/Plugins/invalid-bad-type.json` — enum-violation
  fixture.
- `tests/Fixtures/Plugins/invalid-bad-version.json` — semver-violation
  fixture.
- `docs/plugins/manifest.md` — human-readable reference.
- `docs/plugins/manifest.schema.json` — JSON Schema (draft 2020-12).

### Modify

- `composer.json` — add
  `justinrainbow/json-schema:^5.2` (the de-facto PHP JSON Schema
  validator). License: MIT, PHP 7.2+, supports draft 2019-09 which is
  close enough to 2020-12 for our needs and the schema features we use
  are stable.
- `composer.lock` — regenerate.
- `CHANGELOG.md` — `Added: plugin manifest specification
  (docs/plugins/manifest.md) and Phlex\\Plugins\\Manifest value object;
  no loader yet (see Step A.4).`
- `AGENTS.md` / `CLAUDE.md` — Caliber regenerates.

### Delete

- None.

## 4. Approach

1. **Add dependency.** `composer require
   justinrainbow/json-schema:^5.2`.
2. **Author the JSON Schema** at `docs/plugins/manifest.schema.json`.
   Required top-level: `name`, `version`, `phlex_min_server_version`,
   `type`, `entry`. Optional: `events`, `settings`, `signature`.
   - `name`: `^phlex-plugin-[a-z0-9][a-z0-9-]*$`, max length 64.
   - `version` and `phlex_min_server_version`:
     `^\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.\-]+)?$` (semver-ish).
   - `type`: `enum` of the eleven kebab-case values.
   - `entry`: `^[A-Z][A-Za-z0-9_]*(?:\\\\[A-Z][A-Za-z0-9_]*)+$` (FQCN).
   - `events`: array of strings matching
     `^phlex\\.[a-z]+(?:\\.[a-z]+)*$`.
   - `settings`: object whose `additionalProperties` matches the
     setting-value schema (`{type: enum(string,int,bool), required:
     bool, secret: bool, default: any}`).
   - `signature`: `^sha256:[0-9a-f]{64}$` or `null`.
3. **Build the PHP value object** (`src/Plugins/Manifest.php`).
   - `fromJson()` decodes (throws `InvalidManifestException` on
     malformed JSON), then delegates to `fromArray()`.
   - `fromArray()` constructs the readonly value object without
     validation (validation is opt-in via `validate()` so callers can
     decide whether to fail fast or collect errors). Unknown fields are
     ignored but recorded into a private `$unknownFields` array so
     `validate()` can surface a soft warning.
   - `validate()` runs the schema validator against
     `docs/plugins/manifest.schema.json` (path resolved relative to the
     project root via a constant) and returns
     `ManifestValidationError[]`. Empty array = valid.
4. **Enum.** `ManifestType` is a `string` backed PHP 8.1 enum with the
   eleven cases. `Manifest::fromArray()` calls
   `ManifestType::tryFrom($data['type'])`, returns
   `InvalidManifestException` if `null`.
5. **Fixtures.** Five JSON fixtures cover happy path and three failure
   modes. Each is a minimal `plugin.json` (5–15 lines).
6. **Doc.** `docs/plugins/manifest.md` walks through every field with an
   example, then links the schema as the formal spec.
7. **No loader.** A.3 ships **only** the spec + parser. The loader,
   sandbox, signature verification, and event-alias-to-FQCN mapping all
   live in A.4. Importantly, A.3 does **not** introduce
   `Phlex\Plugins\Contract\LifecycleInterface` — that contract lives in
   A.4 where it's actually called.

## 5. Tests (REQUIRED — §0.4 minimum bar)

`tests/Unit/Plugins/ManifestTest.php`:

1. `test_fromJson_parses_valid_lastfm_fixture` — loads
   `tests/Fixtures/Plugins/valid-lastfm.json`, asserts every field.
2. `test_fromJson_parses_valid_oidc_fixture` — second happy-path fixture
   covers an `auth-provider` with secret settings.
3. `test_fromJson_throws_on_malformed_json` —
   `InvalidManifestException`.
4. `test_validate_returns_empty_on_valid_manifest`.
5. `test_validate_returns_error_for_missing_name` — loads
   `invalid-missing-name.json`, asserts one error with `code =
   "required"`, `field = "name"`.
6. `test_validate_returns_error_for_bad_type` —
   `invalid-bad-type.json`, expects `code = "enum"`, `field = "type"`.
7. `test_validate_returns_error_for_bad_version` —
   `invalid-bad-version.json`, expects `code = "pattern"`.
8. `test_signature_can_be_null` — manifest without `signature` survives
   parsing and validation.
9. `test_signature_must_match_sha256_pattern` — synthetic invalid
   signature returns a validation error.
10. `test_unknown_fields_recorded_as_warnings` — feed a manifest with a
    `description` field (not in our schema); `validate()` reports a
    `code = "unknown_field"` error but parsing succeeds.

`tests/Unit/Plugins/ManifestTypeTest.php`:

11. `test_tryFrom_returns_enum_for_each_known_value` — loops through the
    eleven kebab-case values.
12. `test_tryFrom_returns_null_for_unknown_value`.

**Coverage target:** ≥ 85 % on `src/Plugins/**`.

**Integration boundary:** A.3 is pure file parsing — no DB, no HTTP, no
WS, no FFmpeg, no FS watcher. The §0.4 integration requirement is
satisfied by the fixture-driven unit tests (the schema validator is
treated as an external library, not an integration boundary).

## 6. Documentation (REQUIRED — §0.4 doc deliverables matrix)

Matrix rows that apply:

- **"The plugin API"** → create `docs/plugins/developer-guide.md` as a
  short stub linking to `docs/plugins/manifest.md` (the full developer
  guide is authored in A.7; A.3 owns the manifest section).
- **"Anything"** → no `README.md` change yet — plugins aren't usable
  until A.5.
- **CHANGELOG** → already in §3 Modify.

PHPDoc per §0.4 on every new public class/method:
- `Manifest` class docblock: "Immutable value object representing a
  parsed `plugin.json`. See `docs/plugins/manifest.md`." `@since 0.10.0`,
  `@package Phlex\Plugins`.
- Each property: `@var <type> <description>`.
- `validate()`: `@return ManifestValidationError[]`.
- Exception: `@throws InvalidManifestException` on malformed JSON.

## 7. Acceptance criteria (subagent checks every box before claiming done)

- [ ] All files in §3 "Create" exist.
- [ ] All files in §3 "Modify" updated.
- [ ] `composer.json` declares
      `justinrainbow/json-schema:^5.2`.
- [ ] The eleven enum cases in `ManifestType` exactly match the §5
      master plan table.
- [ ] All five fixture files are valid JSON.
- [ ] `./vendor/bin/phpunit` — green.
- [ ] `./vendor/bin/phpstan analyze src/ --level=9` — zero new errors.
- [ ] `./vendor/bin/phpcs --standard=PSR12 src/` — clean.
- [ ] `find src -name '*.php' -exec php -l {} \;` — no syntax errors.
- [ ] Coverage of `src/Plugins/**` ≥ 85 %.
- [ ] PHPDoc on every new public class/method.
- [ ] `docs/plugins/manifest.md` and `docs/plugins/manifest.schema.json`
      exist; the doc references the §5 manifest example verbatim.
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
git checkout -b a.3-plugin-manifest

# ─── 2. Do the work; add tests; update docs (§0.4); add PHPDocs ───

# ─── 3. Verify (§0.4 minimum bar) ───
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-text | grep 'Plugins'
./vendor/bin/phpstan analyze src/ --level=9
./vendor/bin/phpcs --standard=PSR12 src/
find src -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"

# ─── 4. Caliber sync (hook active) ───
git add -A

# ─── 5. Commit — NEW commit, NEVER --amend ───
git commit -m "Step A.3: define plugin manifest schema + Manifest value object"

# ─── 6. CRITICAL: drop env-injected token before using gh ───
unset GITHUB_TOKEN

# ─── 7. PR, auto-merge, branch delete ───
gh pr create \
  --title "Step A.3: plugin manifest specification" \
  --body  "Ships docs/plugins/manifest.md, docs/plugins/manifest.schema.json, and the Phlex\\Plugins\\Manifest value object that parses and validates plugin.json. Loader and admin UI follow in A.4 and A.5. Implements step A.3 of PHLEX_EXPANSION_PLAN.md."
gh pr merge --squash --delete-branch

# ─── 8. Return to master with merged PR pulled — REQUIRED END STATE ───
git checkout master
git pull --ff-only origin master

# ─── 9. POSTCONDITION assertions (subagent reports these) ───
git status --short                          # MUST be empty
git branch --show-current                   # MUST be 'master'
git log --oneline -1                        # MUST show the new squashed commit
git branch --list 'a.3-*'                   # MUST be empty
```

## 9. Reviewer hand-off

Review = Yes. Reviewer runs `a.3-plugin-manifest-review.md` and
additionally lint-checks `docs/plugins/manifest.schema.json` with a
generic JSON Schema linter (e.g., `npx ajv-cli validate -s
docs/plugins/manifest.schema.json -d
tests/Fixtures/Plugins/valid-lastfm.json`).
