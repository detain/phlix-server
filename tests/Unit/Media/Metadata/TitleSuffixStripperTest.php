<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Metadata\TitleSuffixStripper;

/**
 * Unit tests for {@see TitleSuffixStripper}: the single source of truth for
 * peeling trailing edition/quality "noise" phrases off a media title.
 *
 * @since 0.22.0
 */
final class TitleSuffixStripperTest extends TestCase
{
    /**
     * Longest-first ordering: a multi-word phrase must be peeled whole rather
     * than leaving a residue from a shorter prefix match.
     *
     * @dataProvider longestFirstCases
     */
    public function testLongestFirstOrdering(string $input, string $expected): void
    {
        $this->assertSame($expected, TitleSuffixStripper::strip($input));
    }

    /** @return array<string, array{0:string,1:string}> */
    public static function longestFirstCases(): array
    {
        return [
            'unrated directors cut' => ['Highlander Unrated Directors Cut', 'Highlander'],
            'extended cut'          => ['Aliens Extended Cut', 'Aliens'],
            'directors cut'         => ['Blade Runner Directors Cut', 'Blade Runner'],
            'theatrical cut'        => ['Watchmen Theatrical Cut', 'Watchmen'],
            'alternate ending'      => ['District 9 Alternate Ending', 'District 9'],
        ];
    }

    /**
     * Separators ` -._` between the title and the noise phrase must be absorbed.
     * (The stripper operates on a phrase containing a literal space — callers
     * normalise dots/underscores to spaces before invoking it — so the separator
     * under test is the one joining the title to the phrase. Note: an underscore
     * is a word character, so the `\b`-anchored regex requires a NON-word
     * separator like ` -.` immediately before the phrase, matching the original
     * SceneFilenameNormalizer behavior.)
     *
     * @dataProvider separatorCases
     */
    public function testSeparatorVariants(string $input): void
    {
        $this->assertSame('Highlander', TitleSuffixStripper::strip($input));
    }

    /** @return array<string, array{0:string}> */
    public static function separatorCases(): array
    {
        return [
            'space' => ['Highlander Directors Cut'],
            'dash'  => ['Highlander - Directors Cut'],
            'dot'   => ['Highlander.Directors Cut'],
        ];
    }

    /**
     * An underscore is a word character, so there is no `\b` between it and the
     * noise token — the phrase is NOT peeled across a bare underscore. Callers
     * (SceneFilenameNormalizer / EpisodeFilenameParser) normalise `_` to spaces
     * before invoking strip(), so this matches real-world behavior.
     */
    public function testUnderscoreNotAbsorbedWithoutWordBoundary(): void
    {
        $this->assertSame('Highlander_Remastered', TitleSuffixStripper::strip('Highlander_Remastered'));
    }

    public function testStackedSuffixes(): void
    {
        $this->assertSame(
            'Highlander',
            TitleSuffixStripper::strip('Highlander Uncut Remastered Directors Cut')
        );
    }

    /**
     * Case-insensitive matching.
     *
     * @dataProvider caseCases
     */
    public function testCaseInsensitivity(string $input): void
    {
        $this->assertSame('Blade Runner', TitleSuffixStripper::strip($input));
    }

    /** @return array<string, array{0:string}> */
    public static function caseCases(): array
    {
        return [
            'lower' => ['Blade Runner directors cut'],
            'upper' => ['Blade Runner DIRECTORS CUT'],
            'title' => ['Blade Runner Directors Cut'],
            'mixed' => ['Blade Runner DiReCtOrS cUt'],
        ];
    }

    public function testDanglingAmpersandFromUncutUnrated(): void
    {
        // Simulates the residue left after token-level quality stripping has
        // removed "UNRATED" (a QUALITY_TOKEN), leaving "Dune UNCUT &".
        $this->assertSame('Dune', TitleSuffixStripper::strip('Dune UNCUT &'));
        // And the whole phrase peels in one call.
        $this->assertSame('Dune', TitleSuffixStripper::strip('Dune Uncut & Unrated'));
    }

    public function testYify(): void
    {
        $this->assertSame('Foo', TitleSuffixStripper::strip('Foo YIFY'));
    }

