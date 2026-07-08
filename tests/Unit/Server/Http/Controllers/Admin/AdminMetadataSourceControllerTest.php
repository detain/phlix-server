<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers\Admin;

use Phlix\Media\Metadata\Resolution\SourceRegistry;
use Phlix\Server\Http\Controllers\Admin\AdminMetadataSourceController;
use Phlix\Server\Http\Request;
use Phlix\Shared\Metadata\MetadataSourceInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AdminMetadataSourceController (Step 3.6, Feature 3).
 *
 * Auth (401/403) is enforced by {@see \Phlix\Server\Http\Middleware\AdminMiddleware}
 * upstream of this controller (verified in the AdminRoutes integration test);
 * here we assert the controller's own behaviour given an already-authenticated
 * admin request — the `{sources: string[]}` envelope, the built-ins-first order,
 * that registered plugin sources are appended, and that a plugin re-using a
 * built-in name does not duplicate it.
 *
 * The controller is driven with a REAL {@see SourceRegistry} so the assertions
 * are non-vacuous end-to-end.
 *
 * @covers \Phlix\Server\Http\Controllers\Admin\AdminMetadataSourceController
 */
final class AdminMetadataSourceControllerTest extends TestCase
{
    public function testIndexReturnsBuiltInSourcesWhenRegistryEmpty(): void
    {
        $controller = new AdminMetadataSourceController(new SourceRegistry());

        $response = $controller->index(new Request());

        $this->assertSame(200, $response->statusCode);
        $body = $this->decode($response->body);
        $this->assertArrayHasKey('sources', $body);
        // Built-ins, in the stable documented order.
        $this->assertSame(['tmdb', 'imdb', 'tvdb', 'fanart', 'local'], $body['sources']);
    }

    public function testIndexAppendsRegisteredPluginSources(): void
    {
        $registry = new SourceRegistry();
        $registry->register($this->fakeSource('anidb', ['anime', 'series']));
        $registry->register($this->fakeSource('myanimelist', ['anime']));

        $controller = new AdminMetadataSourceController($registry);

        $response = $controller->index(new Request());

        $this->assertSame(200, $response->statusCode);
        $body = $this->decode($response->body);
        // Built-ins first (in order), then the plugin sources in registration order.
        $this->assertSame(
            ['tmdb', 'imdb', 'tvdb', 'fanart', 'local', 'anidb', 'myanimelist'],
            $body['sources'],
        );
    }

    public function testIndexDeduplicatesPluginThatReusesABuiltInName(): void
    {
        $registry = new SourceRegistry();
        // A plugin that (re-)registers under a built-in name must NOT duplicate
        // that name in the output list.
        $registry->register($this->fakeSource('tmdb', ['movie']));
        $registry->register($this->fakeSource('anidb', ['anime']));

        $controller = new AdminMetadataSourceController($registry);

        $response = $controller->index(new Request());

        $this->assertSame(200, $response->statusCode);
        $body = $this->decode($response->body);
        // 'tmdb' appears exactly once (from the built-in list); only the truly
        // new 'anidb' is appended.
        $sources = $body['sources'];
        $this->assertIsArray($sources);
        $this->assertSame(
            ['tmdb', 'imdb', 'tvdb', 'fanart', 'local', 'anidb'],
            $sources,
        );
        $this->assertCount(1, array_keys($sources, 'tmdb', true));
    }

    /**
     * A minimal {@see MetadataSourceInterface} implementer for registry tests.
     *
     * @param non-empty-string $name
     * @param list<non-empty-string> $types
     */
    private function fakeSource(string $name, array $types): MetadataSourceInterface
    {
        return new class ($name, $types) implements MetadataSourceInterface {
            /**
             * @param non-empty-string $name
             * @param list<non-empty-string> $types
             */
            public function __construct(
                private readonly string $name,
                private readonly array $types,
            ) {
            }

            public function sourceName(): string
            {
                return $this->name;
            }

            public function supportedMediaTypes(): array
            {
                return $this->types;
            }

            public function search(string $query, array $options = []): array
            {
                return [];
            }

            public function getDetails(string $externalId, array $options = []): array
            {
                return [];
            }

            public function getImages(string $externalId): array
            {
                return [];
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
