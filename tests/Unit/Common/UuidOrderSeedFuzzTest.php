<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common;

use PHPUnit\Framework\TestCase;
use Phlix\Common\Uuid;

/**
 * S443 — the order-seed fuzz that pins `Uuid::v4()`'s entropy source.
 *
 * ## The hazard this test exists for
 *
 * `Uuid::v4()` used to mint all 122 "random" bits from `mt_rand()` — the
 * process-global Mersenne Twister. THREE independent actors drive that one
 * stream in a test process:
 *
 *  1. PHPUnit's own runner: `phpunit.xml` sets `executionOrder="random"`, and
 *     `vendor/phpunit/phpunit/src/TextUI/TestRunner.php:36` calls
 *     `mt_srand($configuration->randomOrderSeed())` before the run — every run,
 *     seeded with `time()` unless `--random-order-seed` pins it
 *     (`Merger.php:708-710`).
 *  2. The order shuffle itself (`TestSuiteSorter.php:178`, `shuffle()` — which
 *     since PHP 7.1 draws from the same MT engine), so the mint offsets of a
 *     replayed run land at exactly the same stream positions.
 *  3. Tests that re-pin the stream mid-run on purpose:
 *     `WebhookServiceTest` seeds 20260713 for jitter determinism,
 *     `BrowseIndexUsageTest` and `FixtureIdGeneratorTest` seed 4242 to prove
 *     their OWN fixture generator is not steerable. After any of them, the
 *     global stream is back at a KNOWN position.
 *
 * Consequence: a run with a pinned `--random-order-seed` (the documented way to
 * reproduce an order-dependent failure) replays a byte-identical sequence of
 * `Uuid::v4()` ids. Two runs at the same seed — or one run where a seeding test
 * sits between two id-minting consumers — mint the SAME `CHAR(36)` primary key
 * twice, and the second INSERT dies with MySQL 1062 `Duplicate entry … for key
 * 'PRIMARY'` on `media_items` (the Music scanner mints through
 * `MusicLibraryScanner::createMediaItem()` → `Uuid::v4()`). That is the s252
 * run-1 intermittent red: green standalone, red under an adversarial ordering.
 *
 * ## What "deterministically reds/greens" means here (the S443 AC)
 *
 * No randomness is sampled by this test. Each case pins `mt_srand()` to a
 * constant, mints a batch, RE-pins to the same constant, and mints again. Under
 * the old `mt_rand()` implementation the two batches are element-for-element
 * identical — every assertion below REDS deterministically. Under the CSPRNG
 * implementation `random_bytes()` is not steerable by `mt_srand()` at all, so
 * the batches cannot overlap — every assertion GREENS deterministically. The
 * same-seed replay is the mutation: reverting the entropy source reddens this
 * named test on the first run, no flake, no MySQL required.
 *
 * @see \Phlix\Tests\Integration\Media\Music\UuidSeedReplayPkCollisionIntegrationTest
 *      for the same hazard proven end-to-end against a real MySQL PRIMARY key.
 */
final class UuidOrderSeedFuzzTest extends TestCase
{
    /** Code-resident lane token (S443). */
    public const ENTROPY_GUARD_TOKEN = 'S443ENTROPYGATEX6W1';

    /**
     * The seeds the estate actually pins mid-run (4242 from BrowseIndexUsage /
     * FixtureIdGenerator, 20260713 from WebhookService) plus the seed the S111
     * investigation used. Hard constants — the test must never sample order
     * or entropy itself.
     *
     * @var list<int>
     */
    private const ADVERSARIAL_SEEDS = [4242, 20260713, 424242];

    /** Ids minted per batch. 300 mirrors the S111 probe's window. */
    private const BATCH_SIZE = 300;

    protected function tearDown(): void
    {
        // Do not leak a pinned stream into the rest of the suite: restore the
        // MT to non-steerable-by-constant state (fresh seed from the CSPRNG —
        // exactly what an unseeded PHP process starts with).
        mt_srand(random_int(0, mt_getrandmax()));

        parent::tearDown();
    }

