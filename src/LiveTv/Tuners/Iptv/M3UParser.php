<?php

/**
 * Phlix media server component: Iptv.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\LiveTv\Tuners\Iptv;

use Phlix\LiveTv\BoundedBodyReader;
use Psr\Log\LoggerInterface;

/**
 * Parser for M3U/M3U8 playlist files.
 *
 * Parses extended M3U files (such as those used as IPTV provider deliverables)
 * containing channel information and stream URLs. Supports:
 * - #EXTINF extended tag parsing
 * - tvg-id, tvg-name, tvg-chno, group-title, tvg-logo attributes
 * - Radio channel detection via radio="1" attribute
 * - HTTP fetching of remote playlists
 *
 * ## Playlist content is UNTRUSTED input (F-1)
 *
 * Every entry URL passes {@see StreamUrlGuard::isTunable()}: a `file://` or
 * metadata-IP entry is dropped at parse time (with a warning) and never becomes
 * a channel stream URL. The same guard is re-asserted at tune time inside
 * {@see IptvTunerDriver::getStreamUrl()} because playlist content can rotate
 * between the two.
 *
 * ## Bounded fetch (F-4)
 *
 * {@see parseUrl()} reads at most `maxBytes + 1` bytes through the shared
 * {@see BoundedBodyReader} cap mechanism and throws
 * {@see M3UPlaylistOversizedException} past the limit — the exact
 * {@see XmlTvParser::parseUrl()} idiom — so one hostile playlist endpoint
 * cannot make a Workerman worker buffer an unbounded body.
 *
 * @since 0.12.0
 */
class M3UParser
{
    /**
     * Default cap on bytes read from a remote M3U URL. M3U is line text —
     * even a six-figure-channel playlist stays well under 8 MiB; anything
     * larger is either hostile or not an M3U file.
     */
    public const DEFAULT_MAX_BYTES = 8 * 1024 * 1024; // 8 MiB

    /** @var LoggerInterface|null Optional logger */
    private ?LoggerInterface $logger;

    /** Maximum number of bytes to read from a remote M3U URL. */
    private int $maxBytes;

    /**
     * URL-body fetch seam: callable(string $url, int $timeoutSecs, int
     * $maxBytes): string returning AT MOST $maxBytes + 1 bytes and throwing
     * \RuntimeException when the URL cannot be fetched. Defaults to the
     * stream-context fetch below; injectable so tests never hit the network
     * and can exercise the oversize path deterministically.
     *
     * @var callable(string, int, int): string
     */
    private $bodyFetcher;

    /** Strict stream policy forwarded to {@see StreamUrlGuard} (DNS + full private deny). */
    private bool $strictStreamPolicy;

    /**
     * @param LoggerInterface|null $logger Optional logger instance
     * @param int|null $maxBytes Maximum playlist download size in bytes (null = config / default)
     * @param callable(string, int, int): string|null $bodyFetcher Fetch seam (null = real HTTP fetch)
     * @param bool $strictStreamPolicy Apply {@see StreamUrlGuard} strict mode (blocking DNS)
     */
    public function __construct(
        ?LoggerInterface $logger = null,
        ?int $maxBytes = null,
        ?callable $bodyFetcher = null,
        bool $strictStreamPolicy = false,
    ) {
        $this->logger = $logger;
        $iptv = $this->loadIptvConfig();
        $this->maxBytes = $maxBytes ?? (is_int($iptv['playlist_max_bytes'] ?? null)
            ? (int) $iptv['playlist_max_bytes']
            : self::DEFAULT_MAX_BYTES);
        $this->bodyFetcher = $bodyFetcher ?? self::fetchViaStream(...);
        $this->strictStreamPolicy = $strictStreamPolicy;
    }

    /**
     * Return the configured maximum download size in bytes.
     */
    public function getMaxBytes(): int
    {
        return $this->maxBytes;
    }

