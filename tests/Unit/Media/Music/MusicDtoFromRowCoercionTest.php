<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Music;

use Phlix\Media\Music\MusicAlbum;
use Phlix\Media\Music\MusicArtist;
use Phlix\Media\Music\MusicTrack;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Closes the two MEASURED gaps left in `fromRow()` after S121 (test stage, role D).
 *
 * **Why a second file, and what it deliberately does not repeat.** The S121
 * behaviour — a `CHAR(36)` UUID survives, an absent/NULL/empty `media_item_id`
 * stays `null`, the sibling `int` columns keep coercing, and the mechanical
 * `src/Media/Music/` sweep — is pinned by
 * {@see MusicDtoMediaItemIdTest}, which is left byte-untouched. What that file
 * pins is `media_item_id`'s **line** coverage: every statement S121 added is
 * executed by it (measured: pcov reports the four changed lines in each of the
 * three DTOs as covered). What it does not pin is the rest of each predicate's
 * **value space**, and two mutants were measured to survive it with the file
 * fully green — `OK (27 tests, 56 assertions)`, exit 0, both times:
 *
 *   1. deleting `is_string($value) &&` from all three `mediaItemIdFromRow()`
 *      helpers. Every existing data set still passes, because PHP's strict
 *      `null !== ''` is `true`, so the absent/NULL cases return `null` anyway and
 *      the UUID cases are strings regardless. The predicate that makes the helper
 *      TOTAL — the one that stops a non-string, non-null column value being
 *      returned from a `?string` function — was therefore unpinned.
 *   2. rewriting `$value !== ''` as `!empty($value)`, the "simplification" a
 *      future reader is most likely to make. Also fully green, because
 *      `!empty('')` is false exactly like `'' !== ''` — but `!empty('0')` is
 *      ALSO false, so a legitimate one-character `'0'` would start returning
 *      `null`.
 *
 * `0` is not a hypothetical input: it is precisely what the pre-S121
 * `MusicTrack::toArray()` emitted for `media_item_id` (the old fallback), so any
 * row that round-trips through cached/persisted pre-S121 output carries it. It
 * must become `null` — "no id", detectable — and must never be handed back as a
 * plausible-looking id.
 *
 * The second half of this file covers `parseDateTime()`, the other coercion in
 * the same `fromRow()` bodies, whose four outcomes were **entirely uncovered**
 * without a reachable MySQL and only partly covered with one. It is reached only
 * through `fromRow()`, one line below the lines S121 changed.
 *
 * **On S120 (assertions swallowed by a production `catch`).** `parseDateTime()`
 * DOES contain a production `catch (\Exception)`, so the hazard is live in this
 * file's subject. It cannot fire here: this class passes no callback into
 * production code, every assertion runs on the value `fromRow()` returned after
 * that `catch` has already completed, and this class contains no `try`/`catch` of
 * its own. So no `ExpectationFailedException` (which is both a `Throwable` and a
 * `RuntimeException`) can be caught by the code under test. Proven by mutation
 * rather than asserted: every test below was driven RED by breaking the exact
 * line it names.
 *
 */
final class MusicDtoFromRowCoercionTest extends TestCase
{
    /**
     * The mandatory columns each DTO's `fromRow()` needs before the column under
     * test is added. Keyed by FQCN so a data-set name identifies its subject —
     * the same convention {@see MusicDtoMediaItemIdTest} settled on.
     *
     * @var array<class-string, array<string, mixed>>
     */
    private const REQUIRED_COLUMNS = [
        MusicArtist::class => ['id' => 7, 'name' => 'Portishead'],
        MusicAlbum::class => ['id' => 11, 'artist_id' => 7, 'title' => 'Dummy'],
        MusicTrack::class => ['id' => 23, 'album_id' => 11, 'artist_id' => 7, 'title' => 'Roads'],
    ];

    /**
     * One data set per DTO, for the cases that do not vary by value.
     *
     * @return array<string, array{class-string}>
     */
    public static function dtoProvider(): array
    {
        $cases = [];
        foreach (array_keys(self::REQUIRED_COLUMNS) as $dtoClass) {
            $cases[$dtoClass] = [$dtoClass];
        }

        return $cases;
    }

    /**
     * Non-string, non-`null` `media_item_id` values, crossed with all three DTOs.
     *
     * `0` is the pre-S121 `MusicTrack` fallback, i.e. the value the old code
     * itself produced; the rest are the other shapes a `mixed` row value can take
     * once anything other than `workerman/mysql` builds the array (a JSON decode,
     * a cached payload, a hand-written fixture).
     *
     * @return array<string, array{class-string, mixed}>
     */
    public static function nonStringMediaItemIdProvider(): array
    {
        $values = [
            'the pre-S121 int fallback 0' => 0,
            'a positive int' => 123,
            'a float' => 4.0,
            'a bool' => true,
        ];

        $cases = [];
        foreach (array_keys(self::REQUIRED_COLUMNS) as $dtoClass) {
            foreach ($values as $label => $value) {
                $cases[$dtoClass . ' / ' . $label] = [$dtoClass, $value];
            }
        }

        return $cases;
    }

