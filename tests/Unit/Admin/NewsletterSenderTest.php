<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Admin;

use DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Phlix\Admin\NewsletterSchedule;
use Phlix\Admin\NewsletterSender;
use Workerman\MySQL\Connection;

/**
 * Unit tests for NewsletterSender class.
 */
class NewsletterSenderTest extends TestCase
{
    /** @var Connection&MockObject */
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = $this->createMock(Connection::class);
    }

    public function testQueueAllCreatesQueueEntries(): void
    {
        // #54 law: the count is rows ACTUALLY created. vendor
        // Connection::query() returns lastInsertId() (never null) for an INSERT
        // that changed rows, and NULL for an IGNORE-skipped duplicate — the
        // callback below emulates one re-fired recipient (third user already
        // queued) against two fresh ones.
        $this->db->expects($this->exactly(3))
            ->method('query')
            ->with(
                $this->stringContains('INSERT IGNORE INTO newsletter_queue'),
                $this->callback(function ($params): bool {
                    return count($params) === 3
                        && is_string($params[0])
                        && strlen($params[0]) === 36;
                })
            )
            ->willReturnCallback(function (string $sql, array $params): ?string {
                return $params[1] === 'user-3' ? null : '0';
            });

        $sender = new NewsletterSender($this->db);
        $weekStart = new DateTime('2024-01-01');
        $result = $sender->queueAll(['user-1', 'user-2', 'user-3'], $weekStart);

        $this->assertEquals(2, $result, 'already-queued duplicates must not inflate the created-count');
    }

    public function testQueueAllReturnsZeroForEmptyUserList(): void
    {
        $this->db->expects($this->never())
            ->method('query');

        $sender = new NewsletterSender($this->db);
        $weekStart = new DateTime('2024-01-01');
        $result = $sender->queueAll([], $weekStart);

        $this->assertEquals(0, $result);
    }

    public function testGetDeliveryStats(): void
    {
        $this->db->method('query')->willReturn([
            ['status' => 'pending', 'count' => '10'],
            ['status' => 'sent', 'count' => '45'],
            ['status' => 'failed', 'count' => '5'],
        ]);

        $sender = new NewsletterSender($this->db);
        $result = $sender->getDeliveryStats();

        $this->assertArrayHasKey('pending', $result);
        $this->assertArrayHasKey('sent', $result);
        $this->assertArrayHasKey('failed', $result);
        $this->assertArrayHasKey('total', $result);

        $this->assertEquals(10, $result['pending']);
        $this->assertEquals(45, $result['sent']);
        $this->assertEquals(5, $result['failed']);
        $this->assertEquals(60, $result['total']);
    }

    public function testGetDeliveryStatsReturnsZerosWhenEmpty(): void
    {
        $this->db->method('query')->willReturn([]);

        $sender = new NewsletterSender($this->db);
        $result = $sender->getDeliveryStats();

        $this->assertEquals(0, $result['pending']);
        $this->assertEquals(0, $result['sent']);
        $this->assertEquals(0, $result['failed']);
        $this->assertEquals(0, $result['total']);
    }

    public function testProcessQueueWithNoPendingEntries(): void
    {
        $this->db->method('query')->willReturn([]);

        $sender = new NewsletterSender($this->db);
        $result = $sender->processQueue(50);

        $this->assertEquals(0, $result);
    }

    public function testGetPendingCount(): void
    {
        $this->db->method('query')->willReturn([['count' => '25']]);

        $sender = new NewsletterSender($this->db);
        $result = $sender->getPendingCount();

        $this->assertEquals(25, $result);
    }

    /**
     * The infinite-drain cure's other half: the PENDING count must speak the
     * same eligibility predicate as processQueue's SELECT. Pre-fix the count
     * saw every pending row while the select refused exhausted-attempt ones —
     * the loop between them could never terminate. This pin is SQL-shape
     * teeth: drop the attempts clause (or its bind) and it reddens.
     */
    public function testGetPendingCountCarriesTheProcessQueueEligibilityPredicate(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->callback(function (string $sql): bool {
                    return str_contains($sql, "status = 'pending'")
                        && str_contains($sql, 'attempts < ?');
                }),
                $this->callback(function (array $params): bool {
                    return $params === [3];
                })
            )
            ->willReturn([['count' => '0']]);

        $sender = new NewsletterSender($this->db);
        $this->assertSame(0, $sender->getPendingCount());
    }

    public function testReapExhaustedMovesUnselectablePendingRowsToFailed(): void
    {
        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->callback(function (string $sql): bool {
                    return str_contains($sql, 'UPDATE newsletter_queue')
                        && str_contains($sql, "SET status = 'failed'")
                        && str_contains($sql, "status = 'pending'")
                        && str_contains($sql, 'attempts >= ?');
                }),
                $this->callback(function (array $params): bool {
                    return count($params) === 2
                        && $params[0] === NewsletterSchedule::NOTE_EXHAUSTED_ATTEMPTS
                        && $params[1] === 3;
                })
            )
            ->willReturn(2);

        $sender = new NewsletterSender($this->db);
        $this->assertSame(2, $sender->reapExhausted());
    }

    public function testReapExhaustedReportsZeroWhenTheDoubleAnswersNonNumeric(): void
    {
        // Honest coercion: a non-int result (never from vendor UPDATE, but
        // the guard is what keeps a weird driver from crashing the sweep).
        $this->db->method('query')->willReturn(true);

        $sender = new NewsletterSender($this->db);
        $this->assertSame(0, $sender->reapExhausted());
    }

    /**
     * E-09 cohort 5a (W5, adjudication A7): processQueue id/user_id
     * stringFromScalar legs + week_start str leg vs the pre-fold dual-guard
     * ternaries (transcribed verbatim from the parent blob). Bool vectors are
     * omitted: unreachable through the stringify-off PDO handle (the fold
     * comment documents the one-corner domain difference).
     */
    public function test_queue_row_decode_legs_are_byte_identical_to_legacy_across_vectors(): void
    {
        /** @var list<array<string, mixed>> $vectors */
        $vectors = [
            ['id' => '12', 'user_id' => '34', 'week_start' => '2026-01-05'],
            ['id' => 123, 'user_id' => 456, 'week_start' => '2026-01-05'],
            ['id' => 7.0, 'user_id' => '8', 'week_start' => '2026-01-06'],
            ['id' => null, 'user_id' => null, 'week_start' => null],
            ['id' => 'abc', 'user_id' => '', 'week_start' => 42],
        ];

        foreach ($vectors as $row) {
            $attemptsIds = [];
            $sentIds = [];
            $db = $this->createMock(Connection::class);
            $db->method('query')->willReturnCallback(
                function (string $sql, array $params = []) use ($row, &$attemptsIds, &$sentIds) {
                    if (str_contains($sql, 'FROM newsletter_queue')) {
                        return [$row];
                    }
                    if (str_contains($sql, 'SET attempts')) {
                        $attemptsIds[] = $params[0];
                    }
                    if (str_contains($sql, "SET status = 'sent'")) {
                        $sentIds[] = $params[0];
                    }
                    return [];
                }
            );

            $sender = new class ($db) extends NewsletterSender {
                /** @var list<array{string, string}> */
                public array $seen = [];

                protected function sendNewsletter(string $userId, string $weekStart): bool
                {
                    $this->seen[] = [$userId, $weekStart];
                    return true;
                }
            };

            $this->assertSame(1, $sender->processQueue(1));

            $queueId = is_string($row['id']) || is_numeric($row['id']) ? (string) $row['id'] : '';
            $userId  = is_string($row['user_id']) || is_numeric($row['user_id'])
                ? (string) $row['user_id']
                : '';
            $week    = is_string($row['week_start']) ? $row['week_start'] : '';

            $this->assertSame([$queueId], $attemptsIds, 'attempts leg for vector ' . json_encode($row));
            $this->assertSame([$queueId], $sentIds, 'sent leg for vector ' . json_encode($row));
            $this->assertSame([[$userId, $week]], $sender->seen, 'send payload for vector ' . json_encode($row));
        }
    }

    /**
     * E-09 cohort 5a (W5): getDeliveryStats status/count fold legs vs the
     * pre-fold ternaries + the KEPT explicit-key consumption arms.
     */
    public function test_delivery_stats_row_decode_legs_are_byte_identical_to_legacy_across_vectors(): void
    {
        /** @var list<array<string, mixed>> $vectors */
        $vectors = [
            ['status' => 'pending', 'count' => 2],
            ['status' => 'sent', 'count' => '7'],
            ['status' => 'failed', 'count' => 1.9],
            ['status' => 'total', 'count' => 3],
            ['status' => null, 'count' => null],
            ['status' => 5, 'count' => 'abc'],
        ];

        foreach ($vectors as $row) {
            $db = $this->createMock(Connection::class);
            $db->method('query')->willReturn([$row]);
            $sender = new NewsletterSender($db);

            $status = is_string($row['status']) ? $row['status'] : '';
            $count  = is_numeric($row['count']) ? (int) $row['count'] : 0;
            $expected = ['pending' => 0, 'sent' => 0, 'failed' => 0, 'total' => 0];
            if ($status === 'pending' || $status === 'sent' || $status === 'failed') {
                $expected[$status] = $count;
            }
            $expected['total'] += $count;

            $this->assertSame($expected, $sender->getDeliveryStats(), 'vector ' . json_encode($row));
        }
    }
}
