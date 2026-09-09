<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata\Writer;

use Phlix\Admin\SettingsRepository;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\MediaItem;
use Phlix\Media\Metadata\EmbeddedWritePolicy;
use Phlix\Media\Metadata\MetadataOverwritePolicy;
use Phlix\Media\Metadata\Writer\EmbeddedMetadataWriter;
use Phlix\Media\Metadata\Writer\EmbeddedWriteFailedException;
use Phlix\Media\Metadata\Writer\ExternalCommandRunnerInterface;
use Phlix\Media\Metadata\Writer\MetadataWriteJob;
use Phlix\Media\Metadata\Writer\MetadataWriteJobStore;
use Phlix\Media\Metadata\Writer\MetadataWriteWorker;
use Phlix\Media\Metadata\Writer\MetadataWriterRegistry;
use Phlix\Media\Metadata\Writer\SidecarWriter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Workerman\MySQL\Connection;

/**
 * S89 — the embedded-tag writer, acceptance criteria clause by clause:
 *
 *  - "Default-off proven by a test (no write happens without explicit
 *    opt-in)" → test_default_off_gate_touches_nothing (+ the per-call gate
 *    re-read, + the policy-level matrix in EmbeddedWritePolicyTest, + the
 *    enqueue gate upstream which is S87's own proven surface).
 *  - "a simulated interrupted-remux scenario leaves the original file intact
 *    (atomic-rename discipline verified)" → the three interruption arms
 *    (non-zero exit with PARTIAL stage, signal-shaped negative exit, real
 *    ffmpeg refusing junk bytes) + the success arms proving the staged file
 *    IS what gets published; every arm asserts zero `.phlix-embed.tmp.*`
 *    residue and byte-identical originals where expected.
 *  - "MetadataOverwritePolicy respected when an operator-curated NFO exists"
 *    → ruling R2's concrete predicate (stem + Kodi-convention NFOs lacking
 *    SidecarWriter::GENERATOR_MARKER) skips with a logged reason and no throw;
 *    ruling R1's policy-deny-on-existing-tags likewise skips; real-file
 *    positive AND negative controls pin the tag detection both ways.
 *
 * Fixtures are REAL where real matters (ffmpeg-authored MP3s for the tag
 * detection and getID3 write-back, real /bin/* processes for failure exits —
 * S345 rule 2), and a scripted runner isolates the ffmpeg-command arms so the
 * interruption exits (SIGKILL shape included) are deterministic.
 */
final class EmbeddedMetadataWriterTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            $this->removeTree($dir);
        }
        $this->dirs = [];
    }

    // ── fixtures ──────────────────────────────────────────────────────────

    private function tempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir() . '/phlix_s89_' . $prefix . '_' . uniqid('', true);
        self::assertTrue(mkdir($dir, 0777, true));
        $this->dirs[] = $dir;

        return $dir;
    }

    private function removeTree(string $dir): void
    {
        @chmod($dir, 0777);
        /** @var list<string> $entries */
        $entries = glob($dir . '/*') ?: [];
        foreach ($entries as $entry) {
            if (is_dir($entry)) {
                $this->removeTree($entry);
            } else {
                @chmod($entry, 0644);
                @unlink($entry);
            }
        }
        @rmdir($dir);
    }

    /** @param array<string, mixed> $metadata */
    private function item(string $path, array $metadata = ['name' => 'Inception'], string $type = 'movie'): MediaItem
    {
        return new MediaItem('item-s89-1', basename($path), $type, $path, $metadata);
    }

    private function fakeMedia(string $dir, string $name, string $bytes = 'ORIGINAL-BYTES'): string
    {
        $path = $dir . '/' . $name;
        file_put_contents($path, $bytes);

        return $path;
    }

    private function policyRepo(mixed $effective): SettingsRepository
    {
        $repo = $this->createMock(SettingsRepository::class);
        $repo->method('getEffective')->willReturn($effective);

        return $repo;
    }

    private function optIn(mixed $effective = true): EmbeddedWritePolicy
    {
        return new EmbeddedWritePolicy($this->policyRepo($effective));
    }

    private function overwrite(mixed $effective = true): MetadataOverwritePolicy
    {
        return new MetadataOverwritePolicy($this->policyRepo($effective));
    }

    private function writer(
        ExternalCommandRunnerInterface $runner,
        ?EmbeddedWritePolicy $optIn = null,
        ?MetadataOverwritePolicy $overwrite = null,
        ?LoggerInterface $logger = null,
    ): EmbeddedMetadataWriter {
        return new EmbeddedMetadataWriter(
            $optIn ?? $this->optIn(),
            $overwrite ?? $this->overwrite(),
            $runner,
            '/usr/bin/ffmpeg',
            $logger,
        );
    }

    private function assertNoStageResidue(string $mediaPath): void
    {
        $this->assertSame([], glob($mediaPath . '.phlix-embed.tmp.*'));
    }

    private function ffmpegBin(): string
    {
        $bin = trim((string) @shell_exec('command -v ffmpeg 2>/dev/null'));
        if ($bin === '') {
            $this->markTestSkipped('ffmpeg binary not available on this machine');
        }

        return $bin;
    }

    /** Real ffmpeg-authored artefacts — never hand-guessed containers (S345 rule 2). */
    private function realMp3(string $dir, string $name, bool $withTitleTag): string
    {
        $path = $dir . '/' . $name;
        $metadata = $withTitleTag ? '-metadata title=ORIGINAL-TAG ' : '';
        $exit = 0;
        exec(
            escapeshellarg($this->ffmpegBin())
            . ' -nostdin -hide_banner -loglevel error -y -f lavfi -i sine=frequency=440:duration=1 '
            . $metadata . escapeshellarg($path),
            $out,
            $exit,
        );
        self::assertSame(0, $exit, 'fixture ffmpeg must succeed: ' . implode("\n", $out));
        self::assertFileExists($path);

        return $path;
    }

    // ── supports() ────────────────────────────────────────────────────────

    public function test_supports_declares_exactly_movie_episode_track(): void
    {
        $writer = $this->writer(new S89ScriptedRunner());

        foreach (['movie', 'episode', 'track'] as $type) {
            $this->assertTrue($writer->supports($type), "writer must support {$type}");
        }
        foreach (['', 'book', 'audiobook', 'image', 'series', 'Movie'] as $type) {
            $this->assertFalse($writer->supports($type), "writer must NOT support {$type}");
        }
    }

    // ── AC 1: default-off ─────────────────────────────────────────────────

    public function test_default_off_gate_touches_nothing(): void
    {
        $dir = $this->tempDir('off');
        $media = $this->fakeMedia($dir, 'x.mkv');
        // Even hostile pre-flight inputs pass silently while the gate is off:
        // the gate is consulted BEFORE any path/permission logic (ordering).
        $this->writer(new S89ScriptedRunner(), $this->optIn(false))->write($this->item($media), [], '');
        $this->writer(new S89ScriptedRunner(), $this->optIn(false))->write($this->item('/nope/gone.mkv'), [], '/nope');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('debug')
            ->with(
                $this->stringContains('skipped, embedded writing not enabled'),
                $this->callback(fn (array $c): bool => $c['item_id'] === 'item-s89-1'
                    && $c['setting'] === EmbeddedWritePolicy::SETTING_KEY),
            );
        $runner = new S89ScriptedRunner();
        $this->writer($runner, $this->optIn(false), logger: $logger)
            ->write($this->item($media), ['name' => 'Inception'], $dir);

        $this->assertSame([], $runner->calls, 'the opt-in gate must short-circuit before any command runs');
        $this->assertSame('ORIGINAL-BYTES', file_get_contents($media));
        $this->assertSame([], glob($dir . '/*.nfo'), 'the embedded writer never creates sidecars');
    }

    public function test_gate_is_consulted_per_call_not_once(): void
    {
        $dir = $this->tempDir('percall');
        $media = $this->fakeMedia($dir, 'x.mkv');

        // First call off: nothing. Same file, fresh writer with the gate on:
        // the write proceeds — a latched/cached gate would show up here.
        $off = new S89ScriptedRunner();
        $this->writer($off, $this->optIn(false))->write($this->item($media), ['name' => 'I'], $dir);
        $this->assertSame([], $off->calls);

        $on = new S89ScriptedRunner([S89ScriptedRunner::writeToStage($media, 'GATE-ON-BYTES')]);
        $this->writer($on, $this->optIn(true))->write($this->item($media), ['name' => 'I'], $dir);
        $this->assertCount(1, $on->calls);
        $this->assertSame('GATE-ON-BYTES', file_get_contents($media));
    }

    // ── pre-flight refusals (throw family; original never touched) ────────

    public function test_degenerate_media_dir_throws_named_exception(): void
    {
        foreach (['', '.'] as $degenerate) {
            try {
                $this->writer(new S89ScriptedRunner())->write($this->item('x.mkv'), [], $degenerate);
                $this->fail("degenerate dir '{$degenerate}' must throw");
            } catch (EmbeddedWriteFailedException $e) {
                $this->assertStringContainsString('no usable media directory', $e->getMessage());
                $this->assertSame('item-s89-1', $e->itemId);
            }
        }
    }

    public function test_missing_media_file_throws_missing_media_file(): void
    {
        $dir = $this->tempDir('gone');
        try {
            $this->writer(new S89ScriptedRunner())->write($this->item($dir . '/x.mkv'), [], $dir);
            $this->fail('missing media must throw');
        } catch (EmbeddedWriteFailedException $e) {
            $this->assertStringContainsString('does not exist', $e->getMessage());
            $this->assertStringContainsString($dir . '/x.mkv', $e->getMessage());
        }
    }

    public function test_read_only_media_dir_throws_and_original_stays_intact(): void
    {
        $dir = $this->tempDir('ro');
        $media = $this->fakeMedia($dir, 'x.mkv');
        self::assertTrue(chmod($dir, 0555));
        try {
            $this->writer(new S89ScriptedRunner())->write($this->item($media), ['name' => 'I'], $dir);
            $this->fail('read-only media dir must throw');
        } catch (EmbeddedWriteFailedException $e) {
            $this->assertStringContainsString('not writable', $e->getMessage());
        } finally {
            chmod($dir, 0777);
        }
        $this->assertSame('ORIGINAL-BYTES', file_get_contents($media));
        $this->assertSame([], glob($dir . '/*.phlix-embed.tmp.*'));
    }

    // ── AC 3 + ruling R2: operator-curated NFO predicate ──────────────────

    public function test_stem_nfo_without_generator_marker_skips_and_writes_nothing(): void
    {
        $dir = $this->tempDir('curated');
        $media = $this->fakeMedia($dir, 'Inception (2010).mkv');
        file_put_contents(
            $dir . '/Inception (2010).nfo',
            "<?xml version=\"1.0\"?>\n<movie><title>Hand-curated</title></movie>\n",
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')
            ->with(
                $this->stringContains('operator-curated NFO present'),
                $this->callback(fn (array $c): bool => $c['nfo_path'] === $dir . '/Inception (2010).nfo'),
            );

        $runner = new S89ScriptedRunner();
        $this->writer($runner, logger: $logger)->write($this->item($media), ['name' => 'Inception'], $dir);

        $this->assertSame([], $runner->calls);
        $this->assertSame('ORIGINAL-BYTES', file_get_contents($media));
    }

    public function test_kodi_convention_movie_nfo_without_marker_skips_too(): void
    {
        $dir = $this->tempDir('convention');
        $media = $this->fakeMedia($dir, 'movie.mkv');
        file_put_contents($dir . '/movie.nfo', '<movie><plot>keeper</plot></movie>');

        $runner = new S89ScriptedRunner();
        $this->writer($runner)->write($this->item($media), ['name' => 'M'], $dir);

        $this->assertSame([], $runner->calls);
        $this->assertSame('ORIGINAL-BYTES', file_get_contents($media));
    }

    public function test_sidecar_stamped_nfo_does_not_skip_the_embedded_write(): void
    {
        // Non-vacuity for the marker check (S345 rule 3): the ONLY difference
        // from the curated test is the generator marker line — if the
        // predicate ignored the marker, this arm would skip and fail.
        $dir = $this->tempDir('machine');
        $media = $this->fakeMedia($dir, 'Inception (2010).mkv');
        file_put_contents(
            $dir . '/Inception (2010).nfo',
            "<!-- generated by phlix SidecarWriter " . SidecarWriter::GENERATOR_MARKER . " -->\n<movie/>\n",
        );

        $runner = new S89ScriptedRunner([S89ScriptedRunner::writeToStage($media, 'MACHINE-OK')]);
        $this->writer($runner)->write($this->item($media), ['name' => 'Inception'], $dir);

        $this->assertCount(1, $runner->calls);
        $this->assertSame('MACHINE-OK', file_get_contents($media));
    }

    public function test_unreadable_nfo_counts_as_curated(): void
    {
        // Fail-safe direction: an existing sidecar we cannot prove
        // machine-made must block the destructive path.
        $dir = $this->tempDir('unreadnfo');
        $media = $this->fakeMedia($dir, 'x.mkv');
        $nfo = $dir . '/x.nfo';
        file_put_contents($nfo, '<movie/>');
        self::assertTrue(chmod($nfo, 0000));
        try {
            $runner = new S89ScriptedRunner();
            $this->writer($runner)->write($this->item($media), ['name' => 'I'], $dir);
            $this->assertSame([], $runner->calls);
        } finally {
            chmod($nfo, 0644);
        }
        $this->assertSame('ORIGINAL-BYTES', file_get_contents($media));
    }

    public function test_curated_predicate_blocks_even_with_both_policies_open(): void
    {
        // Ruling R2: the per-file predicate stands IN FRONT of the policy —
        // policy-allow must not override a detected curation.
        $dir = $this->tempDir('front');
        $media = $this->fakeMedia($dir, 'x.mkv');
        file_put_contents($dir . '/x.nfo', '<movie><title>curated</title></movie>');

        $runner = new S89ScriptedRunner();
        $this->writer($runner, $this->optIn(true), $this->overwrite(true))
            ->write($this->item($media), ['name' => 'I'], $dir);

        $this->assertSame([], $runner->calls);
    }

    // ── ruling R1: MetadataOverwritePolicy on existing embedded tags ──────

    public function test_existing_real_tags_with_policy_deny_skip_without_throw(): void
    {
        $dir = $this->tempDir('deny');
        $media = $this->realMp3($dir, 'song.mp3', withTitleTag: true);
        $before = (string) file_get_contents($media);
        $this->assertNotFalse($before);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')
            ->with(
                $this->stringContains('overwrite policy denies'),
                $this->callback(fn (array $c): bool => $c['setting'] === MetadataOverwritePolicy::SETTING_KEY),
            );
        $logger->expects($this->never())->method('warning');

        $runner = new S89ScriptedRunner();
        $this->writer($runner, $this->optIn(true), $this->overwrite(false), $logger)
            ->write($this->item($media, ['name' => 'Renamed'], 'track'), ['name' => 'Renamed'], $dir);

        $this->assertSame([], $runner->calls);
        $this->assertSame($before, file_get_contents($media), 'a policy deny must leave zero byte-diff');
    }

    public function test_untagged_real_mp3_writes_even_when_policy_denies_positive_control(): void
    {
        // The detection, not a blanket deny, gates R1 (S345 rule 3): same
        // policy-false as the previous test, file WITHOUT tags → real getID3
        // write happens and the title round-trips through the real reader.
        $dir = $this->tempDir('allow');
        $media = $this->realMp3($dir, 'plain.mp3', withTitleTag: false);

        $runner = new S89ScriptedRunner();
        $this->writer($runner, $this->optIn(true), $this->overwrite(false))
            ->write($this->item($media, ['name' => 'New Song'], 'track'), ['name' => 'New Song'], $dir);

        $this->assertSame([], $runner->calls, 'audio writes through getID3, never through the command runner');
        $info = (new \getID3())->analyze($media);
        $this->assertSame(['New Song'], $info['tags']['id3v2']['title'] ?? null);
        $this->assertFalse(
            str_contains((string) file_get_contents($media), EmbeddedMetadataWriter::GENERATOR_MARKER),
            'the survival token stays CODE-resident; media files are never stamped with it',
        );
    }

    public function test_tagged_real_mp3_with_policy_allow_overwrites_the_title(): void
    {
        $dir = $this->tempDir('retag');
        $media = $this->realMp3($dir, 'tagged.mp3', withTitleTag: true);

        $this->writer(new S89ScriptedRunner(), $this->optIn(true), $this->overwrite(true))
            ->write($this->item($media, ['name' => 'Replaced'], 'track'), ['name' => 'Replaced'], $dir);

        $info = (new \getID3())->analyze($media);
        $this->assertSame(['Replaced'], $info['tags']['id3v2']['title'] ?? null);
        $this->assertNoStageResidue($media);
    }

    // ── container scoping ─────────────────────────────────────────────────

    public function test_unsupported_container_skips_without_touching(): void
    {
        $dir = $this->tempDir('container');
        $media = $this->fakeMedia($dir, 'clip.avi');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')->with(
            $this->stringContains('unsupported container extension'),
            $this->callback(fn (array $c): bool => $c['extension'] === 'avi'),
        );

        $runner = new S89ScriptedRunner();
        $this->writer($runner, logger: $logger)->write($this->item($media), ['name' => 'C'], $dir);

        $this->assertSame([], $runner->calls);
        $this->assertSame('ORIGINAL-BYTES', file_get_contents($media));
    }

    // ── AC 2: atomic-rename discipline under interruption ─────────────────

    public function test_interrupted_remux_with_partial_stage_leaves_original_byte_identical(): void
    {
        $dir = $this->tempDir('interrupt');
        $media = $this->fakeMedia($dir, 'x.mkv');

        $runner = new S89ScriptedRunner([
            // Simulated power loss mid-remux: the tool created the output and
            // wrote PARTIAL bytes, then died on SIGTERM (shell 128+15 shape).
            static function (string $cmd) use ($media): array {
                // The tool opened its output and wrote PARTIAL bytes, then died
                // on SIGTERM (shell 128+15 shape).
                $staged = S89ScriptedRunner::stageOf($cmd, $media);
                self::assertNotNull($staged, 'the interrupted arm needs the real staged target from the command');
                file_put_contents((string) $staged, 'PART');
                $observed = file_get_contents($media);

                return ['exitCode' => 143, 'stdout' => '', 'stderr' => 'Terminated', 'observed' => $observed];
            },
        ]);

        try {
            $this->writer($runner)->write($this->item($media), ['name' => 'I'], $dir);
            $this->fail('a non-zero remux must throw');
        } catch (EmbeddedWriteFailedException $e) {
            $this->assertStringContainsString('ffmpeg remux exited 143', $e->getMessage());
        }

        $this->assertSame('ORIGINAL-BYTES', file_get_contents($media));
        $this->assertNoStageResidue($media);
        $this->assertSame(
            'ORIGINAL-BYTES',
            $runner->results[0]['observed'] ?? null,
            'the writer must never open the original for write (bytes mid-run are the originals)',
        );
    }

    public function test_signal_shaped_negative_exit_is_also_a_clean_failure(): void
    {
        $dir = $this->tempDir('sigkill');
        $media = $this->fakeMedia($dir, 'x.mkv');

        $runner = new S89ScriptedRunner([['exitCode' => -9, 'stdout' => '', 'stderr' => '']]);
        $this->expectException(EmbeddedWriteFailedException::class);
        try {
            $this->writer($runner)->write($this->item($media), ['name' => 'I'], $dir);
        } finally {
            $this->assertSame('ORIGINAL-BYTES', file_get_contents($media));
            $this->assertNoStageResidue($media);
        }
    }

    public function test_zero_exit_without_usable_stage_throws_and_keeps_original(): void
    {
        $dir = $this->tempDir('nostage');
        $media = $this->fakeMedia($dir, 'x.mkv');

        // ffmpeg "succeeds" but left a 0-BYTE staged file (interrupted right
        // after container creation): publishing that would truncate a good
        // file, so the empty-stage guard must refuse it.
        $runner = new S89ScriptedRunner([
            static function (string $cmd) use ($media): array {
                $staged = S89ScriptedRunner::stageOf($cmd, $media);
                self::assertNotNull($staged);
                touch((string) $staged);

                return ['exitCode' => 0, 'stdout' => '', 'stderr' => ''];
            },
        ]);

        try {
            $this->writer($runner)->write($this->item($media), ['name' => 'I'], $dir);
            $this->fail('a successful exit without a usable stage must throw');
        } catch (EmbeddedWriteFailedException $e) {
            $this->assertStringContainsString('no usable staged file', $e->getMessage());
        }
        $this->assertSame('ORIGINAL-BYTES', file_get_contents($media));
        $this->assertNoStageResidue($media);
    }

    public function test_real_ffmpeg_refusing_a_junk_input_is_a_clean_failure(): void
    {
        // The non-zero-exit arm WITHOUT simulation: the ACTUAL binary, the
        // ACTUAL command line (S345 rule 2 — the fake cannot certify that
        // ffmpeg accepts this argument shape at all; this pair does: refusal
        // here and full success in the sibling suite).
        $dir = $this->tempDir('realrefuse');
        $media = $this->fakeMedia($dir, 'x.mkv');

        $runner = new \Phlix\Media\Metadata\Writer\ExecExternalCommandRunner();
        try {
            $this->writer($runner)->write($this->item($media), ['name' => 'I'], $dir);
            $this->fail('ffmpeg must refuse a non-container input');
        } catch (EmbeddedWriteFailedException $e) {
            $this->assertStringContainsString('ffmpeg remux exited', $e->getMessage());
        }
        $this->assertSame('ORIGINAL-BYTES', file_get_contents($media));
        $this->assertNoStageResidue($media);
    }

    // ── success arm: staged file IS what publishes ────────────────────────

    public function test_successful_remux_publishes_stage_atomically_and_preserves_mode(): void
    {
        $dir = $this->tempDir('success');
        $media = $this->fakeMedia($dir, 'Inception (2010).mkv');
        self::assertTrue(chmod($media, 0642));

        $runner = new S89ScriptedRunner([S89ScriptedRunner::writeToStage($media, 'FULL-NEW-CONTAINER')]);
        $this->writer($runner)->write(
            $this->item($media),
            ['name' => 'Inception', 'year' => '2010', 'overview' => 'A heist <film> & more', 'vote_average' => 8.4],
            $dir,
        );

        $this->assertSame('FULL-NEW-CONTAINER', file_get_contents($media));
        $this->assertSame(0642, fileperms($media) & 0777, 'the original file mode must survive the publish');
        $this->assertNoStageResidue($media);

        $cmd = $runner->calls[0];
        $this->assertStringContainsString('-nostdin -hide_banner -loglevel error -y', $cmd);
        $this->assertStringContainsString('-map 0 -c copy', $cmd);
        $this->assertStringContainsString('-metadata title=' . escapeshellarg('Inception'), $cmd);
        $this->assertStringContainsString('-metadata synopsis=' . escapeshellarg('A heist <film> & more'), $cmd);
        $this->assertStringNotContainsString('-movflags', $cmd, 'MKV rejects faststart');
        $this->assertStringContainsString(
            '-f matroska',
            $cmd,
            'container pinned: the stage carries no extension to sniff',
        );
        $this->assertStringContainsString('-i ' . escapeshellarg($media) . ' ', $cmd, 'the ORIGINAL is only -i');
        $this->assertStringEndsNotWith(escapeshellarg($media), $cmd, 'output must be the stage, never the original');
    }

    public function test_mp4_gets_faststart_and_mkv_does_not(): void
    {
        $dir = $this->tempDir('mp4');
        $media = $this->fakeMedia($dir, 'x.mp4');
        $runner = new S89ScriptedRunner([S89ScriptedRunner::writeToStage($media, 'NEW')]);
        $this->writer($runner)->write($this->item($media), ['name' => 'X'], $dir);
        $this->assertStringContainsString('-movflags +faststart -f mp4', $runner->calls[0]);

        $mkv = $this->fakeMedia($dir, 'y.mkv');
        $runner2 = new S89ScriptedRunner([S89ScriptedRunner::writeToStage($mkv, 'NEW')]);
        $this->writer($runner2)->write($this->item($mkv), ['name' => 'Y'], $dir);
        $this->assertStringNotContainsString('-movflags', $runner2->calls[0]);
        $this->assertStringContainsString('-f matroska', $runner2->calls[0]);
    }

    public function test_provider_metadata_values_cannot_break_out_of_their_quoting(): void
    {
        $dir = $this->tempDir('escape');
        $media = $this->fakeMedia($dir, 'x.mkv');
        $evil = "O'Brien; touch /tmp/phlix_pwned_s89 \"\$(`id`)\"";

        $runner = new S89ScriptedRunner([S89ScriptedRunner::writeToStage($media, 'NEW')]);
        $this->writer($runner)->write($this->item($media, ['name' => $evil]), ['name' => $evil], $dir);

        $this->assertStringContainsString('-metadata title=' . escapeshellarg($evil), $runner->calls[0]);
        $this->assertSame([], glob('/tmp/phlix_pwned_s89*'), 'no injection side effect may reach the filesystem');
    }

    public function test_rewriting_the_same_item_twice_is_idempotent_and_leaves_no_residue(): void
    {
        $dir = $this->tempDir('idem');
        $media = $this->fakeMedia($dir, 'x.mkv');
        $writer = $this->writer(new S89ScriptedRunner([
            S89ScriptedRunner::writeToStage($media, 'STABLE-BYTES'),
            S89ScriptedRunner::writeToStage($media, 'STABLE-BYTES'),
        ]));

        $writer->write($this->item($media), ['name' => 'I'], $dir);
        $first = (string) file_get_contents($media);
        $writer->write($this->item($media), ['name' => 'I'], $dir);

        $this->assertSame('STABLE-BYTES', $first);
        $this->assertSame($first, file_get_contents($media));
        $this->assertNoStageResidue($media);
    }

    public function test_failed_getid3_write_on_junk_audio_keeps_the_original(): void
    {
        $dir = $this->tempDir('junkmp3');
        $media = $this->fakeMedia($dir, 'fake.mp3');

        try {
            $item = $this->item($media, ['name' => 'I'], 'track');
            $this->writer(new S89ScriptedRunner())->write($item, ['name' => 'I'], $dir);
            $this->fail('getID3 must refuse a junk MP3');
        } catch (EmbeddedWriteFailedException $e) {
            $this->assertStringContainsString('getid3_writetags failed', $e->getMessage());
        }
        $this->assertSame('ORIGINAL-BYTES', file_get_contents($media));
        $this->assertNoStageResidue($media);
    }

    public function test_empty_audio_fields_fail_loud_before_touching_the_file(): void
    {
        $dir = $this->tempDir('emptymeta');
        $media = $this->fakeMedia($dir, 'a.mp3');

        try {
            $this->writer(new S89ScriptedRunner())->write($this->item($media, [], 'track'), [], $dir);
            $this->fail('no embeddable fields must throw, not silently "succeed"');
        } catch (EmbeddedWriteFailedException $e) {
            $this->assertStringContainsString('no embeddable audio fields', $e->getMessage());
        }
        $this->assertSame('ORIGINAL-BYTES', file_get_contents($media));
        $this->assertNoStageResidue($media);
    }

    // ── review round 1: symmetric guards ──────────────────────────────────

    public function test_empty_video_fields_fail_loud_before_any_staging(): void
    {
        $dir = $this->tempDir('emptyvideo');
        $media = $this->fakeMedia($dir, 'v.mkv');
        $runner = new S89ScriptedRunner();

        try {
            $this->writer($runner)->write($this->item($media), [], $dir);
            $this->fail('a zero-flag remux is a pointless destructive rewrite and must throw');
        } catch (EmbeddedWriteFailedException $e) {
            $this->assertStringContainsString('no embeddable video fields', $e->getMessage());
        }

        $this->assertSame([], $runner->calls, 'the guard must fire before the command runs');
        $this->assertSame('ORIGINAL-BYTES', file_get_contents($media));
        $this->assertNoStageResidue($media);
    }

    public function test_a_symlink_at_the_media_path_is_refused_not_followed(): void
    {
        $dir = $this->tempDir('symlink');
        $target = $dir . '/outside-target.mp3';
        file_put_contents($target, 'SENSITIVE-TARGET-BYTES');
        $link = $dir . '/link.mp3';
        self::assertTrue(symlink($target, $link));

        try {
            $this->writer(new S89ScriptedRunner())
                ->write($this->item($link, ['name' => 'X'], 'track'), ['name' => 'X'], $dir);
            $this->fail('the destructive arm must never dereference a symlinked media path');
        } catch (EmbeddedWriteFailedException $e) {
            $this->assertStringContainsString('symbolic link', $e->getMessage());
        }

        $this->assertTrue(is_link($link), 'the operator link itself must survive untouched');
        $this->assertSame('SENSITIVE-TARGET-BYTES', file_get_contents($target));
        $this->assertSame([], glob($dir . '/*.phlix-embed.tmp.*'));
    }

    public function test_stage_creation_is_exclusive_and_fails_loud_when_the_path_exists(): void
    {
        // Removal-red for the `fopen(...,'xb')` guard (S345 rule 3): calling the
        // guard directly in a directory the process cannot write must throw, so
        // reverting it to a plain path builder reddens this arm instead of
        // silently handing stage creation to ffmpeg -y / copy().
        $dir = $this->tempDir('stageguard');
        $media = $this->fakeMedia($dir, 'g.mp3');
        self::assertTrue(chmod($dir, 0555));

        try {
            $method = new \ReflectionMethod(EmbeddedMetadataWriter::class, 'createStage');
            $method->setAccessible(true);
            $method->invoke($this->writer(new S89ScriptedRunner()), $this->item($media), $media);
            $this->fail('stage creation must fail loudly when it cannot create exclusively');
        } catch (EmbeddedWriteFailedException $e) {
            $this->assertStringContainsString('exclusively create the stage file', $e->getMessage());
        } finally {
            chmod($dir, 0777);
        }
    }

    public function test_whitespace_only_fields_count_as_empty_behind_both_guards(): void
    {
        // S345 rule 1 for the round-1 guards: '   ' must reach the SAME refusal
        // as '' (asString() does not trim — measured), or blank tags would ride
        // a full destructive rewrite of the operator's file.
        $dir = $this->tempDir('blankmeta');
        $video = $this->fakeMedia($dir, 'w.mkv');
        $audio = $this->fakeMedia($dir, 'w.mp3');
        $runner = new S89ScriptedRunner();

        try {
            $this->writer($runner)->write($this->item($video), ['name' => '   '], $dir);
            $this->fail('whitespace-only title must count as no embeddable video fields');
        } catch (EmbeddedWriteFailedException $e) {
            $this->assertStringContainsString('no embeddable video fields', $e->getMessage());
        }

        try {
            $this->writer($runner)
                ->write($this->item($audio, ['name' => '   '], 'track'), ['name' => '   '], $dir);
            $this->fail('whitespace-only title must count as no embeddable audio fields');
        } catch (EmbeddedWriteFailedException $e) {
            $this->assertStringContainsString('no embeddable audio fields', $e->getMessage());
        }

        $this->assertSame([], $runner->calls);
        $this->assertSame('ORIGINAL-BYTES', file_get_contents($video));
        $this->assertSame('ORIGINAL-BYTES', file_get_contents($audio));
    }

    public function test_publish_never_propagates_setuid_or_sticky_bits(): void
    {
        // Removal-guard for the `& 0777` publish mask (S345 rule 3): the other
        // mode arm uses 0642, which passes under the old `& 07777` too — this
        // one cannot.
        $dir = $this->tempDir('setuid');
        $media = $this->fakeMedia($dir, 's.mkv');
        self::assertTrue(chmod($media, 04644));
        $runner = new S89ScriptedRunner([S89ScriptedRunner::writeToStage($media, 'NEW')]);

        $this->writer($runner)->write($this->item($media), ['name' => 'S'], $dir);

        $perms = fileperms($media);
        $this->assertSame(0, $perms & 07000, 'setuid/setgid/sticky must not survive the publish');
        $this->assertSame(0644, $perms & 0777);
        $this->assertNoStageResidue($media);
    }

    // ── worker-visible contract (R1: deny is never a warning) ─────────────

    public function test_worker_sees_a_policy_skip_as_success_and_a_write_failure_as_warning(): void
    {
        $dir = $this->tempDir('worker');
        $curated = $this->fakeMedia($dir, 'x.mkv');
        file_put_contents($dir . '/x.nfo', '<movie><title>curated</title></movie>');
        $junk = $this->fakeMedia($dir, 'y.mkv');

        $store = new MetadataWriteJobStore($this->tempDir('wqueue'));
        $registry = new MetadataWriterRegistry();
        $registry->register($this->writer(
            new S89ScriptedRunner([['exitCode' => 1, 'stdout' => '', 'stderr' => 'boom']]),
        ));

        $workerLogger = $this->createMock(LoggerInterface::class);
        $workerLogger->expects($this->once())->method('warning')
            ->with(
                $this->stringContains('writer failed for item'),
                $this->callback(fn (array $c): bool => $c['writer'] === EmbeddedMetadataWriter::class
                    && str_contains((string) $c['error'], 'exited 1')),
            );

        $rows = [
            'w-1' => ['id' => 'w-1', 'name' => 'x', 'type' => 'movie', 'path' => $curated,
                'metadata_json' => '{"name":"x"}'],
            'w-2' => ['id' => 'w-2', 'name' => 'y', 'type' => 'movie', 'path' => $junk,
                'metadata_json' => '{"name":"y"}'],
        ];
        $worker = new MetadataWriteWorker(
            $store,
            $registry,
            new S89RowRepo($this->createMock(Connection::class), $rows),
            $workerLogger,
        );

        $store->enqueue(new MetadataWriteJob('w-1', 'lib-1'));
        $store->enqueue(new MetadataWriteJob('w-2', 'lib-1'));

        // One batch (cap=2) drains both jobs: w-1 is the curated SKIP (writer
        // returns normally → no worker warning), w-2 the scripted remux failure
        // (→ exactly one warning naming this writer class).
        $this->assertSame(2, $worker->runOnce());
        $this->assertSame('ORIGINAL-BYTES', file_get_contents($curated));
    }
}

