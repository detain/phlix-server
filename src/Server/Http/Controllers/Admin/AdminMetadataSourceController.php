<?php

/**
 * Phlix media server component: Admin.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Server\Http\Controllers\Admin;

use Phlix\Media\Metadata\Resolution\SourceRegistry;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;

/**
 * Admin JSON API exposing the available metadata-source names (Step 3.6).
 *
 * Feature 3 (configurable metadata-source priority) lets an admin reorder the
 * per-media-type `metadata.provider_priority` lists in the SPA. The editor
 * needs the REAL set of source names to choose from, which is the union of:
 *
 *  - the built-in sources the host always ships
 *    ({@see self::BUILTIN_SOURCES}: `tmdb`, `imdb`, `tvdb`, `fanart`, `local`),
 *    and
 *  - any plugin-contributed sources currently registered in the
 *    {@see SourceRegistry} (e.g. `anidb` / `myanimelist` when those plugins are
 *    enabled — see Step 3.5b).
 *
 *  - `GET /api/v1/admin/metadata/sources` — read-only: returns
 *    `{sources: string[]}` with the built-ins first (in a stable, sensible
 *    order) followed by any extra registered plugin source names not already
 *    among the built-ins, de-duplicated.
 *
 * This endpoint performs NO database access and NO write — it merely reflects
 * the in-process registry plus the fixed built-in list.
 *
 * The route is gated by {@see \Phlix\Server\Http\Middleware\AdminMiddleware}
 * (registered in {@see \Phlix\Server\Http\Routes\AdminRoutes}); non-admin
 * callers receive a JSON 401/403 from the middleware BEFORE this controller
 * runs, so it assumes an already-authenticated admin.
 *
 * Envelope: success returns the top-level named key `{sources: string[]}` —
 * the same shape the sibling admin controllers use (no `{success}` wrapper).
 *
 * @package Phlix\Server\Http\Controllers\Admin
 * @since 3.6
 */
final class AdminMetadataSourceController
{
    /**
     * The built-in metadata sources the host always ships, in a stable,
     * sensible default order. Plugin sources from the {@see SourceRegistry}
     * are appended after these (de-duplicated against this list).
     *
     * @var list<string>
     */
    private const BUILTIN_SOURCES = ['tmdb', 'imdb', 'tvdb', 'fanart', 'local'];

    /**
     * @param SourceRegistry $sources Registry of plugin-contributed metadata
     *        sources, populated on plugin-enable / cleared on plugin-disable.
     */
    public function __construct(
        private readonly SourceRegistry $sources,
    ) {
    }

    /**
     * List the available metadata-source names.
     *
     * `GET /api/v1/admin/metadata/sources` returns `{sources: string[]}` — the
     * built-in sources (in {@see self::BUILTIN_SOURCES} order) followed by any
     * registered plugin source names not already among the built-ins,
     * de-duplicated and in stable registration order.
     *
     * @param Request $request The HTTP request (unused body).
     *
     * @return Response 200 { sources: string[] }
     */
    public function index(Request $request): Response
    {
        $names = self::BUILTIN_SOURCES;

        foreach ($this->sources->names() as $pluginName) {
            if (!in_array($pluginName, $names, true)) {
                $names[] = $pluginName;
            }
        }

        return (new Response())->json(['sources' => $names]);
    }
}
