<?php

/**
 * Phlix media server component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Access\AccessScheduleService;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * API controller for managing access schedules.
 *
 * Provides CRUD operations for profile access schedules that define
 * time-based access control windows.
 *
 * Endpoints:
 * - GET    /api/v1/profiles/{profileId}/schedules      — list all schedules for a profile
 * - POST   /api/v1/profiles/{profileId}/schedules      — create a new schedule
 * - GET    /api/v1/profiles/{profileId}/schedules/{id}  — get a specific schedule
 * - PUT    /api/v1/profiles/{profileId}/schedules/{id}  — update a schedule
 * - DELETE /api/v1/profiles/{profileId}/schedules/{id}  — delete a schedule
 *
 * @package Phlix\Server\Http\Controllers
 */
final class AccessScheduleController
{
    /**
     * Valid days of week abbreviations.
     *
     * @var array<string>
     */
    private const VALID_DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /**
     * Create a new AccessScheduleController instance.
     *
     * @param AccessScheduleService $accessScheduleService Service for schedule operations.
     */
    public function __construct(
        private readonly AccessScheduleService $accessScheduleService,
    ) {
    }

    /**
     * List all schedules for a profile.
     *
     * @param Request               $request The HTTP request (unused body).
     * @param array<string, string> $params  Path parameters:
     *                                       - profileId: The profile ID.
     *
     * @return Response 200 { schedules: array } | 400 { error }
     */
    public function listForProfile(Request $request, array $params): Response
    {
        $profileId = $this->parseProfileId($params['profileId'] ?? null);
        if ($profileId === null) {
            return (new Response())->status(400)->json(['error' => 'Invalid profile ID']);
        }

        $schedules = $this->accessScheduleService->getSchedulesForProfile($profileId);

        return (new Response())->json([
            'schedules' => array_map(fn($s) => $s->toArray(), $schedules),
        ]);
    }

    /**
     * Create a new schedule for a profile.
     *
     * @param Request               $request The HTTP request with body:
     *                                       - name: string (required)
     *                                       - start_time: string (required, HH:MM:SS)
     *                                       - end_time: string (required, HH:MM:SS)
     *                                       - days_of_week: array<string> (required)
     *                                       - is_active: bool (optional, default true)
     * @param array<string, string> $params  Path parameters:
     *                                       - profileId: The profile ID.
     *
     * @return Response 201 { schedule: array, message: string } | 400 { error }
     */
    public function createForProfile(Request $request, array $params): Response
    {
        $profileId = $this->parseProfileId($params['profileId'] ?? null);
        if ($profileId === null) {
            return (new Response())->status(400)->json(['error' => 'Invalid profile ID']);
        }

        $data = $request->body;

        // Validate required fields
        $name = is_string($data['name'] ?? null) && $data['name'] !== '' ? $data['name'] : null;
        $startTime = $this->validateTime($data['start_time'] ?? null);
        $endTime = $this->validateTime($data['end_time'] ?? null);
        $daysOfWeek = $this->validateDaysOfWeek($data['days_of_week'] ?? null);

        if ($name === null || $startTime === null || $endTime === null || $daysOfWeek === null) {
            return (new Response())->status(400)->json([
                'error' => 'Missing or invalid required fields: name, start_time, end_time, days_of_week',
            ]);
        }

        $isActive = (bool) ($data['is_active'] ?? true);

        $scheduleId = $this->accessScheduleService->createSchedule(
            $profileId,
            $name,
            $startTime,
            $endTime,
            $daysOfWeek,
            $isActive,
        );

        return (new Response())->status(201)->json([
            'schedule_id' => $scheduleId,
            'message' => 'Schedule created successfully',
        ]);
    }

    /**
     * Get a specific schedule.
     *
     * @param Request               $request The HTTP request (unused).
     * @param array<string, string> $params  Path parameters:
     *                                       - profileId: The profile ID.
     *                                       - scheduleId: The schedule ID.
     *
     * @return Response 200 { schedule: array } | 404 { error }
     */
    public function getSchedule(Request $request, array $params): Response
    {
        $scheduleId = $this->parseScheduleId($params['scheduleId'] ?? null);
        if ($scheduleId === null) {
            return (new Response())->status(400)->json(['error' => 'Invalid schedule ID']);
        }

        $schedule = $this->accessScheduleService->getScheduleById($scheduleId);
        if ($schedule === null) {
            return (new Response())->status(404)->json(['error' => 'Schedule not found']);
        }

        return (new Response())->json(['schedule' => $schedule->toArray()]);
    }

