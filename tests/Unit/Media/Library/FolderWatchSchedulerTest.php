<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library;

use Phlix\Common\Logger\LoggerFactory;
use Phlix\Media\Library\FolderWatchScheduler;
use Phlix\Media\Library\FolderWatcher;
use Phlix\Media\Library\LibraryManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

/**
 * The driver that makes {@see FolderWatcher} live.
 *
 * The defect this suite guards is a two-part "exists but never runs":
 * `FolderWatcher::checkForChanges()` had NO caller anywhere in the repo, so
 * `LibraryUpdated` was never dispatched; and `FolderWatcher::watch()` is called
 * only from `LibraryManager::createLibrary()` (an HTTP path), so a managed
 * worker's watch list was empty anyway. A driver that fixed only the first half
 * would still detect nothing.
 *
 * The second half of the guard is the cost bound: a check is a full recursive
 * `stat()` walk that blocks the `library-scan` worker's event loop, so a tick
 * must touch at most ONE library, with one deliberate exception: a tick whose
 * registration walk THREW also re-checks one library, so a library that can
 * never be registered cannot starve the re-check phase for the others. The
 * bound is pinned by the all-succeeding shapes below; the exception is pinned
 * by {@see testAnUnregisterableLibraryDoesNotStarveTheHealthyOnes()}.
 */
final class FolderWatchSchedulerTest extends TestCase
{
    protected function setUp(): void
    {
        LoggerFactory::init(__DIR__ . '/../../../../config/logger.php');
    }

    // -------------------------------------------------------------------
    // The feature flag
    // -------------------------------------------------------------------

    /**
     * Ships OFF. `start()` must arm nothing and, just as importantly, must not
     * touch the DB or the filesystem — a disabled install has to behave exactly
     * as it did before this class existed.
     */
    public function testDisabledSchedulerArmsNothingAndDoesNoWork(): void
    {
        $watcher = $this->createMock(FolderWatcher::class);
        $watcher->expects($this->never())->method('watch');
        $watcher->expects($this->never())->method('checkLibrary');

        $libraries = $this->createMock(LibraryManager::class);
        $libraries->expects($this->never())->method('getAllLibraries');

        $scheduler = new FolderWatchScheduler($watcher, $libraries, null, false, 300);

        $this->assertFalse($scheduler->isEnabled());
        $this->assertFalse($scheduler->start());
    }

    /**
     * The constructor default is OFF, so a mis-wired container that skips the
     * `enabled` parameter fails safe (filesystem polling stays off) rather than
     * silently turning polling on. PHP-DI skips optional constructor
     * parameters, which is exactly how such a mis-wiring happens.
     */
    public function testEnabledDefaultsToFalse(): void
    {
        $scheduler = new FolderWatchScheduler(
            $this->createMock(FolderWatcher::class),
            $this->createMock(LibraryManager::class)
        );

        $this->assertFalse($scheduler->isEnabled());
    }

    /**
     * `start()` itself must not walk or query — the first library is only
     * registered on the first tick, so worker boot stays fast.
     */
    public function testStartDoesNoWorkEvenWhenEnabled(): void
    {
        $watcher = $this->createMock(FolderWatcher::class);
        $watcher->expects($this->never())->method('watch');
        $watcher->expects($this->never())->method('checkLibrary');

        $libraries = $this->createMock(LibraryManager::class);
        $libraries->expects($this->never())->method('getAllLibraries');

        $scheduler = new FolderWatchScheduler($watcher, $libraries, null, true, 300);

        // Return value is not asserted: whether a Workerman timer can actually
        // be armed depends on an event loop this unit test does not run. What
        // matters is that no work happened and nothing threw.
        $scheduler->start();
        $this->assertSame(0, $scheduler->registeredCount());
    }

    // -------------------------------------------------------------------
    // The cost bound: one library per tick.
    //
    // Every watch() in this section SUCCEEDS, so the deliberate
    // failed-registration exception to the bound cannot fire in either test
    // here. It is covered by
    // testAnUnregisterableLibraryDoesNotStarveTheHealthyOnes() below.
    // -------------------------------------------------------------------

