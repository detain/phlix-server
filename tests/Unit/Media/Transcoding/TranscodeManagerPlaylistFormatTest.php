<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Phlix\Admin\SettingsRepository;
use Phlix\Media\Streaming\Rendition;
use Phlix\Media\Transcoding\EncodeSettings;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\TranscodeManager;
use ReflectionMethod;
use Workerman\MySQL\Connection;

/**
 * S57 — the HLS playlists `writeVodPlaylists()` emits, in both containers.
 *
 * Two halves, and they are proved by opposite means on purpose:
 *
 *  - **Flag OFF** is pinned to LITERALS CAPTURED FROM `origin/master` @
 *    `f9f31f4f` (the S56 merge), not to a re-derivation of the new code. The
 *    capture ran the same reflection driver in a `git worktree` of
 *    `origin/master` with `vendor/` hardlinked and `src/` a genuine copy
 *    (`stat -c %h` = 1 both sides), and `diff` reported the two 400-line dumps
 *    identical. Anything that moves a byte of the default path — including a
 *    pure reordering that ffmpeg or a player would not notice — fails here.
 *  - **Flag ON** is checked STRUCTURALLY (tag position, one MAP per playlist,
 *    the timeline held invariant against the MPEG-TS playlist of the same job),
 *    because a literal for a brand-new output would only prove I wrote what I
 *    meant to write.
 *
 * Every case that drives a mock records into a variable seeded with a
 * distinguishing sentinel and asserts after the call returns — never inside the
 * callback, which sits under `produceSegment()`'s `try`/`finally`.
 *
 * @see \Phlix\Tests\E2E\Media\Transcoding\Fmp4HlsPlaybackE2ETest for the half
 *      this file cannot reach: whether hls.js in a real browser actually plays
 *      the bytes these playlists describe.
 */
final class TranscodeManagerPlaylistFormatTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────────
    // MPEG-TS literals — captured from origin/master @ f9f31f4f.
    // Do NOT regenerate these from the code under test.
    // ─────────────────────────────────────────────────────────────────

    private const MASTER_LEGACY_MASTER = "#EXTM3U\n"
        . "#EXT-X-VERSION:3\n"
        . "#EXT-X-STREAM-INF:BANDWIDTH=5000000,RESOLUTION=1920x1080,CODECS=\"avc1.640029,mp4a.40.2\"\n"
        . "media_0.m3u8\n";

    private const MASTER_MULTIVARIANT_WITH_AUDIO = "#EXTM3U\n"
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

    private const MASTER_MEDIA_720P = "#EXTM3U\n"
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

    private const MASTER_MEDIA_LEGACY = "#EXTM3U\n"
        . "#EXT-X-VERSION:3\n"
        . "#EXT-X-PLAYLIST-TYPE:VOD\n"
        . "#EXT-X-TARGETDURATION:6\n"
        . "#EXT-X-MEDIA-SEQUENCE:0\n"
        . "#EXT-X-INDEPENDENT-SEGMENTS\n"
        . "#EXTINF:6.000000,\n"
        . "seg-00000.ts\n"
        . "#EXTINF:6.000000,\n"
        . "seg-00001.ts\n"
        . "#EXT-X-ENDLIST\n";

    private const MASTER_AUDIO_A1 = "#EXTM3U\n"
        . "#EXT-X-VERSION:3\n"
        . "#EXT-X-PLAYLIST-TYPE:VOD\n"
        . "#EXT-X-TARGETDURATION:6\n"
        . "#EXT-X-MEDIA-SEQUENCE:0\n"
        . "#EXT-X-INDEPENDENT-SEGMENTS\n"
        . "#EXTINF:6.000000,\n"
        . "seg-a1-00000.ts\n"
        . "#EXTINF:6.000000,\n"
        . "seg-a1-00001.ts\n"
        . "#EXT-X-ENDLIST\n";

    private string $segmentDir;

    protected function setUp(): void
    {
        $this->segmentDir = sys_get_temp_dir() . '/phlix_s57_' . uniqid();
        mkdir($this->segmentDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->segmentDir);
    }

    // ─────────────────────────────────────────────────────────────────
    // flag OFF — byte-identical to origin/master
    // ─────────────────────────────────────────────────────────────────

    /**
     * The strongest of the flag-off cases: the exact bytes of the whole
     * multi-variant master, audio group and all. A reordered attribute, a
     * changed quote, a bumped `#EXT-X-VERSION` — anything a structural
     * assertion would wave through — fails this.
     */
    public function test_flag_off_multi_variant_master_is_byte_identical_to_master(): void
    {
        $this->assertSame(
            self::MASTER_MULTIVARIANT_WITH_AUDIO,
            $this->build('buildMultiVariantMaster', [$this->transcodeLadder(), $this->audioTracks()])
        );
    }

    public function test_flag_off_legacy_master_is_byte_identical_to_master(): void
    {
        $this->assertSame(
            self::MASTER_LEGACY_MASTER,
            $this->build('buildMasterPlaylist', [1920, 1080, 5000000])
        );
    }

    public function test_flag_off_video_media_playlist_is_byte_identical_to_master(): void
    {
        $this->assertSame(
            self::MASTER_MEDIA_720P,
            $this->build('buildMediaPlaylist', [25.0, 6, '720p'])
        );
    }

    public function test_flag_off_legacy_media_playlist_is_byte_identical_to_master(): void
    {
        $this->assertSame(
            self::MASTER_MEDIA_LEGACY,
            $this->build('buildMediaPlaylist', [12.0, 6, null])
        );
    }

    public function test_flag_off_audio_media_playlist_is_byte_identical_to_master(): void
    {
        $this->assertSame(
            self::MASTER_AUDIO_A1,
            $this->build('buildAudioMediaPlaylist', [12.0, 6, 'a1'])
        );
    }

    /**
     * The default-argument half. Every builder gained a `$format` parameter; if
     * one of them defaulted to `fmp4`, or if a call site stopped passing the
     * job's container, the four cases above would still pass while every
     * pre-existing job silently changed flavour. So: the ONLY way `EXT-X-MAP`
     * or version 7 may appear is with the container explicitly set to `fmp4`.
     */
    public function test_no_default_path_can_emit_ext_x_map_or_version_7(): void
    {
        $dir = $this->segmentDir . '/off';
        mkdir($dir, 0755, true);
        $this->write($dir, $this->transcodeLadder(), $this->audioTracks(), null);

        $files = glob($dir . '/*.m3u8') ?: [];
        $this->assertCount(5, $files, 'master + 2 video + 2 audio: the fixture must actually write playlists');

        foreach ($files as $file) {
            $text = (string) file_get_contents($file);
            $this->assertStringNotContainsString('#EXT-X-MAP', $text, basename($file));
            $this->assertStringContainsString("#EXT-X-VERSION:3\n", $text, basename($file));
            $this->assertStringNotContainsString('.m4s', $text, basename($file));
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // flag ON — the EXT-X-MAP shape
    // ─────────────────────────────────────────────────────────────────

    public function test_a_video_media_playlist_carries_exactly_one_map_naming_its_own_init(): void
    {
        $text = $this->build('buildMediaPlaylist', [25.0, 6, '720p', EncodeSettings::FORMAT_FMP4]);

        $this->assertSame(
            1,
            substr_count($text, '#EXT-X-MAP:'),
            'one EXT-X-MAP applies to every segment after it; a second would re-initialise mid-stream'
        );
        $this->assertStringContainsString('#EXT-X-MAP:URI="init-v720p.m4s"' . "\n", $text);
    }

    /**
     * Placement, not mere presence. RFC 8216 §4.3.2.5: an `EXT-X-MAP` applies to
     * the Media Segments that FOLLOW it. Emitted after the first `#EXTINF` it
     * would leave segment 0 with no initialisation section at all — hls.js
     * appends it to a SourceBuffer that has never seen a `moov` and raises a
     * fatal `bufferAppendError`, which is exactly the failure this step exists
     * to avoid.
     */
    public function test_the_map_precedes_the_first_extinf(): void
    {
        $text = $this->build('buildMediaPlaylist', [25.0, 6, '720p', EncodeSettings::FORMAT_FMP4]);
        $lines = explode("\n", $text);

        $mapAt = null;
        $firstInfAt = null;
        foreach ($lines as $i => $line) {
            if ($mapAt === null && str_starts_with($line, '#EXT-X-MAP:')) {
                $mapAt = $i;
            }
            if ($firstInfAt === null && str_starts_with($line, '#EXTINF:')) {
                $firstInfAt = $i;
            }
        }

        $this->assertNotNull($mapAt, 'no EXT-X-MAP was emitted at all');
        $this->assertNotNull($firstInfAt, 'the fixture must contain segments for the ordering to mean anything');
        $this->assertLessThan($firstInfAt, $mapAt, 'EXT-X-MAP must precede the first Media Segment');
    }

    public function test_an_audio_media_playlist_maps_its_own_audio_init_not_a_video_one(): void
    {
        $text = $this->build('buildAudioMediaPlaylist', [12.0, 6, 'a1', EncodeSettings::FORMAT_FMP4]);

        $this->assertStringContainsString('#EXT-X-MAP:URI="init-a1.m4s"' . "\n", $text);
        $this->assertStringNotContainsString('init-v', $text, 'an audio rendition has its own init segment');
        $this->assertStringContainsString("seg-a1-00000.m4s\n", $text);
    }

    public function test_the_legacy_single_variant_playlist_maps_the_unprefixed_init(): void
    {
        $text = $this->build('buildMediaPlaylist', [12.0, 6, null, EncodeSettings::FORMAT_FMP4]);

        $this->assertStringContainsString('#EXT-X-MAP:URI="init.m4s"' . "\n", $text);
        $this->assertStringContainsString("seg-00000.m4s\n", $text);
    }

    /**
     * `EXT-X-MAP` on a Media Segment requires protocol version 6 (RFC 8216 §7);
     * Apple's HLS Authoring Specification puts the fMP4 floor at 7. Leaving the
     * shipped `3` would make a conforming client reject the playlist outright —
     * a `manifestParsingError`, not a degradation.
     */
    public function test_every_fmp4_playlist_declares_version_7_and_every_mpegts_one_declares_3(): void
    {
        $cases = [
            'buildMediaPlaylist' => [25.0, 6, '720p'],
            'buildAudioMediaPlaylist' => [25.0, 6, 'a0'],
            'buildMasterPlaylist' => [1920, 1080, 5000000],
        ];
        foreach ($cases as $method => $args) {
            $this->assertStringContainsString(
                "#EXT-X-VERSION:3\n",
                $this->build($method, $args),
                "{$method} (mpegts)"
            );
            $this->assertStringContainsString(
                "#EXT-X-VERSION:7\n",
                $this->build($method, array_merge($args, [EncodeSettings::FORMAT_FMP4])),
                "{$method} (fmp4)"
            );
        }

        $this->assertStringContainsString(
            "#EXT-X-VERSION:7\n",
            $this->build(
                'buildMultiVariantMaster',
                [$this->transcodeLadder(), $this->audioTracks(), EncodeSettings::FORMAT_FMP4]
            ),
            'the master must not declare a LOWER version than the media playlists it references'
        );
    }

    /**
     * The invariant S58's shared `SegmentTemplate@duration` will rest on, and
     * the one that keeps ABR switching legal: changing the container must move
     * the segment EXTENSION and nothing else. Compared line by line against the
     * MPEG-TS playlist of the same job rather than against a second literal, so
     * a change to the timeline arithmetic fails here even if both flavours moved
     * together.
     */
    public function test_the_container_moves_the_extension_and_nothing_else(): void
    {
        $ts = $this->build('buildMediaPlaylist', [25.0, 6, '720p']);
        $fmp4 = $this->build('buildMediaPlaylist', [25.0, 6, '720p', EncodeSettings::FORMAT_FMP4]);

        // Normalise the two known, intended differences away; what remains must match.
        $normalised = str_replace(
            ['#EXT-X-VERSION:7', '#EXT-X-MAP:URI="init-v720p.m4s"' . "\n", '.m4s'],
            ['#EXT-X-VERSION:3', '', '.ts'],
            $fmp4
        );

        $this->assertSame($ts, $normalised);
    }

    public function test_the_master_advertises_the_same_levels_and_codecs_in_both_containers(): void
    {
        $ts = $this->build('buildMultiVariantMaster', [$this->transcodeLadder(), $this->audioTracks()]);
        $fmp4 = $this->build(
            'buildMultiVariantMaster',
            [$this->transcodeLadder(), $this->audioTracks(), EncodeSettings::FORMAT_FMP4]
        );

        $this->assertSame($ts, str_replace('#EXT-X-VERSION:7', '#EXT-X-VERSION:3', $fmp4));
    }

    // ─────────────────────────────────────────────────────────────────
    // the writer, and its TWO callers
    // ─────────────────────────────────────────────────────────────────

    public function test_the_writer_emits_the_fmp4_flavour_across_every_file_it_writes(): void
    {
        $dir = $this->segmentDir . '/on';
        mkdir($dir, 0755, true);
        $this->write($dir, $this->transcodeLadder(), $this->audioTracks(), EncodeSettings::FORMAT_FMP4);

        $expected = [
            'master.m3u8' => null,
            'media_a0.m3u8' => 'init-a0.m4s',
            'media_a1.m3u8' => 'init-a1.m4s',
            'media_v1080p.m3u8' => 'init-v1080p.m4s',
            'media_v720p.m3u8' => 'init-v720p.m4s',
        ];
        foreach ($expected as $name => $init) {
            $this->assertFileExists("{$dir}/{$name}");
            $text = (string) file_get_contents("{$dir}/{$name}");
            $this->assertStringContainsString("#EXT-X-VERSION:7\n", $text, $name);
            if ($init === null) {
                $this->assertStringNotContainsString('#EXT-X-MAP', $text, 'a MASTER playlist carries no EXT-X-MAP');
                continue;
            }
            $this->assertStringContainsString('#EXT-X-MAP:URI="' . $init . '"' . "\n", $text, $name);
            $this->assertStringNotContainsString('.ts', $text, $name);
        }
    }

    /**
     * Job creation, through the real public entry point, with the setting on —
     * the branch that decides what an install actually gets. Reaching it needs
     * a real `EncodeSettings` over a stubbed repository, because
     * `computeSegmentParams()` reads the LIVE setting exactly once (at creation)
     * and stamps it; everything downstream reads the stamp.
     */
    public function test_ensure_hls_job_writes_fmp4_playlists_when_the_setting_is_on(): void
    {
        $dir = $this->ensureJobDir(EncodeSettings::FORMAT_FMP4);

        $media = (string) file_get_contents("{$dir}/media_v720p.m3u8");
        $this->assertStringContainsString('#EXT-X-MAP:URI="init-v720p.m4s"' . "\n", $media);
        $this->assertStringContainsString("seg-v720p-00000.m4s\n", $media);
        $this->assertStringContainsString("#EXT-X-VERSION:7\n", $media);
        $this->assertStringContainsString(
            "#EXT-X-VERSION:7\n",
            (string) file_get_contents("{$dir}/master.m3u8")
        );
    }

    /**
     * The control for the case above: the SAME source, the SAME call, the
     * setting absent. Without it, "the playlist says .m4s" could be true of
     * every job on every install.
     */
    public function test_ensure_hls_job_writes_mpegts_playlists_when_the_setting_is_off(): void
    {
        $dir = $this->ensureJobDir(null);

        $media = (string) file_get_contents("{$dir}/media_v720p.m3u8");
        $this->assertStringNotContainsString('#EXT-X-MAP', $media);
        $this->assertStringContainsString("seg-v720p-00000.ts\n", $media);
        $this->assertStringContainsString("#EXT-X-VERSION:3\n", $media);
    }

    /**
     * The writer's SECOND caller. `sweepSegmentCache()` evicts an idle job
     * directory while the DB row lives on, and the next playlist request
     * rebuilds it here. If this path regenerated MPEG-TS playlists for an fMP4
     * job, that job would 404 for the rest of its life — the master is written
     * first and its mere presence short-circuits every later attempt.
     *
     * The container comes from the ROW, never from the live setting: the manager
     * below is built with no `EncodeSettings` at all (so the live value is the
     * `mpegts` default) and must still regenerate fMP4.
     */
    public function test_regeneration_reproduces_the_jobs_own_container_not_the_live_setting(): void
    {
        $dir = $this->segmentDir . '/regen';
        mkdir($dir, 0755, true);

        $row = [
            'id' => 'regen',
            'hls_dir' => $dir,
            'duration_seconds' => 25,
            'segment_seconds' => 6,
            'segment_params' => json_encode(['video_codec' => 'libx264', 'segment_format' => 'fmp4']),
            'variants' => json_encode(['renditions' => [[
                'id' => '720p',
                'width' => 1280,
                'height' => 720,
                'bandwidth' => 3128000,
                'codecs' => 'avc1.640029,mp4a.40.2',
                'is_copy' => false,
            ]]]),
        ];

        $manager = new TranscodeManager($this->rowDb($row), $this->createMock(FfmpegRunner::class), $this->segmentDir);
        $this->assertTrue($manager->ensurePlaylistRegenerated('regen'));

        $media = (string) file_get_contents("{$dir}/media_v720p.m3u8");
        $this->assertStringContainsString('#EXT-X-MAP:URI="init-v720p.m4s"' . "\n", $media);
        $this->assertStringContainsString("seg-v720p-00000.m4s\n", $media);
        $this->assertStringContainsString(
            "#EXT-X-VERSION:7\n",
            (string) file_get_contents("{$dir}/master.m3u8")
        );
    }

    /**
     * Same method, the other branch: a job with `variants IS NULL` regenerates
     * through the legacy single-variant write, which is a separate
     * `writeVodPlaylists()` call and was therefore a separate place to forget
     * the container.
     */
    public function test_legacy_regeneration_reproduces_the_jobs_own_container(): void
    {
        $dir = $this->segmentDir . '/regen-legacy';
        mkdir($dir, 0755, true);

        $row = [
            'id' => 'regen-legacy',
            'hls_dir' => $dir,
            'duration_seconds' => 12,
            'segment_seconds' => 6,
            'segment_params' => json_encode([
                'video_codec' => 'libx264',
                'width' => 1280,
                'height' => 720,
                'bandwidth' => 3128000,
                'segment_format' => 'fmp4',
            ]),
        ];

        $manager = new TranscodeManager($this->rowDb($row), $this->createMock(FfmpegRunner::class), $this->segmentDir);
        $this->assertTrue($manager->ensurePlaylistRegenerated('regen-legacy'));

        $media = (string) file_get_contents("{$dir}/media_0.m3u8");
        $this->assertStringContainsString('#EXT-X-MAP:URI="init.m4s"' . "\n", $media);
        $this->assertStringContainsString("seg-00000.m4s\n", $media);

        // The legacy MASTER is a separate `buildMasterPlaylist()` hand-off, and
        // was the one place `$segmentFormat` could be dropped without any other
        // case noticing: a master at version 3 referencing a media playlist at
        // version 7 is precisely the mismatch playlistVersion() exists to
        // prevent. (Found by mutation M18 — it survived until this line.)
        $this->assertStringContainsString(
            "#EXT-X-VERSION:7\n",
            (string) file_get_contents("{$dir}/master.m3u8")
        );
    }

    /**
     * A `segment_params` that will not decode must degrade to the SHIPPED
     * container, never to fMP4.
     *
     * The direction matters and is not symmetric. Degrading to `mpegts` gives a
     * corrupt row the behaviour every install already has. Degrading to `fmp4`
     * would hand an unrecognisable row a playlist naming `.m4s` files that the
     * producer — which resolves its own container through `segmentFormatOf()` on
     * a DIFFERENT array — may well never write, i.e. a silent flip into the
     * un-servable branch on the strength of unreadable data.
     *
     * (Found by mutation M29, which flipped exactly this fallback and survived.)
     */
    public function test_an_undecodable_segment_params_regenerates_as_mpegts(): void
    {
        $dir = $this->segmentDir . '/regen-corrupt';
        mkdir($dir, 0755, true);

        $row = [
            'id' => 'regen-corrupt',
            'hls_dir' => $dir,
            'duration_seconds' => 12,
            'segment_seconds' => 6,
            // A truncated write: valid UTF-8, not valid JSON.
            'segment_params' => '{"video_codec":"libx264","segment_format":"fmp4"',
            'variants' => json_encode(['renditions' => [[
                'id' => '720p',
                'width' => 1280,
                'height' => 720,
                'bandwidth' => 3128000,
                'codecs' => 'avc1.640029,mp4a.40.2',
                'is_copy' => false,
            ]]]),
        ];

        $manager = new TranscodeManager($this->rowDb($row), $this->createMock(FfmpegRunner::class), $this->segmentDir);
        $this->assertTrue($manager->ensurePlaylistRegenerated('regen-corrupt'));

        $media = (string) file_get_contents("{$dir}/media_v720p.m3u8");
        $this->assertStringNotContainsString('#EXT-X-MAP', $media, 'a corrupt row must not be read as fMP4');
        $this->assertStringContainsString("seg-v720p-00000.ts\n", $media);
        $this->assertStringContainsString("#EXT-X-VERSION:3\n", $media);
    }

    /**
     * The control for both regeneration cases: an identical row WITHOUT the
     * stamp regenerates MPEG-TS. Without this, a regeneration that ignored the
     * row and always emitted fMP4 would pass the two above.
     */
    public function test_regeneration_of_an_unstamped_job_stays_on_mpegts(): void
    {
        $dir = $this->segmentDir . '/regen-off';
        mkdir($dir, 0755, true);

        $row = [
            'id' => 'regen-off',
            'hls_dir' => $dir,
            'duration_seconds' => 25,
            'segment_seconds' => 6,
            'segment_params' => json_encode(['video_codec' => 'libx264']),
            'variants' => json_encode(['renditions' => [[
                'id' => '720p',
                'width' => 1280,
                'height' => 720,
                'bandwidth' => 3128000,
                'codecs' => 'avc1.640029,mp4a.40.2',
                'is_copy' => false,
            ]]]),
        ];

        $manager = new TranscodeManager($this->rowDb($row), $this->createMock(FfmpegRunner::class), $this->segmentDir);
        $this->assertTrue($manager->ensurePlaylistRegenerated('regen-off'));

        $media = (string) file_get_contents("{$dir}/media_v720p.m3u8");
        $this->assertStringNotContainsString('#EXT-X-MAP', $media);
        $this->assertStringContainsString("seg-v720p-00000.ts\n", $media);
    }

    // ─────────────────────────────────────────────────────────────────
    // the join between the playlist and the producer
    // ─────────────────────────────────────────────────────────────────

    /**
     * The defect no assertion on the playlist ALONE can catch: a playlist that
     * is internally perfect but names a file `ensureSegment()` will never
     * publish. The playlist and the producer resolve the filename through two
     * different call chains (`buildMediaPlaylist()` → `segmentFileName()` and
     * `produceSegment()` → `segmentFileName()` via the merged `$segParams`), and
     * S56's own worklog records that this array reaches the producer by three
     * routes of which only one carries the job's setting.
     *
     * So: take the name the fMP4 PLAYLIST advertises for segment 1, ask the real
     * `ensureSegment()` for segment 1 of the same job, and require them to be
     * the same file.
     */
    public function test_the_name_the_playlist_advertises_is_the_name_the_producer_publishes(): void
    {
        $dir = $this->segmentDir . '/join';
        mkdir($dir, 0755, true);
        file_put_contents("{$dir}/in.mkv", 'x');

        $row = [
            'id' => 'join',
            'hls_dir' => $dir,
            'input_path' => "{$dir}/in.mkv",
            'status' => 'completed',
            'duration_seconds' => 25,
            'segment_seconds' => 6,
            'segment_params' => json_encode(['video_codec' => 'libx264', 'segment_format' => 'fmp4']),
            'variants' => json_encode(['renditions' => [[
                'id' => '720p',
                'width' => 1280,
                'height' => 720,
                'bandwidth' => 3128000,
                'video_codec' => 'libx264',
                'audio_codec' => 'aac',
                'is_copy' => false,
            ]]]),
        ];

        $ff = $this->createMock(FfmpegRunner::class);
        $ff->method('startSegmentEncode')->willReturnCallback(
            static function (string $in, string $out): int {
                file_put_contents($out, 'encoded');
                return 4242;
            }
        );

        $manager = new TranscodeManager(
            $this->rowDb($row),
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
            50
        );

        $this->assertTrue($manager->ensurePlaylistRegenerated('join'));
        $advertised = $this->segmentEntries((string) file_get_contents("{$dir}/media_v720p.m3u8"));
        $published = $manager->ensureSegment('join', '720p', 1);

        $this->assertIsString($published, 'the producer must have published a segment for the join to mean anything');
        $this->assertSame('seg-v720p-00001.m4s', $advertised[1], 'control: the playlist advertises the fMP4 name');
        $this->assertSame($advertised[1], basename($published));
    }

    // ─────────────────────────────────────────────────────────────────
    // helpers
    // ─────────────────────────────────────────────────────────────────

    /**
     * @param list<mixed> $args
     */
    private function build(string $method, array $args): string
    {
        $m = new ReflectionMethod(TranscodeManager::class, $method);
        $m->setAccessible(true);
        $result = $m->invokeArgs($this->manager(), $args);
        $this->assertIsString($result);

        return $result;
    }

    /**
     * @param list<Rendition>|null                 $variants
     * @param list<array<string, mixed>>|null      $audioTracks
     */
    private function write(string $dir, ?array $variants, ?array $audioTracks, ?string $format): void
    {
        $m = new ReflectionMethod(TranscodeManager::class, 'writeVodPlaylists');
        $m->setAccessible(true);
        $args = [$dir, 25.0, 6, 1920, 1080, 5000000, $variants, $audioTracks];
        if ($format !== null) {
            $args[] = $format;
        }
        $m->invokeArgs($this->manager(), $args);
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
     * Runs `ensureHlsJob()` over a mocked 720p source with the segment-format
     * setting either absent or set, and returns the job directory.
     */
    private function ensureJobDir(?string $format): string
    {
        $captured = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, ?array $params = null) use (&$captured): array {
                $captured[] = [$sql, $params ?? []];
                if (str_contains($sql, 'key_hash = ?') && str_contains($sql, 'IN (')) {
                    return [];
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
                return [];
            }
        );

        $ff = $this->createMock(FfmpegRunner::class);
        $ff->method('extractColorMetadata')->willReturn([
            'color_space' => 'bt709',
            'color_transfer' => 'bt709',
            'color_primaries' => 'bt709',
            'max_luminance' => 1000.0,
            'avg_luminance' => 200.0,
        ]);
        $ff->method('probe')->willReturn([
            'streams' => [
                ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1280, 'height' => 720],
                ['codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 2],
            ],
            'format' => ['duration' => '25.0'],
        ]);

        $repo = $this->createMock(SettingsRepository::class);
        $repo->method('getEffective')->willReturnCallback(
            /** @return mixed */
            static fn (string $key) => $key === EncodeSettings::SEGMENT_FORMAT_KEY ? $format : null
        );

        $manager = new TranscodeManager(
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
            new EncodeSettings($repo)
        );
        $manager->ensureHlsJob('media-1', 'web');

        foreach ($captured as [$sql, $params]) {
            if (!str_contains($sql, 'INSERT INTO transcode_jobs')) {
                continue;
            }
            $dir = $params[4] ?? '';
            $this->assertIsString($dir);
            $this->assertFileExists("{$dir}/master.m3u8");
            return $dir;
        }
        $this->fail('no transcode_jobs INSERT was captured');
    }

    /**
     * A Connection mock that answers the narrowed job-row lookup with `$row`.
     *
     * @param array<string, mixed> $row
     */
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
     * The URI lines of a media playlist, in order.
     *
     * @return list<string>
     */
    private function segmentEntries(string $playlist): array
    {
        $out = [];
        foreach (explode("\n", $playlist) as $line) {
            if ($line !== '' && !str_starts_with($line, '#')) {
                $out[] = $line;
            }
        }

        return $out;
    }

    /**
     * @return list<Rendition>
     */
    private function transcodeLadder(): array
    {
        return [
            new Rendition('1080p', '1080p', 1920, 1080, 5128000, 4600000, 'avc1.640029,mp4a.40.2', false, false),
            new Rendition('720p', '720p', 1280, 720, 3128000, 2800000, 'avc1.640029,mp4a.40.2', false, false),
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
