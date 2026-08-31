<?php

/**
 * Phlix media server component: Server\Http\Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Access\ProfileAccessPolicy;
use Phlix\Auth\AuthManager;
use Phlix\Auth\RateLimitException;
use Phlix\Auth\UserProfileManager;
use Phlix\Common\RateLimit\RateLimiterInterface;
use Phlix\Media\Storage\AvatarStorage;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * S81 — self-service profiles: CRUD / PIN / switch / avatar for the CALLER'S
 * OWN account.
 *
 * Mirrors {@see \Phlix\Server\Http\Controllers\Admin\AdminProfileController}
 * but ownership-checked against the authenticated caller's own `user_id`
 * (via {@see ProfileAccessPolicy::canManageProfile()}), NOT against
 * AdminMiddleware — an admin may still manage another account's profiles
 * through the admin controller; this surface is the end-user one.
 *
 * ## Refusals are 404, never 403
 *
 * Every profile-scoped action answers 404 for "no such profile" AND "not
 * yours" (the {@see ProfileTagController::denyUnlessProfileManageable()}
 * pattern): a 403 would confirm to an unentitled caller that the profile
 * exists.
 *
 * ## The security-relevant decisions (S81 blocker record)
 *
 * - **Switch re-mints tokens.** Profile context lives in the JWT claim; the
 *   switch endpoint answers with {@see AuthManager::buildAuthResponse()} so
 *   the caller's session moves to the new profile immediately, never at token
 *   expiry.
 * - **Activation is switch-only.** `update()` is deliberately unable to set
 *   `is_active` here: it would re-open the two-active-profiles hole
 *   (`update()` sets one row without clearing its siblings; there is no
 *   unique index on `(user_id, is_active)`). A request carrying `is_active`
 *   is refused with 400 `profile.use_switch`.
 * - **PIN verify is throttled and fails closed.** The endpoint sits behind
 *   the `rate_limiter.pin_verify` profile (DB-backed, 5 / 300s, keyed per
 *   profile — only the profile's owner can burn its own budget); a profile
 *   with NO PIN answers 409 `profile.no_pin` instead of a pass.
 * - **Deleting the LAST profile is refused** — an account with zero profiles
 *   has no active profile and every profile-scoped session breaks. Deleting
 *   an ACTIVE (non-last) profile is allowed; `resolveProfileIdForUser()`
 *   heals the session on its next profile-scoped write.
 *
 * Every constructor parameter is REQUIRED: PHP-DI's `autowire()` silently
 * skips optional ones, and a nullable dependency here would be one wiring
 * mistake away from an unthrottled PIN oracle (the S81 blocker class).
 *
 * @package Phlix\Server\Http\Controllers
 * @since   S81
 */
final class ProfilesController
{
    public function __construct(
        private readonly UserProfileManager $profiles,
        private readonly ProfileAccessPolicy $accessPolicy,
        private readonly AuthManager $auth,
        private readonly AvatarStorage $avatars,
        private readonly RateLimiterInterface $pinVerifyLimiter,
    ) {
    }

    /**
     * List the authenticated user's own profiles.
     *
     * GET /api/v1/profiles
     *
     * @param Request               $request The HTTP request (userId set from auth).
     * @param array<string, string> $params  Path parameters (unused).
     *
     * @return Response 200 { profiles: array } — hydrated rows (pin_hash
     *                   never leaves the model; `findByUserId()` strips it).
     */
    public function list(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';
        if ($userId === '') {
            return (new Response())->status(401)->json(['error' => 'Unauthorized']);
        }

        return (new Response())->json([
            'profiles' => $this->profiles->findByUserId($userId),
        ]);
    }

