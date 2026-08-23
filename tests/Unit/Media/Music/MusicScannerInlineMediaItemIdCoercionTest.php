<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Music;

use PhpToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pins the TWO INLINE copies of the `media_item_id` CHAR(36) coercion that live as
 * local variables inside `MusicLibraryScanner` (S127).
 *
 * **What this closes, and why it is not "more of S121".** S121 fixed one predicate
 * that exists in SIX places: three DTO helpers (`MusicArtist`, `MusicAlbum`,
 * `MusicTrack`, each a `private static mediaItemIdFromRow()`) and three inline locals
 * (`MusicLibraryScanner::upsertArtist()`, `::upsertAlbum()`, and — since S331 —
 * `::ensurePlaceholderArtist()`). S121's mechanical
 * backstop, {@see MusicDtoMediaItemIdTest}, reflects every class DECLARING a
 * `mediaItemId` **property**, so it structurally cannot see a local variable. Three
 * of six sites were guarded; three were not.
 *
 * 🔴 **The gap was UNATTRIBUTABLE, not unpinned — and that distinction is this
 * file's entire deliverable.** Measured on `origin/master` `f701b40c` (2026-08-05):
 * restoring `is_numeric()` at either inline site reds **the same three tests**,
 * `MusicScanUnchangedSkipTest::testAnUnstampedLibraryIsReadOnceAndThenSkipped`,
 * `::testAChangedFileIsStampedSoItIsSkippedOnTheFollowingScan` and
 * `::testAFileEditedBetweenItsProbeAndItsFlushIsReReadOnTheNextScan`. Their messages
 * are about **stamping** — *"an updated file must be stamped, not left to re-read
 * forever"* — and say **nothing** about `media_item_id`, nothing about CHAR(36) and
 * nothing about which of the two sites changed. A developer reading that output has
 * no route from the symptom to the cause. So the acceptance criterion here is a
 * **named, self-describing message**, not first-time redness: the redness half was
 * already true and closing on it alone would ship nothing.
 *
 * **Why a source-text guard rather than a behavioural one.** The behavioural route
 * would mean driving a real scan through a DB double until a UUID survives to a
 * caller — which is what already happens, and which produced exactly the orphan
 * messages above. The defect shape here is *drift between six copies of one
 * predicate*, and drift in a copy is a property of the source, not of one execution.
 *
 * **How it avoids being the kind of guard people delete.** Per the step's own design
 * constraint, a guard that breaks on harmless reformatting trains people to delete
 * it. This one reads the file through `PhpToken`, **discards every comment and every
 * whitespace token**, and matches a token SUBSEQUENCE in which the row variable is a
 * wildcard. So it survives reindentation, line rewrapping, comment edits, and
 * renaming `$firstRow`; it fails only when the predicate itself changes. Comments are
 * dropped in both directions, which also means a comment quoting `is_numeric(...)`
 * (there are several in this tree) can neither fake a pass nor fake a failure — the
 * sixth-instance trap where a detector matches its own documentation.
 *
 * **Anti-vacuity.** {@see self::testTheTokenScanIsNotVacuous()} asserts the scanner
 * tokenizes to a floor of significant tokens and that the scan discovers EXACTLY the
 * three known sites, each inside its expected method. Blinding the discovery (an empty
 * file, a deleted method, a hollowed body) fails there with an explicit
 * `ANTI-VACUITY:` message rather than passing on an empty set.
 *
 * **On S120 (assertions swallowed by a production `catch`):** every assertion here
 * runs on a string derived from `file_get_contents()` and `PhpToken::tokenize()` in
 * the test process. No production code is invoked at all, so no
 * `ExpectationFailedException` can be caught by one. This class contains no
 * `try`/`catch`.
 */
final class MusicScannerInlineMediaItemIdCoercionTest extends TestCase
{
    /**
     * The class carrying the two inline copies.
     *
     * ⚠ `src/Media/Library/MusicLibraryScanner.php` does NOT exist — the plan text
     * for this step named that path and it is wrong. The file is under
     * `src/Media/Music/`, which is what this FQCN resolves to.
     */
    private const SCANNER = \Phlix\Media\Music\MusicLibraryScanner::class;

