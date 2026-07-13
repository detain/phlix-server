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
