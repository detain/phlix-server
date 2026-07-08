<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins;

use Mockery\Expectation;
use Mockery\MockInterface;

/**
 * Test helper that narrows Mockery's fluent expectation API for static analysis.
 *
 * {@see MockInterface::shouldReceive()} declares a union return type of
 * `ExpectationInterface|HigherOrderMessage`, and the latter relies on `__call()`
 * magic, so PHPStan cannot see the fluent methods (`once()`, `with()`,
 * `andReturn()`, ...). Routing `shouldReceive()` through this helper exposes the
 * concrete {@see Expectation} the runtime actually returns without changing any
 * behaviour.
 */
trait MockeryExpectationTrait
{
    /**
     * The runtime value is a {@see \Mockery\CompositeExpectation} whose fluent
     * methods are provided via `__call()`; it is documented here as the
     * concrete {@see Expectation} so static analysis can see that fluent API.
     *
     * @return Expectation
     */
    private function expect(MockInterface $mock, string $method)
    {
        /** @var Expectation $expectation */
        $expectation = $mock->shouldReceive($method);

        return $expectation;
    }
}
