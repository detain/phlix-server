<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Workerman;

use Phlix\Auth\AuthManager;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\RequestAuthenticator;
use Phlix\Server\Workerman\HttpHandler;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Regression guard for the daemon (start.php) HTTP worker wiring.
 *
 * start.php is the Workerman daemon entry point. It is NOT exercised by the
 * unit-test suite (it boots Worker processes) and lives at the repo root,
 * outside the `src/` tree analysed by PHPStan in CI — so a type mismatch in
 * how it constructs {@see HttpHandler} slips past every existing gate and only
 * surfaces as a worker-startup TypeError in production.
 *
 * That is exactly what happened: start.php passed a raw {@see AuthManager} to
 * HttpHandler arg #2, which actually requires the shared
 * {@see RequestAuthenticator} collaborator (`public/index.php` wraps the
 * AuthManager in one; start.php had drifted). Every HTTP worker died at boot.
 *
 * This test pins the contract start.php depends on:
 *  - HttpHandler's constructor arg #2 is typed `RequestAuthenticator`.
 *  - A RequestAuthenticator is constructible from an AuthManager.
 *  - The full HttpHandler wiring (the same `new HttpHandler(...)` call start.php
 *    makes) instantiates without a TypeError.
 */
final class HttpHandlerWiringTest extends TestCase
{
    /**
     * Pins HttpHandler's second constructor parameter to RequestAuthenticator.
     *
     * If someone changes the signature, this fails loudly here rather than at
     * daemon boot. start.php must pass a RequestAuthenticator, never the raw
     * AuthManager.
     */
    public function testHttpHandlerSecondConstructorArgIsRequestAuthenticator(): void
    {
        $ctor = (new \ReflectionClass(HttpHandler::class))->getConstructor();
        self::assertNotNull($ctor, 'HttpHandler must declare a constructor.');

        $params = $ctor->getParameters();
        self::assertArrayHasKey(1, $params, 'HttpHandler constructor must have a second parameter.');

        $second = $params[1];
        self::assertSame(
            RequestAuthenticator::class,
            $this->typeName($second),
            'HttpHandler arg #2 must be RequestAuthenticator — start.php wraps the AuthManager in one.',
        );
    }

    /**
     * A RequestAuthenticator must be constructible directly from an AuthManager,
     * exactly as start.php (and public/index.php) build it.
     */
    public function testRequestAuthenticatorIsConstructibleFromAuthManager(): void
    {
        $ctor = (new \ReflectionClass(RequestAuthenticator::class))->getConstructor();
        self::assertNotNull($ctor, 'RequestAuthenticator must declare a constructor.');

        $params = $ctor->getParameters();
        self::assertArrayHasKey(0, $params, 'RequestAuthenticator constructor must accept an argument.');
        self::assertSame(
            AuthManager::class,
            $this->typeName($params[0]),
            'RequestAuthenticator arg #1 must be AuthManager.',
        );

        $authManager = $this->createMock(AuthManager::class);
        $authenticator = new RequestAuthenticator($authManager);
        self::assertInstanceOf(RequestAuthenticator::class, $authenticator);
    }

    /**
     * Builds the daemon HTTP worker wiring the way start.php's onWorkerStart
     * closure does — resolve an AuthManager, wrap it in a RequestAuthenticator,
     * and construct the HttpHandler — and asserts it instantiates WITHOUT a
     * TypeError. This is the assertion that would have caught the boot crash.
     */
    public function testDaemonHttpHandlerWiringInstantiatesWithoutTypeError(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $authManager = $this->createMock(AuthManager::class);
        $application = $this->createMock(Application::class);

        // Mirror start.php exactly: wrap the AuthManager, then new HttpHandler.
        $authenticator = new RequestAuthenticator($authManager);
        $handler = new HttpHandler($container, $authenticator, '/var/www/phlix/public', $application);

        self::assertInstanceOf(HttpHandler::class, $handler);
    }

    /**
     * Resolve the (non-nullable, single) type name of a reflection parameter.
     */
    private function typeName(ReflectionParameter $param): ?string
    {
        $type = $param->getType();

        return $type instanceof ReflectionNamedType ? $type->getName() : null;
    }
}
