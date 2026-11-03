<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Admin;

use DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
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
}
