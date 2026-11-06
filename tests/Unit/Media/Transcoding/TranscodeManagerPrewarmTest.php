<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Transcoding;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Media\Transcoding\SegmentBusyException;
use Phlix\Media\Transcoding\TranscodeManager;
use Phlix\Server\Http\RequestContext;
use ReflectionMethod;
use ReflectionProperty;
use Workerman\MySQL\Connection;

/**
 * C6 (playback-lag) — a freshly created HLS job pre-generates its first window.
 *
 * FIELD-hub-phlix proved the cold-playback mechanics: on a cache-wiped boot
 * (PrivateTmp + the temp-dir segment cache) every 6 s segment is built INLINE
 * inside the reader's request — 3.0–6.6 s per segment — because the producer
 * only ever ran at READ time. The cure: when `ensureHlsJob()` commits a new
 * job row, immediately kick the EXISTING per-segment producer (the same
 * `produceSegment()` reservation/cap/dedup machinery) for the top-rung init
 * segment + first few fragments, in LAUNCH-ONLY mode (`waitForEncode=false`):
 * fire the detached encode and return — nobody waits, and the reader's first
 * request finds either the published file (ready path) or an honest in-flight
 * state (wait-fast path), never a cold inline build it must pay for itself.
 *
 * The laws pinned here are exactly the constraints the cure must not violate:
 *
 *  1. creation launches the first window on the TOP rung (`original`, the id
 *     the FIELD traces show the client requesting), names the real segment
 *     filenames, and carries the fMP4 `init_file` so the atomic publish chain
 *     emits `init-voriginal.m4s` BEFORE the first fragment;
 *  2. the pre-gen is capped by the SAME `maxConcurrentSegments` ceiling — when
 *     the box is already at capacity, creation still completes (busy-safe);
 *  3. the inline read path is UNTOUCHED when no pre-gen ran: `ensureSegment()`
 *     still launches-and-waits exactly as it always did (this is not a
 *     replacement of the on-demand producer, only a head start);
 *  4. a reader arriving while the pre-gen produced the file gets the cache hit
 *     WITHOUT launching a duplicate (no double-build racing the inline path);
 *  5. the window is bounded: out-of-range indices end the loop, and the knob
 *     (0) disables pre-generation entirely without breaking anything;
 *  6. the launch-only leg GIVES UP (returns null — the loop's stop signal) when
 *     a sibling encode already holds this exact final: no redundant duplicate
 *     launch, and the sibling's reservation is left untouched;
 *  7. launch-only encodes take NO waiter slot and join NO relay cancel group —
 *     the head start exists precisely to outlive the create request;
 *  8. a THROWN launch clears its own reservation (mirroring the inline
 *     finally's compare-and-clear), so a segment is never stranded as a
 *     phantom in-flight that every later drive gives up on;
 *  9. wave A′ (playback-lag, 2026-11-06): prewarm-initiated encodes occupy at
 *     MOST floor(cap/2) of the global ceiling — prewarm temps are tagged
 *     `{final}.part-p<8hex>` (a `p` is no hex digit, so the tagged family and
 *     the normal family are disjoint glob classes), the total cap sums both
 *     families, and an over-budget prewarm segment SKIPS SILENTLY (null → the
 *     window loop stops) instead of throwing — on-demand production keeps the
 *     FULL ceiling and the observed 7.9 s inline seg0 stall behind a stacked
 *     prewarm window cannot recur;
 *    10. a tagged temp still counts against the FULL global ceiling (an inline
 *     reader on a saturated tree is still 503 — A′ never weakens the cap);
 *    11. the dedup snapshot strips the prewarm tag — an in-flight prewarm
 *     final is seen as in-flight and never re-launched by a later drive.
 *
 * Laws 6-8 are the executable teeth for the review register
 * (.reviews/2026-11-05/REVIEW-playback-program.md, C6 minors M1/M2): the
 * reviewer's own mutation probes deleted/flipped each corresponding leg and
 * the full 661-test Transcoding directory stayed green — they were un-pinned.
 * They drive the PRIVATE launch-only seam (`ensureSegmentInternal(..., false)`
 * — reflection is the honest route; the public `ensureSegment()` always waits)
 * and observe the cancel-registry args of `FfmpegRunner::startSegmentEncode`.
 *
 * Every capture happens through the `FfmpegRunner` mock — no real ffmpeg, no
 * sockets, no sleeps: `startSegmentEncode` is stubbed to publish the final
 * file synchronously so launch-only vs wait semantics are observable purely
 * through call counts and returned paths.
 */
