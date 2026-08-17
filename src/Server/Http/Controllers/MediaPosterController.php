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
use Phlix\Media\Metadata\Dto\MetadataValue;
use Phlix\Media\Metadata\Exception\TmdbUnconfiguredException;
use Phlix\Media\Metadata\TmdbProvider;
use Phlix\Server\Http\Middleware\AdminMiddleware;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Throwable;

/**
 * Candidate-poster listing and poster-selection endpoints (Step 15.1/15.2).
 *
 * `GET /api/v1/media/{id}/posters` — list available poster candidates for a
 * media item, optionally re-fetching from TMDB when no candidates are stored.
 *
 * `PUT /api/v1/media/{id}/poster` — set the active poster URL on a media item,
 * validating it is among the known candidates (anti-SSRF).
 *
 * Both endpoints are admin-gated.
 *
 * @package Phlix\Server\Http\Controllers
 * @since   0.58.0
 */
class MediaPosterController
{
    /** Maximum posters returned per provider. */
    private const MAX_POSTERS_PER_PROVIDER = 30;

    private ItemRepository $items;

    private TmdbProvider $tmdb;

    private readonly AdminMiddleware $adminMiddleware;

    /**
     * @param ItemRepository $items Media item repository.
     * @param TmdbProvider $tmdb TMDB image provider.
     * @param AdminMiddleware $adminMiddleware Admin gate for BOTH handlers
     *        ({@see self::requireAdmin()}). REQUIRED — see below.
     *
     * ## S323 — the admin gate is a construction-time requirement
     *
     * This used to be `private ?AdminMiddleware $adminMiddleware = null;` filled
     * by an OPTIONAL `setAdminMiddleware()` setter, with
     * {@see self::requireAdmin()} wrapping its decision in
     * `if ($this->adminMiddleware !== null)`. That combination **failed OPEN**: a
     * controller built without the setter returned "authorised" from
     * `requireAdmin()` without any admin decision having been taken, so
     * `GET /api/v1/media/{id}/posters` (which spends the server's TMDB quota and
     * persists the fetched image block) and `PUT /api/v1/media/{id}/poster` (which
     * rewrites `metadata.poster_url`) were reachable by any logged-in user.
     *
     * `requireAdmin()` checks `$request->userId` first, so the fail-open never
     * reached an anonymous caller here (unlike `ThemeMediaController`, S323
     * phase 1). The live wiring did call the setter — on BOTH of this
     * controller's construction paths, `Application::getMediaPosterController()`
     * and `WebPortalRouter` — so the hole was latent. "Latent" is a property of
     * today's wiring, not of the class, and PHP-DI's `autowire()` SKIPS optional
     * parameters, which is how this estate has produced silently-null
     * dependencies before.
     *
     * Making the dependency REQUIRED removes the null state entirely: the gate has
     * no null branch left to take, and a construction that omits it is an
     * `ArgumentCountError` at the `new`, not a security downgrade at request time.
     *
     * Do NOT re-introduce a nullable type, a default value, or a setter.
     * `tests/Unit/Server/Http/Controllers/MediaPosterControllerAdminGateIsStructuralTest.php`
     * fails on any of the three.
     */
    public function __construct(
        ItemRepository $items,
        TmdbProvider $tmdb,
        AdminMiddleware $adminMiddleware
    ) {
        $this->items = $items;
        $this->tmdb = $tmdb;
        $this->adminMiddleware = $adminMiddleware;
    }

