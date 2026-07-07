<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Streaming;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Phlix\Media\Streaming\SourceProfile;

/**
 * Unit tests for {@see SourceProfile} — the typed, I/O-free ABR ladder input and
 * its tolerant {@see SourceProfile::fromSourceMetadata()} adapter over A1's
 * persisted `metadata_json['source']` blob.
 */
final class SourceProfileTest extends TestCase
{
    public function testDirectConstructionDefaultsToAllNull(): void
    {
        $profile = new SourceProfile();

        self::assertNull($profile->width);
        self::assertNull($profile->height);
        self::assertNull($profile->videoCodec);
        self::assertNull($profile->videoBitrate);
        self::assertNull($profile->audioCodec);
        self::assertNull($profile->audioBitrate);
        self::assertNull($profile->pixFmt);
    }

    public function testDirectConstructionPreservesValues(): void
    {
        $profile = new SourceProfile(1920, 1080, 'h264', 8_000_000, 'aac', 192_000, 'yuv420p');

        self::assertSame(1920, $profile->width);
        self::assertSame(1080, $profile->height);
        self::assertSame('h264', $profile->videoCodec);
        self::assertSame(8_000_000, $profile->videoBitrate);
        self::assertSame('aac', $profile->audioCodec);
        self::assertSame(192_000, $profile->audioBitrate);
        self::assertSame('yuv420p', $profile->pixFmt);
    }

    public function testFromSourceMetadataEmptyArrayYieldsAllNull(): void
    {
        $profile = SourceProfile::fromSourceMetadata([]);

        self::assertNull($profile->width);
        self::assertNull($profile->height);
        self::assertNull($profile->videoCodec);
        self::assertNull($profile->videoBitrate);
        self::assertNull($profile->audioCodec);
        self::assertNull($profile->audioBitrate);
        self::assertNull($profile->pixFmt);
    }

    public function testFromSourceMetadataCoercesNumericStrings(): void
    {
        $profile = SourceProfile::fromSourceMetadata([
            'width' => '1920',
            'height' => '1080',
            'video_codec' => 'h264',
            'video_bitrate' => '8000000',
            'audio_codec' => 'aac',
            'audio_bitrate' => '192000',
            'pix_fmt' => 'yuv420p',
        ]);

        self::assertSame(1920, $profile->width);
        self::assertSame(1080, $profile->height);
        self::assertSame('h264', $profile->videoCodec);
        self::assertSame(8_000_000, $profile->videoBitrate);
        self::assertSame('aac', $profile->audioCodec);
        self::assertSame(192_000, $profile->audioBitrate);
        self::assertSame('yuv420p', $profile->pixFmt);
    }

    public function testFromSourceMetadataTruncatesFloatStrings(): void
    {
        $profile = SourceProfile::fromSourceMetadata([
            'width' => '1920.9',
            'height' => 1080.5,
            'video_bitrate' => '8000000.7',
        ]);

        self::assertSame(1920, $profile->width);
        self::assertSame(1080, $profile->height);
        self::assertSame(8_000_000, $profile->videoBitrate);
    }

    public function testFromSourceMetadataIsTolerantOfNullsMissingKeysAndBadTypes(): void
    {
        $profile = SourceProfile::fromSourceMetadata([
            'width' => null,
            'height' => '',
            'video_codec' => '   ',
            'video_bitrate' => 'not-a-number',
            'audio_codec' => 0,
            'audio_bitrate' => true,
            // pix_fmt intentionally missing
        ]);

        self::assertNull($profile->width, 'explicit null stays null');
        self::assertNull($profile->height, 'empty string is not numeric -> null');
        self::assertNull($profile->videoCodec, 'whitespace-only string trims to null');
        self::assertNull($profile->videoBitrate, 'non-numeric string -> null');
        self::assertNull($profile->audioCodec, 'non-string (int 0) codec -> null');
        self::assertNull($profile->audioBitrate, 'bool is not numeric -> null');
        self::assertNull($profile->pixFmt, 'missing key -> null');
    }

    public function testFromSourceMetadataTrimsCodecStrings(): void
    {
        $profile = SourceProfile::fromSourceMetadata([
            'video_codec' => '  hevc ',
            'audio_codec' => "ac3\n",
        ]);

        self::assertSame('hevc', $profile->videoCodec);
        self::assertSame('ac3', $profile->audioCodec);
    }

    public function testFromSourceMetadataAcceptsNativeIntegers(): void
    {
        $profile = SourceProfile::fromSourceMetadata([
            'width' => 3840,
            'height' => 2160,
            'video_bitrate' => 16_000_000,
        ]);

        self::assertSame(3840, $profile->width);
        self::assertSame(2160, $profile->height);
        self::assertSame(16_000_000, $profile->videoBitrate);
    }

    /**
     * @param non-empty-string $codec
     */
    #[DataProvider('h264CodecProvider')]
    public function testIsH264RecognisesH264Family(string $codec): void
    {
        self::assertTrue((new SourceProfile(videoCodec: $codec))->isH264());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function h264CodecProvider(): iterable
    {
        yield 'h264' => ['h264'];
        yield 'avc1' => ['avc1'];
        yield 'avc' => ['avc'];
        yield 'uppercase H264' => ['H264'];
        yield 'mixed AvC1' => ['AvC1'];
    }

    /**
     * @param non-empty-string $codec
     */
    #[DataProvider('nonH264CodecProvider')]
    public function testIsH264RejectsOtherCodecs(string $codec): void
    {
        self::assertFalse((new SourceProfile(videoCodec: $codec))->isH264());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonH264CodecProvider(): iterable
    {
        yield 'hevc' => ['hevc'];
        yield 'h265' => ['h265'];
        yield 'vp9' => ['vp9'];
        yield 'av1' => ['av1'];
        yield 'mpeg4' => ['mpeg4'];
    }

    public function testIsH264FalseWhenCodecNull(): void
    {
        self::assertFalse((new SourceProfile())->isH264());
    }

    /**
     * @param non-empty-string $codec
     */
    #[DataProvider('aacCodecProvider')]
    public function testIsAacRecognisesAacFamily(string $codec): void
    {
        self::assertTrue((new SourceProfile(audioCodec: $codec))->isAac());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function aacCodecProvider(): iterable
    {
        yield 'aac' => ['aac'];
        yield 'mp4a' => ['mp4a'];
        yield 'uppercase AAC' => ['AAC'];
        yield 'mixed Mp4A' => ['Mp4A'];
    }

    /**
     * @param non-empty-string $codec
     */
    #[DataProvider('nonAacCodecProvider')]
    public function testIsAacRejectsOtherCodecs(string $codec): void
    {
        self::assertFalse((new SourceProfile(audioCodec: $codec))->isAac());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonAacCodecProvider(): iterable
    {
        yield 'ac3' => ['ac3'];
        yield 'eac3' => ['eac3'];
        yield 'mp3' => ['mp3'];
        yield 'flac' => ['flac'];
        yield 'opus' => ['opus'];
        yield 'truehd' => ['truehd'];
    }

    public function testIsAacFalseWhenCodecNull(): void
    {
        self::assertFalse((new SourceProfile())->isAac());
    }
}
