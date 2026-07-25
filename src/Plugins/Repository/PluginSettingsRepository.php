<?php

/**
 * Phlix media server component: Repository.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Repository;

use DateTimeImmutable;
use Workerman\MySQL\Connection;

/**
 * Workerman\MySQL-backed key/value settings store for the bundled auth-provider
 * plugins (migration `093_plugin_settings.sql`).
 *
 * S48 moves each bundled provider's configuration (OIDC issuer/client-id/secret,
 * LDAP host/base-dn/bind, GitHub client-id/secret/scopes) off its hand-rolled
 * `settings.json` file and into the `plugin_settings` DB table so the config
 * persists in the database, is visible to every resident worker, and survives a
 * read-only/ephemeral filesystem. One row per plugin, keyed by manifest name.
 *
 * This is intentionally SEPARATE from {@see PluginRepository} (the catalog
 * `plugins` table): the bundled auth providers are enabled through
 * {@see \Phlix\Auth\AuthProviderBootstrapper}, not the PluginLoader pipeline, so
 * they must never appear as catalog rows (see migration 093's header comment).
 *
 * All queries are parameterized — never interpolate input into the SQL string.
 *
 * ## THE WRITE CONTRACT: {@see self::save()} is a WHOLESALE REPLACE
 *
 * This store has no per-key update. `save()` overwrites the whole
 * `settings_json` document, so **preserving a key the caller did not send is the
 * CALLER's job** — see {@see \Phlix\Plugins\Github\Controller\GithubAdminController::saveSettings()}
 * and {@see \Phlix\Plugins\Oidc\Controller\OidcAdminController::saveSettings()},
 * which read the existing map first and re-emit any key absent from the request
 * body. An absent key must always be PRESERVED; only an explicitly empty value
 * clears it.
 *
 * That is not a stylistic preference. Dropping keys that were merely absent from a
 * request payload is the exact shape that **wiped live Trakt OAuth tokens on
 * production** during a plugin update, and post-S48 a lost `redirect_uri` makes
 * every GitHub/OIDC login answer `503 callback_url_not_configured`. It is a live
 * scenario, not a theoretical one: the shipped admin SPA's OIDC form posts only
 * `provider_url`/`client_id`/`client_secret`/`scopes`, so without the caller-side
 * preservation a single click on Save would wipe a `redirect_uri` that was set
 * through the API. `AuthProviderSettingsPreservationRealDbIntegrationTest` drives
 * both real controllers through this repository against real MySQL and reads the
 * column back directly; reverting either controller to a wholesale replace turns it
 * RED with a message naming the incident.
 *
 * ## MySQL normalises `json` object key ORDER (real-DB behaviour the mocks hide)
 *
 * `settings_json` is a native MySQL `json` column, so the server stores a parsed
 * binary document and re-serialises it on read with its own key ordering —
 * shortest key first, then lexicographic — NOT the insertion order that was
 * written. Every value, scalar type, list order, nested structure and multi-byte
 * character survives byte-identically; only the key order of OBJECTS changes.
 *
 * There is **no production impact** — every consumer reads by key — but it is
 * invisible to the in-memory test double, which hands the same PHP array back. So
 * do not assert a round-trip with a whole-map `assertSame()`: it will fail on key
 * order and look exactly like data loss. Compare per key, or use
 * `PluginSettingsRealDbIntegrationTest`'s recursive key-sorted-but-type-STRICT
 * helper (pinned by `testMysqlJsonNormalisesObjectKeyOrder`).
 *
 * @package Phlix\Plugins\Repository
 * @since 0.102.0
 */
class PluginSettingsRepository implements PluginSettingsStore
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Read a plugin's persisted settings map.
     *
     * The three-state return is deliberate: `null` (no row) and `[]` (row present,
     * empty document) mean different things to
     * {@see \Phlix\Plugins\PluginDbSettings::getSettings()} — only `null` triggers
     * the one-time legacy `settings.json` import, so a row that was deliberately
     * saved empty is never re-seeded from a stale file.
     *
     * Object key order is NOT preserved across a round-trip (MySQL re-serialises
     * `json` documents in its own order — see the class docblock); read by key.
     *
     * @param string $pluginName Bundled-plugin key (e.g. `oidc`, `ldap`, `github`).
     *
     * @return array<string, mixed>|null The decoded settings map, an empty array
     *         when the row exists but holds no/blank JSON, or NULL when there is
     *         no row at all (the caller uses NULL to trigger a one-time legacy
     *         settings.json import).
     */
    public function get(string $pluginName): ?array
    {
        $rows = $this->db->query(
            'SELECT settings_json FROM plugin_settings WHERE plugin_name = ? LIMIT 1',
            [$pluginName],
        );

        if (!is_array($rows) || !isset($rows[0]) || !is_array($rows[0])) {
            return null;
        }

        $json = is_string($rows[0]['settings_json'] ?? null) ? (string) $rows[0]['settings_json'] : '';
        if ($json === '') {
            return [];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($json, true);

        /** @var array<string, mixed> */
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Upsert a plugin's settings map (WHOLESALE REPLACE).
     *
     * Whatever map is passed becomes the entire stored document — any key not in
     * `$settings` is GONE. There is no merge and no per-key update, so the caller
     * MUST re-emit every key it wants to keep: read the current map with
     * {@see self::get()} first and preserve any key absent from the request payload
     * (an absent key is never a deletion). See the class docblock for why —
     * dropping absent keys is the shape that wiped live Trakt OAuth tokens on
     * production, and a lost `redirect_uri` now 503s every GitHub/OIDC login.
     *
     * @param string               $pluginName Bundled-plugin key.
     * @param array<string, mixed> $settings   Full settings map to store — the
     *                                         COMPLETE document, not a patch.
     *
     * @return void
     */
    public function save(string $pluginName, array $settings): void
    {
        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

        $this->db->query(
            'INSERT INTO plugin_settings (plugin_name, settings_json, updated_at) '
            . 'VALUES (?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE settings_json = VALUES(settings_json), updated_at = VALUES(updated_at)',
            [$pluginName, (string) json_encode($settings), $now],
        );
    }

    /**
     * Whether a settings row exists for the given plugin.
     *
     * @param string $pluginName Bundled-plugin key.
     */
    public function exists(string $pluginName): bool
    {
        $rows = $this->db->query('SELECT 1 FROM plugin_settings WHERE plugin_name = ? LIMIT 1', [$pluginName]);

        return is_array($rows) && count($rows) > 0;
    }
}
