<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http;

use PHPUnit\Framework\TestCase;
use Phlix\Plugins\Github\Controller\GithubCallbackController;
use Phlix\Plugins\Oidc\Controller\OidcCallbackController;
use Phlix\Server\Http\AuthProviderRouteRegistrar;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Server\Http\Router;
use Psr\Container\ContainerInterface;

/**
 * S47 — the centralized, mandatory auth-provider route registrar. This is the
 * structural guard against the S44 dead-OIDC bug (a provider shipping with its
 * routes unregistered): the routes are asserted here, so a missing one is a
 * failing test rather than silent dead code.
 */
final class AuthProviderRouteRegistrarTest extends TestCase
{
    /**
     * Every AUTHENTICATED identity route is registered AND sits behind
     * AuthMiddleware — an unauthenticated request short-circuits to 401 before
     * any handler runs (so no container is needed). This covers the NEW S47
     * DELETE unlink route in particular.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function authenticatedRoutes(): array
    {
        return [
            'list identities'  => ['GET', '/auth/identities'],
            'unlink identity'  => ['DELETE', '/auth/identities/some-id'],
            'link oidc'        => ['GET', '/auth/identities/link/oidc'],
            'link ldap'        => ['POST', '/auth/identities/link/ldap'],
            'link hub'         => ['POST', '/auth/identities/link/hub'],
            'link github'      => ['GET', '/auth/identities/link/github'],
        ];
    }

    /**
     * @dataProvider authenticatedRoutes
     */
    public function test_authenticated_routes_are_registered_behind_auth(string $method, string $path): void
    {
        $router = new Router();
        (new AuthProviderRouteRegistrar())->register($router);

        $request = new Request();
        $request->method = $method;
        $request->path = $path;
        // No userId → AuthMiddleware must reject with 401 (proves the route
        // exists AND is authenticated; a missing route would 404).

        $response = $router->dispatch($request);

        $this->assertSame(401, $response->statusCode, "$method $path should be auth-gated, not missing");
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('auth.required', $body['code']);
    }

    /**
     * The UNAUTHENTICATED OIDC login flow (authorize + callback) is registered.
     * Proven by dispatching with a container that returns a fake controller whose
     * methods emit a sentinel — reached only if the route is wired.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function oidcLoginRoutes(): array
    {
        return [
            'authorize' => ['GET', '/auth/oidc/authorize'],
            'callback'  => ['GET', '/auth/oidc/callback'],
        ];
    }

    /**
     * @dataProvider oidcLoginRoutes
     */
    public function test_oidc_login_routes_are_registered_unauthenticated(string $method, string $path): void
    {
        $fake = new class {
            public function authorize(Request $request, array $params): Response
            {
                return (new Response())->status(299)->json(['hit' => 'authorize']);
            }

            public function callback(Request $request, array $params): Response
            {
                return (new Response())->status(299)->json(['hit' => 'callback']);
            }
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturnCallback(
            static fn (string $id): object => $id === OidcCallbackController::class ? $fake : new \stdClass(),
        );

        $router = new Router($container);
        (new AuthProviderRouteRegistrar())->register($router);

        $request = new Request();
        $request->method = $method;
        $request->path = $path;

        $response = $router->dispatch($request);

        // 299 sentinel means the route resolved to our fake controller (i.e. it
        // is registered and NOT behind auth).
        $this->assertSame(299, $response->statusCode, "$method $path should be registered unauthenticated");
    }

    /**
     * The UNAUTHENTICATED GitHub login flow (authorize + callback) is registered
     * (S48). Same proof technique as the OIDC test above.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function githubLoginRoutes(): array
    {
        return [
            'authorize' => ['GET', '/auth/github/authorize'],
            'callback'  => ['GET', '/auth/github/callback'],
        ];
    }

    /**
     * @dataProvider githubLoginRoutes
     */
    public function test_github_login_routes_are_registered_unauthenticated(string $method, string $path): void
    {
        $fake = new class {
            public function authorize(Request $request, array $params): Response
            {
                return (new Response())->status(299)->json(['hit' => 'authorize']);
            }

            public function callback(Request $request, array $params): Response
            {
                return (new Response())->status(299)->json(['hit' => 'callback']);
            }
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturnCallback(
            static fn (string $id): object => $id === GithubCallbackController::class ? $fake : new \stdClass(),
        );

        $router = new Router($container);
        (new AuthProviderRouteRegistrar())->register($router);

        $request = new Request();
        $request->method = $method;
        $request->path = $path;

        $response = $router->dispatch($request);

        $this->assertSame(299, $response->statusCode, "$method $path should be registered unauthenticated");
    }
}
