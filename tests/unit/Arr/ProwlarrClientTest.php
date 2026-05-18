<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Arr;

use PHPUnit\Framework\TestCase;
use Phlex\Arr\ProwlarrClient;

/**
 * Unit tests for ProwlarrClient.
 *
 * @package Phlex\Tests\Unit\Arr
 * @since 0.12.0
 */
class ProwlarrClientTest extends TestCase
{
    private MockableProwlarrClient $mockableClient;

    protected function setUp(): void
    {
        $this->mockableClient = new MockableProwlarrClient('http://localhost:9696', 'test-api-key');
    }

    public function testGetIndexersReturnsArray(): void
    {
        $expectedResponse = [
            ['id' => 1, 'name' => 'Torznab', 'enabled' => true],
            ['id' => 2, 'name' => 'Newznab', 'enabled' => false],
        ];

        $this->mockableClient->setMockResponse($expectedResponse);

        $result = $this->mockableClient->getIndexers();

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertEquals('Torznab', $result[0]['name']);
        $this->assertEquals('get', $this->mockableClient->getLastMethodCalled());
        $this->assertEquals('/api/v1/indexer', $this->mockableClient->getLastPathCalled());
    }

    public function testGetIndexerStats(): void
    {
        $expectedResponse = [
            'id' => 1,
            'name' => 'Torznab',
            'status' => 'OK',
            'lastError' => null,
        ];

        $this->mockableClient->setMockResponse($expectedResponse);

        $result = $this->mockableClient->getIndexerStats(1);

        $this->assertIsArray($result);
        $this->assertEquals('Torznab', $result['name']);
        $this->assertEquals('get', $this->mockableClient->getLastMethodCalled());
        $this->assertEquals('/api/v1/indexer/1', $this->mockableClient->getLastPathCalled());
    }

    public function testGetHealth(): void
    {
        $expectedResponse = [
            ['id' => 1, 'type' => 'warning', 'message' => 'Indexer unavailable'],
            ['id' => 2, 'type' => 'error', 'message' => 'Connection timeout'],
        ];

        $this->mockableClient->setMockResponse($expectedResponse);

        $result = $this->mockableClient->getHealth();

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertEquals('get', $this->mockableClient->getLastMethodCalled());
        $this->assertEquals('/api/v1/health', $this->mockableClient->getLastPathCalled());
    }

    public function testTriggerReindexerSendsPost(): void
    {
        $this->mockableClient->setMockResponse(['result' => true]);

        $result = $this->mockableClient->triggerReindexerCheck(1);

        $this->assertTrue($result);
        $this->assertEquals('post', $this->mockableClient->getLastMethodCalled());
        $this->assertEquals('/api/v1/indexer/1/recheck', $this->mockableClient->getLastPathCalled());
    }

    public function testTriggerReindexerReturnsFalseOnFailure(): void
    {
        $this->mockableClient->setMockResponse(new \RuntimeException('Connection refused'));

        $result = $this->mockableClient->triggerReindexerCheck(1);

        $this->assertFalse($result);
    }

    public function testTestConnectionReturnsTrueOnSuccess(): void
    {
        $this->mockableClient->setMockResponse(['version' => '0.8.0.12345']);

        $result = $this->mockableClient->testConnection();

        $this->assertTrue($result);
        $this->assertEquals('get', $this->mockableClient->getLastMethodCalled());
        $this->assertEquals('/api/v1/system/status', $this->mockableClient->getLastPathCalled());
    }

    public function testTestConnectionReturnsFalseOnFailure(): void
    {
        $this->mockableClient->setMockResponse(new \RuntimeException('Connection refused'));

        $result = $this->mockableClient->testConnection();

        $this->assertFalse($result);
    }

    public function testConstructorSetsBaseUrlAndApiKey(): void
    {
        $client = new ProwlarrClient('http://prowlarr.local:9696', 'my-secret-key');

        // Use reflection to verify properties
        $reflection = new \ReflectionClass($client);
        $baseUrlProperty = $reflection->getProperty('baseUrl');
        $baseUrlProperty->setAccessible(true);
        $apiKeyProperty = $reflection->getProperty('apiKey');
        $apiKeyProperty->setAccessible(true);

        $this->assertEquals('http://prowlarr.local:9696', $baseUrlProperty->getValue($client));
        $this->assertEquals('my-secret-key', $apiKeyProperty->getValue($client));
    }
}

/**
 * Testable version of ProwlarrClient that allows mocking HTTP responses.
 *
 * @internal For testing only
 */
class MockableProwlarrClient extends ProwlarrClient
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
