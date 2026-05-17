<?php

declare(strict_types=1);

namespace Phlex\Auth;

use Workerman\MySQL\Connection;

/**
 * Manages user profiles with support for multiple profiles per account.
 *
 * This class provides comprehensive profile management including:
 * - Profile CRUD operations (create, read, update, delete)
 * - Multi-profile support per user account (up to 5 profiles)
 * - Parental controls via content rating restrictions
 * - Profile PIN protection for admin actions
 * - Genre-based content filtering (allowed/blocked genres)
 * - Daily watch time limits
 *
 * Profile Types:
 * - Standard Profile: Regular user profile with customizable settings
 * - Admin Profile: Profile with elevated permissions (managed via is_admin flag)
 *
 * Parental Control Ratings:
 * The system supports standard MPAA content ratings in order of restrictiveness:
 * - G: General Audiences (all ages)
 * - PG: Parental Guidance Suggested
 * - PG-13: Parents Strongly Cautioned (ages 13+)
 * - R: Restricted (ages 17+ unless accompanied)
 * - NC-17: No One 17 & Under Admitted
 * - X: Adult content restriction
 * - UNRATED: Content without a rating (can be blocked via allow_unrated setting)
 *
 * @package Phlex\Auth
 * @author Phlex Development Team
 * @license Proprietary
 *
 * @see WatchHistory For watch history tracking per profile
 * @see AuthManager For authentication and token management
 */
class UserProfileManager
{
    /**
     * Database connection instance.
     *
     * @var Connection
     */
    private Connection $db;

    /**
     * Content ratings in order of restrictiveness (least to most restrictive).
     *
     * Used for comparing profile content restrictions against media ratings.
     * Lower values indicate more permissive ratings.
     *
     * @var array<string, int>
     */
    public const RATING_ORDER = [
        'G' => 1,
        'PG' => 2,
        'PG-13' => 3,
        'R' => 4,
        'NC-17' => 5,
        'X' => 6,
        'UNRATED' => 7,
    ];

    /**
     * Maximum number of profiles allowed per user account.
     *
     * This limit ensures system performance and user experience quality.
     *
     * @var int
     */
    public const MAX_PROFILES_PER_USER = 5;

    /**
     * Profile type constants for categorization.
     *
     * @deprecated Use is_admin flag instead for permission-based profile types
     */
    public const TYPE_STANDARD = 'standard';
    public const TYPE_ADMIN = 'admin';

    /**
     * Profile name constraints.
     *
     * @var int
     */
    public const MIN_NAME_LENGTH = 1;
    public const MAX_NAME_LENGTH = 100;

    /**
     * PIN length options for profile protection.
     *
     * @var int
     */
    public const PIN_LENGTH_4 = 4;
    public const PIN_LENGTH_6 = 6;

    /**
     * Default content rating for new profiles.
     *
     * @var string
     */
    public const DEFAULT_CONTENT_RATING = 'R';

    /**
     * Constructs a new UserProfileManager instance.
     *
     * @param Connection $db Database connection for profile data persistence
     *
     * @throws void
     */
    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * Find a profile by its unique identifier.
     *
     * Retrieves a single profile record without associated settings.
     * Use findByIdWithSettings() when settings are needed.
     *
     * @param string $profileId The unique profile identifier (UUID format)
     *
     * @return array|null Profile data array with keys: id, user_id, name, avatar_url,
     *                   is_active, is_admin, created_at, updated_at. Returns null
     *                   if profile not found.
     *
     * @throws void
     *
     * @example
     * $profile = $manager->findById('prof_abc123');
     * if ($profile !== null) {
     *     echo $profile['name'];
     * }
     */
    public function findById(string $profileId): ?array
    {
        $result = $this->db->query(
            "SELECT * FROM user_profiles WHERE id = ?",
            [$profileId]
        );
        return $result[0] ?? null;
    }

