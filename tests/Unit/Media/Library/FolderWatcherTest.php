<?php

namespace Phlix\Tests\Unit\Media\Library;

use Crell\Tukio\Dispatcher;
use PHPUnit\Framework\TestCase;
use Phlix\Common\Events\ListenerRegistry;
use Phlix\Media\Library\FolderWatcher;
use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Playlists\LibraryUpdated;

class FolderWatcherTest extends TestCase
{
    /** @var list<string> Temp directories to remove in tearDown. */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        LoggerFactory::init(__DIR__ . '/../../../../config/logger.php');
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeTree($dir);
        }
        $this->tempDirs = [];
    }

    public function testCanCreateFolderWatcher(): void
    {
        $watcher = new FolderWatcher();

        $this->assertInstanceOf(FolderWatcher::class, $watcher);
    }

    public function testWatchedPathsStartsEmpty(): void
    {
        $watcher = new FolderWatcher();

        $this->assertEmpty($watcher->getWatchedPaths());
    }

    public function testCanSetCheckInterval(): void
    {
        $watcher = new FolderWatcher();
        $watcher->setCheckInterval(60);

        // The interval setter persists the configured value.
        $this->assertSame(60, $watcher->getCheckInterval());
    }

    /**
     * Bringing a path under watch must NOT look like a change: `watch()` stores
     * the baseline checksum, so the first check after it reports nothing. This
     * is what lets FolderWatchScheduler register libraries without emitting a
     * spurious LibraryUpdated for every library at worker start.
     */
    public function testWatchEstablishesABaselineSoTheFirstCheckIsQuiet(): void
    {
        $dir = $this->makeTempDir();
        file_put_contents($dir . '/a.mkv', 'a');

        $events = [];
        $watcher = new FolderWatcher(
            LoggerFactory::get(LogChannels::MEDIA),
            $this->recordingDispatcher($events)
        );
        $watcher->watch('lib-a', [$dir]);

        $this->assertSame([], $watcher->checkForChanges());
        $this->assertSame([], $events);
    }

    public function testCheckForChangesDetectsANewFileAndDispatchesLibraryUpdated(): void
    {
        $dir = $this->makeTempDir();
        file_put_contents($dir . '/a.mkv', 'a');

        $events = [];
        $watcher = new FolderWatcher(
            LoggerFactory::get(LogChannels::MEDIA),
            $this->recordingDispatcher($events)
        );
        $watcher->watch('lib-a', [$dir]);

        file_put_contents($dir . '/b.mkv', 'b');

        $changes = $watcher->checkForChanges();

        $this->assertSame(
            [['library_id' => 'lib-a', 'path' => $dir, 'change_detected' => true]],
            $changes
        );
        $this->assertCount(1, $events);
        $this->assertSame('lib-a', $events[0]->libraryId);
        $this->assertSame($dir, $events[0]->path);
    }

    /**
     * The change is consumed: a second check with nothing further changed must
     * be quiet, or the scheduler's timer would re-dispatch on every tick.
     */
    public function testASecondCheckAfterAChangeIsQuiet(): void
    {
        $dir = $this->makeTempDir();
        // Pass the shared logger: FolderWatcher's no-logger fallback mkdir()s a
        // sys_get_temp_dir()/phlix_media_* directory per instance and never
        // removes it.
        $watcher = new FolderWatcher(LoggerFactory::get(LogChannels::MEDIA));
        $watcher->watch('lib-a', [$dir]);

        file_put_contents($dir . '/b.mkv', 'b');
        $this->assertCount(1, $watcher->checkForChanges());
        $this->assertSame([], $watcher->checkForChanges());
    }

    /**
     * The whole point of checkLibrary(): only the named library's paths are
     * walked, so the scheduler can spread many libraries across ticks instead
     * of walking every watched directory in one blocking call.
     */
    public function testCheckLibraryTouchesOnlyThatLibrarysPaths(): void
    {
        $dirA = $this->makeTempDir();
        $dirB = $this->makeTempDir();

        $events = [];
        $watcher = new FolderWatcher(
            LoggerFactory::get(LogChannels::MEDIA),
            $this->recordingDispatcher($events)
        );
        $watcher->watch('lib-a', [$dirA]);
        $watcher->watch('lib-b', [$dirB]);

        file_put_contents($dirA . '/a.mkv', 'a');
        file_put_contents($dirB . '/b.mkv', 'b');

        $changes = $watcher->checkLibrary('lib-a');

        $this->assertSame(
            [['library_id' => 'lib-a', 'path' => $dirA, 'change_detected' => true]],
            $changes
        );
        $this->assertCount(1, $events, 'lib-b must not have been walked or dispatched for');
        $this->assertSame('lib-a', $events[0]->libraryId);

        // lib-b's change is still pending — it was not consumed by the lib-a check.
        $this->assertCount(1, $watcher->checkLibrary('lib-b'));
    }

    public function testCheckLibraryOfAnUnwatchedLibraryIsAnEmptyNoOp(): void
    {
        $events = [];
        $watcher = new FolderWatcher(
            LoggerFactory::get(LogChannels::MEDIA),
            $this->recordingDispatcher($events)
        );

        $this->assertSame([], $watcher->checkLibrary('never-watched'));
        $this->assertSame([], $events);
    }

    /**
     * A library with several paths reports one change record per changed PATH.
     * That shape is load-bearing: it mirrors LibraryScanCompleted, which is why
     * SmartPlaylistRefreshSubscriber's existing library-keyed pending set
     * coalesces both without a second coalescing mechanism.
     */
    public function testCheckLibraryReportsOneRecordPerChangedPath(): void
    {
        $dirOne = $this->makeTempDir();
        $dirTwo = $this->makeTempDir();

        $events = [];
        $watcher = new FolderWatcher(
            LoggerFactory::get(LogChannels::MEDIA),
            $this->recordingDispatcher($events)
        );
        $watcher->watch('lib-a', [$dirOne, $dirTwo]);

        file_put_contents($dirOne . '/a.mkv', 'a');
        file_put_contents($dirTwo . '/b.mkv', 'b');

        $this->assertCount(2, $watcher->checkLibrary('lib-a'));
        $this->assertCount(2, $events);
        $this->assertSame(['lib-a', 'lib-a'], array_map(
            static fn(LibraryUpdated $e): string => $e->libraryId,
            $events
        ));
    }

    public function testUnwatchStopsBothCheckEntryPoints(): void
    {
        $dir = $this->makeTempDir();
        // Shared logger — see testASecondCheckAfterAChangeIsQuiet().
        $watcher = new FolderWatcher(LoggerFactory::get(LogChannels::MEDIA));
        $watcher->watch('lib-a', [$dir]);
        $watcher->unwatch('lib-a');

        file_put_contents($dir . '/b.mkv', 'b');

        $this->assertSame([], $watcher->checkLibrary('lib-a'));
        $this->assertSame([], $watcher->checkForChanges());
    }

    /**
     * Build a dispatcher that appends every LibraryUpdated it receives to $sink.
     *
     * @param list<LibraryUpdated> $sink Captured events, by reference.
     */
    private function recordingDispatcher(array &$sink): Dispatcher
    {
        $listeners = new ListenerRegistry();
        $listeners->subscribe(
            LibraryUpdated::class,
            static function (LibraryUpdated $event) use (&$sink): void {
                $sink[] = $event;
            }
        );

        return new Dispatcher($listeners->provider());
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/phlix_folderwatcher_test_' . bin2hex(random_bytes(6));
        mkdir($dir, 0o755, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = scandir($dir);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir . '/' . $entry;
            is_dir($full) ? $this->removeTree($full) : @unlink($full);
        }
        @rmdir($dir);
    }
}
