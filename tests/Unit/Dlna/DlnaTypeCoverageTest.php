<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Dlna;

use Phlix\Dlna\DlnaMimeTypes;
use Phlix\Dlna\DlnaProtocolInfo;
use Phlix\Media\MediaItemType;
use PHPUnit\Framework\TestCase;

/**
 * The DLNA surface must cover the WHOLE `media_items.type` ENUM.
 *
 * ## The defect
 *
 * `ContentDirectory::getProtocolInfo()` and `DlnaMimeTypes::forItem()` each
 * carried a hand-written four-arm `match` over the item type:
 *
 * ```php
 * 'video', 'movie' => …,
 * 'audio', 'music' => …,
 * 'image', 'photo' => …,
 * default          => …,
 * ```
 *
 * The column ENUM has THIRTEEN members. `episode`, `track` and `audiobook` —
 * the types the TV, music and audiobook scanners actually write — all fell to
 * the default arm, and `'image'` was not a member at all (the member is
 * `photo`; see {@see MediaItemType}). This is the same duplicated-vocabulary
 * defect that produced MySQL 1265 truncations in `stats_playback_events` and
 * zeroed the Music storage bucket, arriving for the fourth time.
 *
 * ## What this file is for
 *
 * Not "the current arms are right" — that is
 * {@see \Phlix\Tests\Unit\Dlna\DlnaProtocolInfoTest}'s job. This file exists so
 * that **adding a 14th ENUM member without giving it a DLNA answer reddens the
 * build**. It reads the vocabulary from {@see MediaItemType::ALL} (which
 * {@see \Phlix\Tests\Unit\Media\MediaItemTypeDriftTest} in turn pins to the
 * migration SQL) and compares it, as an ordered list, with the DLNA table.
 */
final class DlnaTypeCoverageTest extends TestCase
{
    /**
     * The count the whole repo's type handling is written around, asserted
     * under its own name so a change to it fails with a message that says so
     * rather than as a confusing knock-on somewhere else.
     */
    private const EXPECTED_MEMBER_COUNT = 13;

    public function test_the_media_item_type_enum_still_has_thirteen_members(): void
    {
        self::assertCount(self::EXPECTED_MEMBER_COUNT, MediaItemType::ALL);
    }

    /**
     * `photo`, NOT `image`. Asserted explicitly because the dead `'image'` arm
     * this step removed had survived four separate readings of the code.
     */
    public function test_photo_is_a_member_and_image_is_not(): void
    {
        self::assertContains('photo', MediaItemType::ALL);
        self::assertNotContains('image', MediaItemType::ALL);

        self::assertArrayHasKey('photo', DlnaMimeTypes::TYPE_FALLBACK_MIME);
        self::assertArrayNotHasKey(
            'image',
            DlnaMimeTypes::TYPE_FALLBACK_MIME,
            "'image' is not a media_items.type member; an arm for it is dead code that reads as "
            . 'coverage.'
        );
    }

    /**
     * THE DRIFT GATE. The DLNA type table and the column ENUM are the same list,
     * in the same order.
     *
     * Order is compared too, not just membership: the constant's docblock claims
     * it is "in column order", and a claim nothing checks stops being true.
     */
    public function test_the_dlna_type_table_is_exactly_the_column_enum(): void
    {
        self::assertSame(
            MediaItemType::ALL,
            array_keys(DlnaMimeTypes::TYPE_FALLBACK_MIME),
            'DlnaMimeTypes::TYPE_FALLBACK_MIME must carry one entry per media_items.type member, '
            . 'in column order. A member with no entry silently resolves to '
            . 'application/octet-stream, which the DLNA stream route answers 415 for.'
        );
    }

    /**
     * Every member resolves to SOMETHING deliberate — a real media MIME or the
     * explicit {@see DlnaMimeTypes::FALLBACK}, never an empty string or null.
     */
    public function test_every_enum_member_has_a_deliberate_answer(): void
    {
        foreach (MediaItemType::ALL as $type) {
            $mime = DlnaMimeTypes::TYPE_FALLBACK_MIME[$type] ?? null;

            self::assertIsString($mime, "No DLNA MIME answer for type '{$type}'.");
            self::assertNotSame('', $mime, "Empty DLNA MIME answer for type '{$type}'.");
            self::assertTrue(
                $mime === DlnaMimeTypes::FALLBACK || DlnaMimeTypes::isMediaType($mime),
                "Type '{$type}' maps to '{$mime}', which is neither a media type nor the explicit "
                . 'FALLBACK.'
            );
        }
    }

