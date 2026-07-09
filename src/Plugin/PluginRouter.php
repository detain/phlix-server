<?php

/**
 * Phlix media server component: Plugin.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugin;

use Phlix\Server\Http\Response;
use RuntimeException;

/**
 * Registers and dispatches plugin-scoped HTTP endpoints.
 *
 * Routes are registered at `/api/v1/plugins/{pluginId}/...` and are
 * matched against incoming requests by the dispatch method.
 *
 * ## Route Registration
 * ```
 * $router->registerPluginRoute('my-plugin', 'GET', '/status', $handler);
 * ```
 *
 * ## Route Matching
 * The dispatch method extracts the pluginId from the path and matches
 * against registered routes. Parameters are captured via curly-brace
 * placeholders: `/items/{id}`.
 *
 * ## Fail Fast
 * - Throws RuntimeException when a duplicate route is registered
 * - Returns null when no route matches (caller should return 404)
 *
 * @package Phlix\Plugin
 * @since 0.15.0
 */
final class PluginRouter
{
    /**
     * Registered routes indexed by pluginId.
     *
     * @var array<string, array<string, array{
     *     method: string,
     *     path: string,
     *     handler: callable
     * }>>
     */
    private array $routes = [];

    /**
     * Path pattern compiled to regex for each route path.
     *
     * @var array<string, string>
     */
    private array $compiledPatterns = [];

    /**
     * Base path prefix for all plugin routes.
     */
    private const BASE_PATH = '/api/v1/plugins';

    /**
     * Register a route for a plugin.
     *
     * @param string   $pluginId Unique plugin identifier.
     * @param string   $method   HTTP method (GET, POST, PUT, DELETE, etc.).
     * @param string   $path     Route path with optional curly-brace placeholders.
     * @param callable $handler Handler receiving (array $params, string $pluginId): Response.
     *
     * @throws RuntimeException When a route is already registered for the same plugin+method+path.
     *
     * @since 0.15.0
     */
    public function registerPluginRoute(string $pluginId, string $method, string $path, callable $handler): void
    {
        $method = strtoupper($method);
        $this->assertValidMethod($method);
        $this->assertValidPath($path);

        $routeKey = $this->buildRouteKey($pluginId, $method, $path);

        if (isset($this->routes[$pluginId][$routeKey])) {
            throw new RuntimeException(sprintf(
                'Route already registered: %s %s for plugin %s',
                $method,
                $path,
                $pluginId
            ));
        }

        $this->routes[$pluginId][$routeKey] = [
            'method'  => $method,
            'path'    => $path,
            'handler' => $handler,
        ];

        unset($this->compiledPatterns[$pluginId]);
    }

    /**
     * Dispatch a plugin-scoped request.
     *
     * @param string $method HTTP method of the incoming request.
     * @param string $path   Request path (e.g. /api/v1/plugins/my-plugin/status).
     *
     * @return Response|null The response from the matched handler, or null if no route matched.
     *
     * @since 0.15.0
     */
    public function dispatchPluginRequest(string $method, string $path): ?Response
    {
        $method = strtoupper($method);

        $parsed = $this->parsePluginPath($path);
        if ($parsed === null) {
            return null;
        }

        [$pluginId, $relativePath] = $parsed;

        if (!isset($this->routes[$pluginId])) {
            return null;
        }

        foreach ($this->routes[$pluginId] as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            $params = $this->matchPath($route['path'], $relativePath);
            if ($params === null) {
                continue;
            }

            $handler = $route['handler'];
            return $handler($params, $pluginId);
        }

        return null;
    }

    /**
     * Get all registered route definitions for a plugin.
     *
     * @param string $pluginId Plugin identifier.
     *
     * @return list<array{method: string, path: string}> Route definitions.
     *
     * @since 0.15.0
     */
    public function getEndpoints(string $pluginId): array
    {
        if (!isset($this->routes[$pluginId])) {
            return [];
        }

        return array_values(array_map(
            fn(array $route): array => [
                'method' => $route['method'],
                'path'   => $route['path'],
            ],
            $this->routes[$pluginId]
        ));
    }

