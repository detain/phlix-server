<?php

declare(strict_types=1);

namespace Phlix\LiveTv\Tuners\Iptv;

/**
 * Immutable value object representing a single M3U playlist entry.
 *
 * Encapsulates the data extracted from an #EXTINF line and its associated URL.
 *
 * @since 0.12.0
 */
final class M3UEntry
{
    /**
     * @param string $url Stream URL for this channel
     * @param string|null $name Display name of the channel
     * @param int|null $tvgId Unique identifier for the channel (tvg-id attribute)
     * @param int|null $tvgChno Channel number (tvg-chno attribute)
     * @param string|null $group Group/category this channel belongs to (group-title attribute)
     * @param string|null $logo URL to channel logo image (tvg-logo attribute)
     * @param bool $isRadio True if this is a radio channel
     */
    public function __construct(
        public readonly string $url,
        public readonly ?string $name = null,
        public readonly ?int $tvgId = null,
        public readonly ?int $tvgChno = null,
        public readonly ?string $group = null,
        public readonly ?string $logo = null,
        public readonly bool $isRadio = false,
    ) {
    }

    /**
     * Get the display name or a fallback.
     *
     * @return string The channel name or 'Unknown Channel' if not set
     */
    public function getName(): string
    {
        return $this->name ?? 'Unknown Channel';
    }

    /**
     * Get the channel number, or 0 if not specified.
     *
     * @return int The channel number
     */
    public function getChannelNumber(): int
    {
        return $this->tvgChno ?? 0;
    }

    /**
     * Convert to array representation.
     *
     * @return array<string, mixed> Array representation of this entry
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'name' => $this->name,
            'tvg_id' => $this->tvgId,
            'tvg_chno' => $this->tvgChno,
            'group' => $this->group,
            'logo' => $this->logo,
            'is_radio' => $this->isRadio,
        ];
    }
}
