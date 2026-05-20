<?php

declare(strict_types=1);

namespace Phlix\LiveTv\Tuners\Iptv;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;

/**
 * Parser for M3U/M3U8 playlist files.
 *
 * Parses extended M3U files (such as those used by IPTV providers) containing
 * channel information and stream URLs. Supports:
 * - #EXTINF extended tag parsing
 * - tvg-id, tvg-name, tvg-chno, group-title, tvg-logo attributes
 * - Radio channel detection via radio="1" attribute
 * - HTTP fetching of remote playlists
 *
 * @since 0.12.0
 */
class M3UParser
{
    /** @var LoggerInterface|null Optional logger */
    private ?LoggerInterface $logger;

    /**
     * @param LoggerInterface|null $logger Optional logger instance
     */
    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * Parse an M3U playlist from a string.
     *
     * @param string $content The M3U playlist content
     * @return M3UEntry[] Array of parsed entries
     *
     * @example
     * ```php
     * $parser = new M3UParser();
     * $entries = $parser->parse("#EXTINF:-1 tvg-id=\"1\" tvg-name=\"Channel\",Channel Name\nhttp://example.com/stream.m3u8");
     * ```
     */
    public function parse(string $content): array
    {
        $entries = [];
        $lines = explode("\n", trim($content));
        $i = 0;

        while ($i < count($lines)) {
            $line = trim($lines[$i]);

            // Skip empty lines and headers
            if ($line === '' || $line === '#EXTM3U') {
                $i++;
                continue;
            }

            // Parse extended info line
            if (str_starts_with($line, '#EXTINF:')) {
                $entry = $this->parseExtInfLine($line, $lines[$i + 1] ?? '');
                if ($entry !== null) {
                    $entries[] = $entry;
                    $i += 2;
                    continue;
                }
            }

            // Handle entries without #EXTINF (single line format)
            if (!str_starts_with($line, '#')) {
                $entries[] = new M3UEntry(url: $line);
            }

            $i++;
        }

        $this->logger?->debug('M3UParser: parsed playlist', ['entry_count' => count($entries)]);

        return $entries;
    }

    /**
     * Fetch and parse an M3U playlist from a URL.
     *
     * @param string $url The URL to fetch the playlist from
     * @param int $timeoutSecs Timeout in seconds for the HTTP request (default: 10)
     * @return M3UEntry[] Array of parsed entries
     * @throws \RuntimeException If the URL cannot be fetched
     *
     * @example
     * ```php
     * $parser = new M3UParser();
     * $entries = $parser->parseUrl('https://example.com/playlist.m3u8');
     * ```
     */
    public function parseUrl(string $url, int $timeoutSecs = 10): array
    {
        $this->logger?->info('M3UParser: fetching playlist', ['url' => $url, 'timeout' => $timeoutSecs]);

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeoutSecs,
                'follow_location' => true,
                'max_redirects' => 5,
                'user_agent' => 'Phlix/1.0 (M3U Parser)',
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $content = @file_get_contents($url, false, $context);

        if ($content === false) {
            $error = error_get_last();
            throw new \RuntimeException("Failed to fetch M3U playlist from $url: " . ($error['message'] ?? 'Unknown error'));
        }

        return $this->parse($content);
    }

    /**
     * Parse an #EXTINF line and the associated URL.
     *
     * @param string $extInfLine The #EXTINF line
     * @param string $urlLine The next line containing the URL
     * @return M3UEntry|null Parsed entry or null if URL is invalid
     */
    private function parseExtInfLine(string $extInfLine, string $urlLine): ?M3UEntry
    {
        // Parse #EXTINF:-1 attributes... channel name
        // Format: #EXTINF:-1 tvg-id="1" tvg-name="Name" tvg-chno="5" group-title="Group",Channel Name
        // or: #EXTINF:-1 radio="1" tvg-id="1",Channel Name

        // Extract attributes and channel name
        // Note: Duration can be -1 for radio channels, so we use -?\d+
        if (!preg_match('/^#EXTINF:(-?\d+)\s*(.*)?,(.+)$/', $extInfLine, $matches)) {
            return null;
        }

        $duration = (int) $matches[1];
        $attributesStr = $matches[2];
        $channelName = trim($matches[3]);

        // Skip non-video entries (duration -1 typically means radio or data)
        // But still parse them if they have a valid URL
        $isRadio = str_contains($attributesStr, 'radio="1"') || str_contains($attributesStr, "radio='1'");

        // Parse attributes
        $tvgId = null;
        $tvgName = null;
        $tvgChno = null;
        $groupTitle = null;
        $tvgLogo = null;

        // Match tvg-id="..." or tvg-id='...'
        if (preg_match('/tvg-id=["\']([^"\']+)["\']/', $attributesStr, $m)) {
            $tvgId = (int) $m[1];
        }

        // Match tvg-name="..." or tvg-name='...'
        if (preg_match('/tvg-name=["\']([^"\']+)["\']/', $attributesStr, $m)) {
            $tvgName = $m[1];
        }

        // Match tvg-chno="..." or tvg-chno='...'
        if (preg_match('/tvg-chno=["\']([^"\']+)["\']/', $attributesStr, $m)) {
            $tvgChno = (int) $m[1];
        }

        // Match group-title="..." or group-title='...'
        if (preg_match('/group-title=["\']([^"\']+)["\']/', $attributesStr, $m)) {
            $groupTitle = $m[1];
        }

        // Match tvg-logo="..." or tvg-logo='...'
        if (preg_match('/tvg-logo=["\']([^"\']+)["\']/', $attributesStr, $m)) {
            $tvgLogo = $m[1];
        }

        // Use tvg-name if channel name is just a number
        if ($tvgName !== null && is_numeric($channelName)) {
            $channelName = $tvgName;
        }

        // Clean up channel name (remove quotes if present)
        $channelName = trim($channelName, '"\' ');

        $url = trim($urlLine);

        // Validate URL
        if ($url === '' || str_starts_with($url, '#') || !filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        return new M3UEntry(
            url: $url,
            name: $channelName,
            tvgId: $tvgId,
            tvgChno: $tvgChno,
            group: $groupTitle,
            logo: $tvgLogo,
            isRadio: $isRadio,
        );
    }
}
