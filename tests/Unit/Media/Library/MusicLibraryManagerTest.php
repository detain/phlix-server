<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Media\Library\AudioScanner;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\MusicLibraryManager;
use Phlix\Media\Library\ScanResult;
use Phlix\Media\Metadata\MetadataManager;
use Phlix\Media\Music\MusicLibraryType;
use Psr\Log\LoggerInterface;
use Workerman\MySQL\Connection;

/**
 * Unit tests for MusicLibraryManager.
 *
 */
class MusicLibraryManagerTest extends TestCase
{
    /** @var Connection&MockObject */
    private Connection $db;
    /** @var AudioScanner&MockObject */
    private AudioScanner $scanner;
    /** @var MetadataManager&MockObject */
    private MetadataManager $metadata;
    private ItemRepository $itemRepo;
    private MusicLibraryManager $manager;

    protected function setUp(): void
    {
        $this->db = $this->createMock(Connection::class);
        $this->scanner = $this->createMock(AudioScanner::class);
        $this->metadata = $this->createMock(MetadataManager::class);
        $this->itemRepo = new ItemRepository($this->db);

        $this->manager = new MusicLibraryManager(
            $this->scanner,
            $this->metadata,
            $this->itemRepo,
            $this->db
        );
    }

    // -- review r2 F2: the SECOND copy of the S96(a) temp-dir defect ------------

    /**
     * Constructing this manager must create NO directory under `sys_get_temp_dir()`.
     *
     * `createDefaultLogger()` used to `mkdir(sys_get_temp_dir() . '/phlix_music_' .
     * uniqid())` on EVERY construction and point a private `StructuredLogger` at
     * `music_manager.log` inside it — the same mechanism S96(a) removed from
     * `MusicLibraryScanner`, in the same subsystem, matching the step's own acceptance
     * criterion glob (`/tmp/phlix_music_*`). Review r2 measured the scanner leaking +0
     * dirs per suite run while THIS class leaked +11, so the criterion was met only for
     * the copy that had been looked at. On production those directories live inside the
     * unit's `PrivateTmp`: unreadable without `nsenter`, destroyed on restart.
     */
    public function testConstructingTheManagerCreatesNoTemporaryLogDirectory(): void
    {
        $pattern = sys_get_temp_dir() . '/phlix_music_*';
        $before = glob($pattern);
        $this->assertIsArray($before);

        // Three, so a per-construction leak cannot hide behind a coincidence.
        for ($i = 0; $i < 3; $i++) {
            new MusicLibraryManager(
                $this->createMock(AudioScanner::class),
                $this->createMock(MetadataManager::class),
                new ItemRepository($this->createMock(Connection::class)),
                $this->createMock(Connection::class)
            );
        }

        $after = glob($pattern);
        $this->assertIsArray($after);
        $this->assertSame(
            count($before),
            count($after),
            'MusicLibraryManager must not mkdir a private log directory. This is the second copy of '
            . 'the S96(a) defect and the reason the step\'s "no new /tmp/phlix_music_* dirs" criterion '
            . 'was objectively unmet'
        );
    }

    /**
     * With no logger supplied, the manager must use the SHARED media-channel logger —
     * the one `config/logger.php` routes to `.logs/app.log` and `.logs/error.log`.
     *
     * Identity, not "is a logger": `LoggerFactory::get()` returns one cached instance per
     * channel, so this also pins the channel. Without it, "stop leaking a directory" and
     * "stop logging at all" would look identical, and a `NullLogger` here would restore
     * the invisibility S96 exists to remove.
     */
    public function testTheDefaultLoggerIsTheSharedMediaChannelLogger(): void
    {
        $manager = new MusicLibraryManager(
            $this->createMock(AudioScanner::class),
            $this->createMock(MetadataManager::class),
            new ItemRepository($this->createMock(Connection::class)),
            $this->createMock(Connection::class)
        );

        $property = new \ReflectionProperty(MusicLibraryManager::class, 'logger');
        $property->setAccessible(true);

        $this->assertSame(
            LoggerFactory::get(LogChannels::MEDIA),
            $property->getValue($manager),
            'a manager built with no logger must log to the shared MEDIA channel, not to a private '
            . 'file in a temp directory nobody can read'
        );
    }

