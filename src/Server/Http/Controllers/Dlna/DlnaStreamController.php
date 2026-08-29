<?php

/**
 * Phlix media server component: Dlna.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers\Dlna;

use Phlix\Common\Logger\StructuredLogger;
use Phlix\Dlna\DlnaMimeTypes;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryRootJail;
use Phlix\Server\Http\Controllers\ByteRangeParser;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * Serves media bytes to a DLNA renderer: `GET|HEAD /dlna/stream/{mediaItemId}`.
 *
 * ## The gap this closes
 *
 * A DLNA renderer is a dumb HTTP client. It cannot present a Bearer token, it
 * cannot follow an OAuth flow, and it cannot be handed a signed URL out of band
 * — it fetches exactly the URL the ContentDirectory advertised in `<res>`. Every
 * other byte-serving route in Phlix requires a session or an HMAC-signed URL, so
 * DLNA browse worked while DLNA *playback* could not, by construction.
 *
 * ## Why this is a ROUTER route and not an HttpHandler bypass
 *
 * The obvious template — {@see \Phlix\Server\Http\FastPath\PreRouterFastPaths::serveMediaStream()},
 * which serves `/media/{id}/stream` — is the WRONG one to copy. It runs before
 * `Application::dispatch()`, so router middleware cannot reach it, which is
 * precisely why it re-implements auth and stream limits inline. Copying that
 * shape here would produce a route with **no allowlist enforcement at all**: an
 * unauthenticated, whole-library read for anything that can reach the port —
 * exactly the defect {@see \Phlix\Server\Http\Middleware\DlnaAllowlistMiddleware}
 * exists to prevent.
 *
 * This controller is therefore registered INSIDE `loadCdsRoutes()`'s existing
 * `$router->group('', …, [$allowlistMiddleware])`, which buys three things:
 *   1. the inbound IP allowlist gates it, per request, like every other CDS path;
 *   2. the `dlna.cds_enabled` master switch gates it — with DLNA off the route is
 *      never registered, so it 404s rather than merely 403ing;
 *   3. {@see Response::withFile()} still streams through the Workerman event loop
 *      (chunked above 2 MB), so a multi-GB file never lands in worker heap.
 *
 * ## What is NOT enforced here, and why it is stated rather than pretended
 *
 * There is no user on a DLNA request, so there is no profile — which means
 * **parental controls and per-profile stream limits cannot apply**. That is
 * inherent to a protocol with no authentication, not an oversight: it is why
 * `dlna.cds_enabled` ships OFF and why the allowlist defaults to LAN-only.
 *
 * This gap pre-dates S52 — DLNA *browse* has never applied a rating filter
 * (`grep -rn RatingGate src/Dlna/` finds nothing) — but S52 makes it actionable,
 * so it is disclosed where an OPERATOR will actually see it rather than only
 * here: `config/dlna.php`'s header, the CHANGELOG, and the `dlna.cds_enabled`
 * `helpText` in the shared settings schema (which is the string the admin UI
 * renders next to the switch). A docblock is not a disclosure.
 *
 * ## Residual TOCTOU on the served path
 *
 * The jail decision is made on the canonicalised path here, but Workerman
 * re-`stat`s and `fopen`s that path STRING at send time
 * (`vendor/workerman/workerman/src/Protocols/Http.php:407-435`), so a symlink
 * swapped into place inside that window would be served un-jailed. Closing it
 * would require handing the event loop an already-open descriptor, which
 * Workerman's `withFile()` does not accept. It requires local write access
 * inside a library directory — i.e. an actor who can already replace the media
 * file itself — so it is accepted and recorded rather than papered over.
 *
 * ## Direct play only
 *
 * S52 serves the source file as-is. It does not start a transcode: the only
 * on-demand pipeline available is HLS (`.m3u8` + `.ts`), which no DLNA renderer
 * speaks, so "trigger a transcode" would swap a dead URL for an unplayable one.
 * A container this server does not recognise is answered `415` — an honest
 * "unsupported" beats bytes labelled with a guessed type.
 *
 * @package Phlix\Server\Http\Controllers\Dlna
 * @since 1.7.0
 */
