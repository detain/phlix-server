<?php

declare(strict_types=1);

namespace Phlix\Media\Markers;

use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Markers\Detection\MarkerCandidateRepository;
use Phlix\Media\Markers\Detection\StoredMarkers;

/**
 * Service for managing marker data on media items.
 *
 * Reads markers from the formal marker columns (populated by promoteCandidates)
 * and falls back to metadata_json candidates when formal columns are empty.
 *
 * @since 0.12.0
 */
class MarkerService
{
    /**
     * @param ItemRepository            $item_repo      Item repository for database access
     * @param MarkerCandidateRepository $candidate_repo Repository for reading candidates from metadata_json
     *
     * @since 0.12.0
     */
    public function __construct(
        private readonly ItemRepository $item_repo,
        private readonly MarkerCandidateRepository $candidate_repo,
    ) {
    }

    /**
     * Promote stored detection candidates to the formal marker columns.
     *
     * Reads intro/outro candidates from media_items.metadata_json and writes
     * them to the formal intro_start_seconds, intro_end_seconds, outro_start_seconds,
     * outro_end_seconds columns.
     *
     * @param string $media_item_id The media item ID to promote candidates for
     *
     * @return void
     *
     * @since 0.12.0
     */
    public function promoteCandidates(string $media_item_id): void
    {
        $item = $this->item_repo->findById($media_item_id);

        if ($item === null) {
            return;
        }

        $stored = $this->candidate_repo->getCandidates($media_item_id);

        if ($stored === null) {
            return;
        }

        $updateData = [];

        if ($stored->hasIntro()) {
            $updateData['intro_start_seconds'] = (int) $stored->intro_start_seconds;
            $updateData['intro_end_seconds'] = (int) $stored->intro_end_seconds;
        }

        if ($stored->hasOutro()) {
            $updateData['outro_start_seconds'] = (int) $stored->outro_start_seconds;
            $updateData['outro_end_seconds'] = (int) $stored->outro_end_seconds;
        }

        if (!empty($updateData)) {
            $this->item_repo->update($media_item_id, $updateData);
        }
    }

    /**
     * Store chapter markers for a media item.
     *
     * Converts ChapterMarker DTOs to array format and persists them
     * to the chapters_json column via the item repository.
     *
     * @param string $media_item_id The media item ID
     * @param ChapterMarker[] $chapters Array of chapter markers to store
     *
     * @return void
     *
     * @since 0.12.0
     */
    public function storeChapters(string $media_item_id, array $chapters): void
    {
        $chaptersArray = array_map(
            fn(ChapterMarker $chapter) => $chapter->toArray(),
            $chapters
        );

        $this->item_repo->updateMarkers($media_item_id, [
            'chapters_json' => json_encode($chaptersArray),
        ]);
    }

    /**
     * Get all markers for a media item.
     *
     * Returns a MarkerSet with intro, outro, and chapters. Reads from formal
     * columns first, then falls back to metadata_json candidates if columns
     * are NULL (i.e., item fingerprinted in F.1/F.2 but not yet promoted).
     *
     * @param string $media_item_id The media item ID
     *
     * @return MarkerSet The marker set for the item (may be empty)
     *
     * @since 0.12.0
     */
    public function getMarkers(string $media_item_id): MarkerSet
    {
        $item = $this->item_repo->findById($media_item_id);

        if ($item === null) {
            return MarkerSet::empty();
        }

        // Try formal columns first
        $intro = $this->getIntroFromColumns($item);
        $outro = $this->getOutroFromColumns($item);
        $chapters = $this->getChaptersFromColumns($item);

        // If no formal markers, fall back to candidates in metadata_json
        if ($intro === null && $outro === null) {
            $stored = $this->candidate_repo->getCandidates($media_item_id);
            if ($stored !== null) {
                $intro = $this->introFromStored($stored);
                $outro = $this->outroFromStored($stored);
            }
        }

        if ($intro === null && $outro === null && empty($chapters)) {
            return MarkerSet::empty();
        }

        return new MarkerSet($intro, $outro, $chapters);
    }

