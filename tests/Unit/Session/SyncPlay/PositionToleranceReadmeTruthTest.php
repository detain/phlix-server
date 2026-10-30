<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Session\SyncPlay;

use PHPUnit\Framework\TestCase;
use Phlix\Session\SyncPlay\GroupState;

/**
 * Review server__pm M-SERVER-31: the README advertised "Position Tolerance:
 * Configurable sync tolerance (default 2s)" while nothing on the operator
 * surface could configure it — no config key, no admin setting, no env. The
 * value is the constant GroupState::POSITION_TOLERANCE (2000 ms); the
 * constructor seams on GroupState/SyncPlayManager are never passed a
 * non-default by any shipped caller (only tests).
 *
 * Cure: the README sentence now tells the fixed-window truth. This guard
 * re-derives the number from the enforcing code and scans the config plane
 * for a tolerance key, so the sentence can only stay green while it matches
 * reality in BOTH directions (a future real config key reddens the
 * "no config key" phrase exactly as a docs revert reddens the fiction check).
 *
 * @package Phlix\Tests\Unit\Session\SyncPlay
 */
final class PositionToleranceReadmeTruthTest extends TestCase
{
    private static function readme(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 4) . '/README.md');
    }

    public function test_the_configurable_tolerance_fiction_never_returns(): void
    {
        self::assertStringNotContainsString(
            'Configurable sync tolerance',
            self::readme(),
            'the README must not call the sync tolerance configurable while no config surface exists.'
        );
    }

    public function test_the_tolerance_line_matches_the_enforcing_constant(): void
    {
        $line = '';
        foreach (explode("\n", self::readme()) as $candidate) {
            if (str_contains($candidate, '**Position Tolerance**')) {
                $line = $candidate;
            }
        }

        self::assertNotSame('', $line, 'the README must still document the sync tolerance.');
        self::assertStringContainsString('fixed', $line, 'the shipped window is fixed; the line must say so.');
        self::assertStringContainsString(
            (string) GroupState::POSITION_TOLERANCE,
            $line,
            'the advertised millisecond value must equal GroupState::POSITION_TOLERANCE.'
        );
        self::assertStringContainsString(
            'no config key',
            $line,
            'the line must disclose the absence of a config surface for the tolerance.'
        );
    }

    public function test_no_config_plane_key_tolerances_the_sync_window(): void
    {
        $configDir = dirname(__DIR__, 4) . '/config';
        $hits = [];
        foreach ((array) glob($configDir . '/*.php') as $file) {
            if (preg_match('/tolerance/i', (string) file_get_contents((string) $file))) {
                $hits[] = basename((string) $file);
            }
        }

        self::assertSame(
            [],
            $hits,
            'a real tolerance config key now exists (' . implode(', ', $hits) . ') — the README line and '
            . 'this guard must be updated to describe the shipped knob instead of its absence.'
        );
    }
}
