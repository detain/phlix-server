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

class MediaItemController
{
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

    public function __construct(
        ItemRepository $itemRepository,
        MarkerService $markerService,
        GaplessPlaybackManager $gaplessManager,
        TrickplayController $trickplayController,
        ChapterMarkerService $chapterMarkerService,
        ?StreamProbeBackfill $streamBackfill = null
    ) {
        $this->itemRepository = $itemRepository;
        $this->markerService = $markerService;
        $this->gaplessManager = $gaplessManager;
        $this->trickplayController = $trickplayController;
        $this->chapterMarkerService = $chapterMarkerService;
        $this->streamBackfill = $streamBackfill;
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $libraryId = $params['library_id'] ?? null;
        $type = $request->queryString('type');
        $limit = $request->queryInt('limit', 100);
        $offset = $request->queryInt('offset', 0);

        if ($libraryId) {
            if ($type !== null) {
                $items = $this->itemRepository->getByType($libraryId, $type, $limit, $offset);
            } else {
                $items = $this->itemRepository->getByLibrary($libraryId, $limit, $offset);
            }
        } else {
            $items = $this->itemRepository->searchFuzzy($request->queryString('q', '') ?? '', $limit);
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
    public function children(Request $request, array $params): Response
    {
        $children = $this->itemRepository->findByParent($params['id']);
        return (new Response())->json(['items' => $children]);
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
        $limit = $request->queryInt('limit', 20);

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

        return (new Response())->json([
            'item_id' => $itemId,
            'intro_marker' => $introMarker,
            'outro_marker' => $outroMarker,
            'chapters' => $chapters,
            'skip_button_spec' => $skipSpec->toArray(),
            'quality_ladder' => $this->buildQualityLadder($item, $request),
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

        $children = $this->itemRepository->findByParent($mediaId);

        if (empty($children)) {
            $type = $item['type'] ?? '';
            if ($type === 'movie' || $type === 'audio') {
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
