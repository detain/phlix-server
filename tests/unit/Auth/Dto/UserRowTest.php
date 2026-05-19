<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Auth\Dto;

use Phlex\Auth\Dto\UserRow;
use PHPUnit\Framework\TestCase;

/**
 * Smoke tests for the UserRow narrowing helper.
 */
class UserRowTest extends TestCase
{
    public function testFirstFromMixedReturnsNullForEmptyOrNonArray(): void
    {
        $this->assertNull(UserRow::firstFromMixed(null));
        $this->assertNull(UserRow::firstFromMixed([]));
        $this->assertNull(UserRow::firstFromMixed('not an array'));
    }

    public function testFirstFromMixedNarrowsStringKeys(): void
    {
        $row = UserRow::firstFromMixed([
            [
                'id' => 'u-1',
                'username' => 'alice',
                0 => 'positional-discarded',
            ],
        ]);

        $this->assertIsArray($row);
        $this->assertSame('u-1', $row['id']);
        $this->assertSame('alice', $row['username']);
        $this->assertArrayNotHasKey(0, $row);
    }

    public function testStringReadsScalarColumn(): void
    {
        $row = ['display_name' => 'Alice', 'count' => 5, 'missing' => null];
        $this->assertSame('Alice', UserRow::string($row, 'display_name'));
        $this->assertSame('5', UserRow::string($row, 'count'));
        $this->assertNull(UserRow::string($row, 'absent'));
        $this->assertNull(UserRow::string($row, 'missing'));
        $this->assertNull(UserRow::string(null, 'anything'));
    }

    public function testIntReadsNumericColumn(): void
    {
        $row = ['count' => '42', 'word' => 'hello'];
        $this->assertSame(42, UserRow::int($row, 'count'));
        $this->assertSame(0, UserRow::int($row, 'word'));
        $this->assertSame(7, UserRow::int($row, 'missing', 7));
        $this->assertSame(0, UserRow::int(null, 'anything'));
    }
}
