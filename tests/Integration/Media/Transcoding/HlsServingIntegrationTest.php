<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media\Transcoding;

use PHPUnit\Framework\TestCase;
use Phlix\Admin\SettingsRepository;
use Phlix\Media\Streaming\HlsStreamer;
use Phlix\Media\Streaming\QualitySelector;
use Phlix\Media\Transcoding\EncodeSettings;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\TranscodeManager;
use Phlix\Server\Http\Controllers\HlsController;
use Phlix\Server\Http\Request;
use Workerman\MySQL\Connection;

/**
 * Full on-demand MULTI-VARIANT HLS chain against a REAL ffmpeg binary (A5):
 * TranscodeManager publishes a complete VOD master (every variant) + one media
 * playlist per variant up front (no background encode); HlsController serves the
 * playlists; and an individual per-variant MPEG-TS segment is transcoded ON DEMAND
 * via `ensureSegment($jobId, $variant, $index)` — including a LATER segment with no
 * earlier segment produced first (seek-anywhere), the copy "original" passthrough,
 * and an out-of-range 404. (HlsController variant-filename PARSING lands in A6; this
 * test drives per-variant production through the manager directly.) The DB is mocked;
 * ffmpeg/filesystem are real. Skipped when ffmpeg is absent.
 *
 * ## ⚠ S60 — this file now runs the SAME chain in BOTH containers
 *
 * It used to run once, implicitly, at whatever `EncodeSettings` resolved with no
 * override — which was MPEG-TS from the beginning of the project until S60. That
 * made "MPEG-TS still works" and "the shipped default works" indistinguishable.
 * They are different claims now and this file makes both, over real ffmpeg
 * output:
 *
 *  - {@see self::testManagerPublishesVodPlaylistAndServesSegmentsOnDemand()} —
 *    NO override, i.e. exactly what an install gets today: fMP4, `#EXT-X-MAP`,
 *    a real `init-v240p.m4s` and `video/mp4` off the controller.
 *  - {@see self::testTheMpegTsRollbackPublishesAndServesTheSameChain()} — an
 *    EXPLICIT `transcoding.segment_format = mpegts`, i.e. the documented
 *    rollback (`PUT /api/v1/admin/settings`), DEMONSTRATED rather than asserted:
 *    the same source through the same code produces `.ts` and serves
 *    `video/mp2t`.
 *
 * Each is the other's control. Without the fMP4 arm, "the `.ts` path works"
 * could be true of a build that had silently ignored the flip; without the
 * MPEG-TS arm, the rollback would be a claim with nothing behind it.
 */
class HlsServingIntegrationTest extends TestCase
{
    /**
     * S486 — teardown-race sentinel (mirrors the S460 lane-sentinel shape in
     * tests/Unit/Media/Transcoding/FfmpegRunnerHlsTest). Every cleanup failure
     * this file raises names it, so a leftover `phlix_hls_ondemand_*` from the
     * zero-residue census attributes to this class instead of surfacing as an
     * anonymous directory several suites later.
     */
    private const S486_LANE_SENTINEL = 'S486FIXREAPX9P4';

    /** Ceiling for the detached-writer quiescence poll below (S460 budget). */
    private const DETACHED_MARKER_TIMEOUT_SECONDS = 15.0;

    /** Poll cadence while waiting for `.part-*` markers to disappear. */
    private const DETACHED_MARKER_POLL_INTERVAL_US = 20_000;

    /** Bounded retries for the guarded recursive removal (late `rename()`s). */
    private const REMOVE_DIR_ATTEMPTS = 3;

    private string $segmentDir;
    private FfmpegRunner $ffmpeg;

    /** @var array<string, mixed> The job row echoed back for `SELECT * ... WHERE id`. */
    private array $insertedJob = [];

    protected function setUp(): void
    {
        $this->ffmpeg = new FfmpegRunner('/usr/bin/ffmpeg', '/usr/bin/ffprobe', sys_get_temp_dir());
        if (!$this->ffmpeg->isAvailable()) {
            $this->markTestSkipped('ffmpeg binary not available');
        }
        // random_bytes, not uniqid(): two workers created in the same microsecond
        // share a uniqid() name (S460 note), and a collision here would make one
        // worker's teardown destroy the other's fixtures. Guarded `@mkdir` for the
        // same reason — a collision must fail HERE with the path, not emit a raw
        // `mkdir(): File exists` warning into the loud parallel printer.
        $this->segmentDir = sys_get_temp_dir() . '/phlix_hls_ondemand_' . bin2hex(random_bytes(8));
        if (!@mkdir($this->segmentDir, 0755, true) && !is_dir($this->segmentDir)) {
            $this->fail(self::S486_LANE_SENTINEL . ': setUp FAILED — could not create ' . $this->segmentDir);
        }
    }

