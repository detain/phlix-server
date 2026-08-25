<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media;

use Phlix\AirPlay\AirPlayManager;
use Phlix\AirPlay\AirPlaySession;
use Phlix\Auth\AuthManager;
use Phlix\Chromecast\CastManager;
use Phlix\Chromecast\CastSession;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Dlna\AvTransport;
use Phlix\Dlna\ContentDirectory;
use Phlix\Dlna\DeviceRegistry;
use Phlix\Dlna\DlnaServer;
use Phlix\Dlna\PlayToManager;
use Phlix\Dlna\PlayToSession;
use Phlix\Dlna\RendererControlClient;
use Phlix\Dlna\RendererDiscovery;
use Phlix\Media\Library\AudiobookScanner;
use Phlix\Media\Library\BookScanner;
use Phlix\Media\Library\FolderWatcher;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Library\MediaScanner;
use Phlix\Media\Library\PhotoLibraryManager;
use Phlix\Roku\RokuManager;
use Phlix\Roku\RokuSession;
use Phlix\Session\PlaybackController;
use Phlix\Session\SessionManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * S167 — prefix-agnostic guard against the per-construction `/tmp/phlix_*` temp-dir mint.
 *
 * ## What this guards
 *
 * S167 converted the `createDefaultLogger()` of the 23 classes listed in
 * {@see self::MINTING_CLASSES} from minting a fresh
 * `sys_get_temp_dir()/phlix_<prefix>_<uniqid>` directory on every construction
 * to returning the shared `LoggerFactory::get(LogChannels::...)` logger. This
 * test snapshots the ENTIRE `/tmp/phlix_*` entry set (files and directories, of
 * ANY prefix), re-invokes `createDefaultLogger()` on every converted class, and
 * asserts the set is unchanged.
 *
 * It is deliberately prefix-agnostic: it enumerates no retired prefix, so
 * reintroducing the mint shape in any converted class — or adding a brand-new
 * `phlix_*` mint to one of their construction paths — goes RED. It is also
 * scoped: the snapshot is taken fresh at test start, so fixtures created by
 * other tests in the same process are part of the baseline and cannot cause a
 * false positive, and the exercise never runs cover extraction or any other
 * per-operation path (the deliberate per-op bucket — `phlix_cover_`,
 * `phlix_audiobook_cover_`, `phlix_backup_`, `phlix_restore_`, `phlix_plugin_` —
 * is intentionally excluded; those mints fire only when the operation itself
 * runs, are documented in the S167 sweep commit, and are not the per-construction
 * pattern this guard exists to kill).
 *
 * The classes are exercised via {@see ReflectionClass::newInstanceWithoutConstructor()}
 * + {@see ReflectionMethod::invoke()} so no constructor dependencies (DB
 * connections, repositories, …) are needed: both the old minting body and the
 * converted shared-logger body are `$this`-free.
 */
final class TempDirMintGuardTest extends TestCase
{
    /**
     * The 23 classes whose `createDefaultLogger()` minted a per-construction
     * temp dir before S167. Deliberately a complete enumeration of the converted
     * surface — the assertion itself stays prefix-agnostic.
     *
     * @var list<class-string>
     */
    private const MINTING_CLASSES = [
        AirPlayManager::class,
        AirPlaySession::class,
        CastManager::class,
        CastSession::class,
        AuthManager::class,
        SessionManager::class,
        PlaybackController::class,
        ContentDirectory::class,
        DeviceRegistry::class,
        RendererDiscovery::class,
        PlayToManager::class,
        RendererControlClient::class,
        AvTransport::class,
        PlayToSession::class,
        DlnaServer::class,
        MediaScanner::class,
        BookScanner::class,
        LibraryManager::class,
        FolderWatcher::class,
        AudiobookScanner::class,
        PhotoLibraryManager::class,
        RokuSession::class,
        RokuManager::class,
    ];

    /**
     * @test
     */
    public function testConstructingConvertedLoggerSurfaceMintsNoTempDirs(): void
    {
        $before = $this->phlixTempEntries();

        foreach (self::MINTING_CLASSES as $class) {
            $reflection = new ReflectionClass($class);
            $instance = $reflection->newInstanceWithoutConstructor();
            $method = $reflection->getMethod('createDefaultLogger');
            $method->setAccessible(true);
            $method->invoke($instance);
        }

        $after = $this->phlixTempEntries();

        $newEntries = array_values(array_diff($after, $before));
        self::assertSame(
            $before,
            $after,
            'S167 mint guard: constructing the converted logger surface created new /tmp/phlix_* entries. '
            . 'A per-construction createDefaultLogger() mint has been reintroduced. New entries: '
            . ($newEntries === [] ? '(none)' : implode(', ', $newEntries))
        );
    }

    protected function tearDown(): void
    {
        LoggerFactory::reset();
        parent::tearDown();
    }

    /**
     * Snapshot of every `/tmp/phlix_*` entry (files and directories), sorted.
     *
     * @return list<string>
     */
    private function phlixTempEntries(): array
    {
        $entries = glob(sys_get_temp_dir() . '/phlix_*') ?: [];
        sort($entries);

        return $entries;
    }
}
