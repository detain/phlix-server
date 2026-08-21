<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Access;

use DateTimeImmutable;
use Phlix\Access\AccessSchedule;
use Phlix\Access\AccessScheduleService;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Regression tests for the timeToMinutes() intdiv fix (S146 / commit 06fff05d,
 * pinned by S336).
 *
 * Before the fix, timeToMinutes() ended with `(...) / 60`. This file declares
 * strict_types=1, so any time whose seconds component is not a multiple of 60
 * (e.g. "00:10:30", or the fromRow() default end_time "23:59:59") made `/`
 * yield a float and the `: int` return type raised a TypeError at runtime.
 * That crashed isActiveAt() -> getActiveScheduleForProfile() -> isAccessAllowed()
 * for 59 of every 60 seconds.
 *
 * These tests exercise the REAL classes (never a mirror): the full service call
 * graph enters AccessScheduleService::getActiveScheduleForProfile() line 69, and
 * the isActiveAt() cases pin every second of the clock and the boundary
 * semantics so the intdiv fix cannot regress unnoticed.
 *
 * Red-on-revert vs boundary-pinning: every test where timeToMinutes() receives
 * a time with a non-`:00` seconds component (as a probe OR as a schedule bound)
 * goes RED on the pre-fix token `/ 60`, because the division yields a float and
 * the `: int` return type raises TypeError under strict_types. That set is
 * Test A (end bound "23:59:59"), Test B (seconds 01..59), the two one-second
 * tests (probe "09:59:59" in testOneSecondBeforeStartIsDenied, and end bound
 * "11:59:59" in testOneSecondAfterEndIsDenied), the midnight default
 * ("23:59:59"), the "00:10:30" case, and the overnight test (its "05:59:59"
 * probe). The remaining tests — the two exact-bound probes (all `:00` seconds),
 * testServiceAllowsAccessWhenTheProfileHasNoSchedules (never reaches
 * timeToMinutes), and testInactiveScheduleNeverAllowsAccess (exits at the
 * isActive gate) — stay green pre-fix by design: they pin boundary SEMANTICS,
 * not the intdiv token.
 */
