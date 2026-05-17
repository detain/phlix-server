# Plugin manifest (`plugin.json`) — A.3 reference

Every Phlex plugin ships a `plugin.json` at the root of its package.
This file is the source of truth for the loader (A.4), the admin UI
(A.5), and the signature verifier (A.7+). A.3 defines:

- **The schema** — `docs/plugins/manifest.schema.json` (JSON Schema
  draft 2020-12). IDEs and CI lint manifests against this file.
- **The parser** — `Phlex\Shared\Plugin\Manifest`, an immutable PHP
  value object (shipped in the `detain/phlex-shared` Composer package).
  Parses `plugin.json` into typed properties. The validator
  (`Phlex\Plugins\Manifest\ManifestSchema`) stays in `phlex-server` and
  emits `Phlex\Shared\Plugin\ManifestValidationError` instances. The
  legacy `Phlex\Plugins\Manifest` and `Phlex\Plugins\ManifestValidationError`
  FQCNs remain available as deprecated aliases through 0.11.x.

> **Scope reminder.** A.3 ships the spec and the parser only. The
> loader, sandbox, signature verification, and event-alias→FQCN
> resolution all live in **A.4**.

## Canonical example

```json
{
    "name": "phlex-plugin-lastfm",
    "version": "1.0.0",
    "phlex_min_server_version": "0.10.0",
    "type": "scrobbler",
    "entry": "Phlex\\Plugins\\Lastfm\\Plugin",
    "events": ["phlex.playback.started", "phlex.playback.stopped"],
    "settings": {
        "api_key": { "type": "string", "required": true, "secret": true },
        "api_secret": { "type": "string", "required": true, "secret": true }
    },
    "signature": "sha256:..."
}
```

This is the same example as `PHLEX_EXPANSION_PLAN.md` §5 — keep them in
sync. The two `valid-*.json` fixtures under
`tests/Fixtures/Plugins/` are minimal, working manifests you can copy
to bootstrap a new plugin.

## Field reference

### Required

| Field | Type | Constraints |
| --- | --- | --- |
| `name` | string | Kebab-case. Must start with `phlex-plugin-`. Max 64 chars. Regex `^phlex-plugin-[a-z0-9][a-z0-9-]*$`. |
| `version` | string | Plugin semver. Regex `^\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.\-]+)?$`. |
| `phlex_min_server_version` | string | Minimum supported Phlex server semver. Same regex. |
| `type` | string | One of the eleven values below. |
| `entry` | string | Fully-qualified entry class name. Regex `^[A-Z][A-Za-z0-9_]*(?:\\[A-Z][A-Za-z0-9_]*)+$`. |

### Plugin types (`type` enum)

Mirrors `PHLEX_EXPANSION_PLAN.md` §5 and `Phlex\Shared\Plugin\ManifestType`:

| Value | Purpose |
| --- | --- |
| `metadata-provider` | Pulls media metadata (e.g. an alternative TMDb). |
| `subtitle-provider` | Downloads or generates subtitles. |
| `auth-provider` | Authenticates users (OIDC, LDAP, SSO, …). |
| `library-type` | Adds a brand-new library kind. |
| `notifier` | Sends notifications (push, email, chat). |
| `scrobbler` | Reports playback to an external service. |
| `tuner` | Live-TV tuner backend. |
| `transcoder-hook` | Adjusts the transcoder pipeline. |
| `ui-theme` | Restyles the web portal. |
| `arr-integration` | Integrates with *arr stack (Sonarr, Radarr). |
| `analytics-sink` | Exports analytics to external systems. |

### Optional

#### `events`

An array of **manifest aliases**. Each alias is a dotted string of the
form `phlex.<area>.<verb>(.<sub>)*` (regex
`^phlex\.[a-z]+(?:\.[a-z]+)*$`). The canonical alias list lives in
[`docs/dev/event-reference.md`](../dev/event-reference.md). The A.4
loader resolves these aliases to event FQCNs at install time.

#### `settings`

A keyed object describing the settings the plugin exposes to operators
via the admin UI. Each value is an object:

| Key | Type | Notes |
| --- | --- | --- |
| `type` | string | One of `string`, `int`, `bool`, `float`, `array`. Required. |
| `required` | boolean | Whether the operator must set a value. Default `false`. |
| `secret` | boolean | Hides the value in UI/logs and stores it encrypted. Default `false`. |
| `default` | any | Default value when the operator leaves the field blank. |

#### `signature`

Either `null` (unsigned plugin) or a string matching
`^sha256:[0-9a-f]{64}$`. Signature *verification* lives in A.7; A.3
only validates the format.

## Parsing and validating in PHP

```php
use Phlex\Plugins\Manifest\ManifestSchema;
use Phlex\Shared\Plugin\Manifest;
use Phlex\Shared\Plugin\ManifestType;
use RuntimeException;

try {
    $manifest = Manifest::fromJson(file_get_contents('plugin.json'));
} catch (RuntimeException $e) {
    // Hard parse failure — the file isn't even valid JSON.
    throw $e;
}

$errors = (new ManifestSchema())->validate($manifest);
if ($errors !== []) {
    foreach ($errors as $error) {
        printf("[%s] %s — %s\n", $error->code, $error->field, $error->message);
    }
    return;
}

assert($manifest->manifestType() instanceof ManifestType);
```

### Error codes returned by `validate()`

The `code` field on `ManifestValidationError` mirrors the JSON Schema
constraint that failed (`required`, `pattern`, `enum`, `type`,
`additionalProperties`, …), plus a Phlex-specific `unknown_field` code
for top-level keys that the schema does not allow.

## Authoring checklist

1. Copy `tests/Fixtures/Plugins/valid-lastfm.json` (scrobbler) or
   `valid-oidc.json` (auth provider) into your plugin's root.
2. Replace `name`, `entry`, `events`, and `settings` to match your
   plugin.
3. Run `npx ajv-cli validate -s docs/plugins/manifest.schema.json -d
   plugin.json` (optional — the loader will validate again at install
   time).
4. Commit the manifest alongside your PHP entry class. Wiring the
   plugin into the runtime arrives in A.4.

## See also

- [`docs/plugins/developer-guide.md`](developer-guide.md) — top-level
  plugin authoring guide (stub; expanded in A.7).
- [`docs/dev/event-reference.md`](../dev/event-reference.md) — canonical
  alias → FQCN table for `events`.
- [`PHLEX_EXPANSION_PLAN.md`](../../PHLEX_EXPANSION_PLAN.md) §5 — the
  master plan that defines the manifest fields and the eleven types.
