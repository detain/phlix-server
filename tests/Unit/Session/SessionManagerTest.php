<?php

namespace Phlix\Tests\Unit\Session;

use PHPUnit\Framework\TestCase;
use Phlix\Session\SessionManager;
use Workerman\MySQL\Connection;

class SessionManagerTest extends TestCase
{
    public function testCanCreateSessionManager(): void
    {
        $db = $this->createMock(Connection::class);
        $manager = new SessionManager($db);

        $this->assertInstanceOf(SessionManager::class, $manager);
    }

    public function testGetActiveSessionCountInitiallyZero(): void
    {
        $db = $this->createMock(Connection::class);
        $manager = new SessionManager($db);

        $this->assertEquals(0, $manager->getActiveSessionCount());
    }

    public function testGenerateUuidFormat(): void
    {
        $db = $this->createMock(Connection::class);
        $manager = new SessionManager($db);

        // Use reflection to test the private generateUuid method
        $reflection = new \ReflectionClass($manager);
        $method = $reflection->getMethod('generateUuid');
        $method->setAccessible(true);

        $uuid = $method->invoke($manager);
        /** @var string $uuid */

        // UUID format: xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $uuid
        );
    }

    public function testGetSessionsByIdsIsCacheFirstAndSingleQuery(): void
    {
        // M10 (srphp major 3): the batch getter must consult the in-memory
        // activeSessions cache per id, send ONLY the cache misses in ONE
        // IN(...) query, and leave unknown ids absent from the map — the
        // exact per-id getSession() semantics, looped zero times.
        $db = $this->createMock(Connection::class);
        $manager = new SessionManager($db);

        $cache = new \ReflectionProperty($manager, 'activeSessions');
        $cache->setAccessible(true);
        $cache->setValue($manager, ['sess-cached' => ['id' => 'sess-cached', 'device_name' => 'TV']]);

        $db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('WHERE id IN'),
                $this->equalTo(['sess-miss', 'sess-gone'])
            )
            ->willReturn([['id' => 'sess-miss', 'device_name' => 'Phone']]);

        $map = $manager->getSessionsByIds(['sess-cached', 'sess-miss', 'sess-gone', '']);

        $this->assertSame('TV', $map['sess-cached']['device_name']);
        $this->assertSame('Phone', $map['sess-miss']['device_name']);
        $this->assertArrayNotHasKey('sess-gone', $map);
        $this->assertCount(2, $map);
    }
}
