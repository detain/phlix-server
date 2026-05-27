<?php

declare(strict_types=1);

namespace Phlix\Server\Workerman;

use Phlix\Auth\AuthManager;
use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Plugins\PluginLoader;
use Phlix\Server\Http\Controllers\BookController;
use Phlix\Server\Http\Controllers\PhotoController;
use Phlix\Server\Http\Middleware\AdminMiddleware;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Server\Http\Router;
use Phlix\Server\Http\Routes\AdminRoutes;
use Phlix\Server\WebPortal\Controllers\AudiobookPageController;
use Phlix\Server\WebPortal\Controllers\BookPageController;
use Phlix\Server\WebPortal\Controllers\MusicPageController;
use Phlix\Server\WebPortal\Controllers\PhotoPageController;
use Phlix\Server\WebPortal\Controllers\PluginAdminPageController;
use Phlix\Server\WebPortal\PageRenderer;
use Phlix\Server\WebPortal\WebPortalRouter;
use Psr\Container\ContainerInterface;
use Throwable;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request as WorkermanRequest;
use Workerman\Protocols\Http\Response as WorkermanResponse;

/**
 * Per-request handler invoked by the Workerman HTTP worker.
 *
 * Modelled on webman's `Webman\App::onMessage()` pattern: each incoming
 * Workerman request first tries the static-file fast path against the
 * public/ document root; falling through, it converts the Workerman
 * request into the project's own {@see Request} object, validates the
 * Bearer token if one is present, and dispatches via the same router
 * tree {@see public/index.php} uses for CGI-style requests.
 *
 * `public/index.php` is left untouched as the CGI entry point — direct
 * invocation by php-fpm / `php -S` / similar continues to work. This
 * class is the *parallel* dispatcher used only when phlix-server runs
 * as a Workerman daemon via {@see start.php}.
 *
 * @package Phlix\Server\Workerman
 */
