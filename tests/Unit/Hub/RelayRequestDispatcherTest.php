<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Hub;

use Phlix\Hub\RelayRequestDispatcher;
use Phlix\Media\Storage\ArtworkStorage;
use Phlix\Media\Storage\AvatarStorage;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\FastPath\PreRouterFastPaths;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Server\WebPortal\WebPortalRouter;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * The relay tunnel must not be a way into the authless DLNA surface.
 *
 * ## The hole this pins shut
 *
 * DLNA carries no credentials of any kind, so the ONLY gate on `/dlna/*` is
 * {@see \Phlix\Server\Http\Middleware\DlnaAllowlistMiddleware}, whose shipped
 * default admits loopback plus the RFC1918/ULA/link-local ranges. But
 * {@see \Phlix\Hub\RelayConsumer} stamps `RELAY_REMOTE_IP = '127.0.0.1'` on every
 * frame it dispatches — a relayed request has no meaningful TCP peer — and
 * loopback is on that LAN list. So a request arriving from anywhere on the
 * internet, over the tunnel, would be handed the LAN policy and admitted **with no
 * token**: an unauthenticated whole-library read, including
 * `/dlna/stream/{id}`'s bytes, which `RelayConsumer::streamFileChunks()` already
 * knows how to carry back.
 *
 * Nothing exploits it today only because phlix-hub's
 * `ServerProxyController::BROWSE_SCOPE_ALLOWLIST` happens to contain no `/dlna`
 * prefix. That is a cross-repo, cross-process invariant that phlix-server's test
 * suite cannot observe: widening the hub allowlist, or adding a second tunnel
 * producer, would silently reopen it and nothing here would fail. These tests
 * therefore pin the deny on THIS side of the boundary, where the bytes live.
 */
final class RelayRequestDispatcherTest extends TestCase
{
    /**
     * An {@see Application} that would happily serve anything, so a 404 in these
     * tests can only have come from the deny — never from a missing route.
     */
    private function permissiveApplication(): Application
    {
        $app = $this->createMock(Application::class);
        $app->method('dispatch')->willReturn(
            (new Response())->status(200)->text('SERVED-BY-THE-APP-ROUTER'),
        );

        return $app;
    }

    private function container(): ContainerInterface
    {
        $portal = $this->createMock(WebPortalRouter::class);
        $portal->method('dispatch')->willReturn((new Response())->status(200)->text('SERVED-BY-THE-PORTAL'));

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($portal);
        $container->method('has')->willReturn(true);

        return $container;
    }

    /**
     * The S238 pre-router image stage, wired with storages that resolve nothing.
     *
     * None of the paths in THIS file are image paths, so the stage must decline
     * every one of them and the dispatcher's behaviour below is unchanged. The
     * doubles are here so a future edit that made the stage swallow an unrelated
     * path would fail loudly rather than reach a real filesystem.
     */
    private function fastPaths(): PreRouterFastPaths
    {
        $artwork = $this->createMock(ArtworkStorage::class);
        $artwork->method('variantPath')->willReturn(null);

        $avatar = $this->createMock(AvatarStorage::class);
        $avatar->method('path')->willReturn(null);

        return new PreRouterFastPaths($artwork, $avatar);
    }

    private function request(string $path, string $method = 'GET'): Request
    {
        $request = new Request();
        $request->method = $method;
        $request->path = $path;
        // Exactly what RelayConsumer stamps on a relayed frame.
        $request->remoteIp = '127.0.0.1';

        return $request;
    }

    /**
     * THE SECURITY ASSERTION: the whole DLNA surface is refused over the tunnel,
     * and the application router is NEVER asked — so no handler could have run and
     * no bytes could have been produced.
     *
     * RED ON REVERT: deleting the deny turns every one of these into the
     * application router's 200.
     *
     * @dataProvider deniedPaths
     */
    public function test_the_dlna_surface_is_refused_over_the_relay(string $path, string $method): void
    {
        $app = $this->createMock(Application::class);
        $app->expects(self::never())->method('dispatch');

        $dispatcher = new RelayRequestDispatcher($app, $this->container(), $this->fastPaths());
        $response = $dispatcher->dispatch($this->request($path, $method));

        self::assertSame(404, $response->statusCode, $method . ' ' . $path . ' must not be relayable.');
        self::assertStringNotContainsString('SERVED', $response->body);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function deniedPaths(): iterable
    {
        yield 'the byte stream (GET)'      => ['/dlna/stream/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'GET'];
        yield 'the byte stream (HEAD)'     => ['/dlna/stream/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'HEAD'];
        yield 'the ContentDirectory SOAP'  => ['/dlna/content_directory', 'POST'];
        yield 'the device description'     => ['/dlna/description.xml', 'GET'];
        yield 'the legacy description'     => ['/description.xml', 'GET'];
        yield 'the legacy CDS control'     => ['/cds/control', 'POST'];
        yield 'an SCPD document'           => ['/scpd/ContentDirectory.xml', 'GET'];
        yield 'the bare prefix'            => ['/dlna', 'GET'];
    }

    /**
     * The refusal must be indistinguishable from "no such route", so the tunnel
     * cannot even be used to learn whether DLNA is switched on.
     */
    public function test_the_refusal_reveals_nothing_about_dlna(): void
    {
        $dispatcher = new RelayRequestDispatcher(
            $this->permissiveApplication(),
            $this->container(),
            $this->fastPaths(),
        );
        $response = $dispatcher->dispatch($this->request('/dlna/stream/aaaaaaaabbbbccccddddeeeeeeeeeeee'));

        self::assertSame(404, $response->statusCode);
        self::assertStringNotContainsStringIgnoringCase('dlna', $response->body);
        self::assertStringNotContainsStringIgnoringCase('forbidden', $response->body);
    }

    /**
     * POSITIVE CONTROL: the deny is scoped to DLNA and does not break the traffic
     * the tunnel exists to carry. Without this, the tests above would also pass
     * against a dispatcher that refused everything.
     *
     * @dataProvider allowedPaths
     */
    public function test_ordinary_relay_traffic_still_reaches_the_router(string $path): void
    {
        $dispatcher = new RelayRequestDispatcher(
            $this->permissiveApplication(),
            $this->container(),
            $this->fastPaths(),
        );
        $response = $dispatcher->dispatch($this->request($path));

        self::assertSame(200, $response->statusCode);
        self::assertSame('SERVED-BY-THE-APP-ROUTER', $response->body);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function allowedPaths(): iterable
    {
        yield 'the browse API'       => ['/api/v1/media'];
        yield 'a media byte stream'  => ['/media/aaaaaaaa-bbbb/stream'];
        yield 'an HLS playlist'      => ['/hls/job-1/master.m3u8'];
        yield 'health'               => ['/health'];
        // Prefix-collision controls: a deny keyed on a loose `str_contains` would
        // wrongly kill these.
        yield 'a path merely containing dlna' => ['/api/v1/settings/dlna'];
        yield 'a sibling of /cds/'            => ['/cdsomething'];
    }

    /**
     * The pre-existing WebPortalRouter fall-through is untouched: an `/api/` path
     * the application router 404s on is still retried there.
     */
    public function test_the_web_portal_fallthrough_still_works(): void
    {
        $app = $this->createMock(Application::class);
        $app->method('dispatch')->willReturn((new Response())->status(404)->text('no route'));

        $dispatcher = new RelayRequestDispatcher($app, $this->container(), $this->fastPaths());
        $response = $dispatcher->dispatch($this->request('/api/v1/libraries'));

        self::assertSame(200, $response->statusCode);
        self::assertSame('SERVED-BY-THE-PORTAL', $response->body);
    }
}
