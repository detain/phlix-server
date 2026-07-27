<?php

/**
 * Phlix media server test double: Media\Music.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Music;

use Phlix\Media\Music\MusicLibraryScanner;

/**
 * A {@see MusicLibraryScanner} that counts tag reads and never spawns a reader pool.
 *
 * Overriding `probeViaGetId3()` rather than mocking getID3 is what makes the probe
 * COUNT the assertion surface: it is the exact call the S122(a) skip exists to avoid,
 * and it is reached only from `probeMetadata()`, which is reached only from the walk.
 *
 * @internal
 */
final class ProbeCountingScanner extends MusicLibraryScanner
{
    /** Tag reads performed since the counter was last reset. */
    public int $probeCount = 0;

    /** @var list<string> Paths whose tags were read, in order. */
    public array $probedPaths = [];

    /** Appended to the title, so a test can make a re-read look like a real change. */
    public string $titleSuffix = '';

    /**
     * Per-path album title overrides, so a test can make one file look as though it moved
     * to a different album (which is what forces a `music_albums` INSERT on a rescan).
     *
     * @var array<string, string>
     */
    public array $albumOverride = [];

    /**
     * Per-path payloads appended to the file IMMEDIATELY AFTER its tags have been read.
     *
     * This is the review r1 B1 window, reproduced where it actually lives: the tags
     * returned below are the pre-edit ones, while the file on disk is the post-edit one
     * by the time `flushAlbum()` writes them. Both halves of an ordinary tag write are
     * applied — the size grows and the mtime moves forward — so the scenario needs no
     * exotic writer, unlike the documented both-preserved failure mode.
     *
     * Fires ONCE per path per scan, so a rescan does not keep growing the file.
     *
     * @var array<string, string>
     */
    public array $editAfterProbe = [];

    /**
     * Clears BOTH counters before a rescan.
     *
     * One method rather than two assignments at 11 call sites, because resetting the
     * count and forgetting the path list is how three of these tests first "failed":
     * the count assertion passed while the path assertion compared against the union
     * of both scans.
     *
     * @return void
     */
    public function resetProbes(): void
    {
        $this->probeCount = 0;
        $this->probedPaths = [];
    }

    protected function probeViaGetId3(string $path): ?array
    {
        $this->probeCount++;
        $this->probedPaths[] = $path;

        // One album per parent directory, so a fixture can exceed the 32-album open
        // window and make the scanner flush DURING the walk.
        $album = $this->albumOverride[$path] ?? 'Skip Album ' . basename(dirname($path));

        $tags = [
            'artist' => 'Skip Artist',
            'album' => $album,
            'title' => basename($path, '.mp3') . $this->titleSuffix,
            'track_number' => 1,
            'disc_number' => 1,
            'duration_secs' => 123,
            'year' => 2020,
            'genre' => 'Rock',
        ];

        // The B1 window: the tags above are settled, the flush has not happened yet, and
        // now an ordinary tag write lands on the file. Anything that stamps from a fresh
        // stat after this point records the POST-edit identity for PRE-edit tags.
        if (isset($this->editAfterProbe[$path])) {
            $payload = $this->editAfterProbe[$path];
            unset($this->editAfterProbe[$path]);
            file_put_contents($path, $payload, FILE_APPEND);
            touch($path, (int) filemtime($path) + 300);
            clearstatcache(true, $path);
        }

        return $tags;
    }

    protected function probeViaFfprobe(string $path): ?array
    {
        self::fail('ffprobe must never be reached: probeViaGetId3() already returned usable tags');
    }

    /**
     * 1 = no reader pool. These tests are about the skip predicate; spawning three
     * child processes per scan would only add noise and wall time.
     */
    protected function readConcurrency(): int
    {
        return 1;
    }
}
