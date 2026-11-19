<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Database;

use Phlix\Common\Database\MysqlError;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit coverage for {@see MysqlError} — the canonical MySQL error detector
 * (refactor D-16). The shapes asserted here are the two the estate's own
 * connection layer provably produces (raw PDOException with intact
 * errorInfo, and the vendor re-wrap carrying only message + SQLSTATE-as-int),
 * plus the MigrationRunner segment contract (anchored parse, bracketed
 * connect failures deliberately unmatched, echo decoys refused — anchored
 * segment wins, ambiguous multi-segment strings parse to nothing).
 */
final class MysqlErrorTest extends TestCase
{
    /**
     * @return array<array{array<array-key,mixed>|int|null}> [errorInfo, expected]
     */
    public static function errorInfoShapes(): array
    {
        return [
            'raw duplicate key' => [['23000', 1062, "Duplicate entry 'a' for key 'users.username'"], 1062],
            'raw deadlock' => [['40001', 1213, 'Deadlock found when trying to get lock'], 1213],
            'raw lock-wait-timeout' => [['HY000', 1205, 'Lock wait timeout exceeded'], 1205],
            'raw connect lost' => [['HY000', 2006, 'MySQL server has gone away'], 2006],
            'numeric string coerces (legacy cast law)' => [['23000', '1062', 'x'], 1062],
            'zero is the no-driver-error sentinel' => [['00000', 0, ''], null],
            'zero string likewise' => [['00000', '0', ''], null],
            'garbage element is no code' => [['23000', 'n/a', 'x'], null],
            'null element is no code' => [['23000', null, 'x'], null],
            'empty array' => [[], null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('errorInfoShapes')]
    public function testDriverCodeReadsErrorInfoArrays(array $errorInfo, ?int $expected): void
    {
        self::assertSame($expected, MysqlError::driverCode($errorInfo));
    }

    public function testDriverCodeAcceptsNull(): void
    {
        self::assertNull(MysqlError::driverCode(null));
    }

    /**
     * @return array<array{string,int|null}> [message-or-segment, expected]
     */
    public static function messageShapes(): array
    {
        return [
            'vendor re-wrap duplicate' => [
                "SQL:INSERT INTO users (username, email) VALUES (?, ?) SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'bob' for key 'users.username'",
                1062,
            ],
            'vendor re-wrap deadlock' => [
                'SQL:DELETE FROM stats_library_changes WHERE id IN (?) SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction',
                1213,
            ],
            'MigrationRunner segment 1050' => [
                "SQLSTATE[42S01]: Base table or view already exists: 1050 Table 'x' already exists",
                1050,
            ],
            'MigrationRunner segment 1060' => [
                "SQLSTATE[42S21]: Column already exists: 1060 Duplicate column name 'v'",
                1060,
            ],
            'HY000 general-error rendering 1826' => [
                "SQLSTATE[HY000]: General error: 1826 Duplicate foreign key constraint name 'fk'",
                1826,
            ],
            'bracketed connect failure deliberately unmatched' => [
                'SQLSTATE[HY000] [2002] Connection refused',
                null,
            ],
            'too-many-connections bracketed shape unmatched' => [
                'SQLSTATE[08004] [1040] Too many connections',
                null,
            ],
            'payload digits before the real segment cannot forge' => [
                'SQL:SELECT * FROM t WHERE id = 1213 SQLSTATE[HY000]: General error: 1064 You have an error in your SQL syntax',
                1064,
            ],
            'echoed decoy after an anchored driver segment cannot win' => [
                'SQLSTATE[42S02]: Base table not found: 1146 SQLSTATE[23000]: Integrity constraint violation: 1062',
                1146,
            ],
            'ambiguous multi-segment string with no anchor is refused' => [
                "SQL:CREATE TABLE t (id INT) 'SQLSTATE[42S01]: e: 1050' SQLSTATE[42000]: Syntax error: 1064 near echo",
                null,
            ],
            'bare digits are not a wire code' => ['1062', null],
            'phrase only is not a wire code' => ["Duplicate entry 'a' for key 'users.username'", null],
            'empty string' => ['', null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('messageShapes')]
    public function testDriverCodeParsesMessageSegments(string $message, ?int $expected): void
    {
        self::assertSame($expected, MysqlError::driverCode($message));
    }

    public function testIsDuplicateKey(): void
    {
        self::assertTrue(MysqlError::isDuplicateKey(MysqlError::ER_DUP_ENTRY));
        self::assertTrue(MysqlError::isDuplicateKey('1062'));
        self::assertFalse(MysqlError::isDuplicateKey(MysqlError::ER_LOCK_DEADLOCK));
        self::assertFalse(MysqlError::isDuplicateKey('Duplicate entry'));
        self::assertFalse(MysqlError::isDuplicateKey(0));
    }

    public function testIsDeadlock(): void
    {
        self::assertTrue(MysqlError::isDeadlock(MysqlError::ER_LOCK_DEADLOCK));
        self::assertFalse(MysqlError::isDeadlock(MysqlError::ER_LOCK_WAIT_TIMEOUT));
        self::assertFalse(MysqlError::isDeadlock(MysqlError::ER_DUP_ENTRY));
    }

    public function testTheClassIsFinalAndUninstantiable(): void
    {
        $reflection = new ReflectionClass(MysqlError::class);

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->getConstructor()->isPrivate());
    }

    public function testTheNamedVocabularyIsPinned(): void
    {
        // The constants ARE the no-magic-numbers contract: pinning the values
        // keeps a rename from silently retargeting every migrated site.
        self::assertSame(1062, MysqlError::ER_DUP_ENTRY);
        self::assertSame(1205, MysqlError::ER_LOCK_WAIT_TIMEOUT);
        self::assertSame(1213, MysqlError::ER_LOCK_DEADLOCK);
        self::assertSame(2006, MysqlError::CR_SERVER_GONE_ERROR);
        self::assertSame(2013, MysqlError::CR_SERVER_LOST);
        self::assertSame('23000', MysqlError::SQLSTATE_INTEGRITY);
        self::assertSame('40001', MysqlError::SQLSTATE_SERIALIZATION_FAILURE);
        self::assertSame('Duplicate entry', MysqlError::DUPLICATE_ENTRY_PHRASE);
    }
}
