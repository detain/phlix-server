<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\LiveTv\Tuners\Iptv;

use PHPUnit\Framework\TestCase;
use Phlex\LiveTv\Tuners\Iptv\IptvDevice;
use Phlex\LiveTv\Tuners\Iptv\IptvTunerDriver;
use Phlex\LiveTv\Tuners\Iptv\M3UParser;
use Phlex\LiveTv\Tuners\Iptv\M3UEntry;
use Phlex\LiveTv\Tuners\Iptv\XmlTvParser;
use Phlex\LiveTv\Tuners\Iptv\XmlTvProgramme;

class IptvTunerDriverTest extends TestCase
{
    private IptvDevice $device;
    private M3UParser $m3uParser;
    private XmlTvParser $xmlTvParser;

    protected function setUp(): void
    {
        $this->device = new IptvDevice(
            sourceId: 'iptv_test',
            name: 'Test IPTV',
            playlistUrl: 'http://example.com/playlist.m3u8',
            epgUrl: 'http://example.com/epg.xml',
            isEnabled: true
        );

        $this->m3uParser = $this->createMock(M3UParser::class);
        $this->xmlTvParser = $this->createMock(XmlTvParser::class);
    }

    public function testGetNameReturnsIptv(): void
    {
        $driver = new IptvTunerDriver(
            $this->m3uParser,
            $this->xmlTvParser,
            $this->device
        );

        $this->assertEquals('iptv', $driver->getName());
    }

    public function testDiscoverDevicesReturnsDeviceWhenEnabled(): void
    {
        $driver = new IptvTunerDriver(
            $this->m3uParser,
            $this->xmlTvParser,
            $this->device
        );

        $devices = $driver->discoverDevices();

        $this->assertCount(1, $devices);
        $this->assertSame($this->device, $devices[0]);
    }

    public function testDiscoverDevicesReturnsEmptyWhenDisabled(): void
    {
        $disabledDevice = new IptvDevice(
            sourceId: 'iptv_disabled',
            name: 'Disabled IPTV',
            playlistUrl: 'http://example.com/playlist.m3u8',
            epgUrl: null,
            isEnabled: false
        );

        $driver = new IptvTunerDriver(
            $this->m3uParser,
            $this->xmlTvParser,
            $disabledDevice
        );

        $devices = $driver->discoverDevices();

        $this->assertEmpty($devices);
    }

    public function testGetChannelLineupParsesM3U(): void
    {
        $entries = [
            new M3UEntry(
                url: 'http://example.com/ch1.m3u8',
                name: 'Channel 1',
                tvgId: 1,
                tvgChno: 1,
                group: 'News',
                logo: 'http://example.com/ch1.png',
                isRadio: false
            ),
            new M3UEntry(
                url: 'http://example.com/ch2.m3u8',
                name: 'Channel 2',
                tvgId: 2,
                tvgChno: 2,
                group: 'Sports',
                logo: 'http://example.com/ch2.png',
                isRadio: false
            ),
        ];

        $this->m3uParser->method('parseUrl')
            ->with('http://example.com/playlist.m3u8')
            ->willReturn($entries);

        $driver = new IptvTunerDriver(
            $this->m3uParser,
            $this->xmlTvParser,
            $this->device
        );

        $lineup = $driver->getChannelLineup($this->device);

        $this->assertCount(2, $lineup);
        $this->assertEquals(1, $lineup[0]['channel_number']);
        $this->assertEquals('Channel 1', $lineup[0]['name']);
        $this->assertEquals('off', $lineup[0]['type']);
        $this->assertEquals(2, $lineup[1]['channel_number']);
        $this->assertEquals('Channel 2', $lineup[1]['name']);
    }

    public function testGetChannelLineupUsesTvgChnoWhenAvailable(): void
    {
        $entries = [
            new M3UEntry(
                url: 'http://example.com/ch1.m3u8',
                name: 'Channel Without Number',
                tvgId: null,
                tvgChno: 10,
                group: null,
                logo: null,
                isRadio: false
            ),
        ];

        $this->m3uParser->method('parseUrl')
            ->willReturn($entries);

        $driver = new IptvTunerDriver(
            $this->m3uParser,
            $this->xmlTvParser,
            $this->device
        );

        $lineup = $driver->getChannelLineup($this->device);

        $this->assertCount(1, $lineup);
        $this->assertEquals(10, $lineup[0]['channel_number']);
    }

