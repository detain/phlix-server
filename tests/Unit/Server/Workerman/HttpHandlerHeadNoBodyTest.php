<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Workerman;

use Phlix\Auth\AuthManager;
use Phlix\Auth\RateLimitException;
use Phlix\Media\Transcoding\SegmentProcessRegistry;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\RequestAuthenticator;
use Phlix\Server\Http\RequestContext;
use Phlix\Server\Http\Response;
use Phlix\Server\Workerman\BodylessResponse;
use Phlix\Server\Workerman\HttpHandler;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http;
use Workerman\Protocols\Http\Request as WorkermanRequest;
use Workerman\Protocols\Http\Response as WorkermanResponse;

/**
 * S113, sites 1–5 of 6: the five {@see HttpHandler} branches that shipped a response
 * BODY on a `HEAD` request. (Site 6, `Router::notFound()`, is pinned by
 * {@see \Phlix\Tests\Unit\Server\Http\RouterHeadNotFoundTest}.)
 *
 * | # | Site                                            | What leaked on a HEAD          |
 * |---|-------------------------------------------------|--------------------------------|
 * | 1 | `serveStatic()`                                 | the WHOLE static file          |
 * | 2 | the page-rendering send (`__invoke`)            | the SPA shell HTML             |
 * | 3 | the `RateLimitException` 429 send               | the 429 JSON envelope          |
 * | 4 | the `catch (Throwable)` 500 send                | the 500 HTML                   |
 * | 5 | `dispatch()`'s `404 - Page not found`           | the 404 HTML                   |
 *
 * ## Why every assertion is on encoded bytes (the S105 pattern)
 *
 * Suppression happens in the ENCODER, not the model: after the fix
 * `Response::$body` still holds the entity, and `serveStatic()`'s reply has no Phlix
 * `Response` at all. Asserting on object properties would therefore pass on the
 * broken code. Every assertion below reads what a client actually receives —
 * `(string) $response` for a buffered reply, and for a file-backed one the output of
 * `Workerman\Protocols\Http::encode()`, which is where Workerman derives a file
 * response's `Content-Length` and hands the file's bytes to the connection.
 *
 * ## Why each site also asserts a NON-ZERO `Content-Length`
 *
 * RFC 9110 §9.3.2 makes a `HEAD` reply's header section describe what the equivalent
 * `GET` would have returned. "No body" is therefore only half the contract — a reply
 * that dropped the body AND answered `Content-Length: 0` would satisfy a naive
 * no-body assertion while telling the client the entity is empty. Each site pins the
 * real number, measured from the GET it mirrors.
 */
final class HttpHandlerHeadNoBodyTest extends TestCase
{
    /** The 404 page envelope `HttpHandler::dispatch()` builds. */
    private const PAGE_404_HTML = '<h1>404 - Page not found</h1>';

    /** The last-resort envelope the `catch (Throwable)` arm builds. */
    private const ERROR_500_HTML = '<h1>500 Internal Server Error</h1>';

    private string $publicRoot = '';

    /** @var list<string> Absolute paths created in setUp(), removed in tearDown(). */
    private array $created = [];

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/phlix-s113-' . bin2hex(random_bytes(6));
        mkdir($root . '/assets/app/.vite', 0o777, true);

        // A static asset big enough that a leaked body is unmistakable on the wire.
        $asset = "// phlix static asset\n" . str_repeat("console.log('x');\n", 200);
        file_put_contents($root . '/assets/app/index-DaB12cd3.js', $asset);

        // A Vite manifest + shell, so `/app` renders the REAL SPA shell branch rather
        // than SharedUiController's missing-bundle 503.
        file_put_contents(
            $root . '/assets/app/.vite/manifest.json',
            (string) json_encode(['index.html' => ['file' => 'assets/app/index-DaB12cd3.js', 'isEntry' => true]]),
        );
        file_put_contents(
            $root . '/assets/app/index.html',
            "<!doctype html>\n<html><head><title>Phlix</title></head><body>\n"
            . str_repeat("<div>shell padding so the shell is worth gzipping</div>\n", 40)
            . "</body></html>\n",
        );

