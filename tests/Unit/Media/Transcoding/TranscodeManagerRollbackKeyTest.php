<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Phlix\Admin\SettingsRepository;
use Phlix\Media\Transcoding\EncodeSettings;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\TranscodeManager;
use Workerman\MySQL\Connection;

/**
 * ⚠ WHAT AN S60 ROLLBACK ACTUALLY DOES — measured, because the runbook got it
 * backwards once already.
 *
 * Between the S60 commit and this one, three docblocks (`EncodeSettings::fingerprint()`,
 * `config/transcoding.php` and `TranscodeManager::JOB_KEY_VERSION`) told an operator that
 * reverting `DEFAULT_SEGMENT_FORMAT` / the config literal to `mpegts` while leaving
 * {@see TranscodeManager::JOB_KEY_VERSION} at `v10` "orphans every cache a second time — a
 * second fleet-wide re-encode for no benefit". That is the OPPOSITE of the truth, and it is
 * wrong in the dangerous direction: it describes a costly-but-safe outcome where the real one
 * is cheap and wrong.
 *
 * The job key is `sha1($mediaItemId . '|' . $profileName . '|' . JOB_KEY_VERSION . fingerprint())`
 * ({@see TranscodeManager::ensureHlsJob()}). `fingerprint()` is `''` whenever every setting sits
 * at its shipped default, **whatever that default is** — the container is folded in as a suffix
 * that is empty AT the default. So a revert of the two default literals alone changes no input
 * to that expression, and the key is byte-identical to the key the shipped fMP4 jobs were
 * inserted under. `findReusableJob()` keys on nothing else, so it hands those fMP4 jobs straight
 * back and the box keeps serving `.m4s` while the config says `mpegts`. The operator re-tests on
 * the content they already played — precisely the content that HAS a v10 job — sees fMP4 still
 * playing, and is told by the docs to expect an extra re-encode rather than a no-op.
 *
 * The two things that are correct, and are pinned here as the other two arms:
 *
 *  1. the **supported** rollback is the admin API (`transcoding.segment_format = mpegts`). That
 *     makes the fingerprint suffix `|mpegts`, so the key moves and a fresh `.ts` job is created;
 *  2. a **code** revert must move all three constants together — `DEFAULT_SEGMENT_FORMAT`, the
 *     `config/transcoding.php` literal AND `JOB_KEY_VERSION` back to `v9`. Only the version
 *     component can separate a v10 fMP4 directory from a v9 MPEG-TS one.
 *
 * ## Why these cases are not tautologies
 *
 * Every claim about a rollback compares a **typed hex literal** (computed by hand, recorded in
 * `SHIPPED_KEY` / `PRE_S60_KEY` / `ADMIN_ROLLBACK_KEY`) against a key **captured out of
 * production** — the value `ensureHlsJob()` actually put on the wire. Reverting
 * `JOB_KEY_VERSION` to `v9` in `src/` reds
 * {@see self::test_a_partial_code_revert_collides_with_the_v10_fmp4_jobs()} and
 * {@see self::test_only_the_full_three_constant_revert_escapes_the_v10_jobs()}; making the
 * fingerprint non-empty at the shipped default reds the same two.
 *
 * The one step that cannot be measured directly is the partial revert itself, because it needs
 * `DEFAULT_SEGMENT_FORMAT` to hold a different value than the one compiled in. Its premise is
 * measured instead: `fingerprint()`'s emptiness is decided by `=== DEFAULT_SEGMENT_FORMAT`, not
 * by the string `fmp4` — asserted from both sides in
 * {@see self::test_the_fingerprint_is_empty_for_whatever_the_constant_says_not_for_fmp4()}.
 *
 * @see \Phlix\Tests\Unit\Media\Transcoding\EncodeSettingsSegmentFormatTest for the fingerprint
 *      in isolation.
 */
final class TranscodeManagerRollbackKeyTest extends TestCase
{
    /**
     * The key an untouched install produces TODAY for `media-1` + profile `web`:
     * `sha1('media-1|web|v10')`, the fingerprint contributing nothing.
     *
     * **This is also the key a PARTIAL code revert produces** — reverting the two default
     * literals touches no component of the string.
     */
    private const SHIPPED_KEY = '5b128f54a500b8e5d263410c0e35003e707ee9ba';

    /**
     * The key the SAME media+profile produced from S49 until S60: `sha1('media-1|web|v9')`.
     * Reaching this key again is what a rollback has to do to get back to the `.ts`
     * directories, and only reverting `JOB_KEY_VERSION` gets there.
     */
    private const PRE_S60_KEY = '8927fb2b25eafc016ec7c7f405d51ac810ef4d21';

