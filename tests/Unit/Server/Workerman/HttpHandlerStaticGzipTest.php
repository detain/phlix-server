<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Workerman;

use Phlix\Auth\AuthManager;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\RequestAuthenticator;
use Phlix\Server\Workerman\HttpHandler;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionMethod;
use ReflectionProperty;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http as HttpProtocol;
use Workerman\Protocols\Http\Request as WorkermanRequest;
use Workerman\Protocols\Http\Response as WorkermanResponse;

/**
 * minors M-SERVER-51 (W-min105) — the gzip variant of serveStatic().
 *
 * The dispatch arms compress buffered router replies (HttpHandlerCompressionTest
 * pins that law); before this batch the STATIC fast path shipped `public/**`
 * bytes with zero encoding on the bare-:8096 docker deploy (no edge proxy in
 * `docker/supervisord.conf`), measured by the review at 2,222,920 bytes raw vs
 * 697,478 gzipped for the SPA bundle. These pins hold the negotiated truth AND
 * every decline path — plus the S113 parity law in its per-client form: the
 * HEAD's `Content-Length` equals what the SAME-shape GET actually ships, for
 * gzip-negotiating and plain clients alike.
 *
 * The observation surface is the full wire bytes via `HttpProtocol::encode()`
 * against a recording connection (the HttpHandlerHeadNoBodyTest idiom), so a
 * stream reply and a buffered reply are discriminated by what the WIRE says,
 * not by private properties.
 */
final class HttpHandlerStaticGzipTest extends TestCase
{
    private string $publicRoot = '';

    /** ~4.2 KB of genuinely compressible JavaScript. */
    private const BIG_JS_PATH = '/assets/app/big-Ab12Cd34.js';

