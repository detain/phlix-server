<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Library\CanonicalKey;

class CanonicalKeyTest extends TestCase
{
    public function testSeparatorVariantsCollapseToOneKey(): void
    {
        $expected = 'hunterxhunter';
        $this->assertSame($expected, CanonicalKey::forTitle('Hunter x Hunter'));
        $this->assertSame($expected, CanonicalKey::forTitle('Hunter.x.Hunter'));
        $this->assertSame($expected, CanonicalKey::forTitle('HunterxHunter'));

        // All three converge to the SAME key.
        $this->assertSame(
            CanonicalKey::forTitle('Hunter x Hunter'),
            CanonicalKey::forTitle('Hunter.x.Hunter')
        );
        $this->assertSame(
            CanonicalKey::forTitle('Hunter.x.Hunter'),
            CanonicalKey::forTitle('HunterxHunter')
        );
    }

    public function testLeadingArticleIsStripped(): void
    {
        // "The Matrix" === "Matrix" at the title-key level.
        $this->assertSame('matrix', CanonicalKey::forTitle('The Matrix'));
        $this->assertSame('matrix', CanonicalKey::forTitle('Matrix'));
        $this->assertSame(
            CanonicalKey::forTitle('The Matrix'),
            CanonicalKey::forTitle('Matrix')
        );

        // 'a' and 'an' are stripped too.
        $this->assertSame('newhope', CanonicalKey::forTitle('A New Hope'));
        $this->assertSame('americantail', CanonicalKey::forTitle('An American Tail'));
    }

    public function testLeadingArticleOnlyStrippedAsWholeWord(): void
    {
        // "Theatre" must NOT have "the" stripped — only a whole leading article
        // followed by a space is removed.
        $this->assertSame('theatre', CanonicalKey::forTitle('Theatre'));
        $this->assertSame('anthology', CanonicalKey::forTitle('Anthology'));
    }

    public function testForTitleEmptyWhenNoAlphanumerics(): void
    {
        $this->assertSame('', CanonicalKey::forTitle('!!!'));
        $this->assertSame('', CanonicalKey::forTitle(''));
    }

    public function testTmdbIdKeysRegardlessOfTitle(): void
    {
        $key = CanonicalKey::forItem('Some Movie', 2020, ['tmdb' => '456']);
        $this->assertSame('tmdb:456', $key);

        // Same tmdb id, totally different title/year → same key.
        $other = CanonicalKey::forItem('Completely Different', 1999, ['tmdb' => 456]);
        $this->assertSame('tmdb:456', $other);
        $this->assertSame($key, $other);
    }

    public function testImdbIdWinsOverTmdbId(): void
    {
        $key = CanonicalKey::forItem('Whatever', 2011, ['imdb' => 'tt0123456', 'tmdb' => '456']);
        $this->assertSame('imdb:tt0123456', $key);
    }

    public function testSameTitleAndYearShareKey(): void
    {
        $a = CanonicalKey::forItem('Hunter x Hunter', 2011, []);
        $b = CanonicalKey::forItem('Hunter.x.Hunter', 2011, []);
        $this->assertSame('hunterxhunter:2011', $a);
        $this->assertSame($a, $b);
    }

    public function testDifferentYearProducesDifferentKey(): void
    {
        // HxH 1999 vs 2011 are legitimately distinct shows.
        $hxh1999 = CanonicalKey::forItem('Hunter x Hunter', 1999, []);
        $hxh2011 = CanonicalKey::forItem('Hunter x Hunter', 2011, []);
        $this->assertSame('hunterxhunter:1999', $hxh1999);
        $this->assertSame('hunterxhunter:2011', $hxh2011);
        $this->assertNotSame($hxh1999, $hxh2011);
    }

    public function testForItemFallsBackToTitleKeyWhenNoYearOrIds(): void
    {
        $this->assertSame('hunterxhunter', CanonicalKey::forItem('Hunter x Hunter', null, []));
        $this->assertSame('matrix', CanonicalKey::forItem('The Matrix', null, []));
    }

    public function testEmptyOrMissingExternalIdsAreIgnored(): void
    {
        // Empty / null / whitespace-only ids fall through to the title key.
        $this->assertSame(
            'matrix:1999',
            CanonicalKey::forItem('The Matrix', 1999, ['imdb' => '', 'tmdb' => null])
        );
        $this->assertSame(
            'matrix:1999',
            CanonicalKey::forItem('The Matrix', 1999, ['imdb' => '   '])
        );

        // A blank imdb but a present tmdb → tmdb wins.
        $this->assertSame(
            'tmdb:99',
            CanonicalKey::forItem('The Matrix', 1999, ['imdb' => '', 'tmdb' => '99'])
        );
    }
}