    /**
     * Strings that a `!empty()` or a re-added `is_numeric()`/`(int)` would ruin,
     * crossed with all three DTOs.
     *
     * `'0'` is falsy, so `!empty('0')` is false; `'123'` is `is_numeric()`-true,
     * so the pre-S121 predicate would have cast it to the int `123`. Both are
     * legitimate `CHAR(36)` contents — the DTO deliberately does not validate UUID
     * format (the FK to `media_items` does), which is what the DTO docblocks say.
     *
     * @return array<string, array{class-string, string}>
     */
    public static function truthyAwkwardStringProvider(): array
    {
        $values = [
            "the falsy string '0'" => '0',
            "the is_numeric()-true string '123'" => '123',
        ];

        $cases = [];
        foreach (array_keys(self::REQUIRED_COLUMNS) as $dtoClass) {
            foreach ($values as $label => $value) {
                $cases[$dtoClass . ' / ' . $label] = [$dtoClass, $value];
            }
        }

        return $cases;
    }

    /**
     * Anti-vacuity pin for the three providers above.
     *
     * PHPUnit 10 already errors on a data provider that returns `[]` (and
     * `phpunit.xml` sets `failOnWarning="true"`), so this is not about the empty
     * case: it is about a provider that SHRINKS. Every provider here is a cross
     * product built in a loop, so dropping one DTO from
     * {@see REQUIRED_COLUMNS} would quietly delete a third of the coverage below
     * while every remaining data set still passed. Counted, and the DTO set
     * pinned by fully-qualified name.
     */
    public function testTheProvidersCoverAllThreeDtos(): void
    {
        $this->assertSame(
            [MusicAlbum::class, MusicArtist::class, MusicTrack::class],
            $this->sorted(array_keys(self::dtoProvider())),
            'dtoProvider() must cover exactly the three music DTOs that declare a mediaItemId property. If a '
            . 'fourth one landed, MusicDtoMediaItemIdTest::testTheSweepDiscoversExactlyTheThreeKnownMusicDtos() '
            . 'is the mechanical alarm — add it to REQUIRED_COLUMNS here as well.',
        );
        $this->assertCount(
            12,
            self::nonStringMediaItemIdProvider(),
            'nonStringMediaItemIdProvider() must stay a full 3 DTOs x 4 values cross product',
        );
        $this->assertCount(
            6,
            self::truthyAwkwardStringProvider(),
            'truthyAwkwardStringProvider() must stay a full 3 DTOs x 2 values cross product',
        );
    }

    /**
     * The `is_string()` half of the predicate, which no existing test pinned:
     * a non-string, non-`null` `media_item_id` must collapse to `null`.
     *
     * Deleting `is_string($value) &&` leaves every data set in
     * {@see MusicDtoMediaItemIdTest} green (measured); it makes each case here
     * return the raw value out of a `?string` function, i.e. a `TypeError`.
     */
    #[DataProvider('nonStringMediaItemIdProvider')]
    public function testANonStringMediaItemIdCollapsesToNull(string $dtoClass, mixed $value): void
    {
        $dto = $this->fromRow($dtoClass, ['media_item_id' => $value]);

        $this->assertNull(
            $dto->mediaItemId,
            $dtoClass . '::fromRow() must reject a non-string media_item_id as null. The column is CHAR(36) and '
            . 'workerman/mysql hands it back as a string, so a non-string means the row was not built by the '
            . 'driver — most likely it carries the pre-S121 int fallback, which must NOT survive as an id.',
        );
    }

    /**
     * The `$value !== ''` half must stay a STRICT emptiness test.
     *
     * `'0'` and `'123'` are truthy-awkward but perfectly legal `CHAR(36)`
     * contents. `assertSame()` is deliberate on both counts: it fails if the value
     * were dropped to `null` (an `!empty()` rewrite) and it fails if it were cast
     * to an int (a re-added `is_numeric()`/`(int)` coercion), which
     * `assertEquals()` would not.
     */
    #[DataProvider('truthyAwkwardStringProvider')]
    public function testATruthyAwkwardStringMediaItemIdSurvivesUnchanged(string $dtoClass, string $value): void
    {
        $dto = $this->fromRow($dtoClass, ['media_item_id' => $value]);

        $this->assertSame(
            $value,
            $dto->mediaItemId,
            $dtoClass . '::fromRow() must pass a non-empty media_item_id through byte-for-byte. Two ways to break '
            . "this: `!empty(\$value)` (false for '0') and a re-added (int) cast (turns '123' into 123). The DTO "
            . 'deliberately does not validate UUID format — the FK to media_items does.',
        );
    }

