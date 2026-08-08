<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use Phlix\Admin\SettingsRepository;
use Phlix\Media\Transcoding\EncodeSettings;
use PHPUnit\Framework\TestCase;

/**
 * S56 — the `transcoding.segment_format` flag and, more importantly, its
 * relationship with the transcode job reuse key.
 *
 * The flag is worthless on its own: `TranscodeManager::ensureHlsJob()` keys a
 * job on `sha1(media|profile|JOB_KEY_VERSION . fingerprint())` and
 * `findReusableJob()` hands an OLD job — and its already-cached segments in an
 * already-written directory — to any later request with the same key. A flag
 * that did NOT move the fingerprint would mean flipping it produced `.m4s`
 * segments into a directory full of `.ts` ones, with no way back short of a
 * `JOB_KEY_VERSION` bump. So these cases are as much about the key as about the
 * setting.
 */
final class EncodeSettingsSegmentFormatTest extends TestCase
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
    // the value itself
    // ─────────────────────────────────────────────────────────────────

    public function test_the_shipped_default_is_mpegts(): void
    {
        $this->assertSame('mpegts', (new EncodeSettings())->segmentFormat());
        $this->assertSame('mpegts', EncodeSettings::DEFAULT_SEGMENT_FORMAT);
        $this->assertSame('mpegts', EncodeSettings::FORMAT_MPEGTS);
        $this->assertSame('fmp4', EncodeSettings::FORMAT_FMP4);
        $this->assertSame(['mpegts', 'fmp4'], EncodeSettings::SEGMENT_FORMATS);
    }

    public function test_fmp4_is_accepted_and_case_normalised(): void
    {
        $this->assertSame(
            'fmp4',
            $this->settings([EncodeSettings::SEGMENT_FORMAT_KEY => 'fmp4'])->segmentFormat()
        );
        $this->assertSame(
            'fmp4',
            $this->settings([EncodeSettings::SEGMENT_FORMAT_KEY => '  FMP4 '])->segmentFormat()
        );
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function rejectedValues(): iterable
    {
        yield 'unknown container' => ['cmaf'];
        yield 'empty string' => [''];
        yield 'near miss' => ['fmp4 segments'];
        yield 'integer' => [4];
        yield 'array' => [['fmp4']];
        yield 'bool' => [true];
    }

    /**
     * An unrecognised container must degrade to the shipped default rather than
     * reach the encode path: a third naming scheme would produce segments no
     * playlist advertises, i.e. a 404 for every request of that job.
     *
     * @dataProvider rejectedValues
     */
    public function test_anything_unrecognised_falls_back_to_mpegts(mixed $configured): void
    {
        $this->assertSame(
            'mpegts',
            $this->settings([EncodeSettings::SEGMENT_FORMAT_KEY => $configured])->segmentFormat()
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // the job key — this is the part that matters
    // ─────────────────────────────────────────────────────────────────

    public function test_the_fingerprint_stays_empty_at_every_shipped_default(): void
    {
        $this->assertSame('', (new EncodeSettings())->fingerprint());
        $this->assertSame(
            '',
            $this->settings([EncodeSettings::SEGMENT_FORMAT_KEY => 'mpegts'])->fingerprint()
        );
    }

    public function test_flipping_the_container_changes_the_fingerprint(): void
    {
        $mpegts = $this->settings([EncodeSettings::SEGMENT_FORMAT_KEY => 'mpegts'])->fingerprint();
        $fmp4 = $this->settings([EncodeSettings::SEGMENT_FORMAT_KEY => 'fmp4'])->fingerprint();

        $this->assertSame('', $mpegts);
        $this->assertNotSame('', $fmp4);
        $this->assertNotSame($mpegts, $fmp4);
    }

    /**
     * The container must move the key even on an install that has ALREADY moved
     * another knob.
     *
     * This is the case a naive implementation gets wrong. Hashing only
     * `preset|crf|audio` and merely adding `$format` to the early-return guard
     * passes every other case in this file — the defaults still yield `''`, and
     * fmp4-at-otherwise-defaults still differs from `''` — while leaving
     * `preset=slow, fmp4` and `preset=slow, mpegts` hashing to the SAME value.
     * On such an install the flip would silently reuse the MPEG-TS job.
     */
    public function test_the_container_changes_the_key_even_when_another_setting_already_moved_it(): void
    {
        $shared = [EncodeSettings::PRESET_KEY => 'slow', EncodeSettings::CRF_H264_KEY => 20];

        $mpegts = $this->settings($shared + [EncodeSettings::SEGMENT_FORMAT_KEY => 'mpegts'])->fingerprint();
        $fmp4 = $this->settings($shared + [EncodeSettings::SEGMENT_FORMAT_KEY => 'fmp4'])->fingerprint();

        $this->assertNotSame('', $mpegts, 'a moved preset must still produce a fingerprint');
        $this->assertNotSame(
            $mpegts,
            $fmp4,
            'flipping the container on an install that already moved the preset must still change the job key'
        );
    }

    /**
     * The other half of that: adding the container must NOT disturb the
     * fingerprint of an install that has moved a knob but left the container
     * alone — otherwise merely DEPLOYING S56 invalidates their whole transcode
     * cache and triggers a fleet-wide re-encode.
     *
     * Pinned against the literal pre-S56 value (`substr(sha1('slow|20|192k'),
     * 0, 12)`) rather than against a re-derivation of the current
     * implementation, because a check derived from its own subject
     * self-adjusts.
     */
    public function test_a_moved_preset_keeps_its_exact_pre_s56_fingerprint_while_the_container_is_default(): void
    {
        $moved = $this->settings([
            EncodeSettings::PRESET_KEY => 'slow',
            EncodeSettings::CRF_H264_KEY => 20,
            EncodeSettings::AUDIO_BITRATE_KEY => '192k',
        ]);

        // Literal, computed by hand from the pre-S56 expression.
        $this->assertSame('4acd092531e6', substr(sha1('slow|20|192k'), 0, 12), 'control: the literal is the sha1');
        $this->assertSame('4acd092531e6', $moved->fingerprint());
    }

    /**
     * ⚠ S60 TRAP, pinned here so it cannot be forgotten. The moment
     * `DEFAULT_SEGMENT_FORMAT` becomes `fmp4`, the suffix collapses to `''` for
     * fmp4 and the fingerprint returns to the MPEG-TS value — matching every
     * pre-existing `.ts` job. Whoever flips the default will red this test, and
     * the fix is to bump `TranscodeManager::JOB_KEY_VERSION` in the same commit
     * (NOT to delete this case).
     */
    public function test_s60_reminder_the_default_is_still_mpegts_so_no_job_key_version_bump_is_owed(): void
    {
        $this->assertSame(
            'mpegts',
            EncodeSettings::DEFAULT_SEGMENT_FORMAT,
            'flipping this default makes fingerprint() collide with every existing mpegts job — '
            . 'bump TranscodeManager::JOB_KEY_VERSION in the same commit'
        );
    }
}