/**
 * Scripted command-runner double: records every command line, runs one script
 * step per call (result array or callable receiving the line), and keeps every
 * step's returned result in {@see self::$results} so tests can inspect values
 * captured mid-run (e.g. the original's bytes while the tool "ran").
 *
 * Stage paths are located by GLOB on the media path, never by re-parsing the
 * command line — a staged sibling containing spaces (real scene filenames do)
 * must not break the double (the exact class of hand-fixture blindness S345
 * rule 2 warns about).
 */
final class S89ScriptedRunner implements ExternalCommandRunnerInterface
{
    /** @var list<string> */
    public array $calls = [];

    /** @var list<array<string, mixed>> */
    public array $results = [];

    /** @var list<mixed> */
    private array $script;

    /** @param list<mixed> $script */
    public function __construct(array $script = [])
    {
        $this->script = $script;
    }

    /**
     * The output target of the recorded ffmpeg command: the LAST single-quoted
     * token of the line, with escapeshellarg's `'\''` un-escaping reversed.
     * Returns null unless the line ends in a well-formed quoted token shaped
     * like $mediaPath's staged sibling — so every arm using it simultaneously
     * PINS "ffmpeg's output arg is the stage (never the original)" and locates
     * the exact path the real tool would have created.
     */
    public static function stageOf(string $cmd, string $mediaPath): ?string
    {
        if (!preg_match("/'((?:[^']|'\\'')*)'\s*$/", $cmd, $m)) {
            return null;
        }
        $token = str_replace("'\\''", "'", $m[1]);

        return str_starts_with($token, $mediaPath . '.phlix-embed.tmp.') ? $token : null;
    }

