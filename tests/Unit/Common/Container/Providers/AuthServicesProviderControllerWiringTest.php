<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Container\Providers;

use DI\Container;
use DI\ContainerBuilder;
use Phlix\Auth\AuthManager;
use Phlix\Auth\AuthProviderBootstrapper;
use Phlix\Auth\RateLimitException;
use Phlix\Auth\WebAuthn\WebAuthnManager;
use Phlix\Common\Container\Providers\AuthServicesProvider;
use Phlix\Common\RateLimit\RateLimitProfiles;
use Phlix\Plugins\Oidc\Controller\OidcCallbackController;
use Phlix\Server\Http\Controllers\AuthController;
use Phlix\Server\Http\Controllers\AuthProviderController;
use Phlix\Server\Http\Controllers\WebAuthnController;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * SV-4.15(f): {@see AuthServicesProvider} binds each per-surface limiter to the
 * matching controller constructor param (register/refresh -> {@see AuthController},
 * webauthn start/finish -> {@see WebAuthnController}). Because those params are
 * optional, autowiring would silently leave them null without the explicit
 * `constructorParameter()` bindings — so this verifies the container actually
 * injects a WORKING limiter by resolving the controller from the container
 * (with mock managers) and driving a surface over its budget end-to-end.
 *
 * @covers \Phlix\Common\Container\Providers\AuthServicesProvider
 */
#[CoversClass(AuthServicesProvider::class)]
final class AuthServicesProviderControllerWiringTest extends TestCase
{
    public function testAuthControllerRegisterIsWiredToADbBackedLimiter(): void
    {
        $controller = $this->buildContainer($this->overLimitDb())->get(AuthController::class);
        self::assertInstanceOf(AuthController::class, $controller);

        $request = new Request();
        $request->headers = ['X-Forwarded-For' => '203.0.113.1'];
        $request->body = ['username' => 'a', 'email' => 'a@b.c', 'password' => 'pw123456'];

        $this->expectException(RateLimitException::class);
        $controller->register($request, []);
    }

    public function testAuthControllerRefreshIsWiredToADbBackedLimiter(): void
    {
        $controller = $this->buildContainer($this->overLimitDb())->get(AuthController::class);
        self::assertInstanceOf(AuthController::class, $controller);

        $request = new Request();
        $request->headers = ['X-Forwarded-For' => '203.0.113.2'];
        $request->body = ['refresh_token' => 'tok'];

        $this->expectException(RateLimitException::class);
        $controller->refresh($request, []);
    }

    public function testWebAuthnStartIsWiredToADbBackedLimiter(): void
    {
        $controller = $this->buildContainer($this->overLimitDb())->get(WebAuthnController::class);
        self::assertInstanceOf(WebAuthnController::class, $controller);

        $request = new Request();
        $request->body = ['username' => 'alice'];

        $this->expectException(RateLimitException::class);
        $controller->startAuthentication($request, []);
    }

    public function testWebAuthnFinishIsWiredToADbBackedLimiter(): void
    {
        $controller = $this->buildContainer($this->overLimitDb())->get(WebAuthnController::class);
        self::assertInstanceOf(WebAuthnController::class, $controller);

        $request = new Request();
        $request->body = ['username' => 'alice', 'credential' => ['id' => 'x'], 'challenge' => 'c'];

        $this->expectException(RateLimitException::class);
        $controller->finishAuthentication($request, []);
    }

    /**
     * The container-injected limiters are the SAME shared instances registered
     * under their {@see RateLimitProfiles} ids (proves the correct id binding).
     */
    public function testInjectedLimitersMatchTheRegisteredProfileInstances(): void
    {
        $container = $this->buildContainer($this->overLimitDb());

        $controller = $container->get(AuthController::class);
        $registerLimiter = (fn () => $this->registerLimiter)->call($controller);
        $refreshLimiter = (fn () => $this->refreshLimiter)->call($controller);

        self::assertSame($container->get(RateLimitProfiles::REGISTER), $registerLimiter);
        self::assertSame($container->get(RateLimitProfiles::REFRESH), $refreshLimiter);

        $webauthn = $container->get(WebAuthnController::class);
        $startLimiter = (fn () => $this->startAuthLimiter)->call($webauthn);
        $finishLimiter = (fn () => $this->finishAuthLimiter)->call($webauthn);

        self::assertSame($container->get(RateLimitProfiles::WEBAUTHN_START), $startLimiter);
        self::assertSame($container->get(RateLimitProfiles::WEBAUTHN_FINISH), $finishLimiter);
    }

    /**
     * S44: the enable-state + OIDC-route controllers autowire cleanly from the
     * container. AuthProviderController now needs an AuthProviderBootstrapper,
     * and the (previously dead) OidcCallbackController is bound with its
     * DB-backed state-store `db` param. Resolving all three proves the DI graph
     * has no missing binding.
     */
    public function testS44AuthProviderWiringResolves(): void
    {
        $container = $this->buildContainer($this->overLimitDb());

        self::assertInstanceOf(
            AuthProviderBootstrapper::class,
            $container->get(AuthProviderBootstrapper::class),
        );
        self::assertInstanceOf(
            AuthProviderController::class,
            $container->get(AuthProviderController::class),
        );
        self::assertInstanceOf(
            OidcCallbackController::class,
            $container->get(OidcCallbackController::class),
        );
    }

    /**
     * A Connection whose bucket read always reports an over-budget window, so the
     * container-injected {@see \Phlix\Common\RateLimit\DbRateLimiter} trips at
     * once.
     */
    private function overLimitDb(): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([['attempts' => 999, 'reset_at' => time() + 600]]);

        return $db;
    }

    private function buildContainer(Connection $db): Container
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        (new AuthServicesProvider())->register($builder, []);

        // Override the heavy managers with mocks so the controllers autowire
        // without standing up the whole auth graph; the limiter params stay bound
        // to the provider's RateLimitProfiles ids.
        $builder->addDefinitions([
            Connection::class => $db,
            AuthManager::class => $this->createMock(AuthManager::class),
            WebAuthnManager::class => $this->createMock(WebAuthnManager::class),
        ]);

        return $builder->build();
    }
}
