<?php

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers\Admin;

use Phlix\Auth\UserRepository;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\RequestContext;
use Phlix\Server\Http\Response;

/**
 * Admin JSON API for user management (Step 1.2a).
 *
 * Provides 7 REST endpoints for administering server users:
 * - GET    /api/v1/admin/users          — list all users
 * - GET    /api/v1/admin/users/{id}     — get a single user
 * - POST   /api/v1/admin/users         — create a new user
 * - PUT    /api/v1/admin/users/{id}     — update an existing user
 * - DELETE /api/v1/admin/users/{id}    — delete a user
 * - POST   /api/v1/admin/users/{id}/set-admin — promote or demote admin status
 * - POST   /api/v1/admin/users/{id}/reset-password — generate a new password
 *
 * All routes are gated by {@see \Phlix\Server\Http\Middleware\AdminMiddleware}
 * (registered in {@see \Phlix\Server\Http\Routes\AdminRoutes}); non-admin
 * callers receive a JSON 401/403 from the middleware. This controller assumes
 * it only runs for authenticated admins.
 *
 * @package Phlix\Server\Http\Controllers\Admin
 * @since 1.2a
 */
final class AdminUserController
{
    /**
     * @param UserRepository $userRepository Repository for user data access
     */
    public function __construct(
        private readonly UserRepository $userRepository,
    ) {
    }

    /**
     * List all users.
     *
     * @return Response 200 { users: User[] }
     */
    public function list(): Response
    {
        $users = $this->userRepository->findAll();
        return (new Response())->json(['users' => $users]);
    }

    /**
     * Get a single user by ID.
     *
     * @param int $id User ID
     *
     * @return Response 200 { user: User } | 404 { error }
     */
    public function get(int $id): Response
    {
        $user = $this->userRepository->findById((string) $id);
        if ($user === null) {
            return (new Response())->status(404)->json(['error' => 'User not found']);
        }
        return (new Response())->json(['user' => $user]);
    }

    /**
     * Create a new user.
     *
     * @param Request $req Request with username, email, password, and optional is_admin
     *
     * @return Response 201 { user_id: int, message: string } | 400 { error, field_errors?: object }
     */
    public function create(Request $req): Response
    {
        $username = is_string($req->input('username')) ? trim($req->input('username')) : '';
        $email = is_string($req->input('email')) ? trim($req->input('email')) : '';
        $password = is_string($req->input('password')) ? $req->input('password') : '';
        $isAdmin = (bool) $req->input('is_admin', false);

        // Validate username: 3-50 chars, alphanumeric + underscore
        if (strlen($username) < 3 || strlen($username) > 50) {
            return (new Response())->status(400)->json([
                'error' => 'Invalid username',
                'field_errors' => ['username' => 'Username must be 3-50 characters'],
            ]);
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            return (new Response())->status(400)->json([
                'error' => 'Invalid username',
                'field_errors' => ['username' => 'Username must be alphanumeric with underscores only'],
            ]);
        }

        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return (new Response())->status(400)->json([
                'error' => 'Invalid email',
                'field_errors' => ['email' => 'Invalid email format'],
            ]);
        }

        // Validate password: min 8 chars
        if (strlen($password) < 8) {
            return (new Response())->status(400)->json([
                'error' => 'Invalid password',
                'field_errors' => ['password' => 'Password must be at least 8 characters'],
            ]);
        }

        // Check email uniqueness
        if ($this->userRepository->emailExists($email)) {
            return (new Response())->status(400)->json([
                'error' => 'Email already exists',
                'field_errors' => ['email' => 'This email is already registered'],
            ]);
        }

        // Hash password and create user
        $hashedPassword = $this->hashPassword($password);
        $userId = $this->userRepository->create([
            'username' => $username,
            'email' => $email,
            'password' => $hashedPassword,
            'is_admin' => $isAdmin ? 1 : 0, // DB column is TINYINT(1), not boolean
        ]);

