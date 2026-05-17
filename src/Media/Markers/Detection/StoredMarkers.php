<?php

declare(strict_types=1);

namespace Phlex\Media\Markers\Detection;

/**
 * Stored marker candidates for an episode.
 *
 * @since 0.12.0
 */
final class StoredMarkers
{
    /**
     * @param string|null                $intro_start_seconds Intro start time or null
     * @param string|null                $intro_end_seconds   Intro end time or null
     * @param string|null                $intro_fingerprint   Intro fingerprint or null
     * @param int|null                   $intro_confidence    Intro confidence or null
     * @param string|null                $outro_start_seconds Outro start time or null
     * @param string|null                $outro_end_seconds   Outro end time or null
     * @param string|null                $outro_fingerprint  Outro fingerprint or null
     * @param int|null                   $outro_confidence    Outro confidence or null
     *
     * @since 0.12.0
     */
    public function __construct(
        public readonly ?string $intro_start_seconds,
        public readonly ?string $intro_end_seconds,
        public readonly ?string $intro_fingerprint,
        public readonly ?int $intro_confidence,
        public readonly ?string $outro_start_seconds,
        public readonly ?string $outro_end_seconds,
        public readonly ?string $outro_fingerprint,
        public readonly ?int $outro_confidence,
    ) {
    }

    /**
     * Create from metadata array.
     *
     * @param array<string, mixed> $metadata Metadata array
     *
     * @return self|null StoredMarkers or null if no markers stored
     *
     * @since 0.12.0
     */
    public static function fromMetadata(array $metadata): ?self
    {
        $introCandidate = $metadata['intro_candidate'] ?? null;
        $outroCandidate = $metadata['outro_candidate'] ?? null;

        if ($introCandidate === null && $outroCandidate === null) {
            return null;
        }

        return new self(
            intro_start_seconds: isset($introCandidate['start_seconds'])
                ? (string) $introCandidate['start_seconds']
                : null,
            intro_end_seconds: isset($introCandidate['end_seconds'])
                ? (string) $introCandidate['end_seconds']
                : null,
            intro_fingerprint: $introCandidate['fingerprint'] ?? null,
            intro_confidence: isset($introCandidate['confidence'])
                ? (int) $introCandidate['confidence']
                : null,
            outro_start_seconds: isset($outroCandidate['start_seconds'])
                ? (string) $outroCandidate['start_seconds']
                : null,
            outro_end_seconds: isset($outroCandidate['end_seconds'])
                ? (string) $outroCandidate['end_seconds']
                : null,
            outro_fingerprint: $outroCandidate['fingerprint'] ?? null,
            outro_confidence: isset($outroCandidate['confidence'])
                ? (int) $outroCandidate['confidence']
                : null,
        );
    }

    /**
     * Check if this has an intro marker.
     *
     * @return bool True if intro marker is present
     *
     * @since 0.12.0
     */
    public function hasIntro(): bool
    {
        return $this->intro_start_seconds !== null
            && $this->intro_end_seconds !== null;
    }

    /**
     * Check if this has an outro marker.
     *
     * @return bool True if outro marker is present
     *
     * @since 0.12.0
     */
    public function hasOutro(): bool
    {
        return $this->outro_start_seconds !== null
            && $this->outro_end_seconds !== null;
    }
}
