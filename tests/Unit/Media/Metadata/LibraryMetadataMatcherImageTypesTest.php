<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

use PHPUnit\Framework\TestCase;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Metadata\LibraryMetadataMatcher;
use Phlix\Media\Metadata\MovieMetadataResolver;

/**
 * Enforcement tests for the per-library image-type selection (M5) in
 * {@see LibraryMetadataMatcher}: a disabled image type's flat metadata key is
 * dropped before persistence; enabled types are stored; with no LibraryManager
 * wired nothing is filtered (back-compat).
 *
 */
class LibraryMetadataMatcherImageTypesTest extends TestCase
{
    private function makeLogger(): StructuredLogger
    {
        return $this->createMock(StructuredLogger::class);
    }

    /**
     * @param array<string, mixed> $resolved
     * @return array<string, array<string, mixed>> Captured metadata_json per item id.
     */
    private function runMovieMatch(LibraryManager $libraries, array $resolved): array
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
        $resolver->method('resolve')->willReturn($resolved);

        $matcher = new LibraryMetadataMatcher(
            $items,
            $resolver,
            null,
            $this->makeLogger(),
            null,
            null,
            $libraries
            // No priorityResolver → effectivePriorityFor() returns null; the
            // image-type load still runs off the LibraryManager.
        );
        $matcher->matchLibrary('lib-1');

        return $updates;
    }

    /**
     * With `backdrop` DISABLED for the library, a resolved backdrop_url is
     * dropped before persistence while the enabled poster_url is kept.
     */
    public function testDisabledBackdropIsNotStored(): void
    {
        $libraries = $this->createMock(LibraryManager::class);
        $libraries->method('getLibrary')->with('lib-1')->willReturn([
            'id' => 'lib-1',
            'type' => 'movie',
            // poster ON, backdrop OFF.
            'options' => ['image_types' => ['poster' => true, 'backdrop' => false]],
        ]);

        $updates = $this->runMovieMatch($libraries, [
            'external_ids' => ['tmdb' => '603'],
            'poster_url' => 'https://image.tmdb.org/t/p/w500/poster.jpg',
            'backdrop_url' => 'https://image.tmdb.org/t/p/w500/back.jpg',
            'sources' => ['tmdb'],
        ]);

        $this->assertArrayHasKey('m1', $updates);
        $this->assertSame('https://image.tmdb.org/t/p/w500/poster.jpg', $updates['m1']['poster_url']);
        $this->assertArrayNotHasKey('backdrop_url', $updates['m1'], 'disabled backdrop must not be stored');
    }

    /**
     * With `poster` DISABLED, the poster_url is dropped and the enabled
     * backdrop_url is kept.
     */
    public function testDisabledPosterIsNotStored(): void
    {
        $libraries = $this->createMock(LibraryManager::class);
        $libraries->method('getLibrary')->with('lib-1')->willReturn([
            'id' => 'lib-1',
            'type' => 'movie',
            'options' => ['image_types' => ['poster' => false, 'backdrop' => true]],
        ]);

        $updates = $this->runMovieMatch($libraries, [
            'external_ids' => ['tmdb' => '603'],
            'poster_url' => 'https://image.tmdb.org/t/p/w500/poster.jpg',
            'backdrop_url' => 'https://image.tmdb.org/t/p/w500/back.jpg',
            'sources' => ['tmdb'],
        ]);

        $this->assertArrayNotHasKey('poster_url', $updates['m1'], 'disabled poster must not be stored');
        $this->assertSame('https://image.tmdb.org/t/p/w500/back.jpg', $updates['m1']['backdrop_url']);
    }

    /**
     * A library with NO stored selection uses the defaults, under which BOTH
     * poster and backdrop are enabled — so both flat keys are stored.
     */
    public function testDefaultsKeepPosterAndBackdrop(): void
    {
        $libraries = $this->createMock(LibraryManager::class);
        $libraries->method('getLibrary')->with('lib-1')->willReturn([
            'id' => 'lib-1',
            'type' => 'movie',
            'options' => [],
        ]);

        $updates = $this->runMovieMatch($libraries, [
            'external_ids' => ['tmdb' => '603'],
            'poster_url' => 'https://image.tmdb.org/t/p/w500/poster.jpg',
            'backdrop_url' => 'https://image.tmdb.org/t/p/w500/back.jpg',
            'sources' => ['tmdb'],
        ]);

        $this->assertSame('https://image.tmdb.org/t/p/w500/poster.jpg', $updates['m1']['poster_url']);
        $this->assertSame('https://image.tmdb.org/t/p/w500/back.jpg', $updates['m1']['backdrop_url']);
    }

    /**
     * Back-compat: with NO LibraryManager wired, no image filtering happens —
     * both flat keys are stored exactly as before.
     */
    public function testNoLibraryManagerDisablesFiltering(): void
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
            'poster_url' => 'https://image.tmdb.org/t/p/w500/poster.jpg',
            'backdrop_url' => 'https://image.tmdb.org/t/p/w500/back.jpg',
            'sources' => ['tmdb'],
        ]);

        // Legacy 4-arg construction — no LibraryManager.
        $matcher = new LibraryMetadataMatcher($items, $resolver, null, $this->makeLogger());
        $matcher->matchLibrary('lib-1');

        $this->assertSame('https://image.tmdb.org/t/p/w500/poster.jpg', $updates['m1']['poster_url']);
        $this->assertSame('https://image.tmdb.org/t/p/w500/back.jpg', $updates['m1']['backdrop_url']);
    }
}
