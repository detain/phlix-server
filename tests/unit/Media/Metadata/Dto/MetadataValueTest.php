<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Media\Metadata\Dto;

use Phlex\Media\Metadata\Dto\MetadataValue;
use PHPUnit\Framework\TestCase;

/**
 * Smoke tests for the MetadataValue narrowing helpers.
 *
 * These mirror the JSON-decoded provider responses where every leaf is
 * inferred as mixed by PHPStan. The helpers should accept any input and
 * coerce it into a usable concrete type without throwing.
 */
class MetadataValueTest extends TestCase
{
    public function testAsStringPreservesStrings(): void
    {
        $this->assertSame('hello', MetadataValue::asString('hello'));
    }

    public function testAsStringCoercesNumbers(): void
    {
        $this->assertSame('42', MetadataValue::asString(42));
        $this->assertSame('3.14', MetadataValue::asString(3.14));
    }

    public function testAsStringFallsBackForOtherTypes(): void
    {
        $this->assertSame('', MetadataValue::asString(null));
        $this->assertSame('default', MetadataValue::asString(['x'], 'default'));
        $this->assertSame('default', MetadataValue::asString(false, 'default'));
    }

    public function testAsNullableStringReturnsNullForEmpty(): void
    {
        $this->assertNull(MetadataValue::asNullableString(''));
        $this->assertNull(MetadataValue::asNullableString(null));
        $this->assertNull(MetadataValue::asNullableString(['x']));
        $this->assertSame('hello', MetadataValue::asNullableString('hello'));
        $this->assertSame('0', MetadataValue::asNullableString(0));
    }

    public function testAsIntPreservesInts(): void
    {
        $this->assertSame(7, MetadataValue::asInt(7));
        $this->assertSame(3, MetadataValue::asInt(3.9));
        $this->assertSame(42, MetadataValue::asInt('42'));
    }

    public function testAsIntFallsBackForNonNumeric(): void
    {
        $this->assertSame(0, MetadataValue::asInt('abc'));
        $this->assertSame(0, MetadataValue::asInt(null));
        $this->assertSame(99, MetadataValue::asInt(null, 99));
    }

    public function testAsNullableIntReturnsNullForGarbage(): void
    {
        $this->assertNull(MetadataValue::asNullableInt('abc'));
        $this->assertNull(MetadataValue::asNullableInt(null));
        $this->assertSame(5, MetadataValue::asNullableInt('5'));
        $this->assertSame(5, MetadataValue::asNullableInt(5));
    }

    public function testAsFloatHandlesAllNumericForms(): void
    {
        $this->assertSame(1.5, MetadataValue::asFloat(1.5));
        $this->assertSame(2.0, MetadataValue::asFloat(2));
        $this->assertSame(3.14, MetadataValue::asFloat('3.14'));
        $this->assertSame(0.0, MetadataValue::asFloat('not a number'));
        $this->assertSame(9.9, MetadataValue::asFloat(null, 9.9));
    }

    public function testAsNullableFloatReturnsNullForGarbage(): void
    {
        $this->assertNull(MetadataValue::asNullableFloat(null));
        $this->assertNull(MetadataValue::asNullableFloat('abc'));
        $this->assertSame(1.5, MetadataValue::asNullableFloat('1.5'));
    }

    public function testAsAssocDropsNonStringKeys(): void
    {
        /** @var array<int|string, mixed> $input */
        $input = ['a' => 1, 0 => 'zero', 'b' => 2];
        $out = MetadataValue::asAssoc($input);

        $this->assertSame(['a' => 1, 'b' => 2], $out);
    }

    public function testAsAssocReturnsEmptyArrayForNonArray(): void
    {
        $this->assertSame([], MetadataValue::asAssoc(null));
        $this->assertSame([], MetadataValue::asAssoc('string'));
        $this->assertSame([], MetadataValue::asAssoc(42));
    }

    public function testAsAssocListNormalizesEntries(): void
    {
        $input = [
            ['a' => 1],
            'not-an-array',
            ['b' => 2, 0 => 'drop'],
        ];

        $out = MetadataValue::asAssocList($input);

        $this->assertCount(2, $out);
        $this->assertSame(['a' => 1], $out[0]);
        $this->assertSame(['b' => 2], $out[1]);
    }

    public function testAsAssocListReturnsEmptyForNonArray(): void
    {
        $this->assertSame([], MetadataValue::asAssocList(null));
        $this->assertSame([], MetadataValue::asAssocList('x'));
    }

    public function testAsListReindexesEntries(): void
    {
        $this->assertSame([], MetadataValue::asList(null));
        $this->assertSame(['a', 'b'], MetadataValue::asList(['x' => 'a', 'y' => 'b']));
        $this->assertSame([1, 2, 3], MetadataValue::asList([1, 2, 3]));
    }
}
