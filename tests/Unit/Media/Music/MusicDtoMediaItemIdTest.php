<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Music;

use Phlix\Media\Music\MusicAlbum;
use Phlix\Media\Music\MusicArtist;
use Phlix\Media\Music\MusicTrack;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Pins `media_item_id` on the three music DTOs (S121).
 *
 * **The bug these tests exist to keep dead.** `MusicArtist::fromRow()`,
 * `MusicAlbum::fromRow()` and `MusicTrack::fromRow()` all coerced
 * `media_item_id` with `is_numeric($row['media_item_id']) ? (int)… : <fallback>`,
 * while the column is a `CHAR(36)` UUID in all three tables (migration 070).
 * `is_numeric()` is false for every UUID, so the field was silently ALWAYS the
 * fallback — `null` on artist/album, `0` on track — no matter what the database
 * held. Nothing 500ed and no payload changed, which is exactly why it survived:
 * a dropped field is invisible until someone reads it.
 *
 * **Two properties, and they pull in opposite directions.** A UUID that IS there
 * must survive; a `media_item_id` that is genuinely absent must stay `null` and
 * must NOT become `''`. The second half matters because the music scanner writes
 * a NULL `media_item_id` when its `createMediaItem()` mint fails and backfills the
 * row on a later pass — so `null` is a real data state, not just a parse failure,
 * and `''` would read as a present-but-unusable id.
 *
 * **On S120 (assertions swallowed by a production `catch`):** every assertion here
 * runs on the value returned by a plain static factory call. Nothing is asserted
 * inside a callback that production code invokes under `catch (\Throwable)` or
 * `catch (\RuntimeException)`, and this class contains no `try`/`catch` of its own,
 * so no `ExpectationFailedException` can be swallowed. Each test below was proven
 * RED by restoring the `is_numeric()` coercion it names.
 *
 * @covers \Phlix\Media\Music\MusicArtist
 * @covers \Phlix\Media\Music\MusicAlbum
 * @covers \Phlix\Media\Music\MusicTrack
 */
final class MusicDtoMediaItemIdTest extends TestCase
{
    /**
     * A real `media_items.id`, exactly as `Phlix\Common\Uuid::v4()` mints it and
     * `CHAR(36)` stores it. 36 characters, hex + dashes, `is_numeric()` false.
     */
    private const UUID = 'b4f4b8fe-0c2f-4f4e-9a0b-2c9f8de3a1c7';

    /**
     * UUID shapes that all fail `is_numeric()`. The all-digit/dash variant is the
     * interesting one: it looks numeric at a glance and still is not, so a
     * "just use is_numeric, UUIDs are hex" argument cannot resurrect the old code.
     *
     * @return array<string, array{string}>
     */
    public static function uuidProvider(): array
    {
        return [
            'canonical v4' => [self::UUID],
            'all hex letters' => ['abcdefab-cdef-4abc-8def-abcdefabcdef'],
            'digits and dashes only' => ['12345678-1234-4123-8123-123456789012'],
            'uppercase' => ['B4F4B8FE-0C2F-4F4E-9A0B-2C9F8DE3A1C7'],
        ];
    }

