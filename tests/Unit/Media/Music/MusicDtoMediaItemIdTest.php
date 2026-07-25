<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Music;

use Phlix\Media\Music\MusicAlbum;
use Phlix\Media\Music\MusicArtist;
use Phlix\Media\Music\MusicTrack;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use RuntimeException;
use SplFileInfo;

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
     * Every class **whose file is named after it** under `src/Media/Music/`
     * (recursively) that DECLARES a `mediaItemId` property — discovered by walking
     * the directory and reflecting it, deliberately NOT a hand-written list.
     *
     * ⚠ Read that qualifier literally; it is measured, not hedging. The sweep derives
     * one FQCN per file from the file's own path, so a class that is **not** the
     * PSR-4 class of its file is invisible to it: r3 finding 5 planted a file
     * declaring a second, differently-named class with the pre-S121
     * `public ?int $mediaItemId` and the suite stayed green. PSR-4 makes that shape
     * abnormal (and `phpcs`/autoloading would not find such a class either), so it is
     * documented rather than chased here; a token/AST-level sweep is the shape that
     * would close it, together with the scanner's two inline copies — **S127**.
     *
     * ⚠ That qualifier is about the CONTENTS of a file and says nothing about a class
     * that is perfectly PSR-4 — which is why it did not cover r4 finding 1, where a
     * legitimate `src/Media/Music/Dto/MusicTrack.php` was dropped because the result
     * set was keyed by SHORT name. The keys are now fully qualified; the notes at the
     * `$found[$canonical]` assignment record the measurements. When you widen what
     * this walk reaches, re-derive what the old narrowness was holding up — that has
     * been the failure mode of every round of this step.
     *
     * ⚠ **Why mechanical.** S121's whole cause was a partial sweep: the coercion bug
     * was written down for `MusicTrack` alone and the two sibling DTOs were missed.
     * A hardcoded three-name provider reproduces exactly that hazard — a fourth
     * music DTO could land tomorrow with the old `is_numeric()`/`int` coercion and
     * nothing here would go red. Globbing means the alarm covers classes that do not
     * exist yet.
     *
     * Measured before adopting the blanket rule: of the 11 files in that tree, all 11
     * resolve to classes and exactly 3 declare a `mediaItemId` property
     * (`MusicArtist`, `MusicAlbum`, `MusicTrack`), all `?string`. There is **no**
     * class there for which a different `mediaItemId` type would be legitimate, so
     * this sweep carries **no exclusion list** — if one is ever needed, it must be
     * added here with a stated reason rather than by narrowing the walk.
     *
     * Innocent additions verified NOT to break it (r3, on PHP 8.3.6): pure and backed
     * enums, an abstract class without the property, a class whose constructor
     * requires arguments (nothing is instantiated), and interface/trait files.
     *
     * Inherited properties are skipped (`getDeclaringClass()` check) so the
     * assertion lands once, on the class that actually declares the type. A file
     * that cannot be LOADED is a hard named failure rather than a skip — see the
     * comment at the `class_exists()` check for why, and r2 finding 3 for the
     * measurement that a silent skip makes the set-equality test below pass.
     *
     * ⚠ **This covers 3 of the 5 predicate sites and cannot cover the other 2.** It
     * reflects classes declaring a `mediaItemId` **property**; the scanner's two
     * copies are inline locals, so the drift shape that produced two of the five
     * sites is doc-only. Closing that is step **S127** — see the DTO docblocks.
     *
     * Keys are the classes' FULLY-QUALIFIED names — they become the PHPUnit data-set
     * names — sorted with `ksort()`; each value is that same FQCN, as the single test
     * argument.
     *
     * @return array<class-string, array{class-string}>
     */
    public static function musicClassesDeclaringMediaItemIdProvider(): array
    {
        // ⚠⚠ MEMOISED, and that is a CORRECTNESS requirement, not an optimisation
        // (S121 review r3 finding 1). Composer's loader includes a candidate file
        // with a plain `include` and `loadClass()` reports success even when the file
        // defines nothing matching the FQCN, while `findFile()` caches no negative
        // result for a file that EXISTS — so every further autoload attempt for that
        // FQCN RE-EXECUTES the file. A second execution of a file that declares a
        // differently-named class, or any non-class symbol, is a PHP FATAL
        // ("Cannot declare class …, because the name is already in use" / "Cannot
        // redeclare …"), which kills the whole runner: exit 255, PHPUnit banner only,
        // 0 of 7,511 tests run and no attribution. This provider is invoked TWICE per
        // run (PHPUnit's data-provider resolution, plus the explicit call in
        // testTheSweepDiscoversExactlyTheThreeKnownMusicDtos()), so without the memo
        // the second invocation retries the failed autoload and detonates instead of
        // reporting. Both this memo AND the `$autoload = false` flags below are
        // required — r3 measured that either one alone still fatals.
        //
        // With both in place the throw below surfaces as a PHPUnit *error* and the
        // runner exits **2** — measured, and the whole point of the design: a
        // non-zero exit is what reddens CI. (Fix r3's proof table recorded "exit 0"
        // for those builds; that was a `… | tail -N; echo $?` artefact reporting
        // `tail`'s status, corrected by review r4 finding 2. A guard that printed a
        // named message and let the pipeline pass would be cosmetic.)
        //
        // On the resident-memory rules: this is test-only code that never runs in a
        // Workerman worker, and the memo is bounded — one array of at most the number
        // of music classes, plus one string — never keyed by request data. It is not
        // the unbounded-static-array shape those rules ban.
        /** @var array<class-string, array{class-string}>|null $memo */
        static $memo = null;
        /** @var string|null $memoError */
        static $memoError = null;

        if ($memoError !== null) {
            throw new RuntimeException($memoError);
        }
        if ($memo !== null) {
            return $memo;
        }

        $dir = dirname(__DIR__, 4) . '/src/Media/Music';
        // RECURSIVE (r3 finding 4): a plain `*.php` glob missed a DTO in a
        // subdirectory — measured, `src/Media/Music/Sub/MusicHiddenDto.php` with the
        // pre-S121 `?int` left the suite green. PSR-4 maps a subdirectory to a
        // namespace segment, so the FQCN is derived from the path RELATIVE to $dir.
        $files = self::phpFilesUnder($dir);

        if ($files === []) {
            $memoError = 'media_item_id sweep found no PHP files under ' . $dir
                . ' — the discovery glob is broken, so the sweep proves nothing. Fix the path.';

            throw new RuntimeException($memoError);
        }

        $found = [];
        // The path each canonical name was FIRST reached from, so the collision throw
        // below can name BOTH offending files instead of one plus a directory
        // (S121 review r5 INFO-1). Written in lockstep with $found, one line apart.
        /** @var array<class-string, string> $firstFile */
        $firstFile = [];
        foreach ($files as $file) {
            $relative = substr($file, strlen($dir) + 1, -strlen('.php'));
            /** @var class-string $fqcn */
            $fqcn = 'Phlix\\Media\\Music\\' . str_replace('/', '\\', $relative);

            // ⚠ A file this sweep cannot LOAD must be a hard, named failure, never a
            // silent `continue` (S121 review r2 finding 3). A skipped file is exactly
            // a partial sweep — the defect S121 exists to remove — and it degrades
            // invisibly: r2 proved that with `setClassMapAuthoritative(true)` forced
            // on the loader, an un-dumped 4th DTO becomes unloadable, the discovered
            // set silently shrinks back to the three known names, and the
            // set-equality test below then PASSES with the bad class sitting on disk.
            // That state is unreachable in this repo today (composer.json sets
            // `optimize-autoloader` but never `classmap-authoritative`, and nothing
            // in the repo, workflows or scripts calls setClassMapAuthoritative), so
            // the PSR-4 fallback always resolves a new file — but the guard must not
            // depend on a config it does not own.
            //
            // ⚠ The `false` second argument is the other half of r3 finding 1: it
            // DISABLES autoloading for these two probes. `class_exists($fqcn)` above
            // has already run the autoloader, so the file is either loaded or
            // unresolvable; re-entering the autoloader here would re-`include` a file
            // that defined nothing matching and turn a reportable condition into a
            // fatal. Never drop the `false`.
            if (!class_exists($fqcn) && !interface_exists($fqcn, false) && !trait_exists($fqcn, false)) {
                $memoError = sprintf(
                    'media_item_id sweep could not load %s from %s. It is REFUSING to skip the file: a '
                    . 'silently omitted class is a partial sweep, which is the exact defect S121 fixed, and '
                    . 'it would let a music DTO keep the pre-S121 is_numeric()/int coercion undetected. '
                    . 'Usual causes: the class name does not match the file name, the file declares no '
                    . 'class at all, the namespace does not match the path under src/Media/Music, or the '
                    . 'autoloader cannot resolve the file (e.g. a classmap-authoritative install, which '
                    . 'disables the PSR-4 fallback this sweep relies on — run `composer dump-autoload`).',
                    $fqcn,
                    $file,
                );

                throw new RuntimeException($memoError);
            }

            if (!class_exists($fqcn)) {
                // Loadable, but an interface or trait: no instance property to type.
                continue;
            }

            $reflection = new ReflectionClass($fqcn);
            // The class's OWN canonical name, not the path-derived $fqcn. PHP class
            // names are case-insensitive, so a file whose name differs from its class
            // only in case (`Dto/musictrack.php` declaring `MusicTrack`) still resolves
            // through `class_exists()`, and then $fqcn carries the FILE's spelling
            // while reflection carries the CLASS's. Keying and comparing on the
            // canonical name is what stops that pair being seen as two classes, and it
            // is also what makes the `getDeclaringClass()` check below correct in that
            // case: comparing it against $fqcn instead made the case-variant file's
            // `mediaItemId` look inherited and SILENTLY dropped it (measured RED-on-fix
            // in r4).
            $canonical = $reflection->getName();
            foreach ($reflection->getProperties() as $property) {
                if ($property->getName() !== 'mediaItemId') {
                    continue;
                }
                if ($property->getDeclaringClass()->getName() !== $canonical) {
                    continue;
                }
                // ⚠⚠ KEYED BY FULLY-QUALIFIED NAME, never by `getShortName()`
                // (S121 review r4 finding 1). The short name was unique only while
                // this walk was FLAT; the recursion added in r3 made
                // `src/Media/Music/Dto/MusicTrack.php` and
                // `src/Media/Music/MusicTrack.php` two different classes with ONE key,
                // so the loser was overwritten and vanished from the sweep. Measured:
                // a brand-new `Dto/MusicTrack` carrying the pre-S121
                // `public ?int $mediaItemId` left the suite GREEN, i.e. a bad DTO
                // passed — the exact partial-sweep failure S121 exists to remove — and
                // in the other sort direction (`Sub/MusicTrack`) the REAL
                // `Phlix\Media\Music\MusicTrack` was the one substituted out, with the
                // set-equality test below unable to see it because the key set had not
                // changed. The FQCN is unique per file by construction, so every
                // discovered class now gets its own data set and its own type
                // assertion. Keep the expectation in
                // testTheSweepDiscoversExactlyTheThreeKnownMusicDtos() expressed in
                // the same terms.
                if (isset($found[$canonical])) {
                    // ⚠ Name BOTH colliding paths, and INFER the cause from them rather
                    // than assert one (S121 review r5 INFO-1). The first version printed
                    // only the current $file plus the search directory and always blamed
                    // "names differing only in case". For the `class_alias()` collision
                    // measured below (that probe, and that probe only), the path the old
                    // message NAMED — $file, the CURRENT one — was the REAL class's own
                    // file, so it fingered a perfectly correct file: a probe aliasing
                    // MusicTrack from Aaa/Alias.php read "reached …\MusicTrack twice, from
                    // …/src/Media/Music/MusicTrack.php and from an earlier file under …",
                    // never mentioning Aaa/Alias.php at all. That report generalises no
                    // further than that one ordering. Which of the pair $file is, is
                    // decided by walk order alone: r6's mirror probe (Zzz/Alias.php, which
                    // sorts AFTER MusicTrack.php) made $file the ALIAS, so for THAT
                    // ordering the old message's one named path would have been the
                    // REDUNDANT file instead. Which SORTED position either file lands in
                    // therefore depends on walk order, so neither this comment nor the
                    // message below claims one (S121 review r6/r7). A guard that fires
                    // loudly but blames an innocent file is the kind the next reader
                    // deletes as broken, which is exactly the failure mode this sweep
                    // exists to stop.
                    $first = $firstFile[$canonical];
                    $cause = strtolower($first) === strtolower($file)
                        // Measured discriminator, not a guess: the case-variant shape is
                        // the one where the two PATHS are equal case-insensitively.
                        ? 'Those two paths differ only in CASE, and PHP class names are case-insensitive, so '
                            . 'both files resolve to this one class. Delete or rename one of them.'
                        : 'Those two paths are NOT case variants of each other, so the cause is inside one of '
                            . 'them: a class_alias(), a namespace that does not match the directory, a second '
                            . 'class declared in a file named after a different one, or a symlink to the other '
                            . 'file. `grep -nE "class_alias|^namespace|class " <both paths>` tells them apart. '
                            . 'Delete or rename whichever file is REDUNDANT — this sweep cannot tell which one '
                            . 'that is, and either path may be the legitimate home, so read both before '
                            . 'editing either.';
                    $memoError = sprintf(
                        'media_item_id sweep reached %s twice, from %s and from %s. Two files resolving to '
                        . 'ONE class means the sweep can only report one of them, so it is refusing to '
                        . 'silently keep the last one (that overwrite is r4 finding 1). %s',
                        $canonical,
                        $first,
                        $file,
                        $cause,
                    );

                    throw new RuntimeException($memoError);
                }
                $found[$canonical] = [$canonical];
                $firstFile[$canonical] = $file;
            }
        }

        ksort($found);
        $memo = $found;

        return $found;
    }

    /**
     * Every `*.php` under `$dir`, recursively, sorted so the sweep order is stable.
     *
     * Recursion is what closes r3 finding 4: PSR-4 maps `src/Media/Music/Sub/X.php`
     * to `Phlix\Media\Music\Sub\X`, so a DTO in a subdirectory is a real music DTO
     * that a non-recursive `glob()` silently missed.
     *
     * @return list<string> Absolute paths, `/`-separated.
     */
    private static function phpFilesUnder(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $found = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $entry) {
            if ($entry instanceof SplFileInfo && $entry->isFile() && $entry->getExtension() === 'php') {
                $found[] = $entry->getPathname();
            }
        }

        sort($found);

        return $found;
    }

    /**
     * The doc-sync half of the sweep. The three DTO docblocks state "the sweep is
     * FIVE sites, not three" and name the three helpers; if a fourth music class
     * ever declares `mediaItemId`, that wording — and the `grep` recipes beside it —
     * become wrong, so this test fails and forces the docs to be updated with it.
     * It also catches a silently-empty glob, which would make the provider-driven
     * test above pass vacuously.
     *
     * ⚠ The expectation is FULLY-QUALIFIED, in the same terms as the provider's keys
     * (r4 finding 1). With short names, a new `Phlix\Media\Music\Dto\MusicTrack`
     * could replace `Phlix\Media\Music\MusicTrack` under the single key `MusicTrack`
     * and this set stayed unchanged — the substitution was invisible to the one test
     * whose job is to see set changes. Namespaced keys make any added, removed OR
     * substituted class a failure here.
     */
    public function testTheSweepDiscoversExactlyTheThreeKnownMusicDtos(): void
    {
        $this->assertSame(
            [
                'Phlix\\Media\\Music\\MusicAlbum',
                'Phlix\\Media\\Music\\MusicArtist',
                'Phlix\\Media\\Music\\MusicTrack',
            ],
            array_keys(self::musicClassesDeclaringMediaItemIdProvider()),
            'The set of src/Media/Music classes declaring a `mediaItemId` property changed. If a class was '
            . 'ADDED, give it the ?string CHAR(36) coercion and update the "FIVE sites" note in all three DTO '
            . 'docblocks; if one was removed, update the same note. Names are fully qualified on purpose — a '
            . 'class in a subdirectory is a real music DTO, not a duplicate of the same-named one above it. '
            . 'Do not just edit this list.',
        );
    }

    /**
     * The type declaration is the other half of the fix: with `?int` still on the
     * property, a correct `?string` coercion would throw a `TypeError` instead of
     * failing an assertion. Asserting the declared type makes a partial revert
     * fail with a NAMED message rather than an opaque error.
     *
     * @param class-string $dtoClass
     */
    #[DataProvider('musicClassesDeclaringMediaItemIdProvider')]
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
