<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Integrations\Trakt;

use PHPUnit\Framework\TestCase;

/**
 * S340 — the start.php structural delegate pin.
 *
 * `start.php` runs outside PHPUnit — no CI job executes the daemon — so the
 * Trakt timer's due-decision now lives in {@see TraktSyncBoot} and is pinned
 * here against start.php's SOURCE (comments stripped, so the assertions cannot
 * be satisfied by the explanatory comment block next to the code), in the same
 * family as {@see \Phlix\Tests\Unit\Hub\RelayTunnelBootTest},
 * {@see \Phlix\Tests\Unit\Playlists\SmartPlaylistRefreshWiringTest} and
 * {@see \Phlix\Tests\Unit\Media\Library\FolderWatchWiringTest}. The failure
 * guarded is "the call site was never added / the bare interval came back",
 * which no runtime test in this suite can observe.
 *
 * @package Phlix\Tests\Unit\Server\Integrations\Trakt
 */
final class TraktSyncBootWiringTest extends TestCase
{
    public function testStartPhpDelegatesTheTraktTimerToTraktSyncBoot(): void
    {
        $code = $this->startPhpWithoutComments();

        // Guard is vacuous unless the Trakt block is present in the stripped source.
        self::assertStringContainsString(
            "'phlix-plugin-trakt'",
            $code,
            'guard is vacuous unless the Trakt block is present in the stripped source',
        );

        // The due-decision must be delegated, not re-invented inline.
        self::assertStringContainsString(
            'TraktSyncBoot::runIfDue(',
            $code,
            'start.php must route every Trakt pull-sync tick through TraktSyncBoot::runIfDue()',
        );

        // The timer must be armed at the SWEEP cadence, not the bare interval.
        self::assertMatchesRegularExpression(
            '/Timer::add\(\s*\\\\Phlix\\\\Server\\\\Integrations\\\\Trakt\\\\TraktSyncBoot::DEFAULT_SWEEP_SECONDS/',
            $code,
            'start.php must arm the Trakt timer at TraktSyncBoot::DEFAULT_SWEEP_SECONDS — the '
            . 'bare-interval shape (first tick a full interval after boot) is the defect.',
        );

        // The pre-fix bare-interval arming must be GONE: no Timer::add armed at
        // the raw interval any more. (The interval still appears, but only as the
        // due-interval argument to runIfDue.)
        self::assertDoesNotMatchRegularExpression(
            '/Timer::add\(\s*\$traktIntervalMinutes \* 60/',
            $code,
            'the bare-interval arming must be gone — Timer::add() may no longer take the '
            . 'configured interval directly',
        );

        // The configured interval must still reach the due-decision, in seconds.
        self::assertStringContainsString(
            '$traktIntervalMinutes * 60',
            $code,
            'the configured interval (minutes * 60) must be passed to the due-decision as seconds',
        );

        // The arm gate must still require a positive interval (interval <= 0 → no sync).
        self::assertMatchesRegularExpression(
            '/\$traktIntervalMinutes > 0/',
            $code,
            'the arm gate must keep requiring a positive interval — an interval <= 0 must not sync',
        );

        // The full arm gate must be preserved, so a disabled plugin / master
        // switch / sync toggle still arms NOTHING (S345 lesson 1: every branch).
        foreach ([
            '$installedTrakt->enabled',
            '$traktMasterEnabled',
            '$traktSettings->syncEnabled',
            '$traktIntervalMinutes > 0',
        ] as $clause) {
            self::assertStringContainsString(
                $clause,
                $code,
                "the arm gate must keep requiring {$clause} — a disabled plugin must not sync",
            );
        }
    }

    /** start.php source with all comments removed. */
    private function startPhpWithoutComments(): string
    {
        $path = dirname(__DIR__, 5) . '/start.php';
        $source = file_get_contents($path);
        self::assertIsString($source);

        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }

        return $out;
    }
}