        $this->created = [
            $root . '/assets/app/index-DaB12cd3.js',
            $root . '/assets/app/.vite/manifest.json',
            $root . '/assets/app/index.html',
        ];
        $this->publicRoot = (string) realpath($root);
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $file) {
            @unlink($file);
        }
        if ($this->publicRoot !== '') {
            @rmdir($this->publicRoot . '/assets/app/.vite');
            @rmdir($this->publicRoot . '/assets/app');
            @rmdir($this->publicRoot . '/assets');
            @rmdir($this->publicRoot);
        }
        // __invoke arms a per-request cancel group; a throwing dispatch must not leak
        // it into a sibling test.
        RequestContext::clearCancelGroup();
        parent::tearDown();
    }

    // --- harness --------------------------------------------------------------

    /**
     * A connection double that records every raw send.
     *
     * @param list<mixed> $sent
     */
    private function makeConnection(array &$sent): TcpConnection
    {
        $sent = [];
        $conn = $this->createMock(TcpConnection::class);
        $conn->bytesRead = 0;
        $conn->bytesWritten = 0;
        $conn->method('send')->willReturnCallback(
            static function (mixed $data) use (&$sent): bool {
                $sent[] = $data;

                return true;
            }
        );

        return $conn;
    }

    private function makeHandler(?Application $application = null): HttpHandler
    {
        $registry = new SegmentProcessRegistry();
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static function (string $id) use ($registry): mixed {
                if ($id === SegmentProcessRegistry::class) {
                    return $registry;
                }
                throw new \RuntimeException('unexpected container get: ' . $id);
            }
        );
        $container->method('has')->willReturn(true);

        return new HttpHandler(
            $container,
            new RequestAuthenticator($this->createMock(AuthManager::class)),
            $this->publicRoot,
            $application ?? $this->notFoundApplication(),
            null,
        );
    }

    /** An Application whose router 404s everything, so `__invoke` falls through. */
    private function notFoundApplication(): Application
    {
        $application = $this->createMock(Application::class);
        $application->method('dispatch')->willReturnCallback(
            static fn (Request $request): Response => (new Response())->status(404)->json(['error' => 'Not Found']),
        );

        return $application;
    }

    /**
     * Drive one request end to end and return the single response that was sent.
     *
     * @param array<string, string> $headers Extra request headers.
     */
    private function invoke(string $method, string $path, ?Application $app = null, array $headers = []): mixed
    {
        $raw = "{$method} {$path} HTTP/1.1\r\nHost: localhost\r\n";
        foreach ($headers as $name => $value) {
            $raw .= "{$name}: {$value}\r\n";
        }
        $raw .= "\r\n";

        $sent = [];
        $this->makeHandler($app)->__invoke($this->makeConnection($sent), new WorkermanRequest($raw));

        self::assertCount(1, $sent, 'exactly one response is sent');

        return $sent[0];
    }

    /**
     * The bytes this response actually puts on the socket.
     *
     * A buffered reply stringifies to the whole message. A FILE-backed reply does
     * not: `Response::__toString()` emits only the head (with no `Content-Length` at
     * all), and both the length and the file's bytes are produced by
     * `Http::encode()`, which pushes them straight into the connection. Routing every
     * measurement through `encode()` is what makes the static-file comparison below
     * a wire comparison rather than a head comparison.
     */
    private function wireBytes(mixed $response): string
    {
        self::assertInstanceOf(WorkermanResponse::class, $response);

        $pushed = [];
        $returned = Http::encode($response, $this->makeConnection($pushed));

        $wire = $returned;
        foreach ($pushed as $chunk) {
            $wire .= (string) $chunk;
        }

        return $wire;
    }

    /** The bytes trailing the head — what a header-only client leaves buffered. */
    private static function bodyBytes(string $wire): string
    {
        $parts = explode("\r\n\r\n", $wire, 2);
        self::assertArrayHasKey(1, $parts, "the head was never terminated. Encoded bytes were:\n" . $wire);

        return $parts[1];
    }

    /**
     * The shared contract: no body, exactly one `Content-Length`, and that length is
     * the size of the entity the equivalent `GET` returned.
     */
    private function assertHeadOf(string $headWire, string $getWire, string $site): void
    {
        $getBody = self::bodyBytes($getWire);
        self::assertNotSame('', $getBody, $site . ': premise — the GET really does carry a body');

        self::assertSame(
            '',
            self::bodyBytes($headWire),
            $site . ': a HEAD must carry NO body (RFC 9110 §9.3.2). The ' . strlen($getBody)
            . " byte(s) below would sit buffered in the socket and desync the next reply:\n" . $headWire,
        );
        self::assertSame(
            1,
            substr_count($headWire, 'Content-Length:'),
            $site . ": exactly ONE Content-Length (RFC 9110 §8.6). Encoded bytes were:\n" . $headWire,
        );
        self::assertStringContainsString(
            'Content-Length: ' . strlen($getBody) . "\r\n",
            $headWire,
            $site . ': a HEAD must report the length the equivalent GET would have returned ('
            . strlen($getBody) . "), never 0. Encoded bytes were:\n" . $headWire,
        );
        self::assertStringNotContainsString(
            'Content-Length: 0' . "\r\n",
            $headWire,
            $site . ': zeroing the length answers the client wrongly as well as conformantly',
        );
    }

    // --- site 1: serveStatic() ------------------------------------------------

    /**
     * Site 1. `serveStatic()` had NO method check of any kind: it called
     * `withFile()` unconditionally, so `Http::encode()` streamed the entire file in
     * answer to a `HEAD`.
     */
    public function testAHeadForAStaticFileShipsTheHeadersButNotTheFile(): void
    {
        $path = '/assets/app/index-DaB12cd3.js';
        $onDisk = (string) file_get_contents($this->publicRoot . $path);

        $getWire = $this->wireBytes($this->invoke('GET', $path));
        $headWire = $this->wireBytes($this->invoke('HEAD', $path));

        // Premise: the GET really does stream the file, so the HEAD assertion below
        // is not vacuous.
        self::assertSame($onDisk, self::bodyBytes($getWire), 'premise: a GET streams the whole file');
        self::assertSame(3622, strlen($onDisk), 'premise: the fixture asset is 3622 bytes');

        $this->assertHeadOf($headWire, $getWire, 'serveStatic()');
        self::assertStringNotContainsString('console.log', $headWire, 'not one byte of the file may reach a HEAD');

        // The header set a GET would have carried is reproduced, not dropped.
        self::assertStringContainsString("Accept-Ranges: bytes\r\n", $headWire);
        self::assertStringContainsString("Cache-Control: public, max-age=31536000, immutable\r\n", $headWire);
        self::assertStringContainsString('Last-Modified: ', $headWire);
        foreach (['Content-Type', 'Cache-Control', 'Accept-Ranges', 'Last-Modified'] as $field) {
            self::assertStringContainsString($field . ': ', $getWire, 'premise: the GET carries ' . $field . ' too');
        }
    }

    /**
     * Site 1, the mechanism: the `HEAD` arm must not hand Workerman a file at all.
     * A reply that still carried `$file` would be re-expanded by `Http::encode()`
     * however the head was rendered, so this pins the class rather than the bytes.
     */
    public function testTheStaticHeadReplyCarriesNoFileForWorkermanToStream(): void
    {
        $head = $this->invoke('HEAD', '/assets/app/index-DaB12cd3.js');
        self::assertInstanceOf(BodylessResponse::class, $head);

        $get = $this->invoke('GET', '/assets/app/index-DaB12cd3.js');
        self::assertInstanceOf(WorkermanResponse::class, $get);
        self::assertNotInstanceOf(BodylessResponse::class, $get, 'a GET must keep the streaming encoder');
    }

    // --- site 2: the page-rendering send --------------------------------------

    /**
     * Site 2. `__invoke`'s page-rendering branch is the only one that never passes
     * through a `Router`, so nothing had ever flagged the SPA shell head-only.
     */
    public function testAHeadForTheSpaShellShipsNoHtml(): void
    {
        $getWire = (string) $this->invoke('GET', '/app');
        $headWire = (string) $this->invoke('HEAD', '/app');

        self::assertStringContainsString('shell padding', self::bodyBytes($getWire), 'premise: the GET renders it');
        $this->assertHeadOf($headWire, $getWire, 'the SPA shell');
        self::assertStringNotContainsString('shell padding', $headWire);
        self::assertStringNotContainsString('<!doctype html>', $headWire);
    }

    /**
     * Site 2, the ORDERING that makes the length truthful: the head-only marking runs
     * AFTER `compressResponse()`. A `HEAD` whose `Accept-Encoding` says gzip must
     * advertise the COMPRESSED size, because that is what the equivalent `GET`
     * returns. Marking before compression would state the uncompressed size — a
     * conformant-looking reply that is wrong by exactly the compression ratio.
     */
    public function testAGzipAcceptingHeadAdvertisesTheCompressedLength(): void
    {
        $gzip = ['Accept-Encoding' => 'gzip, deflate'];

        $getWire = (string) $this->invoke('GET', '/app', null, $gzip);
        $headWire = (string) $this->invoke('HEAD', '/app', null, $gzip);

        self::assertStringContainsString("Content-Encoding: gzip\r\n", $getWire, 'premise: the GET is gzipped');
        self::assertStringContainsString("Content-Encoding: gzip\r\n", $headWire, 'the HEAD must say so too');

        $compressed = strlen(self::bodyBytes($getWire));
        $uncompressed = strlen(self::bodyBytes((string) $this->invoke('GET', '/app')));
        self::assertLessThan($uncompressed, $compressed, 'premise: gzip actually shrinks this shell');

        // Not compared for EQUALITY against the GET's compressed size: the shell
        // carries a fresh per-request CSP nonce, so two gzip runs differ by a few
        // bytes. The property under test is which SIDE of the compression the length
        // was taken from, and that is a gap of thousands of bytes.
        self::assertSame(
            '',
            self::bodyBytes($headWire),
            "a gzipped HEAD must carry no body either. Encoded bytes were:\n" . $headWire,
        );
        self::assertSame(1, substr_count($headWire, 'Content-Length:'), 'exactly ONE Content-Length');

        self::assertSame(
            1,
            preg_match('/\r\nContent-Length: (\d+)\r\n/', $headWire, $m),
            "no Content-Length on the gzipped HEAD. Encoded bytes were:\n" . $headWire,
        );
        $advertised = (int) $m[1];
        self::assertGreaterThan(0, $advertised, 'never 0');
        self::assertLessThan(
            $uncompressed,
            $advertised,
            'the HEAD advertised ' . $advertised . ' for a gzipped reply whose uncompressed size is '
            . $uncompressed . ' — the marking ran BEFORE compressResponse() and pinned the wrong length',
        );
        self::assertLessThanOrEqual(
            16,
            abs($advertised - $compressed),
            'the advertised length (' . $advertised . ') must be the compressed size (~' . $compressed
            . '), differing only by the per-request nonce',
        );
    }

    // --- site 3: the RateLimitException 429 -----------------------------------

    /**
     * Site 3. The 429 is built in a catch block, after the limiter threw straight
     * THROUGH the router, so `markHeadOnly()` never saw it.
     */
    public function testAHeadThatTripsTheRateLimiterShipsNo429Body(): void
    {
        $limited = $this->createMock(Application::class);
        $limited->method('dispatch')->willReturnCallback(
            static function (): never {
                throw new RateLimitException(resetAt: time() + 90, remaining: 0);
            }
        );

        $getWire = (string) $this->invoke('GET', '/api/v1/media/facets', $limited);
        $headWire = (string) $this->invoke('HEAD', '/api/v1/media/facets', $limited);

        self::assertStringStartsWith("HTTP/1.1 429 Too Many Requests\r\n", $headWire);
        self::assertStringContainsString('rate_limited', self::bodyBytes($getWire), 'premise: the GET carries it');
        $this->assertHeadOf($headWire, $getWire, 'the 429');
        self::assertStringNotContainsString('rate_limited', $headWire, 'the envelope must not reach a HEAD client');
        // The signal a rate-limited client actually needs is a HEADER, and it survives.
        self::assertMatchesRegularExpression('/\r\nRetry-After: \d+\r\n/', $headWire);
    }

    // --- site 4: the catch (Throwable) 500 ------------------------------------

    /**
     * Site 4. The last-resort 500 is a hand-built raw Workerman response, so it never
     * had a `Response::$headOnly` to consult. A crash is exactly when a desynced
     * keep-alive connection is least welcome.
     */
    public function testAHeadThatCrashesTheWorkerShipsNo500Body(): void
    {
        $exploding = $this->createMock(Application::class);
        $exploding->method('dispatch')->willReturnCallback(
            static function (): never {
                throw new \RuntimeException('boom');
            }
        );

        $getWire = (string) $this->invoke('GET', '/api/v1/anything', $exploding);
        $headWire = (string) $this->invoke('HEAD', '/api/v1/anything', $exploding);

        self::assertStringStartsWith("HTTP/1.1 500 Internal Server Error\r\n", $headWire);
        self::assertSame(self::ERROR_500_HTML, self::bodyBytes($getWire), 'premise: the GET carries the HTML');
        $this->assertHeadOf($headWire, $getWire, 'the 500');
        self::assertStringNotContainsString('Internal Server Error</h1>', $headWire);
        self::assertStringContainsString(
            'Content-Length: ' . strlen(self::ERROR_500_HTML) . "\r\n",
            $headWire,
            'the 500 must still declare its 34-byte entity',
        );
    }

    // --- site 5: dispatch()'s 404 page ----------------------------------------

    /**
     * Site 5. `dispatch()`'s own `404 - Page not found` envelope — a different site
     * from `Router::notFound()` (different class, different body, different
     * dispatcher) and the one a browser hits for any unrouted non-`/api/` path.
     */
    public function testAHeadForAnUnroutedPageShipsNo404Html(): void
    {
        $getWire = (string) $this->invoke('GET', '/no-such-page');
        $headWire = (string) $this->invoke('HEAD', '/no-such-page');

        self::assertStringStartsWith("HTTP/1.1 404 Not Found\r\n", $headWire);
        self::assertSame(self::PAGE_404_HTML, self::bodyBytes($getWire), 'premise: the GET carries the HTML');
        $this->assertHeadOf($headWire, $getWire, "dispatch()'s 404 page");
        self::assertStringNotContainsString('Page not found', $headWire);
    }

    // --- the whole-connection consequence -------------------------------------

    /**
     * The defect stated as bytes rather than as prose. Five `HEAD`s — one per site —
     * pipelined onto one keep-alive connection. A header-only client reads to each
     * CRLFCRLF and stops; if any site leaks a body, the client's idea of where the
     * next reply begins drifts by exactly that many bytes and every subsequent reply
     * is misparsed.
     *
     * ⚠ This is a MODEL of the desync, executed against the real encoders but not
     * against a live Workerman worker — see the step worklog for what was and was not
     * proved on a real socket.
     */
    public function testAHeaderOnlyClientReadingFiveHeadRepliesConsumesExactlyFiveHeads(): void
    {
        $limited = $this->createMock(Application::class);
        $limited->method('dispatch')->willReturnCallback(
            static function (): never {
                throw new RateLimitException(resetAt: time() + 5, remaining: 0);
            }
        );
        $exploding = $this->createMock(Application::class);
        $exploding->method('dispatch')->willReturnCallback(
            static function (): never {
                throw new \RuntimeException('boom');
            }
        );

        $stream = $this->wireBytes($this->invoke('HEAD', '/assets/app/index-DaB12cd3.js'))
            . (string) $this->invoke('HEAD', '/app')
            . (string) $this->invoke('HEAD', '/api/v1/media/facets', $limited)
            . (string) $this->invoke('HEAD', '/api/v1/anything', $exploding)
            . (string) $this->invoke('HEAD', '/no-such-page');

        // A header-only client consumes up to and including each terminator. Five
        // complete heads and nothing else means exactly six chunks, the last empty.
        $chunks = explode("\r\n\r\n", $stream);
        self::assertCount(6, $chunks, "the stream must be exactly five heads. Bytes were:\n" . $stream);
        self::assertSame('', $chunks[5], 'nothing may trail the fifth head');

        // Every chunk after the first is a status line, i.e. the client's read offset
        // never drifted.
        foreach (array_slice($chunks, 1, 4) as $i => $chunk) {
            self::assertStringStartsWith(
                'HTTP/1.1 ',
                $chunk,
                'reply ' . ($i + 2) . " did not begin at a status line — the connection desynced:\n" . $stream,
            );
        }
        self::assertSame(5, substr_count($stream, 'HTTP/1.1 '), 'five replies, five status lines');
    }

    /**
     * DISCRIMINATING CONTROL for all five sites at once: the fix keys on the METHOD.
     * Every `GET` above still carries its whole body, so none of this suite can be
     * passed by suppressing bodies generally.
     */
    public function testEveryGetStillCarriesItsBody(): void
    {
        $exploding = $this->createMock(Application::class);
        $exploding->method('dispatch')->willReturnCallback(
            static function (): never {
                throw new \RuntimeException('boom');
            }
        );

        $bodies = [
            'serveStatic()'  => self::bodyBytes($this->wireBytes($this->invoke('GET', '/assets/app/index-DaB12cd3.js'))),
            'the SPA shell'  => self::bodyBytes((string) $this->invoke('GET', '/app')),
            'the 500'        => self::bodyBytes((string) $this->invoke('GET', '/api/v1/anything', $exploding)),
            'the 404 page'   => self::bodyBytes((string) $this->invoke('GET', '/no-such-page')),
        ];

        foreach ($bodies as $site => $body) {
            self::assertNotSame('', $body, $site . ': a GET must still ship its entity');
        }
        self::assertSame(3622, strlen($bodies['serveStatic()']));
        self::assertSame(self::ERROR_500_HTML, $bodies['the 500']);
        self::assertSame(self::PAGE_404_HTML, $bodies['the 404 page']);
    }
}
