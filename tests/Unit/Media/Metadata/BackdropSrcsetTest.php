<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Metadata\BackdropSrcset;

final class BackdropSrcsetTest extends TestCase
{
    public function testReturnsNullForNullOrEmpty(): void
    {
        $this->assertNull(BackdropSrcset::forBackdropUrl(null));
        $this->assertNull(BackdropSrcset::forBackdropUrl(''));
        $this->assertNull(BackdropSrcset::largeUrl(null));
        $this->assertNull(BackdropSrcset::largeUrl(''));
    }

    public function testReturnsNullForNonTmdbUrls(): void
    {
        $this->assertNull(BackdropSrcset::forBackdropUrl('https://example.com/bg.jpg'));
        $this->assertNull(BackdropSrcset::forBackdropUrl('/local/backdrops/abc.jpg'));
        $this->assertNull(BackdropSrcset::largeUrl('https://example.com/bg.jpg'));
        $this->assertNull(BackdropSrcset::largeUrl('not a url'));
    }

    public function testRejectsSpoofedTmdbHost(): void
    {
        // The TMDB host must come right after the scheme — a look-alike path or
        // sub/super-domain must not be rewritten into TMDB URLs.
        $this->assertNull(BackdropSrcset::forBackdropUrl('https://evil.com/image.tmdb.org/t/p/w500/bg.jpg'));
        $this->assertNull(BackdropSrcset::largeUrl('https://image.tmdb.org.evil.com/t/p/w500/bg.jpg'));
    }

    public function testWidthSwapsAW500BackdropToLargeSizesAndOriginal(): void
    {
        $srcset = BackdropSrcset::forBackdropUrl('https://image.tmdb.org/t/p/w500/bg123.jpg');

        $this->assertSame(
            'https://image.tmdb.org/t/p/w780/bg123.jpg 780w, '
            . 'https://image.tmdb.org/t/p/w1280/bg123.jpg 1280w, '
            . 'https://image.tmdb.org/t/p/original/bg123.jpg 1920w',
            $srcset,
        );
    }

    public function testLargeUrlSwapsAnySizeSegmentToOriginal(): void
    {
        $this->assertSame(
            'https://image.tmdb.org/t/p/original/bg123.jpg',
            BackdropSrcset::largeUrl('https://image.tmdb.org/t/p/w500/bg123.jpg'),
        );
        // Already-original passes through as original (idempotent).
        $this->assertSame(
            'https://image.tmdb.org/t/p/original/bg123.jpg',
            BackdropSrcset::largeUrl('https://image.tmdb.org/t/p/original/bg123.jpg'),
        );
    }

    public function testEveryCandidateCarriesAWidthDescriptor(): void
    {
        $srcset = BackdropSrcset::forBackdropUrl('https://image.tmdb.org/t/p/w1280/x.jpg');

        $this->assertNotNull($srcset);
        foreach (explode(', ', $srcset) as $candidate) {
            $this->assertMatchesRegularExpression('/ \d+w$/', $candidate);
        }
    }

    public function testPreservesTheUrlScheme(): void
    {
        $srcset = BackdropSrcset::forBackdropUrl('http://image.tmdb.org/t/p/w780/x.jpg');

        $this->assertNotNull($srcset);
        $this->assertStringStartsWith('http://image.tmdb.org/t/p/w780/x.jpg 780w', $srcset);
        $this->assertSame(
            'http://image.tmdb.org/t/p/original/x.jpg',
            BackdropSrcset::largeUrl('http://image.tmdb.org/t/p/w780/x.jpg'),
        );
    }

    // ---- S101: the LIST-ROW size budget (rowUrl / rowSrcset) ----

    public function testRowHelpersReturnNullForNullEmptyOrNonTmdbUrls(): void
    {
        $this->assertNull(BackdropSrcset::rowUrl(null));
        $this->assertNull(BackdropSrcset::rowUrl(''));
        $this->assertNull(BackdropSrcset::rowSrcset(null));
        $this->assertNull(BackdropSrcset::rowSrcset(''));
        $this->assertNull(BackdropSrcset::rowUrl('https://assets.fanart.tv/fanart/movies/1/bg.jpg'));
        $this->assertNull(BackdropSrcset::rowSrcset('/api/v1/artwork/abc?size=w780'));
    }

    public function testRowHelpersRejectSpoofedTmdbHost(): void
    {
        $this->assertNull(BackdropSrcset::rowUrl('https://evil.com/image.tmdb.org/t/p/w500/bg.jpg'));
        $this->assertNull(BackdropSrcset::rowSrcset('https://image.tmdb.org.evil.com/t/p/w500/bg.jpg'));
    }

