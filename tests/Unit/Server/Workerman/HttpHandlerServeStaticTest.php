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
 * Since minors M-SERVER-49 the tag additionally requires the Vite hash naming
 * law — unhashed residents of the same tree (index.html, .vite/manifest.json)
 * fall closed to `no-cache` instead of being pinned for a year.
 */
final class HttpHandlerServeStaticTest extends TestCase
{
    private string $publicRoot = '';

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/phlix-servestatic-' . bin2hex(random_bytes(6));
        mkdir($root . '/assets/app', 0o777, true);
        file_put_contents($root . '/assets/app/index-DaB12cd3.js', "console.log('hi');\n");
        // Unhashed residents of the same tree (minors M-SERVER-49): the SPA shell
        // reachable via the side-door URL, the Vite manifest, and a publicDir-style
        // copy whose suffix is too short to be a content hash.
        file_put_contents($root . '/assets/app/index.html', "<!doctype html><html><body></body></html>\n");
        mkdir($root . '/assets/app/.vite', 0o777, true);
        file_put_contents($root . '/assets/app/.vite/manifest.json', "{}\n");
        file_put_contents($root . '/assets/app/legacy-Ab12.js', "console.log('legacy');\n");
        // Pattern edges: a hashed font (immutable by law, extension-agnostic) and a
        // doubled extension whose final segment is NOT hash-shaped (fails closed).
        file_put_contents($root . '/assets/app/fa-Db12cd34.woff2', "f\n");
        file_put_contents($root . '/assets/app/i18n-DaB12cd3.index.js', "i\n");
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
        @unlink($this->publicRoot . '/assets/app/index.html');
        @unlink($this->publicRoot . '/assets/app/.vite/manifest.json');
        @rmdir($this->publicRoot . '/assets/app/.vite');
        @unlink($this->publicRoot . '/assets/app/legacy-Ab12.js');
        @unlink($this->publicRoot . '/assets/app/fa-Db12cd34.woff2');
        @unlink($this->publicRoot . '/assets/app/i18n-DaB12cd3.index.js');
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

    /**
     * Pattern edge (minors M-SERVER-49): the hash law is extension-agnostic — a
     * hashed font inside the tree keeps the immutable tag exactly like a chunk.
     */
    public function testHashedFontInsideTheTreeKeepsImmutableHeader(): void
    {
        $resp = $this->invokeServeStatic('/assets/app/fa-Db12cd34.woff2');

        self::assertInstanceOf(WorkermanResponse::class, $resp);
        $cacheControl = $resp->getHeader('Cache-Control');
        self::assertIsString($cacheControl);
        self::assertStringContainsString('immutable', $cacheControl);
    }

    /**
     * The side-door shell (minors M-SERVER-49): GET /assets/app/index.html is
     * reachable and carries the RAW pre-injection document — a year of
     * `immutable` there is a stale-bundle boot trap on the next upgrade. The
     * gate must fall closed to revalidation for anything not hash-named.
     */
    public function testUnhashedShellInsideTheTreeNeverGetsImmutableHeader(): void
    {
        $resp = $this->invokeServeStatic('/assets/app/index.html');

        self::assertInstanceOf(WorkermanResponse::class, $resp);
        $cacheControl = $resp->getHeader('Cache-Control');
        self::assertIsString($cacheControl);
        self::assertStringNotContainsString('immutable', $cacheControl);
        self::assertStringContainsString('no-cache', $cacheControl);
    }

    /** The Vite manifest is likewise unhashed — same revalidation law (M-SERVER-49). */
    public function testViteManifestInsideTheTreeNeverGetsImmutableHeader(): void
    {
        $resp = $this->invokeServeStatic('/assets/app/.vite/manifest.json');

        self::assertInstanceOf(WorkermanResponse::class, $resp);
        $cacheControl = $resp->getHeader('Cache-Control');
        self::assertIsString($cacheControl);
        self::assertStringNotContainsString('immutable', $cacheControl);
        self::assertStringContainsString('no-cache', $cacheControl);
    }

