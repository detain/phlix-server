<?php

namespace Phlix\Tests\Unit\Media\Streaming;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Streaming\QualitySelector;

class QualitySelectorTest extends TestCase
{
    /**
     * The optional S507 (AD-8) direct-play gate keys that only samsung-tizen
     * declares. Used to prove the five pre-existing profiles opt into none of them.
     */
    private const S507_GATE_KEYS = [
        'audio_codecs',
        'max_audio_channels',
        'max_h264_level',
        'max_hevc_level',
        'hevc_profiles',
        'allow_hdr',
        'allow_anamorphic',
        'allow_interlaced',
        'max_bitrate_by_height',
        'vp9_max_bitrate',
        'vp9_require_limited_range',
    ];

    /**
     * S507 (AD-8) survival token. Pinned as a code string literal (NOT a comment —
     * php_strip_whitespace removes comments) and asserted below, so the merge lock
     * can prove the shape-pin file reached master byte-for-byte.
     */
    private const S507_SURVIVAL_TOKEN = 'S507TIZENPROFX9P4';

    public function testCanCreateQualitySelector(): void
    {
        $selector = new QualitySelector();
        $this->assertInstanceOf(QualitySelector::class, $selector);
    }

    public function testSelectsDirectPlayForCompatibleSource(): void
    {
        $selector = new QualitySelector();

        $sourceInfo = [
            'streams' => [
                ['codec_type' => 'video', 'codec' => 'h264', 'width' => 1920, 'height' => 1080, 'bitrate' => 5000000],
                ['codec_type' => 'audio', 'codec' => 'aac', 'channels' => 2],
            ],
        ];

        $result = $selector->selectQuality($sourceInfo, 'generic');

        $this->assertEquals('direct', $result['method']);
    }

    public function testSelectsTranscodeForIncompatibleSource(): void
    {
        $selector = new QualitySelector();

        $sourceInfo = [
            'streams' => [
                ['codec_type' => 'video', 'codec' => 'hevc', 'width' => 3840, 'height' => 2160, 'bitrate' => 100000000],
                ['codec_type' => 'audio', 'codec' => 'truehd', 'channels' => 8],
            ],
        ];

        $result = $selector->selectQuality($sourceInfo, 'mobile-low');

        $this->assertEquals('transcode', $result['method']);
    }

    /**
     * Build a minimal sourceInfo (one video + one audio stream) for selectQuality.
     *
     * @param array<string, mixed> $video
     * @param array<string, mixed> $audio
     *
     * @return array{streams: array<int, array<string, mixed>>}
     */
    private function src(array $video, array $audio): array
    {
        return [
            'streams' => [
                ['codec_type' => 'video'] + $video,
                ['codec_type' => 'audio'] + $audio,
            ],
        ];
    }

    /** Plain SDR H.264 1080p / AAC 2ch — the baseline a Samsung Tizen direct-plays. */
    private function cleanTizenH264(): array
    {
        return $this->src(
            ['codec' => 'h264', 'width' => 1920, 'height' => 1080, 'bitrate' => 8000000,
                'level' => 40, 'sample_aspect_ratio' => '1:1', 'field_order' => 'progressive',
                'color_transfer' => 'bt709', 'color_range' => 'tv'],
            ['codec' => 'aac', 'channels' => 2],
        );
    }

    // ---- S507 (AD-8): profile existence + shape --------------------------------

