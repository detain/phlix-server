<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

use Phlix\Common\Logger\LoggerFactory;
use Phlix\Media\Metadata\Imdb\ImdbLookup;
use Phlix\Media\Metadata\MovieMetadataResolver;
use Phlix\Media\Metadata\Resolution\SourceRegistry;
use Phlix\Media\Metadata\TmdbProvider;
use Phlix\Tests\Unit\Media\Metadata\Resolution\FakeMetadataSource;
use PHPUnit\Framework\TestCase;

/**
 * F2 plugin-source dispatch on {@see MovieMetadataResolver}.
 *
 * @covers \Phlix\Media\Metadata\MovieMetadataResolver
 */
final class MovieMetadataResolverPluginSourcesTest extends TestCase
{
    protected function setUp(): void
    {
        LoggerFactory::init(__DIR__ . '/../../../../config/logger.php');
    }

    /**
     * @return array{TmdbProvider, ImdbLookup}
     */
    private function tmdbOnlyMocks(): array
    {
        $tmdb = $this->createMock(TmdbProvider::class);
        $tmdb->method('findByImdbId')->willReturn(['id' => '603']);
        $tmdb->method('getDetails')->with('603')->willReturn([
            'name' => 'The Matrix',
            'overview' => 'TMDB overview.',
            'year' => 1999,
            'tmdb_id' => '603',
            'imdb_id' => 'tt0133093',
            // Deliberately NO official_rating — that is the gap a plugin fills.
        ]);
        $imdb = $this->createMock(ImdbLookup::class);
        $imdb->method('getByImdbId')->willReturn(null);
        return [$tmdb, $imdb];
    }

    /**
     * RULE 7: with a plugin source registered but the flag OFF (the bulk-scan
     * default), the source is NEVER consulted and the output is identical to the
     * TMDB-only result — no network calls, no `plugin_ratings`, no extra source.
     */
    public function testDefaultPathNeverConsultsPluginSources(): void
    {
        [$tmdb, $imdb] = $this->tmdbOnlyMocks();
        $registry = new SourceRegistry();
        $fake = new FakeMetadataSource('omdb', ['movie'], [['id' => 'x', 'title' => 'X']], ['official_rating' => 'R']);
        $registry->register($fake);

        $resolver = new MovieMetadataResolver($tmdb, $imdb, null, null, null, $registry);

        // No includePluginSources argument → defaults to false.
        $result = $resolver->resolve('The Matrix', 1999, ['imdb' => 'tt0133093']);

        $this->assertNotNull($result);
        $this->assertSame(0, $fake->searchCalls, 'plugin search() must not run on the default path');
        $this->assertSame(0, $fake->getDetailsCalls, 'plugin getDetails() must not run on the default path');
        $this->assertSame(['tmdb'], $result['sources']);
        $this->assertArrayNotHasKey('plugin_ratings', $result);
        $this->assertArrayNotHasKey('official_rating', $result);
    }

    /**
     * With the flag ON: a plugin field TMDB lacks (official_rating) surfaces,
     * a field BOTH carry (overview) keeps TMDB's value (TMDB wins under it), and
     * the plugin sourceName joins `sources[]`.
     */
    public function testOptInMergesPluginGapFillUnderTmdb(): void
    {
        [$tmdb, $imdb] = $this->tmdbOnlyMocks();
        $registry = new SourceRegistry();
        $registry->register(new FakeMetadataSource(
            'omdb',
            ['movie'],
            [['id' => 'tt0133093', 'title' => 'The Matrix']],
            [
                'overview' => 'PLUGIN overview (should lose to TMDB).',
                'official_rating' => 'R',
            ],
        ));

        $resolver = new MovieMetadataResolver($tmdb, $imdb, null, null, null, $registry);
        $result = $resolver->resolve('The Matrix', 1999, ['imdb' => 'tt0133093'], null, true);

        $this->assertNotNull($result);
        $this->assertSame('TMDB overview.', $result['overview'], 'TMDB must win a shared field');
        $this->assertSame('R', $result['official_rating'], 'plugin fills a TMDB gap');
        $this->assertSame(['tmdb', 'omdb'], $result['sources']);
    }

    /**
     * omdb-style ratings surface under `plugin_ratings` on the opt-in path.
     */
    public function testOptInSurfacesPluginRatings(): void
    {
        [$tmdb, $imdb] = $this->tmdbOnlyMocks();
        $registry = new SourceRegistry();
        $registry->register(new FakeMetadataSource(
            'omdb',
            ['movie'],
            [['id' => 'tt0133093', 'title' => 'The Matrix']],
            ['ratings' => [
                ['source' => 'imdb', 'score' => 8.7, 'votes' => 1900000],
                ['source' => 'rt', 'score' => 8.8],
            ]],
        ));

        $resolver = new MovieMetadataResolver($tmdb, $imdb, null, null, null, $registry);
        $result = $resolver->resolve('The Matrix', 1999, ['imdb' => 'tt0133093'], null, true);

        $this->assertNotNull($result);
        $this->assertSame([
            ['source' => 'imdb', 'score' => 8.7, 'votes' => 1900000],
            ['source' => 'rt', 'score' => 8.8],
        ], $result['plugin_ratings']);
    }

    /**
     * A throwing plugin source is skipped; resolution still returns the TMDB
     * result (per-source isolation).
     */
    public function testThrowingPluginSourceDoesNotBreakResolution(): void
    {
        [$tmdb, $imdb] = $this->tmdbOnlyMocks();
        $registry = new SourceRegistry();
        $registry->register(new FakeMetadataSource('anidb', ['movie'], [], [], throwOnSearch: true));

        $resolver = new MovieMetadataResolver($tmdb, $imdb, null, null, null, $registry);
        $result = $resolver->resolve('The Matrix', 1999, ['imdb' => 'tt0133093'], null, true);

        $this->assertNotNull($result);
        $this->assertSame('TMDB overview.', $result['overview']);
        $this->assertSame(['tmdb'], $result['sources']);
        $this->assertArrayNotHasKey('plugin_ratings', $result);
    }

    /**
     * When no SourceRegistry is injected (unit-test / legacy construction), the
     * opt-in flag is inert — behaviour is exactly today's.
     */
    public function testNullRegistryMakesFlagInert(): void
    {
        [$tmdb, $imdb] = $this->tmdbOnlyMocks();
        $resolver = new MovieMetadataResolver($tmdb, $imdb); // no registry

        $result = $resolver->resolve('The Matrix', 1999, ['imdb' => 'tt0133093'], null, true);

        $this->assertNotNull($result);
        $this->assertSame(['tmdb'], $result['sources']);
        $this->assertArrayNotHasKey('plugin_ratings', $result);
    }
}
