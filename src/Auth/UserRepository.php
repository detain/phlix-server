<?php

/**
 * Phlix media server component: Auth.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Auth;

use Phlix\Auth\Dto\UserRow;
use Phlix\Common\Uuid;
use Phlix\Common\Util\RowMap;
use Workerman\MySQL\Connection;

/**
 * User repository for user data access and management.
 *
 * This class provides comprehensive data access operations for user
 * management including user creation, retrieval, updates, password
 * verification, and user settings management.
 *
 * @author Phlix Team
 * @version 1.0.0
 * @description Provides data access layer for user entities with support
 *              for authentication, profile management, and settings storage.
 * @see AuthManager For authentication orchestration
 * @see UserProfileManager For profile-specific operations
 *
 * @property Connection $db Database connection instance
 */
class UserRepository
{
    /** @var Connection Database connection for MySQL queries */
    private Connection $db;

    // NOTE: These caches were previously `private static`, which made them
    // process-wide (shared across every UserRepository instance in the same
    // Workerman worker / PHPUnit process). Under PHPUnit's default
    // single-process execution this meant one test's cached row (keyed on a
    // generic id/username like "user-1" or "alice") could leak into an
    // unrelated, later-running test that happened to reuse the same key with
    // a different mock DB — producing order-dependent failures (see
    // UserRepositoryStatusTest::test_get_status_returns_disabled_for_disabled_user
    // and ProviderManagerTest::test_fallback_to_password_auth). Each
    // UserRepository is already constructed per-request (bound to a single
    // `$db` connection), so scoping the cache to the instance gives the same
    // within-request caching behaviour without the cross-instance leak.

    /** @var array<string, array{user: array<string, mixed>, expires_at: int}> User cache keyed by id */
    private array $cacheById = [];

    /** @var array<string, array{user: array<string, mixed>, expires_at: int}> User cache keyed by username */
    private array $cacheByUsername = [];

    /** @var array<string, array{user: array<string, mixed>, expires_at: int}> User cache keyed by email */
    private array $cacheByEmail = [];

    /** @var array<string, array{status: string|null, expires_at: int}> Status cache keyed by user id */
    private array $statusCacheById = [];

    /** @var int Cache TTL in seconds (60 seconds) */
    private const CACHE_TTL = 60;

    /**
     * Create a new UserRepository instance.
     *
     * @param Connection $db Workerman MySQL connection instance
     *
     * @example
     * ```php
     * $repo = new UserRepository($dbConnection);
     * ```
     */
    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * Find a user by their unique identifier.
     *
     * @param string $id User UUID to look up
     *
     * @return array<string, mixed>|null User record with all fields including
     *         password_hash, or null if user not found
     *
     * @example
     * ```php
     * $user = $repo->findById('550e8400-e29b-41d4-a716-446655440000');
     * ```
     */
    public function findById(string $id): ?array
    {
        $now = time();

        // Check cache first
        if (isset($this->cacheById[$id])) {
            $entry = $this->cacheById[$id];
            if ($entry['expires_at'] > $now) {
                return $entry['user'];
            }
            unset($this->cacheById[$id]);
        }

        $result = $this->db->query("SELECT * FROM users WHERE id = ?", [$id]);
        $user = UserRow::firstFromMixed($result);

        if ($user !== null) {
            $this->cacheById[$id] = [
                'user' => $user,
                'expires_at' => $now + self::CACHE_TTL,
            ];
        }

        return $user;
    }

    /**
     * Find a user by their username.
     *
     * @param string $username Username to look up (case-sensitive)
     *
     * @return array<string, mixed>|null User record or null if not found
     *
     * @example
     * ```php
     * $user = $repo->findByUsername('john_doe');
     * ```
     */
    public function findByUsername(string $username): ?array
    {
        $now = time();

        // Check cache first
        if (isset($this->cacheByUsername[$username])) {
            $entry = $this->cacheByUsername[$username];
            if ($entry['expires_at'] > $now) {
                return $entry['user'];
            }
            unset($this->cacheByUsername[$username]);
        }

        $result = $this->db->query(
            "SELECT * FROM users WHERE username = ?",
            [$username]
        );
        $user = UserRow::firstFromMixed($result);

        if ($user !== null) {
            $this->cacheByUsername[$username] = [
                'user' => $user,
                'expires_at' => $now + self::CACHE_TTL,
            ];
        }

        return $user;
    }