    /**
     * Find a profile by ID with full settings loaded.
     *
     * Retrieves profile data joined with profile_settings for complete
     * profile information including content rating, PIN status, and
     * genre restrictions.
     *
     * @param string $profileId The unique profile identifier (UUID format)
     *
     * @return array|null Profile data with nested 'settings' key containing:
     *                    - content_rating: string (G, PG, PG-13, R, NC-17, X, UNRATED)
     *                    - pin_required_for_admin: bool
     *                    - max_daily_watch_time: int (seconds, 0 = unlimited)
     *                    - allow_unrated: bool
     *                    - allowed_genres: array|null
     *                    - blocked_genres: array|null
     *                    Returns null if profile not found.
     *
     * @throws void
     *
     * @see findById() For simple profile lookup without settings
     */
    public function findByIdWithSettings(string $profileId): ?array
    {
        $result = $this->db->query(
            "SELECT p.*, ps.content_rating, ps.pin_hash, ps.pin_required_for_admin,
                    ps.max_daily_watch_time, ps.allowed_genres, ps.blocked_genres, ps.allow_unrated
             FROM user_profiles p
             LEFT JOIN profile_settings ps ON p.id = ps.profile_id
             WHERE p.id = ?",
            [$profileId]
        );

        if (empty($result)) {
            return null;
        }

        return $this->hydrateProfile($result[0]);
    }

    /**
     * Get all profiles associated with a user account.
     *
     * Returns profiles ordered by active status (active first) then by name.
     * Each profile includes basic content_rating from settings.
     *
     * @param string $userId The unique user identifier (UUID format)
     *
     * @return array<int, array> Array of profile arrays, each containing:
     *                           - id: string
     *                           - user_id: string
     *                           - name: string
     *                           - avatar_url: string|null
     *                           - is_active: bool
     *                           - is_admin: bool
     *                           - created_at: string (Y-m-d H:i:s)
     *                           - updated_at: string (Y-m-d H:i:s)
     *                           - content_rating: string (if settings exist)
     *                           - settings: array (if content_rating exists)
     *
     * @throws void
     *
     * @see getActiveProfile() To get only the currently active profile
     */
    public function findByUserId(string $userId): array
    {
        $results = $this->db->query(
            "SELECT p.*, ps.content_rating
             FROM user_profiles p
             LEFT JOIN profile_settings ps ON p.id = ps.profile_id
             WHERE p.user_id = ?
             ORDER BY p.is_active DESC, p.name ASC",
            [$userId]
        );

        return array_map(fn($r) => $this->hydrateProfile($r), $results);
    }

    /**
     * Get the currently active profile for a user.
     *
     * Returns the profile marked as active (only one active profile per user).
     * Used for determining which profile's settings to apply for media filtering.
     *
     * @param string $userId The unique user identifier (UUID format)
     *
     * @return array|null Profile array with settings, or null if no active
     *                   profile exists. See findByIdWithSettings() return format.
     *
     * @throws void
     *
     * @see findByUserId() To get all profiles for a user
     */
    public function getActiveProfile(string $userId): ?array
    {
        $result = $this->db->query(
            "SELECT p.*, ps.content_rating
             FROM user_profiles p
             LEFT JOIN profile_settings ps ON p.id = ps.profile_id
             WHERE p.user_id = ? AND p.is_active = TRUE
             LIMIT 1",
            [$userId]
        );

        if (empty($result)) {
            return null;
        }

        return $this->hydrateProfile($result[0]);
    }