    /**
     * THE load-bearing assertion. Three libraries must take three ticks to
     * register — never one tick that walks all three.
     */
    public function testRegistersExactlyOneLibraryPerTick(): void
    {
        $libraries = $this->libraryManagerReturning([
            $this->library('lib-a', ['/media/a']),
            $this->library('lib-b', ['/media/b']),
            $this->library('lib-c', ['/media/c']),
        ]);

        $watched = [];
        $watcher = $this->createMock(FolderWatcher::class);
        $watcher->method('watch')->willReturnCallback(
            static function (string $id, array $paths) use (&$watched): void {
                $watched[] = $id;
            }
        );
        // Every watch() above SUCCEEDS, so in THIS test nothing may be CHECKED
        // while registrations are still outstanding — that would be two
        // libraries' worth of walking in one tick. The bound has one deliberate
        // exception, and it cannot fire here: a tick whose registration walk
        // THREW does also re-check one library, so that a library which can
        // never be registered cannot starve the re-check phase for the others.
        // That is pinned by
        // testAnUnregisterableLibraryDoesNotStarveTheHealthyOnes().
        $watcher->expects($this->never())->method('checkLibrary');

        $scheduler = new FolderWatchScheduler($watcher, $libraries, null, true, 300);

        $scheduler->tick();
        $this->assertSame(['lib-a'], $watched);
        $this->assertSame(1, $scheduler->registeredCount());
        $this->assertSame(2, $scheduler->pendingRegistrationCount());

        $scheduler->tick();
        $this->assertSame(['lib-a', 'lib-b'], $watched);

        $scheduler->tick();
        $this->assertSame(['lib-a', 'lib-b', 'lib-c'], $watched);
        $this->assertSame(3, $scheduler->registeredCount());
        $this->assertSame(0, $scheduler->pendingRegistrationCount());
    }

    /**
     * Once everything is registered, a tick checks exactly ONE library, and
     * successive ticks round-robin through them.
     */
    public function testChecksOneLibraryPerTickInRoundRobinOrder(): void
    {
        $libraries = $this->libraryManagerReturning([
            $this->library('lib-a', ['/media/a']),
            $this->library('lib-b', ['/media/b']),
        ]);

        $checked = [];
        $watcher = $this->createMock(FolderWatcher::class);
        $watcher->method('checkLibrary')->willReturnCallback(
            static function (string $id) use (&$checked): array {
                $checked[] = $id;
                return [];
            }
        );

        $scheduler = new FolderWatchScheduler($watcher, $libraries, null, true, 300);

        $scheduler->tick(); // registers lib-a
        $scheduler->tick(); // registers lib-b
        $this->assertSame([], $checked, 'registration ticks must not also check');

        $scheduler->tick();
        $scheduler->tick();
        $scheduler->tick();

        $this->assertSame(['lib-a', 'lib-b', 'lib-a'], $checked);
    }

    // -------------------------------------------------------------------
    // Reconciling with the libraries table
    // -------------------------------------------------------------------

    /**
     * The second half of the original defect: the watch list must be built from
     * the DB inside this worker. `LibraryManager::createLibrary()` is the only
     * other `watch()` caller and it runs in an HTTP worker, so without this the
     * managed worker would watch nothing at all.
     */
    public function testRegistersTheLibrarysConfiguredPaths(): void
    {
        $libraries = $this->libraryManagerReturning([
            $this->library('lib-a', ['/media/movies', '/media/more-movies']),
        ]);

        $watcher = $this->createMock(FolderWatcher::class);
        $watcher->expects($this->once())
            ->method('watch')
            ->with('lib-a', ['/media/movies', '/media/more-movies']);

        (new FolderWatchScheduler($watcher, $libraries, null, true, 300))->tick();
    }

    public function testDeletedLibraryIsUnwatched(): void
    {
        $libraries = $this->createMock(LibraryManager::class);
        $libraries->method('getAllLibraries')->willReturnOnConsecutiveCalls(
            [$this->library('lib-a', ['/media/a'])],
            [$this->library('lib-a', ['/media/a'])],
            []
        );

        $watcher = $this->createMock(FolderWatcher::class);
        $watcher->expects($this->once())->method('unwatch')->with('lib-a');

        $scheduler = new FolderWatchScheduler($watcher, $libraries, null, true, 300);
        $scheduler->tick(); // register
        $scheduler->tick(); // check
        $scheduler->tick(); // library is gone

        $this->assertSame(0, $scheduler->registeredCount());
        $this->assertSame(0, $scheduler->pendingRegistrationCount());
    }

