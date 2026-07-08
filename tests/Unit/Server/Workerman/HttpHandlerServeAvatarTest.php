<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Workerman;

use Phlix\Auth\AuthManager;
use Phlix\Auth\SignedUrl;
use Phlix\Media\Storage\AvatarStorage;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\RequestAuthenticator;
use Phlix\Server\Workerman\HttpHandler;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Workerman\Protocols\Http\Request as WorkermanRequest;

/**
 * @covers \Phlix\Server\Workerman\HttpHandler
 *
 * Tests for {@see HttpHandler::serveUserAvatar()} — the private method invoked
 * inline from {@see HttpHandler::__invoke()} to serve avatar bytes for
 * GET /api/v1/users/{id}/avatar with either session or signed-URL auth.
 */
final class HttpHandlerServeAvatarTest extends TestCase
{
    private AuthManager $authManager;
    private RequestAuthenticator $authenticator;
    private AvatarStorage $avatarStorage;
    private ContainerInterface $container;
    private string $publicRoot;
    private Application $application;
    private HttpHandler $handler;
    private \ReflectionMethod $reflection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authManager = $this->createMock(AuthManager::class);
        $this->authenticator = new RequestAuthenticator($this->authManager);

        $tmpDir = sys_get_temp_dir() . '/avatar-handler-' . bin2hex(random_bytes(8));
        mkdir($tmpDir, 0755, true);
        $this->avatarStorage = new AvatarStorage($tmpDir);

        $this->container = $this->createMock(ContainerInterface::class);
        $this->container->method('get')
            ->willReturnCallback(fn (string $id): mixed => match ($id) {
                AvatarStorage::class => $this->avatarStorage,
                default => null,
            });

        $this->publicRoot = sys_get_temp_dir() . '/phlix-public-' . bin2hex(random_bytes(8));
        mkdir($this->publicRoot, 0755, true);

        $this->application = $this->createMock(Application::class);

        $this->handler = new HttpHandler(
            $this->container,
            $this->authenticator,
            $this->publicRoot,
            $this->application
        );

        $this->reflection = new \ReflectionMethod(
            HttpHandler::class,
            'serveUserAvatar'
        );
        $this->reflection->setAccessible(true);

        putenv('JWT_SECRET=test-secret-for-avatar-url-test-32bytes!');
        SignedUrl::resetSharedForTesting();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        SignedUrl::resetSharedForTesting();
        putenv('JWT_SECRET');

        $files = is_dir($this->publicRoot) ? array_diff(scandir($this->publicRoot), ['.', '..']) : [];
        foreach ($files as $file) {
            unlink($this->publicRoot . '/' . $file);
        }
        if (is_dir($this->publicRoot)) {
            rmdir($this->publicRoot);
        }
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

    public function testServeAvatarReturnsNullForPost(): void
    {
        $wr = $this->makeWorkermanRequest('POST', '/api/v1/users/user-1/avatar');

        $result = $this->reflection->invoke($this->handler, $wr, null);

        $this->assertNull($result);
    }

    public function testServeAvatarReturnsNullForNonAvatarPath(): void
    {
        $wr = $this->makeWorkermanRequest('GET', '/api/v1/media/123');

        $result = $this->reflection->invoke($this->handler, $wr, null);

        $this->assertNull($result);
    }

    public function testServeAvatarReturnsNullForGetWithNoMatch(): void
    {
        $wr = $this->makeWorkermanRequest('GET', '/api/v1/users/avatar');

        $result = $this->reflection->invoke($this->handler, $wr, null);

        $this->assertNull($result);
    }

    public function testServeAvatarReturns401WithNoSessionAndNoSignature(): void
    {
        $wr = $this->makeWorkermanRequest('GET', '/api/v1/users/user-1/avatar', []);

        $result = $this->reflection->invoke($this->handler, $wr, null);

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

        $result = $this->reflection->invoke($this->handler, $wr, null);

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

        $result = $this->reflection->invoke($this->handler, $wr, null);

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

        $mockContainer = $this->createMock(ContainerInterface::class);
        $mockContainer->method('get')
            ->willReturnCallback(fn (string $id): mixed => match ($id) {
                AvatarStorage::class => $realStorage,
                default => null,
            });

        $handler = new HttpHandler(
            $mockContainer,
            $this->authenticator,
            $this->publicRoot,
            $this->application
        );

        $signer = SignedUrl::fromEnv();
        $signedUrl = $signer->mint('/api/v1/users/user-1/avatar');
        parse_str((string) parse_url($signedUrl, PHP_URL_QUERY), $parsed);

        $wr = $this->makeWorkermanRequest('GET', '/api/v1/users/user-1/avatar', [
            'exp' => $parsed['exp'],
            'sig' => $parsed['sig'],
        ]);

        $result = $this->reflection->invoke($handler, $wr, null);

        $this->assertNotNull($result);
        /** @var \Workerman\Protocols\Http\Response $result */
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

        $result = $this->reflection->invoke($this->handler, $wr, 'user-1');

        $this->assertInstanceOf(\Workerman\Protocols\Http\Response::class, $result);
        $this->assertSame(200, $result->getStatusCode());

        unlink($tmpAvatar);
    }

    public function testServeAvatarReturns404WhenNoAvatarForUser(): void
    {
        $wr = $this->makeWorkermanRequest('GET', '/api/v1/users/user-1/avatar', []);

        $result = $this->reflection->invoke($this->handler, $wr, 'user-1');

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

        $result = $this->reflection->invoke($this->handler, $wr, null);

        $this->assertInstanceOf(\Workerman\Protocols\Http\Response::class, $result);
        $this->assertSame(404, $result->getStatusCode());
    }
}
