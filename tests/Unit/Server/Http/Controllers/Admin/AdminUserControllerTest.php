<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers\Admin;

use Phlix\Auth\UserRepository;
use Phlix\Server\Http\Controllers\Admin\AdminUserController;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\RequestContext;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AdminUserController (Step 1.2a).
 *
 * Auth (401/403) is enforced by {@see \Phlix\Server\Http\Middleware\AdminMiddleware}
 * upstream of this controller; here we assert the controller's behaviour given an
 * already-authenticated-admin request.
 *
 * @covers \Phlix\Server\Http\Controllers\Admin\AdminUserController
 */
final class AdminUserControllerTest extends TestCase
{
    private function makeRequest(array $body = []): Request
    {
        $request = new Request();
        $request->body = $body;

        return $request;
    }

    /**
     * Helper to set the current user ID in the request context.
     */
    private function setCurrentUser(string $userId): void
    {
        RequestContext::setUserId($userId);
    }

    /**
     * Helper to clear the current user ID.
     */
    private function clearCurrentUser(): void
    {
        RequestContext::clearUserId();
    }

    // ─────────────────────────────────────────────────────────────────
    // list()
    // ─────────────────────────────────────────────────────────────────

    public function testListReturnsUsersArray(): void
    {
        $users = [
            ['id' => '1', 'username' => 'alice', 'email' => 'alice@example.com', 'is_admin' => 1],
            ['id' => '2', 'username' => 'bob', 'email' => 'bob@example.com', 'is_admin' => 0],
        ];

        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->once())
            ->method('findAll')
            ->willReturn($users);

