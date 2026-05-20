<?php

declare(strict_types=1);

namespace Phlix\Media\Markers\Detection;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Clusters audio fingerprints to detect intro and outro segments.
 *
 * Uses Jaccard similarity on fingerprint strings to group episodes
 * that share common intro (first N seconds) or outro (last M seconds)
 * audio content.
 *
 * @since 0.12.0
 */
class FingerprintClusterer
{
    /** @var float Jaccard similarity threshold for a match */
    private float $similarityThreshold;

    /** @var int Maximum duration in seconds to consider for intro detection */
    private int $introMaxDuration;

    /** @var int Maximum duration in seconds to consider for outro detection */
    private int $outroMaxDuration;

    /** @var LoggerInterface Logger instance */
    private LoggerInterface $logger;

    /**
     * @param float          $similarityThreshold Jaccard threshold (0.0–1.0), default 0.85
     * @param int            $introMaxDuration     Max intro duration in seconds, default 180
     * @param int            $outroMaxDuration     Max outro duration in seconds, default 180
     * @param LoggerInterface|null $logger        Optional logger
     *
     * @since 0.12.0
     */
    public function __construct(
        float $similarityThreshold = 0.85,
        int $introMaxDuration = 180,
        int $outroMaxDuration = 180,
        ?LoggerInterface $logger = null,
    ) {
        $this->similarityThreshold = $similarityThreshold;
        $this->introMaxDuration = $introMaxDuration;
        $this->outroMaxDuration = $outroMaxDuration;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Cluster episodes by fingerprint similarity for intro/outro detection.
     *
     * @param array<int, array{media_item_id: string, fingerprint: string, duration: int}> $episodes
     *
     * @return ClusteringResult
     *
     * @since 0.12.0
     */
    public function cluster(array $episodes): ClusteringResult
    {
        if (count($episodes) < 2) {
            $this->logger->debug('FingerprintClusterer: insufficient episodes for clustering', [
                'count' => count($episodes),
            ]);
            return new ClusteringResult(null, null, array_column($episodes, 'media_item_id'));
        }

        $introGroup = $this->findIntroGroup($episodes);
        $outroGroup = $this->findOutroGroup($episodes);

        $unmatched = array_column($episodes, 'media_item_id');
        if ($introGroup !== null) {
            $unmatched = array_diff($unmatched, array_column($introGroup['episodes'], 'media_item_id'));
        }
        if ($outroGroup !== null) {
            $unmatched = array_diff($unmatched, array_column($outroGroup['episodes'], 'media_item_id'));
        }

        $this->logger->debug('FingerprintClusterer: clustering complete', [
            'intro_found' => $introGroup !== null,
            'outro_found' => $outroGroup !== null,
            'unmatched_count' => count($unmatched),
        ]);

        return new ClusteringResult(
            intro: $introGroup ? $this->buildIntroCandidate($introGroup) : null,
            outro: $outroGroup ? $this->buildOutroCandidate($outroGroup) : null,
            unmatched: array_values($unmatched),
        );
    }

    /**
     * Calculate Jaccard similarity between two fingerprint strings.
     *
     * Treats fingerprints as sets of characters and computes the intersection
     * over union. Returns a float between 0.0 (completely dissimilar) and
     * 1.0 (identical).
     *
     * @param string $fpA First fingerprint
     * @param string $fpB Second fingerprint
     *
     * @return float Similarity score 0.0–1.0
     *
     * @since 0.12.0
     */
    private function similarity(string $fpA, string $fpB): float
    {
        if ($fpA === '' || $fpB === '') {
            return 0.0;
        }

        if ($fpA === $fpB) {
            return 1.0;
        }

        // Use mode 1 to get only characters present in the string
        $setA = count_chars($fpA, 1);
        $setB = count_chars($fpB, 1);

        // Count intersection by finding common character codes
        $intersection = 0;
        $keysA = array_keys($setA);
        $keysB = array_keys($setB);

        foreach ($keysA as $char) {
            if (in_array($char, $keysB, true)) {
                $intersection += min($setA[$char], $setB[$char]);
            }
        }

        $union = strlen($fpA) + strlen($fpB) - $intersection;

        if ($union === 0) {
            return 0.0;
        }

        return $intersection / $union;
    }

    /**
     * Find the intro group among episodes.
     *
     * @param array<int, array{media_item_id: string, fingerprint: string, duration: int}> $episodes
     *
     * @return array{episodes: list<array{media_item_id: string, fingerprint: string}>, representative_fingerprint: string, confidence: int}|null
     *
     * @since 0.12.0
     */
    private function findIntroGroup(array $episodes): ?array
    {
        $candidates = [];

        foreach ($episodes as $episode) {
            $candidates[] = [
                'media_item_id' => $episode['media_item_id'],
                'fingerprint' => $episode['fingerprint'],
            ];
        }

        return $this->findLargestSimilarGroup($candidates);
    }

    /**
     * Find the outro group among episodes.
     *
     * @param array<int, array{media_item_id: string, fingerprint: string, duration: int}> $episodes
     *
     * @return array{episodes: list<array{media_item_id: string, fingerprint: string}>, representative_fingerprint: string, confidence: int}|null
     *
     * @since 0.12.0
     */
    private function findOutroGroup(array $episodes): ?array
    {
        $candidates = [];

        foreach ($episodes as $episode) {
            $candidates[] = [
                'media_item_id' => $episode['media_item_id'],
                'fingerprint' => $episode['fingerprint'],
            ];
        }

        return $this->findLargestSimilarGroup($candidates);
    }

    /**
     * Find the largest group of similar fingerprints.
     *
     * @param array<int, array{media_item_id: string, fingerprint: string}> $candidates
     *
     * @return array{episodes: list<array{media_item_id: string, fingerprint: string}>, representative_fingerprint: string, confidence: int}|null
     *
     * @since 0.12.0
     */
    private function findLargestSimilarGroup(array $candidates): ?array
    {
        $n = count($candidates);
        if ($n < 2) {
            return null;
        }

        $bestGroup = null;
        $bestSize = 1;

        for ($i = 0; $i < $n; $i++) {
            $group = [$candidates[$i]];
            $representative = $candidates[$i]['fingerprint'];

            for ($j = 0; $j < $n; $j++) {
                if ($i === $j) {
                    continue;
                }

                $sim = $this->similarity($representative, $candidates[$j]['fingerprint']);
                if ($sim >= $this->similarityThreshold) {
                    $group[] = $candidates[$j];
                }
            }

            if (count($group) > $bestSize) {
                $bestSize = count($group);
                $bestGroup = $group;
                $representative = $this->selectRepresentativeFingerprint($group);
            }
        }

        if ($bestGroup === null || $bestSize < 2) {
            return null;
        }

        $confidence = $this->calculateGroupConfidence($bestGroup);

        return [
            'episodes' => $bestGroup,
            'representative_fingerprint' => $representative,
            'confidence' => $confidence,
        ];
    }

    /**
     * Select the most representative fingerprint from a group.
     *
     * Chooses the fingerprint with the highest average similarity to all others.
     *
     * @param array<int, array{media_item_id: string, fingerprint: string}> $group
     *
     * @return string The representative fingerprint
     *
     * @since 0.12.0
     */
    private function selectRepresentativeFingerprint(array $group): string
    {
        if (count($group) === 1) {
            return $group[0]['fingerprint'];
        }

        $bestFingerprint = $group[0]['fingerprint'];
        $bestTotalSimilarity = 0.0;

        foreach ($group as $candidate) {
            $totalSim = 0.0;
            foreach ($group as $other) {
                if ($candidate['media_item_id'] === $other['media_item_id']) {
                    continue;
                }
                $totalSim += $this->similarity($candidate['fingerprint'], $other['fingerprint']);
            }

            if ($totalSim > $bestTotalSimilarity) {
                $bestTotalSimilarity = $totalSim;
                $bestFingerprint = $candidate['fingerprint'];
            }
        }

        return $bestFingerprint;
    }

    /**
     * Calculate confidence score for a group.
     *
     * Based on group size relative to total and average similarity.
     *
     * @param array<int, array{media_item_id: string, fingerprint: string}> $group
     *
     * @return int Confidence 0–100
     *
     * @since 0.12.0
     */
    private function calculateGroupConfidence(array $group): int
    {
        if (count($group) < 2) {
            return 0;
        }

        $totalSim = 0.0;
        $n = count($group);
        $pairCount = 0;

        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $totalSim += $this->similarity($group[$i]['fingerprint'], $group[$j]['fingerprint']);
                $pairCount++;
            }
        }

