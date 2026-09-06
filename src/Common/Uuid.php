<?php

/**
 * Phlix media server component: Common.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Common;

/**
 * Shared UUID v4 generator.
 *
 * Produces UUID v4 strings in the standard format:
 * `550e8400-e29b-41d4-a716-446655440000`
 *
 * The SHAPE matches the historical per-class generateUuid() implementations
 * scattered throughout the codebase (8-4-4-4-12 hex, version 0x4000, variant
 * 0x8000). Centralising it here eliminates ~32 duplicate definitions.
 *
 * ## The entropy source is the CSPRNG — deliberately (S443)
 *
 * These ids are primary keys (`media_items`, `users`, jobs …). They used to be
 * minted from `mt_rand()`, the process-global Mersenne Twister, which is NOT
 * private state: PHPUnit's runner calls `mt_srand(randomOrderSeed)` before a
 * random-ordered run (`phpunit.xml executionOrder="random"`), the order
 * `shuffle()` draws from the same stream, and individual tests re-pin it mid
 * run for their own determinism (`WebhookServiceTest`, `BrowseIndexUsageTest`).
 * Any of those makes every subsequent `Uuid::v4()` byte-reproducible — so two
 * same-seed runs (or two minting consumers separated by a re-pin) mint the SAME
 * CHAR(36) PK and the second INSERT dies with MySQL 1062 `Duplicate entry …
 * for key 'PRIMARY'` (the intermittent Music red, s252 run 1). `random_bytes()`
 * is a CSPRNG and cannot be steered by `mt_srand()`; replaying the order seed
 * can no longer replay an id. The fix mirrors what S111/S334 already did to the
 * test-side fixture generator (`tests/Support/FixtureIdGenerator.php`).
 *
 * Determinism elsewhere is untouched: nothing in the estate may rely on seeded
 * UUID reproducibility, and jitter/retry randomness keeps drawing from
 * `mt_rand()` at its own call sites (`WebhookDeliveryRecord` et al.).
 *
 * @package Phlix\Common
 */
final readonly class Uuid
{
    /** A UUID carries exactly 16 random bytes before the shape nibbles. */
    private const BYTES = 16;

    /**
     * Generate a random UUID v4 string from the CSPRNG.
     *
     * @return string UUID in standard format xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
     */
    public static function v4(): string
    {
        $bytes = random_bytes(self::BYTES);

        // RFC 4122: version 4 in the high nibble of time_hi_and_version,
        // variant 10xx in the top bits of clock_seq. Same shape the historical
        // sprintf('%04x…', mt_rand(0, 0x0fff) | 0x4000, …) produced, bit for bit.
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    /**
     * Prevent instantiation — static methods only.
     */
    private function __construct()
    {
    }
}