    /**
     * Create a profile on the authenticated user's own account.
     *
     * POST /api/v1/profiles
     *
     * Body: `{ name: string (required, 3-50), content_rating?: string,
     * pin?: string (4/6 digits), pin_required_for_admin?: bool }`
     *
     * @param Request               $request The HTTP request (userId set from auth).
     * @param array<string, string> $params  Path parameters (unused).
     *
     * @return Response 201 { profile_id, message } | 400 { error }
     */
    public function create(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';
        if ($userId === '') {
            return (new Response())->status(401)->json(['error' => 'Unauthorized']);
        }

        $data = is_array($request->body) ? $request->body : [];
        $name = $data['name'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            return (new Response())->status(400)->json(['error' => 'Profile name is required']);
        }

        $payload = [
            'name' => trim($name),
            'pin_required_for_admin' => (bool)($data['pin_required_for_admin'] ?? false),
        ];
        if (is_string($data['content_rating'] ?? null) && $data['content_rating'] !== '') {
            $payload['content_rating'] = $data['content_rating'];
        }
        if (is_string($data['pin'] ?? null) && $data['pin'] !== '') {
            $payload['pin'] = $data['pin'];
        }

        try {
            $profileId = $this->profiles->create($userId, $payload);
        } catch (\InvalidArgumentException $e) {
            return (new Response())->status(400)->json(['error' => $e->getMessage()]);
        }

        return (new Response())->status(201)->json([
            'profile_id' => $profileId,
            'message' => 'Profile created',
        ]);
    }

    /**
     * Get one of the caller's own profiles, with settings.
     *
     * GET /api/v1/profiles/{profileId}
     *
     * @param Request               $request The HTTP request (userId set from auth).
     * @param array<string, string> $params  Path parameters ({profileId}).
     *
     * @return Response 200 { profile } | 404 { error } (uniform for
     *                   "no such profile" and "not yours").
     */
    public function get(Request $request, array $params): Response
    {
        $profileId = $this->parseProfileId($params['profileId'] ?? null);
        if ($profileId === null) {
            return (new Response())->status(400)->json(['error' => 'Invalid profile ID']);
        }

        $denied = $this->denyUnlessProfileManageable($request, $profileId);
        if ($denied !== null) {
            return $denied;
        }

        $profile = $this->profiles->findByIdWithSettings($profileId);
        if ($profile === null) {
            return (new Response())->status(404)->json(['error' => 'Profile not found']);
        }

        // pin_hash never leaves the server (findByIdWithSettings joins it in).
        unset($profile['pin_hash']);

        return (new Response())->json(['profile' => $profile]);
    }

    /**
     * Update one of the caller's own profiles.
     *
     * PUT /api/v1/profiles/{profileId}
     *
     * Body: `{ name?: string, content_rating?: string,
     * pin_required_for_admin?: bool }` — `is_active` is REFUSED (use the
     * switch endpoint; see the class docblock), `pin` is handled by the
     * dedicated pin endpoints, and `avatar_url` by the avatar endpoint.
     *
     * @param Request               $request The HTTP request (userId set from auth).
     * @param array<string, string> $params  Path parameters ({profileId}).
     *
     * @return Response 200 { message } | 400 { error } | 404 { error }
     */
    public function update(Request $request, array $params): Response
    {
        $profileId = $this->parseProfileId($params['profileId'] ?? null);
        if ($profileId === null) {
            return (new Response())->status(400)->json(['error' => 'Invalid profile ID']);
        }

        $denied = $this->denyUnlessProfileManageable($request, $profileId);
        if ($denied !== null) {
            return $denied;
        }

        $data = is_array($request->body) ? $request->body : [];

        if (array_key_exists('is_active', $data)) {
            return (new Response())->status(400)->json([
                'error' => 'profile.use_switch',
                'message' => 'Activation is switch-only: use POST /api/v1/profiles/{id}/switch',
            ]);
        }

        $payload = [];
        if (isset($data['name']) && is_string($data['name'])) {
            $payload['name'] = $data['name'];
        }
        if (array_key_exists('content_rating', $data)) {
            if (!is_string($data['content_rating'])) {
                return (new Response())->status(400)->json(['error' => 'content_rating must be a string']);
            }
            $payload['content_rating'] = $data['content_rating'];
        }
        if (array_key_exists('pin_required_for_admin', $data)) {
            $payload['pin_required_for_admin'] = (bool)$data['pin_required_for_admin'];
        }

        try {
            $this->profiles->update($profileId, $payload);
        } catch (\InvalidArgumentException $e) {
            return (new Response())->status(400)->json(['error' => $e->getMessage()]);
        }

        return (new Response())->json(['message' => 'Profile updated']);
    }

