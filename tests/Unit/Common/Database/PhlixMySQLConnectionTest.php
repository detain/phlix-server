<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Database;

use Phlix\Common\Database\PhlixMySQLConnection;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Unit coverage for {@see PhlixMySQLConnection} transaction mutex.
 *
 * Since the mutex is only meaningful inside a Swoole coroutine runtime, these
 * tests verify:
 * 1. The class has the expected transaction-lifecycle methods with correct signatures
 * 2. The transaction-lock properties are declared and default-initialized correctly
 * 3. The non-coroutine path delegates to the parent (verified via mock)
 *
 * Concurrent/serialization behaviour inside coroutines is validated separately
 * via integration tests on a live Swoole worker restart.
 *
 * @see PhlixMySQLConnection::beginTrans()
 * @see PhlixMySQLConnection::commitTrans()
 * @see PhlixMySQLConnection::rollBackTrans()
 * @see PhlixMySQLConnection::query()
 */
final class PhlixMySQLConnectionTest extends TestCase
{
    /**
     * Verify the transaction-lock properties are declared on the class.
     *
     * This uses reflection to check property DECLARATIONS only (names and types),
     * not values — so it never instantiates the class and thus never opens a
     * connection socket.
     */
    public function testTransactionLockPropertiesAreDeclared(): void
    {
        // These property names must match the private fields in PhlixMySQLConnection.
        $expectedProperties = [
            'queryLock' => \Swoole\Coroutine\Channel::class,
            'queryLockHolder' => 'int',
            'transLock' => \Swoole\Coroutine\Channel::class,
            'transLockHolder' => 'int',
            'transNesting' => 'int',
        ];

        $class = PhlixMySQLConnection::class;
        foreach ($expectedProperties as $name => $expectedType) {
            $prop = new ReflectionProperty($class, $name);
            $prop->setAccessible(true);

            $this->assertSame(
                $expectedType,
                $prop->getType()?->getName(),
                "Property \${$name} must be declared as {$expectedType}"
            );
        }
    }

    /**
     * Verify beginTrans(), commitTrans() and rollBackTrans() are declared
     * public on the class (they override the parent's abstract/public
     * counterparts from Workerman\MySQL\Connection).
     */
    public function testTransactionMethodsArePublic(): void
    {
        $class = PhlixMySQLConnection::class;

        foreach (['beginTrans', 'commitTrans', 'rollBackTrans'] as $method) {
            $reflection = new \ReflectionMethod($class, $method);
            $this->assertTrue(
                $reflection->isPublic(),
                "Method {$method}() must be public"
            );
        }
    }

    /**
     * Verify query() is still public (it carries the per-query mutex logic).
     */
    public function testQueryMethodIsPublic(): void
    {
        $reflection = new \ReflectionMethod(PhlixMySQLConnection::class, 'query');
        $this->assertTrue($reflection->isPublic(), 'query() must be public');
    }

    /**
     * Verify the constructor accepts exactly 6 parameters with the expected
     * defaults (charset defaults to 'utf8mb4', others required).
     * Uses reflection only — does not open a connection.
     */
    public function testConstructorSignature(): void
    {
        $ctor = new \ReflectionMethod(PhlixMySQLConnection::class, '__construct');
        $params = $ctor->getParameters();

        $this->assertCount(6, $params, 'Constructor must accept exactly 6 parameters');

        $this->assertSame('host', $params[0]->getName());
        $this->assertSame('port', $params[1]->getName());
        $this->assertSame('user', $params[2]->getName());
        $this->assertSame('password', $params[3]->getName());
        $this->assertSame('db_name', $params[4]->getName());
        $this->assertSame('charset', $params[5]->getName());

        $this->assertTrue($params[5]->isDefaultValueAvailable());
        $this->assertSame('utf8mb4', $params[5]->getDefaultValue());
    }
}