    /**
     * Find a user by id, but only when the row's `is_admin` flag is
     * set. Returns `null` for unknown ids and for known-but-non-admin
     * users alike. Used by
     * {@see \Phlix\Server\Http\Middleware\AdminMiddleware} to gate the
     * `/api/v1/admin/*` JSON API in Step A.5.
     *
     * Security: callers MUST treat any non-null return as "this user
     * is allowed to perform privileged operations". Do not leak the
     * distinction between "user does not exist" and "user is not
     * admin" to the HTTP boundary — both should map to 403 / 404.
     *
     * @param string $id User UUID to look up.
     *
     * @return array<string, mixed>|null Row when the user exists and
     *         `is_admin = 1`, otherwise null.
     *
     * @since 0.10.0 (Step A.5)
     */
    public function findAdminById(string $id): ?array
    {
        // S1 security fix: a disabled admin must be treated as a non-admin, so
        // gate on status = 'active' as well as is_admin. Without this predicate
        // a suspended admin still passes AdminMiddleware::checkAccess().
        $result = $this->db->query(
            "SELECT * FROM users WHERE id = ? AND is_admin = 1 AND status = 'active'",
            [$id]
        );
        if (!is_array($result) || !isset($result[0]) || !is_array($result[0])) {
            return null;
        }
        /** @var array<string, mixed> $row */
        $row = $result[0];
        return $row;
    }

    /**
     * Look up only a user's account status by id.
     *
     * S1 security fix: the authenticated hot path (token refresh + per-request
     * access-token validation in {@see \Phlix\Auth\AuthManager}) uses this to
     * re-check the backing user's status so an account disabled mid-session is
     * revoked rather than continuing to work until its token expires. Kept to a
     * single lightweight lookup on the primary key, selecting only `status`.
     *
     * @param string $id User UUID to look up.
     *
     * @return string|null The stored status string, or null when the user does
     *         not exist (caller treats null as "not active").
     *
     * @since S1 (signup approval gate — security follow-up)
     */
    public function getStatus(string $id): ?string
    {
        $now = time();

        // Check cache first
        if (isset($this->statusCacheById[$id])) {
            $entry = $this->statusCacheById[$id];
            if ($entry['expires_at'] > $now) {
                return $entry['status'];
            }
            unset($this->statusCacheById[$id]);
        }

        $result = $this->db->query(
            "SELECT status FROM users WHERE id = ?",
            [$id]
        );
        $status = UserRow::string(UserRow::firstFromMixed($result), 'status');

        $this->statusCacheById[$id] = [
            'status' => $status,
            'expires_at' => $now + self::CACHE_TTL,
        ];

        return $status;
    }

    /**
     * Total number of rows in the `users` table, optionally filtered by a predicate.
     *
     * Used by {@see \Phlix\Auth\AuthManager::register()} to detect the very
     * first registration on a fresh install and auto-promote that user
     * to admin (Step A.5 minimum-viable admin bootstrap).
     *
     * @param string $predicate Optional SQL WHERE clause (e.g. "is_admin = 1").
     *                           SECURITY: The predicate is appended directly to
     *                           the COUNT query after "WHERE " — callers MUST pass
     *                           ONLY hardcoded, internal literals (no user input).
     *                           In practice only 'is_admin = 1' is ever passed from
     *                           AdminUserController::countAdmins().
     *
     * @return int Total user count (>= 0).
     *
     * @since 0.10.0 (Step A.5)
     */
    public function countUsers(string $predicate = ''): int
    {
        $sql = 'SELECT COUNT(*) AS c FROM users';
        if ($predicate !== '') {
            $sql .= ' WHERE ' . $predicate;
        }
        $row = UserRow::firstFromMixed($this->db->query($sql));
        return UserRow::int($row, 'c', 0);
    }

    /**
     * Return all users.
     *
     * @return array<int, array<string, mixed>> Array of user rows
     */
    public function findAll(): array
    {
        $result = $this->db->query('SELECT * FROM users');
        if (!is_array($result)) {
            return [];
        }
        /** @var array<int, array<string, mixed>> */
        return $result;
    }

    /**
     * Delete a user by ID.
     *
     * @param string $id User UUID to delete
     *
     * @return void
     */
    public function delete(string $id): void
    {
        $this->db->query('DELETE FROM users WHERE id = ?', [$id]);

        // Invalidate caches for this user
        unset($this->cacheById[$id]);
        unset($this->statusCacheById[$id]);
    }

