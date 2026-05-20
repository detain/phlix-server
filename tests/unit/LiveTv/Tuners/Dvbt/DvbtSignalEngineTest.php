<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\LiveTv\Tuners\Dvbt;

use PHPUnit\Framework\TestCase;
use Phlix\LiveTv\Tuners\Dvbt\DvbtDevice;
use Phlix\LiveTv\Tuners\Dvbt\DvbtSignalEngine;

class DvbtSignalEngineTest extends TestCase
{
    public function testGetStreamUrlReturnsString(): void
    {
        $engine = new DvbtSignalEngine('/usr/bin/ffmpeg', '/usr/bin/dvbv5-zap');

        $device = new DvbtDevice(
            adapterPath: '/dev/dvb/adapter0',
            adapterIndex: 0,
            frontendIndex: 0,
            modulation: 'auto',
            frequencyMin: 470000000,
            frequencyMax: 862000000
        );

        $streamUrl = $engine->getStreamUrl($device, 1);

        $this->assertIsString($streamUrl);
        $this->assertNotEmpty($streamUrl);
    }

    public function testTuneReturnsIngestUrl(): void
    {
        $engine = new DvbtSignalEngine('/usr/bin/ffmpeg', '/usr/bin/dvbv5-zap');

        $device = new DvbtDevice(
            adapterPath: '/dev/dvb/adapter0',
            adapterIndex: 0,
            frontendIndex: 0,
            modulation: 'auto',
            frequencyMin: 470000000,
            frequencyMax: 862000000
        );

        $ingestUrl = $engine->tune($device, 474000000, 'auto');

        $this->assertIsString($ingestUrl);
    }

    public function testGetSignalStrengthReturnsArray(): void
    {
        $engine = new DvbtSignalEngine('/usr/bin/ffmpeg', '/usr/bin/dvbv5-zap');

        $device = new DvbtDevice(
            adapterPath: '/dev/dvb/adapter0',
            adapterIndex: 0,
            frontendIndex: 0,
            modulation: 'auto',
            frequencyMin: 470000000,
            frequencyMax: 862000000
        );

        $signalStrength = $engine->getSignalStrength($device);

        $this->assertIsArray($signalStrength);
        $this->assertArrayHasKey('signal', $signalStrength);
        $this->assertArrayHasKey('snr', $signalStrength);
        $this->assertArrayHasKey('ber', $signalStrength);
        $this->assertArrayHasKey('ucblocks', $signalStrength);
    }
}