final class DlnaStreamController
{
    /**
     * The shape a `media_items.id` is allowed to have.
     *
     * `media_items.id` is `CHAR(36)` holding a UUID v4. This is asserted BEFORE
     * the value is used for anything, so a client-supplied id can never be a path
     * fragment, a null byte, or an encoded traversal — it is rejected as
     * "not found" while still a string in memory. The router's `{param}` pattern
     * already excludes `/`; this excludes `.`, `%`, `\` and everything else.
     */
    private const ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/';

    /**
     * @param ItemRepository  $items The row source — the ONLY place a filesystem
     *                               path may come from on this route.
     * @param LibraryRootJail $jail  Second-layer guard: the resolved path must
     *                               live inside a configured library root.
     * @param StructuredLogger|null $logger DLNA channel logger, or null in tests.
     */
    public function __construct(
        private readonly ItemRepository $items,
        private readonly LibraryRootJail $jail,
        private readonly ?StructuredLogger $logger = null,
    ) {
    }

    /**
     * Handle `GET|HEAD /dlna/stream/{mediaItemId}`.
     *
     * @param Request               $request The HTTP request (`Range` header, method).
     * @param array<string, string> $params  Route parameters; `mediaItemId`.
     *
     * @return Response 200 (whole file), 206 (byte window), 404 (unknown id /
     *                  missing or out-of-jail file), 415 (container we do not
     *                  direct-play), 416 (unsatisfiable range).
     */
    public function handle(Request $request, array $params): Response
    {
        $id = $params['mediaItemId'] ?? '';
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            return $this->notFound();
        }

        $item = $this->items->findById($id);
        if (!is_array($item)) {
            return $this->notFound();
        }

        $path = $item['path'] ?? null;
        if (!is_string($path) || $path === '') {
            return $this->notFound();
        }

        // Canonicalise ONCE, then only ever touch the canonical path. A container
        // object (series/season/album/artist) has a directory here, so is_file()
        // also filters those out.
        $real = realpath($path);
        if ($real === false || !is_file($real) || !is_readable($real)) {
            return $this->notFound();
        }

        if (!$this->jail->allows($real)) {
            // Deliberately logged, never surfaced: the response must not confirm
            // that a path exists, let alone name it.
            $this->logger?->warning(
                'DLNA stream refused: resolved media path is outside every configured library root',
                ['media_item_id' => $id],
            );

            return $this->notFound();
        }

        // Direct-play gate keyed on the CONTAINER (the extension), not on the
        // row's `type`: a row typed `movie` whose file is a .iso must not be
        // served as video/mp4.
        //
        // Read from the CANONICAL path, never from the raw `media_items.path`.
        // Those two disagree whenever the stored path is a symlink, and the bytes
        // come from the canonical one — so gating on the raw path would serve
        // `Film.mp4 → Disc.iso` as `video/mp4`, reaching the exact failure this
        // gate exists to prevent by a different route.
        $item['path'] = $real;
        if (DlnaMimeTypes::forPath($real) === DlnaMimeTypes::FALLBACK) {
            return (new Response())
                ->status(415)
                ->text('Unsupported media type for direct play');
        }

        // The served Content-Type is resolved from the ROW so it is identical to
        // the `protocolInfo` MIME the ContentDirectory advertises for the same
        // item (S53) — a renderer that sees the two disagree rejects the object.
        // A junk explicit `mime_type` falls back to the container's own type.
        $mime = DlnaMimeTypes::forItem($item);
        if (!DlnaMimeTypes::isMediaType($mime)) {
            $mime = DlnaMimeTypes::forPath($real);
        }

        // A failing filesize() here realistically means the file vanished between
        // is_file() above and this call, so answer it as "gone" — the same 404
        // every other unservable state gets — rather than a 500 that reports a
        // server fault for a client-visible race.
        $fileSize = filesize($real);
        if ($fileSize === false) {
            return $this->notFound();
        }
        $fileSize = (int) $fileSize;