    /**
     * `GET /api/v1/media/{id}/posters`.
     *
     * Reads stored `metadata.images.*` from the item. If no stored candidates
     * exist for a provider, re-fetches via TMDB and persists the result back
     * (merge-preserve). Returns all stored candidates, capped at 30 per provider.
     *
     * @param array<string, string> $params Route params; `id` is the item id.
     *
     * @return Response `200 {providers, current}` · `404` missing ·
     *                 `422` TMDB unconfigured · `502` TMDB unreachable ·
     *                 `401`/`403`.
     */
    public function listPosters(Request $request, array $params): Response
    {
        $auth = $this->requireAdmin($request);
        if ($auth !== null) {
            return $auth;
        }

        $item = $this->items->findById($params['id'] ?? '');
        if ($item === null) {
            return (new Response())->status(404)->json(['error' => 'Item not found']);
        }

        /** @var array<string, mixed> $metadata */
        $metadata = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];
        $imagesBlock = is_array($metadata['images'] ?? null) ? $metadata['images'] : [];
        $existingImages = MetadataValue::asAssoc($imagesBlock);

        $providers = [];

        // TMDB: always attempt if the external id is present.
        $externalIds = MetadataValue::asAssoc($metadata['external_ids'] ?? null);
        $tmdbId = MetadataValue::asNullableString($externalIds['tmdb'] ?? null);

        if ($tmdbId !== null) {
            $providerKey = 'tmdb';
            $providerImages = MetadataValue::asAssoc($existingImages[$providerKey] ?? null);
            $posters = MetadataValue::asAssocList($providerImages['posters'] ?? null);

            if ($posters === []) {
                try {
                    $itemId = is_string($item['id'] ?? null) ? $item['id'] : '';
                    $posters = $this->fetchAndPersistImages($itemId, $tmdbId, $metadata);
                } catch (TmdbUnconfiguredException $e) {
                    return (new Response())->status(422)->json([
                        'error' => $e->getMessage(),
                        'code' => TmdbUnconfiguredException::ERROR_CODE,
                    ]);
                } catch (Throwable $e) {
                    return (new Response())->status(502)->json([
                        'error' => 'TMDB request failed',
                        'code' => 'metadata.tmdb_unreachable',
                    ]);
                }
            }

            if ($posters !== []) {
                $providers[] = [
                    'provider' => $providerKey,
                    'posters' => array_slice($posters, 0, self::MAX_POSTERS_PER_PROVIDER),
                ];
            }
        }

        // Re-mint the stored (scan-time signed, now likely expired) internal
        // artwork signature so `current` is always fetchable; external covers and
        // null pass through unchanged. This response bypasses MediaItemShaper.
        $current = is_string($metadata['poster_url'] ?? null) ? $metadata['poster_url'] : null;

