<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\LiveTv\Tuners\Dvbt;

use PHPUnit\Framework\TestCase;
use Phlex\LiveTv\Tuners\Dvbt\DvbtDevice;
use Phlex\LiveTv\Tuners\Dvbt\DvbtDeviceScanner;
use Phlex\LiveTv\Tuners\Dvbt\DvbtSignalEngine;
use Phlex\LiveTv\Tuners\Dvbt\DvbtTunerDriver;

class DvbtTunerDriverTest extends TestCase
{
    public function testGetNameReturnsDvbt(): void
    {
        $scanner = new DvbtDeviceScanner();
        $signalEngine = new DvbtSignalEngine('/usr/bin/ffmpeg', '/usr/bin/dvbv5-zap');
        $driver = new DvbtTunerDriver($scanner, $signalEngine);

        $this->assertEquals('dvbt', $driver->getName());
    }

    public function testDiscoverDevicesDelegatesToScanner(): void
    {
        $scanner = $this->createMock(DvbtDeviceScanner::class);

        $device = new DvbtDevice(
            adapterPath: '/dev/dvb/adapter0',
            adapterIndex: 0,
            frontendIndex: 0,
            modulation: 'auto',
            frequencyMin: 470000000,
            frequencyMax: 862000000
        );

        $scanner->method('scan')
            ->willReturn([$device]);

        $signalEngine = new DvbtSignalEngine('/usr/bin/ffmpeg', '/usr/bin/dvbv5-zap');
        $driver = new DvbtTunerDriver($scanner, $signalEngine);

        $devices = $driver->discoverDevices();

        $this->assertCount(1, $devices);
        $this->assertSame($device, $devices[0]);
    }

    public function testGetStreamUrlDelegatesToSignalEngine(): void
    {
        $scanner = new DvbtDeviceScanner();
        $signalEngine = $this->createMock(DvbtSignalEngine::class);

        $device = new DvbtDevice(
            adapterPath: '/dev/dvb/adapter0',
            adapterIndex: 0,
            frontendIndex: 0,
            modulation: 'auto',
            frequencyMin: 470000000,
            frequencyMax: 862000000
        );

        $expectedUrl = '/dev/dvb/adapter0/dvr0';
        $signalEngine->method('getStreamUrl')
            ->with($device, 1)
            ->willReturn($expectedUrl);

        $driver = new DvbtTunerDriver($scanner, $signalEngine);

        $streamUrl = $driver->getStreamUrl($device, 1);

        $this->assertEquals($expectedUrl, $streamUrl);
    }

    public function testDiscoverDevicesReturnsEmptyWhenNoDevices(): void
    {
        $scanner = $this->createMock(DvbtDeviceScanner::class);
        $scanner->method('scan')
            ->willReturn([]);

        $signalEngine = new DvbtSignalEngine('/usr/bin/ffmpeg', '/usr/bin/dvbv5-zap');
        $driver = new DvbtTunerDriver($scanner, $signalEngine);

        $devices = $driver->discoverDevices();

        $this->assertIsArray($devices);
        $this->assertEmpty($devices);
    }

    public function testGetChannelLineupReturnsEmptyArray(): void
    {
        $scanner = new DvbtDeviceScanner();
        $signalEngine = new DvbtSignalEngine('/usr/bin/ffmpeg', '/usr/bin/dvbv5-zap');
        $driver = new DvbtTunerDriver($scanner, $signalEngine);

        $device = new DvbtDevice(
            adapterPath: '/dev/dvb/adapter0',
            adapterIndex: 0,
            frontendIndex: 0,
            modulation: 'auto',
            frequencyMin: 470000000,
            frequencyMax: 862000000
        );

        $lineup = $driver->getChannelLineup($device);

        $this->assertIsArray($lineup);
        $this->assertEmpty($lineup);
    }

    public function testScanChannelsReturnsEmptyArray(): void
    {
        $scanner = new DvbtDeviceScanner();
        $signalEngine = new DvbtSignalEngine('/usr/bin/ffmpeg', '/usr/bin/dvbv5-zap');
        $driver = new DvbtTunerDriver($scanner, $signalEngine);

        $device = new DvbtDevice(
            adapterPath: '/dev/dvb/adapter0',
            adapterIndex: 0,
            frontendIndex: 0,
            modulation: 'auto',
            frequencyMin: 470000000,
            frequencyMax: 862000000
        );

        $channels = $driver->scanChannels($device);

        $this->assertIsArray($channels);
        $this->assertEmpty($channels);
    }

    public function testGetStreamUrlThrowsOnInvalidDevice(): void
    {
        $scanner = new DvbtDeviceScanner();
        $signalEngine = new DvbtSignalEngine('/usr/bin/ffmpeg', '/usr/bin/dvbv5-zap');
        $driver = new DvbtTunerDriver($scanner, $signalEngine);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected DvbtDevice for DVB-T tuner');

        // Use an HDHomeRunDevice which is not a DvbtDevice
        // The driver should throw InvalidArgumentException
        $invalidDevice = new \Phlex\LiveTv\Tuners\HdHomeRun\HdHomeRunDevice(
            deviceId: '12345678',
            ipAddress: '192.168.1.100',
            tunerCount: 2,
            lineupUrl: 'http://192.168.1.100/lineup.json'
        );

        $driver->getStreamUrl($invalidDevice, 1);
    }
}