    /** The column, spelled once so the tests and the messages cannot disagree. */
    private const COLUMN = 'media_item_id';

    /**
     * The three methods that inline the predicate, and nothing else in the file does.
     * Asserted as a SET by testTheTokenScanIsNotVacuous(), not merely iterated — a
     * fourth inline site appearing, or one of these three disappearing, must both fail.
     * (S331 added `ensurePlaceholderArtist`, which mirrors the upsert's natural-key
     * branch; the count moved from two inline sites to three.)
     *
     * @return array<string, array{string}>
     */
    public static function inlineSiteProvider(): array
    {
        return [
            'MusicLibraryScanner::ensurePlaceholderArtist()' => ['ensurePlaceholderArtist'],
            'MusicLibraryScanner::upsertArtist()' => ['upsertArtist'],
            'MusicLibraryScanner::upsertAlbum()' => ['upsertAlbum'],
        ];
    }

    /**
     * The whole point of the step: the inline predicate must test the value as a
     * STRING, because `media_item_id` is a `CHAR(36)` UUID (migration 070) and
     * `is_numeric()` is false for every UUID — so an `is_numeric()` coercion silently
     * resolves the id to `null` on every row, forever, with nothing 500ing.
     */
    #[DataProvider('inlineSiteProvider')]
    public function testTheInlineCoercionTestsTheValueAsAString(string $method): void
    {
        $body = self::significantTokenTextOfMethod($method);

        $this->assertMatchesTokenSubsequence(
            "is_string ( \$ROW [ '" . self::COLUMN . "' ] )",
            $body,
            'MusicLibraryScanner::' . $method . '() no longer coerces ' . self::COLUMN
            . " with is_string(). That column is a CHAR(36) UUID (migration 070), so any\n"
            . "numeric predicate — is_numeric(), is_int(), ctype_digit() — is FALSE for every\n"
            . "real id and the coercion silently yields null on every row, with no error\n"
            . "anywhere. This is one of the SIX copies of that predicate: three inline locals\n"
            . "here (ensurePlaceholderArtist, upsertArtist, upsertAlbum) and three DTO helpers\n"
            . "(MusicArtist/MusicAlbum/MusicTrack::mediaItemIdFromRow(), guarded by\n"
            . "MusicDtoMediaItemIdTest). FIX: restore\n"
            . "    isset(\$row['" . self::COLUMN . "']) && is_string(\$row['" . self::COLUMN . "'])\n"
            . "        && \$row['" . self::COLUMN . "'] !== '' ? \$row['" . self::COLUMN . "'] : null\n"
            . 'in MusicLibraryScanner::' . $method . '(), or change all six sites together.'
        );
    }

    /**
     * The negative half, stated separately so the EXACT mutation this step exists to
     * catch — `is_string` back to `is_numeric` — prints a message that names the
     * mutation itself rather than only the absence of the right one.
     */
    #[DataProvider('inlineSiteProvider')]
    public function testTheInlineCoercionDoesNotUseANumericPredicate(string $method): void
    {
        $body = self::significantTokenTextOfMethod($method);

        foreach (['is_numeric', 'is_int', 'is_integer', 'ctype_digit', 'intval'] as $numericPredicate) {
            $this->assertDoesNotMatchTokenSubsequence(
                $numericPredicate . " ( \$ROW [ '" . self::COLUMN . "' ] )",
                $body,
                'MusicLibraryScanner::' . $method . '() coerces ' . self::COLUMN . ' with '
                . $numericPredicate . "() — this is the S121 defect, reintroduced inline.\n"
                . self::COLUMN . " is a CHAR(36) UUID (migration 070). " . $numericPredicate
                . "() is false for EVERY\nUUID shape, including the all-digits-and-dashes one, so the id resolves to\n"
                . "null on\n"
                . "every row and the scanner writes music rows with no media_item link. Nothing\n"
                . "throws; the field is simply always absent. FIX: use\n"
                . "    is_string(\$row['" . self::COLUMN . "']) && \$row['" . self::COLUMN . "'] !== ''\n"
                . 'as MusicArtist/MusicAlbum/MusicTrack::mediaItemIdFromRow() already do.'
            );
        }
    }