    /**
     * Bulk-promote all candidates for a show's episodes.
     *
     * Finds all episode children of the given show and promotes their
     * marker candidates to the formal columns.
     *
     * @param string $show_id The show/series media item ID
     *
     * @return int Count of items that had candidates and were promoted
     *
     * @since 0.12.0
     */
    public function promoteShowMarkers(string $show_id): int
    {
        $episodes = $this->item_repo->findByParent($show_id);

        $count = 0;
        foreach ($episodes as $episode) {
            $rawId = $episode['id'] ?? null;
            if (!is_string($rawId)) {
                continue;
            }
            $episodeId = $rawId;
            $stored = $this->candidate_repo->getCandidates($episodeId);
            if ($stored !== null && ($stored->hasIntro() || $stored->hasOutro())) {
                $this->promoteCandidates($episodeId);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get markers for all episodes of a show.
     *
     * @param string $show_id The show/series media item ID
     *
     * @return array<string, array{id: string, name: string, markers: array<string, mixed>}>
     *         Episode markers keyed by episode ID
     *
     * @since 0.12.0
     */
    public function getShowMarkers(string $show_id): array
    {
        $episodes = $this->item_repo->findByParent($show_id);
        /** @var array<string, array{id: string, name: string, markers: array<string, mixed>}> $result */
        $result = [];

        foreach ($episodes as $episode) {
            $rawId = $episode['id'] ?? null;
            $rawName = $episode['name'] ?? null;
            if (!is_string($rawId)) {
                continue;
            }
            $episodeId = $rawId;
            $episodeName = is_string($rawName) ? $rawName : '';
            $markerSet = $this->getMarkers($episodeId);
            $result[$episodeId] = [
                'id' => $episodeId,
                'name' => $episodeName,
                'markers' => $markerSet->toArray(),
            ];
        }

        return $result;
    }

    /**
     * Extract intro marker from formal database columns.
     *
     * @param array<string, mixed> $item Hydrated media item
     *
     * @return IntroMarker|null Intro marker or null if not set
     *
     * @since 0.12.0
     */
    private function getIntroFromColumns(array $item): ?IntroMarker
    {
        if (!isset($item['intro_start_seconds'], $item['intro_end_seconds'])) {
            return null;
        }

        $startSeconds = $item['intro_start_seconds'];
        $endSeconds = $item['intro_end_seconds'];

        if (!is_numeric($startSeconds) || !is_numeric($endSeconds)) {
            return null;
        }

        return new IntroMarker(
            start_seconds: (int) $startSeconds,
            end_seconds: (int) $endSeconds,
            confidence: is_numeric($item['intro_confidence'] ?? null) ? (int) $item['intro_confidence'] : 100,
        );
    }

    /**
     * Extract outro marker from formal database columns.
     *
     * @param array<string, mixed> $item Hydrated media item
     *
     * @return OutroMarker|null Outro marker or null if not set
     *
     * @since 0.12.0
     */
    private function getOutroFromColumns(array $item): ?OutroMarker
    {
        if (!isset($item['outro_start_seconds'], $item['outro_end_seconds'])) {
            return null;
        }

        $startSeconds = $item['outro_start_seconds'];
        $endSeconds = $item['outro_end_seconds'];

        if (!is_numeric($startSeconds) || !is_numeric($endSeconds)) {
            return null;
        }

        return new OutroMarker(
            start_seconds: (int) $startSeconds,
            end_seconds: (int) $endSeconds,
            confidence: is_numeric($item['outro_confidence'] ?? null) ? (int) $item['outro_confidence'] : 100,
        );
    }

    /**
     * Extract chapter markers from formal database columns.
     *
     * @param array<string, mixed> $item Hydrated media item
     *
     * @return ChapterMarker[] Array of chapter markers (empty if none)
     *
     * @since 0.12.0
     */
    private function getChaptersFromColumns(array $item): array
    {
        if (!isset($item['chapters_json'])) {
            return [];
        }

        $chaptersJson = $item['chapters_json'];
        if (is_string($chaptersJson)) {
            $decoded = json_decode($chaptersJson, true);
            if (!is_array($decoded)) {
                return [];
            }
            $chaptersJson = $decoded;
        }

        if (!is_array($chaptersJson)) {
            return [];
        }

        /** @var ChapterMarker[] $chapters */
        $chapters = [];
        foreach ($chaptersJson as $chapter) {
            if (!is_array($chapter)) {
                continue;
            }
            $start = $chapter['start'] ?? null;
            $end = $chapter['end'] ?? null;
            if (is_numeric($start) && is_numeric($end)) {
                $title = isset($chapter['title']) && is_string($chapter['title']) ? $chapter['title'] : null;
                $chapters[] = new ChapterMarker(
                    start_seconds: (int) $start,
                    end_seconds: (int) $end,
                    title: $title,
                );
            }
        }

        return $chapters;
    }

    /**
     * Build IntroMarker from stored candidate metadata.
     *
     * @param StoredMarkers $stored Stored markers from metadata_json
     *
     * @return IntroMarker|null Intro marker or null if not available
     *
     * @since 0.12.0
     */
    private function introFromStored(StoredMarkers $stored): ?IntroMarker
    {
        if (!$stored->hasIntro()) {
            return null;
        }

        return new IntroMarker(
            start_seconds: (int) $stored->intro_start_seconds,
            end_seconds: (int) $stored->intro_end_seconds,
            confidence: $stored->intro_confidence ?? 100,
        );
    }

    /**
     * Build OutroMarker from stored candidate metadata.
     *
     * @param StoredMarkers $stored Stored markers from metadata_json
     *
     * @return OutroMarker|null Outro marker or null if not available
     *
     * @since 0.12.0
     */
    private function outroFromStored(StoredMarkers $stored): ?OutroMarker
    {
        if (!$stored->hasOutro()) {
            return null;
        }

        return new OutroMarker(
            start_seconds: (int) $stored->outro_start_seconds,
            end_seconds: (int) $stored->outro_end_seconds,
            confidence: $stored->outro_confidence ?? 100,
        );
    }
}
