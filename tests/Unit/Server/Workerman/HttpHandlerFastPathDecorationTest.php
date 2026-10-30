<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Workerman;

use Phlix\Auth\AuthManager;
use Phlix\Auth\SignedUrl;
use Phlix\Media\Storage\ArtworkStorage;
use Phlix\Media\Storage\AvatarStorage;
use Phlix\Media\Transcoding\SegmentProcessRegistry;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\Middleware\CorsManager;
use Phlix\Server\Http\RequestContext;
use Phlix\Server\Http\Response;
use Phlix\Server\Workerman\BodylessResponse;
use Phlix\Server\Workerman\HttpHandler;
use Phlix\Server\Http\RequestAuthenticator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request as WorkermanRequest;
use Workerman\Protocols\Http\Response as WorkermanResponse;

/**
 * minors M-SERVER-50 (W-min101): the early-exit arms of `HttpHandler::__invoke()`
 * must carry the SAME security-header + CORS decoration as dispatched replies.
 *
 * Before this fix, the static-file arm, the CSRF-rejection 403 and the
 * pre-router fast-path arm each `send()`ed a reply that skipped both
 * {@see \Phlix\Server\Http\Middleware\SecurityHeaders} and
 * {@see \Phlix\Server\Http\Middleware\CorsManager} entirely, while the
 * CorsManager class docblock asserted every final response was decorated.
 * The consequences were concrete, not cosmetic:
 *
 *  - every static asset, avatar, artwork and stream reply shipped WITHOUT
 *    `X-Content-Type-Options: nosniff` (the fast-path avatar comment in
 *    `PreRouterFastPaths::serveUserAvatar()` even relies on the OLD absence
 *    of the header to explain why mis-typed avatars once rendered);
 *  - a cross-origin XHR from an allowlisted native client could not READ any
 *    fast-path/static reply (no ACAO echo), so the credentialed-token seam
 *    silently 404-ish-failed on exactly the byte-serving surfaces.
 *
 * The suite drives the real `__invoke()` with a recording connection (the
 * S113/S238 pattern) and asserts on the sent responses' headers.
 */
final class HttpHandlerFastPathDecorationTest extends TestCase
{
    private string $publicRoot = '';