    /**
     * Get all registered route definitions for all plugins.
     *
     * @return array<string, list<array{method: string, path: string}>> Routes by pluginId.
     *
     * @since 0.15.0
     */
    public function getAllEndpoints(): array
    {
        $result = [];
        foreach ($this->routes as $pluginId => $routes) {
            $result[$pluginId] = $this->getEndpoints($pluginId);
        }
        return $result;
    }

    /**
     * Parse a plugin-scoped path into pluginId and relative path.
     *
     * @param string $path Full request path.
     *
     * @return array{string, string}|null [pluginId, relativePath] or null if path does not match base prefix.
     *
     * @since 0.15.0
     */
    private function parsePluginPath(string $path): ?array
    {
        $baseLen = strlen(self::BASE_PATH);
        if (!str_starts_with($path, self::BASE_PATH)) {
            return null;
        }

        $remainder = substr($path, $baseLen);
        if ($remainder === '' || !str_starts_with($remainder, '/')) {
            return null;
        }

        $remainder = substr($remainder, 1);
        $parts = explode('/', $remainder, 2);

        $pluginId = $parts[0];
        $relativePath = isset($parts[1]) ? '/' . $parts[1] : '/';

        if ($pluginId === '') {
            return null;
        }

        return [$pluginId, $relativePath];
    }

    /**
     * Build the internal route key.
     *
     * @param string $pluginId Plugin identifier.
     * @param string $method   HTTP method.
     * @param string $path     Route path.
     *
     * @return string Unique route key.
     *
     * @since 0.15.0
     */
    private function buildRouteKey(string $pluginId, string $method, string $path): string
    {
        return $method . ':' . $path;
    }

    /**
     * Compile a route path to a regex pattern.
     *
     * @param string $path Route path with optional {param} placeholders.
     *
     * @return string Regex pattern.
     *
     * @since 0.15.0
     */
    private function compilePathToRegex(string $path): string
    {
        $pattern = preg_replace('/\{([^}]+)\}/', '(?P<$1>[^/]+)', $path);
        if ($pattern === null) {
            return $path;
        }
        return '#^' . $pattern . '$#';
    }

    /**
     * Match a request path against a route path.
     *
     * @param string $routePath  Registered route path.
     * @param string $requestPath Request path to match.
     *
     * @return array<string, string>|null Captured parameters or null if no match.
     *
     * @since 0.15.0
     */
    private function matchPath(string $routePath, string $requestPath): ?array
    {
        if (!isset($this->compiledPatterns[$routePath])) {
            $this->compiledPatterns[$routePath] = $this->compilePathToRegex($routePath);
        }

        $pattern = $this->compiledPatterns[$routePath];
        if (preg_match($pattern, $requestPath, $matches) !== 1) {
            return null;
        }

        $params = [];
        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $params[$key] = $value;
            }
        }

        return $params;
    }

    /**
     * Assert that the HTTP method is valid.
     *
     * @param string $method HTTP method to validate.
     *
     * @throws RuntimeException When the method is invalid.
     *
     * @since 0.15.0
     */
    private function assertValidMethod(string $method): void
    {
        static $validMethods = [
            'GET'     => true,
            'POST'    => true,
            'PUT'     => true,
            'DELETE'  => true,
            'PATCH'   => true,
            'HEAD'    => true,
            'OPTIONS' => true,
        ];

        if (isset($validMethods[$method])) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Invalid HTTP method "%s"',
            $method
        ));
    }

    /**
     * Assert that the path is valid for registration.
     *
     * @param string $path Path to validate.
     *
     * @throws RuntimeException When the path is invalid.
     *
     * @since 0.15.0
     */
    private function assertValidPath(string $path): void
    {
        if ($path === '') {
            throw new RuntimeException('Route path cannot be empty');
        }

        if (!str_starts_with($path, '/')) {
            throw new RuntimeException(sprintf(
                'Route path must start with "/", got "%s"',
                $path
            ));
        }

        if (str_contains($path, '//')) {
            throw new RuntimeException(sprintf(
                'Route path cannot contain double slashes, got "%s"',
                $path
            ));
        }
    }
}