        $avgSimilarity = $pairCount > 0 ? $totalSim / $pairCount : 0.0;

        $sizeScore = min($n / 3, 1.0) * 50;
        $similarityScore = $avgSimilarity * 50;

        return (int)min(100, $sizeScore + $similarityScore);
    }

    /**
     * Build an IntroMarkerCandidate from a group.
     *
     * @param array{episodes: list<array{media_item_id: string, fingerprint: string}>, representative_fingerprint: string, confidence: int} $group
     *
     * @return IntroMarkerCandidate
     *
     * @since 0.12.0
     */
    private function buildIntroCandidate(array $group): IntroMarkerCandidate
    {
        return new IntroMarkerCandidate(
            start_seconds: 0,
            end_seconds: $this->introMaxDuration,
            fingerprint: $group['representative_fingerprint'],
            confidence: $group['confidence'],
        );
    }

    /**
     * Build an OutroMarkerCandidate from a group.
     *
     * @param array{episodes: list<array{media_item_id: string, fingerprint: string}>, representative_fingerprint: string, confidence: int} $group
     *
     * @return OutroMarkerCandidate
     *
     * @since 0.12.0
     */
    private function buildOutroCandidate(array $group): OutroMarkerCandidate
    {
        $startSeconds = 0;
        $endSeconds = $this->outroMaxDuration;

        return new OutroMarkerCandidate(
            start_seconds: $startSeconds,
            end_seconds: $endSeconds,
            fingerprint: $group['representative_fingerprint'],
            confidence: $group['confidence'],
        );
    }
}
