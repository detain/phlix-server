<?php

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
    /** @var M3UParser M3U playlist parser */
    private M3UParser $m3uParser;

    /** @var XmlTvParser XMLTV guide data parser */
    private XmlTvParser $xmlTvParser;

    /** @var IptvDevice The IPTV device/source this driver manages */
    private IptvDevice $device;

    /** @var LoggerInterface|null Optional logger */
    private ?LoggerInterface $logger;

    /**
     * @param M3UParser $m3uParser M3U playlist parser
     * @param XmlTvParser $xmlTvParser XMLTV guide data parser
     * @param IptvDevice $device The IPTV device this driver manages
     * @param LoggerInterface|null $logger Optional logger instance
     */
    public function __construct(
        M3UParser $m3uParser,
        XmlTvParser $xmlTvParser,
        IptvDevice $device,
        ?LoggerInterface $logger = null
    ) {
        $this->m3uParser = $m3uParser;
        $this->xmlTvParser = $xmlTvParser;
        $this->device = $device;
        $this->logger = $logger;
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
     * @return array<int, array{channel_number:int, name:string, type:string, transport_stream_id:null, program_id:null}> Channel list
     */
    public function getChannelLineup(HdHomeRunDevice|IptvDevice|DvbtDevice $device): array
    {
        if (!$device instanceof IptvDevice) {
            throw new \InvalidArgumentException('Expected IptvDevice for IPTV tuner');
        }

        $this->logger?->info('IptvTunerDriver: getting channel lineup', [
            'source_id' => $device->sourceId,
        ]);

        $entries = $this->m3uParser->parseUrl($device->playlistUrl);

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
     * @return array<int, array{channel_number:int, name:string, type:string, transport_stream_id:null, program_id:null}> Discovered channels
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
     * @param HdHomeRunDevice|IptvDevice|DvbtDevice $device The device to use
     * @param int $channelNumber The channel number to tune
     * @return string The stream URL
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

        $entries = $this->m3uParser->parseUrl($device->playlistUrl);

        // Find the entry with matching channel number
        foreach ($entries as $entry) {
            if ($entry->tvgChno !== null && $entry->tvgChno === $channelNumber) {
                $this->logger?->debug('IptvTunerDriver: found channel URL', [
                    'channel' => $channelNumber,
                    'url' => $entry->url,
                ]);
                return $entry->url;
            }
        }

        // Fallback: use index-based matching (channel number 1 = first entry)
        $index = $channelNumber - 1;
        if ($index >= 0 && $index < count($entries)) {
            $this->logger?->debug('IptvTunerDriver: found channel URL by index', [
                'channel' => $channelNumber,
                'url' => $entries[$index]->url,
            ]);
            return $entries[$index]->url;
        }

        // Last resort: return first entry
        if (!empty($entries)) {
            $this->logger?->warning('IptvTunerDriver: channel not found, returning first entry', [
                'channel' => $channelNumber,
                'url' => $entries[0]->url,
            ]);
            return $entries[0]->url;
        }

        throw new \RuntimeException("No channels available in playlist for device: {$device->sourceId}");
    }
}