final class HttpHandler
{
    private readonly Router $adminRouter;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly AuthManager $authManager,
        private readonly string $publicRoot,
    ) {
        $this->adminRouter = new Router();
        AdminRoutes::register($this->adminRouter, $this->container);
    }

    /**
     * Workerman onMessage callback.
     */
    public function __invoke(TcpConnection $connection, WorkermanRequest $wr): void
    {
        try {
            $static = $this->serveStatic($wr);
            if ($static !== null) {
                $connection->send($static);
                return;
            }

            $request = Request::fromWorkerman($wr, $connection);

            // Bearer-token auth (mirror public/index.php)
            $token = $request->getBearerToken();
            if ($token !== null && $token !== '') {
                $auth = $this->authManager->validateAccessToken($token);
                if (is_array($auth) && is_string($auth['user_id'] ?? null)) {
                    $request->userId = $auth['user_id'];
                }
            }

            $response = $this->dispatch($request);
            $connection->send($response->toWorkermanResponse());
        } catch (Throwable $e) {
            LoggerFactory::get(LogChannels::HTTP)->error(
                'Unhandled exception in HTTP worker',
                [
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ],
            );
            $connection->send(new WorkermanResponse(
                500,
                ['Content-Type' => 'text/html; charset=utf-8'],
                '<h1>500 Internal Server Error</h1>',
            ));
        }
    }

    /**
     * Serve a file directly from public/ if the request path maps to
     * one. Returns null when no static file matches and the request
     * should fall through to the dynamic dispatcher.
     */
    private function serveStatic(WorkermanRequest $wr): ?WorkermanResponse
    {
        $path = $wr->path();
        if ($path === '' || $path === '/' || str_starts_with($path, '/api/')) {
            return null;
        }
        // No `..` traversal — realpath() canonicalises and must stay under public/.
        $candidate = $this->publicRoot . $path;
        $real = realpath($candidate);
        if ($real === false) {
            return null;
        }
        if (!str_starts_with($real, $this->publicRoot . DIRECTORY_SEPARATOR)) {
            return null;
        }
        if (!is_file($real)) {
            return null;
        }
        // Never serve PHP source files raw.
        if (strtolower((string) pathinfo($real, PATHINFO_EXTENSION)) === 'php') {
            return null;
        }
        $mime = function_exists('mime_content_type') ? (mime_content_type($real) ?: null) : null;
        $mime ??= 'application/octet-stream';

        $resp = new WorkermanResponse(200, ['Content-Type' => $mime]);
        $resp->withFile($real);
        return $resp;
    }

    /**
     * Dispatch a dynamic request to the right router/controller.
     *
     * Mirrors the routing chain in {@see public/index.php}. Kept in
     * sync by convention — when a new top-level route is added there,
     * add it here too (or extract the chain into a shared helper).
     */
    private function dispatch(Request $request): Response
    {
        $path = $request->path;

        // Admin JSON API
        if (str_starts_with($path, '/api/v1/admin/')) {
            return $this->adminRouter->dispatch($request);
        }
        // Other JSON API
        if (str_starts_with($path, '/api/')) {
            /** @var WebPortalRouter $api */
            $api = $this->container->get(WebPortalRouter::class);
            return $api->dispatch($request);
        }

        /** @var PageRenderer $renderer */
        $renderer = $this->container->get(PageRenderer::class);

        if ($path === '/' || $path === '') {
            return $renderer->renderHome($request);
        }
        if ($path === '/login') {
            return $renderer->renderLogin($request);
        }
        if (str_starts_with($path, '/admin/plugins')) {
            return $this->dispatchAdminPlugins($renderer, $request, $path);
        }
        if ($path === '/admin/dashboard') {
            return $this->dispatchAdminDashboard($renderer, $request);
        }
        if (str_starts_with($path, '/music')) {
            return $this->dispatchMusic($request, $path);
        }
        if (str_starts_with($path, '/books')) {
            return $this->dispatchBooks($request, $path);
        }
        if (str_starts_with($path, '/audiobooks')) {
            return $this->dispatchAudiobooks($request, $path);
        }
        if (str_starts_with($path, '/photo')) {
            return $this->dispatchPhoto($request, $path);
        }
        return (new Response())->status(404)->html('<h1>404 - Page not found</h1>');
    }

    private function dispatchAdminPlugins(PageRenderer $renderer, Request $request, string $path): Response
    {
        /** @var AdminMiddleware $admin */
        $admin = $this->container->get(AdminMiddleware::class);
        $gate = $admin->checkAccess($request);
        if ($gate === 401) {
            return (new Response())->status(401)->html('<h1>401 — admin authentication required</h1>');
        }
        if ($gate === 403) {
            return (new Response())->status(403)->html('<h1>403 — administrator privileges required</h1>');
        }
        /** @var PluginLoader $loader */
        $loader = $this->container->get(PluginLoader::class);
        $page = new PluginAdminPageController($loader, $this->publicRoot . '/templates');
        if ($path === '/admin/plugins') {
            return $page->index($request, []);
        }
        if ($path === '/admin/plugins/install') {
            return $page->install($request, []);
        }
        if (preg_match('#^/admin/plugins/(?P<name>[^/]+)$#', $path, $m) === 1) {
            return $page->detail($request, ['name' => $m['name']]);
        }
        return (new Response())->status(404)->html('<h1>404 - Page not found</h1>');
    }

    private function dispatchAdminDashboard(PageRenderer $renderer, Request $request): Response
    {
        /** @var AdminMiddleware $admin */
        $admin = $this->container->get(AdminMiddleware::class);
        $gate = $admin->checkAccess($request);
        if ($gate === 401) {
            return (new Response())->status(401)->html('<h1>401 — admin authentication required</h1>');
        }
        if ($gate === 403) {
            return (new Response())->status(403)->html('<h1>403 — administrator privileges required</h1>');
        }
        return $renderer->renderDashboard($request);
    }

    private function dispatchMusic(Request $request, string $path): Response
    {
        /** @var MusicPageController $music */
        $music = $this->container->get(MusicPageController::class);
        if ($path === '/music' || $path === '/music/albums') {
            return $music->albums($request, []);
        }
        if (preg_match('#^/music/albums/(?P<name>.+)$#', $path, $m) === 1) {
            return $music->album($request, ['name' => urldecode($m['name'])]);
        }
        if ($path === '/music/artists') {
            return $music->artists($request, []);
        }
        if (preg_match('#^/music/artists/(?P<name>.+)$#', $path, $m) === 1) {
            return $music->artist($request, ['name' => urldecode($m['name'])]);
        }
        if ($path === '/music/tracks') {
            return $music->tracks($request, []);
        }
        if ($path === '/music/player') {
            return $music->player($request, []);
        }
        return (new Response())->status(404)->html('<h1>404 - Page not found</h1>');
    }

    private function dispatchBooks(Request $request, string $path): Response
    {
        if (preg_match('#^/books/(?P<id>[^/]+)/cover$#', $path, $m) === 1) {
            /** @var BookController $api */
            $api = $this->container->get(BookController::class);
            return $api->getCover($request, ['id' => $m['id']]);
        }
        if (preg_match('#^/books/(?P<id>[^/]+)/download$#', $path, $m) === 1) {
            /** @var BookController $api */
            $api = $this->container->get(BookController::class);
            return $api->downloadBook($request, ['id' => $m['id']]);
        }
        if (preg_match('#^/books/(?P<id>[^/]+)/read$#', $path, $m) === 1) {
            /** @var BookPageController $page */
            $page = $this->container->get(BookPageController::class);
            return $page->reader($request, ['id' => $m['id']]);
        }
        if (preg_match('#^/books/(?P<id>[^/]+)$#', $path, $m) === 1) {
            /** @var BookPageController $page */
            $page = $this->container->get(BookPageController::class);
            return $page->detail($request, ['id' => $m['id']]);
        }
        if ($path === '/books') {
            /** @var BookPageController $page */
            $page = $this->container->get(BookPageController::class);
            return $page->index($request, []);
        }
        return (new Response())->status(404)->html('<h1>404 - Page not found</h1>');
    }

    private function dispatchAudiobooks(Request $request, string $path): Response
    {
        /** @var AudiobookPageController $audiobook */
        $audiobook = $this->container->get(AudiobookPageController::class);
        if ($path === '/audiobooks') {
            return $audiobook->index($request, []);
        }
        if (preg_match('#^/audiobooks/(?P<id>[^/]+)/read$#', $path, $m) === 1) {
            return $audiobook->player($request, ['id' => $m['id']]);
        }
        if (preg_match('#^/audiobooks/(?P<id>[^/]+)$#', $path, $m) === 1) {
            return $audiobook->detail($request, ['id' => $m['id']]);
        }
        return (new Response())->status(404)->html('<h1>404 - Page not found</h1>');
    }

    private function dispatchPhoto(Request $request, string $path): Response
    {
        if (preg_match('#^/photo/photos/(?P<id>[^/]+)/thumbnail$#', $path, $m) === 1) {
            /** @var PhotoController $api */
            $api = $this->container->get(PhotoController::class);
            return $api->getThumbnail($request, ['id' => $m['id']]);
        }
        if (preg_match('#^/photo/photos/(?P<id>[^/]+)/full$#', $path, $m) === 1) {
            /** @var PhotoController $api */
            $api = $this->container->get(PhotoController::class);
            return $api->getFull($request, ['id' => $m['id']]);
        }
        /** @var PhotoPageController $page */
        $page = $this->container->get(PhotoPageController::class);
        if ($path === '/photo/albums') {
            return $page->albums($request, []);
        }
        if (preg_match('#^/photo/album/(?P<id>[^/]+)$#', $path, $m) === 1) {
            return $page->album($request, ['id' => $m['id']]);
        }
        if (preg_match('#^/photo/photo/(?P<id>[^/]+)$#', $path, $m) === 1) {
            return $page->photo($request, ['id' => $m['id']]);
        }
        if ($path === '/photo/slideshow') {
            return $page->slideshow($request, []);
        }
        return (new Response())->status(404)->html('<h1>404 - Page not found</h1>');
    }
}
