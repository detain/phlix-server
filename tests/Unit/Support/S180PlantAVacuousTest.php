<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * S180 FAILABILITY PROOF — THROWAWAY. This branch exists only to show the CI job
 * "Assertion-Escape Prober (S180)" going RED on a real GitHub runner. It is never
 * merged and its PR is closed as soon as the red is observed.
 *
 * The shape is the production one: SmartPlaylistRefreshSubscriber::drainTick()
 * wraps refreshLibrary() in catch (Throwable) so a failed refresh cannot kill the
 * worker's timer, and an assertion inside a callback it invokes therefore cannot
 * fail its test.
 */
final class S180PlantAVacuousTest extends TestCase
{
    public function testPlantAssertionInsideASwallowingCallback(): void
    {
        $swallower = new class {
            public function run(callable $cb): void
            {
                try {
                    $cb();
                } catch (\Throwable) {
                    // swallowed, exactly like production does
                }
            }
        };

        $swallower->run(function (): void {
            $this->assertSame(1, 2, 'PLANTED-A: this assertion cannot fail its test');
        });

        $this->assertTrue(true, 'the test passes regardless');
    }
}
