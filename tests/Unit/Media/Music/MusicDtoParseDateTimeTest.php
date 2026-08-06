<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Music;

use Phlix\Media\Music\MusicAlbum;
use Phlix\Media\Music\MusicArtist;
use Phlix\Media\Music\MusicTrack;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins `parseDateTime()`'s two S143 defects on all three music DTOs.
 *
 * **Defect 1 — the helper FABRICATED a timestamp.** `new \DateTime('')` does not
 * throw; PHP's parser reads an empty or whitespace-only string as "now". So
 * `created_at => ''` returned **exactly the current second**, and
 * `'0000-00-00 00:00:00'` — MySQL's zero date — returned **`-0001-11-30`**. Both
 * were indistinguishable from a real value once returned. That is worse than
 * `null`: a `null` is visibly absent, a fabricated "now" looks plausible forever
 * and silently corrupts anything that sorts, filters or reports by date.
 *
 * **Defect 2 — the type guard was `\DateTime`, not `\DateTimeInterface`.**
 * `\DateTimeImmutable` does not extend `\DateTime`; both implement
 * `\DateTimeInterface`. A valid immutable therefore fell past the `is_string()`
 * check and was dropped to `null` — and modern PHP produces `\DateTimeImmutable`
 * by default.
 *
 * 🔴 **Why this file had to exist before the fix could be credited, and the
 * measurement that says so.** On `f701b40c`, the plan's own prescribed mutant —
 * `is_string($value)` → `is_string($value) && $value !== ''` in all three copies —
 * gave `Tests: 8978, Failures: 2`: **zero extra red.** The whole empty-input value
 * space was pinned by nothing. Meanwhile mutating `instanceof \DateTime` to
 * `instanceof \DateTimeImmutable` **did** red three tests
 * ({@see MusicDtoFromRowCoercionTest::testAnAlreadyParsedDateTimeIsPassedThroughByIdentity},
 * all three data sets). So the guard's **existence** was pinned while its
 * **breadth** was not, and a green identity test must NOT be read as coverage of
 * either defect. This is a value-space gap, not a line-coverage gap — the existing
 * "four outcomes" documentation is accurate about branches and wrong about values.
 *
 * ⚠ **The plan's prescribed mutant can no longer red anything, and that is
 * expected, not a hole.** Once `''` correctly returns `null`, adding
 * `&& $value !== ''` to the string guard is behaviour-PRESERVING: it routes the
 * empty string to the trailing `return null` instead of to the explicit empty
 * branch, and both return `null`. A mutant that cannot change behaviour cannot be
 * caught by any test, and demanding one would be demanding a test that pins the
 * shape of the fix rather than its contract. The live mutations are the INVERSE —
 * deleting the empty guard, deleting the zero-date guard, narrowing
 * `\DateTimeInterface` back to `\DateTime` — and every one of them is proven RED
 * against a named test below.
 *
 * **On S120 (assertions swallowed by a production `catch`).** `parseDateTime()`
 * contains a production `catch (\Exception)`, so the hazard is live in this file's
 * subject. It cannot fire here: no callback is passed into production code, every
 * assertion runs on the value `fromRow()` already returned, and this class contains
 * no `try`/`catch` of its own — so no `ExpectationFailedException` can be caught by
 * the code under test.
 */
final class MusicDtoParseDateTimeTest extends TestCase
{
    /**
     * The mandatory columns each DTO's `fromRow()` needs before the column under
     * test is added. Same convention and same values as
     * {@see MusicDtoFromRowCoercionTest}, so a reader comparing the two files is
     * not also comparing fixtures.
     *
     * @var array<class-string, array<string, mixed>>
     */
    private const REQUIRED_COLUMNS = [
        MusicArtist::class => ['id' => 7, 'name' => 'Portishead'],
        MusicAlbum::class => ['id' => 11, 'artist_id' => 7, 'title' => 'Dummy'],
        MusicTrack::class => ['id' => 23, 'album_id' => 11, 'artist_id' => 7, 'title' => 'Roads'],
    ];

    /**
     * All three DTOs, keyed by FQCN so a data-set name identifies its subject.
     *
     * @return array<string, array{class-string}>
     */
    public static function dtoProvider(): array
    {
        return [
            MusicArtist::class => [MusicArtist::class],
            MusicAlbum::class => [MusicAlbum::class],
            MusicTrack::class => [MusicTrack::class],
        ];
    }

    /**
     * Every string input that must become `null` rather than a fabricated instant,
     * crossed with all three DTOs.
     *
     * The shapes are deliberately VARIED rather than repeated: a single `''` case
     * would be killed by a fix that special-cases only `''`, and the whitespace and
     * zero-date families reach the parser by different routes (blank → "now",
     * zero date → year -1). One plant is not coverage.
     *
     * @return array<string, array{class-string, string}>
     */
    public static function degenerateTimestampProvider(): array
    {
        $inputs = [
            'empty string' => '',
            'single space' => ' ',
            'several spaces' => '   ',
            'tab and newline' => "\t\n",
            'mysql zero datetime' => '0000-00-00 00:00:00',
            'mysql zero date' => '0000-00-00',
            'mysql zero datetime with microseconds' => '0000-00-00 00:00:00.000000',
            'mysql zero datetime, space padded' => '  0000-00-00 00:00:00  ',
        ];

        $cases = [];
        foreach (self::dtoProvider() as $dtoClass => $args) {
            foreach ($inputs as $label => $input) {
                $cases[$dtoClass . ' / ' . $label] = [$args[0], $input];
            }
        }

        return $cases;
    }

