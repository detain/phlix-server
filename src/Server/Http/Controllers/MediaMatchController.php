<?php

/**
 * Phlix media server component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\MediaItemShaper;
use Phlix\Media\Metadata\Exception\TmdbUnconfiguredException;
use Phlix\Media\Metadata\LibraryMetadataMatcher;
use Phlix\Server\Http\Middleware\AdminMiddleware;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Throwable;

/**
 * Interactive per-item metadata-match API (Phase S5).
 *
 * Lets an admin search TMDB for a single media item and apply a chosen result,
 * to fix wrong/unmatched items from the UI without re-running a whole-library
 * match. Both endpoints are admin-gated, mirroring
 * {@see LibraryController::matchMetadata()}.
 *
 *  - `GET  /api/v1/media/{id}/match/search` — candidate list (auto from the
 *    item's title/year, or manual `query`/`year`/`type`).
 *  - `POST /api/v1/media/{id}/match/apply`  — resolve + persist a chosen
 *    `{tmdb_id, type}` onto the item (series → its season/episode subtree too),
 *    returning the freshly-shaped item.
 *
 * @package Phlix\Server\Http\Controllers
 * @since   0.25.0
 */
class MediaMatchController
{
    /**
     * Maximum character length for string values in the context block.
     * Prevents unbounded string growth from pathological metadata values.
     */
    private const MAX_CONTEXT_STRING = 500;

    private ItemRepository $items;

    private LibraryMetadataMatcher $matcher;

    private ?AdminMiddleware $adminMiddleware = null;

    public function __construct(ItemRepository $items, LibraryMetadataMatcher $matcher)
    {
        $this->items = $items;
        $this->matcher = $matcher;
    }

    /**
     * Set the admin middleware (admin-only operations).
     */
    public function setAdminMiddleware(AdminMiddleware $middleware): void
    {
        $this->adminMiddleware = $middleware;
    }

    /**
     * `GET /api/v1/media/{id}/match/search`.
     *
     * Query params (all optional): `query` (default = item title), `year`
     * (default = item year), `type` (`tv`|`movie`, default derived from the
     * item's type).
     *
     * @param array<string, string> $params Route params; `id` is the item id.
     *
     * @return Response `200 { results, query, type }` · `400` no usable query ·
     *                  `404` item-missing · `422` TMDB unconfigured · `401`/`403`.
     */
    public function search(Request $request, array $params): Response
    {
        $auth = $this->requireAdmin($request);
        if ($auth !== null) {
            return $auth;
        }

        $item = $this->items->findById($params['id'] ?? '');
        if ($item === null) {
            return (new Response())->status(404)->json(['error' => 'Item not found']);
        }

        $type = $request->queryString('type');
        $type = ($type === 'tv' || $type === 'movie')
            ? $type
            : LibraryMetadataMatcher::modeForType($item['type'] ?? null);

        $query = $request->queryString('query');
        $query = ($query !== null && trim($query) !== '') ? trim($query) : $this->itemTitle($item);
        if ($query === null || $query === '') {
            return (new Response())->status(400)->json([
                'error' => 'No search query and the item has no title',
                'code' => 'metadata.no_query',
            ]);
        }

        $year = $request->queryString('year');
        $yearInt = ($year !== null && is_numeric($year)) ? (int) $year : $this->itemYear($item);

        try {
            $results = $this->matcher->searchCandidates($query, $type, $yearInt, 20);
        } catch (TmdbUnconfiguredException $e) {
            return (new Response())->status(422)->json([
                'error' => $e->getMessage(),
                'code' => TmdbUnconfiguredException::ERROR_CODE,
            ]);
        } catch (Throwable $e) {
            return (new Response())->status(502)->json([
                'error' => 'TMDB search failed',
                'code' => 'metadata.tmdb_unreachable',
            ]);
        }

        $context = $this->buildContext($item, $query);

        // Attach the same context built from the item-being-searched to every
        // TMDB candidate result (all candidates are matches against the same item).
        $results = array_map(
            static fn (array $result): array => array_merge($result, ['context' => $context]),
            $results,
        );

        return (new Response())->json([
            'results' => $results,
            'query' => $query,
            'type' => $type,
        ]);
    }

