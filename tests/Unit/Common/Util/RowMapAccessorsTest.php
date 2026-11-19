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
use ReflectionMethod;

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

    /**
     * S121 law, extracted verbatim from the music DTOs' mediaItemIdFromRow():
     * strict string, empty collapses to NULL, whitespace passes through
     * UNTRIMMED (the disclosed residual — mutants adding trim() or widening to
     * is_numeric must redden the ' '/'"\0"' rows below, exactly as they reddened
     * S121's guards).
     */
    public function testNullableNonEmptyStrCarriesTheS121MediaItemIdLaw(): void
    {
        $uuid = '3f2b1c0a-9d8e-4f70-a1b2-c3d4e5f60718';
        $this->assertSame($uuid, RowMap::nullableNonEmptyStr(['x' => $uuid], 'x'));
        $this->assertSame('0', RowMap::nullableNonEmptyStr(['x' => '0'], 'x'), 'junk strings pass: FK validates, not the reader');
        $this->assertNull(RowMap::nullableNonEmptyStr(['x' => ''], 'x'), "'' collapses to NULL — never to '' downstream");
        $this->assertNull(RowMap::nullableNonEmptyStr(['x' => null], 'x'));
        $this->assertNull(RowMap::nullableNonEmptyStr([], 'x'), 'missing key behaves like SQL NULL');
        $this->assertNull(RowMap::nullableNonEmptyStr(['x' => 42], 'x'), 'strict family: int is NOT a string');
        $this->assertNull(RowMap::nullableNonEmptyStr(['x' => 3.5], 'x'));
        $this->assertNull(RowMap::nullableNonEmptyStr(['x' => true], 'x'));
        $this->assertNull(RowMap::nullableNonEmptyStr(['x' => ['a']], 'x'));
        // Whitespace residual (S121 review r8 INFO-2): passes through UNCHANGED.
        $this->assertSame(' ', RowMap::nullableNonEmptyStr(['x' => ' '], 'x'));
        $this->assertSame('  ', RowMap::nullableNonEmptyStr(['x' => '  '], 'x'));
        $this->assertSame("\0", RowMap::nullableNonEmptyStr(['x' => "\0"], 'x'));
        $this->assertSame(' x ', RowMap::nullableNonEmptyStr(['x' => ' x '], 'x'));
        $this->assertSame($uuid . ' ', RowMap::nullableNonEmptyStr(['x' => $uuid . ' '], 'x'));
    }

    /**
     * S143 law: the parser must never FABRICATE — degenerate strings, non-
     * strings and unparseable strings all hydrate NULL (pre-S143, '' yielded
     * the current second and '0000-00-00…' yielded year -0001).
     */
    public function testParseMysqlDateTimeRefusesToFabricate(): void
    {
        $this->assertNull(RowMap::parseMysqlDateTime(''), "new DateTime('') silently means 'now' — refused");
        $this->assertNull(RowMap::parseMysqlDateTime('   '), 'whitespace also parses as now — refused');
        $this->assertNull(RowMap::parseMysqlDateTime('0000-00-00 00:00:00'), 'MySQL zero date — refused');
        $this->assertNull(RowMap::parseMysqlDateTime('0000-00-00'), 'zero date, short form — refused');
        $this->assertNull(RowMap::parseMysqlDateTime('not a date at all'));
        $this->assertNull(RowMap::parseMysqlDateTime(123), 'an int is NOT a unix timestamp');
        $this->assertNull(RowMap::parseMysqlDateTime(123.5));
        $this->assertNull(RowMap::parseMysqlDateTime(true));
        $this->assertNull(RowMap::parseMysqlDateTime(['2026-01-01']));
        $this->assertNull(RowMap::parseMysqlDateTime(null));
        // Real values parse; trim() decides EMPTYNESS only — the parsed string
        // itself is passed byte-for-byte (padded values keep parsing as always).
        $parsed = RowMap::parseMysqlDateTime('2026-01-01 10:00:00');
        $this->assertInstanceOf(\DateTime::class, $parsed);
        $this->assertSame('2026-01-01 10:00:00', $parsed->format('Y-m-d H:i:s'));
        $padded = RowMap::parseMysqlDateTime('  2026-01-01 10:00:00  ');
        $this->assertInstanceOf(\DateTime::class, $padded, 'non-blank padded values are NOT this law\'s business');
    }

    /**
     * S143's second bug: \DateTimeImmutable does NOT extend \DateTime. The
     * interface conversion must preserve instant + microseconds + timezone,
     * and a mutable \DateTime must come back BY IDENTITY.
     */
    public function testParseMysqlDateTimeHandlesInterfaceConversionAndIdentity(): void
    {
        $immutable = new \DateTimeImmutable('2026-02-02 11:00:00.123456', new \DateTimeZone('America/New_York'));
        $converted = RowMap::parseMysqlDateTime($immutable);
        $this->assertInstanceOf(\DateTime::class, $converted);
        $this->assertNotSame($immutable, $converted, 'immutable is CONVERTED to mutable');
        $this->assertSame('2026-02-02 11:00:00.123456', $converted->format('Y-m-d H:i:s.u'), 'microseconds survive');
        $this->assertSame($immutable->getTimezone()->getName(), $converted->getTimezone()->getName(), 'timezone survives');
        $this->assertSame($immutable->getTimestamp(), $converted->getTimestamp(), 'instant survives');

        $mutable = new \DateTime('2026-03-03 12:00:00', new \DateTimeZone('UTC'));
        $this->assertSame($mutable, RowMap::parseMysqlDateTime($mutable), 'mutable DateTime returns BY IDENTITY');
    }

    public function testNullableDateTimeCollapsesMissingNullAndValueIdentically(): void
    {
        // Equivalence to `isset($row[$c]) ? parse($row[$c]) : null`: the parser
        // maps NULL to null anyway, so missing-key and explicit-NULL land alike.
        $this->assertNull(RowMap::nullableDateTime([], 'x'));
        $this->assertNull(RowMap::nullableDateTime(['x' => null], 'x'));
        $this->assertNull(RowMap::nullableDateTime(['x' => ''], 'x'));
        $this->assertNull(RowMap::nullableDateTime(['x' => 123], 'x'));
        $value = RowMap::nullableDateTime(['x' => '2026-01-01 10:00:00'], 'x');
        $this->assertInstanceOf(\DateTime::class, $value);
        $this->assertSame('2026-01-01 10:00:00', $value->format('Y-m-d H:i:s'));
    }

    public function testEveryValueAccessorRequiresItsDefaultArgument(): void
    {
        // Structural pin (SRV-MICRO idiom), not a behavior pin: the whole
        // no-harmonization doctrine rests on every default being a MANDATORY
        // argument. A silent `= ''` escape hatch would let a future cohort
        // omit the argument at a call site and quietly harmonize a banked
        // legacy default away — and the r1 review proved a mandate-stripping
        // mutant survived all behavioral tests. The signature IS the law here.
        $defaultTaking = ['str', 'int', 'intFromNumeric', 'floatFromNumeric', 'stringFromScalar', 'boolFromCast'];
        foreach ($defaultTaking as $method) {
            $reflection = new ReflectionMethod(RowMap::class, $method);
            $this->assertSame(
                3,
                $reflection->getNumberOfRequiredParameters(),
                "RowMap::{$method}() must REQUIRE its default argument (explicit-default mandate)"
            );
            $this->assertSame(
                3,
                $reflection->getNumberOfParameters(),
                "RowMap::{$method}() must accept no parameter beyond (row, column, default)"
            );
            $this->assertFalse(
                $reflection->getParameters()[2]->isOptional(),
                "RowMap::{$method}() default must have no silent fallback (third parameter optional)"
            );
        }
        // The four nullable forms encode the null policy in the METHOD NAME —
        // they take exactly (row, column) and must not grow a hidden default
        // parameter that would re-open the harmonization escape.
        $nullableForms = [
            'nullableStr',
            'nullableInt',
            'nullableIntFromNumeric',
            'nullableStringFromScalar',
            'nullableNonEmptyStr',
            'nullableDateTime',
        ];
        foreach ($nullableForms as $method) {
            $reflection = new ReflectionMethod(RowMap::class, $method);
            $this->assertSame(
                2,
                $reflection->getNumberOfParameters(),
                "RowMap::{$method}() is name-encoded NULL policy: (row, column) only, no default parameter"
            );
            $this->assertSame(
                2,
                $reflection->getNumberOfRequiredParameters(),
                "RowMap::{$method}() must require both parameters"
            );
        }
    }
}
