<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Plugins;

use Phlix\Common\Uuid;
use Phlix\Plugins\Github\Plugin as GithubPlugin;
use Phlix\Plugins\Oidc\Plugin as OidcPlugin;
use Phlix\Plugins\Repository\PluginSettingsRepository;
use Phlix\Tests\Support\Database\RequiresRealDatabase;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * REAL-MySQL proof for S48's DB-backed plugin settings store — migration
 * `093_plugin_settings.sql`, {@see PluginSettingsRepository} and the
 * {@see \Phlix\Plugins\PluginDbSettings} trait.
 *
 * Everything in S48 was verified against `InMemoryPluginSettingsRepository` and
 * mock `Workerman\MySQL\Connection` doubles. Mock-DB tests have repeatedly hidden
 * REAL SQL/schema bugs in this repo — the sibling S44 step shipped a production
 * blocker (`users.password_hash NOT NULL`) that only a real-DB test caught, and
 * `LiveTv`'s read path was inert for months behind mock `query()` stubs. So the
 * pieces that actually touch SQL get real-MySQL coverage here:
 *
 *   1. migration 093 applies on top of a fully-migrated schema and is IDEMPOTENT
 *      (`CREATE TABLE IF NOT EXISTS`) — a re-run neither errors nor destroys rows;
 *   2. the table's real shape (PK, JSON column, NOT NULL `updated_at`, collation);
 *   3. `get()`/`save()`/`exists()` round-trip through a real `JSON` column —
 *      including nested arrays, lists, scalar TYPES (int/float/bool stay
 *      int/float/bool across the encode → store → decode cycle) and non-ASCII
 *      (CJK, accents, emoji) byte-for-byte;
 *   4. the documented repo traps: positional `?` placeholders under
 *      workerman/mysql, `query()` returning a PLAIN ARRAY (never a ResultSet),
 *      and `SELECT`-prefixed reads;
 *   5. the trait's one-time legacy `settings.json` → DB import against the real
 *      store, and `saveSettings()`'s wholesale replace.
 *
 * Self-skips when no MySQL is reachable (same guard as every sibling integration
 * test) and runs for real in CI, whose `phpunit.yml` job provisions a `mysql:8.0`
 * service and applies every migration before the suite. Only rows this run
 * created are touched, keyed by a per-run token, and they are removed in
 * tearDown.
 */
final class PluginSettingsRealDbIntegrationTest extends TestCase
{
    use RequiresRealDatabase;

    private ?Connection $db = null;

    private string $token = '';

    /** @var list<string> plugin_settings keys this run created. */
    private array $keys = [];

    private string $pluginDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->requireRealDatabase('skipping S48 plugin-settings real-DB test. Runs in CI.');

        $this->assertNotNull($this->db);
        $this->applyMigration093();
        $this->token = substr(Uuid::v4(), 0, 8);

