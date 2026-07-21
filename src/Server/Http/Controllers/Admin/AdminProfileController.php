<?php

/**
 * Phlix media server component: Admin.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers\Admin;

use Phlix\Auth\UserProfileManager;
use Phlix\Auth\UserRepository;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * Admin JSON API for user profile management (Step 1.2b).
 *
 * Provides 7 REST endpoints for administering user profiles:
 * - GET    /api/v1/admin/users/{userId}/profiles      — list profiles for a user
 * - POST   /api/v1/admin/users/{userId}/profiles    — create a new profile
 * - GET    /api/v1/admin/profiles/{id}               — get a single profile
 * - PUT    /api/v1/admin/profiles/{id}               — update a profile
 * - DELETE /api/v1/admin/profiles/{id}              — delete a profile
 * - POST   /api/v1/admin/profiles/{id}/pin           — set/clear profile PIN
 * - DELETE /api/v1/admin/profiles/{id}/pin         — delete profile PIN
 *
 * All routes are gated by {@see \Phlix\Server\Http\Middleware\AdminMiddleware}
 * (registered in {@see \Phlix\Server\Http\Routes\AdminRoutes}); non-admin
 * callers receive a JSON 401/403 from the middleware. This controller assumes
 * it only runs for authenticated admins.
 *
 * @package Phlix\Server\Http\Controllers\Admin
 * @since 1.2b
 */
final class AdminProfileController
{
    /**
     * Mapping of integer rating (0-12) to string rating label.
     *
     * Keys 0-6 are the original MPAA movie ratings and are STABLE (never
     * renumbered). Keys 7-12 append the US TV ratings so an admin can cap a
     * profile at a TV rating; they compare on the shared restrictiveness scale
     * (see {@see \Phlix\Media\Library\ContentRating}).
     *
     * @var array<int, string>
     */
    private const RATING_MAP = [
        0 => 'G',
        1 => 'PG',
        2 => 'PG-13',
        3 => 'R',
        4 => 'NC-17',
        5 => 'X',
        6 => 'UNRATED',
        7 => 'TV-Y',
        8 => 'TV-G',
        9 => 'TV-Y7',
        10 => 'TV-PG',
        11 => 'TV-14',
        12 => 'TV-MA',
    ];

    /** Highest valid numeric rating key accepted by create()/update(). */
    private const MAX_RATING_INT = 12;
    /**
     * @param UserProfileManager $profileManager Profile management service
     * @param UserRepository    $userRepository  User repository for user existence checks
     */
    public function __construct(
        private readonly UserProfileManager $profileManager,
        private readonly UserRepository $userRepository,
    ) {
    }

    /**
     * List all profiles for a user.
     *
     * @param Request               $request The HTTP request (unused body).
     * @param array<string, string> $params  Path parameters ({userId} — a UUID string).
     *
     * @return Response 200 { profiles: Profile[] } | 404 { error }
     */
    public function listForUser(Request $request, array $params): Response
    {
        $userId = is_string($params['userId'] ?? null) ? $params['userId'] : '';
        $user = $this->userRepository->findById($userId);
        if ($user === null) {
            return (new Response())->status(404)->json(['error' => 'User not found']);
        }

        $profiles = $this->profileManager->findByUserId($userId);
        return (new Response())->json(['profiles' => $profiles]);
    }

    /**
     * Create a new profile for a user.
     *
     * @param Request               $request The HTTP request (name required, rating optional 0-12).
     * @param array<string, string> $params  Path parameters ({userId} — a UUID string).
     *
     * @return Response 201 { profile_id: int, message: string }
     *                  | 400 { error: string }
     *                  | 404 { error: string }
     */
    public function createForUser(Request $request, array $params): Response
    {
        $userId = is_string($params['userId'] ?? null) ? $params['userId'] : '';
        $user = $this->userRepository->findById($userId);
        if ($user === null) {
            return (new Response())->status(404)->json(['error' => 'User not found']);
        }

        // Must read the EFFECTIVE cap, not the shipped constant. This
        // pre-check returns 400 before UserProfileManager::create() runs, so
        // it — not create()'s own guard — is what an operator actually hits.
        // Leaving the constant here would have pinned the admin API at 5 while
        // `auth.max_profiles` appeared to be wired.
        $existing = $this->profileManager->findByUserId($userId);
        if (count($existing) >= $this->profileManager->maxProfiles()) {
            return (new Response())->status(400)->json(['error' => 'Maximum profiles reached']);
        }

        $name = is_string($request->input('name')) ? trim($request->input('name')) : '';
        if (strlen($name) < 1 || strlen($name) > 50) {
            return (new Response())->status(400)->json([
                'error' => 'Invalid name',
                'field_errors' => ['name' => 'Name must be 1-50 characters'],
            ]);
        }

        $rating = $request->input('rating');
        if ($rating !== null && !is_int($rating) && !is_numeric($rating)) {
            return (new Response())->status(400)->json([
                'error' => 'Invalid rating',
                'field_errors' => ['rating' => 'Rating must be an integer 0-' . self::MAX_RATING_INT],
            ]);
        }
        if ($rating !== null) {
            $ratingInt = is_int($rating) ? $rating : (int) $rating;
            if ($ratingInt < 0 || $ratingInt > self::MAX_RATING_INT) {
                return (new Response())->status(400)->json([
                    'error' => 'Invalid rating',
                    'field_errors' => ['rating' => 'Rating must be 0-' . self::MAX_RATING_INT],
                ]);
            }
        }

        $data = ['name' => $name];
        if ($rating !== null) {
            $ratingInt = is_int($rating) ? $rating : (int) $rating;
            $data['content_rating'] = self::RATING_MAP[$ratingInt] ?? 'R';
        }

        $newId = $this->profileManager->create($userId, $data);
        return (new Response())->status(201)->json([
            'profile_id' => (int) $newId,
            'message' => 'Profile created successfully',
        ]);
    }

