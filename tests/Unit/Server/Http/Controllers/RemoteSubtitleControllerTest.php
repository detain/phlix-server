<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Subtitles\Quota\SubtitleProviderQuotaRepository;
use Phlix\Media\Subtitles\SubtitleFetchService;
use Phlix\Media\Subtitles\SubtitleSourceRegistry;
use Phlix\Media\Subtitles\SubtitleStorage;
use Phlix\Server\Http\Controllers\RemoteSubtitleController;
use Phlix\Server\Http\Request;
use Phlix\Shared\Subtitle\SubtitleCandidate;
use Phlix\Shared\Subtitle\SubtitleFile;
use Phlix\Tests\Unit\Media\Subtitles\Fakes\FakeSubtitleSource;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * @covers \Phlix\Server\Http\Controllers\RemoteSubtitleController
 */
final class RemoteSubtitleControllerTest extends TestCase
{
    private string $baseDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->baseDir = sys_get_temp_dir() . '/phlix_rsc_' . uniqid('', true);
        mkdir($this->baseDir, 0o775, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->baseDir)) {
            @system('rm -rf ' . escapeshellarg($this->baseDir));
        }
        parent::tearDown();
    }

    private function quotaRepo(): SubtitleProviderQuotaRepository
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        return new SubtitleProviderQuotaRepository($db);
    }

    private function controller(SubtitleSourceRegistry $registry, ItemRepository $items): RemoteSubtitleController
    {
        $storage = new SubtitleStorage($this->baseDir);
        $fetch = new SubtitleFetchService($registry, $storage, $this->quotaRepo(), $items, null, null);

        return new RemoteSubtitleController($fetch, $items, $storage);
    }

    private function candidate(): SubtitleCandidate
    {
        return new SubtitleCandidate(
            provider: 'opensubtitles',
            language: 'en',
            downloadId: 'dl-1',
            releaseName: 'The.Movie',
            format: 'srt',
            downloadCount: 42,
        );
    }

    public function testSearchReturnsCandidates(): void
    {
        $registry = new SubtitleSourceRegistry();
        $registry->register(new FakeSubtitleSource('opensubtitles', 0, [$this->candidate()]));

        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn(['id' => 'm1', 'path' => '/x.mkv', 'metadata' => []]);

        $request = new Request();
        $request->query = ['lang' => 'en'];

        $response = $this->controller($registry, $items)->search($request, ['id' => 'm1']);

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertCount(1, $body['candidates']);
        $this->assertSame('opensubtitles', $body['candidates'][0]['provider']);
        $this->assertSame('dl-1', $body['candidates'][0]['downloadId']);
        $this->assertSame(42, $body['candidates'][0]['downloadCount']);
    }

    public function testSearchEmptyRegistryReturnsEmptyCandidates(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn(['id' => 'm1', 'path' => '/x.mkv', 'metadata' => []]);

        $request = new Request();
        $request->query = ['lang' => 'en'];

        $response = $this->controller(new SubtitleSourceRegistry(), $items)->search($request, ['id' => 'm1']);

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame([], $body['candidates']);
    }

    public function testDownloadAttachesTrackAndReturnsIt(): void
    {
        $file = new SubtitleFile(
            language: 'en',
            format: 'srt',
            content: "1\n00:00:01,000 --> 00:00:02,000\nHi\n",
            provider: 'opensubtitles',
            suggestedFilename: 'x.srt',
        );
        $registry = new SubtitleSourceRegistry();
        $registry->register(new FakeSubtitleSource('opensubtitles', 0, [], $file));

        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn(['id' => 'm1', 'path' => '/x.mkv', 'metadata' => []]);
        $items->method('addExternalSubtitleStream')->willReturn('stream-99');
        $items->method('getItemStreams')->willReturn([[
            'id' => 'stream-99',
            'media_item_id' => 'm1',
            'stream_index' => 3,
            'stream_type' => 'subtitle',
            'codec' => 'webvtt',
            'language' => 'en',
            'source' => 'opensubtitles',
            'storage_path' => $this->baseDir . '/m1/en.vtt',
        ]]);

        $request = new Request();
        $request->body = ['provider' => 'opensubtitles', 'downloadId' => 'dl-1', 'language' => 'en'];

        $response = $this->controller($registry, $items)->download($request, ['id' => 'm1']);

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('stream-99', $body['track']['id']);
        $this->assertSame('opensubtitles', $body['track']['source']);
        $this->assertFileExists($this->baseDir . '/m1/en.vtt');
    }

    public function testDownloadRejectsMissingFields(): void
    {
        $items = $this->createMock(ItemRepository::class);

        $request = new Request();
        $request->body = ['provider' => 'opensubtitles']; // no downloadId / language

        $response = $this->controller(new SubtitleSourceRegistry(), $items)->download($request, ['id' => 'm1']);

        $this->assertSame(400, $response->statusCode);
    }

    public function testServeExternalReturnsStoredVtt(): void
    {
        // Write a stored subtitle the way the storage layer would.
        $storage = new SubtitleStorage($this->baseDir);
        $path = $storage->store('m1', new SubtitleFile(
            language: 'en',
            format: 'vtt',
            content: "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nHi\n",
            provider: 'opensubtitles',
            suggestedFilename: 'x.vtt',
        ));

        $items = $this->createMock(ItemRepository::class);
        $items->method('getStreamById')->willReturn([
            'id' => 'stream-99',
            'media_item_id' => 'm1',
            'storage_path' => $path,
        ]);

        $fetch = new SubtitleFetchService(new SubtitleSourceRegistry(), $storage, $this->quotaRepo(), $items, null, null);
        $controller = new RemoteSubtitleController($fetch, $items, $storage);

        $response = $controller->serveExternal(new Request(), ['id' => 'm1', 'streamId' => 'stream-99']);

        $this->assertSame(200, $response->statusCode);
        $this->assertStringContainsString('text/vtt', $response->headers['Content-Type'] ?? '');
        $this->assertStringStartsWith('WEBVTT', $response->body);
    }

    public function testServeExternalIsNotFoundWhenStreamBelongsToAnotherItem(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('getStreamById')->willReturn([
            'id' => 'stream-99',
            'media_item_id' => 'OTHER-ITEM',
            'storage_path' => $this->baseDir . '/x.vtt',
        ]);

        $controller = $this->controller(new SubtitleSourceRegistry(), $items);

        $response = $controller->serveExternal(new Request(), ['id' => 'm1', 'streamId' => 'stream-99']);

        $this->assertSame(404, $response->statusCode);
    }
}
