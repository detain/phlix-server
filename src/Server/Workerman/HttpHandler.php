<?php

declare(strict_types=1);

namespace Phlix\Server\Workerman;

use Phlix\Auth\AuthManager;
use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Plugins\PluginLoader;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\Controllers\AuthController;
use Phlix\Server\Http\Controllers\BookController;
use Phlix\Server\Http\Controllers\PhotoController;
use Phlix\Server\Http\Middleware\AdminMiddleware;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Server\WebPortal\Controllers\AdminAppController;
use Phlix\Server\WebPortal\Controllers\AudiobookPageController;
use Phlix\Server\WebPortal\Controllers\BookPageController;
use Phlix\Server\WebPortal\Controllers\MusicPageController;
use Phlix\Server\WebPortal\Controllers\PhotoPageController;
use Phlix\Server\WebPortal\Controllers\PluginAdminPageController;
use Phlix\Server\WebPortal\PageRenderer;
use Phlix\Theming\ThemeMiddleware;
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
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly AuthManager $authManager,
        private readonly string $publicRoot,
        private readonly Application $application,
    ) {
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

            // Bearer-token auth (mirrors the inline check that
            // public/index.php used to do). Application's router has no
            // global auth middleware — controllers check $request->userId
            // themselves — so we populate it here before dispatch.
            //
            // Browser sessions arrive as a `phlix_session` HttpOnly
            // cookie rather than an Authorization header — set by
            // {@see AuthController::browserAuthResponse()} on login. We
            // fall back to it here so subsequent page navigations show
            // the user as authenticated without needing client-side JS
            // to attach a header.
            $token = $request->getBearerToken();
            if ($token === null || $token === '') {
                $cookieToken = $request->getCookie(AuthController::SESSION_COOKIE);
                if (is_string($cookieToken) && $cookieToken !== '') {
                    $token = $cookieToken;
                    $request->bearerToken = $cookieToken;
                }
            }
            if ($token !== null && $token !== '') {
                $auth = $this->authManager->validateAccessToken($token);
                if (is_array($auth) && is_string($auth['user_id'] ?? null)) {
                    $request->userId = $auth['user_id'];
                }
            }

            // 1) Try the fully-populated Application router first. It
            //    owns every /api/*, /health, /system/info, /.well-known,
            //    /hls/, /dash/, /stream/, /opds/, and the browser-form
            //    auth aliases (/auth/login, /auth/register, /auth/refresh).
            //    Its constructor wires ThemeMiddleware into the middleware
            //    chain, so HTML responses produced by routes here already
            //    have `{$theme_css|raw}` / `{$theme_js|raw}` substituted.
            $appResponse = $this->application->dispatch($request);
            if ($appResponse->statusCode !== 404) {
                $connection->send($appResponse->toWorkermanResponse());
                return;
            }

            // 2) Fall through to the page-rendering routes (home, login,
            //    library, search, settings, admin SSR pages, /music,
            //    /books, /audiobooks, /photo). These aren't in
            //    Application's router so we have to dispatch and apply
            //    ThemeMiddleware ourselves.
            /** @var ThemeMiddleware $theme */
            $theme = $this->container->get(ThemeMiddleware::class);
            $response = $theme->onHttpRequest($request, fn (Request $req): Response => $this->dispatch($req));
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
        $resp = new WorkermanResponse(200, ['Content-Type' => self::mimeFor($real)]);
        $resp->withFile($real);
        return $resp;
    }

    /**
     * Best-guess Content-Type for a file we're about to serve.
     *
     * Extension first — `mime_content_type()` sniffs file content via
     * libmagic and returns `text/plain` for any text format, so CSS, JS,
     * SVG, and JSON would all be mis-typed by the browser if we trusted
     * it. For everything not in the explicit map, fall back to libmagic
     * (good for images / archives) and finally to a safe binary default.
     */
    private static function mimeFor(string $path): string
    {
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $byExt = [
            'css'   => 'text/css; charset=utf-8',
            'js'    => 'application/javascript; charset=utf-8',
            'mjs'   => 'application/javascript; charset=utf-8',
            'json'  => 'application/json; charset=utf-8',
            'map'   => 'application/json; charset=utf-8',
            'html'  => 'text/html; charset=utf-8',
            'htm'   => 'text/html; charset=utf-8',
            'txt'   => 'text/plain; charset=utf-8',
            'xml'   => 'application/xml; charset=utf-8',
            'svg'   => 'image/svg+xml',
            'webmanifest' => 'application/manifest+json',
            'ico'   => 'image/x-icon',
            'png'   => 'image/png',
            'jpg'   => 'image/jpeg',
            'jpeg'  => 'image/jpeg',
            'gif'   => 'image/gif',
            'webp'  => 'image/webp',
            'avif'  => 'image/avif',
            'woff'  => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf'   => 'font/ttf',
            'otf'   => 'font/otf',
            'eot'   => 'application/vnd.ms-fontobject',
            'mp3'   => 'audio/mpeg',
            'm4a'   => 'audio/mp4',
            'ogg'   => 'audio/ogg',
            'mp4'   => 'video/mp4',
            'webm'  => 'video/webm',
            'pdf'   => 'application/pdf',
            'wasm'  => 'application/wasm',
        ];
        if (isset($byExt[$ext])) {
            return $byExt[$ext];
        }
        if (function_exists('mime_content_type')) {
            $detected = mime_content_type($path);
            if (is_string($detected) && $detected !== '') {
                return $detected;
            }
        }
        return 'application/octet-stream';
    }

    /**
     * Dispatch the page-rendering routes that aren't registered on the
     * {@see Application} router. The Application router owns every
     * `/api/*`, `/health`, `/.well-known`, `/hls/*`, `/dash/*`,
     * `/stream/*`, `/opds/*` and the browser-form `/auth/login` style
     * aliases — `__invoke()` tries it first. Anything that 404s there
     * falls through to here.
     */
    private function dispatch(Request $request): Response
    {
        $path = $request->path;

        /** @var PageRenderer $renderer */
        $renderer = $this->container->get(PageRenderer::class);

        if ($path === '/' || $path === '') {
            return $renderer->renderHome($request);
        }
        if ($path === '/login') {
            return $renderer->renderLogin($request);
        }
        if ($path === '/register' || $path === '/auth/register') {
            return $renderer->renderRegister($request);
        }
        if ($path === '/library' || $path === '/library/') {
            return $renderer->renderLibrariesOverview($request);
        }
        if (preg_match('#^/library/(?P<id>[^/]+)$#', $path, $m) === 1) {
            return $renderer->renderLibrary($request, ['id' => $m['id']]);
        }
        if ($path === '/search') {
            return $renderer->renderSearch($request);
        }
        if ($path === '/settings') {
            return $renderer->renderSettings($request);
        }
        if (str_starts_with($path, '/admin/plugins')) {
            return $this->dispatchAdminPlugins($renderer, $request, $path);
        }
        if ($path === '/admin/dashboard') {
            return $this->dispatchAdminDashboard($renderer, $request);
        }
        if ($path === '/admin' || str_starts_with($path, '/admin/')) {
            return $this->dispatchAdminApp($request);
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

    /**
     * Serve the client-side admin SPA shell for `/admin` + `/admin/*`
     * (Step 0.4). Reached only AFTER the specific `/admin/plugins` and
     * `/admin/dashboard` SSR branches, so those keep winning. Reuses the
     * same {@see AdminMiddleware} gate as the JSON API; a failed gate (401
     * or 403) redirects to `/login` via
     * {@see AdminAppController::gateRedirect()}.
     */
    private function dispatchAdminApp(Request $request): Response
    {
        /** @var AdminMiddleware $admin */
        $admin = $this->container->get(AdminMiddleware::class);
        $app = new AdminAppController($this->publicRoot);
        $redirect = $app->gateRedirect($admin->checkAccess($request));
        return $redirect ?? $app->shell($request, []);
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
