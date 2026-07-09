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
 * This mirrors {@see \Phlix\Server\Workerman\HttpHandler} steps 1 and 1b:
 *   1. The fully-populated {@see Application} router (owns every `/api/*`,
 *      `/health`, `/.well-known`, streaming, and auth routes).
 *   1b. {@see WebPortalRouter} for any `/api/` path the Application router 404s
 *       on (`/api/v1/libraries`, `/api/v1/media/{id}`, `/api/v1/users/me/*`).
 *
 * Static-file serving, the media byte-stream fast path, and the SSR
 * page-rendering fall-through are intentionally NOT mirrored here: Phase 1 of
 * the hub proxy carries JSON/browse traffic only. Binary media streaming over
 * the tunnel is a later phase.
 *
 * @package Phlix\Hub
 * @since 0.10.0
 */
final class RelayRequestDispatcher
{
    /**
     * @param Application        $application The route-registered (un-booted) app router.
     * @param ContainerInterface $container   Container used to resolve {@see WebPortalRouter} lazily.
     */
    public function __construct(
        private readonly Application $application,
        private readonly ContainerInterface $container,
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
        $response = $this->application->dispatch($request);

        if ($response->statusCode === 404 && str_starts_with($request->path, '/api/')) {
            $webPortalRouter = $this->container->get(WebPortalRouter::class);
            if ($webPortalRouter instanceof WebPortalRouter) {
                return $webPortalRouter->dispatch($request);
            }
        }

        return $response;
    }
}