    /**
     * Create a new profile for a user account.
     *
     * Creates both the profile record and associated default settings.
     * The first profile created for a user automatically becomes active.
     *
     * @param string $userId The unique user identifier (UUID format)
     * @param array{
     *     name: string,
     *     avatar_url?: string|null,
     *     is_active?: bool,
     *     is_admin?: bool,
     *     content_rating?: string,
     *     pin?: string,
     *     pin_required_for_admin?: bool,
     *     max_daily_watch_time?: int,
     *     allowed_genres?: array<string>,
     *     blocked_genres?: array<string>,
     *     allow_unrated?: bool
     * } $data Profile data including:
     *         - name (required): Profile display name (1-100 chars)
     *         - avatar_url (optional): Profile picture URL
     *         - is_active (optional): Set as active profile (default: false)
     *         - is_admin (optional): Admin privileges (default: false)
     *         - content_rating (optional): Max allowed rating (default: 'R')
     *         - pin (optional): 4 or 6 digit PIN for protection
     *         - pin_required_for_admin (optional): Require PIN for admin actions
     *         - max_daily_watch_time (optional): Seconds, 0 = unlimited
     *         - allowed_genres (optional): Array of permitted genres
     *         - blocked_genres (optional): Array of prohibited genres
     *         - allow_unrated (optional): Allow unrated content (default: true)
     *
     * @return string The generated UUID for the new profile
     *
     * @throws \InvalidArgumentException If maximum profiles reached (5) or
     *                                    invalid name (empty or >100 chars)
     *
     * @example
     * $profileId = $manager->create('user_123', [
     *     'name' => 'Kids Profile',
     *     'content_rating' => 'G',
     *     'pin' => '1234',
     *     'allowed_genres' => ['Animation', 'Family'],
     * ]);
     */
    public function create(string $userId, array $data): string
    {
        // Check max profiles limit
        $existingCount = $this->db->query(
            "SELECT COUNT(*) as count FROM user_profiles WHERE user_id = ?",
            [$userId]
        );

        if (($existingCount[0]['count'] ?? 0) >= self::MAX_PROFILES_PER_USER) {
            throw new \InvalidArgumentException(
                'Maximum number of profiles (' . self::MAX_PROFILES_PER_USER . ') reached'
            );
        }

        // Validate name
        $name = trim($data['name'] ?? '');
        if (strlen($name) < self::MIN_NAME_LENGTH || strlen($name) > self::MAX_NAME_LENGTH) {
            throw new \InvalidArgumentException(
                sprintf('Profile name must be %d-%d characters', self::MIN_NAME_LENGTH, self::MAX_NAME_LENGTH)
            );
        }

        $id = $this->generateUuid();
        $isAdmin = $data['is_admin'] ?? false;

        $this->db->query(
            "INSERT INTO user_profiles (id, user_id, name, avatar_url, is_active, is_admin)
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                $id,
                $userId,
                $name,
                $data['avatar_url'] ?? null,
                $data['is_active'] ?? false,
                $isAdmin,
            ]
        );

        // Create default settings for the profile
        $this->createProfileSettings($id, [
            'content_rating' => $data['content_rating'] ?? self::DEFAULT_CONTENT_RATING,
            'pin_hash' => isset($data['pin']) ? password_hash($data['pin'], PASSWORD_ARGON2ID) : null,
            'pin_required_for_admin' => $data['pin_required_for_admin'] ?? false,
            'max_daily_watch_time' => $data['max_daily_watch_time'] ?? 0,
            'allowed_genres' => $data['allowed_genres'] ?? null,
            'blocked_genres' => $data['blocked_genres'] ?? null,
            'allow_unrated' => $data['allow_unrated'] ?? true,
        ]);

