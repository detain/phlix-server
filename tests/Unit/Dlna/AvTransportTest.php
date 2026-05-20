<?php

namespace Phlix\Tests\Unit\Dlna;

use PHPUnit\Framework\TestCase;
use Phlix\Dlna\AvTransport;
use Phlix\Dlna\TransportState;

class AvTransportTest extends TestCase
{
    private AvTransport $avTransport;

    protected function setUp(): void
    {
        $this->avTransport = new AvTransport();
    }

    public function testGetInstance(): void
    {
        $instance = $this->avTransport->getInstance(0);

        $this->assertInstanceOf(TransportState::class, $instance);
        $this->assertEquals(0, $instance->getInstanceId());
    }

    public function testGetMultipleInstances(): void
    {
        $instance0 = $this->avTransport->getInstance(0);
        $instance1 = $this->avTransport->getInstance(1);
        $instance2 = $this->avTransport->getInstance(2);

        $this->assertNotSame($instance0, $instance1);
        $this->assertNotSame($instance1, $instance2);
    }

    public function testSetAvTransportUri(): void
    {
        $result = $this->avTransport->setAvTransportUri(0, 'http://example.com/media.mp4');

        $this->assertArrayHasKey('CurrentState', $result);
        $this->assertEquals(AvTransport::TRANSPORT_STATE_STOPPED, $result['CurrentState']);

        $instance = $this->avTransport->getInstance(0);
        $this->assertEquals('http://example.com/media.mp4', $instance->getMediaUri());
        $this->assertEquals(AvTransport::TRANSPORT_STATE_STOPPED, $instance->getTransportState());
    }

    public function testPlay(): void
    {
        // First set URI
        $this->avTransport->setAvTransportUri(0, 'http://example.com/media.mp4');

        $result = $this->avTransport->play(0);

        $this->assertArrayHasKey('CurrentState', $result);
        $this->assertEquals(AvTransport::TRANSPORT_STATE_PLAYING, $result['CurrentState']);

        $instance = $this->avTransport->getInstance(0);
        $this->assertTrue($instance->isPlaying());
    }

    public function testPlayWithoutUriReturnsError(): void
    {
        // Get a fresh instance without setting URI
        $result = $this->avTransport->play(99);

        $this->assertArrayHasKey('Error', $result);
        $this->assertEquals(702, $result['Error']['code']);
    }

    public function testPause(): void
    {
        // Set URI and start playing
        $this->avTransport->setAvTransportUri(0, 'http://example.com/media.mp4');
        $this->avTransport->play(0);

        $result = $this->avTransport->pause(0);

        $this->assertArrayHasKey('CurrentState', $result);
        $this->assertEquals(AvTransport::TRANSPORT_STATE_PAUSED, $result['CurrentState']);

        $instance = $this->avTransport->getInstance(0);
        $this->assertTrue($instance->isPaused());
    }

    public function testStop(): void
    {
        // Set URI and start playing
        $this->avTransport->setAvTransportUri(0, 'http://example.com/media.mp4');
        $this->avTransport->play(0);

        $result = $this->avTransport->stop(0);

        $this->assertArrayHasKey('CurrentState', $result);
        $this->assertEquals(AvTransport::TRANSPORT_STATE_STOPPED, $result['CurrentState']);

        $instance = $this->avTransport->getInstance(0);
        $this->assertTrue($instance->isStopped());
        $this->assertEquals(0, $instance->getPosition());
    }

    public function testSeek(): void
    {
        // Set URI with metadata containing duration
        $metadata = '<DIDL-Lite><item><upnp:duration>01:00:00</upnp:duration></item></DIDL-Lite>';
        $this->avTransport->setAvTransportUri(0, 'http://example.com/media.mp4', $metadata);
        $this->avTransport->play(0);

        $result = $this->avTransport->seek(0, 'REL_TIME', '00:05:30');

        $this->assertArrayHasKey('CurrentState', $result);
        $this->assertEquals(AvTransport::TRANSPORT_STATE_PLAYING, $result['CurrentState']);

        $instance = $this->avTransport->getInstance(0);
        // 5 minutes 30 seconds = 330 seconds = 3300000000 ticks
        $this->assertEquals(3300000000, $instance->getPosition());
    }

    public function testSeekBeyondDuration(): void
    {
        // Set URI with metadata containing duration
        $metadata = '<DIDL-Lite><item><upnp:duration>00:01:00</upnp:duration></item></DIDL-Lite>';
        $this->avTransport->setAvTransportUri(0, 'http://example.com/media.mp4', $metadata);
        $this->avTransport->play(0);

        // Seek beyond the duration (should be clamped)
        $result = $this->avTransport->seek(0, 'REL_TIME', '00:05:00');

        // Position should be clamped to duration
        $instance = $this->avTransport->getInstance(0);
        $this->assertLessThanOrEqual($instance->getMediaDuration(), $instance->getPosition());
    }

