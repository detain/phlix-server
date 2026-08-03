<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use Mockery\MockInterface;
use Phlix\Auth\UserRepository;
use Phlix\Media\Storage\AvatarStorage;
use Phlix\Server\Http\Controllers\UserAvatarController;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class UserAvatarControllerTest extends TestCase
{
    private AvatarStorage $avatarStorage;
    private UserRepository&MockObject $userRepository;

    protected function setUp(): void
    {
        parent::setUp();
        \Mockery::close();

        $this->userRepository = $this->createMock(UserRepository::class);

        $tmpDir = sys_get_temp_dir() . '/avatar-ctrl-' . bin2hex(random_bytes(8));
        mkdir($tmpDir, 0755, true);
        $this->avatarStorage = new AvatarStorage($tmpDir);
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    private function authedRequest(): Request
    {
        $req = new Request();
        $req->userId = 'user-1';
        return $req;
    }

    private function authedRequestWithFile(string $tmpPath): Request
    {
        $req = $this->authedRequest();
        $req->files = [
            'avatar' => [
                'name' => 'photo.jpg',
                'type' => 'image/jpeg',
                'tmp_name' => $tmpPath,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($tmpPath),
            ],
        ];
        return $req;
    }

    public function testUploadAvatarReturns401WhenUnauthenticated(): void
    {
        $controller = new UserAvatarController($this->avatarStorage, $this->userRepository);
        $response = $controller->uploadAvatar(new Request(), []);

        $this->assertSame(401, $response->statusCode);
        /** @var array<array-key, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('Unauthorized', $body['error']);
    }

    public function testUploadAvatarReturns400WhenNoFileUploaded(): void
    {
        $controller = new UserAvatarController($this->avatarStorage, $this->userRepository);
        $response = $controller->uploadAvatar($this->authedRequest(), []);

        $this->assertSame(400, $response->statusCode);
        /** @var array<array-key, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('No file uploaded', $body['error']);
    }

    public function testUploadAvatarReturns400WhenUploadError(): void
    {
        $controller = new UserAvatarController($this->avatarStorage, $this->userRepository);

        $req = $this->authedRequest();
        $req->files = [
            'avatar' => [
                'name' => 'photo.jpg',
                'type' => 'image/jpeg',
                'tmp_name' => '/tmp/nonexistent.jpg',
                'error' => UPLOAD_ERR_INI_SIZE,
                'size' => 0,
            ],
        ];

        $response = $controller->uploadAvatar($req, []);

        $this->assertSame(400, $response->statusCode);
        /** @var array{error: string} $body */
        $body = json_decode($response->body, true);
        $this->assertStringContainsString('File exceeds server upload limit', $body['error']);
    }

    public function testUploadAvatarReturns400WhenStorageRejectsFile(): void
    {
        $controller = new UserAvatarController($this->avatarStorage, $this->userRepository);

        $req = $this->authedRequest();
        $req->files = [
            'avatar' => [
                'name' => 'photo.jpg',
                'type' => 'image/jpeg',
                'tmp_name' => '/etc/passwd',
                'error' => UPLOAD_ERR_OK,
                'size' => filesize('/etc/passwd'),
            ],
        ];

        $response = $controller->uploadAvatar($req, []);

        $this->assertSame(400, $response->statusCode);
        /** @var array{error: string} $body */
        $body = json_decode($response->body, true);
        $this->assertStringContainsString('Avatar', $body['error']);
        $this->assertStringContainsString('not a valid image', $body['error']);
    }

    public function testUploadAvatarReturns500WhenStorageFails(): void
    {
        /** @var AvatarStorage&MockInterface $failingStorage */
        $failingStorage = \Mockery::mock(AvatarStorage::class);
        // shouldReceive() returns a CompositeExpectation whose fluent methods are
        // forwarded via __call; type it as the concrete Expectation so andThrow()
        // type-checks against its real declaration.
        /** @var \Mockery\Expectation $storeExpectation */
        $storeExpectation = $failingStorage->shouldReceive('store');
        $storeExpectation->andThrow(new \RuntimeException('Disk full'));

        $controller = new UserAvatarController($failingStorage, $this->userRepository);

        $req = $this->authedRequest();
        $req->files = [
            'avatar' => [
                'name' => 'photo.jpg',
                'type' => 'image/jpeg',
                'tmp_name' => '/tmp/fake.jpg',
                'error' => UPLOAD_ERR_OK,
                'size' => 1024,
            ],
        ];

        $response = $controller->uploadAvatar($req, []);

        $this->assertSame(500, $response->statusCode);
        /** @var array<array-key, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('error', $body);
    }

    public function testUploadAvatarSuccessStoresAndReturnsSignedUrl(): void
    {
        $tmpPrefix = sys_get_temp_dir();
        $this->userRepository->expects($this->once())
            ->method('updateAvatar')
            ->with('user-1', $this->stringStartsWith($tmpPrefix === '' ? '/tmp' : $tmpPrefix));

        $controller = new UserAvatarController($this->avatarStorage, $this->userRepository);

        $tmpAvatar = $this->createTempAvatarFile();
        try {
            $response = $controller->uploadAvatar($this->authedRequestWithFile($tmpAvatar), []);

            $this->assertSame(200, $response->statusCode);
            /** @var array{avatar_url: string} $body */
            $body = json_decode($response->body, true);
            $this->assertArrayHasKey('avatar_url', $body);
            $this->assertStringStartsWith('/api/v1/users/user-1/avatar?', $body['avatar_url']);
            $this->assertStringContainsString('exp=', $body['avatar_url']);
            $this->assertStringContainsString('sig=', $body['avatar_url']);
        } finally {
            unlink($tmpAvatar);
        }
    }

    public function testDeleteAvatarReturns401WhenUnauthenticated(): void
    {
        $controller = new UserAvatarController($this->avatarStorage, $this->userRepository);
        $response = $controller->deleteAvatar(new Request(), []);

        $this->assertSame(401, $response->statusCode);
        /** @var array<array-key, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('Unauthorized', $body['error']);
    }

    public function testDeleteAvatarSuccessCallsDeleteAndClearDb(): void
    {
        $this->userRepository->expects($this->once())
            ->method('clearAvatar')
            ->with('user-1');

        $controller = new UserAvatarController($this->avatarStorage, $this->userRepository);
        $response = $controller->deleteAvatar($this->authedRequest(), []);

        $this->assertSame(200, $response->statusCode);
        /** @var array<array-key, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertSame('Avatar removed', $body['message']);
    }

    private function createTempAvatarFile(): string
    {
        $tmpAvatar = sys_get_temp_dir() . '/avatar-test-' . bin2hex(random_bytes(8)) . '.jpg';
        imagejpeg(imagecreatetruecolor(256, 256), $tmpAvatar, 85);
        return $tmpAvatar;
    }
}
