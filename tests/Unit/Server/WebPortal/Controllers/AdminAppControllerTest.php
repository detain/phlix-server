<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\WebPortal\Controllers;

use Phlix\Server\Http\Request;
use Phlix\Server\WebPortal\Controllers\AdminAppController;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see AdminAppController} (Step 0.4).
 *
 * Covers the SPA shell serving (present + missing bundle) and the
 * gate→response mapping that both entry points share: a `null` gate is
 * "allowed" (caller renders the shell); a 401/403 gate maps to a 302
 * redirect to `/login`.
 *
 * @covers \Phlix\Server\WebPortal\Controllers\AdminAppController
 */
final class AdminAppControllerTest extends TestCase
{
    private string $publicRoot = '';

    protected function tearDown(): void
    {
        // Clean up any temp public root the test created.
        if ($this->publicRoot !== '' && is_dir($this->publicRoot)) {
            $this->rrmdir($this->publicRoot);
        }
        $this->publicRoot = '';
        parent::tearDown();
    }

    public function testShellReturnsTheBuiltBundleHtmlForAnAdmin(): void
    {
        $root = $this->makePublicRootWithShell(
            '<!doctype html><html><body><div id="root"></div>'
            . '<script type="module" src="/assets/admin/assets/index-abc.js"></script>'
            . '</body></html>',
        );
        $controller = new AdminAppController($root);

        $response = $controller->shell($this->makeRequest(), []);

        $this->assertSame(200, $response->statusCode);
        $this->assertStringContainsString('<div id="root"></div>', $response->body);
        $this->assertStringContainsString('/assets/admin/assets/index-abc.js', $response->body);
        $this->assertSame('text/html; charset=utf-8', $response->headers['Content-Type']);
    }

    public function testShellReturns503WhenTheBundleIsNotBuilt(): void
    {
        // A real, empty public root with no assets/admin/index.html.
        $root = sys_get_temp_dir() . '/phlix_admin_no_bundle_' . uniqid('', true);
        mkdir($root, 0o775, true);
        $this->publicRoot = $root;

        $controller = new AdminAppController($root);
        $response = $controller->shell($this->makeRequest(), []);

        $this->assertSame(503, $response->statusCode);
        $this->assertStringContainsString('Admin UI not built', $response->body);
        $this->assertStringContainsString('npm run build', $response->body);
    }

    public function testGateRedirectReturnsNullWhenAllowed(): void
    {
        $controller = new AdminAppController(sys_get_temp_dir());
        $this->assertNull($controller->gateRedirect(null));
    }

    public function testGateRedirectRedirectsUnauthenticatedToLogin(): void
    {
        $controller = new AdminAppController(sys_get_temp_dir());
        $response = $controller->gateRedirect(401);

        $this->assertNotNull($response);
        $this->assertSame(302, $response->statusCode);
        $this->assertSame('/login', $response->headers['Location']);
    }

    public function testGateRedirectRedirectsNonAdminToLogin(): void
    {
        $controller = new AdminAppController(sys_get_temp_dir());
        $response = $controller->gateRedirect(403);

        $this->assertNotNull($response);
        $this->assertSame(302, $response->statusCode);
        $this->assertSame('/login', $response->headers['Location']);
    }

    /**
     * Build a temp public root containing assets/admin/index.html with the
     * given HTML. Records the root for teardown.
     */
    private function makePublicRootWithShell(string $html): string
    {
        $root = sys_get_temp_dir() . '/phlix_admin_root_' . uniqid('', true);
        $adminDir = $root . '/assets/admin';
        mkdir($adminDir, 0o775, true);
        file_put_contents($adminDir . '/index.html', $html);
        $this->publicRoot = $root;
        return $root;
    }

    private function makeRequest(): Request
    {
        $request           = new Request();
        $request->method   = 'GET';
        $request->path     = '/admin';
        $request->headers  = [];
        $request->query    = [];
        $request->body     = [];
        $request->files    = [];
        $request->remoteIp = '127.0.0.1';
        $request->remotePort = 0;
        $request->protocol = 'HTTP/1.1';
        $request->queryString = '';
        $request->userId   = 'admin-1';
        return $request;
    }

    /** Recursively remove a directory tree created by the test. */
    private function rrmdir(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
