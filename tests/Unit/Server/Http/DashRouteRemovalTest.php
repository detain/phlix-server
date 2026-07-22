<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http;

use Phlix\Server\Http\Controllers\DashController;
use Phlix\Server\Http\Router;
use PHPUnit\Framework\TestCase;

/**
 * S28 sub-task (c): the dead DASH route helper stays gone.
 *
 * `Router::dashStreaming()` registered `/dash/...` routes pointing at
 * `DashController::getMasterManifest` / `getAdaptationSetManifest` / `getSegment`
 * — methods that NEVER existed on the controller. It had zero callers, so it was
 * pure dead code that would 500 on any hit. This guard locks the removal:
 *
 *  - `Router` must not declare `dashStreaming()` again, and
 *  - `DashController` must not sprout the phantom method names the dead helper
 *    referenced (the real DASH surface is `getManifest()` + `serveFile()`, wired
 *    in `Application::loadStreamingRoutes()` and left untouched).
 *
 * @covers \Phlix\Server\Http\Router
 */
final class DashRouteRemovalTest extends TestCase
{
    public function testRouterNoLongerDeclaresTheDeadDashHelper(): void
    {
        self::assertFalse(
            method_exists(Router::class, 'dashStreaming'),
            'Router::dashStreaming() was dead code (zero callers, phantom handler methods) and must stay removed.',
        );
    }

    /**
     * @return list<array{0:string}>
     */
    public static function phantomMethods(): array
    {
        return [
            ['getMasterManifest'],
            ['getAdaptationSetManifest'],
            ['getSegment'],
        ];
    }

    /**
     * @dataProvider phantomMethods
     */
    public function testDashControllerHasNoPhantomHandlerMethods(string $method): void
    {
        self::assertFalse(
            method_exists(DashController::class, $method),
            "DashController::{$method}() never existed; the removed dashStreaming() referenced it.",
        );
    }

    public function testRealDashHandlersAreStillPresent(): void
    {
        self::assertTrue(method_exists(DashController::class, 'getManifest'));
        self::assertTrue(method_exists(DashController::class, 'serveFile'));
    }
}
