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
use Phlix\Access\StreamSessionService;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * API controller for managing per-profile stream limits and active streams.
 *
 * Provides endpoints to view and update stream limits for profiles,
 * and to list currently active streams.
 *
 * Endpoints (registered under BOTH prefixes — `/api/v1/admin/…` for the admin
 * SPA via {@see \Phlix\Server\Http\Routes\AdminRoutes}, plain `/api/v1/…` for
 * the shipped native clients):
 * - GET    /api/v1/[admin/]profiles/{profileId}/stream-limits   — get stream limits
 * - PUT    /api/v1/[admin/]profiles/{profileId}/stream-limits   — update stream limits
 * - GET    /api/v1/[admin/]profiles/{profileId}/active-streams  — list active streams
 *
 * ## Authorization (S208)
 *
 * Every handler runs {@see ProfileAccessPolicy::canManageProfile()} — owner of
 * the profile, or a server admin — and answers 404 (never 403) otherwise. There
 * is no by-id sub-resource here: the profile in the path IS the record, so the
 * profile-level check is the whole ownership check.
 *
 * @package Phlix\Server\Http\Controllers
 */
final class StreamLimitController
{
    /**
     * Create a new StreamLimitController instance.
     *
     * @param StreamSessionService $streamSessionService Service for stream operations.
     * @param ProfileAccessPolicy  $accessPolicy         Owner-or-admin gate (S208).
     *                                                   REQUIRED, never nullable: an
     *                                                   optional dependency here would
     *                                                   be skipped by PHP-DI autowiring
     *                                                   and silently fail open.
     */
    public function __construct(
        private readonly StreamSessionService $streamSessionService,
        private readonly ProfileAccessPolicy $accessPolicy,
    ) {
    }

    /**
     * Get the stream limits for a profile.
     *
     * @param Request               $request The HTTP request (unused body).
     * @param array<string, string> $params  Path parameters:
     *                                       - profileId: The profile ID.
     *
     * @return Response 200 { stream_limits: array } | 400 { error }
     */
    public function getStreamLimits(Request $request, array $params): Response
    {
        $profileId = $this->parseProfileId($params['profileId'] ?? null);
        if ($profileId === null) {
            return (new Response())->status(400)->json(['error' => 'Invalid profile ID']);
        }

        $denied = $this->denyUnlessProfileManageable($request, $profileId);
        if ($denied !== null) {
            return $denied;
        }

        $limits = $this->streamSessionService->getStreamLimit($profileId);

        return (new Response())->json([
            'stream_limits' => $limits->toArray(),
        ]);
    }

    /**
     * Update the stream limits for a profile.
     *
     * @param Request               $request The HTTP request with body:
     *                                       - max_concurrent_streams: int (required)
     *                                       - max_total_bandwidth_kbps: int|null (optional)
     * @param array<string, string> $params  Path parameters:
     *                                       - profileId: The profile ID.
     *
     * @return Response 200 { stream_limits: array, message: string } | 400 { error }
     */
    public function updateStreamLimits(Request $request, array $params): Response
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

        // Validate max_concurrent_streams
        $maxConcurrentStreams = $this->parsePositiveInt($data['max_concurrent_streams'] ?? null);
        if ($maxConcurrentStreams === null || $maxConcurrentStreams < 1) {
            return (new Response())->status(400)->json([
                'error' => 'Invalid max_concurrent_streams: must be a positive integer',
            ]);
        }

        // max_total_bandwidth_kbps is optional
        $maxTotalBandwidthKbps = null;
        if (isset($data['max_total_bandwidth_kbps'])) {
            $maxTotalBandwidthKbps = $this->parsePositiveInt($data['max_total_bandwidth_kbps']);
            if ($maxTotalBandwidthKbps === null || $maxTotalBandwidthKbps < 1) {
                return (new Response())->status(400)->json([
                    'error' => 'Invalid max_total_bandwidth_kbps: must be a positive integer or null',
                ]);
            }
        }

        $this->streamSessionService->updateStreamLimit($profileId, $maxConcurrentStreams, $maxTotalBandwidthKbps);

        $updatedLimits = $this->streamSessionService->getStreamLimit($profileId);

        return (new Response())->json([
            'stream_limits' => $updatedLimits->toArray(),
            'message' => 'Stream limits updated successfully',
        ]);
    }

    /**
     * List all active streams for a profile.
     *
     * @param Request               $request The HTTP request (unused body).
     * @param array<string, string> $params  Path parameters:
     *                                       - profileId: The profile ID.
     *
     * @return Response 200 { active_streams: array, count: int } | 400 { error }
     */
    public function getActiveStreams(Request $request, array $params): Response
    {
        $profileId = $this->parseProfileId($params['profileId'] ?? null);
        if ($profileId === null) {
            return (new Response())->status(400)->json(['error' => 'Invalid profile ID']);
        }

        $denied = $this->denyUnlessProfileManageable($request, $profileId);
        if ($denied !== null) {
            return $denied;
        }

        $streams = $this->streamSessionService->getActiveStreamsForProfile($profileId);

        return (new Response())->json([
            'active_streams' => $streams,
            'count' => count($streams),
        ]);
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
     * @param mixed $value The value to parse.
     *
     * @return string|null The parsed profile ID, or null if invalid.
     */
    private function parseProfileId(mixed $value): ?string
    {
        $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
        if (is_string($value) && preg_match($uuidPattern, $value)) {
            return $value;
        }

        return null;
    }

    /**
     * Parse a positive integer from a value.
     *
     * @param mixed $value The value to parse.
     *
     * @return int|null The parsed positive integer, or null if invalid.
     */
    private function parsePositiveInt(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            $int = (int) $value;
            if ($int > 0) {
                return $int;
            }
        }

        if (is_numeric($value)) {
            $int = (int) $value;
            if ($int > 0) {
                return $int;
            }
        }

        return null;
    }
}
