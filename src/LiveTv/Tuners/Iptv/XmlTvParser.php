<?php

declare(strict_types=1);

namespace Phlex\LiveTv\Tuners\Iptv;

use Psr\Log\LoggerInterface;

/**
 * Parser for XMLTV (XMLTV-ng) guide data files.
 *
 * Parses XMLTV format files containing programme listings for multiple channels.
 * Supports:
 * - <programme> elements with start/stop times
 * - Channel identification via xmltv-id
 * - Programme metadata: title, description, category, episode-num
 * - Content rating via <rating> and <star-rating>
 * - Year via <production-date> or <date>
 *
 * @since 0.12.0
 */
class XmlTvParser
{
    /**
     * Default cap on bytes read from a remote XMLTV URL when no explicit
     * value is provided via configuration. Keeps a single malicious EPG
     * endpoint from exhausting worker memory.
     */
    public const DEFAULT_MAX_BYTES = 64 * 1024 * 1024; // 64 MiB

    /** @var LoggerInterface|null Optional logger */
    private ?LoggerInterface $logger;

    /** Maximum number of bytes to read from a remote XMLTV URL. */
    private int $maxBytes;

    /** Maximum redirects to follow when fetching XMLTV URLs. */
    private int $maxRedirects;

    /**
     * @param LoggerInterface|null $logger Optional logger instance
     * @param int|null $maxBytes Maximum download size in bytes (null = use config / default)
     * @param int|null $maxRedirects Maximum HTTP redirects (null = use config / default)
     */
    public function __construct(
        ?LoggerInterface $logger = null,
        ?int $maxBytes = null,
        ?int $maxRedirects = null,
    ) {
        $this->logger = $logger;
        $config = $this->loadXmltvConfig();
        $this->maxBytes = $maxBytes ?? (is_int($config['max_bytes'] ?? null) ? (int) $config['max_bytes'] : self::DEFAULT_MAX_BYTES);
        $this->maxRedirects = $maxRedirects ?? (is_int($config['max_redirects'] ?? null) ? (int) $config['max_redirects'] : 3);
    }

    /**
     * Return the configured maximum download size in bytes.
     */
    public function getMaxBytes(): int
    {
        return $this->maxBytes;
    }

    /**
     * Load the LiveTV `xmltv` config block, falling back to defaults.
     *
     * @return array<string, mixed>
     */
    private function loadXmltvConfig(): array
    {
        $configPath = defined('PHLEX_CONFIG_PATH') ? PHLEX_CONFIG_PATH : __DIR__ . '/../../../../config';
        $configFile = $configPath . '/livetv.php';
        if (is_file($configFile)) {
            /** @var array<string, mixed> $config */
            $config = include $configFile;
            $xmltv = $config['xmltv'] ?? null;
            if (is_array($xmltv)) {
                /** @var array<string, mixed> $xmltv */
                return $xmltv;
            }
        }
        return [];
    }

    /**
     * Parse an XMLTV file from a string.
     *
     * @param string $xml The XMLTV content
     * @return XmlTvProgramme[] Array of parsed programmes
     *
     * @example
     * ```php
     * $parser = new XmlTvParser();
     * $programmes = $parser->parse($xmltvContent);
     * ```
     */
    public function parse(string $xml): array
    {
        $programmes = [];

        // Handle empty or whitespace-only XML
        if (trim($xml) === '') {
            return [];
        }

        // Suppress XML errors and handle them gracefully
        libxml_use_internal_errors(true);

        $doc = new \DOMDocument();
        try {
            if (!$doc->loadXML($xml)) {
                $errors = libxml_get_errors();
                libxml_clear_errors();
                $this->logger?->warning('XmlTvParser: failed to parse XML', ['errors' => $errors]);
                return [];
            }
        } catch (\ValueError $e) {
            // Handle empty string case - DOMDocument::loadXML throws ValueError on empty string
            libxml_clear_errors();
            $this->logger?->warning('XmlTvParser: failed to parse XML', ['error' => $e->getMessage()]);
            return [];
        }

        $programmeNodes = $doc->getElementsByTagName('programme');

        foreach ($programmeNodes as $programmeNode) {
            $programme = $this->parseProgrammeNode($programmeNode);
            if ($programme !== null) {
                $programmes[] = $programme;
            }
        }

        $this->logger?->debug('XmlTvParser: parsed programmes', ['count' => count($programmes)]);

        return $programmes;
    }