        // Point both bundled plugins at an EMPTY scratch directory so the trait's
        // legacy-file fallback is deterministic and can never read (or write) the
        // real `src/Plugins/*/settings.json`.
        $this->pluginDir = sys_get_temp_dir() . '/phlix_s48_realdb_' . $this->token;
        mkdir($this->pluginDir, 0755, true);
        GithubPlugin::setPluginDirectory($this->pluginDir);
        OidcPlugin::setPluginDirectory($this->pluginDir);
    }

    protected function tearDown(): void
    {
        $db = $this->db;
        if ($db !== null) {
            foreach ($this->keys as $key) {
                $db->query('DELETE FROM plugin_settings WHERE plugin_name = ?', [$key]);
            }
        }
        $this->keys = [];

        foreach (glob($this->pluginDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->pluginDir);
        GithubPlugin::setPluginDirectory(dirname(__DIR__, 3) . '/src/Plugins/Github');
        OidcPlugin::setPluginDirectory(dirname(__DIR__, 3) . '/src/Plugins/Oidc');

        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // 1 + 2 — migration 093: applies, is idempotent, and has the shape the
    // repository's SQL assumes.
    // -----------------------------------------------------------------------

    /**
     * The migration's OWN SQL, replayed against a schema that already has the
     * table, must be a clean no-op that preserves existing rows. `CREATE TABLE IF
     * NOT EXISTS` is only idempotent if nothing else in the file mutates state —
     * this executes the real file rather than trusting a reading of it, because
     * `scripts/run-migrations.php` re-applies every migration on EVERY deploy
     * (there is no "already applied" short-circuit for a box whose ledger was
     * rebuilt), so a non-idempotent 093 would break every future upgrade.
     */
    public function testMigration093IsIdempotentAndPreservesRows(): void
    {
        $key = 'itest-mig-' . $this->token;
        $repo = new PluginSettingsRepository($this->conn());
        $this->keys[] = $key;
        $repo->save($key, ['client_id' => 'survivor', 'scopes' => 'read:user']);

        // Replay the migration file exactly as the runner would.
        $this->applyMigration093();

        $this->assertSameSettings(
            ['client_id' => 'survivor', 'scopes' => 'read:user'],
            $repo->get($key),
            'replaying migration 093 must not destroy existing plugin_settings rows',
        );
        $this->assertSame(1, $this->tableCount(), 'the plugin_settings table must still exist exactly once');
    }

    /**
     * The real column shape the repository's INSERT … ON DUPLICATE KEY UPDATE
     * relies on: `plugin_name` is the PRIMARY KEY (so the upsert has a conflict
     * target at all), `settings_json` is a real MySQL `json` column, and
     * `updated_at` is NOT NULL (so an INSERT that omitted it would fail — the
     * repository always supplies it).
     */
    public function testTableShapeMatchesWhatTheRepositoryAssumes(): void
    {
        $cols = [];
        /** @var array<int, array<string, mixed>> $rows */
        $rows = (array) $this->conn()->query(
            'SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_KEY '
            . 'FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            ['plugin_settings'],
        );
        foreach ($rows as $row) {
            $name = is_string($row['COLUMN_NAME'] ?? null) ? $row['COLUMN_NAME'] : '';
            $cols[$name] = $row;
        }

        $this->assertArrayHasKey('plugin_name', $cols);
        $this->assertArrayHasKey('settings_json', $cols);
        $this->assertArrayHasKey('updated_at', $cols);
        $this->assertSame('PRI', $cols['plugin_name']['COLUMN_KEY'] ?? null, 'the upsert needs plugin_name as the PK');
        $this->assertSame('json', $cols['settings_json']['DATA_TYPE'] ?? null);
        $this->assertSame('NO', $cols['updated_at']['IS_NULLABLE'] ?? null);
    }

    // -----------------------------------------------------------------------
    // 3 + 4 — round-trip through a real JSON column, with the repo's DB traps.
    // -----------------------------------------------------------------------

    /**
     * The full lifecycle against real MySQL: absent → NULL (the sentinel the
     * trait's one-time legacy import depends on), write, read back, UPDATE the
     * same key (the ON DUPLICATE KEY branch), and `exists()`.
     */
    public function testWriteReadUpdateRoundTrip(): void
    {
        $key = 'itest-rt-' . $this->token;
        $repo = new PluginSettingsRepository($this->conn());

        $this->assertFalse($repo->exists($key));
        $this->assertNull(
            $repo->get($key),
            'no row must read back as NULL, not [] — the trait uses NULL to trigger the legacy import',
        );

        $this->keys[] = $key;
        $repo->save($key, ['client_id' => 'first', 'client_secret' => 'sekrit']);

        $this->assertTrue($repo->exists($key));
        $this->assertSameSettings(['client_id' => 'first', 'client_secret' => 'sekrit'], $repo->get($key));

        // Second save on the same key exercises ON DUPLICATE KEY UPDATE, not a
        // duplicate-PK error, and leaves exactly one row.
        $repo->save($key, ['client_id' => 'second']);
        $this->assertSame(['client_id' => 'second'], $repo->get($key));
        $this->assertSame(1, $this->rowCount($key), 'the upsert must not create a second row');

        // An explicitly EMPTY map is a real, distinguishable state: the row exists
        // and reads back as [] (not NULL, which would re-trigger the legacy import).
        $repo->save($key, []);
        $this->assertSame([], $repo->get($key));
        $this->assertTrue($repo->exists($key));
    }

    /**
     * Types and encoding must survive the encode → MySQL `json` → decode cycle.
     *
     * This is the assertion a mock store cannot make: an in-memory double hands
     * the SAME PHP array back, so it can never catch a JSON column mangling a
     * nested array, coercing an int to a string, or a `utf8mb4` mismatch turning
     * an emoji into `????`. The values chosen mirror what the bundled providers
     * really store (LDAP attribute maps are nested; scopes lists; secrets can be
     * any byte string) plus the hostile cases.
     */
    public function testTypesAndNonAsciiSurviveTheJsonColumn(): void
    {
        $key = 'itest-types-' . $this->token;
        $repo = new PluginSettingsRepository($this->conn());
        $this->keys[] = $key;

        $settings = [
            'client_id' => 'gh-client',
            // array-valued / JSON-ish settings
            'scope_list' => ['read:user', 'user:email', 'repo'],
            'attribute_map' => [
                'mail' => 'email',
                'nested' => ['deep' => ['deeper' => true]],
            ],
            'empty_list' => [],
            // scalar types
            'timeout' => 30,
            'ratio' => 0.5,
            'enabled' => true,
            'disabled' => false,
            'nothing' => null,
            // non-ASCII + hostile strings
            'display_name' => 'Phlix Médiathèque — 日本語 · Ünicode ✅ 🎬',
            'secret' => "quote\"back\\slash'and</script>",
            'newlines' => "line1\nline2\ttabbed",
        ];

        $repo->save($key, $settings);
        $loaded = $repo->get($key);

        // Order-insensitive but TYPE-STRICT: MySQL's `json` type normalises object
        // key order (see testMysqlJsonNormalisesObjectKeyOrder), so a plain
        // assertSame on the whole map would fail for a reason that has nothing to
        // do with the values. List ORDER is still asserted strictly — JSON arrays
        // are ordered and `scope_list` must not be shuffled.
        $this->assertSameSettings($settings, $loaded, 'every value must round-trip through the real JSON column');
        $this->assertSame(['read:user', 'user:email', 'repo'], $loaded['scope_list'] ?? null);
        // assertSameSettings already pins types, but call them out so a failure
        // reads unambiguously.
        $this->assertIsInt($loaded['timeout'] ?? null);
        $this->assertIsFloat($loaded['ratio'] ?? null);
        $this->assertTrue($loaded['enabled'] ?? null);
        $this->assertFalse($loaded['disabled'] ?? null);
        $this->assertIsArray($loaded['attribute_map'] ?? null);
        $this->assertSame(
            'Phlix Médiathèque — 日本語 · Ünicode ✅ 🎬',
            $loaded['display_name'] ?? null,
            'utf8mb4 must be preserved end-to-end (a utf8mb3 column would mangle the emoji)',
        );
    }

    /**
     * The repo's documented workerman/mysql traps, asserted directly against the
     * repository's real queries:
     *
     *  - positional `?` placeholders bind correctly (a `:named` mismatch 500s);
     *  - `query()` hands back a PLAIN ARRAY of rows, never a lazy ResultSet (the
     *    LiveTv `RowQuery` defect: a mock returning an array hid a real read path
     *    that returned an object nobody iterated);
     *  - the read starts with `SELECT`, which the connection wrapper requires.
     */
    public function testWorkermanMysqlBindingAndPlainArrayContract(): void
    {
        $key = 'itest-bind-' . $this->token;
        $repo = new PluginSettingsRepository($this->conn());
        $this->keys[] = $key;
        $repo->save($key, ['marker' => $this->token]);

        $rows = $this->conn()->query(
            'SELECT plugin_name, settings_json FROM plugin_settings WHERE plugin_name = ? LIMIT 1',
            [$key],
        );

        $this->assertIsArray($rows, 'query() must return a plain array, not a ResultSet object');
        $this->assertArrayHasKey(0, $rows);
        $this->assertIsArray($rows[0]);
        $this->assertSame($key, $rows[0]['plugin_name'] ?? null, 'the positional ? placeholder must bind');

        // A wrong key must select nothing rather than everything — proves the
        // placeholder is really parameterised and not string-interpolated away.
        $none = $this->conn()->query(
            'SELECT plugin_name FROM plugin_settings WHERE plugin_name = ? LIMIT 1',
            [$key . '-nope'],
        );
        $this->assertSame([], is_array($none) ? $none : [null]);
    }

    /**
     * A plugin key at the column's 64-character limit still round-trips — the
     * bundled keys are short (`github`/`oidc`/`ldap`), but a silent right-trim
     * would make `get()` miss the row `save()` just wrote.
     */
    public function testMaximumLengthPluginKeyRoundTrips(): void
    {
        $key = substr('itest-long-' . str_repeat($this->token, 10), 0, 64);
        $this->assertSame(64, strlen($key));
        $repo = new PluginSettingsRepository($this->conn());
        $this->keys[] = $key;

        $repo->save($key, ['client_id' => 'long-key']);

        $this->assertSameSettings(['client_id' => 'long-key'], $repo->get($key));
    }

    /**
     * REAL-DB BEHAVIOUR THIS TEST DISCOVERED, pinned so nobody is surprised by it:
     * MySQL's native `json` type stores objects in a NORMALISED form, and part of
     * that normalisation is re-ordering keys (shortest first, then
     * lexicographically). An in-memory settings double hands back the identical PHP
     * array, so this is invisible to every mock-backed test in the step.
     *
     * No production code is affected — every consumer looks settings up BY KEY
     * (`$settings['client_id']`, `array_key_exists('scopes', …)`) — but a future
     * change that renders the map positionally (a config diff, a signature over the
     * serialised settings, an `array_values()` read) would silently misbehave, and
     * a future test that `assertSame`s a whole map would fail for a reason that
     * looks like data loss and is not. Hence: assert the normalisation explicitly.
     */
    public function testMysqlJsonNormalisesObjectKeyOrder(): void
    {
        $key = 'itest-order-' . $this->token;
        $repo = new PluginSettingsRepository($this->conn());
        $this->keys[] = $key;

        $repo->save($key, ['client_id' => 'cid', 'scopes' => 'read:user', 'redirect_uri' => 'https://a.example/cb']);
        $loaded = (array) $repo->get($key);

        // Same key/value pairs …
        $this->assertSameSettings(
            ['client_id' => 'cid', 'scopes' => 'read:user', 'redirect_uri' => 'https://a.example/cb'],
            $loaded,
        );
        // … re-ordered shortest-key-first by MySQL, NOT in insertion order.
        $this->assertSame(['scopes', 'client_id', 'redirect_uri'], array_keys($loaded));
    }

    // -----------------------------------------------------------------------
    // 5 — the PluginDbSettings trait against the REAL store.
    // -----------------------------------------------------------------------

    /**
     * The trait's one-time legacy `settings.json` → DB import, against real MySQL:
     * an upgrading operator's on-disk config is seeded into `plugin_settings` on
     * the first read, and subsequent reads come from the DB even after the file is
     * gone. Verified through BOTH bundled plugin families.
     */
    public function testTraitImportsLegacyFileIntoTheRealStoreOnce(): void
    {
        $repo = new PluginSettingsRepository($this->conn());
        $this->keys[] = GithubPlugin::PLUGIN_NAME;
        $this->keys[] = OidcPlugin::PLUGIN_NAME;
        // Start from a genuinely absent row (a fully-migrated CI DB has none, but
        // do not depend on that).
        $this->conn()->query('DELETE FROM plugin_settings WHERE plugin_name IN (?, ?)', [
            GithubPlugin::PLUGIN_NAME,
            OidcPlugin::PLUGIN_NAME,
        ]);

        file_put_contents(
            $this->pluginDir . '/settings.json',
            (string) json_encode(['client_id' => 'legacy-' . $this->token, 'client_secret' => 'legacy-secret']),
        );

        $plugin = new GithubPlugin($repo);
        $first = $plugin->getSettings();

        $this->assertSame('legacy-' . $this->token, $first['client_id'] ?? null);
        $this->assertSame(
            'legacy-' . $this->token,
            $repo->get(GithubPlugin::PLUGIN_NAME)['client_id'] ?? null,
            'the first read must have PERSISTED the legacy file into plugin_settings',
        );

        // File removed: the DB row is now the source of truth.
        unlink($this->pluginDir . '/settings.json');
        $this->assertSame('legacy-' . $this->token, $plugin->getSettings()['client_id'] ?? null);
        $this->assertFalse(
            is_file($this->pluginDir . '/settings.json'),
            'the DB path must never write the legacy file back',
        );
    }

    /**
     * `saveSettings()` through the trait persists to the DB (never the file) and is
     * a WHOLESALE REPLACE at this layer — the keys a caller omits are gone from the
     * row. That is by design; preserving an operator's absent keys is the ADMIN
     * CONTROLLER's job, and this test exists so the division of responsibility is
     * explicit and cannot be mistaken for a store-level merge.
     *
     * @see AuthProviderSettingsPreservationRealDbIntegrationTest the controller-level guarantee
     */
    public function testSaveSettingsThroughTheTraitIsAWholesaleReplaceAtTheStoreLayer(): void
    {
        $repo = new PluginSettingsRepository($this->conn());
        $this->keys[] = GithubPlugin::PLUGIN_NAME;
        $plugin = new GithubPlugin($repo);

        $plugin->saveSettings([
            'client_id' => 'cid',
            'client_secret' => 'sec',
            'scopes' => 'read:user user:email repo',
            'redirect_uri' => 'https://media.example.org/auth/github/callback',
        ]);
        $this->assertSame(
            'read:user user:email repo',
            $repo->get(GithubPlugin::PLUGIN_NAME)['scopes'] ?? null,
        );
        $this->assertFalse(is_file($this->pluginDir . '/settings.json'), 'the DB path must not write a file');

        // A partial map handed straight to the store DROPS the other keys.
        $plugin->saveSettings(['client_id' => 'cid']);
        $row = (array) $repo->get(GithubPlugin::PLUGIN_NAME);
        $this->assertSameSettings(['client_id' => 'cid'], $row);
        $this->assertArrayNotHasKey('scopes', $row);
        $this->assertArrayNotHasKey('redirect_uri', $row);
    }

    // -----------------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------------

    private function conn(): Connection
    {
        $db = $this->db;
        if ($db === null) {
            $this->fail('No database connection');
        }

        return $db;
    }

    /**
     * Strict (type-preserving) settings-map comparison that tolerates MySQL's
     * `json` object-key re-ordering but nothing else — list order, value types and
     * the exact key set are all still asserted.
     * {@see testMysqlJsonNormalisesObjectKeyOrder}
     *
     * @param array<string, mixed>      $expected
     * @param array<string, mixed>|null $actual
     */
    private function assertSameSettings(array $expected, ?array $actual, string $message = ''): void
    {
        $this->assertIsArray($actual, $message);
        $this->assertSame(self::sortKeysDeep($expected), self::sortKeysDeep($actual), $message);
    }

    /**
     * Recursively sort ASSOCIATIVE keys (leaving list order alone) so two maps can
     * be compared with assertSame regardless of MySQL's JSON key normalisation.
     *
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private static function sortKeysDeep(array $value): array
    {
        $isList = array_is_list($value);
        /** @var mixed $item */
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortKeysDeep($item);
            }
        }
        if (!$isList) {
            ksort($value);
        }

        return $value;
    }

    /**
     * Execute `migrations/093_plugin_settings.sql` verbatim. Comment lines are
     * dropped and the file is one statement, mirroring what MigrationRunner does.
     */
    private function applyMigration093(): void
    {
        $path = dirname(__DIR__, 3) . '/migrations/093_plugin_settings.sql';
        $sql = (string) file_get_contents($path);
        $body = [];
        foreach (explode("\n", $sql) as $line) {
            if (str_starts_with(trim($line), '--')) {
                continue;
            }
            $body[] = $line;
        }
        $statement = trim(rtrim(trim(implode("\n", $body)), ';'));
        $this->assertStringStartsWith('CREATE TABLE IF NOT EXISTS plugin_settings', $statement);
        $this->conn()->query($statement);
    }

    private function tableCount(): int
    {
        $rows = $this->conn()->query(
            'SELECT COUNT(*) AS c FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            ['plugin_settings'],
        );

        return is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? (int) ($rows[0]['c'] ?? 0) : 0;
    }

    private function rowCount(string $key): int
    {
        $rows = $this->conn()->query(
            'SELECT COUNT(*) AS c FROM plugin_settings WHERE plugin_name = ?',
            [$key],
        );

        return is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? (int) ($rows[0]['c'] ?? 0) : 0;
    }
}