    public function testSamsungTizenProfileExistsAndIsDistinctFromTv4k(): void
    {
        $selector = new QualitySelector();

        $tizen = $selector->getProfile('samsung-tizen');
        $this->assertNotNull($tizen, 'samsung-tizen must be a known QualitySelector profile');
        $this->assertNotSame($selector->getProfile('tv-4k'), $tizen);
        // AD-8 / S507 survival pin: the code-resident token must equal itself so it
        // is exercised, never tree-shaken, and provably lives in a .php (not a comment).
        $this->assertSame('S507TIZENPROFX9P4', self::S507_SURVIVAL_TOKEN);

        // Shape pins: the AD-8 thresholds the dedicated profile must carry.
        $this->assertSame(3840, $tizen['max_resolution'][0]);
        $this->assertSame(2160, $tizen['max_resolution'][1]);
        $this->assertSame(50000000, $tizen['max_bitrate']);
        $this->assertSame(['h264', 'h265', 'vp9'], $tizen['direct_play']);
        $this->assertNotContains('av1', $tizen['direct_play'], 'AV1 stays excluded (already-encoded)');
        $this->assertSame(52, $tizen['max_h264_level'], 'H.264 level ceiling = L5.2 (×10 units)');
        $this->assertSame(120, $tizen['max_hevc_level'], 'HEVC Main ≤ L120');
        $this->assertContains('main', $tizen['hevc_profiles']);
        $this->assertFalse($tizen['allow_hdr'], 'SDR-only direct play');
        $this->assertFalse($tizen['allow_anamorphic']);
        $this->assertFalse($tizen['allow_interlaced']);
        $this->assertSame(2, $tizen['max_audio_channels'], 'explicit channel budget');
        $this->assertSame(['aac', 'ac3', 'eac3', 'mp3'], $tizen['audio_codecs'], 'per-profile allow-list');
        $this->assertNotContains('flac', $tizen['audio_codecs']);
        $this->assertNotContains('opus', $tizen['audio_codecs']);
        $this->assertSame(20000000, $tizen['max_bitrate_by_height'][1080], 'FHD tier 20M');
        $this->assertSame(50000000, $tizen['max_bitrate_by_height'][2160], 'UHD tier 50M');
        $this->assertSame(20000000, $tizen['vp9_max_bitrate']);
        $this->assertTrue($tizen['vp9_require_limited_range']);
    }

    public function testSamsungTizenCleanSourceDirectPlays(): void
    {
        $selector = new QualitySelector();
        $this->assertSame('direct', $selector->selectQuality($this->cleanTizenH264(), 'samsung-tizen')['method']);
    }

    // ---- S507: each MISSING gate, proven in isolation --------------------------

    public function testH264LevelGateRejectsAbove5p2(): void
    {
        $selector = new QualitySelector();
        $source = $this->src(
            ['codec' => 'h264', 'width' => 1920, 'height' => 1080, 'bitrate' => 8000000, 'level' => 60],
            ['codec' => 'aac', 'channels' => 2],
        );
        $this->assertSame('transcode', $selector->selectQuality($source, 'samsung-tizen')['method']);
        // At/below the ceiling passes.
        $sourceAt = $this->src(
            ['codec' => 'h264', 'width' => 1920, 'height' => 1080, 'bitrate' => 8000000, 'level' => 52],
            ['codec' => 'aac', 'channels' => 2],
        );
        $this->assertSame('direct', $selector->selectQuality($sourceAt, 'samsung-tizen')['method']);
    }

    /** @dataProvider hdrTransferProvider */
    public function testHdrGate(string $transfer, string $expected): void
    {
        $selector = new QualitySelector();
        $source = $this->src(
            ['codec' => 'h264', 'width' => 1920, 'height' => 1080, 'bitrate' => 8000000, 'color_transfer' => $transfer],
            ['codec' => 'aac', 'channels' => 2],
        );
        $this->assertSame($expected, $selector->selectQuality($source, 'samsung-tizen')['method']);
    }

    /** @return array<string, array{string, string}> */
    public static function hdrTransferProvider(): array
    {
        return [
            'SDR bt709 direct' => ['bt709', 'direct'],
            'HDR10 PQ transcode' => ['smpte2084', 'transcode'],
            'HLG transcode' => ['arib-std-b67', 'transcode'],
        ];
    }