    public function testRowUrlStepsTheStoredW500UpToW780(): void
    {
        // Backdrops are STORED at /w500, which is narrower than a row strip needs.
        $this->assertSame(
            'https://image.tmdb.org/t/p/w780/bg123.jpg',
            BackdropSrcset::rowUrl('https://image.tmdb.org/t/p/w500/bg123.jpg'),
        );
        // Any size segment (including /original) normalises DOWN to the row width,
        // so a row never fetches the multi-megabyte hero asset.
        $this->assertSame(
            'https://image.tmdb.org/t/p/w780/bg123.jpg',
            BackdropSrcset::rowUrl('https://image.tmdb.org/t/p/original/bg123.jpg'),
        );
        $this->assertSame(
            'http://image.tmdb.org/t/p/w780/x.jpg',
            BackdropSrcset::rowUrl('http://image.tmdb.org/t/p/w1280/x.jpg'),
        );
    }

    public function testRowSrcsetAdvertisesExactlyW780AndW1280(): void
    {
        $srcset = BackdropSrcset::rowSrcset('https://image.tmdb.org/t/p/w500/bg123.jpg');

        $this->assertSame(
            'https://image.tmdb.org/t/p/w780/bg123.jpg 780w, '
            . 'https://image.tmdb.org/t/p/w1280/bg123.jpg 1280w',
            $srcset,
        );
    }

    /**
     * The reason this pair exists at all: a list page returns up to 100 rows, so
     * the row srcset must never carry `/original` the way {@see BackdropSrcset::forBackdropUrl()}
     * (a single hero image) deliberately does.
     */
    public function testRowSrcsetNeverAdvertisesOriginalUnlikeTheHeroSrcset(): void
    {
        $url = 'https://image.tmdb.org/t/p/w500/bg123.jpg';

        $row = BackdropSrcset::rowSrcset($url);
        $hero = BackdropSrcset::forBackdropUrl($url);

        $this->assertIsString($row);
        $this->assertIsString($hero);
        $this->assertStringNotContainsString('/original/', $row);
        $this->assertStringNotContainsString('1920w', $row);
        $this->assertStringContainsString('/original/', $hero, 'the hero srcset still advertises /original');
        $this->assertCount(2, explode(',', $row), 'the row candidate list stays short');
        $this->assertCount(3, explode(',', $hero));
        $this->assertLessThan(strlen($hero), strlen($row), 'the row srcset is the cheaper payload');
    }

    public function testEveryRowCandidateCarriesAWidthDescriptor(): void
    {
        $srcset = BackdropSrcset::rowSrcset('https://image.tmdb.org/t/p/w1280/x.jpg');

        $this->assertNotNull($srcset);
        foreach (explode(', ', $srcset) as $candidate) {
            $this->assertMatchesRegularExpression('/ \d+w$/', $candidate);
        }
    }

    /**
     * The two size budgets share ONE width ladder: the hero srcset is EXACTLY the
     * row srcset plus the `/original` candidate. Pinned structurally (not as two
     * hard-coded strings) so tuning the ladder can never move one budget without
     * the other — the drift the duplicated `WIDTHS`/`ROW_WIDTHS` pair invited.
     *
     * @dataProvider ladderUrlProvider
     */
    public function testTheHeroSrcsetIsExactlyTheRowSrcsetPlusOriginal(string $url): void
    {
        $row = BackdropSrcset::rowSrcset($url);
        $hero = BackdropSrcset::forBackdropUrl($url);
        $large = BackdropSrcset::largeUrl($url);

        $this->assertIsString($row);
        $this->assertIsString($hero);
        $this->assertIsString($large);
        $this->assertSame($row . ', ' . $large . ' 1920w', $hero);
        // …and the shared ladder therefore has the same steps in both, in order.
        $rowWidths = self::descriptors($row);
        $this->assertSame($rowWidths, array_slice(self::descriptors($hero), 0, count($rowWidths)));
    }

    /**
     * The single-URL row base is the SMALLEST step of the shared ladder, so a
     * non-`srcset` client fetches the cheapest candidate rather than a size that
     * is not on the ladder at all.
     *
     * @dataProvider ladderUrlProvider
     */
    public function testRowUrlUsesTheSmallestStepOfTheSharedLadder(string $url): void
    {
        $row = BackdropSrcset::rowSrcset($url);
        $rowUrl = BackdropSrcset::rowUrl($url);

        $this->assertIsString($row);
        $this->assertIsString($rowUrl);
        $first = explode(', ', $row)[0];
        $this->assertSame($rowUrl . ' ' . self::descriptors($row)[0] . 'w', $first);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ladderUrlProvider(): iterable
    {
        yield 'stored w500' => ['https://image.tmdb.org/t/p/w500/bg123.jpg'];
        yield 'already original' => ['https://image.tmdb.org/t/p/original/bg123.jpg'];
        yield 'http scheme, nested path' => ['http://image.tmdb.org/t/p/w1280/a/b/c.jpg'];
    }

    /**
     * The numeric `w` descriptors of a srcset, in order.
     *
     * @return list<int>
     */
    private static function descriptors(string $srcset): array
    {
        $out = [];
        foreach (explode(', ', $srcset) as $candidate) {
            $descriptor = substr($candidate, (int) strrpos($candidate, ' ') + 1);
            $out[] = (int) rtrim($descriptor, 'w');
        }

        return $out;
    }
}
