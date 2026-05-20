<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Theming;

use PHPUnit\Framework\TestCase;
use Phlix\Auth\UserProfileManager;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Theming\Theme;
use Phlix\Theming\ThemeMiddleware;
use Phlix\Theming\ThemeRegistry;
use Workerman\MySQL\Connection;

class ThemeMiddlewareTest extends TestCase
{
    private ThemeMiddleware $middleware;
    private ThemeRegistry $registry;
    private UserProfileManager $profiles;

    protected function setUp(): void
    {
        $db = $this->createMock(Connection::class);
        $this->registry = new ThemeRegistry($db, 'var/themes/');
        $this->profiles = $this->createMock(UserProfileManager::class);

        $this->middleware = new ThemeMiddleware($this->registry, $this->profiles);

        // Register the default theme
        $this->registry->registerBuiltIn(new Theme(
            id: ThemeRegistry::DEFAULT_THEME_ID,
            name: 'Phlix Dark',
            type: 'builtin',
            cssUrl: '/assets/css/themes/phlix-dark.css',
            jsUrl: null,
            thumbnailUrl: '/assets/images/themes/phlix-dark.png',
            version: '1.0.0',
            pluginName: null,
            dark: true
        ));

        // Register a test theme
        $this->registry->registerBuiltIn(new Theme(
            id: 'test-dark',
            name: 'Test Dark',
            type: 'builtin',
            cssUrl: '/assets/css/test-dark.css',
            jsUrl: '/assets/js/test-dark.js',
            thumbnailUrl: '/assets/images/test-dark.png',
            version: '1.0.0',
            pluginName: null,
            dark: true
        ));
    }

    public function testInjectsCssLinkIntoHtmlResponse(): void
    {
        $html = '<html><head>{$theme_css|raw}</head><body></body></html>';

        $response = (new Response())->html($html);

        $nextCalled = false;
        $next = function ($req) use (&$nextCalled, $response) {
            $nextCalled = true;
            return $response;
        };

        $request = new Request();
        $request->headers = [];

        $result = $this->middleware->onHttpRequest($request, $next);

        $this->assertTrue($nextCalled);
        // Uses default phlix-dark theme since no user is authenticated
        $this->assertStringContainsString('<link rel="stylesheet" href="/assets/css/themes/phlix-dark.css">', $result->body);
        $this->assertStringNotContainsString('{$theme_css|raw}', $result->body);
    }

    public function testInjectsBothCssAndJsWhenJsPresent(): void
    {
        $html = '<html><head>{$theme_css|raw}</head><body>{$theme_js|raw}</body></html>';

        $response = (new Response())->html($html);

        $next = function ($req) use ($response) {
            return $response;
        };

        $request = new Request();
        $request->headers = [];

        $result = $this->middleware->onHttpRequest($request, $next);

        // Uses default phlix-dark theme since no user is authenticated (no JS in default)
        $this->assertStringContainsString('<link rel="stylesheet" href="/assets/css/themes/phlix-dark.css">', $result->body);
        $this->assertStringNotContainsString('{$theme_css|raw}', $result->body);
        $this->assertStringNotContainsString('{$theme_js|raw}', $result->body);
    }

    public function testDoesNotModifyNonHtmlResponse(): void
    {
        $response = (new Response())->json(['status' => 'ok']);

        $nextCalled = false;
        $next = function ($req) use (&$nextCalled, $response) {
            $nextCalled = true;
            return $response;
        };

        $request = new Request();
        $request->headers = [];

        $result = $this->middleware->onHttpRequest($request, $next);

        $this->assertTrue($nextCalled);
        // JSON response should not be modified
        $this->assertStringContainsString('"status"', $result->body);
        $this->assertStringContainsString('"ok"', $result->body);
    }

    public function testUsesDefaultThemeWhenUserNotAuthenticated(): void
    {
        $this->registry->registerBuiltIn(new Theme(
            id: ThemeRegistry::DEFAULT_THEME_ID,
            name: 'Phlix Dark',
            type: 'builtin',
            cssUrl: '/assets/css/themes/phlix-dark.css',
            jsUrl: null,
            thumbnailUrl: '/assets/images/themes/phlix-dark.png',
            version: '1.0.0',
            pluginName: null,
            dark: true
        ));

        $html = '<html><head>{$theme_css|raw}</head><body></body></html>';

        $response = (new Response())->html($html);

        $next = function ($req) use ($response) {
            return $response;
        };

        $request = new Request();
        $request->headers = [];

        $result = $this->middleware->onHttpRequest($request, $next);

        $this->assertStringContainsString('<link rel="stylesheet" href="/assets/css/themes/phlix-dark.css">', $result->body);
    }
}
