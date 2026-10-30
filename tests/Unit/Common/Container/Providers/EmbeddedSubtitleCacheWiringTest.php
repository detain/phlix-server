<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlix\Media\Subtitles\EmbeddedSubtitleCache;
use Phlix\Media\Subtitles\SubtitleStorage;
use Phlix\Common\Container\Providers\MediaServicesProvider;
use PHPUnit\Framework\TestCase;

/**
 * Wiring guard for the embedded-VTT cache definition (minors M-SERVER-54(a)).
 *
 * The controller test hand-builds the cache with a temp dir and therefore
 * proves NOTHING about the production binding: only these definitions decide
 * that the cache roots at `subtitles.storage_path` + `/embedded` (the SAME
 * operator volume as downloaded subtitles, its own namespace) and stays a
 * process-scoped singleton so racing request arms share one view. A silent
 * drop of the factory to plain autowiring would pin the historic
 * `/var/subtitles/embedded` default no matter what the operator configures —
 * invisible to every hand-constructed unit test.
 */
final class EmbeddedSubtitleCacheWiringTest extends TestCase
{
    private function container(string $storageRoot): \DI\Container
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        (new MediaServicesProvider())->register($builder, []);

        $builder->addDefinitions([
            'app.config' => ['subtitles' => ['storage_path' => $storageRoot]],
        ]);

        return $builder->build();
    }

    public function testTheCacheRootsAtTheConfiguredSubtitleVolumePlusEmbedded(): void
    {
        $cache = $this->container('/srv/op-root')->get(EmbeddedSubtitleCache::class);

        self::assertInstanceOf(EmbeddedSubtitleCache::class, $cache);
        self::assertSame(
            '/srv/op-root/embedded',
            $cache->baseDir(),
            'the cache must ride the configured subtitles.storage_path, in its own embedded/ namespace'
        );

        // A trailing slash on the operator value must not double it.
        $slashy = $this->container('/srv/op-root/')->get(EmbeddedSubtitleCache::class);
        self::assertSame('/srv/op-root/embedded', $slashy->baseDir());
    }

    public function testTheCacheIsProcessScopedAndIndependentOfRemoteStorage(): void
    {
        $container = $this->container('/srv/op-root');

        $first = $container->get(EmbeddedSubtitleCache::class);
        $second = $container->get(EmbeddedSubtitleCache::class);
        self::assertSame($first, $second, 'one shared cache instance per container (worker-process scope)');

        // The remote (downloaded) storage keeps the UN-suffixed root: the two
        // namespaces must never collide on disk.
        $storage = $container->get(SubtitleStorage::class);
        self::assertSame('/srv/op-root', $storage->baseDir());
        self::assertStringStartsWith('/srv/op-root/embedded/', $first->pathFor('item-1', 0, 1, 1));
        self::assertStringStartsWith('/srv/op-root/item-1/', $storage->itemDir('item-1') . '/');
    }
}