    /**
     * The watcher is keyed by PATH, so an admin editing a library's paths would
     * otherwise leave it watching the old ones forever.
     */
    public function testEditedPathsAreReWatched(): void
    {
        $libraries = $this->createMock(LibraryManager::class);
        $libraries->method('getAllLibraries')->willReturnOnConsecutiveCalls(
            [$this->library('lib-a', ['/media/old'])],
            [$this->library('lib-a', ['/media/new'])],
            [$this->library('lib-a', ['/media/new'])]
        );

        $watchedWith = [];
        $watcher = $this->createMock(FolderWatcher::class);
        $watcher->method('watch')->willReturnCallback(
            static function (string $id, array $paths) use (&$watchedWith): void {
                $watchedWith[] = $paths;
            }
        );
        $watcher->expects($this->once())->method('unwatch')->with('lib-a');

        $scheduler = new FolderWatchScheduler($watcher, $libraries, null, true, 300);
        $scheduler->tick(); // registers /media/old
        // The edit is noticed, the stale registration dropped and the library
        // re-queued in the same syncLibraries() pass, so with nothing else in
        // the queue ahead of it the new paths are registered on this same tick.
        $scheduler->tick();
        $scheduler->tick(); // steady state: re-checks /media/new

        $this->assertSame([['/media/old'], ['/media/new']], $watchedWith);
    }

    public function testLibraryWithNoPathsIsSkipped(): void
    {
        $libraries = $this->libraryManagerReturning([
            $this->library('lib-empty', []),
            $this->library('lib-a', ['/media/a']),
        ]);

        $watcher = $this->createMock(FolderWatcher::class);
        $watcher->expects($this->once())->method('watch')->with('lib-a', ['/media/a']);

        $scheduler = new FolderWatchScheduler($watcher, $libraries, null, true, 300);
        $scheduler->tick();

        $this->assertSame(1, $scheduler->registeredCount());
        $this->assertSame(0, $scheduler->pendingRegistrationCount());
    }

    // -------------------------------------------------------------------
    // Never kill the worker
    // -------------------------------------------------------------------

    /**
     * `RecursiveDirectoryIterator` throws on an unreadable directory, which a
     * dropped network mount produces. A throw out of a Workerman timer callback
     * would take down the library-scan worker.
     *
     * This ONE-library shape is deliberately kept, but it is not the guard that
     * matters — see
     * {@see testAnUnregisterableLibraryDoesNotStarveTheHealthyOnes()}, which is
     * the shape a single library hides.
     */
    public function testAThrowingWatchDoesNotEscapeTheTick(): void
    {
        $libraries = $this->libraryManagerReturning([
            $this->library('lib-a', ['/media/a']),
        ]);

        $attempts = 0;
        $watcher = $this->createMock(FolderWatcher::class);
        $watcher->method('watch')->willReturnCallback(
            static function () use (&$attempts): void {
                $attempts++;
                throw new RuntimeException('mount gone');
            }
        );

        $scheduler = new FolderWatchScheduler($watcher, $libraries, null, true, 300);
        $scheduler->tick();

        $this->assertSame(1, $attempts);
        $this->assertSame(0, $scheduler->registeredCount(), 'a failed walk must not count as registered');

        // Retried on the next tick — a transient mount blip must recover fast.
        $scheduler->tick();
        $this->assertSame(2, $attempts);
        $this->assertSame(0, $scheduler->registeredCount());

        // ...and then progressively less often: an unreadable directory throws
        // every single time, so retrying at full rate would burn a walk per
        // tick forever. The cooldowns after failures 1, 2 and 3 are 1, 2 and 4
        // ticks, so the attempts land on ticks 1, 2, 4 and 8.
        $scheduler->tick(); // 3 — cooling down
        $this->assertSame(2, $attempts);
        $scheduler->tick(); // 4 — third attempt
        $this->assertSame(3, $attempts);
        $scheduler->tick(); // 5 — cooling down
        $scheduler->tick(); // 6 — cooling down
        $scheduler->tick(); // 7 — cooling down
        $this->assertSame(3, $attempts);
        $scheduler->tick(); // 8 — fourth attempt
        $this->assertSame(4, $attempts);
        $this->assertSame(0, $scheduler->registeredCount());
    }

