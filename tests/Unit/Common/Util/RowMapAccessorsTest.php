<?php

/**
 * Phlix media server component: Util.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Util;

use Phlix\Common\Util\RowMap;
use PHPUnit\Framework\TestCase;

/**
 * E-09 — convention table for the RowMap value-coercion accessors.
 *
 * Each accessor pins ONE legacy narrowing family found in the estate's
 * 17 hand-rolled `fromRow()` DTOs. The tests below are the semantic
 * contract: identical inputs must produce identical outputs whether the
 * call site reads the raw ternary or the named accessor — a numeric
 * string is `0` under STRICT int (Webhook records) and `5` under
 * numeric coercion (Collection/WatchHistoryRow), and BOTH are laws here,
 * never harmonized silently.
 */
class RowMapAccessorsTest extends TestCase
{
    public function testStrPassesOnlyRealStrings(): void
    {
        $this->assertSame('abc', RowMap::str(['x' => 'abc'], 'x', 'D'));
        $this->assertSame('', RowMap::str(['x' => ''], 'x', 'D'), 'empty string is a real string value');
        $this->assertSame('D', RowMap::str([], 'x', 'D'));
        $this->assertSame('D', RowMap::str(['x' => null], 'x', 'D'));
        $this->assertSame('D', RowMap::str(['x' => 5], 'x', 'D'));
        $this->assertSame('D', RowMap::str(['x' => true], 'x', 'D'));
        $this->assertSame('D', RowMap::str(['x' => ['a']], 'x', 'D'));
    }

    public function testNullableStrPassesOnlyRealStrings(): void
    {
        $this->assertSame('abc', RowMap::nullableStr(['x' => 'abc'], 'x'));
        $this->assertSame('', RowMap::nullableStr(['x' => ''], 'x'));
        $this->assertNull(RowMap::nullableStr([], 'x'));
        $this->assertNull(RowMap::nullableStr(['x' => null], 'x'));
        $this->assertNull(RowMap::nullableStr(['x' => 5], 'x'), 'STRICT family: int is NOT a string');
        $this->assertNull(RowMap::nullableStr(['x' => true], 'x'));
    }

    public function testIntPassesOnlyRealInts(): void
    {
        $this->assertSame(5, RowMap::int(['x' => 5], 'x', 9));
        $this->assertSame(0, RowMap::int(['x' => 0], 'x', 9), 'legit 0 is not the default');
        $this->assertSame(9, RowMap::int(['x' => '5'], 'x', 9), 'strict discipline: numeric string takes default');
        $this->assertSame(9, RowMap::int(['x' => 5.0], 'x', 9), 'float is not int');
        $this->assertSame(9, RowMap::int(['x' => true], 'x', 9));
        $this->assertSame(9, RowMap::int([], 'x', 9));
        $this->assertSame(9, RowMap::int(['x' => null], 'x', 9));
    }

    public function testNullableIntPassesOnlyRealInts(): void
    {
        $this->assertSame(5, RowMap::nullableInt(['x' => 5], 'x'));
        $this->assertSame(0, RowMap::nullableInt(['x' => 0], 'x'));
        $this->assertNull(RowMap::nullableInt(['x' => '500'], 'x'));
        $this->assertNull(RowMap::nullableInt(['x' => 5.5], 'x'));
        $this->assertNull(RowMap::nullableInt([], 'x'));
        $this->assertNull(RowMap::nullableInt(['x' => null], 'x'));
    }

    public function testIntFromNumericMirrorsLegacyIsNumericCast(): void
    {
        $this->assertSame(5, RowMap::intFromNumeric(['x' => 5], 'x', 9));
        $this->assertSame(5, RowMap::intFromNumeric(['x' => '5'], 'x', 9));
        $this->assertSame(7, RowMap::intFromNumeric(['x' => '7.9'], 'x', 9), '(int) cast truncates');
        $this->assertSame(5, RowMap::intFromNumeric(['x' => ' 5'], 'x', 9));
        $this->assertSame(5, RowMap::intFromNumeric(['x' => '5 '], 'x', 9), 'PHP 8 is_numeric allows trailing ws');
        $this->assertSame(1000, RowMap::intFromNumeric(['x' => '1e3'], 'x', 9));
        $this->assertSame(5, RowMap::intFromNumeric(['x' => 5.0], 'x', 9));
        $this->assertSame(9, RowMap::intFromNumeric(['x' => 'abc'], 'x', 9));
        $this->assertSame(9, RowMap::intFromNumeric(['x' => '0x1A'], 'x', 9));
        $this->assertSame(9, RowMap::intFromNumeric(['x' => true], 'x', 9), 'bool is not numeric');
        $this->assertSame(9, RowMap::intFromNumeric(['x' => false], 'x', 9));
        $this->assertSame(9, RowMap::intFromNumeric([], 'x', 9));
        $this->assertSame(9, RowMap::intFromNumeric(['x' => null], 'x', 9));
        $this->assertSame(9, RowMap::intFromNumeric(['x' => ['a']], 'x', 9));
    }

