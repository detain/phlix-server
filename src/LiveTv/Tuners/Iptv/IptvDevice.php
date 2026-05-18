<?php

declare(strict_types=1);

namespace Phlex\LiveTv\Tuners\Iptv;

/**
 * Immutable descriptor for an IPTV source.
 *
 * Represents a configured IPTV source with its M3U playlist URL
 * and optional XMLTV guide data URL.
 *
 * @since 0.12.0
 */
final class IptvDevice
{
    /**
     * @param string $sourceId Unique identifier for this IPTV source
     * @param string $name Human-readable name for this source
     * @param string $playlistUrl URL to the M3U playlist file
     * @param string|null $epgUrl Optional URL to XMLTV guide data
     * @param bool $isEnabled Whether this source is enabled
     */
    public function __construct(
        public readonly string $sourceId,
        public readonly string $name,
        public readonly string $playlistUrl,
        public readonly ?string $epgUrl = null,
        public readonly bool $isEnabled = true,
    ) {
    }

    /**
     * Check if this device has EPG data configured.
     *
     * @return bool True if an EPG URL is set
     */
    public function hasEpd(): bool
    {
        return $this->epgUrl !== null;
    }

    /**
     * Convert to array representation.
     *
     * @return array<string, mixed> Array representation
     */
    public function toArray(): array
    {
        return [
            'source_id' => $this->sourceId,
            'name' => $this->name,
            'playlist_url' => $this->playlistUrl,
            'epg_url' => $this->epgUrl,
            'is_enabled' => $this->isEnabled,
        ];
    }
}
