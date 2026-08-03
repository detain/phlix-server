<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use Phlix\Server\Http\Controllers\ByteRangeParser;
use Phlix\Server\Http\Controllers\HlsController;
use PHPUnit\Framework\TestCase;

final class ByteRangeParserTest extends TestCase
{
    public function testNullHeaderReturnsNull(): void
    {
        self::assertNull(ByteRangeParser::parse(null, 100));
    }

    public function testMultiRangeIsUnsupportedFallsBackToNull(): void
    {
        self::assertNull(ByteRangeParser::parse('bytes=0-1,4-5', 100));
    }

    public function testClosedRange(): void
    {
        self::assertSame(
            ['satisfiable' => true, 'start' => 0, 'end' => 3],
            ByteRangeParser::parse('bytes=0-3', 16),
        );
    }

    public function testOpenEndedRangeExtendsToEof(): void
    {
        self::assertSame(
            ['satisfiable' => true, 'start' => 4, 'end' => 15],
            ByteRangeParser::parse('bytes=4-', 16),
        );
    }

    public function testOverlongEndIsClampedNotRejected(): void
    {
        self::assertSame(
            ['satisfiable' => true, 'start' => 0, 'end' => 15],
            ByteRangeParser::parse('bytes=0-999999', 16),
        );
    }

    public function testStartPastEofIsUnsatisfiable(): void
    {
        $r = ByteRangeParser::parse('bytes=999-1000', 16);
        self::assertNotNull($r);
        self::assertFalse($r['satisfiable']);
    }

    public function testSuffixRange(): void
    {
        self::assertSame(
            ['satisfiable' => true, 'start' => 12, 'end' => 15],
            ByteRangeParser::parse('bytes=-4', 16),
        );
    }

    public function testSuffixRangeOnEmptyFileIsUnsatisfiable(): void
    {
        $r = ByteRangeParser::parse('bytes=-4', 0);
        self::assertNotNull($r);
        self::assertFalse($r['satisfiable']);
    }

    /**
     * The trait retains a delegating parseRange() for its mixing-in consumers;
     * it must return exactly what ByteRangeParser::parse() computes.
     */
    public function testTraitDelegatesToParser(): void
    {
        self::assertSame(
            ByteRangeParser::parse('bytes=0-3', 16),
            HlsController::parseRange('bytes=0-3', 16),
        );
    }
}
