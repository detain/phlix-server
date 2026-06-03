<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Metadata\PosterSrcset;

final class PosterSrcsetTest extends TestCase
{
    public function testReturnsNullForNullOrEmpty(): void
    {
        $this->assertNull(PosterSrcset::forPosterUrl(null));
        $this->assertNull(PosterSrcset::forPosterUrl(''));
    }

    public function testReturnsNullForNonTmdbUrls(): void
    {
        $this->assertNull(PosterSrcset::forPosterUrl('https://example.com/poster.jpg'));
        $this->assertNull(PosterSrcset::forPosterUrl('/local/posters/abc.jpg'));
        $this->assertNull(PosterSrcset::forPosterUrl('not a url'));
    }

    public function testRejectsSpoofedTmdbHost(): void
    {
        // The TMDB host must come right after the scheme — a look-alike path or
        // sub/super-domain must not be rewritten into TMDB URLs.
        $this->assertNull(PosterSrcset::forPosterUrl('https://evil.com/image.tmdb.org/t/p/w500/abc.jpg'));
        $this->assertNull(PosterSrcset::forPosterUrl('https://image.tmdb.org.evil.com/t/p/w500/abc.jpg'));
    }

    public function testBuildsWidthDescriptorSrcsetFromAW500Url(): void
    {
        $srcset = PosterSrcset::forPosterUrl('https://image.tmdb.org/t/p/w500/abc123.jpg');

        $this->assertSame(
            'https://image.tmdb.org/t/p/w185/abc123.jpg 185w, '
            . 'https://image.tmdb.org/t/p/w342/abc123.jpg 342w, '
            . 'https://image.tmdb.org/t/p/w500/abc123.jpg 500w, '
            . 'https://image.tmdb.org/t/p/w780/abc123.jpg 780w',
            $srcset,
        );
    }

    public function testSwapsAnySizeSegmentIncludingOriginal(): void
    {
        $srcset = PosterSrcset::forPosterUrl('https://image.tmdb.org/t/p/original/abc123.jpg');

        $this->assertNotNull($srcset);
        $this->assertStringContainsString('/w185/abc123.jpg 185w', $srcset);
        $this->assertStringContainsString('/w780/abc123.jpg 780w', $srcset);
        // the source size segment is fully replaced — no `original` leaks through
        $this->assertStringNotContainsString('original', $srcset);
    }

    public function testEveryCandidateCarriesAWidthDescriptor(): void
    {
        // Matches the client's `srcsetHasWidthDescriptor()` so MediaCard emits a
        // `sizes` attribute (a density srcset would not).
        $srcset = PosterSrcset::forPosterUrl('https://image.tmdb.org/t/p/w342/x.jpg');

        $this->assertNotNull($srcset);
        foreach (explode(', ', $srcset) as $candidate) {
            $this->assertMatchesRegularExpression('/ \d+w$/', $candidate);
        }
    }

    public function testPreservesTheUrlScheme(): void
    {
        $srcset = PosterSrcset::forPosterUrl('http://image.tmdb.org/t/p/w342/x.jpg');

        $this->assertNotNull($srcset);
        $this->assertStringStartsWith('http://image.tmdb.org/t/p/w185/x.jpg 185w', $srcset);
    }
}
