<?php

/**
 * Phlix media server component: Access.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Access;

use DateTimeInterface;

/**
 * Represents a time-based access control schedule for a profile.
 *
 * A schedule defines a recurring time window during which access is allowed.
 * It can be restricted to specific days of the week and time ranges.
 *
 * Example: A schedule named "Weekend Only" could allow access only on
 * Saturday and Sunday from 10:00 to 22:00.
 *
 * @package Phlix\Access
 */
final class AccessSchedule
{
    /**
     * Create a new AccessSchedule instance.
     *
     * @param int           $id         Unique identifier for the schedule.
     * @param int           $profileId  The profile this schedule belongs to.
     * @param string        $name       Human-readable name for the schedule.
     * @param string        $startTime  Start time in HH:MM:SS format.
     * @param string        $endTime    End time in HH:MM:SS format.
     * @param array<string> $daysOfWeek Array of day abbreviations ('mon', 'tue', etc.).
     * @param bool          $isActive   Whether the schedule is currently active.
     */
    public function __construct(
        public readonly int $id,
        public readonly int $profileId,
        public readonly string $name,
        public readonly string $startTime,
        public readonly string $endTime,
        public readonly array $daysOfWeek,
        public readonly bool $isActive,
    ) {
    }

    /**
     * Create an AccessSchedule from a database row.
     *
     * @param array<string, mixed> $row Raw database row with keys:
     *                                  id, profile_id, name, start_time, end_time,
     *                                  days_of_week, is_active.
     *
     * @return self
     */
    public static function fromRow(array $row): self
    {
        $id = isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : 0;
        $profileId = isset($row['profile_id']) && is_numeric($row['profile_id']) ? (int) $row['profile_id'] : 0;
        $name = isset($row['name']) && is_string($row['name']) ? $row['name'] : '';
        $startTime = isset($row['start_time']) && is_string($row['start_time']) ? $row['start_time'] : '00:00:00';
        $endTime = isset($row['end_time']) && is_string($row['end_time']) ? $row['end_time'] : '23:59:59';
        $isActive = isset($row['is_active']) ? (bool) $row['is_active'] : true;

        $daysOfWeekStr = isset($row['days_of_week']) && is_string($row['days_of_week']) ? $row['days_of_week'] : '';
        $daysOfWeek = $daysOfWeekStr !== '' ? explode(',', $daysOfWeekStr) : [];

        return new self(
            id: $id,
            profileId: $profileId,
            name: $name,
            startTime: $startTime,
            endTime: $endTime,
            daysOfWeek: $daysOfWeek,
            isActive: $isActive,
        );
    }

    /**
     * Check if this schedule is currently active at the given time.
     *
     * Returns true only if ALL of the following conditions are met:
     * - The schedule is marked as active
     * - The current day of week is in the schedule's days_of_week
     * - The current time is within the start_time and end_time range
     *
     * @param DateTimeInterface $now The time to check against. Defaults to current time.
     *
     * @return bool True if access should be allowed at the given time.
     */
    public function isActiveAt(DateTimeInterface $now): bool
    {
        if (!$this->isActive) {
            return false;
        }

        // Check day of week
        $dayAbbrev = strtolower(substr($now->format('D'), 0, 3));
        if (!in_array($dayAbbrev, $this->daysOfWeek, true)) {
            return false;
        }

        // Check time range
        $currentMinutes = self::timeToMinutes($now->format('H:i:s'));
        $startMinutes = self::timeToMinutes($this->startTime);
        $endMinutes = self::timeToMinutes($this->endTime);

        // Handle overnight schedules (e.g., 22:00 to 06:00)
        if ($startMinutes <= $endMinutes) {
            return $currentMinutes >= $startMinutes && $currentMinutes <= $endMinutes;
        }

        // Overnight: allowed if current time is after start OR before end
        return $currentMinutes >= $startMinutes || $currentMinutes <= $endMinutes;
    }

    /**
     * Convert a time string (HH:MM:SS) to minutes since midnight.
     *
     * @param string $time Time in HH:MM:SS format.
     *
     * @return int Minutes since midnight (0-1440).
     */
    private static function timeToMinutes(string $time): int
    {
        $parts = explode(':', $time);
        $hours = (int) ($parts[0] ?? 0);
        $minutes = (int) ($parts[1] ?? 0);
        $seconds = (int) ($parts[2] ?? 0);

        // intdiv, not `/ 60`: this file declares strict_types=1, so any time whose
        // seconds component is not a multiple of 60 (e.g. "00:10:30") made `/`
        // yield a float and the `: int` return type raise a TypeError at runtime.
        // Truncating to whole minutes is what the declared 0-1440 range means.
        return intdiv($hours * 3600 + $minutes * 60 + $seconds, 60);
    }

    /**
     * Convert the schedule to an array representation.
     *
     * @return array{
     *     id: int,
     *     profile_id: int,
     *     name: string,
     *     start_time: string,
     *     end_time: string,
     *     days_of_week: array<string>,
     *     is_active: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'profile_id' => $this->profileId,
            'name' => $this->name,
            'start_time' => $this->startTime,
            'end_time' => $this->endTime,
            'days_of_week' => $this->daysOfWeek,
            'is_active' => $this->isActive,
        ];
    }
}
