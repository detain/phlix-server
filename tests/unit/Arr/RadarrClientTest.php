<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Arr;

use PHPUnit\Framework\TestCase;
use Phlex\Arr\RadarrClient;

/**
 * Unit tests for RadarrClient.
 *
 * @package Phlex\Tests\Unit\Arr
 * @since 0.12.0
 */
class RadarrClientTest extends TestCase
{
    private MockableRadarrClient $mockableClient;

    protected function setUp(): void
    {
        $this->mockableClient = new MockableRadarrClient('http://localhost:7878', 'test-api-key');
    }

    public function testGetMoviesReturnsArray(): void
    {
        $expectedResponse = [
            ['id' => 1, 'title' => 'Test Movie', 'tmdbId' => 123456],
            ['id' => 2, 'title' => 'Another Movie', 'tmdbId' => 654321],
        ];

        $this->mockableClient->setMockResponse($expectedResponse);

        $result = $this->mockableClient->getMovies();

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertEquals('Test Movie', $result[0]['title']);
        $this->assertEquals('get', $this->mockableClient->getLastMethodCalled());
        $this->assertEquals('/api/v3/movie', $this->mockableClient->getLastPathCalled());
    }

    public function testGetMovieByIdReturnsSingleMovie(): void
    {
        $expectedResponse = [
            'id' => 1,
            'title' => 'Test Movie',
            'tmdbId' => 123456,
            'monitored' => true,
        ];

        $this->mockableClient->setMockResponse($expectedResponse);

        $result = $this->mockableClient->getMovieById(1);

        $this->assertIsArray($result);
        $this->assertEquals('Test Movie', $result['title']);
        $this->assertEquals('get', $this->mockableClient->getLastMethodCalled());
        $this->assertEquals('/api/v3/movie/1', $this->mockableClient->getLastPathCalled());
    }

    public function testGetQueueParsesItems(): void
    {
        $expectedResponse = [
            'records' => [
                ['id' => 1, 'status' => 'downloading', 'movie' => ['title' => 'Test Movie']],
                ['id' => 2, 'status' => 'pending', 'movie' => ['title' => 'Another Movie']],
            ],
        ];

        $this->mockableClient->setMockResponse($expectedResponse);

        $result = $this->mockableClient->getQueue();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('records', $result);
        $this->assertCount(2, $result['records']);
        $this->assertEquals('get', $this->mockableClient->getLastMethodCalled());
        $this->assertEquals('/api/v3/queue', $this->mockableClient->getLastPathCalled());
    }

    public function testGetQualityProfilesReturnsProfiles(): void
    {
        $expectedResponse = [
            ['id' => 1, 'name' => 'HD-720p'],
            ['id' => 2, 'name' => 'HD-1080p'],
            ['id' => 3, 'name' => 'Ultra HD 4K'],
        ];

        $this->mockableClient->setMockResponse($expectedResponse);

        $result = $this->mockableClient->getQualityProfiles();

        $this->assertIsArray($result);
        $this->assertCount(3, $result);
        $this->assertEquals('get', $this->mockableClient->getLastMethodCalled());
        $this->assertEquals('/api/v3/qualityprofile', $this->mockableClient->getLastPathCalled());
    }

    public function testGetCustomFormatsReturnsFormats(): void
    {
        $expectedResponse = [
            ['id' => 1, 'name' => 'BR-Dish'],
            ['id' => 2, 'name' => 'HD-Audio'],
        ];

        $this->mockableClient->setMockResponse($expectedResponse);

        $result = $this->mockableClient->getCustomFormats();

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertEquals('get', $this->mockableClient->getLastMethodCalled());
        $this->assertEquals('/api/v3/customformat', $this->mockableClient->getLastPathCalled());
    }

    public function testGetTagListReturnsTags(): void
    {
        $expectedResponse = [
            ['id' => 1, 'label' => 'anime'],
            ['id' => 2, 'label' => 'kids'],
        ];

        $this->mockableClient->setMockResponse($expectedResponse);

        $result = $this->mockableClient->getTagList();

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertEquals('get', $this->mockableClient->getLastMethodCalled());
        $this->assertEquals('/api/v3/tag', $this->mockableClient->getLastPathCalled());
    }

    public function testAddMovieBuildsCorrectPayload(): void
    {
        $expectedResponse = ['id' => 10, 'tmdbId' => 123456, 'title' => 'New Movie'];

        $this->mockableClient->setMockResponse($expectedResponse);

        $result = $this->mockableClient->addMovie(123456, 2, '/movies', true);

        $this->assertIsArray($result);
        $this->assertEquals(123456, $result['tmdbId']);
        $this->assertEquals('post', $this->mockableClient->getLastMethodCalled());
        $this->assertEquals('/api/v3/movie', $this->mockableClient->getLastPathCalled());
    }

    public function testTriggerDownloadSendsPost(): void
    {
        $this->mockableClient->setMockResponse(['result' => true]);

        $result = $this->mockableClient->triggerDownload(100);

        $this->assertTrue($result);
        $this->assertEquals('post', $this->mockableClient->getLastMethodCalled());
        $this->assertEquals('/api/v3/release/100', $this->mockableClient->getLastPathCalled());
    }

    public function testTestConnectionReturnsTrueOnSuccess(): void
    {
        $this->mockableClient->setMockResponse(['version' => '3.0.0.12345']);

        $result = $this->mockableClient->testConnection();

        $this->assertTrue($result);
        $this->assertEquals('get', $this->mockableClient->getLastMethodCalled());
        $this->assertEquals('/api/v3/system/status', $this->mockableClient->getLastPathCalled());
    }

    public function testTestConnectionReturnsFalseOnFailure(): void
    {
        $this->mockableClient->setMockResponse(new \RuntimeException('Connection refused'));

        $result = $this->mockableClient->testConnection();

        $this->assertFalse($result);
    }

    public function testConstructorSetsBaseUrlAndApiKey(): void
    {
        $client = new RadarrClient('http://radarr.local:7878', 'my-secret-key');

        // Use reflection to verify properties
        $reflection = new \ReflectionClass($client);
        $baseUrlProperty = $reflection->getProperty('baseUrl');
        $baseUrlProperty->setAccessible(true);
        $apiKeyProperty = $reflection->getProperty('apiKey');
        $apiKeyProperty->setAccessible(true);

        $this->assertEquals('http://radarr.local:7878', $baseUrlProperty->getValue($client));
        $this->assertEquals('my-secret-key', $apiKeyProperty->getValue($client));
    }
}

/**
 * Testable version of RadarrClient that allows mocking HTTP responses.
 *
 * @internal For testing only
 */
class MockableRadarrClient extends RadarrClient
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
