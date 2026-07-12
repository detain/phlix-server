<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlix\Common\Container\Providers\LiveTvServicesProvider;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\LiveTv\LiveTvManager;
use Phlix\LiveTv\Recorder;
use Phlix\LiveTv\Recording\RecordingScheduler;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * SV-3.1b0: the container yields a FULLY-wired DVR stack per worker.
 *
 * Resolving {@see LiveTvManager} builds a {@see Recorder} that has its ffmpeg
 * path and back-reference LiveTvManager set (so {@see Recorder::resolveTunerStreamUrl()}
 * is reachable), and the whole stack is shared as singletons.
 *
 * @covers \Phlix\Common\Container\Providers\LiveTvServicesProvider
 */
final class LiveTvServicesProviderTest extends TestCase
{
    /**
     * @return \Psr\Container\ContainerInterface
     */
    private function buildContainer(): \Psr\Container\ContainerInterface
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);

        (new LiveTvServicesProvider())->register($builder, []);

        // Satisfy the dependencies the provider resolves from the container.
        $builder->addDefinitions([
            Connection::class => $this->createMock(Connection::class),
            'logger.livetv'   => new StructuredLogger('livetv', []),
            'app.config'      => [
                'ffmpeg' => ['ffmpeg_path' => '/opt/custom/ffmpeg'],
                'livetv' => [
                    'dvr' => [
                        'storage_path'      => '/srv/dvr',
                        'max_storage_bytes' => 1024,
                    ],
                    'comskip' => ['binary_path' => '/usr/bin/comskip'],
                ],
            ],
        ]);

        return $builder->build();
    }

    private function readPrivate(object $obj, string $prop): mixed
    {
        $ref = new \ReflectionObject($obj);
        $p = $ref->getProperty($prop);
        $p->setAccessible(true);
        return $p->getValue($obj);
    }

    public function testContainerYieldsFullyWiredRecorder(): void
    {
        $container = $this->buildContainer();

        /** @var LiveTvManager $manager */
        $manager = $container->get(LiveTvManager::class);
        $this->assertInstanceOf(LiveTvManager::class, $manager);

        $recorder = $manager->getRecorder();
        $this->assertInstanceOf(Recorder::class, $recorder);

        // The Recorder↔LiveTvManager cycle was closed: liveTvManager is set to
        // the SAME manager, so resolveTunerStreamUrl() is reachable.
        $this->assertSame(
            $manager,
            $this->readPrivate($recorder, 'liveTvManager'),
            'Recorder must be linked back to its LiveTvManager'
        );

        // ffmpeg path came from app.config['ffmpeg']['ffmpeg_path'].
        $this->assertSame('/opt/custom/ffmpeg', $this->readPrivate($recorder, 'ffmpegPath'));

        // Storage path + max bytes came from the dvr config block.
        $this->assertSame('/srv/dvr', $this->readPrivate($recorder, 'storagePath'));
        $this->assertSame(1024, $this->readPrivate($recorder, 'maxStorageBytes'));

        // A comskip onComplete hook was registered (the constructor pushes one
        // when a ComskipLifecycleManager is supplied).
        $callbacks = $this->readPrivate($recorder, 'onCompleteCallbacks');
        $this->assertIsArray($callbacks);
        $this->assertNotEmpty($callbacks, 'comskip lifecycle hook must be wired');
    }

    public function testStackIsSharedSingletons(): void
    {
        $container = $this->buildContainer();

        /** @var LiveTvManager $manager */
        $manager = $container->get(LiveTvManager::class);

        // The Recorder resolved directly is the SAME instance the manager holds.
        $this->assertSame($manager->getRecorder(), $container->get(Recorder::class));
        // Manager is a singleton.
        $this->assertSame($manager, $container->get(LiveTvManager::class));

        // RecordingScheduler resolves and shares the same Recorder + manager.
        /** @var RecordingScheduler $scheduler */
        $scheduler = $container->get(RecordingScheduler::class);
        $this->assertInstanceOf(RecordingScheduler::class, $scheduler);
        $this->assertSame($manager, $this->readPrivate($scheduler, 'liveTvManager'));
        $this->assertSame($manager->getRecorder(), $this->readPrivate($scheduler, 'recorder'));
    }
}
