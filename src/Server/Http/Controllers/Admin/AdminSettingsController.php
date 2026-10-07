<?php

/**
 * Phlix media server component: Admin.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers\Admin;

use JsonSchema\Validator;
use Phlix\Admin\SettingsRepository;
use Phlix\Auth\AuthMethodLockoutException;
use Phlix\Auth\AuthMethodPolicy;
use Phlix\Plugins\SettingsMasker;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Shared\Schema\SchemaPaths;
use Throwable;

/**
 * Admin JSON API for the server-wide settings store (Step 0.5).
 *
 *   - `GET  /api/v1/admin/settings` → effective values (config default merged
 *     with any DB override) plus the list of keys that are overridden.
 *   - `PUT  /api/v1/admin/settings` → validate the submitted keys against the
 *     typed allow-list, reject unknown keys / wrong types, then persist the
 *     overrides via {@see SettingsRepository}. Persisted overrides survive a
 *     restart because the DB is the persistent store.
 *
 * The editable-settings allow-list (dotted key → internal type) is the single
 * source of truth for the key allow-list and the GET `types` map. As of Step
 * 0.7 it is **derived from the shared `server-settings.schema.json`** bundled
 * in `detain/phlix-shared` (located via {@see SchemaPaths::serverSettings()})
 * so the server and the admin SPA render/validate from one schema; the prior
 * hardcoded `ALLOWED_KEYS` constant (and its `0.7:` seam) is gone. The
 * JSON-Schema `type` of each property is mapped to the internal vocabulary
 * (`boolean→bool`, `integer→int`, `number→float`, `string→string`,
 * `array`/`object→json`).
 *
 * **PUT validation is two-stage:**
 *   1. The internal-type gate ({@see valueMatchesType()} + {@see coerce()}) —
 *      tolerant of the string-y values a JSON/form body actually carries
 *      (`"45"` for an int, `"true"` for a bool) and responsible for turning
 *      them into canonical PHP values.
 *   2. **Real JSON-Schema validation of the coerced value against that key's
 *      own property sub-schema** via `justinrainbow/json-schema`
 *      ({@see validateAgainstSchema()}). This is what actually enforces
 *      `enum`, `minimum` and `maximum` — before this stage existed those
 *      keywords were emitted to the UI for display and never checked on
 *      write, so every bound in the schema was cosmetic and trivially
 *      bypassed with a direct PUT.
 *
 * Both stages report through the same `errors` map (`{"<key>": "<message>"}`)
 * that the admin SPA renders as inline per-field errors, and a single failing
 * key rejects the whole request without persisting anything.
 *
 * **Secrets** (schema `"secret": true`) are never echoed back: GET replaces
 * their values with {@see SettingsMasker::MASK} and publishes a separate
 * `secretStatus` map ({@see secretStatus()}) so the UI can distinguish a set
 * secret from an unset one without seeing it. PUT mirrors this — submitting
 * the unchanged mask sentinel for a secret key is a no-op that leaves the
 * stored value untouched, so saving the form cannot wipe credentials the
 * admin never edited. This reuses the same mechanism the plugin settings path
 * already uses ({@see \Phlix\Server\Http\Controllers\PluginAdminController}).
 *
 * Route group is gated by {@see \Phlix\Server\Http\Middleware\AdminMiddleware}
 * (registered in {@see \Phlix\Server\Http\Routes\AdminRoutes}); non-admin
 * callers receive a JSON 401/403 from the middleware. This controller assumes
 * it only runs for authenticated admins.
 *
 * Resident-memory rules: no `exit`/`die`, no blocking `sleep()`, no request
 * data parked in `static`/`global`. The cached allow-list ({@see $allowedKeys})
 * is shared/immutable config data loaded once from the schema, not request
 * state, so the static cache is safe under the resident-memory model.
 *
 * @package Phlix\Server\Http\Controllers\Admin
 * @since   0.5 (Server-wide settings store)
 */
