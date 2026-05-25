<?php

declare(strict_types=1);

namespace Phlix\Auth;

use Phlix\Auth\Dto\UserRow;
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
        $result = $this->db->query("SELECT * FROM users WHERE id = ?", [$id]);
        return UserRow::firstFromMixed($result);
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
        $result = $this->db->query(
            "SELECT * FROM users WHERE username = ?",
            [$username]
        );
        return UserRow::firstFromMixed($result);
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
        $result = $this->db->query(
            "SELECT * FROM users WHERE id = ? AND is_admin = 1",
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
     * Total number of rows in the `users` table. Used by
     * {@see \Phlix\Auth\AuthManager::register()} to detect the very
     * first registration on a fresh install and auto-promote that user
     * to admin (Step A.5 minimum-viable admin bootstrap).
     *
     * @return int Total user count (>= 0).
     *
     * @since 0.10.0 (Step A.5)
     */
    public function countUsers(): int
    {
        $row = UserRow::firstFromMixed($this->db->query("SELECT COUNT(*) AS c FROM users"));
        return UserRow::int($row, 'c', 0);
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
        $result = $this->db->query(
            "SELECT * FROM users WHERE email = ?",
            [$email]
        );
        return UserRow::firstFromMixed($result);
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

        $this->db->query(
            "INSERT INTO users (id, username, email, password_hash, display_name) VALUES (?, ?, ?, ?, ?)",
            [
                $id,
                $data['username'],
                $data['email'],
                $passwordHash,
                $data['display_name'] ?? $data['username'],
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

        if ($columns === []) {
            return;
        }

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
     * @param string $email Email address to check
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
    public function emailExists(string $email): bool
    {
        $result = $this->db->query(
            "SELECT 1 FROM users WHERE email = ?",
            [$email]
        );
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
     *     'https://accounts.google.com/12345',
     *     'alice@example.com',
     *     'Alice'
     * );
     * ```
     */
    public function findOrCreateByExternalId(
        string $externalId,
        ?string $email = null,
        ?string $displayName = null
    ): string {
        $provider = 'external';

        $existingRow = UserRow::firstFromMixed(
            $this->db->query(
                "SELECT * FROM users WHERE external_id = ?",
                [$externalId]
            )
        );

        if ($existingRow !== null) {
            $userId = UserRow::string($existingRow, 'id');
            if ($userId !== null) {
                return $userId;
            }
        }

        $id = $this->generateUuid();
        $username = $email ?? 'user_' . substr($externalId, 0, 16);

        $this->db->query(
            "INSERT INTO users (id, username, email, display_name, provider, external_id, password_hash) VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                $id,
                $username,
                $email ?? '',
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
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}
