<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Fixtures;

use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * A tiny, real controller used to exercise the Router's string-handler
 * resolution paths (SV-4.8): both the DI-container branch (resolved via
 * `$container->get(self::class)`) and the container-less fallback branch
 * (`new self()`). Having a concrete, autoloadable class name is required
 * because the fallback path calls `new $class()` with the FQCN string.
 */
final class RouterFixtureController
{
    /** @var bool Flipped to true once handle()/returnsNonResponse() runs, proving the method was invoked. */
    public bool $handled = false;

    /**
     * Normal handler: records invocation and returns a marked Response so a
     * test can assert this instance's method actually ran.
     *
     * @param array<string, string> $params
     */
    public function handle(Request $request, array $params): Response
    {
        $this->handled = true;

        return (new Response())
            ->status(200)
            ->json(['from' => 'RouterFixtureController', 'params' => $params]);
    }

    /**
     * Handler that deliberately returns a non-Response value to exercise the
     * BadMethodCallException guard in Router::callHandler() for the array/DI path.
     *
     * @param array<string, string> $params
     * @return array<string, string>
     */
    public function returnsNonResponse(Request $request, array $params): array
    {
        $this->handled = true;

        return ['not' => 'a response'];
    }
}
