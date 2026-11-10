<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use Phlix\Server\Http\Controllers\ControllerCopy;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * SH-041 copy-law sweep (review server__copywriter#3/#4/#5/#6/#12/#13).
 *
 * Pins, in source, the conventions this row normalized across
 * src/Server/Http/Controllers and src/Session/SyncPlay/SyncPlayManager.php:
 *  - #13 duplication: the segment-failure tokens and the hub-enrolment
 *    sentence live in exactly one home (ControllerCopy), and the constant
 *    VALUES are byte-identical to the pre-extraction wire prose — the
 *    players and the admin UI keep seeing the same strings;
 *  - #3 casing: no lowercase-led human copy in an error/message literal
 *    (bare snake_case machine tokens stay legal per #14's doctrine);
 *  - #4 punctuation: the SyncPlay refusal family ends its sentences;
 *    the LibraryController message family does too;
 *  - #5 outcomes: the profiles pair speaks `successfully` once;
 *  - #6 spelling: prose writes ID and characters in full;
 *  - the SRV-m43 pointer family (`must be a string` arms in this scope)
 *    is swept, which is what that register asked a sibling row to do.
 *
 * @package Phlix\Tests\Unit\Server\Http\Controllers
 */
final class ControllerCopySweepTest extends TestCase
{
    private const CONTROLLER_DIR = __DIR__ . '/../../../../../src/Server/Http/Controllers';
    private const SYNCPLAY_MANAGER = __DIR__ . '/../../../../../src/Session/SyncPlay/SyncPlayManager.php';

    public function test_the_segment_arms_share_one_named_home(): void
    {
        foreach (['HlsController.php', 'DashController.php'] as $rail) {
            $source = self::source(self::CONTROLLER_DIR . '/' . $rail);
            self::assertStringNotContainsString(
                "'segment ",
                $source,
                $rail . ' must reference ControllerCopy constants, not inline segment prose.'
            );
            foreach (['SEGMENT_BUSY', 'SEGMENT_CACHE_FULL', 'SEGMENT_BUILD_REFUSED', 'SEGMENT_UNAVAILABLE'] as $name) {
                self::assertSame(
                    1,
                    substr_count($source, 'ControllerCopy::' . $name),
                    $rail . ' must carry exactly one ' . $name . ' arm.'
                );
            }
        }
    }

    public function test_the_shared_copy_values_are_wire_byte_identical(): void
    {
        self::assertSame('segment busy', ControllerCopy::SEGMENT_BUSY);
        self::assertSame('segment cache full', ControllerCopy::SEGMENT_CACHE_FULL);
        self::assertSame('segment build refused', ControllerCopy::SEGMENT_BUILD_REFUSED);
        self::assertSame('segment unavailable', ControllerCopy::SEGMENT_UNAVAILABLE);
        self::assertSame(
            "This server isn't enrolled with a Phlix Hub yet. Go to Admin > Hub to enroll it.",
            ControllerCopy::HUB_NOT_ENROLLED
        );
    }

    public function test_the_hub_enrolment_sentence_lives_in_exactly_one_source_file(): void
    {
        $hits = self::srcFilesContaining('enrolled with a Phlix Hub');
        self::assertSame(
            ['src/Server/Http/Controllers/ControllerCopy.php'],
            $hits,
            'the enrolment refusal must exist in one home (server__copywriter#13); a new quoting file must join the constant, not the prose.'
        );
    }

    public function test_the_hls_and_dash_bad_request_pair_answer_identically(): void
    {
        $hls = substr_count(self::source(self::CONTROLLER_DIR . '/HlsController.php'), "'Invalid request'");
        $dash = substr_count(self::source(self::CONTROLLER_DIR . '/DashController.php'), "'Invalid request'");
        self::assertSame($dash, $hls, 'Hls and Dash answer the same malformed-request probe with the same prose (server__copywriter#3).');
        self::assertGreaterThan(0, $hls, 'both rails must still answer the probe at all.');
    }

    public function test_no_lowercase_human_copy_leads_an_error_or_message_literal(): void
    {
        $offenders = [];
        foreach (self::inScopeSources() as $label => $source) {
            preg_match_all("/'(?:error|message)' => '([a-z][^']*)'/", $source, $matches);
            foreach ($matches[1] as $value) {
                // Bare snake_case machine tokens stay legal (finding #14
                // doctrine: the code is contract, the copy is ours — and a
                // token like missing_identity_id IS the contract text).
                if (preg_match('/^[a-z0-9]+(_[a-z0-9]+)+$/', $value) === 1) {
                    continue;
                }
                $offenders[] = $label . ': ' . $value;
            }
        }
        self::assertSame([], $offenders, 'human prose in error/message literals leads with a capitalized word (server__copywriter#3).');
    }

    public function test_the_syncplay_refusal_family_ends_its_sentences(): void
    {
        // The REST mirrors in SyncPlayController/AdminUserController carry the
        // same refusals as the WS manager — the whole in-scope family must
        // speak in sentences, not just the file the review sampled (SH-041 #4).
        // 'Invalid password' is EXCLUDED (SH-041 R2): on the join rail this
        // prose is a consumer wire contract — the vendored @phlix/ui
        // strict-equality-matches the un-dotted form — so the byte-pin lives
        // in SyncPlayJoinRejectionWireContractTest, not in this punctuation
        // sweep. The admin rail's dotted 'Invalid password.' arms
        // (AdminUserController) have no consumer matcher and stay in the
        // sentence family pinned by AdminUserControllerTest.
        $family = [
            'You are not in a group',
            'Group not found',
            'Group is full',
            'Failed to join group',
            'Not in any group',
            'Group timed out due to inactivity',
        ];
        $manager = self::source(self::SYNCPLAY_MANAGER);
        foreach ($family as $sentence) {
            foreach (self::inScopeSources() as $label => $source) {
                self::assertSame(
                    0,
                    substr_count($source, "'" . $sentence . "'"),
                    'the refusal family speaks in sentences — a bare copy of "' . $sentence . '" drifted back into ' . $label . '.'
                );
            }
            self::assertGreaterThanOrEqual(
                1,
                substr_count($manager, "'" . $sentence . ".'"),
                'the refusal family must still answer with "' . $sentence . '."'
            );
        }
    }

    public function test_the_library_message_family_ends_its_sentences(): void
    {
        $source = self::source(self::CONTROLLER_DIR . '/LibraryController.php');
        foreach (
            [
            'Library updated successfully',
            'Library deleted successfully',
            'Library scan queued',
            'Metadata match queued',
            'Metadata refresh queued',
            'Library prune queued',
            'Metadata clear queued',
            'Artwork clear queued',
            'Delete-all items queued (destructive)',
            ] as $sentence
        ) {
            self::assertSame(
                0,
                substr_count($source, "'" . $sentence . "'"),
                $sentence . ' is a sentence and must carry its period (server__copywriter#4).'
            );
        }
    }

    public function test_the_profiles_pair_speaks_successfully_once(): void
    {
        $source = self::source(self::CONTROLLER_DIR . '/ProfilesController.php');
        foreach (['Profile created', 'Profile updated'] as $short) {
            self::assertSame(0, substr_count($source, "'" . $short . "'"), $short . ' must join the dominant successfully family (server__copywriter#5).');
        }
        self::assertStringContainsString("'Profile created successfully'", $source);
        self::assertStringContainsString("'Profile updated successfully'", $source);
    }

    public function test_prose_spells_id_and_characters_in_full(): void
    {
        $offenders = [];
        foreach (self::inScopeSources() as $label => $source) {
            preg_match_all("/'(?:error|message)' => '((?:[^']|\\\\')*)'/", $source, $matches);
            foreach ($matches[1] as $value) {
                if (strpos($value, ' ') === false) {
                    continue; // machine tokens carry no spaces; prose does.
                }
                if (preg_match('/(?<![_a-zA-Z0-9])id(?![_a-zA-Z0-9])/', $value) === 1) {
                    $offenders[] = $label . ' (id): ' . $value;
                }
                if (preg_match('/\bchars\b/', $value) === 1) {
                    $offenders[] = $label . ' (chars): ' . $value;
                }
            }
        }
        self::assertSame([], $offenders, 'estate prose writes ID and characters in full (server__copywriter#6).');
    }

    public function test_the_must_be_a_string_family_is_swept_from_this_scope(): void
    {
        $hits = self::srcFilesContaining('must be a string');
        // The one estate remnant is LiveTvManager's docblock describing the
        // SHA-256 hex gate's INPUT SHAPE to its reader — developer prose, not
        // user-facing copy; no live rejection arm says "must be a string".
        self::assertSame(['src/LiveTv/LiveTvManager.php'], $hits);
        $lines = file($hits[0]);
        self::assertIsArray($lines);
        foreach ($lines as $number => $line) {
            if (strpos($line, 'must be a string') === false) {
                continue;
            }
            self::assertMatchesRegularExpression(
                '/^\s*\*/',
                $line,
                'line ' . ($number + 1) . ' of ' . $hits[0] . ' must be docblock prose, not a user-facing arm (SRV-m43 pointer family, server__copywriter#3).'
            );
        }
    }

    /** @return array<string, string> label => source */
    private static function inScopeSources(): array
    {
        $sources = [basename(self::SYNCPLAY_MANAGER) => self::source(self::SYNCPLAY_MANAGER)];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::CONTROLLER_DIR));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $label = (string) str_replace(dirname(self::CONTROLLER_DIR) . '/', '', $file->getPathname());
                $sources[$label] = self::source($file->getPathname());
            }
        }
        return $sources;
    }

    /** @return list<string> repo-relative php files whose raw source contains $needle */
    private static function srcFilesContaining(string $needle): array
    {
        $srcDir = dirname(self::CONTROLLER_DIR, 3) . '/';
        $hits = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(rtrim($srcDir, '/')));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            if (strpos((string) file_get_contents($file->getPathname()), $needle) !== false) {
                $hits[] = (string) str_replace($srcDir, 'src/', $file->getPathname());
            }
        }
        sort($hits);
        return $hits;
    }

    private static function source(string $path): string
    {
        $source = file_get_contents($path);
        self::assertIsString($source, 'source file ' . $path . ' must be readable.');
        return $source;
    }
}