class AccessScheduleTest extends TestCase
{
    /**
     * Build a fixed instant on 2026-08-20 (a Thursday) so the day-of-week gate
     * always passes; the schedules below include all 7 days regardless.
     */
    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-20 ' . $time);
    }

    /**
     * Hydrate a schedule through the real fromRow() path.
     */
    private function schedule(string $startTime, string $endTime, bool $isActive = true): AccessSchedule
    {
        return AccessSchedule::fromRow([
            'id' => 1,
            'profile_id' => 'a1b2c3d4-0000-4000-8000-000000000001',
            'name' => 'TestSchedule',
            'start_time' => $startTime,
            'end_time' => $endTime,
            'days_of_week' => 'mon,tue,wed,thu,fri,sat,sun',
            'is_active' => $isActive,
        ]);
    }

    /**
     * Test A — full call graph through AccessScheduleService (line 69).
     *
     * The row is deliberately deterministic against the REAL current wall clock
     * (getActiveScheduleForProfile uses `new DateTime()`): all 7 days pass the
     * day gate, and 00:00:00-23:59:59 always contains the current time. Post-fix
     * the schedule is ALWAYS active; pre-fix the end_time "23:59:59" ALWAYS
     * threw TypeError. An active matching schedule DENIES access.
     */
    public function testServiceReturnsTheAllDayActiveScheduleForTheProfile(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([
            [
                'id' => 1,
                'profile_id' => 'a1b2c3d4-0000-4000-8000-000000000001',
                'name' => 'AllDay',
                'start_time' => '00:00:00',
                'end_time' => '23:59:59',
                'days_of_week' => 'mon,tue,wed,thu,fri,sat,sun',
                'is_active' => true,
            ],
        ]);

        $service = new AccessScheduleService($db);

        $schedule = $service->getActiveScheduleForProfile('profile-id');
        $this->assertInstanceOf(AccessSchedule::class, $schedule);
        $this->assertSame(1, $schedule->id);
        $this->assertFalse($service->isAccessAllowed('profile-id'));
    }

    /**
     * Test A — a profile with no schedule rows has no restrictions: access is
     * allowed.
     */
    public function testServiceAllowsAccessWhenTheProfileHasNoSchedules(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $service = new AccessScheduleService($db);

        $this->assertNull($service->getActiveScheduleForProfile('profile-id'));
        $this->assertTrue($service->isAccessAllowed('profile-id'));
    }

    /**
     * Test B — no TypeError at ANY second of the clock.
     *
     * The 10:30:00-10:30:59 window contains all 60 seconds. Pre-fix,
     * timeToMinutes() threw TypeError for seconds 01..59 because the seconds
     * component is not a multiple of 60.
     */
    public function testNoTypeErrorAtAnySecondOfTheWindow(): void
    {
        $schedule = $this->schedule('10:30:00', '10:30:59');

        for ($second = 0; $second < 60; $second++) {
            $time = sprintf('10:30:%02d', $second);
            $this->assertTrue(
                $schedule->isActiveAt($this->at($time)),
                sprintf('second %02d of the window must be active', $second),
            );
        }
    }

    /**
     * Test C1 — the start bound of a same-day window is INCLUSIVE
     * (`>= $startMinutes`, AccessSchedule.php:120). A mutation to strict `>`
     * flips this probe. Uses `:00` seconds deliberately: this test pins the
     * boundary SEMANTICS, not the intdiv token, so it stays green pre-fix.
     */
    public function testExactStartSecondIsAllowed(): void
    {
        $schedule = $this->schedule('10:00:00', '12:00:00');

        $this->assertTrue($schedule->isActiveAt($this->at('10:00:00')));
    }

    /**
     * Test C2 — the end bound of a same-day window is INCLUSIVE
     * (`<= $endMinutes`, AccessSchedule.php:120). A mutation to strict `<`
     * flips this probe. Uses `:00` seconds deliberately: this test pins the
     * boundary SEMANTICS, not the intdiv token, so it stays green pre-fix.
     */
    public function testExactEndSecondIsAllowed(): void
    {
        $schedule = $this->schedule('10:00:00', '12:00:00');

        $this->assertTrue($schedule->isActiveAt($this->at('12:00:00')));
    }

    /**
     * Test C3 — one second before the start bound is DENIED (the start
     * comparison is `>=`, so 09:59:59 truncates to minute 599 < 600).
     */
    public function testOneSecondBeforeStartIsDenied(): void
    {
        $schedule = $this->schedule('10:00:00', '12:00:00');

        $this->assertFalse($schedule->isActiveAt($this->at('09:59:59')));
    }

    /**
     * Test C4 — one second after end denied.
     *
     * NOTE on the exact probe: seconds truncate to whole minutes, so the ENTIRE
     * end minute is inclusive — with end "12:00:00", 12:00:01 truncates to the
     * same minute (720) and is still active. The first denied time is therefore
     * the minute AFTER the end minute. To keep this case a literal one-second
     * probe, the end time is "11:59:59": it truncates to minute 719, and
     * 12:00:00 (one second later) truncates to 720 -> denied. Pre-fix, the end
     * value "11:59:59" (seconds=59) also threw TypeError.
     */
    public function testOneSecondAfterEndIsDenied(): void
    {
        $schedule = $this->schedule('10:00:00', '11:59:59');

        $this->assertFalse($schedule->isActiveAt($this->at('12:00:00')));
    }

    /**
     * Test C5 — the midnight boundary through the real row-hydration default
     * path: fromRow() with NO end_time key defaults to '23:59:59'. Both ends of
     * the all-day window must be active, and the default end value must NOT
     * throw (pre-fix, timeToMinutes('23:59:59') raised TypeError).
     */
    public function testMidnightBoundaryViaFromRowDefaultEndTime(): void
    {
        $schedule = AccessSchedule::fromRow([
            'id' => 1,
            'profile_id' => 'a1b2c3d4-0000-4000-8000-000000000001',
            'name' => 'MidnightDefault',
            'start_time' => '00:00:00',
            // deliberately no 'end_time' key: fromRow() defaults it to '23:59:59'
            'days_of_week' => 'mon,tue,wed,thu,fri,sat,sun',
            'is_active' => true,
        ]);

        $this->assertSame('23:59:59', $schedule->endTime);
        $this->assertTrue($schedule->isActiveAt($this->at('00:00:00')));
        $this->assertTrue($schedule->isActiveAt($this->at('23:59:59')));
    }

    /**
     * Test C6 — the exact example from the code comment: "00:10:30" has a
     * seconds component (30) that is not a multiple of 60. Pre-fix this threw
     * TypeError; post-fix the second is truncated to whole minutes and the time
     * is inside the window.
     */
    public function testNonMultipleOfSixtySecondsAreHandled(): void
    {
        $schedule = $this->schedule('00:10:00', '00:11:00');

        $this->assertTrue($schedule->isActiveAt($this->at('00:10:30')));
    }

    /**
     * Test C7 — the overnight branch (src/Access/AccessSchedule.php:124) is
     * INCLUSIVE at both bounds: `>= $startMinutes` and `<= $endMinutes`. The
     * exact-bound probes (22:00:00 -> 1320 = startMinutes, 06:00:00 -> 360 =
     * endMinutes) pin those comparisons; a mutation to strict `<`/`>` flips them.
     */
    public function testOvernightScheduleWindowIsInclusiveAtBothBounds(): void
    {
        $schedule = $this->schedule('22:00:00', '06:00:00');

        $this->assertTrue($schedule->isActiveAt($this->at('22:00:00'))); // exact start bound — inclusive
        $this->assertTrue($schedule->isActiveAt($this->at('06:00:00'))); // exact end bound — inclusive
        $this->assertTrue($schedule->isActiveAt($this->at('23:00:00')));
        $this->assertTrue($schedule->isActiveAt($this->at('05:59:59')));
        $this->assertFalse($schedule->isActiveAt($this->at('12:00:00')));
    }

    /**
     * Test C8 — an inactive schedule never allows access: isActiveAt() exits at
     * the `isActive` gate (AccessSchedule.php:103-105) before timeToMinutes()
     * is reached, so this test pins the gate, not the intdiv token, and stays
     * green pre-fix.
     */
    public function testInactiveScheduleNeverAllowsAccess(): void
    {
        $schedule = $this->schedule('00:00:00', '23:59:59', false);

        $this->assertFalse($schedule->isActiveAt($this->at('12:00:00')));
        $this->assertFalse($schedule->isActiveAt($this->at('23:59:59')));
    }
}
