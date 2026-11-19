<?php

/**
 * Phlix media server component: Media.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Support;

use Phlix\Media\CollectionJob;
use Phlix\Media\CollectionJobStore;
use Phlix\Media\Metadata\Writer\MetadataWriteJob;
use Phlix\Media\Metadata\Writer\MetadataWriteJobStore;
use Phlix\Media\SimilarityJob;
use Phlix\Media\SimilarityJobStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * D-10 format contract: the on-disk byte grammar shared by the three JSON job
 * stores (SimilarityJobStore, CollectionJobStore, MetadataWriteJobStore).
 *
 * These tests were written BEFORE the family was folded onto one base class
 * (Phlix\Media\Support\JsonJobStore) and pass unchanged against both the
 * standalone clones and the folded base — that is the whole point: the fold is
 * falsifiable byte-per-byte, and a file written by either generation must drain
 * through the other. The legacy-bytes legs therefore write with a verbatim copy
 * of the pre-fold clone writer, never through store code.
 *
 * Grammar pinned (read off the clones' file_put_contents line):
 *   file name  : {queueDir}/{preg_replace('/[^a-zA-Z0-9_-]/','_',itemId)}.job.json
 *   payload    : json_encode($job->toArray(), JSON_THROW_ON_ERROR) . "\n" .
 *                (string)microtime(true) . "\n"
 *   write      : file_put_contents(..., LOCK_EX); enqueue is idempotent per
 *                item id and NEVER rewrites an existing payload
 *   drain      : lowest line-2 float first (content time, not name, not mtime);
 *                the file is REMOVED before the payload is parsed; empty or
 *                corrupt first line dequeues as null after the drop
 *   eviction   : at count >= MAX_QUEUE_SIZE the enqueue first drops the
 *                LEXICOGRAPHICALLY first existing file (sort() order, list
 *                taken before the newcomer is written — not the content-oldest),
 *                then writes
 *
 * File modes: the family never chmods payload files (no 0600 anywhere —
 * grep-verified at lane start), so no mode is asserted; umask default applies.
 */
final class JsonJobStoreFormatTest extends TestCase
{
    /** @var list<string> temp queue dirs swept in tearDown */
    private array $tmpQueues = [];

    /** The trio union — the exact surface the fold must keep identical. */
    private const STORE_SIMILARITY = SimilarityJobStore::class;
    private const STORE_COLLECTION = CollectionJobStore::class;
    private const STORE_METADATA_WRITE = MetadataWriteJobStore::class;

    protected function tearDown(): void
    {
        foreach ($this->tmpQueues as $dir) {
            if (is_dir($dir)) {
                foreach ((array) glob($dir . '/*') as $file) {
                    if (is_string($file) && is_file($file)) {
                        @unlink($file);
                    }
                }
                @rmdir($dir);
            }
        }
        $this->tmpQueues = [];
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: class-string}>
     */
    public static function storeClassProvider(): array
    {
        return [
            'similarity' => [self::STORE_SIMILARITY],
            'collection' => [self::STORE_COLLECTION],
            'metadata_write' => [self::STORE_METADATA_WRITE],
        ];
    }

    private function makeStore(string $class): SimilarityJobStore|CollectionJobStore|MetadataWriteJobStore
    {
        $dir = sys_get_temp_dir() . '/phlix_d10_' . bin2hex(random_bytes(6));
        $this->tmpQueues[] = $dir;

        if ($class === self::STORE_SIMILARITY) {
            return new SimilarityJobStore($dir);
        }
        if ($class === self::STORE_COLLECTION) {
            return new CollectionJobStore($dir);
        }

        return new MetadataWriteJobStore($dir);
    }

    private function dirOf(SimilarityJobStore|CollectionJobStore|MetadataWriteJobStore $store): string
    {
        if ($store instanceof SimilarityJobStore) {
            // Similarity has no public getQueueDir() (unlike its two siblings)
            // — the fold must not silently GROW its public surface.
            $property = new \ReflectionProperty($store, 'queueDir');

            return (string) $property->getValue($store);
        }

        return $store->getQueueDir();
    }

