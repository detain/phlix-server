<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

use PHPUnit\Framework\TestCase;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Metadata\LibraryMetadataMatcher;
use Phlix\Media\Metadata\MovieMetadataResolver;
use Phlix\Media\Storage\ArtworkStorage;

/**
 * SV-3.4: proves that once an {@see ArtworkStorage} is wired into
 * {@see LibraryMetadataMatcher}, matching a movie with a TMDB poster downloads
 * + caches the poster locally and rewrites the persisted metadata to use LOCAL
 * artwork URLs (a `poster_srcset` of `/api/v1/artwork/...` variants and a local
 * `poster_url`), NOT the raw TMDB CDN URL. Without the DI wiring the field is
 * null and `cacheArtworkLocally()` is a no-op (regression this guards).
 *
 */
class LibraryMetadataMatcherArtworkTest extends TestCase
{
    private const TMDB_POSTER = 'https://image.tmdb.org/t/p/w500/poster.jpg';

    /**
     * @param ArtworkStorage|null $artwork
     * @return array<string, array<string, mixed>> Captured metadata_json per item id.
     */
    private function runMovieMatch(?ArtworkStorage $artwork): array
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('getByLibrary')->willReturnOnConsecutiveCalls(
            [['id' => 'm1', 'type' => 'movie', 'name' => 'The Matrix', 'metadata' => []]],
            []
        );

        $updates = [];
        $items->method('update')->willReturnCallback(
            static function (string $id, array $data) use (&$updates): void {
                $updates[$id] = is_array($data['metadata_json'] ?? null) ? $data['metadata_json'] : [];
            }
        );

        $resolver = $this->createMock(MovieMetadataResolver::class);
        $resolver->method('resolve')->willReturn([
            'external_ids' => ['tmdb' => '603'],
            'poster_url' => self::TMDB_POSTER,
            'sources' => ['tmdb'],
        ]);

        // Positional ctor: items, resolver, seriesResolver, logger, tmdb,
        // noiseSuffixes, libraries, priorityResolver, themeMusic, fuzzyMatcher,
        // artworkStorage. No LibraryManager → no image-type filtering (poster
        // passes through), so the artwork rewrite is what we assert.
        $matcher = new LibraryMetadataMatcher(
            $items,
            $resolver,
            null,
            $this->createMock(StructuredLogger::class),
            null,
            null,
            null,
            null,
            null,
            null,
            $artwork
        );
        $matcher->matchLibrary('lib-1');