        return (new Response())->status(201)->json([
            'user_id' => $userId,
            'message' => 'User created successfully',
        ]);
    }

    /**
     * Update an existing user.
     *
     * @param int     $id  User ID
     * @param Request $req Request with optional username, email, password
     *
     * @return Response 200 { message } | 404 | 400 { error }
     */
    public function update(int $id, Request $req): Response
    {
        $user = $this->userRepository->findById((string) $id);
        if ($user === null) {
            return (new Response())->status(404)->json(['error' => 'User not found']);
        }

        $username = $req->input('username');
        $email = $req->input('email');
        $password = $req->input('password');

        // Validate username if provided
        if ($username !== null) {
            $username = is_string($username) ? trim($username) : '';
            if (strlen($username) < 3 || strlen($username) > 50) {
                return (new Response())->status(400)->json([
                    'error' => 'Invalid username',
                    'field_errors' => ['username' => 'Username must be 3-50 characters'],
                ]);
            }
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
                return (new Response())->status(400)->json([
                    'error' => 'Invalid username',
                    'field_errors' => ['username' => 'Username must be alphanumeric with underscores only'],
                ]);
            }
        }

        // Validate email if provided
        if ($email !== null) {
            $email = is_string($email) ? trim($email) : '';
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return (new Response())->status(400)->json([
                    'error' => 'Invalid email',
                    'field_errors' => ['email' => 'Invalid email format'],
                ]);
            }
            // Check email uniqueness (excluding current user)
            if ($this->userRepository->emailExists($email, (int) $id)) {
                return (new Response())->status(400)->json([
                    'error' => 'Email already in use',
                    'field_errors' => ['email' => 'This email is already registered'],
                ]);
            }
        }

        // Validate password if provided
        if ($password !== null) {
            $password = is_string($password) ? $password : '';
            if (strlen($password) < 8) {
                return (new Response())->status(400)->json([
                    'error' => 'Invalid password',
                    'field_errors' => ['password' => 'Password must be at least 8 characters'],
                ]);
            }
        }

        // Build update data
        $data = [];
        if ($username !== null) {
            $data['username'] = $username;
        }
        if ($email !== null) {
            $data['email'] = $email;
        }
        if ($password !== null) {
            $data['password'] = $this->hashPassword($password);
        }

        if ($data !== []) {
            $this->userRepository->update((string) $id, $data);
        }

        return (new Response())->json(['message' => 'User updated successfully']);
    }

    /**
     * Delete a user.
     *
     * @param int $id User ID
     *
     * @return Response 200 { message } | 404 | 400 { error }
     */
    public function delete(int $id): Response
    {
        $user = $this->userRepository->findById((string) $id);
        if ($user === null) {
            return (new Response())->status(404)->json(['error' => 'User not found']);
        }

        // Cannot delete own account
        $currentUserId = RequestContext::getUserId();
        if ($currentUserId !== null && (int) $currentUserId === $id) {
            return (new Response())->status(400)->json(['error' => 'Cannot delete your own account']);
        }

        // Cannot delete the last admin
        if (!empty($user['is_admin'])) {
            $adminCount = $this->countAdmins();
            if ($adminCount <= 1) {
                return (new Response())->status(400)->json(['error' => 'Cannot delete the last admin']);
            }
        }

        $this->userRepository->delete((string) $id);
        return (new Response())->json(['message' => 'User deleted successfully']);
    }

    /**
     * Promote or demote a user's admin status.
     *
     * @param int     $id  User ID
     * @param Request $req Request with is_admin (bool)
     *
     * @return Response 200 { message } | 404 | 400 { error }
     */
    public function setAdmin(int $id, Request $req): Response
    {
        $user = $this->userRepository->findById((string) $id);
        if ($user === null) {
            return (new Response())->status(404)->json(['error' => 'User not found']);
        }

        $isAdmin = (bool) $req->input('is_admin');

        // Cannot demote yourself
        $currentUserId = RequestContext::getUserId();
        if ($currentUserId !== null && (int) $currentUserId === $id && !$isAdmin) {
            return (new Response())->status(400)->json(['error' => 'Cannot demote yourself']);
        }

        // Cannot demote the last admin
        if (!$isAdmin && !empty($user['is_admin'])) {
            $adminCount = $this->countAdmins();
            if ($adminCount <= 1) {
                return (new Response())->status(400)->json(['error' => 'Cannot demote the last admin']);
            }
        }

        $this->userRepository->setAdmin((string) $id, $isAdmin);
        return (new Response())->json(['message' => 'User admin status updated successfully']);
    }

    /**
     * Reset a user's password to a randomly generated value.
     *
     * @param int $id User ID
     *
     * @return Response 200 { message, new_password: string } | 404
     */
    public function resetPassword(int $id): Response
    {
        $user = $this->userRepository->findById((string) $id);
        if ($user === null) {
            return (new Response())->status(404)->json(['error' => 'User not found']);
        }

        $newPassword = $this->generatePassword();
        $hashedPassword = $this->hashPassword($newPassword);
        $this->userRepository->update((string) $id, ['password' => $hashedPassword]);

        return (new Response())->json([
            'message' => 'Password reset successfully',
            'new_password' => $newPassword,
        ]);
    }

    /**
     * Count the number of admin users.
     *
     * @return int Number of users with is_admin = 1
     */
    private function countAdmins(): int
    {
        return $this->userRepository->countUsers('is_admin = 1');
    }

    /**
     * Hash a plain text password using Argon2ID.
     *
     * @param string $plaintext Plain text password
     *
     * @return string Hashed password
     */
    private function hashPassword(string $plaintext): string
    {
        return password_hash($plaintext, PASSWORD_ARGON2ID);
    }

    /**
     * Generate a random 12-character password.
     *
     * @return string Random password
     */
    private function generatePassword(): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
        $password = '';
        $max = strlen($chars) - 1;
        for ($i = 0; $i < 12; $i++) {
            $password .= $chars[random_int(0, $max)];
        }
        return $password;
    }
}