    /**
     * The second half of the same predicate. An empty `media_item_id` must collapse
     * to `null` and must NOT survive as `''` — a present-but-unusable id reads as a
     * link that exists, which is worse than an absent one. This is the same property
     * MusicDtoMediaItemIdTest pins on the three DTO helpers.
     */
    #[DataProvider('inlineSiteProvider')]
    public function testTheInlineCoercionRejectsTheEmptyString(string $method): void
    {
        $body = self::significantTokenTextOfMethod($method);

        $this->assertMatchesTokenSubsequence(
            "\$ROW [ '" . self::COLUMN . "' ] !== ''",
            $body,
            'MusicLibraryScanner::' . $method . '() no longer rejects an EMPTY ' . self::COLUMN
            . ".\nAn empty string passes is_string(), so without the `!== ''` arm the scanner\n"
            . "propagates '' as if it were a media_items.id. A present-but-unusable id is worse\n"
            . "than a null one: null is visibly absent, '' reads as a link that exists. The\n"
            . "three DTO helpers all carry this arm — see MusicArtist::mediaItemIdFromRow().\n"
            . 'FIX: restore the ' . "`&& \$row['" . self::COLUMN . "'] !== ''` conjunct in " . $method . '().'
        );
    }

    /**
     * File-wide sweep: no method ANYWHERE in the scanner may coerce this column with
     * a numeric predicate. The three data-driven tests above name the three known
     * sites; this one catches a FOURTH site being added with the old shape, which is
     * exactly how the original five-site drift happened (the defect was written down
     * for one class and the siblings were missed).
     */
    public function testNoMethodInTheScannerCoercesThisColumnNumerically(): void
    {
        $offenders = [];
        foreach (self::coercionSites() as $site) {
            if ($site['predicate'] === 'is_string') {
                continue;
            }
            $offenders[] = $site['method'] . '() line ' . $site['line']
                . ' uses ' . $site['predicate'] . '()';
        }

        $this->assertSame(
            [],
            $offenders,
            "A method in MusicLibraryScanner coerces " . self::COLUMN . " with a NON-STRING\n"
            . "predicate. That column is a CHAR(36) UUID (migration 070); every numeric\n"
            . "predicate is false for every UUID, so the coercion always yields the fallback.\n"
            . "Offending site(s):\n  " . implode("\n  ", $offenders) . "\n"
            . 'FIX: use is_string($row[\'' . self::COLUMN . '\']) && $row[\'' . self::COLUMN . '\'] !== \'\'.'
        );
    }

