<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Trakt;

use Phlix\Plugins\Scrobbler\Trakt\TraktSettings;
use PHPUnit\Framework\TestCase;

final class TraktSettingsTest extends TestCase
{
    public function testConstructorStoresAllValues(): void
    {
        $settings = new TraktSettings(
            accessToken: 'test-access-token',
            refreshToken: 'test-refresh-token',
            expiresAt: 1699999999,
            syncEnabled: true,
            syncIntervalMinutes: 60,
            scrobbleEnabled: false,
            username: 'testuser'
        );

        $this->assertSame('test-access-token', $settings->accessToken);
        $this->assertSame('test-refresh-token', $settings->refreshToken);
        $this->assertSame(1699999999, $settings->expiresAt);
        $this->assertTrue($settings->syncEnabled);
        $this->assertSame(60, $settings->syncIntervalMinutes);
        $this->assertFalse($settings->scrobbleEnabled);
        $this->assertSame('testuser', $settings->username);
    }

    public function testDefaultValues(): void
    {
        $settings = new TraktSettings();

        $this->assertNull($settings->accessToken);
        $this->assertNull($settings->refreshToken);
        $this->assertNull($settings->expiresAt);
        $this->assertTrue($settings->syncEnabled);
        $this->assertSame(30, $settings->syncIntervalMinutes);
        $this->assertTrue($settings->scrobbleEnabled);
        $this->assertSame('', $settings->username);
    }

    public function testFromArray(): void
    {
        $data = [
            'access_token' => 'array-access',
            'refresh_token' => 'array-refresh',
            'expires_at' => 1700000000,
            'sync_enabled' => false,
            'sync_interval_minutes' => 45,
            'scrobble_enabled' => false,
            'username' => 'arrayuser',
        ];

        $settings = TraktSettings::fromArray($data);

        $this->assertSame('array-access', $settings->accessToken);
        $this->assertSame('array-refresh', $settings->refreshToken);
        $this->assertSame(1700000000, $settings->expiresAt);
        $this->assertFalse($settings->syncEnabled);
        $this->assertSame(45, $settings->syncIntervalMinutes);
        $this->assertFalse($settings->scrobbleEnabled);
        $this->assertSame('arrayuser', $settings->username);
    }

    public function testToArray(): void
    {
        $settings = new TraktSettings(
            accessToken: 'toarray-access',
            refreshToken: 'toarray-refresh',
            expiresAt: 1700000000,
            syncEnabled: true,
            syncIntervalMinutes: 30,
            scrobbleEnabled: true,
            username: 'toarrayuser'
        );

        $array = $settings->toArray();

        $this->assertSame('toarray-access', $array['access_token']);
        $this->assertSame('toarray-refresh', $array['refresh_token']);
        $this->assertSame(1700000000, $array['expires_at']);
        $this->assertTrue($array['sync_enabled']);
        $this->assertSame(30, $array['sync_interval_minutes']);
        $this->assertTrue($array['scrobble_enabled']);
        $this->assertSame('toarrayuser', $array['username']);
    }

    public function testHasTokens(): void
    {
        $settingsWithTokens = new TraktSettings(
            accessToken: 'access',
            refreshToken: 'refresh'
        );
        $settingsWithoutAccess = new TraktSettings(
            accessToken: null,
            refreshToken: 'refresh'
        );
        $settingsWithoutRefresh = new TraktSettings(
            accessToken: 'access',
            refreshToken: null
        );
        $settingsWithoutTokens = new TraktSettings();

        $this->assertTrue($settingsWithTokens->hasTokens());
        $this->assertFalse($settingsWithoutAccess->hasTokens());
        $this->assertFalse($settingsWithoutRefresh->hasTokens());
        $this->assertFalse($settingsWithoutTokens->hasTokens());
    }

    public function testIsConfigured(): void
    {
        $configured = new TraktSettings(
            accessToken: 'access',
            refreshToken: 'refresh',
            username: 'testuser'
        );
        $noUsername = new TraktSettings(
            accessToken: 'access',
            refreshToken: 'refresh',
            username: ''
        );
        $noTokens = new TraktSettings(username: 'testuser');

        $this->assertTrue($configured->isConfigured());
        $this->assertFalse($noUsername->isConfigured());
        $this->assertFalse($noTokens->isConfigured());
    }

    public function testIsTokenExpiredWhenNoToken(): void
    {
        $settings = new TraktSettings();
        $this->assertTrue($settings->isTokenExpired());
    }

    public function testIsTokenExpiredWhenExpired(): void
    {
        $settings = new TraktSettings(
            accessToken: 'test',
            expiresAt: time() - 3600
        );
        $this->assertTrue($settings->isTokenExpired());
    }

    public function testIsTokenValidWhenNotExpired(): void
    {
        $settings = new TraktSettings(
            accessToken: 'test',
            expiresAt: time() + 3600
        );
        $this->assertFalse($settings->isTokenExpired());
    }
}
