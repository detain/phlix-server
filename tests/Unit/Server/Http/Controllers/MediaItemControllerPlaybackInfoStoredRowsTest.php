<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\StreamTrackShaper;
use Phlix\Media\Markers\Detection\MarkerCandidateRepository;
use Phlix\Media\Markers\MarkerService;
use Phlix\Media\Playback\GaplessPlaybackManager;
use Phlix\Media\Playback\PlaybackPreferences;
use Phlix\Media\Streaming\Trickplay\TrickplayController;
use Phlix\Media\MarkerService as ChapterMarkerService;
use Phlix\Server\Http\Controllers\MediaItemController;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Item C on the API path: `MediaItemController::getPlaybackInfo()` shapes the STORED
 * `media_streams` rows and never probes or writes in the request.
 *
 * The controller holds no probe collaborator any more, so "never probes" is proven
 * by what it can observe: a legacy item (one audio row, no subtitles, marker NULL)
 * backed by a REAL media file must produce no write statement at all, and its
 * audio/subtitle tracks must equal the shape of the stored rows.
 */
final class MediaItemControllerPlaybackInfoStoredRowsTest extends TestCase
{
    /** @var list<string> Every SQL statement the controller issued. */
    private array $sql = [];

    /** @var list<string> Temp files to unlink in tearDown. */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->tmpFiles = [];
    }

    /** @return list<array<string, mixed>> A legacy shape: one video row, ONE audio row, no subtitles. */
    private function legacyStoredRows(): array
    {
        return [
            ['id' => 's-v', 'stream_index' => 0, 'stream_type' => 'video', 'codec' => 'h264',
                'language' => null, 'bitrate' => null],
            ['id' => 's-a', 'stream_index' => 1, 'stream_type' => 'audio', 'codec' => 'aac',
                'language' => 'eng', 'bitrate' => 128000],
        ];
    }

    /** @return array<string, mixed> */
    private function itemRow(string $path): array
    {
        return [
            'id' => 'ep-1',
            'name' => 'Episode 1',
            'type' => 'episode',
            'library_id' => 'lib-1',
            'parent_id' => 'show-1',
            'path' => $path,
            'metadata_json' => json_encode([]),
            'streams_probed_at' => null,
            'intro_start_seconds' => null,
            'intro_end_seconds' => null,
            'outro_start_seconds' => null,
            'outro_end_seconds' => null,
            'chapters_json' => null,
        ];
    }

    public function testPlaybackInfoServesTheStoredRowsAndNeverWritesOrProbes(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'phlix-pinfo-');
        $this->tmpFiles[] = $path;
        $stored = $this->legacyStoredRows();
        $item = $this->itemRow($path);

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            function (string $sql) use ($stored, $item): array {
                $this->sql[] = $sql;
                return str_contains($sql, 'media_streams') ? $stored : [$item];
            }
        );

        $itemRepo = new ItemRepository($db);
        $gapless = $this->createMock(GaplessPlaybackManager::class);
        $gapless->method('getPreferences')->willReturn(PlaybackPreferences::fromRaw(0, 0.3, 0.3));
        $controller = new MediaItemController(
            $itemRepo,
            new MarkerService($itemRepo, new MarkerCandidateRepository($itemRepo)),
            $gapless,
            new TrickplayController('/tmp/trickplay', ''),
            new ChapterMarkerService($this->createMock(Connection::class)),
        );

        $body = json_decode($controller->getPlaybackInfo(new Request(), ['id' => 'ep-1'])->body, true);

        // The shaped output is exactly the shape of the stored rows: one audio track, no subtitles.
        $this->assertSame(
            json_decode((string) json_encode(StreamTrackShaper::audioTracks($stored)), true),
            $body['audio_tracks'],
        );
        $this->assertSame([], $body['subtitle_tracks']);

        // No write statement reached the database: no stream replacement and no
        // probed-marker stamp, even though the file exists and the marker is NULL.
        foreach ($this->sql as $statement) {
            $this->assertDoesNotMatchRegularExpression(
                '/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i',
                $statement,
                'playback info must not write: ' . $statement,
            );
        }
    }
}