        $controller = new AdminUserController($repo);
        $response = $controller->list();

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('users', $body);
        $this->assertCount(2, $body['users']);
        $this->assertSame('alice', $body['users'][0]['username']);
    }

    public function testListReturnsEmptyArrayWhenNoUsers(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->once())
            ->method('findAll')
            ->willReturn([]);

        $controller = new AdminUserController($repo);
        $response = $controller->list();

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('users', $body);
        $this->assertCount(0, $body['users']);
    }

    // ─────────────────────────────────────────────────────────────────
    // get()
    // ─────────────────────────────────────────────────────────────────

    public function testGetHappyPath(): void
    {
        $user = ['id' => '1', 'username' => 'alice', 'email' => 'alice@example.com', 'is_admin' => 1];

        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->once())
            ->method('findById')
            ->with('1')
            ->willReturn($user);

        $controller = new AdminUserController($repo);
        $response = $controller->get(1);

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('user', $body);
        $this->assertSame('alice', $body['user']['username']);
    }

    public function testGetNotFound(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->once())
            ->method('findById')
            ->with('999')
            ->willReturn(null);

        $controller = new AdminUserController($repo);
        $response = $controller->get(999);

        $this->assertSame(404, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('error', $body);
        $this->assertSame('User not found', $body['error']);
    }

    // ─────────────────────────────────────────────────────────────────
    // create()
    // ─────────────────────────────────────────────────────────────────

    public function testCreateHappyPath(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->once())
            ->method('emailExists')
            ->with('alice@example.com')
            ->willReturn(false);
        $repo->expects($this->once())
            ->method('create')
            ->willReturn('new-user-id');

        $controller = new AdminUserController($repo);
        $response = $controller->create($this->makeRequest([
            'username' => 'alice',
            'email' => 'alice@example.com',
            'password' => 'securepassword123',
            'is_admin' => false,
        ]));

        $this->assertSame(201, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('user_id', $body);
        $this->assertSame('new-user-id', $body['user_id']);
        $this->assertSame('User created successfully', $body['message']);
    }

    public function testCreateValidationUsernameTooShort(): void
    {
        $repo = $this->createMock(UserRepository::class);
        // emailExists should NOT be called because validation fails first
        $repo->expects($this->never())->method('emailExists');

        $controller = new AdminUserController($repo);
        $response = $controller->create($this->makeRequest([
            'username' => 'ab', // too short
            'email' => 'alice@example.com',
            'password' => 'securepassword123',
        ]));

        $this->assertSame(400, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertSame('Invalid username', $body['error']);
        $this->assertArrayHasKey('field_errors', $body);
        $this->assertArrayHasKey('username', $body['field_errors']);
    }

    public function testCreateValidationEmailTaken(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->once())
            ->method('emailExists')
            ->with('alice@example.com')
            ->willReturn(true); // email already taken

        $controller = new AdminUserController($repo);
        $response = $controller->create($this->makeRequest([
            'username' => 'alice',
            'email' => 'alice@example.com',
            'password' => 'securepassword123',
        ]));

        $this->assertSame(400, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertSame('Email already exists', $body['error']);
        $this->assertArrayHasKey('field_errors', $body);
        $this->assertArrayHasKey('email', $body['field_errors']);
    }

    public function testCreateValidationWeakPassword(): void
    {
        $repo = $this->createMock(UserRepository::class);
        // emailExists should NOT be called because validation fails first
        $repo->expects($this->never())->method('emailExists');

        $controller = new AdminUserController($repo);
        $response = $controller->create($this->makeRequest([
            'username' => 'alice',
            'email' => 'alice@example.com',
            'password' => 'short', // too short
        ]));

        $this->assertSame(400, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertSame('Invalid password', $body['error']);
        $this->assertArrayHasKey('field_errors', $body);
        $this->assertArrayHasKey('password', $body['field_errors']);
    }

    // ─────────────────────────────────────────────────────────────────
    // update()
    // ─────────────────────────────────────────────────────────────────

    public function testUpdateHappyPath(): void
    {
        $existingUser = ['id' => '1', 'username' => 'alice', 'email' => 'alice@example.com', 'is_admin' => 1];

        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->once())
            ->method('findById')
            ->with('1')
            ->willReturn($existingUser);
        // No email uniqueness conflict (email not changed)
        $repo->expects($this->never())->method('emailExists');
        $repo->expects($this->once())
            ->method('update')
            ->with('1', ['username' => 'alice_updated']);

        $controller = new AdminUserController($repo);
        $response = $controller->update(1, $this->makeRequest([
            'username' => 'alice_updated',
        ]));

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertSame('User updated successfully', $body['message']);
    }

    public function testUpdateNotFound(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->once())
            ->method('findById')
            ->with('999')
            ->willReturn(null);

        $controller = new AdminUserController($repo);
        $response = $controller->update(999, $this->makeRequest(['username' => 'newname']));

        $this->assertSame(404, $response->statusCode);
    }

    public function testUpdateEmailTakenOnChange(): void
    {
        $existingUser = ['id' => '1', 'username' => 'alice', 'email' => 'alice@example.com', 'is_admin' => 1];

        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->once())
            ->method('findById')
            ->with('1')
            ->willReturn($existingUser);
        $repo->expects($this->once())
            ->method('emailExists')
            ->with('bob@example.com', 1) // excludeId = current user id
            ->willReturn(true); // email already taken by another user

        $controller = new AdminUserController($repo);
        $response = $controller->update(1, $this->makeRequest([
            'email' => 'bob@example.com',
        ]));

        $this->assertSame(400, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertSame('Email already in use', $body['error']);
    }

    public function testUpdatePasswordUpdated(): void
    {
        $existingUser = ['id' => '1', 'username' => 'alice', 'email' => 'alice@example.com', 'is_admin' => 1];

        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->once())
            ->method('findById')
            ->with('1')
            ->willReturn($existingUser);
        // update() should be called with a hashed password
        $repo->expects($this->once())
            ->method('update')
            ->with('1', $this->callback(function (array $data): bool {
                return isset($data['password']) && password_verify('newpassword123', $data['password']);
            }));

        $controller = new AdminUserController($repo);
        $response = $controller->update(1, $this->makeRequest([
            'password' => 'newpassword123',
        ]));

        $this->assertSame(200, $response->statusCode);
    }

    // ─────────────────────────────────────────────────────────────────
    // delete()
    // ─────────────────────────────────────────────────────────────────

    public function testDeleteHappyPath(): void
    {
        $user = ['id' => '2', 'username' => 'bob', 'email' => 'bob@example.com', 'is_admin' => 0];
        $this->setCurrentUser('1'); // admin 1 is deleting user 2

        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->once())
            ->method('findById')
            ->with('2')
            ->willReturn($user);
        // Not an admin, so no last-admin check
        $repo->expects($this->never())->method('countUsers');
        $repo->expects($this->once())
            ->method('delete')
            ->with('2');

        $controller = new AdminUserController($repo);
        $response = $controller->delete(2);

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertSame('User deleted successfully', $body['message']);

        $this->clearCurrentUser();
    }

    public function testDeleteNotFound(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->once())
            ->method('findById')
            ->with('999')
            ->willReturn(null);

        $controller = new AdminUserController($repo);
        $response = $controller->delete(999);

        $this->assertSame(404, $response->statusCode);
    }

    public function testDeleteCannotDeleteOwnAccount(): void
    {
        $this->setCurrentUser('1');

        $user = ['id' => '1', 'username' => 'admin', 'email' => 'admin@example.com', 'is_admin' => 1];

        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->once())
            ->method('findById')
            ->with('1')
            ->willReturn($user);
        // delete() should NOT be called
        $repo->expects($this->never())->method('delete');

        $controller = new AdminUserController($repo);
        $response = $controller->delete(1);

        $this->assertSame(400, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertSame('Cannot delete your own account', $body['error']);

        $this->clearCurrentUser();
    }

    public function testDeleteCannotDeleteLastAdmin(): void
    {
        $this->setCurrentUser('1');

        $user = ['id' => '2', 'username' => 'admin2', 'email' => 'admin2@example.com', 'is_admin' => 1];

        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->once())
            ->method('findById')
            ->with('2')
            ->willReturn($user);
        $repo->expects($this->once())
            ->method('countUsers')
            ->with('is_admin = 1')
            ->willReturn(1); // only 1 admin (the one being deleted)
        // delete() should NOT be called
        $repo->expects($this->never())->method('delete');

        $controller = new AdminUserController($repo);
        $response = $controller->delete(2);

        $this->assertSame(400, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertSame('Cannot delete the last admin', $body['error']);

        $this->clearCurrentUser();
    }

    // ─────────────────────────────────────────────────────────────────
    // setAdmin()
    // ─────────────────────────────────────────────────────────────────

    public function testSetAdminPromoteHappyPath(): void
    {
        $user = ['id' => '2', 'username' => 'bob', 'email' => 'bob@example.com', 'is_admin' => 0];

        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->once())
            ->method('findById')
            ->with('2')
            ->willReturn($user);
        $repo->expects($this->once())
            ->method('setAdmin')
            ->with('2', true);

        $controller = new AdminUserController($repo);
        $response = $controller->setAdmin(2, $this->makeRequest(['is_admin' => true]));

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertSame('User admin status updated successfully', $body['message']);
    }

    public function testSetAdminDemoteHappyPath(): void
    {
        $this->setCurrentUser('1');

        $user = ['id' => '2', 'username' => 'admin2', 'email' => 'admin2@example.com', 'is_admin' => 1];

        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->once())
            ->method('findById')
            ->with('2')
            ->willReturn($user);
        // countUsers should be called when demoting an admin
        $repo->expects($this->once())
            ->method('countUsers')
            ->with('is_admin = 1')
            ->willReturn(2); // 2 admins, so demoting one leaves 1
        $repo->expects($this->once())
            ->method('setAdmin')
            ->with('2', false);

        $controller = new AdminUserController($repo);
        $response = $controller->setAdmin(2, $this->makeRequest(['is_admin' => false]));

        $this->assertSame(200, $response->statusCode);

        $this->clearCurrentUser();
    }

    public function testSetAdminCannotDemoteLastAdmin(): void
    {
        $this->setCurrentUser('1');

        $user = ['id' => '2', 'username' => 'admin2', 'email' => 'admin2@example.com', 'is_admin' => 1];

        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->once())
            ->method('findById')
            ->with('2')
            ->willReturn($user);
        $repo->expects($this->once())
            ->method('countUsers')
            ->with('is_admin = 1')
            ->willReturn(1); // only 1 admin
        // setAdmin() should NOT be called
        $repo->expects($this->never())->method('setAdmin');

        $controller = new AdminUserController($repo);
        $response = $controller->setAdmin(2, $this->makeRequest(['is_admin' => false]));

        $this->assertSame(400, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertSame('Cannot demote the last admin', $body['error']);

        $this->clearCurrentUser();
    }

    public function testSetAdminCannotDemoteSelf(): void
    {
        $this->setCurrentUser('1');

        $user = ['id' => '1', 'username' => 'admin', 'email' => 'admin@example.com', 'is_admin' => 1];

        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->once())
            ->method('findById')
            ->with('1')
            ->willReturn($user);
        // setAdmin() should NOT be called
        $repo->expects($this->never())->method('setAdmin');

        $controller = new AdminUserController($repo);
        $response = $controller->setAdmin(1, $this->makeRequest(['is_admin' => false]));

        $this->assertSame(400, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertSame('Cannot demote yourself', $body['error']);

        $this->clearCurrentUser();
    }

    public function testSetAdminNotFound(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->once())
            ->method('findById')
            ->with('999')
            ->willReturn(null);

        $controller = new AdminUserController($repo);
        $response = $controller->setAdmin(999, $this->makeRequest(['is_admin' => true]));

        $this->assertSame(404, $response->statusCode);
    }

    // ─────────────────────────────────────────────────────────────────
    // resetPassword()
    // ─────────────────────────────────────────────────────────────────

    public function testResetPasswordHappyPath(): void
    {
        $user = ['id' => '1', 'username' => 'alice', 'email' => 'alice@example.com', 'is_admin' => 1];

        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->once())
            ->method('findById')
            ->with('1')
            ->willReturn($user);

        // Verify update is called with a hashed password (argon2id)
        $repo->expects($this->once())
            ->method('update')
            ->with('1', $this->callback(function (array $data): bool {
                return isset($data['password']) && str_starts_with($data['password'], '$argon2id$');
            }));

        $controller = new AdminUserController($repo);
        $response = $controller->resetPassword(1);

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('message', $body);
        $this->assertArrayHasKey('new_password', $body);
        $this->assertSame('Password reset successfully', $body['message']);
        // new_password should be 12 characters
        $this->assertSame(12, strlen($body['new_password']));
    }

    public function testResetPasswordNotFound(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->once())
            ->method('findById')
            ->with('999')
            ->willReturn(null);

        $controller = new AdminUserController($repo);
        $response = $controller->resetPassword(999);

        $this->assertSame(404, $response->statusCode);
    }

    // ─────────────────────────────────────────────────────────────────
    // list(?status=) filter (S1)
    // ─────────────────────────────────────────────────────────────────

    public function testListFiltersByPendingStatus(): void
    {
        $pending = [
            ['id' => '3', 'username' => 'nina', 'email' => 'nina@example.com', 'status' => 'pending'],
        ];

        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->once())
            ->method('listByStatus')
            ->with('pending')
            ->willReturn($pending);
        // The unfiltered findAll() must NOT be used when a valid status is given.
        $repo->expects($this->never())->method('findAll');

        $request = new Request();
        $request->query = ['status' => 'pending'];

        $controller = new AdminUserController($repo);
        $response = $controller->list($request);

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertCount(1, $body['users']);
        $this->assertSame('pending', $body['users'][0]['status']);
    }

    public function testListIgnoresInvalidStatusAndReturnsAll(): void
    {
        $all = [
            ['id' => '1', 'username' => 'alice', 'status' => 'active'],
            ['id' => '2', 'username' => 'bob', 'status' => 'disabled'],
        ];

        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->once())->method('findAll')->willReturn($all);
        $repo->expects($this->never())->method('listByStatus');

        $request = new Request();
        $request->query = ['status' => 'banana'];

        $controller = new AdminUserController($repo);
        $response = $controller->list($request);

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertCount(2, $body['users']);
    }

    // ─────────────────────────────────────────────────────────────────
    // approve() (S1)
    // ─────────────────────────────────────────────────────────────────

    public function testApproveSetsStatusActive(): void
    {
        $user = ['id' => '3', 'username' => 'nina', 'status' => 'pending'];

        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->once())->method('findById')->with('3')->willReturn($user);
        $repo->expects($this->once())->method('setStatus')->with('3', 'active');

        $controller = new AdminUserController($repo);
        $response = $controller->approve($this->makeRequest(), ['id' => '3']);

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertSame('User approved successfully', $body['message']);
    }

    public function testApproveNotFound(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->once())->method('findById')->with('999')->willReturn(null);
        $repo->expects($this->never())->method('setStatus');

        $controller = new AdminUserController($repo);
        $response = $controller->approve($this->makeRequest(), ['id' => '999']);

        $this->assertSame(404, $response->statusCode);
    }

    // ─────────────────────────────────────────────────────────────────
    // disable() (S1)
    // ─────────────────────────────────────────────────────────────────

    public function testDisableSetsStatusDisabled(): void
    {
        $this->setCurrentUser('1');
        $user = ['id' => '3', 'username' => 'nina', 'status' => 'active', 'is_admin' => 0];

        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->once())->method('findById')->with('3')->willReturn($user);
        $repo->expects($this->once())->method('setStatus')->with('3', 'disabled');

        $controller = new AdminUserController($repo);
        $response = $controller->disable($this->makeRequest(), ['id' => '3']);

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertSame('User disabled successfully', $body['message']);

        $this->clearCurrentUser();
    }

    public function testDisableNotFound(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->once())->method('findById')->with('999')->willReturn(null);
        $repo->expects($this->never())->method('setStatus');

        $controller = new AdminUserController($repo);
        $response = $controller->disable($this->makeRequest(), ['id' => '999']);

        $this->assertSame(404, $response->statusCode);
    }

    public function testDisableCannotDisableOwnAccount(): void
    {
        $this->setCurrentUser('1');
        $user = ['id' => '1', 'username' => 'admin', 'status' => 'active', 'is_admin' => 1];

        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->once())->method('findById')->with('1')->willReturn($user);
        $repo->expects($this->never())->method('setStatus');

        $controller = new AdminUserController($repo);
        $response = $controller->disable($this->makeRequest(), ['id' => '1']);

        $this->assertSame(400, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertSame('Cannot disable your own account', $body['error']);

        $this->clearCurrentUser();
    }

    public function testDisableCannotDisableLastAdmin(): void
    {
        $this->setCurrentUser('1');
        $user = ['id' => '2', 'username' => 'admin2', 'status' => 'active', 'is_admin' => 1];

        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->once())->method('findById')->with('2')->willReturn($user);
        $repo->expects($this->once())->method('countUsers')->with('is_admin = 1')->willReturn(1);
        $repo->expects($this->never())->method('setStatus');

        $controller = new AdminUserController($repo);
        $response = $controller->disable($this->makeRequest(), ['id' => '2']);

        $this->assertSame(400, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertSame('Cannot disable the last admin', $body['error']);

        $this->clearCurrentUser();
    }

    // ─────────────────────────────────────────────────────────────────
    // reject() (S1)
    // ─────────────────────────────────────────────────────────────────

    public function testRejectDeletesPendingUser(): void
    {
        $user = ['id' => '3', 'username' => 'nina', 'status' => 'pending'];

        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->once())->method('findById')->with('3')->willReturn($user);
        $repo->expects($this->once())->method('delete')->with('3');

        $controller = new AdminUserController($repo);
        $response = $controller->reject($this->makeRequest(), ['id' => '3']);

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertSame('User rejected successfully', $body['message']);
    }

    public function testRejectNotFound(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->once())->method('findById')->with('999')->willReturn(null);
        $repo->expects($this->never())->method('delete');

        $controller = new AdminUserController($repo);
        $response = $controller->reject($this->makeRequest(), ['id' => '999']);

        $this->assertSame(404, $response->statusCode);
    }

    public function testRejectRefusesNonPendingUser(): void
    {
        $user = ['id' => '3', 'username' => 'nina', 'status' => 'active'];

        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->once())->method('findById')->with('3')->willReturn($user);
        $repo->expects($this->never())->method('delete');

        $controller = new AdminUserController($repo);
        $response = $controller->reject($this->makeRequest(), ['id' => '3']);

        $this->assertSame(400, $response->statusCode);
        /** @var array<string, mixed> */
        $body = json_decode($response->body, true);
        $this->assertStringContainsString('pending', $body['error']);
    }
}