    /**
     * The core S143 contract: a degenerate `created_at` becomes `null`, never a
     * plausible-looking instant.
     *
     * RED when the `$trimmed === ''` guard is deleted (blank inputs), and RED when
     * the `str_starts_with($trimmed, '0000-00-00')` guard is deleted (zero dates).
     * The two guards are therefore independently pinned — a fix that added only one
     * of them cannot pass this test.
     */
    #[DataProvider('degenerateTimestampProvider')]
    public function testADegenerateTimestampBecomesNullRatherThanAFabricatedInstant(
        string $dtoClass,
        string $input
    ): void {
        $dto = $this->fromRow($dtoClass, ['created_at' => $input, 'updated_at' => $input]);

        $this->assertNull(
            $dto->createdAt,
            $dtoClass . "::fromRow() turned a degenerate created_at into a DateTime instead of null.\n"
            . 'Input was ' . var_export($input, true) . ', it produced '
            . ($dto->createdAt === null ? 'null' : $dto->createdAt->format('Y-m-d H:i:s.u')) . ".\n"
            . "S143: new \\DateTime('') does NOT throw — PHP reads a blank string as \"now\", and\n"
            . "'0000-00-00…' as year -1. Either result is indistinguishable from a timestamp the\n"
            . "row actually carried, so it silently corrupts everything that sorts or filters by\n"
            . "date. null is visibly absent; a fabricated instant looks plausible forever.\n"
            . 'FIX: keep both guards in parseDateTime() — the blank check AND the zero-date check.'
        );
        $this->assertNull(
            $dto->updatedAt,
            $dtoClass . '::fromRow() applied the S143 guard to created_at but not to updated_at'
        );
    }

    /**
     * The fabrication stated as its own property rather than only as "is null".
     *
     * A future "fix" that returned the unix epoch, or `new \DateTime('@0')`, would
     * satisfy nothing this file cares about while still inventing a timestamp — but
     * it would satisfy a naive `assertNotEquals(now)`. So this asserts the invented
     * value specifically: whatever the helper returns for blank input, it must not
     * be an instant within a minute of the moment the test ran, which is the exact
     * signature of the defect.
     */
    #[DataProvider('dtoProvider')]
    public function testBlankInputDoesNotProduceTheCurrentTime(string $dtoClass): void
    {
        $before = new \DateTime();
        $dto = $this->fromRow($dtoClass, ['created_at' => '']);

        $produced = $dto->createdAt;
        $isNow = $produced !== null && abs($produced->getTimestamp() - $before->getTimestamp()) < 60;

        $this->assertFalse(
            $isNow,
            $dtoClass . "::fromRow() FABRICATED the current time from an empty created_at.\n"
            . 'It returned ' . ($produced === null ? 'null' : $produced->format('Y-m-d H:i:s'))
            . ', and the test started at ' . $before->format('Y-m-d H:i:s') . ".\n"
            . 'This is the S143 defect exactly: new \\DateTime(\'\') === "now".'
        );
    }

    /**
     * Defect 2. A `\DateTimeImmutable` must round-trip — same instant, same
     * microseconds, same timezone — instead of being dropped to `null`.
     *
     * RED when `instanceof \DateTimeInterface` is narrowed back to
     * `instanceof \DateTime`: an immutable then falls past `is_string()` and the
     * helper returns `null`.
     */
    #[DataProvider('dtoProvider')]
    public function testADateTimeImmutableRoundTrips(string $dtoClass): void
    {
        $immutable = new \DateTimeImmutable('2019-03-04 05:06:07.123456', new \DateTimeZone('America/New_York'));

        $dto = $this->fromRow($dtoClass, ['created_at' => $immutable]);

        $this->assertInstanceOf(
            \DateTime::class,
            $dto->createdAt,
            $dtoClass . "::fromRow() DROPPED a \\DateTimeImmutable created_at to null.\n"
            . "S143: \\DateTimeImmutable does NOT extend \\DateTime — both implement\n"
            . "\\DateTimeInterface — so an `instanceof \\DateTime` guard rejects a perfectly\n"
            . "valid immutable, and modern PHP produces immutables by default.\n"
            . 'FIX: guard on \\DateTimeInterface and convert with \\DateTime::createFromInterface().'
        );
        $this->assertSame(
            $immutable->format('Y-m-d H:i:s.u'),
            $dto->createdAt->format('Y-m-d H:i:s.u'),
            $dtoClass . '::fromRow() changed the instant (or lost the microseconds) while converting '
            . 'a \\DateTimeImmutable'
        );
        $this->assertSame(
            $immutable->getTimezone()->getName(),
            $dto->createdAt->getTimezone()->getName(),
            $dtoClass . '::fromRow() lost the timezone while converting a \\DateTimeImmutable — '
            . 'the same wall-clock reading in a different zone is a different instant'
        );
        $this->assertSame(
            $immutable->getTimestamp(),
            $dto->createdAt->getTimestamp(),
            $dtoClass . '::fromRow() produced a different instant from the \\DateTimeImmutable it was given'
        );
    }