    /**
     * ANTI-VACUITY. Everything above is an assertion over a token stream, so all of it
     * passes trivially if the stream is empty — a moved file, a renamed method, a
     * tokenizer returning nothing. This test fails loudly in each of those cases.
     *
     * Three independent floors:
     *  1. the scanner source tokenizes to a large number of significant tokens;
     *  2. all three named methods still exist on the class;
     *  3. the scan discovers EXACTLY the three known sites, in exactly those methods —
     *     a strict set comparison, never a substring or a count alone.
     */
    public function testTheTokenScanIsNotVacuous(): void
    {
        $tokens = self::significantTokens();
        $this->assertGreaterThan(
            2000,
            count($tokens),
            'ANTI-VACUITY: tokenizing ' . self::SCANNER . ' produced only ' . count($tokens)
            . " significant tokens, far below the floor for a ~2,900-line scanner. The source\n"
            . "this guard reads is empty or unreadable, so every other assertion in this file\n"
            . 'is passing on nothing. Fix the discovery before trusting any green here.'
        );

        $reflection = new ReflectionClass(self::SCANNER);
        foreach (array_keys(self::inlineSiteProvider()) as $label) {
            $method = substr($label, (int)strpos($label, '::') + 2, -2);
            $this->assertTrue(
                $reflection->hasMethod($method),
                'ANTI-VACUITY: ' . self::SCANNER . ' has no method ' . $method . '(). This guard'
                . " names its sites explicitly, so a rename makes it guard nothing. Either the\n"
                . "method moved (update inlineSiteProvider() and this message together) or the\n"
                . 'inline coercion was refactored away (then delete this file and say so in S127).'
            );
        }

        $discovered = [];
        foreach (self::coercionSites() as $site) {
            $discovered[] = $site['method'];
        }
        sort($discovered);

        $this->assertSame(
            ['ensurePlaceholderArtist', 'upsertAlbum', 'upsertArtist'],
            $discovered,
            "ANTI-VACUITY: the token scan for `\$row['" . self::COLUMN . "']` inside a\n"
            . "type-predicate call did not find exactly the three known inline sites. Found: "
            . ($discovered === [] ? '(none)' : implode(', ', $discovered)) . ".\n"
            . "Zero found means the guard is hollow and proves nothing. More than three means a\n"
            . "FOURTH inline copy of the predicate has appeared — that is the drift this step\n"
            . "exists to catch: add it to inlineSiteProvider() so it is pinned too, and update\n"
            . 'the "six sites" wording in the three DTO docblocks to match the new count.'
        );
    }

    /**
     * Every discovered site must sit inside one of the two declared methods, proven
     * by LINE RANGE off Reflection rather than by trusting the scan's own bookkeeping.
     * This is the cross-check that makes the set comparison above meaningful: it is
     * the only assertion here that ties a source line to a real declared method.
     */
    public function testEverySiteLiesInsideTheMethodItIsAttributedTo(): void
    {
        $sites = self::coercionSites();
        $this->assertNotSame([], $sites, 'ANTI-VACUITY: no coercion site discovered at all.');

        foreach ($sites as $site) {
            $method = new ReflectionMethod(self::SCANNER, $site['method']);
            $this->assertGreaterThanOrEqual(
                $method->getStartLine(),
                $site['line'],
                'site attribution is broken: line ' . $site['line'] . ' is above '
                . $site['method'] . '()'
            );
            $this->assertLessThanOrEqual(
                $method->getEndLine(),
                $site['line'],
                'site attribution is broken: line ' . $site['line'] . ' is below '
                . $site['method'] . '()'
            );
        }
    }

    // ---------------------------------------------------------------------
    // Discovery
    // ---------------------------------------------------------------------

    /**
     * Every place in the scanner where a single-argument type predicate is applied
     * directly to `$something['media_item_id']`, with the enclosing method and line.
     *
     * The DTO helpers deliberately do NOT match this shape — they bind
     * `$row['media_item_id'] ?? null` to a local first and then test `$value` — which
     * is why this scan is specific to the inline sites and cannot double-count the
     * three sites MusicDtoMediaItemIdTest already owns.
     *
     * @return list<array{method: string, predicate: string, line: int}>
     */
    private static function coercionSites(): array
    {
        $tokens = self::significantTokens();
        $ranges = self::methodLineRanges();
        $sites = [];

        $count = count($tokens);
        for ($i = 0; $i + 5 < $count; $i++) {
            if ($tokens[$i]->id !== T_STRING || $tokens[$i + 1]->text !== '(') {
                continue;
            }
            if ($tokens[$i + 2]->id !== T_VARIABLE || $tokens[$i + 3]->text !== '[') {
                continue;
            }
            if ($tokens[$i + 4]->text !== "'" . self::COLUMN . "'" || $tokens[$i + 5]->text !== ']') {
                continue;
            }
            $line = $tokens[$i]->line;
            $method = null;
            foreach ($ranges as $name => $range) {
                if ($line >= $range[0] && $line <= $range[1]) {
                    $method = $name;
                    break;
                }
            }
            if ($method === null) {
                continue;
            }
            $sites[] = [
                'method' => $method,
                'predicate' => strtolower($tokens[$i]->text),
                'line' => $line,
            ];
        }

        return $sites;
    }

