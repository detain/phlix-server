<?php

/**
 * Phlix media server component: Access.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Access;

use DateTime;
use DateTimeInterface;
use Workerman\MySQL\Connection;

/**
 * Service for managing and querying access schedules.
 *
 * Provides methods to retrieve access schedules for profiles and check
 * if access is currently allowed based on scheduled time windows.
 *
 * @package Phlix\Access
 */
final class AccessScheduleService
{
    /**
     * Create a new AccessScheduleService instance.
     *
     * @param Connection $db Database connection for accessing schedule data.
     */
    public function __construct(
        private readonly Connection $db,
    ) {
    }

    /**
     * Get the currently active schedule for a profile.
     *
     * Returns the first active schedule that matches the current day and time,
     * or null if no schedule is currently active.
     *
     * @param string $profileId The profile ID (UUID) to check.
     *
     * @return AccessSchedule|null The active schedule, or null if none is active.
     */
    public function getActiveScheduleForProfile(string $profileId): ?AccessSchedule
    {
        $now = new DateTime();

        // Get all active schedules for this profile
        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT * FROM access_schedules WHERE profile_id = ? AND is_active = TRUE',
            [$profileId],
        );

        if (!is_array($rows) || $rows === []) {
            return null;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $schedule = AccessSchedule::fromRow($row);
            if ($schedule->isActiveAt($now)) {
                return $schedule;
            }
        }

        return null;
    }

    /**
     * Check if access is allowed for a profile at the current time.
     *
     * Access is ALLOWED when:
     * - The profile has no schedules defined (no restrictions)
     * - No active schedule matches the current time
     *
     * Access is DENIED when:
     * - An active schedule exists that matches the current day and time
     *
     * @param string $profileId The profile ID (UUID) to check.
     *
     * @return bool True if access is allowed, false if denied.
     */
    public function isAccessAllowed(string $profileId): bool
    {
        return $this->getActiveScheduleForProfile($profileId) === null;
    }

    /**
     * Get all schedules for a profile.
     *
     * @param string $profileId The profile ID (UUID) to get schedules for.
     *
     * @return list<AccessSchedule> List of all schedules for the profile.
     */
    public function getSchedulesForProfile(string $profileId): array
    {
        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT * FROM access_schedules WHERE profile_id = ? ORDER BY created_at DESC',
            [$profileId],
        );

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $schedules = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $schedules[] = AccessSchedule::fromRow($row);
            }
        }

        return $schedules;
    }

    /**
     * Get a single schedule by ID.
     *
     * @param int $scheduleId The schedule ID to retrieve.
     *
     * @return AccessSchedule|null The schedule, or null if not found.
     */
    public function getScheduleById(int $scheduleId): ?AccessSchedule
    {
        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query(
            'SELECT * FROM access_schedules WHERE id = ?',
            [$scheduleId],
        );

        if (!is_array($rows) || $rows === [] || !is_array($rows[0])) {
            return null;
        }

        /** @var array<string, mixed> $firstRow */
        $firstRow = $rows[0];
        return AccessSchedule::fromRow($firstRow);
    }

    /**
     * Create a new access schedule.
     *
     * @param string   $profileId  The profile ID (UUID) this schedule belongs to.
     * @param string   $name       Human-readable name for the schedule.
     * @param string   $startTime  Start time in HH:MM:SS format.
     * @param string   $endTime    End time in HH:MM:SS format.
     * @param array<string> $daysOfWeek Array of day abbreviations.
     * @param bool     $isActive   Whether the schedule is active.
     *
     * @return int The ID of the newly created schedule.
     */
    public function createSchedule(
        string $profileId,
        string $name,
        string $startTime,
        string $endTime,
        array $daysOfWeek,
        bool $isActive = true,
    ): int {
        $daysOfWeekStr = implode(',', $daysOfWeek);

        $this->db->query(
            'INSERT INTO access_schedules (profile_id, name, start_time, end_time, days_of_week, is_active)'
            . ' VALUES (?, ?, ?, ?, ?, ?)',
            [$profileId, $name, $startTime, $endTime, $daysOfWeekStr, $isActive],
        );

        /** @var int */
        return (int) $this->db->lastInsertId();
    }

    /**
     * Update an existing access schedule.
     *
     * @param int      $scheduleId The schedule ID to update.
     * @param array<string, mixed> $data Fields to update.
     *
     * @return bool True if the schedule was updated.
     */
    public function updateSchedule(int $scheduleId, array $data): bool
    {
        $sets = [];
        $values = [];

        if (isset($data['name'])) {
            $sets[] = 'name = ?';
            $values[] = $data['name'];
        }

        if (isset($data['start_time'])) {
            $sets[] = 'start_time = ?';
            $values[] = $data['start_time'];
        }

        if (isset($data['end_time'])) {
            $sets[] = 'end_time = ?';
            $values[] = $data['end_time'];
        }

        if (isset($data['days_of_week'])) {
            $sets[] = 'days_of_week = ?';
            /** @var mixed $rawDays */
            $rawDays = $data['days_of_week'];
            if (is_array($rawDays)) {
                /** @var array<string> $rawDaysArr */
                $rawDaysArr = $rawDays;
                $values[] = implode(',', $rawDaysArr);
            } elseif (is_string($rawDays)) {
                $values[] = $rawDays;
            } else {
                $values[] = '';
            }
        }

        if (isset($data['is_active'])) {
            $sets[] = 'is_active = ?';
            $values[] = (bool) $data['is_active'];
        }

        if (empty($sets)) {
            return false;
        }

        $values[] = $scheduleId;

        $result = $this->db->query(
            'UPDATE access_schedules SET ' . implode(', ', $sets) . ' WHERE id = ?',
            $values,
        );

        return $result !== false;
    }

    /**
     * Delete an access schedule.
     *
     * @param int $scheduleId The schedule ID to delete.
     *
     * @return bool True if the schedule was deleted.
     */
    public function deleteSchedule(int $scheduleId): bool
    {
        $result = $this->db->query(
            'DELETE FROM access_schedules WHERE id = ?',
            [$scheduleId],
        );

        return $result !== false;
    }
}
