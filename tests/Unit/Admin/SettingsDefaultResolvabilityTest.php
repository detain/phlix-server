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
 * forever. An audit found 25 of the then-53 vendored keys in exactly that
 * state, all from wrong config-file prefixes; phlix-shared v0.24.0 removed
 * them, leaving 40 keys that all resolve. This test catches that entire class
 * of defect in one assertion, against the REAL `config/` directory.
 *
 * It deliberately does NOT mock the config dir — the point is to check the
 * schema against the files actually shipped.
 */
final class SettingsDefaultResolvabilityTest extends TestCase
{
    /**
     * Every key the vendored schema declares must resolve to a real config
     * default — no quarantine, no exceptions.
     *
     * This assertion ran quarantined behind a `PENDING_SHARED_REVENDOR` list of
     * 25 unresolvable keys while `detain/phlix-shared` still declared settings
     * whose dotted prefix named a config file that does not exist. That
     * re-vendor landed in phlix-shared v0.24.0 (the schema dropped from 53 keys
     * to 40, removing every key with no resolvable config path and no runtime
     * consumer), so the quarantine constant and its companion staleness test
     * were deleted and this assertion now runs for real against all 40 keys.
     *
     * Do NOT re-introduce a quarantine list here. A key that fails to resolve
     * is a live setting that renders in the admin UI, accepts a PUT, and
     * reports `null` forever — fix the config default or remove the key from
     * the shared schema instead.
     */
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

        self::assertSame(
            [],
            $unresolvable,
            "These schema keys resolve to a NULL default — they render in the admin UI and do nothing.\n"
            . "Add the missing config/*.php default, or remove the key from phlix-shared:\n  - "
            . implode("\n  - ", $unresolvable),
        );
    }

    /**
     * A resolvable default is not enough — an `enum`-constrained key's config
     * default must actually BE one of its declared members.
     *
     * `config/transcoding.php` shipped `'preferred_accelerator' => ... ?: null`
     * against a schema whose auto-detect sentinel is the empty string, so GET
     * returned `null`, the SPA's select matched no option and rendered blank,
     * and a plain Save then round-tripped a value the PUT validator rejects.
     * This assertion covers that whole class, not just the one key.
     */
    public function testEnumConstrainedKeysHaveADefaultThatIsADeclaredMember(): void
    {
        $repo = $this->repositoryOverRealConfigDir();

        foreach ($this->schemaProperties() as $key => $def) {
            if (!isset($def['enum']) || !is_array($def['enum'])) {
                continue;
            }

            /** @var mixed $default */
            $default = $repo->getDefault($key);
            self::assertContains(
                $default,
                $def['enum'],
                sprintf(
                    'config default for %s is %s, which is not one of its schema enum members [%s]',
                    $key,
                    var_export($default, true),
                    implode(', ', array_map(
                        static fn (mixed $m): string => var_export($m, true),
                        $def['enum'],
                    )),
                ),
            );
        }
    }

    /**
     * The auto-detect sentinel specifically: phlix-shared v0.24.0 swapped the
     * JSON `null` enum member for `''`, and `config/transcoding.php` must match
     * it exactly (not `null`, not absent).
     */
    public function testPreferredAcceleratorDefaultIsTheEmptyStringSentinel(): void
    {
        $repo = $this->repositoryOverRealConfigDir();

        self::assertTrue($repo->hasDefault('transcoding.preferred_accelerator'));
        self::assertSame('', $repo->getDefault('transcoding.preferred_accelerator'));
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
     * @return array<string, array<string, mixed>> Every declared property, keyed by dotted key.
     */
    private function schemaProperties(): array
    {
        $path = SchemaPaths::serverSettings();
        self::assertFileExists($path, 'Vendored server-settings.schema.json is missing');

        $raw = file_get_contents($path);
        self::assertIsString($raw);

        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('properties', $decoded);
        self::assertIsArray($decoded['properties']);

        /** @var array<string, array<string, mixed>> $properties */
        $properties = $decoded['properties'];
        self::assertNotEmpty($properties, 'Schema declares no properties');

        return $properties;
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
