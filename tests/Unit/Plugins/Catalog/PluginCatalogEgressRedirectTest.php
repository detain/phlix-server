<?php

/**
 * Phlix media server test: Plugins.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Catalog;

use Phlix\Plugins\Catalog\PluginCatalogService;
use Phlix\Plugins\Util\RecursiveDelete;
use Phlix\Tests\Support\Coroutine\RunsInCoroutine;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Workerman\Http\Client;

/**
 * EGRESS_TRANSPORT_LAW alignment of the catalog live fetchers (derived from
 * FIX-DERIVED-SRV.md SRV-6; estate precedent 4187ff14 / R20-01 / R20-02):
 * every egress path in the estate REFUSES HTTP redirects — a response that
 * answers 3xx must terminate the fetch with a refusal that names no
 * destination URL, so a catalog host can never move the egress path (or
 * smuggle a `file://`-downgrade hop) behind the resolver's vetted URL.
 *
 * Before this cure the three `PluginCatalogService::defaultFetcher()`
 * transports followed redirects (curl FOLLOWLOCATION=true MAXREDIRS=3; stream
 * follow_location=1 max_redirects=3; the workerman async client carried NO
 * allow_redirects option at all — the vendor default follows 5), making the
 * catalog path the last un-re aligned egress family of the subsystem.
 *
 * The transport legs cannot fake the transport — the class under test IS the
 * real wrapper's redirect behaviour — so they dial a live loopback `php -S`
 * fixture (venue idiom copied from NotificationPluginEgressRedirectTest /
 * HttpInstallerRedirectE2eTest, incl. the S315 `exec` law so proc_terminate()
 * signals the server, not the shell). The fixture logs every path it SERVES;
 * the conviction is that '/secret' never appears there.
 *
 * Red at the parent commit, per leg:
 *  - curlFetch / streamFetch / defaultFetcher redirect legs: the parent
 *    followed the hop and RETURNED the /secret marker body (measured) — no
 *    throw, and the fixture served both paths;
 *  - the async option pin: the parent's request options carried no
 *    'allow_redirects' key at all (the R20-01 hole);
 *  - the async 302-refusal leg: the parent's success arm returned the moved
 *    body without consulting status.
 *  - the two 200-control legs: green at the parent by design — they are
 *    regression guards on the refusal check's negatives.
 */
final class PluginCatalogEgressRedirectTest extends TestCase
{
    use RunsInCoroutine;

    /** Marker body only ever served by the redirect TARGET path. */
    private const SECRET_BODY = 'SECRET-EXFIL-MARKER';

    /** Refusal wording shared by all three transports (names NO URL). */
    private const REFUSAL_NEEDLE = 'redirect refused';

    private string $root = '';

    private string $hitLog = '';

    /** @var resource|null */
    private $serverProc = null;

    private int $serverPort = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/phlix_catalog_egress_redirect_' . uniqid('', true);
        $this->hitLog = $this->root . '/hits.log';
        $serverDir = $this->root . '/server';
        mkdir($serverDir, 0775, true);
        file_put_contents($serverDir . '/router.php', $this->routerScript());
        touch($this->hitLog);

