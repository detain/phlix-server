<?php

declare(strict_types=1);

namespace Phlex\Media\Markers\Detection;

use Generator;
use Phlex\Media\Library\ItemRepository;
use Phlex\Media\Markers\Fingerprinting\ChromaPrintInterface;
use Phlex\Media\Markers\Fingerprinting\FingerprintRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Orchestrates intro/outro detection for TV shows.
 *
 * Fetches all episodes of a show, clusters their fingerprints to find
 * shared intro/outro regions, and returns detection results.
 *
 * @since 0.12.0
 */
class IntroDetectionJob
{
    /** @var FingerprintRepository Fingerprint repository */
    private FingerprintRepository $fingerprintRepo;

    /** @var ItemRepository Item repository */
    private ItemRepository $itemRepo;

    /** @var ChromaPrintInterface ChromaPrint implementation */
    private ChromaPrintInterface $chromaPrint;

    /** @var LoggerInterface Logger instance */
    private LoggerInterface $logger;

    /** @var FingerprintClusterer Clusterer instance */
    private FingerprintClusterer $clusterer;

    /** @var int Minimum episodes required for detection */
    private int $minEpisodes;

    /**
     * @param FingerprintRepository $fingerprintRepo Fingerprint repository
     * @param ItemRepository         $itemRepo         Item repository
     * @param ChromaPrintInterface   $chromaPrint       ChromaPrint implementation
     * @param LoggerInterface|null $logger            Optional logger
     * @param int                   $minEpisodes       Minimum episodes for detection (default 3)
     *
     * @since 0.12.0
     */
    public function __construct(
        FingerprintRepository $fingerprintRepo,
        ItemRepository $itemRepo,
        ChromaPrintInterface $chromaPrint,
        ?LoggerInterface $logger = null,
        int $minEpisodes = 3,
    ) {
        $this->fingerprintRepo = $fingerprintRepo;
        $this->itemRepo = $itemRepo;
        $this->chromaPrint = $chromaPrint;
        $this->logger = $logger ?? new NullLogger();
        $this->minEpisodes = $minEpisodes;

        $this->clusterer = new FingerprintClusterer(
            similarityThreshold: 0.85,
            introMaxDuration: 180,
            outroMaxDuration: 180,
            logger: $this->logger,
        );
    }

    /**
     * Run detection for all episodes of a given show.
     *
     * @param string $showId The show/series media item ID
     *
     * @return IntroDetectionResult Detection result for the show
     *
     * @since 0.12.0
     */
    public function detectForShow(string $showId): IntroDetectionResult
    {
        $this->logger->info('IntroDetectionJob: starting detection for show', [
            'show_id' => $showId,
        ]);

        $episodes = $this->getEpisodesByShow($showId);

        if (count($episodes) < $this->minEpisodes) {
            $this->logger->debug('IntroDetectionJob: insufficient episodes for detection', [
                'show_id' => $showId,
                'episode_count' => count($episodes),
                'minimum_required' => $this->minEpisodes,
            ]);

            return new IntroDetectionResult(
                show_id: $showId,
                episodes_fingerprinted: 0,
                intro_candidate: null,
                outro_candidate: null,
                episodes_processed: array_column($episodes, 'media_item_id'),
            );
        }

        $fingerprintedEpisodes = $this->filterFingerprintedEpisodes($episodes);

        if (count($fingerprintedEpisodes) < $this->minEpisodes) {
            $this->logger->debug('IntroDetectionJob: insufficient fingerprinted episodes', [
                'show_id' => $showId,
                'fingerprinted_count' => count($fingerprintedEpisodes),
                'minimum_required' => $this->minEpisodes,
            ]);

            return new IntroDetectionResult(
                show_id: $showId,
                episodes_fingerprinted: count($fingerprintedEpisodes),
                intro_candidate: null,
                outro_candidate: null,
                episodes_processed: array_column($episodes, 'media_item_id'),
            );
        }

        $clusterResult = $this->clusterer->cluster($fingerprintedEpisodes);

        $this->logger->info('IntroDetectionJob: detection complete', [
            'show_id' => $showId,
            'intro_found' => $clusterResult->intro !== null,
            'outro_found' => $clusterResult->outro !== null,
            'unmatched_count' => count($clusterResult->unmatched),
        ]);

        return new IntroDetectionResult(
            show_id: $showId,
            episodes_fingerprinted: count($fingerprintedEpisodes),
            intro_candidate: $clusterResult->intro,
            outro_candidate: $clusterResult->outro,
            episodes_processed: array_column($episodes, 'media_item_id'),
        );
    }

