<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\WebPortal;

use Phlix\Server\Http\Request;
use Phlix\Server\WebPortal\Controllers\SharedUiController;
use PHPUnit\Framework\TestCase;

/**
 * LW-077-a: the server-side `wsUrl`/`wsPath` bootstrap seam.
 *
 * Mirrors the hub's shipped design (`phlix-hub` SharedUiController
 * `configuredSocketBaseUrl()` / `configuredSocketBasePath()`), with the
 * server's own env-var convention: `PHLIX_WS_URL` / `PHLIX_WS_PATH`.
 *
 * Pinned behaviors:
 *  1. the grammar arms (accepted origins/paths verbatim; everything else
 *     fail-soft to null — unset, blank, whitespace, non-ws scheme, embedded
 *     path/query/userinfo, bare `//host`, out-of-range ports);
 *  2. the keys are ALWAYS present in `window.__PHLIX__` (null when unset),
 *     so the ui lane's socketHint consumer sees a stable shape;
 *  3. a configured cross-host origin is added to the shell CSP connect-src
 *     (otherwise the strict portless same-host allowance would block it);
 *  4. a configured origin on the request's own host is NOT added — the
 *     portless `ws://host` source already covers every port on that host.
 *
 * Nothing here touches the committed bundle: fixtures build a throwaway
 * `public/` tree exactly like SharedUiControllerCspNonceTest.
 */
final class SharedUiSocketSeamTest extends TestCase
{
    private string $publicRoot = '';

    /** @var list<string> env vars this test mutates */
    private const MUTATED_ENV = ['PHLIX_WS_URL', 'PHLIX_WS_PATH'];

