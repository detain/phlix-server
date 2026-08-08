<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use Phlix\Media\Library\ItemRepository;
use Phlix\Media\MarkerService as ChapterMarkerService;
use Phlix\Media\Markers\Detection\MarkerCandidateRepository;
use Phlix\Media\Markers\MarkerService;
use Phlix\Media\Playback\GaplessPlaybackManager;
use Phlix\Media\Playback\PlaybackPreferences;
use Phlix\Media\Streaming\Trickplay\BifWriter;
use Phlix\Media\Streaming\Trickplay\TrickplayController;
use Phlix\Server\Http\Controllers\MediaItemController;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * S275 — `GET /api/v1/media/{id}/trickplay` and the existence-gated BIF URL.
 *
 * ⚠ Both branches run here, in the same file, over the SAME item id and the same
 * controller wiring, with only the presence of `thumbs.bif` on disk differing.
 * A single "the field is there" test cannot tell you which branch produced it
 * ([[feedback_verify_which_branch_actually_fired]]); a single "the field is
 * absent" test cannot tell an existence check from a feature that was never
 * wired at all. The absent case therefore asserts a **succeeding sibling** —
 * `sprite_url` is populated in exactly the same response — so an empty payload
 * caused by some unrelated failure cannot read as a pass.
 */
final class MediaItemTrickplayBifTest extends TestCase
{
    private string $tempDir = '';

    private const ITEM_ID = 'item-bif-1';

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/s275_bif_' . uniqid();
        mkdir($this->tempDir . '/trickplay/' . self::ITEM_ID, 0755, true);
    }

    protected function tearDown(): void
    {
        if ($this->tempDir === '' || !is_dir($this->tempDir)) {
            return;
        }
        $this->removeDirectory($this->tempDir);
    }

    private function removeDirectory(string $dir): void
    {
        $entries = array_diff((array) scandir($dir), ['.', '..']);
        foreach ($entries as $entry) {
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Builds the controller over a DB row that HAS sprite/timeline paths, so the
     * sprite branch is populated regardless of the BIF branch.
     */
    private function controller(): MediaItemController
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([[
            'id' => self::ITEM_ID,
            'name' => 'A Movie',
            'type' => 'movie',
            'library_id' => 'lib-1',
            'path' => '/media/a.mkv',
            'metadata_json' => json_encode([]),
            'trickplay_sprite_path' => '/var/transcodes/trickplay/' . self::ITEM_ID . '/sprite.jpg',
            'trickplay_timeline_path' => '/var/transcodes/trickplay/' . self::ITEM_ID . '/timeline.json',
        ]]);

        $itemRepo = new ItemRepository($db);
        $markerService = new MarkerService($itemRepo, new MarkerCandidateRepository($itemRepo));

        $gapless = $this->createMock(GaplessPlaybackManager::class);
        $gapless->method('getPreferences')->willReturn(PlaybackPreferences::fromRaw(0, 0.3, 0.3));

        return new MediaItemController(
            $itemRepo,
            $markerService,
            $gapless,
            new TrickplayController($this->tempDir, ''),
            new ChapterMarkerService($db)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function callTrickplay(): array
    {
        $response = $this->controller()->getTrickplay(new Request(), ['id' => self::ITEM_ID]);
        $this->assertSame(200, $response->statusCode);

        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);

        return $body;
    }

    private function writeBif(): string
    {
        $jpeg = "\xFF\xD8" . str_repeat("\x41", 12) . "\xFF\xD9";
        $bif = BifWriter::build([$jpeg, $jpeg], 10000);
        file_put_contents($this->tempDir . '/trickplay/' . self::ITEM_ID . '/thumbs.bif', $bif);

        return $bif;
    }

    public function testBifUrlIsAbsentWhenTheArtifactIsNotOnDisk(): void
    {
        $body = $this->callTrickplay();

        $this->assertArrayNotHasKey('trickplay_bif_url', $body);

        // The succeeding sibling: this response is NOT empty for some unrelated
        // reason. sprite_url came back, so the handler ran to completion and the
        // BIF key is missing because the file is missing.
        $this->assertSame('/trickplay/' . self::ITEM_ID . '/sprite.jpg', $body['sprite_url']);
        $this->assertSame('/trickplay/' . self::ITEM_ID . '/timeline.json', $body['timeline_url']);
    }

    public function testBifUrlIsPresentWhenTheArtifactIsOnDisk(): void
    {
        $this->writeBif();

        $body = $this->callTrickplay();

        $this->assertArrayHasKey('trickplay_bif_url', $body);
        $this->assertSame('/trickplay/' . self::ITEM_ID . '/thumbs.bif', $body['trickplay_bif_url']);
        $this->assertSame('/trickplay/' . self::ITEM_ID . '/sprite.jpg', $body['sprite_url']);
    }

    public function testTheUrlItAdvertisesIsTheOneTheServerActuallyAnswers(): void
    {
        $bif = $this->writeBif();
        $body = $this->callTrickplay();

        /** @var string $advertised */
        $advertised = $body['trickplay_bif_url'];

        // Take the advertised URL apart and feed its jobId back through the very
        // handler the route points at. Advertising a URL nothing serves is the
        // defect this gate exists for, so the assertion has to close the loop
        // rather than compare two strings the same code produced.
        $this->assertSame(1, preg_match('#^/trickplay/([^/]+)/thumbs\.bif$#', $advertised, $m));

        $controller = new TrickplayController($this->tempDir, '');
        $served = $controller->getBif(new Request(), ['jobId' => $m[1]]);

        $this->assertSame(200, $served->statusCode);
        $this->assertSame($bif, $served->body);
    }

    public function testTheBifBranchIsIndependentOfTheSpritePaths(): void
    {
        // A row with NO sprite/timeline columns but a BIF on disk: the sprite
        // fields come back null and the BIF is still advertised. Sharing one
        // branch would have hidden the BIF here.
        $this->writeBif();

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([[
            'id' => self::ITEM_ID,
            'name' => 'A Movie',
            'type' => 'movie',
            'library_id' => 'lib-1',
            'path' => '/media/a.mkv',
            'metadata_json' => json_encode([]),
        ]]);

        $itemRepo = new ItemRepository($db);
        $gapless = $this->createMock(GaplessPlaybackManager::class);
        $gapless->method('getPreferences')->willReturn(PlaybackPreferences::fromRaw(0, 0.3, 0.3));

        $controller = new MediaItemController(
            $itemRepo,
            new MarkerService($itemRepo, new MarkerCandidateRepository($itemRepo)),
            $gapless,
            new TrickplayController($this->tempDir, ''),
            new ChapterMarkerService($db)
        );

        $response = $controller->getTrickplay(new Request(), ['id' => self::ITEM_ID]);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);

        $this->assertNull($body['sprite_url']);
        $this->assertNull($body['timeline_url']);
        $this->assertSame('/trickplay/' . self::ITEM_ID . '/thumbs.bif', $body['trickplay_bif_url']);
    }
}
