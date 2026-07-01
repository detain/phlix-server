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
}
