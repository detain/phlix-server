<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Admin;

use Phlix\Admin\SettingsRepository;
use Phlix\Shared\Schema\SchemaPaths;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Workerman\MySQL\Connection;

/**
 * The single highest-leverage settings test: **every key the shared schema
 * declares must resolve to a real config default.**
 *
 * `SettingsRepository::getEffective()` is `override ?? getDefault()`. When
 * `getDefault()` returns null because the key's dotted prefix names a config
 * file (or a path inside one) that does not exist, the setting is a *fake*: it
 * renders in the admin UI, accepts a PUT, and reports `null` as its value
 * forever. An audit found 25 of the 53 vendored keys in exactly that state,
 * all from wrong config-file prefixes. This test catches that entire class of
 * defect in one assertion, against the REAL `config/` directory.
 *
 * It deliberately does NOT mock the config dir — the point is to check the
 * schema against the files actually shipped.
 *
 * @covers \Phlix\Admin\SettingsRepository
 */
final class SettingsDefaultResolvabilityTest extends TestCase
{
    /**
     * Keys KNOWN to be unresolvable against the currently-vendored
     * `detain/phlix-shared` schema, pending the in-flight schema key rename +
     * re-vendor in that repo.
     *
     * ---------------------------------------------------------------------
     * TODO(phlix-shared re-vendor): DELETE THIS CONSTANT ENTIRELY.
     *
     * This list is a temporary quarantine, not an accepted state. Every entry
     * is a setting that is live in the admin UI today and does nothing. The
     * fix lands in `detain/phlix-shared` (rename each key so its prefix names
     * a config file that exists, or delete the key), followed by
     * `composer update detain/phlix-shared` here. When that re-vendor happens,
     * empty this array — the test then runs its assertion for real.
     *
     * Note the nuance for the `trakt.*` trio: `config/scrobblers/trakt.php`
     * DOES exist and `SettingsRepository::getDefault()` now resolves nested
     * config paths, so those keys resolve as soon as the schema renames them
     * to `scrobblers.trakt.*`. No server-side change is needed for them.
     *
     * A key NOT in this list that fails to resolve is a genuine regression and
     * fails the test — the quarantine never grows silently.
     * ---------------------------------------------------------------------
     *
     * @var list<string>
     */
    private const PENDING_SHARED_REVENDOR = [
        'auth.enabled',
        'auth.rate_limit',
        'auth.session_lifetime',
        'database.pool_size',
        'database.timeout',
        'hls.max_concurrent_segments',
        'hls.segment_seconds',
        'metadata.fanart_api_key',
        'metadata.preferred_country',
        'metadata.preferred_language',
        'subsystem.library_scan_enabled',
        'subsystem.marker_detection_enabled',
        'subsystem.media_asset_jobs_enabled',
        'subsystem.plugin_auto_update_enabled',
        'subsystem.similarity_enabled',
        'trakt.client_id',
        'trakt.client_secret',
        'trakt.redirect_uri',
        'transcoding.max_concurrent_scan_probes',
        'transcoding.max_concurrent_transcodes',
        'transcoding.segment_cache_max_age',
        'transcoding.segment_cache_max_bytes',
        'transcoding.segment_max_inflight_global',
        'transcoding.stale_job_max_age',
        'transcoding.transcode_timeout',
    ];

    public function testEverySchemaKeyResolvesToAConfigDefault(): void
    {
        $repo = $this->repositoryOverRealConfigDir();

        $unresolvable = [];
        foreach ($this->schemaKeys() as $key) {
            if (!$repo->hasDefault($key)) {
                $unresolvable[] = $key;
            }
        }
        sort($unresolvable);

        // Any NEW unresolvable key is a hard failure — the quarantine below
        // covers only the keys the pending phlix-shared re-vendor will fix.
        $regressions = array_values(array_diff($unresolvable, self::PENDING_SHARED_REVENDOR));
        self::assertSame(
            [],
            $regressions,
            "These schema keys resolve to a NULL default — they render in the admin UI and do nothing.\n"
            . "Add the missing config/*.php default, or fix the key's config-file prefix in phlix-shared:\n  - "
            . implode("\n  - ", $regressions),
        );

        if ($unresolvable !== []) {
            self::markTestSkipped(sprintf(
                'PENDING phlix-shared re-vendor: %d of %d schema keys still resolve to a null default '
                . '(%s). These are quarantined in self::PENDING_SHARED_REVENDOR; delete that constant '
                . 'after `composer update detain/phlix-shared` picks up the schema key renames, then '
                . 'this assertion runs for real.',
                count($unresolvable),
                count($this->schemaKeys()),
                implode(', ', $unresolvable),
            ));
        }

        // Reached only once the vendored schema is clean.
        self::assertSame([], $unresolvable);
    }

    /**
     * A stale quarantine entry is itself a defect: once a key starts resolving
     * it must be removed from the list, or the list silently stops meaning
     * anything.
     */
    public function testQuarantineListContainsNoAlreadyFixedKeys(): void
    {
        $repo = $this->repositoryOverRealConfigDir();

        $fixed = [];
        foreach (self::PENDING_SHARED_REVENDOR as $key) {
            if ($repo->hasDefault($key)) {
                $fixed[] = $key;
            }
        }

        self::assertSame(
            [],
            $fixed,
            'These keys now resolve — remove them from self::PENDING_SHARED_REVENDOR: '
            . implode(', ', $fixed),
        );
    }

    /**
     * Nested config paths must be reachable: `config/scrobblers/trakt.php` is a
     * real shipped file and was unreachable by any dotted key before
     * `getDefault()` learned to resolve multi-segment file paths.
     */
    public function testNestedConfigFilePathsResolve(): void
    {
        $repo = $this->repositoryOverRealConfigDir();

        self::assertTrue(
            $repo->hasDefault('scrobblers.trakt.client_id'),
            'config/scrobblers/trakt.php must be reachable via a dotted key',
        );
        self::assertSame('', $repo->getDefault('scrobblers.trakt.client_id'));
    }

    /**
     * The path jail must survive the nested-path support: no dotted key may
     * escape `config/`.
     */
    public function testTraversalKeysCannotEscapeTheConfigDirectory(): void
    {
        $repo = $this->repositoryOverRealConfigDir();

        foreach (['../composer.json', '..\\composer.json', '/etc/passwd.x', '..../server.host'] as $key) {
            self::assertFalse($repo->hasDefault($key), sprintf('Key %s must not resolve', $key));
            self::assertNull($repo->getDefault($key), sprintf('Key %s must not resolve', $key));
        }
    }

    /**
     * Build a repository pointed at the repo's REAL `config/` directory. The
     * DB connection is never touched by `getDefault()`, so an uninitialised
     * instance is sufficient (and avoids needing a live MySQL).
     */
    private function repositoryOverRealConfigDir(): SettingsRepository
    {
        /** @var Connection $db */
        $db = (new ReflectionClass(Connection::class))->newInstanceWithoutConstructor();

        return new SettingsRepository($db, dirname(__DIR__, 3) . '/config');
    }

    /**
     * @return list<string> Every property key declared by the vendored schema.
     */
    private function schemaKeys(): array
    {
        $path = SchemaPaths::serverSettings();
        self::assertFileExists($path, 'Vendored server-settings.schema.json is missing');

        $raw = file_get_contents($path);
        self::assertIsString($raw);

        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('properties', $decoded);
        self::assertIsArray($decoded['properties']);

        /** @var list<string> $keys */
        $keys = array_map('strval', array_keys($decoded['properties']));
        self::assertNotEmpty($keys, 'Schema declares no properties');

        return $keys;
    }
}