    /**
     * `parseDateTime()`, string branch. Both timestamps are asserted and they are
     * DELIBERATELY DIFFERENT values, so swapping `created_at` and `updated_at`
     * fails instead of passing on an accidentally identical fixture.
     */
    #[DataProvider('dtoProvider')]
    public function testFromRowParsesBothTimestampStrings(string $dtoClass): void
    {
        $created = '2019-03-04 05:06:07';
        $updated = '2026-07-25 18:30:00';

        $dto = $this->fromRow($dtoClass, ['created_at' => $created, 'updated_at' => $updated]);

        $this->assertInstanceOf(\DateTime::class, $dto->createdAt, $dtoClass . ' dropped created_at');
        $this->assertInstanceOf(\DateTime::class, $dto->updatedAt, $dtoClass . ' dropped updated_at');
        $this->assertSame(
            $created,
            $dto->createdAt->format('Y-m-d H:i:s'),
            $dtoClass . '::fromRow() must parse created_at into that same instant, not a different one',
        );
        $this->assertSame(
            $updated,
            $dto->updatedAt->format('Y-m-d H:i:s'),
            $dtoClass . '::fromRow() must parse updated_at into that same instant, not created_at',
        );
    }

    /**
     * `parseDateTime()`, already-a-`DateTime` branch. `assertSame()` pins object
     * IDENTITY, so re-wrapping or cloning the instance fails too.
     */
    #[DataProvider('dtoProvider')]
    public function testAnAlreadyParsedDateTimeIsPassedThroughByIdentity(string $dtoClass): void
    {
        $created = new \DateTime('2019-03-04 05:06:07');
        $updated = new \DateTime('2026-07-25 18:30:00');

        $dto = $this->fromRow($dtoClass, ['created_at' => $created, 'updated_at' => $updated]);

        $this->assertSame(
            $created,
            $dto->createdAt,
            $dtoClass . '::fromRow() must hand back the very DateTime it was given, not a copy of it',
        );
        $this->assertSame($updated, $dto->updatedAt, $dtoClass . ' re-wrapped updated_at');
    }

    /**
     * `parseDateTime()`, the production `catch (\Exception)`. An unparseable
     * string must become `null` — the row must not blow up `fromRow()`.
     *
     * PHP 8.3 raises `DateMalformedStringException` here, which extends
     * `\Exception`, so the existing `catch (\Exception)` still holds. The
     * assertion runs on the returned DTO, AFTER that catch has completed, so it
     * cannot itself be swallowed by it (S120).
     */
    #[DataProvider('dtoProvider')]
    public function testAnUnparseableTimestampBecomesNullInsteadOfThrowing(string $dtoClass): void
    {
        $dto = $this->fromRow($dtoClass, ['created_at' => 'not-a-timestamp', 'updated_at' => '2026-07-25 18:30:00']);

        $this->assertNull(
            $dto->createdAt,
            $dtoClass . '::fromRow() must swallow an unparseable created_at as null rather than letting '
            . 'DateMalformedStringException escape — one bad row must not 500 a whole listing',
        );
        $this->assertInstanceOf(
            \DateTime::class,
            $dto->updatedAt,
            $dtoClass . ' must still parse the GOOD timestamp in the same row — the null must be per-column, '
            . 'not "any bad value voids the row"',
        );
    }

    /**
     * `parseDateTime()`, neither-`DateTime`-nor-string branch: the trailing
     * `return null`. `isset()` in `fromRow()` is false for a SQL NULL, so this
     * branch is reached only by a present, non-null, non-string value — an int
     * unix timestamp being the realistic one.
     */
    #[DataProvider('dtoProvider')]
    public function testANonStringNonDateTimeTimestampBecomesNull(string $dtoClass): void
    {
        $dto = $this->fromRow($dtoClass, ['created_at' => 1551675967, 'updated_at' => ['2026-07-25']]);

        $this->assertNull(
            $dto->createdAt,
            $dtoClass . '::fromRow() must reject an int created_at as null. It is NOT treated as a unix '
            . 'timestamp — the music tables store DATETIME, so an int here means the row did not come from '
            . 'the driver and guessing at its meaning would invent data.',
        );
        $this->assertNull($dto->updatedAt, $dtoClass . ' must reject an array updated_at as null');
    }

    /**
     * Builds one DTO from its mandatory columns plus the columns under test.
     *
     * `match` without a `default` on purpose: a fourth music DTO makes this throw
     * `UnhandledMatchError` instead of silently testing two of three.
     *
     * @param class-string         $dtoClass
     * @param array<string, mixed> $row
     */
    private function fromRow(string $dtoClass, array $row): MusicArtist|MusicAlbum|MusicTrack
    {
        $full = $row + self::REQUIRED_COLUMNS[$dtoClass];

        return match ($dtoClass) {
            MusicArtist::class => MusicArtist::fromRow($full),
            MusicAlbum::class => MusicAlbum::fromRow($full),
            MusicTrack::class => MusicTrack::fromRow($full),
        };
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