    /**
     * Delete one of the caller's own profiles.
     *
     * DELETE /api/v1/profiles/{profileId}
     *
     * Refuses the LAST profile (409 `profile.last_profile`): an account with
     * zero profiles has no active profile and every profile-scoped session
     * breaks. Deleting an ACTIVE (non-last) profile is allowed —
     * `resolveProfileIdForUser()` heals the session on its next
     * profile-scoped write.
     *
     * @param Request               $request The HTTP request (userId set from auth).
     * @param array<string, string> $params  Path parameters ({profileId}).
     *
     * @return Response 200 { message } | 404 { error } | 409 { error }
     */
    public function delete(Request $request, array $params): Response
    {
        $profileId = $this->parseProfileId($params['profileId'] ?? null);
        if ($profileId === null) {
            return (new Response())->status(400)->json(['error' => 'Invalid profile ID']);
        }

        $denied = $this->denyUnlessProfileManageable($request, $profileId);
        if ($denied !== null) {
            return $denied;
        }

        $own = $this->profiles->findByUserId($request->userId ?? '');
        if (count($own) <= 1) {
            return (new Response())->status(409)->json([
                'error' => 'profile.last_profile',
                'message' => 'Cannot delete the last profile',
            ]);
        }

        $this->profiles->delete($profileId);

        return (new Response())->json(['message' => 'Profile deleted']);
    }

