<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Workerman;

use Phlix\Auth\AuthManager;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\RequestAuthenticator;
use Phlix\Server\Http\Response;
use Phlix\Server\Workerman\HttpHandler;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Workerman\Protocols\Http\Request as WorkermanRequest;

/**
 *
 * Pins the S4 response-compression behaviour of {@see HttpHandler::compressResponse()}
 * and its helpers: text/JSON/HTML bodies are gzip'd when the client supports it and
 * the body clears the size threshold, while media/streaming responses (file-backed
 * or non-text Content-Types — HLS/DASH playlists & segments, images, video) are
 * NEVER compressed. Exercised via reflection because these are private seams on the
 * per-request handler.
 */
final class HttpHandlerCompressionTest extends TestCase
{
    private function makeHandler(): HttpHandler
    {
        return new HttpHandler(
            $this->createMock(ContainerInterface::class),
            new RequestAuthenticator($this->createMock(AuthManager::class)),
            '/var/www/phlix/public',
            $this->createMock(Application::class),
            null,
        );
    }

    private function makeRequest(string $acceptEncoding = 'gzip, deflate, br'): WorkermanRequest
    {
        $raw = "GET / HTTP/1.1\r\nHost: localhost\r\n";
        if ($acceptEncoding !== '') {
            $raw .= "Accept-Encoding: {$acceptEncoding}\r\n";
        }
        $raw .= "\r\n";

        return new WorkermanRequest($raw);
    }

    private function invokeCompress(HttpHandler $handler, WorkermanRequest $wr, Response $response): void
    {
        $m = new \ReflectionMethod(HttpHandler::class, 'compressResponse');
        $m->setAccessible(true);
        $m->invoke($handler, $wr, $response);
    }

    /**
     * @return array<string, string>
     */
    private function lcHeaders(Response $response): array
    {
        $out = [];
        foreach ($response->headers as $k => $v) {
            $out[strtolower($k)] = $v;
        }

        return $out;
    }

    private function largeBody(): string
    {
        return str_repeat('{"k":"the quick brown fox jumps"} ', 200); // ~6.6 KB, compressible
    }

    // --- happy path --------------------------------------------------------

    public function testJsonBodyIsGzippedWhenClientSupportsIt(): void
    {
        $handler = $this->makeHandler();
        $plain = $this->largeBody();
        $response = (new Response())->header('Content-Type', 'application/json')->body($plain);

        $this->invokeCompress($handler, $this->makeRequest(), $response);

        $h = $this->lcHeaders($response);
        self::assertSame('gzip', $h['content-encoding'] ?? null);
        self::assertArrayHasKey('vary', $h);
        self::assertStringContainsStringIgnoringCase('accept-encoding', $h['vary']);
        self::assertSame((string) strlen($response->body), $h['content-length'] ?? null);
        self::assertLessThan(strlen($plain), strlen($response->body));
        // Round-trips back to the original bytes.
        self::assertSame($plain, gzdecode($response->body));
    }

    public function testHtmlBodyIsGzipped(): void
    {
        $handler = $this->makeHandler();
        $response = (new Response())->html('<html><body>' . str_repeat('<p>hello world</p>', 300) . '</body></html>');

        $this->invokeCompress($handler, $this->makeRequest(), $response);

        self::assertSame('gzip', $this->lcHeaders($response)['content-encoding'] ?? null);
    }

    // --- skip conditions ---------------------------------------------------

    public function testNotCompressedWhenClientDoesNotAcceptGzip(): void
    {
        $handler = $this->makeHandler();
        $response = (new Response())->header('Content-Type', 'application/json')->body($this->largeBody());

        $this->invokeCompress($handler, $this->makeRequest('deflate, br'), $response);

        self::assertArrayNotHasKey('content-encoding', $this->lcHeaders($response));
    }

    public function testNotCompressedWhenNoAcceptEncodingHeader(): void
    {
        $handler = $this->makeHandler();
        $response = (new Response())->header('Content-Type', 'application/json')->body($this->largeBody());

        $this->invokeCompress($handler, $this->makeRequest(''), $response);

        self::assertArrayNotHasKey('content-encoding', $this->lcHeaders($response));
    }

