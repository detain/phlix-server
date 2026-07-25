<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;

/**
 * SV-2.9 / SV-0.7: guards the managed-worker supervision wiring.
 *
 * The disk-leak bug class this suite prevents is "a config/process.php entry is
 * enabled but nothing in start.php spawns it" — which left the similarity (and,
 * previously, media-asset) queues accumulating undrained on disk. The single
 * source of truth for the spawn map is config/managed_workers.php; start.php
 * reads it and config/process.php together. This asserts they stay in sync.
 */
final class ManagedWorkersConfigTest extends TestCase
{
    /**
     * @return array<string, array{enabled?: bool, count?: int, poll_seconds?: int}>
     */
    private function processConfig(): array
    {
        /** @var array<string, array{enabled?: bool, count?: int, poll_seconds?: int}> $cfg */
        $cfg = require dirname(__DIR__, 3) . '/config/process.php';
        return $cfg;
    }

    /**
     * @return array<string, class-string>
     */
    private function managedWorkers(): array
    {
        /** @var array<string, class-string> $map */
        $map = require dirname(__DIR__, 3) . '/config/managed_workers.php';
        return $map;
    }

    /**
     * @return array<string, mixed>
     */
    private function similarityJobsConfig(): array
    {
        /** @var array<string, mixed> $cfg */
        $cfg = require dirname(__DIR__, 3) . '/config/similarity_jobs.php';
        return $cfg;
    }

    /**
     * @return array<string, mixed>
     */
    private function markerDetectionConfig(): array
    {
        /** @var array<string, mixed> $cfg */
        $cfg = require dirname(__DIR__, 3) . '/config/marker_detection.php';
        return $cfg;
    }

    public function test_process_config_registers_the_similarity_worker(): void
    {
        $proc = $this->processConfig();

        $this->assertArrayHasKey('similarity', $proc, 'config/process.php must register a similarity worker.');
        $this->assertTrue($proc['similarity']['enabled'] ?? false, 'The similarity worker must be enabled.');
        $this->assertArrayHasKey('count', $proc['similarity']);
        $this->assertArrayHasKey('poll_seconds', $proc['similarity']);
    }

    public function test_process_config_registers_the_media_asset_worker(): void
    {
        // Regression guard: the media-asset entry was enabled but had no spawner.
        $proc = $this->processConfig();

        $this->assertArrayHasKey('media-asset', $proc);
        $this->assertTrue($proc['media-asset']['enabled'] ?? false);
    }

    public function test_process_config_registers_the_marker_detection_worker(): void
    {
        // SV-0.7 regression guard: the marker/intro-detection worker drains the
        // file-based job queue of shows needing intro/outro detection. The generic
        // test_every_enabled_process_entry… guard SKIPS disabled entries, so a
        // regression flipping enabled=false (the exact "queue never drains" bug
        // SV-0.7 fixed) would go uncaught without this dedicated assertion.
        $proc = $this->processConfig();

        $this->assertArrayHasKey(
            'marker-detection',
            $proc,
            'config/process.php must register a marker-detection worker.'
        );
        $this->assertTrue(
            $proc['marker-detection']['enabled'] ?? false,
            'The marker-detection worker must be enabled or intro-detection jobs never drain.'
        );
        $this->assertArrayHasKey('count', $proc['marker-detection']);
        $this->assertArrayHasKey('poll_seconds', $proc['marker-detection']);
    }

    public function test_marker_detection_worker_is_in_the_managed_worker_map(): void
    {
        $map = $this->managedWorkers();

        $this->assertArrayHasKey('marker-detection', $map);
        $this->assertSame(
            \Phlix\Media\Markers\Detection\BackgroundDetectorWorker::class,
            $map['marker-detection']
        );
    }

    public function test_marker_detection_poll_seconds_matches_worker_interval(): void
    {
        // Coherence guard: config/process.php pins poll_seconds to
        // config/marker_detection.php worker_interval "in a comment" — assert the
        // invariant so the two configs cannot silently drift apart.
        $proc = $this->processConfig();
        $markerCfg = $this->markerDetectionConfig();

        $pollSeconds = $proc['marker-detection']['poll_seconds'] ?? null;
        $workerInterval = $markerCfg['worker_interval'] ?? null;

        $this->assertNotNull($pollSeconds, 'marker-detection must define poll_seconds.');
        $this->assertSame(
            $workerInterval,
            $pollSeconds,
            'process.php marker-detection poll_seconds must equal marker_detection.php '
            . 'worker_interval (the config comment pins this invariant).'
        );
    }

    public function test_similarity_jobs_config_has_expected_shape(): void
    {
        $cfg = $this->similarityJobsConfig();

        $this->assertArrayHasKey('job_queue_dir', $cfg);
        $this->assertIsString($cfg['job_queue_dir']);
        $this->assertNotSame('', $cfg['job_queue_dir']);
        $this->assertArrayHasKey('worker_interval', $cfg);
        $this->assertIsInt($cfg['worker_interval']);
        $this->assertArrayHasKey('max_concurrent', $cfg);
        $this->assertIsInt($cfg['max_concurrent']);
        $this->assertGreaterThan(0, $cfg['max_concurrent']);
    }

    public function test_similarity_worker_is_in_the_managed_worker_map(): void
    {
        $map = $this->managedWorkers();

        $this->assertArrayHasKey('similarity', $map);
        $this->assertSame(\Phlix\Media\SimilarityWorker::class, $map['similarity']);
    }