    private function enqueueFor(
        SimilarityJobStore|CollectionJobStore|MetadataWriteJobStore $store,
        string $itemId
    ): void {
        if ($store instanceof SimilarityJobStore) {
            $store->enqueue(new SimilarityJob($itemId, 'lib-1'));

            return;
        }
        if ($store instanceof CollectionJobStore) {
            $store->enqueue(new CollectionJob($itemId));

            return;
        }

        $store->enqueue(new MetadataWriteJob($itemId, 'lib-1'));
    }

    /**
     * @return array<string, mixed>|null the drained job as its raw payload array
     */
    private function dequeueFor(
        SimilarityJobStore|CollectionJobStore|MetadataWriteJobStore $store
    ): ?array {
        $job = null;

        if ($store instanceof SimilarityJobStore) {
            $job = $store->dequeue();
        } elseif ($store instanceof CollectionJobStore) {
            $job = $store->dequeue();
        } else {
            $job = $store->dequeue();
        }

        return $job?->toArray();
    }

    private function soleJobFile(string $dir): string
    {
        $files = (array) glob($dir . '/*.job.json');
        $this->assertCount(1, $files, 'exactly one payload file on disk');

        return (string) $files[0];
    }

    private function maxQueueSizeOf(SimilarityJobStore|CollectionJobStore|MetadataWriteJobStore $store): int
    {
        $constant = (new \ReflectionClass($store))->getReflectionConstant('MAX_QUEUE_SIZE');
        $this->assertNotFalse($constant, 'each store keeps its own private MAX_QUEUE_SIZE');

        return (int) $constant->getValue();
    }

