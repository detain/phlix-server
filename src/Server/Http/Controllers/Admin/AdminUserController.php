<?php

/**
 * Phlix media server component: Admin.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers\Admin;

use Phlix\Auth\UserRepository;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\RequestContext;
use Phlix\Server\Http\Response;

/**
 * Admin JSON API for user management (Step 1.2a).
 *
 * Provides REST endpoints for administering server users:
 * - GET    /api/v1/admin/users          — list all users (?status= filter)
 * - GET    /api/v1/admin/users/{id}     — get a single user
 * - POST   /api/v1/admin/users         — create a new user
 * - PUT    /api/v1/admin/users/{id}     — update an existing user
 * - DELETE /api/v1/admin/users/{id}    — delete a user
 * - POST   /api/v1/admin/users/{id}/set-admin — promote or demote admin status
 * - POST   /api/v1/admin/users/{id}/reset-password — generate a new password
 * - POST   /api/v1/admin/users/{id}/approve — approve a pending signup (S1)
 * - POST   /api/v1/admin/users/{id}/disable — disable a user (S1)
 * - POST   /api/v1/admin/users/{id}/reject  — reject (delete) a pending signup (S1)
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
     * List users, optionally filtered by account status.
     *
     * `GET /api/v1/admin/users` returns every user; `?status=pending`
     * (or active|disabled) narrows the list — used by the admin UI to render
     * the pending-approval queue (S1). Each row includes the `status` column.
     *
     * @param Request|null $request The HTTP request (optional `status` query).
     *
     * @return Response 200 { users: User[] }
     */
    public function list(?Request $request = null): Response
    {
        $status = $request !== null ? $request->queryString('status') : null;

        if (is_string($status) && in_array($status, ['pending', 'active', 'disabled'], true)) {
            $users = $this->userRepository->listByStatus($status);
        } else {
            $users = $this->userRepository->findAll();
        }

        return (new Response())->json(['users' => $users]);
    }

    /**
     * Approve a pending user: set status='active' so they can log in (S1).
     *
     * @param Request              $request The HTTP request (unused body).
     * @param array<string, string> $params Path parameters ({id}).
     *
     * @return Response 200 { message } | 404 { error }
     */
    public function approve(Request $request, array $params): Response
    {
        return $this->changeStatus($params['id'] ?? '', 'active', 'User approved successfully');
    }

    /**
     * Disable a user: set status='disabled' so they can no longer log in (S1).
     *
     * Refuses to disable the last remaining admin so the box can't lock itself
     * out, mirroring {@see delete()} / {@see setAdmin()}.
     *
     * @param Request              $request The HTTP request (unused body).
     * @param array<string, string> $params Path parameters ({id}).
     *
     * @return Response 200 { message } | 404 | 400 { error }
     */
    public function disable(Request $request, array $params): Response
    {
        $id = $params['id'] ?? '';
        $user = $this->userRepository->findById($id);
        if ($user === null) {
            return (new Response())->status(404)->json(['error' => 'User not found']);
        }

        // Cannot disable yourself.
        $currentUserId = RequestContext::getUserId();
        if ($currentUserId !== null && (string) $currentUserId === $id) {
            return (new Response())->status(400)->json(['error' => 'Cannot disable your own account']);
        }

        // Cannot disable the last admin.
        if (!empty($user['is_admin']) && $this->countAdmins() <= 1) {
            return (new Response())->status(400)->json(['error' => 'Cannot disable the last admin']);
        }

        $this->userRepository->setStatus($id, 'disabled');
        return (new Response())->json(['message' => 'User disabled successfully']);
    }

    /**
     * Reject a pending user: delete the account (S1).
     *
     * Only meaningful for a still-pending account; an already-active account
     * should be disabled instead, so this refuses non-pending users.
     *
     * @param Request              $request The HTTP request (unused body).
     * @param array<string, string> $params Path parameters ({id}).
     *
     * @return Response 200 { message } | 404 | 400 { error }
     */
    public function reject(Request $request, array $params): Response
    {
        $id = $params['id'] ?? '';
        $user = $this->userRepository->findById($id);
        if ($user === null) {
            return (new Response())->status(404)->json(['error' => 'User not found']);
        }

        $status = is_string($user['status'] ?? null) ? $user['status'] : 'active';
        if ($status !== 'pending') {
            return (new Response())->status(400)->json([
                'error' => 'Only pending users can be rejected; disable an active account instead',
            ]);
        }

        $this->userRepository->delete($id);
        return (new Response())->json(['message' => 'User rejected successfully']);
    }

    /**
     * Shared helper: ensure the user exists, then set its status.
     *
     * @param string $id            User UUID.
     * @param string $status        Target status (active|disabled|pending).
     * @param string $successMessage Message returned on success.
     *
     * @return Response 200 { message } | 404 { error }
     */
    private function changeStatus(string $id, string $status, string $successMessage): Response
    {
        $user = $this->userRepository->findById($id);
        if ($user === null) {
            return (new Response())->status(404)->json(['error' => 'User not found']);
        }

        $this->userRepository->setStatus($id, $status);
        return (new Response())->json(['message' => $successMessage]);
    }

    /**
     * Get a single user by ID.
     *
     * @param Request               $request The HTTP request (unused body).
     * @param array<string, string> $params  Path parameters ({id} — a UUID string).
     *
     * @return Response 200 { user: User } | 404 { error }
     */
    public function get(Request $request, array $params): Response
    {
        $id = is_string($params['id'] ?? null) ? $params['id'] : '';
        $user = $this->userRepository->findById($id);
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
     * @param Request               $request The HTTP request (optional username, email, password).
     * @param array<string, string> $params  Path parameters ({id} — a UUID string).
     *
     * @return Response 200 { message } | 404 | 400 { error }
     */
    public function update(Request $request, array $params): Response
    {
        $id = is_string($params['id'] ?? null) ? $params['id'] : '';
        $user = $this->userRepository->findById($id);
        if ($user === null) {
            return (new Response())->status(404)->json(['error' => 'User not found']);
        }

        $username = $request->input('username');
        $email = $request->input('email');
        $password = $request->input('password');

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
            if ($this->userRepository->emailExists($email, $id)) {
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
            $this->userRepository->update($id, $data);
        }

        return (new Response())->json(['message' => 'User updated successfully']);
    }

    /**
     * Delete a user.
     *
     * @param Request               $request The HTTP request (unused body).
     * @param array<string, string> $params  Path parameters ({id} — a UUID string).
     *
     * @return Response 200 { message } | 404 | 400 { error }
     */
    public function delete(Request $request, array $params): Response
    {
        $id = is_string($params['id'] ?? null) ? $params['id'] : '';
        $user = $this->userRepository->findById($id);
        if ($user === null) {
            return (new Response())->status(404)->json(['error' => 'User not found']);
        }

        // Cannot delete own account
        $currentUserId = RequestContext::getUserId();
        if ($currentUserId !== null && (string) $currentUserId === $id) {
            return (new Response())->status(400)->json(['error' => 'Cannot delete your own account']);
        }

        // Cannot delete the last admin
        if (!empty($user['is_admin'])) {
            $adminCount = $this->countAdmins();
            if ($adminCount <= 1) {
                return (new Response())->status(400)->json(['error' => 'Cannot delete the last admin']);
            }
        }

        $this->userRepository->delete($id);
        return (new Response())->json(['message' => 'User deleted successfully']);
    }

    /**
     * Promote or demote a user's admin status.
     *
     * @param Request               $request The HTTP request (is_admin bool).
     * @param array<string, string> $params  Path parameters ({id} — a UUID string).
     *
     * @return Response 200 { message } | 404 | 400 { error }
     */
    public function setAdmin(Request $request, array $params): Response
    {
        $id = is_string($params['id'] ?? null) ? $params['id'] : '';
        $user = $this->userRepository->findById($id);
        if ($user === null) {
            return (new Response())->status(404)->json(['error' => 'User not found']);
        }

        $isAdmin = (bool) $request->input('is_admin');

        // Cannot demote yourself
        $currentUserId = RequestContext::getUserId();
        if ($currentUserId !== null && (string) $currentUserId === $id && !$isAdmin) {
            return (new Response())->status(400)->json(['error' => 'Cannot demote yourself']);
        }

        // Cannot demote the last admin
        if (!$isAdmin && !empty($user['is_admin'])) {
            $adminCount = $this->countAdmins();
            if ($adminCount <= 1) {
                return (new Response())->status(400)->json(['error' => 'Cannot demote the last admin']);
            }
        }

        $this->userRepository->setAdmin($id, $isAdmin);
        return (new Response())->json(['message' => 'User admin status updated successfully']);
    }

    /**
     * Issue a one-time password reset token for a user.
     *
     * S7+F1: Instead of returning a plaintext password (which is a security
     * risk), this method generates a cryptographically secure random token,
     * stores it as a hash in the database with a 15-minute expiry, and sets
     * the `must_change_password` flag so the user is forced to set a new
     * password before accessing content.
     *
     * The token itself should be sent to the user via an out-of-band channel
     * (e.g. email) — this endpoint only stores the hashed token.
     *
     * @param Request               $request The HTTP request (unused body).
     * @param array<string, string> $params  Path parameters ({id} — a UUID string).
     *
     * @return Response 200 { message } | 404
     */
    public function resetPassword(Request $request, array $params): Response
    {
        $id = is_string($params['id'] ?? null) ? $params['id'] : '';
        $user = $this->userRepository->findById($id);
        if ($user === null) {
            return (new Response())->status(404)->json(['error' => 'User not found']);
        }

        // Generate a 32-byte (64-character hex) cryptographically secure token.
        $token = bin2hex(random_bytes(32));
        $hashedToken = password_hash($token, PASSWORD_ARGON2ID);
        $expiresAt = time() + 900; // 15 minutes from now.

        // Store the hashed token, its expiry, and force a password change.
        $this->userRepository->setPasswordResetToken($id, $hashedToken, $expiresAt);
        $this->userRepository->setMustChangePassword($id, true);

        // NOTE: In a full implementation, the plaintext $token would be sent
        // to the user via email here. The out-of-band delivery is handled by
        // external notification infrastructure; this endpoint only records the
        // hashed token so the user can submit it via the reset-password form.

        return (new Response())->json([
            'message' => 'Password reset token issued. The user must change their password before accessing content.',
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
}
