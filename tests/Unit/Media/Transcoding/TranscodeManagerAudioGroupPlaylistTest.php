<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Streaming\Rendition;
use Phlix\Media\Transcoding\EncodeSettings;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\TranscodeManager;
use ReflectionMethod;
use Workerman\MySQL\Connection;

/**
 * Fix 3 (playback-lag program, 2026-11-06) — video rung media playlists are
 * SELF-DESCRIBING for audio.
 *
 * The shipped law (FfmpegRunner `video_only` / P3B-S3): when a source carries
 * multiple audio tracks, EVERY video rung is encoded `-an` and sound lives ONLY
 * in the `media_a{N}.m3u8` renditions, which before this fix were announced
 * ONLY in `master.m3u8`. A client that loads a rung playlist DIRECTLY — the
 * pinned-'original'/'quality' path in the vendored player, and hls.js has no
 * supported API to graft a group off a separate master onto a bare playlist —
 * therefore sees zero audio tracks and plays SILENT (byte-proven live: 18 video
 * segment requests, 0 audio requests). The playlists share the job directory,
 * so re-emitting the master's exact `#EXT-X-MEDIA:TYPE=AUDIO` lines into each
 * video rung fixes it server-side with no wire changes.
 *
 * Anti-drift law under test: master and video playlists render the group from
 * ONE method, so the lines are asserted EQUAL (attribute order included), not
 * merely present. The audio-only playlists must NOT carry them (self-describing
 * already), single-audio/legacy jobs must stay byte-identical, and the master's
 * shipped bytes must not move because of the extraction.
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
    // f9f31f4f) — it must keep passing untouched: the renderer extraction has
    // to be behavior-preserving for the master, and this file is where the
    // extraction would first notice if it weren't.
    //
    // The RUNG goldens encode the intended new output: the master's group
    // lines verbatim, after the header tags, before the first segment (and,
    // on fMP4, before the container's EXT-X-MAP — an EXT-X-MAP still
    // precedes every Media Segment it governs).
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

    private const EXPECTED_RUNG_MPEGTS = "#EXTM3U\n"
        . "#EXT-X-VERSION:3\n"
        . "#EXT-X-PLAYLIST-TYPE:VOD\n"
        . "#EXT-X-TARGETDURATION:6\n"
        . "#EXT-X-MEDIA-SEQUENCE:0\n"
        . "#EXT-X-INDEPENDENT-SEGMENTS\n"
        . self::GROUP_LINE_EN . "\n"
        . self::GROUP_LINE_FR . "\n"
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
        . self::GROUP_LINE_EN . "\n"
        . self::GROUP_LINE_FR . "\n"
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

    /**
     * Pre-fix bytes of the NO-GROUP media playlist — the shape every
     * single-audio and legacy job must keep, byte for byte. Captured from
     * the shipped builder output (this equals the S57 flag-off literal in
     * TranscodeManagerPlaylistFormatTest::MASTER_MEDIA_720P by construction).
     */
    private const EXPECTED_RUNG_NO_GROUP = "#EXTM3U\n"
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

    private string $segmentDir;

    protected function setUp(): void
    {
        $this->segmentDir = sys_get_temp_dir() . '/phlix_fix3_' . uniqid();
        mkdir($this->segmentDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->segmentDir);
    }

    // ─────────────────────────────────────────────────────────────────
    // The anti-drift law: the rung carries the master's lines, verbatim.
    // ─────────────────────────────────────────────────────────────────

    public function test_a_multi_audio_rung_playlist_carries_the_masters_group_lines_verbatim(): void
    {
        $master = $this->build('buildMultiVariantMaster', [$this->ladder(), $this->audioTracks()]);
        $rung = $this->build(
            'buildMediaPlaylist',
            [25.0, 6, '720p', EncodeSettings::FORMAT_MPEGTS, $this->audioTracks()]
        );

        // Equality, not presence: same lines, same order, same attribute
        // order. Two renderers that may drift is the defect class itself.
        $this->assertSame(
            $this->groupLines($master),
            $this->groupLines($rung),
            'a video rung must advertise exactly the group the master does'
        );
        $this->assertCount(2, $this->groupLines($rung), 'the fixture must be genuinely multi-audio');
    }

    public function test_the_multi_audio_mpegts_rung_is_the_expected_bytes(): void
    {
        $this->assertSame(
            self::EXPECTED_RUNG_MPEGTS,
            $this->build(
                'buildMediaPlaylist',
                [25.0, 6, '720p', EncodeSettings::FORMAT_MPEGTS, $this->audioTracks()]
            )
        );
    }

    public function test_the_multi_audio_fmp4_rung_is_the_expected_bytes(): void
    {
        $this->assertSame(
            self::EXPECTED_RUNG_FMP4,
            $this->build(
                'buildMediaPlaylist',
                [25.0, 6, '720p', EncodeSettings::FORMAT_FMP4, $this->audioTracks()]
            )
        );
    }

    /**
     * The exact production defect: the pinned-'original' rung is a stream
     * COPY withheld from the master, yet it is encoded `-an` like every other
     * rung (multi-audio applies `video_only` to all video variants). It has
     * no master to lean on, so it MUST carry the group itself.
     */
    public function test_the_copy_original_rung_carries_the_group_too(): void
    {
        $dir = $this->segmentDir . '/ladder';
        mkdir($dir, 0755, true);
        $this->writePlaylists($dir, $this->ladderWithCopyOriginal(), $this->audioTracks(), null);

        $original = (string) file_get_contents("{$dir}/media_voriginal.m3u8");
        $this->assertStringContainsString(self::GROUP_LINE_EN, $original);
        $this->assertStringContainsString(self::GROUP_LINE_FR, $original);
        $this->assertStringNotContainsString('media_voriginal.m3u8', (string) file_get_contents("{$dir}/master.m3u8"));
    }

    // ─────────────────────────────────────────────────────────────────
    // What must NOT move.
    // ─────────────────────────────────────────────────────────────────

    public function test_the_master_bytes_do_not_move_with_the_renderer_extraction(): void
    {
        $this->assertSame(
            self::EXPECTED_MASTER,
            $this->build('buildMultiVariantMaster', [$this->ladder(), $this->audioTracks()])
        );
    }

    public function test_a_rung_without_a_group_is_byte_identical_to_the_pre_fix_bytes(): void
    {
        // Default-argument call (single-audio / legacy / live jobs): the
        // exact pre-fix bytes, so the "muxed jobs unchanged" promise is
        // pinned against a literal, not against the builder's own new output.
        $this->assertSame(
            self::EXPECTED_RUNG_NO_GROUP,
            $this->build('buildMediaPlaylist', [25.0, 6, '720p'])
        );

        // And the writer's one-element descriptor edge (never persisted by
        // ensureHlsJob — descriptors require count > 1 — but the builder
        // must not invent a group from a lone track either): same bytes.
        $dir = $this->segmentDir . '/single';
        mkdir($dir, 0755, true);
        $solo = [$this->audioTracks()[0]];
        $this->writePlaylists($dir, $this->ladder(), $solo, null);
        $this->assertSame(
            self::EXPECTED_RUNG_NO_GROUP,
            (string) file_get_contents("{$dir}/media_v720p.m3u8"),
            'a single-track list must render the pre-fix media playlist'
        );
        $this->assertStringNotContainsString(
            '#EXT-X-MEDIA:TYPE=AUDIO',
            (string) file_get_contents("{$dir}/master.m3u8")
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
    // The writer end to end — BOTH production callers.
    // ─────────────────────────────────────────────────────────────────

    public function test_the_writer_stamps_every_video_rung_of_a_multi_audio_job(): void
    {
        $dir = $this->segmentDir . '/write';
        mkdir($dir, 0755, true);
        $this->writePlaylists($dir, $this->ladder(), $this->audioTracks(), EncodeSettings::FORMAT_FMP4);

        foreach (['media_v1080p.m3u8', 'media_v720p.m3u8'] as $name) {
            $text = (string) file_get_contents("{$dir}/{$name}");
            $this->assertSame(
                $this->groupLines((string) file_get_contents("{$dir}/master.m3u8")),
                $this->groupLines($text),
                $name
            );
        }
    }

    /**
     * The regeneration caller must stamp the same lines: an evicted-then-
     * rebuilt directory is produced by THIS writer from the persisted ladder
     * JSON (`audio_tracks`), and it is also the legitimate self-heal route
     * for pre-fix jobs — a swept directory comes back self-describing.
     */
    public function test_regeneration_stamps_the_group_on_the_video_rungs(): void
    {
        $ladderArray = [
            'renditions' => [[
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
        $row = [
            'id' => 'fix3-regen',
            'hls_dir' => $this->segmentDir . '/fix3-regen',
            'duration_seconds' => 25,
            'segment_seconds' => 6,
            'segment_params' => json_encode(['video_codec' => 'libx264', 'segment_format' => 'fmp4']),
            'variants' => (string) json_encode($ladderArray),
        ];

        $manager = new TranscodeManager($this->rowDb($row), $this->createMock(FfmpegRunner::class), $this->segmentDir);
        $this->assertTrue($manager->ensurePlaylistRegenerated('fix3-regen'));

        $rung = (string) file_get_contents("{$row['hls_dir']}/media_v720p.m3u8");
        $this->assertSame(
            $this->groupLines((string) file_get_contents("{$row['hls_dir']}/master.m3u8")),
            $this->groupLines($rung),
            'a regenerated rung carries the group the regenerated master advertises'
        );
        $audio = (string) file_get_contents("{$row['hls_dir']}/media_a0.m3u8");
        $this->assertStringNotContainsString('#EXT-X-MEDIA:TYPE=AUDIO', $audio);
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
     * @param list<Rendition>              $variants
     * @param list<array<string, mixed>>|null $audioTracks
     */
    private function writePlaylists(string $dir, array $variants, ?array $audioTracks, ?string $format): void
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