    /**
     * Start/end line of every method DECLARED on the scanner (inherited methods are
     * skipped — their lines belong to another file).
     *
     * @return array<string, array{int, int}>
     */
    private static function methodLineRanges(): array
    {
        $reflection = new ReflectionClass(self::SCANNER);
        $ranges = [];
        foreach ($reflection->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== self::SCANNER) {
                continue;
            }
            $start = $method->getStartLine();
            $end = $method->getEndLine();
            if ($start === false || $end === false) {
                continue;
            }
            $ranges[$method->getName()] = [$start, $end];
        }

        return $ranges;
    }

    // ---------------------------------------------------------------------
    // Token normalisation
    // ---------------------------------------------------------------------

    /**
     * The scanner's tokens with ALL whitespace, comments and doc comments removed —
     * memoised, because six tests read it and the file is ~2,900 lines.
     *
     * Dropping comments is the load-bearing part, not an optimisation: this tree
     * contains several comments that quote `is_numeric($row['media_item_id'])` while
     * explaining why it is wrong. A raw-text guard would match its own documentation
     * and report a defect that is not there — and would equally keep reporting green
     * if the real code changed but a comment still carried the old text.
     *
     * @return list<PhpToken>
     */
    private static function significantTokens(): array
    {
        /** @var list<PhpToken>|null $memo */
        static $memo = null;
        if ($memo !== null) {
            return $memo;
        }

        $file = (new ReflectionClass(self::SCANNER))->getFileName();
        $source = $file === false ? '' : (string)file_get_contents($file);

        $memo = [];
        foreach (PhpToken::tokenize($source) as $token) {
            if ($token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_INLINE_HTML])) {
                continue;
            }
            $memo[] = $token;
        }

        return $memo;
    }

    /**
     * The significant tokens of one method, joined by single spaces — the canonical
     * form every assertion above matches against. Reformatting the method changes
     * nothing here; changing what it computes does.
     */
    private static function significantTokenTextOfMethod(string $method): string
    {
        $ranges = self::methodLineRanges();
        if (!isset($ranges[$method])) {
            return '';
        }
        [$start, $end] = $ranges[$method];

        $parts = [];
        foreach (self::significantTokens() as $token) {
            if ($token->line >= $start && $token->line <= $end) {
                $parts[] = $token->text;
            }
        }

        return implode(' ', $parts);
    }

    // ---------------------------------------------------------------------
    // Assertions
    // ---------------------------------------------------------------------

    /**
     * `$pattern` is written in the canonical spaced-token form, with the literal
     * `$ROW` standing for "any variable" so that renaming `$firstRow` does not red a
     * guard about coercion semantics.
     *
     * ⚠ Deliberately NOT `assertStringContainsString` on a path-like literal: S236
     * records that `'…/next-up-MUTATED'` CONTAINS `'…/next-up'`, so a substring
     * assertion passes the exact mutation it exists to catch. Here the needle ends at
     * a closing `)` or a quoted `''`, and the haystack is space-delimited tokens, so
     * a token cannot be extended without breaking the match — but the rule is worth
     * restating where the next reader is.
     */
    private function assertMatchesTokenSubsequence(string $pattern, string $haystack, string $message): void
    {
        $this->assertSame(
            1,
            preg_match(self::toRegex($pattern), $haystack),
            $message
        );
    }

    private function assertDoesNotMatchTokenSubsequence(string $pattern, string $haystack, string $message): void
    {
        $this->assertSame(
            0,
            preg_match(self::toRegex($pattern), $haystack),
            $message
        );
    }

    /** Turns a canonical spaced-token pattern into an anchored-per-token regex. */
    private static function toRegex(string $pattern): string
    {
        $parts = [];
        foreach (explode(' ', $pattern) as $token) {
            $parts[] = $token === '$ROW' ? '\$[A-Za-z_][A-Za-z0-9_]*' : preg_quote($token, '/');
        }

        return '/' . implode(' ', $parts) . '/';
    }
}
