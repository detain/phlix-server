<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use Phlix\Admin\SettingsRepository;
use Phlix\Media\Transcoding\EncodeSettings;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour of the policy behind `transcoding.preset`, `transcoding.crf_h264`
 * and `transcoding.audio_bitrate`.
 *
 * Two properties matter more than the rest here:
 *
 *  1. **A bad value must never reach ffmpeg.** An invalid `-preset` or `-b:a`
 *     makes ffmpeg exit immediately, so a mistyped setting would present as
 *     "all playback is broken" with the cause buried in a transcode log. Every
 *     unparseable input therefore falls back rather than passing through.
 *  2. **At the shipped defaults the fingerprint is empty**, which is what keeps
 *     the transcode job key — and therefore the existing segment cache — intact
 *     when this feature is deployed.
 */
final class EncodeSettingsTest extends TestCase
{
    /**
     * @param array<string, mixed> $values
     */
    private function settings(array $values): EncodeSettings
    {
        $repo = $this->createMock(SettingsRepository::class);
        $repo->method('getEffective')->willReturnCallback(
            /** @return mixed */
            static fn (string $key) => $values[$key] ?? null
        );

        return new EncodeSettings($repo);
    }

    // ─────────────────────────────────────────────────────────────────
    // defaults
    // ─────────────────────────────────────────────────────────────────

    public function test_without_a_store_it_yields_the_shipped_literals(): void
    {
        $s = new EncodeSettings();

        $this->assertSame('veryfast', $s->preset());
        $this->assertSame(23, $s->crfH264());
        $this->assertSame('128k', $s->audioBitrate());
    }

    public function test_the_shipped_defaults_match_the_literals_that_were_replaced(): void
    {
        // These were hardcoded at ten sites across TranscodeManager. Pinning
        // them makes any change to the shipped encode a deliberate edit.
        $this->assertSame('veryfast', EncodeSettings::DEFAULT_PRESET);
        $this->assertSame(23, EncodeSettings::DEFAULT_CRF_H264);
        $this->assertSame('128k', EncodeSettings::DEFAULT_AUDIO_BITRATE);
    }

    // ─────────────────────────────────────────────────────────────────
    // preset
    // ─────────────────────────────────────────────────────────────────

    public function test_a_valid_preset_is_honoured(): void
    {
        $this->assertSame('slow', $this->settings([EncodeSettings::PRESET_KEY => 'slow'])->preset());
    }

