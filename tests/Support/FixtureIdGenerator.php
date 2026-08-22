<?php

declare(strict_types=1);

namespace Phlix\Tests\Support;

/**
 * The S111 fixture-id generator, extracted from BrowseIndexUsageTest so a
 * unit-level test can probe the REAL generator directly — S334's Residual A
 * must demonstrate, without a MySQL server, that the S111 pin's assertion (2)
 * (satisfiable by the counter alone) is backed by a genuinely varying CSPRNG
 * half.
 *
 * A `CHAR(36)` fixture id that is unique per ROW and per RUN, drawn from the
 * CSPRNG and a counter — never from `mt_rand()` (S111).
 *
 * PHPUnit's `--random-order-seed` calls `mt_srand()`, which is the standard way
 * to reproduce an order-dependent failure. The previous implementations of this
 * helper — and `ItemRepository`'s `Uuid::v4()` fallback, which used to supply
 * the `media_items` ids because the fixture passed none — were both
 * `mt_rand()`-based, so a pinned seed made every fixture id in the process
 * byte-for-byte reproducible. Any row surviving an earlier same-seed run then
 * collided with `Duplicate entry '…' for key 'media_items.PRIMARY'`, i.e. the
 * debugging tool itself was unusable on that class.
 *
 * Two independent sources, so uniqueness does not rest on either alone:
 *  - a run-unique prefix from `random_bytes()`, which `mt_srand()` cannot steer;
 *  - a per-row monotonic counter in the final field, which makes intra-run
 *    uniqueness provable rather than merely probable.
 *
 * The output keeps the v4/variant-8 nibbles so it is shaped like every other id
 * in the schema.
 */
final class FixtureIdGenerator
{
    /**
     * Monotonic per-row counter (S111) — static so it keeps counting across
     * every `setUp()` in the process, not just within one test.
     */
    private static int $seq = 0;

    public static function generate(): string
    {
        $prefix = bin2hex(random_bytes(9));
        $seq = sprintf('%012x', ++self::$seq);

        return sprintf(
            '%s-%s-4%s-8%s-%s',
            substr($prefix, 0, 8),
            substr($prefix, 8, 4),
            substr($prefix, 12, 3),
            substr($prefix, 15, 3),
            $seq
        );
    }
}