        $this->startServer();
    }

    protected function tearDown(): void
    {
        $this->stopServer();
        if ($this->root !== '') {
            RecursiveDelete::remove($this->root);
        }

        parent::tearDown();
    }

    /**
     * Router: /ok answers 200, /secret answers 200 with the marker body,
     * EVERY other path answers 302 → /secret. Every served path is appended
     * to the hit log the tests read; the Location is rebuilt from the
     * request's own Host so the fixture needs no knowledge of its bound port.
     */
    private function routerScript(): string
    {
        $hitsExport = var_export($this->hitLog, true);
        $markerExport = var_export(self::SECRET_BODY, true);

        return <<<PHP
<?php
\$uri = parse_url(\$_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '';
file_put_contents({$hitsExport}, \$uri . "\\n", FILE_APPEND);
if (\$uri === '/ok') {
    echo 'PAYLOAD-OK';
    exit;
}
if (\$uri === '/secret') {
    echo {$markerExport};
    exit;
}
header('Location: http://' . \$_SERVER['HTTP_HOST'] . '/secret', true, 302);
exit;
PHP;
    }

    private function baseUrl(): string
    {
        return "http://127.0.0.1:{$this->serverPort}";
    }

    private function startServer(): void
    {
        $lastError = '';
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $port = $this->freePort();
            $out = $this->root . "/server-{$attempt}.out";

            // ⚠ `exec ` is load-bearing (house S315 law, see
            // HttpInstallerRedirectE2eTest): proc_terminate() must signal the
            // server, not the shell that spawned it.
            $cmd = 'exec ' . implode(' ', array_map('escapeshellarg', [
                PHP_BINARY,
                '-S',
                "127.0.0.1:{$port}",
                $this->root . '/server/router.php',
            ]));

            $proc = proc_open(
                $cmd,
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $out, 'a']],
                $pipes,
            );
            $this->assertIsResource($proc, 'proc_open() refused to start the catalog egress redirect fixture server');
            $this->serverProc = $proc;
            $this->serverPort = $port;

            if ($this->waitForReady()) {
                return;
            }

            $lastError = "port {$port}: " . (is_file($out) ? (string) file_get_contents($out) : '(no output)');
            $this->stopServer();
        }

        $this->fail("the catalog egress redirect fixture server never became ready.\n{$lastError}");
    }

    private function waitForReady(): bool
    {
        $deadline = microtime(true) + 10.0;
        $context = stream_context_create(['http' => ['timeout' => 1.0, 'ignore_errors' => true]]);
        while (microtime(true) < $deadline) {
            if ($this->serverProc !== null && proc_get_status($this->serverProc)['running'] === false) {
                return false;
            }
            $body = @file_get_contents("http://127.0.0.1:{$this->serverPort}/ok", false, $context);
            if ($body === 'PAYLOAD-OK') {
                // The readiness probe itself served /ok — reset the ledger so
                // each arm asserts only on requests IT made.
                $this->resetHits();

                return true;
            }
            usleep(100_000);
        }

        return false;
    }

    private function stopServer(): void
    {
        if ($this->serverProc === null) {
            return;
        }
        $status = proc_get_status($this->serverProc);
        if ($status['running'] === true) {
            proc_terminate($this->serverProc, SIGTERM);
            $deadline = microtime(true) + 5.0;
            while (microtime(true) < $deadline) {
                // Re-read every round: the child exits asynchronously and a
                // hoisted or assumed-constant read would poll a stale value.
                $status = proc_get_status($this->serverProc);
                if ($status['running'] !== true) {
                    break;
                }
                usleep(50_000);
            }
            $status = proc_get_status($this->serverProc);
            if ($status['running'] === true) {
                proc_terminate($this->serverProc, SIGKILL);
                usleep(200_000);
            }
        }
        proc_close($this->serverProc);
        $this->serverProc = null;
    }

    private function freePort(): int
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($socket, "could not reserve a port: {$errstr} ({$errno})");
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        $this->assertGreaterThan(0, $port);

        return $port;
    }

    /** @return list<string> paths the fixture actually served */
    private function hits(): array
    {
        $lines = preg_split('/\R/', (string) @file_get_contents($this->hitLog)) ?: [];

        return array_values(array_filter(array_map('trim', $lines), static fn (string $l): bool => $l !== ''));
    }

    private function resetHits(): void
    {
        file_put_contents($this->hitLog, '');
    }

    private function invokePrivateStatic(string $method, mixed ...$args): mixed
    {
        $reflection = new ReflectionMethod(PluginCatalogService::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke(null, ...$args);
    }

    /**
     * curlFetch leg: a 302 answer must terminate the fetch with the refusal,
     * the marker path must never be REQUESTED at all, and the refusal message
     * must name no URL (87616b0f disclosure law). Red at the parent: the hop
     * was followed and the marker body returned.
     */
    public function test_curl_fetch_refuses_redirect_and_never_requests_the_target(): void
    {
        $message = null;
        try {
            $this->invokePrivateStatic('curlFetch', $this->baseUrl() . '/follow-me', 10);
        } catch (\RuntimeException $exception) {
            $message = $exception->getMessage();
        }

        self::assertNotNull($message, 'a 302 answer must raise the redirect refusal');
        self::assertStringContainsString(self::REFUSAL_NEEDLE, (string) $message);
        self::assertStringNotContainsString('127.0.0.1', (string) $message);
        self::assertStringNotContainsString('/secret', (string) $message);
        self::assertSame(
            ['/follow-me'],
            $this->hits(),
            'the cURL transport must not request the redirect target',
        );
    }

    /**
     * streamFetch leg: same convictions through the file_get_contents
     * fallback transport. Red at the parent: the hop was followed and the
     * marker body returned (measured).
     */
    public function test_stream_fetch_refuses_redirect_and_never_requests_the_target(): void
    {
        $message = null;
        try {
            $this->invokePrivateStatic('streamFetch', $this->baseUrl() . '/follow-me', 10);
        } catch (\RuntimeException $exception) {
            $message = $exception->getMessage();
        }

        self::assertNotNull($message, 'a 302 answer must raise the redirect refusal');
        self::assertStringContainsString(self::REFUSAL_NEEDLE, (string) $message);
        self::assertStringNotContainsString('127.0.0.1', (string) $message);
        self::assertStringNotContainsString('/secret', (string) $message);
        self::assertSame(
            ['/follow-me'],
            $this->hits(),
            'the stream transport must not request the redirect target',
        );
    }

    /** Control: a plain 200 still returns its body through curlFetch. */
    public function test_curl_fetch_returns_body_on_plain_200(): void
    {
        self::assertSame('PAYLOAD-OK', $this->invokePrivateStatic('curlFetch', $this->baseUrl() . '/ok', 10));
        self::assertSame(['/ok'], $this->hits());
    }

    /** Control: a plain 200 still returns its body through streamFetch. */
    public function test_stream_fetch_returns_body_on_plain_200(): void
    {
        self::assertSame('PAYLOAD-OK', $this->invokePrivateStatic('streamFetch', $this->baseUrl() . '/ok', 10));
        self::assertSame(['/ok'], $this->hits());
    }

    /**
     * End-to-end through the production defaultFetcher() closure (outside a
     * coroutine it routes to curlFetch — the same routing the plugin
     * auto-update worker executes). Red at the parent: the hop was followed.
     */
    public function test_default_fetcher_closure_refuses_redirect(): void
    {
        $fetcher = PluginCatalogService::defaultFetcher();

        $message = null;
        try {
            $fetcher($this->baseUrl() . '/follow-me', 10);
        } catch (\RuntimeException $exception) {
            $message = $exception->getMessage();
        }

        self::assertNotNull($message, 'the production fetcher must raise the redirect refusal');
        self::assertStringContainsString(self::REFUSAL_NEEDLE, (string) $message);
        self::assertStringNotContainsString(
            'async fetch',
            (string) $message,
            'the blocking arm must serve a non-coroutine caller',
        );
        self::assertSame(['/follow-me'], $this->hits());
    }

    /**
     * Async-arm option pin (the R20-01 hole): the workerman/http-client
     * request must carry allow_redirects max=0 — its vendor default follows
     * up to 5. Red at the parent: the key was absent entirely.
     */
    public function test_async_fetch_options_carry_the_redirect_law(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to execute the coroutine branch.');
        }

        $captured = null;
        $fake = $this->createMock(Client::class);
        $fake->expects($this->once())
            ->method('request')
            ->willReturnCallback(
                static function (string $url, array $options) use (&$captured): void {
                    $captured = $options;
                    $options['success'](new \Workerman\Psr7\Response(200, [], 'PAYLOAD-OK'));
                }
            );

        $body = $this->runInCoroutine(
            fn (): mixed => $this->invokePrivateStaticAsync($fake, 'http://catalog.test/plugins.json')
        );

        self::assertSame('PAYLOAD-OK', $body, 'the 200 arm must still deliver the body');
        self::assertIsArray($captured);
        self::assertArrayHasKey('allow_redirects', (array) $captured, 'the async request must carry the redirect law');
        self::assertSame(['max' => 0], $captured['allow_redirects'] ?? null);
    }

    /**
     * Async-arm refusal leg: a 302 delivered through the success callback
     * must raise the refusal naming no URL. Red at the parent: the moved body
     * was returned without consulting the status.
     */
    public function test_async_fetch_refuses_redirect_response(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to execute the coroutine branch.');
        }

        $fake = $this->createMock(Client::class);
        $fake->expects($this->once())
            ->method('request')
            ->willReturnCallback(
                static function (string $url, array $options): void {
                    $options['success'](new \Workerman\Psr7\Response(
                        302,
                        ['Location' => 'http://evil.invalid/secret'],
                        'moved-body',
                    ));
                }
            );

        $message = null;
        try {
            $this->runInCoroutine(
                fn (): mixed => $this->invokePrivateStaticAsync($fake, 'http://catalog.test/plugins.json')
            );
        } catch (\RuntimeException $exception) {
            $message = $exception->getMessage();
        }

        self::assertNotNull($message, 'a 302 through the async success arm must raise the redirect refusal');
        self::assertStringContainsString(self::REFUSAL_NEEDLE, (string) $message);
        self::assertStringNotContainsString('evil.invalid', (string) $message);
        self::assertStringNotContainsString('catalog.test', (string) $message);
    }

    private function invokePrivateStaticAsync(Client $client, string $url): mixed
    {
        $reflection = new ReflectionMethod(PluginCatalogService::class, 'asyncFetch');
        $reflection->setAccessible(true);

        return $reflection->invoke(null, $client, $url, 10);
    }
}