    public function testGetTransportInfo(): void
    {
        $this->avTransport->setAvTransportUri(0, 'http://example.com/media.mp4');
        $this->avTransport->play(0);

        $info = $this->avTransport->getTransportInfo(0);

        $this->assertArrayHasKey('CurrentTransportState', $info);
        $this->assertArrayHasKey('CurrentTransportStatus', $info);
        $this->assertArrayHasKey('CurrentSpeed', $info);
        $this->assertEquals(AvTransport::TRANSPORT_STATE_PLAYING, $info['CurrentTransportState']);
        $this->assertEquals('1', $info['CurrentSpeed']);
    }

    public function testGetPositionInfo(): void
    {
        $metadata = '<DIDL-Lite><item><upnp:duration>01:30:00</upnp:duration></item></DIDL-Lite>';
        $this->avTransport->setAvTransportUri(0, 'http://example.com/media.mp4', $metadata);
        $this->avTransport->play(0);
        $this->avTransport->seek(0, 'REL_TIME', '00:10:00');

        $info = $this->avTransport->getPositionInfo(0);

        $this->assertArrayHasKey('Track', $info);
        $this->assertArrayHasKey('TrackDuration', $info);
        $this->assertArrayHasKey('TrackURI', $info);
        $this->assertArrayHasKey('RelTime', $info);
        $this->assertEquals('01:30:00', $info['TrackDuration']);
        $this->assertEquals('00:10:00', $info['RelTime']);
    }

    public function testGetMediaInfo(): void
    {
        $this->avTransport->setAvTransportUri(0, 'http://example.com/media.mp4');

        $info = $this->avTransport->getMediaInfo(0);

        $this->assertArrayHasKey('NrTracks', $info);
        $this->assertArrayHasKey('CurrentURI', $info);
        $this->assertArrayHasKey('PlayMedium', $info);
        $this->assertEquals('http://example.com/media.mp4', $info['CurrentURI']);
        $this->assertEquals('NETWORK', $info['PlayMedium']);
    }

    public function testGetDeviceCapabilities(): void
    {
        $caps = $this->avTransport->getDeviceCapabilities(0);

        $this->assertArrayHasKey('PlayMedia', $caps);
        $this->assertArrayHasKey('RecMedia', $caps);
        $this->assertArrayHasKey('RecQualityModes', $caps);
        $this->assertStringContainsString('NETWORK', $caps['PlayMedia']);
    }

    public function testGetTransportSettings(): void
    {
        $settings = $this->avTransport->getTransportSettings(0);

        $this->assertArrayHasKey('PlayMode', $settings);
        $this->assertArrayHasKey('RecQualityMode', $settings);
        $this->assertEquals('NORMAL', $settings['PlayMode']);
    }

    public function testSetPlayMode(): void
    {
        $this->avTransport->setAvTransportUri(0, 'http://example.com/media.mp4');

        $result = $this->avTransport->setPlayMode(0, 'REPEAT_ALL');

        $this->assertEmpty($result); // Success returns empty array

        $instance = $this->avTransport->getInstance(0);
        $this->assertEquals('REPEAT_ALL', $instance->getPlayMode());
    }

    public function testSetInvalidPlayMode(): void
    {
        $this->avTransport->setAvTransportUri(0, 'http://example.com/media.mp4');

        $result = $this->avTransport->setPlayMode(0, 'INVALID_MODE');

        $this->assertArrayHasKey('Error', $result);
        $this->assertEquals(701, $result['Error']['code']);
    }

    public function testGetCurrentTransportActions(): void
    {
        // When stopped
        $this->avTransport->setAvTransportUri(0, 'http://example.com/media.mp4');

        $actions = $this->avTransport->getCurrentTransportActions(0);
        $this->assertStringContainsString('Play', $actions['Actions']);
        $this->assertStringNotContainsString('Pause', $actions['Actions']);

        // When playing
        $this->avTransport->play(0);

        $actions = $this->avTransport->getCurrentTransportActions(0);
        $this->assertStringContainsString('Pause', $actions['Actions']);
        $this->assertStringContainsString('Stop', $actions['Actions']);
        $this->assertStringContainsString('Seek', $actions['Actions']);

        // When paused
        $this->avTransport->pause(0);

        $actions = $this->avTransport->getCurrentTransportActions(0);
        $this->assertStringContainsString('Play', $actions['Actions']);
        $this->assertStringContainsString('Stop', $actions['Actions']);
    }

    public function testGetScpdXml(): void
    {
        $scpd = $this->avTransport->getScpdXml();

        $this->assertStringContainsString('scpd', $scpd);
        $this->assertStringContainsString('specVersion', $scpd);
        $this->assertStringContainsString('actionList', $scpd);
        $this->assertStringContainsString('serviceStateTable', $scpd);
        $this->assertStringContainsString('SetAVTransportURI', $scpd);
        $this->assertStringContainsString('Play', $scpd);
        $this->assertStringContainsString('Pause', $scpd);
        $this->assertStringContainsString('Stop', $scpd);
        $this->assertStringContainsString('Seek', $scpd);
    }

    public function testPlaySpeed(): void
    {
        $this->avTransport->setAvTransportUri(0, 'http://example.com/media.mp4');

        $result = $this->avTransport->play(0, '2'); // 2x speed

        $instance = $this->avTransport->getInstance(0);
        $this->assertEquals('2', $instance->getPlaybackSpeed());
    }
}