    /**
     * S486 — the race this replaces: `ensureSegment()` returns as soon as the
     * published fragment EXISTS, but the wrapper that wrote it
     * (`FfmpegRunner::startSegmentEncode`, fMP4 publish chain) deletes its
     * `.part-*` marker + `.part-*.m3u8` sibling AFTER that moment. The old
     * blind `rrmdir()` could scan the directory before the wrapper's trailing
     * `rm -f` and then `unlink()` names that no longer exist — the
     * `unlink(…seg-voriginal-00000.m4s.part-<hex>): No such file or directory`
     * pair CI printed. Serial printers hide the suppressed events; the parallel
     * gate's paraunit surfaces them, so `@` is not protection — only never
     * calling `unlink()` on a gone path is.
     *
     * Fix, in order: (1) poll the expected post-condition — every
     * `seg-*.part-*` marker under the job dirs is GONE, and the marker is the
     * last thing the wrapper's write phase touches, so its disappearance means
     * no detached writer can create or delete anything in here anymore;
     * (2) guarded recursive removal — existence re-checked per path, bounded
     * retries; (3) verification — a directory that survives fails loudly
     * naming the lane sentinel. No global warning suppression added anywhere.
     */
    protected function tearDown(): void
    {
        $survivors = [];

        if (isset($this->segmentDir) && $this->segmentDir !== '' && is_dir($this->segmentDir)) {
            $this->waitForDetachedWriterQuiescence();
            $this->removeDirectory($this->segmentDir, $survivors);
        }

        parent::tearDown();

        if ($survivors !== []) {
            $this->fail(
                self::S486_LANE_SENTINEL . ': cleanup FAILED — ' . implode('; ', $survivors)
                . ' (detached writer exceeded its ' . self::DETACHED_MARKER_TIMEOUT_SECONDS . 's '
                . 'quiescence budget or files could not be unlinked)'
            );
        }
    }

    /**
     * Poll until no `seg-*.part-*` marker remains in any job dir (the wrapper's
     * trailing `rm -f` consumes the marker as its final step, so absence means
     * the write phase is over), bounded by DETACHED_MARKER_TIMEOUT_SECONDS.
     * On timeout the guarded removal below still runs — the timeout is reported
     * only if something actually survives, because that is the only claim this
     * lane can make from what it observed.
     */
    private function waitForDetachedWriterQuiescence(): void
    {
        $deadline = hrtime(true) + (int) (self::DETACHED_MARKER_TIMEOUT_SECONDS * 1_000_000_000);

        while ($this->inFlightMarkerPaths() !== []) {
            if (hrtime(true) > $deadline) {
                return;
            }
            usleep(self::DETACHED_MARKER_POLL_INTERVAL_US);
        }
    }

    /**
     * @return list<string> every `.part-*` encode marker under the job dirs —
     *         covers the bare marker plus its `.i`/`.s0`/`.m3u8` siblings, all
     *         of which share the `seg-…part-…` stem and all of which the
     *         wrapper's chain removes.
     */
    private function inFlightMarkerPaths(): array
    {
        return glob("{$this->segmentDir}/*/seg-*.part-*") ?: [];
    }

    /**
     * Remove $dir recursively after re-checking each name; up to
     * REMOVE_DIR_ATTEMPTS passes so a sibling that appears between a scan and
     * its removal still gets one. A path still present after the last attempt
     * is appended to $survivors for the loud report.
     */
    private function removeDirectory(string $dir, array &$survivors): void
    {
        for ($attempt = 1; $attempt <= self::REMOVE_DIR_ATTEMPTS; $attempt++) {
            $this->removeTree($dir);
            if (!is_dir($dir)) {
                return;
            }
            usleep(self::DETACHED_MARKER_POLL_INTERVAL_US);
        }

        // Reached only when every attempt left the directory in place —
        // fall-through IS the surviving state, so this reports unconditionally.
        $leftover = array_values(array_filter(
            scandir($dir) ?: [],
            static fn (string $entry): bool => $entry !== '.' && $entry !== '..'
        ));
        $survivors[] = $dir . ' (entries left: '
            . ($leftover === [] ? 'none — directory itself unremovable' : implode(', ', $leftover))
            . ')';
    }

