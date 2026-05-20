<?php

declare(strict_types=1);

namespace Phlix\Media\Markers\Detection;

use Phlix\Media\Library\ItemRepository;

/**
 * Repository for storing and retrieving marker candidates on media items.
 *
 * Persists intro/outro detection candidates in the media_items metadata_json
 * column, avoiding schema changes at this stage.
 *
 * @since 0.12.0
 */
class MarkerCandidateRepository
{
    /** @var ItemRepository Item repository for database access */
    private ItemRepository $itemRepo;

    /** Metadata key for storing intro candidate */
    public const INTRO_CANDIDATE_KEY = 'intro_candidate';

    /** Metadata key for storing outro candidate */
    public const OUTRO_CANDIDATE_KEY = 'outro_candidate';

    /**
     * Creates a new MarkerCandidateRepository.
     *
     * @param ItemRepository $itemRepo The item repository
     *
     * @since 0.12.0
     */
    public function __construct(ItemRepository $itemRepo)
    {
        $this->itemRepo = $itemRepo;
    }

    /**
     * Store intro/outro candidates on all episodes of a show.
     *
     * @param string                $showId  The show/series media item ID
     * @param IntroDetectionResult   $result  The detection result to store
     *
     * @return void
     *
     * @since 0.12.0
     */
    public function storeCandidates(string $showId, IntroDetectionResult $result): void
    {
        foreach ($result->episodes_processed as $episodeId) {
            $this->storeCandidateOnEpisode($episodeId, $result);
        }
    }

    /**
     * Store candidates on a single episode.
     *
     * @param string               $episodeId Episode media item ID
     * @param IntroDetectionResult  $result    Detection result to store
     *
     * @return void
     *
     * @since 0.12.0
     */
    private function storeCandidateOnEpisode(string $episodeId, IntroDetectionResult $result): void
    {
        $item = $this->itemRepo->findById($episodeId);

        if ($item === null) {
            return;
        }

        /** @var array<string, mixed> $metadata */
        $metadata = $item['metadata'] ?? [];

        if ($result->intro_candidate !== null) {
            $metadata[self::INTRO_CANDIDATE_KEY] = [
                'start_seconds' => $result->intro_candidate->start_seconds,
                'end_seconds' => $result->intro_candidate->end_seconds,
                'fingerprint' => $result->intro_candidate->fingerprint,
                'confidence' => $result->intro_candidate->confidence,
            ];
        }

        if ($result->outro_candidate !== null) {
            $metadata[self::OUTRO_CANDIDATE_KEY] = [
                'start_seconds' => $result->outro_candidate->start_seconds,
                'end_seconds' => $result->outro_candidate->end_seconds,
                'fingerprint' => $result->outro_candidate->fingerprint,
                'confidence' => $result->outro_candidate->confidence,
            ];
        }

        $this->itemRepo->update($episodeId, [
            'metadata_json' => $metadata,
        ]);
    }

    /**
     * Load stored candidates for an episode.
     *
     * @param string $mediaItemId The media item ID
     *
     * @return StoredMarkers|null Stored markers or null if none found
     *
     * @since 0.12.0
     */
    public function getCandidates(string $mediaItemId): ?StoredMarkers
    {
        $item = $this->itemRepo->findById($mediaItemId);

        if ($item === null) {
            return null;
        }

        /** @var array<string, mixed> $metadata */
        $metadata = $item['metadata'] ?? [];

        return StoredMarkers::fromMetadata($metadata);
    }

    /**
     * Check if an episode has marker candidates.
     *
     * @param string $mediaItemId The media item ID
     *
     * @return bool True if candidates exist
     *
     * @since 0.12.0
     */
    public function hasCandidates(string $mediaItemId): bool
    {
        $stored = $this->getCandidates($mediaItemId);
        return $stored !== null && ($stored->hasIntro() || $stored->hasOutro());
    }
}
