<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Container\Providers;

use DI\Container;
use DI\ContainerBuilder;
use Phlix\Auth\RateLimitException;
use Phlix\Common\Container\Providers\AuthServicesProvider;
use Phlix\Common\Container\Providers\HubServicesProvider;
use Phlix\Common\RateLimit\RateLimitProfiles;
use Phlix\Hub\HubClient;
use Phlix\Server\Http\Controllers\HubJwksController;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * SV-4.15(g): {@see HubServicesProvider} binds the worker-local in-memory
 * {@see RateLimitProfiles::JWKS} limiter to {@see HubJwksController}'s optional
 * `limiter` ctor param. Because that param is optional, autowiring would silently
 * leave it null without the explicit `constructorParameter()` binding — so this
 * resolves the controller from a container (with the {@see HubClient} graph
 * mocked out) and verifies the container actually injects the SAME registered
 * profile instance AND that it enforces end-to-end.
 *
 * The JWKS profile itself is registered in {@see AuthServicesProvider} (all
 * providers merge into one container), so both providers are registered here.
 *
 */
final class HubServicesProviderJwksWiringTest extends TestCase
{
    public function testInjectedLimiterMatchesTheRegisteredJwksProfile(): void
    {
        $container = $this->buildContainer([]);

        $controller = $container->get(HubJwksController::class);
        self::assertInstanceOf(HubJwksController::class, $controller);

        $limiter = (fn () => $this->limiter)->call($controller);

        self::assertSame($container->get(RateLimitProfiles::JWKS), $limiter);
    }

    public function testContainerWiredJwksLimiterEnforcesEndToEnd(): void
    {
        // jwks max = 2 → first request passes, second trips.
        $container = $this->buildContainer([
            'rate_limit' => ['jwks' => ['max' => 2, 'window' => 60]],
        ]);

        $controller = $container->get(HubJwksController::class);
        self::assertInstanceOf(HubJwksController::class, $controller);

        $request = new Request();
        $request->headers = ['X-Forwarded-For' => '203.0.113.99'];

        // First hit is under budget.
        self::assertSame(200, $controller->handle($request, [])->statusCode);

        // Second hit from the same IP trips the container-injected limiter.
        $this->expectException(RateLimitException::class);
        $controller->handle($request, []);
    }

    /**
     * @param array<string, mixed> $appConfig
     */
    private function buildContainer(array $appConfig): Container
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);

        // AuthServicesProvider registers the JWKS profile; HubServicesProvider
        // binds it to HubJwksController.
        (new AuthServicesProvider())->register($builder, $appConfig);
        (new HubServicesProvider())->register($builder, $appConfig);

        // Override the heavy HubClient graph with a mock returning an empty JWKS
        // so the controller resolves without standing up the key manager / HTTP
        // client; the limiter param stays bound to RateLimitProfiles::JWKS.
        $hubClient = $this->createMock(HubClient::class);
        $hubClient->method('getPublicKeysJwk')->willReturn([]);
        $builder->addDefinitions([HubClient::class => $hubClient]);

        return $builder->build();
    }
}
