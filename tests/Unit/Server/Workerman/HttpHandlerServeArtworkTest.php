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
 * Authoritative end-to-end route test for GET /api/v1/artwork/{id}?size=.
 *
 * SV-3.4 sub-4 (first block below) covers conditional caching: the existing
 * ETag ("<size>-<mtime>" hex) + immutable Cache-Control are preserved; a
 * matching If-None-Match (or, as a fallback, an up-to-date If-Modified-Since)
 * returns 304 with no body. Freshness is decided AFTER the signed-URL auth gate,
 * the size-variant validation and the 404 existence check, so a stale/missing
 * validator still streams the full file (200).
 *
 * SV-3.4 sub-7 (second block below) is the comprehensive route contract that the
 * sub-4 block only touched incidentally — it exhaustively pins down, in the
 * handler's real evaluation order (size-validation → auth → 404 → freshness):
 *  - signed-URL auth: valid signature → 200 with the requested variant file;
 *    missing / tampered / expired signature → 401;
 *  - session auth: a resolved (non-empty) userId serves WITHOUT any signature,
 *    while an empty-string userId falls back to (and fails) signed-URL auth;
 *  - size validation: each supported width (185/342/500/780) + `original`
 *    serves its own variant, an omitted size defaults to `original`, and an
 *    unsupported size is a clean 400 — decided BEFORE the auth gate;
 *  - 404: an uncached (null) variant or a missing variant file → 404 JSON, not
 *    a crash or an empty 200.
 * The 304/ETag paths are NOT re-tested here (owned by the sub-4 block above).
 */
final class HttpHandlerServeArtworkTest extends TestCase
{
    private string $artworkPath = '';

    /** @var list<string> Extra temp artwork files created per-test; cleaned in tearDown. */
    private array $tempFiles = [];

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
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
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

    // ------------------------------------------------------------------
    // SV-3.4 sub-7: dedicated route contract — signed-URL/session auth,
    // size validation and 404. (ETag/304 owned by the sub-4 block above.)
    // ------------------------------------------------------------------