    public function testSmallBodyBelowThresholdIsNotCompressed(): void
    {
        $handler = $this->makeHandler();
        $response = (new Response())->header('Content-Type', 'application/json')->body('{"ok":true}');

        $this->invokeCompress($handler, $this->makeRequest(), $response);

        self::assertArrayNotHasKey('content-encoding', $this->lcHeaders($response));
    }

    public function testEmptyBodyIsNotCompressed(): void
    {
        $handler = $this->makeHandler();
        $response = (new Response())->status(204)->header('Content-Type', 'application/json');

        $this->invokeCompress($handler, $this->makeRequest(), $response);

        self::assertArrayNotHasKey('content-encoding', $this->lcHeaders($response));
    }

    public function testAlreadyEncodedBodyIsNotDoubleCompressed(): void
    {
        $handler = $this->makeHandler();
        $response = (new Response())
            ->header('Content-Type', 'application/json')
            ->header('Content-Encoding', 'br')
            ->body($this->largeBody());

        $this->invokeCompress($handler, $this->makeRequest(), $response);

        self::assertSame('br', $this->lcHeaders($response)['content-encoding'] ?? null);
    }

    public function testFileBackedResponseIsNeverCompressed(): void
    {
        $handler = $this->makeHandler();
        // A file-backed (withFile) response with a text Content-Type and no buffered
        // body: the filePath guard alone must exclude it (this is the HLS/DASH path).
        $response = (new Response())
            ->header('Content-Type', 'application/vnd.apple.mpegurl')
            ->withFile('/tmp/does-not-need-to-exist.m3u8');

        $this->invokeCompress($handler, $this->makeRequest(), $response);

        self::assertArrayNotHasKey('content-encoding', $this->lcHeaders($response));
        self::assertSame('', $response->body);
    }

    /**
     * Content-Types that must never be gzipped even with a large buffered body and a
     * gzip-capable client — the media/streaming surface.
     *
     * @return array<string, array{0: string}>
     */
    public static function nonCompressibleTypeProvider(): array
    {
        return [
            'hls playlist'  => ['application/vnd.apple.mpegurl'],
            'hls playlist alt' => ['application/x-mpegurl'],
            'dash manifest' => ['application/dash+xml'],
            'mpeg-ts segment' => ['video/mp2t'],
            'mp4 video'     => ['video/mp4'],
            'matroska'      => ['video/x-matroska'],
            'jpeg image'    => ['image/jpeg'],
            'png image'     => ['image/png'],
            'webp image'    => ['image/webp'],
            'mpeg audio'    => ['audio/mpeg'],
            'octet-stream'  => ['application/octet-stream'],
            'pdf'           => ['application/pdf'],
        ];
    }