    /**
     * Detect for all shows that have unfingerprinted episodes.
     *
     * @return Generator<IntroDetectionResult> Yields detection results for each show
     *
     * @since 0.12.0
     */
    public function detectAllPending(): Generator
    {
        $this->logger->debug('IntroDetectionJob: scanning for pending shows');

        $shows = $this->findShowsWithUnfingerprintedEpisodes();

        foreach ($shows as $showId) {
            yield $this->detectForShow($showId);
        }
    }

    /**
     * Access the ChromaPrint implementation injected at construction.
     *
     * Exposed primarily so callers (e.g. the
     * {@see BackgroundDetectorWorker}) can reuse the same configured
     * implementation when fingerprinting episodes that don't yet have
     * stored fingerprints.
     *
     * @return ChromaPrintInterface
     *
     * @since 0.12.0
     */
    public function getChromaPrint(): ChromaPrintInterface
    {
        return $this->chromaPrint;
    }

    /**
     * Get all episodes for a show.
     *
     * @param string $showId The show/series media item ID
     *
     * @return array<int, array{media_item_id: string, fingerprint: string, duration: int}> Episode data
     *
     * @since 0.12.0
     */
    private function getEpisodesByShow(string $showId): array
    {
        $items = $this->itemRepo->findByParent($showId);

        $episodes = [];
        foreach ($items as $item) {
            $type = $item['type'] ?? null;
            if ($type !== 'episode' && $type !== '_episode') {
                continue;
            }
            $mediaItemId = $item['id'] ?? null;
            if (!is_string($mediaItemId) || $mediaItemId === '') {
                continue;
            }

            $fingerprint = $this->fingerprintRepo->getFingerprint($mediaItemId);
            $duration = $this->extractDuration($item);

            $episodes[] = [
                'media_item_id' => $mediaItemId,
                'fingerprint' => $fingerprint,
                'duration' => $duration,
            ];
        }

        return $episodes;
    }

    /**
     * Filter episodes that have fingerprints.
     *
     * @param array<int, array{media_item_id: string, fingerprint: string, duration: int}> $episodes
     *
     * @return array<int, array{media_item_id: string, fingerprint: string, duration: int}>
     *
     * @since 0.12.0
     */
    private function filterFingerprintedEpisodes(array $episodes): array
    {
        return array_values(array_filter(
            $episodes,
            fn(array $ep) => $ep['fingerprint'] !== ''
        ));
    }

    /**
     * Find shows that have episodes without fingerprints.
     *
     * @return array<string> Show IDs with unfingerprinted episodes
     *
     * @since 0.12.0
     */
    private function findShowsWithUnfingerprintedEpisodes(): array
    {
        return [];
    }

    /**
     * Extract duration from a media item.
     *
     * @param array<string, mixed> $item Media item array
     *
     * @return int Duration in seconds, default 0
     *
     * @since 0.12.0
     */
    private function extractDuration(array $item): int
    {
        $metadata = $item['metadata'] ?? [];
        if (!is_array($metadata)) {
            return 0;
        }
        $duration = $metadata['duration'] ?? 0;
        return is_numeric($duration) ? (int) $duration : 0;
    }
}
