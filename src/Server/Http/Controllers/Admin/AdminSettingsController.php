<?php

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers\Admin;

use Phlix\Admin\SettingsRepository;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
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
 * Route group is gated by {@see \Phlix\Server\Http\Middleware\AdminMiddleware}
 * (registered in {@see \Phlix\Server\Http\Routes\AdminRoutes}); non-admin
 * callers receive a JSON 401/403 from the middleware. This controller assumes
 * it only runs for authenticated admins.
 *
 * Resident-memory rules: no `exit`/`die`, no blocking `sleep()`, no request
 * data parked in `static`/`global`. The allow-list constant below is
 * shared/immutable config data, not request state, so it is safe.
 *
 * @package Phlix\Server\Http\Controllers\Admin
 * @since   0.5 (Server-wide settings store)
 */
final class AdminSettingsController
{
    /**
     * Typed allow-list of editable server settings: dotted key → value type
     * (string|int|bool|float|json). This is the inline validation source for
     * PUT while step 0.7's shared JSON schema is still `todo`.
     *
     * Keys are a curated, representative slice of the Phase-1.3 setting
     * groups (transcoding/hwaccel, metadata providers + API keys, marker
     * detection, subtitles, discovery, trickplay, newsletter, port-forward/
     * UPnP). The dotted key names the `config/<file>.php` default it
     * overrides (see {@see SettingsRepository}).
     *
     * 0.7: replace/back this map with the shared `server-settings.schema.json`
     * once that step lands; PUT validation should then defer to the schema.
     *
     * @var array<string, string>
     */
    public const ALLOWED_KEYS = [
        // Transcoding / hardware acceleration (config/hwaccel.php).
        'hwaccel.enabled'                          => 'bool',
        'hwaccel.prefer_hardware'                  => 'bool',
        'hwaccel.probe_timeout'                    => 'int',

        // Metadata providers + API keys (config/tmdb.php).
        'tmdb.api_key'                             => 'string',

        // Marker detection (config/marker_detection.php).
        'marker_detection.similarity_threshold'    => 'float',
        'marker_detection.intro_max_duration'      => 'int',

        // Subtitles (config/subtitles.php).
        'subtitles.enabled'                        => 'bool',
        'subtitles.default_language'               => 'string',
        'subtitles.burn_in_by_default'             => 'bool',

        // Discovery (config/discovery.php).
        'discovery.discovery_port'                 => 'int',

        // Trickplay (config/trickplay.php).
        'trickplay.enabled'                        => 'bool',
        'trickplay.interval_seconds'               => 'int',

        // Newsletter (config/newsletter.php).
        'newsletter.enabled'                       => 'bool',
        'newsletter.send_hour'                     => 'int',

        // Port-forward / UPnP (config/port-forward.php).
        'port-forward.port_forwarding.upnp_enabled' => 'bool',
    ];

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
            $keys   = array_keys(self::ALLOWED_KEYS);
            $merged = $this->settings->getEffectiveMany($keys);

            return (new Response())->json([
                'success' => true,
                'data'    => [
                    'settings'   => $merged['values'],
                    'overridden' => $merged['overridden'],
                    'types'      => self::ALLOWED_KEYS,
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

            $errors    = [];
            $validated = [];
            foreach ($settings as $key => $value) {
                if (!is_string($key) || !isset(self::ALLOWED_KEYS[$key])) {
                    $errors[(string) $key] = 'Unknown setting key.';
                    continue;
                }

                $type = self::ALLOWED_KEYS[$key];
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

            $merged = $this->settings->getEffectiveMany(array_keys(self::ALLOWED_KEYS));

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
