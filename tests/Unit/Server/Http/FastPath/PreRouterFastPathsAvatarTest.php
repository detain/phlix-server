<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\FastPath;

use Phlix\Auth\SignedUrl;
use Phlix\Media\Storage\ArtworkStorage;
use Phlix\Media\Storage\AvatarStorage;
use Phlix\Server\Http\FastPath\PreRouterFastPaths;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\TestCase;
use Workerman\Protocols\Http\Request as WorkermanRequest;

/**
 * Tests for the avatar endpoint of {@see PreRouterFastPaths} — GET
 * /api/v1/users/{id}/avatar, served with either session or signed-URL auth.
 *
 * ⚠ S238 MOVED THIS. It used to be `HttpHandler::serveUserAvatar()`, a private
 * pre-router method reachable only from the Workerman HTTP daemon, which is why a
 * relayed browse could render no avatars. The assertions are carried over
 * unchanged and still run against the {@see \Workerman\Protocols\Http\Response}
 * `HttpHandler` sends, via `Response::toWorkermanResponse()`.
 */
final class PreRouterFastPathsAvatarTest extends TestCase
{
    private AvatarStorage $avatarStorage;
    private PreRouterFastPaths $fastPaths;

    protected function setUp(): void
    {
        parent::setUp();

        $tmpDir = sys_get_temp_dir() . '/avatar-handler-' . bin2hex(random_bytes(8));
        mkdir($tmpDir, 0755, true);
        $this->avatarStorage = new AvatarStorage($tmpDir);

        $this->fastPaths = new PreRouterFastPaths(
            $this->createMock(ArtworkStorage::class),
            $this->avatarStorage,
        );

        putenv('JWT_SECRET=test-secret-for-avatar-url-test-32bytes!');
        SignedUrl::resetSharedForTesting();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        SignedUrl::resetSharedForTesting();
        putenv('JWT_SECRET');
    }

    /**
     * @param array<string, mixed> $get
     */
    private function makeWorkermanRequest(
        string $method,
        string $path,
        array $get = [],
    ): WorkermanRequest {
        $queryString = $get ? '?' . http_build_query($get) : '';
        $requestLine = "{$method} {$path}{$queryString} HTTP/1.1\r\nHost: localhost\r\n\r\n";
        return new WorkermanRequest($requestLine);
    }

    private function dispatch(
        WorkermanRequest $wr,
        ?string $userId = null,
        ?PreRouterFastPaths $fastPaths = null,
    ): ?\Workerman\Protocols\Http\Response {
        $request = Request::fromWorkerman($wr);
        $request->userId = $userId;

        return ($fastPaths ?? $this->fastPaths)->dispatch($request)?->toWorkermanResponse();
    }

    public function testServeAvatarReturnsNullForPost(): void
    {
        $wr = $this->makeWorkermanRequest('POST', '/api/v1/users/user-1/avatar');

        $this->assertNull($this->dispatch($wr));
    }

    public function testServeAvatarReturnsNullForNonAvatarPath(): void
    {
        $wr = $this->makeWorkermanRequest('GET', '/api/v1/media/123');

        $this->assertNull($this->dispatch($wr));
    }

    public function testServeAvatarReturnsNullForGetWithNoMatch(): void
    {
        $wr = $this->makeWorkermanRequest('GET', '/api/v1/users/avatar');

        $this->assertNull($this->dispatch($wr));
    }

    public function testServeAvatarReturns401WithNoSessionAndNoSignature(): void
    {
        $wr = $this->makeWorkermanRequest('GET', '/api/v1/users/user-1/avatar', []);

        $result = $this->dispatch($wr);

        $this->assertInstanceOf(\Workerman\Protocols\Http\Response::class, $result);
        $this->assertSame(401, $result->getStatusCode());
    }

    public function testServeAvatarReturns401WithTamperedSignature(): void
    {
        $signer = SignedUrl::fromEnv();
        $future = time() + 3600;
        $sig = $signer->signature('/api/v1/users/user-1/avatar', $future);

        $wr = $this->makeWorkermanRequest(
            'GET',
            '/api/v1/users/user-1/avatar',
            ['exp' => (string) $future, 'sig' => $sig . 'x'],
        );

        $result = $this->dispatch($wr);

        $this->assertInstanceOf(\Workerman\Protocols\Http\Response::class, $result);
        $this->assertSame(401, $result->getStatusCode());
    }

    public function testServeAvatarReturns401WithExpiredSignature(): void
    {
        $signer = SignedUrl::fromEnv();
        $past = time() - 100;
        $sig = $signer->signature('/api/v1/users/user-1/avatar', $past);

        $wr = $this->makeWorkermanRequest(
            'GET',
            '/api/v1/users/user-1/avatar',
            ['exp' => (string) $past, 'sig' => $sig],
        );

        $result = $this->dispatch($wr);

        $this->assertInstanceOf(\Workerman\Protocols\Http\Response::class, $result);
        $this->assertSame(401, $result->getStatusCode());
    }