    public function test_a_preset_is_normalised_for_case_and_whitespace(): void
    {
        $this->assertSame('medium', $this->settings([EncodeSettings::PRESET_KEY => '  MEDIUM '])->preset());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function badPresets(): array
    {
        return [
            'typo' => ['veryfastt'],
            'nvenc name on the software path' => ['p4'],
            'empty' => [''],
            'injection attempt' => ['veryfast -f null /dev/null'],
            'int' => [23],
            'array' => [['slow']],
            'null' => [null],
        ];
    }

    /**
     * @dataProvider badPresets
     */
    public function test_an_invalid_preset_falls_back_instead_of_reaching_ffmpeg(mixed $value): void
    {
        // The injection case is the pointed one: params are concatenated into a
        // command string, so an unvalidated preset would be an argv injection.
        $this->assertSame(
            EncodeSettings::DEFAULT_PRESET,
            $this->settings([EncodeSettings::PRESET_KEY => $value])->preset()
        );
    }

    public function test_every_declared_preset_is_accepted(): void
    {
        foreach (EncodeSettings::PRESETS as $preset) {
            $this->assertSame($preset, $this->settings([EncodeSettings::PRESET_KEY => $preset])->preset());
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // vendor mapping
    // ─────────────────────────────────────────────────────────────────

    public function test_nvenc_gets_its_own_preset_namespace(): void
    {
        // x264 names are not valid for NVENC — ffmpeg fails outright on them.
        $s = $this->settings([EncodeSettings::PRESET_KEY => 'slow']);

        $this->assertSame('p6', $s->presetForVendor('nvenc'));
    }

    public function test_the_default_preset_maps_to_the_nvenc_rung_that_was_hardcoded(): void
    {
        // p4 is exactly what buildHwaccelSegmentCommand emitted before this
        // class existed, so the shipped default is a no-op on NVENC.
        $this->assertSame('p4', (new EncodeSettings())->presetForVendor('nvenc'));
    }

    public function test_other_vendors_receive_the_preset_unchanged(): void
    {
        $s = $this->settings([EncodeSettings::PRESET_KEY => 'slow']);

        $this->assertSame('slow', $s->presetForVendor('qsv'));
        $this->assertSame('slow', $s->presetForVendor('vaapi'));
        $this->assertSame('slow', $s->presetForVendor('anything-else'));
    }

    public function test_every_preset_has_an_nvenc_mapping(): void
    {
        // A missing entry would silently fall back to p4 and quietly ignore the
        // admin's choice on NVENC boxes only.
        foreach (EncodeSettings::PRESETS as $preset) {
            $this->assertArrayHasKey($preset, EncodeSettings::NVENC_PRESET_MAP);
            $this->assertMatchesRegularExpression('/^p[1-7]$/', EncodeSettings::NVENC_PRESET_MAP[$preset]);
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // CRF
    // ─────────────────────────────────────────────────────────────────

    public function test_an_in_range_crf_is_honoured(): void
    {
        $this->assertSame(20, $this->settings([EncodeSettings::CRF_H264_KEY => 20])->crfH264());
    }

    public function test_a_numeric_string_crf_is_coerced(): void
    {
        $this->assertSame(28, $this->settings([EncodeSettings::CRF_H264_KEY => '28'])->crfH264());
    }

    public function test_crf_is_clamped_at_both_ends(): void
    {
        // 0 is lossless — enormous files that would fill the segment cache.
        $this->assertSame(
            EncodeSettings::MIN_CRF,
            $this->settings([EncodeSettings::CRF_H264_KEY => 0])->crfH264()
        );
        $this->assertSame(
            EncodeSettings::MAX_CRF,
            $this->settings([EncodeSettings::CRF_H264_KEY => 51])->crfH264()
        );
        $this->assertSame(
            EncodeSettings::MIN_CRF,
            $this->settings([EncodeSettings::CRF_H264_KEY => -5])->crfH264()
        );
    }

    public function test_a_non_numeric_crf_falls_back(): void
    {
        $this->assertSame(
            EncodeSettings::DEFAULT_CRF_H264,
            $this->settings([EncodeSettings::CRF_H264_KEY => 'high quality'])->crfH264()
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // audio bitrate
    // ─────────────────────────────────────────────────────────────────

    public function test_audio_bitrate_accepts_the_k_suffixed_form(): void
    {
        $this->assertSame('192k', $this->settings([EncodeSettings::AUDIO_BITRATE_KEY => '192k'])->audioBitrate());
    }

    public function test_audio_bitrate_accepts_a_bare_number_and_normalises_it(): void
    {
        $this->assertSame('192k', $this->settings([EncodeSettings::AUDIO_BITRATE_KEY => '192'])->audioBitrate());
        $this->assertSame('192k', $this->settings([EncodeSettings::AUDIO_BITRATE_KEY => 192])->audioBitrate());
        $this->assertSame('192k', $this->settings([EncodeSettings::AUDIO_BITRATE_KEY => '192K'])->audioBitrate());
    }

    public function test_audio_bitrate_is_clamped(): void
    {
        $this->assertSame('32k', $this->settings([EncodeSettings::AUDIO_BITRATE_KEY => '1k'])->audioBitrate());
        $this->assertSame('512k', $this->settings([EncodeSettings::AUDIO_BITRATE_KEY => '9999k'])->audioBitrate());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function badAudioBitrates(): array
    {
        return [
            'units we do not emit' => ['128kbps'],
            'megabit form' => ['1M'],
            'injection attempt' => ['128k -f null /dev/null'],
            'words' => ['high'],
            'empty' => [''],
            'array' => [[128]],
            'null' => [null],
        ];
    }

    /**
     * @dataProvider badAudioBitrates
     */
    public function test_an_unparseable_audio_bitrate_falls_back(mixed $value): void
    {
        $this->assertSame(
            EncodeSettings::DEFAULT_AUDIO_BITRATE,
            $this->settings([EncodeSettings::AUDIO_BITRATE_KEY => $value])->audioBitrate()
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // fingerprint — the transcode-cache contract
    // ─────────────────────────────────────────────────────────────────

    public function test_the_fingerprint_is_empty_at_the_shipped_defaults(): void
    {
        // THIS is what stops deploying the feature from invalidating every
        // cached transcode on every install and triggering a re-encode storm.
        $this->assertSame('', (new EncodeSettings())->fingerprint());
    }

    public function test_the_fingerprint_is_empty_when_overrides_equal_the_defaults(): void
    {
        // An admin who saves the default value explicitly must not be punished
        // with a full cache invalidation.
        $s = $this->settings([
            EncodeSettings::PRESET_KEY => 'veryfast',
            EncodeSettings::CRF_H264_KEY => 23,
            EncodeSettings::AUDIO_BITRATE_KEY => '128k',
        ]);

        $this->assertSame('', $s->fingerprint());
    }

    public function test_the_fingerprint_is_also_empty_when_a_value_is_clamped_back_to_the_default(): void
    {
        // Fingerprint must reflect the EFFECTIVE encode, not the raw row —
        // otherwise a value that clamps to the default would invalidate the
        // cache while producing byte-identical output.
        $s = $this->settings([EncodeSettings::AUDIO_BITRATE_KEY => '128']);

        $this->assertSame('128k', $s->audioBitrate());
        $this->assertSame('', $s->fingerprint());
    }

    public function test_changing_any_value_changes_the_fingerprint(): void
    {
        $base = (new EncodeSettings())->fingerprint();

        $preset = $this->settings([EncodeSettings::PRESET_KEY => 'slow'])->fingerprint();
        $crf = $this->settings([EncodeSettings::CRF_H264_KEY => 20])->fingerprint();
        $audio = $this->settings([EncodeSettings::AUDIO_BITRATE_KEY => '192k'])->fingerprint();

        foreach (['preset' => $preset, 'crf' => $crf, 'audio' => $audio] as $label => $fp) {
            $this->assertNotSame($base, $fp, $label . ' must change the job key');
            $this->assertNotSame('', $fp, $label . ' must produce a non-empty fingerprint');
        }

        // And they must not collide with each other.
        $this->assertCount(3, array_unique([$preset, $crf, $audio]));
    }

    public function test_the_fingerprint_is_stable_for_the_same_inputs(): void
    {
        // An unstable fingerprint would re-key the job on every request and
        // defeat transcode reuse entirely.
        $a = $this->settings([EncodeSettings::PRESET_KEY => 'slow'])->fingerprint();
        $b = $this->settings([EncodeSettings::PRESET_KEY => 'slow'])->fingerprint();

        $this->assertSame($a, $b);
    }

    // ─────────────────────────────────────────────────────────────────
    // degradation
    // ─────────────────────────────────────────────────────────────────

    public function test_a_throwing_store_degrades_to_defaults_rather_than_breaking_transcoding(): void
    {
        $repo = $this->createMock(SettingsRepository::class);
        $repo->method('getEffective')->willThrowException(new \RuntimeException('db gone'));

        $s = new EncodeSettings($repo);

        $this->assertSame('veryfast', $s->preset());
        $this->assertSame(23, $s->crfH264());
        $this->assertSame('128k', $s->audioBitrate());
        $this->assertSame('', $s->fingerprint());
    }
}