    private string $bigJsBody;

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/phlix-staticgzip-' . bin2hex(random_bytes(6));
        mkdir($root . '/assets/app', 0o777, true);
        $this->bigJsBody = str_repeat("console.log('phlix-static-gzip-variant');\n", 140);
        self::assertGreaterThan(1024, strlen($this->bigJsBody), 'premise: fixture clears GZIP_MIN_BYTES');
        file_put_contents($root . self::BIG_JS_PATH, $this->bigJsBody);
        file_put_contents($root . '/assets/app/small-Za12Bc34.js', "console.log(1);\n");
        mkdir($root . '/img', 0o777, true);
        file_put_contents($root . '/img/photo-Ab12.jpg', random_bytes(4096));
        // Over the 4 MiB buffer ceiling: zero bytes, .css type — WOULD compress
        // fine, must still decline on size grounds (stream stays zero-copy).
        file_put_contents($root . '/assets/app/huge-Bc34De56.css', str_repeat("\0", 4 * 1024 * 1024 + 1));
        $this->publicRoot = (string) realpath($root);
    }

    protected function tearDown(): void
    {
        if ($this->publicRoot === '' || !is_dir($this->publicRoot)) {
            return;
        }
        @system('rm -rf ' . escapeshellarg($this->publicRoot));
    }

    private function makeHandler(): HttpHandler
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static function (string $id): mixed {
                throw new \RuntimeException('unexpected container get: ' . $id);
            }
        );

        return new HttpHandler(
            $container,
            new RequestAuthenticator($this->createMock(AuthManager::class)),
            $this->publicRoot,
            $this->createMock(Application::class),
            null,
        );
    }

    private function serveStatic(string $method, string $path, ?string $acceptEncoding): ?WorkermanResponse
    {
        $head = $method . ' ' . $path . " HTTP/1.1\r\nHost: localhost\r\n";
        if ($acceptEncoding !== null) {
            $head .= 'Accept-Encoding: ' . $acceptEncoding . "\r\n";
        }
        $wr = new WorkermanRequest($head . "\r\n");

        $m = new ReflectionMethod(HttpHandler::class, 'serveStatic');
        $m->setAccessible(true);
        /** @var ?WorkermanResponse $result */
        $result = $m->invoke($this->makeHandler(), $wr);

        return $result;
    }

    /** @param list<mixed> $sent */
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

    /** The complete wire bytes for a serveStatic reply (head + pushed stream). */
    private function wireBytes(WorkermanResponse $response): string
    {
        $pushed = [];
        $wire = HttpProtocol::encode($response, $this->makeConnection($pushed));
        foreach ($pushed as $chunk) {
            $wire .= (string) $chunk;
        }

        return $wire;
    }

    /** @return array{0:string,1:string} head, body */
    private static function splitWire(string $wire): array
    {
        $parts = explode("\r\n\r\n", $wire, 2);
        self::assertArrayHasKey(1, $parts, 'the head was never terminated. Wire was: ' . $wire);

        return [$parts[0], $parts[1]];
    }

    public function testGzipNegotiatedGetShipsEncodedBodyWithImmutableAndVary(): void
    {
        $resp = $this->serveStatic('GET', self::BIG_JS_PATH, 'gzip, deflate, br');
        self::assertInstanceOf(WorkermanResponse::class, $resp);

        [$head, $body] = self::splitWire($this->wireBytes($resp));

        self::assertStringContainsString('HTTP/1.1 200', $head);
        self::assertStringContainsString('Content-Encoding: gzip', $head);
        // The negotiated reply must NOT lose the immutable long-cache law —
        // the two headers are orthogonal and both were required to survive.
        self::assertStringContainsString('Cache-Control: public, max-age=31536000, immutable', $head);
        self::assertStringContainsString('Vary: Accept-Encoding', $head);

        self::assertNotSame('', $body, 'premise: the negotiating GET really carries a body');
        self::assertLessThan(strlen($this->bigJsBody), strlen($body), 'and it is smaller than the raw bytes');
        self::assertStringContainsString(
            'Content-Length: ' . strlen($body),
            $head,
            'framing length matches the encoded body byte-for-byte',
        );
        self::assertSame($this->bigJsBody, gzdecode($body), 'the encoded body round-trips to the file');
    }

    public function testGzipNegotiatedHeadAdvertisesTheCompressedLengthAndCarriesNoBody(): void
    {
        $get = $this->serveStatic('GET', self::BIG_JS_PATH, 'gzip');
        $head = $this->serveStatic('HEAD', self::BIG_JS_PATH, 'gzip');
        self::assertInstanceOf(WorkermanResponse::class, $get);
        self::assertInstanceOf(WorkermanResponse::class, $head);

        [$getHead] = self::splitWire($this->wireBytes($get));
        [$headHead, $headBody] = self::splitWire($this->wireBytes($head));

        self::assertSame('', $headBody, 'S113: a HEAD never ships a body');
        self::assertStringContainsString(
            'Content-Encoding: gzip',
            $headHead,
            'HEAD describes the variant its GET ships',
        );
        $getLength = self::headerValue($getHead, 'Content-Length');
        self::assertSame(
            $getLength,
            self::headerValue($headHead, 'Content-Length'),
            'parity: the negotiating HEAD advertises exactly the negotiating GET\'s length',
        );
    }

    public function testPlainClientStillGetsTheZeroCopyStreamReply(): void
    {
        $resp = $this->serveStatic('GET', self::BIG_JS_PATH, null);
        self::assertInstanceOf(WorkermanResponse::class, $resp);

        [$head, $body] = self::splitWire($this->wireBytes($resp));

        self::assertStringNotContainsString('Content-Encoding', $head);
        self::assertStringNotContainsString('Vary: Accept-Encoding', $head);
        self::assertSame($this->bigJsBody, $body, 'plain GET streams the real bytes');
        self::assertStringContainsString('Content-Length: ' . strlen($this->bigJsBody), $head);
    }

    public function testPlainHeadStillAdvertisesTheRealFileSize(): void
    {
        $resp = $this->serveStatic('HEAD', self::BIG_JS_PATH, null);
        self::assertInstanceOf(WorkermanResponse::class, $resp);

        [$head, $body] = self::splitWire($this->wireBytes($resp));

        self::assertSame('', $body);
        self::assertStringContainsString('Content-Length: ' . strlen($this->bigJsBody), $head);
    }

    public function testSmallAssetBelowTheFloorIsNeverEncoded(): void
    {
        $resp = $this->serveStatic('GET', '/assets/app/small-Za12Bc34.js', 'gzip');
        self::assertInstanceOf(WorkermanResponse::class, $resp);

        [$head] = self::splitWire($this->wireBytes($resp));
        self::assertStringNotContainsString('Content-Encoding', $head);
    }

    public function testImageTypeIsNeverEncoded(): void
    {
        $resp = $this->serveStatic('GET', '/img/photo-Ab12.jpg', 'gzip');
        self::assertInstanceOf(WorkermanResponse::class, $resp);

        [$head] = self::splitWire($this->wireBytes($resp));
        self::assertStringNotContainsString('Content-Encoding', $head);
    }

    public function testFileOverTheBufferCeilingStaysAStreamEvenWhenTextual(): void
    {
        $resp = $this->serveStatic('GET', '/assets/app/huge-Bc34De56.css', 'gzip');
        self::assertInstanceOf(WorkermanResponse::class, $resp);

        // The 4 MiB+1 stream would page through the fake connection's
        // stream-context internals; object-level truth is sharper here anyway:
        // `withFile()` sets Workerman's private $file property (the zero-copy
        // promise), and an encoded variant never does.
        self::assertNull($resp->getHeader('Content-Encoding'), 'over-ceiling text must NOT be encoded');
        $file = new ReflectionProperty(WorkermanResponse::class, 'file');
        $file->setAccessible(true);
        /** @var array{file?:string}|null $fileInfo */
        $fileInfo = $file->getValue($resp);
        self::assertIsArray($fileInfo, 'the reply is still the file-backed stream');
        self::assertSame(
            $this->publicRoot . '/assets/app/huge-Bc34De56.css',
            $fileInfo['file'] ?? null,
            'withFile() names the real file — the zero-copy promise kept',
        );
    }

    /** @param string $head an encoded head block (no trailing CRLFCRLF) */
    private static function headerValue(string $head, string $name): ?string
    {
        foreach (explode("\r\n", $head) as $line) {
            if (stripos($line, $name . ':') === 0) {
                return trim(substr($line, strlen($name) + 1));
            }
        }

        return null;
    }
}