    public function testServeAvatarReturns200WithValidSignedUrlAndExistingAvatar(): void
    {
        $tmpAvatar = sys_get_temp_dir() . '/avatar-test-' . bin2hex(random_bytes(8)) . '.jpg';
        imagejpeg(imagecreatetruecolor(256, 256), $tmpAvatar, 85);

        $storageDir = sys_get_temp_dir() . '/avatar-storage-' . bin2hex(random_bytes(8));
        mkdir($storageDir, 0755, true);
        $realStorage = new AvatarStorage($storageDir);
        $realStorage->store('user-1', $tmpAvatar);

        $fastPaths = new PreRouterFastPaths($this->createMock(ArtworkStorage::class), $realStorage);

        $signer = SignedUrl::fromEnv();
        $signedUrl = $signer->mint('/api/v1/users/user-1/avatar');
        parse_str((string) parse_url($signedUrl, PHP_URL_QUERY), $parsed);

        $wr = $this->makeWorkermanRequest('GET', '/api/v1/users/user-1/avatar', [
            'exp' => $parsed['exp'],
            'sig' => $parsed['sig'],
        ]);

        $result = $this->dispatch($wr, null, $fastPaths);

        $this->assertNotNull($result);
        $this->assertSame(200, $result->getStatusCode());

        unlink($tmpAvatar);
        $files = array_diff(scandir($storageDir), ['.', '..']);
        foreach ($files as $file) {
            unlink($storageDir . '/' . $file);
        }
        rmdir($storageDir);
    }

    public function testServeAvatarReturns200WithSessionAuth(): void
    {
        $tmpAvatar = sys_get_temp_dir() . '/avatar-session-' . bin2hex(random_bytes(8)) . '.jpg';
        imagejpeg(imagecreatetruecolor(256, 256), $tmpAvatar, 85);

        $this->avatarStorage->store('user-1', $tmpAvatar);

        $wr = $this->makeWorkermanRequest('GET', '/api/v1/users/user-1/avatar', []);

        $result = $this->dispatch($wr, 'user-1');

        $this->assertInstanceOf(\Workerman\Protocols\Http\Response::class, $result);
        $this->assertSame(200, $result->getStatusCode());

        unlink($tmpAvatar);
    }

    /**
     * S238: the avatar bytes are JPEG and must SAY so.
     *
     * The pre-move code did `mimeFor(pathinfo($path, PATHINFO_EXTENSION))` — it
     * handed the helper the bare extension `"jpg"`, from which the helper's own
     * `pathinfo()` extracted `""`, so no map entry matched and EVERY avatar went
     * out as `application/octet-stream`. It rendered anyway only because these
     * fast-path responses carry no `X-Content-Type-Options: nosniff`, so browsers
     * sniffed it — a guarantee that does not survive an intermediary such as the
     * hub relay this step makes the endpoint reachable through.
     */
    public function testServeAvatarDeclaresJpegContentType(): void
    {
        $tmpAvatar = sys_get_temp_dir() . '/avatar-mime-' . bin2hex(random_bytes(8)) . '.jpg';
        imagejpeg(imagecreatetruecolor(64, 64), $tmpAvatar, 85);

        $this->avatarStorage->store('user-1', $tmpAvatar);

        $result = $this->dispatch(
            $this->makeWorkermanRequest('GET', '/api/v1/users/user-1/avatar', []),
            'user-1',
        );

        $this->assertInstanceOf(\Workerman\Protocols\Http\Response::class, $result);
        $this->assertSame(200, $result->getStatusCode());
        $this->assertSame(
            'image/jpeg',
            $result->getHeader('Content-Type'),
            'Avatars are JPEG (AvatarStorage re-encodes every upload and only ever writes <id>.jpg); '
            . 'serving them as application/octet-stream relies on browser content sniffing, which an '
            . 'intermediary need not provide.',
        );

        unlink($tmpAvatar);
    }

    public function testServeAvatarReturns404WhenNoAvatarForUser(): void
    {
        $wr = $this->makeWorkermanRequest('GET', '/api/v1/users/user-1/avatar', []);

        $result = $this->dispatch($wr, 'user-1');

        $this->assertInstanceOf(\Workerman\Protocols\Http\Response::class, $result);
        $this->assertSame(404, $result->getStatusCode());
    }

    public function testServeAvatarReturns404WhenFileDeleted(): void
    {
        $signer = SignedUrl::fromEnv();
        $future = time() + 3600;
        $sig = $signer->signature('/api/v1/users/user-1/avatar', $future);

        $wr = $this->makeWorkermanRequest(
            'GET',
            '/api/v1/users/user-1/avatar',
            ['exp' => (string) $future, 'sig' => $sig],
        );

        $result = $this->dispatch($wr);

        $this->assertInstanceOf(\Workerman\Protocols\Http\Response::class, $result);
        $this->assertSame(404, $result->getStatusCode());
    }
}
