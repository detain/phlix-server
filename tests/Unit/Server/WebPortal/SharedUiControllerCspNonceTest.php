<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\WebPortal;

use Phlix\Server\Http\Middleware\SecurityHeaders;
use Phlix\Server\Http\Request;
use Phlix\Server\WebPortal\Controllers\SharedUiController;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the SPA shell serves its inline `window.__PHLIX__` bootstrap block
 * with a per-request CSP nonce that matches the nonce on the `<script>` tag,
 * so the inline script executes under the strict (`script-src 'self'`) policy
 * without weakening it to `'unsafe-inline'`.
 */
final class SharedUiControllerCspNonceTest extends TestCase
{
    private string $publicRoot = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->publicRoot = sys_get_temp_dir() . '/phlix-shell-' . bin2hex(random_bytes(6));
        $appDir = $this->publicRoot . '/assets/app';
        mkdir($appDir . '/.vite', 0o777, true);

        file_put_contents(
            $appDir . '/.vite/manifest.json',
            json_encode([
                'index.html' => ['file' => 'assets/index-abc123.js', 'isEntry' => true],
            ], JSON_THROW_ON_ERROR),
        );

        file_put_contents(
            $appDir . '/index.html',
            '<!DOCTYPE html><html><head><title>Phlix</title></head><body></body></html>',
        );
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->publicRoot);
        parent::tearDown();
    }

    public function testShellNonceMatchesBetweenScriptTagAndCspHeader(): void
    {
        $controller = new SharedUiController($this->publicRoot);
        $response = $controller->shell(new Request());

        self::assertSame(200, $response->statusCode);

        $csp = $response->headers['Content-Security-Policy'] ?? '';
        self::assertNotSame('', $csp, 'SPA shell must set its own CSP header.');

        // Nonce on the inline bootstrap <script>.
        self::assertSame(
            1,
            preg_match('/<script nonce="([^"]+)">window\.__PHLIX__ = /', $response->body, $tagMatch),
            'Inline bootstrap script must carry a nonce attribute.',
        );
        $tagNonce = $tagMatch[1] ?? '';

        // Nonce in the CSP script-src directive.
        self::assertSame(
            1,
            preg_match("/script-src 'self' 'nonce-([^']+)'/", $csp, $cspMatch),
            "CSP script-src must carry a matching 'nonce-...' source.",
        );
        $cspNonce = $cspMatch[1] ?? '';

        self::assertSame($tagNonce, $cspNonce, 'Script-tag and CSP nonce must be identical.');

        // The nonce must be a fresh base64-encoded 16-byte random value.
        self::assertNotFalse(base64_decode($tagNonce, true));
        self::assertSame(16, strlen((string) base64_decode($tagNonce, true)));

        // Strict policy is preserved: never 'unsafe-inline' on script-src.
        self::assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $csp);
        self::assertStringContainsString("frame-ancestors 'self'", $csp);
        self::assertStringContainsString('media-src \'self\' blob:', $csp);
        self::assertStringContainsString('worker-src \'self\' blob:', $csp);

        // The SPA-shell CSP is built from SecurityHeaders::contentSecurityPolicy(),
        // so the TMDB image allowlist must reach the consumer path too (S01).
        self::assertStringContainsString('https://image.tmdb.org', $csp);
        self::assertStringContainsString('https://tmdb.org', $csp);
    }

    public function testEachResponseGeneratesAFreshNonce(): void
    {
        $controller = new SharedUiController($this->publicRoot);

        $first = $controller->shell(new Request());
        $second = $controller->shell(new Request());

        preg_match('/<script nonce="([^"]+)">/', $first->body, $a);
        preg_match('/<script nonce="([^"]+)">/', $second->body, $b);

        self::assertNotSame($a[1] ?? '', $b[1] ?? '', 'Nonce must be regenerated per request.');
    }

    public function testDefaultSecurityHeaderCspHasNoNonceAndStaysStrict(): void
    {
        $csp = SecurityHeaders::contentSecurityPolicy();

        self::assertStringContainsString("script-src 'self';", $csp);
        self::assertStringNotContainsString('nonce-', $csp);
        self::assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $csp);
    }

    /**
     * S01 (updates.md #1): the CSP `img-src` directive must explicitly allowlist the
     * TMDB image CDN hosts so remotely-served poster/backdrop/cast artwork renders,
     * WITHOUT opening the policy up to a scheme/host wildcard.
     */
    public function testCspImgSrcAllowlistsTmdbHostsExplicitlyWithoutWildcard(): void
    {
        $csp = SecurityHeaders::contentSecurityPolicy();

        // Isolate the img-src directive's source list (between "img-src " and ";").
        self::assertSame(
            1,
            preg_match('/img-src ([^;]+);/', $csp, $m),
            'CSP must contain a terminated img-src directive.',
        );
        $imgSrc = trim($m[1] ?? '');
        $sources = preg_split('/\s+/', $imgSrc) ?: [];

        // Both TMDB hosts are named explicitly.
        self::assertContains('https://image.tmdb.org', $sources);
        self::assertContains('https://tmdb.org', $sources);

        // No wildcard leaked in: neither a bare host/scheme wildcard nor "https:".
        self::assertNotContains('*', $sources, 'img-src must not use a bare wildcard.');
        self::assertNotContains('https:', $sources, 'img-src must not open the whole https: scheme.');
        self::assertNotContains('https://*', $sources, 'img-src must not wildcard the https host.');
        foreach ($sources as $source) {
            self::assertStringNotContainsString('*', $source, 'No img-src source may contain a wildcard.');
        }

        // The directive is exactly the intended allowlist — locks the surface down.
        self::assertSame(
            "'self' data: blob: https://image.tmdb.org https://tmdb.org",
            $imgSrc,
        );
    }

    private function rmrf(string $path): void
    {
        if ($path === '' || ! is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }
}
