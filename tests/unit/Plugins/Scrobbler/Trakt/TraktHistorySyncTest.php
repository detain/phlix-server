<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Trakt;

use Phlix\Plugins\Scrobbler\Trakt\TraktSettings;
use PHPUnit\Framework\TestCase;

/**
 * Tests for TraktSettings behavior related to history sync.
 *
 * Note: Full TraktHistorySync testing requires mocking TraktApi and WatchHistory
 * which are final classes. These tests focus on the settings-driven behavior.
 */
final class TraktHistorySyncTest extends TestCase
{
    public function testSyncDisabledInSettings(): void
    {
        $settings = new TraktSettings(
            accessToken: 'test-access',
            refreshToken: 'test-refresh',
            username: 'testuser',
            syncEnabled: false
        );

        $this->assertFalse($settings->syncEnabled);
    }

    public function testSyncEnabledByDefault(): void
    {
        $settings = new TraktSettings(
            accessToken: 'test-access',
            refreshToken: 'test-refresh',
            username: 'testuser'
        );

        $this->assertTrue($settings->syncEnabled);
    }

    public function testSyncIntervalDefault(): void
    {
        $settings = new TraktSettings(
            accessToken: 'test-access',
            refreshToken: 'test-refresh',
            username: 'testuser'
        );

        $this->assertSame(30, $settings->syncIntervalMinutes);
    }

    public function testSyncIntervalCustom(): void
    {
        $settings = new TraktSettings(
            accessToken: 'test-access',
            refreshToken: 'test-refresh',
            username: 'testuser',
            syncIntervalMinutes: 60
        );

        $this->assertSame(60, $settings->syncIntervalMinutes);
    }

    public function testFromArraySyncSettings(): void
    {
        $data = [
            'access_token' => 'test-access',
            'refresh_token' => 'test-refresh',
            'username' => 'testuser',
            'sync_enabled' => true,
            'sync_interval_minutes' => 45,
            'scrobble_enabled' => true,
        ];

        $settings = TraktSettings::fromArray($data);

        $this->assertTrue($settings->syncEnabled);
        $this->assertSame(45, $settings->syncIntervalMinutes);
    }

    public function testScrobbleEnabledDefault(): void
    {
        $settings = new TraktSettings(
            accessToken: 'test-access',
            refreshToken: 'test-refresh',
            username: 'testuser'
        );

        $this->assertTrue($settings->scrobbleEnabled);
    }

    public function testScrobbleEnabledCanBeDisabled(): void
    {
        $data = [
            'access_token' => 'test-access',
            'refresh_token' => 'test-refresh',
            'username' => 'testuser',
            'scrobble_enabled' => false,
        ];

        $settings = TraktSettings::fromArray($data);

        $this->assertFalse($settings->scrobbleEnabled);
    }
}
