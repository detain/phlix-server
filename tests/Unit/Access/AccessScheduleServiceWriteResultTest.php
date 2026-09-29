<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Access;

use PHPUnit\Framework\TestCase;
use Phlix\Access\AccessScheduleService;
use Workerman\MySQL\Connection;

/**
 * L-7 (security audit 2026-09-29) — honest write predicates on
 * AccessScheduleService::updateSchedule()/deleteSchedule().
 *
 * Both used to end `return $result !== false;`. Workerman's query() never
 * returns false (it THROWS on failure), so the guard was dead code permanently
 * evaluating true: deleting a non-existent schedule id reported success, and
 * the UPDATE's return value said nothing the docblock claimed it said. The S131
 * family fix (see StreamSessionServiceTest's pinned shapes) replaces the lie
 * with the real contract:
 *
 *   * UPDATE … WHERE id = ?  → true iff the write RAN (int rowcount returned;
 *     a 0-rowcount no-op — values already current — is still a satisfied
 *     intent, unlike the upsert case this deliberately mirrors).
 *   * DELETE … WHERE id = ?  → true iff a row was REMOVED (rowcount > 0).
 */
final class AccessScheduleServiceWriteResultTest extends TestCase
{
    private function serviceReturning(mixed $writeResult): AccessScheduleService
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn($writeResult);

        return new AccessScheduleService($db);
    }

    public function test_update_returns_true_when_write_ran_with_rowcount(): void
    {
        $service = $this->serviceReturning(1);

        self::assertTrue($service->updateSchedule(7, ['name' => 'weekday']));
    }

    public function test_update_returns_true_on_identical_value_noop(): void
    {
        // MySQL reports 0 affected rows when the new values equal the stored
        // ones — the caller's intent IS satisfied; must not read as failure.
        $service = $this->serviceReturning(0);

        self::assertTrue($service->updateSchedule(7, ['name' => 'weekday']));
    }

    public function test_update_returns_false_for_empty_data_without_touching_db(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('query');
        $service = new AccessScheduleService($db);

        self::assertFalse($service->updateSchedule(7, []));
    }

    public function test_delete_returns_true_only_when_a_row_was_removed(): void
    {
        self::assertTrue($this->serviceReturning(1)->deleteSchedule(7));
        // 0 rows: unknown id — the OLD code called this success. Pinned false.
        self::assertFalse($this->serviceReturning(0)->deleteSchedule(7));
    }
}
