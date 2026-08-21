<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Support;

use Phlix\Tests\Support\FixtureIdGenerator;
use PHPUnit\Framework\TestCase;

/**
 * S334 — unit-level proof that the S111 pin's CSPRNG half is real.
 *
 * BrowseIndexUsageTest's S111 pin ("a pinned mt_rand seed must not reproduce a
 * fixture id") is satisfiable by the fixture generator's monotonic counter
 * ALONE: if `bin2hex(random_bytes(9))` were frozen to a constant, two calls
 * under a pinned seed would still differ in their final counter field, so every
 * existing assertion would stay green while the cross-run collisions S111
 * existed to remove were reinstated. This class probes the generator directly
 * (no MySQL, no `RequiresRealDatabase` gate) and compares only the random
 * half — the first 18 hex chars before the 12-hex counter field — so the
 * counter cannot mask a regression.
 */
final class FixtureIdGeneratorTest extends TestCase
{
    public function testCspRngHalfVariesUnderPinnedMtRandSeed(): void
    {
        // Two calls under the SAME pinned mt_rand seed. The monotonic counter
        // still increments between them, so comparing whole ids would prove
        // nothing about the CSPRNG half (that is the S334 half-blind defect).
        // Compare only the random half: positions 0-23 carry all 18 CSPRNG hex
        // chars (the '-' separators and the v4/variant nibbles are constants),
        // while the 12-hex counter field starts at position 24.
        mt_srand(4242);
        $first = FixtureIdGenerator::generate();
        mt_srand(4242);
        $second = FixtureIdGenerator::generate();

        $this->assertNotSame(
            substr($first, 0, 24),
            substr($second, 0, 24),
            'the CSPRNG half of a fixture id must vary even under a pinned mt_rand seed (S334)',
        );
    }

    public function testGeneratedIdsAreWellFormedV4Uuids(): void
    {
        $id = FixtureIdGenerator::generate();

        $this->assertSame(36, strlen($id));
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-8[0-9a-f]{3}-[0-9a-f]{12}$/',
            $id,
        );
    }

    public function testIdsAreUniqueAcrossCallsWithoutMtRandSeeding(): void
    {
        $ids = [];
        for ($i = 0; $i < 64; $i++) {
            $ids[] = FixtureIdGenerator::generate();
        }

        $this->assertCount(64, array_unique($ids), '64 generator calls must produce 64 distinct ids');
    }
}