    /**
     * Builds a handler whose ArtworkStorage returns a distinct variant path per
     * requested size, so a test can assert the route serves the RIGHT variant.
     *
     * @param array<string, ?string> $sizeToPath size => variant path (null = no cached variant)
     */
    private function makeHandlerForSizes(array $sizeToPath): HttpHandler
    {
        $storage = $this->createMock(ArtworkStorage::class);
        $storage->method('variantPath')->willReturnCallback(
            static function (string $itemId, string $size) use ($sizeToPath): ?string {
                return $sizeToPath[$size] ?? null;
            }
        );

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

    /** Invoke the private serveArtwork with an explicit resolved-session userId. */
    private function invokeAs(HttpHandler $handler, WorkermanRequest $wr, ?string $userId): ?WorkermanResponse
    {
        $m = new \ReflectionMethod(HttpHandler::class, 'serveArtwork');
        $m->setAccessible(true);
        /** @var WorkermanResponse|null $result */
        $result = $m->invoke($handler, $wr, $userId);

        return $result;
    }

    /** A request carrying NO exp/sig token (pairs with session auth or a 401). */
    private function unsignedRequest(string $id, string $size): WorkermanRequest
    {
        return new WorkermanRequest(
            "GET /api/v1/artwork/{$id}?size={$size} HTTP/1.1\r\nHost: localhost\r\n\r\n"
        );
    }

    /** Creates (and tracks for cleanup) a real, readable temp artwork variant. */
    private function makeTempArtwork(string $tag): string
    {
        $path = sys_get_temp_dir() . '/phlix-artwork-' . $tag . '-' . bin2hex(random_bytes(4)) . '.jpg';
        file_put_contents($path, 'JPEGDATA-' . $tag);
        touch($path, 1_700_000_000);
        $this->tempFiles[] = $path;

        return $path;
    }

    public function testNonGetMethodFallsThroughToRouter(): void
    {
        // A non-GET verb is not this route's concern — serveArtwork returns null
        // so the request falls through to the main router (never 401/404 here).
        $handler = $this->makeHandler($this->artworkPath);

        $resp = $this->invoke(
            $handler,
            new WorkermanRequest("POST /api/v1/artwork/item-1?size=w500 HTTP/1.1\r\nHost: localhost\r\n\r\n")
        );

        self::assertNull($resp);
    }

    public function testNonArtworkPathFallsThroughToRouter(): void
    {
        // A GET that isn't the artwork route → null passthrough, not a 404 that
        // would shadow whatever the router owns for that path.
        $handler = $this->makeHandler($this->artworkPath);

        $resp = $this->invoke(
            $handler,
            new WorkermanRequest("GET /api/v1/media/item-1 HTTP/1.1\r\nHost: localhost\r\n\r\n")
        );

        self::assertNull($resp);
    }

    public function testValidSignedUrlServesRequestedVariantFile(): void
    {
        // Distinct file per size proves the route resolves the RIGHT variant for
        // the requested ?size= (not just "some 200").
        $variant = $this->makeTempArtwork('w342');
        $handler = $this->makeHandlerForSizes(['w342' => $variant]);

        $resp = $this->invoke($handler, $this->signedRequest('item-1', 'w342'));

        self::assertInstanceOf(WorkermanResponse::class, $resp);
        self::assertSame(200, $resp->getStatusCode());
        self::assertNotNull($resp->file);
        self::assertSame($variant, $resp->file['file']);
        self::assertSame('image/jpeg', $resp->getHeader('Content-Type'));
        self::assertSame('public, max-age=31536000, immutable', $resp->getHeader('Cache-Control'));
    }

    public function testMissingSignatureIsRejectedWith401(): void
    {
        // No session, no exp/sig → the signed-URL gate fails closed.
        $handler = $this->makeHandler($this->artworkPath);

        $resp = $this->invoke($handler, $this->unsignedRequest('item-1', 'w500'));

        self::assertInstanceOf(WorkermanResponse::class, $resp);
        self::assertSame(401, $resp->getStatusCode());
        self::assertSame('Unauthorized', $resp->rawBody());
        self::assertSame('text/plain; charset=utf-8', $resp->getHeader('Content-Type'));
    }

    public function testTamperedSignatureIsRejectedWith401(): void
    {
        // Flip the first character of an otherwise-valid signature — hash_equals
        // must reject it (constant-time compare, no substring/prefix bypass).
        $handler = $this->makeHandler($this->artworkPath);

        $minted = SignedUrl::fromEnv()->mint('/api/v1/artwork/item-1?size=w500');
        $tampered = preg_replace_callback(
            '/([?&]sig=)([^&]+)/',
            /** @param array<int, string> $m */
            static function (array $m): string {
                $sig = $m[2];
                $sig[0] = $sig[0] === 'A' ? 'B' : 'A';

                return $m[1] . $sig;
            },
            $minted,
        );
        self::assertIsString($tampered);
        self::assertNotSame($minted, $tampered);

        $resp = $this->invoke(
            $handler,
            new WorkermanRequest("GET {$tampered} HTTP/1.1\r\nHost: localhost\r\n\r\n")
        );

        self::assertInstanceOf(WorkermanResponse::class, $resp);
        self::assertSame(401, $resp->getStatusCode());
        self::assertSame('Unauthorized', $resp->rawBody());
    }

    public function testExpiredSignatureIsRejectedWith401(): void
    {
        // Mint with "now" far in the past so exp = pastNow + ttl is already
        // elapsed by the time the (real-clock) verifier runs.
        $handler = $this->makeHandler($this->artworkPath);

        $pastNow = time() - 1_000_000;
        $minted = SignedUrl::fromEnv()->mint('/api/v1/artwork/item-1?size=w500', null, $pastNow);

        $resp = $this->invoke(
            $handler,
            new WorkermanRequest("GET {$minted} HTTP/1.1\r\nHost: localhost\r\n\r\n")
        );

        self::assertInstanceOf(WorkermanResponse::class, $resp);
        self::assertSame(401, $resp->getStatusCode());
        self::assertSame('Unauthorized', $resp->rawBody());
    }

    public function testValidSessionServesWithoutAnySignature(): void
    {
        // A resolved (non-empty) session userId is an alternative to signed URLs:
        // the request carries NO exp/sig yet still serves 200.
        $handler = $this->makeHandler($this->artworkPath);

        $resp = $this->invokeAs($handler, $this->unsignedRequest('item-1', 'w500'), 'user-abc-123');

        self::assertInstanceOf(WorkermanResponse::class, $resp);
        self::assertSame(200, $resp->getStatusCode());
        self::assertNotNull($resp->file);
        self::assertSame($this->artworkPath, $resp->file['file']);
    }

    public function testEmptyStringUserIdFallsBackToSignedUrlAuth(): void
    {
        // userId === '' is treated as "no session" (the `|| $userId === ''`
        // branch), so an unsigned request still fails the signed-URL gate.
        $handler = $this->makeHandler($this->artworkPath);

        $resp = $this->invokeAs($handler, $this->unsignedRequest('item-1', 'w500'), '');

        self::assertInstanceOf(WorkermanResponse::class, $resp);
        self::assertSame(401, $resp->getStatusCode());
    }

    public function testInvalidSizeIsRejectedWith400(): void
    {
        // A validly-signed request with an unsupported size is a clean 400 — the
        // size check never falls through to a 500 or a default variant.
        $handler = $this->makeHandler($this->artworkPath);

        $resp = $this->invoke($handler, $this->signedRequest('item-1', 'jumbo'));

        self::assertInstanceOf(WorkermanResponse::class, $resp);
        self::assertSame(400, $resp->getStatusCode());
        self::assertStringContainsString('Invalid size parameter', $resp->rawBody());
        self::assertSame('application/json; charset=utf-8', $resp->getHeader('Content-Type'));
    }

    public function testInvalidSizeIsRejectedBeforeAuthGate(): void
    {
        // Ordering guard: size validation runs BEFORE the auth gate, so an
        // UNSIGNED request with a bad size gets 400 (not 401). This pins the
        // handler's real precedence so a future reorder can't turn the size
        // check into an auth oracle.
        $handler = $this->makeHandler($this->artworkPath);

        $resp = $this->invoke($handler, $this->unsignedRequest('item-1', '999'));

        self::assertInstanceOf(WorkermanResponse::class, $resp);
        self::assertSame(400, $resp->getStatusCode());
    }

    public function testEachSupportedSizeServesItsOwnVariant(): void
    {
        // Every real supported size (ArtworkStorage::WIDTHS + `original`) resolves
        // to and serves its OWN cached variant file.
        $map = [
            'w185'     => $this->makeTempArtwork('w185'),
            'w342'     => $this->makeTempArtwork('w342'),
            'w500'     => $this->makeTempArtwork('w500'),
            'w780'     => $this->makeTempArtwork('w780'),
            'original' => $this->makeTempArtwork('original'),
        ];
        $handler = $this->makeHandlerForSizes($map);

        foreach ($map as $size => $expectedFile) {
            $resp = $this->invokeAs($handler, $this->unsignedRequest('item-1', $size), 'user-1');

            self::assertInstanceOf(WorkermanResponse::class, $resp, "size {$size}");
            self::assertSame(200, $resp->getStatusCode(), "size {$size}");
            self::assertNotNull($resp->file, "size {$size}");
            self::assertSame($expectedFile, $resp->file['file'], "size {$size}");
        }
    }

    public function testOmittedSizeDefaultsToOriginalVariant(): void
    {
        // No ?size= at all → the handler defaults to 'original' and serves that
        // variant. `w500` is mapped too but must NOT be chosen.
        $original = $this->makeTempArtwork('original');
        $handler = $this->makeHandlerForSizes([
            'original' => $original,
            'w500'     => $this->makeTempArtwork('w500'),
        ]);

        $req = new WorkermanRequest(
            "GET /api/v1/artwork/item-1 HTTP/1.1\r\nHost: localhost\r\n\r\n"
        );
        $resp = $this->invokeAs($handler, $req, 'user-1');

        self::assertInstanceOf(WorkermanResponse::class, $resp);
        self::assertSame(200, $resp->getStatusCode());
        self::assertNotNull($resp->file);
        self::assertSame($original, $resp->file['file']);
    }

    public function testUncachedVariantReturns404JsonNotEmpty200(): void
    {
        // No cached artwork for this item (variantPath === null) → a clean 404
        // JSON, never an empty 200. Session-authed to isolate the 404 branch.
        $handler = $this->makeHandlerForSizes(['w500' => null]);

        $resp = $this->invokeAs($handler, $this->unsignedRequest('no-art', 'w500'), 'user-1');

        self::assertInstanceOf(WorkermanResponse::class, $resp);
        self::assertSame(404, $resp->getStatusCode());
        self::assertStringContainsString('Artwork not found', $resp->rawBody());
        self::assertSame('application/json; charset=utf-8', $resp->getHeader('Content-Type'));
    }

    public function testMissingVariantFileReturns404JsonBody(): void
    {
        // variantPath resolves to a path whose file does not exist on disk → 404
        // JSON. (The sub-4 block covers the same 404 WITH a conditional header;
        // this pins the plain-request body shape.)
        $missing = sys_get_temp_dir() . '/phlix-artwork-gone-' . bin2hex(random_bytes(4)) . '.jpg';
        $handler = $this->makeHandler($missing);

        $resp = $this->invokeAs($handler, $this->unsignedRequest('item-1', 'w500'), 'user-1');

        self::assertInstanceOf(WorkermanResponse::class, $resp);
        self::assertSame(404, $resp->getStatusCode());
        self::assertStringContainsString('Artwork not found', $resp->rawBody());
    }
}
