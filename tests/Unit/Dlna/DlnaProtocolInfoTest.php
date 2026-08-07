<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Dlna;

use Phlix\Dlna\DlnaMimeTypes;
use Phlix\Dlna\DlnaProtocolInfo;
use PHPUnit\Framework\TestCase;

/**
 * {@see DlnaProtocolInfo} — what a renderer is TOLD about a resource.
 *
 * The coverage-over-the-ENUM question lives in {@see DlnaTypeCoverageTest};
 * this file is about the individual answers being right, and in particular
 * about the claim S53 stopped making.
 */
final class DlnaProtocolInfoTest extends TestCase
{
    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function itemProvider(): array
    {
        return [
            'mp4 movie' => [
                ['type' => 'movie', 'path' => '/library/Film.mp4'],
                'http-get:*:video/mp4:DLNA.ORG_PN=AVC_MP4_MP_HD;DLNA.ORG_OP=01;DLNA.ORG_CI=0',
            ],
            'mkv episode claims no profile' => [
                ['type' => 'episode', 'path' => '/library/S01E01.mkv'],
                'http-get:*:video/x-matroska:DLNA.ORG_OP=01;DLNA.ORG_CI=0',
            ],
            'mpeg-2 transport stream claims no profile' => [
                ['type' => 'video', 'path' => '/library/Broadcast.ts'],
                'http-get:*:video/mp2t:DLNA.ORG_OP=01;DLNA.ORG_CI=0',
            ],
            'mp3 track' => [
                ['type' => 'track', 'path' => '/library/Song.mp3'],
                'http-get:*:audio/mpeg:DLNA.ORG_PN=MP3;DLNA.ORG_OP=01;DLNA.ORG_CI=0',
            ],
            'm4b audiobook' => [
                ['type' => 'audiobook', 'path' => '/library/Book.m4b'],
                'http-get:*:audio/mp4:DLNA.ORG_PN=AAC_ISO;DLNA.ORG_OP=01;DLNA.ORG_CI=0',
            ],
            'jpeg photo' => [
                ['type' => 'photo', 'path' => '/library/Holiday.jpg'],
                'http-get:*:image/jpeg:DLNA.ORG_PN=JPEG_LRG;DLNA.ORG_OP=01;DLNA.ORG_CI=0',
            ],
            'unknown container claims nothing at all' => [
                ['type' => 'movie', 'path' => '/library/Disc.iso'],
                'http-get:*:application/octet-stream:*',
            ],
            'container row with no path' => [
                ['type' => 'series'],
                'http-get:*:application/octet-stream:*',
            ],
            'pathless episode falls back on its type' => [
                ['type' => 'episode'],
                'http-get:*:video/mp4:DLNA.ORG_PN=AVC_MP4_MP_HD;DLNA.ORG_OP=01;DLNA.ORG_CI=0',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $item
     *
     * @dataProvider itemProvider
     */
    public function test_protocol_info_for_a_row(array $item, string $expected): void
    {
        self::assertSame($expected, DlnaProtocolInfo::forItem($item));
    }

    /**
     * THE DEFECT, named. `AVC_MP4_MP_HD` used to be advertised for anything
     * typed `video`/`movie`, whatever the container held — so a `.mkv`, an
     * `.avi` and an MPEG-2 `.ts` were all announced as H.264-in-MP4. A renderer
     * that trusts a `DLNA.ORG_PN` starts a decoder for that exact profile and
     * fails mid-stream.
     *
     * A profile is now claimed ONLY where the container implies it.
     */
    public function test_a_dlna_profile_is_not_claimed_for_a_container_that_does_not_imply_one(): void
    {
        foreach (['/library/Film.mkv', '/library/Film.avi', '/library/Film.ts', '/library/Film.wmv'] as $path) {
            $protocolInfo = DlnaProtocolInfo::forItem(['type' => 'movie', 'path' => $path]);

            self::assertStringNotContainsString(
                'DLNA.ORG_PN=',
                $protocolInfo,
                "A DLNA profile must not be asserted for {$path}: {$protocolInfo}"
            );
        }

        // CONTROL: it IS claimed where the container settles the question, so
        // the assertion above is not just "profiles are never emitted".
        self::assertStringContainsString(
            'DLNA.ORG_PN=AVC_MP4_MP_HD',
            DlnaProtocolInfo::forItem(['type' => 'movie', 'path' => '/library/Film.mp4'])
        );
    }

    /**
     * The MIME comes from the ONE table the stream route serves under, and a
     * junk `mime_type` column value is rejected the same way the controller
     * rejects it — so the advertised type and the served `Content-Type` agree
     * on the substituted value too, not just on the happy path.
     */
    public function test_a_junk_explicit_mime_type_falls_back_to_the_container(): void
    {
        $item = [
            'type'      => 'movie',
            'path'      => '/library/Film.mp4',
            'mime_type' => 'not-a-media-type',
        ];

        self::assertSame('video/mp4', DlnaProtocolInfo::mimeFor($item));
        self::assertStringContainsString(':video/mp4:', DlnaProtocolInfo::forItem($item));
        self::assertStringNotContainsString('not-a-media-type', DlnaProtocolInfo::forItem($item));
    }

    /**
     * A legitimate explicit `mime_type` still wins over the extension —
     * {@see DlnaMimeTypes::forItem()}'s documented resolution order, unchanged.
     */
    public function test_an_explicit_media_mime_type_wins(): void
    {
        self::assertSame(
            'video/webm',
            DlnaProtocolInfo::mimeFor([
                'type'      => 'movie',
                'path'      => '/library/Film.mp4',
                'mime_type' => 'video/webm',
            ])
        );
    }

    /**
     * The seek/convert flags are only promised for something this server will
     * actually serve. An unrecognised container is answered `415` by
     * {@see \Phlix\Server\Http\Controllers\Dlna\DlnaStreamController}, so
     * advertising `DLNA.ORG_OP=01` for it would be a promise about bytes that
     * never arrive.
     */
    public function test_no_operation_flags_are_promised_for_a_container_the_route_refuses(): void
    {
        $protocolInfo = DlnaProtocolInfo::forItem(['type' => 'movie', 'path' => '/library/Disc.iso']);

        self::assertSame(DlnaMimeTypes::FALLBACK, DlnaProtocolInfo::mimeFor(['path' => '/library/Disc.iso']));
        self::assertStringNotContainsString('DLNA.ORG_OP', $protocolInfo);
        self::assertStringNotContainsString('DLNA.ORG_CI', $protocolInfo);
        self::assertSame('http-get:*:application/octet-stream:*', $protocolInfo);
    }
}