        return $id;
    }

    /**
     * Update an existing profile's information and settings.
     *
     * Supports partial updates - only provided fields are modified.
     * This method handles both profile basic info and profile settings updates.
     *
     * @param string $profileId The unique profile identifier (UUID format)
     * @param array{
     *     name?: string,
     *     avatar_url?: string|null,
     *     is_active?: bool,
     *     content_rating?: string,
     *     pin?: string,
     *     pin_required_for_admin?: bool,
     *     max_daily_watch_time?: int,
     *     allowed_genres?: array<string>|null,
     *     blocked_genres?: array<string>|null,
     *     allow_unrated?: bool
     * } $data Fields to update. See create() for field descriptions.
     *
     * @return void
     *
     * @throws \InvalidArgumentException If profile not found or invalid name
     *
     * @see create() For field descriptions and validation rules
     * @see delete() To remove a profile entirely
     */
    public function update(string $profileId, array $data): void
    {
        $profile = $this->findById($profileId);
        if (!$profile) {
            throw new \InvalidArgumentException('Profile not found');
        }

        $sets = [];
        $values = [];

        if (isset($data['name'])) {
            $name = trim($data['name']);
            if (strlen($name) < self::MIN_NAME_LENGTH || strlen($name) > self::MAX_NAME_LENGTH) {
                throw new \InvalidArgumentException(
                    sprintf('Profile name must be %d-%d characters', self::MIN_NAME_LENGTH, self::MAX_NAME_LENGTH)
                );
            }
            $sets[] = 'name = ?';
            $values[] = $name;
        }

        if (array_key_exists('avatar_url', $data)) {
            $sets[] = 'avatar_url = ?';
            $values[] = $data['avatar_url'];
        }

        if (isset($data['is_active'])) {
            $sets[] = 'is_active = ?';
            $values[] = (bool)$data['is_active'];
        }

        if (!empty($sets)) {
            $values[] = $profileId;
            $this->db->query(
                "UPDATE user_profiles SET " . implode(', ', $sets) . " WHERE id = ?",
                $values
            );
        }

        // Update settings if provided
        if (
            isset($data['content_rating']) || isset($data['pin']) || isset($data['pin_required_for_admin'])
            || isset($data['max_daily_watch_time']) || isset($data['allowed_genres'])
            || isset($data['blocked_genres']) || isset($data['allow_unrated'])
        ) {
            $this->updateProfileSettings($profileId, $data);
        }
    }

    /**
     * Switch the active profile for a user.
     *
     * Deactivates all existing profiles for the user and activates
     * the specified profile. The profile must belong to the user.
     *
     * @param string $userId The unique user identifier (UUID format)
     * @param string $profileId The profile to make active (UUID format)
     *
     * @return bool True if switch successful, false if profile doesn't
     *              exist or doesn't belong to user
     *
     * @throws void
     *
     * @see getActiveProfile() To retrieve the currently active profile
     */
    public function switchProfile(string $userId, string $profileId): bool
    {
        // Verify profile belongs to user
        $profile = $this->findById($profileId);
        if (!$profile || $profile['user_id'] !== $userId) {
            return false;
        }

        $this->db->query(
            "UPDATE user_profiles SET is_active = FALSE WHERE user_id = ?",
            [$userId]
        );

        $this->db->query(
            "UPDATE user_profiles SET is_active = TRUE WHERE id = ?",
            [$profileId]
        );

        return true;
    }

    /**
     * Delete a profile and its associated data.
     *
     * Permanently removes the profile and its settings from the database.
     * This action cannot be undone. Consider using switchProfile() to
     * deactivate rather than delete if the profile should remain accessible.
     *
     * @param string $profileId The unique profile identifier (UUID format)
     *
     * @return void
     *
     * @throws \InvalidArgumentException If profile not found
     *
     * @see update() To modify profile settings without deletion
     */
    public function delete(string $profileId): void
    {
        $profile = $this->findById($profileId);
        if (!$profile) {
            throw new \InvalidArgumentException('Profile not found');
        }

        $this->db->query("DELETE FROM user_profiles WHERE id = ?", [$profileId]);
    }

    /**
     * Verify if a provided PIN matches the profile's PIN.
     *
     * Used for parental control verification before allowing access to
     * restricted content or administrative profile actions.
     *
     * @param string $profileId The unique profile identifier (UUID format)
     * @param string $pin The PIN to verify (4 or 6 digits)
     *
     * @return bool True if PIN matches or no PIN is set, false if incorrect
     *
     * @throws void
     *
     * @see setPin() To set or change a profile PIN
     * @see removePin() To remove the PIN requirement
     */
    public function verifyPin(string $profileId, string $pin): bool
    {
        $result = $this->db->query(
            "SELECT pin_hash FROM profile_settings WHERE profile_id = ?",
            [$profileId]
        );

        if (empty($result) || empty($result[0]['pin_hash'])) {
            return true; // No PIN set, allow access
        }

        return password_verify($pin, $result[0]['pin_hash']);
    }

    /**
     * Set or update the PIN for a profile.
     *
     * The PIN provides additional protection for profile settings and
     * content access restrictions. Uses Argon2ID for secure hashing.
     *
     * @param string $profileId The unique profile identifier (UUID format)
     * @param string $pin The new PIN (must be exactly 4 or 6 digits)
     *
     * @return void
     *
     * @throws \InvalidArgumentException If PIN is not 4 or 6 digits or contains
     *                                    non-digit characters
     *
     * @see verifyPin() To check if a PIN is correct
     * @see removePin() To remove PIN protection
     */
    public function setPin(string $profileId, string $pin): void
    {
        if (strlen($pin) !== self::PIN_LENGTH_4 && strlen($pin) !== self::PIN_LENGTH_6) {
            throw new \InvalidArgumentException('PIN must be 4 or 6 digits');
        }

        if (!ctype_digit($pin)) {
            throw new \InvalidArgumentException('PIN must contain only digits');
        }

        $pinHash = password_hash($pin, PASSWORD_ARGON2ID);

        $this->db->query(
            "UPDATE profile_settings SET pin_hash = ? WHERE profile_id = ?",
            [$pinHash, $profileId]
        );
    }

    /**
     * Remove the PIN requirement from a profile.
     *
     * Disables PIN protection for the profile. After calling this method,
     * verifyPin() will return true for any PIN value.
     *
     * @param string $profileId The unique profile identifier (UUID format)
     *
     * @return void
     *
     * @throws void
     *
     * @see setPin() To set a new PIN
     * @see verifyPin() For PIN verification logic
     */
    public function removePin(string $profileId): void
    {
        $this->db->query(
            "UPDATE profile_settings SET pin_hash = NULL WHERE profile_id = ?",
            [$profileId]
        );
    }

    /**
     * Check if a content rating is allowed for a profile.
     *
     * Used for parental control filtering. Compares the media's content rating
     * against the profile's maximum allowed rating setting.
     *
     * Content Rating Hierarchy (least to most restrictive):
     * - G (1) < PG (2) < PG-13 (3) < R (4) < NC-17 (5) < X (6) < UNRATED (7)
     *
     * A profile with PG-13 rating allows: G, PG, PG-13
     *
     * @param string $profileId The unique profile identifier (UUID format)
     * @param string $contentRating The content rating to check (G, PG, PG-13, R, NC-17, X, UNRATED)
     *
     * @return bool True if content is allowed, false if restricted.
     *              Returns true if no settings exist (no restrictions).
     *
     * @throws void
     *
     * @see getAllowedRatings() To get list of all allowed ratings for a profile
     */
    public function isContentRatingAllowed(string $profileId, string $contentRating): bool
    {
        $result = $this->db->query(
            "SELECT content_rating, allow_unrated FROM profile_settings WHERE profile_id = ?",
            [$profileId]
        );

        if (empty($result)) {
            return true; // No settings, allow all
        }

        $settings = $result[0];

        // Unrated content check
        if ($contentRating === 'UNRATED') {
            return (bool)$settings['allow_unrated'];
        }

        // Check rating order
        $profileRating = $settings['content_rating'] ?? self::DEFAULT_CONTENT_RATING;
        $profileRatingLevel = self::RATING_ORDER[$profileRating] ?? 4;
        $contentRatingLevel = self::RATING_ORDER[$contentRating] ?? 4;

        return $contentRatingLevel <= $profileRatingLevel;
    }

    /**
     * Get all content ratings allowed for a profile.
     *
     * Returns an array of rating codes that the profile is permitted to access,
     * based on the profile's maximum content rating setting and unrated content
     * preference.
     *
     * @param string $profileId The unique profile identifier (UUID format)
     *
     * @return array<string> Array of allowed rating codes (e.g., ['G', 'PG', 'PG-13']).
     *                      Returns all 7 ratings if no settings exist.
     *
     * @throws void
     *
     * @see isContentRatingAllowed() To check a single rating
     */
    public function getAllowedRatings(string $profileId): array
    {
        $result = $this->db->query(
            "SELECT content_rating, allow_unrated FROM profile_settings WHERE profile_id = ?",
            [$profileId]
        );

        if (empty($result)) {
            return ['G', 'PG', 'PG-13', 'R', 'NC-17', 'X', 'UNRATED'];
        }

        $settings = $result[0];
        $maxRating = $settings['content_rating'] ?? self::DEFAULT_CONTENT_RATING;
        $maxLevel = self::RATING_ORDER[$maxRating] ?? 4;

        $allowed = [];
        foreach (self::RATING_ORDER as $rating => $level) {
            if ($level <= $maxLevel) {
                $allowed[] = $rating;
            }
        }

        if ($settings['allow_unrated']) {
            $allowed[] = 'UNRATED';
        }

        return $allowed;
    }

    /**
     * Create default profile settings for a new profile.
     *
     * Internal method called during profile creation to establish
     * default parental control and restriction settings.
     *
     * @param string $profileId The profile identifier to create settings for
     * @param array{
     *     content_rating?: string,
     *     pin_hash?: string|null,
     *     pin_required_for_admin?: bool,
     *     max_daily_watch_time?: int,
     *     allowed_genres?: array<string>|null,
     *     blocked_genres?: array<string>|null,
     *     allow_unrated?: bool
     * } $data Settings data (see create() for field descriptions)
     *
     * @return void
     *
     * @internal
     */
    private function createProfileSettings(string $profileId, array $data): void
    {
        $id = $this->generateUuid();

        $this->db->query(
            "INSERT INTO profile_settings (id, profile_id, content_rating, pin_hash, pin_required_for_admin,
             max_daily_watch_time, allowed_genres, blocked_genres, allow_unrated)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $id,
                $profileId,
                $data['content_rating'] ?? self::DEFAULT_CONTENT_RATING,
                $data['pin_hash'] ?? null,
                $data['pin_required_for_admin'] ?? false,
                $data['max_daily_watch_time'] ?? 0,
                isset($data['allowed_genres']) ? json_encode($data['allowed_genres']) : null,
                isset($data['blocked_genres']) ? json_encode($data['blocked_genres']) : null,
                $data['allow_unrated'] ?? true,
            ]
        );
    }

    /**
     * Update profile settings for an existing profile.
     *
     * Internal method handling partial updates to profile settings.
     * Only modifies settings that are explicitly provided in $data.
     *
     * @param string $profileId The profile identifier to update settings for
     * @param array{
     *     content_rating?: string,
     *     pin?: string,
     *     pin_required_for_admin?: bool,
     *     max_daily_watch_time?: int,
     *     allowed_genres?: array<string>|null,
     *     blocked_genres?: array<string>|null,
     *     allow_unrated?: bool
     * } $data Settings fields to update (see create() for field descriptions)
     *
     * @return void
     *
     * @internal
     */
    private function updateProfileSettings(string $profileId, array $data): void
    {
        $sets = [];
        $values = [];

        if (isset($data['content_rating'])) {
            $sets[] = 'content_rating = ?';
            $values[] = $data['content_rating'];
        }

        if (isset($data['pin'])) {
            $sets[] = 'pin_hash = ?';
            $values[] = password_hash($data['pin'], PASSWORD_ARGON2ID);
        }

        if (isset($data['pin_required_for_admin'])) {
            $sets[] = 'pin_required_for_admin = ?';
            $values[] = (bool)$data['pin_required_for_admin'];
        }

        if (isset($data['max_daily_watch_time'])) {
            $sets[] = 'max_daily_watch_time = ?';
            $values[] = (int)$data['max_daily_watch_time'];
        }

        if (isset($data['allowed_genres'])) {
            $sets[] = 'allowed_genres = ?';
            $values[] = json_encode($data['allowed_genres']);
        }

        if (isset($data['blocked_genres'])) {
            $sets[] = 'blocked_genres = ?';
            $values[] = json_encode($data['blocked_genres']);
        }

        if (isset($data['allow_unrated'])) {
            $sets[] = 'allow_unrated = ?';
            $values[] = (bool)$data['allow_unrated'];
        }

        if (empty($sets)) {
            return;
        }

        $values[] = $profileId;
        $this->db->query(
            "UPDATE profile_settings SET " . implode(', ', $sets) . " WHERE profile_id = ?",
            $values
        );
    }

    /**
     * Hydrate a database row into a complete profile array.
     *
     * Transforms raw database records (including JOINed settings) into
     * structured arrays with properly typed and parsed values.
     *
     * @param array $row Raw database row from user_profiles LEFT JOIN profile_settings
     *
     * @return array Hydrated profile array with settings sub-array when applicable
     *
     * @internal
     */
    private function hydrateProfile(array $row): array
    {
        $profile = [
            'id' => $row['id'],
            'user_id' => $row['user_id'],
            'name' => $row['name'],
            'avatar_url' => $row['avatar_url'],
            'is_active' => (bool)$row['is_active'],
            'is_admin' => (bool)$row['is_admin'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];

        // Settings
        if (isset($row['content_rating'])) {
            $profile['settings'] = [
                'content_rating' => $row['content_rating'],
                'pin_required_for_admin' => (bool)($row['pin_required_for_admin'] ?? false),
                'max_daily_watch_time' => (int)($row['max_daily_watch_time'] ?? 0),
                'allow_unrated' => (bool)($row['allow_unrated'] ?? true),
            ];

            if (isset($row['allowed_genres']) && $row['allowed_genres']) {
                $profile['settings']['allowed_genres'] = json_decode($row['allowed_genres'], true);
            }

            if (isset($row['blocked_genres']) && $row['blocked_genres']) {
                $profile['settings']['blocked_genres'] = json_decode($row['blocked_genres'], true);
            }
        }

        return $profile;
    }

    /**
     * Generate a UUID v4 string.
     *
     * Creates a random UUID suitable for use as a unique identifier.
     * Format: xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx (RFC 4122 compliant)
     *
     * @return string UUID v4 string
     *
     * @internal
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
