<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Database;

use InvalidArgumentException;
use Phlix\Common\Database\PruneDeadline;
use PHPUnit\Framework\TestCase;

/**
 * D-14 pins for the deadline value object: the five renderings the migrated
 * sites use (byte-for-byte the SQL fragments those sites produced by hand
 * before the engine existed — the shapes are the migration contract), the
 * bound-vs-inlined param law, and the negative-int fail-fast.
 */
final class PruneDeadlineTest extends TestCase
{
    public function testAtMostUnixTimeBindsTheTimestampAsNativeInt(): void
    {
        $deadline = PruneDeadline::atMostUnixTime(1_700_000_123);

        $this->assertSame('<=', $deadline->comparator);
        $this->assertSame('?', $deadline->expression);
        $this->assertSame([1_700_000_123], $deadline->params);
    }

    public function testNotAfterNowRendersTheDbClockWithNoParams(): void
    {
        $deadline = PruneDeadline::notAfterNow();

        $this->assertSame('<=', $deadline->comparator);
        $this->assertSame('NOW()', $deadline->expression);
        $this->assertSame([], $deadline->params);
    }

    public function testBeforeNowMinusSecondsInlinesTheIntervalLiteral(): void
    {
        $deadline = PruneDeadline::beforeNowMinusSeconds(86_400);

        $this->assertSame('<', $deadline->comparator);
        $this->assertSame('NOW() - INTERVAL 86400 SECOND', $deadline->expression);
        $this->assertSame([], $deadline->params);
    }

    public function testBeforeDateSubNowSecondsInlinesThePortableSpelling(): void
    {
        $deadline = PruneDeadline::beforeDateSubNowSeconds(60);

        $this->assertSame('<', $deadline->comparator);
        $this->assertSame('DATE_SUB(NOW(), INTERVAL 60 SECOND)', $deadline->expression);
        $this->assertSame([], $deadline->params);
    }

    public function testBeforeDateSubNowDaysBINDSTheDaysForTheStatsLaw(): void
    {
        $deadline = PruneDeadline::beforeDateSubNowDays(30);

        $this->assertSame('<', $deadline->comparator);
        $this->assertSame('DATE_SUB(NOW(), INTERVAL ? DAY)', $deadline->expression);
        $this->assertSame([30], $deadline->params);
    }

    public function testNegativeTimestampsAndIntervalsFailLoud(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PruneDeadline::atMostUnixTime(-1);
    }

    public function testNegativeSecondsFailLoud(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PruneDeadline::beforeNowMinusSeconds(-1);
    }

    public function testNegativeDateSubSecondsFailLoud(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PruneDeadline::beforeDateSubNowSeconds(-1);
    }

    public function testNegativeDaysFailLoud(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PruneDeadline::beforeDateSubNowDays(-1);
    }
}