    /**
     * (1) The replay half: re-pinning the SAME seed must not reproduce any id.
     * Element-for-element this is exactly what the s252 same-seed reruns did to
     * the shared `phlix_test` database.
     */
    public function test_replaying_the_mt_srand_seed_reproduces_no_uuid(): void
    {
        foreach (self::ADVERSARIAL_SEEDS as $seed) {
            mt_srand($seed);
            $firstRun = $this->mintBatch();

            mt_srand($seed);
            $replay = $this->mintBatch();

            $overlap = array_values(array_intersect($firstRun, $replay));

            $this->assertSame(
                [],
                $overlap,
                sprintf(
                    'mt_srand(%d) steered %d/%d Uuid::v4() ids on replay — a same-seed '
                    . 'rerun (or a seeding test between two minting consumers) mints '
                    . 'byte-identical primary keys, i.e. the Music 1062 dup-PK hazard (S443). '
                    . 'First replayed id: %s',
                    $seed,
                    count($overlap),
                    self::BATCH_SIZE,
                    $overlap[0] ?? '(none)',
                ),
            );
        }
    }

    /**
     * (2) The consumer-interleaving half: two id-minting consumers separated by
     * a mid-run re-pin start from the SAME stream offset, so their ids collide
     * pairwise at index 0 — the shape of two integration classes minting into
     * one table inside one run.
     */
    public function test_seed_reset_between_two_minting_consumers_cannot_collide(): void
    {
        mt_srand(self::ADVERSARIAL_SEEDS[0]);

        $consumerA = $this->mintBatch();   // e.g. an earlier Music scan class

        // A seeding test runs between them (BrowseIndexUsageTest pins 4242 and
        // leaves the stream there; WebhookServiceTest pins 20260713 the same
        // way). Either way the next consumer starts at a KNOWN offset.
        mt_srand(self::ADVERSARIAL_SEEDS[0]);

        $consumerB = $this->mintBatch();   // e.g. the next Music scan class

        $collisions = array_values(array_intersect($consumerA, $consumerB));

        $this->assertSame(
            [],
            $collisions,
            'two consumers minting across a mid-run mt_srand() re-pin replayed identical '
            . 'Uuid::v4() ids — inserting both sets into one CHAR(36)-PK table raises '
            . 'MySQL 1062 Duplicate entry for key \'PRIMARY\' (the s252 run-1 red, S443).',
        );
    }

    /**
     * (3) The batch itself must be duplicate-free in every scenario — a
     * same-process stream cannot repeat a 122-bit draw within 300 calls even
     * under the old implementation, so this stays green on the mutant and pins
     * the counter-free uniqueness the CSPRNG mint provides unaided.
     */
    public function test_batch_of_uuids_is_duplicate_free_under_a_pinned_seed(): void
    {
        mt_srand(self::ADVERSARIAL_SEEDS[1]);
        $ids = $this->mintBatch();

        $this->assertCount(
            $ids === [] ? 0 : count(array_unique($ids)),
            $ids,
            'Uuid::v4() minted a duplicate within one pinned-seed batch — '
            . 'intra-run uniqueness is structural, not probabilistic.',
        );
    }

    /**
     * (4) The fix must not over-reach into FORMAT: RFC 4122 v4 shape is pinned
     * byte-for-byte in place (version nibble '4', variant nibble [89ab]) —
     * the exact contract every CHAR(36) PK column and the old
     * per-class generateUuid() relied on.
     */
    public function test_uuid_v4_keeps_the_rfc4122_shape_under_a_pinned_seed(): void
    {
        mt_srand(self::ADVERSARIAL_SEEDS[2]);

        foreach ($this->mintBatch() as $id) {
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
                $id,
                'the entropy-source fix must not change the minted shape (S443).',
            );
        }
    }

    /**
     * @return list<string>
     */
    private function mintBatch(): array
    {
        $ids = [];

        for ($i = 0; $i < self::BATCH_SIZE; $i++) {
            $ids[] = Uuid::v4();
        }

        return $ids;
    }
}