    /**
     * Promote (or demote) a user's admin flag.
     *
     * @param string $id      User UUID to update.
     * @param bool   $isAdmin Whether the user should be admin.
     *
     * @since 0.10.0 (Step A.5)
     */
    public function setAdmin(string $id, bool $isAdmin): void
    {
        $this->db->query(
            "UPDATE users SET is_admin = ? WHERE id = ?",
            [$isAdmin ? 1 : 0, $id]
        );

        // Invalidate caches for this user - admin status affects auth decisions
        unset($this->cacheById[$id]);
    }

    /**
     * Set a user's account status.
     *
     * Part of the signup approval gate (S1): admins move a 'pending' account to
     * 'active' (approve) or 'disabled' (suspend); registration writes 'pending'
     * or 'active' through {@see create()}.
     *
     * @param string $id     User UUID to update.
     * @param string $status One of 'pending', 'active', 'disabled'. Any other
     *                       value is ignored (no-op) so the ENUM is never fed a
     *                       bad value.
     *
     * @return void
     *
     * @since S1 (signup approval gate)
     */
    public function setStatus(string $id, string $status): void
    {
        if (!in_array($status, ['pending', 'active', 'disabled'], true)) {
            return;
        }
        $this->db->query(
            "UPDATE users SET status = ? WHERE id = ?",
            [$status, $id]
        );

        // Invalidate caches for this user - status affects session validity
        unset($this->cacheById[$id]);
        unset($this->statusCacheById[$id]);
    }

    /**
     * Return all users whose account status matches the given value.
     *
     * Backs the admin "pending approval" queue
     * (`GET /api/v1/admin/users?status=pending`).
     *
     * @param string $status One of 'pending', 'active', 'disabled'.
     *
     * @return array<int, array<string, mixed>> Matching user rows (empty when
     *         the status is invalid or no rows match).
     *
     * @since S1 (signup approval gate)
     */
    public function listByStatus(string $status): array
    {
        if (!in_array($status, ['pending', 'active', 'disabled'], true)) {
            return [];
        }
        $result = $this->db->query('SELECT * FROM users WHERE status = ?', [$status]);
        if (!is_array($result)) {
            return [];
        }
        /** @var array<int, array<string, mixed>> */
        return $result;
    }

    /**
     * Find a user by their email address.
     *
     * @param string $email Email address to look up (case-sensitive)
     *
     * @return array<string, mixed>|null User record or null if not found
     *
     * @example
     * ```php
     * $user = $repo->findByEmail('john@example.com');
     * ```
     */
    public function findByEmail(string $email): ?array
    {
        $now = time();

        // Check cache first
        if (isset($this->cacheByEmail[$email])) {
            $entry = $this->cacheByEmail[$email];
            if ($entry['expires_at'] > $now) {
                return $entry['user'];
            }
            unset($this->cacheByEmail[$email]);
        }

        $result = $this->db->query(
            "SELECT * FROM users WHERE email = ?",
            [$email]
        );
        $user = UserRow::firstFromMixed($result);

        if ($user !== null) {
            $this->cacheByEmail[$email] = [
                'user' => $user,
                'expires_at' => $now + self::CACHE_TTL,
            ];
        }

        return $user;
    }

    /**
     * Create a new user account.
     *
     * Creates a new user with hashed password using Argon2ID and initializes
     * default user settings. Returns the new user's UUID.
     *
     * @param array<string, mixed> $data User data including:
     *        - username: Unique username (required)
     *        - email: Valid email address (required)
     *        - password: Plain text password (required, will be hashed)
     *        - display_name: Display name (optional, defaults to username)
     *        - status: Account status enum (optional, defaults to 'active' so
     *                  existing callers keep creating immediately-usable users;
     *                  the signup approval gate passes 'pending' when the
     *                  `auth.signup_mode` setting is 'approval').
     *
     * @return string Generated UUID for the new user
     *
     * @throws \Exception If database insert fails
     *
     * @example
     * ```php
     * $userId = $repo->create([
     *     'username' => 'john_doe',
     *     'email' => 'john@example.com',
     *     'password' => 'secure_password',
     *     'display_name' => 'John Doe'
     * ]);
     * ```
     */
    public function create(array $data): string
    {
        $id = $this->generateUuid();
        $passwordRaw = $data['password'] ?? '';
        if (!is_string($passwordRaw)) {
            throw new \InvalidArgumentException('password must be a string');
        }
        $passwordHash = password_hash($passwordRaw, PASSWORD_ARGON2ID);

        // Default to 'active' so existing callers (admin user create, external
        // provider create) keep producing immediately-usable accounts. The
        // signup approval gate passes 'pending' explicitly. Any unknown value is
        // coerced back to 'active' rather than letting the ENUM reject it.
        $statusRaw = $data['status'] ?? 'active';
        $status = is_string($statusRaw) && in_array($statusRaw, ['pending', 'active', 'disabled'], true)
            ? $statusRaw
            : 'active';

        $this->db->query(
            "INSERT INTO users (id, username, email, password_hash, display_name, status) VALUES (?, ?, ?, ?, ?, ?)",
            [
                $id,
                $data['username'],
                $data['email'],
                $passwordHash,
                $data['display_name'] ?? $data['username'],
                $status,
            ]
        );

        // Create default settings
        $this->db->query(
            "INSERT INTO user_settings (user_id) VALUES (?)",
            [$id]
        );

        return $id;
    }