    public function testAnamorphicGate(): void
    {
        $selector = new QualitySelector();
        $anamorphic = $this->src(
            ['codec' => 'h264', 'width' => 1920, 'height' => 1080, 'bitrate' => 8000000,
                'sample_aspect_ratio' => '4:3'],
            ['codec' => 'aac', 'channels' => 2],
        );
        $this->assertSame('transcode', $selector->selectQuality($anamorphic, 'samsung-tizen')['method']);
    }

    public function testInterlaceGate(): void
    {
        $selector = new QualitySelector();
        $interlaced = $this->src(
            ['codec' => 'h264', 'width' => 1920, 'height' => 1080, 'bitrate' => 8000000, 'field_order' => 'tt'],
            ['codec' => 'aac', 'channels' => 2],
        );
        $this->assertSame('transcode', $selector->selectQuality($interlaced, 'samsung-tizen')['method']);
    }

    public function testHevcLevelAndProfileGates(): void
    {
        $selector = new QualitySelector();
        // Main @ L120 direct-plays.
        $mainOk = $this->src(
            ['codec' => 'h265', 'width' => 3840, 'height' => 2160, 'bitrate' => 30000000,
                'profile' => 'Main', 'level' => 120],
            ['codec' => 'aac', 'channels' => 2],
        );
        $this->assertSame('direct', $selector->selectQuality($mainOk, 'samsung-tizen')['method']);
        // Main @ L150 exceeds the ceiling → transcode.
        $tooHigh = $this->src(
            ['codec' => 'h265', 'width' => 3840, 'height' => 2160, 'bitrate' => 30000000,
                'profile' => 'Main', 'level' => 150],
            ['codec' => 'aac', 'channels' => 2],
        );
        $this->assertSame('transcode', $selector->selectQuality($tooHigh, 'samsung-tizen')['method']);
        // Rext (outside Main/Main10) → transcode.
        $badProfile = $this->src(
            ['codec' => 'h265', 'width' => 3840, 'height' => 2160, 'bitrate' => 30000000,
                'profile' => 'Rext', 'level' => 120],
            ['codec' => 'aac', 'channels' => 2],
        );
        $this->assertSame('transcode', $selector->selectQuality($badProfile, 'samsung-tizen')['method']);
    }

    public function testVp9RangeAndBitrateGate(): void
    {
        $selector = new QualitySelector();
        // Limited range, under the VP9 bitrate ceiling → direct.
        $ok = $this->src(
            ['codec' => 'vp9', 'width' => 1920, 'height' => 1080, 'bitrate' => 12000000, 'color_range' => 'tv'],
            ['codec' => 'aac', 'channels' => 2],
        );
        $this->assertSame('direct', $selector->selectQuality($ok, 'samsung-tizen')['method']);
        // Full ("pc") range → transcode.
        $full = $this->src(
            ['codec' => 'vp9', 'width' => 1920, 'height' => 1080, 'bitrate' => 12000000, 'color_range' => 'pc'],
            ['codec' => 'aac', 'channels' => 2],
        );
        $this->assertSame('transcode', $selector->selectQuality($full, 'samsung-tizen')['method']);
        // Over the VP9 bitrate ceiling → transcode.
        $hot = $this->src(
            ['codec' => 'vp9', 'width' => 1920, 'height' => 1080, 'bitrate' => 25000000, 'color_range' => 'tv'],
            ['codec' => 'aac', 'channels' => 2],
        );
        $this->assertSame('transcode', $selector->selectQuality($hot, 'samsung-tizen')['method']);
    }

    public function testPerTierBitrateCeiling(): void
    {
        $selector = new QualitySelector();
        // 1080p source at 25M exceeds the FHD 20M tier → transcode.
        $fhdHot = $this->src(
            ['codec' => 'h264', 'width' => 1920, 'height' => 1080, 'bitrate' => 25000000, 'level' => 40],
            ['codec' => 'aac', 'channels' => 2],
        );
        $this->assertSame('transcode', $selector->selectQuality($fhdHot, 'samsung-tizen')['method']);
        // 4K source at 40M sits under the UHD 50M tier → direct.
        $uhd = $this->src(
            ['codec' => 'h264', 'width' => 3840, 'height' => 2160, 'bitrate' => 40000000, 'level' => 50],
            ['codec' => 'aac', 'channels' => 2],
        );
        $this->assertSame('direct', $selector->selectQuality($uhd, 'samsung-tizen')['method']);
    }

