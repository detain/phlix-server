<?php

declare(strict_types=1);

namespace Phlix\Tests\Fixtures\Events;

/**
 * Static-method fixture for the array-callable hash branch in
 * {@see \Phlix\Common\Events\ListenerRegistry::callableHash()}.
 *
 * @internal
 */
final class StaticListener
{
    public static function handle(SampleEvent $_e): void
    {
    }
}