    /**
     * Update user profile data.
     *
     * Supports updating display_name, email, and password. Only provided
     * fields are updated; others remain unchanged.
     *
     * @param string $id User UUID to update
     * @param array<string, mixed> $data Fields to update:
     *        - display_name: New display name
     *        - email: New email address
     *        - password: New plain text password (will be hashed)
     *
     * @return void
     *
     * @example
     * ```php
     * $repo->update('user-uuid-123', [
     *     'display_name' => 'John Smith',
     *     'email' => 'newemail@example.com'
     * ]);
     * ```
     */
    public function update(string $id, array $data): void
    {
        $sets = [];
        $values = [];

        if (isset($data['display_name'])) {
            $sets[] = 'display_name = ?';
            $values[] = $data['display_name'];
        }

        if (isset($data['email'])) {
            $sets[] = 'email = ?';
            $values[] = $data['email'];
        }

        if (isset($data['password'])) {
            $passwordRaw = $data['password'];
            if (!is_string($passwordRaw)) {
                throw new \InvalidArgumentException('password must be a string');
            }
            $sets[] = 'password_hash = ?';
            $values[] = password_hash($passwordRaw, PASSWORD_ARGON2ID);
        }

        if (empty($sets)) {
            return;
        }

        $values[] = $id;
        $this->db->query(
            "UPDATE users SET " . implode(', ', $sets) . " WHERE id = ?",
            $values
        );

        // Invalidate caches for this user
        unset($this->cacheById[$id]);
    }

    /**
     * Update the user's last login timestamp.
     *
     * @param string $id User UUID to update
     *
     * @return void
     *
     * @example
     * ```php
     * $repo->updateLastLogin('user-uuid-123');
     * ```
     */
    public function updateLastLogin(string $id): void
    {
        $this->db->query("UPDATE users SET last_login = NOW() WHERE id = ?", [$id]);
    }

    /**
     * Get user settings including profile-related preferences.
     *
     * Retrieves user settings such as streaming preferences, content
     * ratings, and subtitle settings. Parses JSON-encoded fields.
     *
     * @param string $userId User UUID to get settings for
     *
     * @return array<string, mixed>|null User settings record or null if not found
     *
     * @example
     * ```php
     * $settings = $repo->getSettings('user-uuid-123');
     * if ($settings) {
     *     echo "Max streams: " . $settings['max_streams'];
     * }
     * ```
     */
    public function getSettings(string $userId): ?array
    {
        $result = $this->db->query(
            "SELECT * FROM user_settings WHERE user_id = ?",
            [$userId]
        );

        $settings = UserRow::firstFromMixed($result);
        if ($settings === null) {
            return null;
        }

        // Parse JSON fields if present
        if (isset($settings['transcoding_preferences']) && is_string($settings['transcoding_preferences'])) {
            $decoded = json_decode($settings['transcoding_preferences'], true);
            $settings['transcoding_preferences'] = is_array($decoded)
                ? RowMap::fromMixed($decoded)
                : [];
        }

        // Fetch active theme from user_profiles and add to settings
        $profileResult = $this->db->query(
            "SELECT active_theme_id FROM user_profiles WHERE user_id = ? AND is_active = TRUE",
            [$userId]
        );
        $profile = UserRow::firstFromMixed($profileResult);
        if ($profile !== null && isset($profile['active_theme_id']) && is_string($profile['active_theme_id'])) {
            $settings['theme'] = $profile['active_theme_id'];
        }

        return $settings;
    }

