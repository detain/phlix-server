<?php

declare(strict_types=1);

namespace Phlix\Server\Http\Controllers;

use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Markers\MarkerService;
use Phlix\Media\Markers\SkipButtonSpec;

class MediaItemController
{
    private ItemRepository $itemRepository;
    private MarkerService $markerService;

    public function __construct(ItemRepository $itemRepository, MarkerService $markerService)
    {
        $this->itemRepository = $itemRepository;
        $this->markerService = $markerService;
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

        // Also get streams
        $itemId = is_string($item['id'] ?? null) ? $item['id'] : '';
        $item['streams'] = $this->itemRepository->getItemStreams($itemId);

        return (new Response())->json(['item' => $item]);
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

        return (new Response())->json([
            'item_id' => $itemId,
            'intro_marker' => $introMarker,
            'outro_marker' => $outroMarker,
            'chapters' => $chapters,
            'skip_button_spec' => $skipSpec->toArray(),
        ]);
    }
}
