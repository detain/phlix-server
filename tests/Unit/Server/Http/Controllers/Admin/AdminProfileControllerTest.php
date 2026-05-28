<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers\Admin;

use Phlix\Auth\UserProfileManager;
use Phlix\Auth\UserRepository;
use Phlix\Server\Http\Controllers\Admin\AdminProfileController;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AdminProfileController (Step 1.2b).
 *
 * Auth (401/403) is enforced by {@see \Phlix\Server\Http\Middleware\AdminMiddleware}
 * upstream of this controller; here we assert the controller's behaviour given an
 * already-authenticated-admin request.
 *
 * @covers \Phlix\Server\Http\Controllers\Admin\AdminProfileController
 */
final class AdminProfileControllerTest extends TestCase
{
    private function makeRequest(array $body = []): Request
    {
        $request = new Request();
        $request->body = $body;

        return $request;
    }

    // ─────────────────────────────────────────────────────────────────
    // listForUser()
    // ─────────────────────────────────────────────────────────────────

    public function testListForUserHappy(): void
    {
        $profiles = [
            ['id' => 'prof_1', 'user_id' => '1', 'name' => 'Alice'],
            ['id' => 'prof_2', 'user_id' => '1', 'name' => 'Bob'],
        ];

        $profileManager = $this->createMock(UserProfileManager::class);
        $profileManager->expects($this->once())
            ->method('findByUserId')
            ->with('1')
            ->willReturn($profiles);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->expects($this->once())
            ->method('findById')
            ->with('1')
            ->willReturn(['id' => '1', 'username' => 'alice']);

        $controller = new AdminProfileController($profileManager, $userRepo);
        $response = $controller->listForUser(1);

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('profiles', $body);
        $this->assertCount(2, $body['profiles']);
    }

