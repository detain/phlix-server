<?php

declare(strict_types=1);

namespace Phlex\Tests\Fixtures\Container;

/**
 * Companion fixture for {@see CircularA}; PHP-DI must detect that A->B->A is
 * unresolvable.
 */
final class CircularB
{
    public function __construct(public CircularA $a)
    {
    }
}
