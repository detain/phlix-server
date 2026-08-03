<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

use Phlix\Media\Metadata\Resolution\SourceRegistry;
use Phlix\Media\Metadata\SeriesMetadataResolver;
use Phlix\Media\Metadata\TmdbProvider;
use Phlix\Tests\Unit\Media\Metadata\Resolution\FakeMetadataSource;
use PHPUnit\Framework\TestCase;

/**
 * F2 plugin-source dispatch on {@see SeriesMetadataResolver}.
 */
final class SeriesMetadataResolverPluginSourcesTest extends TestCase
{
    private function tmdb(): TmdbProvider
    {
        $tmdb = $this->createMock(TmdbProvider::class);
        $tmdb->method('searchTv')->willReturn([['id' => '1668', 'name' => '24']]);
        $tmdb->method('getTvDetails')->with('1668')->willReturn([
            'name' => '24',
            'overview' => 'TMDB overview.',
            'year' => 2001,
            'tmdb_id' => '1668',
            // No official_rating — the gap a plugin fills.
        ]);
        return $tmdb;
    }

    /**
     * RULE 7: flag OFF (default) with a plugin source registered → never
     * consulted, output identical to TMDB-only.
     */
    public function testDefaultPathNeverConsultsPluginSources(): void
    {
        $registry = new SourceRegistry();
        $fake = new FakeMetadataSource(
            'myanimelist',
            ['series'],
            [['id' => 's1', 'title' => '24']],
            ['official_rating' => 'TV-14'],
        );
        $registry->register($fake);

        $resolver = new SeriesMetadataResolver($this->tmdb(), null, null, null, $registry);
        $result = $resolver->resolve('24', 2001);

        $this->assertNotNull($result);
        $this->assertSame(0, $fake->searchCalls);
        $this->assertSame(0, $fake->getDetailsCalls);
        $this->assertSame(['tmdb'], $result['sources']);
        $this->assertArrayNotHasKey('plugin_ratings', $result);
        $this->assertArrayNotHasKey('official_rating', $result);
    }

    /**
     * Flag ON: plugin gap-fill surfaces under TMDB; shared field keeps TMDB's
     * value; sourceName joins sources[]; ratings surface.
     */
    public function testOptInMergesPluginGapFillUnderTmdb(): void
    {
        $registry = new SourceRegistry();
        $registry->register(new FakeMetadataSource(
            'myanimelist',
            ['series'],
            [['id' => 's1', 'title' => '24']],
            [
                'overview' => 'PLUGIN overview (should lose).',
                'official_rating' => 'TV-14',
                'ratings' => [['source' => 'imdb', 'score' => 8.4]],
            ],
        ));

        $resolver = new SeriesMetadataResolver($this->tmdb(), null, null, null, $registry);
        $result = $resolver->resolve('24', 2001, null, true);

        $this->assertNotNull($result);
        $this->assertSame('TMDB overview.', $result['overview']);
        $this->assertSame('TV-14', $result['official_rating']);
        $this->assertSame(['tmdb', 'myanimelist'], $result['sources']);
        $this->assertSame([['source' => 'imdb', 'score' => 8.4]], $result['plugin_ratings']);
    }

    /**
     * A throwing plugin source is skipped; the TMDB series result still returns.
     */
    public function testThrowingPluginSourceDoesNotBreakResolution(): void
    {
        $registry = new SourceRegistry();
        $registry->register(new FakeMetadataSource('anidb', ['series'], [], [], throwOnSearch: true));

        $resolver = new SeriesMetadataResolver($this->tmdb(), null, null, null, $registry);
        $result = $resolver->resolve('24', 2001, null, true);

        $this->assertNotNull($result);
        $this->assertSame('TMDB overview.', $result['overview']);
        $this->assertSame(['tmdb'], $result['sources']);
    }
}
