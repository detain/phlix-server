<?php

/**
 * Phlix media server component: Playback preferences.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Playback;

/**
 * Playback preferences DTO encapsulating gapless playback and crossfade settings.
 *
 * This immutable value object carries the user's crossfade preferences as
 * propagated from the client through the API and into the GaplessPlayer.
 *
 * @author Phlix Development Team
 * @version 1.0.0
 * @description Crossfade and gapless playback preference values
 *
 * @property-read int    $crossfadeDuration Crossfade duration in seconds (0 = off)
 * @property-read float  $crossfadeFadeOut  Fade-out fraction (0.0–1.0) of crossfade_duration
 * @property-read float  $crossfadeFadeIn   Fade-in fraction (0.0–1.0) of crossfade_duration
 */
final readonly class PlaybackPreferences
{
    /**
     * Minimum allowed crossfade duration in seconds.
     */
    public const int MIN_CROSSFADE_DURATION = 0;

    /**
     * Maximum allowed crossfade duration in seconds (5 minutes).
     */
    public const int MAX_CROSSFADE_DURATION = 300;

    /**
     * Minimum fade fraction.
     */
    public const float MIN_FADE_FRACTION = 0.0;

    /**
     * Maximum fade fraction.
     */
    public const float MAX_FADE_FRACTION = 1.0;

    /**
     * Default fade fraction when not specified.
     */
    public const float DEFAULT_FADE_FRACTION = 0.3;

    /**
     * Create PlaybackPreferences from raw values, clamping each to its valid range.
     *
     * @param mixed $crossfadeDuration Crossfade duration in seconds (0 = off), int|float|null or numeric string
     * @param mixed $crossfadeFadeOut  Fade-out fraction (0.0–1.0)
     * @param mixed $crossfadeFadeIn   Fade-in fraction (0.0–1.0)
     *
     * @return self New immutable instance with clamped values
     */
    public static function fromRaw(
        mixed $crossfadeDuration = null,
        mixed $crossfadeFadeOut = null,
        mixed $crossfadeFadeIn = null
    ): self {
        $duration = self::clampDuration(self::toNumericIntOrNull($crossfadeDuration) ?? 0);
        $fadeOut = self::clampFadeFraction(self::toNumericFloatOrNull($crossfadeFadeOut) ?? self::DEFAULT_FADE_FRACTION);
        $fadeIn = self::clampFadeFraction(self::toNumericFloatOrNull($crossfadeFadeIn) ?? self::DEFAULT_FADE_FRACTION);

        return new self($duration, $fadeOut, $fadeIn);
    }

    /**
     * Convert mixed input to int|null for duration values.
     *
     * @param mixed $value Raw value
     *
     * @return int|null Converted int value or null if not valid
     */
    private static function toNumericIntOrNull(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        return null;
    }

    /**
     * Convert mixed input to float|null for fade fraction values.
     *
     * @param mixed $value Raw value
     *
     * @return float|null Converted value or null if not valid
     */
    private static function toNumericFloatOrNull(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }
        if (is_float($value)) {
            return $value;
        }
        if (is_int($value)) {
            return (float) $value;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        return null;
    }

    /**
     * Create PlaybackPreferences from environment/config values.
     *
     * @param array{
     *     crossfade_duration?: int|float,
     *     crossfade_fade_out?: float,
     *     crossfade_fade_in?: float
     * } $config Config array from config/playback.php
     */
    public static function fromConfig(array $config): self
    {
        return self::fromRaw(
            $config['crossfade_duration'] ?? 0,
            isset($config['crossfade_fade_out']) ? (float) $config['crossfade_fade_out'] : null,
            isset($config['crossfade_fade_in']) ? (float) $config['crossfade_fade_in'] : null
        );
    }

    /**
     * Private constructor — use factory methods.
     *
     * @param int   $crossfadeDuration Crossfade duration in seconds
     * @param float $crossfadeFadeOut  Fade-out fraction
     * @param float $crossfadeFadeIn   Fade-in fraction
     */
    private function __construct(
        public int $crossfadeDuration,
        public float $crossfadeFadeOut,
        public float $crossfadeFadeIn
    ) {
    }

    /**
     * Whether crossfade is enabled (duration > 0).
     */
    public function isCrossfadeEnabled(): bool
    {
        return $this->crossfadeDuration > 0;
    }

    /**
     * The duration in seconds for the fade-out phase.
     *
     * Computed as: crossfade_duration × crossfadeFadeOut
     */
    public function fadeOutDuration(): float
    {
        return (float) $this->crossfadeDuration * $this->crossfadeFadeOut;
    }

    /**
     * The duration in seconds for the fade-in phase.
     *
     * Computed as: crossfade_duration × crossfadeFadeIn
     */
    public function fadeInDuration(): float
    {
        return (float) $this->crossfadeDuration * $this->crossfadeFadeIn;
    }

    /**
     * Clamp duration to MIN_CROSSFADE_DURATION..MAX_CROSSFADE_DURATION range.
     *
     * @param int|float $value Raw duration value
     *
     * @return int Clamped duration in seconds
     */
    private static function clampDuration(int|float $value): int
    {
        $intVal = (int) $value;
        return max(self::MIN_CROSSFADE_DURATION, min(self::MAX_CROSSFADE_DURATION, $intVal));
    }

    /**
     * Clamp fade fraction to MIN_FADE_FRACTION..MAX_FADE_FRACTION range.
     *
     * @param float $value Raw fraction value
     *
     * @return float Clamped fraction
     */
    private static function clampFadeFraction(float $value): float
    {
        return max(self::MIN_FADE_FRACTION, min(self::MAX_FADE_FRACTION, $value));
    }

    /**
     * Convert to array for JSON serialization or API responses.
     *
     * @return array{crossfadeDuration: int, crossfadeFadeOut: float, crossfadeFadeIn: float}
     */
    public function toArray(): array
    {
        return [
            'crossfadeDuration' => $this->crossfadeDuration,
            'crossfadeFadeOut' => $this->crossfadeFadeOut,
            'crossfadeFadeIn' => $this->crossfadeFadeIn,
        ];
    }
}