    /**
     * The key the SUPPORTED rollback produces: `transcoding.segment_format = mpegts` over the
     * admin API, leaving every constant alone. The container is no longer the default, so the
     * fingerprint suffix is `|mpegts` and the key is
     * `sha1('media-1|web|v10' . substr(sha1('veryfast|23|128k|mpegts'), 0, 12))`.
     */
    private const ADMIN_ROLLBACK_KEY = '950850334be585e55c62685240b92187cd879b7b';

    /** The `transcode_jobs` id of the one v10 fMP4 job the simulated table holds. */
    private const FMP4_JOB_ID = 'v10-fmp4-job';

    private string $segmentDir;

    protected function setUp(): void
    {
        $this->segmentDir = sys_get_temp_dir() . '/phlix_s60_rollback_' . uniqid();
        mkdir($this->segmentDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->segmentDir);
    }

    // ─────────────────────────────────────────────────────────────────
    // the corrected claim
    // ─────────────────────────────────────────────────────────────────

    /**
     * ⚠ THE CORRECTED RUNBOOK CLAIM: a partial code revert is a silent NO-OP, not a second
     * fleet-wide re-encode.
     *
     * The simulated table holds exactly one job — a v10 fMP4 one, inserted under
     * {@see self::SHIPPED_KEY} — and the reuse lookup answers only for that key. An untouched
     * install asks for it and gets it back, which is the shipped behaviour. A partial revert
     * asks for the SAME key (the assertions below establish that every component of the key
     * string is untouched by such a revert), so it gets the same fMP4 job back: `.m4s`
     * playlists, `.m4s` segments, a `manifest.mpd`, on a box whose config now says `mpegts`.
     *
     * The captured SELECT is asserted to carry no container predicate at all, so the fMP4-ness
     * of the reused job cannot save the operator: `findReusableJob()` has no way to notice.
     */
    public function test_a_partial_code_revert_collides_with_the_v10_fmp4_jobs(): void
    {
        $captured = [];
        $db = $this->tableHoldingOneFmp4JobAt(self::SHIPPED_KEY, $captured);

        $result = $this->manager($db, null)->ensureHlsJob('media-1', 'web');

        // 1. Production really did look up (and insert under) the v10 key.
        $this->assertSame(self::SHIPPED_KEY, $this->reuseLookupKey($captured));

        // 2. Nothing in the key string moves when only the two default literals move:
        //    the media id and the profile are the caller's, JOB_KEY_VERSION is not touched by
        //    such a revert, and the fingerprint is '' at the shipped default either side of it.
        $this->assertSame(
            '',
            (new EncodeSettings())->fingerprint(),
            'the fingerprint contributes nothing to the key at the shipped default, so reverting '
            . 'the default cannot move the key'
        );
        $this->assertSame(
            self::SHIPPED_KEY,
            sha1('media-1|web|v10'),
            'control: the shipped key is the key of a string with NO container in it'
        );

        // 3. The consequence, at the method that has to notice and cannot.
        $this->assertTrue(
            $result['reused'],
            'a request at the v10 key must land on the pre-existing v10 fMP4 job — which is '
            . 'exactly what a partially-reverted install still asks for'
        );
        $this->assertSame(self::FMP4_JOB_ID, $result['job_id']);
        $this->assertSame(
            '/dash/' . self::FMP4_JOB_ID . '/manifest.mpd',
            $result['dash_url'],
            'the reused job is unambiguously the fMP4 one: it published a DASH manifest, which '
            . 'no MPEG-TS job ever does'
        );

        $reuseSql = $this->reuseLookupSql($captured);
        $this->assertStringNotContainsString(
            'segment_format',
            $reuseSql,
            'findReusableJob() has no container predicate, so it cannot decline an fMP4 job for '
            . 'an install that has just reverted its default to mpegts'
        );
    }

    /**
     * The premise the case above rests on, measured from both sides: `fingerprint()` is empty
     * for whatever {@see EncodeSettings::DEFAULT_SEGMENT_FORMAT} names, NOT for the string
     * `fmp4`.
     *
     * That is what makes "a partial revert leaves the fingerprint empty" a statement about the
     * code rather than an assumption: with the constant reverted to `mpegts`, `mpegts` becomes
     * the free member and `''` is what an install with no override produces — the same `''`
     * measured here. (It is also what an install produced for every release from S56 to S59,
     * pinned as a literal in `EncodeSettingsSegmentFormatTest`.)
     */
    public function test_the_fingerprint_is_empty_for_whatever_the_constant_says_not_for_fmp4(): void
    {
        // No override at all — the shipped default, resolved through the constant.
        $this->assertSame('', (new EncodeSettings())->fingerprint());

        // Explicitly choosing the value the CONSTANT names is the same thing.
        $this->assertSame(
            '',
            $this->settingsWithFormat(EncodeSettings::DEFAULT_SEGMENT_FORMAT)->fingerprint()
        );

        // And the other member of the enum is NOT free — so the emptiness above is decided by
        // the comparison against the constant, not by the method being inert.
        $this->assertNotSame('', $this->settingsWithFormat(EncodeSettings::FORMAT_MPEGTS)->fingerprint());
        $this->assertSame(
            EncodeSettings::FORMAT_FMP4,
            EncodeSettings::DEFAULT_SEGMENT_FORMAT,
            'control: `mpegts` is the non-default member TODAY — which is exactly the pair a '
            . 'partial revert swaps'
        );
    }

