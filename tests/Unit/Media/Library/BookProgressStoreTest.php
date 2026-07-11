<?php

namespace Phlix\Tests\Unit\Media\Library;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Phlix\Media\Library\BookProgress;
use Phlix\Media\Library\BookProgressStore;
use Workerman\MySQL\Connection;

class BookProgressStoreTest extends TestCase
{
    /** @return Connection&MockObject */
    private function createMockConnection(): Connection
    {
        return $this->createMock(Connection::class);
    }

    public function testCanCreateBookProgressStore(): void
    {
        $db = $this->createMockConnection();
        $store = new BookProgressStore($db);

        $this->assertInstanceOf(BookProgressStore::class, $store);
    }

    public function testSaveAndRetrieveProgress(): void
    {
        $db = $this->createMockConnection();

        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('INSERT INTO book_progress'),
                $this->callback(function ($params) {
                    return count($params) === 7
                        && $params[0] === 'user-123'
                        && $params[1] === 'book-456'
                        && $params[2] === 5000
                        && $params[3] === 5
                        && $params[4] === 200
                        && is_string($params[5]);
                })
            );

        $progress = new BookProgress(
            'book-456',
            'user-123',
            5000,
            5,
            200,
            25.5,
            time()
        );

        $store = new BookProgressStore($db);
        $store->saveProgress($progress);
    }

    public function testGetProgressReturnsNullWhenNotFound(): void
    {
        $db = $this->createMockConnection();

        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('SELECT * FROM book_progress'),
                $this->callback(function ($params) {
                    return $params[0] === 'user-123' && $params[1] === 'book-456';
                })
            )
            ->willReturn([]);

        $store = new BookProgressStore($db);
        $result = $store->getProgress('user-123', 'book-456');

        $this->assertNull($result);
    }

    public function testGetProgressReturnsProgressWhenFound(): void
    {
        $db = $this->createMockConnection();

        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('SELECT * FROM book_progress'),
                $this->callback(function ($params) {
                    return $params[0] === 'user-123' && $params[1] === 'book-456';
                })
            )
            ->willReturn([[
                'user_id' => 'user-123',
                'book_id' => 'book-456',
                'position_ms' => 10000,
                'current_page' => 15,
                'total_pages' => 200,
                'percent_complete' => 7.5,
                'last_read_at' => time(),
            ]]);

        $store = new BookProgressStore($db);
        $result = $store->getProgress('user-123', 'book-456');

        $this->assertInstanceOf(BookProgress::class, $result);
        $this->assertSame('book-456', $result->book_id);
        $this->assertSame('user-123', $result->user_id);
        $this->assertSame(10000, $result->position_ms);
        $this->assertSame(15, $result->current_page);
        $this->assertSame(200, $result->total_pages);
        $this->assertSame(7.5, $result->percent_complete);
    }
}