    /**
     * Update user settings.
     *
     * Supports updating streaming preferences, content ratings, and subtitle
     * settings. Creates settings record if it doesn't exist.
     *
     * @param string $userId User UUID to update settings for
     * @param array<string, mixed> $settings Settings to update:
     *        - max_streams: Maximum concurrent streams
     *        - max_bitrate: Maximum streaming bitrate
     *        - preferred_audio_language: Preferred audio language code
     *        - preferred_subtitle_language: Preferred subtitle language code
     *        - subtitle_mode: Subtitle display mode
     *        - default_content_rating: Default content rating filter
     *        - transcoding_preferences: Array of transcoding options
     *
     * @return void
     *
     * @example
     * ```php
     * $repo->updateSettings('user-uuid-123', [
     *     'max_streams' => 3,
     *     'preferred_audio_language' => 'eng'
     * ]);
     * ```
     */
    public function updateSettings(string $userId, array $settings): void
    {
        // Build a parallel column list and bound-value list. We keep the column
        // names and the placeholders separate so the INSERT and the UPDATE
        // clause are both well-formed — the previous implementation reused
        // "col = ?" fragments as INSERT column names, producing invalid SQL like
        // `INSERT INTO user_settings (user_id, max_streams = ?, ...)` that threw
        // on a user's first-ever save.
        $columns = [];
        $values = [];

        $allowedFields = [
            'max_streams',
            'max_bitrate',
            'preferred_audio_language',
            'preferred_subtitle_language',
            'subtitle_mode',
            'default_content_rating',
        ];

        foreach ($allowedFields as $field) {
            if (isset($settings[$field])) {
                $columns[] = $field;
                $values[] = $settings[$field];
            }
        }

        if (isset($settings['transcoding_preferences']) && is_array($settings['transcoding_preferences'])) {
            $columns[] = 'transcoding_preferences';
            $values[] = json_encode($settings['transcoding_preferences']);
        }

        if ($columns !== []) {
            // Upsert in a single statement (user_id is the PRIMARY KEY), matching the
            // INSERT ... ON DUPLICATE KEY UPDATE convention used elsewhere in this
            // codebase (e.g. AudiobookProgressStore). On a new row the VALUES() are
            // inserted; on an existing row only the supplied columns are updated.
            $insertColumns = array_merge(['user_id'], $columns);
            $placeholders = implode(', ', array_fill(0, count($insertColumns), '?'));
            $updateClause = implode(
                ', ',
                array_map(static fn (string $col): string => "{$col} = VALUES({$col})", $columns)
            );

            $sql = 'INSERT INTO user_settings (' . implode(', ', $insertColumns) . ')'
                . ' VALUES (' . $placeholders . ')'
                . ' ON DUPLICATE KEY UPDATE ' . $updateClause;

            $this->db->query($sql, array_merge([$userId], $values));
        }

        // Handle theme separately — it goes to user_profiles, not user_settings
        if (isset($settings['theme']) && is_string($settings['theme'])) {
            $validThemes = ['phlix-light', 'phlix-dark', 'phlix-amoled', 'phlix-contrast'];
            if (in_array($settings['theme'], $validThemes, true)) {
                $this->db->query(
                    'UPDATE user_profiles SET active_theme_id = ? WHERE user_id = ? AND is_active = TRUE',
                    [$settings['theme'], $userId]
                );
            }
        }
    }

    /**
     * Update user avatar URL.
     *
     * @param string $userId User UUID to update
     * @param string $avatarUrl URL to the avatar image
     *
     * @return void
     *
     * @example
     * ```php
     * $repo->updateAvatar('user-uuid-123', 'https://example.com/avatars/john.jpg');
     * ```
     */
    public function updateAvatar(string $userId, string $avatarUrl): void
    {
        $this->db->query(
            "UPDATE users SET avatar_url = ? WHERE id = ?",
            [$avatarUrl, $userId]
        );

        // Invalidate cache for this user
        unset($this->cacheById[$userId]);
    }

    /**
     * Get user avatar URL.
     *
     * @param string $userId User UUID to get avatar for
     *
     * @return string|null Avatar URL or null if not set
     *
     * @example
     * ```php
     * $avatarUrl = $repo->getAvatar('user-uuid-123');
     * ```
     */
    public function getAvatar(string $userId): ?string
    {
        $result = $this->db->query(
            "SELECT avatar_url FROM users WHERE id = ?",
            [$userId]
        );

        return UserRow::string(UserRow::firstFromMixed($result), 'avatar_url');
    }