final class AdminSettingsController
{
    /**
     * Lazily-loaded cache of the schema-derived allow-list: dotted key →
     * internal type. Populated once by {@see loadAllowedKeysFromSchema()} on
     * the first {@see allowedKeys()} call and reused thereafter.
     *
     * This is immutable config data (the schema is shipped read-only in the
     * vendored package), NOT per-request state, so caching it in a static is
     * resident-memory-safe — it does not grow per request and is identical
     * for every caller.
     *
     * @var array<string, string>|null
     */
    private static ?array $allowedKeys = null;

    /**
     * Lazily-loaded cache of the per-key meta block derived from the same
     * schema: dotted key → meta block. Populated once by
     * {@see loadSchemaMeta()} on the first call and reused thereafter.
     *
     * This is immutable config data (the schema is shipped read-only in the
     * vendored package), NOT per-request state, so caching it in a static is
     * resident-memory-safe.
     *
     * @var array<string, array<string, mixed>>|null
     */
    private static ?array $schemaMeta = null;

    /**
     * Lazily-loaded cache of the raw per-property JSON-Schema sub-schemas, as
     * `stdClass` objects, for use with `justinrainbow/json-schema`'s
     * {@see Validator}. Populated once by {@see loadSchemaValidators()}.
     *
     * The library needs genuine `stdClass` (not associative arrays) to tell an
     * object from an array, which is why this is decoded separately from
     * {@see $schemaMeta} rather than re-encoded per request.
     *
     * Same resident-memory reasoning as the other two caches: immutable,
     * process-wide, read-only config that does not grow per request.
     *
     * @var array<string, \stdClass>|null
     */
    private static ?array $schemaValidators = null;

    /** @var SettingsRepository Server-settings store. */
    private SettingsRepository $settings;

    /** @var AuthMethodPolicy|null F7 auth-method lock-out guard (see update()). */
    private ?AuthMethodPolicy $authPolicy;

    /**
     * @param SettingsRepository   $settings   Server-settings store.
     * @param AuthMethodPolicy|null $authPolicy Cross-key guard for the five
     *        `auth.<method>.enabled` toggles. NULL keeps the guard inert —
     *        acceptable only in hand-built test doubles; `AdminServicesProvider`
     *        names this parameter explicitly so the container never ships the
     *        inert form (`tests/Unit/Auth/AuthMethodPolicyWiringGuardTest`).
     *
     * @since 0.5
     */
    public function __construct(SettingsRepository $settings, ?AuthMethodPolicy $authPolicy = null)
    {
        $this->settings = $settings;
        $this->authPolicy = $authPolicy;
    }

    /**
     * Typed allow-list of editable server settings: dotted key → internal
     * value type (`bool|int|float|string|json`).
     *
     * This is the public accessor that replaces the former `ALLOWED_KEYS`
     * constant. The map is derived from the shared
     * `server-settings.schema.json` and cached in {@see $allowedKeys} after
     * the first call (the schema is immutable config, so the static cache is
     * resident-memory-safe). It is both the PUT validation source and the GET
     * `types` map.
     *
     * @return array<string, string> Dotted setting key → internal type.
     *
     * @since 0.7 (derived from the shared server-settings schema)
     */
    public static function allowedKeys(): array
    {
        if (self::$allowedKeys === null) {
            self::$allowedKeys = self::loadAllowedKeysFromSchema();
        }

        return self::$allowedKeys;
    }

    /**
     * Per-key meta block sourced directly from the shared schema.
     *
     * Each key in the returned map corresponds to a property in
     * `server-settings.schema.json` — including keys that have no type
     * mapping (and therefore do not appear in {@see allowedKeys()}).  The
     * meta block carries everything the admin SPA needs to render a settings
     * row: label, help text, help links, tier, group, enum constraints, min/
     * max bounds, default value, and the secret/restart flags.
     *
     * @return array<string, array<string, mixed>> Dotted setting key → meta block.
     *
     * @since 0.7.1
     */
    public static function schemaMeta(): array
    {
        if (self::$schemaMeta === null) {
            self::$schemaMeta = self::loadSchemaMeta();
        }

        return self::$schemaMeta;
    }

