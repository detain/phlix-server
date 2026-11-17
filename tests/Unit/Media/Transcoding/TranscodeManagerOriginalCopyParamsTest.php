<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\EncodeSettings;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\TranscodeManager;

/**
 * The "Original" copy rung's per-segment encode contract (perf #1).
 *
 * {@see TranscodeManager::segmentParamsForRendition()} is private and reads only the
 * persisted Rendition array, so it is reached through reflection on an instance built
 * without its (heavy) constructor, with the one collaborator it needs — the effective
 * encode settings — injected. The resulting params are then fed to the real
 * {@see FfmpegRunner::buildSegmentCommand()} so the test pins the ACTUAL argv: a copy
 * video stream with either a copied or an AAC re-encoded audio track.
 */
final class TranscodeManagerOriginalCopyParamsTest extends TestCase
{
    private TranscodeManager $manager;

    protected function setUp(): void
    {
        $manager = (new \ReflectionClass(TranscodeManager::class))->newInstanceWithoutConstructor();
        $settingsProp = new \ReflectionProperty(TranscodeManager::class, 'encodeSettings');
        $settingsProp->setValue($manager, new EncodeSettings());
        $this->manager = $manager;
    }

    /**
     * @param array<string, mixed> $rendition
     *
     * @return array<string, mixed>
     */
    private function paramsFor(array $rendition): array
    {
        $method = new \ReflectionMethod(TranscodeManager::class, 'segmentParamsForRendition');
        /** @var array<string, mixed> $params */
        $params = $method->invoke($this->manager, $rendition);

        return $params;
    }

    /**
     * @return array<string, mixed>
     */
    private function copyRendition(?bool $audioCopy): array
    {
        $rendition = [
            'id' => 'original',
            'label' => 'Original (1080p)',
            'width' => 1920,
            'height' => 1080,
            'bitrate' => 8_192_000,
            'codecs' => 'avc1.640029,mp4a.40.2',
            'url' => null,
            'is_original' => true,
            'is_copy' => true,
            'video_bitrate' => 8_000_000,
        ];
        if ($audioCopy !== null) {
            $rendition['audio_copy'] = $audioCopy;
        }

        return $rendition;
    }

    public function testCopyRenditionWithCopiedAudioIsAPureStreamCopy(): void
    {
        $params = $this->paramsFor($this->copyRendition(true));

        self::assertSame(['video_codec' => 'copy', 'audio_codec' => 'copy'], $params);
    }

    public function testPersistedCopyRenditionWithoutAudioCopyKeyStillCopiesAudio(): void
    {
        // Rows persisted before the audio_copy key existed were built only for AAC
        // sources, so a missing key must keep the copy-audio contract.
        $params = $this->paramsFor($this->copyRendition(null));

        self::assertSame(['video_codec' => 'copy', 'audio_codec' => 'copy'], $params);
    }

    public function testCopyRenditionWithReencodedAudioCopiesVideoAndEncodesAac(): void
    {
        $params = $this->paramsFor($this->copyRendition(false));

        self::assertSame('copy', $params['video_codec']);
        self::assertSame('aac', $params['audio_codec']);
        self::assertSame('128k', $params['audio_bitrate']);
        self::assertSame(48000, $params['audio_sample_rate']);
        self::assertSame(2, $params['audio_channels']);
        self::assertArrayNotHasKey('preset', $params, 'a copy never gets encoder flags');
        self::assertArrayNotHasKey('crf', $params);
    }

    public function testReencodedCopyCommandCopiesVideoAndEncodesAudio(): void
    {
        $params = $this->paramsFor($this->copyRendition(false));

        $cmd = (new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/tmp'))->buildSegmentCommand(
            '/media/in.mkv',
            '/tmp/out.ts',
            1800.0,
            6.0,
            $params,
        );

        self::assertStringContainsString('-c:v copy', $cmd);
        self::assertStringNotContainsString('-c:v libx264', $cmd);
        self::assertStringNotContainsString('-force_key_frames', $cmd, 'a copy cannot force a keyframe');
        self::assertStringNotContainsString('-preset', $cmd);
        self::assertStringContainsString('-c:a aac', $cmd);
        self::assertStringContainsString('-b:a 128k', $cmd);
        self::assertStringContainsString('-ac 2', $cmd);
        // Input-side seek, so the copy starts on the keyframe at or before the segment.
        self::assertStringContainsString('-ss 1800', $cmd);
    }

    public function testCopyCommandWithCopiedAudioStaysPureCopy(): void
    {
        $params = $this->paramsFor($this->copyRendition(true));

        $cmd = (new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', '/tmp'))->buildSegmentCommand(
            '/media/in.mkv',
            '/tmp/out.ts',
            1800.0,
            6.0,
            $params,
        );

        self::assertStringContainsString('-c:v copy', $cmd);
        self::assertStringContainsString('-c:a copy', $cmd);
        self::assertStringNotContainsString('-c:a aac', $cmd);
    }

    public function testTranscodeRungCarriesTheConfiguredPresetExplicitly(): void
    {
        $params = $this->paramsFor([
            'id' => '1080p',
            'label' => '1080p',
            'width' => 1920,
            'height' => 1080,
            'bitrate' => 5_478_000,
            'codecs' => 'avc1.640029,mp4a.40.2',
            'url' => null,
            'is_original' => false,
            'is_copy' => false,
            'video_bitrate' => 5_000_000,
        ]);

        // ABR rungs always pass the effective preset (config/transcoding.php,
        // default veryfast) explicitly, never the builder's fallback.
        self::assertSame((new EncodeSettings())->preset(), $params['preset']);
        self::assertSame('veryfast', $params['preset']);
        self::assertSame('libx264', $params['video_codec']);
    }
}