    /**
     * Update an existing schedule.
     *
     * @param Request               $request The HTTP request with body (all optional):
     *                                       - name: string
     *                                       - start_time: string (HH:MM:SS)
     *                                       - end_time: string (HH:MM:SS)
     *                                       - days_of_week: array<string>
     *                                       - is_active: bool
     * @param array<string, string> $params  Path parameters:
     *                                       - profileId: The profile ID.
     *                                       - scheduleId: The schedule ID.
     *
     * @return Response 200 { schedule: array, message: string } | 400 { error } | 404 { error }
     */
    public function updateSchedule(Request $request, array $params): Response
    {
        $scheduleId = $this->parseScheduleId($params['scheduleId'] ?? null);
        if ($scheduleId === null) {
            return (new Response())->status(400)->json(['error' => 'Invalid schedule ID']);
        }

        // Check schedule exists
        $existing = $this->accessScheduleService->getScheduleById($scheduleId);
        if ($existing === null) {
            return (new Response())->status(404)->json(['error' => 'Schedule not found']);
        }

        $data = $request->body;
        $updateData = [];

        if (isset($data['name']) && is_string($data['name']) && $data['name'] !== '') {
            $updateData['name'] = $data['name'];
        }

        if (isset($data['start_time'])) {
            $startTime = $this->validateTime($data['start_time']);
            if ($startTime !== null) {
                $updateData['start_time'] = $startTime;
            }
        }

        if (isset($data['end_time'])) {
            $endTime = $this->validateTime($data['end_time']);
            if ($endTime !== null) {
                $updateData['end_time'] = $endTime;
            }
        }

        if (isset($data['days_of_week'])) {
            $daysOfWeek = $this->validateDaysOfWeek($data['days_of_week']);
            if ($daysOfWeek !== null) {
                $updateData['days_of_week'] = $daysOfWeek;
            }
        }

        if (isset($data['is_active'])) {
            $updateData['is_active'] = (bool) $data['is_active'];
        }

        if (empty($updateData)) {
            return (new Response())->status(400)->json(['error' => 'No valid fields to update']);
        }

        $this->accessScheduleService->updateSchedule($scheduleId, $updateData);

        // Fetch updated schedule
        $updated = $this->accessScheduleService->getScheduleById($scheduleId);

        return (new Response())->json([
            'schedule' => $updated?->toArray() ?? [],
            'message' => 'Schedule updated successfully',
        ]);
    }

    /**
     * Delete a schedule.
     *
     * @param Request               $request The HTTP request (unused).
     * @param array<string, string> $params  Path parameters:
     *                                       - profileId: The profile ID.
     *                                       - scheduleId: The schedule ID.
     *
     * @return Response 200 { message: string } | 404 { error }
     */
    public function deleteSchedule(Request $request, array $params): Response
    {
        $scheduleId = $this->parseScheduleId($params['scheduleId'] ?? null);
        if ($scheduleId === null) {
            return (new Response())->status(400)->json(['error' => 'Invalid schedule ID']);
        }

        // Check schedule exists
        $existing = $this->accessScheduleService->getScheduleById($scheduleId);
        if ($existing === null) {
            return (new Response())->status(404)->json(['error' => 'Schedule not found']);
        }

        $this->accessScheduleService->deleteSchedule($scheduleId);

        return (new Response())->json(['message' => 'Schedule deleted successfully']);
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
     * Parse a schedule ID from a string.
     *
     * @param mixed $value The value to parse.
     *
     * @return int|null The parsed schedule ID, or null if invalid.
     */
    private function parseScheduleId(mixed $value): ?int
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
     * Validate a time string in HH:MM:SS format.
     *
     * @param mixed $value The time value to validate.
     *
     * @return string|null The validated time string, or null if invalid.
     */
    private function validateTime(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        // Support both HH:MM:SS and HH:MM formats
        if (!preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $value, $matches)) {
            return null;
        }

        $hours = (int) $matches[1];
        $minutes = (int) $matches[2];
        $seconds = isset($matches[3]) ? (int) $matches[3] : 0;

        if ($hours > 23 || $minutes > 59 || $seconds > 59) {
            return null;
        }

        // Normalize to HH:MM:SS format
        return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
    }

    /**
     * Validate days of week array.
     *
     * @param mixed $value The value to validate.
     *
     * @return array<string>|null Validated array of day abbreviations, or null if invalid.
     */
    private function validateDaysOfWeek(mixed $value): ?array
    {
        if (!is_array($value) || $value === []) {
            return null;
        }

        $validDays = [];
        foreach ($value as $day) {
            if (!is_string($day)) {
                return null;
            }

            $dayLower = strtolower(trim($day));
            if (!in_array($dayLower, self::VALID_DAYS, true)) {
                return null;
            }

            $validDays[] = $dayLower;
        }

        if (count($validDays) === 0) {
            return null;
        }

        return $validDays;
    }
}
