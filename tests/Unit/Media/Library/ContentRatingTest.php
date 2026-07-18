<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library;

use Phlix\Auth\UserProfileManager;
use Phlix\Media\Library\ContentRating;
use Phlix\Media\Library\ItemRepository;
use PHPUnit\Framework\TestCase;

/**
 * Covers the canonical content-rating vocabulary + normalization and asserts the
 * per-class RATING_ORDER maps derive from it (enum-expansion contract).
 */
final class ContentRatingTest extends TestCase
{
    public function testMovieRatingsKeepTheirRelativeOrder(): void
    {
        // Existing MPAA ratings must keep working and stay strictly ordered.
        $ranks = ContentRating::RANKS;
        $this->assertLessThan($ranks['PG'], $ranks['G']);
        $this->assertLessThan($ranks['PG-13'], $ranks['PG']);
        $this->assertLessThan($ranks['R'], $ranks['PG-13']);
        $this->assertLessThan($ranks['NC-17'], $ranks['R']);
        $this->assertLessThan($ranks['X'], $ranks['NC-17']);
        $this->assertLessThan($ranks['UNRATED'], $ranks['X']);
    }

    public function testTvRatingsInterleaveAtExpectedRanks(): void
    {
        $r = ContentRating::RANKS;
        // rank 0: G, TV-Y, TV-G
        $this->assertSame($r['G'], $r['TV-Y']);
        $this->assertSame($r['G'], $r['TV-G']);
        // rank 1: TV-Y7 sits strictly between G and PG
        $this->assertGreaterThan($r['G'], $r['TV-Y7']);
        $this->assertLessThan($r['PG'], $r['TV-Y7']);
        // rank 2/3/4 interleave
        $this->assertSame($r['PG'], $r['TV-PG']);
        $this->assertSame($r['PG-13'], $r['TV-14']);
        $this->assertSame($r['R'], $r['TV-MA']);
    }

    public function testRanksContainsAllThirteenCanonicalValuesAndNotNr(): void
    {
        $expected = [
            'G', 'TV-Y', 'TV-G', 'TV-Y7', 'PG', 'TV-PG',
            'PG-13', 'TV-14', 'R', 'TV-MA', 'NC-17', 'X', 'UNRATED',
        ];
        $this->assertSame($expected, array_keys(ContentRating::RANKS));
        // NR is an alias of UNRATED, never a distinct key.
        $this->assertArrayNotHasKey('NR', ContentRating::RANKS);
    }

    public function testItemRepositoryAndProfileRatingOrderDeriveFromCanonical(): void
    {
        $this->assertSame(ContentRating::RANKS, ItemRepository::RATING_ORDER);
        $this->assertSame(ContentRating::RANKS, UserProfileManager::RATING_ORDER);
    }

    /**
     * @dataProvider normalizationCases
     */
    public function testNormalize(mixed $input, ?string $expected): void
    {
        $this->assertSame($expected, ContentRating::normalize($input));
    }

    /**
     * @return array<string, array{0: mixed, 1: string|null}>
     */
    public static function normalizationCases(): array
    {
        return [
            'movie passthrough' => ['PG-13', 'PG-13'],
            'tv passthrough' => ['TV-14', 'TV-14'],
            'lowercase' => ['pg-13', 'PG-13'],
            'whitespace' => ['  R  ', 'R'],
            'nr alias' => ['NR', 'UNRATED'],
            'not rated alias' => ['Not Rated', 'UNRATED'],
            'ur alias' => ['ur', 'UNRATED'],
            'unrated passthrough' => ['UNRATED', 'UNRATED'],
            'tv-y7-fv suffix' => ['TV-Y7-FV', 'TV-Y7'],
            'empty' => ['', null],
            'unknown' => ['GP', null],
            'non-string int' => [7, null],
            'null' => [null, null],
        ];
    }

    public function testRankAndIsValid(): void
    {
        $this->assertSame(3, ContentRating::rank('TV-14'));
        $this->assertSame(3, ContentRating::rank('PG-13'));
        $this->assertSame(7, ContentRating::rank('NR')); // alias → UNRATED rank
        $this->assertNull(ContentRating::rank('BOGUS'));

        $this->assertTrue(ContentRating::isValid('TV-MA'));
        $this->assertTrue(ContentRating::isValid('nr'));
        $this->assertFalse(ContentRating::isValid('BOGUS'));
        $this->assertFalse(ContentRating::isValid(null));
    }
}
