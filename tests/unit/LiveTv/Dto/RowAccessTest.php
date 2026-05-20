<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\LiveTv\Dto;

use PHPUnit\Framework\TestCase;
use Phlix\LiveTv\Dto\RowAccess;

/**
 * @covers \Phlix\LiveTv\Dto\RowAccess
 */
class RowAccessTest extends TestCase
{
    public function testStringPassesStringsThrough(): void
    {
        $this->assertSame('hello', RowAccess::string(['k' => 'hello'], 'k'));
    }

    public function testStringStringifiesNumerics(): void
    {
        $this->assertSame('42', RowAccess::string(['k' => 42], 'k'));
        $this->assertSame('3.5', RowAccess::string(['k' => 3.5], 'k'));
    }

    public function testStringReturnsDefaultForNullOrMissing(): void
    {
        $this->assertSame('', RowAccess::string([], 'k'));
        $this->assertSame('fallback', RowAccess::string(['k' => null], 'k', 'fallback'));
        $this->assertSame('fallback', RowAccess::string(['k' => ['x']], 'k', 'fallback'));
    }

    public function testStringOrNullReturnsNullForMissingOrNull(): void
    {
        $this->assertNull(RowAccess::stringOrNull([], 'k'));
        $this->assertNull(RowAccess::stringOrNull(['k' => null], 'k'));
        $this->assertSame('x', RowAccess::stringOrNull(['k' => 'x'], 'k'));
        $this->assertSame('7', RowAccess::stringOrNull(['k' => 7], 'k'));
    }

    public function testIntCoercesNumericStringsAndFloats(): void
    {
        $this->assertSame(7, RowAccess::int(['k' => 7], 'k'));
        $this->assertSame(7, RowAccess::int(['k' => '7'], 'k'));
        $this->assertSame(7, RowAccess::int(['k' => 7.9], 'k'));
        $this->assertSame(1, RowAccess::int(['k' => true], 'k'));
        $this->assertSame(0, RowAccess::int(['k' => false], 'k'));
    }

    public function testIntReturnsDefaultForNonNumeric(): void
    {
        $this->assertSame(0, RowAccess::int([], 'k'));
        $this->assertSame(-1, RowAccess::int(['k' => 'abc'], 'k', -1));
        $this->assertSame(-1, RowAccess::int(['k' => null], 'k', -1));
    }

    public function testIntOrNullReturnsNullForMissingOrNull(): void
    {
        $this->assertNull(RowAccess::intOrNull([], 'k'));
        $this->assertNull(RowAccess::intOrNull(['k' => null], 'k'));
        $this->assertNull(RowAccess::intOrNull(['k' => 'abc'], 'k'));
        $this->assertSame(5, RowAccess::intOrNull(['k' => 5], 'k'));
        $this->assertSame(5, RowAccess::intOrNull(['k' => '5'], 'k'));
    }

    public function testBoolFollowsTinyIntSemantics(): void
    {
        $this->assertTrue(RowAccess::bool(['k' => 1], 'k'));
        $this->assertTrue(RowAccess::bool(['k' => '1'], 'k'));
        $this->assertTrue(RowAccess::bool(['k' => true], 'k'));
        $this->assertFalse(RowAccess::bool(['k' => 0], 'k'));
        $this->assertFalse(RowAccess::bool(['k' => '0'], 'k'));
        $this->assertFalse(RowAccess::bool(['k' => ''], 'k'));
        $this->assertFalse(RowAccess::bool(['k' => false], 'k'));
        $this->assertTrue(RowAccess::bool([], 'k', true));
        $this->assertFalse(RowAccess::bool(['k' => null], 'k'));
    }
}