    /**
     * Step callable simulating a COMPLETED tool run: creates the staged file
     * at exactly the path the command's final token names and exits 0. If the
     * final token is NOT a staged sibling of $mediaPath the step FAILS loudly
     * (exit 1, nothing published) — this is the arm that would catch a writer
     * regression pointing ffmpeg at the original itself.
     */
    public static function writeToStage(string $mediaPath, string $bytes): callable
    {
        return static function (string $cmd) use ($mediaPath, $bytes): array {
            $staged = self::stageOf($cmd, $mediaPath);
            if ($staged === null) {
                return ['exitCode' => 1, 'stdout' => '',
                    'stderr' => 'final token was not a stage of the media: ' . $cmd];
            }

            file_put_contents($staged, $bytes);

            return ['exitCode' => 0, 'stdout' => '', 'stderr' => ''];
        };
    }

    public function run(string $commandLine): array
    {
        $this->calls[] = $commandLine;

        $step = array_shift($this->script);
        $result = $step === null
            ? ['exitCode' => 0, 'stdout' => '', 'stderr' => '']
            : (is_callable($step) ? $step($commandLine) : $step);

        /** @var array<string, mixed> $result */
        $this->results[] = $result;

        return $result;
    }
}

/** ItemRepository double with fixed rows — unique name per suite file (S87 precedent). */
final class S89RowRepo extends ItemRepository
{
    /** @param array<string, array<string, mixed>> $rows */
    public function __construct(Connection $db, private array $rows)
    {
        parent::__construct($db);
    }

    public function findById(string $id): ?array
    {
        return $this->rows[$id] ?? null;
    }
}