    /**
     * `POST /api/v1/media/{id}/match/apply`.
     *
     * Body: `{ tmdb_id, type? }` — `type` (`tv`|`movie`) defaults to the value
     * derived from the item's type. Resolves full metadata for `tmdb_id` and
     * persists it onto the item (series → its season/episode subtree too).
     *
     * @param array<string, string> $params Route params; `id` is the item id.
     *
     * @return Response `200 { item, applied }` · `400` bad tmdb_id/type ·
     *                  `404` item-missing · `422` TMDB unconfigured /
     *                  no match · `502` TMDB unreachable · `401`/`403`.
     */
    public function apply(Request $request, array $params): Response
    {
        $auth = $this->requireAdmin($request);
        if ($auth !== null) {
            return $auth;
        }

        $id = $params['id'] ?? '';
        $item = $this->items->findById($id);
        if ($item === null) {
            return (new Response())->status(404)->json(['error' => 'Item not found']);
        }

        $tmdbRaw = $request->input('tmdb_id');
        $tmdbId = is_scalar($tmdbRaw) ? trim((string) $tmdbRaw) : '';
        if ($tmdbId === '') {
            return (new Response())->status(400)->json([
                'error' => 'tmdb_id is required',
                'code' => 'metadata.bad_tmdb_id',
            ]);
        }

        $typeRaw = $request->input('type');
        $type = ($typeRaw === 'tv' || $typeRaw === 'movie')
            ? $typeRaw
            : LibraryMetadataMatcher::modeForType($item['type'] ?? null);

        try {
            $applied = $this->matcher->applyMatch($id, $tmdbId, $type);
        } catch (TmdbUnconfiguredException $e) {
            return (new Response())->status(422)->json([
                'error' => $e->getMessage(),
                'code' => TmdbUnconfiguredException::ERROR_CODE,
            ]);
        } catch (Throwable $e) {
            return (new Response())->status(502)->json([
                'error' => 'TMDB apply failed',
                'code' => 'metadata.tmdb_unreachable',
            ]);
        }

        if ($applied['matched'] !== true) {
            return (new Response())->status(422)->json([
                'error' => 'TMDB returned no usable details for that id',
                'code' => 'metadata.no_match',
            ]);
        }

        // Re-load and shape so the UI can refresh the card with fresh metadata.
        $fresh = $this->items->findById($id) ?? $item;
        $freshId = is_string($fresh['id'] ?? null) ? $fresh['id'] : $id;
        $shaped = MediaItemShaper::shapeDetail($fresh, $this->items->getItemStreams($freshId));

        return (new Response())->json([
            'item' => $shaped,
            'applied' => $applied,
        ]);
    }

    /**
     * Resolve the item's title for an auto search: `name` column, else
     * `metadata.title`/`metadata.series_title`.
     *
     * @param array<string, mixed> $item Hydrated item.
     */
    private function itemTitle(array $item): ?string
    {
        $metadata = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];
        $candidates = [
            $item['name'] ?? null,
            $metadata['series_title'] ?? null,
            $metadata['title'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }
        return null;
    }