    /**
     * Values that must all collapse to `null` — never to `''`, never to `0`.
     *
     * `ABSENT` is modelled by the key simply not being in the row.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function absentMediaItemIdProvider(): array
    {
        return [
            'key absent from the row' => [[]],
            'SQL NULL' => [['media_item_id' => null]],
            'empty string' => [['media_item_id' => '']],
        ];
    }

    #[DataProvider('uuidProvider')]
    public function testArtistFromRowKeepsTheChar36Uuid(string $uuid): void
    {
        $artist = MusicArtist::fromRow(['id' => 7, 'name' => 'Portishead', 'media_item_id' => $uuid]);

        $this->assertSame(
            $uuid,
            $artist->mediaItemId,
            'MusicArtist::fromRow() must carry the CHAR(36) media_item_id UUID through unchanged; '
            . 'an is_numeric() guard drops it to null',
        );
        $this->assertIsString($artist->mediaItemId, 'mediaItemId must stay a string, not be cast to int');
    }

    #[DataProvider('uuidProvider')]
    public function testAlbumFromRowKeepsTheChar36Uuid(string $uuid): void
    {
        $album = MusicAlbum::fromRow([
            'id' => 11,
            'artist_id' => 7,
            'title' => 'Dummy',
            'media_item_id' => $uuid,
        ]);

        $this->assertSame(
            $uuid,
            $album->mediaItemId,
            'MusicAlbum::fromRow() must carry the CHAR(36) media_item_id UUID through unchanged; '
            . 'an is_numeric() guard drops it to null',
        );
        $this->assertIsString($album->mediaItemId, 'mediaItemId must stay a string, not be cast to int');
    }

    #[DataProvider('uuidProvider')]
    public function testTrackFromRowKeepsTheChar36Uuid(string $uuid): void
    {
        $track = MusicTrack::fromRow([
            'id' => 23,
            'album_id' => 11,
            'artist_id' => 7,
            'title' => 'Roads',
            'media_item_id' => $uuid,
        ]);

        $this->assertSame(
            $uuid,
            $track->mediaItemId,
            'MusicTrack::fromRow() must carry the CHAR(36) media_item_id UUID through unchanged; '
            . 'an is_numeric() guard drops it to 0, which is what made every DTO-based track unplayable',
        );
        $this->assertIsString($track->mediaItemId, 'mediaItemId must stay a string, not be cast to int');
    }

    /**
     * @param array<string, mixed> $row
     */
    #[DataProvider('absentMediaItemIdProvider')]
    public function testArtistFromRowLeavesAnAbsentMediaItemIdNull(array $row): void
    {
        $artist = MusicArtist::fromRow($row + ['id' => 7, 'name' => 'Portishead']);

        $this->assertNull(
            $artist->mediaItemId,
            'An absent/NULL/empty media_item_id must stay null on MusicArtist — the scanner writes NULL when '
            . 'the media_items mint fails and backfills later, so null is a real state and "" would read as an id',
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    #[DataProvider('absentMediaItemIdProvider')]
    public function testAlbumFromRowLeavesAnAbsentMediaItemIdNull(array $row): void
    {
        $album = MusicAlbum::fromRow($row + ['id' => 11, 'artist_id' => 7, 'title' => 'Dummy']);

        $this->assertNull(
            $album->mediaItemId,
            'An absent/NULL/empty media_item_id must stay null on MusicAlbum, never ""',
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    #[DataProvider('absentMediaItemIdProvider')]
    public function testTrackFromRowLeavesAnAbsentMediaItemIdNull(array $row): void
    {
        $track = MusicTrack::fromRow($row + ['id' => 23, 'album_id' => 11, 'artist_id' => 7, 'title' => 'Roads']);

        $this->assertNull(
            $track->mediaItemId,
            'An absent/NULL/empty media_item_id must stay null on MusicTrack, never "" and never 0 — '
            . 'music_tracks.media_item_id is NOT NULL, so null here means the SELECT did not carry the column',
        );
    }

    /**
     * The UUID has to survive the JSON-serialisation hop too, since `toArray()`
     * is what any future emitter would call.
     */
    public function testToArrayEmitsTheUuidNotAnIntegerOnAllThreeDtos(): void
    {
        $artist = MusicArtist::fromRow(['id' => 7, 'name' => 'Portishead', 'media_item_id' => self::UUID]);
        $album = MusicAlbum::fromRow([
            'id' => 11,
            'artist_id' => 7,
            'title' => 'Dummy',
            'media_item_id' => self::UUID,
        ]);
        $track = MusicTrack::fromRow([
            'id' => 23,
            'album_id' => 11,
            'artist_id' => 7,
            'title' => 'Roads',
            'media_item_id' => self::UUID,
        ]);

        $this->assertSame(self::UUID, $artist->toArray()['media_item_id'], 'MusicArtist::toArray() dropped the UUID');
        $this->assertSame(self::UUID, $album->toArray()['media_item_id'], 'MusicAlbum::toArray() dropped the UUID');
        $this->assertSame(self::UUID, $track->toArray()['media_item_id'], 'MusicTrack::toArray() dropped the UUID');
    }

    /**
     * The type declaration is the other half of the fix: with `?int` still on the
     * property, a correct `?string` coercion would throw a `TypeError` instead of
     * failing an assertion. Asserting the declared type makes a partial revert
     * fail with a NAMED message rather than an opaque error.
     *
     * @return array<string, array{class-string}>
     */
    public static function dtoClassProvider(): array
    {
        return [
            'MusicArtist' => [MusicArtist::class],
            'MusicAlbum' => [MusicAlbum::class],
            'MusicTrack' => [MusicTrack::class],
        ];
    }

    /**
     * @param class-string $dtoClass
     */
    #[DataProvider('dtoClassProvider')]
    public function testMediaItemIdIsDeclaredAsANullableString(string $dtoClass): void
    {
        $type = (new ReflectionProperty($dtoClass, 'mediaItemId'))->getType();

        $this->assertInstanceOf(ReflectionNamedType::class, $type, $dtoClass . '::$mediaItemId has no simple type');
        $this->assertSame(
            'string',
            $type->getName(),
            $dtoClass . '::$mediaItemId must be declared string (media_item_id is CHAR(36)), not an integer',
        );
        $this->assertTrue(
            $type->allowsNull(),
            $dtoClass . '::$mediaItemId must be nullable — an unminted/absent media item is a legitimate null',
        );
    }

    /**
     * The guard against over-correcting. `id`, `artist_id`, `album_id`,
     * `track_number`, `disc_number` and `duration_secs` really ARE
     * `int unsigned` columns (migration 065/070), so they must keep coercing to
     * `int`. Only `media_item_id` changed type in S121.
     */
    public function testSiblingIntegerColumnsStillCoerceToInt(): void
    {
        // Strings are what an emulated-prepare driver hands back for INT columns.
        $track = MusicTrack::fromRow([
            'id' => '23',
            'media_item_id' => self::UUID,
            'album_id' => '11',
            'artist_id' => '7',
            'title' => 'Roads',
            'track_number' => '4',
            'disc_number' => '2',
            'duration_secs' => '302',
        ]);

        $this->assertSame(23, $track->id, 'music_tracks.id is INT UNSIGNED and must stay an int');
        $this->assertSame(11, $track->albumId, 'music_tracks.album_id is INT UNSIGNED and must stay an int');
        $this->assertSame(7, $track->artistId, 'music_tracks.artist_id is INT UNSIGNED and must stay an int');
        $this->assertSame(4, $track->trackNumber);
        $this->assertSame(2, $track->discNumber);
        $this->assertSame(302, $track->durationSecs);

        $album = MusicAlbum::fromRow([
            'id' => '11',
            'artist_id' => '7',
            'title' => 'Dummy',
            'year' => '1994',
            'total_tracks' => '11',
            'total_discs' => '1',
        ]);
        $this->assertSame(11, $album->id, 'music_albums.id is INT UNSIGNED and must stay an int');
        $this->assertSame(7, $album->artistId, 'music_albums.artist_id is INT UNSIGNED and must stay an int');
        $this->assertSame(1994, $album->year);

        $artist = MusicArtist::fromRow(['id' => '7', 'name' => 'Portishead']);
        $this->assertSame(7, $artist->id, 'music_artists.id is INT UNSIGNED and must stay an int');
    }
}