    public function testListForUserNotFound(): void
    {
        $profileManager = $this->createMock(UserProfileManager::class);
        $profileManager->expects($this->never())->method('findByUserId');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->expects($this->once())
            ->method('findById')
            ->with('999')
            ->willReturn(null);

        $controller = new AdminProfileController($profileManager, $userRepo);
        $response = $controller->listForUser(999);

        $this->assertSame(404, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertSame('User not found', $body['error']);
    }

    // ─────────────────────────────────────────────────────────────────
    // createForUser()
    // ─────────────────────────────────────────────────────────────────

    public function testCreateForUserHappy(): void
    {
        $profileManager = $this->createMock(UserProfileManager::class);
        $profileManager->expects($this->once())
            ->method('findByUserId')
            ->with('1')
            ->willReturn([['id' => 'prof_1', 'name' => 'Alice']]);
        $profileManager->expects($this->once())
            ->method('create')
            ->with('1', ['name' => 'Bob', 'content_rating' => 'PG-13'])
            ->willReturn('prof_2');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->expects($this->once())
            ->method('findById')
            ->with('1')
            ->willReturn(['id' => '1', 'username' => 'alice']);

        $controller = new AdminProfileController($profileManager, $userRepo);
        $response = $controller->createForUser(1, $this->makeRequest([
            'name' => 'Bob',
            'rating' => 2,
        ]));

        $this->assertSame(201, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('profile_id', $body);
        $this->assertSame('Profile created successfully', $body['message']);
    }

    public function testCreateForUserMaxProfiles(): void
    {
        $existingProfiles = [
            ['id' => 'prof_1'],
            ['id' => 'prof_2'],
            ['id' => 'prof_3'],
            ['id' => 'prof_4'],
            ['id' => 'prof_5'],
        ];

        $profileManager = $this->createMock(UserProfileManager::class);
        $profileManager->expects($this->once())
            ->method('findByUserId')
            ->with('1')
            ->willReturn($existingProfiles);
        $profileManager->expects($this->never())->method('create');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->expects($this->once())
            ->method('findById')
            ->with('1')
            ->willReturn(['id' => '1']);

        $controller = new AdminProfileController($profileManager, $userRepo);
        $response = $controller->createForUser(1, $this->makeRequest(['name' => 'TooMany']));

        $this->assertSame(400, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertSame('Maximum profiles reached', $body['error']);
    }

    public function testCreateForUserUserNotFound(): void
    {
        $profileManager = $this->createMock(UserProfileManager::class);
        $profileManager->expects($this->never())->method('findByUserId');
        $profileManager->expects($this->never())->method('create');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->expects($this->once())
            ->method('findById')
            ->with('999')
            ->willReturn(null);

        $controller = new AdminProfileController($profileManager, $userRepo);
        $response = $controller->createForUser(999, $this->makeRequest(['name' => 'Bob']));

        $this->assertSame(404, $response->statusCode);
    }

    public function testCreateForUserInvalidName(): void
    {
        $profileManager = $this->createMock(UserProfileManager::class);
        $profileManager->expects($this->once())
            ->method('findByUserId')
            ->willReturn([]);
        $profileManager->expects($this->never())->method('create');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->expects($this->once())
            ->method('findById')
            ->willReturn(['id' => '1']);

        $controller = new AdminProfileController($profileManager, $userRepo);
        $response = $controller->createForUser(1, $this->makeRequest([
            'name' => 'This name is way too long and exceeds fifty characters which is the maximum allowed',
        ]));

        $this->assertSame(400, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertSame('Invalid name', $body['error']);
        $this->assertArrayHasKey('field_errors', $body);
    }

    public function testCreateForUserInvalidRating(): void
    {
        $profileManager = $this->createMock(UserProfileManager::class);
        $profileManager->expects($this->once())
            ->method('findByUserId')
            ->willReturn([]);
        $profileManager->expects($this->never())->method('create');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->expects($this->once())
            ->method('findById')
            ->willReturn(['id' => '1']);

        $controller = new AdminProfileController($profileManager, $userRepo);
        $response = $controller->createForUser(1, $this->makeRequest([
            'name' => 'ValidName',
            'rating' => 99,
        ]));

        $this->assertSame(400, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertSame('Invalid rating', $body['error']);
    }

    // ─────────────────────────────────────────────────────────────────
    // get()
    // ─────────────────────────────────────────────────────────────────

    public function testGetHappyPath(): void
    {
        $profile = ['id' => 'prof_1', 'user_id' => '1', 'name' => 'Alice'];

        $profileManager = $this->createMock(UserProfileManager::class);
        $profileManager->expects($this->once())
            ->method('findById')
            ->with('1')
            ->willReturn($profile);

        $userRepo = $this->createMock(UserRepository::class);

        $controller = new AdminProfileController($profileManager, $userRepo);
        $response = $controller->get(1);

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('profile', $body);
        $this->assertSame('Alice', $body['profile']['name']);
    }

    public function testGetNotFound(): void
    {
        $profileManager = $this->createMock(UserProfileManager::class);
        $profileManager->expects($this->once())
            ->method('findById')
            ->with('999')
            ->willReturn(null);

        $userRepo = $this->createMock(UserRepository::class);

        $controller = new AdminProfileController($profileManager, $userRepo);
        $response = $controller->get(999);

        $this->assertSame(404, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertSame('Profile not found', $body['error']);
    }

    // ─────────────────────────────────────────────────────────────────
    // update()
    // ─────────────────────────────────────────────────────────────────

    public function testUpdateHappyPath(): void
    {
        $profile = ['id' => 'prof_1', 'user_id' => '1', 'name' => 'Alice'];

        $profileManager = $this->createMock(UserProfileManager::class);
        $profileManager->expects($this->once())
            ->method('findById')
            ->with('1')
            ->willReturn($profile);
        $profileManager->expects($this->once())
            ->method('update')
            ->with('1', ['name' => 'Alice Updated']);

        $userRepo = $this->createMock(UserRepository::class);

        $controller = new AdminProfileController($profileManager, $userRepo);
        $response = $controller->update(1, $this->makeRequest(['name' => 'Alice Updated']));

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertSame('Profile updated successfully', $body['message']);
    }

    public function testUpdateNotFound(): void
    {
        $profileManager = $this->createMock(UserProfileManager::class);
        $profileManager->expects($this->once())
            ->method('findById')
            ->with('999')
            ->willReturn(null);

        $userRepo = $this->createMock(UserRepository::class);

        $controller = new AdminProfileController($profileManager, $userRepo);
        $response = $controller->update(999, $this->makeRequest(['name' => 'New Name']));

        $this->assertSame(404, $response->statusCode);
    }

    // ─────────────────────────────────────────────────────────────────
    // delete()
    // ─────────────────────────────────────────────────────────────────

    public function testDeleteHappyPath(): void
    {
        $profile = ['id' => 'prof_1', 'user_id' => '1', 'name' => 'Alice'];

        $profileManager = $this->createMock(UserProfileManager::class);
        $profileManager->expects($this->once())
            ->method('findById')
            ->with('1')
            ->willReturn($profile);
        $profileManager->expects($this->once())
            ->method('delete')
            ->with('1');

        $userRepo = $this->createMock(UserRepository::class);

        $controller = new AdminProfileController($profileManager, $userRepo);
        $response = $controller->delete(1);

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertSame('Profile deleted successfully', $body['message']);
    }

    public function testDeleteNotFound(): void
    {
        $profileManager = $this->createMock(UserProfileManager::class);
        $profileManager->expects($this->once())
            ->method('findById')
            ->with('999')
            ->willReturn(null);

        $userRepo = $this->createMock(UserRepository::class);

        $controller = new AdminProfileController($profileManager, $userRepo);
        $response = $controller->delete(999);

        $this->assertSame(404, $response->statusCode);
    }

    // ─────────────────────────────────────────────────────────────────
    // setPin()
    // ─────────────────────────────────────────────────────────────────

    public function testSetPinHappyPath4Digit(): void
    {
        $profile = ['id' => 'prof_1', 'user_id' => '1', 'name' => 'Alice'];

        $profileManager = $this->createMock(UserProfileManager::class);
        $profileManager->expects($this->once())
            ->method('findById')
            ->with('1')
            ->willReturn($profile);
        $profileManager->expects($this->once())
            ->method('setPin')
            ->with('1', '1234');

        $userRepo = $this->createMock(UserRepository::class);

        $controller = new AdminProfileController($profileManager, $userRepo);
        $response = $controller->setPin(1, $this->makeRequest(['pin' => '1234']));

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertSame('PIN set successfully', $body['message']);
    }

    public function testSetPinHappyPath6Digit(): void
    {
        $profile = ['id' => 'prof_1', 'user_id' => '1', 'name' => 'Alice'];

        $profileManager = $this->createMock(UserProfileManager::class);
        $profileManager->expects($this->once())
            ->method('findById')
            ->with('1')
            ->willReturn($profile);
        $profileManager->expects($this->once())
            ->method('setPin')
            ->with('1', '123456');

        $userRepo = $this->createMock(UserRepository::class);

        $controller = new AdminProfileController($profileManager, $userRepo);
        $response = $controller->setPin(1, $this->makeRequest(['pin' => '123456']));

        $this->assertSame(200, $response->statusCode);
    }

    public function testSetPinClear(): void
    {
        $profile = ['id' => 'prof_1', 'user_id' => '1', 'name' => 'Alice'];

        $profileManager = $this->createMock(UserProfileManager::class);
        $profileManager->expects($this->once())
            ->method('findById')
            ->with('1')
            ->willReturn($profile);
        $profileManager->expects($this->once())
            ->method('removePin')
            ->with('1');

        $userRepo = $this->createMock(UserRepository::class);

        $controller = new AdminProfileController($profileManager, $userRepo);
        $response = $controller->setPin(1, $this->makeRequest(['pin' => null]));

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertSame('PIN cleared successfully', $body['message']);
    }

    public function testSetPinInvalidLength(): void
    {
        $profile = ['id' => 'prof_1', 'user_id' => '1', 'name' => 'Alice'];

        $profileManager = $this->createMock(UserProfileManager::class);
        $profileManager->expects($this->once())
            ->method('findById')
            ->with('1')
            ->willReturn($profile);
        $profileManager->expects($this->never())->method('setPin');

        $userRepo = $this->createMock(UserRepository::class);

        $controller = new AdminProfileController($profileManager, $userRepo);
        $response = $controller->setPin(1, $this->makeRequest(['pin' => '12345']));

        $this->assertSame(400, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertSame('Invalid PIN length', $body['error']);
    }

    public function testSetPinNonDigits(): void
    {
        $profile = ['id' => 'prof_1', 'user_id' => '1', 'name' => 'Alice'];

        $profileManager = $this->createMock(UserProfileManager::class);
        $profileManager->expects($this->once())
            ->method('findById')
            ->with('1')
            ->willReturn($profile);
        $profileManager->expects($this->never())->method('setPin');

        $userRepo = $this->createMock(UserRepository::class);

        $controller = new AdminProfileController($profileManager, $userRepo);
        $response = $controller->setPin(1, $this->makeRequest(['pin' => 'abcd']));

        $this->assertSame(400, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertSame('PIN must contain only digits', $body['error']);
    }

    public function testSetPinNotFound(): void
    {
        $profileManager = $this->createMock(UserProfileManager::class);
        $profileManager->expects($this->once())
            ->method('findById')
            ->with('999')
            ->willReturn(null);

        $userRepo = $this->createMock(UserRepository::class);

        $controller = new AdminProfileController($profileManager, $userRepo);
        $response = $controller->setPin(999, $this->makeRequest(['pin' => '1234']));

        $this->assertSame(404, $response->statusCode);
    }

    // ─────────────────────────────────────────────────────────────────
    // deletePin()
    // ─────────────────────────────────────────────────────────────────

    public function testDeletePinHappyPath(): void
    {
        $profile = ['id' => 'prof_1', 'user_id' => '1', 'name' => 'Alice'];

        $profileManager = $this->createMock(UserProfileManager::class);
        $profileManager->expects($this->once())
            ->method('findById')
            ->with('1')
            ->willReturn($profile);
        $profileManager->expects($this->once())
            ->method('removePin')
            ->with('1');

        $userRepo = $this->createMock(UserRepository::class);

        $controller = new AdminProfileController($profileManager, $userRepo);
        $response = $controller->deletePin(1);

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertSame('PIN deleted successfully', $body['message']);
    }

    public function testDeletePinNotFound(): void
    {
        $profileManager = $this->createMock(UserProfileManager::class);
        $profileManager->expects($this->once())
            ->method('findById')
            ->with('999')
            ->willReturn(null);

        $userRepo = $this->createMock(UserRepository::class);

        $controller = new AdminProfileController($profileManager, $userRepo);
        $response = $controller->deletePin(999);

        $this->assertSame(404, $response->statusCode);
    }
}
