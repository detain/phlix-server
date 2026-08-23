<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Streaming\Trickplay;

use PHPUnit\Framework\TestCase;

/**
 * S349 — `TrickplayController` must consume the single-sourced filename
 * constants instead of re-spelling the literals.
 *
 * ## Why this file exists
 *
 * S284 single-sourced the two trickplay artefact filenames as
 * `FfmpegRunner::SPRITE_FILENAME` (`sprite.jpg`) and
 * `FfmpegRunner::TIMELINE_FILENAME` (`timeline.json`). S284's own docblock
 * warns that a second spelling "would silently stop matching the moment this
 * one moved". The S344 sweep found exactly that: `TrickplayController` re-spelled
 * both literals at five sites (the URL builders, the content readers and the
 * sprite 404 fallback), so a rename in `FfmpegRunner` would have left this
 * controller serving dead paths with no compile-time or test-time signal.
 * This test pins the consumption and makes a re-spelling red.
 *
 * ## Shape chosen (stated per the AC)
 *
 * A tokenized scan of **only** `src/Media/Streaming/Trickplay/TrickplayController.php`
 * — never a codebase-wide scan, because the route literals in
 * `src/Server/Core/Application.php` and the test fixtures elsewhere legitimately
 * keep the literal filenames. Comments are stripped with `token_get_all()`
 * (`T_COMMENT`/`T_DOC_COMMENT`, the in-repo precedent is
 * `MaintenanceTaskCoverageTest::stripComments()`), so a detector cannot fire on
 * — or be satisfied by — its own documentation: the class docblock names both
 * literals and must not trip the negative assertions.
 *
 * The assertions are three-fold:
 *
 *  1. **Negative (whole file):** neither the filename value `sprite.jpg` nor
 *     `timeline.json` appears in the comment-stripped source. The needle is the
 *     filename VALUE, not a quoted form: the re-spelled sites wrote
 *     `'/sprite.jpg'` (quote, path slash, name), so a needle of `'sprite.jpg'`
 *     — quote, name, quote — would never match and the detector would be
 *     vacuous. The mutation-red proof caught exactly that bug in the first
 *     draft; the value-level needle catches both the slash-prefixed and bare
 *     re-spellings.
 *  2. **Per-site inventory (the five named sites):** each of the five methods —
 *     `getSpriteUrl`, `getTimelineUrl`, `getSpriteContent`, `getTimelineContent`,
 *     `getSprite` — has a body that references its constant
 *     (`FfmpegRunner::SPRITE_FILENAME` or `FfmpegRunner::TIMELINE_FILENAME`) and
 *     does not re-spell either filename value. This is the "assert about the
 *     five named sites and nothing else" shape.
 *  3. **Anti-vacuity positives (whole file):** all five method names still exist
 *     as `function …` declarations, `FfmpegRunner::SPRITE_FILENAME` appears at
 *     least 3 times and `FfmpegRunner::TIMELINE_FILENAME` at least 2 — a
 *     hollowed extractor or a wholesale file replacement cannot pass.
 *
 * The `sprite.png` fallback literals (no constant exists for them) and the route
 * literals are out of scope and are not asserted about.
 *
 * ## Delivery
 *
 * An ordinary PHPUnit unit test: no DB, no mocks, fails natively on the
 * developer's machine the moment a site re-spells a literal.
 */
final class TrickplayControllerLiteralsTokenizedTest extends TestCase
{
    /**
     * The five sites that must consume the constants, keyed by method name.
     *
     * The order mirrors the five re-spelled sites the S344 sweep found:
     *  - `getSpriteUrl`       (was `…/sprite.jpg` URL build)
     *  - `getTimelineUrl`     (was `…/timeline.json` URL build)
     *  - `getSpriteContent`   (was `$jobDir . '/sprite.jpg'` read path)
     *  - `getTimelineContent` (was `…/timeline.json` read path)
     *  - `getSprite`          (was `!file_exists($jobDir . '/sprite.jpg')` 404 fallback)
     *
     * @var array<string, string>
     */
    private const SITES = [
        'getSpriteUrl'       => 'FfmpegRunner::SPRITE_FILENAME',
        'getTimelineUrl'     => 'FfmpegRunner::TIMELINE_FILENAME',
        'getSpriteContent'   => 'FfmpegRunner::SPRITE_FILENAME',
        'getTimelineContent' => 'FfmpegRunner::TIMELINE_FILENAME',
        'getSprite'          => 'FfmpegRunner::SPRITE_FILENAME',
    ];

    /**
     * The minimum constant-reference counts in the whole comment-stripped
     * source. After S349 the measured counts are exactly 3 and 2 (one per
     * site); the assertions are floors so a future, legitimate consumer of a
     * constant cannot redden the guard.
     */
    private const MIN_SPRITE_FILENAME_REFS = 3;
    private const MIN_TIMELINE_FILENAME_REFS = 2;

    /**
     * NEGATIVE, whole file: the two single-sourced literals must not appear in
     * the executable source of the controller.
     *
     * Comment stripping is what makes this a detector rather than a prose
     * matcher: the class docblock and the route docblocks name both literals.
     */
    public function testTheControllerSourceNoLongerReSpellsTheSingleSourcedLiterals(): void
    {
        $stripped = self::stripComments(self::controllerSource());

        self::assertStringNotContainsString(
            'sprite.jpg',
            $stripped,
            'S349: `TrickplayController` re-spells the `sprite.jpg` literal. '
            . 'Consume FfmpegRunner::SPRITE_FILENAME instead — a second spelling silently '
            . 'stops matching the moment the single source moves.'
        );
        self::assertStringNotContainsString(
            'timeline.json',
            $stripped,
            'S349: `TrickplayController` re-spells the `timeline.json` literal. '
            . 'Consume FfmpegRunner::TIMELINE_FILENAME instead.'
        );
    }

