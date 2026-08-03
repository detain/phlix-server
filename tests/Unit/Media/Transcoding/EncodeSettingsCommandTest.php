<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use Phlix\Media\Transcoding\EncodeSettings;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\Hwaccel\HwaccelCapability;
use Phlix\Media\Transcoding\Hwaccel\HwaccelRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Proves the encode settings reach the actual ffmpeg command line, and — just
 * as important — that at the shipped defaults the command is UNCHANGED.
 *
 * {@see EncodeSettingsTest} covers the policy object. This file covers the
 * consequence, because "the value resolves" was exactly the standard that let
 * a third of this project's settings ship without a consumer.
 *
 * The hardware path gets its own tests for a specific reason: it hardcoded
 * per-vendor presets and never consulted `$params['preset']` at all, so a
 * preset control wired only through the software path would be a control that
 * silently does nothing on any box with a GPU — while appearing to work in
 * every unit test of the software builder.
 *
 */
final class EncodeSettingsCommandTest extends TestCase
{
    private function runner(): FfmpegRunner
    {
        return new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', sys_get_temp_dir());
    }

    /**
     * Build a software segment command with `$params` merged over a minimal
     * valid base.
     *
     * @param array<string, mixed> $params
     */
    private function softwareCommand(array $params): string
    {
        $base = [
            'video_codec' => 'libx264',
            'audio_codec' => 'aac',
            'pix_fmt' => 'yuv420p',
        ];

        return $this->invokeBuilder('buildSegmentCommand', array_merge($base, $params));
    }

    /**
     * @param array<string, mixed> $params
     */
    private function invokeBuilder(string $method, array $params): string
    {
        $ref = new \ReflectionMethod(FfmpegRunner::class, $method);
        $ref->setAccessible(true);

        $result = $ref->invoke($this->runner(), '/tmp/in.mkv', '/tmp/out.ts', 0.0, 6.0, $params);

        $this->assertIsString($result);

        return $result;
    }

    // ─────────────────────────────────────────────────────────────────
    // software path
    // ─────────────────────────────────────────────────────────────────

    public function test_a_configured_preset_reaches_the_software_command(): void
    {
        $cmd = $this->softwareCommand(['preset' => 'slow']);

        $this->assertStringContainsString('-preset slow', $cmd);
        $this->assertStringNotContainsString('-preset veryfast', $cmd);
    }

    public function test_a_configured_crf_reaches_the_software_command(): void
    {
        $cmd = $this->softwareCommand(['crf' => 20]);

        $this->assertStringContainsString('-crf 20', $cmd);
        $this->assertStringNotContainsString('-crf 23', $cmd);
    }

    public function test_a_configured_audio_bitrate_reaches_the_software_command(): void
    {
        $cmd = $this->softwareCommand(['audio_bitrate' => '192k']);

        $this->assertStringContainsString('192k', $cmd);
        $this->assertStringNotContainsString('128k', $cmd);
    }

    public function test_the_shipped_defaults_produce_the_historical_flags(): void
    {
        // The safety property: deploying this feature must not change a single
        // byte of the command on an install that has changed nothing.
        $defaults = new EncodeSettings();
        $cmd = $this->softwareCommand([
            'preset' => $defaults->preset(),
            'crf' => $defaults->crfH264(),
            'audio_bitrate' => $defaults->audioBitrate(),
        ]);

        $this->assertStringContainsString('-preset veryfast', $cmd);
        $this->assertStringContainsString('-crf 23', $cmd);
        $this->assertStringContainsString('128k', $cmd);
    }