    public function testNullableIntFromNumericMirrorsLegacyDurationTicksShape(): void
    {
        $this->assertSame(10000, RowMap::nullableIntFromNumeric(['x' => '10000'], 'x'));
        $this->assertSame(8, RowMap::nullableIntFromNumeric(['x' => '8.1'], 'x'));
        $this->assertNull(RowMap::nullableIntFromNumeric(['x' => null], 'x'));
        $this->assertNull(RowMap::nullableIntFromNumeric(['x' => 'x'], 'x'));
        $this->assertNull(RowMap::nullableIntFromNumeric([], 'x'));
    }

    public function testFloatFromNumericMirrorsLegacyProgressPercentShape(): void
    {
        $this->assertSame(5.0, RowMap::floatFromNumeric(['x' => '5.0'], 'x', 7.5));
        $this->assertSame(18.18, RowMap::floatFromNumeric(['x' => 18.18], 'x', 7.5));
        $this->assertSame(5.0, RowMap::floatFromNumeric(['x' => 5], 'x', 7.5), 'int is numeric too');
        $this->assertSame(1000.0, RowMap::floatFromNumeric(['x' => '1e3'], 'x', 7.5));
        $this->assertSame(7.5, RowMap::floatFromNumeric(['x' => 'abc'], 'x', 7.5));
        $this->assertSame(7.5, RowMap::floatFromNumeric([], 'x', 7.5));
        $this->assertSame(7.5, RowMap::floatFromNumeric(['x' => null], 'x', 7.5));
    }

    public function testStringFromScalarCastsScalarsOnly(): void
    {
        $this->assertSame('abc', RowMap::stringFromScalar(['x' => 'abc'], 'x', 'D'));
        $this->assertSame('55', RowMap::stringFromScalar(['x' => 55], 'x', 'D'));
        $this->assertSame('1.25', RowMap::stringFromScalar(['x' => 1.25], 'x', 'D'));
        $this->assertSame('1', RowMap::stringFromScalar(['x' => true], 'x', 'D'));
        $this->assertSame('', RowMap::stringFromScalar(['x' => false], 'x', 'D'), '(string)false is empty');
        $this->assertSame('', RowMap::stringFromScalar(['x' => ''], 'x', 'D'));
        $this->assertSame(
            'D',
            RowMap::stringFromScalar(['x' => ['a']], 'x', 'D'),
            'legacy asString() rejected arrays'
        );
        $this->assertSame('D', RowMap::stringFromScalar(['x' => new \stdClass()], 'x', 'D'));
        $this->assertSame('D', RowMap::stringFromScalar([], 'x', 'D'));
        $this->assertSame('D', RowMap::stringFromScalar(['x' => null], 'x', 'D'));
    }

    public function testNullableStringFromScalarKeepsRealNullsNull(): void
    {
        $this->assertNull(RowMap::nullableStringFromScalar([], 'x'));
        $this->assertNull(RowMap::nullableStringFromScalar(['x' => null], 'x'));
        $this->assertSame('abc', RowMap::nullableStringFromScalar(['x' => 'abc'], 'x'));
        $this->assertSame('55', RowMap::nullableStringFromScalar(['x' => 55], 'x'));
        $this->assertSame('1', RowMap::nullableStringFromScalar(['x' => true], 'x'));
        $this->assertSame(
            '',
            RowMap::nullableStringFromScalar(['x' => ['a']], 'x'),
            'legacy contract: non-scalar was NOT null, it was asString() to empty string'
        );
        $this->assertSame('', RowMap::nullableStringFromScalar(['x' => ''], 'x'));
    }

    public function testBoolFromCastMirrorsLegacyTruthyCast(): void
    {
        $this->assertTrue(RowMap::boolFromCast(['x' => 1], 'x', false));
        $this->assertSame(
            false,
            RowMap::boolFromCast(['x' => 0], 'x', true),
            'present 0 is false, default does NOT rescue it'
        );
        $this->assertTrue(RowMap::boolFromCast(['x' => '1'], 'x', false));
        $this->assertFalse(RowMap::boolFromCast(['x' => '0'], 'x', true));
        $this->assertFalse(RowMap::boolFromCast(['x' => ''], 'x', true));
        $this->assertSame(
            true,
            RowMap::boolFromCast(['x' => 'false'], 'x', false),
            'documented cast grammar: non-empty "false" string is TRUE'
        );
        $this->assertTrue(RowMap::boolFromCast(['x' => true], 'x', false));
        $this->assertFalse(RowMap::boolFromCast(['x' => false], 'x', true));
        $this->assertTrue(RowMap::boolFromCast(['x' => null], 'x', true), 'SQL NULL takes the explicit null-default');
        $this->assertFalse(RowMap::boolFromCast(['x' => null], 'x', false));
        $this->assertTrue(RowMap::boolFromCast([], 'x', true), 'missing key behaves like NULL');
        $this->assertFalse(RowMap::boolFromCast(['x' => []], 'x', true));
    }

    public function testStrategiesAreDistinctForTheSameColumnValue(): void
    {
        // The E-09 drift, expressed as a positive law: one input, three
        // named semantics, no silent harmonization.
        $row = ['x' => '5'];
        $this->assertSame(0, RowMap::int($row, 'x', 0), 'strict family (Webhook id)');
        $this->assertSame(5, RowMap::intFromNumeric($row, 'x', 0), 'numeric family (Collection sort_order)');
        $this->assertSame('5', RowMap::str($row, 'x', ''), 'strict string passes it through');
        $this->assertSame('5', RowMap::stringFromScalar($row, 'x', ''), 'scalar-cast string too');
    }
}
