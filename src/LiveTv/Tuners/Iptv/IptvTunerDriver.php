<?php

/**
 * Phlix media server component: Iptv.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\LiveTv\Tuners\Iptv;

use Phlix\LiveTv\Tuners\Dvbt\DvbtDevice;
use Phlix\LiveTv\Tuners\HdHomeRun\HdHomeRunDevice;
use Phlix\LiveTv\Tuners\TunerDriverInterface;
use Psr\Log\LoggerInterface;

/**
 * IPTV tuner driver implementing TunerDriverInterface.
 *
 * This driver ingests M3U playlists (HTTP-fetched .m3u8 files containing
 * channel URLs) and optional XMLTV guide data, making IPTV streams available
 * alongside HDHomeRun/DVB-T tuners in the unified LiveTvManager pipeline.
 *
 * @since 0.12.0
 */
class IptvTunerDriver implements TunerDriverInterface
{
    /** Default TTL for the parsed-playlist cache, in seconds. */
    public const DEFAULT_PLAYLIST_CACHE_TTL_SECS = 300;

    /** @var M3UParser M3U playlist parser */
    private M3UParser $m3uParser;

    /** @var XmlTvParser XMLTV guide data parser */
    private XmlTvParser $xmlTvParser;

    /** @var IptvDevice The IPTV device/source this driver manages */
    private IptvDevice $device;

    /** @var LoggerInterface|null Optional logger */
    private ?LoggerInterface $logger;

    /**
     * Parsed-playlist TTL cache keyed by playlist URL.
     *
     * Before this cache EVERY tune re-fetched (and re-parsed) the entire
     * playlist synchronously inside the worker — a multi-MB fetch on the path
     * of each channel change. Process-local by design: the IPTV drivers live in
     * the long-lived tuner/HTTP workers, so each worker process holds its own
     * copy (up to `count` duplicates); entries are cheap (parsed value
     * objects) and staleness is bounded by the TTL.
     *
     * @var array<string, array{entries: M3UEntry[], fetchedAt: int}>
     */
    private array $playlistCache = [];

    /** @var int Parsed-playlist cache TTL in seconds */
    private int $playlistCacheTtlSecs;

    /** @var bool Strict stream policy re-asserted at tune time (see StreamUrlGuard) */
    private bool $strictStreamPolicy;

    /**
     * @param M3UParser $m3uParser M3U playlist parser
     * @param XmlTvParser $xmlTvParser XMLTV guide data parser
     * @param IptvDevice $device The IPTV device this driver manages
     * @param LoggerInterface|null $logger Optional logger instance
     * @param int|null $playlistCacheTtlSecs Parsed-playlist cache TTL (null = 300s default)
     * @param bool $strictStreamPolicy Re-assert {@see StreamUrlGuard} in strict mode at tune time
     */
    public function __construct(
        M3UParser $m3uParser,
        XmlTvParser $xmlTvParser,
        IptvDevice $device,
        ?LoggerInterface $logger = null,
        ?int $playlistCacheTtlSecs = null,
        bool $strictStreamPolicy = false
    ) {
        $this->m3uParser = $m3uParser;
        $this->xmlTvParser = $xmlTvParser;
        $this->device = $device;
        $this->logger = $logger;
        $this->playlistCacheTtlSecs = max(0, $playlistCacheTtlSecs ?? self::DEFAULT_PLAYLIST_CACHE_TTL_SECS);
        $this->strictStreamPolicy = $strictStreamPolicy;
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'iptv';
    }

    /**
     * @inheritDoc
     *
     * For IPTV, returns the single configured device.
     *
     * @return IptvDevice[] Array containing the IPTV device
     */
    public function discoverDevices(): array
    {
        $this->logger?->info('IptvTunerDriver: discovering devices');

        if ($this->device->isEnabled) {
            return [$this->device];
        }

        return [];
    }

    /**
     * @inheritDoc
     *
     * Parses the M3U playlist and returns the channel lineup.
     *
     * @param HdHomeRunDevice|IptvDevice|DvbtDevice $device The device to query
     * @return array<int, array{channel_number:int, name:string, type:string, transport_stream_id:null,
     * program_id:null}> Channel list
     */
    public function getChannelLineup(HdHomeRunDevice|IptvDevice|DvbtDevice $device): array
    {
        if (!$device instanceof IptvDevice) {
            throw new \InvalidArgumentException('Expected IptvDevice for IPTV tuner');
        }

        $this->logger?->info('IptvTunerDriver: getting channel lineup', [
            'source_id' => $device->sourceId,
        ]);

        $entries = $this->loadEntries($device->playlistUrl);

        $lineup = [];
        foreach ($entries as $index => $entry) {
            $lineup[] = [
                'channel_number' => $entry->tvgChno ?? ($index + 1),
                'name' => $entry->getName(),
                'type' => $entry->isRadio ? 'radio' : 'off',
                'transport_stream_id' => null,
                'program_id' => null,
            ];
        }

        $this->logger?->info('IptvTunerDriver: lineup parsed', [
            'source_id' => $device->sourceId,
            'channel_count' => count($lineup),
        ]);

        return $lineup;
    }