    // ─────────────────────────────────────────────────────────────────
    // hardware path — the silent no-op this nearly shipped as
    // ─────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $params
     */
    private function hwaccelCommand(string $vendor, array $params): string
    {
        $capability = new HwaccelCapability(
            vendor: $vendor,
            encoder: $vendor === 'nvenc' ? 'h264_nvenc' : 'h264_' . $vendor,
            decoder: 'h264',
            supports_hdr_tone_mapping: false,
            supported_codecs: ['h264'],
            supported_profiles: ['high'],
            max_resolution_w: 3840,
            max_resolution_h: 2160,
            max_bitrate: 100000000,
        );

        // The builder resolves its capability from the injected registry, not
        // from an argument, and returns null without one — so the registry has
        // to be populated or every hardware assertion below silently passes on
        // a null command. HwaccelRegistry is final with a private constructor,
        // so it is built and seeded by reflection.
        $registryRef = new \ReflectionClass(HwaccelRegistry::class);
        $registry = $registryRef->newInstanceWithoutConstructor();
        foreach (
            [
            'capabilities' => [$vendor => $capability],
            'initialized' => true,
            'vendor_priority' => [$vendor => 1],
            'config' => ['fallback_to_software' => false],
            ] as $prop => $value
        ) {
            if (!$registryRef->hasProperty($prop)) {
                continue;
            }
            $p = $registryRef->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue($registry, $value);
        }

        $runner = $this->runner();

        $registryProp = new \ReflectionProperty(FfmpegRunner::class, 'hwaccelRegistry');
        $registryProp->setAccessible(true);
        $registryProp->setValue($runner, $registry);

        // Skip the real hardware probe, which would overwrite the stub.
        $probed = new \ReflectionProperty(FfmpegRunner::class, 'hwaccelProbed');
        $probed->setAccessible(true);
        $probed->setValue(null, true);

        $ref = new \ReflectionMethod(FfmpegRunner::class, 'buildHwaccelSegmentCommand');
        $ref->setAccessible(true);

        $result = $ref->invoke(
            $runner,
            '/tmp/in.mkv',
            '/tmp/out.ts',
            0.0,
            6.0,
            array_merge(['video_codec' => 'libx264', 'audio_codec' => 'aac'], $params),
        );

        $this->assertIsString($result, 'hwaccel builder returned null — the registry stub did not take');

        return $result;
    }

    public function test_at_the_default_preset_the_vendor_tuning_is_left_alone(): void
    {
        // vaapi/qsv were tuned to `fast` independently of the software path's
        // `veryfast`. Forwarding the software default would silently retune
        // every GPU encode the moment this setting shipped.
        $this->assertStringContainsString(
            '-preset:v p4',
            $this->hwaccelCommand('nvenc', ['preset' => EncodeSettings::DEFAULT_PRESET])
        );
        $this->assertStringContainsString(
            '-preset:v fast',
            $this->hwaccelCommand('qsv', ['preset' => EncodeSettings::DEFAULT_PRESET])
        );
        $this->assertStringContainsString(
            '-preset:v fast',
            $this->hwaccelCommand('vaapi', ['preset' => EncodeSettings::DEFAULT_PRESET])
        );
    }

    public function test_with_no_preset_param_at_all_the_vendor_tuning_is_left_alone(): void
    {
        $this->assertStringContainsString('-preset:v p4', $this->hwaccelCommand('nvenc', []));
        $this->assertStringContainsString('-preset:v fast', $this->hwaccelCommand('qsv', []));
    }

    public function test_an_overridden_preset_is_translated_into_the_nvenc_namespace(): void
    {
        // x264 names are not valid for NVENC; emitting `-preset:v slow` there
        // would make ffmpeg exit and break playback on exactly the boxes that
        // transcode fastest.
        $cmd = $this->hwaccelCommand('nvenc', ['preset' => 'slow']);

        $this->assertStringContainsString('-preset:v p6', $cmd);
        $this->assertStringNotContainsString('-preset:v slow', $cmd);
    }

    public function test_an_overridden_preset_reaches_the_other_vendors_verbatim(): void
    {
        $this->assertStringContainsString('-preset:v slow', $this->hwaccelCommand('qsv', ['preset' => 'slow']));
        $this->assertStringContainsString('-preset:v slow', $this->hwaccelCommand('vaapi', ['preset' => 'slow']));
    }

    public function test_an_unrecognised_preset_cannot_reach_the_hardware_command(): void
    {
        // Defence in depth: EncodeSettings already rejects these, but params
        // are concatenated into a shell string, so the builder validates too.
        $cmd = $this->hwaccelCommand('nvenc', ['preset' => 'slow -f null /dev/null']);

        $this->assertStringNotContainsString('/dev/null', $cmd);
        $this->assertStringContainsString('-preset:v p4', $cmd);
    }

    public function test_a_configured_crf_reaches_the_hardware_command(): void
    {
        $this->assertStringContainsString('-crf 20', $this->hwaccelCommand('nvenc', ['crf' => 20]));
    }
}