    /**
     * Fail-closed edges (minors M-SERVER-49): a suffix too short to be a Vite
     * content hash, and a doubled extension whose final segment is not
     * hash-shaped, both revalidate — never the year tag on changing bytes.
     */
    public function testNonHashShapedNamesInsideTheTreeFailClosedToRevalidation(): void
    {
        foreach (['/assets/app/legacy-Ab12.js', '/assets/app/i18n-DaB12cd3.index.js'] as $path) {
            $resp = $this->invokeServeStatic($path);

            self::assertInstanceOf(WorkermanResponse::class, $resp, $path);
            $cacheControl = $resp->getHeader('Cache-Control');
            self::assertIsString($cacheControl, $path);
            self::assertStringNotContainsString('immutable', $cacheControl, $path);
            self::assertStringContainsString('no-cache', $cacheControl, $path);
        }
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

    /**
     * R20-03 — serveStatic() must deny source maps and Smarty template sources
     * (fall-through to the dispatcher = null), not just php. .js.map under the
     * Vite bundle carries the SPA's full TypeScript via sourcesContent and
     * public/templates/emails/*.tpl is the email template source — neither is
     * client-facing. The pre-existing php deny must keep holding through the
     * extension-list refactor.
     */
    public function testSourceMapsTemplateAndPhpSourcesAreNeverServed(): void
    {
        file_put_contents($this->publicRoot . '/assets/app/index-DaB12cd3.js.map', '{"sourcesContent":["leaked"]}');
        mkdir($this->publicRoot . '/templates/emails', 0o777, true);
        file_put_contents($this->publicRoot . '/templates/emails/newsletter.tpl', 'Dear {\$name},');
        file_put_contents($this->publicRoot . '/config-backup.php', "<?php echo 'secret';");

        try {
            self::assertNull(
                $this->invokeServeStatic('/assets/app/index-DaB12cd3.js.map'),
                'R20-03: source maps must never be served over HTTP',
            );
            self::assertNull(
                $this->invokeServeStatic('/templates/emails/newsletter.tpl'),
                'R20-03: Smarty template sources must never be served over HTTP',
            );
            self::assertNull(
                $this->invokeServeStatic('/config-backup.php'),
                'pre-existing law: php source must never be served raw',
            );
            // Case-insensitivity of the deny (the guard lowercases the extension).
            file_put_contents($this->publicRoot . '/assets/app/upper.MAP', '{"sourcesContent":["leaked"]}');
            self::assertNull(
                $this->invokeServeStatic('/assets/app/upper.MAP'),
                'R20-03: the deny is case-insensitive — .MAP must not slip through',
            );
        } finally {
            @unlink($this->publicRoot . '/assets/app/upper.MAP');
            @unlink($this->publicRoot . '/assets/app/index-DaB12cd3.js.map');
            @unlink($this->publicRoot . '/templates/emails/newsletter.tpl');
            @unlink($this->publicRoot . '/config-backup.php');
            @rmdir($this->publicRoot . '/templates/emails');
            @rmdir($this->publicRoot . '/templates');
        }
    }

    /**
     * R20-03 companion — the deny is extension-based on the RESOLVED path, so
     * a legitimately-named neighbour (js, not map) keeps serving.
     */
    public function testJsNeighbourOfDeniedMapStillServes(): void
    {
        file_put_contents($this->publicRoot . '/assets/app/index-DaB12cd3.js.map', '{}');
        try {
            self::assertNull($this->invokeServeStatic('/assets/app/index-DaB12cd3.js.map'));
            self::assertInstanceOf(
                WorkermanResponse::class,
                $this->invokeServeStatic('/assets/app/index-DaB12cd3.js'),
                'the .js sibling must remain served — the deny is exact-extension, not substring',
            );
        } finally {
            @unlink($this->publicRoot . '/assets/app/index-DaB12cd3.js.map');
        }
    }
}
