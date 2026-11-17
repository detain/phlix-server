<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Access;

use DateTimeImmutable;
use Phlix\Access\AccessScheduleService;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Workerman\MySQL\Connection;

/**
 * #6c — the active schedule rows per profile are memoised for 30 s.
 *
 * Only the rows are cached. The time-of-day test runs on every call, so the window
 * boundary is exact. Staleness bound: a schedule written by another process reaches
 * this one within 30 s; a write made through THIS service clears the memo at once.
 */
final class AccessScheduleMemoTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private static function lockedOutTodayRow(): array
    {
        $today = strtolower(substr((new DateTimeImmutable())->format('D'), 0, 3));

        return [[
            'id' => 7,
            'profile_id' => 'p1',
            'name' => 'Bedtime',
            'start_time' => '00:00:00',
            'end_time' => '23:59:59',
            'days_of_week' => $today,
            'is_active' => 1,
        ]];
    }

    public function test_repeat_checks_for_one_profile_read_the_rows_once(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('query')
            ->with(self::stringContains('FROM access_schedules'), ['p1'])
            ->willReturn(self::lockedOutTodayRow());

        $service = new AccessScheduleService($db);

        self::assertFalse($service->isAccessAllowed('p1'));
        self::assertFalse($service->isAccessAllowed('p1'));
        self::assertNotNull($service->getActiveScheduleForProfile('p1'));
    }

    public function test_a_schedule_written_through_this_service_is_seen_at_once(): void
    {
        $reads = 0;
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function (string $sql) use (&$reads): mixed {
                if (str_starts_with($sql, 'SELECT * FROM access_schedules WHERE profile_id')) {
                    $reads++;
                    return [];
                }
                return 1;
            }
        );

        $service = new AccessScheduleService($db);
        self::assertTrue($service->isAccessAllowed('p1'));

        $service->deleteSchedule(7);
        self::assertTrue($service->isAccessAllowed('p1'));

        self::assertSame(2, $reads, 'a write made here must drop the memo so the next check re-reads the rows');
    }

    public function test_an_expired_entry_is_read_again(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::exactly(2))->method('query')->willReturn([]);

        $service = new AccessScheduleService($db);
        $service->isAccessAllowed('p1');

        $memo = new ReflectionProperty(AccessScheduleService::class, 'activeMemo');
        $memo->setAccessible(true);
        /** @var array<string, array{at: int, schedules: list<mixed>}> $entries */
        $entries = $memo->getValue($service);
        foreach ($entries as $key => $entry) {
            $entries[$key]['at'] = hrtime(true) - 31_000_000_000;
        }
        $memo->setValue($service, $entries);

        $service->isAccessAllowed('p1');
    }

    public function test_the_memo_is_bounded_and_prunes_aged_entries(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);
        $service = new AccessScheduleService($db);

        $memo = new ReflectionProperty(AccessScheduleService::class, 'activeMemo');
        $memo->setAccessible(true);
        $aged = [];
        for ($i = 0; $i < 600; $i++) {
            $aged['old-' . $i] = ['at' => hrtime(true) - 60_000_000_000, 'schedules' => []];
        }
        $memo->setValue($service, $aged);

        $service->isAccessAllowed('p-live');

        /** @var array<string, mixed> $after */
        $after = $memo->getValue($service);
        self::assertCount(1, $after, 'the aged-out entries are pruned; only the live profile remains');
        self::assertArrayHasKey('p-live', $after);
    }
}