    /** @var list<string> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        RequestContext::setUserId(null);
        RequestContext::setProfileId(null);

        $root = sys_get_temp_dir() . '/phlix-s50-' . bin2hex(random_bytes(6));
        mkdir($root . '/assets/app', 0o777, true);
        file_put_contents($root . '/assets/app/index-DaB12cd3.js', "console.log('x');\n");
        file_put_contents($root . '/robots.txt', "User-agent: *\n");
        // ArtworkStorage layout (variantPath = <root>/<itemId>/<size>.jpg), the
        // same shape HttpHandlerImageFastPathWiringTest seeds.
        mkdir($root . '/artwork-s50/item-s50', 0o777, true);
        file_put_contents($root . '/artwork-s50/item-s50/w342.jpg', 'JPEGDATA-S50');
        $this->created = [
            $root . '/assets/app/index-DaB12cd3.js',
            $root . '/robots.txt',
            $root . '/artwork-s50/item-s50/w342.jpg',
        ];
        $this->publicRoot = (string) realpath($root);

        putenv('JWT_SECRET=s50-decoration-secret-32-bytes-ok!!');
        SignedUrl::resetSharedForTesting();
    }

    protected function tearDown(): void
    {
        SignedUrl::resetSharedForTesting();
        putenv('JWT_SECRET');
        putenv(CorsManager::ENV_ALLOWED_ORIGINS);
        RequestContext::setUserId(null);
        RequestContext::setProfileId(null);
        RequestContext::clearCancelGroup();

        foreach ($this->created as $file) {
            @unlink($this->publicRoot . '/' . $file);
        }
        @rmdir($this->publicRoot . '/assets/app');
        @rmdir($this->publicRoot . '/assets');
        if ($this->publicRoot !== '') {
            @rmdir($this->publicRoot);
        }

        parent::tearDown();
    }

    // --- the static-file arm ---------------------------------------------------

    public function testStaticReplyCarriesTheFullSecurityHeaderSet(): void
    {
        $response = $this->invoke('GET', '/assets/app/index-DaB12cd3.js');

        self::assertInstanceOf(WorkermanResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('nosniff', $response->getHeader('X-Content-Type-Options'));
        self::assertSame('SAMEORIGIN', $response->getHeader('X-Frame-Options'));
        self::assertIsString($response->getHeader('Strict-Transport-Security'));
        self::assertIsString($response->getHeader('Content-Security-Policy'));
        // The pre-existing header set survives untouched — decoration adds, never replaces.
        self::assertSame('application/javascript; charset=utf-8', $response->getHeader('Content-Type'));
        self::assertStringContainsString('immutable', (string) $response->getHeader('Cache-Control'));
    }

    public function testStaticHeadReplyCarriesSecurityHeadersToo(): void
    {
        $response = $this->invoke('HEAD', '/robots.txt');

        self::assertInstanceOf(BodylessResponse::class, $response, 'the S113 head-only class must survive decoration');
        self::assertSame('nosniff', $response->getHeader('X-Content-Type-Options'));
    }

    public function testStaticReplyForAllowlistedOriginCarriesCredentialedCorsHeaders(): void
    {
        putenv(CorsManager::ENV_ALLOWED_ORIGINS . '=https://tv.example.com');

        $response = $this->invoke('GET', '/robots.txt', headers: ['Origin' => 'https://tv.example.com']);

        self::assertInstanceOf(WorkermanResponse::class, $response);
        self::assertSame('https://tv.example.com', $response->getHeader('Access-Control-Allow-Origin'));
        self::assertSame('true', $response->getHeader('Access-Control-Allow-Credentials'));
        self::assertSame('Origin', $response->getHeader('Vary'));
        self::assertSame('nosniff', $response->getHeader('X-Content-Type-Options'));
    }

    public function testStaticReplyForForeignOriginCarriesNoCorsHeadersButKeepsSecurityHeaders(): void
    {
        putenv(CorsManager::ENV_ALLOWED_ORIGINS . '=https://tv.example.com');

        $response = $this->invoke('GET', '/robots.txt', headers: ['Origin' => 'https://evil.example']);

        self::assertInstanceOf(WorkermanResponse::class, $response);
        self::assertNull($response->getHeader('Access-Control-Allow-Origin'));
        self::assertNull($response->getHeader('Vary'));
        self::assertSame('nosniff', $response->getHeader('X-Content-Type-Options'));
    }

    public function testStaticReplyWithoutAllowlistCarriesNoCorsHeadersAtAll(): void
    {
        $response = $this->invoke('GET', '/robots.txt', headers: ['Origin' => 'https://tv.example.com']);

        self::assertInstanceOf(WorkermanResponse::class, $response);
        self::assertNull(
            $response->getHeader('Access-Control-Allow-Origin'),
            'empty allowlist = CORS off, byte-for-byte',
        );
    }

    // --- the CSRF-rejection 403 arm ----------------------------------------------

    public function testCsrfRejectedReplyCarriesSecurityHeaders(): void
    {
        $authManager = $this->createMock(AuthManager::class);
        $authManager->method('validateAccessToken')->willReturn(['user_id' => 'user-s50']);

        $response = $this->invoke(
            'POST',
            '/api/v1/settings',
            headers: [
                'Cookie' => 'phlix_session=cookie-token-s50',
                'Origin' => 'https://evil.example',
            ],
            authManager: $authManager,
        );

        self::assertInstanceOf(WorkermanResponse::class, $response);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('nosniff', $response->getHeader('X-Content-Type-Options'));
        self::assertIsString($response->getHeader('Content-Security-Policy'));
        $body = (string) $response->rawBody();
        self::assertStringContainsString('csrf.invalid_origin', $body, 'the CSRF envelope itself must not change');
    }

    // --- the pre-router fast-path arm ----------------------------------------------

    public function testArtworkFastPathReplyCarriesSecurityHeaders(): void
    {
        $response = $this->invoke(
            'GET',
            '/api/v1/artwork/item-s50?size=w342',
            imageStorage: true,
        );

        self::assertInstanceOf(WorkermanResponse::class, $response);
        self::assertSame(401, $response->getStatusCode(), 'premise: the fast path itself answered (unsigned)');
        self::assertSame('nosniff', $response->getHeader('X-Content-Type-Options'));
        self::assertIsString($response->getHeader('Strict-Transport-Security'));
    }

    public function testSignedArtworkFastPathReplyCarriesSecurityAndCorsHeaders(): void
    {
        putenv(CorsManager::ENV_ALLOWED_ORIGINS . '=https://app.example.com');
        $minted = SignedUrl::fromEnv()->mint('/api/v1/artwork/item-s50?size=w342');

        $response = $this->invoke(
            'GET',
            $minted,
            headers: ['Origin' => 'https://app.example.com'],
            imageStorage: true,
        );

        self::assertInstanceOf(WorkermanResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('nosniff', $response->getHeader('X-Content-Type-Options'));
        self::assertSame('https://app.example.com', $response->getHeader('Access-Control-Allow-Origin'));
        self::assertNotNull($response->file, 'the withFile streaming must survive decoration');
    }

    // --- control: dispatched replies keep their existing decoration ---------------

    public function testDispatchedReplyDecorationIsUnchanged(): void
    {
        $application = $this->createMock(Application::class);
        $application->method('dispatch')->willReturn(
            (new Response())->status(200)->text('SERVED-BY-THE-ROUTER'),
        );

        $response = $this->invoke('GET', '/api/v1/libraries', application: $application);

        self::assertInstanceOf(WorkermanResponse::class, $response);
        self::assertStringContainsString('SERVED-BY-THE-ROUTER', (string) $response->rawBody());
        self::assertSame('nosniff', $response->getHeader('X-Content-Type-Options'));
    }

    // --- harness -------------------------------------------------------------------

    /**
     * Drive one real `__invoke()` and return the single sent response.
     *
     * @param array<string, string> $headers
     */
    private function invoke(
        string $method,
        string $path,
        array $headers = [],
        ?Application $application = null,
        ?AuthManager $authManager = null,
        bool $imageStorage = false,
    ): mixed {
        $raw = "{$method} {$path} HTTP/1.1\r\nHost: localhost\r\n";
        foreach ($headers as $name => $value) {
            $raw .= "{$name}: {$value}\r\n";
        }
        $raw .= "\r\n";

        $sent = [];
        $this->makeHandler($application, $authManager, $imageStorage)->__invoke(
            $this->makeConnection($sent),
            new WorkermanRequest($raw),
        );

        self::assertCount(1, $sent, 'exactly one response is sent');

        return $sent[0];
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

    private function makeHandler(
        ?Application $application,
        ?AuthManager $authManager,
        bool $imageStorage,
    ): HttpHandler {
        $registry = new SegmentProcessRegistry();
        $artwork = new ArtworkStorage($this->publicRoot . '/artwork-s50');
        $avatar = $this->createMock(AvatarStorage::class);
        $avatar->method('path')->willReturn(null);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturnCallback(
            static function (string $id) use ($registry, $artwork, $avatar): mixed {
                if ($id === SegmentProcessRegistry::class) {
                    return $registry;
                }
                if ($id === ArtworkStorage::class) {
                    return $artwork;
                }
                if ($id === AvatarStorage::class) {
                    return $avatar;
                }

                throw new \RuntimeException('unexpected container get: ' . $id);
            }
        );

        return new HttpHandler(
            $container,
            new RequestAuthenticator($authManager ?? $this->createMock(AuthManager::class)),
            $this->publicRoot,
            $application ?? $this->createMock(Application::class),
            null,
        );
    }
}
