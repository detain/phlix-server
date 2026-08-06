<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Workerman;

use Phlix\Auth\AuthManager;
use Phlix\Auth\SignedUrl;
use Phlix\Media\Storage\ArtworkStorage;
use Phlix\Media\Storage\AvatarStorage;
use Phlix\Media\Transcoding\SegmentProcessRegistry;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\FastPath\PreRouterFastPaths;
use Phlix\Server\Http\RequestAuthenticator;
use Phlix\Server\Http\RequestContext;
use Phlix\Server\Http\Response;
use Phlix\Server\Workerman\HttpHandler;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request as WorkermanRequest;
use Workerman\Protocols\Http\Response as WorkermanResponse;

/**
 * The Workerman daemon's half of S238: `HttpHandler::__invoke()` must still serve
 * artwork and avatars ITSELF, before the router.
 *
 * ## Why this file exists
 *
 * S238 moved the two endpoints out of `HttpHandler` into
 * {@see PreRouterFastPaths} so the hub relay could serve them too. The endpoints'
 * own contract is covered against that class directly
 * ({@see \Phlix\Tests\Unit\Server\Http\FastPath\PreRouterFastPathsArtworkTest}),
 * and the relay half by
 * {@see \Phlix\Tests\Unit\Hub\RelayImageDispatchTest} — but neither of those can
 * see the WIRING here. Before this file, deleting the fast-path block from
 * `__invoke()` reddened nothing at all: the pre-move tests reached the private
 * methods by reflection and never entered `__invoke()` either, so the direct-HTTP
 * wiring was unpinned both before and after the move.
 *
 * These tests therefore drive the real `__invoke()` with a recording connection
 * and assert on the bytes it hands back.
 */
final class HttpHandlerImageFastPathWiringTest extends TestCase
{
    private string $tempDir = '';
    private string $posterPath = '';
    private string $posterBytes = '';

    protected function setUp(): void
    {
        parent::setUp();
        RequestContext::setUserId(null);
        RequestContext::setProfileId(null);

        $this->tempDir = sys_get_temp_dir() . '/phlix-s238-wiring-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir . '/item-1', 0775, true);
        $this->posterPath = $this->tempDir . '/item-1/w342.jpg';
        $this->posterBytes = 'JPEGDATA-POSTER-FOR-THE-WIRING-TEST';
        file_put_contents($this->posterPath, $this->posterBytes);

        putenv('JWT_SECRET=s238-httphandler-wiring-secret-32-bytes!');
        SignedUrl::resetSharedForTesting();
    }

    protected function tearDown(): void
    {
        SignedUrl::resetSharedForTesting();
        putenv('JWT_SECRET');
        RequestContext::setUserId(null);
        RequestContext::setProfileId(null);
        RequestContext::clearCancelGroup();

        foreach (['/item-1/w342.jpg'] as $file) {
            @unlink($this->tempDir . $file);
        }
        @rmdir($this->tempDir . '/item-1');
        @rmdir($this->tempDir);

        parent::tearDown();
    }