    public function test_every_enabled_process_entry_has_a_managed_worker_class(): void
    {
        $proc = $this->processConfig();
        $map = $this->managedWorkers();

        foreach ($proc as $key => $settings) {
            if (($settings['enabled'] ?? false) !== true) {
                continue;
            }
            $this->assertArrayHasKey(
                $key,
                $map,
                sprintf(
                    'config/process.php enables "%s" but config/managed_workers.php has no spawner '
                    . 'for it — start.php would never drain its queue (disk leak).',
                    $key
                )
            );
        }
    }

    /**
     * S96(c): `library-scan` MUST stay `count: 1`.
     *
     * {@see \Phlix\Media\Library\LibraryScanWorker::start()} calls
     * {@see \Phlix\Media\Library\ScanJobRepository::reapStaleJobs()}, which fails EVERY
     * `running` row in `library_scan_jobs` — no `library_id` filter, no age guard. That
     * is correct for a single consumer (a row left `running` by a crash would otherwise
     * spin the scan UI forever, which is the music-scan-hang incident) and WRONG the
     * moment a second consumer exists: `start.php` calls `start()` once per fork, so
     * `count: 2` has fork #2 fail fork #1's just-claimed job while its scan keeps
     * running, silently, because nothing re-reads the job row mid-scan.
     *
     * The invariant used to live only in a code comment — and `config/process.php`
     * actively asserted the opposite ("Running both run paths at once is SAFE"),
     * reasoning from `claimNext()`'s atomicity, which says nothing about the reaper.
     * This test is what makes raising the count fail out loud instead of quietly
     * killing live scans.
     */
    public function testLibraryScanCountMustStayOneForTheUnscopedReaper(): void
    {
        $proc = $this->processConfig();

        $this->assertArrayHasKey('library-scan', $proc);
        $this->assertSame(
            1,
            $proc['library-scan']['count'] ?? null,
            'library-scan must be count:1. LibraryScanWorker::start() reaps EVERY running scan job at '
            . 'startup, so a second consumer fails the first one\'s in-flight job. Bounding that safely '
            . 'needs per-job worker ownership (an owner id / heartbeat column), not a bigger count.',
        );
    }

    /**
     * S96(c) — review r1 HIGH-1: the STANDALONE spawner must not advertise the very
     * thing the invariant forbids.
     *
     * `count: 1` is enforced by the test above, and both spawners read that same
     * config key — but the OTHER half of the invariant ("only one consumer of this
     * queue at a time") is enforced by documentation alone, because bounding the
     * reaper properly needs per-job worker ownership (an owner id / heartbeat column;
     * `library_scan_jobs` has no `updated_at`, so "no progress in N minutes" is not
     * even expressible today). Documentation-as-mitigation only works if every
     * document agrees: `scripts/run-library-scan-worker.php` is the one an operator
     * reads immediately BEFORE starting a second consumer, and it used to state that
     * doing so "is safe" — reasoning from `claimNext()`'s atomicity, which says
     * nothing about the unscoped reaper `start()` runs one line later.
     *
     * The needles below are the two RETRACTED claims, quoted here only so this test
     * can detect their return.
     */
    public function testTheStandaloneScanWorkerScriptForbidsASecondConsumer(): void
    {
        $root = dirname(__DIR__, 3);
        $paths = [
            'scripts/run-library-scan-worker.php',
            'config/process.php',
        ];

        // The exact wording each file used to carry, and must never carry again.
        $retracted = ['at the same time is safe', 'at once is SAFE'];

        foreach ($paths as $relative) {
            $file = $root . '/' . $relative;
            $this->assertFileExists($file);
            $contents = (string) file_get_contents($file);

            foreach ($retracted as $claim) {
                $this->assertStringNotContainsString(
                    $claim,
                    $contents,
                    sprintf(
                        '%s must not claim that running both scan-worker spawners concurrently is safe. '
                        . 'LibraryScanWorker::start() reaps EVERY running job with no age guard and no '
                        . 'library_id filter, so the second consumer to boot stamps the first one\'s '
                        . 'in-flight job `failed` while its scan carries on unaware.',
                        $relative
                    )
                );
            }

            $this->assertStringContainsString(
                'reapStaleJobs',
                $contents,
                $relative . ' must name the reaper as the reason a second consumer is unsafe, not just '
                . 'say "do not do this" — the previous wording was wrong precisely because it reasoned '
                . 'from claimNext() instead.'
            );
        }
    }

    public function test_managed_worker_classes_exist_and_expose_start_int(): void
    {
        foreach ($this->managedWorkers() as $key => $class) {
            $this->assertTrue(class_exists($class), sprintf('Managed worker class for "%s" (%s) must exist.', $key, $class));
            $this->assertTrue(
                method_exists($class, 'start'),
                sprintf('Managed worker %s must expose start() so start.php can arm its Timer.', $class)
            );

            $method = new \ReflectionMethod($class, 'start');
            $params = $method->getParameters();
            $this->assertNotEmpty($params, sprintf('%s::start() must accept a poll-interval argument.', $class));
            $this->assertSame(
                'int',
                (string) $params[0]->getType(),
                sprintf('%s::start() first parameter must be an int poll interval.', $class)
            );
        }
    }
}
