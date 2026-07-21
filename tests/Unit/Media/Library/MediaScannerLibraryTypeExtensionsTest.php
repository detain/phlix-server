<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library;

use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\MediaScanner;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Regression guard for the `libraries.type` -> extension-class translation in
 * {@see MediaScanner::extensionsForLibraryType()}.
 *
 * ## The bug
 *
 * The extension lookup used to be
 * `$this->namingOptions[$type] ?? $this->namingOptions['video']`, indexing a
 * map keyed by EXTENSION CLASS (`video|audio|image|book`) with a
 * `libraries.type` value (`movie|series|music|photo|video|book|audiobook`).
 * `LibraryManager` hand-translated two of the mismatches at the call site
 * (`scanPhotoLibrary()` passes `image`, `scanBookLibrary()` passes `book`) but
 * `scanAudiobookLibrary()` passes `audiobook`, which is not a key in the map —
 * so audiobook libraries silently fell through to VIDEO extensions and scanned
 * for `.mkv`/`.mp4`, never for the `.m4b`/`.mp3`/`.m4a` files they contain.
 *
 * The `$type` argument could NOT simply be rewritten at the call site: it is
 * also forwarded to `determineMediaType()` and ends up as the created rows'
 * `media_items.type`, so `audiobook` has to survive the call intact. The
 * translation therefore belongs inside the scanner.
 *
 * ## Three vocabularies, deliberately not unified
 *
 *  1. `libraries.type` — 7 members, the `$type` argument here.
 *  2. `media_items.type` — 13-member DB ENUM; spells it `photo`, not `image`.
 *  3. extension classes — the `loadNamingOptions()` keys; spell it `image`.
 *
 * These share words and conflating them has caused production bugs, so the
 * assertions below pin each mapping independently rather than asserting one
 * generic rule.
 *
 * @covers \Phlix\Media\Library\MediaScanner
 */
final class MediaScannerLibraryTypeExtensionsTest extends TestCase
{
    private ?string $tmpDir = null;

    protected function tearDown(): void
    {
        if ($this->tmpDir !== null && is_dir($this->tmpDir)) {
            $this->removeDir($this->tmpDir);
        }
        $this->tmpDir = null;
        parent::tearDown();
    }

    private function scanner(): MediaScanner
    {
        return new MediaScanner(
            $this->createMock(Connection::class),
            $this->createMock(ItemRepository::class)
        );
    }

    /**
     * @param array<int, string> $filenames
     */
    private function makeTempDirWith(array $filenames): string
    {
        $dir = sys_get_temp_dir() . '/phlix_libtype_test_' . uniqid();
        mkdir($dir, 0775, true);
        foreach ($filenames as $name) {
            file_put_contents($dir . '/' . $name, 'x');
        }

        return $dir;
    }

    private function removeDir(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * A mixed directory that discriminates every extension class at once.
     */
    private function mixedFixture(): string
    {
        return $this->makeTempDirWith([
            'Feature Film.mkv',      // video
            'Chapter One.m4b',       // audiobook-only
            'Chapter Two.mp3',       // audio (+ audiobook)
            'Cover Art.jpg',         // image
            'Manual.epub',           // book
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // THE regression
    // ─────────────────────────────────────────────────────────────────

    /**
     * An audiobook library must scan audio-family extensions, NOT video.
     *
     * Before the fix this returned 1 — the `.mkv` — because `audiobook` missed
     * the map and fell through to the video list.
     */
    public function test_an_audiobook_library_scans_audio_files_not_video(): void
    {
        $this->tmpDir = $this->mixedFixture();

        $this->assertSame(
            2,
            $this->scanner()->countFiles($this->tmpDir, 'audiobook'),
            'An audiobook library must see the .m4b and the .mp3, and nothing else.'
        );
    }

    /**
     * `.m4b` is the dominant audiobook container and is what made the plain
     * "route audiobook to the audio list" fix insufficient: the audio list is
     * a MUSIC list and does not contain it.
     */
    public function test_m4b_is_scannable_by_an_audiobook_library(): void
    {
        $this->tmpDir = $this->makeTempDirWith(['Book.m4b']);

        $this->assertSame(1, $this->scanner()->countFiles($this->tmpDir, 'audiobook'));
    }

    /**
     * ...but `.m4b` must NOT leak into a music library's extension set — an
     * audiobook is not a music track, and the two lists are deliberately
     * distinct.
     */
    public function test_m4b_is_not_scanned_by_a_music_library(): void
    {
        $this->tmpDir = $this->makeTempDirWith(['Book.m4b', 'Track.mp3']);

        $this->assertSame(
            1,
            $this->scanner()->countFiles($this->tmpDir, 'music'),
            'Only the .mp3 belongs to a music library.'
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // the mappings that were already (incidentally) correct
    // ─────────────────────────────────────────────────────────────────

    /**
     * `movie`, `series` and `video` were never keys in the map either; their
     * fallback to video extensions was correct but incidental. Now explicit.
     *
     * @return array<string, array{string}>
     */
    public static function videoLibraryTypes(): array
    {
        return ['movie' => ['movie'], 'series' => ['series'], 'video' => ['video']];
    }

    /**
     * @dataProvider videoLibraryTypes
     */
    public function test_video_content_library_types_scan_video_extensions(string $type): void
    {
        $this->tmpDir = $this->mixedFixture();

        $this->assertSame(1, $this->scanner()->countFiles($this->tmpDir, $type));
    }

    /**
     * `photo` is the `libraries.type` spelling; `image` is the extension-class
     * spelling. Both must resolve to image extensions — `photo` because that is
     * what a real library row carries, and `image` because
     * `LibraryManager::scanPhotoLibrary()` still passes the translated literal.
     *
     * @return array<string, array{string}>
     */
    public static function imageLibraryTypes(): array
    {
        return ['libraries.type spelling' => ['photo'], 'translated literal' => ['image']];
    }

    /**
     * @dataProvider imageLibraryTypes
     */
    public function test_photo_and_image_both_scan_image_extensions(string $type): void
    {
        $this->tmpDir = $this->mixedFixture();

        $this->assertSame(1, $this->scanner()->countFiles($this->tmpDir, $type));
    }

    public function test_a_book_library_scans_book_extensions(): void
    {
        $this->tmpDir = $this->mixedFixture();

        $this->assertSame(1, $this->scanner()->countFiles($this->tmpDir, 'book'));
    }

    /**
     * An unrecognised type keeps the historical video fallback rather than
     * scanning nothing — a new library type must degrade to "scans video",
     * which is what every caller before it did.
     */
    public function test_an_unknown_library_type_falls_back_to_video(): void
    {
        $this->tmpDir = $this->mixedFixture();

        $this->assertSame(1, $this->scanner()->countFiles($this->tmpDir, 'not-a-real-type'));
    }

    /**
     * The `media_items.type` ENUM member `photo` must never be confused with
     * the extension class `image` in the OTHER direction: asserting the two
     * resolve identically pins the translation, so a future edit that renames
     * one vocabulary breaks here rather than in production.
     */
    public function test_photo_and_image_resolve_to_the_same_extension_set(): void
    {
        $this->tmpDir = $this->mixedFixture();

        $scanner = $this->scanner();

        $this->assertSame(
            $scanner->countFiles($this->tmpDir, 'photo'),
            $scanner->countFiles($this->tmpDir, 'image'),
        );
    }
}
