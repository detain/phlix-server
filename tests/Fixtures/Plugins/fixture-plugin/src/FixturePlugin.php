<?php

declare(strict_types=1);

namespace Phlex\Tests\Fixtures\Plugins\FixturePlugin;

use Phlex\Common\Events\Playback\PlaybackStarted;
use Phlex\Plugins\Contract\LifecycleInterface;
use Psr\Container\ContainerInterface;

/**
 * Minimal plugin used by the A.4 integration test
 * (`tests/integration/Plugins/InstallEnableDisableTest.php`).
 *
 * Subscribes to {@see PlaybackStarted} and counts the number of times
 * the listener fires; the test reads the counter to assert that the
 * loader subscribed/unsubscribed correctly.
 */
final class FixturePlugin implements LifecycleInterface
{
    public int $playbackStartedCount = 0;
    public bool $onEnableCalled = false;
    public bool $onDisableCalled = false;

    public function onEnable(ContainerInterface $container): void
    {
        $this->onEnableCalled = true;
    }

    public function onDisable(): void
    {
        $this->onDisableCalled = true;
    }

    public function subscribedEvents(): array
    {
        return [
            PlaybackStarted::class => 'handlePlaybackStarted',
        ];
    }

    public function handlePlaybackStarted(PlaybackStarted $event): void
    {
        $this->playbackStartedCount++;
    }
}