    /**
     * @dataProvider nonCompressibleTypeProvider
     */
    public function testMediaContentTypesAreNeverCompressed(string $contentType): void
    {
        $handler = $this->makeHandler();
        $response = (new Response())->header('Content-Type', $contentType)->body($this->largeBody());

        $this->invokeCompress($handler, $this->makeRequest(), $response);

        self::assertArrayNotHasKey(
            'content-encoding',
            $this->lcHeaders($response),
            "{$contentType} must not be gzip-compressed",
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function compressibleTypeProvider(): array
    {
        return [
            'json'          => ['application/json'],
            'json charset'  => ['application/json; charset=utf-8'],
            'html'          => ['text/html; charset=utf-8'],
            'plain'         => ['text/plain'],
            'css'           => ['text/css'],
            'javascript'    => ['application/javascript'],
            'xml'           => ['application/xml'],
            'svg'           => ['image/svg+xml'],
            'atom (opds)'   => ['application/atom+xml'],
            'manifest'      => ['application/manifest+json'],
        ];
    }

    /**
     * @dataProvider compressibleTypeProvider
     */
    public function testTextContentTypesAreCompressed(string $contentType): void
    {
        $handler = $this->makeHandler();
        $response = (new Response())->header('Content-Type', $contentType)->body($this->largeBody());

        $this->invokeCompress($handler, $this->makeRequest(), $response);

        self::assertSame(
            'gzip',
            $this->lcHeaders($response)['content-encoding'] ?? null,
            "{$contentType} should be gzip-compressed",
        );
    }

    public function testGzipThatDoesNotShrinkTheBodyIsSkipped(): void
    {
        $handler = $this->makeHandler();
        // High-entropy (already-incompressible) bytes over the size threshold with a
        // compressible text/* Content-Type: gzip's ~20-byte envelope makes the encoded
        // output >= the original, so the shrink guard must leave the body untouched.
        $random = random_bytes(4096);
        $response = (new Response())->header('Content-Type', 'text/plain')->body($random);

        $this->invokeCompress($handler, $this->makeRequest(), $response);

        self::assertArrayNotHasKey('content-encoding', $this->lcHeaders($response));
        self::assertSame($random, $response->body, 'The plain body must be left in place unchanged.');
    }

    public function testStalePreExistingContentLengthIsReplacedWithCompressedSize(): void
    {
        $handler = $this->makeHandler();
        $plain = $this->largeBody();
        // A deliberately-wrong, differently-cased Content-Length that must be dropped
        // (case-insensitively) and rewritten to the compressed size so framing is correct.
        $response = (new Response())
            ->header('Content-Type', 'application/json')
            ->header('content-length', '999999')
            ->body($plain);

        $this->invokeCompress($handler, $this->makeRequest(), $response);

        $h = $this->lcHeaders($response);
        self::assertSame('gzip', $h['content-encoding'] ?? null);
        self::assertSame((string) strlen($response->body), $h['content-length'] ?? null);
        self::assertNotSame('999999', $h['content-length'] ?? null);
        // Exactly one Content-Length header survives (no case-variant duplicate).
        $clCount = 0;
        foreach (array_keys($response->headers) as $name) {
            if (strcasecmp($name, 'Content-Length') === 0) {
                $clCount++;
            }
        }
        self::assertSame(1, $clCount, 'Exactly one Content-Length header must remain.');
    }

    public function testVaryAlreadyContainingAcceptEncodingIsNotDuplicated(): void
    {
        $handler = $this->makeHandler();
        // Pre-existing Vary already lists Accept-Encoding (differently cased) — the merge
        // must dedup and not append a second occurrence.
        $response = (new Response())
            ->header('Content-Type', 'application/json')
            ->header('Vary', 'Origin, accept-encoding')
            ->body($this->largeBody());

        $this->invokeCompress($handler, $this->makeRequest(), $response);

        $vary = $this->lcHeaders($response)['vary'] ?? '';
        self::assertStringContainsString('Origin', $vary);
        // Only one Accept-Encoding token, regardless of case.
        self::assertSame(
            1,
            substr_count(strtolower($vary), 'accept-encoding'),
            "Vary must not duplicate Accept-Encoding (got: {$vary})",
        );
    }

    public function testMissingContentTypeIsNotCompressed(): void
    {
        $handler = $this->makeHandler();
        $response = (new Response())->body($this->largeBody());

        $this->invokeCompress($handler, $this->makeRequest(), $response);

        self::assertArrayNotHasKey('content-encoding', $this->lcHeaders($response));
    }

    public function testExistingVaryHeaderIsPreservedAndAppended(): void
    {
        $handler = $this->makeHandler();
        $response = (new Response())
            ->header('Content-Type', 'application/json')
            ->header('Vary', 'Origin')
            ->body($this->largeBody());

        $this->invokeCompress($handler, $this->makeRequest(), $response);

        $vary = $this->lcHeaders($response)['vary'] ?? '';
        self::assertStringContainsString('Origin', $vary);
        self::assertStringContainsStringIgnoringCase('accept-encoding', $vary);
    }

    // --- helper: isCompressibleType() --------------------------------------

    private function invokeIsCompressible(?string $type): bool
    {
        $m = new \ReflectionMethod(HttpHandler::class, 'isCompressibleType');
        $m->setAccessible(true);
        $result = $m->invoke(null, $type);
        self::assertIsBool($result);

        return $result;
    }

    public function testIsCompressibleTypeNullAndEmpty(): void
    {
        self::assertFalse($this->invokeIsCompressible(null));
        self::assertFalse($this->invokeIsCompressible(''));
        self::assertFalse($this->invokeIsCompressible('; charset=utf-8'));
    }
}