    public function testMaxAudioChannelsGate(): void
    {
        $selector = new QualitySelector();
        $surround = $this->src(
            ['codec' => 'h264', 'width' => 1920, 'height' => 1080, 'bitrate' => 8000000, 'level' => 40],
            ['codec' => 'ac3', 'channels' => 6],
        );
        $this->assertSame('transcode', $selector->selectQuality($surround, 'samsung-tizen')['method']);
    }

    /**
     * Already-encoded AD-8 behaviour preserved: DTS/TrueHD are absent from the
     * allow-list, and now FLAC/Opus too (Tizen can't decode them natively) → all
     * transcode rather than direct-play.
     */
    public function testAudioAllowListForcesTranscodeForUnsupportedCodec(): void
    {
        $selector = new QualitySelector();
        foreach (['dts', 'truehd', 'flac', 'opus'] as $codec) {
            $source = $this->src(
                ['codec' => 'h264', 'width' => 1920, 'height' => 1080, 'bitrate' => 8000000, 'level' => 40],
                ['codec' => $codec, 'channels' => 2],
            );
            $this->assertSame(
                'transcode',
                $selector->selectQuality($source, 'samsung-tizen')['method'],
                "{$codec} must not direct-play on samsung-tizen",
            );
        }
    }

    // ---- S507: byte-identical regression for the five pre-existing profiles -----

    /**
     * The samsung-tizen gates must NOT leak into tv-4k: a 4K HEVC Main@L150
     * 40 Mbps interlaced HDR source still direct-plays under tv-4k exactly as it
     * did before S507 (tv-4k declares none of the optional gate keys).
     */
    public function testTv4kProfileGainsNoSamsungTizenGates(): void
    {
        $selector = new QualitySelector();
        $source = $this->src(
            ['codec' => 'h265', 'width' => 3840, 'height' => 2160, 'bitrate' => 40000000,
                'profile' => 'Main', 'level' => 150, 'field_order' => 'tt',
                'sample_aspect_ratio' => '4:3', 'color_transfer' => 'smpte2084'],
            ['codec' => 'flac', 'channels' => 8],
        );
        $this->assertSame('direct', $selector->selectQuality($source, 'tv-4k')['method']);
    }

    /**
     * S507 (AD-8) opt-in inertness, structurally pinned for every pre-existing
     * profile. Each of the samsung-tizen direct-play gate keys is OPTIONAL — a
     * profile that does not declare it is unconstrained on that axis. This asserts
     * none of the five legacy profiles carries a single gate key, so canDirectPlay
     * stays byte-identical for them (the behavioural tv-4k test above is one face
     * of this; the shape test here is the exhaustive one, covering all five).
     *
     * @dataProvider preExistingProfileProvider
     */
    public function testPreExistingProfilesDeclareNoOptionalGateKeys(string $profileName): void
    {
        $selector = new QualitySelector();
        $profile = $selector->getProfile($profileName);
        $this->assertNotNull($profile, "{$profileName} must be a known profile");

        foreach (self::S507_GATE_KEYS as $gateKey) {
            $this->assertArrayNotHasKey(
                $gateKey,
                $profile,
                "pre-existing profile '{$profileName}' must not declare opt-in gate key '{$gateKey}'",
            );
        }
    }

    /** @return array<string, array{string}> */
    public static function preExistingProfileProvider(): array
    {
        $cases = [];
        foreach (['generic', 'mobile-low', 'mobile-high', 'web', 'tv-4k'] as $name) {
            $cases[$name] = [$name];
        }

        return $cases;
    }
}