    /**
     * THE regression guard for the starvation defect.
     *
     * `registerLibrary()` used to swallow the throw, leave the library
     * unregistered and let `tick()` return unconditionally — while
     * `syncLibraries()` re-queued that same library on the very next tick. The
     * three combined into a livelock: `pendingRegistration` was non-empty on
     * every tick forever, so `checkNextLibrary()` was never reached again and
     * ONE library with an unreadable subdirectory silently disabled folder
     * watching for the whole install.
     *
     * Measured against the real classes (real FolderWatcher, real temp dirs, a
     * root-owned subdirectory, only `getAllLibraries()` stubbed), 3 libraries,
     * 33 ticks: `LibraryUpdated` events 0 before, 2 after — matching the
     * control run with the broken library removed.
     *
     * Exact counts are asserted rather than "> 0" so that both halves of the
     * fix are pinned: the healthy libraries must keep their round-robin turn
     * (no starvation), AND the broken one must not be retried at full rate.
     */
    public function testAnUnregisterableLibraryDoesNotStarveTheHealthyOnes(): void
    {
        // The poisoned library is FIRST in DB order, i.e. the head of the
        // registration queue — the position that starved everything else.
        $libraries = $this->libraryManagerReturning([
            $this->library('lib-poison', ['/media/unreadable']),
            $this->library('lib-b', ['/media/b']),
            $this->library('lib-c', ['/media/c']),
        ]);

        $attempts = [];
        $checked = [];
        $watcher = $this->createMock(FolderWatcher::class);
        $watcher->method('watch')->willReturnCallback(
            static function (string $id) use (&$attempts): void {
                $attempts[] = $id;
                if ($id === 'lib-poison') {
                    // Exactly what RecursiveDirectoryIterator does for a
                    // present-but-unreadable directory: it throws EVERY time.
                    throw new RuntimeException('Failed to open directory: Permission denied');
                }
            }
        );
        $watcher->method('checkLibrary')->willReturnCallback(
            static function (string $id) use (&$checked): array {
                $checked[] = $id;
                return [];
            }
        );

        $scheduler = new FolderWatchScheduler($watcher, $libraries, null, true, 300);

        for ($tick = 0; $tick < 12; $tick++) {
            $scheduler->tick();
        }

        // The healthy libraries are registered and keep taking turns.
        $this->assertSame(2, $scheduler->registeredCount());
        $this->assertSame(
            ['lib-b', 'lib-c', 'lib-b', 'lib-c', 'lib-b', 'lib-c', 'lib-b', 'lib-c', 'lib-b'],
            $checked,
            'a library that cannot be registered must not stop the others being re-checked'
        );

        // The poison really is poisonous: never registered, still retried, but
        // 4 attempts in 12 ticks (ticks 1, 4, 6, 10) rather than one per tick.
        $this->assertSame(
            ['lib-poison', 'lib-poison', 'lib-poison', 'lib-poison'],
            array_values(array_filter($attempts, static fn(string $id): bool => $id === 'lib-poison')),
            'a permanently unreadable path must back off, not retry at full rate'
        );
        $this->assertSame(['lib-poison', 'lib-b', 'lib-c'], array_slice($attempts, 0, 3));
    }

    /**
     * CONTROL for the test above — proof the harness is not vacuously green.
     *
     * Same libraries, same tick count, same recorders, poison removed. It
     * records 10 checks where the poisoned run records 9, so the recorder
     * demonstrably observes checks; a run that reported ZERO checks (which is
     * exactly what the poisoned run did before the fix) would be a real
     * difference, not an artefact of the harness.
     */
    public function testControlTheSameHarnessWithoutThePoisonedLibrary(): void
    {
        $libraries = $this->libraryManagerReturning([
            $this->library('lib-b', ['/media/b']),
            $this->library('lib-c', ['/media/c']),
        ]);

        $checked = [];
        $watcher = $this->createMock(FolderWatcher::class);
        $watcher->method('checkLibrary')->willReturnCallback(
            static function (string $id) use (&$checked): array {
                $checked[] = $id;
                return [];
            }
        );

        $scheduler = new FolderWatchScheduler($watcher, $libraries, null, true, 300);

        for ($tick = 0; $tick < 12; $tick++) {
            $scheduler->tick();
        }

        $this->assertSame(2, $scheduler->registeredCount());
        $this->assertCount(10, $checked, 'two registration ticks, then one check per tick');
        $this->assertSame(
            ['lib-b', 'lib-c', 'lib-b', 'lib-c', 'lib-b', 'lib-c', 'lib-b', 'lib-c', 'lib-b', 'lib-c'],
            $checked
        );
    }

