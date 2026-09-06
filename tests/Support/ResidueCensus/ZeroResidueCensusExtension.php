<?php

declare(strict_types=1);

namespace Phlix\Tests\Support\ResidueCensus;

use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use RuntimeException;

/**
 * S439 (finish-S167) — the census that keeps the suite honest about /tmp.
 *
 * Registered from `phpunit.xml` beside {@see \Phlix\Tests\Support\AssertionEscape\AssertionEscapeGuardExtension}.
 * At bootstrap it snapshots the set of `phlix_*` entries already present in the
 * temp dir (so a polluted venue never frames the suite for someone else's dirt),
 * and at execution end it diffs again: every `phlix_*` entry the suite created
 * and never removed is printed to STDERR by name and the subscriber throws.
 *
 * The throw is the RED mechanism, and it is deliberate: `DirectDispatcher::dispatch()`
 * catches every Throwable a subscriber raises and demotes it to a PHPUnit warning —
 * but `failOnPhpunitWarning` defaults to `true` (phpunit.xsd; corrected finding in
 * AssertionEscapeGuardExtension's docblock), so the demotion still exits 1, and the
 * STDERR block names the offense before the summary tail does. This is the same
 * proven shape S120 verified by execution.
 *
 * Scope: the whole suite, both directions of what audit31 (`steps/97-triage/deep-audit/S167.md`)
 * found — the 212 residue entries at `3bd64b84` were all test-side leakers plus one
 * success-path leak in `BackupManager::createBackup()`. The pre-existing
 * `tests/Unit/Media/TempDirMintGuardTest` guards only the src-side logger mint sites
 * (reflection over known construction paths); it is blind to fixtures by construction.
 * This census needs no knowledge of minters at all: residue is residue, whatever made it.
 *
 * The wiring is pinned by `tests/Unit/Media/ZeroResidueCensusTest.php` — deleting the
 * `<bootstrap>` line below would otherwise be silent, exactly the failure mode
 * AssertionEscapeGuardWiringTest was written to end for its sibling.
 */
final class ZeroResidueCensusExtension implements Extension
{
    /** Glob shape shared with TempDirMintGuardTest — every first-party temp entry. */
    public const RESIDUE_GLOB = '/phlix_*';

    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $baseline = self::snapshot(sys_get_temp_dir());

        $facade->registerSubscribers(
            new class ($baseline) implements ExecutionFinishedSubscriber {
                /** @param list<string> $baseline */
                public function __construct(private readonly array $baseline)
                {
                }

                public function notify(ExecutionFinished $event): void
                {
                    // `self::` here would bind to the anonymous class — name the outer explicitly.
                    $residue = ZeroResidueCensusExtension::findResidue(\sys_get_temp_dir(), $this->baseline);

                    if ($residue === []) {
                        return;
                    }

                    $out = "\nS439 ZERO-RESIDUE CENSUS — " . count($residue)
                        . " /tmp/phlix_* entries created by this suite and never removed:\n";

                    foreach (array_slice($residue, 0, 40) as $path) {
                        $out .= '  · ' . $path . "\n";
                    }

                    if (count($residue) > 40) {
                        $out .= '  … and ' . (count($residue) - 40) . " more\n";
                    }

                    $out .= "  Every test that mints a phlix_* temp path owns a teardown for it (S439).\n";

                    fwrite(STDERR, $out);

                    throw new RuntimeException(
                        'ZeroResidueCensus: ' . count($residue) . ' /tmp/phlix_* residue entries after suite; '
                        . 'first: ' . $residue[0]
                    );
                }
            },
        );
    }

    /**
     * The suite created and did not remove these — basenames of the diff against
     * the pre-run snapshot, sorted for a deterministic report.
     *
     * @param list<string> $baseline full paths present before execution began
     * @return list<string>
     */
    public static function findResidue(string $tempDir, array $baseline): array
    {
        return self::censusFrom($tempDir, $baseline);
    }

    /**
     * @param list<string> $baseline
     * @return list<string>
     */
    private static function censusFrom(string $tempDir, array $baseline): array
    {
        $residue = array_values(array_diff(self::snapshot($tempDir), $baseline));

        sort($residue);

        return $residue;
    }

    /** @return list<string> */
    private static function snapshot(string $tempDir): array
    {
        $entries = glob($tempDir . self::RESIDUE_GLOB);

        return $entries === false ? [] : array_values($entries);
    }
}
