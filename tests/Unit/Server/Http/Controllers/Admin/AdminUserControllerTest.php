<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers\Admin;

use Phlix\Auth\AuthManager;
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
 */
final class AdminUserControllerTest extends TestCase
{
    /**
     * @param array<string, mixed> $body
     */
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
        RequestContext::setUserId(null);
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
        /** @var array{users: list<array<string, mixed>>} $body */
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
        /** @var array{users: list<array<string, mixed>>} $body */
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
        $response = $controller->get($this->makeRequest(), ['id' => '1']);

        $this->assertSame(200, $response->statusCode);
        /** @var array{user: array<string, mixed>} $body */
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('user', $body);
        $this->assertSame('alice', $body['user']['username']);
    }

    /**
     * Regression: the daemon Router always invokes a controller method as
     * `$method($request, $params)`. Before this fix `get()` declared
     * `int $id` first, so the Router passed a Request where an int was
     * expected → TypeError → HTTP 500 (observed live). A UUID `$params['id']`
     * must now be forwarded verbatim to the repository and return a non-500.
     */
    public function testGetWithUuidParamsDoesNotTypeError(): void
    {
        $uuid = '3f8a1c2d-0b4e-4a6f-9c1d-2e3f4a5b6c7d';
        $user = ['id' => $uuid, 'username' => 'alice', 'email' => 'alice@example.com', 'is_admin' => 1];

        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->once())
            ->method('findById')
            ->with($uuid)
            ->willReturn($user);

        $controller = new AdminUserController($repo);
        $response = $controller->get($this->makeRequest(), ['id' => $uuid]);

        $this->assertLessThan(500, $response->statusCode);
        $this->assertSame(200, $response->statusCode);
        /** @var array{user: array<string, mixed>} $body */
        $body = json_decode($response->body, true);
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
        $response = $controller->get($this->makeRequest(), ['id' => '999']);

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
        /** @var array{error: mixed, field_errors: array<string, mixed>} $body */
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
        /** @var array{error: mixed, field_errors: array<string, mixed>} $body */
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
        /** @var array{error: mixed, field_errors: array<string, mixed>} $body */
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
        $response = $controller->update($this->makeRequest([
            'username' => 'alice_updated',
        ]), ['id' => '1']);

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
        $response = $controller->update($this->makeRequest(['username' => 'newname']), ['id' => '999']);

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
            ->with('bob@example.com', '1') // excludeId = current user id (UUID string)
            ->willReturn(true); // email already taken by another user

        $controller = new AdminUserController($repo);
        $response = $controller->update($this->makeRequest([
            'email' => 'bob@example.com',
        ]), ['id' => '1']);

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
        $response = $controller->update($this->makeRequest([
            'password' => 'newpassword123',
        ]), ['id' => '1']);

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
        $response = $controller->delete($this->makeRequest(), ['id' => '2']);

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
        $response = $controller->delete($this->makeRequest(), ['id' => '999']);

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
        $response = $controller->delete($this->makeRequest(), ['id' => '1']);

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
        $response = $controller->delete($this->makeRequest(), ['id' => '2']);

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
        $response = $controller->setAdmin($this->makeRequest(['is_admin' => true]), ['id' => '2']);

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
        $response = $controller->setAdmin($this->makeRequest(['is_admin' => false]), ['id' => '2']);

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
        $response = $controller->setAdmin($this->makeRequest(['is_admin' => false]), ['id' => '2']);

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
        $response = $controller->setAdmin($this->makeRequest(['is_admin' => false]), ['id' => '1']);

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
        $response = $controller->setAdmin($this->makeRequest(['is_admin' => true]), ['id' => '999']);

        $this->assertSame(404, $response->statusCode);
    }

    // ─────────────────────────────────────────────────────────────────
    // resetPassword()
    // ─────────────────────────────────────────────────────────────────

    /**
     * S7+F1: resetPassword() no longer returns a plaintext password.
     * Instead it issues a one-time reset token (hashed at rest, with expiry)
     * and sets the must_change_password flag so the user must change before access.
     *
     * @since S7+F1
     */
    public function testResetPasswordHappyPath(): void
    {
        $user = ['id' => '1', 'username' => 'alice', 'email' => 'alice@example.com', 'is_admin' => 1];

        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->once())
            ->method('findById')
            ->with('1')
            ->willReturn($user);

        // S7+F1: No longer calls update() with a password.
        // Instead calls setPasswordResetToken() with a hashed token + expiry,
        // and setMustChangePassword(true) to force a password change.
        $repo->expects($this->once())
            ->method('setPasswordResetToken')
            ->with(
                '1',
                $this->callback(function (string $hashedToken): bool {
                    // Token should be hashed with Argon2ID (starts with $argon2id$)
                    return str_starts_with($hashedToken, '$argon2id$');
                }),
                $this->callback(function (int $expiresAt): bool {
                    // Expiry should be ~15 minutes (900 seconds) from now
                    return $expiresAt > time() + 800 && $expiresAt <= time() + 900;
                })
            );

        $repo->expects($this->once())
            ->method('setMustChangePassword')
            ->with('1', true);

        $controller = new AdminUserController($repo);
        $response = $controller->resetPassword($this->makeRequest(), ['id' => '1']);

        $this->assertSame(200, $response->statusCode);
        /** @var array{message: string} $body */
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('message', $body);
        // S7+F1: new_password must NOT be in the response (security fix)
        $this->assertArrayNotHasKey('new_password', $body);
        $this->assertStringContainsString('reset token issued', $body['message']);
    }

    public function testResetPasswordNotFound(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->once())
            ->method('findById')
            ->with('999')
            ->willReturn(null);

        $controller = new AdminUserController($repo);
        $response = $controller->resetPassword($this->makeRequest(), ['id' => '999']);

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
        /** @var array{users: list<array<string, mixed>>} $body */
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
        /** @var array{users: list<array<string, mixed>>} $body */
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
        /** @var array{error: string} $body */
        $body = json_decode($response->body, true);
        $this->assertStringContainsString('pending', $body['error']);
    }

    // ─────────────────────────────────────────────────────────────────
    // SV-2.7: AuthManager user-status cache invalidation wiring
    //
    // Every action here that mutates a user's `status` column must
    // immediately invalidate AuthManager's in-worker status cache so a
    // status change takes effect on THIS worker's very next request for
    // that user, instead of waiting out the cache TTL. All other tests in
    // this file construct the controller with no AuthManager (the default
    // null) and already prove that omission is a harmless no-op (the
    // nullsafe `?->` call), so these tests only need to cover the
    // "AuthManager IS wired" side.
    // ─────────────────────────────────────────────────────────────────

    public function testApproveInvalidatesAuthManagerUserStatusCache(): void
    {
        $user = ['id' => '3', 'username' => 'nina', 'status' => 'pending'];

        $repo = $this->createMock(UserRepository::class);
        $repo->method('findById')->with('3')->willReturn($user);
        $repo->expects($this->once())->method('setStatus')->with('3', 'active');

        $authManager = $this->createMock(AuthManager::class);
        $authManager->expects($this->once())
            ->method('invalidateUserStatusCache')
            ->with('3');

        $controller = new AdminUserController($repo, $authManager);
        $response = $controller->approve($this->makeRequest(), ['id' => '3']);

        $this->assertSame(200, $response->statusCode);
    }

    public function testDisableInvalidatesAuthManagerUserStatusCache(): void
    {
        $this->setCurrentUser('1');
        $user = ['id' => '3', 'username' => 'nina', 'status' => 'active', 'is_admin' => 0];

        $repo = $this->createMock(UserRepository::class);
        $repo->method('findById')->with('3')->willReturn($user);
        $repo->expects($this->once())->method('setStatus')->with('3', 'disabled');

        $authManager = $this->createMock(AuthManager::class);
        $authManager->expects($this->once())
            ->method('invalidateUserStatusCache')
            ->with('3');

        $controller = new AdminUserController($repo, $authManager);
        $response = $controller->disable($this->makeRequest(), ['id' => '3']);

        $this->assertSame(200, $response->statusCode);
        $this->clearCurrentUser();
    }

    public function testDisableDoesNotInvalidateWhenBlockedByLastAdminGuard(): void
    {
        $this->setCurrentUser('1');
        $user = ['id' => '2', 'username' => 'admin2', 'status' => 'active', 'is_admin' => 1];

        $repo = $this->createMock(UserRepository::class);
        $repo->method('findById')->with('2')->willReturn($user);
        $repo->method('countUsers')->with('is_admin = 1')->willReturn(1);
        $repo->expects($this->never())->method('setStatus');

        $authManager = $this->createMock(AuthManager::class);
        $authManager->expects($this->never())->method('invalidateUserStatusCache');

        $controller = new AdminUserController($repo, $authManager);
        $response = $controller->disable($this->makeRequest(), ['id' => '2']);

        $this->assertSame(400, $response->statusCode);
        $this->clearCurrentUser();
    }

    public function testRejectInvalidatesAuthManagerUserStatusCache(): void
    {
        $user = ['id' => '3', 'username' => 'nina', 'status' => 'pending'];

        $repo = $this->createMock(UserRepository::class);
        $repo->method('findById')->with('3')->willReturn($user);
        $repo->expects($this->once())->method('delete')->with('3');

        $authManager = $this->createMock(AuthManager::class);
        $authManager->expects($this->once())
            ->method('invalidateUserStatusCache')
            ->with('3');

        $controller = new AdminUserController($repo, $authManager);
        $response = $controller->reject($this->makeRequest(), ['id' => '3']);

        $this->assertSame(200, $response->statusCode);
    }

    public function testDeleteInvalidatesAuthManagerUserStatusCache(): void
    {
        $user = ['id' => '2', 'username' => 'bob', 'email' => 'bob@example.com', 'is_admin' => 0];
        $this->setCurrentUser('1');

        $repo = $this->createMock(UserRepository::class);
        $repo->method('findById')->with('2')->willReturn($user);
        $repo->expects($this->once())->method('delete')->with('2');

        $authManager = $this->createMock(AuthManager::class);
        $authManager->expects($this->once())
            ->method('invalidateUserStatusCache')
            ->with('2');

        $controller = new AdminUserController($repo, $authManager);
        $response = $controller->delete($this->makeRequest(), ['id' => '2']);

        $this->assertSame(200, $response->statusCode);
        $this->clearCurrentUser();
    }
}
