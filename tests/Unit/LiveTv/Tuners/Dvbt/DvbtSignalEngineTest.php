<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\LiveTv\Tuners\Dvbt;

use PHPUnit\Framework\TestCase;
use Phlix\LiveTv\Tuners\Dvbt\DvbtDevice;
use Phlix\LiveTv\Tuners\Dvbt\DvbtSignalEngine;
use ReflectionMethod;

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

        $this->assertNotEmpty($streamUrl);
        $this->assertSame('/dev/dvb/adapter0/dvr0', $streamUrl);
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

        $this->assertNotEmpty($ingestUrl);
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

        $this->assertCount(4, $signalStrength);
        $this->assertArrayHasKey('signal', $signalStrength);
        $this->assertArrayHasKey('snr', $signalStrength);
        $this->assertArrayHasKey('ber', $signalStrength);
        $this->assertArrayHasKey('ucblocks', $signalStrength);
    }

    /**
     * SH-018 (secaudit finding 2): both call sites used to write a fully
     * PREDICTABLE `/tmp/dvbt_stream_{adapter}_{frontend}.ts` — on a host with
     * local co-tenants that is a symlink-preemption target — and the live
     * builder interpolated the configured binary path into a shell string
     * RAW while the sibling builder escaped it. The cure: tempnam-allocated
     * unpredictable 0600 files + shell-free argv-array proc_open.
     */
    public function testThePredictableStreamPathIsExtinctFromTheEngine(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 5) . '/src/LiveTv/Tuners/Dvbt/DvbtSignalEngine.php'
        );
        $this->assertIsString($source);
        $this->assertStringNotContainsString(
            '/tmp/dvbt_stream_',
            $source,
            'SH-018: the predictable stream path template must never return'
        );
    }

    public function testTuneReturnsUnpredictablePlaceholderPathNotALiveFile(): void
    {
        // A real (executable) binary path keeps tune() on the placeholder
        // branch; without one it takes the DVR fallback, which is legacy
        // behavior this cure deliberately does not touch.
        $fakeZap = sys_get_temp_dir() . '/phlix-sh018-tune-zap-' . posix_getpid();
        file_put_contents($fakeZap, "#!/bin/sh\nexit 0\n");
        chmod($fakeZap, 0755);

        try {
            $this->assertTunePlaceholderShape(new DvbtSignalEngine('/usr/bin/ffmpeg', $fakeZap));
        } finally {
            @unlink($fakeZap);
        }
    }

    private function assertTunePlaceholderShape(DvbtSignalEngine $engine): void
    {
        $device = $this->device();

        $url1 = $engine->tune($device, 474000000, 'auto');
        $url2 = $engine->tune($device, 480000000, 'auto');

        $this->assertStringStartsWith('pipe:///tmp/', $url1);
        $this->assertMatchesRegularExpression(
            '#^pipe:///.*phlix-dvbt-a0-f0-.+$#',
            $url1,
            'the placeholder must be a tempnam-allocated unpredictable path'
        );
        $this->assertNotSame($url1, $url2, 'successive tunes must not reuse one predictable path');
        $this->assertFileDoesNotExist(
            substr($url1, strlen('pipe://')),
            'tune() launches nothing, so its probe file is released — no per-tune temp leak'
        );
    }

    public function testZapArgvCarriesTheConfiguredBinaryAsOneOpaqueElement(): void
    {
        // The shell-free guarantee: a binary path FULL of shell metacharsers
        // reaches the child as argv[0] verbatim — nothing to escape because
        // no shell ever parses it. Pre-cure, this exact string was sprintf'd
        // raw into a shell command.
        $poison = '/usr/bin/false; touch /tmp/phlix-sh018-pwn';
        $engine = new DvbtSignalEngine('/usr/bin/ffmpeg', $poison);
        $device = $this->device();

        $method = new ReflectionMethod($engine, 'zapArgv');
        $argv = $method->invoke($engine, $device, 474000000, 'QAM64', '/tmp/whatever');

        $this->assertIsArray($argv);
        $this->assertSame($poison, $argv[0]);
        $this->assertCount(13, $argv);
        $this->assertFileDoesNotExist('/tmp/phlix-sh018-pwn');
    }

    public function testStartStreamingLaunchesShellFreeWritesPrivateFileAndStopRemovesIt(): void
    {
        $fakeZap = sys_get_temp_dir() . '/phlix-sh018-fake-zap-' . posix_getpid();
        file_put_contents($fakeZap, "#!/bin/sh\nprintf 'ts-bytes\\n'\n");
        chmod($fakeZap, 0755);

        try {
            $engine = new DvbtSignalEngine('/usr/bin/ffmpeg', $fakeZap);
            $device = $this->device();

            $stream = $engine->startStreaming($device, 474000000, 'auto');
            $path = substr($stream['ingest_url'], strlen('file://'));

            $this->assertIsResource($stream['process']);
            $this->assertMatchesRegularExpression('#phlix-dvbt-a0-f0-#', $path);

            // Wait (bounded) for the child's stdout to land in the file.
            $content = '';
            for ($i = 0; $i < 100 && $content === ''; $i++) {
                $content = (string) @file_get_contents($path);
                if ($content === '') {
                    usleep(20_000);
                }
            }
            $this->assertSame("ts-bytes\n", $content, 'the argv-launched child writes through the descriptor');
            clearstatcache();
            $this->assertSame(
                0600,
                fileperms($path) & 0777,
                'the stream file is private from birth regardless of umask'
            );

            $engine->stopStreaming($stream['process']);
            $this->assertFileDoesNotExist($path, 'stopStreaming unlinks the file its process owned');
        } finally {
            @unlink($fakeZap);
        }
    }

    private function device(): DvbtDevice
    {
        return new DvbtDevice(
            adapterPath: '/dev/dvb/adapter0',
            adapterIndex: 0,
            frontendIndex: 0,
            modulation: 'auto',
            frequencyMin: 470000000,
            frequencyMax: 862000000
        );
    }
}