    public function testGetChannelLineupFallsBackToIndexWhenNoTvgChno(): void
    {
        $entries = [
            new M3UEntry(
                url: 'http://example.com/ch1.m3u8',
                name: 'Channel Without Number',
                tvgId: null,
                tvgChno: null,
                group: null,
                logo: null,
                isRadio: false
            ),
        ];

        $this->m3uParser->method('parseUrl')
            ->willReturn($entries);

        $driver = new IptvTunerDriver(
            $this->m3uParser,
            $this->xmlTvParser,
            $this->device
        );

        $lineup = $driver->getChannelLineup($this->device);

        $this->assertCount(1, $lineup);
        $this->assertEquals(1, $lineup[0]['channel_number']); // Index + 1
    }

    public function testGetStreamUrlReturnsCorrectUrl(): void
    {
        $entries = [
            new M3UEntry(url: 'http://example.com/ch1.m3u8', name: 'Channel 1', tvgChno: 1),
            new M3UEntry(url: 'http://example.com/ch2.m3u8', name: 'Channel 2', tvgChno: 2),
            new M3UEntry(url: 'http://example.com/ch3.m3u8', name: 'Channel 3', tvgChno: 3),
        ];

        $this->m3uParser->method('parseUrl')
            ->willReturn($entries);

        $driver = new IptvTunerDriver(
            $this->m3uParser,
            $this->xmlTvParser,
            $this->device
        );

        $streamUrl = $driver->getStreamUrl($this->device, 2);

        $this->assertEquals('http://example.com/ch2.m3u8', $streamUrl);
    }

    public function testGetStreamUrlFallsBackToIndexBasedMatching(): void
    {
        $entries = [
            new M3UEntry(url: 'http://example.com/first.m3u8', name: 'First'),
            new M3UEntry(url: 'http://example.com/second.m3u8', name: 'Second'),
        ];

        $this->m3uParser->method('parseUrl')
            ->willReturn($entries);

        $driver = new IptvTunerDriver(
            $this->m3uParser,
            $this->xmlTvParser,
            $this->device
        );

        // Channel number 1 = first entry (index 0 + 1)
        $streamUrl = $driver->getStreamUrl($this->device, 1);
        $this->assertEquals('http://example.com/first.m3u8', $streamUrl);
    }

    public function testGetStreamUrlThrowsWhenNoChannels(): void
    {
        $this->m3uParser->method('parseUrl')
            ->willReturn([]);

        $driver = new IptvTunerDriver(
            $this->m3uParser,
            $this->xmlTvParser,
            $this->device
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No channels available');

        $driver->getStreamUrl($this->device, 1);
    }

    public function testScanChannelsFetchesXmltvWhenConfigured(): void
    {
        $entries = [
            new M3UEntry(url: 'http://example.com/ch1.m3u8', name: 'Channel 1', tvgChno: 1),
        ];

        $programmes = [
            new XmlTvProgramme(
                channelId: 'ch1',
                startTime: time(),
                endTime: time() + 3600,
                title: 'Test Show'
            ),
        ];

        $this->m3uParser->method('parseUrl')
            ->willReturn($entries);

        $this->xmlTvParser->method('parseUrl')
            ->with('http://example.com/epg.xml')
            ->willReturn($programmes);

        $driver = new IptvTunerDriver(
            $this->m3uParser,
            $this->xmlTvParser,
            $this->device
        );

        $lineup = $driver->scanChannels($this->device);

        $this->assertCount(1, $lineup);
        $this->assertEquals('Channel 1', $lineup[0]['name']);
    }

    public function testIptvDeviceHasEpd(): void
    {
        $deviceWithEpd = new IptvDevice(
            sourceId: 'test',
            name: 'Test',
            playlistUrl: 'http://example.com/playlist.m3u8',
            epgUrl: 'http://example.com/epg.xml',
            isEnabled: true
        );

        $deviceWithoutEpd = new IptvDevice(
            sourceId: 'test',
            name: 'Test',
            playlistUrl: 'http://example.com/playlist.m3u8',
            epgUrl: null,
            isEnabled: true
        );

        $this->assertTrue($deviceWithEpd->hasEpd());
        $this->assertFalse($deviceWithoutEpd->hasEpd());
    }

    public function testIptvDeviceToArray(): void
    {
        $array = $this->device->toArray();

        $this->assertEquals('iptv_test', $array['source_id']);
        $this->assertEquals('Test IPTV', $array['name']);
        $this->assertEquals('http://example.com/playlist.m3u8', $array['playlist_url']);
        $this->assertEquals('http://example.com/epg.xml', $array['epg_url']);
        $this->assertTrue($array['is_enabled']);
    }
}
