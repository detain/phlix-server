<?php

declare(strict_types=1);

namespace Phlex\Tests\Fixtures\Events;

use Phlex\Common\Events\AbstractEvent;

/**
 * Trivial event used by EventDispatcherFactoryTest /
 * ListenerRegistryTest to exercise dispatch behaviour without coupling
 * the tests to a specific production event.
 */
final class SampleEvent extends AbstractEvent
{
    public function __construct(public readonly string $message)
    {
        parent::__construct();
    }
}