    /**
     * Set (or clear) the PIN on one of the caller's own profiles.
     *
     * POST /api/v1/profiles/{profileId}/pin
     *
     * Body: `{ pin: string }` — an empty/absent `pin` REMOVES the PIN
     * (mirrors the admin controller's setPin semantics). PINs are exactly
     * 4 or 6 digits.
     *
     * @param Request               $request The HTTP request (userId set from auth).
     * @param array<string, string> $params  Path parameters ({profileId}).
     *
     * @return Response 200 { message } | 400 { error } | 404 { error }
     */
    public function setPin(Request $request, array $params): Response
    {
        $profileId = $this->parseProfileId($params['profileId'] ?? null);
        if ($profileId === null) {
            return (new Response())->status(400)->json(['error' => 'Invalid profile ID']);
        }

        $denied = $this->denyUnlessProfileManageable($request, $profileId);
        if ($denied !== null) {
            return $denied;
        }

        $data = is_array($request->body) ? $request->body : [];
        $pin = $data['pin'] ?? null;

        if ($pin === null || $pin === '') {
            $this->profiles->removePin($profileId);
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

        $this->profiles->setPin($profileId, $pin);
        return (new Response())->json(['message' => 'PIN set successfully']);
    }

    /**
     * Remove the PIN from one of the caller's own profiles.
     *
     * DELETE /api/v1/profiles/{profileId}/pin
     *
     * @param Request               $request The HTTP request (userId set from auth).
     * @param array<string, string> $params  Path parameters ({profileId}).
     *
     * @return Response 200 { message } | 404 { error }
     */
    public function removePin(Request $request, array $params): Response
    {
        $profileId = $this->parseProfileId($params['profileId'] ?? null);
        if ($profileId === null) {
            return (new Response())->status(400)->json(['error' => 'Invalid profile ID']);
        }

        $denied = $this->denyUnlessProfileManageable($request, $profileId);
        if ($denied !== null) {
            return $denied;
        }

        $this->profiles->removePin($profileId);
        return (new Response())->json(['message' => 'PIN removed']);
    }

    /**
     * Verify a PIN against one of the caller's own profiles.
     *
     * POST /api/v1/profiles/{profileId}/pin/verify
     *
     * Body: `{ pin: string }`. Throttled by `rate_limiter.pin_verify`
     * (DB-backed 5 / 300s, keyed per profile) — this is the S81 blocker's
     * "unlimited-attempt PIN oracle" closure. Outcomes:
     *
     *   - 200 { verified: true } — PIN matches
     *   - 403 { error: 'profile.pin_mismatch' } — PIN wrong
     *   - 409 { error: 'profile.no_pin' } — profile has NO PIN configured
     *   - 429 — rate limit tripped (central mapping)
     *
     * @param Request               $request The HTTP request (userId set from auth).
     * @param array<string, string> $params  Path parameters ({profileId}).
     *
     * @return Response
     */
    public function verifyPin(Request $request, array $params): Response
    {
        $profileId = $this->parseProfileId($params['profileId'] ?? null);
        if ($profileId === null) {
            return (new Response())->status(400)->json(['error' => 'Invalid profile ID']);
        }

        $denied = $this->denyUnlessProfileManageable($request, $profileId);
        if ($denied !== null) {
            return $denied;
        }

        $this->enforcePinVerifyLimit($profileId);

        $data = is_array($request->body) ? $request->body : [];
        $pin = $data['pin'] ?? null;
        if (!is_string($pin) || $pin === '') {
            return (new Response())->status(400)->json(['error' => 'PIN is required']);
        }

        if (!$this->profiles->hasPin($profileId)) {
            return (new Response())->status(409)->json(['error' => 'profile.no_pin']);
        }

        if (!$this->profiles->verifyPin($profileId, $pin)) {
            return (new Response())->status(403)->json(['error' => 'profile.pin_mismatch']);
        }

        return (new Response())->json(['verified' => true]);
    }

    /**
     * Switch the caller's session to one of their own profiles.
     *
     * POST /api/v1/profiles/{profileId}/switch
     *
     * Runs {@see UserProfileManager::switchProfile()} (clear-then-set, now
     * transactional) and RE-MINTS the token pair for the new profile via
     * {@see AuthManager::buildAuthResponse()} — profile context lives in the
     * JWT claim, so returning 200 without new tokens would leave the caller
     * on the old profile until expiry.
     *
     * @param Request               $request The HTTP request (userId set from auth).
     * @param array<string, string> $params  Path parameters ({profileId}).
     *
     * @return Response 200 { access_token, refresh_token, profile_id, ... }
     *                   | 404 { error }
     */
    public function switchProfile(Request $request, array $params): Response
    {
        $profileId = $this->parseProfileId($params['profileId'] ?? null);
        if ($profileId === null) {
            return (new Response())->status(400)->json(['error' => 'Invalid profile ID']);
        }

        $denied = $this->denyUnlessProfileManageable($request, $profileId);
        if ($denied !== null) {
            return $denied;
        }

        $userId = $request->userId ?? '';
        if ($userId === '') {
            return (new Response())->status(401)->json(['error' => 'Unauthorized']);
        }

        if (!$this->profiles->switchProfile($userId, $profileId)) {
            return (new Response())->status(404)->json(['error' => 'Profile not found']);
        }

        return (new Response())->json($this->auth->buildAuthResponse($userId, $profileId));
    }

    /**
     * Upload an avatar for one of the caller's own profiles.
     *
     * POST /api/v1/profiles/{profileId}/avatar
     *
     * Mirrors the user-avatar flow (see {@see UserAvatarController}: MIME
     * verified by `getimagesize()` + `finfo`, 5 MB cap, 4096 px cap, GD
     * re-encode strips EXIF, no client filename ever reaches the filesystem)
     * but keyed per PROFILE — `AvatarStorage` stores `<id>.jpg` under its
     * storage dir, and profile ids are CHAR(36) UUIDs with no cross-account
     * collision, so the per-user namespace is shared safely.
     *
     * @param Request               $request The HTTP request (userId set from auth).
     * @param array<string, string> $params  Path parameters ({profileId}).
     *
     * @return Response 200 { avatar_url } | 400 { error } | 404 { error }
     *                   | 500 { error }
     */
    public function uploadAvatar(Request $request, array $params): Response
    {
        $profileId = $this->parseProfileId($params['profileId'] ?? null);
        if ($profileId === null) {
            return (new Response())->status(400)->json(['error' => 'Invalid profile ID']);
        }

        $denied = $this->denyUnlessProfileManageable($request, $profileId);
        if ($denied !== null) {
            return $denied;
        }

        $files = $request->files;
        $file = $files['avatar'] ?? $files['file'] ?? null;

        if ($file === null) {
            return (new Response())->status(400)->json(['error' => 'No file uploaded']);
        }

        $error = is_array($file) && isset($file['error']) && is_int($file['error'])
            ? $file['error']
            : UPLOAD_ERR_NO_FILE;
        if ($error !== UPLOAD_ERR_OK) {
            return (new Response())->status(400)->json([
                'error' => 'Upload error: ' . $this->uploadErrorMessage($error),
            ]);
        }

        $tmpPath = is_array($file) && isset($file['tmp_name']) ? $file['tmp_name'] : '';
        if ($tmpPath === '' || !is_string($tmpPath)) {
            return (new Response())->status(400)->json(['error' => 'Invalid file data']);
        }

        try {
            $avatarPath = $this->avatars->store($profileId, $tmpPath);
        } catch (\InvalidArgumentException $e) {
            return (new Response())->status(400)->json(['error' => $e->getMessage()]);
        } catch (\RuntimeException $e) {
            return (new Response())->status(500)->json(['error' => 'Storage error: ' . $e->getMessage()]);
        }

        $this->profiles->update($profileId, ['avatar_url' => $avatarPath]);

        $avatarUrl = $this->avatars->url($profileId, $avatarPath);

        return (new Response())->json(['avatar_url' => $avatarUrl]);
    }

    /**
     * Owner-or-admin gate, mirroring ProfileTagController: answers 404 for
     * "no such profile" AND "not yours" (a 403 would confirm existence).
     */
    private function denyUnlessProfileManageable(Request $request, string $profileId): ?Response
    {
        if ($this->accessPolicy->canManageProfile($request->userId ?? null, $profileId)) {
            return null;
        }

        return (new Response())->status(404)->json(['error' => 'Profile not found']);
    }

    /**
     * Throttle PIN-verify attempts against a single profile. Keyed per
     * profile: the endpoint is ownership-gated, so only the profile's owner
     * (or an admin) can burn its budget — per-IP keying would let a sprayed
     * attacker exhaust the OWNER's legitimate attempts.
     *
     * @throws RateLimitException when the budget is exhausted (central 429)
     */
    private function enforcePinVerifyLimit(string $profileId): void
    {
        $state = $this->pinVerifyLimiter->hit('profile-pin:' . $profileId);
        if ($state->limited) {
            throw new RateLimitException($state->resetAt, $state->remaining);
        }
    }

    /**
     * Parse a profile ID from a string. Profile IDs are CHAR(36) UUID strings.
     *
     * @param mixed $value The value to parse.
     *
     * @return string|null The parsed profile ID, or null if invalid.
     */
    private function parseProfileId(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }

    /**
     * Human-readable upload error message, mirroring UserAvatarController.
     *
     * @param int $errorCode The PHP upload error constant.
     */
    private function uploadErrorMessage(int $errorCode): string
    {
        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds size limit',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Server is missing a temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload',
            default => 'Unknown upload error',
        };
    }
}