    /**
     * PER-SITE INVENTORY: each of the five named methods contains its constant
     * reference and re-spells neither literal inside its own body.
     *
     * This is the "assert about the five named sites and nothing else" shape:
     * a re-spelling anywhere else in the file is caught by the whole-file
     * negative; a re-spelling at one of these sites is caught here, named.
     */
    public function testEachOfTheFiveSitesConsumesItsConstant(): void
    {
        foreach (self::SITES as $method => $reference) {
            $body = self::methodBody($method);

            self::assertStringContainsString(
                $reference,
                $body,
                "S349: site `{$method}()` no longer references {$reference}. "
                . 'Per-site inventory: this is one of the five re-spelled sites the S344 '
                . 'sweep found — consume the constant.'
            );
            self::assertStringNotContainsString(
                'sprite.jpg',
                $body,
                "S349: site `{$method}()` re-spells the 'sprite.jpg' literal. "
                . 'Consume FfmpegRunner::SPRITE_FILENAME.'
            );
            self::assertStringNotContainsString(
                'timeline.json',
                $body,
                "S349: site `{$method}()` re-spells the 'timeline.json' literal. "
                . 'Consume FfmpegRunner::TIMELINE_FILENAME.'
            );
        }
    }

    /**
     * ANTI-VACUITY: the guard is reading a real, complete controller.
     *
     * All five method names must still exist as declarations, and the two
     * constant references must appear at least as often as the five sites
     * consume them. Without these, a hollowed-out extractor — or a wholesale
     * file replacement that simply deletes every site — could pass the
     * negative assertions by examining nothing.
     */
    public function testTheAntiVacuityPositivesStillHold(): void
    {
        $stripped = self::stripComments(self::controllerSource());

        foreach (array_keys(self::SITES) as $method) {
            self::assertStringContainsString(
                'function ' . $method,
                $stripped,
                "S349 anti-vacuity: `{$method}()` no longer exists in the controller, "
                . 'so the guard would be asserting about nothing. The five named sites must stay.'
            );
        }

        $spriteRefs = substr_count($stripped, 'FfmpegRunner::SPRITE_FILENAME');
        self::assertGreaterThanOrEqual(
            self::MIN_SPRITE_FILENAME_REFS,
            $spriteRefs,
            "S349 anti-vacuity: found {$spriteRefs} FfmpegRunner::SPRITE_FILENAME "
            . 'references, expected at least ' . self::MIN_SPRITE_FILENAME_REFS . '.'
        );

        $timelineRefs = substr_count($stripped, 'FfmpegRunner::TIMELINE_FILENAME');
        self::assertGreaterThanOrEqual(
            self::MIN_TIMELINE_FILENAME_REFS,
            $timelineRefs,
            "S349 anti-vacuity: found {$timelineRefs} FfmpegRunner::TIMELINE_FILENAME "
            . 'references, expected at least ' . self::MIN_TIMELINE_FILENAME_REFS . '.'
        );
    }

    /**
     * The REAL controller source, read from disk — never a copy or fixture.
     *
     * @return string
     */
    private static function controllerSource(): string
    {
        $source = file_get_contents(
            dirname(__DIR__, 5) . '/src/Media/Streaming/Trickplay/TrickplayController.php'
        );
        self::assertIsString($source, 'S349: the real controller source could not be read.');

        return $source;
    }

    /**
     * The body of one named method, extracted from the comment-stripped source
     * via `token_get_all()` — from the opening `{` to its matching `}`.
     *
     * @return string
     */
    private static function methodBody(string $method): string
    {
        $tokens = token_get_all(self::stripComments(self::controllerSource()));
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }

            $j = $i + 1;
            while (
                $j < $count
                && is_array($tokens[$j])
                && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
            ) {
                $j++;
            }
            if (
                $j >= $count
                || !is_array($tokens[$j])
                || $tokens[$j][0] !== T_STRING
                || $tokens[$j][1] !== $method
            ) {
                continue;
            }

            // The opening brace after the parameter list.
            $parenDepth = 0;
            $open = null;
            for ($k = $j + 1; $k < $count; $k++) {
                if ($tokens[$k] === '(') {
                    $parenDepth++;
                } elseif ($tokens[$k] === ')') {
                    $parenDepth--;
                } elseif ($parenDepth === 0 && $tokens[$k] === '{') {
                    $open = $k;
                    break;
                }
            }
            self::assertNotNull($open, "S349: no body brace found for `{$method}()`.");

            // The matching closing brace.
            $braceDepth = 0;
            $close = null;
            for ($k = $open; $k < $count; $k++) {
                if ($tokens[$k] === '{') {
                    $braceDepth++;
                } elseif ($tokens[$k] === '}') {
                    $braceDepth--;
                    if ($braceDepth === 0) {
                        $close = $k;
                        break;
                    }
                }
            }
            self::assertNotNull($close, "S349: no closing brace found for `{$method}()`.");

            $parts = [];
            for ($k = $open; $k <= $close; $k++) {
                $parts[] = is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
            }

            return implode('', $parts);
        }

        self::fail("S349: no method named `{$method}` found in the controller source.");

        return '';
    }

    /**
     * Remove `//`, `#` and `/* … *\/` comments so a source-scanning assertion
     * cannot be satisfied by prose that merely NAMES the thing it is looking
     * for — the in-repo precedent is
     * `MaintenanceTaskCoverageTest::stripComments()`.
     */
    private static function stripComments(string $source): string
    {
        // token_get_all() only tokenises PHP once it has seen an open tag, so a
        // fragment needs one prepended or every comment survives.
        if (!str_starts_with(ltrim($source), '<?php')) {
            $source = "<?php\n" . $source;
        }

        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
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
