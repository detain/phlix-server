<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\WebPortal;

use Phlix\Auth\SignedUrl;
use Phlix\Media\Storage\ArtworkStorage;
use Phlix\Server\Http\Request;
use Phlix\Auth\AuthManager;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Markers\MarkerService;
use Phlix\Media\Markers\PlaybackMarkerService;
use Phlix\Session\PlaybackController;
use Phlix\Session\SessionManager;
use Phlix\Server\WebPortal\WebPortalRouter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * S73 — GET /api/v1/people/{personId}/photo endpoint contract.
 *
 * Pins the four load-bearing properties of the new rail:
 *   1. parse-before-lookup: personId shape and the `w` ladder answer 400
 *      WITHOUT touching the storage (the 400-before-lookup landmine);
 *   2. auth is handler-side and identical to the artwork rail (session user
 *      or a valid scan-time signature; anonymous-unsigned gets 401);
 *   3. miss → lazy resize-then-cache → flat 404 JSON, same shape as before;
 *   4. cache semantics (ETag, Last-Modified, If-None-Match → 304, immutable
 *      Cache-Control) survive on this route because it shares the extracted
 *      ArtworkByteResponder with /api/v1/artwork/{key}.
 */
final class WebPortalRouterPersonPhotoTest extends TestCase
{
    private string $tmpDir;
    private string $jpegPath;

    protected function setUp(): void
    {
        parent::setUp();
        SignedUrl::resetSharedForTesting();

        $this->tmpDir = sys_get_temp_dir() . '/person-photo-test-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0755, true);

        $this->jpegPath = $this->tmpDir . '/people-77-w185.jpg';
        file_put_contents($this->jpegPath, 'PERSON-JPEG-BYTES');
        touch($this->jpegPath, 1_700_000_000);
    }

