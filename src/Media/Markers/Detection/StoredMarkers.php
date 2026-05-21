<?php

declare(strict_types=1);

namespace Phlix\Media\Markers\Detection;

/**
 * Stored marker candidates for an episode.
 *
 * @since 0.12.0
 */
final class StoredMarkers
{
    /**
     * @param int|null    $intro_start_seconds Intro start time in seconds or null
     * @param int|null    $intro_end_seconds   Intro end time in seconds or null
     * @param string|null $intro_fingerprint   Intro fingerprint or null
     * @param int|null    $intro_confidence    Intro confidence or null
     * @param int|null    $outro_start_seconds Outro start time in seconds or null
     * @param int|null    $outro_end_seconds   Outro end time in seconds or null
     * @param string|null $outro_fingerprint   Outro fingerprint or null
     * @param int|null    $outro_confidence    Outro confidence or null
     *
     * @since 0.12.0
     */
    public function __construct(
        public readonly ?int $intro_start_seconds,
        public readonly ?int $intro_end_seconds,
        public readonly ?string $intro_fingerprint,
        public readonly ?int $intro_confidence,
        public readonly ?int $outro_start_seconds,
        public readonly ?int $outro_end_seconds,
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
        $introCandidate = is_array($metadata['intro_candidate'] ?? null) ? $metadata['intro_candidate'] : null;
        $outroCandidate = is_array($metadata['outro_candidate'] ?? null) ? $metadata['outro_candidate'] : null;

        if ($introCandidate === null && $outroCandidate === null) {
            return null;
        }

        $introStartSeconds = null;
        $introEndSeconds = null;
        $introFingerprint = null;
        $introConfidence = null;
        $outroStartSeconds = null;
        $outroEndSeconds = null;
        $outroFingerprint = null;
        $outroConfidence = null;

        if ($introCandidate !== null) {
            $introStartSeconds = is_int($introCandidate['start_seconds'] ?? null) ? $introCandidate['start_seconds'] : null;
            $introEndSeconds = is_int($introCandidate['end_seconds'] ?? null) ? $introCandidate['end_seconds'] : null;
            $introFingerprint = is_string($introCandidate['fingerprint'] ?? null) ? $introCandidate['fingerprint'] : null;
            $introConfidence = is_int($introCandidate['confidence'] ?? null) ? $introCandidate['confidence'] : null;
        }

        if ($outroCandidate !== null) {
            $outroStartSeconds = is_int($outroCandidate['start_seconds'] ?? null) ? $outroCandidate['start_seconds'] : null;
            $outroEndSeconds = is_int($outroCandidate['end_seconds'] ?? null) ? $outroCandidate['end_seconds'] : null;
            $outroFingerprint = is_string($outroCandidate['fingerprint'] ?? null) ? $outroCandidate['fingerprint'] : null;
            $outroConfidence = is_int($outroCandidate['confidence'] ?? null) ? $outroCandidate['confidence'] : null;
        }

        return new self(
            intro_start_seconds: $introStartSeconds,
            intro_end_seconds: $introEndSeconds,
            intro_fingerprint: $introFingerprint,
            intro_confidence: $introConfidence,
            outro_start_seconds: $outroStartSeconds,
            outro_end_seconds: $outroEndSeconds,
            outro_fingerprint: $outroFingerprint,
            outro_confidence: $outroConfidence,
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
