<?php

declare(strict_types=1);

namespace Phlix\Media\Metadata\ThemeMusic;

/**
 * Immutable, validated view of the `config/theme_music.php` array.
 *
 * Built once at container time via {@see self::fromArray()} (which coerces + range
 * -checks every field), then injected into {@see ThemeMusicResolver} and the
 * item-level stream controller. Keeps the raw config-array narrowing out of the
 * services so they stay PHPStan-level-9 clean without repeated `is_string(...)`
 * guards.
 *
 * @since 0.66.0
 */
final class ThemeMusicConfig
{
    public const SOURCE_LOCAL_THEN_PLEX = 'local_then_plex';
    public const SOURCE_LOCAL_ONLY = 'local_only';
    public const SOURCE_OFF = 'off';

    /**
     * @param bool   $enabled         Master gate.
     * @param string $source          One of the SOURCE_* constants.
     * @param string $plexArchiveBase Base URL of the Plex theme archive (no trailing slash).
     * @param string $cacheDir        Directory fetched themes are cached to (no trailing slash).
     * @param int    $fetchTimeout    Outbound fetch timeout in seconds (> 0).
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly string $source,
        public readonly string $plexArchiveBase,
        public readonly string $cacheDir,
        public readonly int $fetchTimeout,
    ) {
    }

    /**
     * Build from the raw `config/theme_music.php` array, coercing + defaulting
     * every field. Unknown/invalid values fall back to safe defaults rather than
     * throwing (a bad config must not crash a worker).
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $enabled = ($config['enabled'] ?? true) === true;

        $sourceRaw = $config['source'] ?? self::SOURCE_LOCAL_THEN_PLEX;
        $source = is_string($sourceRaw) ? strtolower(trim($sourceRaw)) : '';
        if (!in_array($source, [self::SOURCE_LOCAL_THEN_PLEX, self::SOURCE_LOCAL_ONLY, self::SOURCE_OFF], true)) {
            $source = self::SOURCE_LOCAL_THEN_PLEX;
        }

        $baseRaw = $config['plex_archive_base'] ?? null;
        $base = is_string($baseRaw) && $baseRaw !== '' ? rtrim($baseRaw, '/') : 'https://tvthemes.plexapp.com';

        $cacheRaw = $config['cache_dir'] ?? null;
        $cacheDir = is_string($cacheRaw) && $cacheRaw !== ''
            ? rtrim($cacheRaw, '/')
            : rtrim(sys_get_temp_dir(), '/') . '/phlix-theme-music';

        $timeoutRaw = $config['fetch_timeout_seconds'] ?? null;
        $timeout = (is_int($timeoutRaw) && $timeoutRaw > 0) ? $timeoutRaw : 8;

        return new self($enabled, $source, $base, $cacheDir, $timeout);
    }

    /** Whether the resolver should produce ANY theme url at all. */
    public function isActive(): bool
    {
        return $this->enabled && $this->source !== self::SOURCE_OFF;
    }

    /** Whether the Plex-archive fallback is permitted (series-only, caller-gated). */
    public function allowsPlexFallback(): bool
    {
        return $this->isActive() && $this->source === self::SOURCE_LOCAL_THEN_PLEX;
    }
}
