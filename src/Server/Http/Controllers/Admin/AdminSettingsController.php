<?php

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers\Admin;

use Phlix\Admin\SettingsRepository;
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
 * source of truth for PUT validation and the GET `types` map. As of Step 0.7
 * it is **derived from the shared `server-settings.schema.json`** bundled in
 * `detain/phlix-shared` (located via {@see SchemaPaths::serverSettings()}) so
 * the server and the admin SPA render/validate from one schema; the prior
 * hardcoded `ALLOWED_KEYS` constant (and its `0.7:` seam) is gone. The
 * JSON-Schema `type` of each property is mapped to the internal vocabulary
 * (`boolean→bool`, `integer→int`, `number→float`, `string→string`,
 * `array`/`object→json`); the resulting map preserves the exact key/type set
 * the constant declared, so GET/PUT behaviour is unchanged.
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

    /** @var SettingsRepository Server-settings store. */
    private SettingsRepository $settings;

    /**
     * @param SettingsRepository $settings Server-settings store.
     *
     * @since 0.5
     */
    public function __construct(SettingsRepository $settings)
    {
        $this->settings = $settings;
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
     * Return effective values (config default merged with DB override) and
     * the list of overridden keys.
     *
     * GET /api/v1/admin/settings
     *
     * @param Request              $request The HTTP request (unused).
     * @param array<string, string> $params Path parameters (unused).
     *
     * @return Response JSON `{ success, data: { settings, overridden, types } }`.
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
                    'settings'   => $merged['values'],
                    'overridden' => $merged['overridden'],
                    'types'      => $allowed,
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
     * Rejects (400) unknown keys and values that don't match the allow-list
     * type. On success persists each override and returns the refreshed
     * effective values.
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

                $type = $allowed[$key];
                if (!self::valueMatchesType($value, $type)) {
                    $errors[$key] = sprintf('Expected type %s.', $type);
                    continue;
                }

                $validated[$key] = ['value' => self::coerce($value, $type), 'type' => $type];
            }

            if ($errors !== []) {
                return (new Response())->status(400)->json([
                    'success' => false,
                    'error'   => 'Validation failed',
                    'errors'  => $errors,
                ]);
            }

            foreach ($validated as $key => $entry) {
                $this->settings->set($key, $entry['value'], $entry['type']);
            }

            $merged = $this->settings->getEffectiveMany(array_keys($allowed));

            return (new Response())->json([
                'success' => true,
                'message' => 'Settings updated.',
                'data'    => [
                    'settings'   => $merged['values'],
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
