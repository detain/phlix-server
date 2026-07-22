<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Subtitles;

use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Subtitles\Quota\SubtitleProviderQuotaRepository;
use Phlix\Media\Subtitles\SubtitleFetchService;
use Phlix\Media\Subtitles\SubtitleSourceRegistry;
use Phlix\Media\Subtitles\SubtitleStorage;
use Phlix\Shared\Subtitle\Exception\QuotaExceeded;
use Phlix\Shared\Subtitle\SubtitleCandidate;
use Phlix\Shared\Subtitle\SubtitleFile;
use Phlix\Tests\Unit\Media\Subtitles\Fakes\FakeSubtitleSource;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * @covers \Phlix\Media\Subtitles\SubtitleFetchService
 */
final class SubtitleFetchServiceTest extends TestCase
{
    private string $baseDir = '';

    /** @var list<array{sql: string, params: array<int, mixed>}> */
    private array $quotaWrites = [];

    /** @var array<string, array<string, mixed>> provider => stored quota row */
    private array $quotaRows = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->baseDir = sys_get_temp_dir() . '/phlix_fetch_' . uniqid('', true);
        mkdir($this->baseDir, 0o775, true);
        $this->quotaWrites = [];
        $this->quotaRows = [];
    }

    protected function tearDown(): void
    {
        if (is_dir($this->baseDir)) {
            @system('rm -rf ' . escapeshellarg($this->baseDir));
        }
        parent::tearDown();
    }

    /**
     * A quota repository backed by a mocked Connection whose SELECTs return
     * {@see $this->quotaRows} and whose INSERTs are captured in
     * {@see $this->quotaWrites}.
     */
    private function quotaRepo(): SubtitleProviderQuotaRepository
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(function (string $sql, $params = []) {
            $params = is_array($params) ? $params : [];
            if (str_starts_with(ltrim($sql), 'SELECT')) {
                $provider = (string) ($params[0] ?? '');
                $row = $this->quotaRows[$provider] ?? null;
                return $row === null ? [] : [$row];
            }
            // INSERT ... ON DUPLICATE KEY UPDATE
            $this->quotaWrites[] = ['sql' => $sql, 'params' => $params];
            return [];
        });

        return new SubtitleProviderQuotaRepository($db);
    }

    private function candidate(string $provider, string $downloadId = 'dl-1'): SubtitleCandidate
    {
        return new SubtitleCandidate(
            provider: $provider,
            language: 'en',
            downloadId: $downloadId,
            releaseName: 'The.Movie.1999',
            format: 'srt',
        );
    }

    private function service(
        SubtitleSourceRegistry $registry,
        ItemRepository $items,
        ?SubtitleProviderQuotaRepository $quota = null,
    ): SubtitleFetchService {
        return new SubtitleFetchService(
            $registry,
            new SubtitleStorage($this->baseDir),
            $quota ?? $this->quotaRepo(),
            $items,
            null,
            null,
        );
    }

    public function testSearchAggregatesCandidatesInPriorityOrder(): void
    {
        $registry = new SubtitleSourceRegistry();
        // low intrinsic priority number runs first.
        $registry->register(new FakeSubtitleSource('bbb', 10, [$this->candidate('bbb')]));
        $registry->register(new FakeSubtitleSource('aaa', 0, [$this->candidate('aaa')]));

        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn(['id' => 'm1', 'path' => '/movies/x.mkv', 'metadata' => []]);

        $candidates = $this->service($registry, $items)->search('m1', ['en']);

        $this->assertSame(
            ['aaa', 'bbb'],
            array_column($candidates, 'provider'),
            'candidates must follow the registry priority order',
        );
    }

    public function testSearchSkipsQuotaExhaustedProvider(): void
    {
        $exhausted = new FakeSubtitleSource('aaa', 0, [$this->candidate('aaa')]);
        $available = new FakeSubtitleSource('bbb', 10, [$this->candidate('bbb')]);

        $registry = new SubtitleSourceRegistry();
        $registry->register($exhausted);
        $registry->register($available);

        // Mark 'aaa' as out of quota (remaining 0, reset far in the future).
        $this->quotaRows['aaa'] = [
            'provider' => 'aaa',
            'downloads_remaining' => 0,
            'reset_time_utc' => '2999-01-01T00:00:00+00:00',
        ];

        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn(['id' => 'm1', 'path' => '/movies/x.mkv', 'metadata' => []]);

        $candidates = $this->service($registry, $items)->search('m1', ['en']);

        $this->assertSame(['bbb'], array_column($candidates, 'provider'));
        $this->assertSame(0, $exhausted->searchByPathCalls, 'exhausted provider must not be queried');
        $this->assertSame(1, $available->searchByPathCalls);
    }

    public function testSearchFallsBackToImdbWhenPathYieldsNothing(): void
    {
        // Path search returns [] (empty candidate list) → IMDb fallback runs.
        $source = new FakeSubtitleSource('aaa', 0, []);
        $registry = new SubtitleSourceRegistry();
        $registry->register($source);

        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn([
            'id' => 'm1',
            'path' => '',
            'metadata' => ['external_ids' => ['imdb' => 'tt0133093']],
        ]);

        $this->service($registry, $items)->search('m1', ['en']);

        $this->assertSame(1, $source->searchByImdbIdCalls, 'IMDb fallback must run when path search is empty');
    }

    public function testSearchEmptyWhenNoSourcesRegistered(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn(['id' => 'm1', 'path' => '/x.mkv', 'metadata' => []]);

        $this->assertSame([], $this->service(new SubtitleSourceRegistry(), $items)->search('m1', ['en']));
    }

    public function testDownloadRecordsQuotaOnQuotaExceeded(): void
    {
        $quotaException = new QuotaExceeded('out', 0, '2999-01-01T00:00:00+00:00');
        $source = new FakeSubtitleSource('opensubtitles', 0, [], null, $quotaException);

        $registry = new SubtitleSourceRegistry();
        $registry->register($source);

        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn(['id' => 'm1', 'path' => '/x.mkv', 'metadata' => []]);
        // Nothing may be persisted on a quota failure.
        $items->expects($this->never())->method('addExternalSubtitleStream');

        $result = $this->service($registry, $items)
            ->download('m1', 'opensubtitles', 'dl-1', 'en', 'srt');

        $this->assertSame(SubtitleFetchService::RESULT_QUOTA_EXCEEDED, $result['status']);
        $this->assertSame(0, $result['downloadsRemaining']);
        $this->assertSame('2999-01-01T00:00:00+00:00', $result['resetTimeUtc']);

        // Quota state was persisted (an INSERT upsert with the reported values).
        $this->assertNotEmpty($this->quotaWrites);
        $last = end($this->quotaWrites);
        $this->assertSame(['opensubtitles', 0, '2999-01-01T00:00:00+00:00'], $last['params']);
    }

    public function testDownloadPersistsFileAndAttachesTrackOnSuccess(): void
    {
        $file = new SubtitleFile(
            language: 'en',
            format: 'srt',
            content: "1\n00:00:01,000 --> 00:00:02,000\nHi\n",
            provider: 'opensubtitles',
            suggestedFilename: 'x.srt',
        );
        $source = new FakeSubtitleSource('opensubtitles', 0, [], $file);

        $registry = new SubtitleSourceRegistry();
        $registry->register($source);

        $capturedStreamData = null;
        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn(['id' => 'm1', 'path' => '/x.mkv', 'metadata' => []]);
        $items->expects($this->once())
            ->method('addExternalSubtitleStream')
            ->with('m1', $this->callback(function ($data) use (&$capturedStreamData): bool {
                $capturedStreamData = $data;
                return true;
            }))
            ->willReturn('stream-99');
        // After attach the service re-shapes the item's streams to return the track.
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

        $result = $this->service($registry, $items)
            ->download('m1', 'opensubtitles', 'dl-1', 'en', 'srt');

        $this->assertSame(SubtitleFetchService::RESULT_OK, $result['status']);

        // 1. The subtitle file was written to the injected base dir as WebVTT.
        $this->assertFileExists($this->baseDir . '/m1/en.vtt');
        $written = (string) file_get_contents($this->baseDir . '/m1/en.vtt');
        $this->assertStringStartsWith('WEBVTT', $written);
        $this->assertStringContainsString('00:00:01.000 --> 00:00:02.000', $written);

        // 2. The attach recorded the storage path + provider.
        $this->assertIsArray($capturedStreamData);
        $this->assertSame('opensubtitles', $capturedStreamData['source']);
        $this->assertSame($this->baseDir . '/m1/en.vtt', $capturedStreamData['storage_path']);

        // 3. The returned track is the freshly-attached external row.
        $this->assertSame('stream-99', $result['track']['id']);
        $this->assertSame('opensubtitles', $result['track']['source']);
        $this->assertIsString($result['track']['url']);

        // 4. A success signal cleared quota exhaustion.
        $last = end($this->quotaWrites);
        $this->assertSame(['opensubtitles', null, null], $last['params']);
    }

    public function testDownloadReturnsProviderUnavailableForUnknownProvider(): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn(['id' => 'm1', 'path' => '/x.mkv', 'metadata' => []]);

        $result = $this->service(new SubtitleSourceRegistry(), $items)
            ->download('m1', 'ghost', 'dl-1', 'en');

        $this->assertSame(SubtitleFetchService::RESULT_PROVIDER_UNAVAILABLE, $result['status']);
    }
}