    /**
     * Verbatim copy of the PRE-FOLD clone writer (the trio shipped this exact
     * expression); used only to mint legacy bytes for the read-compat legs.
     *
     * @param array<string, mixed> $payload
     */
    private function writeLegacyJobFile(string $dir, string $fileName, array $payload, float $timestamp): void
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        file_put_contents($dir . '/' . $fileName, $json . "\n" . $timestamp . "\n", LOCK_EX);
    }

    #[DataProvider('storeClassProvider')]
    public function testEnqueueWritesThePinnedPayloadGrammar(string $class): void
    {
        $store = $this->makeStore($class);
        $dir = $this->dirOf($store);

        $this->enqueueFor($store, 'alpha');

        $bytes = (string) file_get_contents($this->soleJobFile($dir));

        $this->assertStringEndsWith("\n", $bytes, 'payload ends with a trailing newline');

        $lines = explode("\n", $bytes);
        $this->assertCount(3, $lines, 'two lines plus the empty tail after the final \n');
        $this->assertSame('', $lines[2]);

        $expectedPayload = $class === self::STORE_COLLECTION
            ? ['item_id' => 'alpha']
            : ['item_id' => 'alpha', 'library_id' => 'lib-1'];

        $this->assertSame(
            json_encode($expectedPayload, JSON_THROW_ON_ERROR),
            $lines[0],
            'line 1 is the byte-exact JSON payload — the identity anchor for the fold'
        );
        $this->assertMatchesRegularExpression('/^\d+\.\d+$/', $lines[1], 'line 2 is a decimal float string');
        $this->assertEqualsWithDelta(microtime(true), (float) $lines[1], 10.0, 'line 2 is the enqueue wall time');
    }

    #[DataProvider('storeClassProvider')]
    public function testFileNameIsSanitizedItemIdInsideTheQueueDir(string $class): void
    {
        $store = $this->makeStore($class);
        $dir = $this->dirOf($store);

        $this->enqueueFor($store, "../../etc/passwd\x00w3");

        // Every byte outside [a-zA-Z0-9_-] becomes '_': the two traversal pairs
        // and their slashes collapse to six underscores, the NUL byte and the
        // slash before w3 each to one. Pinned as a literal, NOT through the
        // production regex — a re-implementation here would drift unnoticed.
        $this->assertFileExists($dir . '/______etc_passwd_w3.job.json');
        $this->assertSame([$dir . '/______etc_passwd_w3.job.json'], (array) glob($dir . '/*.job.json'));
    }

    #[DataProvider('storeClassProvider')]
    public function testReEnqueueNeverRewritesThePayloadBytes(string $class): void
    {
        $store = $this->makeStore($class);
        $dir = $this->dirOf($store);

        $this->enqueueFor($store, 'alpha');
        $path = $this->soleJobFile($dir);
        $beforeBytes = (string) file_get_contents($path);
        clearstatcache(true, $path);
        $beforeMtime = (int) filemtime($path);

        // One full second so any rewrite would move mtime past second granularity.
        usleep(1200000);
        $this->enqueueFor($store, 'alpha');

        $this->assertSame($beforeBytes, (string) file_get_contents($path), 'bytes untouched');
        clearstatcache(true, $path);
        $this->assertSame($beforeMtime, (int) filemtime($path), 'mtime untouched — enqueue returned early');
        $this->assertSame(1, $store->queueSize());
    }

    #[DataProvider('storeClassProvider')]
    public function testLegacyCloneBytesDrainThroughTheStore(string $class): void
    {
        $store = $this->makeStore($class);
        $dir = $this->dirOf($store);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $payload = $class === self::STORE_COLLECTION
            ? ['item_id' => 'legacy-one']
            : ['item_id' => 'legacy-one', 'library_id' => 'lib-1'];

        $this->writeLegacyJobFile($dir, 'legacy-one.job.json', $payload, microtime(true));

        $restored = $this->dequeueFor($store);
        $this->assertNotNull($restored, 'legacy bytes must be readable by this generation');
        $this->assertSame($payload, $restored);
        $this->assertFileDoesNotExist($dir . '/legacy-one.job.json', 'drain removes the file');
    }

    /**
     * New-writer bytes must satisfy the LEGACY reader algorithm too. The reader
     * is re-implemented here verbatim from the pre-fold clone dequeue leg
     * (read, trim, explode, lines[0], json_decode, fromArray) so both
     * directions of the compatibility claim are proven inside one test.
     */
    #[DataProvider('storeClassProvider')]
    public function testCurrentWriterBytesParseThroughTheLegacyReaderAlgorithm(string $class): void
    {
        $store = $this->makeStore($class);
        $dir = $this->dirOf($store);

        $this->enqueueFor($store, 'alpha');

        $content = file_get_contents($this->soleJobFile($dir));
        $this->assertIsString($content);

        // Verbatim pre-fold reader leg.
        $lines = explode("\n", trim($content));
        $json = $lines[0] ?? '';
        $this->assertNotSame('', $json);
        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $legacyParsed = $class === self::STORE_COLLECTION
            ? CollectionJob::fromArray($data)
            : ($class === self::STORE_SIMILARITY
                ? SimilarityJob::fromArray($data)
                : MetadataWriteJob::fromArray($data));

        $expectedPayload = $class === self::STORE_COLLECTION
            ? ['item_id' => 'alpha']
            : ['item_id' => 'alpha', 'library_id' => 'lib-1'];
        $this->assertSame($expectedPayload, $legacyParsed->toArray());
    }

    #[DataProvider('storeClassProvider')]
    public function testDequeueOrdersByContentTimestampNotByFileName(string $class): void
    {
        $store = $this->makeStore($class);

        // 'zzz' first in time, 'aaa' later — name order would drain aaa first.
        $this->enqueueFor($store, 'zzz');
        usleep(1500000);
        $this->enqueueFor($store, 'aaa');

        $first = $this->dequeueFor($store);
        $second = $this->dequeueFor($store);
        $this->assertSame('zzz', $first['item_id'] ?? null, 'FIFO is the in-file timestamp line');
        $this->assertSame('aaa', $second['item_id'] ?? null);
        $this->assertNull($this->dequeueFor($store), 'drained queue yields null');
        $this->assertSame(0, $store->queueSize());
    }

    #[DataProvider('storeClassProvider')]
    public function testCorruptFirstLineDequeuesNullAndIsDropped(string $class): void
    {
        $store = $this->makeStore($class);
        $dir = $this->dirOf($store);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($dir . '/broken.job.json', "not-json\n123\n", LOCK_EX);

        $this->assertNull($this->dequeueFor($store), 'corrupt entry reads as an empty dequeue');
        $this->assertFileDoesNotExist($dir . '/broken.job.json', 'the corrupt file is dropped');
    }

    #[DataProvider('storeClassProvider')]
    public function testEmptyPayloadLineDequeuesNullAndIsDropped(string $class): void
    {
        $store = $this->makeStore($class);
        $dir = $this->dirOf($store);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($dir . '/emptyone.job.json', "\n456\n", LOCK_EX);

        $this->assertNull($this->dequeueFor($store));
        $this->assertFileDoesNotExist($dir . '/emptyone.job.json');
    }

    #[DataProvider('storeClassProvider')]
    public function testEvictionAtBoundDropsTheLexicographicallyFirstFile(string $class): void
    {
        $store = $this->makeStore($class);
        $dir = $this->dirOf($store);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $bound = $this->maxQueueSizeOf($store);

        // Fill to exactly the bound with legacy-grammar files named job-NNNN.
        // The eviction list is taken BEFORE the newcomer is written, so the
        // dropped file is the sorted-first job-0000 — this pins the real
        // semantics (name-sorted, pre-write), not the intuitive-but-wrong one.
        $ts = 1000000.0;
        for ($i = 0; $i < $bound; $i++) {
            $id = sprintf('job-%04d', $i);
            $payload = $class === self::STORE_COLLECTION
                ? ['item_id' => $id]
                : ['item_id' => $id, 'library_id' => 'lib-1'];
            $this->writeLegacyJobFile($dir, $id . '.job.json', $payload, $ts);
            $ts += 0.001;
        }
        $this->assertSame($bound, $store->queueSize());

        $this->enqueueFor($store, 'alpha');

        $this->assertCount($bound, (array) glob($dir . '/*.job.json'), 'exactly one eviction fired');
        $this->assertFileDoesNotExist($dir . '/job-0000.job.json', 'lexicographically first dropped');
        $this->assertFileExists($dir . '/alpha.job.json', 'newcomer written after the evict');
        $this->assertFileExists($dir . '/job-0001.job.json', 'only one file dropped');
    }

    public function testPendingItemIdsOrderAndCorruptSkips(): void
    {
        foreach ([self::STORE_SIMILARITY, self::STORE_COLLECTION] as $class) {
            $store = $this->makeStore($class);
            $dir = $this->dirOf($store);
            $this->enqueueFor($store, 'beta');
            usleep(1500);
            $this->enqueueFor($store, 'gamma');
            file_put_contents($dir . '/broken.job.json', "not-json\n1.5\n", LOCK_EX);

            // The corrupt file carries ts 1.5 so it sorts FIRST, but pending
            // ids skip it without going fatal.
            $this->assertSame(
                ['beta', 'gamma'],
                $this->pendingIdsOf($store),
                'FIFO by content time, corrupt skipped — ' . $class
            );
        }
    }

    private function pendingIdsOf(SimilarityJobStore|CollectionJobStore|MetadataWriteJobStore $store): array
    {
        if ($store instanceof SimilarityJobStore) {
            return $store->getPendingItemIds();
        }
        if ($store instanceof CollectionJobStore) {
            return $store->getPendingItemIds();
        }

        $this->fail('MetadataWriteJobStore has no pending-ids surface (D-10 divergence pin).');
    }
}
