<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Console\Commands;

use Phlix\Console\Commands\HwaccelProbeCommand;
use Phlix\Media\Transcoding\Hwaccel\HwaccelCapability;
use Phlix\Media\Transcoding\Hwaccel\HwaccelProbe;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \Phlix\Console\Commands\HwaccelProbeCommand
 */
class HwaccelProbeCommandTest extends TestCase
{
    private function tester(HwaccelProbe $probe): CommandTester
    {
        $application = new Application();
        $application->add(new HwaccelProbeCommand(fn(): HwaccelProbe => $probe));

        return new CommandTester($application->find('hwaccel:probe'));
    }

    public function testRendersDetectedCapabilities(): void
    {
        $capability = new HwaccelCapability(
            vendor: 'nvenc',
            encoder: 'h264_nvenc',
            decoder: 'h264_cuvid',
            supports_hdr_tone_mapping: true,
            supported_codecs: ['h264', 'hevc'],
            supported_profiles: ['main', 'high'],
            max_resolution_w: 4096,
            max_resolution_h: 2160,
            max_bitrate: 100000000,
        );

        $probe = $this->createMock(HwaccelProbe::class);
        $probe->expects($this->once())
            ->method('probe')
            ->willReturn(['nvenc' => $capability]);

        $tester = $this->tester($probe);
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('nvenc', $display);
        $this->assertStringContainsString('h264_nvenc', $display);
        $this->assertStringContainsString('h264_cuvid', $display);
        $this->assertStringContainsString('h264, hevc', $display);
    }

    public function testNoVendorsPrintsMessage(): void
    {
        $probe = $this->createMock(HwaccelProbe::class);
        $probe->method('probe')->willReturn([]);

        $tester = $this->tester($probe);
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('No hardware-acceleration vendors detected.', $tester->getDisplay());
    }

    public function testProbeThrowsExitsOne(): void
    {
        $probe = $this->createMock(HwaccelProbe::class);
        $probe->method('probe')->willThrowException(new RuntimeException('ffmpeg missing'));

        $tester = $this->tester($probe);
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Hardware-acceleration probe failed: ffmpeg missing', $tester->getDisplay());
    }
}
