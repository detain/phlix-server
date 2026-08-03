<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Metadata\LibraryMetadataMatcher;
use Phlix\Media\Metadata\MovieMetadataResolver;
use Phlix\Media\Metadata\SeriesMetadataResolver;
use Phlix\Media\Metadata\TmdbProvider;

/**
 * SM-0.2 — the PRECEDENCE RULE for `metadata_json.episode_title`.
 *
 * The scanner writes a filename-derived title
 * ({@see \Phlix\Media\Library\EpisodeFilenameParser}); the matcher writes a
 * provider-derived one. The contract these tests pin is:
 *
 *  1. a real provider title ALWAYS wins — the filename is never allowed to
 *     overwrite it;
 *  2. an ABSENT (or empty) provider title leaves the filename title in place,
 *     which is the whole point of the fallback — 501 of the 1,328 title-less
 *     episodes in the reference library are recoverable this way with no
 *     provider call at all;
 *  3. the matcher never writes an empty `episode_title`.
 *
 * The mechanism is `enrichEpisode()`'s `array_merge($meta, $patch)`: `$patch`
 * only carries `episode_title` when `stringOrNull()` accepted the provider's
 * value. That is easy to break with a well-meant "always set the key" refactor,
 * hence these tests rather than a comment.
 */
class LibraryMetadataMatcherEpisodeTitleFallbackTest extends TestCase
{
    private const FILENAME_TITLE = 'Let It Be Me';

    public function testProviderTitleWinsOverTheFilenameTitle(): void
    {
        $persisted = $this->runEnrichment(['episode_title' => 'Flesh and Bone', 'overview' => 'Pilot.']);

        $this->assertSame('Flesh and Bone', $persisted['episode_title']);
    }

    public function testFilenameTitleSurvivesWhenTheProviderHasNoTitle(): void
    {
        // The provider knows the episode but supplies no name for it — the
        // dominant failure shape: 1,188 of the 1,228 unmatched anime episodes
        // were re-fetched with a working key and still came back title-less.
        $persisted = $this->runEnrichment(['overview' => 'Pilot.', 'air_date' => '2004-10-18']);

        $this->assertSame(self::FILENAME_TITLE, $persisted['episode_title']);
    }

    public function testFilenameTitleSurvivesWhenTheProviderHasNoEpisodeAtAll(): void
    {
        // Bucket C: the episode number is beyond the provider's season range,
        // so there is no entry for it whatsoever (742 rows / 55.9% of the residue).
        $persisted = $this->runEnrichment(null);

        $this->assertSame(self::FILENAME_TITLE, $persisted['episode_title']);
    }

    public function testAnEmptyProviderTitleDoesNotClobberTheFilenameTitle(): void
    {
        $persisted = $this->runEnrichment(['episode_title' => '', 'overview' => 'Pilot.']);

        $this->assertSame(self::FILENAME_TITLE, $persisted['episode_title']);
    }

    public function testNoEpisodeTitleKeyIsInventedWhenNeitherSideHasOne(): void
    {
        $persisted = $this->runEnrichment(['overview' => 'Pilot.'], []);

        $this->assertArrayNotHasKey('episode_title', $persisted);
    }

    /**
     * Drive `applyMatch()` down to a single episode and return the metadata the
     * matcher persisted for it.
     *
     * @param array<string, mixed>|null $providerEpisode Provider data for episode 1,
     *                                                   or null for "not listed".
     * @param array<string, mixed>      $existingMeta    The episode row's stored metadata.
     *
     * @return array<string, mixed>
     */
    private function runEnrichment(?array $providerEpisode, ?array $existingMeta = null): array
    {
        $existingMeta ??= ['season' => 1, 'episode' => 1, 'episode_title' => self::FILENAME_TITLE];

        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->with('s1')->willReturn([
            'id' => 's1',
            'type' => 'series',
            'name' => 'Some Show',
            'metadata' => [],
        ]);
        $items->method('findByParent')->willReturnCallback(
            static function (string $parent) use ($existingMeta): array {
                if ($parent === 's1') {
                    return [['id' => 'se1', 'type' => 'season', 'metadata' => ['season' => 1]]];
                }
                if ($parent === 'se1') {
                    return [['id' => 'ep1', 'type' => 'episode', 'metadata' => $existingMeta]];
                }
                return [];
            }
        );

        $captured = [];
        $items->method('update')->willReturnCallback(
            static function (string $id, array $data) use (&$captured): bool {
                if ($id === 'ep1' && isset($data['metadata_json']) && is_array($data['metadata_json'])) {
                    $captured = $data['metadata_json'];
                }
                return true;
            }
        );

        $tmdb = $this->createMock(TmdbProvider::class);
        $tmdb->method('hasApiKey')->willReturn(true);
        $tmdb->method('getTvDetails')->with('1399')->willReturn([
            'name' => 'Some Show',
            'overview' => 'Synopsis.',
            'year' => 2004,
        ]);

        $series = $this->createMock(SeriesMetadataResolver::class);
        $series->method('resolveSeasonEpisodes')->with('1399', 1)->willReturn([
            'overview' => 'Season one.',
            'episodes' => $providerEpisode === null ? [] : [1 => $providerEpisode],
        ]);

        $matcher = new LibraryMetadataMatcher(
            $items,
            $this->createMock(MovieMetadataResolver::class),
            $series,
            $this->logger(),
            $tmdb,
        );
        $matcher->applyMatch('s1', '1399', 'tv');

        $this->assertNotSame([], $captured, 'the episode must have been persisted');

        return $captured;
    }

    private function logger(): StructuredLogger&MockObject
    {
        return $this->createMock(StructuredLogger::class);
    }
}