        // Many renderers HEAD the resource before opening it, to learn the size
        // and whether byte seeking is offered. Answered explicitly (rather than
        // via Router::dispatch()'s GET→HEAD fallback, which suppresses the
        // file-backed body and would therefore report Content-Length: 0).
        //
        // The `Content-Length` set here is the size a GET would return, per RFC
        // 9110 §9.3.2. It survives to the wire as the ONLY Content-Length because
        // `headOnly` makes {@see \Phlix\Server\Http\Response::toWorkermanResponse()}
        // render the reply through {@see \Phlix\Server\Workerman\BodylessResponse};
        // Workerman's own encoder appends a contradictory `Content-Length: 0` after
        // it, which RFC 9110 §8.6 makes invalid and which is exactly the outcome
        // this arm exists to avoid. Asserted on the ENCODED BYTES, not on the
        // header array — a header-array assertion cannot see that defect at all.
        //
        // `headOnly` is ALSO set by the router — {@see \Phlix\Server\Http\Router::markHeadOnly()}
        // flags every response `dispatch()` returns for a `HEAD` request, whether the
        // route was registered for HEAD in its own right (`match(['GET','HEAD'], …)`,
        // as this one is) or resolved through the GET→HEAD fallback. So this
        // assignment is REDUNDANT on the live route and is kept deliberately, as
        // defence in depth: it keeps the HEAD arm correct on its own when the
        // controller is invoked by anything that is not `Router::dispatch()` — the
        // unit test calls `handle()` directly, and `Plugin\PluginRouter` accepts HEAD
        // registrations without flagging them. Assigning `true` twice is a no-op.
        // The flag is also what the CGI/FPM entrypoint reads to suppress the body, so
        // setting it keeps both entrypoints in agreement.
        if ($request->method === 'HEAD') {
            $head = (new Response())
                ->status(200)
                ->header('Content-Type', $mime)
                ->header('Accept-Ranges', 'bytes')
                ->header('Content-Length', (string) $fileSize);
            $head->headOnly = true;

            return $head;
        }

        return $this->serveBytes($request, $real, $fileSize, $mime);
    }

    /**
     * Stream the file, honouring a single-range `Range` request.
     *
     * Byte seeking is not optional for DLNA: a renderer's scrub bar issues
     * `bytes=N-` requests, and S53 advertises `DLNA.ORG_OP=01` (byte-seek
     * supported) on the strength of this method. {@see ByteRangeParser} resolves
     * `bytes=A-B`, `bytes=A-` and `bytes=-N`, clamping an over-long last-byte-pos
     * to EOF per RFC 7233 §2.1 instead of rejecting a conforming client.
     *
     * @param Request $request  The request (for the `Range` header).
     * @param string  $realPath Canonical, jailed, readable file path.
     * @param int     $fileSize Size of that file in bytes.
     * @param string  $mime     Content-Type to advertise.
     */
    private function serveBytes(Request $request, string $realPath, int $fileSize, string $mime): Response
    {
        $range = ByteRangeParser::parse($request->getHeader('Range'), $fileSize);

        if ($range !== null) {
            if (!$range['satisfiable']) {
                return (new Response())
                    ->status(416)
                    ->header('Content-Type', $mime)
                    ->header('Accept-Ranges', 'bytes')
                    ->header('Content-Range', "bytes */{$fileSize}");
            }

            // withFile() with a non-zero window makes Workerman emit
            // Content-Range + Content-Length itself (mirrored for the CGI path by
            // Response::finalizeFileHeaders()).
            return (new Response())
                ->status(206)
                ->header('Content-Type', $mime)
                ->header('Accept-Ranges', 'bytes')
                ->withFile($realPath, $range['start'], $range['end'] - $range['start'] + 1);
        }

        return (new Response())
            ->status(200)
            ->header('Content-Type', $mime)
            ->header('Accept-Ranges', 'bytes')
            ->withFile($realPath);
    }

    /**
     * The single "no" answer for every not-found / not-permitted-path case.
     *
     * One indistinguishable reply for "no such id", "row has no file", "file
     * missing", "file vanished mid-request" and "path outside the library roots",
     * carrying no filesystem detail — an unauthenticated caller learns nothing
     * about the host from it.
     *
     * The one deliberate exception is the `415`: a renderer has to be told the
     * difference between "no such object" and "this container is not served", so
     * the 415 is an existence oracle by design. It is only reachable by a caller
     * the allowlist already admitted, and that caller can enumerate every id via
     * CDS Browse anyway, so it discloses nothing new.
     */
    private function notFound(): Response
    {
        return (new Response())->status(404)->text('Media not found');
    }
}