    protected function tearDown(): void
    {
        SignedUrl::resetSharedForTesting();
        $this->removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    private function router(?ArtworkStorage $storage): WebPortalRouter
    {
        return new WebPortalRouter(
            $this->createMock(LibraryManager::class),
            $this->createMock(ItemRepository::class),
            $this->createMock(SessionManager::class),
            $this->createMock(PlaybackController::class),
            $this->createMock(AuthManager::class),
            $this->createMock(PlaybackMarkerService::class),
            $this->createMock(MarkerService::class),
            artworkStorage: $storage,
        );
    }

    /**
     * @param array<string, string> $query
     */
    private function request(string $path, array $query = [], ?string $userId = 'u1'): Request
    {
        $request = new Request();
        $request->method = 'GET';
        $request->path = $path;
        $request->userId = $userId;
        $request->query = $query;

        return $request;
    }

    /** @return array{0: string, 1: array<string, string>} path + parsed query of a minted signed URL */
    private function signed(string $pathWithQuery): array
    {
        $minted = SignedUrl::fromEnv()->mint($pathWithQuery);
        [$pathOnly, $query] = array_pad(explode('?', $minted, 2), 2, '');
        parse_str($query, $parsed);

        return [$pathOnly, array_map('strval', $parsed)];
    }

    private function storageWithVariant(): MockObject&ArtworkStorage
    {
        $storage = $this->createMock(ArtworkStorage::class);
        $storage->method('variantPath')->willReturnCallback(
            fn (string $key, string $size): ?string =>
                $key === 'people-77' && $size === 'w185' ? $this->jpegPath : null,
        );

        return $storage;
    }

    // -----------------------------------------------------------------
    // 1. wiring + parse-before-lookup
    // -----------------------------------------------------------------

    public function testUnwiredStorageAnswers503Not404(): void
    {
        $response = $this->router(null)->dispatch(
            $this->request('/api/v1/people/77/photo', ['w' => '185']),
        );

        self::assertSame(503, $response->statusCode, 'unwired storage must not read as "person has no photo"');
    }

    public function testNonNumericPersonIdIsRefusedWithStorageUntouched(): void
    {
        $storage = $this->createMock(ArtworkStorage::class);
        $storage->expects(self::never())->method('variantPath');
        $storage->expects(self::never())->method('ensureVariant');

        // '{personId}' matches [^/]+ on the RAW path, so %2F-encoded traversal
        // survives pattern matching, is decoded ONCE at the routing boundary,
        // and must be refused by the handler's digit gate before any lookup.
        $response = $this->router($storage)->dispatch(
            $this->request('/api/v1/people/..%2F..%2Fetc%2Fpasswd/photo', ['w' => '185']),
        );

        self::assertSame(400, $response->statusCode);
    }

    public function testOffLadderOrMissingWidthIs400BeforeAnyLookup(): void
    {
        foreach ([[], ['w' => '999'], ['w' => 'abc'], ['w' => '185x']] as $case => $query) {
            $storage = $this->createMock(ArtworkStorage::class);
            $storage->expects(self::never())->method('variantPath');
            $storage->expects(self::never())->method('ensureVariant');

            $response = $this->router($storage)->dispatch(
                $this->request('/api/v1/people/77/photo', $query),
            );

            self::assertSame(400, $response->statusCode, "width case {$case} must be a 400 before lookup");
        }
    }

    // -----------------------------------------------------------------
    // 2. auth parity with the artwork rail
    // -----------------------------------------------------------------

    public function testAnonymousUnsignedRequestIsRejectedBeforeAnyLookup(): void
    {
        $storage = $this->createMock(ArtworkStorage::class);
        $storage->expects(self::never())->method('variantPath');
        $storage->expects(self::never())->method('ensureVariant');

        $response = $this->router($storage)->dispatch(
            $this->request('/api/v1/people/77/photo', ['w' => '185'], null),
        );

        self::assertSame(401, $response->statusCode);
    }

    public function testValidSignedUrlGrantsAccessWithoutASession(): void
    {
        [$path, $query] = $this->signed('/api/v1/people/77/photo?w=185');

        $response = $this->router($this->storageWithVariant())->dispatch(
            $this->request($path, $query, null),
        );

        self::assertSame(200, $response->statusCode);
    }

    public function testTamperedSignatureIsRejected(): void
    {
        [$path, $query] = $this->signed('/api/v1/people/77/photo?w=185');
        $query['sig'] = str_repeat('0', strlen((string) $query['sig']));

        $storage = $this->createMock(ArtworkStorage::class);
        $storage->expects(self::never())->method('variantPath');

        $response = $this->router($storage)->dispatch($this->request($path, $query, null));

        self::assertSame(401, $response->statusCode);
    }

    // -----------------------------------------------------------------
    // 3. miss semantics: hit, lazy arm, flat 404
    // -----------------------------------------------------------------

    public function testSessionUserGetsStoredPeopleVariantThroughFlatKey(): void
    {
        $storage = $this->storageWithVariant();
        $storage->expects(self::never())->method('ensureVariant');

        $response = $this->router($storage)->dispatch(
            $this->request('/api/v1/people/77/photo', ['w' => '185']),
        );

        self::assertSame(200, $response->statusCode);
        self::assertSame($this->jpegPath, $response->filePath);
        self::assertSame('image/jpeg', $response->headers['Content-Type'] ?? null);
    }

    public function testVariantMissConsultsTheLazyResizeArmOnce(): void
    {
        $storage = $this->createMock(ArtworkStorage::class);
        $storage->method('variantPath')->willReturn(null);
        $storage->expects(self::once())->method('ensureVariant')
            ->with('people-77', 'w185')
            ->willReturn($this->jpegPath);

        $response = $this->router($storage)->dispatch(
            $this->request('/api/v1/people/77/photo', ['w' => '185']),
        );

        self::assertSame(200, $response->statusCode);
    }

    public function testStillMissingAfterLazyArmYieldsTheSameFlat404Json(): void
    {
        $storage = $this->createMock(ArtworkStorage::class);
        $storage->method('variantPath')->willReturn(null);
        $storage->method('ensureVariant')->willReturn(null);

        $response = $this->router($storage)->dispatch(
            $this->request('/api/v1/people/77/photo', ['w' => '185']),
        );

        self::assertSame(404, $response->statusCode);
        self::assertStringStartsWith('application/json', (string) ($response->headers['Content-Type'] ?? ''));
        self::assertStringContainsString('Artwork not found', (string) $response->body);
    }

    // -----------------------------------------------------------------
    // 4. cache semantics on the NEW route (shared responder)
    // -----------------------------------------------------------------

    public function testValidatorsAreEmittedAndIfNoneMatchYields304(): void
    {
        $stat = stat($this->jpegPath);
        self::assertNotFalse($stat);
        $etag = sprintf('"%x-%x"', $stat['size'], $stat['mtime']);

        $fresh = $this->router($this->storageWithVariant())->dispatch(
            $this->request('/api/v1/people/77/photo', ['w' => '185']),
        );
        self::assertSame(200, $fresh->statusCode);
        self::assertSame($etag, $fresh->headers['ETag'] ?? null);
        self::assertSame('public, max-age=31536000, immutable', $fresh->headers['Cache-Control'] ?? null);
        self::assertNotFalse(strtotime((string) ($fresh->headers['Last-Modified'] ?? false)));

        $conditional = $this->router($this->storageWithVariant())->dispatch(
            (function (Request $r) use ($etag): Request {
                $r->headers = ['If-None-Match' => $etag];

                return $r;
            })($this->request('/api/v1/people/77/photo', ['w' => '185'])),
        );

        self::assertSame(304, $conditional->statusCode);
        self::assertNull($conditional->filePath, 'a 304 must not attach the file');
        self::assertSame('', (string) $conditional->body);
        // Validators are echoed on 304s too.
        self::assertSame($etag, $conditional->headers['ETag'] ?? null);
        self::assertSame('public, max-age=31536000, immutable', $conditional->headers['Cache-Control'] ?? null);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
