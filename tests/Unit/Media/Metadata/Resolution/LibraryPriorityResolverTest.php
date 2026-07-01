<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata\Resolution;

use Phlix\Media\Metadata\Resolution\LibraryPriorityResolver;
use Phlix\Media\Metadata\Resolution\PriorityConfig;
use Phlix\Media\Metadata\Resolution\PriorityFieldResolver;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Media\Metadata\Resolution\LibraryPriorityResolver
 */
final class LibraryPriorityResolverTest extends TestCase
{
    /** A representative global effective map (config default <- admin override). */
    private const GLOBAL_MAP = [
        'movie'  => ['tmdb', 'imdb'],
        'series' => ['tmdb', 'imdb'],
        'anime'  => ['anidb', 'tvdb', 'local'],
    ];

    /**
     * A null override returns the SAME global config instance unchanged (no copy,
     * no re-merge) so the common "no per-library override" path is cheap.
     */
    public function testNullOverrideReturnsGlobalUnchanged(): void
    {
        $global = new PriorityConfig(self::GLOBAL_MAP, PriorityFieldResolver::GENRES_UNION);
        $resolver = new LibraryPriorityResolver($global);

        $this->assertSame($global, $resolver->effectiveFor(null));
    }

    /**
     * An empty-map override also returns the global config unchanged.
     */
    public function testEmptyOverrideReturnsGlobalUnchanged(): void
    {
        $global = new PriorityConfig(self::GLOBAL_MAP);
        $resolver = new LibraryPriorityResolver($global);

        $this->assertSame($global, $resolver->effectiveFor([]));
    }

    /**
     * An override REPLACES the named type's list outright (per-type merge), while
     * untouched types fall back to the global list.
     */
    public function testOverrideReplacesNamedTypeAndKeepsOthers(): void
    {
        $global = new PriorityConfig(self::GLOBAL_MAP);
        $resolver = new LibraryPriorityResolver($global);

        $effective = $resolver->effectiveFor(['movie' => ['imdb', 'tmdb']]);

        // Not the same instance — a fresh merged config.
        $this->assertNotSame($global, $effective);
        // The named type is replaced entirely...
        $this->assertSame(['imdb', 'tmdb'], $effective->orderFor('movie'));
        // ...and untouched types keep the global list.
        $this->assertSame(['tmdb', 'imdb'], $effective->orderFor('series'));
        $this->assertSame(['anidb', 'tvdb', 'local'], $effective->orderFor('anime'));
    }

    /**
     * An override may introduce a type NOT present in the global map; that type
     * takes the override list while everything else stays global.
     */
    public function testOverrideAddsNewType(): void
    {
        $global = new PriorityConfig(self::GLOBAL_MAP);
        $resolver = new LibraryPriorityResolver($global);

        $effective = $resolver->effectiveFor(['documentary' => ['imdb']]);

        $this->assertSame(['imdb'], $effective->orderFor('documentary'));
        $this->assertSame(['tmdb', 'imdb'], $effective->orderFor('movie'));
    }

    /**
     * The global genres mode is preserved on the merged config (the override only
     * re-orders sources; it never changes genres_mode).
     */
    public function testGenresModePreserved(): void
    {
        $global = new PriorityConfig(self::GLOBAL_MAP, PriorityFieldResolver::GENRES_UNION);
        $resolver = new LibraryPriorityResolver($global);

        $effective = $resolver->effectiveFor(['movie' => ['imdb']]);

        $this->assertSame(PriorityFieldResolver::GENRES_UNION, $effective->genresMode());
    }
}