    /**
     * Clear user avatar URL.
     *
     * Sets the avatar_url to NULL for the given user.
     *
     * @param string $userId User UUID to clear avatar for
     *
     * @return void
     *
     * @example
     * ```php
     * $repo->clearAvatar('user-uuid-123');
     * ```
     */
    public function clearAvatar(string $userId): void
    {
        $this->db->query(
            "UPDATE users SET avatar_url = NULL WHERE id = ?",
            [$userId]
        );

        // Invalidate cache for this user
        unset($this->cacheById[$userId]);
    }

    /**
     * Verify a user's password.
     *
     * Uses bcrypt/Argon2 to securely compare the provided password
     * against the stored hash. Returns false if user doesn't exist.
     *
     * @param string $id User UUID to verify
     * @param string $password Plain text password to verify
     *
     * @return bool True if password matches, false otherwise
     *
     * @example
     * ```php
     * if ($repo->verifyPassword('user-uuid-123', 'provided_password')) {
     *     // Password is correct
     * }
     * ```
     */
    public function verifyPassword(string $id, string $password): bool
    {
        $user = $this->findById($id);
        if (!$user) {
            return false;
        }

        $hash = UserRow::string($user, 'password_hash');
        if ($hash === null) {
            return false;
        }

        return password_verify($password, $hash);
    }

    /**
     * Check if an email is already registered.
     *
     * @param string          $email     Email address to check
     * @param int|string|null $excludeId Optional user id (UUID string) to
     *                                   exclude from the match — used when
     *                                   updating an existing user's email.
     *
     * @return bool True if email exists, false otherwise
     *
     * @example
     * ```php
     * if ($repo->emailExists('test@example.com')) {
     *     // Email already taken
     * }
     * ```
     */
    public function emailExists(string $email, int|string|null $excludeId = null): bool
    {
        if ($excludeId !== null) {
            $result = $this->db->query(
                'SELECT 1 FROM users WHERE email = ? AND id != ?',
                [$email, $excludeId]
            );
        } else {
            $result = $this->db->query(
                'SELECT 1 FROM users WHERE email = ?',
                [$email]
            );
        }
        return !empty($result);
    }

    /**
     * Check if a username is already taken.
     *
     * @param string $username Username to check
     *
     * @return bool True if username exists, false otherwise
     *
     * @example
     * ```php
     * if ($repo->usernameExists('john_doe')) {
     *     // Username already taken
     * }
     * ```
     */
    public function usernameExists(string $username): bool
    {
        $result = $this->db->query(
            "SELECT 1 FROM users WHERE username = ?",
            [$username]
        );
        return !empty($result);
    }

    /**
     * Find a user by their external (provider-specific) identity.
     *
     * Used during external provider authentication to look up the local
     * user account linked to a given external ID.
     *
     * @param string $provider   Provider name (e.g. "oidc", "ldap").
     * @param string $externalId Provider's unique identifier for the user.
     *
     * @return array<string, mixed>|null User record or null if not found.
     *
     * @since 0.12.0 (Step D.1)
     *
     * @example
     * ```php
     * $user = $repo->findByExternalId('oidc', 'https://accounts.google.com/12345');
     * ```
     */
    public function findByExternalId(string $provider, string $externalId): ?array
    {
        $result = $this->db->query(
            "SELECT * FROM users WHERE provider = ? AND external_id = ?",
            [$provider, $externalId]
        );

        if (!is_array($result) || !isset($result[0]) || !is_array($result[0])) {
            return null;
        }

        /** @var array<string, mixed> $row */
        $row = $result[0];

        return $row;
    }