    /**
     * Get a single profile by ID.
     *
     * @param Request               $request The HTTP request (unused body).
     * @param array<string, string> $params  Path parameters ({id} — a UUID string).
     *
     * @return Response 200 { profile: Profile } | 404 { error }
     */
    public function get(Request $request, array $params): Response
    {
        $profileId = is_string($params['id'] ?? null) ? $params['id'] : '';
        $profile = $this->profileManager->findByIdWithSettings($profileId);
        if ($profile === null) {
            return (new Response())->status(404)->json(['error' => 'Profile not found']);
        }
        return (new Response())->json(['profile' => $profile]);
    }

    /**
     * Update an existing profile.
     *
     * @param Request               $request The HTTP request (optional name and/or rating).
     * @param array<string, string> $params  Path parameters ({id} — a UUID string).
     *
     * @return Response 200 { message: string } | 404 { error } | 400 { error }
     */
    public function update(Request $request, array $params): Response
    {
        $profileId = is_string($params['id'] ?? null) ? $params['id'] : '';
        $profile = $this->profileManager->findById($profileId);
        if ($profile === null) {
            return (new Response())->status(404)->json(['error' => 'Profile not found']);
        }

        $name = $request->input('name');
        $rating = $request->input('rating');

        if ($name !== null) {
            $name = is_string($name) ? trim($name) : '';
            if (strlen($name) < 1 || strlen($name) > 50) {
                return (new Response())->status(400)->json([
                    'error' => 'Invalid name',
                    'field_errors' => ['name' => 'Name must be 1-50 characters'],
                ]);
            }
        }

        if ($rating !== null) {
            if (!is_int($rating) && !is_numeric($rating)) {
                return (new Response())->status(400)->json([
                    'error' => 'Invalid rating',
                    'field_errors' => ['rating' => 'Rating must be an integer 0-' . self::MAX_RATING_INT],
                ]);
            }
            $ratingInt = is_int($rating) ? $rating : (int) $rating;
            if ($ratingInt < 0 || $ratingInt > self::MAX_RATING_INT) {
                return (new Response())->status(400)->json([
                    'error' => 'Invalid rating',
                    'field_errors' => ['rating' => 'Rating must be 0-' . self::MAX_RATING_INT],
                ]);
            }
        }

        $data = [];
        if ($name !== null) {
            $data['name'] = $name;
        }
        if ($rating !== null) {
            $ratingInt = is_int($rating) ? $rating : (int) $rating;
            $data['content_rating'] = self::RATING_MAP[$ratingInt] ?? 'R';
        }

        if ($data !== []) {
            $this->profileManager->update($profileId, $data);
        }

        return (new Response())->json(['message' => 'Profile updated successfully']);
    }

    /**
     * Delete a profile.
     *
     * @param Request               $request The HTTP request (unused body).
     * @param array<string, string> $params  Path parameters ({id} — a UUID string).
     *
     * @return Response 200 { message: string } | 404 { error }
     */
    public function delete(Request $request, array $params): Response
    {
        $profileId = is_string($params['id'] ?? null) ? $params['id'] : '';
        $profile = $this->profileManager->findById($profileId);
        if ($profile === null) {
            return (new Response())->status(404)->json(['error' => 'Profile not found']);
        }

        $this->profileManager->delete($profileId);
        return (new Response())->json(['message' => 'Profile deleted successfully']);
    }

    /**
     * Set or clear the PIN for a profile.
     *
     * @param Request               $request The HTTP request (optional pin: 4 or 6 digits, or null/empty to clear).
     * @param array<string, string> $params  Path parameters ({id} — a UUID string).
     *
     * @return Response 200 { message: string }
     *                  | 400 { error: string }
     *                  | 404 { error: string }
     */
    public function setPin(Request $request, array $params): Response
    {
        $profileId = is_string($params['id'] ?? null) ? $params['id'] : '';
        $profile = $this->profileManager->findById($profileId);
        if ($profile === null) {
            return (new Response())->status(404)->json(['error' => 'Profile not found']);
        }

        $pin = $request->input('pin');

        if ($pin === null || $pin === '') {
            $this->profileManager->removePin($profileId);
            return (new Response())->json(['message' => 'PIN cleared successfully']);
        }

        if (!is_string($pin)) {
            return (new Response())->status(400)->json(['error' => 'PIN must be a string']);
        }

        if (
            strlen($pin) !== UserProfileManager::PIN_LENGTH_4
            && strlen($pin) !== UserProfileManager::PIN_LENGTH_6
        ) {
            return (new Response())->status(400)->json(['error' => 'Invalid PIN length']);
        }

        if (!ctype_digit($pin)) {
            return (new Response())->status(400)->json(['error' => 'PIN must contain only digits']);
        }

        $this->profileManager->setPin($profileId, $pin);
        return (new Response())->json(['message' => 'PIN set successfully']);
    }

    /**
     * Delete/clear the PIN for a profile.
     *
     * @param Request               $request The HTTP request (unused body).
     * @param array<string, string> $params  Path parameters ({id} — a UUID string).
     *
     * @return Response 200 { message: string } | 404 { error }
     */
    public function deletePin(Request $request, array $params): Response
    {
        $profileId = is_string($params['id'] ?? null) ? $params['id'] : '';
        $profile = $this->profileManager->findById($profileId);
        if ($profile === null) {
            return (new Response())->status(404)->json(['error' => 'Profile not found']);
        }

        $this->profileManager->removePin($profileId);
        return (new Response())->json(['message' => 'PIN deleted successfully']);
    }
}