        return (new Response())->json([
            'providers' => $providers,
            'current' => \Phlix\Auth\SignedUrl::refreshArtworkUrl($current),
        ]);
    }

    /**
     * Fetch TMDB images for a TMDB ID and persist them into the item's
     * metadata (merge-preserve). Returns an empty array on network failure.
     *
     * @param string               $itemId   Media item UUID.
     * @param string               $tmdbId   TMDB movie or series ID.
     * @param array<string, mixed> $metadata Current metadata array.
     *
     * @return list<array{url: string, url_original: string, width: int, height: int, language: string|null}>
     *         The posters list (may be empty).
     *
     * @throws TmdbUnconfiguredException When TMDB is not configured.
     */
    private function fetchAndPersistImages(string $itemId, string $tmdbId, array $metadata): array
    {
        $images = $this->tmdb->getImages($tmdbId);

        $posters = $images['posters'] ?? [];

        // Persist merge-preserve: keep existing data, merge in new images.
        $existingImages = MetadataValue::asAssoc($metadata['images'] ?? null);
        $newMetadata = array_merge($metadata, [
            'images' => array_merge($existingImages, [
                'tmdb' => $images,
            ]),
        ]);

        $this->items->update($itemId, [
            'metadata_json' => $newMetadata,
        ]);

        /** @var list<array{url: string, url_original: string, width: int, height: int, language: string|null}> */
        return $posters;
    }

    /**
     * `PUT /api/v1/media/{id}/poster` { "poster_url": "https://…" }.
     *
     * Validates the supplied URL is among the item's known poster candidates
     * (anti-SSRF guard) and persists `metadata.poster_url` (merge-preserve),
     * returning the reshaped item.
     *
     * @param array<string, string> $params Route params; `id` is the item id.
     *
     * @return Response `200 {item}` · `400` URL not a known candidate ·
     *                 `404` missing · `401`/`403`.
     */
    public function setPoster(Request $request, array $params): Response
    {
        $auth = $this->requireAdmin($request);
        if ($auth !== null) {
            return $auth;
        }

        $item = $this->items->findById($params['id'] ?? '');
        if ($item === null) {
            return (new Response())->status(404)->json(['error' => 'Item not found']);
        }

        $posterUrlRaw = $request->input('poster_url');
        $posterUrl = is_scalar($posterUrlRaw) ? trim((string) $posterUrlRaw) : '';
        if ($posterUrl === '') {
            return (new Response())->status(400)->json([
                'error' => 'poster_url is required',
                'code' => 'poster.missing_url',
            ]);
        }

        // Anti-SSRF: validate the URL is among the item's known candidates.
        if (!$this->isKnownPosterUrl($item, $posterUrl)) {
            return (new Response())->status(400)->json([
                'error' => 'poster_url is not among the item\'s known poster candidates',
                'code' => 'poster.poster_not_candidate',
            ]);
        }

        /** @var array<string, mixed> $metadata */
        $metadata = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];

        $itemId = is_string($item['id'] ?? null) ? $item['id'] : '';

        $newMetadata = array_merge($metadata, [
            'poster_url' => $posterUrl,
        ]);

        $this->items->update($itemId, [
            'metadata_json' => $newMetadata,
        ]);

        // Reload and shape so the UI gets a fresh, fully-shaped item.
        $fresh = $this->items->findById($itemId) ?? $item;
        $freshId = is_string($fresh['id'] ?? null) ? $fresh['id'] : ($params['id'] ?? '');
        $shaped = MediaItemShaper::shapeDetail($fresh, $this->items->getItemStreams($freshId));

        return (new Response())->json(['item' => $shaped]);
    }

    /**
     * Check whether a poster URL is among the item's known poster candidates
     * from `metadata.images.*`.
     *
     * @param array<string, mixed> $item      The media item (with parsed metadata).
     * @param string              $posterUrl  The URL to validate.
     *
     * @return bool True when the URL matches a known candidate.
     */
    private function isKnownPosterUrl(array $item, string $posterUrl): bool
    {
        /** @var array<string, mixed> $metadata */
        $metadata = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];
        $images = MetadataValue::asAssoc($metadata['images'] ?? null);

        if ($images === []) {
            return false;
        }

        foreach ($images as $providerImages) {
            if (!is_array($providerImages)) {
                continue;
            }
            $posters = MetadataValue::asAssocList($providerImages['posters'] ?? null);
            foreach ($posters as $poster) {
                if (!is_array($poster)) {
                    continue;
                }
                $url = MetadataValue::asString($poster['url'] ?? null);
                if ($url !== '' && $url === $posterUrl) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Require auth, then admin access. Returns an error Response, or null when OK.
     *
     * S323: there is deliberately NO `if ($this->adminMiddleware !== null)` guard
     * here. The middleware is a required constructor dependency
     * ({@see self::$adminMiddleware}), so the check is unconditional and this
     * method can only return `null` — "proceed" — after an admin decision was
     * actually taken. Re-adding a null guard would restore the S323 fail-open.
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

        // Unconditional; see the docblock above.
        $status = $this->adminMiddleware->checkAccess($request);
        if ($status !== null) {
            return (new Response())->status($status)->json([
                'error' => $status === 401 ? 'Unauthorized' : 'Forbidden',
                'code' => $status === 401 ? 'auth.required' : 'auth.not_admin',
            ]);
        }

        return null;
    }
}