    private function removeTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeTree($path);
            } elseif (file_exists($path) || is_link($path)) {
                @unlink($path);
            }
            // neither → vanished between the scan and the check; nothing to unlink
        }
        if (is_dir($dir)) {
            @rmdir($dir);
        }
    }

    /**
     * ⚠ THE SHIPPED DEFAULT, END TO END, OVER REAL FFMPEG OUTPUT.
     *
     * No `transcoding.segment_format` override anywhere — this is what an
     * install gets. Since S60 that is fMP4, so the playlist carries an
     * `#EXT-X-MAP`, `ensureSegment()` publishes a real `init-v240p.m4s`
     * alongside the fragment, and the controller types both `video/mp4`.
     */
    public function testManagerPublishesVodPlaylistAndServesSegmentsOnDemand(): void
    {
        $this->runTheWholeChain(null, 'm4s', 'video/mp4');
    }

    /**
     * ⚠ THE ROLLBACK, DEMONSTRATED — not asserted.
     *
     * The documented rollback for S60 is
     * `PUT /api/v1/admin/settings {"transcoding.segment_format":"mpegts"}`, and
     * `EncodeSettings` reads that key at ENCODE time. So: same source, same
     * manager, same controller, that one key set — and real ffmpeg has to
     * produce `.ts` bytes that the real `HlsController` serves as `video/mp2t`,
     * with no `#EXT-X-MAP` anywhere and no `.m4s` on disk.
     *
     * This is the case that would have caught the defect S60 nearly shipped:
     * `EncodeSettings::segmentFormat()` mapped everything that was not `fmp4`
     * onto the default, so at the instant of the flip an explicit `mpegts`
     * resolved to `fmp4` and the rollback did nothing at all.
     */
    public function testTheMpegTsRollbackPublishesAndServesTheSameChain(): void
    {
        $this->runTheWholeChain(EncodeSettings::FORMAT_MPEGTS, 'ts', 'video/mp2t');
    }

    /**
     * @param string|null $segmentFormat `transcoding.segment_format` override, or
     *                                   null for "no override at all".
     * @param string      $ext           The segment extension this must produce.
     * @param string      $contentType   The type `HlsController` must serve it as.
     */
    private function runTheWholeChain(?string $segmentFormat, string $ext, string $contentType): void
    {
        $fmp4 = $ext === 'm4s';

        // An 8-second clip. At 2s segments the playlist has 4 entries (seg-00000..3).
        // A 640x480 source so the ABR ladder yields MULTIPLE transcoded rungs
        // (480p/360p/240p) in addition to the copy "original" — SV-4.6 excludes the
        // copy variant from the master's switchable set, so a single-rung source
        // would leave only one #EXT-X-STREAM-INF. The video bitrate is forced high
        // (~4 Mbps CBR) so it sits ABOVE every rung's canonical target: otherwise
        // AbrLadder's source-bitrate cap would collapse the 480p/360p/240p rungs
        // onto one shared BANDWIDTH and the monotonic-gradient guard would (rightly)
        // prune them to a single rung.
        $clip = "{$this->segmentDir}/in.mkv";
        $cmd = sprintf(
            '%s -y -hide_banner -loglevel error -f lavfi -i testsrc=duration=8:size=640x480:rate=24 '
            . '-f lavfi -i sine=frequency=440:duration=8 -c:v libx264 -pix_fmt yuv420p '
            . '-x264-params nal-hrd=cbr -b:v 4M -minrate 4M -maxrate 4M -bufsize 8M '
            . '-c:a aac -shortest %s 2>/dev/null',
            escapeshellarg('/usr/bin/ffmpeg'),
            escapeshellarg($clip)
        );
        exec($cmd, $o, $code);
        $this->assertSame(0, $code);

        $repo = $this->createMock(SettingsRepository::class);
        $repo->method('getEffective')->willReturnCallback(
            /** @return mixed */
            static fn (string $key) => $key === EncodeSettings::SEGMENT_FORMAT_KEY ? $segmentFormat : null
        );

        $manager = new TranscodeManager(
            $this->mockDb($clip),
            $this->ffmpeg,
            $this->segmentDir,
            null,
            2,
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

        // The premise, stated before anything is measured: the settings seam
        // really did resolve the container this arm claims to be exercising.
        $this->assertSame(
            $fmp4 ? EncodeSettings::FORMAT_FMP4 : EncodeSettings::FORMAT_MPEGTS,
            (new EncodeSettings($repo))->segmentFormat()
        );

        $job = $manager->ensureHlsJob('media-1', 'web');
        $this->assertFalse($job['reused']);
        // The playlist is the deliverable — the job is ready immediately.
        $this->assertSame('completed', $job['status']);
        $jobId = $job['job_id'];

        $streamer = new HlsStreamer($this->segmentDir, 'http://localhost:8096', new QualitySelector());
        $hls = new HlsController($streamer, $manager);
        $req = new Request();

        // Master → MULTIPLE switchable variants (a 640x480 H.264/AAC source → 480p /
        // 360p / 240p transcoded rungs), each pointing at its own media_v{id}.m3u8.
        // SV-4.6: the copy "original" is deliberately NOT advertised as a switchable
        // rung in the master (its segment boundaries can drift), so it does not
        // appear here — but its media playlist is still written (asserted below).
        $master = $hls->serveFile($req, ['job_id' => $jobId, 'file' => 'master.m3u8']);
        $this->assertSame(200, $master->statusCode);
        $this->assertSame('application/vnd.apple.mpegurl', $master->headers['Content-Type']);
        $this->assertGreaterThanOrEqual(2, substr_count($this->bodyOf($master), '#EXT-X-STREAM-INF:'));
        $this->assertSame(
            preg_match_all('/^(media_v[A-Za-z0-9]+\.m3u8)$/m', $this->bodyOf($master), $mm),
            substr_count($this->bodyOf($master), '#EXT-X-STREAM-INF:')
        );
        $this->assertContains('media_v240p.m3u8', $mm[1]);
        // SV-4.6: the copy "original" is excluded from the master's switchable set.
        $this->assertNotContains('media_voriginal.m3u8', $mm[1]);
        // …but its media playlist is still written to disk so "Original" is selectable.
        $this->assertFileExists("{$this->segmentDir}/{$jobId}/media_voriginal.m3u8");

        // The 240p variant's media playlist is a COMPLETE VOD list (all segments +
        // ENDLIST) up front, with per-variant seg-v240p-NNNNN.{ts,m4s} names.
        $media = $hls->serveFile($req, ['job_id' => $jobId, 'file' => 'media_v240p.m3u8']);
        $this->assertSame(200, $media->statusCode);
        $this->assertStringContainsString('#EXT-X-PLAYLIST-TYPE:VOD', $this->bodyOf($media));
        $this->assertStringContainsString('#EXT-X-ENDLIST', $this->bodyOf($media));
        // ~8s at 2s segments → 4 (or 5 if ffmpeg's real duration rounds up) entries.
        $this->assertGreaterThanOrEqual(
            4,
            preg_match_all('/^seg-v240p-\d+\.' . $ext . '$/m', $this->bodyOf($media))
        );
        // The container is visible in the playlist itself, in BOTH directions —
        // an fMP4 presentation is unplayable without its EXT-X-MAP, and an
        // MPEG-TS one must never grow one.
        if ($fmp4) {
            $this->assertStringContainsString(
                '#EXT-X-MAP:URI="init-v240p.m4s"',
                $this->bodyOf($media)
            );
            $this->assertStringContainsString('#EXT-X-VERSION:7', $this->bodyOf($media));
            $this->assertStringNotContainsString('.ts', $this->bodyOf($media));
        } else {
            $this->assertStringNotContainsString('#EXT-X-MAP', $this->bodyOf($media));
            $this->assertStringContainsString('#EXT-X-VERSION:3', $this->bodyOf($media));
            $this->assertStringNotContainsString('.m4s', $this->bodyOf($media));
        }

        // On-demand: the FIRST 240p segment is transcoded when requested.
        $seg0 = $manager->ensureSegment($jobId, '240p', 0);
        $this->assertNotNull($seg0);
        $this->assertSame("{$this->segmentDir}/{$jobId}/seg-v240p-00000.{$ext}", $seg0);
        $this->assertGreaterThan(0, (int) filesize($seg0));

        // The fMP4 init is published by the index-0 encode and is what hls.js
        // fetches FIRST. It is also the file that does not exist at all on the
        // MPEG-TS arm, which is why it is asserted in both directions.
        if ($fmp4) {
            $this->assertFileExists("{$this->segmentDir}/{$jobId}/init-v240p.m4s");
            $this->assertGreaterThan(
                0,
                (int) filesize("{$this->segmentDir}/{$jobId}/init-v240p.m4s")
            );
        } else {
            $this->assertFileDoesNotExist("{$this->segmentDir}/{$jobId}/init-v240p.m4s");
            $this->assertSame([], glob("{$this->segmentDir}/{$jobId}/*.m4s") ?: []);
        }

        // Seek-anywhere: a LATER 240p segment is produced with NO earlier segment
        // encoded first — this is what the old linear encode could not do.
        $this->assertFileDoesNotExist("{$this->segmentDir}/{$jobId}/seg-v240p-00003.{$ext}");
        $seg3 = $manager->ensureSegment($jobId, '240p', 3);
        $this->assertNotNull($seg3);
        $this->assertSame("{$this->segmentDir}/{$jobId}/seg-v240p-00003.{$ext}", $seg3);
        $this->assertGreaterThan(0, (int) filesize($seg3));

        // The copy "original" passthrough (H.264 + AAC source) produces its own
        // seg-voriginal-NNNNN.{ts,m4s} via -c copy.
        $origSeg = $manager->ensureSegment($jobId, 'original', 0);
        $this->assertNotNull($origSeg);
        $this->assertSame("{$this->segmentDir}/{$jobId}/seg-voriginal-00000.{$ext}", $origSeg);
        $this->assertGreaterThan(0, (int) filesize($origSeg));

        // A produced segment serves through HlsController's static path with the
        // right content-type.
        $seg0Served = $hls->serveFile($req, ['job_id' => $jobId, 'file' => "seg-v240p-00000.{$ext}"]);
        $this->assertSame(200, $seg0Served->statusCode);
        $this->assertSame($contentType, $seg0Served->headers['Content-Type']);
        $this->assertGreaterThan(0, strlen($this->bodyOf($seg0Served)));

        // …and, on the fMP4 arm, so does the init — through the SAME route, which
        // is the request hls.js makes before any fragment (S310).
        if ($fmp4) {
            $initServed = $hls->serveFile($req, ['job_id' => $jobId, 'file' => 'init-v240p.m4s']);
            $this->assertSame(200, $initServed->statusCode);
            $this->assertSame('video/mp4', $initServed->headers['Content-Type']);
            $this->assertGreaterThan(0, strlen($this->bodyOf($initServed)));
        }

        // An out-of-range segment is null (→ 404), not an endless wait.
        $this->assertNull($manager->ensureSegment($jobId, '240p', 99));
        // An unknown variant is null, too.
        $this->assertNull($manager->ensureSegment($jobId, '4320p', 0));

        // The denominators, on STDERR (phpunit.xml is strict about php://output).
        fwrite(STDERR, sprintf(
            "\n[S60] %s chain: setting=%s → %d %s segments + %d init on disk, "
            . "controller typed seg as %s\n",
            $fmp4 ? 'DEFAULT (fmp4)' : 'ROLLBACK (mpegts)',
            var_export($segmentFormat, true),
            count(glob("{$this->segmentDir}/{$jobId}/seg-*.{$ext}") ?: []),
            $ext,
            count(glob("{$this->segmentDir}/{$jobId}/init-*.m4s") ?: []),
            (string) $seg0Served->headers['Content-Type']
        ));
    }

    /**
     * Resolves the served bytes of a response. Playlists and segments now stream via
     * {@see \Phlix\Server\Http\Response::withFile()} rather than buffering into
     * `->body`, so read the file window from disk.
     */
    private function bodyOf(\Phlix\Server\Http\Response $res): string
    {
        if ($res->filePath === null) {
            return $res->body;
        }
        $bytes = $res->fileLength > 0
            ? file_get_contents($res->filePath, false, null, $res->fileOffset, $res->fileLength)
            : file_get_contents($res->filePath, false, null, $res->fileOffset);
        return $bytes === false ? '' : $bytes;
    }

    private function mockDb(string $clipPath): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, ?array $params = null) use ($clipPath) {
                if (str_contains($sql, 'key_hash = ?') && str_contains($sql, 'IN (')) {
                    return [];
                }
                if (str_contains($sql, 'COUNT(*)')) {
                    return [['c' => 0]];
                }
                if (str_contains($sql, 'FROM media_items')) {
                    return [['id' => 'media-1', 'path' => $clipPath]];
                }
                if (str_contains($sql, 'INSERT INTO transcode_jobs')) {
                    // Record the job so a later SELECT can echo the fields ensureSegment()
                    // needs (hls_dir, input_path, duration, segment_seconds, segment_params,
                    // and the A5 multi-variant `variants` ladder at index 14).
                    $p = $params ?? [];
                    $this->insertedJob = [
                        'id' => (string) ($p[0] ?? ''),
                        'input_path' => (string) ($p[2] ?? ''),
                        'hls_dir' => (string) ($p[4] ?? ''),
                        'status' => 'completed',
                        'duration_seconds' => (int) ($p[11] ?? 0),
                        'segment_seconds' => (int) ($p[12] ?? 0),
                        'segment_params' => is_string($p[13] ?? null) ? $p[13] : null,
                        'variants' => is_string($p[14] ?? null) ? $p[14] : null,
                    ];
                    return [];
                }
                if (str_contains($sql, 'transcode_jobs WHERE id = ?')) {
                    return $this->insertedJob === [] ? [] : [$this->insertedJob];
                }
                return [];
            }
        );
        return $db;
    }
}
