<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Streaming\Dash\AdaptationSet;
use Phlix\Media\Streaming\Rendition;
use Phlix\Media\Transcoding\EncodeSettings;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\TranscodeManager;
use ReflectionMethod;
use Workerman\MySQL\Connection;

/**
 * Fix A′′ (playback-lag program, 2026-11-06) — every VIDEO rung gets a
 * per-rung mini-master INDEX so direct rung loads discover the audio group.
 *
 * This file supersedes the Fix 3 law (b0387c10, same day). Fix 3 emitted the
 * `#EXT-X-MEDIA:TYPE=AUDIO` group lines INSIDE each video rung's media
 * playlist. That was spec-invalid for the actual client: hls.js 1.7.4 parses
 * media playlists with NO EXT-X-MEDIA handling at all (its playlist router
 * keys media-vs-master detection on `#EXTINF`/`#EXT-X-TARGETDURATION`, and
 * `audioTracks` are populated ONLY by `parseMasterPlaylistMedia` on the
 * master path, surfaced via `onManifestParsed`). The lines sat in the rung
 * bytes inert; live haproxy captures confirmed the failure persisted
 * (master → media_voriginal → video segments only, ZERO media_a/seg-a
 * requests ever). The player's pinned-'Original'/quality path loads a rung
 * playlist directly (the variant is learned from the POST /transcode variants
 * JSON, not from the master — SV-4.6 withholds the copy rung from it), so it
 * needs a MASTER-SHAPED document to load.
 *
 * The shipped law under test: `media_v{id}.m3u8` keeps its original
 * pre-Fix-3 bytes (zero group lines, every job, both containers — the
 * `-an` segments remain announced nowhere but the group documents), and the
 * writer additionally emits `media_v{id}_master.m3u8` per rung: the exact
 * same multi-variant master shape with exactly ONE `#EXT-X-STREAM-INF` —
 * this rung — plus the identical `aud`-group lines when the job has them.
 * Single-level ABR is a no-op in hls.js; loading the index collects the
 * group, auto-selects `DEFAULT=YES`, fetches `seg-a*` alongside the video,
 * and activates the already-wired track menu. The index is ALWAYS written —
 * with no group (single-audio/muxed) it is a legal one-variant master, so
 * client code needs one uniform URL rule for both cases.
 *
 * Anti-drift law, continued from Fix 3: the master and every rung index
 * render the group through ONE method ({@see
 * \Phlix\Media\Transcoding\TranscodeManager::audioGroupRenditionLines()}),
 * so the lines are asserted EQUAL (attribute order included), not merely
 * present. The bare rung and audio-only playlists must carry NO group
 * lines; the master's shipped bytes must not move; and the self-heal rail
 * (`ensurePlaylistRegenerated()`) must cure pre-fix job directories — they
 * have a master, so the old master-only presence check would short-circuit
 * forever and leave every existing title silent.
 *
 * Both containers are covered because the container branch lives INSIDE the
 * builders (S57 law): `mpegts` is the rollback + every pre-S60 job, `fmp4` is
 * the shipped default — the silence reproduced on both.
 *
 * @see \Phlix\Tests\Unit\Media\Transcoding\TranscodeManagerPlaylistFormatTest
 *      for the byte-golden culture this file continues (literals captured
 *      against the shipped master bytes, never re-derived from new code).
 */
final class TranscodeManagerAudioGroupPlaylistTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────────
    // Byte goldens.
    //
    // The MASTER golden is copied from the S57 flag-off literal in
    // TranscodeManagerPlaylistFormatTest (captured from origin/master @
    // f9f31f4f) — it must keep passing untouched: this fix is purely
    // ADDITIVE on the master, and this file is where an accidental change
    // to the group renderer or the master builder would first notice.
    //
    // The RUNG goldens encode the RESTORED pre-Fix-3 shape (zero group
    // lines) — the same no-group bytes Fix 3 pinned as its "must not move"
    // control, promoted to the law for EVERY job, multi-audio included.
    //
    // The INDEX goldens are the master shape restricted to one variant:
    // captured from `buildMultiVariantMaster([$rung], …)` semantics, i.e.
    // the shipped master's exact bytes for a ladder of one, never
    // hand-dressed from the new code's output.
    // ─────────────────────────────────────────────────────────────────

    private const EXPECTED_MASTER = "#EXTM3U\n"
        . "#EXT-X-VERSION:3\n"
        . "#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID=\"aud\",NAME=\"English\",LANGUAGE=\"eng\","
        . "DEFAULT=YES,AUTOSELECT=YES,URI=\"media_a0.m3u8\"\n"
        . "#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID=\"aud\",NAME=\"Francais\",LANGUAGE=\"fra\","
        . "DEFAULT=NO,AUTOSELECT=YES,URI=\"media_a1.m3u8\"\n"
        . "#EXT-X-STREAM-INF:BANDWIDTH=5128000,RESOLUTION=1920x1080,CODECS=\"avc1.640029,mp4a.40.2\","
        . "AUDIO=\"aud\"\n"
        . "media_v1080p.m3u8\n"
        . "#EXT-X-STREAM-INF:BANDWIDTH=3128000,RESOLUTION=1280x720,CODECS=\"avc1.640029,mp4a.40.2\","
        . "AUDIO=\"aud\"\n"
        . "media_v720p.m3u8\n";

    private const GROUP_LINE_EN = '#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID="aud",NAME="English",LANGUAGE="eng",'
        . 'DEFAULT=YES,AUTOSELECT=YES,URI="media_a0.m3u8"';

    private const GROUP_LINE_FR = '#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID="aud",NAME="Francais",LANGUAGE="fra",'
        . 'DEFAULT=NO,AUTOSELECT=YES,URI="media_a1.m3u8"';

    /** The restored law for EVERY rung media playlist (both Fix 3 cases too). */
    private const EXPECTED_RUNG_MPEGTS = "#EXTM3U\n"
        . "#EXT-X-VERSION:3\n"
        . "#EXT-X-PLAYLIST-TYPE:VOD\n"
        . "#EXT-X-TARGETDURATION:6\n"
        . "#EXT-X-MEDIA-SEQUENCE:0\n"
        . "#EXT-X-INDEPENDENT-SEGMENTS\n"
        . "#EXTINF:6.000000,\n"
        . "seg-v720p-00000.ts\n"
        . "#EXTINF:6.000000,\n"
        . "seg-v720p-00001.ts\n"
        . "#EXTINF:6.000000,\n"
        . "seg-v720p-00002.ts\n"
        . "#EXTINF:6.000000,\n"
        . "seg-v720p-00003.ts\n"
        . "#EXTINF:1.000000,\n"
        . "seg-v720p-00004.ts\n"
        . "#EXT-X-ENDLIST\n";

    private const EXPECTED_RUNG_FMP4 = "#EXTM3U\n"
        . "#EXT-X-VERSION:7\n"
        . "#EXT-X-PLAYLIST-TYPE:VOD\n"
        . "#EXT-X-TARGETDURATION:6\n"
        . "#EXT-X-MEDIA-SEQUENCE:0\n"
        . "#EXT-X-INDEPENDENT-SEGMENTS\n"
        . "#EXT-X-MAP:URI=\"init-v720p.m4s\"\n"
        . "#EXTINF:6.000000,\n"
        . "seg-v720p-00000.m4s\n"
        . "#EXTINF:6.000000,\n"
        . "seg-v720p-00001.m4s\n"
        . "#EXTINF:6.000000,\n"
        . "seg-v720p-00002.m4s\n"
        . "#EXTINF:6.000000,\n"
        . "seg-v720p-00003.m4s\n"
        . "#EXTINF:1.000000,\n"
        . "seg-v720p-00004.m4s\n"
        . "#EXT-X-ENDLIST\n";

    /** Per-rung index over the 720p rung of the multi-audio job (mpegts). */
    private const EXPECTED_INDEX_720P_MPEGTS = "#EXTM3U\n"
        . "#EXT-X-VERSION:3\n"
        . self::GROUP_LINE_EN . "\n"
        . self::GROUP_LINE_FR . "\n"
        . "#EXT-X-STREAM-INF:BANDWIDTH=3128000,RESOLUTION=1280x720,CODECS=\"avc1.640029,mp4a.40.2\","
        . "AUDIO=\"aud\"\n"
        . "media_v720p.m3u8\n";

    /** Per-rung index over a NO-group job's rung: legal one-variant master. */
    private const EXPECTED_INDEX_720P_NO_GROUP = "#EXTM3U\n"
        . "#EXT-X-VERSION:3\n"
        . "#EXT-X-STREAM-INF:BANDWIDTH=3128000,RESOLUTION=1280x720,CODECS=\"avc1.640029,mp4a.40.2\"\n"
        . "media_v720p.m3u8\n";

    /** The copy-'original' rung (1080p geometry) as withheld by SV-4.6. */
    private const EXPECTED_INDEX_ORIGINAL_MPEGTS = "#EXTM3U\n"
        . "#EXT-X-VERSION:3\n"
        . self::GROUP_LINE_EN . "\n"
        . self::GROUP_LINE_FR . "\n"
        . "#EXT-X-STREAM-INF:BANDWIDTH=5128000,RESOLUTION=1920x1080,CODECS=\"avc1.640029,mp4a.40.2\","
        . "AUDIO=\"aud\"\n"
        . "media_voriginal.m3u8\n";

    // ─────────────────────────────────────────────────────────────────
    // AUDIO-DECLARATION law (2026-11-06, this file's second same-day
    // contract). Fix A′′ made audio DISCOVERABLE; this law makes it
    // TRUTHFUL. The rung CODECS attribute is the client contract: hls.js
    // surfaces STREAM-INF `audioCodec` and phlix-ui 3174b805
    // (hls-playback.ts codecStringCarriesAudio → Player.vue
    // hasOriginalAudio) synthesizes the single 'Original' track entry
    // from it. Three shapes, three truths:
    //
    //  * muxed single-audio (count 1, no group): the segments DO carry
    //    AAC (`-map 0:a:0`, `-c:a aac`/copy) → CODECS keeps `mp4a.40.2`
    //    and the UI's 'Original' entry is real. No group lines — there
    //    are no `media_a` renditions to point at. (Pre-existing bytes,
    //    pinned as law here.)
    //  * multi-audio (group): AUDIO="aud" + rendition lines carry the
    //    truth; combined CODECS stays (it describes variant PLUS group —
    //    and the renditions ARE aac re-encodes). (Pinned by the Fix A′′
    //    goldens above; re-pinned here WITH the count set, proving the
    //    group wins over the flag.)
    //  * silent source (count 0): `-map 0:a:0?` is OPTIONAL — it mapped
    //    NOTHING. Every declaration must go: no group lines (never had
    //    any) AND no audio codec entry (the defect this law cures: the
    //    pre-law bytes claimed `mp4a.40.2` on video-only segments, so
    //    silent titles grew a phantom 'Original').
    //
    // Legacy null (rows persisted before the law, no `audio_stream_count`
    // key): unknown ≠ zero — the pre-law bytes stand, disclosed.
    // ─────────────────────────────────────────────────────────────────

    /** Master over a silent two-rung ladder: every CODECS audio entry gone. */
    private const EXPECTED_MASTER_SILENT = "#EXTM3U\n"
        . "#EXT-X-VERSION:3\n"
        . "#EXT-X-STREAM-INF:BANDWIDTH=5128000,RESOLUTION=1920x1080,CODECS=\"avc1.640029\"\n"
        . "media_v1080p.m3u8\n"
        . "#EXT-X-STREAM-INF:BANDWIDTH=3128000,RESOLUTION=1280x720,CODECS=\"avc1.640029\"\n"
        . "media_v720p.m3u8\n";

    /** A silent source's per-rung index: one level, no audio claim anywhere. */
    private const EXPECTED_INDEX_720P_SILENT = "#EXTM3U\n"
        . "#EXT-X-VERSION:3\n"
        . "#EXT-X-STREAM-INF:BANDWIDTH=3128000,RESOLUTION=1280x720,CODECS=\"avc1.640029\"\n"
        . "media_v720p.m3u8\n";

    private string $segmentDir;

    protected function setUp(): void
    {
        $this->segmentDir = sys_get_temp_dir() . '/phlix_mini_' . uniqid();
        mkdir($this->segmentDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->segmentDir);
    }

    // ─────────────────────────────────────────────────────────────────
    // The rung media playlists: zero group lines, every job, both
    // containers (the Fix 3 emit is REVERTED — hls.js cannot parse it
    // there, so the bytes were inert weight).
    // ─────────────────────────────────────────────────────────────────

    public function test_the_multi_audio_mpegts_rung_is_the_original_no_group_bytes(): void
    {
        $dir = $this->segmentDir . '/rung-ts';
        mkdir($dir, 0755, true);
        $this->writePlaylists($dir, $this->ladder(), $this->audioTracks(), EncodeSettings::FORMAT_MPEGTS);

        // The exact shipped pre-Fix-3 bytes — pinned against a literal, so
        // the revert is byte-proven, not "no group lines in some shape".
        $this->assertSame(
            self::EXPECTED_RUNG_MPEGTS,
            (string) file_get_contents("{$dir}/media_v720p.m3u8")
        );
        $this->assertStringNotContainsString(
            '#EXT-X-MEDIA:TYPE=AUDIO',
            (string) file_get_contents("{$dir}/media_v1080p.m3u8")
        );
    }

    public function test_the_multi_audio_fmp4_rung_is_the_original_no_group_bytes(): void
    {
        $dir = $this->segmentDir . '/rung-fmp4';
        mkdir($dir, 0755, true);
        $this->writePlaylists($dir, $this->ladder(), $this->audioTracks(), EncodeSettings::FORMAT_FMP4);

        $this->assertSame(
            self::EXPECTED_RUNG_FMP4,
            (string) file_get_contents("{$dir}/media_v720p.m3u8")
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // The per-rung mini-master indexes: the shape that actually fixes
    // the silence — a MASTER-parsed document naming exactly this rung.
    // ─────────────────────────────────────────────────────────────────

    public function test_a_multi_audio_rung_index_carries_the_masters_group_lines_and_one_level(): void
    {
        $dir = $this->segmentDir . '/index';
        mkdir($dir, 0755, true);
        $this->writePlaylists($dir, $this->ladder(), $this->audioTracks(), EncodeSettings::FORMAT_MPEGTS);

        $master = (string) file_get_contents("{$dir}/master.m3u8");
        foreach (['media_v1080p_master.m3u8', 'media_v720p_master.m3u8'] as $name) {
            $this->assertFileExists("{$dir}/{$name}", $name);
            $text = (string) file_get_contents("{$dir}/{$name}");
            // Equality, not presence: same lines, same order, same attribute
            // order. Two renderers that may drift is the defect class itself.
            $this->assertSame($this->groupLines($master), $this->groupLines($text), $name);
            $this->assertCount(2, $this->groupLines($text), $name);
            $this->assertSame(1, substr_count($text, '#EXT-X-STREAM-INF'), $name);
        }

        $this->assertSame(
            self::EXPECTED_INDEX_720P_MPEGTS,
            (string) file_get_contents("{$dir}/media_v720p_master.m3u8")
        );
    }

    public function test_the_copy_original_rung_gets_its_index_while_staying_out_of_the_master(): void
    {
        $dir = $this->segmentDir . '/ladder';
        mkdir($dir, 0755, true);
        $this->writePlaylists(
            $dir,
            $this->ladderWithCopyOriginal(),
            $this->audioTracks(),
            EncodeSettings::FORMAT_MPEGTS
        );

        // The exact production defect: the pinned-'Original' path is a stream
        // COPY withheld from the master by SV-4.6, yet encoded `-an` like
        // every other rung. Its index is the ONLY document from which a
        // direct load can discover audio.
        $this->assertSame(
            self::EXPECTED_INDEX_ORIGINAL_MPEGTS,
            (string) file_get_contents("{$dir}/media_voriginal_master.m3u8")
        );
        $this->assertStringNotContainsString('media_voriginal', (string) file_get_contents("{$dir}/master.m3u8"));
        // And the bare copy rung itself stayed silent-bytes (no group lines).
        $this->assertStringNotContainsString(
            '#EXT-X-MEDIA:TYPE=AUDIO',
            (string) file_get_contents("{$dir}/media_voriginal.m3u8")
        );
    }

    /**
     * The uniform-URL promise: with no group to advertise, the index is a
     * legal one-variant master WITHOUT any EXT-X-MEDIA — the client loads
     * `media_v{id}_master.m3u8` for BOTH job shapes.
     */
    public function test_a_single_audio_job_gets_a_group_free_one_variant_index(): void
    {
        $dir = $this->segmentDir . '/single';
        mkdir($dir, 0755, true);
        $solo = [$this->audioTracks()[0]];
        $this->writePlaylists($dir, $this->ladder(), $solo, EncodeSettings::FORMAT_MPEGTS);

        $this->assertSame(
            self::EXPECTED_INDEX_720P_NO_GROUP,
            (string) file_get_contents("{$dir}/media_v720p_master.m3u8")
        );
        $this->assertFileExists("{$dir}/media_v1080p_master.m3u8");
        foreach (glob("{$dir}/*.m3u8") ?: [] as $file) {
            $this->assertStringNotContainsString(
                '#EXT-X-MEDIA:TYPE=AUDIO',
                (string) file_get_contents($file),
                basename($file)
            );
        }
        $this->assertFileDoesNotExist("{$dir}/media_a0.m3u8", 'a one-track list writes no audio-only playlists');
    }

    /**
     * The indexes are a client URL CONVENTION (the UI derives them from the
     * variants JSON), not an advertisement: the master must not name them —
     * a master that listed an index would make every rung A/B-ambiguous for
     * a height-resolving ladder menu.
     */
    public function test_the_master_never_references_the_mini_master_indexes(): void
    {
        $dir = $this->segmentDir . '/nolink';
        mkdir($dir, 0755, true);
        $this->writePlaylists($dir, $this->ladder(), $this->audioTracks(), EncodeSettings::FORMAT_MPEGTS);

        $this->assertStringNotContainsString('_master', (string) file_get_contents("{$dir}/master.m3u8"));
    }

    // ─────────────────────────────────────────────────────────────────
    // The AUDIO-DECLARATION law (see the goldens above): CODECS truth per
    // source shape, asserted through the SAME matcher the client uses.
    // ─────────────────────────────────────────────────────────────────

    /**
     * Shape (b) of the shipped ui contract: a muxed single-audio job has
     * no group to advertise, so the ONE carrier is the CODECS attribute —
     * and it must hit the client matcher for the synthesized 'Original'
     * entry to appear. Byte-equality against the pre-law golden proves the
     * declaration survives untouched; the matcher assertion proves the
     * SURVIVAL is what the ui consumes (this literal is the law
     * TranscodeManager relied on before it was ever named).
     */
    public function test_a_muxed_single_audio_job_declares_audio_through_codecs_alone(): void
    {
        $dir = $this->segmentDir . '/declare-muxed';
        mkdir($dir, 0755, true);
        $this->writePlaylists($dir, $this->ladder(), null, EncodeSettings::FORMAT_MPEGTS, 1);

        $index = (string) file_get_contents("{$dir}/media_v720p_master.m3u8");
        $this->assertSame(self::EXPECTED_INDEX_720P_NO_GROUP, $index);
        $this->assertSame([], $this->groupLines($index));
        foreach ($this->codecsOf($index) as $codecs) {
            $this->assertTrue(
                $this->uiCarriesAudio($codecs),
                'muxed rungs must match phlix-ui codecStringCarriesAudio: ' . $codecs
            );
        }

        // Legacy unknown (pre-law rows, null) and muxed (count 1) render the
        // SAME bytes — the strip keys on ZERO alone, never on absence.
        $legacyDir = $this->segmentDir . '/declare-muxed-legacy';
        mkdir($legacyDir, 0755, true);
        $this->writePlaylists($legacyDir, $this->ladder(), null, EncodeSettings::FORMAT_MPEGTS);
        $this->assertSame(
            $index,
            (string) file_get_contents("{$legacyDir}/media_v720p_master.m3u8")
        );
    }

    /**
     * Shape (c) — THE DEFECT. A zero-audio source's segments carry no sound
     * (`-map 0:a:0?` mapped nothing), so no document may claim an audio
     * codec. Red against pre-law bytes: the rung CODECS shipped as
     * `avc1.640029,mp4a.40.2` and silent titles grew a phantom 'Original'.
     */
    public function test_a_silent_source_index_declares_no_audio(): void
    {
        $dir = $this->segmentDir . '/declare-silent-index';
        mkdir($dir, 0755, true);
        $this->writePlaylists($dir, $this->ladder(), null, EncodeSettings::FORMAT_MPEGTS, 0);

        $index = (string) file_get_contents("{$dir}/media_v720p_master.m3u8");
        $this->assertSame(self::EXPECTED_INDEX_720P_SILENT, $index);
        $this->assertStringNotContainsString('AUDIO=', $index);
        $this->assertStringNotContainsString('mp4a', $index);
        foreach ($this->codecsOf($index) as $codecs) {
            $this->assertFalse($this->uiCarriesAudio($codecs), $codecs);
        }
    }

    /** The master takes the same strip as its indexes — one flag, one truth. */
    public function test_a_silent_source_master_declares_no_audio(): void
    {
        $dir = $this->segmentDir . '/declare-silent-master';
        mkdir($dir, 0755, true);
        $this->writePlaylists($dir, $this->ladder(), null, EncodeSettings::FORMAT_MPEGTS, 0);

        $this->assertSame(
            self::EXPECTED_MASTER_SILENT,
            (string) file_get_contents("{$dir}/master.m3u8")
        );
    }

    /**
     * Shape (a): the group always wins over the flag — with a group present
     * the combined CODECS (variant PLUS `AUDIO=` group semantics) and the
     * rendition lines must not move, count ≥ 2 notwithstanding. A mutation
     * that stripped alongside a group would break the multi-audio sound
     * announcement exactly as loudly as the phantom fix would silence it.
     */
    public function test_the_declaration_law_never_touches_a_grouped_job(): void
    {
        $dir = $this->segmentDir . '/declare-grouped';
        mkdir($dir, 0755, true);
        $this->writePlaylists($dir, $this->ladder(), $this->audioTracks(), EncodeSettings::FORMAT_MPEGTS, 2);

        $this->assertSame(
            self::EXPECTED_INDEX_720P_MPEGTS,
            (string) file_get_contents("{$dir}/media_v720p_master.m3u8")
        );
        $this->assertSame(
            self::EXPECTED_MASTER,
            (string) file_get_contents("{$dir}/master.m3u8")
        );
    }

    /**
     * DASH carries the same law: `@codecs` is the EXHAUSTIVE contents of a
     * Representation — even stricter than HLS. Group case keeps its pre-law
     * strip (S58); the silent case joins it; the muxed case keeps claiming.
     */
    public function test_the_declaration_law_reaches_the_dash_representations(): void
    {
        $strip = function (?int $count): array {
            $sets = $this->buildSets($count);
            $codecs = [];
            foreach ($sets as $set) {
                if ($set->contentType !== 'video') {
                    continue;
                }
                foreach ($set->representations as $representation) {
                    $codecs[] = $representation->codecs;
                }
            }

            return $codecs;
        };

        $this->assertSame(
            ['avc1.640029', 'avc1.640029'],
            $strip(0),
            'silent video Representations advertise video only'
        );
        $this->assertSame(
            ['avc1.640029,mp4a.40.2', 'avc1.640029,mp4a.40.2'],
            $strip(1),
            'muxed single-audio keeps its honest claim'
        );
        // The S58 group case must be driven with REAL descriptors — the
        // strip is a function of the group, not of the count alone (a count
        // with descriptors lost to corruption stays muxed on disk, and the
        // claim remains true of those segments).
        $grouped = function (?int $count): array {
            $sets = $this->buildSets($count, $this->audioTracks());
            $codecs = [];
            foreach ($sets as $set) {
                if ($set->contentType !== 'video') {
                    continue;
                }
                foreach ($set->representations as $representation) {
                    $codecs[] = $representation->codecs;
                }
            }

            return $codecs;
        };
        $this->assertSame(
            ['avc1.640029', 'avc1.640029'],
            $grouped(2),
            'the S58 group strip still applies (audio lives in its own sets)'
        );
        $this->assertSame(
            ['avc1.640029,mp4a.40.2', 'avc1.640029,mp4a.40.2'],
            $strip(null),
            'pre-law bytes do not move'
        );
    }

    /**
     * Regeneration must REPLAY the persisted count, not re-derive (and not
     * forget): an evicted silent job that came back claiming `mp4a.40.2`
     * would re-grow the phantom on every cache sweep.
     */
    public function test_regeneration_replays_the_persisted_audio_count(): void
    {
        $row = $this->silentSourceRow('declare-regen-silent', 0);
        $manager = new TranscodeManager($this->rowDb($row), $this->createMock(FfmpegRunner::class), $this->segmentDir);
        $this->assertTrue($manager->ensurePlaylistRegenerated('declare-regen-silent'));

        // The row is an fMP4 job: the goldens are mpegts-shaped, so the
        // flavour shifts exactly as the shipped S57 law requires (the
        // container branch lives inside the builders; VERSION is the ONLY
        // byte that moves for a master-shaped document).
        $fmp4 = static fn (string $master): string =>
            str_replace('#EXT-X-VERSION:3', '#EXT-X-VERSION:7', $master);
        $this->assertSame(
            $fmp4(self::EXPECTED_INDEX_720P_SILENT),
            (string) file_get_contents("{$row['hls_dir']}/media_v720p_master.m3u8")
        );
        $this->assertSame(
            $fmp4(self::EXPECTED_MASTER_SILENT),
            (string) file_get_contents("{$row['hls_dir']}/master.m3u8")
        );
    }

    /**
     * …and a pre-law row (key absent) regenerates EXACTLY its old bytes —
     * absence means unknown, never zero. The false claim inside such rows
     * is disclosed, not silently rewritten under an evicted job.
     */
    public function test_regeneration_of_a_pre_law_row_keeps_its_bytes(): void
    {
        $row = $this->silentSourceRow('declare-regen-legacy', null);
        $manager = new TranscodeManager($this->rowDb($row), $this->createMock(FfmpegRunner::class), $this->segmentDir);
        $this->assertTrue($manager->ensurePlaylistRegenerated('declare-regen-legacy'));

        $this->assertSame(
            str_replace('#EXT-X-VERSION:3', '#EXT-X-VERSION:7', self::EXPECTED_INDEX_720P_NO_GROUP),
            (string) file_get_contents("{$row['hls_dir']}/media_v720p_master.m3u8")
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // What must NOT move.
    // ─────────────────────────────────────────────────────────────────

    public function test_the_master_bytes_do_not_move_with_the_index_addition(): void
    {
        $this->assertSame(
            self::EXPECTED_MASTER,
            $this->build('buildMultiVariantMaster', [$this->ladder(), $this->audioTracks()])
        );
    }

    public function test_the_audio_only_playlists_carry_no_group_lines(): void
    {
        $dir = $this->segmentDir . '/audioonly';
        mkdir($dir, 0755, true);
        $this->writePlaylists($dir, $this->ladder(), $this->audioTracks(), EncodeSettings::FORMAT_FMP4);

        foreach (['media_a0.m3u8' => 'a0', 'media_a1.m3u8' => 'a1'] as $name => $audioId) {
            $text = (string) file_get_contents("{$dir}/{$name}");
            $this->assertStringNotContainsString('#EXT-X-MEDIA:TYPE=AUDIO', $text, $name);
            // Non-vacuity: this is a real audio-only playlist of the SAME job,
            // carrying its own init map.
            $this->assertStringContainsString("#EXT-X-MAP:URI=\"init-{$audioId}.m4s\"\n", $text, $name);
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // The writer's TWO production callers.
    // ─────────────────────────────────────────────────────────────────

    /**
     * Regeneration (LRU eviction recovery) must produce the IDENTICAL set —
     * the persisted ladder JSON rebuilds rungs AND indexes through the same
     * writer, or an evicted-then-healed job would re-break the pinned-
     * 'Original' path.
     */
    public function test_regeneration_writes_indexes_and_group_free_rungs(): void
    {
        $row = $this->multiAudioRow('mini-regen');

        $manager = new TranscodeManager($this->rowDb($row), $this->createMock(FfmpegRunner::class), $this->segmentDir);
        $this->assertTrue($manager->ensurePlaylistRegenerated('mini-regen'));

        $dir = $row['hls_dir'];
        $master = (string) file_get_contents("{$dir}/master.m3u8");
        $index = (string) file_get_contents("{$dir}/media_v720p_master.m3u8");
        $this->assertSame($this->groupLines($master), $this->groupLines($index));
        $this->assertStringContainsString('media_v720p.m3u8', $index);
        $this->assertSame(self::EXPECTED_RUNG_FMP4, (string) file_get_contents("{$dir}/media_v720p.m3u8"));
        $audio = (string) file_get_contents("{$dir}/media_a0.m3u8");
        $this->assertStringNotContainsString('#EXT-X-MEDIA:TYPE=AUDIO', $audio);
    }

    // ─────────────────────────────────────────────────────────────────
    // The self-heal rail — how pre-fix job directories (the owner's
    // existing titles, e.g. the live 28ca880d dir) are cured WITHOUT
    // deleting anything.
    // ─────────────────────────────────────────────────────────────────

    /**
     * The rail: HlsController probes ONLY the requested file and calls
     * `ensurePlaylistRegenerated()` when a playlist name is missing; a
     * client's first request for `media_voriginal_master.m3u8` against a
     * PRE-FIX directory (master + rungs, zero indexes) must not be
     * short-circuited by master presence.
     */
    public function test_the_rail_cures_a_pre_fix_directory_through_master_presence(): void
    {
        $row = $this->multiAudioRow('mini-heal');
        $dir = $row['hls_dir'];
        mkdir($dir, 0775, true);
        // Ship the live (b0387c10-era) bytes verbatim: fMP4 master WITH the
        // group, rungs carrying Fix 3's inert group lines, and NOT ONE index.
        $masterPre = str_replace('#EXT-X-VERSION:3', '#EXT-X-VERSION:7', self::EXPECTED_MASTER);
        $staleRung = str_replace(
            "#EXT-X-INDEPENDENT-SEGMENTS\n",
            "#EXT-X-INDEPENDENT-SEGMENTS\n" . self::GROUP_LINE_EN . "\n" . self::GROUP_LINE_FR . "\n",
            self::EXPECTED_RUNG_FMP4
        );
        file_put_contents("{$dir}/master.m3u8", $masterPre);
        file_put_contents("{$dir}/media_v720p.m3u8", $staleRung);
        $this->assertSame([], glob("{$dir}/media_v*_master.m3u8") ?: [], 'the fixture is genuinely pre-fix');

        $manager = new TranscodeManager($this->rowDb($row), $this->createMock(FfmpegRunner::class), $this->segmentDir);
        $this->assertTrue(
            $manager->ensurePlaylistRegenerated('mini-heal'),
            'master-present must no longer mean complete-present'
        );

        $this->assertFileExists("{$dir}/media_v720p_master.m3u8");
        $index = (string) file_get_contents("{$dir}/media_v720p_master.m3u8");
        $this->assertStringContainsString(self::GROUP_LINE_EN, $index);
        // The inert Fix 3 emit does NOT survive the heal: the rung comes back
        // as the original no-group media playlist, and the master's shipped
        // bytes do not move.
        $this->assertSame(self::EXPECTED_RUNG_FMP4, (string) file_get_contents("{$dir}/media_v720p.m3u8"));
        $this->assertSame($masterPre, (string) file_get_contents("{$dir}/master.m3u8"));
    }

    /**
     * A partially damaged post-fix set (one index deleted) heals too — the
     * presence law is a rung↔index COUNT, not a boolean "has any index".
     */
    public function test_the_rail_heals_a_directory_missing_exactly_one_index(): void
    {
        $row = $this->multiAudioRow('mini-partial');
        $manager = new TranscodeManager($this->rowDb($row), $this->createMock(FfmpegRunner::class), $this->segmentDir);
        $this->assertTrue($manager->ensurePlaylistRegenerated('mini-partial'));
        $dir = $row['hls_dir'];
        $this->assertCount(2, glob("{$dir}/media_v*_master.m3u8") ?: [], 'control: the full set exists');

        unlink("{$dir}/media_v720p_master.m3u8");
        $this->assertTrue($manager->ensurePlaylistRegenerated('mini-partial'));
        $this->assertFileExists("{$dir}/media_v720p_master.m3u8");
    }

    /**
     * The early-return must STAY an early-return for a complete set — a
     * request storm for one genuinely-missing file must not re-serialise
     * every playlist of a healthy job on every hit.
     */
    public function test_a_complete_directory_is_left_byte_untouched(): void
    {
        $row = $this->multiAudioRow('mini-keep');
        $manager = new TranscodeManager($this->rowDb($row), $this->createMock(FfmpegRunner::class), $this->segmentDir);
        $this->assertTrue($manager->ensurePlaylistRegenerated('mini-keep'));
        $dir = $row['hls_dir'];

        $sentinel = "#EXTM3U\n#EXT-X-VERSION:3\n"
            . "#EXT-X-STREAM-INF:BANDWIDTH=1,RESOLUTION=1x1,CODECS=\"a\"\n"
            . "media_v720p.m3u8\n";
        file_put_contents("{$dir}/media_v720p_master.m3u8", $sentinel);

        $this->assertTrue($manager->ensurePlaylistRegenerated('mini-keep'));
        $this->assertSame(
            $sentinel,
            (string) file_get_contents("{$dir}/media_v720p_master.m3u8"),
            'rung↔index counts match (2/2) → the set is complete → no rewrite'
        );
    }

    /**
     * A legacy single-variant directory (master + `media_0.m3u8`) has ZERO
     * rungs and ZERO indexes — an equal count, so the new check must
     * short-circuit exactly like the old one did, not force a regen storm
     * on every legacy title.
     */
    public function test_a_legacy_directory_short_circuits_on_the_equal_zero_counts(): void
    {
        $row = [
            'id' => 'mini-legacy',
            'hls_dir' => $this->segmentDir . '/mini-legacy',
            'duration_seconds' => 12,
            'segment_seconds' => 6,
            'segment_params' => (string) json_encode([
                'width' => 1920,
                'height' => 1080,
                'bandwidth' => 5000000,
                'video_codec' => 'libx264',
            ]),
        ];
        $manager = new TranscodeManager($this->rowDb($row), $this->createMock(FfmpegRunner::class), $this->segmentDir);
        $this->assertTrue($manager->ensurePlaylistRegenerated('mini-legacy'));
        $dir = $row['hls_dir'];
        $this->assertFileExists("{$dir}/master.m3u8");
        $this->assertFileExists("{$dir}/media_0.m3u8");
        $this->assertSame([], glob("{$dir}/media_v*.m3u8") ?: [], 'the legacy path writes no rungs and no indexes');

        // And with the legacy set present, the call is an early true again.
        $masterBefore = (string) file_get_contents("{$dir}/master.m3u8");
        $this->assertTrue($manager->ensurePlaylistRegenerated('mini-legacy'));
        $this->assertSame($masterBefore, (string) file_get_contents("{$dir}/master.m3u8"));
    }

    // ─────────────────────────────────────────────────────────────────
    // helpers
    // ─────────────────────────────────────────────────────────────────

    /** @param list<mixed> $args */
    private function build(string $method, array $args): string
    {
        $m = new ReflectionMethod(TranscodeManager::class, $method);
        $m->setAccessible(true);
        $result = $m->invokeArgs($this->manager(), $args);
        $this->assertIsString($result);

        return $result;
    }

    /**
     * Drives the real writer (the reflection harness TranscodeManagerPlaylistFormatTest
     * uses for the same private seam).
     *
     * @param list<Rendition>                 $variants
     * @param list<array<string, mixed>>|null $audioTracks
     * @param int|null                        $sourceAudioStreamCount AUDIO-DECLARATION
     *     law input; null models a pre-law persisted row.
     */
    private function writePlaylists(
        string $dir,
        array $variants,
        ?array $audioTracks,
        string $format,
        ?int $sourceAudioStreamCount = null
    ): void {
        $m = new ReflectionMethod(TranscodeManager::class, 'writeVodPlaylists');
        $m->setAccessible(true);
        $m->invokeArgs(
            $this->manager(),
            [$dir, 25.0, 6, 1920, 1080, 5000000, $variants, $audioTracks, $format, $sourceAudioStreamCount]
        );
    }

    /**
     * The MPD adaptation sets for the same ladder, through the real builder.
     *
     * @param list<array<string, mixed>>|null $audioTracks
     *
     * @return list<AdaptationSet>
     */
    private function buildSets(?int $sourceAudioStreamCount, ?array $audioTracks = null): array
    {
        $m = new ReflectionMethod(TranscodeManager::class, 'buildMpdAdaptationSets');
        $m->setAccessible(true);
        $sets = $m->invokeArgs(
            $this->manager(),
            [6, 1920, 1080, 5000000, $this->ladder(), $audioTracks, $sourceAudioStreamCount]
        );
        $this->assertIsArray($sets);

        return $sets;
    }

    /**
     * A persisted multi-variant row WITHOUT `audio_tracks` — the shape both
     * the muxed single-audio job and the silent source share on disk, told
     * apart only by the (possibly absent) `audio_stream_count` key.
     *
     * @return array<string, mixed>
     */
    private function silentSourceRow(string $id, ?int $audioStreamCount): array
    {
        $ladderArray = [
            'renditions' => [[
                'id' => '1080p',
                'label' => '1080p',
                'width' => 1920,
                'height' => 1080,
                'bitrate' => 5128000,
                'video_bitrate' => 4600000,
                'codecs' => 'avc1.640029,mp4a.40.2',
                'is_original' => false,
                'is_copy' => false,
            ], [
                'id' => '720p',
                'label' => '720p',
                'width' => 1280,
                'height' => 720,
                'bitrate' => 3128000,
                'video_bitrate' => 2800000,
                'codecs' => 'avc1.640029,mp4a.40.2',
                'is_original' => false,
                'is_copy' => false,
            ]],
        ];
        if ($audioStreamCount !== null) {
            $ladderArray['audio_stream_count'] = $audioStreamCount;
        }

        return [
            'id' => $id,
            'hls_dir' => $this->segmentDir . '/' . $id,
            'duration_seconds' => 25,
            'segment_seconds' => 6,
            'segment_params' => json_encode(['video_codec' => 'libx264', 'segment_format' => 'fmp4']),
            'variants' => (string) json_encode($ladderArray),
        ];
    }

    /**
     * Every `CODECS="…"` value of a master-shaped playlist, in order.
     *
     * @return list<string>
     */
    private function codecsOf(string $playlist): array
    {
        $count = preg_match_all('/CODECS="([^"]*)"/', $playlist, $m);
        $this->assertIsInt($count);
        $this->assertGreaterThan(0, $count, 'a master-shaped playlist always carries CODECS');

        return $m[1];
    }

    /**
     * MIRROR of the client contract — phlix-ui 3174b805
     * `src/player/hls-playback.ts` codecStringCarriesAudio: split the csv,
     * trim, lowercase, and test each entry against the audio-family
     * prefixes. Hard-coded deliberately: if the ui matcher ever moves, this
     * pin reddens HERE first and the two halves of the contract re-negotiate
     * in the open, instead of silently losing the 'Original' entry (or
     * re-growing the phantom).
     */
    private function uiCarriesAudio(string $codecs): bool
    {
        foreach (explode(',', $codecs) as $entry) {
            $entry = strtolower(trim($entry));
            foreach (['mp4a', 'ac-3', 'ec-3', 'opus'] as $prefix) {
                if (str_starts_with($entry, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function manager(): TranscodeManager
    {
        return new TranscodeManager(
            $this->createMock(Connection::class),
            $this->createMock(FfmpegRunner::class),
            $this->segmentDir,
            null,
            6
        );
    }

    /**
     * A persisted multi-audio fMP4 job row — the exact shape
     * `ensurePlaylistRegenerated()` rebuilds from (ladder JSON with
     * `renditions` + `audio_tracks`, per the ensureHlsJob write path).
     *
     * @return array<string, mixed>
     */
    private function multiAudioRow(string $id): array
    {
        $ladderArray = [
            'renditions' => [[
                'id' => '1080p',
                'label' => '1080p',
                'width' => 1920,
                'height' => 1080,
                'bitrate' => 5128000,
                'video_bitrate' => 4600000,
                'codecs' => 'avc1.640029,mp4a.40.2',
                'is_original' => false,
                'is_copy' => false,
            ], [
                'id' => '720p',
                'label' => '720p',
                'width' => 1280,
                'height' => 720,
                'bitrate' => 3128000,
                'video_bitrate' => 2800000,
                'codecs' => 'avc1.640029,mp4a.40.2',
                'is_original' => false,
                'is_copy' => false,
            ]],
            'audio_tracks' => $this->audioTracks(),
        ];

        return [
            'id' => $id,
            'hls_dir' => $this->segmentDir . '/' . $id,
            'duration_seconds' => 25,
            'segment_seconds' => 6,
            'segment_params' => json_encode(['video_codec' => 'libx264', 'segment_format' => 'fmp4']),
            'variants' => (string) json_encode($ladderArray),
        ];
    }

    /** @param array<string, mixed> $row */
    private function rowDb(array $row): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function (string $sql) use ($row): array {
                if (str_contains($sql, 'COUNT(*)')) {
                    return [['c' => 0]];
                }
                return str_contains($sql, 'transcode_jobs WHERE id = ?') ? [$row] : [];
            }
        );

        return $db;
    }

    /**
     * The `#EXT-X-MEDIA:TYPE=AUDIO` lines of a playlist, in order.
     *
     * @return list<string>
     */
    private function groupLines(string $playlist): array
    {
        $out = [];
        foreach (explode("\n", $playlist) as $line) {
            if (str_starts_with($line, '#EXT-X-MEDIA:TYPE=AUDIO')) {
                $out[] = $line;
            }
        }

        return $out;
    }

    /** @return list<Rendition> */
    private function ladder(): array
    {
        return [
            new Rendition('1080p', '1080p', 1920, 1080, 5128000, 4600000, 'avc1.640029,mp4a.40.2', false, false),
            new Rendition('720p', '720p', 1280, 720, 3128000, 2800000, 'avc1.640029,mp4a.40.2', false, false),
        ];
    }

    /**
     * The production shape: the copy "Original" rung withheld from the master
     * by SV-4.6 but still written with its own media playlist.
     *
     * @return list<Rendition>
     */
    private function ladderWithCopyOriginal(): array
    {
        return [
            new Rendition('original', 'Original', 1920, 1080, 5128000, 4600000, 'avc1.640029,mp4a.40.2', true, true),
            ...$this->ladder(),
        ];
    }

    /**
     * @return list<array{
     *     index:int, stream_index:int, language:string, label:string, default:bool, codec:string
     * }>
     */
    private function audioTracks(): array
    {
        return [
            [
                'index' => 0,
                'stream_index' => 1,
                'language' => 'eng',
                'label' => 'English',
                'default' => true,
                'codec' => 'aac',
            ],
            [
                'index' => 1,
                'stream_index' => 2,
                'language' => 'fra',
                // The quote-stripping m3u8Attr trap, same as the S57 fixture.
                'label' => 'Fran"cais',
                'default' => false,
                'codec' => 'ac3',
            ],
        ];
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
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