        return $updates;
    }

    /**
     * With ArtworkStorage wired, the persisted metadata carries a LOCAL srcset
     * (built from the cached variants) and a LOCAL poster_url — never the TMDB
     * CDN URL — and the raw TMDB path is retained under poster_path.
     */
    public function testWiredArtworkStorageWritesLocalSrcset(): void
    {
        $localSrcset = '/api/v1/artwork/m1?size=w185 185w, '
            . '/api/v1/artwork/m1?size=w342 342w, '
            . '/api/v1/artwork/m1?size=w500 500w';
        $signedLocalUrl = '/api/v1/artwork/m1?size=w500&sig=abc123';

        $artwork = $this->createMock(ArtworkStorage::class);
        $artwork->expects($this->once())
            ->method('downloadAndStore')
            ->with('m1', '/poster.jpg')
            ->willReturn(['w185', 'w342', 'w500', 'w780', 'original']);
        $artwork->method('srcset')->with('m1')->willReturn($localSrcset);
        $artwork->method('relativePath')->with('m1', 'w500')->willReturn('/api/v1/artwork/m1?size=w500');
        $artwork->method('url')->with('m1', 'w500', '/api/v1/artwork/m1?size=w500')->willReturn($signedLocalUrl);

        $updates = $this->runMovieMatch($artwork);

        $this->assertArrayHasKey('m1', $updates);
        $meta = $updates['m1'];

        // poster_srcset is emitted and is LOCAL, not a TMDB CDN url.
        $this->assertArrayHasKey('poster_srcset', $meta);
        $this->assertSame($localSrcset, $meta['poster_srcset']);
        $this->assertStringContainsString('/api/v1/artwork/', (string) $meta['poster_srcset']);
        $this->assertStringNotContainsString('image.tmdb.org', (string) $meta['poster_srcset']);

        // poster_url is rewritten to the local (signed) server URL.
        $this->assertSame($signedLocalUrl, $meta['poster_url']);
        $this->assertStringNotContainsString('image.tmdb.org', (string) $meta['poster_url']);

        // The raw TMDB path is retained for future re-resolution.
        $this->assertSame('/poster.jpg', $meta['poster_path'] ?? null);
    }

    /**
     * Regression guard for the DI landmine: with NO ArtworkStorage wired (the
     * pre-fix state), cacheArtworkLocally() is a no-op — poster_url stays the
     * TMDB CDN url and no poster_srcset is emitted.
     */
    public function testUnwiredArtworkStorageLeavesTmdbUrl(): void
    {
        $updates = $this->runMovieMatch(null);

        $this->assertArrayHasKey('m1', $updates);
        $meta = $updates['m1'];
        $this->assertSame(self::TMDB_POSTER, $meta['poster_url']);
        $this->assertArrayNotHasKey('poster_srcset', $meta);
    }

    /**
     * Per-scan dedup: two items that resolve to the SAME TMDB poster path must
     * trigger exactly ONE download + resize. The second item reuses the first's
     * local URLs instead of re-fetching the identical bytes into a second
     * directory. This is the fix for the ~15x redundant artwork work seen when
     * every season/episode inherits its series poster (the dominant scan cost).
     */
    public function testDuplicatePosterPathDownloadsOnceAndReusesLocalUrls(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('getByLibrary')->willReturnOnConsecutiveCalls(
            [
                ['id' => 'm1', 'type' => 'movie', 'name' => 'First', 'metadata' => []],
                ['id' => 'm2', 'type' => 'movie', 'name' => 'Second', 'metadata' => []],
            ],
            []
        );

        $updates = [];
        $items->method('update')->willReturnCallback(
            static function (string $id, array $data) use (&$updates): void {
                $updates[$id] = is_array($data['metadata_json'] ?? null) ? $data['metadata_json'] : [];
            }
        );

        $resolver = $this->createMock(MovieMetadataResolver::class);
        $resolver->method('resolve')->willReturn([
            'external_ids' => ['tmdb' => '603'],
            'poster_url' => self::TMDB_POSTER,
            'sources' => ['tmdb'],
        ]);

        $localSrcset = '/api/v1/artwork/m1?size=w185 185w, /api/v1/artwork/m1?size=w500 500w';
        $signedLocalUrl = '/api/v1/artwork/m1?size=w500&sig=abc123';

        $artwork = $this->createMock(ArtworkStorage::class);
        // The dedup contract: exactly ONE download for the shared poster path,
        // for the first (owning) item — m2 must NOT re-download.
        $artwork->expects($this->once())
            ->method('downloadAndStore')
            ->with('m1', '/poster.jpg')
            ->willReturn(['w185', 'w342', 'w500', 'w780', 'original']);
        $artwork->method('srcset')->willReturn($localSrcset);
        $artwork->method('relativePath')->willReturn('/api/v1/artwork/m1?size=w500');
        $artwork->method('url')->willReturn($signedLocalUrl);

        // Positional ctor mirrors runMovieMatch(): …, artworkStorage last.
        $matcher = new LibraryMetadataMatcher(
            $items,
            $resolver,
            null,
            $this->createMock(StructuredLogger::class),
            null,
            null,
            null,
            null,
            null,
            null,
            $artwork
        );
        $matcher->matchLibrary('lib-1');

        // Both items end up with the SAME local URLs (m2 reuses m1's variants).
        $this->assertSame($signedLocalUrl, $updates['m1']['poster_url'] ?? null);
        $this->assertSame($signedLocalUrl, $updates['m2']['poster_url'] ?? null);
        $this->assertSame($localSrcset, $updates['m2']['poster_srcset'] ?? null);
        $this->assertSame('/poster.jpg', $updates['m2']['poster_path'] ?? null);
        // The reused URL never falls back to the TMDB CDN.
        $this->assertStringNotContainsString(
            'image.tmdb.org',
            (string) ($updates['m2']['poster_url'] ?? ''),
        );
    }
}