    /**
     * Load the LiveTV `iptv` config block, falling back to defaults.
     *
     * @return array<string, mixed>
     */
    private function loadIptvConfig(): array
    {
        $configPath = defined('PHLIX_CONFIG_PATH') ? PHLIX_CONFIG_PATH : __DIR__ . '/../../../../config';
        $configFile = $configPath . '/livetv.php';
        if (is_file($configFile)) {
            /** @var array<string, mixed> $config */
            $config = include $configFile;
            $iptv = $config['iptv'] ?? null;
            if (is_array($iptv)) {
                /** @var array<string, mixed> $iptv */
                return $iptv;
            }
        }
        return [];
    }

    /**
     * Parse an M3U playlist from a string.
     *
     * Entries whose stream URL fails {@see StreamUrlGuard::isTunable()} are
     * dropped with a warning — the playlist body is third-party, rotating
     * content and NEVER a source of fetchable-scheme instructions.
     *
     * @param string $content The M3U playlist content
     * @return M3UEntry[] Array of parsed entries
     *
     * @example
     * ```php
     * $parser = new M3UParser();
     * $entries = $parser->parse("#EXTINF:-1 tvg-id=\"1\" tvg-name=\"Channel\",Channel
     * Name\nhttp://example.com/stream.m3u8");
     * ```
     */
    public function parse(string $content): array
    {
        $entries = [];
        $lines = explode("\n", trim($content));
        $i = 0;
        $dropped = 0;

        while ($i < count($lines)) {
            $line = trim($lines[$i]);

            // Skip empty lines and headers
            if ($line === '' || $line === '#EXTM3U') {
                $i++;
                continue;
            }

            // Parse extended info line
            if (str_starts_with($line, '#EXTINF:')) {
                $parsed = $this->parseExtInfLine($line, $lines[$i + 1] ?? '');
                if ($parsed['entry'] !== null) {
                    $entries[] = $parsed['entry'];
                    $i += 2;
                    continue;
                }
                $dropped++;
                // A refused EXTINF pair is ONE dropped entry. When the pair
                // died on its URL line the refusal was reached against that
                // exact line here; re-testing it as a bare entry below
                // counted the same drop twice. A malformed TAG line (or a
                // URL line the single-line path would still accept) never
                // consumes: comma-less "#EXTINF:-1 Name" playlists rely on
                // the fallback below picking up the URL that follows.
                $i += $parsed['urlRefused'] ? 2 : 1;
                continue;
            }

            // Handle entries without #EXTINF (single line format)
            if (!str_starts_with($line, '#')) {
                if ($this->acceptsStreamUrl($line)) {
                    $entries[] = new M3UEntry(url: $line);
                } else {
                    $dropped++;
                }
            }

            $i++;
        }

        if ($dropped > 0) {
            $this->logger?->warning('M3UParser: dropped entries failing the stream URL policy', [
                'dropped' => $dropped,
            ]);
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
     * @throws \RuntimeException If the URL fails the stream policy or cannot be fetched
     * @throws M3UPlaylistOversizedException If the body exceeds the configured byte cap
     *
     * @example
     * ```php
     * $parser = new M3UParser();
     * $entries = $parser->parseUrl('https://example.com/playlist.m3u8');
     * ```
     */
    public function parseUrl(string $url, int $timeoutSecs = 10): array
    {
        $this->logger?->info('M3UParser: fetching playlist', [
            'url' => $url,
            'timeout' => $timeoutSecs,
            'max_bytes' => $this->maxBytes,
        ]);

        // The playlist URL itself gets the same jail as its content: a
        // rotated/hostile config value must not dial file:///etc/passwd either.
        StreamUrlGuard::assertTunable($url, $this->strictStreamPolicy);

        $fetch = $this->bodyFetcher;
        $content = $fetch($url, $timeoutSecs, $this->maxBytes);

        if (strlen($content) > $this->maxBytes) {
            throw new M3UPlaylistOversizedException(
                "M3U playlist payload from {$url} exceeds maximum allowed size of {$this->maxBytes} bytes"
            );
        }

        return $this->parse($content);
    }

    /**
     * Parse an #EXTINF line and the associated URL.
     *
     * @param string $extInfLine The #EXTINF line
     * @param string $urlLine The next line containing the URL
     *
     * @return array{entry: M3UEntry|null, urlRefused: bool} `entry` is the
     *         parsed pair or null on refusal; `urlRefused` is true exactly
     *         when the pair died on a URL-bearing line that the bare-line
     *         path would refuse identically — telling {@see self::parse()}
     *         to consume both lines so one dropped entry is counted once.
     */
    private function parseExtInfLine(string $extInfLine, string $urlLine): array
    {
        // Parse #EXTINF:-1 attributes... channel name
        // Format: #EXTINF:-1 tvg-id="1" tvg-name="Name" tvg-chno="5" group-title="Group",Channel Name
        // or: #EXTINF:-1 radio="1" tvg-id="1",Channel Name

        // Extract attributes and channel name
        // Note: Duration can be -1 for radio channels, so we use -?\d+
        if (!preg_match('/^#EXTINF:(-?\d+)\s*(.*)?,(.+)$/', $extInfLine, $matches)) {
            return ['entry' => null, 'urlRefused' => false];
        }

        $attributesStr = $matches[2];
        $channelName = trim($matches[3]);

        // Radio detection stays cheap: the attribute is a tag on the line.
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

        if ($url === '' || str_starts_with($url, '#')) {
            // No URL datum to consume: the next line stands on its own and
            // the main loop judges it exactly as before.
            return ['entry' => null, 'urlRefused' => false];
        }

        // Validate URL shape, then run it through the stream jail. The jail
        // verdict also decides line consumption: whatever it refuses here the
        // bare-line path would refuse identically below, so the pair owns
        // that line and reports it refused (count-once law).
        $shapeOk = filter_var($url, FILTER_VALIDATE_URL) !== false;
        $guardOk = $this->acceptsStreamUrl($url);

        if (!$guardOk) {
            return ['entry' => null, 'urlRefused' => true];
        }

        if (!$shapeOk) {
            // Shape-invalid yet jail-clean: keep the pre-existing behaviour
            // of handing the line to the single-line fallback below, which
            // accepts jail-clean URLs without the filter_var check.
            return ['entry' => null, 'urlRefused' => false];
        }

        return ['entry' => new M3UEntry(
            url: $url,
            name: $channelName,
            tvgId: $tvgId,
            tvgChno: $tvgChno,
            group: $groupTitle,
            logo: $tvgLogo,
            isRadio: $isRadio,
        ), 'urlRefused' => false];
    }

    /**
     * Gate one candidate stream URL, logging the specific refusal reason.
     *
     * Only scheme/host are logged — playlist URLs routinely carry provider
     * tokens in the query string and those must not land in the log.
     */
    private function acceptsStreamUrl(string $url): bool
    {
        $refusal = StreamUrlGuard::refusalReason($url, $this->strictStreamPolicy);

        if ($refusal === null) {
            return true;
        }

        $this->logger?->warning('M3UParser: refusing playlist entry', [
            'scheme' => (string) (parse_url($url, PHP_URL_SCHEME) ?? ''),
            'host' => (string) (parse_url($url, PHP_URL_HOST) ?? ''),
            'reason' => $refusal,
        ]);

        return false;
    }

    /**
     * The default bounded body fetch: stream context (as the pre-cap
     * implementation used) but reading at most $maxBytes + 1 bytes through
     * {@see BoundedBodyReader}, so the oversize detection costs one byte, not
     * an unbounded buffer.
     */
    private static function fetchViaStream(string $url, int $timeoutSecs, int $maxBytes): string
    {
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

        $handle = @fopen($url, 'r', false, $context);

        if ($handle === false) {
            $error = error_get_last();
            throw new \RuntimeException(
                "Failed to fetch M3U playlist from $url: " . ($error['message'] ?? 'Unknown error')
            );
        }

        try {
            return BoundedBodyReader::read($handle, $maxBytes);
        } finally {
            fclose($handle);
        }
    }
}
