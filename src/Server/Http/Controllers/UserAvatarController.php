<?php

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Auth\UserRepository;
use Phlix\Media\Storage\AvatarStorage;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * Avatar upload/delete endpoints for the authenticated user (Step 12.3).
 *
 * Each handler requires an authenticated user ($request->userId, set by the
 * entry point from the Bearer token / session cookie before dispatch and
 * enforced by AuthMiddleware on the route group).
 *
 * Handlers are referenced from {@see \Phlix\Server\WebPortal\WebPortalRouter},
 * which both HTTP entry points (public/index.php and the Workerman daemon's
 * HttpHandler) dispatch `/api/*` to, so a single registration serves both.
 */
class UserAvatarController
{
    public function __construct(
        private AvatarStorage $avatarStorage,
        private UserRepository $userRepository,
    ) {
    }

    /**
     * Upload a new avatar for the authenticated user.
     *
     * POST /api/v1/users/me/avatar
     * Content-Type: multipart/form-data
     *
     * Expects $_FILES['avatar'] or $_FILES['file'] to contain the uploaded image.
     *
     * Flow:
     * 1. Extract userId from $request->userId
     * 2. Check $request->files for uploaded file (key 'avatar' or 'file')
     * 3. Validate file was uploaded successfully (error_check in $_FILES)
     * 4. Call $this->avatarStorage->store($userId, $tmpPath)
     * 5. Call $this->userRepository->updateAvatar($userId, $avatarPath)
     * 6. Return { avatar_url: $this->avatarStorage->url($userId, $avatarPath) }
     *
     * @param Request              $request The HTTP request (userId set from auth)
     * @param array<string,string> $params  Route parameters (unused)
     *
     * @return Response JSON response with avatar_url or error
     *
     * @api_endpoint POST /api/v1/users/me/avatar
     *
     * @requires Authentication
     */
    public function uploadAvatar(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';
        if ($userId === '') {
            return (new Response())->status(401)->json(['error' => 'Unauthorized']);
        }

        $files = $request->files;
        $file = $files['avatar'] ?? $files['file'] ?? null;

        if ($file === null) {
            return (new Response())->status(400)->json(['error' => 'No file uploaded']);
        }

        // Validate upload success - $_FILES[$field]['error'] === UPLOAD_ERR_OK
        $error = is_array($file) && isset($file['error']) && is_int($file['error'])
            ? $file['error']
            : UPLOAD_ERR_NO_FILE;
        if ($error !== UPLOAD_ERR_OK) {
            return (new Response())->status(400)->json(['error' => 'Upload error: ' . $this->uploadErrorMessage($error)]);
        }

        $tmpPath = is_array($file) && isset($file['tmp_name']) ? $file['tmp_name'] : '';
        if ($tmpPath === '' || !is_string($tmpPath)) {
            return (new Response())->status(400)->json(['error' => 'Invalid file data']);
        }

        try {
            $avatarPath = $this->avatarStorage->store($userId, $tmpPath);
        } catch (\InvalidArgumentException $e) {
            return (new Response())->status(400)->json(['error' => $e->getMessage()]);
        } catch (\RuntimeException $e) {
            return (new Response())->status(500)->json(['error' => 'Storage error: ' . $e->getMessage()]);
        }

        $this->userRepository->updateAvatar($userId, $avatarPath);

        $avatarUrl = $this->avatarStorage->url($userId, $avatarPath);

        return (new Response())->json(['avatar_url' => $avatarUrl]);
    }

    /**
     * Delete the avatar for the authenticated user.
     *
     * DELETE /api/v1/users/me/avatar
     *
     * Flow:
     * 1. Extract userId from $request->userId
     * 2. Call $this->avatarStorage->delete($userId)
     * 3. Call $this->userRepository->clearAvatar($userId)
     * 4. Return { message: 'Avatar removed' }
     *
     * @param Request              $request The HTTP request (userId set from auth)
     * @param array<string,string> $params  Route parameters (unused)
     *
     * @return Response JSON response with success message or error
     *
     * @api_endpoint DELETE /api/v1/users/me/avatar
     *
     * @requires Authentication
     */
    public function deleteAvatar(Request $request, array $params): Response
    {
        $userId = $request->userId ?? '';
        if ($userId === '') {
            return (new Response())->status(401)->json(['error' => 'Unauthorized']);
        }

        $this->avatarStorage->delete($userId);
        $this->userRepository->clearAvatar($userId);

        return (new Response())->json(['message' => 'Avatar removed']);
    }

    /**
     * Convert a PHP upload error constant to a human-readable message.
     *
     * @param int $error PHP UPLOAD_ERR_* constant
     *
     * @return string Human-readable error message
     */
    private function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE => 'File exceeds server upload limit',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds form upload limit',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'Upload blocked by extension',
            default => 'Unknown upload error',
        };
    }
}
