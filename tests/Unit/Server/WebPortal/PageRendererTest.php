<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\WebPortal;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Server\Http\Request;
use Phlix\Server\WebPortal\PageRenderer;
use Phlix\Session\PlaybackController;

final class PageRendererTest extends TestCase
{
    private function renderer(): PageRenderer
    {
        return new PageRenderer(
            '/tmp/templates',
            $this->createMock(LibraryManager::class),
            $this->createMock(ItemRepository::class),
            $this->createMock(PlaybackController::class),
        );
    }

    public function testRenderPlayerRedirectsLegacyRouteToTheSpaPlayer(): void
    {
        $response = $this->renderer()->renderPlayer(new Request(), ['id' => 'abc-123']);

        $this->assertSame(302, $response->statusCode);
        $this->assertSame('/app/player/abc-123', $response->headers['Location']);
    }

    public function testRenderPlayerUrlEncodesTheId(): void
    {
        $response = $this->renderer()->renderPlayer(new Request(), ['id' => 'a/b c']);

        $this->assertSame('/app/player/a%2Fb%20c', $response->headers['Location']);
    }

    public function testRenderPlayerToleratesAMissingId(): void
    {
        $response = $this->renderer()->renderPlayer(new Request(), []);

        $this->assertSame('/app/player/', $response->headers['Location']);
    }
}