    /** @var array<string, string|false> pre-test env snapshot */
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::MUTATED_ENV as $name) {
            $this->envBackup[$name] = getenv($name);
            putenv($name);
        }
        unset($_SERVER['HTTP_HOST']);

        $this->publicRoot = sys_get_temp_dir() . '/phlix-socketseam-' . bin2hex(random_bytes(6));
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
        foreach (self::MUTATED_ENV as $name) {
            $previous = $this->envBackup[$name];
            if ($previous === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $previous);
            }
        }
        unset($_SERVER['HTTP_HOST']);

        $this->rmrf($this->publicRoot);
        parent::tearDown();
    }

    /** @return array<string, array{0: string, 1: ?string}> */
    public static function urlGrammarProvider(): array
    {
        return [
            'ws literal host port' => ['ws://127.0.0.1:8804', 'ws://127.0.0.1:8804'],
            'wss bare host' => ['wss://hub.example.com', 'wss://hub.example.com'],
            'wss host port' => ['wss://hub.example.com:8804', 'wss://hub.example.com:8804'],
            'surrounding whitespace trimmed' => ['  wss://pin.example.com  ', 'wss://pin.example.com'],
            'port one is in range' => ['ws://h.example.com:1', 'ws://h.example.com:1'],
            'port 65535 is in range' => ['wss://h.example.com:65535', 'wss://h.example.com:65535'],
            'ipv6 loopback with port' => ['ws://[::1]:8804', 'ws://[::1]:8804'],
            'ipv6 bare wss' => ['wss://[2001:db8::1]', 'wss://[2001:db8::1]'],
            'ipv6 malformed rejected' => ['ws://[zzz]:8804', null],
            'ipv6 unbracketed rejected' => ['ws://::1:8804', null],
            'ipv6 empty brackets rejected' => ['ws://[]', null],
            'http scheme rejected' => ['http://hub.example.com', null],
            'https scheme rejected' => ['https://hub.example.com', null],
            'embedded path rejected' => ['ws://hub.example.com/syncplay', null],
            'trailing slash rejected' => ['wss://hub.example.com/', null],
            'query rejected' => ['wss://hub.example.com?x=1', null],
            'userinfo rejected' => ['ws://user:pw@hub.example.com', null],
            'bare double-slash rejected' => ['//hub.example.com', null],
            'out-of-range port rejected' => ['ws://h.example.com:99999', null],
            'port zero rejected' => ['ws://h.example.com:0', null],
            'six-digit port rejected' => ['ws://h.example.com:65536', null],
            'non-numeric junk rejected' => ['not a url', null],
            'empty value null' => ['', null],
            'whitespace-only value null' => ['   ', null],
        ];
    }

    /**
     * @dataProvider urlGrammarProvider
     */
    public function testConfiguredSocketBaseUrlGrammarArms(string $raw, ?string $expected): void
    {
        putenv('PHLIX_WS_URL=' . $raw);

        self::assertSame($expected, SharedUiController::configuredSocketBaseUrl());
    }

    public function testConfiguredSocketBaseUrlUnsetIsNull(): void
    {
        self::assertNull(SharedUiController::configuredSocketBaseUrl());
    }

    /** @return array<string, array{0: string, 1: ?string}> */
    public static function pathGrammarProvider(): array
    {
        return [
            'single segment' => ['/syncplay', '/syncplay'],
            'root' => ['/', '/'],
            'multi segment path-safe chars' => ['/a/b.c_d-e/f~g', '/a/b.c_d-e/f~g'],
            'missing leading slash rejected' => ['syncplay', null],
            'query rejected' => ['/x?y=1', null],
            'fragment rejected' => ['/x#frag', null],
            'space rejected' => ['/x y', null],
            'wildcard rejected' => ['/x*', null],
            'empty value null' => ['', null],
            'whitespace-only value null' => ['   ', null],
        ];
    }

    /**
     * @dataProvider pathGrammarProvider
     */
    public function testConfiguredSocketBasePathGrammarArms(string $raw, ?string $expected): void
    {
        putenv('PHLIX_WS_PATH=' . $raw);

        self::assertSame($expected, SharedUiController::configuredSocketBasePath());
    }

    public function testConfiguredSocketBasePathUnsetIsNull(): void
    {
        self::assertNull(SharedUiController::configuredSocketBasePath());
    }

    public function testShellAlwaysCarriesTheSeamKeysNullWhenUnset(): void
    {
        $response = (new SharedUiController($this->publicRoot))->shell(new Request());

        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('"wsUrl":null', $response->body);
        self::assertStringContainsString('"wsPath":null', $response->body);
    }

    public function testShellInjectsConfiguredSocketValuesVerbatim(): void
    {
        putenv('PHLIX_WS_URL=wss://ws.example.com:8804');
        putenv('PHLIX_WS_PATH=/syncplay');

        $response = (new SharedUiController($this->publicRoot))->shell(new Request());

        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('"wsUrl":"wss://ws.example.com:8804"', $response->body);
        self::assertStringContainsString('"wsPath":"/syncplay"', $response->body);
    }

    public function testCrossHostConfiguredOriginIsAddedToCspConnectSrc(): void
    {
        $_SERVER['HTTP_HOST'] = 'phlix.example.com';
        putenv('PHLIX_WS_URL=wss://ws.example.com:8804');

        $response = (new SharedUiController($this->publicRoot))->shell(new Request());

        $csp = $response->headers['Content-Security-Policy'] ?? '';
        self::assertSame(
            1,
            preg_match('/connect-src ([^;]+);/', $csp, $m),
            'CSP must contain a terminated connect-src directive.',
        );
        $sources = preg_split('/\s+/', trim($m[1] ?? '')) ?: [];

        self::assertContains("'self'", $sources);
        self::assertContains('wss://ws.example.com:8804', $sources, 'pinned cross-host origin must be allowed');
        self::assertContains('ws://phlix.example.com', $sources, 'legacy same-host allowance stays');
        self::assertContains('wss://phlix.example.com', $sources);
    }

    public function testIpv6ConfiguredOriginIsAddedToCspConnectSrc(): void
    {
        $_SERVER['HTTP_HOST'] = 'phlix.example.com';
        putenv('PHLIX_WS_URL=ws://[2001:db8::1]:8804');

        $response = (new SharedUiController($this->publicRoot))->shell(new Request());

        $csp = $response->headers['Content-Security-Policy'] ?? '';
        self::assertSame(1, preg_match('/connect-src ([^;]+);/', $csp, $m));
        $sources = preg_split('/\s+/', trim($m[1] ?? '')) ?: [];
        self::assertContains('ws://[2001:db8::1]:8804', $sources, 'CSP parity with the controller grammar');
    }

    public function testSameHostConfiguredOriginIsNotDuplicatedInTheCsp(): void
    {
        // The portless same-host source already allows EVERY port on this
        // host — an origin pinned to the request's own host adds nothing and
        // must not be emitted as a second source.
        $_SERVER['HTTP_HOST'] = 'ws.example.com:443';
        putenv('PHLIX_WS_URL=wss://ws.example.com:8804');

        $response = (new SharedUiController($this->publicRoot))->shell(new Request());

        $csp = $response->headers['Content-Security-Policy'] ?? '';
        self::assertStringContainsString('connect-src \'self\' ws://ws.example.com wss://ws.example.com;', $csp);
        self::assertStringNotContainsString('wss://ws.example.com:8804', $csp);
    }

    public function testMalformedConfiguredEnvFailsSoftShellStillServes(): void
    {
        putenv('PHLIX_WS_URL=http://oops.example.com/path?x=1');
        putenv('PHLIX_WS_PATH=not-a-path');

        $response = (new SharedUiController($this->publicRoot))->shell(new Request());

        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('"wsUrl":null', $response->body);
        self::assertStringContainsString('"wsPath":null', $response->body);
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
