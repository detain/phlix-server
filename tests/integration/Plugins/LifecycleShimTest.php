<?php

declare(strict_types=1);

namespace Phlex\Tests\Integration\Plugins;

use Phlex\Plugins\Contract\LifecycleInterface as LegacyLifecycleInterface;
use Phlex\Shared\Plugin\LifecycleInterface as SharedLifecycleInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Guard for the `interface … extends …` bridge that keeps
 * `Phlex\Plugins\Contract\LifecycleInterface` (pre-0.11 FQCN) usable
 * after the contract moved to `Phlex\Shared\Plugin\LifecycleInterface`.
 *
 * @coversNothing
 */
final class LifecycleShimTest extends TestCase
{
    public function test_legacy_implementer_satisfies_shared_contract(): void
    {
        $impl = new LegacyImplementer();

        $this->assertInstanceOf(LegacyLifecycleInterface::class, $impl);
        $this->assertInstanceOf(SharedLifecycleInterface::class, $impl);

        $this->assertTrue(
            is_a($impl, SharedLifecycleInterface::class),
            'Legacy implementers must satisfy the shared LifecycleInterface contract.'
        );
    }
}

/**
 * Fixture: implements the LEGACY `Phlex\Plugins\Contract\LifecycleInterface`
 * — proving the `extends` bridge keeps existing plugin code working.
 */
final class LegacyImplementer implements LegacyLifecycleInterface
{
    public function onEnable(ContainerInterface $container): void
    {
    }

    public function onDisable(): void
    {
    }

    public function subscribedEvents(): array
    {
        return [];
    }
}
