<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Catalog;

use Phlix\Admin\SettingsRepository;
use Phlix\Plugins\Catalog\CatalogSourceResolver;
use Phlix\Plugins\Catalog\PluginCatalogService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Workerman\MySQL\Connection;

/**
 * S217(a): `plugins.catalog.channel` must have a DECLARED default in
 * `config/plugins.php`, not merely a code fallback.
 *
 * Before S217 the key resolved to nothing: `config/plugins.php`'s `catalog`
 * block declared `default_source`, `sources` and `fetch_timeout` only, and
 * `stable` existed solely as the else-branch of
 * {@see PluginCatalogService::channel()}. So
 * `SettingsRepository::hasDefault('plugins.catalog.channel')` was **false** and
 * `getDefault()` returned `null` — the state
 * {@see \Phlix\Tests\Unit\Admin\SettingsDefaultResolvabilityTest} exists to
 * forbid for every key the shared schema publishes.
 *
 * That test can only see keys the vendored schema declares, and
 * `server-settings.schema.json` does not (yet) declare this one, so it was
 * silent here. Publishing the key upstream without the config default would
 * have turned it red. This test guards the config side directly, so the default
 * cannot be dropped again while the upstream publish is still pending.
 *
 * It runs against the repository's REAL `config/` directory, exactly as
 * `SettingsDefaultResolvabilityTest` does — the point is the file actually
 * shipped, not a fixture.
 */
final class CatalogChannelConfigDefaultTest extends TestCase
{
    /**
     * The declared default must EXIST (`hasDefault`), not just read as null.
     *
     * `getDefault()` alone cannot express this: a declared-null default and a
     * missing config path both return `null`. Deleting `'channel' => 'stable'`
     * from `config/plugins.php` reddens this assertion.
     */
    public function testChannelKeyResolvesToADeclaredConfigDefault(): void
    {
        $repo = $this->repositoryOverRealConfigDir();

        self::assertTrue(
            $repo->hasDefault(PluginCatalogService::KEY_CHANNEL),
            PluginCatalogService::KEY_CHANNEL . ' must be declared in config/plugins.php, '
            . 'not left to the code fallback in PluginCatalogService::channel().',
        );
        self::assertSame(
            CatalogSourceResolver::CHANNEL_STABLE,
            $repo->getDefault(PluginCatalogService::KEY_CHANNEL),
        );
    }

    /**
     * The shipped default must be a real channel — and specifically the one
     * that keeps the OFFICIAL catalog on its immutable pinned release tag.
     *
     * A default of `dev` would silently point every fresh install at the
     * catalog repo's moving `master` branch, which is precisely the mutable
     * trust anchor S217(b) documents as opt-in / advanced.
     *
     * Floor (anti-vacuity): `CHANNEL_VALUES` must be non-empty, otherwise
     * `assertContains` over it could never fail.
     */
    public function testShippedDefaultIsAValidChannelThatKeepsThePinnedRef(): void
    {
        self::assertNotEmpty(
            PluginCatalogService::CHANNEL_VALUES,
            'CHANNEL_VALUES is the vocabulary this test checks against; an empty '
            . 'list would make the membership assertion vacuous.',
        );

        $repo    = $this->repositoryOverRealConfigDir();
        $default = $repo->getDefault(PluginCatalogService::KEY_CHANNEL);

        self::assertContains($default, PluginCatalogService::CHANNEL_VALUES);
        self::assertIsString($default);
        self::assertNull(
            CatalogSourceResolver::refForChannel($default),
            'The shipped default channel must resolve the OFFICIAL catalog to '
            . 'OFFICIAL_PINNED_REF (an immutable tag), never to a moving branch.',
        );
    }

    /**
     * The config default and the code fallback must AGREE.
     *
     * `PluginCatalogService::channel()` returns `stable` for any value that is
     * not `dev`, so a config default of anything else would make the declared
     * default a lie about what the service actually reports.
     */
    public function testConfigDefaultAgreesWithTheServiceCodeFallback(): void
    {
        $repo = $this->repositoryOverRealConfigDir();

        $service = new PluginCatalogService(
            new class ($this->uninitialisedConnection(), $this->realConfigDir()) extends SettingsRepository {
                public function getEffective(string $key): mixed
                {
                    // No override row (and no DB): the effective value IS the
                    // config default, resolved against the real config/ dir.
                    return $this->getDefault($key);
                }
            },
        );

        self::assertSame($repo->getDefault(PluginCatalogService::KEY_CHANNEL), $service->channel());
    }

    /**
     * Build a repository pointed at the repo's REAL `config/` directory. The DB
     * connection is never touched by `getDefault()`, so an uninitialised
     * instance is sufficient (and avoids needing a live MySQL).
     */
    private function repositoryOverRealConfigDir(): SettingsRepository
    {
        return new SettingsRepository($this->uninitialisedConnection(), $this->realConfigDir());
    }

    private function realConfigDir(): string
    {
        return dirname(__DIR__, 4) . '/config';
    }

    private function uninitialisedConnection(): Connection
    {
        /** @var Connection $db */
        $db = (new ReflectionClass(Connection::class))->newInstanceWithoutConstructor();

        return $db;
    }
}
