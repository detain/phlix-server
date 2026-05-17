<?php

declare(strict_types=1);

namespace Phlex\Media\Markers\Detection;

/**
 * Result of clustering fingerprints into intro/outro groups.
 *
 * @since 0.12.0
 */
final class ClusteringResult
{
    /**
     * @param IntroMarkerCandidate|null  $intro      Detected intro cluster or null
     * @param OutroMarkerCandidate|null  $outro     Detected outro cluster or null
     * @param array<string>              $unmatched  Episode IDs that didn't cluster
     *
     * @since 0.12.0
     */
    public function __construct(
        public readonly ?IntroMarkerCandidate $intro,
        public readonly ?OutroMarkerCandidate $outro,
        public readonly array $unmatched,
    ) {
    }

    /**
     * Check if any clusters were found.
     *
     * @return bool True if at least one cluster was found
     *
     * @since 0.12.0
     */
    public function hasClusters(): bool
    {
        return $this->intro !== null || $this->outro !== null;
    }
}