final class TranscodeManagerPrewarmTest extends TestCase
{
    private string $segmentDir;
    private string $inputPath;

    protected function setUp(): void
    {
        $this->segmentDir = sys_get_temp_dir() . '/phlix_pw_' . uniqid('', true);
        mkdir($this->segmentDir, 0755, true);
        $this->inputPath = $this->segmentDir . '/in.mkv';
        file_put_contents($this->inputPath, 'x');
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->segmentDir);
    }

    /**
     * Law 1: creating the job launches the first window (3 segments by default)
     * on the top rung, with the exact filenames a reader will request.
     */
    public function test_job_creation_launches_the_first_window_on_the_top_rung(): void
    {
        $launches = [];
        $manager = $this->manager($this->jobDb(), $this->recordingRunner($launches), 3, null);

        $result = $manager->ensureHlsJob('media-1', 'web');

        $this->assertFalse($result['reused']);
        $this->assertCount(3, $launches, 'creation must pre-generate exactly the 3-segment window');
        foreach ([0, 1, 2] as $i) {
            $this->assertSame(
                sprintf('seg-voriginal-%05d.m4s', $i),
                basename($launches[$i]['out']),
                'window index ' . $i . ' must publish the top-rung segment name'
            );
            $this->assertSame(
                'init-voriginal.m4s',
                basename((string) $launches[$i]['init']),
                'the pre-gen encode must carry the init file so the publish chain emits it first'
            );
        }
        $this->assertSame(0.0, $launches[0]['start']);
        $this->assertSame(12.0, $launches[2]['start'], 'window segments encode their own offsets');
    }

    /**
     * Law 5a: the knob disables pre-generation outright — creation must behave
     * exactly like today (write playlists, commit the row, launch nothing).
     */
    public function test_prewarm_zero_disables_the_head_start(): void
    {
        $launches = [];
        $manager = $this->manager($this->jobDb(), $this->recordingRunner($launches), 0, null);

        $result = $manager->ensureHlsJob('media-1', 'web');

        $this->assertFalse($result['reused']);
        $this->assertSame([], $launches);
    }

    /**
     * Law 4: the reader's first request hits the PRE-GENERATED file without
     * launching a second encode — the whole point of the head start.
     */
    public function test_first_read_is_served_from_the_prewarm_without_a_duplicate_build(): void
    {
        $launches = [];
        $db = $this->jobDb();
        $manager = $this->manager($db, $this->recordingRunner($launches), 3, null);

        $result = $manager->ensureHlsJob('media-1', 'web');
        $jobId = (string) $result['job_id'];

        $served = $manager->ensureSegment($jobId, 'original', 0);

        $this->assertSame($launches[0]['out'], $served, 'the reader must get the pre-generated file');
        $this->assertCount(3, $launches, 'a cache hit must not launch anything');
    }

    /**
     * Law 3: with pre-generation disabled (the busy/late/flag-off world), the
     * inline on-demand path still launches and waits EXACTLY as it always did.
     * The cure is additive; it never replaces the read-time producer.
     */
    public function test_inline_path_is_intact_when_no_prewarm_ran(): void
    {
        $launches = [];
        $db = $this->jobDb();
        $manager = $this->manager($db, $this->recordingRunner($launches), 0, null);

        $result = $manager->ensureHlsJob('media-1', 'web');
        $jobId = (string) $result['job_id'];

        $served = $manager->ensureSegment($jobId, 'original', 4);

        $this->assertIsString($served);
        $this->assertSame('seg-voriginal-00004.m4s', basename($served));
        $this->assertCount(1, $launches, 'the reader itself paid the inline build, once');
        $this->assertSame($served, $launches[0]['out']);
    }

    /**
     * Law 2: the pre-gen obeys the shared encode cap. When the box is already
     * at `maxConcurrentSegments`, the head start must STOP EARLY and job
     * creation must still complete — a busy server never fails a play request
     * because the optional background window could not be launched.
     */
    public function test_prewarm_is_busy_safe_under_the_encode_cap(): void
    {
        // A foreign in-flight encode occupying the single global slot. The cap
        // check globs segment_dir/*/seg-*.part-???????? live.
        $rival = $this->segmentDir . '/rival';
        mkdir($rival, 0755, true);
        file_put_contents($rival . '/seg-x.part-12345678', '');

        $launches = [];
        $manager = $this->manager($this->jobDb(), $this->recordingRunner($launches), 3, 1);

        $result = $manager->ensureHlsJob('media-1', 'web');

        $this->assertFalse($result['reused'], 'creation must complete even when the pre-gen is capped out');
        $this->assertSame([], $launches, 'no pre-gen encode may exceed the cap');
    }

    /**
     * Law 5b: the window is bounded by the timeline — with only 5 segments of
     * content (25 s / 6 s) and a knob of 7, pre-generation stops at the last
     * real segment instead of throwing or looping past the end.
     */
    public function test_prewarm_window_stops_at_the_end_of_the_timeline(): void
    {
        $launches = [];
        $manager = $this->manager($this->jobDb(), $this->recordingRunner($launches), 7, null);

        $manager->ensureHlsJob('media-1', 'web');

        $this->assertCount(5, $launches, 'duration bounds the window: ceil(25/6) = 5');
        $this->assertSame('seg-voriginal-00004.m4s', basename($launches[4]['out']));
    }

    /**
     * Law 6 (register M1): the launch-only leg's give-up branch
     * (`if (!$reserved) return null;` inside `if (!$waitForEncode)` —
     * TranscodeManager.php:1466-1468) must refuse to double-build a final a
     * sibling encode already holds. The reviewer deleted the branch and the
     * whole 661-test directory stayed green: this is the missing tooth.
     *
     * The state exercised is exactly what C6 leaves behind ON PURPOSE (the
     * launch leg RETAINS reservation + registry entry, :1458-1461): the
     * synchronously published file of window segment 1 is unlinked (encode in
     * flight, not yet published) and a sibling `.part-12345678` temp
     * materialises the claim — the very name shape
     * `reconcileInFlightSegments()` maps back to the final, so the pin stays
     * true whether or not the ≤1 s reconcile throttle lets the next glob run.
     * Re-driving the exact private seam the prewarm loop uses must now return
     * null (its break signal, :1673) without launching a fourth encode.
     */
    public function test_launch_only_leg_gives_up_when_a_sibling_already_holds_the_segment(): void
    {
        $launches = [];
        $manager = $this->manager($this->jobDb(), $this->recordingRunner($launches), 3, null);

        $result = $manager->ensureHlsJob('media-1', 'web');
        $jobId = (string) $result['job_id'];
        $this->assertCount(3, $launches);

        $final = $launches[1]['out'];
        $this->assertNotSame($launches[0]['out'], $final);
        unlink($final);
        file_put_contents($final . '.part-12345678', '');

        $reservation = $this->segmentReservation($manager, $final);
        $this->assertNotNull($reservation, 'C6 law: the launch leg keeps its registry entry for the in-flight final');

        $again = $this->driveLaunchOnly($manager, $jobId, 'original', 1);

        $this->assertNull($again, 'launch-only must give up when the reservation is not its own to take');
        $this->assertCount(3, $launches, 'giving up must not launch a duplicate encode of the in-flight segment');
        $this->assertFalse(is_file($final), 'the give-up path must not publish anything');
        $this->assertSame(
            $reservation,
            $this->segmentReservation($manager, $final),
            'the give-up path must not clobber the sibling reservation it did not own'
        );
    }

    /**
     * Law 7 (register M2): the C6 docblock declares the launch-only leg "takes
     * NO waiter slot" and "registers the encode with NO relay cancel group"
     * (TranscodeManager.php:1452-1457) — prewarm encodes must OUTLIVE the
     * create request. That law was unobservable at the call site: flipping
     * `waitForEncode` false → true in the prewarm loop (:1672) survived the
     * entire directory (the reviewer's probe), because the synchronous-publish
     * mock makes wait vs no-wait behave identically from the outside.
     *
     * The pin arms the request's relay cancel group (the exact state
     * RelayConsumer sets for a relay-served create request) and captures the
     * full seven-argument launch contract: the inline leg would thread
     * `RequestContext::getRelayCancelGroup()` into arg 7 and had already run
     * `incrementSegmentWaiter()` before launching (:1496, :1525-1533). Prewarm
     * must pass a literal null cancel group, carry the final as cancel key
     * (arg 6, :1472-1480), and leave every waiter counter at zero — sampled
     * INSIDE the launch and re-checked after the window completes.
     */
    public function test_prewarm_launches_take_no_waiter_slot_and_no_relay_cancel_group(): void
    {
        $launches = [];
        /** @var TranscodeManager|null $managerRef wire for the in-launch waiter probe */
        $managerRef = null;
        $manager = $this->manager($this->jobDb(), $this->capturingRunner($launches, $managerRef), 3, null);
        $managerRef = $manager;

        RequestContext::setRelayCancelGroup('relay-chan-77');
        try {
            $result = $manager->ensureHlsJob('media-1', 'web');
        } finally {
            RequestContext::clearRelayCancelGroup();
        }

        $this->assertFalse($result['reused']);
        $this->assertCount(3, $launches);
        foreach ($launches as $i => $launch) {
            $this->assertNull(
                $launch['cancelGroup'],
                'prewarm encode ' . $i . ' must not join the create request cancel group — the disconnect-kill'
                    . " for the create request must never reap the head start built to outlive it"
            );
            $this->assertSame($launch['out'], $launch['cancelKey'], 'the registry cancel key is the final path');
            $this->assertSame(
                0,
                $launch['waitersAtLaunch'],
                'launch-only takes NO waiter slot — 1 here means the leg flipped to waitForEncode=true'
            );
        }
        foreach ($launches as $launch) {
            $this->assertSame(0, $manager->waiterCount($launch['out']), 'waiter counters stay at zero — no leak');
        }
    }

    /**
     * Law 8 (register M2 sub-observation): the launch-only catch clears its own
     * reservation via `clearReservationIfMine()` when the detached spawn THROWS
     * (TranscodeManager.php:1481-1485) — the mirror of the inline finally's
     * compare-and-clear. A leaked reservation makes the segment look
     * permanently in flight: law 6 then makes EVERY later launch-only drive
     * give up on a file nobody is actually encoding. The register noted the
     * catch had no execution pin at all.
     *
     * Seam: a runner double whose first attempt throws (creation still
     * completes — the prewarm window catch aborts the loop, law 2's family).
     * The re-drive of the same index must then SUCCEED. Honest bounds: the
     * stale-grace is 5 s (SEGMENT_INFLIGHT_STALE_GRACE_MS) and reconcile is
     * 1 s-throttled, so the mutation stays red for any re-drive within five
     * seconds of creation — the test itself never approaches a second.
     */
    public function test_failed_launch_clears_its_reservation_so_the_next_drive_can_build(): void
    {
        $launches = [];
        /** @var TranscodeManager|null $managerRef */
        $managerRef = null;
        $manager = $this->manager(
            $this->jobDb(),
            $this->capturingRunner($launches, $managerRef, true),
            3,
            null
        );
        $managerRef = $manager;

        $result = $manager->ensureHlsJob('media-1', 'web');
        $jobId = (string) $result['job_id'];

        $this->assertFalse($result['reused'], 'a throwing prewarm launch must never fail job creation');
        $this->assertCount(0, $launches, 'the failed attempt threw before any encode was recorded');

        $again = $this->driveLaunchOnly($manager, $jobId, 'original', 0);

        $this->assertIsString(
            $again,
            'the launch-failure catch must clear its reservation — a stale entry strands the segment'
        );
        $this->assertSame($launches[0]['out'], $again);
        $this->assertCount(1, $launches, 'the re-drive re-reserved and launched exactly once');
        $this->assertNotNull(
            $this->segmentReservation($manager, $again),
            'the successful re-drive holds a fresh reservation, retained under the C6 law'
        );
    }

    /**
     * Law 9 (wave A′): the observed FIELD-hub-phlix stall — a 3-segment prewarm
     * window stacked on top of everything the reader needed, seg0 answering
     * 7.9 s inline on a 4-slot box. Prewarm launches may occupy at most
     * floor(cap/2): cap 4 → budget 2, so the third window segment skips
     * SILENTLY (the loop's own null-stop signal, never an exception) while job
     * creation still completes and the reader keeps the FULL ceiling.
     *
     * The double creates the tagged `.part-p<8hex>` temp per launch WITHOUT
     * publishing — exactly the seconds-long mid-encode state the real detached
     * chain leaves on disk while the window loop drives the next index — so
     * the budget reads the same live glob production reads.
     */
    public function test_prewarm_budget_is_half_the_ceiling_and_skips_silently(): void
    {
        $launches = [];
        $manager = $this->manager($this->jobDb(), $this->holdingRunner($launches), 3, 4);

        $result = $manager->ensureHlsJob('media-1', 'web');

        $this->assertFalse($result['reused'], 'budget exhaustion must never fail job creation');
        $this->assertCount(2, $launches, 'cap 4 → prewarm may hold at most floor(4/2) = 2 slots');
        $this->assertSame('seg-voriginal-00000.m4s', basename($launches[0]['out']));
        $this->assertSame('seg-voriginal-00001.m4s', basename($launches[1]['out']));
    }

    /**
     * Law 9 (budget math, continued): cap 2 → budget 1. A two-slot box gives
     * the head start exactly ONE slot — the floor division must not round up.
     */
    public function test_prewarm_budget_rounds_down_at_the_smallest_useful_ceiling(): void
    {
        $launches = [];
        $manager = $this->manager($this->jobDb(), $this->holdingRunner($launches), 3, 2);

        $manager->ensureHlsJob('media-1', 'web');

        $this->assertCount(1, $launches, 'cap 2 → budget 1, never 2');
    }

    /**
     * Law 9 (budget math, continued): cap 8 → budget 4. When the window fits
     * under the budget the head start is FULLY taken — A′ is a ceiling on
     * prewarm, never a reduction of it.
     */
    public function test_prewarm_window_is_untouched_while_it_fits_the_budget(): void
    {
        $launches = [];
        $manager = $this->manager($this->jobDb(), $this->holdingRunner($launches), 3, 8);

        $manager->ensureHlsJob('media-1', 'web');

        $this->assertCount(3, $launches, 'cap 8 → budget 4 > window 3 → every segment launches');
    }

    /**
     * Law 9 (budget math, terminal arm): cap 1 → `intdiv(1, 2) = 0` — the head
     * start may occupy ZERO slots and every slot belongs to demand, so the
     * FIRST window index already reads `count(0) >= budget(0)` and skips
     * silently on an entirely empty tree (the docblock law at
     * TranscodeManager.php prewarmInFlightBudget: "A cap=1 box budgets 0 —
     * every slot belongs to demand; the head start stands down entirely").
     * Job creation must still complete, no launch and no stranded temps may
     * happen, AND the reader's own demand path must still get the FULL single
     * slot — the stand-down is prewarm-only. The register's mutation probe
     * `intdiv(cap, 2) → max(1, intdiv(cap, 2))` (giving even a cap-1 box a
     * head-start slot) reddens the empty-launches pin below.
     */
    public function test_prewarm_stands_down_entirely_on_a_single_slot_box(): void
    {
        $launches = [];
        $manager = $this->manager($this->jobDb(), $this->recordingRunner($launches), 3, 1);

        $result = $manager->ensureHlsJob('media-1', 'web');
        $jobId = (string) $result['job_id'];

        $this->assertFalse($result['reused'], 'the stand-down must never fail job creation');
        $this->assertSame([], $launches, 'cap 1 → budget 0 → the head start launches nothing');
        $this->assertSame(
            [],
            glob($this->segmentDir . '/*/seg-*.part-*') ?: [],
            'a stood-down window must leave no in-flight temps behind'
        );

        $served = $manager->ensureSegment($jobId, 'original', 0);

        $this->assertSame('seg-voriginal-00000.m4s', basename($served));
        $this->assertCount(1, $launches, 'the demand path keeps the FULL single-slot ceiling');
    }

    /**
     * Law 9 (silent-skip tooth): the over-budget refusal rolls its reservation
     * back BEFORE returning — a stranded reservation would make the segment
     * look in flight forever (law-8's disease on the skip path). Re-driving
     * the skipped index answers null again, launches nothing, and holds no
     * reservation for that final.
     */
    public function test_over_budget_prewarm_leaves_no_stranded_reservation(): void
    {
        $launches = [];
        $manager = $this->manager($this->jobDb(), $this->holdingRunner($launches), 3, 4);
        $result = $manager->ensureHlsJob('media-1', 'web');
        $jobId = (string) $result['job_id'];
        $this->assertCount(2, $launches);

        $skippedFinal = dirname($launches[0]['out']) . '/seg-voriginal-00002.m4s';
        $again = $this->driveLaunchOnly($manager, $jobId, 'original', 2);

        $this->assertNull($again, 'the budget skip is a silent give-up, not a launch');
        $this->assertCount(2, $launches, 'the re-drive must not sneak a third launch past the budget');
        $this->assertNull(
            $this->segmentReservation($manager, $skippedFinal),
            'a skipped prewarm segment must not strand a reservation'
        );
    }

    /**
     * Law 10: A′ never weakens the FULL global ceiling — tagged temps are
     * segment encodes too. Four foreign tagged temps saturate cap 4 and an
     * inline reader still fast-fails with the 503 backpressure, so the
     * prewarm share can never be used to smuggle the fleet past the cap.
     */
    public function test_tagged_prewarm_temps_count_against_the_full_global_ceiling(): void
    {
        $launches = [];
        $manager = $this->manager($this->jobDb(), $this->recordingRunner($launches), 0, 4);
        $result = $manager->ensureHlsJob('media-1', 'web');
        $jobId = (string) $result['job_id'];

        $dir = $this->segmentDir . '/' . $jobId;
        for ($i = 0; $i < 4; $i++) {
            file_put_contents(sprintf($dir . '/seg-voriginal-%05d.m4s.part-p%08x', $i, 0xa0 + $i), '');
        }

        $this->expectException(SegmentBusyException::class);
        $manager->ensureSegment($jobId, 'original', 4);
    }

    /**
     * Law 11: the dedup snapshot maps a TAGGED temp back to its final
     * (`.part-p?[0-9a-f]+` strip). A sibling worker's in-flight prewarm is
     * therefore visible to the next drive for that exact segment: give up,
     * no duplicate launch. Delete the `p?` from the regex and the snapshot
     * misses the final — the launch-only path would re-reserve, launch, and
     * this pin goes red on both the null and the count.
     */
    public function test_dedup_snapshot_strips_the_prewarm_tag(): void
    {
        $launches = [];
        $manager = $this->manager($this->jobDb(), $this->holdingRunner($launches), 0, null);
        $result = $manager->ensureHlsJob('media-1', 'web');
        $jobId = (string) $result['job_id'];
        $this->assertSame([], $launches);

        $final = $this->segmentDir . '/' . $jobId . '/seg-voriginal-00003.m4s';
        file_put_contents($final . '.part-pffffffff', '');

        $again = $this->driveLaunchOnly($manager, $jobId, 'original', 3);

        $this->assertNull($again, 'a tagged in-flight final must dedup like a plain one');
        $this->assertCount(0, $launches, 'the strip law must not re-launch the sibling\'s encode');
    }

    // ─────────────────────────────────────────────────────────────────
    // harness
    // ─────────────────────────────────────────────────────────────────

    /**
     * A db double that captures the transcode_jobs INSERT and answers the
     * job-row read (`FROM transcode_jobs WHERE id = ?`) with a row derived from
     * exactly those params — the memoized `jobRowEntry` path in the manager then
     * sees the same job the creation wrote. Launch recording lives in the
     * ffmpeg double ({@see recordingRunner()}), not here.
     */
    private function jobDb(): Connection
    {
        $db = $this->createMock(Connection::class);
        $inserted = null;
        $db->method('query')->willReturnCallback(
            /** @param list<mixed>|null $params */
            /** @return list<array<string, mixed>> */
            function (string $sql, ?array $params = null) use (&$inserted): array {
                if (str_contains($sql, 'INSERT INTO transcode_jobs')) {
                    $inserted = $params;
                    return [];
                }
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
                    return [['path' => $this->inputPath]];
                }
                if (str_contains($sql, 'transcode_jobs WHERE id = ?')) {
                    if (!is_array($inserted)) {
                        return [];
                    }
                    // Placeholder map of the creation INSERT (positional ?):
                    // 0 id, 1 media_item_id, 2 input_path, 3 output_path,
                    // 4 hls_dir, 5 profile, 6 key_hash, 7 width, 8 height,
                    // 9 bandwidth, 10 subtitle_tracks, 11 duration_seconds,
                    // 12 segment_seconds, 13 segment_params, 14 variants.
                    return [[
                        'id' => $inserted[0],
                        'media_item_id' => $inserted[1],
                        'input_path' => $inserted[2],
                        'output_path' => $inserted[3],
                        'hls_dir' => $inserted[4],
                        'status' => 'completed',
                        'progress' => 100,
                        'duration_seconds' => $inserted[11],
                        'segment_seconds' => $inserted[12],
                        'segment_params' => $inserted[13],
                        'variants' => $inserted[14],
                    ]];
                }
                return [];
            }
        );

        return $db;
    }

    /**
     * Runner mock: `startSegmentEncode` publishes the final file synchronously
     * (the detached real chain takes seconds; the test needs only the observable
     * launch) and records what it was asked to build.
     *
     * @param list<array{out:string,start:float,init:?string}> $launches
     */
    private function recordingRunner(array &$launches): FfmpegRunner
    {
        $ff = $this->createMock(FfmpegRunner::class);
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
        $ff->method('startSegmentEncode')->willReturnCallback(
            /** @param array<string, mixed> $params */
            static function (
                string $inputPath,
                string $outFile,
                float $start,
                float $duration,
                array $params
            ) use (&$launches): int {
                $launches[] = [
                    'out' => $outFile,
                    'start' => $start,
                    'init' => is_string($params['init_file'] ?? null) ? $params['init_file'] : null,
                ];
                file_put_contents($outFile, 'encoded');
                return 4242;
            }
        );

        return $ff;
    }

    private function manager(Connection $db, FfmpegRunner $ff, int $prewarm, ?int $maxConcurrent): TranscodeManager
    {
        return new TranscodeManager(
            $db,
            $ff,
            $this->segmentDir,
            null,
            6,
            null,
            null,
            null,
            $maxConcurrent,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            $prewarm
        );
    }

    /**
     * Runner double capturing the FULL seven-argument launch contract of
     * `FfmpegRunner::startSegmentEncode` — the two cancel-registry args plus a
     * waiter-slot sample taken INSIDE the launch, the only place launch-only
     * vs inline is observable against a synchronous-publish double (mirrors
     * the established in-launch probe, TranscodeManagerTest.php:3352-3393).
     * `$throwOnFirstAttempt` models a failing detached spawn for the
     * reservation-leak pin. Probe/colour stubs match {@see recordingRunner()}.
     *
     * @param list<array{out:string,start:float,cancelKey:?string,cancelGroup:?string,waitersAtLaunch:int}> $launches
     */
    private function capturingRunner(
        array &$launches,
        ?TranscodeManager &$managerRef,
        bool $throwOnFirstAttempt = false
    ): FfmpegRunner {
        $attempts = 0;
        $ff = $this->createMock(FfmpegRunner::class);
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
        $ff->method('startSegmentEncode')->willReturnCallback(
            /**
             * @param array<string, mixed> $params
             */
            function (
                string $inputPath,
                string $outFile,
                float $start,
                float $duration,
                array $params,
                ?string $cancelKey,
                ?string $cancelGroup
            ) use (
                &$launches,
                &$managerRef,
                $throwOnFirstAttempt,
                &$attempts
            ): int {
                $attempts++;
                if ($throwOnFirstAttempt && $attempts === 1) {
                    throw new \RuntimeException('SIMULATED prewarm launch failure (detached spawn refused)');
                }
                assert($managerRef instanceof TranscodeManager);
                $launches[] = [
                    'out' => $outFile,
                    'start' => $start,
                    'cancelKey' => $cancelKey,
                    'cancelGroup' => $cancelGroup,
                    'waitersAtLaunch' => $managerRef->waiterCount($outFile),
                ];
                file_put_contents($outFile, 'encoded');

                return 4242;
            }
        );

        return $ff;
    }

    /**
     * Drive the private launch-only seam the prewarm loop itself uses:
     * `ensureSegmentInternal($jobId, $variant, $index, null, false)` (call
     * site TranscodeManager.php:1672). Reflection is the honest route — the
     * public `ensureSegment()` always runs the waiting leg.
     */
    private function driveLaunchOnly(TranscodeManager $manager, string $jobId, ?string $variant, int $index): mixed
    {
        $method = new ReflectionMethod(TranscodeManager::class, 'ensureSegmentInternal');
        $method->setAccessible(true);

        return $method->invokeArgs($manager, [$jobId, $variant, $index, null, false]);
    }

    /**
     * Read one worker-local reservation entry ({@see
     * TranscodeManager::$segmentEncodesInFlight}) — the sibling state laws 6/8
     * turn on. Null means nothing holds this final: free to reserve.
     *
     * @return array{at: int, gen: int}|null
     */
    private function segmentReservation(TranscodeManager $manager, string $final): ?array
    {
        $property = new ReflectionProperty(TranscodeManager::class, 'segmentEncodesInFlight');
        $property->setAccessible(true);
        /** @var array<string, array{at: int, gen: int}> $map */
        $map = $property->getValue($manager);

        return $map[$final] ?? null;
    }

    /**
     * Runner double for the A′ laws: like {@see recordingRunner()} but the
     * launch simulates a STILL-RUNNING encode — it materialises exactly the
     * temp the real `FfmpegRunner::startSegmentEncode` writes for a prewarm
     * launch (`{out}.part-p<8hex>`, 8th arg true) and publishes NOTHING. The
     * window loop's next budget read then sees what production sees: the
     * in-flight tagged temp. Probe/colour stubs match {@see recordingRunner()}.
     *
     * @param list<array{out:string,start:float,prewarm:bool}> $launches
     */
    private function holdingRunner(array &$launches): FfmpegRunner
    {
        $ff = $this->createMock(FfmpegRunner::class);
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
        $ff->method('startSegmentEncode')->willReturnCallback(
            /** @param array<string, mixed> $params */
            static function (
                string $inputPath,
                string $outFile,
                float $start,
                float $duration,
                array $params,
                ?string $cancelKey = null,
                ?string $cancelGroup = null,
                bool $prewarm = false
            ) use (&$launches): int {
                $launches[] = ['out' => $outFile, 'start' => $start, 'prewarm' => $prewarm];
                file_put_contents($outFile . '.part-p' . bin2hex(random_bytes(4)), '');
                return 4242;
            }
        );

        return $ff;
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
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
