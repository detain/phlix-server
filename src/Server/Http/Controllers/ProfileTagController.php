<?php

/**
 * Phlix media server component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Access\ProfileAccessPolicy;
use Phlix\Access\ProfileTag;
use Phlix\Access\ProfileTagService;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * API controller for managing profile tags.
 *
 * Provides CRUD operations for profile tags that define content filtering
 * restrictions (blocked or allowed tags).
 *
 * Endpoints (registered under BOTH prefixes — `/api/v1/admin/…` for the admin
 * SPA via {@see \Phlix\Server\Http\Routes\AdminRoutes}, plain `/api/v1/…` for
 * the shipped native clients):
 * - GET    /api/v1/[admin/]profiles/{profileId}/tags          — list all tags for a profile
 * - POST   /api/v1/[admin/]profiles/{profileId}/tags          — add a tag
 * - DELETE /api/v1/[admin/]profiles/{profileId}/tags/{tagId}  — remove a tag
 *
 * ## Authorization (S208)
 *
 * Every handler runs {@see ProfileAccessPolicy::canManageProfile()} — owner of
 * the profile, or a server admin — and `deleteTag()` additionally checks the tag
 * it fetched belongs to the `{profileId}` in the path. Both refusals are 404,
 * never 403.
 *
 * @package Phlix\Server\Http\Controllers
 */
final class ProfileTagController
{
    /**
     * Create a new ProfileTagController instance.
     *
     * @param ProfileTagService   $profileTagService Service for tag operations.
     * @param ProfileAccessPolicy $accessPolicy      Owner-or-admin gate (S208).
     *                                               REQUIRED, never nullable: an
     *                                               optional dependency here would
     *                                               be skipped by PHP-DI autowiring
     *                                               and silently fail open.
     */
    public function __construct(
        private readonly ProfileTagService $profileTagService,
        private readonly ProfileAccessPolicy $accessPolicy,
    ) {
    }

    /**
     * List all tags for a profile.
     *
     * @param Request               $request The HTTP request (unused body).
     * @param array<string, string> $params  Path parameters:
     *                                       - profileId: The profile ID.
     *
     * @return Response 200 { tags: array } | 400 { error }
     */
    public function listForProfile(Request $request, array $params): Response
    {
        $profileId = $this->parseProfileId($params['profileId'] ?? null);
        if ($profileId === null) {
            return (new Response())->status(400)->json(['error' => 'Invalid profile ID']);
        }

        $denied = $this->denyUnlessProfileManageable($request, $profileId);
        if ($denied !== null) {
            return $denied;
        }

        $tags = $this->profileTagService->getTagsForProfile($profileId);

        return (new Response())->json([
            'tags' => array_map(fn($t) => $t->toArray(), $tags),
        ]);
    }

    /**
     * Add a tag to a profile.
     *
     * @param Request               $request The HTTP request with body:
     *                                       - tag: string (required)
     *                                       - type: string (required, 'blocked' or 'allowed')
     * @param array<string, string> $params  Path parameters:
     *                                       - profileId: The profile ID.
     *
     * @return Response 201 { tag: array, message: string } | 400 { error }
     */
    public function createForProfile(Request $request, array $params): Response
    {
        $profileId = $this->parseProfileId($params['profileId'] ?? null);
        if ($profileId === null) {
            return (new Response())->status(400)->json(['error' => 'Invalid profile ID']);
        }

        $denied = $this->denyUnlessProfileManageable($request, $profileId);
        if ($denied !== null) {
            return $denied;
        }

        $data = $request->body;

        // Validate required fields
        $tag = is_string($data['tag'] ?? null) && $data['tag'] !== '' ? $data['tag'] : null;
        $type = $this->validateTagType($data['type'] ?? null);

        if ($tag === null || $type === null) {
            return (new Response())->status(400)->json([
                'error' => 'Missing or invalid required fields: tag, type',
            ]);
        }

        // Validate tag length
        if (strlen($tag) > 100) {
            return (new Response())->status(400)->json([
                'error' => 'Tag must be 100 characters or less',
            ]);
        }

        $tagId = $this->profileTagService->setTag($profileId, $tag, $type);

        return (new Response())->status(201)->json([
            'tag_id' => $tagId,
            'message' => 'Tag added successfully',
        ]);
    }

    /**
     * Remove a tag from a profile.
     *
     * @param Request               $request The HTTP request (unused).
     * @param array<string, string> $params  Path parameters:
     *                                       - profileId: The profile ID.
     *                                       - tagId: The tag ID to remove.
     *
     * @return Response 200 { message: string } | 400 { error } | 404 { error }
     */
    public function deleteTag(Request $request, array $params): Response
    {
        $profileId = $this->parseProfileId($params['profileId'] ?? null);
        if ($profileId === null) {
            return (new Response())->status(400)->json(['error' => 'Invalid profile ID']);
        }

        $tagId = $this->parseTagId($params['tagId'] ?? null);
        if ($tagId === null) {
            return (new Response())->status(400)->json(['error' => 'Invalid tag ID']);
        }

        $denied = $this->denyUnlessProfileManageable($request, $profileId);
        if ($denied !== null) {
            return $denied;
        }

        // Check the tag exists AND belongs to the profile in the path (S208).
        // `{profileId}` was declared by this route and read by nothing, so a tag
        // id — a sequential integer — was enough to delete any profile's tag.
        // The compare is case-insensitive for the same CHAR(36) `_ci` collation
        // reason documented on AccessScheduleController::scheduleOwnedByProfile().
        $existing = $this->profileTagService->getTagById($tagId);
        if ($existing === null || strcasecmp($existing->profileId, $profileId) !== 0) {
            return (new Response())->status(404)->json(['error' => 'Tag not found']);
        }

        $this->profileTagService->deleteTag($tagId);

        return (new Response())->json(['message' => 'Tag removed successfully']);
    }

    /**
     * Refuse the request unless the caller owns `$profileId` or is an admin (S208).
     *
     * @param Request $request   The incoming request (supplies `userId`).
     * @param string  $profileId Profile id taken from the path.
     *
     * @return Response|null 404 to short-circuit, or null to continue.
     */
    private function denyUnlessProfileManageable(Request $request, string $profileId): ?Response
    {
        if ($this->accessPolicy->canManageProfile($request->userId, $profileId)) {
            return null;
        }

        // 404 rather than 403: a 403 would confirm the profile exists to a
        // caller who is not entitled to know that.
        return (new Response())->status(404)->json(['error' => 'Profile not found']);
    }

    /**
     * Parse a profile ID from a string.
     *
     * Profile IDs are CHAR(36) UUID strings.
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
     * Parse a tag ID from a string.
     *
     * @param mixed $value The value to parse.
     *
     * @return int|null The parsed tag ID, or null if invalid.
     */
    private function parseTagId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Validate a tag type string.
     *
     * @param mixed $value The value to validate.
     *
     * @return string|null The validated type ('blocked' or 'allowed'), or null if invalid.
     */
    private function validateTagType(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        if ($value === ProfileTag::TYPE_BLOCKED || $value === ProfileTag::TYPE_ALLOWED) {
            return $value;
        }

        return null;
    }
}
