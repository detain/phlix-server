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
}