    /**
     * The four container types name no bytes, so they must NOT be given a
     * playable MIME — a `<res>` for an artist row would send a renderer after
     * a stream that does not exist.
     *
     * This is the negative half of the coverage claim: without it, "every
     * member has an answer" could be satisfied by giving all thirteen
     * `video/mp4`.
     */
    public function test_container_types_resolve_to_the_unplayable_fallback(): void
    {
        foreach (['series', 'season', 'album', 'artist'] as $container) {
            self::assertSame(
                DlnaMimeTypes::FALLBACK,
                DlnaMimeTypes::TYPE_FALLBACK_MIME[$container],
                "Container type '{$container}' must not advertise a playable MIME."
            );
        }
    }

    /**
     * The three types the old four-arm `match` dropped now resolve to real
     * media MIMEs — the concrete regression this step fixes.
     *
     * Exercised on PATHLESS rows, because that is the only state in which the
     * type fallback is the deciding factor: a row that has a path is resolved
     * by its extension (and, if the extension is unknown, refused outright).
     */
    public function test_the_types_the_old_match_dropped_now_resolve(): void
    {
        $expected = [
            'episode'   => 'video/mp4',
            'track'     => 'audio/mpeg',
            'audiobook' => 'audio/mpeg',
        ];

        foreach ($expected as $type => $mime) {
            self::assertSame($mime, DlnaMimeTypes::forItem(['type' => $type]));
        }

        // CONTROL: this is a real change. The pre-S53 arms answered
        // application/octet-stream for all three.
        self::assertNotSame(DlnaMimeTypes::FALLBACK, DlnaMimeTypes::forItem(['type' => 'episode']));
    }

    /**
     * CONSEQUENCE, end to end: `protocolInfo` for every ENUM member is a
     * well-formed four-field `http-get` string whose MIME field is the one
     * {@see DlnaMimeTypes} resolves — so no member can produce a malformed or
     * self-inconsistent advertisement.
     */
    public function test_protocol_info_is_well_formed_for_every_enum_member(): void
    {
        foreach (MediaItemType::ALL as $type) {
            $item = ['id' => 'x', 'type' => $type];

            $protocolInfo = DlnaProtocolInfo::forItem($item);
            $fields = explode(':', $protocolInfo);

            self::assertCount(4, $fields, "protocolInfo for '{$type}' is not four fields: {$protocolInfo}");
            self::assertSame('http-get', $fields[0]);
            self::assertSame('*', $fields[1]);
            self::assertSame(DlnaMimeTypes::TYPE_FALLBACK_MIME[$type], $fields[2]);
            self::assertNotSame('', $fields[3], "protocolInfo for '{$type}' has an empty fourth field.");
        }
    }

    /**
     * A row whose CONTAINER this server does not direct-play is never
     * advertised as playable, whatever its `type` says.
     *
     * This is the drift the {@see DlnaMimeTypes} extraction exists to prevent,
     * reaching the last place it still lived: the stream route gates on the
     * extension and answers `415`, so a `.iso` typed `movie` must not be
     * announced `video/mp4` — which is exactly what the pre-S53 code did.
     */
    public function test_an_unplayable_container_is_not_advertised_as_its_type(): void
    {
        $iso = ['id' => 'x', 'type' => 'movie', 'path' => '/library/Disc.iso'];

        self::assertSame(DlnaMimeTypes::FALLBACK, DlnaProtocolInfo::mimeFor($iso));
        self::assertSame('http-get:*:application/octet-stream:*', DlnaProtocolInfo::forItem($iso));

        // CONTROL: the same row with a container the route DOES serve is
        // advertised as playable, so the assertion above is discriminating.
        $mp4 = ['id' => 'x', 'type' => 'movie', 'path' => '/library/Disc.mp4'];
        self::assertSame('video/mp4', DlnaProtocolInfo::mimeFor($mp4));
    }
}
