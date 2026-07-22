<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Subtitles;

use Phlix\Media\Subtitles\Quota\SubtitleProviderQuotaRepository;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * @covers \Phlix\Media\Subtitles\Quota\SubtitleProviderQuotaRepository
 */
final class SubtitleProviderQuotaRepositoryTest extends TestCase
{
    public function testGetReturnsNullWhenNoRow(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $repo = new SubtitleProviderQuotaRepository($db);
        $this->assertNull($repo->get('opensubtitles'));
        $this->assertFalse($repo->isExhausted('opensubtitles'));
    }

    public function testGetHydratesRow(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([[
            'provider' => 'opensubtitles',
            'downloads_remaining' => '0',
            'reset_time_utc' => '2999-01-01T00:00:00+00:00',
            'updated_at' => '2026-07-22 00:00:00',
        ]]);

        $row = (new SubtitleProviderQuotaRepository($db))->get('opensubtitles');

        $this->assertNotNull($row);
        $this->assertSame('opensubtitles', $row['provider']);
        $this->assertSame(0, $row['downloads_remaining']);
        $this->assertSame('2999-01-01T00:00:00+00:00', $row['reset_time_utc']);
    }

    public function testIsExhaustedTrueWhenRemainingZeroAndResetInFuture(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([[
            'provider' => 'opensubtitles',
            'downloads_remaining' => 0,
            'reset_time_utc' => '2999-01-01T00:00:00+00:00',
        ]]);

        $this->assertTrue((new SubtitleProviderQuotaRepository($db))->isExhausted('opensubtitles'));
    }

    public function testIsExhaustedFalseOnceResetHasPassed(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([[
            'provider' => 'opensubtitles',
            'downloads_remaining' => 0,
            'reset_time_utc' => '2000-01-01T00:00:00+00:00',
        ]]);

        $this->assertFalse(
            (new SubtitleProviderQuotaRepository($db))->isExhausted('opensubtitles'),
            'a reset time in the past means the quota window rolled over',
        );
    }

    public function testIsExhaustedFalseWhenRemainingPositive(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([[
            'provider' => 'opensubtitles',
            'downloads_remaining' => 12,
            'reset_time_utc' => null,
        ]]);

        $this->assertFalse((new SubtitleProviderQuotaRepository($db))->isExhausted('opensubtitles'));
    }

    public function testRecordQuotaExceededUpsertsWithProvidedValues(): void
    {
        $captured = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(function (string $sql, array $params = []) use (&$captured) {
            $captured[] = ['sql' => $sql, 'params' => $params];
            return [];
        });

        (new SubtitleProviderQuotaRepository($db))
            ->recordQuotaExceeded('opensubtitles', 0, '2999-01-01T00:00:00+00:00');

        $this->assertCount(1, $captured);
        $this->assertStringContainsString('INSERT INTO subtitle_provider_quota', $captured[0]['sql']);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $captured[0]['sql']);
        $this->assertSame(
            ['opensubtitles', 0, '2999-01-01T00:00:00+00:00'],
            $captured[0]['params'],
        );
    }

    public function testRecordQuotaExceededStoresZeroWhenRemainingUnknown(): void
    {
        $captured = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(function (string $sql, array $params = []) use (&$captured) {
            $captured[] = $params;
            return [];
        });

        (new SubtitleProviderQuotaRepository($db))->recordQuotaExceeded('subscene', null, null);

        // null remaining -> stored as 0 so isExhausted() treats it as out-of-quota.
        $this->assertSame(['subscene', 0, null], $captured[0]);
    }

    public function testRecordSuccessClearsExhaustion(): void
    {
        $captured = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(function (string $sql, array $params = []) use (&$captured) {
            $captured[] = $params;
            return [];
        });

        (new SubtitleProviderQuotaRepository($db))->recordSuccess('opensubtitles');

        $this->assertSame(['opensubtitles', null, null], $captured[0]);
    }
}
