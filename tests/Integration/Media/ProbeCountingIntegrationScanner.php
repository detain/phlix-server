<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media;

use Phlix\Media\Music\MusicLibraryScanner;

/**
 * Counts tag reads while still performing them for REAL — `probeViaGetId3()` delegates
 * to the shipped implementation, so getID3 runs against the real fixture.
 *
 * The artist name is prefixed so the fixtures can be purged: `music_artists` has no
 * `library_id` column (migration 065), so a marker in the name is the only handle.
 */
final class ProbeCountingIntegrationScanner extends MusicLibraryScanner
{
    /** Tag reads since the counter was last reset. */
    public int $probeCount = 0;

    /** @var list<string> Paths whose tags were read, in order. */
    public array $probedPaths = [];

    /** Prepended to the artist tag so fixtures are purgeable. */
    public string $artistPrefix = '';

    /**
     * @return array<string, mixed>|null
     */
    protected function probeViaGetId3(string $path): ?array
    {
        $this->probeCount++;
        $this->probedPaths[] = $path;

        $tags = parent::probeViaGetId3($path);
        if ($tags === null) {
            return null;
        }

        $tags['artist'] = $this->artistPrefix . (is_string($tags['artist'] ?? null) ? $tags['artist'] : 'Unknown');
        $tags['album'] = $this->artistPrefix . (is_string($tags['album'] ?? null) ? $tags['album'] : 'Unknown');
        // Distinct titles, so three copies of one fixture are three distinct tracks.
        $tags['title'] = basename($path, '.mp3');

        return $tags;
    }

    /**
     * Clears both counters before a rescan.
     *
     * @return void
     */
    public function resetProbes(): void
    {
        $this->probeCount = 0;
        $this->probedPaths = [];
    }

    /** No reader pool: these tests are about the skip predicate, not about concurrency. */
    protected function readConcurrency(): int
    {
        return 1;
    }
}