    /**
     * Read and decode the shared `server-settings.schema.json`, projecting its
     * `properties` into the internal dotted-key → type allow-list.
     *
     * Each property whose JSON-Schema `type` maps to a known internal type
     * (see {@see mapSchemaType()}) contributes one entry. Properties without a
     * usable `type` are skipped.
     *
     * Fail-safe: any unreadable, unparseable, or structurally-unexpected
     * schema (missing file, non-JSON, no `properties` object) yields an empty
     * allow-list `[]` rather than an exception — a degraded but non-crashing
     * state. The lock-in unit test and CI catch a genuinely broken/missing
     * vendored schema loudly, so this never silently masks a real defect.
     *
     * @return array<string, string> Dotted setting key → internal type.
     */
    private static function loadAllowedKeysFromSchema(): array
    {
        $path = SchemaPaths::serverSettings();
        $raw  = is_file($path) ? file_get_contents($path) : false;
        if ($raw === false) {
            return [];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        if (!isset($decoded['properties']) || !is_array($decoded['properties'])) {
            return [];
        }

        /** @var array<string, string> $map */
        $map = [];
        foreach ($decoded['properties'] as $key => $def) {
            if (!is_string($key) || !is_array($def)) {
                continue;
            }

            if (!isset($def['type']) || !is_string($def['type'])) {
                continue;
            }

            $internal = self::mapSchemaType($def['type']);
            if ($internal !== null) {
                $map[$key] = $internal;
            }
        }

        return $map;
    }

    /**
     * Read and decode the shared `server-settings.schema.json` and project
     * every property into a per-key meta block.
     *
     * Unlike {@see loadAllowedKeysFromSchema()}, no type filtering is applied —
     * every declared property appears in the result, providing the rich
     * per-key metadata the admin SPA needs for rendering.  Optional fields
     * that are absent from a given property definition are emitted as `null`.
     *
     * Fail-safe: any unreadable, unparseable, or structurally-unexpected
     * schema yields an empty map `[]` rather than an exception.
     *
     * @return array<string, array<string, mixed>> Dotted setting key → meta block.
     *
     * @since 0.7.1
     */
    private static function loadSchemaMeta(): array
    {
        $path = SchemaPaths::serverSettings();
        $raw  = is_file($path) ? file_get_contents($path) : false;
        if ($raw === false) {
            return [];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        if (!isset($decoded['properties']) || !is_array($decoded['properties'])) {
            return [];
        }

        /** @var array<string, array<string, mixed>> $meta */
        $meta = [];
        foreach ($decoded['properties'] as $key => $def) {
            if (!is_string($key) || !is_array($def)) {
                continue;
            }

            $meta[$key] = [
                'label'      => $def['label'] ?? null,
                'helpText'   => $def['helpText'] ?? null,
                'helpLinks'  => isset($def['helpLinks']) && is_array($def['helpLinks'])
                    ? $def['helpLinks']
                    : [],
                'tier'       => $def['tier'] ?? 'standard',
                'group'      => $def['group'] ?? null,
                'enum'       => isset($def['enum']) && is_array($def['enum']) ? $def['enum'] : null,
                'enumLabels' => isset($def['enumLabels']) && is_array($def['enumLabels'])
                    ? $def['enumLabels']
                    : null,
                'optionHelp' => isset($def['optionHelp']) && is_array($def['optionHelp'])
                    ? $def['optionHelp']
                    : null,
                'minimum'    => isset($def['minimum']) && is_numeric($def['minimum'])
                    ? (float) $def['minimum']
                    : null,
                'maximum'    => isset($def['maximum']) && is_numeric($def['maximum'])
                    ? (float) $def['maximum']
                    : null,
                'default'    => array_key_exists('default', $def) ? $def['default'] : null,
                'secret'     => !empty($def['secret']),
                'restart'    => !empty($def['restart']),
            ];
        }

        return $meta;
    }

    /**
     * Per-key JSON-Schema sub-schemas (as `stdClass`) used to enforce `enum`,
     * `minimum` and `maximum` — every constraint the schema declares — on PUT.
     *
     * @return array<string, \stdClass> Dotted setting key → property sub-schema.
     *
     * @since 1.3.0
     */
    private static function schemaValidators(): array
    {
        if (self::$schemaValidators === null) {
            self::$schemaValidators = self::loadSchemaValidators();
        }

        return self::$schemaValidators;
    }

    /**
     * Decode the shared schema a second time as `stdClass` and project each
     * property into a standalone sub-schema the validator can be pointed at.
     *
     * Validating each key against its OWN property sub-schema (rather than the
     * whole document against an assembled object) keeps error attribution
     * trivially per-key and side-steps the document's `additionalProperties:
     * false`, which would otherwise reject a partial PUT payload outright.
     *
     * Fail-safe: an unreadable/unparseable schema yields `[]`, which means no
     * constraint validation runs — the internal-type gate still applies. This
     * mirrors the other two loaders' non-crashing degradation.
     *
     * @return array<string, \stdClass> Dotted setting key → property sub-schema.
     */
    private static function loadSchemaValidators(): array
    {
        $path = SchemaPaths::serverSettings();
        $raw  = is_file($path) ? file_get_contents($path) : false;
        if ($raw === false) {
            return [];
        }

        $decoded = json_decode((string) $raw);
        if (!($decoded instanceof \stdClass) || !($decoded->properties ?? null) instanceof \stdClass) {
            return [];
        }

        $out = [];
        /** @var mixed $def */
        foreach (get_object_vars($decoded->properties) as $key => $def) {
            if (!($def instanceof \stdClass)) {
                continue;
            }
            $out[$key] = $def;
        }

        return $out;
    }

    /**
     * Validate one already-coerced value against its property sub-schema.
     *
     * @param string $key   Dotted setting key.
     * @param mixed  $value The coerced PHP value about to be persisted.
     *
     * @return string|null Null when valid, else a human-readable message for
     *                     the `errors` map the SPA renders inline.
     */
    private static function validateAgainstSchema(string $key, mixed $value): ?string
    {
        $schema = self::schemaValidators()[$key] ?? null;
        if ($schema === null) {
            // No sub-schema (degraded/missing schema file) — the internal-type
            // gate already ran; do not invent a second failure mode here.
            return null;
        }

        $subject = self::toValidatorValue($schema, $value);

        $validator = new Validator();
        $validator->validate($subject, $schema);

        if ($validator->isValid()) {
            return null;
        }

        $messages = [];
        /** @var array<string, mixed> $error */
        foreach ($validator->getErrors() as $error) {
            $message = $error['message'] ?? null;
            if (is_string($message) && $message !== '') {
                $messages[] = $message;
            }
        }

        return $messages === []
            ? 'Value does not satisfy the schema constraints.'
            : implode(' ', array_unique($messages));
    }

    /**
     * Normalise a coerced PHP value into the shape `justinrainbow/json-schema`
     * expects.
     *
     * PHP associative arrays are ambiguous — the library reads them as JSON
     * arrays, so an `object`-typed setting persisted as an assoc array would
     * spuriously fail its `type` check. Round-tripping non-scalars through JSON
     * restores the object/array distinction. An empty array submitted for an
     * `object`-typed key is treated as an empty object (clearing the map),
     * preserving the pre-validation behaviour.
     *
     * @param \stdClass $schema The property sub-schema.
     * @param mixed     $value  The coerced value.
     *
     * @return mixed A value the validator can check.
     */
    private static function toValidatorValue(\stdClass $schema, mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if ($value === [] && ($schema->type ?? null) === 'object') {
            return new \stdClass();
        }

        $encoded = json_encode($value);
        if ($encoded === false) {
            return $value;
        }

        return json_decode($encoded);
    }

    /**
     * Map a JSON-Schema `type` to the controller's internal type vocabulary.
     *
     * The internal vocabulary (`bool|int|float|string|json`) is exactly what
     * {@see valueMatchesType()} and {@see coerce()} understand, so this mapping
     * reproduces the key/type set the former `ALLOWED_KEYS` constant declared.
     *
     * @param string $jsonType The JSON-Schema `type` keyword.
     *
     * @return string|null The internal type, or null when the JSON type has no
     *                      internal equivalent (such properties are skipped).
     */
    private static function mapSchemaType(string $jsonType): ?string
    {
        return match ($jsonType) {
            'boolean' => 'bool',
            'integer' => 'int',
            'number'  => 'float',
            'string'  => 'string',
            'array', 'object' => 'json',
            default   => null,
        };
    }

    /**
     * Is this key flagged `"secret": true` in the shared schema?
     *
     * Driven by the schema flag, NOT by a hardcoded key list — so a key that
     * gains `"secret": true` in a future `detain/phlix-shared` release is
     * masked automatically, with no change here.
     *
     * @param string $key Dotted setting key.
     *
     * @return bool True when the key's value must never leave the server.
     */
    private static function isSecret(string $key): bool
    {
        return (self::schemaMeta()[$key]['secret'] ?? false) === true;
    }

    /**
     * Replace every secret key's value with {@see SettingsMasker::MASK}.
     *
     * A secret that is unset (null / empty string) is left as-is so the UI can
     * still tell "not configured" from "configured but hidden" even without
     * consulting {@see secretStatus()}.
     *
     * @param array<string, mixed> $values Effective values, secrets included.
     *
     * @return array<string, mixed> The same map with secret values redacted.
     */
    private static function maskSecrets(array $values): array
    {
        /** @var mixed $value */
        foreach ($values as $key => $value) {
            if (!self::isSecret($key)) {
                continue;
            }
            if ($value === null || $value === '' || !is_scalar($value)) {
                continue;
            }
            $values[$key] = SettingsMasker::MASK;
        }

        return $values;
    }

    /**
     * Per-secret "is it set?" summary, mirroring
     * {@see SettingsMasker::secretStatus()}'s shape for plugin settings.
     *
     * The raw secret is NEVER included — only whether a non-empty value is
     * stored and its character length, so the UI can render a
     * length-appropriate "yes, it really is set" cue next to a masked field.
     *
     * @param array<string, mixed> $values Effective values BEFORE masking.
     *
     * @return array<string, array{set: bool, length: int}> Keyed by secret key.
     */
    private static function secretStatus(array $values): array
    {
        $out = [];
        foreach (self::schemaMeta() as $key => $meta) {
            if (($meta['secret'] ?? false) !== true) {
                continue;
            }
            /** @var mixed $value */
            $value = $values[$key] ?? null;
            $set   = is_scalar($value) && (string) $value !== '';
            $out[$key] = [
                'set'    => $set,
                'length' => $set ? mb_strlen((string) $value) : 0,
            ];
        }

        return $out;
    }

    /**
     * Return effective values (config default merged with DB override) and
     * the list of overridden keys.
     *
     * Values for keys flagged `"secret": true` in the schema are replaced with
     * {@see SettingsMasker::MASK} — they are never sent to the browser, where
     * they would otherwise sit in the XHR body, the DOM, proxy logs and HAR
     * captures, one "Show" click from being displayed. `secretStatus` carries
     * the set/unset + length cue instead. {@see update()} honours the same
     * sentinel so re-submitting a masked field does not overwrite the stored
     * secret.
     *
     * GET /api/v1/admin/settings
     *
     * @param Request              $request The HTTP request (unused).
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return Response JSON `{ success, data: { settings, overridden, types, meta, secretStatus } }`.
     *
     * @since 0.5
     */
    public function index(Request $request, array $params): Response
    {
        try {
            $allowed = self::allowedKeys();
            $keys    = array_keys($allowed);
            $merged  = $this->settings->getEffectiveMany($keys);

            return (new Response())->json([
                'success' => true,
                'data'    => [
                    'settings'     => self::maskSecrets($merged['values']),
                    'overridden'   => $merged['overridden'],
                    'types'        => $allowed,
                    'meta'         => self::schemaMeta(),
                    'secretStatus' => self::secretStatus($merged['values']),
                ],
            ]);
        } catch (Throwable $e) {
            return (new Response())->status(500)->json([
                'success' => false,
                'error'   => 'Failed to load settings',
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Validate and persist setting overrides.
     *
     * PUT /api/v1/admin/settings
     * Body: `{ "settings": { "<key>": <value>, ... } }`
     *
     * Rejects (400) unknown keys, values that don't match the allow-list type,
     * and values that violate their property's JSON-Schema constraints
     * (`enum` / `minimum` / `maximum` / anything else the schema declares).
     * Errors are reported as a `{"<key>": "<message>"}` map, and a single bad
     * key rejects the whole request without persisting anything.
     *
     * A key flagged `"secret": true` whose submitted value is exactly
     * {@see SettingsMasker::MASK} is SKIPPED — that is the sentinel
     * {@see index()} sent, meaning "unchanged", so the stored secret is left
     * alone. Without this guard the first Save on the settings page would
     * overwrite every secret with the literal mask string.
     *
     * @param Request              $request The HTTP request.
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return Response JSON success/validation-error payload.
     *
     * @since 0.5
     */
    public function update(Request $request, array $params): Response
    {
        try {
            $body     = $request->body;
            $settings = $body['settings'] ?? null;

            if (!is_array($settings) || $settings === []) {
                return (new Response())->status(400)->json([
                    'success' => false,
                    'error'   => 'Invalid payload',
                    'message' => 'Body must contain a non-empty "settings" object.',
                ]);
            }

            $allowed   = self::allowedKeys();
            $errors    = [];
            $validated = [];
            foreach ($settings as $key => $value) {
                if (!is_string($key) || !isset($allowed[$key])) {
                    $errors[(string) $key] = 'Unknown setting key.';
                    continue;
                }

                // Secret echoed back unchanged → keep the stored value, skip.
                // Mirrors PluginAdminController's guard so a Save cannot wipe
                // credentials the admin never touched.
                if (self::isSecret($key) && $value === SettingsMasker::MASK) {
                    continue;
                }

                $type = $allowed[$key];
                if (!self::valueMatchesType($value, $type)) {
                    $errors[$key] = sprintf('Expected type %s.', $type);
                    continue;
                }

                $coerced = self::coerce($value, $type);

                // Real JSON-Schema validation of the coerced value: this is
                // what enforces enum / minimum / maximum, which were previously
                // display-only metadata and unenforced on write.
                $schemaError = self::validateAgainstSchema($key, $coerced);
                if ($schemaError !== null) {
                    $errors[$key] = $schemaError;
                    continue;
                }

                $validated[$key] = ['value' => $coerced, 'type' => $type];
            }

            // Every submitted key was an unchanged secret sentinel: nothing to
            // persist, but this is a successful no-op, not a 400.
            if ($errors === [] && $validated === []) {
                $merged = $this->settings->getEffectiveMany(array_keys($allowed));

                return (new Response())->json([
                    'success' => true,
                    'message' => 'Settings updated.',
                    'data'    => [
                        'settings'   => self::maskSecrets($merged['values']),
                        'overridden' => $merged['overridden'],
                    ],
                ]);
            }

            if ($errors !== []) {
                return (new Response())->status(400)->json([
                    'success' => false,
                    'error'   => 'Validation failed',
                    'errors'  => $errors,
                ]);
            }

            // F7 cross-key semantic guard, B2 hardening: a write-set that
            // touches any of the five auth-method toggles re-validates the
            // lock-out policy INSIDE a transaction under FOR UPDATE row
            // locks, so two concurrent admin PUTs (one disabling password,
            // one disabling webauthn) can each no longer validate against a
            // snapshot that still shows the other's method ON and then both
            // persist to an all-off landing. A write-set without toggles
            // takes the plain path below — zero new locks, zero transaction
            // starts, byte-identical behavior to before B2.
            if ($this->authTouchedMethods($validated) !== []) {
                return $this->updateAuthGuarded($validated);
            }

            foreach ($validated as $key => $entry) {
                $this->settings->set($key, $entry['value'], $entry['type']);
            }

            $merged = $this->settings->getEffectiveMany(array_keys($allowed));

            return (new Response())->json([
                'success' => true,
                'message' => 'Settings updated.',
                'data'    => [
                    'settings'   => self::maskSecrets($merged['values']),
                    'overridden' => $merged['overridden'],
                ],
            ]);
        } catch (Throwable $e) {
            return (new Response())->status(500)->json([
                'success' => false,
                'error'   => 'Failed to update settings',
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Persist an auth-toggle write-set under the B2 serialization protocol.
     *
     * Order of operations, all on the repository's own connection:
     *   1. BEGIN
     *   2. `SELECT ... FOR UPDATE` over the five toggle keys
     *      ({@see SettingsRepository::lockSettingRows()} — present rows take
     *      X locks, absent keys take next-key gap locks under REPEATABLE
     *      READ; see that docblock for the honest coverage analysis).
     *   3. RE-RUN the F7 guard ({@see authMethodLockoutResponse()}) — its
     *      `currentState()` read now happens AFTER the lock, so the policy
     *      validates against the locked, latest committed view, not the
     *      pre-transaction snapshot that made the original guard a
     *      read-probe-write race.
     *   4. Reject → ROLLBACK, return the byte-identical 422/500 envelope.
     *   5. Accept → persist every key, COMMIT. The whole write-set is now
     *      atomic (a mid-loop `set()` failure rolls back all keys, including
     *      non-auth keys batched into the same PUT — a deliberate improvement
     *      over the old per-key partial-persistence, confined to auth-
     *      touching write-sets; non-auth PUTs keep the plain loop).
     *
     * A commit/lock failure propagates to update()'s outer catch(Throwable)
     * as the existing 500 'Failed to update settings' envelope; the rollback
     * in the catch mirrors the house transaction idiom (a secondary rollback
     * failure after a dead connection throws past us — accepted, documented,
     * same as CollectionItemRepository::applyMemberDiff()).
     *
     * @param array<string, array{value: mixed, type: string}> $validated
     */
    private function updateAuthGuarded(array $validated): Response
    {
        $this->settings->beginTransaction();

        try {
            $this->settings->lockSettingRows(self::authMethodSettingKeys());

            $lockout = $this->authMethodLockoutResponse($validated);
            if ($lockout !== null) {
                $this->settings->rollbackTransaction();

                return $lockout;
            }

            foreach ($validated as $key => $entry) {
                $this->settings->set($key, $entry['value'], $entry['type']);
            }

            $merged = $this->settings->getEffectiveMany(array_keys(self::allowedKeys()));
            $this->settings->commitTransaction();
        } catch (Throwable $e) {
            $this->settings->rollbackTransaction();

            throw $e;
        }

        return (new Response())->json([
            'success' => true,
            'message' => 'Settings updated.',
            'data'    => [
                'settings'   => self::maskSecrets($merged['values']),
                'overridden' => $merged['overridden'],
            ],
        ]);
    }

    /**
     * Which auth methods does this write-set toggle? Keyed method => proposed.
     *
     * @param array<string, array{value: mixed, type: string}> $validated
     *
     * @return array<string, bool>
     */
    private static function authTouchedMethods(array $validated): array
    {
        $touched = [];
        foreach ($validated as $key => $entry) {
            $method = AuthMethodPolicy::methodForSettingsKey((string) $key);
            if ($method !== null) {
                $touched[$method] = $entry['value'] === true;
            }
        }

        return $touched;
    }

    /**
     * The five `auth.<method>.enabled` setting keys, in canonical method order.
     *
     * @return list<string>
     */
    private static function authMethodSettingKeys(): array
    {
        return array_map([AuthMethodPolicy::class, 'settingsKey'], AuthMethodPolicy::METHODS);
    }

    /**
     * F7 auth-method lock-out guard for {@see update()}.
     *
     * Inspects the per-key-validated write-set; when it touches any of the
     * five `auth.<method>.enabled` toggles, overlays the new values on the
     * live policy state and asks {@see AuthMethodPolicy::assertSafeTransition()}
     * (R1: never all-off; R2: never strand an active admin without a factor)
     * BEFORE the first `set()` runs. A non-null return means nothing was
     * persisted. On the admin settings PUT this runs INSIDE the B2
     * transaction, after {@see SettingsRepository::lockSettingRows()} — the
     * `currentState()` read is therefore the locked view, not a racy
     * snapshot.
     *
     * @param array<string, array{value: mixed, type: string}> $validated
     */
    private function authMethodLockoutResponse(array $validated): ?Response
    {
        $touched = self::authTouchedMethods($validated);

        if ($touched === []) {
            return null;
        }

        if ($this->authPolicy === null) {
            // Fail closed: an unwired guard must never degrade into
            // unguarded toggle writes. The wiring test keeps this unreachable
            // in the container; it is here for the null-default constructor.
            return (new Response())->status(500)->json([
                'success' => false,
                'error'   => 'Auth-method lock-out guard unavailable',
                'message' => 'The server cannot validate auth-method toggles without the auth policy service.',
            ]);
        }

        /** @var array<string, bool> $proposed */
        $proposed = array_merge($this->authPolicy->currentState(), $touched);

        try {
            $this->authPolicy->assertSafeTransition($proposed);
        } catch (AuthMethodLockoutException $e) {
            $errors = [];
            foreach (array_keys($touched) as $method) {
                $errors[AuthMethodPolicy::settingsKey($method)] = $e->getMessage();
            }

            return (new Response())->status(422)->json([
                'success' => false,
                'error'   => 'Validation failed',
                'errors'  => $errors,
                'reason'  => $e->reason(),
                'message' => $e->getMessage(),
            ]);
        } catch (Throwable $e) {
            // Fail closed on ANY other policy error (unreadable store,
            // malformed state): refuse the write, persist nothing, stay loud.
            return (new Response())->status(500)->json([
                'success' => false,
                'error'   => 'Auth-method policy check failed',
                'message' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Check whether a raw submitted value is acceptable for a declared type.
     *
     * Numeric strings are accepted for int/float (JSON bodies / form input
     * often arrive as strings); booleans accept the canonical bool-ish set.
     *
     * @param mixed  $value Raw submitted value.
     * @param string $type  Declared allow-list type.
     *
     * @return bool True when the value can be coerced to the type.
     */
    private static function valueMatchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'bool'   => is_bool($value)
                || (is_int($value) && ($value === 0 || $value === 1))
                || (is_string($value) && in_array(strtolower($value), ['0', '1', 'true', 'false'], true)),
            'int'    => is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1),
            'float'  => is_int($value) || is_float($value)
                || (is_string($value) && is_numeric($value)),
            'json'   => is_array($value),
            'string' => is_string($value),
            default  => false,
        };
    }

    /**
     * Coerce a validated raw value into its canonical PHP type.
     *
     * @param mixed  $value Raw submitted value (already type-validated).
     * @param string $type  Declared allow-list type.
     *
     * @return mixed The coerced value.
     */
    private static function coerce(mixed $value, string $type): mixed
    {
        return match ($type) {
            'bool'  => is_bool($value)
                ? $value
                : (is_string($value)
                    ? in_array(strtolower($value), ['1', 'true'], true)
                    : (bool) $value),
            'int'   => (int) (is_numeric($value) ? $value : 0),
            'float' => (float) (is_numeric($value) ? $value : 0),
            default => $value,
        };
    }
}
