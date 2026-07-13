<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Workerman;

use Phlix\Auth\AuthManager;
use Phlix\Auth\SignedUrl;
use Phlix\Media\Storage\ArtworkStorage;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\RequestAuthenticator;
use Phlix\Server\Workerman\HttpHandler;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Workerman\Protocols\Http\Request as WorkermanRequest;
use Workerman\Protocols\Http\Response as WorkermanResponse;

/**
 * @covers \Phlix\Server\Workerman\HttpHandler
 *
 * SV-3.4 sub-4: conditional caching on GET /api/v1/artwork/{id}?size=.
 * The existing ETag ("<size>-<mtime>" hex) + immutable Cache-Control are
 * preserved; a matching If-None-Match (or, as a fallback, an up-to-date
 * If-Modified-Since) now returns 304 with no body. Freshness is decided AFTER
 * the signed-URL auth gate, the size-variant validation and the 404 existence
 * check, so a stale/missing validator still streams the full file (200).
 */
final class HttpHandlerServeArtworkTest extends TestCase
{
    private string $artworkPath = '';

    protected function setUp(): void
    {
        SignedUrl::resetSharedForTesting();
        $this->artworkPath = sys_get_temp_dir() . '/phlix-artwork-' . bin2hex(random_bytes(6)) . '.jpg';
        file_put_contents($this->artworkPath, 'JPEGDATA-ARTWORK-BYTES');
        // Pin a stable mtime so the validators are deterministic.
        touch($this->artworkPath, 1_700_000_000);
    }

    protected function tearDown(): void
    {
        if ($this->artworkPath !== '' && is_file($this->artworkPath)) {
            @unlink($this->artworkPath);
        }
        SignedUrl::resetSharedForTesting();
    }

    private function makeHandler(?string $variantPath): HttpHandler
    {
        $storage = $this->createMock(ArtworkStorage::class);
        $storage->method('variantPath')->willReturn($variantPath);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($storage);

        return new HttpHandler(
            $container,
            new RequestAuthenticator($this->createMock(AuthManager::class)),
            sys_get_temp_dir(),
            $this->createMock(Application::class),
            null,
        );
    }

    private function invoke(HttpHandler $handler, WorkermanRequest $wr): ?WorkermanResponse
    {
        $m = new \ReflectionMethod(HttpHandler::class, 'serveArtwork');
        $m->setAccessible(true);
        /** @var WorkermanResponse|null $result */
        $result = $m->invoke($handler, $wr, null);

        return $result;
    }

    /** @param array<string, string> $headers */
    private function signedRequest(string $id, string $size, array $headers = []): WorkermanRequest
    {
        // userId=null path → signed-URL auth. The signature is over the canonical
        // resource (query stripped), so the ?size= param does not affect verify.
        $minted = SignedUrl::fromEnv()->mint('/api/v1/artwork/' . $id . '?size=' . $size);
        $extra = '';
        foreach ($headers as $name => $value) {
            $extra .= "{$name}: {$value}\r\n";
        }

        return new WorkermanRequest(
            "GET {$minted} HTTP/1.1\r\nHost: localhost\r\n{$extra}\r\n"
        );
    }

    private function expectedEtag(): string
    {
        $stat = stat($this->artworkPath);
        self::assertNotFalse($stat);

        return sprintf('"%x-%x"', $stat['size'], $stat['mtime']);
    }

    private function expectedLastModified(): string
    {
        return gmdate('D, d M Y H:i:s', (int) filemtime($this->artworkPath)) . ' GMT';
    }

    public function testNoConditionalHeadersServesFullBodyWithValidators(): void
    {
        $handler = $this->makeHandler($this->artworkPath);

        $resp = $this->invoke($handler, $this->signedRequest('item-1', 'w500'));

        self::assertInstanceOf(WorkermanResponse::class, $resp);
        self::assertSame(200, $resp->getStatusCode());
        // Full body: the file is attached for streaming via withFile().
        self::assertNotNull($resp->file);
        self::assertSame($this->artworkPath, $resp->file['file']);
        self::assertSame('image/jpeg', $resp->getHeader('Content-Type'));
        self::assertSame($this->expectedEtag(), $resp->getHeader('ETag'));
        self::assertSame($this->expectedLastModified(), $resp->getHeader('Last-Modified'));
        // Existing immutable-cache behavior is preserved.
        self::assertSame('public, max-age=31536000, immutable', $resp->getHeader('Cache-Control'));
    }

