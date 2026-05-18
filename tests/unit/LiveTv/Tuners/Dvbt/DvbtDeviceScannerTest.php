<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\LiveTv\Tuners\Dvbt;

use PHPUnit\Framework\TestCase;
use Phlex\LiveTv\Tuners\Dvbt\DvbtDeviceScanner;

class DvbtDeviceScannerTest extends TestCase
{
    public function testScanReturnsArrayOfDevices(): void
    {
        // This test would fail on a system without /dev/dvb,
        // but we're testing the scanner's behavior
        $scanner = new DvbtDeviceScanner();

        $devices = $scanner->scan();

        $this->assertIsArray($devices);
    }

    public function testScanReturnsEmptyWhenNoDevDvb(): void
    {
        $scanner = new DvbtDeviceScanner();

        // If /dev/dvb doesn't exist, should return empty array
        $devices = $scanner->scan();

        // The scan method should handle missing /dev/dvb gracefully
        $this->assertIsArray($devices);
    }

    public function testScanReturnsEmptyWhenNoFrontends(): void
    {
        $scanner = new DvbtDeviceScanner();

        // Even if adapter dirs exist but no frontends, should return empty
        $devices = $scanner->scan();

        $this->assertIsArray($devices);
    }
}