    /**
     * Find or create a user by their external identity.
     *
     * On first login via an external provider, creates a new local user
     * record with password_hash = NULL and the provider/external_id set.
     * On subsequent logins, returns the existing user record.
     *
     * The existence lookup is scoped by BOTH `(provider, external_id)` — the
     * same key as {@see self::findByExternalId()} and the `UNIQUE(provider,
     * external_id)` index from migration 009. Scoping by `external_id` alone
     * (the prior behaviour) risked cross-matching two different providers that
     * happen to mint the same opaque id, and the row was always inserted with
     * the literal `'external'` regardless of which provider actually
     * authenticated — losing the ability to tell an OIDC identity from an LDAP
     * one. Both are fixed here: the caller threads the REAL provider name
     * (`oidc` / `ldap`), taken from {@see \Phlix\Shared\Auth\AuthResult::$attributes}`['provider']`.
     *
     * @param string $provider    Provider name that authenticated (e.g. "oidc",
     *                             "ldap"). Stored verbatim in `users.provider`
     *                             and used to scope the existence lookup.
     * @param string $externalId  Provider's unique identifier.
     * @param string|null $email  User's email (used as username seed).
     * @param string|null $displayName User's display name.
     *
     * @return string The local user UUID (existing or newly created).
     *
     * @since 0.12.0 (Step D.1)
     *
     * @example
     * ```php
     * $userId = $repo->findOrCreateByExternalId(
     *     'oidc',
     *     'https://accounts.google.com/12345',
     *     'alice@example.com',
     *     'Alice'
     * );
     * ```
     */
    public function findOrCreateByExternalId(
        string $provider,
        string $externalId,
        ?string $email = null,
        ?string $displayName = null
    ): string {
        $existingRow = UserRow::firstFromMixed(
            $this->db->query(
                "SELECT * FROM users WHERE provider = ? AND external_id = ?",
                [$provider, $externalId]
            )
        );

        if ($existingRow !== null) {
            $userId = UserRow::string($existingRow, 'id');
            if ($userId !== null) {
                return $userId;
            }
        }

        $id = $this->generateUuid();

        // Stable hash of the unique identity key (provider, external_id) —
        // migration 009. Computed once and reused for BOTH the username and
        // email fallbacks below so every email-less external user gets a
        // distinct, deterministic value.
        $identityHash = hash('sha256', $provider . "\0" . $externalId);

        // `users.username` is NOT NULL + UNIQUE (migration 001). The old
        // fallback 'user_' . substr($externalId, 0, 16) collides whenever two
        // email-less external identities share the first 16 chars of their
        // external_id — realistic for LDAP where external_id = 'ldap.' + DN
        // (e.g. 'ldap.uid=john.smith,ou=people' vs
        // 'ldap.uid=john.smart,ou=people' both truncate to 'ldap.uid=john.sm'),
        // and possible for OIDC composite `sub` values. The second create then
        // hits the UNIQUE index -> 500. Derive the fallback from the identity
        // hash instead so each email-less external user gets a distinct,
        // deterministic username ('user_' + 24 hex chars = 29 chars, well
        // within VARCHAR(255) and the 3-50 local-registration bound, hex-only
        // so it is always a valid username). A provider-supplied email (used as
        // the username seed) still wins.
        $username = $email ?? 'user_' . substr($identityHash, 0, 24);

        // `users.email` is NOT NULL + UNIQUE (migration 001), yet external
        // providers (OIDC/LDAP) may not supply an email. A shared '' placeholder
        // would make a SECOND email-less external user collide on the unique
        // index. Derive a stable, per-identity placeholder from the same
        // identity hash so every email-less external user gets a distinct,
        // deterministic value that is bounded well under VARCHAR(255)
        // regardless of external_id length.
        $emailValue = ($email !== null && $email !== '')
            ? $email
            : $provider . '+' . $identityHash . '@no-email.local';

        // password_hash stays NULL: an external identity has no local password,
        // and a fake '' hash could interfere with password verification.
        $this->db->query(
            "INSERT INTO users (id, username, email, display_name, provider, external_id, password_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                $id,
                $username,
                $emailValue,
                $displayName ?? $username,
                $provider,
                $externalId,
                null,
            ],
        );

        $this->db->query(
            "INSERT INTO user_settings (user_id) VALUES (?)",
            [$id],
        );