    /**
     * The correct code rollback: all three constants together.
     *
     * `JOB_KEY_VERSION` is the only component of the key that a code revert can move, so it is
     * the only thing that can put a request back on the pre-S60 `.ts` directories. This case
     * measures the production key against BOTH literals — same-as-v10 and not-v9 — so reverting
     * the constant in `src/` while leaving the two defaults alone reds it.
     */
    public function test_only_the_full_three_constant_revert_escapes_the_v10_jobs(): void
    {
        $captured = [];
        $db = $this->tableHoldingOneFmp4JobAt(self::SHIPPED_KEY, $captured);

        $this->manager($db, null)->ensureHlsJob('media-1', 'web');
        $liveKey = $this->reuseLookupKey($captured);

        $this->assertSame(
            self::SHIPPED_KEY,
            $liveKey,
            'the shipped install asks under the v10 key'
        );
        $this->assertNotSame(
            self::PRE_S60_KEY,
            $liveKey,
            'a full revert (DEFAULT_SEGMENT_FORMAT + config literal + JOB_KEY_VERSION back to v9) '
            . 'is what returns an install to the pre-S60 key — if this ever passes, the version '
            . 'component has been reverted without the defaults'
        );
        $this->assertNotSame(
            self::SHIPPED_KEY,
            self::PRE_S60_KEY,
            'control: the two literals really are different keys'
        );
    }

    /**
     * The supported rollback, and the one the docs were right about: a PUT of
     * `transcoding.segment_format = mpegts` moves the key, so the v10 fMP4 job in the table is
     * NOT reused and a fresh `.ts` job is created instead.
     *
     * Same simulated table as the partial-revert case — one v10 fMP4 job, reachable only at
     * {@see self::SHIPPED_KEY} — so the two cases are each other's control: identical fixture,
     * opposite outcome, and the only difference is which rollback route was taken.
     */
    public function test_the_admin_api_rollback_moves_the_key_and_gets_a_fresh_job(): void
    {
        $captured = [];
        $db = $this->tableHoldingOneFmp4JobAt(self::SHIPPED_KEY, $captured);

        $result = $this
            ->manager($db, EncodeSettings::FORMAT_MPEGTS)
            ->ensureHlsJob('media-1', 'web');

        $this->assertSame(
            self::ADMIN_ROLLBACK_KEY,
            $this->reuseLookupKey($captured),
            'the `|mpegts` fingerprint suffix must move the key off the shipped one'
        );
        $this->assertNotSame(self::SHIPPED_KEY, $this->reuseLookupKey($captured));
        $this->assertFalse(
            $result['reused'],
            'the supported rollback must NOT re-enter the fMP4 job directory'
        );
        $this->assertNotSame(self::FMP4_JOB_ID, $result['job_id']);
        $this->assertNull(
            $result['dash_url'],
            'the fresh job is MPEG-TS: no manifest.mpd, so no DASH endpoint advertised'
        );

        // And the fresh row is persisted under the moved key, so the NEXT play reuses the `.ts`
        // job rather than falling back onto the fMP4 one.
        $this->assertSame(self::ADMIN_ROLLBACK_KEY, $this->insertedKey($captured));
    }

