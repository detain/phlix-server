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
 *
 * ## ⚠ S60 moved the default from `mpegts` to `fmp4`
 *
 * Several cases below changed their expected value and one changed its name.
 * The claims did not change — "the shipped default", "an unrecognised value
 * degrades", "flipping the container moves the key" are all still what is being
 * asserted — but the shipped default is now `fmp4`, which reverses which side of
 * each pair is the free one. Two cases are NEW and could not have failed before
 * the flip, because the two things they separate were the same observation while
 * the default was `mpegts`:
 *
 *  - {@see self::test_an_explicit_mpegts_is_honoured_and_never_folded_into_the_default()}
 *    — the rollback. `segmentFormat()` used to map everything that was not
 *    `fmp4` onto the default, which at the instant of the flip turned
 *    `PUT transcoding.segment_format = mpegts` into a no-op.
 *  - {@see self::test_s60_the_fingerprint_cannot_tell_the_new_default_from_the_old_one()}
 *    — the trap this file has carried a reminder about since S56, now measured
 *    rather than warned about.
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

    /**
     * ⚠ S60 FLIPPED THIS. It read `test_the_shipped_default_is_mpegts` and
     * asserted `'mpegts'` at every line below, from S56 until S60.
     *
     * The literals are deliberately typed rather than derived from the class:
     * this case is the third anchor
     * {@see SegmentFormatSchemaEnumDriftTest}'s header names, and a check
     * derived from its own subject self-adjusts. `FORMAT_MPEGTS` is still
     * asserted here BY VALUE because the rollback (and every pre-S60 job
     * directory on disk) is spelled with that exact string.
     */
    public function test_the_shipped_default_is_fmp4(): void
    {
        $this->assertSame('fmp4', (new EncodeSettings())->segmentFormat());
        $this->assertSame('fmp4', EncodeSettings::DEFAULT_SEGMENT_FORMAT);
        $this->assertSame('mpegts', EncodeSettings::FORMAT_MPEGTS);
        $this->assertSame('fmp4', EncodeSettings::FORMAT_FMP4);
        $this->assertSame(['mpegts', 'fmp4'], EncodeSettings::SEGMENT_FORMATS);

        // The config file is the OTHER half of the same default: it is what
        // `SettingsRepository::getDefault()` reads when there is no override
        // row, so a drift between the two means the effective value the admin
        // API reports is not the one the encoder uses.
        $config = require dirname(__DIR__, 4) . '/config/transcoding.php';
        $this->assertIsArray($config);
        $this->assertSame('fmp4', $config['segment_format'] ?? null);
        $this->assertSame(EncodeSettings::DEFAULT_SEGMENT_FORMAT, $config['segment_format'] ?? null);
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
     * ⚠ THE ROLLBACK, AT THE ONE METHOD IT GOES THROUGH.
     *
     * This case did not exist before S60 and could not have failed before it,
     * because "returns mpegts" and "fell through to the default" were the same
     * observation while the default WAS mpegts. They are not the same now, and
     * the original expression — `$normalised === FORMAT_FMP4 ? FORMAT_FMP4 :
     * DEFAULT_SEGMENT_FORMAT` — quietly became "mpegts is unrecognised, give
     * them fmp4" at the instant of the flip. That is the documented rollback
     * (`PUT transcoding.segment_format = mpegts`) doing nothing at all, with
     * every other case in this file still green.
     *
     * The case-normalisation pair is here too, mirroring the fmp4 one, because
     * a `match` arm is easy to write for the canonical spelling only.
     */
    public function test_an_explicit_mpegts_is_honoured_and_never_folded_into_the_default(): void
    {
        $this->assertSame(
            'mpegts',
            $this->settings([EncodeSettings::SEGMENT_FORMAT_KEY => 'mpegts'])->segmentFormat(),
            'the rollback route is a PUT of exactly this value — it must not resolve to the default'
        );
        $this->assertSame(
            'mpegts',
            $this->settings([EncodeSettings::SEGMENT_FORMAT_KEY => ' MPEGTS '])->segmentFormat()
        );

        // Not vacuous: the default really is the OTHER member, so "returned
        // mpegts" cannot be "returned the default and we could not tell".
        $this->assertSame('fmp4', EncodeSettings::DEFAULT_SEGMENT_FORMAT);
        $this->assertNotSame(
            EncodeSettings::DEFAULT_SEGMENT_FORMAT,
            $this->settings([EncodeSettings::SEGMENT_FORMAT_KEY => 'mpegts'])->segmentFormat()
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
     * ⚠ S60 changed the EXPECTED value here (`mpegts` → `fmp4`) but not the
     * claim: the claim was always "degrades to the shipped default", and the
     * shipped default moved. The literal is typed rather than read off
     * `DEFAULT_SEGMENT_FORMAT` so that a future flip has to come back through
     * this case; asserting the constant would make it unfalsifiable.
     * `'fmp4 segments'` and `'cmaf'` are the interesting rows — they are the
     * near-misses a hand-edited config produces.
     *
     * @dataProvider rejectedValues
     */
    public function test_anything_unrecognised_falls_back_to_the_shipped_default(mixed $configured): void
    {
        $this->assertSame(
            'fmp4',
            $this->settings([EncodeSettings::SEGMENT_FORMAT_KEY => $configured])->segmentFormat()
        );
        $this->assertSame(
            EncodeSettings::DEFAULT_SEGMENT_FORMAT,
            $this->settings([EncodeSettings::SEGMENT_FORMAT_KEY => $configured])->segmentFormat()
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // the job key — this is the part that matters
    // ─────────────────────────────────────────────────────────────────

    public function test_the_fingerprint_stays_empty_at_every_shipped_default(): void
    {
        $this->assertSame('', (new EncodeSettings())->fingerprint());
        // ⚠ S60: this line read `'mpegts'` until the flip. `fmp4` is the value
        // that is now free — an install that has changed nothing keeps an empty
        // fingerprint, which is exactly why the flip could not express itself
        // here and had to be carried by JOB_KEY_VERSION instead.
        $this->assertSame(
            '',
            $this->settings([EncodeSettings::SEGMENT_FORMAT_KEY => 'fmp4'])->fingerprint()
        );
    }

    public function test_flipping_the_container_changes_the_fingerprint(): void
    {
        $mpegts = $this->settings([EncodeSettings::SEGMENT_FORMAT_KEY => 'mpegts'])->fingerprint();
        $fmp4 = $this->settings([EncodeSettings::SEGMENT_FORMAT_KEY => 'fmp4'])->fingerprint();

        // ⚠ S60 reversed which side is free. Before the flip: mpegts `''`,
        // fmp4 hashed. After it: fmp4 `''`, mpegts hashed — so rolling BACK is
        // now the direction that moves the key, and rolling back therefore
        // still gets a fresh job id and a fresh `.ts` directory rather than
        // re-entering an fMP4 one.
        $this->assertSame('', $fmp4);
        $this->assertNotSame('', $mpegts);
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
     * ⚠ THE S60 TRAP, SPRUNG — stated as a measurement rather than a warning.
     *
     * Until S60 this case was `test_s60_reminder_the_default_is_still_mpegts_…`
     * and asserted `DEFAULT_SEGMENT_FORMAT === 'mpegts'`, so that whoever flipped
     * the default would red it and be told what to do. The flip has happened, so
     * the reminder is replaced by the thing it was warning about, PROVEN:
     *
     * `fingerprint()` returns `''` for the shipped default whatever that default
     * is. A pre-S60 install at `mpegts` produced `''`. A post-S60 install at
     * `fmp4` produces `''`. **The fingerprint therefore CANNOT distinguish the
     * two**, and `findReusableJob()` keys on
     * `sha1(media|profile|JOB_KEY_VERSION . fingerprint())` — so with the
     * fingerprint identical, the only thing standing between an fMP4 request and
     * a directory full of `.ts` files is `JOB_KEY_VERSION`.
     *
     * That constant is asserted to have moved in
     * {@see \Phlix\Tests\Unit\Media\Transcoding\TranscodeManagerTest::testEnsureHlsJobReuseKeyCarriesFormatVersion()},
     * which computes both keys and requires them to differ. This case exists so
     * the REASON the bump is load-bearing is pinned beside the method that
     * creates the need, not only beside the method that satisfies it.
     */
    public function test_s60_the_fingerprint_cannot_tell_the_new_default_from_the_old_one(): void
    {
        // The post-flip value, measured.
        $this->assertSame('', (new EncodeSettings())->fingerprint());

        // The pre-flip value, as a LITERAL — `''` is what an untouched install
        // produced at `DEFAULT_SEGMENT_FORMAT = mpegts`, for every release from
        // S56 to S59. Re-deriving it from today's code would be a check derived
        // from its own subject.
        $preFlipFingerprintAtDefaults = '';

        $this->assertSame(
            $preFlipFingerprintAtDefaults,
            (new EncodeSettings())->fingerprint(),
            'the fingerprint is IDENTICAL either side of the flip, so it cannot be what '
            . 'invalidates the pre-S60 MPEG-TS jobs — TranscodeManager::JOB_KEY_VERSION is'
        );

        // The control: the fingerprint is not simply a constant `''`. It DOES
        // move for a setting that is off its default — which is what makes the
        // paragraph above a statement about this particular value and not about
        // the method being inert.
        $this->assertNotSame(
            '',
            $this->settings([EncodeSettings::SEGMENT_FORMAT_KEY => 'mpegts'])->fingerprint()
        );
    }
}