    public function testSingleTokenNoiseNeverEmptiesByDefault(): void
    {
        // A film literally named "DC" must survive (allowEmpty=false default).
        $this->assertSame('DC', TitleSuffixStripper::strip('DC'));
        $this->assertSame('Uncut', TitleSuffixStripper::strip('Uncut'));
        $this->assertSame('Remastered', TitleSuffixStripper::strip('Remastered'));
    }

    public function testAllowEmptyPermitsEmptyResult(): void
    {
        // With allowEmpty=true a single-token noise title may reduce to ''.
        $this->assertSame('', TitleSuffixStripper::strip('DC', true));
        $this->assertSame('', TitleSuffixStripper::strip('Directors Cut', true));
        // Mid-content title still keeps its words even with allowEmpty=true.
        $this->assertSame('Blade Runner', TitleSuffixStripper::strip('Blade Runner Directors Cut', true));
    }

    /**
     * A MULTI-token noise phrase that is the entire title must also survive when
     * allowEmpty=false (the guard isn't limited to single-token phrases), and
     * must collapse to '' under allowEmpty=true. Pins both halves of the guard
     * on a multi-token input (the single-token case is covered separately).
     */
    public function testAllowEmptyFalseKeepsMultiTokenNoiseTitle(): void
    {
        $this->assertSame('Directors Cut', TitleSuffixStripper::strip('Directors Cut', false));
        $this->assertSame('Uncut & Unrated', TitleSuffixStripper::strip('Uncut & Unrated', false));
        $this->assertSame('', TitleSuffixStripper::strip('Uncut & Unrated', true));
    }

    /**
     * Stacked noise tokens joined by the ` -._` separators (and an interior dash)
     * must all peel in one call — exercises the rescan loop across separators.
     */
    public function testStackedSuffixesAcrossSeparators(): void
    {
        $this->assertSame('Highlander', TitleSuffixStripper::strip('Highlander - Uncut.Remastered Directors Cut'));
        $this->assertSame('Aliens', TitleSuffixStripper::strip('Aliens.Extended-Remastered'));
    }

    /**
     * A title that legitimately CONTAINS a noise word mid-title (not trailing on
     * a word boundary) is left fully intact even when a real trailing noise
     * phrase is also present and peeled.
     */
    public function testNoiseWordMidTitleLeftIntact(): void
    {
        // "Cut" mid-title kept; trailing "Directors Cut" peeled.
        $this->assertSame('The Cutting Room', TitleSuffixStripper::strip('The Cutting Room Directors Cut'));
        // "Extended" as a real mid-title word survives when nothing trails it.
        $this->assertSame('The Extended Universe Saga', TitleSuffixStripper::strip('The Extended Universe Saga'));
    }

    /**
     * Mid-string / substring noise must NOT be stripped — only genuine trailing
     * phrases on a word boundary.
     *
     * @dataProvider midStringCases
     */
    public function testMidStringNoiseNotStripped(string $input, string $expected): void
    {
        $this->assertSame($expected, TitleSuffixStripper::strip($input));
    }

    /** @return array<string, array{0:string,1:string}> */
    public static function midStringCases(): array
    {
        return [
            'leading uncut'       => ['Uncut Gems', 'Uncut Gems'],
            'directors mid'       => ['The Directors Cut Saga', 'The Directors Cut Saga'],
            'substring uncut'     => ['Uncuttable Bonds', 'Uncuttable Bonds'],
            'word-boundary guard' => ['The Great Uncutting', 'The Great Uncutting'],
            // trailing noise still peels even when a similar word appears earlier
            'trailing only peels' => ['Uncut Gems Directors Cut', 'Uncut Gems'],
        ];
    }

    public function testNoNoiseLeavesTitleUnchanged(): void
    {
        $this->assertSame('The Matrix', TitleSuffixStripper::strip('The Matrix'));
        $this->assertSame('Tom & Jerry', TitleSuffixStripper::strip('Tom & Jerry'));
    }

    public function testWhitespaceTrimmed(): void
    {
        $this->assertSame('Blade Runner', TitleSuffixStripper::strip('  Blade Runner Directors Cut  '));
    }
}