    /**
     * THE WIRING: a signed artwork GET is answered by the fast path inside
     * `__invoke()`, and the application router is NEVER asked.
     *
     * RED ON REVERT: delete the `PreRouterFastPaths` block from `__invoke()` and
     * the router mock's `expects(never())` fires; so does the 200/bytes assertion,
     * because the mocked router answers nothing.
     */
    public function testASignedArtworkGetIsServedBeforeTheRouter(): void
    {
        $application = $this->createMock(Application::class);
        $application->expects(self::never())->method('dispatch');

        $sent = [];
        $connection = $this->makeConnection($sent);

        $minted = SignedUrl::fromEnv()->mint('/api/v1/artwork/item-1?size=w342');
        ($this->makeHandler($application))(
            $connection,
            new WorkermanRequest("GET {$minted} HTTP/1.1\r\nHost: localhost\r\n\r\n"),
        );

        self::assertCount(1, $sent);
        $response = $sent[0];
        self::assertInstanceOf(WorkermanResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/jpeg', $response->getHeader('Content-Type'));

        // The bytes are attached for event-loop streaming, not buffered — the
        // property Response::toWorkermanResponse() has to preserve.
        self::assertNotNull($response->file);
        self::assertSame($this->posterPath, $response->file['file']);
        self::assertSame($this->posterBytes, (string) file_get_contents($response->file['file']));
    }

    /**
     * An UNAUTHENTICATED artwork GET is refused by the fast path, still without
     * reaching the router. The 401 beside the 200 above is what shows the fast
     * path decides, rather than merely passing everything through.
     */
    public function testAnUnsignedArtworkGetIs401BeforeTheRouter(): void
    {
        $application = $this->createMock(Application::class);
        $application->expects(self::never())->method('dispatch');

        $sent = [];
        $connection = $this->makeConnection($sent);

        ($this->makeHandler($application))(
            $connection,
            new WorkermanRequest("GET /api/v1/artwork/item-1?size=w342 HTTP/1.1\r\nHost: localhost\r\n\r\n"),
        );

        self::assertCount(1, $sent);
        self::assertInstanceOf(WorkermanResponse::class, $sent[0]);
        self::assertSame(401, $sent[0]->getStatusCode());
    }

    /**
     * CONTROL: a NON-image GET still reaches the application router.
     *
     * Without this, a fast path that swallowed every request would pass both
     * tests above. It also pins that `couldHandle()` is not a catch-all.
     */
    public function testANonImageGetStillReachesTheRouter(): void
    {
        $application = $this->createMock(Application::class);
        $application->expects(self::once())
            ->method('dispatch')
            ->willReturn((new Response())->status(200)->text('SERVED-BY-THE-ROUTER'));

        $sent = [];
        $connection = $this->makeConnection($sent);

        ($this->makeHandler($application))(
            $connection,
            new WorkermanRequest("GET /api/v1/libraries HTTP/1.1\r\nHost: localhost\r\n\r\n"),
        );

        self::assertCount(1, $sent);
        self::assertInstanceOf(WorkermanResponse::class, $sent[0]);
        self::assertStringContainsString('SERVED-BY-THE-ROUTER', $sent[0]->rawBody());
    }

    /**
     * The image storages are pulled from the container ONLY for a request that is
     * actually one of these endpoints.
     *
     * The deleted private methods resolved theirs inside the matched branch, so
     * every unrelated `HttpHandler` caller was free of a dependency on them; a
     * `couldHandle()` that stopped guarding would reintroduce it for every
     * request in the process. The container here THROWS on those two ids, so an
     * unguarded resolve becomes a visible 500 rather than a silent extra lookup.
     */
    public function testTheImageStoragesAreNotResolvedForAnUnrelatedRequest(): void
    {
        $application = $this->createMock(Application::class);
        $application->method('dispatch')->willReturn((new Response())->status(204));

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturnCallback(
            static function (string $id): mixed {
                if ($id === ArtworkStorage::class || $id === AvatarStorage::class) {
                    throw new \RuntimeException('the image storages must not be resolved for ' . $id);
                }

                return $id === SegmentProcessRegistry::class ? new SegmentProcessRegistry() : null;
            },
        );

        $handler = new HttpHandler(
            $container,
            new RequestAuthenticator($this->createMock(AuthManager::class)),
            $this->tempDir . '/nonexistent-public',
            $application,
            null,
        );

        $sent = [];
        $connection = $this->makeConnection($sent);

        $handler($connection, new WorkermanRequest("GET /api/v1/libraries HTTP/1.1\r\nHost: localhost\r\n\r\n"));

        self::assertCount(1, $sent);
        self::assertInstanceOf(WorkermanResponse::class, $sent[0]);
        self::assertSame(
            204,
            $sent[0]->getStatusCode(),
            'A 500 here means the image storages were resolved for a request that is not an image request.',
        );
    }

    /**
     * @param list<mixed> $sent
     */
    private function makeConnection(array &$sent): TcpConnection
    {
        $sent = [];
        $connection = $this->createMock(TcpConnection::class);
        $connection->bytesRead = 0;
        $connection->bytesWritten = 0;
        $connection->method('send')->willReturnCallback(
            static function (mixed $data) use (&$sent): bool {
                $sent[] = $data;

                return true;
            }
        );

        return $connection;
    }

    private function makeHandler(Application $application): HttpHandler
    {
        $artwork = new ArtworkStorage($this->tempDir);
        $avatar = $this->createMock(AvatarStorage::class);
        $avatar->method('path')->willReturn(null);
        $registry = new SegmentProcessRegistry();

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturnCallback(
            static fn (string $id): mixed => match ($id) {
                ArtworkStorage::class => $artwork,
                AvatarStorage::class => $avatar,
                SegmentProcessRegistry::class => $registry,
                default => null,
            },
        );

        return new HttpHandler(
            $container,
            new RequestAuthenticator($this->createMock(AuthManager::class)),
            $this->tempDir . '/nonexistent-public',
            $application,
            null,
        );
    }
}
