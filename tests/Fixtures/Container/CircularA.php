<?php

declare(strict_types=1);

namespace Phlix\Tests\Fixtures\Container;

/**
 * Fixture used by ContainerFactoryTest::test_get_with_circular_dependency_throws
 * to demonstrate PHP-DI's circular-dependency detection.
 */
final class CircularA
{
    public function __construct(public CircularB $b)
    {
    }
}
