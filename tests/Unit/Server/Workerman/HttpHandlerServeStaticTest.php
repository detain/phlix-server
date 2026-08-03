<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Workerman;

use Phlix\Auth\AuthManager;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\RequestAuthenticator;
use Phlix\Server\Workerman\HttpHandler;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Workerman\Protocols\Http\Request as WorkermanRequest;
use Workerman\Protocols\Http\Response as WorkermanResponse;

/**
 *
 * Pins the S4 immutable-cache behaviour of {@see HttpHandler::serveStatic()}: the
 * content-hashed Vite bundle under `/assets/app/**` gets a long-lived immutable
 * `Cache-Control`, while other static files (favicon, robots) do not.
 */
final class HttpHandlerServeStaticTest extends TestCase
{
    private string $publicRoot = '';

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/phlix-servestatic-' . bin2hex(random_bytes(6));
        mkdir($root . '/assets/app', 0o777, true);
        file_put_contents($root . '/assets/app/index-DaB12cd3.js', "console.log('hi');\n");
        file_put_contents($root . '/robots.txt', "User-agent: *\n");
        // Sibling of assets/app/ (under assets/ but NOT under assets/app/) — the
        // traversal-escape target for the regression test below.
        file_put_contents($root . '/assets/foo.js', "console.log('sibling');\n");
        // A sibling directory whose name STARTS WITH "app" but is not "app/" — boundary
        // check that the immutable gate requires the trailing separator (assets/app/),
        // so `assets/appendix/` must NOT be treated as inside `assets/app/`.
        mkdir($root . '/assets/appendix', 0o777, true);
        file_put_contents($root . '/assets/appendix/x-DeadBeef1.js', "console.log('appendix');\n");
        // realpath() canonicalises publicRoot the same way the handler does.
        $this->publicRoot = (string) realpath($root);
    }

    protected function tearDown(): void
    {
        if ($this->publicRoot === '' || !is_dir($this->publicRoot)) {
            return;
        }
        @unlink($this->publicRoot . '/assets/app/index-DaB12cd3.js');
        @unlink($this->publicRoot . '/robots.txt');
        @unlink($this->publicRoot . '/assets/foo.js');
        @unlink($this->publicRoot . '/assets/appendix/x-DeadBeef1.js');
        @rmdir($this->publicRoot . '/assets/appendix');
        @rmdir($this->publicRoot . '/assets/app');
        @rmdir($this->publicRoot . '/assets');
        @rmdir($this->publicRoot);
    }

    private function makeHandler(): HttpHandler
    {
        return new HttpHandler(
            $this->createMock(ContainerInterface::class),
            new RequestAuthenticator($this->createMock(AuthManager::class)),
            $this->publicRoot,
            $this->createMock(Application::class),
            null,
        );
    }

    private function invokeServeStatic(string $path): ?WorkermanResponse
    {
        $wr = new WorkermanRequest("GET {$path} HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $m = new \ReflectionMethod(HttpHandler::class, 'serveStatic');
        $m->setAccessible(true);
        $result = $m->invoke($this->makeHandler(), $wr);
        self::assertTrue($result === null || $result instanceof WorkermanResponse);

        return $result;
    }

    public function testHashedAssetGetsImmutableCacheControl(): void
    {
        $resp = $this->invokeServeStatic('/assets/app/index-DaB12cd3.js');

        self::assertInstanceOf(WorkermanResponse::class, $resp);
        $cacheControl = $resp->getHeader('Cache-Control');
        self::assertIsString($cacheControl);
        self::assertStringContainsString('immutable', $cacheControl);
        self::assertStringContainsString('max-age=31536000', $cacheControl);
    }

    public function testNonAssetFileHasNoImmutableCacheControl(): void
    {
        $resp = $this->invokeServeStatic('/robots.txt');

        self::assertInstanceOf(WorkermanResponse::class, $resp);
        $cacheControl = $resp->getHeader('Cache-Control');
        // Either absent entirely or, if present, not the immutable long-cache directive.
        if (is_string($cacheControl)) {
            self::assertStringNotContainsString('immutable', $cacheControl);
        } else {
            self::assertTrue($cacheControl === null || $cacheControl === []);
        }
    }

    /**
     * Boundary check: a sibling directory whose name merely *starts with* `app`
     * (here `assets/appendix/`) is NOT inside `assets/app/`, so its content-hashed
     * file must NOT receive the immutable directive. This pins the trailing-separator
     * boundary of the `assets/app/` gate (a naive `assets/app` prefix without the
     * separator would wrongly match `assets/appendix/`).
     */
    public function testSiblingDirStartingWithAppPrefixDoesNotGetImmutableHeader(): void
    {
        $resp = $this->invokeServeStatic('/assets/appendix/x-DeadBeef1.js');

        self::assertInstanceOf(WorkermanResponse::class, $resp);
        $cacheControl = $resp->getHeader('Cache-Control');
        if (is_string($cacheControl)) {
            self::assertStringNotContainsString(
                'immutable',
                $cacheControl,
                'assets/appendix/ is a sibling of assets/app/, not inside it.',
            );
        } else {
            self::assertTrue($cacheControl === null || $cacheControl === []);
        }
    }

    /**
     * Reviewer finding (S4 round 1): the immutable-cache decision must be gated on
     * the RESOLVED/jailed real path, not the raw request path string. A request
     * whose raw path literally *starts with* `/assets/app/` but escapes it via `..`
     * resolves to a file OUTSIDE `/assets/app/` (here `publicRoot/assets/foo.js`,
     * a sibling of the `app/` directory) and must NOT be tagged immutable, even
     * though it is still served (the general publicRoot jail earlier in the method
     * still allows it, since the resolved file remains under publicRoot).
     */
    public function testTraversalPathStartingWithAssetsAppPrefixDoesNotGetImmutableHeader(): void
    {
        $resp = $this->invokeServeStatic('/assets/app/../foo.js');

        self::assertInstanceOf(
            WorkermanResponse::class,
            $resp,
            'The file resolves inside publicRoot (assets/foo.js), so it is still served.',
        );
        $cacheControl = $resp->getHeader('Cache-Control');
        if (is_string($cacheControl)) {
            self::assertStringNotContainsString(
                'immutable',
                $cacheControl,
                'A path resolving outside /assets/app/ must never be tagged immutable, '
                    . 'even if the raw request path string starts with /assets/app/.',
            );
        } else {
            self::assertTrue($cacheControl === null || $cacheControl === []);
        }
    }
}
