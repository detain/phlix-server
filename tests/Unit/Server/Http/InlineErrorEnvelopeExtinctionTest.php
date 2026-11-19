<?php

namespace Phlix\Tests\Unit\Server\Http;

use PHPUnit\Framework\TestCase;

/**
 * Wave-5 SRV-ENVELOPES (refactor-1119, E-10 batch-1): extinction census guard.
 *
 * Batch-1 converted every inline half-envelope site in WebPortalRouter (56)
 * and MediaItemController (36) onto Response::errorPlain(). This file keeps
 * them extinct: the inline `(new Response())->status(...)->json([...])` form
 * may never return to either file, and the adopted call counts never shrink.
 *
 * The counter is canary-tested (test_census_counter_detects_a_planted_legacy_
 * form) so a silent breakage of the detection string cannot make the zero
 * claims vacuous. Future batches (Profiles / Book / Audiobook per the
 * SRV-ENVELOPES register) extend the file list here, batch by batch.
 */
final class InlineErrorEnvelopeExtinctionTest extends TestCase
{
    private const WPR_FILE = __DIR__ . '/../../../../src/Server/WebPortal/WebPortalRouter.php';
    private const MIC_FILE = __DIR__ . '/../../../../src/Server/Http/Controllers/MediaItemController.php';

    /** Inline half-envelope marker: a single-key `['error' => ...]` literal. */
    private const LEGACY_MARKERS = ["'error' =>", '"error" =>'];

    public function test_batch_one_files_exist_and_are_non_empty(): void
    {
        foreach ([self::WPR_FILE, self::MIC_FILE] as $file) {
            self::assertFileExists($file);
            self::assertGreaterThan(0, (int) filesize($file), "empty file: $file");
        }
    }

    public function test_no_inline_error_envelope_literals_remain(): void
    {
        foreach ([self::WPR_FILE, self::MIC_FILE] as $file) {
            $source = (string) file_get_contents($file);
            foreach (self::LEGACY_MARKERS as $marker) {
                $hits = substr_count($source, $marker);
                self::assertSame(
                    0,
                    $hits,
                    "legacy inline error envelope survived in " . basename($file) . " ($hits hits of $marker)",
                );
            }
        }
    }

    public function test_adopted_call_counts_match_batch_one_census_exactly(): void
    {
        // Batch-1 census at conversion: WebPortalRouter 56 sites,
        // MediaItemController 36 sites. These numbers move only when a
        // deliberate batch adds or retires sites alongside this pin.
        self::assertSame(
            56,
            substr_count((string) file_get_contents(self::WPR_FILE), '->errorPlain('),
            'WebPortalRouter errorPlain census drifted from batch-1 (56)',
        );
        self::assertSame(
            36,
            substr_count((string) file_get_contents(self::MIC_FILE), '->errorPlain('),
            'MediaItemController errorPlain census drifted from batch-1 (36)',
        );
    }

    public function test_census_counter_detects_a_planted_legacy_form(): void
    {
        $planted = <<<'PHP'
            return (new Response())->status(404)->json(['error' => 'Library not found']);
            PHP;

        $hits = 0;
        foreach (self::LEGACY_MARKERS as $marker) {
            $hits += substr_count($planted, $marker);
        }

        self::assertSame(1, $hits, 'detection markers no longer catch the legacy inline form');
    }
}
