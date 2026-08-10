<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use Phlix\Admin\SettingsRepository;
use Phlix\Media\Streaming\Dash\DashStreamer;
use Phlix\Media\Streaming\Rendition;
use Phlix\Media\Transcoding\EncodeSettings;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\TranscodeManager;
use Phlix\Tests\Support\Dash\MpdSchema;
use ReflectionMethod;
use Workerman\MySQL\Connection;

/**
 * S58 — the VOD DASH manifest `writeVodPlaylists()` publishes beside the HLS
 * playlists.
 *
 * Three kinds of check, deliberately of three different strengths:
 *
 *  - **Schema.** Every manifest shape is put through the REAL MPEG-DASH
 *    `DASH-MPD.xsd` ({@see MpdSchema}), not through a string comparison — a test
 *    asserting that the XML I wrote equals the XML I meant to write proves
 *    nothing. `test_the_schema_validator_rejects_a_malformed_manifest()` is the
 *    positive control that the validator can say no, including for the two
 *    exact defects the pre-S58 `AdaptationSet` class shipped.
 *  - **Agreement with the HLS playlists.** The manifest and the playlists are
 *    written from the same arguments in the same call, so the checks compare the
 *    two OUTPUTS: the Representation set against the master's
 *    `#EXT-X-STREAM-INF` levels, each expanded `SegmentTemplate` against the
 *    playlist's own segment URIs, the segment counts, the default audio track.
 *  - **Flag OFF pinned to LITERALS CAPTURED FROM `origin/master` @ `a146cb57`.**
 *    The capture ran the same reflection driver in a `git worktree` of
 *    `origin/master` with `vendor/` hardlinked and `src/` a genuine copy
 *    (`stat -c %h` = 1 on both sides); the two 938-line dumps of eight
 *    scenarios × both containers were `diff`-identical once the ADDED
 *    `manifest.mpd` files were removed. The three literals below cover exactly
 *    the flag-off paths S58 refactored and that S57's literals do not reach.
 *
 * @see \Phlix\Tests\Integration\Media\Transcoding\VodMpdSegmentResolutionTest
 *      for the half this file cannot reach: whether the names in the manifest
 *      are files real ffmpeg actually writes, and whether a real DASH demuxer
 *      can play them.
 */
final class TranscodeManagerVodMpdTest extends TestCase
{
    private const NS = 'urn:mpeg:dash:schema:mpd:2011';

    // ─────────────────────────────────────────────────────────────────
    // MPEG-TS literals — captured from origin/master @ a146cb57.
    // Do NOT regenerate these from the code under test.
    // ─────────────────────────────────────────────────────────────────

    /** Legacy job with no width/height/bandwidth: the LEGACY_BANDWIDTH fallback. */
    private const MASTER_LEGACY_NO_DESCRIPTORS = "#EXTM3U\n"
        . "#EXT-X-VERSION:3\n"
        . "#EXT-X-STREAM-INF:BANDWIDTH=3000000,CODECS=\"avc1.640029,mp4a.40.2\"\n"
        . "media_0.m3u8\n";

    /** `segment_seconds` 0: the six-second fallback grid. */
    private const MASTER_MEDIA_720P_ZERO_SEGSECS = "#EXTM3U\n"
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

    /** No audio descriptor flagged default: the first one still becomes DEFAULT=YES. */
    private const MASTER_MULTIVARIANT_NO_FLAGGED_DEFAULT = "#EXTM3U\n"
        . "#EXT-X-VERSION:3\n"
        . "#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID=\"aud\",NAME=\"English\",LANGUAGE=\"eng\","
        . "DEFAULT=YES,AUTOSELECT=YES,URI=\"media_a0.m3u8\"\n"
        . "#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID=\"aud\",NAME=\"Track 2\","
        . "DEFAULT=NO,AUTOSELECT=YES,URI=\"media_a1.m3u8\"\n"
        . "#EXT-X-STREAM-INF:BANDWIDTH=5128000,RESOLUTION=1920x1080,CODECS=\"avc1.640029,mp4a.40.2\","
        . "AUDIO=\"aud\"\n"
        . "media_v1080p.m3u8\n"
        . "#EXT-X-STREAM-INF:BANDWIDTH=3128000,RESOLUTION=1280x720,CODECS=\"avc1.640029,mp4a.40.2\","
        . "AUDIO=\"aud\"\n"
        . "media_v720p.m3u8\n";

    private string $segmentDir;

    protected function setUp(): void
    {
        $this->segmentDir = sys_get_temp_dir() . '/phlix_s58_' . uniqid();
        mkdir($this->segmentDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->segmentDir);
    }

    // ─────────────────────────────────────────────────────────────────
    // the validator itself
    // ─────────────────────────────────────────────────────────────────