    /**
     * ⚠ This case measures NOTHING about `src/`. It guards the three hex literals above against
     * a typo, by re-deriving each from the string it documents. Every claim this file makes
     * about production is in the other cases, where a literal is compared with a key captured
     * out of `ensureHlsJob()`.
     */
    public function test_control_the_hex_literals_in_this_file_are_the_documented_sha1s(): void
    {
        $this->assertSame(self::SHIPPED_KEY, sha1('media-1|web|v10'));
        $this->assertSame(self::PRE_S60_KEY, sha1('media-1|web|v9'));
        $this->assertSame(
            self::ADMIN_ROLLBACK_KEY,
            sha1('media-1|web|v10' . substr(sha1('veryfast|23|128k|mpegts'), 0, 12))
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // harness
    // ─────────────────────────────────────────────────────────────────

    /**
     * A `transcode_jobs` table holding exactly ONE job: a completed v10 fMP4 job whose
     * directory exists and carries a `manifest.mpd`, reachable only at `$key`.
     *
     * The manifest is what makes "the reused job is the fMP4 one" observable from the return
     * value rather than from the fixture: `dashManifestUrl()` consults the disk, and only an
     * fMP4 job ever publishes one.
     *
     * @param array<int, array{0: string, 1: array<int, mixed>}> $captured Receives [sql, params].
     */
    private function tableHoldingOneFmp4JobAt(string $key, array &$captured): Connection
    {
        $dir = $this->segmentDir . '/' . self::FMP4_JOB_ID;
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/' . TranscodeManager::MPD_FILENAME, '<?xml version="1.0"?><MPD/>');
        file_put_contents($dir . '/init-v720p.m4s', 'init');

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            /** @return mixed */
            function (string $sql, ?array $params = null) use ($key, $dir, &$captured) {
                $captured[] = [$sql, $params ?? []];

                if (str_contains($sql, 'key_hash = ?') && str_contains($sql, 'IN (')) {
                    return ($params[0] ?? null) === $key
                        ? [[
                            'id' => self::FMP4_JOB_ID,
                            'hls_dir' => $dir,
                            'status' => 'completed',
                        ]]
                        : [];
                }
                if (str_contains($sql, 'COUNT(*)')) {
                    return [['c' => 0]];
                }
                if (str_contains($sql, 'FROM media_streams')) {
                    return [];
                }
                if (str_contains($sql, 'FROM media_items')) {
                    return [['path' => '/m.mkv']];
                }
                if (str_contains($sql, 'transcode_jobs WHERE id = ?')) {
                    return [['status' => 'completed']];
                }
                return [];
            }
        );

        return $db;
    }

    /**
     * A manager over `$db`, with `transcoding.segment_format` either left at the shipped
     * default (`null`) or pinned explicitly.
     */
    private function manager(Connection $db, ?string $segmentFormat): TranscodeManager
    {
        $ff = $this->createMock(FfmpegRunner::class);
        $this->stubProbe($ff);

        if ($segmentFormat === null) {
            return new TranscodeManager($db, $ff, $this->segmentDir, null, 6);
        }

        return new TranscodeManager(
            $db,
            $ff,
            $this->segmentDir,
            null,
            6,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            $this->settingsWithFormat($segmentFormat)
        );
    }

    private function settingsWithFormat(string $segmentFormat): EncodeSettings
    {
        $repo = $this->createMock(SettingsRepository::class);
        $repo->method('getEffective')->willReturnCallback(
            /** @return mixed */
            static fn (string $key) => $key === EncodeSettings::SEGMENT_FORMAT_KEY ? $segmentFormat : null
        );

        return new EncodeSettings($repo);
    }

    /**
     * @param FfmpegRunner&MockObject $ff
     */
    private function stubProbe(FfmpegRunner $ff): void
    {
        $ff->method('probe')->willReturn([
            'streams' => [
                ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1280, 'height' => 720],
                ['codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 2],
            ],
            'format' => ['duration' => '25.0'],
        ]);
        $ff->method('extractColorMetadata')->willReturn([
            'color_space' => 'bt709',
            'color_transfer' => 'bt709',
            'color_primaries' => 'bt709',
            'max_luminance' => 1000.0,
            'avg_luminance' => 200.0,
        ]);
    }

    /**
     * The key `findReusableJob()` looked the job up under.
     *
     * @param array<int, array{0: string, 1: array<int, mixed>}> $captured
     */
    private function reuseLookupKey(array $captured): string
    {
        foreach ($captured as [$sql, $params]) {
            if (str_contains($sql, 'key_hash = ?') && str_contains($sql, 'IN (')) {
                $key = $params[0] ?? null;
                return is_string($key) ? $key : '';
            }
        }
        $this->fail('no reuse lookup was captured');
    }

    /**
     * @param array<int, array{0: string, 1: array<int, mixed>}> $captured
     */
    private function reuseLookupSql(array $captured): string
    {
        foreach ($captured as [$sql, $_params]) {
            if (str_contains($sql, 'key_hash = ?') && str_contains($sql, 'IN (')) {
                return $sql;
            }
        }
        $this->fail('no reuse lookup was captured');
    }

    /**
     * The key the freshly-created job row was persisted under (placeholder 6 = key_hash).
     *
     * @param array<int, array{0: string, 1: array<int, mixed>}> $captured
     */
    private function insertedKey(array $captured): string
    {
        foreach ($captured as [$sql, $params]) {
            if (str_contains($sql, 'INSERT INTO transcode_jobs')) {
                $key = $params[6] ?? null;
                return is_string($key) ? $key : '';
            }
        }
        $this->fail('no transcode_jobs INSERT was captured');
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = "{$dir}/{$entry}";
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