    /**
     * The ORDERING inside the widened guard, which the S143 fix could plausibly
     * break: `\DateTime` implements `\DateTimeInterface`, so putting the interface
     * branch first would send a mutable through `createFromInterface()` and hand
     * back a COPY. Identity is pinned by
     * {@see MusicDtoFromRowCoercionTest::testAnAlreadyParsedDateTimeIsPassedThroughByIdentity};
     * it is re-asserted here because that ordering is now a live way to break it,
     * and a regression should red in the file that introduced the risk.
     */
    #[DataProvider('dtoProvider')]
    public function testAMutableDateTimeIsStillReturnedByIdentityAfterTheGuardWasWidened(string $dtoClass): void
    {
        $mutable = new \DateTime('2019-03-04 05:06:07');

        $dto = $this->fromRow($dtoClass, ['created_at' => $mutable]);

        $this->assertSame(
            $mutable,
            $dto->createdAt,
            $dtoClass . "::fromRow() re-wrapped a mutable \\DateTime instead of handing back the very\n"
            . "object it was given. \\DateTime implements \\DateTimeInterface, so the widened S143\n"
            . "guard must test `instanceof \\DateTime` FIRST; with the interface branch first, every\n"
            . 'mutable goes through createFromInterface() and callers lose object identity.'
        );
    }

    /**
     * ANTI-VACUITY / control. Every assertion above is satisfied by a helper that
     * returns `null` for absolutely everything, which would be a far worse bug than
     * the one being fixed. These cases prove real timestamps still parse to the
     * instant they name.
     *
     * @return array<string, array{class-string, string, string}>
     */
    public static function realTimestampProvider(): array
    {
        $inputs = [
            'mysql datetime' => ['2019-03-04 05:06:07', '2019-03-04 05:06:07'],
            'iso 8601' => ['2026-07-25T18:30:00', '2026-07-25 18:30:00'],
            'date only' => ['2019-03-04', '2019-03-04 00:00:00'],
            // The trim() added by S143 decides EMPTINESS only; the parser still
            // receives the original bytes, so a padded value keeps parsing exactly
            // as it did before. This case is what proves that claim.
            'space padded, still valid' => [' 2019-03-04 05:06:07 ', '2019-03-04 05:06:07'],
            // ⚠ CHECK THE DIFF AGAINST ITSELF. The zero-date guard is
            // `str_starts_with($trimmed, '0000-00-00')`, and the obvious
            // over-generalisation of it is "reject year 0000". This value shares the
            // guard's first FOUR characters and must still parse — it proves the
            // guard was not widened past the MySQL zero date it exists for.
            'year 0000 but a real date' => ['0000-01-01 00:00:00', '0000-01-01 00:00:00'],
        ];

        $cases = [];
        foreach (self::dtoProvider() as $dtoClass => $args) {
            foreach ($inputs as $label => [$input, $expected]) {
                $cases[$dtoClass . ' / ' . $label] = [$args[0], $input, $expected];
            }
        }

        return $cases;
    }

    #[DataProvider('realTimestampProvider')]
    public function testARealTimestampStillParsesToThatInstant(
        string $dtoClass,
        string $input,
        string $expected
    ): void {
        $dto = $this->fromRow($dtoClass, ['created_at' => $input]);

        $this->assertInstanceOf(
            \DateTime::class,
            $dto->createdAt,
            'ANTI-VACUITY: ' . $dtoClass . '::fromRow() returned null for the REAL timestamp '
            . var_export($input, true) . ".\n"
            . "The S143 guards are over-broad — they are now swallowing valid input, which is\n"
            . 'a worse defect than the fabrication they replaced. Narrow them.'
        );
        $this->assertSame(
            $expected,
            $dto->createdAt->format('Y-m-d H:i:s'),
            $dtoClass . '::fromRow() parsed ' . var_export($input, true) . ' to a different instant'
        );
    }

    /**
     * The pre-existing contracts S143 must not have disturbed: an unparseable
     * string and a non-string, non-`\DateTimeInterface` value are both `null`.
     * Re-asserted here because the fix rewrote the whole body of this helper.
     */
    #[DataProvider('dtoProvider')]
    public function testTheSurvivingNullContractsAreUnchanged(string $dtoClass): void
    {
        $dto = $this->fromRow($dtoClass, [
            'created_at' => 'not-a-timestamp',
            'updated_at' => 1551675967,
        ]);

        $this->assertNull(
            $dto->createdAt,
            $dtoClass . '::fromRow() must still swallow an unparseable created_at as null'
        );
        $this->assertNull(
            $dto->updatedAt,
            $dtoClass . '::fromRow() must still reject an int updated_at as null — it is NOT read as a '
            . 'unix timestamp, and S143 did not change that'
        );
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
}