    /**
     * The control without which every other schema assertion in this file is
     * worthless.
     *
     * `libxml_use_internal_errors(true)` makes libxml accumulate errors silently,
     * so a validator that never reads the error list — or that quietly failed to
     * LOAD the schema — reports every document as valid. Each case below is a
     * manifest that must be refused, and two of them are the exact defects the
     * shipped `AdaptationSet` emitted before S58 (`@id` a name rather than an
     * `xs:unsignedInt`, and a `@bandwidth` attribute that does not exist on an
     * AdaptationSet).
     *
     * @dataProvider malformedManifests
     */
    public function test_the_schema_validator_rejects_a_malformed_manifest(string $why, string $xml): void
    {
        $errors = MpdSchema::errors($xml);

        $this->assertNotSame([], $errors, "the validator ACCEPTED a manifest that is {$why}");
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function malformedManifests(): array
    {
        $good = self::referenceManifest();

        return [
            'pre-S58 AdaptationSet@id (a name, not an unsignedInt)' => [
                'an AdaptationSet whose id is a name',
                str_replace('<AdaptationSet id="0"', '<AdaptationSet id="video-1080"', $good),
            ],
            'pre-S58 AdaptationSet@bandwidth (not an attribute of AdaptationSet)' => [
                'an AdaptationSet carrying @bandwidth',
                str_replace('contentType="video"', 'contentType="video" bandwidth="5128000"', $good),
            ],
            'Representation before SegmentTemplate' => [
                'a set whose children are out of sequence order',
                self::swapTemplateAndRepresentation($good),
            ],
            'no profiles attribute' => [
                'missing the required @profiles',
                str_replace(' profiles="' . DashStreamer::PROFILE_ISOFF_LIVE . '"', '', $good),
            ],
            'no minBufferTime attribute' => [
                'missing the required @minBufferTime',
                str_replace(' minBufferTime="PT2S"', '', $good),
            ],
            'Representation without bandwidth' => [
                'a Representation missing the required @bandwidth',
                str_replace(' bandwidth="5128000"', '', $good),
            ],
            'mediaPresentationDuration is not an xs:duration' => [
                'a duration that is a bare number',
                str_replace('PT25.000S', '25.000', $good),
            ],
            'an empty @lang' => [
                'an AdaptationSet with an empty language',
                str_replace('contentType="video"', 'contentType="video" lang=""', $good),
            ],
            'not XML at all' => ['not well-formed XML', '<MPD><Period></MPD>'],
            'empty string' => ['empty', ''],
        ];
    }

    /**
     * Swaps the `<SegmentTemplate>` and `<Representation>` lines of the
     * reference manifest so the ONLY thing wrong with the result is child ORDER
     * — every element and attribute stays exactly as it was. A surgery that
     * mangled an element name instead would be rejected for the wrong reason and
     * would prove nothing about the sequence constraint.
     *
     * @throws \LogicException When the two lines cannot be found, so a silently
     *                         unchanged document can never be asserted on.
     */
    private static function swapTemplateAndRepresentation(string $manifest): string
    {
        $lines = explode("\n", $manifest);
        foreach ($lines as $i => $line) {
            if (
                str_contains($line, '<SegmentTemplate')
                && str_contains($lines[$i + 1] ?? '', '<Representation')
            ) {
                [$lines[$i], $lines[$i + 1]] = [$lines[$i + 1], $lines[$i]];
                return implode("\n", $lines);
            }
        }

        throw new \LogicException('the reference manifest no longer has adjacent SegmentTemplate/Representation lines');
    }

    /**
     * And the other half of the control: the reference manifest the malformed
     * cases are derived from must itself VALIDATE. Without this, every case
     * above could be failing for a reason that has nothing to do with the
     * mutation applied to it.
     */
    public function test_the_reference_manifest_the_negative_controls_derive_from_is_valid(): void
    {
        $this->assertSame([], MpdSchema::errors(self::referenceManifest()));
    }

    // ─────────────────────────────────────────────────────────────────
    // AC part 1 — every shape validates against the real schema
    // ─────────────────────────────────────────────────────────────────

    public function test_the_abr_manifest_validates_against_the_dash_mpd_schema(): void
    {
        $dir = $this->write('abr', self::abrVariants(), null, 'fmp4');

        $this->assertValidManifest($dir);
    }

    public function test_the_multi_audio_manifest_validates_against_the_dash_mpd_schema(): void
    {
        $dir = $this->write('multiaudio', self::abrVariants(), self::audioTracks(), 'fmp4');

        $this->assertValidManifest($dir);
    }

    public function test_the_legacy_single_variant_manifest_validates_against_the_dash_mpd_schema(): void
    {
        $dir = $this->write('legacy', null, null, 'fmp4');

        $this->assertValidManifest($dir);
    }

    /**
     * `AdaptationSet@lang` is `xs:language`. A language that HLS would have
     * emitted verbatim into a `LANGUAGE="…"` attribute without complaint makes
     * the WHOLE manifest schema-invalid here, so unusable values are dropped
     * rather than passed through — and the drop is what keeps the other tracks
     * describable at all.
     */
    public function test_an_unusable_audio_language_is_omitted_rather_than_invalidating_the_manifest(): void
    {
        $tracks = [
            ['index' => 0, 'stream_index' => 1, 'language' => '', 'label' => 'A', 'default' => true, 'codec' => 'aac'],
            ['index' => 1, 'stream_index' => 2, 'language' => 'und', 'label' => 'B', 'default' => false,
                'codec' => 'aac'],
            ['index' => 2, 'stream_index' => 3, 'language' => 'en US', 'label' => 'C', 'default' => false,
                'codec' => 'aac'],
            ['index' => 3, 'stream_index' => 4, 'language' => 'pt-BR', 'label' => 'D', 'default' => false,
                'codec' => 'aac'],
        ];
        $dir = $this->write('lang', self::abrVariants(), $tracks, 'fmp4');

        $this->assertValidManifest($dir);

        $langs = [];
        foreach ($this->adaptationSets($dir) as $set) {
            if ($set->getAttribute('contentType') === 'audio') {
                $langs[] = $set->hasAttribute('lang') ? $set->getAttribute('lang') : null;
            }
        }
        $this->assertSame([null, null, null, 'pt-BR'], $langs);
    }

    // ─────────────────────────────────────────────────────────────────
    // structure
    // ─────────────────────────────────────────────────────────────────

    /**
     * The defect the plan's own wording prescribes ("one AdaptationSet per ABR
     * rendition") and the pre-S58 class implemented: a DASH client adapts BETWEEN
     * the Representations of ONE AdaptationSet and never between AdaptationSets,
     * so a set per rung is a manifest with no adaptation in it.
     */
    public function test_the_video_ladder_is_one_adaptation_set_holding_one_representation_per_rung(): void
    {
        $dir = $this->write('ladder', self::abrVariants(), null, 'fmp4');

        $video = [];
        foreach ($this->adaptationSets($dir) as $set) {
            if ($set->getAttribute('contentType') === 'video') {
                $video[] = $set;
            }
        }

        $this->assertCount(1, $video, 'the ABR rungs must share ONE AdaptationSet or nothing can adapt');
        $this->assertSame(
            ['1080p', '720p'],
            $this->representationIds($video[0]),
            'one Representation per advertised rung, in master order'
        );
        $this->assertCount(
            1,
            $this->children($video[0], 'SegmentTemplate'),
            'exactly one SegmentTemplate, shared by every representation'
        );
    }

    /**
     * The manifest's Representation set is exactly the master playlist's
     * `#EXT-X-STREAM-INF` level set — same ids, same order, same bandwidths.
     *
     * That is how the SV-4.6 exclusion of the stream-COPY `original` reaches
     * DASH: a copy gets no forced IDR, so its boundaries drift to the nearest
     * source GOP and `segmentAlignment="true"` would be a lie. The copy keeps its
     * own `media_voriginal.m3u8` (S49), which this test also pins, so the
     * exclusion is provably from the MPD only.
     */
    public function test_the_representation_set_is_exactly_the_master_playlists_level_set(): void
    {
        $dir = $this->write('levels', self::abrVariants(), null, 'fmp4');

        $master = (string) file_get_contents("{$dir}/master.m3u8");
        $this->assertSame(
            ['media_v1080p.m3u8', 'media_v720p.m3u8'],
            $this->uriLines($master),
            'control: the master advertises exactly the two transcode rungs'
        );
        $this->assertFileExists(
            "{$dir}/media_voriginal.m3u8",
            'the copy Original keeps its own playlist; only its ABR/DASH advertisement is withheld'
        );

        $video = $this->videoSet($dir);
        $this->assertSame(['1080p', '720p'], $this->representationIds($video));
        $this->assertSame(
            ['5128000', '3128000'],
            array_map(
                static fn (DOMElement $r): string => $r->getAttribute('bandwidth'),
                $this->children($video, 'Representation')
            ),
            'a Representation@bandwidth that disagreed with its BANDWIDTH= level would rank the ladder '
            . 'differently for a DASH client than for an HLS one over identical files'
        );
    }

    /**
     * A ladder in which NOTHING is ABR-switchable publishes no manifest at all,
     * while HLS still falls back to the full variant list.
     *
     * The asymmetry is deliberate. HLS must stay playable, so the master falls
     * back. DASH has no way to say "these boundaries are approximate", so
     * publishing a `segmentAlignment="true"` manifest over stream-copy segments
     * would be a lie a client cannot detect — and an absent manifest is a clean
     * 404 that S59's serve path already has to handle.
     */
    public function test_a_degenerate_ladder_publishes_no_manifest_while_hls_still_falls_back(): void
    {
        $copyOnly = [
            new Rendition('original', 'Original', 1920, 1080, 8000000, 7500000, 'avc1.640029,mp4a.40.2', true, true),
        ];
        $dir = $this->write('degenerate', $copyOnly, null, 'fmp4');

        $this->assertFileDoesNotExist("{$dir}/" . TranscodeManager::MPD_FILENAME);
        $this->assertSame(
            ['media_voriginal.m3u8'],
            $this->uriLines((string) file_get_contents("{$dir}/master.m3u8")),
            'control: HLS still advertises the copy, so the absence above is the MPD declining, '
            . 'not the writer failing'
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // agreement with the S57 playlists
    // ─────────────────────────────────────────────────────────────────

    /**
     * The join, and the point of the whole step: expand every
     * `SegmentTemplate` for every Representation over every index, and require
     * the resulting names to be EXACTLY the segment URIs the corresponding HLS
     * media playlist lists — plus the `#EXT-X-MAP` init for the same rendition.
     *
     * A manifest that is internally perfect but names files the playlists (and
     * therefore the producer, which resolves the same
     * `segmentFileName()`/`initSegmentFileName()` pair) never write is the
     * failure mode a schema check cannot see.
     */
    public function test_every_segment_template_expansion_matches_the_hls_playlist_of_the_same_rendition(): void
    {
        $dir = $this->write('join', self::abrVariants(), self::audioTracks(), 'fmp4');

        $checked = 0;
        foreach ($this->adaptationSets($dir) as $set) {
            $template = $this->children($set, 'SegmentTemplate')[0];
            foreach ($this->children($set, 'Representation') as $rep) {
                $id = $rep->getAttribute('id');
                $playlist = $set->getAttribute('contentType') === 'audio'
                    ? "{$dir}/media_{$id}.m3u8"
                    : "{$dir}/media_v{$id}.m3u8";
                $this->assertFileExists($playlist);
                $text = (string) file_get_contents($playlist);

                $this->assertSame(
                    $this->expandMedia($template, $id, count($this->uriLines($text))),
                    $this->uriLines($text),
                    "the manifest and media playlist disagree about {$id}'s segment names"
                );
                $this->assertSame(
                    $this->expandInitialization($template, $id),
                    $this->extMapUri($text),
                    "the manifest and the #EXT-X-MAP disagree about {$id}'s init segment"
                );
                $checked++;
            }
        }

        // Denominator: 2 video rungs + 2 audio tracks. A loop that matched
        // nothing would otherwise read exactly like a pass.
        $this->assertSame(4, $checked);
    }

    /**
     * The count a DASH client derives (`ceil(mediaPresentationDuration /
     * (@duration / @timescale))`) must be the number of `#EXTINF` entries the
     * playlists carry, or the two protocols disagree about how long the title is
     * over identical files.
     */
    public function test_the_manifest_and_the_playlists_agree_on_the_segment_count(): void
    {
        $dir = $this->write('count', self::abrVariants(), null, 'fmp4', 25.0, 6);

        $mpd = $this->manifest($dir);
        $template = $this->children($this->videoSet($dir), 'SegmentTemplate')[0];
        $segmentSeconds = (float) $template->getAttribute('duration') / (float) $template->getAttribute('timescale');
        $total = $this->mediaPresentationSeconds($mpd);

        $this->assertSame(6.0, $segmentSeconds);
        $this->assertSame(
            count($this->uriLines((string) file_get_contents("{$dir}/media_v720p.m3u8"))),
            (int) ceil($total / $segmentSeconds)
        );
        $this->assertSame(5, (int) ceil($total / $segmentSeconds), 'control: 25 s at 6 s is five segments');
    }

    /**
     * `@timescale` and `@startNumber` are both bugs waiting to happen and both
     * were wrong in the pre-S58 class: MPD's DEFAULT timescale is 1 (so an
     * undeclared one turns `duration="6000"` into 6000 SECONDS) and its default
     * `startNumber` is 1 (so a client would ask for `…-00001.m4s` first and never
     * fetch segment 0, which is the only one that exists at play-start).
     */
    public function test_the_segment_template_declares_a_millisecond_timescale_and_a_zero_start_number(): void
    {
        $dir = $this->write('template', self::abrVariants(), null, 'fmp4', 25.0, 4);
        $template = $this->children($this->videoSet($dir), 'SegmentTemplate')[0];

        $this->assertSame('1000', $template->getAttribute('timescale'));
        $this->assertSame('4000', $template->getAttribute('duration'));
        $this->assertSame('0', $template->getAttribute('startNumber'));
        $this->assertSame(
            "seg-v\$RepresentationID\$-\$Number%05d\$.m4s",
            $template->getAttribute('media')
        );
        $this->assertSame("init-v\$RepresentationID\$.m4s", $template->getAttribute('initialization'));
    }

    /**
     * A `mediaPresentationDuration` even a millisecond SHORT of the real length
     * can drop the final, partial segment — the same content the HLS playlist
     * protects with a short last `#EXTINF`. So it rounds up.
     */
    public function test_the_media_presentation_duration_is_rounded_up_never_down(): void
    {
        $dir = $this->write('dur', self::abrVariants(), null, 'fmp4', 24.0834567, 6);

        $this->assertSame('PT24.084S', $this->manifest($dir)->documentElement?->getAttribute(
            'mediaPresentationDuration'
        ));
        $this->assertSame('PT0.001S', DashStreamer::xsDuration(0.0000001));
        $this->assertSame('PT6.000S', DashStreamer::xsDuration(6.0), 'an exact value must not be inflated');
    }

    // ─────────────────────────────────────────────────────────────────
    // audio
    // ─────────────────────────────────────────────────────────────────

    /**
     * One AdaptationSet per audio TRACK, not one holding all of them.
     * Representations inside a set are switched automatically on bandwidth —
     * which for languages would silently change what the viewer is hearing.
     */
    public function test_each_audio_track_is_its_own_adaptation_set_with_its_own_language(): void
    {
        $dir = $this->write('audio', self::abrVariants(), self::audioTracks(), 'fmp4');

        $audio = [];
        foreach ($this->adaptationSets($dir) as $set) {
            if ($set->getAttribute('contentType') === 'audio') {
                $audio[] = $set;
            }
        }

        $this->assertCount(2, $audio);
        $this->assertSame(['eng', 'fra'], array_map(
            static fn (DOMElement $s): string => $s->getAttribute('lang'),
            $audio
        ));
        $this->assertSame([['a0'], ['a1']], array_map(
            fn (DOMElement $s): array => $this->representationIds($s),
            $audio
        ));
        $this->assertSame(
            ['0', '1', '2'],
            array_map(
                static fn (DOMElement $s): string => $s->getAttribute('id'),
                $this->adaptationSets($dir)
            ),
            'AdaptationSet@id is xs:unsignedInt and must be unique within the Period'
        );
    }

    /**
     * DASH expresses "this is the default track" as `Role value="main"`; HLS
     * expresses it as `DEFAULT=YES`. Both are now decided by the SAME
     * `defaultAudioPosition()`, so a job cannot default to English on hls.js and
     * to French on a DASH client. The fixture flags the SECOND track, so an
     * implementation that just took the first would fail.
     */
    public function test_the_role_main_audio_set_is_the_playlists_default_rendition(): void
    {
        $tracks = self::audioTracks();
        $tracks[0]['default'] = false;
        $tracks[1]['default'] = true;
        $dir = $this->write('default', self::abrVariants(), $tracks, 'fmp4');

        $roles = [];
        foreach ($this->adaptationSets($dir) as $set) {
            if ($set->getAttribute('contentType') !== 'audio') {
                continue;
            }
            $role = $this->children($set, 'Role')[0];
            $roles[$this->representationIds($set)[0]] = $role->getAttribute('value');
        }

        $this->assertSame(['a0' => 'alternate', 'a1' => 'main'], $roles);

        $master = (string) file_get_contents("{$dir}/master.m3u8");
        $this->assertStringContainsString('URI="media_a1.m3u8"', $master);
        $this->assertMatchesRegularExpression(
            '/#EXT-X-MEDIA:[^\n]*DEFAULT=YES[^\n]*URI="media_a1\.m3u8"/',
            $master,
            'control: the HLS default is the same track the MPD marks main'
        );
    }

    /**
     * A multi-audio job's video segments are encoded `-an`, so their
     * Representation must not keep claiming `mp4a.40.2`: a DASH client reads
     * `@codecs` as the exhaustive contents of the segments and will wait for an
     * audio track that never arrives. The single-audio control is the other half
     * — there the audio IS muxed in, so the codec must stay.
     */
    public function test_a_multi_audio_video_representation_drops_the_audio_codec(): void
    {
        $multi = $this->write('codecs-multi', self::abrVariants(), self::audioTracks(), 'fmp4');
        $single = $this->write('codecs-single', self::abrVariants(), null, 'fmp4');

        $this->assertSame(
            ['avc1.640029', 'avc1.640029'],
            array_map(
                static fn (DOMElement $r): string => $r->getAttribute('codecs'),
                $this->children($this->videoSet($multi), 'Representation')
            )
        );
        $this->assertSame(
            ['avc1.640029,mp4a.40.2', 'avc1.640029,mp4a.40.2'],
            array_map(
                static fn (DOMElement $r): string => $r->getAttribute('codecs'),
                $this->children($this->videoSet($single), 'Representation')
            ),
            'control: with audio muxed into the video segments the codec must stay'
        );
        $this->assertSame(
            ['mp4a.40.2', 'mp4a.40.2'],
            array_map(
                static fn (DOMElement $r): string => $r->getAttribute('codecs'),
                array_merge(...array_map(
                    fn (DOMElement $s): array => $this->children($s, 'Representation'),
                    array_values(array_filter(
                        $this->adaptationSets($multi),
                        static fn (DOMElement $s): bool => $s->getAttribute('contentType') === 'audio'
                    ))
                ))
            ),
            'and every audio rendition is re-encoded to AAC-LC whatever the source codec was'
        );
    }

    /**
     * A job with exactly ONE audio track has that track MUXED into every video
     * segment (`writeVodPlaylists()` only forms an audio group above one), so it
     * must get no audio AdaptationSet and its video Representations must keep
     * `mp4a.40.2`. The manifest would otherwise promise `seg-a0-NNNNN.m4s` files
     * that nothing ever produces.
     *
     * (Found by mutation M5, which passed the descriptor list through
     * unconditionally and survived every other case in this file.)
     */
    public function test_a_single_audio_track_is_muxed_and_gets_no_audio_adaptation_set(): void
    {
        $one = [self::audioTracks()[0]];
        $dir = $this->write('one-audio', self::abrVariants(), $one, 'fmp4');

        $this->assertSame(
            ['video'],
            array_map(
                static fn (DOMElement $s): string => $s->getAttribute('contentType'),
                $this->adaptationSets($dir)
            )
        );
        $this->assertSame(
            ['avc1.640029,mp4a.40.2', 'avc1.640029,mp4a.40.2'],
            array_map(
                static fn (DOMElement $r): string => $r->getAttribute('codecs'),
                $this->children($this->videoSet($dir), 'Representation')
            ),
            'a single track stays muxed, so the video representations still carry it'
        );
        $this->assertFileDoesNotExist(
            "{$dir}/media_a0.m3u8",
            'control: the playlists agree — no audio group was formed either'
        );
    }

    /**
     * A DASH client ranks Representations by `@bandwidth`; an audio set
     * advertising 0 would always look like the cheapest possible choice.
     *
     * (Found by mutation M14.)
     */
    public function test_an_audio_representation_advertises_the_nominal_aac_allowance(): void
    {
        $dir = $this->write('audio-bw', self::abrVariants(), self::audioTracks(), 'fmp4');

        foreach ($this->adaptationSets($dir) as $set) {
            if ($set->getAttribute('contentType') !== 'audio') {
                continue;
            }
            $this->assertSame(
                (string) Rendition::AUDIO_BANDWIDTH,
                $this->children($set, 'Representation')[0]->getAttribute('bandwidth')
            );
            $this->assertSame('128000', $this->children($set, 'Representation')[0]->getAttribute('bandwidth'));
        }
    }

    /**
     * The manifest's NAME and the MPD's fixed attributes, asserted as literals.
     *
     * Every other test in this file reaches the manifest through
     * `TranscodeManager::MPD_FILENAME` and `DashStreamer::PROFILE_ISOFF_LIVE`, so
     * all of them SELF-ADJUST when those constants move — a check derived from
     * its own subject. These are the values other components already hardcode:
     * `DashController::getManifest()` advertises `/dash/{job}/manifest.mpd`, and
     * `TranscodeFileServer::contentTypeFor()` types `mpd` as
     * `application/dash+xml`. `type="static"` is likewise load-bearing: a
     * `dynamic` MPD tells a client the presentation is LIVE, which changes
     * segment availability to wall-clock and takes seeking away.
     *
     * (Found by mutations M33, M36 and M37 — all three survived the entire
     * matrix on nothing but constant-following assertions.)
     */
    public function test_the_manifests_name_profile_and_type_are_the_literals_other_components_assume(): void
    {
        $this->assertSame('manifest.mpd', TranscodeManager::MPD_FILENAME);
        $this->assertSame('urn:mpeg:dash:profile:isoff-live:2011', DashStreamer::PROFILE_ISOFF_LIVE);

        $dir = $this->write('literals', self::abrVariants(), null, 'fmp4');
        $this->assertFileExists("{$dir}/manifest.mpd");

        $root = $this->manifest($dir)->documentElement;
        $this->assertNotNull($root);
        $this->assertSame('urn:mpeg:dash:profile:isoff-live:2011', $root->getAttribute('profiles'));
        $this->assertSame('static', $root->getAttribute('type'));
        $this->assertSame('PT2S', $root->getAttribute('minBufferTime'));
        $this->assertSame('urn:mpeg:dash:schema:mpd:2011', $root->namespaceURI);
    }

    // ─────────────────────────────────────────────────────────────────
    // the flag-off guarantee — literals from origin/master @ a146cb57
    // ─────────────────────────────────────────────────────────────────

    /**
     * With the shipped `mpegts` default nothing DASH-shaped is written at all —
     * for every job shape, not just the ABR one. An MPEG-TS job has no init
     * segment for `@initialization` to point at, so a manifest over it could
     * never be expanded by any client.
     */
    public function test_flag_off_writes_no_manifest_for_any_job_shape(): void
    {
        $shapes = [
            'off-abr' => [self::abrVariants(), null],
            'off-multiaudio' => [self::abrVariants(), self::audioTracks()],
            'off-legacy' => [null, null],
        ];
        foreach ($shapes as $name => [$variants, $tracks]) {
            $dir = $this->write($name, $variants, $tracks, 'mpegts');

            $this->assertFileDoesNotExist("{$dir}/" . TranscodeManager::MPD_FILENAME, $name);
            $this->assertFileExists("{$dir}/master.m3u8", "{$name}: control — the playlists were still written");
        }
    }

    /**
     * The legacy master for a row carrying NO descriptors, byte-identical to
     * `origin/master`. S58 lifted the `3000000` and the `avc1.640029,mp4a.40.2`
     * out into constants shared with the MPD, and this is the literal that
     * catches the lift moving a byte.
     */
    public function test_flag_off_legacy_master_with_no_descriptors_is_byte_identical_to_master(): void
    {
        $dir = $this->write('lit-legacy', null, null, 'mpegts', 25.0, 6, null, null, null);

        $this->assertSame(
            self::MASTER_LEGACY_NO_DESCRIPTORS,
            (string) file_get_contents("{$dir}/master.m3u8")
        );
    }

    /**
     * A non-positive `segment_seconds` falls back to six. S58 lifted that guard
     * out of the two playlist builders so the DASH `@duration` cannot fall back
     * to a different number; this literal is the proof the lift is inert.
     */
    public function test_flag_off_a_non_positive_segment_length_still_produces_the_six_second_grid(): void
    {
        $dir = $this->write('lit-zero', self::abrVariants(), null, 'mpegts', 25.0, 0);

        $this->assertSame(
            self::MASTER_MEDIA_720P_ZERO_SEGSECS,
            (string) file_get_contents("{$dir}/media_v720p.m3u8")
        );
    }

    /**
     * When no descriptor is flagged default the FIRST track still becomes
     * `DEFAULT=YES`. S58 lifted that choice into `defaultAudioPosition()` so the
     * MPD's `Role main` agrees with it; this literal is the proof the lift is
     * inert on the playlist side.
     */
    public function test_flag_off_the_master_still_defaults_the_first_audio_track_when_none_is_flagged(): void
    {
        $tracks = self::audioTracks();
        $tracks[0]['default'] = false;
        $tracks[1]['default'] = false;
        $tracks[1]['language'] = '';
        $tracks[1]['label'] = '';
        $dir = $this->write('lit-nodefault', self::abrVariants(), $tracks, 'mpegts');

        $this->assertSame(
            self::MASTER_MULTIVARIANT_NO_FLAGGED_DEFAULT,
            (string) file_get_contents("{$dir}/master.m3u8")
        );
    }

    /**
     * And the same fallback, seen from the MPD: the first track is `main`.
     */
    public function test_with_no_flagged_default_the_first_audio_set_is_the_main_one(): void
    {
        $tracks = self::audioTracks();
        $tracks[0]['default'] = false;
        $tracks[1]['default'] = false;
        $dir = $this->write('mpd-nodefault', self::abrVariants(), $tracks, 'fmp4');

        $roles = [];
        foreach ($this->adaptationSets($dir) as $set) {
            if ($set->getAttribute('contentType') === 'audio') {
                $roles[] = $this->children($set, 'Role')[0]->getAttribute('value');
            }
        }
        $this->assertSame(['main', 'alternate'], $roles);
    }

    // ─────────────────────────────────────────────────────────────────
    // the two production call sites
    // ─────────────────────────────────────────────────────────────────

    public function test_job_creation_writes_the_manifest_when_the_setting_is_on(): void
    {
        $dir = $this->ensureJobDir('fmp4');

        $this->assertFileExists("{$dir}/" . TranscodeManager::MPD_FILENAME);
        $this->assertSame([], MpdSchema::errors((string) file_get_contents(
            "{$dir}/" . TranscodeManager::MPD_FILENAME
        )));
    }

    public function test_job_creation_writes_no_manifest_when_the_setting_is_off(): void
    {
        $dir = $this->ensureJobDir(null);

        $this->assertFileDoesNotExist("{$dir}/" . TranscodeManager::MPD_FILENAME);
    }

    /**
     * The writer's SECOND caller. `sweepSegmentCache()` evicts an idle job
     * directory while the row lives on; the next request rebuilds it here. The
     * manifest has to come back with the playlists — and it has to come back in
     * the container the JOB was created with. The manager below is built with no
     * `EncodeSettings` at all, so the LIVE value is the `mpegts` default.
     */
    public function test_regeneration_writes_the_manifest_for_the_jobs_own_container(): void
    {
        $dir = $this->segmentDir . '/regen';
        mkdir($dir, 0755, true);

        $manager = new TranscodeManager(
            $this->rowDb($this->jobRow('regen', $dir, 'fmp4')),
            $this->createMock(FfmpegRunner::class),
            $this->segmentDir
        );
        $this->assertTrue($manager->ensurePlaylistRegenerated('regen'));

        $mpd = "{$dir}/" . TranscodeManager::MPD_FILENAME;
        $this->assertFileExists($mpd);
        $this->assertSame([], MpdSchema::errors((string) file_get_contents($mpd)));
        $this->assertStringContainsString(
            'id="regen"',
            (string) file_get_contents($mpd),
            'MPD@id is the job id, taken from the directory the writer was handed'
        );
    }

    public function test_regeneration_of_an_mpegts_job_writes_no_manifest(): void
    {
        $dir = $this->segmentDir . '/regen-off';
        mkdir($dir, 0755, true);

        $manager = new TranscodeManager(
            $this->rowDb($this->jobRow('regen-off', $dir, null)),
            $this->createMock(FfmpegRunner::class),
            $this->segmentDir
        );
        $this->assertTrue($manager->ensurePlaylistRegenerated('regen-off'));

        $this->assertFileDoesNotExist("{$dir}/" . TranscodeManager::MPD_FILENAME);
        $this->assertFileExists("{$dir}/master.m3u8");
    }

    /**
     * The LEGACY regeneration branch is a separate `writeVodPlaylists()` call and
     * was therefore a separate place to forget the manifest (S57's mutation M18
     * survived on exactly that asymmetry).
     */
    public function test_legacy_regeneration_writes_a_manifest_over_the_unprefixed_names(): void
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

        $mpd = "{$dir}/" . TranscodeManager::MPD_FILENAME;
        $this->assertSame([], MpdSchema::errors((string) file_get_contents($mpd)));

        $template = $this->children($this->videoSet($dir), 'SegmentTemplate')[0];
        $this->assertSame("seg-\$Number%05d\$.m4s", $template->getAttribute('media'));
        $this->assertSame('init.m4s', $template->getAttribute('initialization'));
        $this->assertSame(
            $this->uriLines((string) file_get_contents("{$dir}/media_0.m3u8")),
            $this->expandMedia($template, '0', 2),
            'the legacy template must expand to the legacy playlist names'
        );
    }

    /**
     * A `segment_params` that will not decode degrades to `mpegts`
     * ({@see TranscodeManager::segmentFormatOfRow()}), and therefore to no
     * manifest — never to an fMP4 manifest over files nothing will write.
     */
    public function test_an_undecodable_segment_params_regenerates_without_a_manifest(): void
    {
        $dir = $this->segmentDir . '/regen-corrupt';
        mkdir($dir, 0755, true);

        $row = $this->jobRow('regen-corrupt', $dir, 'fmp4');
        $row['segment_params'] = '{"video_codec":"libx264","segment_format":"fmp4"';

        $manager = new TranscodeManager($this->rowDb($row), $this->createMock(FfmpegRunner::class), $this->segmentDir);
        $this->assertTrue($manager->ensurePlaylistRegenerated('regen-corrupt'));

        $this->assertFileDoesNotExist("{$dir}/" . TranscodeManager::MPD_FILENAME);
    }

    // ─────────────────────────────────────────────────────────────────
    // fixtures
    // ─────────────────────────────────────────────────────────────────

    /** @return list<Rendition> */
    private static function abrVariants(): array
    {
        return [
            new Rendition(
                'original',
                'Original (1920x1080)',
                1920,
                1080,
                8000000,
                7500000,
                'avc1.640029,mp4a.40.2',
                true,
                true
            ),
            new Rendition('1080p', '1080p', 1920, 1080, 5128000, 4700000, 'avc1.640029,mp4a.40.2', false, false),
            new Rendition('720p', '720p', 1280, 720, 3128000, 2800000, 'avc1.640029,mp4a.40.2', false, false),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function audioTracks(): array
    {
        return [
            ['index' => 0, 'stream_index' => 1, 'language' => 'eng', 'label' => 'English',
                'default' => true, 'codec' => 'aac'],
            ['index' => 1, 'stream_index' => 2, 'language' => 'fra', 'label' => 'Francais',
                'default' => false, 'codec' => 'ac3'],
        ];
    }

    /**
     * A hand-written, schema-VALID manifest of the target shape — the base the
     * negative controls mutate. Deliberately not produced by the code under
     * test: a control derived from its own subject self-adjusts.
     */
    private static function referenceManifest(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<MPD xmlns="urn:mpeg:dash:schema:mpd:2011" id="job-1"'
            . ' profiles="' . DashStreamer::PROFILE_ISOFF_LIVE . '" type="static"'
            . ' minBufferTime="PT2S" mediaPresentationDuration="PT25.000S">' . "\n"
            . '  <Period id="1" start="PT0S">' . "\n"
            . '    <AdaptationSet id="0" contentType="video" mimeType="video/mp4"'
            . ' segmentAlignment="true" startWithSAP="1">' . "\n"
            . '      <SegmentTemplate timescale="1000" duration="6000" startNumber="0"'
            . ' media="seg-v$RepresentationID$-$Number%05d$.m4s"'
            . ' initialization="init-v$RepresentationID$.m4s"/>' . "\n"
            . '      <Representation id="1080p" codecs="avc1.640029" bandwidth="5128000"'
            . ' width="1920" height="1080"/>' . "\n"
            . '    </AdaptationSet>' . "\n"
            . '  </Period>' . "\n"
            . '</MPD>' . "\n";
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function jobRow(string $id, string $dir, ?string $format, array $extra = []): array
    {
        $params = ['video_codec' => 'libx264'];
        if ($format !== null) {
            $params['segment_format'] = $format;
        }

        return $extra + [
            'id' => $id,
            'hls_dir' => $dir,
            'duration_seconds' => 25,
            'segment_seconds' => 6,
            'segment_params' => json_encode($params),
            'variants' => json_encode(['renditions' => [
                ['id' => '1080p', 'width' => 1920, 'height' => 1080, 'bitrate' => 5128000,
                    'codecs' => 'avc1.640029,mp4a.40.2', 'is_copy' => false],
                ['id' => '720p', 'width' => 1280, 'height' => 720, 'bitrate' => 3128000,
                    'codecs' => 'avc1.640029,mp4a.40.2', 'is_copy' => false],
            ]]),
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // drivers
    // ─────────────────────────────────────────────────────────────────

    /**
     * Invokes the real `writeVodPlaylists()` and returns the directory it wrote to.
     *
     * @param list<Rendition>|null            $variants
     * @param list<array<string, mixed>>|null $audioTracks
     */
    private function write(
        string $name,
        ?array $variants,
        ?array $audioTracks,
        string $format,
        float $duration = 25.0,
        int $segSeconds = 6,
        ?int $width = 1920,
        ?int $height = 1080,
        ?int $bandwidth = 5128000
    ): string {
        $dir = "{$this->segmentDir}/{$name}";
        mkdir($dir, 0755, true);

        $method = new ReflectionMethod(TranscodeManager::class, 'writeVodPlaylists');
        $method->setAccessible(true);
        $method->invokeArgs($this->manager(), [
            $dir, $duration, $segSeconds, $width, $height, $bandwidth, $variants, $audioTracks, $format,
        ]);

        return $dir;
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
                if (str_contains($sql, 'COUNT(*)')) {
                    return [['c' => 0]];
                }
                if (str_contains($sql, 'FROM media_items')) {
                    return [['path' => '/m.mkv']];
                }
                return [];
            }
        );

        $ffmpeg = $this->createMock(FfmpegRunner::class);
        $ffmpeg->method('extractColorMetadata')->willReturn([
            'color_space' => 'bt709',
            'color_transfer' => 'bt709',
            'color_primaries' => 'bt709',
            'max_luminance' => 1000.0,
            'avg_luminance' => 200.0,
        ]);
        $ffmpeg->method('probe')->willReturn([
            'streams' => [
                ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1280, 'height' => 720],
                ['codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 2],
            ],
            'format' => ['duration' => '25.0'],
        ]);

        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->willReturnCallback(
            /** @return mixed */
            static fn (string $key) => $key === EncodeSettings::SEGMENT_FORMAT_KEY ? $format : null
        );

        $manager = new TranscodeManager(
            $db,
            $ffmpeg,
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
            new EncodeSettings($settings)
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

    // ─────────────────────────────────────────────────────────────────
    // MPD helpers
    // ─────────────────────────────────────────────────────────────────

    private function assertValidManifest(string $dir): void
    {
        $path = "{$dir}/" . TranscodeManager::MPD_FILENAME;
        $this->assertFileExists($path);
        $xml = (string) file_get_contents($path);
        $this->assertNotSame('', $xml, 'an empty manifest must not read as a pass');

        $errors = MpdSchema::errors($xml);
        $this->assertSame([], $errors, "manifest is not schema-valid:\n" . implode("\n", $errors) . "\n{$xml}");
    }

    private function manifest(string $dir): DOMDocument
    {
        $doc = new DOMDocument();
        $this->assertTrue($doc->load("{$dir}/" . TranscodeManager::MPD_FILENAME));

        return $doc;
    }

    /** @return list<DOMElement> */
    private function adaptationSets(string $dir): array
    {
        $doc = $this->manifest($dir);
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('m', self::NS);

        $out = [];
        foreach ($xpath->query('/m:MPD/m:Period/m:AdaptationSet') ?: [] as $node) {
            $this->assertInstanceOf(DOMElement::class, $node);
            $out[] = $node;
        }

        return $out;
    }

    private function videoSet(string $dir): DOMElement
    {
        foreach ($this->adaptationSets($dir) as $set) {
            if ($set->getAttribute('contentType') === 'video') {
                return $set;
            }
        }
        $this->fail('the manifest carries no video AdaptationSet');
    }

    /** @return list<DOMElement> */
    private function children(DOMElement $parent, string $name): array
    {
        $out = [];
        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMElement && $node->localName === $name) {
                $out[] = $node;
            }
        }
        $this->assertNotSame([], $out, "expected at least one <{$name}> child");

        return $out;
    }

    /** @return list<string> */
    private function representationIds(DOMElement $set): array
    {
        return array_map(
            static fn (DOMElement $r): string => $r->getAttribute('id'),
            $this->children($set, 'Representation')
        );
    }

    /**
     * Expands `SegmentTemplate@media` the way a client does.
     *
     * @return list<string>
     */
    private function expandMedia(DOMElement $template, string $representationId, int $count): array
    {
        $start = (int) $template->getAttribute('startNumber');
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = $this->expand($template->getAttribute('media'), $representationId, $start + $i);
        }

        return $out;
    }

    private function expandInitialization(DOMElement $template, string $representationId): string
    {
        return $this->expand($template->getAttribute('initialization'), $representationId, 0);
    }

    private function expand(string $template, string $representationId, int $number): string
    {
        $expanded = str_replace('$RepresentationID$', $representationId, $template);
        $expanded = (string) preg_replace_callback(
            '/\$Number%0(\d+)d\$/',
            static fn (array $m): string => sprintf('%0' . $m[1] . 'd', $number),
            $expanded
        );

        return str_replace('$Number$', (string) $number, $expanded);
    }

    private function mediaPresentationSeconds(DOMDocument $doc): float
    {
        $raw = $doc->documentElement?->getAttribute('mediaPresentationDuration') ?? '';
        $this->assertMatchesRegularExpression('/^PT[0-9.]+S$/', $raw, "unparseable duration '{$raw}'");

        return (float) substr($raw, 2, -1);
    }

    // ─────────────────────────────────────────────────────────────────
    // playlist helpers
    // ─────────────────────────────────────────────────────────────────

    /**
     * The URI lines of a playlist, in order.
     *
     * @return list<string>
     */
    private function uriLines(string $playlist): array
    {
        $out = [];
        foreach (explode("\n", $playlist) as $line) {
            if ($line !== '' && !str_starts_with($line, '#')) {
                $out[] = $line;
            }
        }

        return $out;
    }

    private function extMapUri(string $playlist): string
    {
        $this->assertSame(
            1,
            preg_match('/^#EXT-X-MAP:URI="([^"]+)"$/m', $playlist, $m),
            'the fMP4 playlist must carry exactly one #EXT-X-MAP for the comparison to mean anything'
        );

        return $m[1];
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob("{$dir}/*") ?: [] as $path) {
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