        return $id;
    }

    /**
     * Update the provider_data JSON column for a user.
     *
     * Stores arbitrary provider-specific metadata (e.g. OIDC claims,
     * refresh tokens) on the user's local record.
     *
     * @param string $userId  Local user UUID.
     * @param array<string, mixed> $data Key-value pairs to store in provider_data.
     *
     * @return void
     *
     * @since 0.12.0 (Step D.1)
     *
     * @example
     * ```php
     * $repo->updateProviderData('user-uuid-123', [
     *     'refresh_token' => 'rt_abc123',
     *     'expires_at' => 1717000000,
     * ]);
     * ```
     */
    public function updateProviderData(string $userId, array $data): void
    {
        $this->db->query(
            "UPDATE users SET provider_data = ? WHERE id = ?",
            [json_encode($data), $userId],
        );

        // Invalidate cache for this user
        unset($this->cacheById[$userId]);
    }

    /**
     * Set or clear the must_change_password flag on a user account.
     *
     * S7+F1: When an admin resets a password, the user is forced to set a new
     * password on next login before they can access any content.
     *
     * @param string $userId         Local user UUID.
     * @param bool   $mustChange     Whether the user must change their password.
     *
     * @return void
     *
     * @since S7+F1
     */
    public function setMustChangePassword(string $userId, bool $mustChange): void
    {
        $this->db->query(
            "UPDATE users SET must_change_password = ? WHERE id = ?",
            [$mustChange ? 1 : 0, $userId],
        );

        // Invalidate cache for this user - must_change_password affects auth
        unset($this->cacheById[$userId]);
    }

    /**
     * Check if a user must change their password before accessing content.
     *
     * S7+F1: Gates login/refresh to require a password change when the flag is set.
     *
     * @param string $userId Local user UUID.
     *
     * @return bool True when must_change_password = 1, false otherwise.
     *
     * @since S7+F1
     */
    public function mustChangePassword(string $userId): bool
    {
        $result = $this->db->query(
            "SELECT must_change_password FROM users WHERE id = ?",
            [$userId],
        );
        $row = UserRow::firstFromMixed($result);
        return UserRow::int($row, 'must_change_password', 0) === 1;
    }

    /**
     * Store a one-time password reset token (hashed at rest).
     *
     * S7+F1: The token is hashed with password_hash() before storage so raw
     * tokens never appear in the database. It expires after a configurable
     * window (default 15 minutes / 900 seconds).
     *
     * @param string $userId       Local user UUID.
     * @param string $hashedToken The hashed reset token (from password_hash).
     * @param int    $expiresAt    Unix timestamp when this token expires.
     *
     * @return void
     *
     * @since S7+F1
     */
    public function setPasswordResetToken(string $userId, string $hashedToken, int $expiresAt): void
    {
        $this->db->query(
            "UPDATE users SET password_reset_token = ?, password_reset_expires_at = ? WHERE id = ?",
            [$hashedToken, $expiresAt, $userId],
        );

        // Invalidate cache for this user
        unset($this->cacheById[$userId]);
    }

    /**
     * Get the stored password reset token hash and expiry for a user.
     *
     * @param string $userId Local user UUID.
     *
     * @return array{token: string|null, expires_at: int|null}|null The stored
     *         hashed token and expiry timestamp, or null if none set.
     *
     * @since S7+F1
     */
    public function getPasswordResetData(string $userId): ?array
    {
        $result = $this->db->query(
            "SELECT password_reset_token, password_reset_expires_at FROM users WHERE id = ?",
            [$userId],
        );
        $row = UserRow::firstFromMixed($result);
        if ($row === null) {
            return null;
        }
        return [
            'token' => UserRow::string($row, 'password_reset_token'),
            'expires_at' => UserRow::intOrNull($row, 'password_reset_expires_at'),
        ];
    }

    /**
     * Clear the stored password reset token and expiry.
     *
     * Called after a successful password change that was triggered by a
     * reset token, or when the token expires.
     *
     * @param string $userId Local user UUID.
     *
     * @return void
     *
     * @since S7+F1
     */
    public function clearPasswordResetToken(string $userId): void
    {
        $this->db->query(
            "UPDATE users SET password_reset_token = NULL, password_reset_expires_at = NULL WHERE id = ?",
            [$userId],
        );

        // Invalidate cache for this user
        unset($this->cacheById[$userId]);
    }

    /**
     * Clear all in-memory caches on this repository instance.
     *
     * This is primarily useful for unit testing to ensure a clean cache state
     * between tests. In production, caches naturally expire based on TTL.
     *
     * Instance-scoped (not static): each UserRepository already owns its own
     * cache (see the class-level NOTE above the cache properties), so there
     * is no process-wide state left to clear from outside an instance.
     *
     * @return void
     *
     * @since 0.32.0
     */
    public function clearCache(): void
    {
        $this->cacheById = [];
        $this->cacheByUsername = [];
        $this->cacheByEmail = [];
        $this->statusCacheById = [];
    }

    /**
     * Generate a UUID v4 string.
     *
     * @return string UUID in standard format: xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
     *
     * @example
     * ```php
     * $uuid = $this->generateUuid();
     * // Returns: '550e8400-e29b-41d4-a716-446655440000'
     * ```
     */
    private function generateUuid(): string
    {
        return Uuid::v4();
    }
}
