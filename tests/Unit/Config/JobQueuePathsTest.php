<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Config;

use InvalidArgumentException;
use Phlix\Config\JobQueuePaths;
use Phlix\Media\CollectionJobStore;
use Phlix\Media\Markers\Detection\MarkerCandidateStore;
use Phlix\Media\MediaAsset\MediaAssetJobStore;
use Phlix\Media\Metadata\Writer\MetadataWriteJobStore;
use Phlix\Media\SimilarityJobStore;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * R14-14: the five file-backed job queues must resolve through exactly one
 * source — JobQueuePaths — with the repo var/jobs/ default (surviving systemd
 * PrivateTmp) and env overrides (per-queue > root > default).
 *
 * Guards the three former duplication sites (config literal, provider
 * fallback, store constructor default) against drifting apart again, and
 * pins that the default is NOT under /tmp any more.
 *
 * @since 1.4.0
 */
final class JobQueuePathsTest extends TestCase
{
    /** @var list<string> Env keys this suite mutates (restored in tearDown). */
    private const TOUCHED_ENV = [
        JobQueuePaths::ROOT_ENV,
        'PHLIX_MARKER_JOBS_DIR',
        'PHLIX_MEDIA_ASSET_JOBS_DIR',
        'PHLIX_COLLECTION_JOBS_DIR',
        'PHLIX_METADATA_WRITE_JOBS_DIR',
        'PHLIX_SIMILARITY_JOBS_DIR',
    ];

    /** @var array<string, string|false> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        foreach (self::TOUCHED_ENV as $key) {
            $this->envBackup[$key] = getenv($key);
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
        $this->envBackup = [];
        $this->sweepMintedDirs();
    }

    /**
     * Three store constructors mkdir at the default path; the repo dir is
     * gitignored, but leave zero residue inside the suite run anyway.
     */
    private function sweepMintedDirs(): void
    {
        foreach (['marker', 'media_asset', 'similarity'] as $name) {
            $dir = JobQueuePaths::defaultRoot() . '/' . $name;
            if (is_dir($dir)) {
                foreach (glob($dir . '/*') ?: [] as $entry) {
                    @unlink($entry);
                }
                @rmdir($dir);
            }
        }
        @rmdir(JobQueuePaths::defaultRoot());
    }

    public function test_default_lands_under_repo_var_jobs_and_never_tmp(): void
    {
        $repoRoot = dirname(__DIR__, 3);
        $resolved = JobQueuePaths::dir('marker');

        $this->assertSame($repoRoot . '/var/jobs/marker', $resolved);
        $this->assertStringNotContainsString('/tmp', $resolved);
        $this->assertStringStartsWith(JobQueuePaths::defaultRoot() . '/', $resolved);
    }

    public function test_per_queue_env_beats_root_env(): void
    {
        putenv('PHLIX_JOB_QUEUE_ROOT=/srv/queues');
        putenv('PHLIX_MARKER_JOBS_DIR=/srv/special/marker');

        $this->assertSame('/srv/special/marker', JobQueuePaths::dir('marker'));
        $this->assertSame('/srv/queues/collection', JobQueuePaths::dir('collection'));
    }

    public function test_root_env_places_every_queue_under_it(): void
    {
        putenv('PHLIX_JOB_QUEUE_ROOT=/srv/queues');

        foreach (['marker', 'media_asset', 'collection', 'metadata_write', 'similarity'] as $name) {
            $this->assertSame('/srv/queues/' . $name, JobQueuePaths::dir($name));
        }
    }

    public function test_empty_and_whitespace_env_values_count_as_unset(): void
    {
        putenv('PHLIX_JOB_QUEUE_ROOT=');
        putenv('PHLIX_MARKER_JOBS_DIR=   ');

        $repoRoot = dirname(__DIR__, 3);
        $this->assertSame($repoRoot . '/var/jobs/marker', JobQueuePaths::dir('marker'));
        $this->assertSame($repoRoot . '/var/jobs/collection', JobQueuePaths::dir('collection'));
    }

    public function test_trailing_slashes_are_normalized_without_doubling(): void
    {
        putenv('PHLIX_JOB_QUEUE_ROOT=/srv/queues///');
        putenv('PHLIX_MEDIA_ASSET_JOBS_DIR=/srv/asset/');

        // Per-queue override: trailing slash stripped, no doubled separator.
        $this->assertSame('/srv/asset', JobQueuePaths::dir('media_asset'));

        // Root override: exactly one separator between root and queue name.
        $this->assertSame('/srv/queues/collection', JobQueuePaths::dir('collection'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidNameProvider(): array
    {
        return [
            'empty' => [''],
            'uppercase' => ['Marker'],
            'dash' => ['bad-name'],
            'path-separator' => ['a/b'],
            'traversal' => ['marker/../evil'],
            'space' => ['two words'],
        ];
    }

    /**
     * @dataProvider invalidNameProvider
     */
    public function test_path_unsafe_names_fail_fast(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must match \[a-z0-9_\]\+/');

        JobQueuePaths::dir($name);
    }

    public function test_configs_provider_fallbacks_and_store_defaults_all_agree(): void
    {
        $expectations = [
            'marker' => [
                'config' => __DIR__ . '/../../../config/marker_detection.php',
                'store' => new MarkerCandidateStore(),
            ],
            'media_asset' => [
                'config' => __DIR__ . '/../../../config/media_asset_jobs.php',
                'store' => new MediaAssetJobStore(),
            ],
            'collection' => [
                'config' => __DIR__ . '/../../../config/collection_jobs.php',
                'store' => new CollectionJobStore(),
            ],
            'metadata_write' => [
                'config' => __DIR__ . '/../../../config/metadata_write_jobs.php',
                'store' => new MetadataWriteJobStore(),
            ],
            'similarity' => [
                'config' => __DIR__ . '/../../../config/similarity_jobs.php',
                'store' => new SimilarityJobStore(),
            ],
        ];

        foreach ($expectations as $name => $pair) {
            /** @var array{job_queue_dir: string} $cfg */
            $cfg = require $pair['config'];

            $this->assertSame(
                JobQueuePaths::dir($name),
                $cfg['job_queue_dir'],
                "config job_queue_dir for '{$name}' must equal the resolver"
            );

            $property = new ReflectionProperty($pair['store'], 'queueDir');
            $property->setAccessible(true);

            $this->assertSame(
                JobQueuePaths::dir($name),
                $property->getValue($pair['store']),
                "store constructor default for '{$name}' must equal the resolver"
            );
        }
    }

    public function test_explicit_constructor_dir_still_wins(): void
    {
        $store = new CollectionJobStore('/custom/queue');

        $this->assertSame('/custom/queue', $store->getQueueDir());
    }
}
