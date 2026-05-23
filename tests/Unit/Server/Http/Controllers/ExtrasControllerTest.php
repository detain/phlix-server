<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use Phlix\Media\Extras\Extra;
use Phlix\Media\Extras\Trailer;
use Phlix\Media\Extras\TrailerResolver;
use Phlix\Server\Http\Controllers\ExtrasController;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for {@see ExtrasController}.
 *
 * Covers the three handler methods now wired in Application::loadApiRoutes():
 *   GET /api/v1/media/{id}/extras
 *   GET /api/v1/media/{id}/trailers
 *   GET /api/v1/media/{id}/extras/other
 *
 * Uses createMock(TrailerResolver::class) following the same pattern as
 * AuthControllerTest (PR #107).
 */
class ExtrasControllerTest extends TestCase
{
    private function makeTrailer(string $id = 't-1', string $title = 'Trailer'): Trailer
    {
        return new Trailer(
            id: $id,
            mediaItemId: 'm-1',
            title: $title,
            source: 'tmdb',
            url: 'https://example.com/' . $id,
            duration: 120,
            quality: 1080,
            isLocal: false,
            filePath: ''
        );
    }

    private function makeExtra(string $id = 'e-1', string $type = Extra::TYPE_FEATURETTE): Extra
    {
        return new Extra(
            id: $id,
            mediaItemId: 'm-1',
            title: 'Behind the scenes',
            type: $type,
            source: 'local',
            url: 'file:///media/extra.mkv',
            duration: 300,
            quality: 1080,
            isLocal: true,
            filePath: '/media/extra.mkv'
        );
    }

    /**
     * Happy path: getExtras() returns 200 with the merged extras payload.
     */
    public function testGetExtrasReturns200OnSuccess(): void
    {
        $resolver = $this->createMock(TrailerResolver::class);
        $resolver->expects($this->once())
            ->method('getAllExtras')
            ->with('m-1')
            ->willReturn([$this->makeTrailer(), $this->makeExtra()]);

        $controller = new ExtrasController($resolver);

        $response = $controller->getExtras(new Request(), ['id' => 'm-1']);

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertIsArray($body);
        $this->assertSame(2, $body['count']);
        $this->assertCount(2, $body['extras']);
    }

    /**
     * Negative: getExtras() returns 400 when {id} is missing.
     */
    public function testGetExtrasReturns400WhenIdMissing(): void
    {
        $resolver = $this->createMock(TrailerResolver::class);
        $resolver->expects($this->never())->method('getAllExtras');

        $controller = new ExtrasController($resolver);

        $response = $controller->getExtras(new Request(), []);

        $this->assertSame(400, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('Missing media item ID', $body['error']);
    }

    /**
     * Negative: getExtras() returns 500 when the resolver throws.
     */
    public function testGetExtrasReturns500OnResolverFailure(): void
    {
        $resolver = $this->createMock(TrailerResolver::class);
        $resolver->method('getAllExtras')
            ->willThrowException(new RuntimeException('boom'));

        $controller = new ExtrasController($resolver);

        $response = $controller->getExtras(new Request(), ['id' => 'm-1']);

        $this->assertSame(500, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertStringContainsString('boom', $body['error']);
    }

    /**
     * Happy path: getTrailers() returns 200 with only trailers.
     */
    public function testGetTrailersReturns200OnSuccess(): void
    {
        $resolver = $this->createMock(TrailerResolver::class);
        $resolver->expects($this->once())
            ->method('getTrailers')
            ->with('m-1')
            ->willReturn([$this->makeTrailer('t-1'), $this->makeTrailer('t-2')]);

        $controller = new ExtrasController($resolver);

        $response = $controller->getTrailers(new Request(), ['id' => 'm-1']);

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame(2, $body['count']);
        $this->assertCount(2, $body['trailers']);
        $this->assertSame('t-1', $body['trailers'][0]['id']);
    }

    /**
     * Negative: getTrailers() returns 400 when {id} is missing.
     */
    public function testGetTrailersReturns400WhenIdMissing(): void
    {
        $resolver = $this->createMock(TrailerResolver::class);
        $resolver->expects($this->never())->method('getTrailers');

        $controller = new ExtrasController($resolver);

        $response = $controller->getTrailers(new Request(), []);

        $this->assertSame(400, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('Missing media item ID', $body['error']);
    }

    /**
     * Negative: getTrailers() returns 500 when the resolver throws.
     */
    public function testGetTrailersReturns500OnResolverFailure(): void
    {
        $resolver = $this->createMock(TrailerResolver::class);
        $resolver->method('getTrailers')
            ->willThrowException(new RuntimeException('upstream tmdb error'));

        $controller = new ExtrasController($resolver);

        $response = $controller->getTrailers(new Request(), ['id' => 'm-1']);

        $this->assertSame(500, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertStringContainsString('upstream tmdb error', $body['error']);
    }

    /**
     * Happy path: getOtherExtras() returns 200 with non-trailer extras.
     */
    public function testGetOtherExtrasReturns200OnSuccess(): void
    {
        $resolver = $this->createMock(TrailerResolver::class);
        $resolver->expects($this->once())
            ->method('getExtras')
            ->with('m-1')
            ->willReturn([$this->makeExtra('e-1'), $this->makeExtra('e-2', Extra::TYPE_INTERVIEW)]);

        $controller = new ExtrasController($resolver);

        $response = $controller->getOtherExtras(new Request(), ['id' => 'm-1']);

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame(2, $body['count']);
        $this->assertCount(2, $body['extras']);
        $this->assertSame('e-1', $body['extras'][0]['id']);
    }

    /**
     * Negative: getOtherExtras() returns 400 when {id} is missing.
     */
    public function testGetOtherExtrasReturns400WhenIdMissing(): void
    {
        $resolver = $this->createMock(TrailerResolver::class);
        $resolver->expects($this->never())->method('getExtras');

        $controller = new ExtrasController($resolver);

        $response = $controller->getOtherExtras(new Request(), []);

        $this->assertSame(400, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertSame('Missing media item ID', $body['error']);
    }

    /**
     * Negative: getOtherExtras() returns 500 when the resolver throws.
     */
    public function testGetOtherExtrasReturns500OnResolverFailure(): void
    {
        $resolver = $this->createMock(TrailerResolver::class);
        $resolver->method('getExtras')
            ->willThrowException(new RuntimeException('db down'));

        $controller = new ExtrasController($resolver);

        $response = $controller->getOtherExtras(new Request(), ['id' => 'm-1']);

        $this->assertSame(500, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertStringContainsString('db down', $body['error']);
    }
}