    /**
     * Resolve the item's year from its metadata, or null.
     *
     * @param array<string, mixed> $item Hydrated item.
     */
    private function itemYear(array $item): ?int
    {
        $metadata = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];
        $year = $metadata['year'] ?? null;
        if (is_int($year)) {
            return $year;
        }
        if (is_string($year) && is_numeric($year)) {
            return (int) $year;
        }
        return null;
    }

    /**
     * Build the `context` block for the search response from the hydrated item.
     *
     * All values are derived from the already-hydrated item (no new I/O).
     * String values are capped at MAX_CONTEXT_STRING to prevent unbounded growth.
     *
     * @param array<string, mixed> $item  Hydrated media item.
     * @param string               $query The cleaned query string used for the search.
     *
     * @return array<string, mixed> Context block with only present (non-null) keys.
     */
    private function buildContext(array $item, string $query): array
    {
        $metadata = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];

        $originalFilename = $this->extractString($metadata, 'raw_filename');
        if ($originalFilename === null) {
            $path = $item['path'] ?? null;
            if (is_string($path) && $path !== '') {
                $originalFilename = $this->extractString(['raw_filename' => basename($path)], 'raw_filename');
            }
        }

        $context = [];

        if ($originalFilename !== null) {
            $context['original_filename'] = $originalFilename;
        }

        $path = $item['path'] ?? null;
        if (is_string($path) && $path !== '') {
            $context['path'] = $this->capString($path);
        }

        if ($query !== '') {
            $context['parsed_title'] = $this->capString($query);
        }

        $year = $this->itemYear($item);
        if ($year !== null) {
            $context['year'] = $year;
        }

        $tags = $this->normalizeTags($metadata, is_string($item['type'] ?? null) ? $item['type'] : null);
        if ($tags !== []) {
            $context['tags'] = $tags;
        }

        return $context;
    }

    /**
     * Extract a string from an array and cap it at MAX_CONTEXT_STRING.
     *
     * @param array<string, mixed> $data Source array.
     * @param string               $key  Key to extract.
     *
     * @return string|null Capped string, or null if absent/empty.
     */
    private function extractString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || $value === '') {
            return null;
        }
        return $this->capString($value);
    }

    /**
     * Cap a string at MAX_CONTEXT_STRING characters.
     *
     * @param string $value Raw string value.
     *
     * @return string Capped string.
     */
    private function capString(string $value): string
    {
        if (mb_strlen($value, 'UTF-8') <= self::MAX_CONTEXT_STRING) {
            return $value;
        }
        return mb_substr($value, 0, self::MAX_CONTEXT_STRING, 'UTF-8');
    }

    /**
     * Normalize tags from metadata into the standard tags map.
     *
     * For series/episode items, extracts: title, year, show, season, episode, episode_title.
     * For audio items (with id3/Vorbis tags), extracts those fields.
     * For all other items, returns an empty array (no tags).
     *
     * @param array<string, mixed> $metadata Decoded metadata_json.
     * @param string|null          $type     Item type (e.g. 'series', 'movie', 'audio').
     *
     * @return array<string, mixed> Normalized tags map.
     */
    private function normalizeTags(array $metadata, ?string $type): array
    {
        // Series / episode — extract structured TV metadata fields.
        if ($type === 'series' || $type === 'episode' || $type === '_episode') {
            $tags = [];
            $videoKeys = ['title', 'year', 'show', 'season', 'episode', 'episode_title'];
            foreach ($videoKeys as $k) {
                $v = $this->extractString($metadata, $k);
                if ($v !== null) {
                    $tags[$k] = $v;
                }
            }
            return $tags;
        }

        // Audio — extract id3 / Vorbis tag fields.
        if ($type === 'audio') {
            $tags = [];
            $audioKeys = ['id3', 'Vorbis', 'artist', 'album', 'title', 'track', 'genre',
                'date', 'composer', 'encoded_by', 'copyright', 'comment',
            ];
            foreach ($audioKeys as $k) {
                $v = $this->extractString($metadata, $k);
                if ($v !== null) {
                    $tags[$k] = $v;
                }
            }
            return $tags;
        }

        return [];
    }

    /**
     * Require auth, then admin access. Returns an error Response, or null when OK.
     */
    private function requireAdmin(Request $request): ?Response
    {
        $userId = $request->userId;
        if ($userId === null || $userId === '') {
            return (new Response())->status(401)->json([
                'error' => 'Unauthorized',
                'code' => 'auth.required',
            ]);
        }

        if ($this->adminMiddleware !== null) {
            $status = $this->adminMiddleware->checkAccess($request);
            if ($status !== null) {
                return (new Response())->status($status)->json([
                    'error' => $status === 401 ? 'Unauthorized' : 'Forbidden',
                    'code' => $status === 401 ? 'auth.required' : 'auth.not_admin',
                ]);
            }
        }

        return null;
    }
}