    public function testMatchingIfNoneMatchYields304WithEmptyBody(): void
    {
        $handler = $this->makeHandler($this->artworkPath);

        $resp = $this->invoke($handler, $this->signedRequest('item-1', 'w500', [
            'If-None-Match' => $this->expectedEtag(),
        ]));

        self::assertInstanceOf(WorkermanResponse::class, $resp);
        self::assertSame(304, $resp->getStatusCode());
        // Empty body: no file attached, no raw body.
        self::assertNull($resp->file);
        self::assertSame('', $resp->rawBody());
        // Validators + immutable-cache are still echoed on the 304.
        self::assertSame($this->expectedEtag(), $resp->getHeader('ETag'));
        self::assertSame($this->expectedLastModified(), $resp->getHeader('Last-Modified'));
        self::assertSame('public, max-age=31536000, immutable', $resp->getHeader('Cache-Control'));
    }

    public function testStaleIfNoneMatchServesFullBody(): void
    {
        $handler = $this->makeHandler($this->artworkPath);

        $resp = $this->invoke($handler, $this->signedRequest('item-1', 'w500', [
            'If-None-Match' => '"deadbeef-1"',
        ]));

        self::assertInstanceOf(WorkermanResponse::class, $resp);
        self::assertSame(200, $resp->getStatusCode());
        self::assertNotNull($resp->file);
        self::assertSame($this->expectedEtag(), $resp->getHeader('ETag'));
    }

    public function testUpToDateIfModifiedSinceYields304(): void
    {
        $handler = $this->makeHandler($this->artworkPath);

        // A time AT or AFTER the file mtime → not modified. No If-None-Match, so
        // the Last-Modified fallback path is exercised.
        $ims = gmdate('D, d M Y H:i:s', (int) filemtime($this->artworkPath) + 60) . ' GMT';
        $resp = $this->invoke($handler, $this->signedRequest('item-1', 'w500', [
            'If-Modified-Since' => $ims,
        ]));

        self::assertInstanceOf(WorkermanResponse::class, $resp);
        self::assertSame(304, $resp->getStatusCode());
        self::assertNull($resp->file);
        self::assertSame('', $resp->rawBody());
        self::assertSame($this->expectedLastModified(), $resp->getHeader('Last-Modified'));
    }

    public function testStaleIfModifiedSinceServesFullBody(): void
    {
        $handler = $this->makeHandler($this->artworkPath);

        // A time BEFORE the file mtime → modified → full body.
        $ims = gmdate('D, d M Y H:i:s', (int) filemtime($this->artworkPath) - 3600) . ' GMT';
        $resp = $this->invoke($handler, $this->signedRequest('item-1', 'w500', [
            'If-Modified-Since' => $ims,
        ]));

        self::assertInstanceOf(WorkermanResponse::class, $resp);
        self::assertSame(200, $resp->getStatusCode());
        self::assertNotNull($resp->file);
    }

    public function testIfNoneMatchTakesPriorityOverIfModifiedSince(): void
    {
        $handler = $this->makeHandler($this->artworkPath);

        // ETag mismatch must win even when If-Modified-Since would say "fresh" —
        // If-None-Match is authoritative, so this serves the full body.
        $ims = gmdate('D, d M Y H:i:s', (int) filemtime($this->artworkPath) + 60) . ' GMT';
        $resp = $this->invoke($handler, $this->signedRequest('item-1', 'w500', [
            'If-None-Match' => '"stale-tag"',
            'If-Modified-Since' => $ims,
        ]));

        self::assertInstanceOf(WorkermanResponse::class, $resp);
        self::assertSame(200, $resp->getStatusCode());
        self::assertNotNull($resp->file);
    }

    public function testConditionalCheckRunsAfterAuthGate(): void
    {
        $handler = $this->makeHandler($this->artworkPath);

        // Unsigned request carrying a (would-be) matching validator must NOT get a
        // 304 — auth is evaluated before freshness. userId=null + no sig → 401.
        $req = new WorkermanRequest(
            "GET /api/v1/artwork/item-1?size=w500 HTTP/1.1\r\nHost: localhost\r\n"
            . 'If-None-Match: ' . $this->expectedEtag() . "\r\n\r\n"
        );
        $resp = $this->invoke($handler, $req);

        self::assertInstanceOf(WorkermanResponse::class, $resp);
        self::assertSame(401, $resp->getStatusCode());
    }

    public function testConditionalCheckRunsAfterExistenceCheck(): void
    {
        // variantPath resolves to a missing file → 404 even with a conditional
        // header present (freshness is only decided for a servable request).
        $missing = sys_get_temp_dir() . '/phlix-artwork-missing-' . bin2hex(random_bytes(4)) . '.jpg';
        $handler = $this->makeHandler($missing);

        $resp = $this->invoke($handler, $this->signedRequest('item-1', 'w500', [
            'If-None-Match' => '"anything"',
        ]));

        self::assertInstanceOf(WorkermanResponse::class, $resp);
        self::assertSame(404, $resp->getStatusCode());
    }
}
