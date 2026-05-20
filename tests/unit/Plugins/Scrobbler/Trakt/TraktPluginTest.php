<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Scrobbler\Trakt;

use Phlix\Plugins\Scrobbler\Trakt\TraktPlugin;
use Phlix\Plugins\Scrobbler\Trakt\TraktSettings;
use Phlix\Shared\Events\Playback\PlaybackStarted;
use Phlix\Shared\Events\Playback\PlaybackStopped;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class TraktPluginTest extends TestCase
{
    public function testSubscribedEventsReturnsExpectedEvents(): void
    {
        $plugin = new TraktPlugin(new TraktSettings(), new NullLogger());

        $events = $plugin->subscribedEvents();

        $this->assertArrayHasKey(PlaybackStarted::class, $events);
        $this->assertArrayHasKey(PlaybackStopped::class, $events);
        $this->assertSame('onPlaybackStarted', $events[PlaybackStarted::class]);
        $this->assertSame('onPlaybackStopped', $events[PlaybackStopped::class]);
    }

    public function testConfigureStoresSettings(): void
    {
        $plugin = new TraktPlugin(new TraktSettings(), new NullLogger());

        $plugin->configure([
            'enabled' => true,
            'access_token' => 'test-access',
            'refresh_token' => 'test-refresh',
            'username' => 'testuser',
            'scrobble_enabled' => true,
        ]);

        $settings = $plugin->getSettings();
        $this->assertSame('testuser', $settings->username);
        $this->assertSame('test-access', $settings->accessToken);
    }

    public function testGetSettingsReturnsCurrentSettings(): void
    {
        $settings = new TraktSettings(
            accessToken: 'stored-access',
            refreshToken: 'stored-refresh',
            username: 'storeduser'
        );
        $plugin = new TraktPlugin($settings, new NullLogger());

        $result = $plugin->getSettings();

        $this->assertSame('stored-access', $result->accessToken);
        $this->assertSame('stored-refresh', $result->refreshToken);
        $this->assertSame('storeduser', $result->username);
    }

    public function testSetAccessToken(): void
    {
        $plugin = new TraktPlugin(new TraktSettings(), new NullLogger());

        $plugin->setAccessToken('new-access-token');

        $settings = $plugin->getSettings();
        $this->assertSame('new-access-token', $settings->accessToken);
    }

    public function testSetRefreshToken(): void
    {
        $plugin = new TraktPlugin(new TraktSettings(), new NullLogger());

        $plugin->setRefreshToken('new-refresh-token');

        $settings = $plugin->getSettings();
        $this->assertSame('new-refresh-token', $settings->refreshToken);
    }

    public function testDefaultSettingsHaveSensibleDefaults(): void
    {
        $plugin = new TraktPlugin(new TraktSettings(), new NullLogger());

        $settings = $plugin->getSettings();

        $this->assertTrue($settings->syncEnabled);
        $this->assertSame(30, $settings->syncIntervalMinutes);
        $this->assertTrue($settings->scrobbleEnabled);
    }

    public function testConfigureWithDisabledScrobble(): void
    {
        $plugin = new TraktPlugin(new TraktSettings(), new NullLogger());

        $plugin->configure([
            'enabled' => true,
            'access_token' => 'test-access',
            'refresh_token' => 'test-refresh',
            'username' => 'testuser',
            'scrobble_enabled' => false,
        ]);

        $settings = $plugin->getSettings();
        $this->assertFalse($settings->scrobbleEnabled);
    }

    public function testConfigureWithDisabledSync(): void
    {
        $plugin = new TraktPlugin(new TraktSettings(), new NullLogger());

        $plugin->configure([
            'enabled' => true,
            'access_token' => 'test-access',
            'refresh_token' => 'test-refresh',
            'username' => 'testuser',
            'sync_enabled' => false,
        ]);

        $settings = $plugin->getSettings();
        $this->assertFalse($settings->syncEnabled);
    }
}
