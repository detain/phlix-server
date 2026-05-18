<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Arr;

use PHPUnit\Framework\TestCase;
use Phlex\Arr\BazarrClient;

/**
 * Unit tests for BazarrClient.
 *
 * @package Phlex\Tests\Unit\Arr
 * @since 0.12.0
 */
class BazarrClientTest extends TestCase
{
    private MockableBazarrClient $mockableClient;

    protected function setUp(): void
    {
        $this->mockableClient = new MockableBazarrClient('http://localhost:6767', 'test-api-key');
    }

    public function testGetSubtitlesReturnsArray(): void
    {
        $expectedResponse = [
            ['id' => 1, 'language' => 'en', 'path' => '/tv/show/S01E01.mkv'],
            ['id' => 2, 'language' => 'es', 'path' => '/tv/show/S01E01.mkv'],
        ];

        $this->mockableClient->setMockResponse($expectedResponse);

        $result = $this->mockableClient->getSubtitles('123');

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertEquals('en', $result[0]['language']);
        $this->assertEquals('get', $this->mockableClient->getLastMethodCalled());
        $this->assertEquals('/api/v1/subtitles?sonarrSeriesId=123', $this->mockableClient->getLastPathCalled());
    }

    public function testGetSubtitlesWithEpisodeFileId(): void
    {
        $expectedResponse = [
            ['id' => 1, 'language' => 'en', 'episodeFileId' => 456],
        ];

        $this->mockableClient->setMockResponse($expectedResponse);

        $result = $this->mockableClient->getSubtitles('123', 456);

        $this->assertIsArray($result);
        $this->assertEquals('get', $this->mockableClient->getLastMethodCalled());
        $this->assertEquals('/api/v1/subtitles?sonarrSeriesId=123&episodeFileId=456', $this->mockableClient->getLastPathCalled());
    }

    public function testGetSubtitleLanguages(): void
    {
        $expectedResponse = [
            ['code' => 'en', 'name' => 'English'],
            ['code' => 'es', 'name' => 'Spanish'],
        ];

        $this->mockableClient->setMockResponse($expectedResponse);

        $result = $this->mockableClient->getSubtitleLanguages('/tv/show/S01E01.mkv');

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertEquals('get', $this->mockableClient->getLastMethodCalled());
        $this->assertEquals('/api/v1/languages?path=%2Ftv%2Fshow%2FS01E01.mkv', $this->mockableClient->getLastPathCalled());
    }

    public function testDownloadSubtitleSendsPost(): void
    {
        $expectedResponse = ['result' => true, 'message' => 'Subtitle download queued'];

        $this->mockableClient->setMockResponse($expectedResponse);

        $result = $this->mockableClient->downloadSubtitle('/tv/show/S01E01.mkv', 'en');

        $this->assertIsArray($result);
        $this->assertTrue($result['result']);
        $this->assertEquals('post', $this->mockableClient->getLastMethodCalled());
        $this->assertEquals('/api/v1/subtitles/download', $this->mockableClient->getLastPathCalled());
    }

    public function testGetLanguages(): void
    {
        $expectedResponse = [
            ['code' => 'en', 'name' => 'English'],
            ['code' => 'es', 'name' => 'Spanish'],
            ['code' => 'pt-BR', 'name' => 'Portuguese (Brazil)'],
        ];

        $this->mockableClient->setMockResponse($expectedResponse);

        $result = $this->mockableClient->getLanguages();

        $this->assertIsArray($result);
        $this->assertCount(3, $result);
        $this->assertEquals('get', $this->mockableClient->getLastMethodCalled());
        $this->assertEquals('/api/v1/languages/list', $this->mockableClient->getLastPathCalled());
    }

    public function testTestConnectionReturnsTrueOnSuccess(): void
    {
        $this->mockableClient->setMockResponse(['version' => '1.0.0.12345', 'bazarr' => '0.12.0']);

        $result = $this->mockableClient->testConnection();

        $this->assertTrue($result);
        $this->assertEquals('get', $this->mockableClient->getLastMethodCalled());
        $this->assertEquals('/api/v1/system', $this->mockableClient->getLastPathCalled());
    }

    public function testTestConnectionReturnsTrueWithVersionOnly(): void
    {
        $this->mockableClient->setMockResponse(['version' => '1.0.0']);

        $result = $this->mockableClient->testConnection();

        $this->assertTrue($result);
    }

    public function testTestConnectionReturnsFalseOnFailure(): void
    {
        $this->mockableClient->setMockResponse(new \RuntimeException('Connection refused'));

        $result = $this->mockableClient->testConnection();

        $this->assertFalse($result);
    }

    public function testConstructorSetsBaseUrlAndApiKey(): void
    {
        $client = new BazarrClient('http://bazarr.local:6767', 'my-secret-key');

        // Use reflection to verify properties
        $reflection = new \ReflectionClass($client);
        $baseUrlProperty = $reflection->getProperty('baseUrl');
        $baseUrlProperty->setAccessible(true);
        $apiKeyProperty = $reflection->getProperty('apiKey');
        $apiKeyProperty->setAccessible(true);

        $this->assertEquals('http://bazarr.local:6767', $baseUrlProperty->getValue($client));
        $this->assertEquals('my-secret-key', $apiKeyProperty->getValue($client));
    }
}

/**
 * Testable version of BazarrClient that allows mocking HTTP responses.
 *
 * @internal For testing only
 */
class MockableBazarrClient extends BazarrClient
{
    private mixed $mockResponse = null;
    private bool $mockThrowsException = false;
    private ?string $lastMethodCalled = null;
    private ?string $lastPathCalled = null;

    public function setMockResponse(mixed $response): void
    {
        $this->mockResponse = $response;
        $this->mockThrowsException = ($response instanceof \Throwable);
    }

    public function getLastMethodCalled(): ?string
    {
        return $this->lastMethodCalled;
    }

    public function getLastPathCalled(): ?string
    {
        return $this->lastPathCalled;
    }

    protected function get(string $path): array
    {
        $this->lastMethodCalled = 'get';
        $this->lastPathCalled = $path;

        if ($this->mockThrowsException && $this->mockResponse instanceof \Throwable) {
            throw $this->mockResponse;
        }

        return $this->mockResponse ?? [];
    }

    protected function post(string $path, array $body): array
    {
        $this->lastMethodCalled = 'post';
        $this->lastPathCalled = $path;

        if ($this->mockThrowsException && $this->mockResponse instanceof \Throwable) {
            throw $this->mockResponse;
        }

        return $this->mockResponse ?? [];
    }
}