    /**
     * @inheritDoc
     *
     * Parses the M3U playlist and optionally refreshes EPG data from XMLTV.
     *
     * @param HdHomeRunDevice|IptvDevice|DvbtDevice $device The device to scan
     * @return array<int, array{channel_number:int, name:string, type:string, transport_stream_id:null,
     * program_id:null}> Discovered channels
     */
    public function scanChannels(HdHomeRunDevice|IptvDevice|DvbtDevice $device): array
    {
        if (!$device instanceof IptvDevice) {
            throw new \InvalidArgumentException('Expected IptvDevice for IPTV tuner');
        }

        $this->logger?->info('IptvTunerDriver: scanning channels', [
            'source_id' => $device->sourceId,
        ]);

        // Same as getChannelLineup - M3U is the source of truth for channels
        // EPG data is handled separately via XMLTV
        $lineup = $this->getChannelLineup($device);

        // If EPG URL is configured, fetch and parse XMLTV data
        if ($device->epgUrl !== null) {
            $this->logger?->info('IptvTunerDriver: fetching EPG data', [
                'source_id' => $device->sourceId,
                'epg_url' => $device->epgUrl,
            ]);

            try {
                $programmes = $this->xmlTvParser->parseUrl($device->epgUrl);
                $this->logger?->info('IptvTunerDriver: EPG data fetched', [
                    'source_id' => $device->sourceId,
                    'programme_count' => count($programmes),
                ]);
            } catch (\Throwable $e) {
                $this->logger?->warning('IptvTunerDriver: failed to fetch EPG', [
                    'source_id' => $device->sourceId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $lineup;
    }

    /**
     * @inheritDoc
     *
     * Returns the stream URL for the specified channel number.
     *
     * The selected URL is re-asserted against {@see StreamUrlGuard} before it
     * is handed out (tune-time gate): parse-time filtering covers the playlist
     * that was parsed, but the guard here is the last wall before the string
     * reaches `ffmpeg -i`, a player redirect, or a persisted recording row —
     * cheap, deterministic, and independent of when the entries were built.
     *
     * @param HdHomeRunDevice|IptvDevice|DvbtDevice $device The device to use
     * @param int $channelNumber The channel number to tune
     * @return string The stream URL
     * @throws \RuntimeException When the tuned entry violates the stream policy
     */
    public function getStreamUrl(HdHomeRunDevice|IptvDevice|DvbtDevice $device, int $channelNumber): string
    {
        if (!$device instanceof IptvDevice) {
            throw new \InvalidArgumentException('Expected IptvDevice for IPTV tuner');
        }

        $this->logger?->info('IptvTunerDriver: getting stream URL', [
            'source_id' => $device->sourceId,
            'channel' => $channelNumber,
        ]);

        $entries = $this->loadEntries($device->playlistUrl);

        // Find the entry with matching channel number
        foreach ($entries as $entry) {
            if ($entry->tvgChno !== null && $entry->tvgChno === $channelNumber) {
                return $this->gateSelectedStreamUrl($entry->url, $channelNumber, 'chno-match');
            }
        }

        // Fallback: use index-based matching (channel number 1 = first entry)
        $index = $channelNumber - 1;
        if ($index >= 0 && $index < count($entries)) {
            return $this->gateSelectedStreamUrl($entries[$index]->url, $channelNumber, 'index-match');
        }

        // Last resort: return first entry
        if (!empty($entries)) {
            $this->logger?->warning('IptvTunerDriver: channel not found, returning first entry', [
                'channel' => $channelNumber,
            ]);
            return $this->gateSelectedStreamUrl($entries[0]->url, $channelNumber, 'first-entry-fallback');
        }

        throw new \RuntimeException("No channels available in playlist for device: {$device->sourceId}");
    }

    /**
     * Tune-time jail for a playlist-supplied URL, then hand it out.
     *
     * @throws \RuntimeException When the URL fails {@see StreamUrlGuard}.
     */
    private function gateSelectedStreamUrl(string $url, int $channelNumber, string $matchKind): string
    {
        try {
            StreamUrlGuard::assertTunable($url, $this->strictStreamPolicy);
        } catch (\RuntimeException $e) {
            $this->logger?->error('IptvTunerDriver: refusing to tune a playlist entry', [
                'source_id' => $this->device->sourceId,
                'channel' => $channelNumber,
                'match' => $matchKind,
                'reason' => $e->getMessage(),
            ]);
            throw $e;
        }

        $this->logger?->debug('IptvTunerDriver: found channel URL', [
            'channel' => $channelNumber,
            'match' => $matchKind,
        ]);

        return $url;
    }

    /**
     * Playlist entries for this device's configured URL, TTL-cached (F-4).
     *
     * The pre-fix code re-fetched the ENTIRE playlist on every lineup read and
     * every tune. Parsing stays synchronous (it runs on operator/admin
     * actions, not the media hot path) but is now bounded once per TTL.
     *
     * @return M3UEntry[]
     */
    private function loadEntries(string $playlistUrl): array
    {
        $now = time();
        $cached = $this->playlistCache[$playlistUrl] ?? null;

        if ($cached !== null && ($now - $cached['fetchedAt']) < $this->playlistCacheTtlSecs) {
            return $cached['entries'];
        }

        $entries = $this->m3uParser->parseUrl($playlistUrl);
        $this->playlistCache[$playlistUrl] = ['entries' => $entries, 'fetchedAt' => $now];

        return $entries;
    }

    /**
     * Drop all cached playlists (e.g. after the operator edits the source).
     *
     * @return void
     */
    public function clearPlaylistCache(): void
    {
        $this->playlistCache = [];
    }
}
