<?php

/**
 * Phlix media server component: Hub.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub;

use Phlix\Server\Core\Application;
use Phlix\Server\Http\FastPath\PreRouterFastPaths;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Server\WebPortal\WebPortalRouter;
use Psr\Container\ContainerInterface;

use function str_starts_with;

/**
 * Dispatches a synthetic HTTP request (one arriving over the relay tunnel as an
 * {@see \Phlix\Shared\Relay\RelayFrameType::HTTP_REQUEST} frame) through the
 * same local routers the Workerman HTTP daemon uses, returning a {@see Response}.
 *
 * This mirrors {@see \Phlix\Server\Workerman\HttpHandler} steps 0, 1 and 1b:
 *   0. {@see PreRouterFastPaths} — the image endpoints (`/api/v1/artwork/{id}`,
 *      `/api/v1/users/{id}/avatar`) that run BEFORE the route table and are in
 *      NO route table. S238: without this step a relayed browse rendered no
 *      posters and no avatars, because `dispatch()` consulted only the two route
 *      tables and both endpoints 404'd in both of them.
 *   1. The fully-populated {@see Application} router (owns every `/api/*`,
 *      `/health`, `/.well-known`, streaming, and auth routes).
 *   1b. {@see WebPortalRouter} for any `/api/` path the Application router 404s
 *       on (`/api/v1/libraries`, `/api/v1/media/{id}`, `/api/v1/users/me/*`).
 *
 * Static-file serving, the `/media/{id}/stream` direct-play fast path, and the
 * SSR page-rendering fall-through are intentionally NOT mirrored here: Phase 1 of
 * the hub proxy carries JSON/browse traffic (and now the small images that browse
 * needs) only. Whether whole video files should travel the tunnel is S164's open
 * question — see {@see PreRouterFastPaths} for why that one path stayed behind.
 *
 * The DLNA surface is HARD-DENIED before dispatch — see
 * {@see self::RELAY_DENIED_PREFIXES} for why the IP allowlist cannot be trusted
 * on this transport.
 *
 * @package Phlix\Hub
 * @since 0.10.0
 */
final class RelayRequestDispatcher
{
    /**
     * Path prefixes that must NEVER be reachable over the relay tunnel.
     *
     * This is the whole DLNA/UPnP surface `Application::loadCdsRoutes()` registers:
     * `/dlna/description.xml`, `/dlna/content_directory`, `/dlna/stream/{id}`,
     * `/cds/control` and `/scpd/{service}.xml`.
     *
     * ## Why a hard deny here rather than trust in the allowlist
     *
     * Those routes carry NO credentials at all — DLNA has no concept of a user —
     * so their only gate is {@see \Phlix\Server\Http\Middleware\DlnaAllowlistMiddleware},
     * whose shipped default is "loopback + RFC1918/ULA/link-local only". But
     * {@see RelayConsumer} stamps `RELAY_REMOTE_IP = '127.0.0.1'` on every frame it
     * dispatches (a relayed request has no meaningful TCP peer), and loopback is on
     * that LAN list. A request arriving from anywhere on the internet through the
     * tunnel would therefore be handed the LAN policy and admitted with no token:
     * an unauthenticated whole-library read.
     *
     * Nothing exploits that today only because phlix-hub's
     * `ServerProxyController::BROWSE_SCOPE_ALLOWLIST` happens to list no `/dlna`
     * prefix — a cross-repo, cross-process invariant that this repository's test
     * suite cannot see. Widening the hub allowlist, or adding a second tunnel
     * producer, would silently reopen it. The deny is therefore asserted HERE, on
     * the server side, where the bytes actually live: a DLNA renderer is by
     * definition on the local network and never arrives via the relay, so nothing
     * legitimate is lost.
     *
     * @var list<string>
     */
    private const RELAY_DENIED_PREFIXES = [
        '/dlna/',
        '/cds/',
        '/scpd/',
    ];

    /**
     * @param Application        $application The route-registered (un-booted) app router.
     * @param ContainerInterface $container   Container used to resolve {@see WebPortalRouter} lazily.
     * @param PreRouterFastPaths $fastPaths   The pre-router image endpoints (S238). Required rather than
     *                                        lazily resolved from `$container`: a container miss would
     *                                        otherwise degrade silently back to the 404 this step exists
     *                                        to remove.
     */
    public function __construct(
        private readonly Application $application,
        private readonly ContainerInterface $container,
        private readonly PreRouterFastPaths $fastPaths,
    ) {
    }

    /**
     * Dispatch a request and return the response.
     *
     * @param Request $request The synthetic request built from the relay frame.
     *
     * @return Response
     *
     * @since 0.10.0
     */
    public function dispatch(Request $request): Response
    {
        if (self::isRelayDenied($request->path)) {
            // Indistinguishable from "no such route", so the tunnel cannot even be
            // used to learn whether DLNA is switched on.
            return (new Response())->status(404)->text('Not found');
        }

        // S238 step 0: the pre-router image endpoints, which are in NO route
        // table. Consulted at the same pipeline position HttpHandler uses — after
        // the deny, before the router — so both transports serve them identically.
        // Null means "not one of mine" and costs two failed preg_match calls.
        $fastPath = $this->fastPaths->dispatch($request);
        if ($fastPath !== null) {
            return $fastPath;
        }

        $response = $this->application->dispatch($request);

        if ($response->statusCode === 404 && str_starts_with($request->path, '/api/')) {
            $webPortalRouter = $this->container->get(WebPortalRouter::class);
            if ($webPortalRouter instanceof WebPortalRouter) {
                return $webPortalRouter->dispatch($request);
            }
        }

        return $response;
    }

    /**
     * Whether `$path` is part of the authless DLNA surface.
     *
     * Matched on {@see Request::$path}, which is never URL-decoded
     * ({@see Request::fromWorkermanRequest()} takes Workerman's `path()`, i.e.
     * `parse_url()`'s output), so an encoded variant cannot decode past this test
     * into a path the router would then match. The bare `/dlna` form is included
     * because the prefix test alone would miss it.
     *
     * @param string $path Request path, query string already removed.
     */
    private static function isRelayDenied(string $path): bool
    {
        if ($path === '/dlna' || $path === '/description.xml') {
            return true;
        }

        foreach (self::RELAY_DENIED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
