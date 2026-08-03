<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Dlna;

use Phlix\Dlna\DlnaMimeTypes;
use PHPUnit\Framework\TestCase;

/**
 * The MIME table shared by the DLNA browse response and the DLNA byte stream.
 *
 * ## Why this matters beyond "does the map have entries"
 *
 * A renderer compares the MIME in `<res protocolInfo="http-get:*:{mime}:…">`
 * with the `Content-Type` of the bytes it then fetches. Those two values were
 * computed from separate private copies of one table, so they could drift
 * silently and a renderer would refuse the item with no error anywhere. The
 * consequential property under test is therefore that ONE function answers both
 * questions, and that an unrecognised container is reported as such
 * ({@see DlnaMimeTypes::FALLBACK}) rather than guessed — that fallback is what
 * makes the stream route answer 415 instead of serving bytes under a made-up
 * type.
 */
final class DlnaMimeTypesTest extends TestCase
{
    /**
     * CONSEQUENCE: a recognised container resolves to its real MIME, from the
     * extension alone, without touching the filesystem (the paths below do not
     * exist).
     *
     * @dataProvider knownContainers
     */
    public function test_known_container_resolves_from_its_extension(string $path, string $expected): void
    {
        self::assertSame($expected, DlnaMimeTypes::forPath($path));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function knownContainers(): iterable
    {
        yield 'mp4'            => ['/nope/a.mp4', 'video/mp4'];
        yield 'm4v'            => ['/nope/a.m4v', 'video/mp4'];
        yield 'mkv'            => ['/nope/a.mkv', 'video/x-matroska'];
        yield 'mov'            => ['/nope/a.mov', 'video/quicktime'];
        yield 'ts'             => ['/nope/a.ts', 'video/mp2t'];
        yield 'mp3'            => ['/nope/a.mp3', 'audio/mpeg'];
        yield 'm4a'            => ['/nope/a.m4a', 'audio/mp4'];
        yield 'flac'           => ['/nope/a.flac', 'audio/flac'];
        yield 'jpeg'           => ['/nope/a.jpeg', 'image/jpeg'];
        yield 'png'            => ['/nope/a.png', 'image/png'];
        yield 'UPPERCASE ext'  => ['/nope/A.MKV', 'video/x-matroska'];
        yield 'dotted name'    => ['/nope/Show.S01E01.1080p.mkv', 'video/x-matroska'];
    }

    /**
     * CONSEQUENCE: an unrecognised (or absent) extension is reported as the
     * fallback, NOT guessed.
     *
     * This is the discriminating case: `.iso` and `.rar` are exactly the files a
     * renderer must be told it cannot play. A table that defaulted to
     * `video/mp4` would pass a "returns a string" test and break playback.
     *
     * @dataProvider unknownContainers
     */
    public function test_unrecognised_container_is_reported_as_the_fallback(string $path): void
    {
        self::assertSame(
            DlnaMimeTypes::FALLBACK,
            DlnaMimeTypes::forPath($path),
            'An unknown container must not be guessed — the stream route 415s on this value.',
        );
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function unknownContainers(): iterable
    {
        yield 'iso disc image' => ['/nope/movie.iso'];
        yield 'rar archive'    => ['/nope/movie.rar'];
        yield 'no extension'   => ['/nope/movie'];
        yield 'empty path'     => [''];
        yield 'directory-like' => ['/nope/Season 01'];
    }

    /**
     * CONSEQUENCE: for a row, an explicit `mime_type` beats the extension.
     */
    public function test_explicit_mime_type_wins_for_a_row(): void
    {
        self::assertSame(
            'video/x-custom',
            DlnaMimeTypes::forItem(['mime_type' => 'video/x-custom', 'path' => '/nope/a.mkv', 'type' => 'movie']),
        );
    }

    /**
     * CONSEQUENCE: with no explicit `mime_type`, the extension decides — and it
     * beats the row's coarse `type`.
     *
     * DISCRIMINATING: a `movie` row holding a `.mkv` must resolve to
     * `video/x-matroska`, not to the `type`-fallback `video/mp4`. Reordering the
     * two lookups fails here.
     */
    public function test_extension_beats_the_row_type(): void
    {
        self::assertSame(
            'video/x-matroska',
            DlnaMimeTypes::forItem(['path' => '/nope/a.mkv', 'type' => 'movie']),
        );
    }

    /**
     * CONSEQUENCE: the coarse `type` fallback only fires when the extension is
     * unknown, preserving the historical LibraryBridge behaviour.
     */
    public function test_row_type_is_the_last_resort(): void
    {
        self::assertSame('video/mp4', DlnaMimeTypes::forItem(['path' => '/nope/a.iso', 'type' => 'movie']));
        self::assertSame('audio/mpeg', DlnaMimeTypes::forItem(['path' => '/nope/a.xyz', 'type' => 'music']));
        self::assertSame('image/jpeg', DlnaMimeTypes::forItem(['path' => '/nope/a.xyz', 'type' => 'photo']));
        self::assertSame(
            DlnaMimeTypes::FALLBACK,
            DlnaMimeTypes::forItem(['path' => '/nope/a.xyz', 'type' => 'book']),
            'An unknown container on a non-AV type stays unrecognised.',
        );
    }

    /**
     * CONSEQUENCE: a row with junk in place of the fields still yields a string
     * rather than throwing — this runs on an authless route, so a malformed row
     * must not become a 500.
     */
    public function test_malformed_row_does_not_throw(): void
    {
        self::assertSame(DlnaMimeTypes::FALLBACK, DlnaMimeTypes::forItem([]));
        self::assertSame(DlnaMimeTypes::FALLBACK, DlnaMimeTypes::forItem(['path' => 42, 'type' => ['x']]));
        self::assertSame(
            DlnaMimeTypes::FALLBACK,
            DlnaMimeTypes::forItem(['mime_type' => '', 'path' => null, 'type' => null]),
            'An empty mime_type must not short-circuit as a valid answer.',
        );
    }

    /**
     * CONSEQUENCE: isMediaType() accepts real media types (with parameters) and
     * rejects everything else — it is the guard that stops a hand-edited
     * `mime_type` from reaching a renderer as a Content-Type.
     */
    public function test_is_media_type_accepts_only_av_and_image_types(): void
    {
        self::assertTrue(DlnaMimeTypes::isMediaType('video/mp4'));
        self::assertTrue(DlnaMimeTypes::isMediaType('AUDIO/MPEG'));
        self::assertTrue(DlnaMimeTypes::isMediaType('image/jpeg; charset=binary'));
        self::assertFalse(DlnaMimeTypes::isMediaType(DlnaMimeTypes::FALLBACK));
        self::assertFalse(DlnaMimeTypes::isMediaType('text/html'));
        self::assertFalse(DlnaMimeTypes::isMediaType(''));
        self::assertFalse(DlnaMimeTypes::isMediaType('novideo/mp4'));
    }

    /**
     * LOCK-IN: every entry in the table really is a media type, so "the
     * extension is known" and "the bytes are playable media" are the same
     * question — which is what lets the stream route gate on the fallback alone.
     */
    public function test_every_mapped_extension_yields_a_media_type(): void
    {
        foreach (['mp4', 'mkv', 'mov', 'ts', 'mp3', 'flac', 'opus', 'jpg', 'webp', 'tiff'] as $ext) {
            $mime = DlnaMimeTypes::forPath('/nope/a.' . $ext);
            self::assertNotSame(DlnaMimeTypes::FALLBACK, $mime, $ext . ' must be mapped.');
            self::assertTrue(DlnaMimeTypes::isMediaType($mime), $ext . ' must map to a media type.');
        }
    }
}