    /**
     * The backoff must not turn a fixable problem into a permanent one: a
     * library is never dropped, so repairing the permissions (or bringing the
     * mount back) brings it under watch on the next scheduled attempt, with no
     * worker restart.
     */
    public function testALibraryRecoversOnceItsPathBecomesReadable(): void
    {
        $libraries = $this->libraryManagerReturning([
            $this->library('lib-a', ['/media/a']),
        ]);

        $attempts = 0;
        $checked = [];
        $watcher = $this->createMock(FolderWatcher::class);
        $watcher->method('watch')->willReturnCallback(
            static function () use (&$attempts): void {
                $attempts++;
                if ($attempts <= 2) {
                    throw new RuntimeException('Failed to open directory: Permission denied');
                }
            }
        );
        $watcher->method('checkLibrary')->willReturnCallback(
            static function (string $id) use (&$checked): array {
                $checked[] = $id;
                return [];
            }
        );

        $scheduler = new FolderWatchScheduler($watcher, $libraries, null, true, 300);

        $scheduler->tick(); // attempt 1 — fails, cooldown 1
        $scheduler->tick(); // attempt 2 — fails, cooldown 2
        $scheduler->tick(); // cooling down
        $this->assertSame(0, $scheduler->registeredCount());

        $scheduler->tick(); // attempt 3 — the permissions are fixed by now
        $this->assertSame(3, $attempts);
        $this->assertSame(1, $scheduler->registeredCount());

        $scheduler->tick();
        $this->assertSame(['lib-a'], $checked, 'a recovered library joins the round-robin');
    }

    /**
     * Editing the paths of a failing library must clear the backoff: the
     * recorded failure was about paths that are no longer configured, so making
     * the admin wait out a cooldown they cannot see would look like the fix had
     * not worked.
     */
    public function testEditingThePathsOfAFailingLibraryRetriesImmediately(): void
    {
        $libraries = $this->createMock(LibraryManager::class);
        $libraries->method('getAllLibraries')->willReturnOnConsecutiveCalls(
            [$this->library('lib-a', ['/media/unreadable'])],
            [$this->library('lib-a', ['/media/unreadable'])],
            [$this->library('lib-a', ['/media/fixed'])]
        );

        $attempts = [];
        $watcher = $this->createMock(FolderWatcher::class);
        $watcher->method('watch')->willReturnCallback(
            static function (string $id, array $paths) use (&$attempts): void {
                $attempts[] = $paths;
                if ($paths === ['/media/unreadable']) {
                    throw new RuntimeException('Failed to open directory: Permission denied');
                }
            }
        );

        $scheduler = new FolderWatchScheduler($watcher, $libraries, null, true, 300);
        $scheduler->tick(); // fails, cooldown 1
        $scheduler->tick(); // fails again, cooldown 2 — normally skips tick 3
        $scheduler->tick(); // paths edited: attempted anyway, and succeeds

        $this->assertSame(
            [['/media/unreadable'], ['/media/unreadable'], ['/media/fixed']],
            $attempts
        );
        $this->assertSame(1, $scheduler->registeredCount());
    }

    /**
     * Dropping a library that sits BEFORE the round-robin cursor shifts every
     * later entry down one slot; without compensating, the cursor points one
     * library too far and one library loses its turn for a round.
     */
    public function testForgettingALibraryBeforeTheCursorDoesNotSkipTheNextOne(): void
    {
        $calls = 0;
        $libraries = $this->createMock(LibraryManager::class);
        $libraries->method('getAllLibraries')->willReturnCallback(
            function () use (&$calls): array {
                $calls++;
                // lib-a is deleted from the DB after the 5th tick.
                return $calls <= 5
                    ? [
                        $this->library('lib-a', ['/media/a']),
                        $this->library('lib-b', ['/media/b']),
                        $this->library('lib-c', ['/media/c']),
                    ]
                    : [
                        $this->library('lib-b', ['/media/b']),
                        $this->library('lib-c', ['/media/c']),
                    ];
            }
        );

        $checked = [];
        $watcher = $this->createMock(FolderWatcher::class);
        $watcher->method('checkLibrary')->willReturnCallback(
            static function (string $id) use (&$checked): array {
                $checked[] = $id;
                return [];
            }
        );

        $scheduler = new FolderWatchScheduler($watcher, $libraries, null, true, 300);
        $scheduler->tick(); // register lib-a
        $scheduler->tick(); // register lib-b
        $scheduler->tick(); // register lib-c
        $scheduler->tick(); // check lib-a  (cursor 0 -> 1)
        $scheduler->tick(); // check lib-b  (cursor 1 -> 2)
        $scheduler->tick(); // lib-a is gone; the cursor must still land on lib-c

        $this->assertSame(['lib-a', 'lib-b', 'lib-c'], $checked);
        $this->assertSame(2, $scheduler->registeredCount());
    }

