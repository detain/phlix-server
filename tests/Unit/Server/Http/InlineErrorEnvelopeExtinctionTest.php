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
    // Batch-3 (SRV-ENVELOPES-B3): the middleware/QuickConnect {error, code} cohort.
    private const QC_FILE = __DIR__ . '/../../../../src/Server/Http/Controllers/Auth/QuickConnectController.php';
    private const SIGNED_FILE = __DIR__ . '/../../../../src/Server/Http/Middleware/SignedUrlMiddleware.php';
    private const ADMIN_FILE = __DIR__ . '/../../../../src/Server/Http/Middleware/AdminMiddleware.php';
    private const DLNA_FILE = __DIR__ . '/../../../../src/Server/Http/Middleware/DlnaAllowlistMiddleware.php';
    private const AUTHMW_FILE = __DIR__ . '/../../../../src/Server/Http/Middleware/AuthMiddleware.php';
    private const CASTING_FILE = __DIR__ . '/../../../../src/Server/Http/Middleware/CastingEnabledMiddleware.php';
    private const PROFILES_FILE = __DIR__ . '/../../../../src/Server/Http/Controllers/ProfilesController.php';
    private const BOOK_FILE = __DIR__ . '/../../../../src/Server/Http/Controllers/BookController.php';

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

    // -----------------------------------------------------------------
    // Batch-4 (SRV-ENVELOPES-B4): ProfilesController.
    // -----------------------------------------------------------------

    public function test_batch_four_profiles_file_exists_and_is_non_empty(): void
    {
        self::assertFileExists(self::PROFILES_FILE);
        self::assertGreaterThan(0, (int) filesize(self::PROFILES_FILE));
    }

    public function test_batch_four_profiles_has_no_inline_error_literals(): void
    {
        $source = (string) file_get_contents(self::PROFILES_FILE);
        foreach (self::LEGACY_MARKERS as $marker) {
            $hits = substr_count($source, $marker);
            self::assertSame(
                0,
                $hits,
                'legacy inline error envelope survived in ProfilesController '
                . "($hits hits of $marker)",
            );
        }
    }

    public function test_batch_four_profiles_adopted_call_counts(): void
    {
        // Batch-4 Profiles census at conversion: 28 half-envelope sites onto
        // errorPlain(), plus the SRV-M17 hold 403 onto the full error() seam.
        // The seam call-count pin uses the (new Response())->error( form on
        // purpose: the bare ->error( pattern is polluted by the LoggerFactory
        // emitter at ProfilesController.php:531 and would drift on unrelated
        // logging edits. 4 seam calls pre-existed (profile.use_switch /
        // profile.last_profile / profile.no_pin / profile.pin_mismatch);
        // the converted hold site is the 5th.
        $source = (string) file_get_contents(self::PROFILES_FILE);
        self::assertSame(
            28,
            substr_count($source, '->errorPlain('),
            'ProfilesController errorPlain census drifted from batch-4 (28)',
        );
        self::assertSame(
            5,
            substr_count($source, '(new Response())->error('),
            'ProfilesController error() seam census drifted from batch-4 (5)',
        );
    }

    // -----------------------------------------------------------------
    // Batch-4 (SRV-ENVELOPES-B4): BookController.
    // -----------------------------------------------------------------

    public function test_batch_four_book_file_exists_and_is_non_empty(): void
    {
        self::assertFileExists(self::BOOK_FILE);
        self::assertGreaterThan(0, (int) filesize(self::BOOK_FILE));
    }

    public function test_batch_four_book_has_no_inline_error_literals(): void
    {
        $source = (string) file_get_contents(self::BOOK_FILE);
        foreach (self::LEGACY_MARKERS as $marker) {
            $hits = substr_count($source, $marker);
            self::assertSame(
                0,
                $hits,
                "legacy inline error envelope survived in BookController "
                . "($hits hits of $marker)",
            );
        }
    }

    public function test_batch_four_book_adopted_call_counts(): void
    {
        // Batch-4 Book census at conversion: 24 pure half-envelope sites,
        // all onto errorPlain(). No error() seam sites exist on this file.
        $source = (string) file_get_contents(self::BOOK_FILE);
        self::assertSame(
            24,
            substr_count($source, '->errorPlain('),
            'BookController errorPlain census drifted from batch-4 (24)',
        );
    }

    // -----------------------------------------------------------------
    // Batch-3 (SRV-ENVELOPES-B3): middleware/QuickConnect {error, code}
    // cohort — the Response::error adoption the batch-1 register left
    // behind (skip rows) plus the live middleware-census extensions found
    // at re-derivation (Admin/Dlna/Auth/Casting gates). Dispatch order vs
    // batch-4 crossed; batch number reflects the register, not the tree.
    // -----------------------------------------------------------------

    private const BATCH_THREE_FILES = [
        'QuickConnectController' => self::QC_FILE,
        'SignedUrlMiddleware' => self::SIGNED_FILE,
        'AdminMiddleware' => self::ADMIN_FILE,
        'DlnaAllowlistMiddleware' => self::DLNA_FILE,
        'AuthMiddleware' => self::AUTHMW_FILE,
        'CastingEnabledMiddleware' => self::CASTING_FILE,
    ];

    public function test_batch_three_files_exist_and_are_non_empty(): void
    {
        foreach (self::BATCH_THREE_FILES as $name => $file) {
            self::assertFileExists($file, "batch-3 file gone: $name");
            self::assertGreaterThan(0, (int) filesize($file), "empty file: $file");
        }
    }

    public function test_batch_three_files_have_no_inline_error_literals(): void
    {
        foreach (self::BATCH_THREE_FILES as $name => $file) {
            $source = (string) file_get_contents($file);
            foreach (self::LEGACY_MARKERS as $marker) {
                $hits = substr_count($source, $marker);
                self::assertSame(
                    0,
                    $hits,
                    "legacy inline error envelope survived in $name ($hits hits of $marker)",
                );
            }
        }
    }

    public function test_batch_three_adopted_call_counts(): void
    {
        // Census at conversion (commit-pinned, register SRV-ENVELOPES-B3):
        // QuickConnectController 15 sites converted + 1 pre-existing seam call
        // (noProfileToApprove's access.scheduled 403) = 16; the five middleware
        // files 2+2+1+1+1. The (new Response())->error( form is the marker on
        // purpose (batch-4 law): bare ->error( would collide with logger
        // emitters; none of these six files has one today, and the stricter
        // marker keeps that true.
        $expected = [
            'QuickConnectController' => 16,
            'SignedUrlMiddleware' => 2,
            'AdminMiddleware' => 2,
            'DlnaAllowlistMiddleware' => 1,
            'AuthMiddleware' => 1,
            'CastingEnabledMiddleware' => 1,
        ];
        foreach ($expected as $name => $count) {
            self::assertSame(
                $count,
                substr_count((string) file_get_contents(self::BATCH_THREE_FILES[$name]), '(new Response())->error('),
                "$name Response::error seam census drifted from batch-3 ($count)",
            );
        }
    }
}
