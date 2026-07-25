<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Music;

use Phlix\Media\Library\ScanResult;
use Phlix\Media\Music\MusicLibraryScanner;
use Phlix\Media\Music\MusicLibraryService;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Unit tests for {@see MusicLibraryService}, focused on the scan/progress
 * forwarding added for the music-scan-hang fix.
 *
 * @covers \Phlix\Media\Music\MusicLibraryService
 */
final class MusicLibraryServiceTest extends TestCase
{
    public function testScanDirectoryForwardsPathAndProgressSinkToScanner(): void
    {
        $sink = static function (int $p, int $t, string $path): void {
            unset($p, $t, $path);
        };
        $expected = new ScanResult();

        $scanner = $this->createMock(MusicLibraryScanner::class);
        $scanner->expects($this->once())
            ->method('scanDirectory')
            ->with('/music/rock', $this->identicalTo($sink))
            ->willReturn($expected);

        $service = new MusicLibraryService($this->createMock(Connection::class), $scanner);

        $this->assertSame($expected, $service->scanDirectory('/music/rock', $sink));
    }

    public function testScanDirectoryForwardsNullSinkByDefault(): void
    {
        $scanner = $this->createMock(MusicLibraryScanner::class);
        $scanner->expects($this->once())
            ->method('scanDirectory')
            ->with('/music/jazz', null)
            ->willReturn(new ScanResult());

        $service = new MusicLibraryService($this->createMock(Connection::class), $scanner);
        $service->scanDirectory('/music/jazz');
    }

    public function testCountFilesDelegatesToScanner(): void
    {
        $scanner = $this->createMock(MusicLibraryScanner::class);
        $scanner->expects($this->once())
            ->method('countAudioFiles')
            ->with('/music/rock')
            ->willReturn(42);

        $service = new MusicLibraryService($this->createMock(Connection::class), $scanner);
        $this->assertSame(42, $service->countFiles('/music/rock'));
    }

    /**
     * S94: `music_albums` has a `title` column and NO `name` column, so the
     * tracks join must read `al.title` — `al.name` made every
     * `/api/v1/music/tracks` call 500 with "Unknown column 'al.name' in 'field
     * list'". A mocked connection cannot reject a bad column name (that is
     * exactly how the defect shipped), so this test inspects the SQL the
     * service emits; the real-DB proof lives in
     * {@see \Phlix\Tests\Integration\Media\MusicTracksQueryIntegrationTest}.
     *
     * Also pins the `AS album_name` output alias, which is API contract:
     * {@see \Phlix\Server\WebPortal\WebPortalRouter::getMusicTracks()} reads
     * `$row['album_name']`, so renaming the alias would silently blank the
     * album on every track card.
     */
    public function testGetAllTracksSelectsAndOrdersByTheAlbumTitleColumn(): void
    {
        /** @var array{sql: string, params: mixed}|null $captured */
        $captured = null;

        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('query')
            ->willReturnCallback(
                /**
                 * @return list<array<string, mixed>>
                 */
                function (mixed $sql = '', mixed $params = null) use (&$captured): array {
                    $captured = [
                        'sql' => is_string($sql) ? $sql : '',
                        'params' => $params,
                    ];
                    return [];
                }
            );

        $service = new MusicLibraryService($db, $this->createMock(MusicLibraryScanner::class));
        $service->getAllTracks(25, 5);

        $this->assertIsArray($captured);
        $sql = $captured['sql'];

        // The album title column, aliased to the contractual output key.
        $this->assertMatchesRegularExpression(
            '/al\.title\s+AS\s+album_name/i',
            $sql,
            'The tracks query must select music_albums.title AS album_name'
        );
        // And the ORDER BY must use it too (the second failing site).
        $this->assertMatchesRegularExpression(
            '/ORDER\s+BY\s+ar\.name,\s*al\.title,\s*t\.disc_number,\s*t\.track_number/i',
            $sql,
            'The tracks query must order by artist name, album title, disc, track'
        );
        // No reference to the non-existent music_albums.name column anywhere.
        $this->assertStringNotContainsString(
            'al.name',
            $sql,
            'music_albums has no `name` column — al.name raises SQLSTATE 42S22'
        );
        // Pagination is still clamped and bound positionally.
        $this->assertSame([25, 5], $captured['params']);
    }
}