    /**
     * A PLAIN PSR-3 logger — not a `StructuredLogger` — must actually be USED.
     *
     * The second half of the defect: the constructor declared `?StructuredLogger`, so
     * `MusicLibraryType::getLibraryManager()` narrowed with
     * `$logger instanceof StructuredLogger ? $logger : null` and silently discarded
     * anything else, guaranteeing the temp-dir branch. A double that inherited from
     * `StructuredLogger` could not detect this, so the mock is of the INTERFACE.
     */
    public function testAPlainPsrLoggerIsHonouredAndReceivesTheRescanLines(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())
            ->method('info')
            ->with($this->stringContains('music library rescan'));

        $this->db->method('query')->willReturn([
            [
                'id' => 'lib-psr3',
                'name' => 'Test Music',
                'type' => 'music',
                'paths' => json_encode(['/nonexistent-path-for-psr3-test']),
                'options' => null,
                'metadata' => null,
            ],
        ]);

        $manager = new MusicLibraryManager(
            $this->scanner,
            $this->metadata,
            $this->itemRepo,
            $this->db,
            $logger
        );

        $manager->rescanLibrary('lib-psr3');
    }

    /**
     * `MusicLibraryType::getLibraryManager()` must FORWARD a plain PSR-3 logger, not
     * narrow it away — the call site review r2 F2 named.
     */
    public function testTheLibraryTypeForwardsAPlainPsrLoggerToTheManager(): void
    {
        $logger = $this->createMock(LoggerInterface::class);

        $manager = (new MusicLibraryType())->getLibraryManager(
            $this->createMock(Connection::class),
            $this->createMock(AudioScanner::class),
            $this->createMock(MetadataManager::class),
            new ItemRepository($this->createMock(Connection::class)),
            $logger
        );

        $property = new \ReflectionProperty(MusicLibraryManager::class, 'logger');
        $property->setAccessible(true);

        $this->assertSame(
            $logger,
            $property->getValue($manager),
            'the caller\'s logger must arrive intact. `instanceof StructuredLogger ? … : null` here '
            . 'dropped it and forced the private temp-dir logger, which is why no amount of container '
            . 'wiring could have fixed this class'
        );
    }

    /**
     * @test
     */
    public function testRescanLibraryReturnsScanResult(): void
    {
        $libraryId = 'test-lib-123';

        // Mock library lookup
        $this->db->method('query')->willReturn([
            [
                'id' => $libraryId,
                'name' => 'Test Music',
                'type' => 'music',
                'paths' => '["/tmp/music"]',
                'options' => '{}',
            ]
        ]);

        // Mock scanner to yield empty generator
        $emptyGenerator = function (): \Generator {
            yield from [];
        };
        $this->scanner->method('scanMusicLibrary')
            ->willReturn($emptyGenerator());

        $result = $this->manager->rescanLibrary($libraryId);

        $this->assertInstanceOf(ScanResult::class, $result);
        $this->assertIsInt($result->scanned);
        $this->assertIsInt($result->added);
        $this->assertIsInt($result->updated);
        // Review r3 MED-2: `assertIsInt($result->durationMs)` used to stand here and it
        // COULD NOT FAIL — `ScanResult::$durationMs` is declared `public int`
        // (`ScanResult.php:64`) and the assignment is an `(int)` cast, so the assertion held
        // for -88274223122786272 too. Mutating `MusicLibraryManager.php:187`'s end timestamp
        // back to `microtime(true)` (the realistic regression: edit one of the two timing
        // lines and not the other, mixing seconds into a nanosecond origin) left the FULL
        // suite with zero durationMs failures. Both bounds are needed: a one-sided
        // `assertLessThan()` passes for a negative number, which is precisely the bug.
        $this->assertGreaterThanOrEqual(
            0,
            $result->durationMs,
            'durationMs must be a sane elapsed millisecond count — a negative value means the start '
            . 'and end timestamps came from different clocks/units, and `library:scan` prints this '
            . 'number to an operator'
        );
        $this->assertLessThan(
            600000,
            $result->durationMs,
            'a rescan over an EMPTY generator cannot take ten minutes; a huge value here is the same '
            . 'mixed-unit bug with the operands the other way round'
        );
    }

    /**
     * @test
     */
    public function testRescanLibraryCallsScanner(): void
    {
        $libraryId = 'test-lib-456';

        $this->db->method('query')->willReturn([
            [
                'id' => $libraryId,
                'name' => 'Test Music',
                'type' => 'music',
                'paths' => '["/tmp/music"]',
                'options' => '{}',
            ]
        ]);

        // Create a generator that yields nothing
        $emptyGenerator = function (): \Generator {
            yield from [];
        };

        // Scanner should be called with music library
        $this->scanner->method('scanMusicLibrary')
            ->willReturn($emptyGenerator());

        // The method should not throw
        $result = $this->manager->rescanLibrary($libraryId);

        $this->assertInstanceOf(ScanResult::class, $result);
    }

    /**
     * @test
     */
    public function testRescanLibraryThrowsForNonexistentLibrary(): void
    {
        $this->db->method('query')->willReturn([]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Library not found');

        $this->manager->rescanLibrary('non-existent-id');
    }

    /**
     * @test
     */
    public function testUpsertTrackStoresTags(): void
    {
        $libraryId = 'test-lib-789';
        $tempFile = tempnam(sys_get_temp_dir(), 'phlix_test_') . '.mp3';

        // Write minimal MP3 data
        file_put_contents($tempFile, $this->createMinimalMp3());

        // Mock item repository
        $this->db->method('query')
            ->willReturnCallback(function ($sql, $params) {
                if (strpos($sql, 'SELECT') === 0) {
                    return []; // No existing item
                }
                return [];
            });

        $item = $this->manager->upsertTrack($libraryId, $tempFile);

        // Clean up
        unlink($tempFile);

        $this->assertNull($item); // No tags in minimal file, returns null
    }

    /**
     * @test
     */
    public function testUpsertTrackReturnsNullForNonexistentFile(): void
    {
        $result = $this->manager->upsertTrack('lib-123', '/non/existent/file.mp3');

        $this->assertNull($result);
    }

    /**
     * @test
     */
    public function testUpsertTrackEnrichesViaMetadataManager(): void
    {
        $libraryId = 'test-lib-enrich';
        $tempFile = tempnam(sys_get_temp_dir(), 'phlix_test_') . '.mp3';

        // Write minimal MP3 data
        file_put_contents($tempFile, $this->createMinimalMp3());

        // Mock the scanner to return tags (simulating a file with valid tags)
        $this->scanner->method('harvestTags')->willReturn([
            'title' => 'Test Track',
            'artist' => 'Test Artist',
            'album' => 'Test Album',
        ]);

        // Mock DB - return existing item on findByPath
        $this->db->method('query')
            ->willReturnCallback(function ($sql, $params) use ($tempFile) {
                if (strpos($sql, 'SELECT') === 0 && strpos($sql, 'path') !== false) {
                    return [[
                        'id' => 'existing-item-id',
                        'name' => 'Test Track',
                        'type' => 'track',
                        'path' => $tempFile,
                        'metadata_json' => '{}',
                    ]];
                }
                return [];
            });

        // Expect metadata refresh to be called
        $this->metadata->expects($this->atLeastOnce())
            ->method('refreshItemMetadata');

        $this->manager->upsertTrack($libraryId, $tempFile);

        // Clean up
        unlink($tempFile);
    }

    /**
     * @test
     */
    public function testGetLibraryReturnsDecodedPathsAndOptions(): void
    {
        $libraryId = 'test-lib-decode';

        $this->db->method('query')->willReturn([
            [
                'id' => $libraryId,
                'name' => 'Test Library',
                'type' => 'music',
                'paths' => '["/path/one", "/path/two"]',
                'options' => '{"scan_interval": 3600}',
            ]
        ]);

        $library = $this->manager->getLibrary($libraryId);

        $this->assertIsArray($library);
        $this->assertEquals(['/path/one', '/path/two'], $library['paths']);
        $this->assertEquals(['scan_interval' => 3600], $library['options']);
    }

    /**
     * @test
     */
    public function testGetLibraryReturnsNullWhenNotFound(): void
    {
        $this->db->method('query')->willReturn([]);

        $result = $this->manager->getLibrary('non-existent');

        $this->assertNull($result);
    }

    /**
     * Creates minimal MP3 data for testing.
     */
    private function createMinimalMp3(): string
    {
        $id3 = 'ID3' . chr(0x04) . chr(0x00) . chr(0x00) . chr(0) . chr(0) . chr(0) . chr(0);
        return $id3 . str_repeat("\x00", 128) . chr(0xFF) . chr(0xFB);
    }
}
