<?php

/**
 * Phlix media server component: Auth.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Auth;

use Phlix\Admin\SettingsRepository;
use Phlix\Auth\Dto\UserRow;
use Phlix\Common\Uuid;
use Phlix\Common\Util\RowMap;
use Phlix\Media\Library\ContentRating;
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
 * @package Phlix\Auth
 * @author Phlix Development Team
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
    public const RATING_ORDER = ContentRating::RANKS;

    /**
     * Shipped maximum number of profiles allowed per user account.
     *
     * This is the DEFAULT, not the enforced limit — {@see self::maxProfiles()}
     * is the single enforcement point and applies the `auth.max_profiles`
     * override on top of this. Both call sites that cap profile creation
     * (this class's {@see self::create()} and the admin controller's
     * pre-check) must go through that method; using this constant directly
     * would reintroduce a hardcoded cap that the setting cannot move.
     *
     * @var int
     */
    public const MAX_PROFILES_PER_USER = 5;

    /**
     * The dotted settings key backing {@see self::maxProfiles()}.
     */
    public const MAX_PROFILES_SETTING_KEY = 'auth.max_profiles';

    /**
     * Hard floor for the profile cap. A configured 0 (or negative) would make
     * profile creation impossible for everyone, including the account's first
     * profile — a settings field must not be able to brick the feature it
     * configures.
     */
    public const MIN_MAX_PROFILES = 1;

    /**
     * Hard ceiling for the profile cap. The limit exists to bound per-account
     * fan-out (every profile carries its own settings, watch history and
     * stream-limit rows); an unbounded value would let one account multiply
     * that indefinitely.
     */
    public const MAX_MAX_PROFILES = 50;

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
     * Name given to the profile {@see resolveProfileIdForUser()} creates for an
     * account that has none, when the account's username cannot be used verbatim.
     *
     * @var string
     */
    public const DEFAULT_PROFILE_NAME = 'Profile';

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
     * Effective-settings store behind `auth.max_profiles`, or NULL to use the
     * shipped default.
     */
    private ?SettingsRepository $settings;

    /**
     * Constructs a new UserProfileManager instance.
     *
     * @param Connection              $db       Database connection for profile
     *                                          data persistence
     * @param SettingsRepository|null $settings Effective-settings store. NULL
     *        degrades to {@see self::MAX_PROFILES_PER_USER}.
     *
     *        NOTE for DI: PHP-DI skips optional constructor parameters during
     *        autowiring, so the binding in `AuthServicesProvider` names this
     *        explicitly. Without that the manager silently keeps the shipped
     *        cap and `auth.max_profiles` becomes inert (read-path class (g)).
     */
    public function __construct(Connection $db, ?SettingsRepository $settings = null)
    {
        $this->db = $db;
        $this->settings = $settings;
    }

    /**
     * The effective maximum number of profiles per user account.
     *
     * ## Single enforcement point
     *
     * There are TWO places that cap profile creation: {@see self::create()}
     * and the pre-check in `AdminProfileController::createForUser()`, which
     * returns 400 before `create()` is ever reached. Wiring only the former
     * would have left the admin API — the only route that creates profiles —
     * still hardcoded at 5, so the setting would appear to do nothing. Both
     * now call this method.
     *
     * Read-path class (a) LIVE: resolved at check time, no restart.
     *
     * @return int Between {@see self::MIN_MAX_PROFILES} and
     *         {@see self::MAX_MAX_PROFILES} inclusive.
     *
     * @since 1.3.0
     */
    public function maxProfiles(): int
    {
        if ($this->settings === null) {
            return self::MAX_PROFILES_PER_USER;
        }

        try {
            /** @var mixed $configured */
            $configured = $this->settings->getEffective(self::MAX_PROFILES_SETTING_KEY);
        } catch (\Throwable) {
            // A settings-store failure must not raise the cap, and must not
            // drop it to zero and block profile creation outright.
            return self::MAX_PROFILES_PER_USER;
        }

        $value = match (true) {
            is_int($configured) => $configured,
            is_string($configured) && is_numeric($configured) => (int) $configured,
            default => self::MAX_PROFILES_PER_USER,
        };

        return max(self::MIN_MAX_PROFILES, min(self::MAX_MAX_PROFILES, $value));
    }

    /**
     * Find a profile by its unique identifier.
     *
     * Retrieves a single profile record without associated settings.
     * Use findByIdWithSettings() when settings are needed.
     *
     * @param string $profileId The unique profile identifier (UUID format)
     *
     * @return array<string, mixed>|null Profile data array with keys: id, user_id, name, avatar_url,
     *                   is_active, is_admin, created_at, updated_at. Returns null
     *                   if profile not found.
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
        return UserRow::firstFromMixed($result);
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
     * @return array<string, mixed>|null Profile data with nested 'settings' key containing:
     *                    - content_rating: string (G, PG, PG-13, R, NC-17, X, UNRATED)
     *                    - pin_required_for_admin: bool
     *                    - max_daily_watch_time: int (seconds, 0 = unlimited)
     *                    - allow_unrated: bool
     *                    - allowed_genres: array|null
     *                    - blocked_genres: array|null
     *                    Returns null if profile not found.
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

        $row = UserRow::firstFromMixed($result);
        if ($row === null) {
            return null;
        }

        return $this->hydrateProfile($row);
    }

    /**
     * Get all profiles associated with a user account.
     *
     * Returns profiles ordered by active status (active first) then by name.
     * Each profile includes basic content_rating from settings.
     *
     * @param string $userId The unique user identifier (UUID format)
     *
     * @return list<array<string, mixed>> Array of profile arrays, each containing:
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

        $rows = RowMap::listFromMixed($results);
        return array_map(fn(array $r): array => $this->hydrateProfile($r), $rows);
    }

    /**
     * Get the currently active profile for a user.
     *
     * Returns the profile marked as active (only one active profile per user).
     * Used for determining which profile's settings to apply for media filtering.
     *
     * @param string $userId The unique user identifier (UUID format)
     *
     * @return array<string, mixed>|null Profile array with settings, or null if no active
     *                   profile exists. See findByIdWithSettings() return format.
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

        $row = UserRow::firstFromMixed($result);
        if ($row === null) {
            return null;
        }

        return $this->hydrateProfile($row);
    }

    /**
     * Resolve the profile id that profile-scoped data should be read/written under
     * for `$userId`, optionally honouring a specific `$requestedProfileId`.
     *
     * This is the single runtime counterpart of migration
     * `100_user_item_data_profile_id.sql` and it enforces the same three rules the
     * migration's backfill does, so a row written at runtime lands where the
     * migration would have put it:
     *
     *   1. **A requested id is verified, never trusted.** When the caller supplies
     *      a `profile_id`, ownership is re-derived from `user_profiles` against the
     *      authenticated `$userId`. A profile belonging to another account — or one
     *      that does not exist — raises {@see ProfileNotOwnedException}. This is the
     *      horizontal-privilege boundary: without it, user A could read and write
     *      user B's favorites simply by naming B's profile id in a request.
     *   2. **Otherwise the active profile wins**, falling back to the earliest
     *      created profile and then the lowest id — byte-for-byte the ordering used
     *      by `migrations/079_activate_first_profiles.sql` and by group (C) of
     *      migration 100.
     *   3. **An account with no profile at all gets one**, named after its username,
     *      exactly as `migrations/080_backfill_missing_profiles.sql` and group (B)
     *      of migration 100 do. This case is live rather than theoretical:
     *      {@see AuthManager::register()} and
     *      {@see UserRepository::findOrCreateByExternalId()} both create accounts
     *      without a profile, so every account created after migration 080 ran has
     *      none. Since `user_item_data.profile_id` is NOT NULL after migration 100,
     *      failing here instead would make such an account unable to favorite
     *      anything at all.
     *
     * ⚠ Concurrency: two simultaneous first-writes for a profile-less account can
     * both reach rule 3 and create a profile. That is why the fallback re-runs the
     * rule-2 query after creating rather than returning the id it just inserted —
     * both racers then converge on the same deterministic winner, so neither writes
     * its data under a profile the other cannot see. The losing extra profile row is
     * harmless and visible to the user; it is not cleaned up here because deleting a
     * profile cascades `user_item_data` and `watch_history`.
     *
     * @param string      $userId             Authenticated account UUID.
     * @param string|null $requestedProfileId Caller-supplied profile UUID, or null
     *                                        to use the account's active/first
     *                                        profile. An empty/whitespace string is
     *                                        treated as null.
     *
     * @return string A profile UUID that is guaranteed to belong to `$userId`.
     *
     * @throws ProfileNotOwnedException  When `$requestedProfileId` is not owned by `$userId`.
     * @throws \InvalidArgumentException When `$userId` is empty.
     *
     * @since S79 (profile-scoped user item data)
     *
     * @see \Phlix\Media\UserItemDataRepository The first consumer.
     */
    public function resolveProfileIdForUser(string $userId, ?string $requestedProfileId = null): string
    {
        if (trim($userId) === '') {
            throw new \InvalidArgumentException('A user id is required to resolve a profile scope');
        }

        $requested = $requestedProfileId === null ? '' : trim($requestedProfileId);

        if ($requested !== '') {
            $ownedId = $this->firstIdFrom($this->db->query(
                "SELECT id FROM user_profiles WHERE id = ? AND user_id = ? LIMIT 1",
                [$requested, $userId]
            ));

            if ($ownedId === '') {
                throw ProfileNotOwnedException::forRequestedProfile($userId, $requested);
            }

            return $ownedId;
        }

        $defaultId = $this->defaultProfileIdFor($userId);
        if ($defaultId !== '') {
            return $defaultId;
        }

        $this->createDefaultProfileFor($userId);

        $created = $this->defaultProfileIdFor($userId);
        if ($created === '') {
            // The INSERT reported success but the row is not readable back. Treat
            // that as a hard failure rather than inventing an id: a fabricated
            // profile_id would violate `fk_user_item_data_profile` on the very next
            // write, and silently returning '' would write data nobody can read.
            throw new \RuntimeException(
                'Failed to establish a default profile for user ' . $userId
            );
        }

        return $created;
    }

    /**
     * The account's active profile id, else its earliest-created, else its lowest
     * id — the deterministic tiebreak shared with migrations 079 and 100.
     *
     * @param string $userId Account UUID.
     *
     * @return string The profile UUID, or '' when the account owns no profile.
     */
    private function defaultProfileIdFor(string $userId): string
    {
        return $this->firstIdFrom($this->db->query(
            "SELECT id FROM user_profiles
             WHERE user_id = ?
             ORDER BY is_active DESC, created_at ASC, id ASC
             LIMIT 1",
            [$userId]
        ));
    }

    /**
     * Create the account's first profile, named after its username.
     *
     * The name is only used when it satisfies {@see create()}'s own validation
     * predicate verbatim; anything else falls back to
     * {@see self::DEFAULT_PROFILE_NAME} rather than being truncated, so this can
     * never throw for a name reason (a multi-byte username can be short in
     * characters yet over the byte limit `create()` measures).
     *
     * @param string $userId Account UUID.
     *
     * @return string The new profile UUID.
     */
    private function createDefaultProfileFor(string $userId): string
    {
        $row = UserRow::firstFromMixed($this->db->query(
            "SELECT username FROM users WHERE id = ? LIMIT 1",
            [$userId]
        ));

        $rawName = $row['username'] ?? null;
        $name = is_string($rawName) ? trim($rawName) : '';
        if (strlen($name) < self::MIN_NAME_LENGTH || strlen($name) > self::MAX_NAME_LENGTH) {
            $name = self::DEFAULT_PROFILE_NAME;
        }

        return $this->create($userId, ['name' => $name]);
    }

    /**
     * Extract the `id` column of the first row of a driver result as a string.
     *
     * @param mixed $result Raw `Connection::query()` return value.
     *
     * @return string The id, or '' when there is no row / no usable id.
     */
    private function firstIdFrom(mixed $result): string
    {
        $row = UserRow::firstFromMixed($result);
        if ($row === null) {
            return '';
        }

        $id = $row['id'] ?? null;

        return is_string($id) ? $id : '';
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
        $countRow = UserRow::firstFromMixed($this->db->query(
            "SELECT COUNT(*) as count FROM user_profiles WHERE user_id = ?",
            [$userId]
        ));

        $maxProfiles = $this->maxProfiles();
        if (UserRow::int($countRow, 'count', 0) >= $maxProfiles) {
            throw new \InvalidArgumentException(
                'Maximum number of profiles (' . $maxProfiles . ') reached'
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
        // The first profile for a user auto-becomes active (per docblock contract).
        // is_active = true only if: explicitly set, OR it's the user's first profile.
        $isFirstProfile = UserRow::int($countRow, 'count', 0) === 0;
        $isActive = $data['is_active'] ?? $isFirstProfile;

        $this->db->query(
            "INSERT INTO user_profiles (id, user_id, name, avatar_url, is_active, is_admin)
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                $id,
                $userId,
                $name,
                $data['avatar_url'] ?? null,
                $isActive,
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
     * @see setPin() To set or change a profile PIN
     * @see removePin() To remove the PIN requirement
     */
    public function verifyPin(string $profileId, string $pin): bool
    {
        $row = UserRow::firstFromMixed($this->db->query(
            "SELECT pin_hash FROM profile_settings WHERE profile_id = ?",
            [$profileId]
        ));

        $pinHash = UserRow::string($row, 'pin_hash');
        if ($pinHash === null || $pinHash === '') {
            return true; // No PIN set, allow access
        }

        return password_verify($pin, $pinHash);
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
     * @see getAllowedRatings() To get list of all allowed ratings for a profile
     */
    public function isContentRatingAllowed(string $profileId, string $contentRating): bool
    {
        $settings = UserRow::firstFromMixed($this->db->query(
            "SELECT content_rating, allow_unrated FROM profile_settings WHERE profile_id = ?",
            [$profileId]
        ));

        if ($settings === null) {
            return true; // No settings, allow all
        }

        // Unrated content check
        if ($contentRating === 'UNRATED') {
            return (bool)($settings['allow_unrated'] ?? false);
        }

        // Check rating order
        $profileRating = UserRow::string($settings, 'content_rating') ?? self::DEFAULT_CONTENT_RATING;
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
     * @see isContentRatingAllowed() To check a single rating
     */
    public function getAllowedRatings(string $profileId): array
    {
        $settings = UserRow::firstFromMixed($this->db->query(
            "SELECT content_rating, allow_unrated FROM profile_settings WHERE profile_id = ?",
            [$profileId]
        ));

        if ($settings === null) {
            return ['G', 'PG', 'PG-13', 'R', 'NC-17', 'X', 'UNRATED'];
        }

        $maxRating = UserRow::string($settings, 'content_rating') ?? self::DEFAULT_CONTENT_RATING;
        $maxLevel = self::RATING_ORDER[$maxRating] ?? 4;

        $allowed = [];
        foreach (self::RATING_ORDER as $rating => $level) {
            if ($level <= $maxLevel) {
                $allowed[] = $rating;
            }
        }

        if (!empty($settings['allow_unrated'])) {
            $allowed[] = 'UNRATED';
        }

        return $allowed;
    }

    /**
     * Resolve the parental content-rating filter for a user's ACTIVE profile,
     * for the media browse/listing read path.
     *
     * This is the single decision point that wires the profile's parental cap
     * into the listing SQL. It returns `null` — meaning "apply NO filtering",
     * the permissive default — in every case where the profile context is
     * absent, unknown, or non-restrictive, so a restricted view can never be
     * applied by accident:
     *
     *   - the user has no active profile;
     *   - the active profile is an admin profile (`is_admin` — the account
     *     owner / manager, whose browse must stay exactly as today);
     *   - the profile has no `profile_settings` row (no cap configured);
     *   - the cap is the most-permissive value (`UNRATED` / the max rank),
     *     which by definition allows every rating.
     *
     * Otherwise it returns the concrete allow-list the browse SQL threads in:
     *
     *   ['allowedRatings' => string[], 'allowUnrated' => bool]
     *
     * where `allowedRatings` are the canonical rating strings whose rank is
     * `<= cap` (mirroring {@see getAllowedRatings()} / the interleaved
     * {@see \Phlix\Media\Library\ItemRepository::RATING_ORDER} scale, so a
     * movie-terms cap like `PG-13` also gates `TV-14`), and `allowUnrated`
     * governs whether genuinely-unrated items (`content_rating IS NULL`) are
     * included. The `UNRATED` STRING is deliberately never in `allowedRatings`
     * for a capped profile (it is rank 7, above any real cap), so stored
     * `UNRATED` rows — including certs normalized-but-unrecognized — stay behind
     * the cap; NULL-column items are the only "unrated" content the profile can
     * see, and only when `allowUnrated` is true.
     *
     * @param string $userId The account (user) identifier whose active profile
     *                        governs the current request.
     *
     * @return array{allowedRatings: list<string>, allowUnrated: bool}|null
     *         The parental allow-list, or null when no filtering should apply.
     */
    public function getActiveRatingFilter(string $userId): ?array
    {
        $profile = $this->getActiveProfile($userId);
        if ($profile === null) {
            return null;
        }

        // Account owner / admin profile: preserve exactly today's behaviour.
        if (($profile['is_admin'] ?? false) === true) {
            return null;
        }

        $profileId = is_string($profile['id'] ?? null) ? $profile['id'] : '';
        if ($profileId === '') {
            return null;
        }

        $settings = UserRow::firstFromMixed($this->db->query(
            "SELECT content_rating, allow_unrated FROM profile_settings WHERE profile_id = ?",
            [$profileId]
        ));

        // No parental-controls row → no cap configured → permissive.
        if ($settings === null) {
            return null;
        }

        $maxRating = UserRow::string($settings, 'content_rating') ?? self::DEFAULT_CONTENT_RATING;
        $maxLevel = self::RATING_ORDER[$maxRating] ?? self::RATING_ORDER['UNRATED'];

        // Most-permissive cap (UNRATED / max rank) allows everything → no filter.
        if ($maxLevel >= self::RATING_ORDER['UNRATED']) {
            return null;
        }

        $allowUnrated = (bool)($settings['allow_unrated'] ?? true);

        $allowedRatings = [];
        foreach (self::RATING_ORDER as $rating => $level) {
            if ($level <= $maxLevel) {
                $allowedRatings[] = $rating;
            }
        }

        return [
            'allowedRatings' => $allowedRatings,
            'allowUnrated' => $allowUnrated,
        ];
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
     *     allow_unrated?: bool,
     *     name?: string,
     *     avatar_url?: string|null,
     *     is_active?: bool
     * } $data Settings fields to update (see create() for field descriptions).
     *   `name`/`avatar_url`/`is_active` are ignored here — they are applied by the
     *   caller against user_profiles — but the caller forwards the whole $data
     *   array, so the shape has to admit them.
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
     * @param array<string, mixed> $row Raw database row from user_profiles LEFT JOIN profile_settings
     *
     * @return array<string, mixed> Hydrated profile array with settings sub-array when applicable
     *
     * @internal
     */
    private function hydrateProfile(array $row): array
    {
        $profile = [
            'id' => $row['id'] ?? null,
            'user_id' => $row['user_id'] ?? null,
            'name' => $row['name'] ?? null,
            'avatar_url' => $row['avatar_url'] ?? null,
            'is_active' => (bool)($row['is_active'] ?? false),
            'is_admin' => (bool)($row['is_admin'] ?? false),
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];

        // Compute the numeric rating rank for the TS Profile interface (1.2c
        // contract). RATING_ORDER is a rating-string => rank map, so this is a
        // KEY lookup (array_search would search the INT values against a string
        // and always miss). Ranks are 0-based and canonical — no `-1` offset.
        // An unknown/missing content_rating defaults to the most-restrictive
        // UNRATED rank, matching isContentRatingAllowed()/getAllowedRatings().
        if (isset($row['content_rating'])) {
            $contentRating = is_string($row['content_rating']) ? $row['content_rating'] : '';
            $profile['rating'] = self::RATING_ORDER[$contentRating] ?? self::RATING_ORDER['UNRATED'];
        }

        // Settings
        if (isset($row['content_rating'])) {
            $maxDaily = $row['max_daily_watch_time'] ?? 0;
            $settings = [
                'content_rating' => $row['content_rating'],
                'pin_required_for_admin' => (bool)($row['pin_required_for_admin'] ?? false),
                'max_daily_watch_time' => is_numeric($maxDaily) ? (int) $maxDaily : 0,
                'allow_unrated' => (bool)($row['allow_unrated'] ?? true),
            ];

            $allowedGenres = $row['allowed_genres'] ?? null;
            if (is_string($allowedGenres) && $allowedGenres !== '') {
                $settings['allowed_genres'] = json_decode($allowedGenres, true);
            }

            $blockedGenres = $row['blocked_genres'] ?? null;
            if (is_string($blockedGenres) && $blockedGenres !== '') {
                $settings['blocked_genres'] = json_decode($blockedGenres, true);
            }

            $profile['settings'] = $settings;
        }

        return $profile;
    }

    /**
     * Gets the active theme ID for a user.
     *
     * Returns the theme ID set for the user's active profile, or null if
     * no theme preference has been set.
     *
     * @param string $userId The unique user identifier (UUID format)
     *
     * @return string|null The active theme ID, or null if not set
     *
     * @since 0.14.0
     *
     * @see setActiveThemeId() To set a user's active theme
     */
    public function getActiveThemeId(string $userId): ?string
    {
        /** @var array<array<string, mixed>> $result */
        $result = $this->db->query(
            "SELECT active_theme_id FROM user_profiles WHERE user_id = ? AND is_active = TRUE LIMIT 1",
            [$userId]
        );

        if (count($result) === 0) {
            return null;
        }

        /** @var array<string, mixed> $row */
        $row = $result[0];

        return is_string($row['active_theme_id'] ?? null) ? $row['active_theme_id'] : null;
    }

    /**
     * Sets the active theme for a user.
     *
     * Updates the active_theme_id for all active profiles of the user.
     *
     * @param string $userId The unique user identifier (UUID format)
     * @param string $themeId The theme identifier to set as active
     *
     * @return void
     *
     * @since 0.14.0
     *
     * @see getActiveThemeId() To retrieve a user's active theme
     */
    public function setActiveThemeId(string $userId, string $themeId): void
    {
        $this->db->query(
            "UPDATE user_profiles SET active_theme_id = ? WHERE user_id = ? AND is_active = TRUE",
            [$themeId, $userId]
        );
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
        return Uuid::v4();
    }
}
