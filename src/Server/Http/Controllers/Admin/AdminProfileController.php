<?php

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
     * Mapping of integer rating (0-6) to string rating label.
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
    ];
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
     * @param int $userId User ID
     *
     * @return Response 200 { profiles: Profile[] } | 404 { error }
     */
    public function listForUser(int $userId): Response
    {
        $user = $this->userRepository->findById((string) $userId);
        if ($user === null) {
            return (new Response())->status(404)->json(['error' => 'User not found']);
        }

        $profiles = $this->profileManager->findByUserId((string) $userId);
        return (new Response())->json(['profiles' => $profiles]);
    }

    /**
     * Create a new profile for a user.
     *
     * @param int     $userId User ID
     * @param Request $req    Request with name (required) and rating (optional, 0-6)
     *
     * @return Response 201 { profile_id: int, message: string }
     *                  | 400 { error: string }
     *                  | 404 { error: string }
     */
    public function createForUser(int $userId, Request $req): Response
    {
        $user = $this->userRepository->findById((string) $userId);
        if ($user === null) {
            return (new Response())->status(404)->json(['error' => 'User not found']);
        }

        $existing = $this->profileManager->findByUserId((string) $userId);
        if (count($existing) >= UserProfileManager::MAX_PROFILES_PER_USER) {
            return (new Response())->status(400)->json(['error' => 'Maximum profiles reached']);
        }

        $name = is_string($req->input('name')) ? trim($req->input('name')) : '';
        if (strlen($name) < 1 || strlen($name) > 50) {
            return (new Response())->status(400)->json([
                'error' => 'Invalid name',
                'field_errors' => ['name' => 'Name must be 1-50 characters'],
            ]);
        }

        $rating = $req->input('rating');
        if ($rating !== null && !is_int($rating) && !is_numeric($rating)) {
            return (new Response())->status(400)->json([
                'error' => 'Invalid rating',
                'field_errors' => ['rating' => 'Rating must be an integer 0-6'],
            ]);
        }
        if ($rating !== null) {
            $ratingInt = is_int($rating) ? $rating : (int) $rating;
            if ($ratingInt < 0 || $ratingInt > 6) {
                return (new Response())->status(400)->json([
                    'error' => 'Invalid rating',
                    'field_errors' => ['rating' => 'Rating must be 0-6'],
                ]);
            }
        }

        $data = ['name' => $name];
        if ($rating !== null) {
            $ratingInt = is_int($rating) ? $rating : (int) $rating;
            $data['content_rating'] = self::RATING_MAP[$ratingInt] ?? 'R';
        }

        $newId = $this->profileManager->create((string) $userId, $data);
        return (new Response())->status(201)->json([
            'profile_id' => (int) $newId,
            'message' => 'Profile created successfully',
        ]);
    }

    /**
     * Get a single profile by ID.
     *
     * @param int $profileId Profile ID
     *
     * @return Response 200 { profile: Profile } | 404 { error }
     */
    public function get(int $profileId): Response
    {
        $profile = $this->profileManager->findByIdWithSettings((string) $profileId);
        if ($profile === null) {
            return (new Response())->status(404)->json(['error' => 'Profile not found']);
        }
        return (new Response())->json(['profile' => $profile]);
    }

    /**
     * Update an existing profile.
     *
     * @param int     $profileId Profile ID
     * @param Request $req       Request with optional name and/or rating
     *
     * @return Response 200 { message: string } | 404 { error } | 400 { error }
     */
    public function update(int $profileId, Request $req): Response
    {
        $profile = $this->profileManager->findById((string) $profileId);
        if ($profile === null) {
            return (new Response())->status(404)->json(['error' => 'Profile not found']);
        }

        $name = $req->input('name');
        $rating = $req->input('rating');

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
                    'field_errors' => ['rating' => 'Rating must be an integer 0-6'],
                ]);
            }
            $ratingInt = is_int($rating) ? $rating : (int) $rating;
            if ($ratingInt < 0 || $ratingInt > 6) {
                return (new Response())->status(400)->json([
                    'error' => 'Invalid rating',
                    'field_errors' => ['rating' => 'Rating must be 0-6'],
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
            $this->profileManager->update((string) $profileId, $data);
        }

        return (new Response())->json(['message' => 'Profile updated successfully']);
    }

    /**
     * Delete a profile.
     *
     * @param int $profileId Profile ID
     *
     * @return Response 200 { message: string } | 404 { error }
     */
    public function delete(int $profileId): Response
    {
        $profile = $this->profileManager->findById((string) $profileId);
        if ($profile === null) {
            return (new Response())->status(404)->json(['error' => 'Profile not found']);
        }

        $this->profileManager->delete((string) $profileId);
        return (new Response())->json(['message' => 'Profile deleted successfully']);
    }

    /**
     * Set or clear the PIN for a profile.
     *
     * @param int     $profileId Profile ID
     * @param Request $req      Request with optional pin (4 or 6 digits, or null/empty to clear)
     *
     * @return Response 200 { message: string }
     *                  | 400 { error: string }
     *                  | 404 { error: string }
     */
    public function setPin(int $profileId, Request $req): Response
    {
        $profile = $this->profileManager->findById((string) $profileId);
        if ($profile === null) {
            return (new Response())->status(404)->json(['error' => 'Profile not found']);
        }

        $pin = $req->input('pin');

        if ($pin === null || $pin === '') {
            $this->profileManager->removePin((string) $profileId);
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

        $this->profileManager->setPin((string) $profileId, $pin);
        return (new Response())->json(['message' => 'PIN set successfully']);
    }

    /**
     * Delete/clear the PIN for a profile.
     *
     * @param int $profileId Profile ID
     *
     * @return Response 200 { message: string } | 404 { error }
     */
    public function deletePin(int $profileId): Response
    {
        $profile = $this->profileManager->findById((string) $profileId);
        if ($profile === null) {
            return (new Response())->status(404)->json(['error' => 'Profile not found']);
        }

        $this->profileManager->removePin((string) $profileId);
        return (new Response())->json(['message' => 'PIN deleted successfully']);
    }
}