    public function testAThrowingCheckDoesNotEscapeTheTick(): void
    {
        $libraries = $this->libraryManagerReturning([
            $this->library('lib-a', ['/media/a']),
        ]);

        $watcher = $this->createMock(FolderWatcher::class);
        $watcher->method('checkLibrary')->willThrowException(new RuntimeException('mount gone'));

        $scheduler = new FolderWatchScheduler($watcher, $libraries, null, true, 300);
        $scheduler->tick(); // register
        $scheduler->tick(); // check throws internally

        $this->assertSame(1, $scheduler->registeredCount());
    }

    /**
     * An unreachable DB must not stop the libraries already registered in this
     * worker from being re-checked.
     */
    public function testAThrowingLibraryListDoesNotStopChecking(): void
    {
        $libraries = $this->createMock(LibraryManager::class);
        $libraries->method('getAllLibraries')->willReturnOnConsecutiveCalls(
            [$this->library('lib-a', ['/media/a'])],
            $this->throwException(new RuntimeException('db down'))
        );

        $watcher = $this->createMock(FolderWatcher::class);
        $watcher->expects($this->once())->method('checkLibrary')->with('lib-a')->willReturn([]);

        $scheduler = new FolderWatchScheduler($watcher, $libraries, null, true, 300);
        $scheduler->tick(); // register lib-a
        $scheduler->tick(); // getAllLibraries() throws; lib-a is still checked

        $this->assertSame(1, $scheduler->registeredCount());
    }

    // -------------------------------------------------------------------
    // Wiring
    // -------------------------------------------------------------------

    /**
     * `enabled` and `intervalSeconds` are optional constructor parameters, and
     * PHP-DI's `autowire()` SKIPS optional parameters — a container binding that
     * does not name them leaves the feature pinned to its defaults. This test
     * exists so that the day someone adds another optional parameter, the
     * matching `->constructorParameter()` line is not forgotten.
     *
     * @return void
     */
    public function testOptionalConstructorParametersAreKnown(): void
    {
        $ctor = (new ReflectionClass(FolderWatchScheduler::class))->getConstructor();
        $this->assertNotNull($ctor);

        $optional = [];
        foreach ($ctor->getParameters() as $param) {
            if ($param->isDefaultValueAvailable()) {
                $type = $param->getType();
                $optional[$param->getName()] = $type instanceof ReflectionNamedType
                    ? $type->getName()
                    : null;
            }
        }

        $this->assertSame(
            ['logger' => 'Phlix\Common\Logger\StructuredLogger', 'enabled' => 'bool', 'intervalSeconds' => 'int'],
            $optional,
            'Every optional parameter here must be bound explicitly in '
            . 'MediaServicesProvider — PHP-DI skips optional params, so an '
            . 'unbound one silently falls back to its default.'
        );
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    /**
     * @param list<array<string, mixed>> $rows
     * @return LibraryManager&\PHPUnit\Framework\MockObject\MockObject
     */
    private function libraryManagerReturning(array $rows): LibraryManager
    {
        $libraries = $this->createMock(LibraryManager::class);
        $libraries->method('getAllLibraries')->willReturn($rows);

        return $libraries;
    }

    /**
     * A row in the shape {@see LibraryManager::getAllLibraries()} returns.
     *
     * @param list<string> $paths
     * @return array<string, mixed>
     */
    private function library(string $id, array $paths): array
    {
        return ['id' => $id, 'name' => $id, 'type' => 'video', 'paths' => $paths];
    }
}