    /**
     * Fetch and parse an XMLTV file from a URL.
     *
     * @param string $url The URL to fetch the XMLTV file from
     * @param int $timeoutSecs Timeout in seconds for the HTTP request (default: 30)
     * @return XmlTvProgramme[] Array of parsed programmes
     * @throws \RuntimeException If the URL cannot be fetched
     *
     * @example
     * ```php
     * $parser = new XmlTvParser();
     * $programmes = $parser->parseUrl('https://example.com/epg.xml');
     * ```
     */
    public function parseUrl(string $url, int $timeoutSecs = 30): array
    {
        $this->logger?->info('XmlTvParser: fetching XMLTV', [
            'url' => $url,
            'timeout' => $timeoutSecs,
            'max_bytes' => $this->maxBytes,
        ]);

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeoutSecs,
                'follow_location' => 1,
                'max_redirects' => $this->maxRedirects,
                'user_agent' => 'Phlex/1.0 (XMLTV Parser)',
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'SNI_enabled' => true,
            ],
        ]);

        $handle = @fopen($url, 'r', false, $context);
        if ($handle === false) {
            $error = error_get_last();
            throw new \RuntimeException("Failed to fetch XMLTV from $url: " . ($error['message'] ?? 'Unknown error'));
        }

        try {
            // Read at most maxBytes + 1 so that we can distinguish "exactly at
            // limit" from "exceeded limit" without buffering an unbounded
            // amount of memory on a malicious endpoint.
            $content = stream_get_contents($handle, $this->maxBytes + 1);
        } finally {
            fclose($handle);
        }

        if ($content === false) {
            $error = error_get_last();
            throw new \RuntimeException("Failed to read XMLTV from $url: " . ($error['message'] ?? 'Unknown error'));
        }

        if (strlen($content) > $this->maxBytes) {
            throw new XmlTvOversizedException(
                "XMLTV payload from {$url} exceeds maximum allowed size of {$this->maxBytes} bytes"
            );
        }

        return $this->parse($content);
    }

    /**
     * Parse a single <programme> XML node.
     *
     * @param \DOMElement $node The programme element
     * @return XmlTvProgramme|null Parsed programme or null if invalid
     */
    private function parseProgrammeNode(\DOMElement $node): ?XmlTvProgramme
    {
        // Get channel ID from the 'channel' attribute
        $channelId = $node->getAttribute('channel');
        if ($channelId === '') {
            return null;
        }

        // Parse start and stop times (format: YYYYMMDDHHMMSS or YYYYMMDDHHMMSS +offset)
        $startStr = $node->getAttribute('start');
        $stopStr = $node->getAttribute('stop');

        $startTime = $this->parseTimeString($startStr);
        $endTime = $this->parseTimeString($stopStr);

        if ($startTime === null) {
            return null;
        }

        // Default end time to start + 1 hour if not specified
        if ($endTime === null) {
            $endTime = $startTime + 3600;
        }

        // Extract title (prefer English)
        $title = $this->getElementText($node, 'title', 'en');
        if ($title === null) {
            // Try without language
            $title = $this->getElementText($node, 'title');
        }
        if ($title === null) {
            $title = 'Unknown Programme';
        }

        // Extract description
        $description = $this->getElementText($node, 'desc', 'en')
            ?? $this->getElementText($node, 'desc');

        // Extract category
        $category = $this->getElementText($node, 'category', 'en')
            ?? $this->getElementText($node, 'category');

        // Extract episode number
        $episodeNum = $this->getEpisodeNum($node);

        // Extract rating
        $rating = $this->getRating($node);

        // Extract year
        $year = $this->getYear($node);

        return new XmlTvProgramme(
            channelId: $channelId,
            startTime: $startTime,
            endTime: $endTime,
            title: $title,
            description: $description,
            category: $category,
            episodeNum: $episodeNum,
            rating: $rating,
            year: $year,
        );
    }

    /**
     * Parse XMLTV time string to Unix timestamp.
     *
     * Format: YYYYMMDDHHMMSS or YYYYMMDDHHMMSS +/-HHMM or Z
     *
     * @param string $timeStr The time string
     * @return int|null Unix timestamp or null if invalid
     */
    private function parseTimeString(string $timeStr): ?int
    {
        if ($timeStr === '') {
            return null;
        }

        // Remove timezone offset for parsing (e.g., +0000, -0500)
        $timeStr = (string) preg_replace('/[+-]\d{4}$/', '', $timeStr);
        // Remove Z suffix (UTC indicator)
        $timeStr = str_replace('Z', '', $timeStr);
        // Trim any remaining whitespace
        $timeStr = trim($timeStr);

        if (preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})$/', $timeStr, $matches)) {
            $year = (int) $matches[1];
            $month = (int) $matches[2];
            $day = (int) $matches[3];
            $hour = (int) $matches[4];
            $minute = (int) $matches[5];
            $second = (int) $matches[6];

            // Handle timezone offset if present (not stripped above)
            $offsetMinutes = 0;

            return mktime($hour, $minute, $second, $month, $day, $year) - ($offsetMinutes * 60);
        }

        return null;
    }

    /**
     * Get text content of a child element, optionally filtered by language.
     *
     * @param \DOMElement $parent The parent element
     * @param string $tagName The child tag name
     * @param string|null $lang Optional language code filter
     * @return string|null The text content or null
     */
    private function getElementText(\DOMElement $parent, string $tagName, ?string $lang = null): ?string
    {
        $elements = $parent->getElementsByTagName($tagName);

        foreach ($elements as $element) {
            if ($lang === null) {
                // Return first element without language filter
                return trim($element->textContent);
            }

            // Check language attribute
            $elementLang = $element->getAttribute('lang');
            if ($elementLang === $lang || str_starts_with($elementLang, $lang)) {
                return trim($element->textContent);
            }
        }

        return null;
    }

    /**
     * Extract episode number from various XMLTV formats.
     *
     * Supports:
     * - <episode-num system="xmltv_ns">0.3.2</episode-num>
     * - <episode-num system="onscreen">S01E05</episode-num>
     *
     * @param \DOMElement $node The programme element
     * @return string|null The episode number or null
     */
    private function getEpisodeNum(\DOMElement $node): ?string
    {
        $episodeNums = $node->getElementsByTagName('episode-num');

        foreach ($episodeNums as $episodeNum) {
            $system = $episodeNum->getAttribute('system');

            if ($system === 'onscreen') {
                return trim($episodeNum->textContent);
            }
        }

        // Fallback: return first episode-num content
        if ($episodeNums->length > 0) {
            $firstEpisodeNum = $episodeNums->item(0);
            if ($firstEpisodeNum !== null) {
                return trim($firstEpisodeNum->textContent);
            }
        }

        return null;
    }

    /**
     * Extract content rating from <rating> and <star-rating> elements.
     *
     * @param \DOMElement $node The programme element
     * @return string|null The rating or null
     */
    private function getRating(\DOMElement $node): ?string
    {
        // Try <rating> first
        $ratings = $node->getElementsByTagName('rating');
        foreach ($ratings as $rating) {
            $value = $rating->getAttribute('value');
            if ($value !== '') {
                return $value;
            }

            // Check for <value> child element
            $values = $rating->getElementsByTagName('value');
            if ($values->length > 0) {
                $firstValue = $values->item(0);
                if ($firstValue !== null) {
                    return trim($firstValue->textContent);
                }
            }
        }

        // Try <star-rating>
        $starRatings = $node->getElementsByTagName('star-rating');
        foreach ($starRatings as $starRating) {
            $value = $starRating->getAttribute('value');
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Extract production/release year from programme data.
     *
     * Checks <production-date> and <date> elements.
     *
     * @param \DOMElement $node The programme element
     * @return int|null The year or null
     */
    private function getYear(\DOMElement $node): ?int
    {
        // Check for <production-date>
        $productionDates = $node->getElementsByTagName('production-date');
        foreach ($productionDates as $date) {
            $text = trim($date->textContent);
            if (preg_match('/^(\d{4})/', $text, $matches)) {
                return (int) $matches[1];
            }
        }

        // Check for <date> (typically YYYYMMDD or just YYYY)
        $dates = $node->getElementsByTagName('date');
        foreach ($dates as $date) {
            $text = trim($date->textContent);
            if (preg_match('/^(\d{4})/', $text, $matches)) {
                return (int) $matches[1];
            }
        }

        return null;
    }
}
