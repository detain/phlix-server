<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Admin;

use DateTime;
use PHPUnit\Framework\TestCase;
use Phlex\Admin\NewsletterSender;
use Workerman\MySQL\Connection;

/**
 * Unit tests for NewsletterSender class.
 *
 * @covers \Phlex\Admin\NewsletterSender
 */
class NewsletterSenderTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = $this->createMock(Connection::class);
    }

    public function testQueueAllCreatesQueueEntries(): void
    {
        $this->db->expects($this->exactly(3))
            ->method('query')
            ->with(
                $this->stringContains('INSERT INTO newsletter_queue'),
                $this->callback(function ($params): bool {
                    return count($params) === 3
                        && is_string($params[0])
                        && strlen($params[0]) === 36;
                })
            );

        $sender = new NewsletterSender($this->db);
        $weekStart = new DateTime('2024-01-01');
        $result = $sender->queueAll(['user-1', 'user-2', 'user-3'], $weekStart);

        $this->assertEquals(3, $result);
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

        $this->assertIsArray($result);
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
