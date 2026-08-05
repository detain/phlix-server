<?php

/**
 * Phlix media server component: Controllers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\MediaItemShaper;
use Phlix\Media\Library\RatingGate;
use Phlix\Media\Library\StreamProbeBackfill;
use Phlix\Media\Library\StreamTrackShaper;
use Phlix\Media\Markers\MarkerService;
use Phlix\Media\Markers\SkipButtonSpec;
use Phlix\Media\Playback\GaplessPlaybackManager;
use Phlix\Media\Streaming\AbrLadder;
use Phlix\Media\Streaming\Rendition;
use Phlix\Media\Streaming\SourceProfile;
use Phlix\Media\Streaming\Trickplay\TrickplayController;
use Phlix\Media\MarkerService as ChapterMarkerService;
use Phlix\Media\MarkerType;
use Phlix\Media\Music\MusicLibraryService;

class MediaItemController
{
    /**
     * `media_items.type` members that exist purely to group other rows and are
     * never themselves playable.
     *
     * Used by {@see shufflePlay()} to decide what a childless item means. It is
     * deliberately an exclusion list rather than an allow-list of playable
     * leaves: the leaf set is open (`movie`, `episode`, `video`, `track`,
     * `audio`, `book`, `photo`, `audiobook`, …) and grows as new library types
     * land, whereas the container set is small and stable. An allow-list is how
     * this check previously came to 404 on a childless `track`/`book`/`photo`/
     * `audiobook` — it named only `movie` and `audio`.
     *
     * ⚠ **CORRECTED (S97): `album` and `artist` ARE scanner-created.** This comment
     * used to claim the music scanner writes only `track`. It does not —
     * {@see \Phlix\Media\Music\MusicLibraryScanner} mints an `artist` row and an
     * `album` row for every artist/album it indexes, and production carries
     * **4,656 `artist` + 10,966 `album` + 61,105 `track`** rows in `media_items`,
     * matching `music_*` exactly (measured read-only 2026-07-27). Only `music`
     * itself has no rows. They are still listed here because they are containers:
     * a `media_items` album/artist id is NOT streamable, so returning one from
     * shuffle hands the client an id it cannot play.
     *
     * Their children do NOT live in `media_items.parent_id` — S97 settled that the
     * `music_*` tables are the one authoritative music hierarchy and this column is
     * never written for music — so {@see shufflePlay()} resolves them through
     * {@see \Phlix\Media\Music\MusicLibraryService} instead of `findByParent()`.
     *
     * @var list<string>
     */
    private const CONTAINER_TYPES = ['series', 'season', 'music', 'album', 'artist'];

    /**
     * The `media_items.type` members whose hierarchy lives in the `music_*` tables
     * rather than in `media_items.parent_id` (S97).
     *
     * @var list<string>
     */
    private const MUSIC_CONTAINER_TYPES = ['album', 'artist'];

    private ItemRepository $itemRepository;
    private MarkerService $markerService;
    private GaplessPlaybackManager $gaplessManager;
    private TrickplayController $trickplayController;
    private ChapterMarkerService $chapterMarkerService;

    /**
     * Lazy one-shot stream backfill for pre-071 items (see getPlaybackInfo()).
     * Built on first use when not injected (the optional ctor arg is a test seam).
     */
    private ?StreamProbeBackfill $streamBackfill;

    /**
     * Shared parental-control access gate. Null in legacy/no-container contexts,
     * in which case every gate check is a strict no-op (owner-safe).
     */
    private ?RatingGate $ratingGate;

    /**
     * The `music_*` read path (S97), used by {@see shufflePlay()} to turn an album
     * or artist id into playable TRACK ids.
     *
     * Optional, and a null instance degrades music shuffle to the pre-S97 404
     * rather than to a wrong answer: without it there is no way to reach a music
     * container's children at all, because `media_items.parent_id` is never written
     * for music.
     */
    private ?MusicLibraryService $musicLibrary;

    public function __construct(
        ItemRepository $itemRepository,
        MarkerService $markerService,
        GaplessPlaybackManager $gaplessManager,
        TrickplayController $trickplayController,
        ChapterMarkerService $chapterMarkerService,
        ?StreamProbeBackfill $streamBackfill = null,
        ?RatingGate $ratingGate = null,
        ?MusicLibraryService $musicLibrary = null
    ) {
        $this->itemRepository = $itemRepository;
        $this->markerService = $markerService;
        $this->gaplessManager = $gaplessManager;
        $this->trickplayController = $trickplayController;
        $this->chapterMarkerService = $chapterMarkerService;
        $this->streamBackfill = $streamBackfill;
        $this->ratingGate = $ratingGate;
        $this->musicLibrary = $musicLibrary;
    }

    /**
     * Resolve the active profile's parental cap for the current request, or null
     * (the permissive no-op) when the gate is unwired or the profile/account is
     * not capped (owner-safe).
     *
     * 🚨 S235 — an UNIDENTIFIED request is NOT permissive here. It resolves
     * {@see RatingGate::denyAll()}, so every guard below fails closed. That is
     * load-bearing on THIS controller specifically: `getDownload()` is the one
     * gate-carrying handler registered on a PUBLIC route
     * (`Application::loadMediaRoutes()`), and while the filter was null for an
     * anonymous caller the cap was skipped and a signed `/media/{id}/stream` URL
     * was minted for any item id — a parental bypass reachable by simply
     * dropping the Bearer token.
     *
     * @return array{allowedRatings: list<string>, allowUnrated: bool}|null
     */
    private function resolveRatingFilter(Request $request): ?array
    {
        if ($this->ratingGate === null) {
            return null;
        }

        return $this->ratingGate->resolveFilterForUser($request->userId ?? '');
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $libraryId = $params['library_id'] ?? null;
        $type = $request->queryString('type');
        // SECURITY: page size is clamped to PageLimit::MAX server-side. An
        // unclamped `?limit=` here reaches `LIMIT ?` and can OOM the resident
        // Workerman worker that is serving every other user.
        $limit = $request->queryPageSize('limit', 100);
        $offset = $request->queryOffset();

        $filter = $this->resolveRatingFilter($request);

        if ($libraryId) {
            if ($type !== null) {
                $items = $this->itemRepository->getByType(
                    $libraryId,
                    $type,
                    $limit,
                    $offset,
                    $filter['allowedRatings'] ?? null,
                    $filter['allowUnrated'] ?? true
                );
            } else {
                $items = $this->itemRepository->getByLibrary($libraryId, $limit, $offset);
            }
        } else {
            $items = $this->itemRepository->searchFuzzy($request->queryString('q', '') ?? '', $limit);
        }

        // Parental cap: drop over-cap items (by effective rating) for a capped
        // active profile. No-op for the owner / un-capped profile. getByType
        // already caps in SQL; this also covers getByLibrary + fuzzy search.
        if ($filter !== null && $this->ratingGate !== null) {
            $items = $this->ratingGate->filterItems($items, $filter, 'id');
        }

        return (new Response())->json(['items' => $items]);
    }

    /**
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $item = $this->itemRepository->findById($params['id']);

        if (!$item) {
            return (new Response())->status(404)->json(['error' => 'Item not found']);
        }

        // Parental cap: a capped profile requesting an over-cap item (by
        // effective rating) gets a 404 so no detail — and no signed stream_url —
        // is disclosed. No-op for the owner / un-capped profile.
        $filter = $this->resolveRatingFilter($request);
        if ($filter !== null && $this->ratingGate !== null && !$this->ratingGate->isAllowed($item, $filter)) {
            return (new Response())->status(404)->json(['error' => 'Item not found']);
        }

        // Enrich into the public media-item shape (poster URLs, genres, overview,
        // season/episode numbers, …) + streams so the detail/player pages don't
        // render a blank hero. Mirrors WebPortalRouter::getMediaItem().
        $itemId = is_string($item['id'] ?? null) ? $item['id'] : '';
        $shaped = MediaItemShaper::shapeDetail($item, $this->itemRepository->getItemStreams($itemId));

        // Mint a signed direct-play URL (the <video src> can't attach a Bearer
        // header and /media/{id}/stream is gated). Mirrors WebPortalRouter.
        if ($itemId !== '') {
            $shaped['stream_url'] = \Phlix\Auth\SignedUrl::fromEnv()->mint('/media/' . $itemId . '/stream');
        }

        return (new Response())->json(['item' => $shaped]);
    }

    /**
     * @param array<string, string> $params
     */
    public function search(Request $request, array $params): Response
    {
        $query = $request->queryString('q', '') ?? '';

        if ($query === '') {
            return (new Response())->status(400)->json(['error' => 'Query parameter "q" is required']);
        }

        $items = $this->itemRepository->searchFuzzy($query);
        return (new Response())->json(['items' => $items]);
    }

    /**
     * @param array<string, string> $params
     */
    public function recentlyAdded(Request $request, array $params): Response
    {
        $libraryId = $params['library_id'] ?? null;
        // SECURITY: clamped server-side (see index()).
        $limit = $request->queryPageSize('limit', 20);

        if (!$libraryId) {
            return (new Response())->status(400)->json(['error' => 'library_id is required']);
        }

        $items = $this->itemRepository->getRecentlyAdded($libraryId, $limit);
        return (new Response())->json(['items' => $items]);
    }

    /**
     * @param array<string, string> $params
     */
    public function delete(Request $request, array $params): Response
    {
        $item = $this->itemRepository->findById($params['id']);

        if (!$item) {
            return (new Response())->status(404)->json(['error' => 'Item not found']);
        }

        $this->itemRepository->delete($params['id']);

        return (new Response())->json(['message' => 'Item deleted successfully']);
    }

    /**
     * Get playback info including markers and skip-spec for a media item.
     *
     * @param array<string, string> $params
     */
    public function getPlaybackInfo(Request $request, array $params): Response
    {
        $item = $this->itemRepository->findById($params['id']);

        if (!$item) {
            return (new Response())->status(404)->json(['error' => 'Item not found']);
        }

        // Parental cap: an over-cap item (by effective rating) is 404 here too, so
        // a capped profile never gets markers/tracks/signed subtitle URLs. No-op
        // for the owner / un-capped profile.
        $filter = $this->resolveRatingFilter($request);
        if ($filter !== null && $this->ratingGate !== null && !$this->ratingGate->isAllowed($item, $filter)) {
            return (new Response())->status(404)->json(['error' => 'Item not found']);
        }

        $itemId = is_string($item['id'] ?? null) ? $item['id'] : '';
        $markers = $this->markerService->getMarkers($itemId);
        $skipSpec = SkipButtonSpec::fromMarkerSet($markers);

        $introMarker = $markers->intro !== null
            ? ['start_seconds' => $markers->intro->start_seconds, 'end_seconds' => $markers->intro->end_seconds]
            : null;

        $outroMarker = $markers->outro !== null
            ? ['start_seconds' => $markers->outro->start_seconds, 'end_seconds' => $markers->outro->end_seconds]
            : null;

        $chapters = [];
        foreach ($markers->chapters as $chapter) {
            $chapters[] = [
                'start_seconds' => $chapter->start_seconds,
                'end_seconds' => $chapter->end_seconds,
                'title' => $chapter->title,
            ];
        }

        // P3B: audio + subtitle track metadata from media_streams for the
        // player's selection menus. Shaped by the shared StreamTrackShaper so
        // this API path and WebPortalRouter::getPlaybackInfo() (the other
        // dispatch path) emit byte-identical shapes. The lazy backfill runs a
        // ONE-SHOT ffprobe for pre-071 items (≤1 audio row, no subtitle rows)
        // so existing libraries expose their full track set without a rescan.
        $streams = $this->streamBackfill()->ensureFor(
            $item,
            $this->itemRepository->getItemStreams($itemId)
        );
        $audioTracks = StreamTrackShaper::audioTracks($streams);
        $subtitleTracks = StreamTrackShaper::subtitleTracks($streams, $itemId);

        // P7: Include crossfade/gapless preferences for sequential playback
        $userId = $request->userId ?? '';
        $playbackPrefs = $this->gaplessManager->getPreferences($userId);

        // Build the quality ladder preview (url => null for each rung).
        $qualityLadder = $this->buildQualityLadder($item, $request);

        return (new Response())->json([
            'item_id' => $itemId,
            'intro_marker' => $introMarker,
            'outro_marker' => $outroMarker,
            'chapters' => $chapters,
            'skip_button_spec' => $skipSpec->toArray(),
            'quality_ladder' => $qualityLadder,
            'audio_tracks' => $audioTracks,
            'subtitle_tracks' => $subtitleTracks,
            'crossfade' => [
                'enabled' => $playbackPrefs->isCrossfadeEnabled(),
                'duration' => $playbackPrefs->crossfadeDuration,
                'fade_out' => $playbackPrefs->crossfadeFadeOut,
                'fade_in' => $playbackPrefs->crossfadeFadeIn,
            ],
        ]);
    }

    /**
     * Returns the lazy stream backfill, building it on first use when none was
     * injected (mirrors WebPortalRouter::streamBackfill() so both dispatch
     * paths behave identically).
     */
    private function streamBackfill(): StreamProbeBackfill
    {
        return $this->streamBackfill ??= new StreamProbeBackfill($this->itemRepository);
    }

    /**
     * Builds the pre-flight ABR ladder PREVIEW for a media item (D6).
     *
     * Advertisement-only: NO transcode job is created and the source is NOT
     * probed — this is purely the ladder a play would produce, so a pre-flight UI
     * can show "here's the quality you'll get" before the user presses play. It is
     * a DIFFERENT key from a real job's `variants[]` (per-item, not per-job, and
     * nothing is playable yet), so every entry's `url` is `null`.
     *
     * The ladder is built from A1's persisted `metadata_json['source']` blob
     * (already-decoded by {@see ItemRepository::hydrateItem()} — NOT re-decoded
     * here). When that blob is absent or is missing width/height (an item not yet
     * scanned with A1's source-metadata capture, or pre-A1), this returns `null`
     * — graceful degradation, no error. The device profile is resolved exactly as
     * {@see \Phlix\Server\Http\Controllers\TranscodeController::start()} does: an
     * explicit `?profile=` wins, else it is derived from the `X-Phlix-Device-Type`
     * header ({@see self::mapDeviceTypeToProfile()}, a byte-identical mapping to
     * TranscodeController's).
     *
     * @param array<string, mixed> $item    The hydrated media item (with decoded `metadata`).
     * @param Request              $request The current request (for `?profile=` / device header).
     *
     * @return list<array<string, mixed>>|null The flat Rendition-shape ladder
     *                                          (each `url` null), or null when the
     *                                          item has no usable source metadata.
     */
    private function buildQualityLadder(array $item, Request $request): ?array
    {
        $metadata = $item['metadata'] ?? null;
        $source = is_array($metadata) ? ($metadata['source'] ?? null) : null;
        if (!is_array($source)) {
            return null; // pre-A1 / unscanned item — no source metadata to build from
        }

        /** @var array<string, mixed> $source */
        $sourceProfile = SourceProfile::fromSourceMetadata($source);
        // Incomplete source (missing width or height) → graceful null, not an error.
        if ($sourceProfile->width === null || $sourceProfile->height === null) {
            return null;
        }

        $explicit = $request->queryString('profile');
        if (is_string($explicit) && $explicit !== '') {
            $profileName = $explicit;
        } else {
            $profileName = $this->mapDeviceTypeToProfile($request->getHeader('X-Phlix-Device-Type') ?? '');
        }

        $ladder = (new AbrLadder())->build($sourceProfile, $profileName);

        // Preview only: no job exists, so nothing is playable — every url stays null
        // (Rendition::toArray() already yields url => null).
        return array_map(
            static fn (Rendition $rendition): array => $rendition->toArray(),
            $ladder->streamVariants(),
        );
    }

    /**
     * Maps an `X-Phlix-Device-Type` header value to a transcode quality profile.
     *
     * Thin `@phlix/ui` clients advertise their platform via the header but don't
     * pass an explicit `?profile=`; this picks a sensible default profile per
     * platform. The lookup is case-insensitive. Anything unknown or empty falls
     * back to `web` (the historical default). Every arm resolves to a profile
     * defined by {@see \Phlix\Media\Streaming\QualitySelector} (generic,
     * mobile-low, mobile-high, web, tv-4k); any arm added later must keep that
     * invariant — the controller test asserts each mapped profile is known.
     *
     * IMPORTANT: this mapping table MUST stay byte-identical to
     * {@see \Phlix\Server\Http\Controllers\TranscodeController::mapDeviceTypeToProfile()}
     * so the pre-flight `quality_ladder` preview and the real transcode job agree
     * on the device profile. A controller test asserts the two tables are identical.
     *
     * Mapping:
     *   samsung-tizen, tizen, roku → tv-4k
     *   android, ios               → mobile-high
     *   windows                    → generic
     *   (anything else / missing)  → web
     *
     * @param string $deviceType The raw header value (may be empty).
     *
     * @return string A profile name QualitySelector understands.
     */
    private function mapDeviceTypeToProfile(string $deviceType): string
    {
        return match (strtolower(trim($deviceType))) {
            'samsung-tizen', 'tizen', 'roku' => 'tv-4k',
            'android', 'ios' => 'mobile-high',
            'windows' => 'generic',
            default => 'web',
        };
    }

    /**
     * GET /api/v1/media/{id}/trickplay
     *
     * Returns the trickplay sprite and timeline URLs for a media item.
     * These point to the existing /trickplay/{itemId}/ routes.
     *
     * @param Request $request HTTP request
     * @param array<string, string> $params Route params with 'id' key
     *
     * @return Response 200 with {sprite_url, timeline_url}, 404 if not found
     */
    public function getTrickplay(Request $request, array $params): Response
    {
        $itemId = $params['id'] ?? '';

        if ($itemId === '') {
            return (new Response())->status(400)->json(['error' => 'Media item ID is required']);
        }

        $item = $this->itemRepository->findById($itemId);
        if ($item === null) {
            return (new Response())->status(404)->json(['error' => 'Item not found']);
        }

        // Check if trickplay data exists for this item
        $spritePath = is_string($item['trickplay_sprite_path'] ?? null) ? $item['trickplay_sprite_path'] : null;
        $timelinePath = is_string($item['trickplay_timeline_path'] ?? null) ? $item['trickplay_timeline_path'] : null;

        if ($spritePath === null || $timelinePath === null) {
            // Return empty success (not 404) so the UI gracefully handles missing
            // trickplay without logging errors — trickplay may simply not be
            // generated yet for this item, or the feature may be disabled.
            return (new Response())->json([
                'sprite_url' => null,
                'timeline_url' => null,
            ]);
        }

        // Generate URLs using TrickplayController's URL-building methods
        $spriteUrl = $this->trickplayController->getSpriteUrl($itemId);
        $timelineUrl = $this->trickplayController->getTimelineUrl($itemId);

        return (new Response())->json([
            'sprite_url' => $spriteUrl,
            'timeline_url' => $timelineUrl,
        ]);
    }

    /**
     * GET /api/v1/media/{id}/chapters/{index}/thumbnail
     *
     * Returns the thumbnail image for a specific chapter of a media item.
     *
     * @param Request $request HTTP request
     * @param array<string, string> $params Route params with 'id' and 'index' keys
     *
     * @return Response 200 with image content, 404 if not found
     */
    public function getChapterThumbnail(Request $request, array $params): Response
    {
        $itemId = $params['id'] ?? '';
        $indexStr = $params['index'] ?? '';

        if ($itemId === '' || $indexStr === '') {
            return (new Response())->status(400)->json(['error' => 'Media item ID and chapter index are required']);
        }

        $index = (int) $indexStr;
        if ($index < 0) {
            return (new Response())->status(400)->json(['error' => 'Chapter index must be non-negative']);
        }

        // First verify the media item exists
        $item = $this->itemRepository->findById($itemId);
        if ($item === null) {
            return (new Response())->status(404)->json(['error' => 'Item not found']);
        }

        // Get chapters from the item's chapters_json
        $chaptersJson = $item['chapters_json'] ?? null;
        $chapters = [];
        if (is_string($chaptersJson)) {
            $decoded = json_decode($chaptersJson, true);
            if (is_array($decoded)) {
                $chapters = $decoded;
            }
        } elseif (is_array($chaptersJson)) {
            $chapters = $chaptersJson;
        }

        // Validate chapter index
        if (!isset($chapters[$index])) {
            return (new Response())->status(404)->json([
                'error' => 'Not Found',
                'message' => 'Chapter at index ' . $index . ' does not exist',
            ]);
        }

        // Get chapter start time to find the corresponding media_markers entry
        // chapters_json stores start in seconds, but media_markers.start_time_ms is in milliseconds
        /** @var array<string, mixed> $chapter */
        $chapter = $chapters[$index];
        $startSeconds = is_int($chapter['start'] ?? null) ? $chapter['start']
            : (is_numeric($chapter['start'] ?? null) ? (int) ($chapter['start']) : null);

        if ($startSeconds === null) {
            return (new Response())->status(404)->json([
                'error' => 'Not Found',
                'message' => 'Chapter start time is invalid',
            ]);
        }
        $startMs = $startSeconds * 1000;

        // Look up the chapter marker via chapterMarkerService to get the thumbnail_path
        $markers = $this->chapterMarkerService->findByMediaItem($itemId);
        $chapterMarker = null;
        foreach ($markers as $marker) {
            if ($marker->type === MarkerType::Chapter && $marker->startTimeMs === $startMs) {
                $chapterMarker = $marker;
                break;
            }
        }

        if ($chapterMarker === null || $chapterMarker->thumbnailPath === null || $chapterMarker->thumbnailPath === '') {
            return (new Response())->status(404)->json([
                'error' => 'Not Found',
                'message' => 'Chapter thumbnail not found',
            ]);
        }

        $thumbnailPath = $chapterMarker->thumbnailPath;

        // Check if file exists
        if (!file_exists($thumbnailPath)) {
            return (new Response())->status(404)->json([
                'error' => 'Not Found',
                'message' => 'Chapter thumbnail file not found on disk',
            ]);
        }

        // ETag + Last-Modified for browser caching (SV-2.5).
        $fileSize = (int) @filesize($thumbnailPath);
        $mtime = (int) @filemtime($thumbnailPath);
        $etag = '"' . md5((string) $mtime . (string) $fileSize) . '"';
        $lastModified = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';

        // Honor conditional GET: If-None-Match (ETag) or If-Modified-Since.
        $ifNoneMatch = $request->getHeader('If-None-Match');
        $ifModifiedSince = $request->getHeader('If-Modified-Since');

        $etagMatch = $ifNoneMatch !== null && $ifNoneMatch === $etag;
        $notModified = $ifNoneMatch === null && $ifModifiedSince !== null
            && $mtime > 0 && $ifModifiedSince !== ''
            && strtotime($ifModifiedSince) >= $mtime;

        if ($etagMatch || $notModified) {
            return (new Response())
                ->status(304)
                ->header('ETag', $etag)
                ->header('Last-Modified', $lastModified)
                ->header('Cache-Control', 'private, max-age=86400');
        }

        // Determine content type
        $extension = strtolower(pathinfo($thumbnailPath, PATHINFO_EXTENSION));
        $contentType = match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };

        // withFile() streams the file via the Workerman event loop without
        // buffering the whole file in memory. ETag and Last-Modified are set
        // explicitly to ensure identical values on both the event-loop and
        // CGI fallback paths.
        return (new Response())
            ->status(200)
            ->header('Content-Type', $contentType)
            ->header('Accept-Ranges', 'bytes')
            ->header('ETag', $etag)
            ->header('Last-Modified', $lastModified)
            ->header('Cache-Control', 'public, max-age=86400')
            ->withFile($thumbnailPath, 0, $fileSize);
    }

    /**
     * Get download URL or info for a media item.
     *
     * @param Request $request Current request
     * @param array<string, string> $params Path parameters with 'id'
     * @return Response JSON response with download URL/info
     */
    public function getDownload(Request $request, array $params): Response
    {
        $item = $this->itemRepository->findById($params['id']);

        if (!$item) {
            return (new Response())->status(404)->json(['error' => 'Item not found']);
        }

        // Parental cap: never mint a signed download/stream URL for an over-cap
        // item (by effective rating) when a capped profile is active — 404 so no
        // URL is disclosed. No-op for the owner / un-capped profile.
        //
        // 🚨 S235: this route is registered PUBLIC, so `$request->userId` may be
        // absent — and an unidentified caller now resolves a deny-all cap rather
        // than a null one, which 404s it here. The route registration is
        // deliberately left public (asserted intentional by
        // ApplicationPlaybackAuthGateTest); it is the GATE, not the router, that
        // refuses. Practical consequence, stated plainly: an anonymous caller no
        // longer gets a signed URL for ANY item, because the server cannot know
        // which profile is asking and therefore cannot prove the item is under
        // that profile's cap.
        $filter = $this->resolveRatingFilter($request);
        if ($filter !== null && $this->ratingGate !== null && !$this->ratingGate->isAllowed($item, $filter)) {
            return (new Response())->status(404)->json(['error' => 'Item not found']);
        }

        $path = $item['path'] ?? null;
        if (!is_string($path) || $path === '' || !file_exists($path)) {
            return (new Response())->status(404)->json(['error' => 'File not found on disk']);
        }

        $fileSize = (int) filesize($path);
        $filename = basename($path);

        return (new Response())->json([
            'url' => \Phlix\Auth\SignedUrl::fromEnv()->mint('/media/' . $params['id'] . '/stream'),
            'filename' => $filename,
            'size' => $fileSize,
            'content_type' => 'application/octet-stream',
        ]);
    }

    /**
     * Get missing episodes for a series (episodes that don't exist locally).
     *
     * @param Request $request Current request
     * @param array<string, string> $params Path parameters with 'id'
     * @return Response JSON response with list of missing episode numbers/info
     */
    public function getMissingEpisodes(Request $request, array $params): Response
    {
        $item = $this->itemRepository->findById($params['id']);

        if (!$item) {
            return (new Response())->status(404)->json(['error' => 'Item not found']);
        }

        $type = $item['type'] ?? '';
        if ($type !== 'series' && $type !== 'show') {
            return (new Response())->status(400)->json(['error' => 'Item is not a series']);
        }

        $metadataJson = $item['metadata_json'] ?? null;
        if (!is_array($metadataJson)) {
            return (new Response())->json(['missing_episodes' => []]);
        }

        $expectedEpisodes = $metadataJson['episode_count'] ?? null;
        if (!is_int($expectedEpisodes) || $expectedEpisodes <= 0) {
            return (new Response())->json(['missing_episodes' => []]);
        }

        $children = $this->itemRepository->findByParent($params['id']);
        $existingEpisodeNumbers = [];
        foreach ($children as $child) {
            $episodeNumber = $child['episode_number'] ?? null;
            if (is_int($episodeNumber) || is_numeric($episodeNumber)) {
                $existingEpisodeNumbers[(int) $episodeNumber] = true;
            }
        }

        $missingEpisodes = [];
        for ($i = 1; $i <= $expectedEpisodes; $i++) {
            if (!isset($existingEpisodeNumbers[$i])) {
                $missingEpisodes[] = ['episode_number' => $i];
            }
        }

        return (new Response())->json([
            'total_expected' => $expectedEpisodes,
            'total_existing' => count($existingEpisodeNumbers),
            'missing_episodes' => $missingEpisodes,
        ]);
    }

    /**
     * Initiate shuffle-play for a media item.
     *
     * ⚠ **Music does not go through `findByParent()` (S97).** An `album` / `artist`
     * `media_items` row has no children there — S97 settled that
     * `media_items.parent_id` is never written for music and the `music_*` tables
     * are the one authoritative hierarchy — so `findByParent()` returned `[]`, the
     * container check fired, and shuffling an album 404'd. Music containers are
     * resolved through {@see MusicLibraryService} into TRACK `media_items` ids,
     * which are the ids `/media/{id}/stream` can actually play. Returning the
     * album/artist ids themselves (which is what a `parent_id`-based hierarchy
     * would have produced) would hand the client ids it cannot stream.
     *
     * @param Request $request Current request with JSON body containing 'media_id'
     * @param array<string, string> $params Path parameters
     * @return Response JSON response with shuffled episode list
     */
    public function shufflePlay(Request $request, array $params): Response
    {
        $body = $request->body;
        $mediaId = is_string($body['media_id'] ?? null) ? $body['media_id'] : null;

        if ($mediaId === null) {
            return (new Response())->status(400)->json(['error' => 'media_id is required']);
        }

        $item = $this->itemRepository->findById($mediaId);

        if (!$item) {
            return (new Response())->status(404)->json(['error' => 'Item not found']);
        }

        $itemType = is_string($item['type'] ?? null) ? $item['type'] : '';

        if (in_array($itemType, self::MUSIC_CONTAINER_TYPES, true)) {
            return $this->shuffleMusicContainer($request, $mediaId, $itemType);
        }

        $children = $this->itemRepository->findByParent($mediaId);

        if (empty($children)) {
            // A childless item is playable on its own unless it is a pure
            // grouping type, in which case there is genuinely nothing to play.
            if ($itemType !== '' && !in_array($itemType, self::CONTAINER_TYPES, true)) {
                return (new Response())->json([
                    'shuffled_ids' => [$mediaId],
                    'mode' => 'single',
                ]);
            }
            return (new Response())->status(404)->json(['error' => 'No playable items found']);
        }

        $ids = array_column($children, 'id');
        shuffle($ids);

        return (new Response())->json([
            'shuffled_ids' => $ids,
            'mode' => 'shuffle',
        ]);
    }

    /**
     * Shuffle-play an `album` / `artist` by resolving it to TRACK ids via `music_*`.
     *
     * The parental cap is applied to the resolved tracks with the SAME gate the
     * `findByParent()` branch uses, so a capped profile cannot reach a track by
     * shuffling its album — the music path must not be a hole in the cap just
     * because it reads a different table.
     *
     * @param string $mediaId The album/artist `media_items` UUID.
     * @param 'album'|'artist'|string $type Its `media_items.type`.
     */
    private function shuffleMusicContainer(Request $request, string $mediaId, string $type): Response
    {
        if ($this->musicLibrary === null) {
            return (new Response())->status(404)->json(['error' => 'No playable items found']);
        }

        $trackIds = $type === 'album'
            ? $this->musicLibrary->getTrackMediaItemIdsForAlbum($mediaId)
            : $this->musicLibrary->getTrackMediaItemIdsForArtist($mediaId);

        if ($trackIds === []) {
            return (new Response())->status(404)->json(['error' => 'No playable items found']);
        }

        $filter = $this->resolveRatingFilter($request);
        if ($filter !== null && $this->ratingGate !== null) {
            $tracks = $this->ratingGate->filterItems(
                $this->itemRepository->findByIds($trackIds),
                $filter,
                'id'
            );
            $trackIds = [];
            foreach ($tracks as $track) {
                $trackId = $track['id'] ?? null;
                if (is_string($trackId) && $trackId !== '') {
                    $trackIds[] = $trackId;
                }
            }

            if ($trackIds === []) {
                return (new Response())->status(404)->json(['error' => 'No playable items found']);
            }
        }

        shuffle($trackIds);

        return (new Response())->json([
            'shuffled_ids' => $trackIds,
            'mode' => 'shuffle',
        ]);
    }

    /**
     * Update media item metadata (title, summary, etc.).
     *
     * @param Request $request Current request with JSON body
     * @param array<string, string> $params Path parameters with 'id'
     * @return Response JSON response with updated item
     */
    public function updateMetadata(Request $request, array $params): Response
    {
        $item = $this->itemRepository->findById($params['id']);

        if (!$item) {
            return (new Response())->status(404)->json(['error' => 'Item not found']);
        }

        $body = $request->body;
        if (!is_array($body)) {
            return (new Response())->status(400)->json(['error' => 'Request body must be JSON object']);
        }

        $updateData = [];

        if (isset($body['title']) && is_string($body['title'])) {
            $updateData['title'] = trim($body['title']);
        }

        if (isset($body['summary']) && is_string($body['summary'])) {
            $summary = trim($body['summary']);
            $metadataJson = is_array($item['metadata_json'] ?? null) ? $item['metadata_json'] : [];
            $metadataJson['summary'] = $summary;
            $updateData['metadata_json'] = $metadataJson;
        }

        if (isset($body['overview']) && is_string($body['overview'])) {
            $overview = trim($body['overview']);
            $existingMeta = $updateData['metadata_json'] ?? null;
            $itemMeta = $item['metadata_json'] ?? null;
            $metadataJson = is_array($existingMeta) ? $existingMeta
                : (is_array($itemMeta) ? $itemMeta : []);
            $metadataJson['overview'] = $overview;
            $updateData['metadata_json'] = $metadataJson;
        }

        if (isset($body['metadata_json']) && is_array($body['metadata_json'])) {
            $existingMetadata = is_array($item['metadata_json'] ?? null) ? $item['metadata_json'] : [];
            $updateData['metadata_json'] = array_merge($existingMetadata, $body['metadata_json']);
        }

        if (empty($updateData)) {
            return (new Response())->status(400)->json(['error' => 'No valid fields to update']);
        }

        $this->itemRepository->update($params['id'], $updateData);

        $updatedItem = $this->itemRepository->findById($params['id']);

        return (new Response())->json(['item' => $updatedItem]);
    }
}
