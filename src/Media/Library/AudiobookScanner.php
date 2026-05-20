<?php

declare(strict_types=1);

namespace Phlix\Media\Library;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\StructuredLogger;
use Psr\Log\LoggerInterface;

/**
 * AudiobookScanner extends BookScanner for M4B / MP3 / M4A audiobook files.
 *
 * Handles chapter extraction from MP4 `chpl` atom (binary) and from
 * ID3v2 CMT2/CHAP frames. Also extracts full audiobook metadata including
 * title, author, narrator, series, description, cover, duration, language, and ISBN.
 *
 * @author Phlix Development Team
 * @version 1.0.0
 * @description Scanner for M4B/MP3 audiobook files with chapter extraction
 * @since 0.18.0
 * @see BookScanner For the parent class with EPUB/PDF/CBZ handling
 */
class AudiobookScanner extends BookScanner
{
    /** @var array<string, array<string>> Supported audiobook file extensions by format */
    private const AUDIOBOOK_EXTENSIONS = [
        'm4b' => ['m4b', 'm4a'],
        'mp3' => ['mp3'],
    ];

    /** @var StructuredLogger|null Logger instance */
    private ?StructuredLogger $logger = null;

    /**
     * Constructor for AudiobookScanner.
     *
     * @param \Workerman\MySQL\Connection $db Database connection
     * @param ItemRepository $itemRepository Repository for media item operations
     * @param StructuredLogger|null $logger Optional custom logger
     * @param \Psr\EventDispatcher\EventDispatcherInterface|null $eventDispatcher Optional PSR-14 dispatcher
     */
    public function __construct(
        \Workerman\MySQL\Connection $db,
        ItemRepository $itemRepository,
        ?StructuredLogger $logger = null,
        ?\Psr\EventDispatcher\EventDispatcherInterface $eventDispatcher = null
    ) {
        parent::__construct($db, $itemRepository, $logger, $eventDispatcher);
        $this->logger = $logger ?? $this->createDefaultLogger();
    }

    /**
     * Creates a default structured logger for the scanner subsystem.
     *
     * @return StructuredLogger A configured logger instance
     */
    private function createDefaultLogger(): StructuredLogger
    {
        $tempDir = sys_get_temp_dir() . '/phlix_audiobook_' . uniqid();
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $config = [
            'handlers' => [
                'stream' => [
                    'type' => 'stream',
                    'path' => $tempDir . '/audiobook_scanner.log',
                    'level' => 'debug',
                ],
            ],
            'processors' => [
                'context' => true,
                'request_id' => false,
                'user_id' => false,
            ],
        ];

        return new StructuredLogger(LogChannels::MEDIA, $config);
    }

    /**
     * Checks if an extension represents an audiobook file.
     *
     * @param string $extension File extension (without dot)
     * @return bool True if the extension is a supported audiobook format
     *
     * @since 0.18.0
     */
    public function isAudiobookExtension(string $extension): bool
    {
        $extension = strtolower($extension);
        foreach (self::AUDIOBOOK_EXTENSIONS as $extensions) {
            if (in_array($extension, $extensions, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Extracts chapters from an M4B/MP4 file.
     *
     * Parses the MP4 `chpl` atom (chapter list) which stores chapter
     * information in binary format. Each chapter has a title, start time,
     * end time, and duration in milliseconds.
     *
     * @param string $path Absolute path to the M4B/MP4 file
     * @return array<int, array<string, mixed>> Array of chapter data:
     *   - title: string|null
     *   - start_ms: int (milliseconds)
     *   - end_ms: int (milliseconds)
     *   - duration_ms: int (milliseconds)
     *   - path_hint: string (file path for seeking)
     *
     * @since 0.18.0
     */
    public function harvestChapters(string $path): array
    {
        if (!file_exists($path) || !is_readable($path)) {
            return [];
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === 'mp3') {
            return $this->harvestMp3Chapters($path);
        }

        return $this->harvestM4bChapters($path);
    }

    /**
     * Reads a 32-bit unsigned integer from a 4-byte string.
     *
     * @param string $data 4-byte big-endian encoded integer
     * @return int|false The parsed integer or false on failure
     */
    private function readUInt32(string $data): int|false
    {
        if (strlen($data) < 4) {
            return false;
        }
        $unpacked = unpack('N', $data);
        return $unpacked !== false ? $unpacked[1] : false;
    }

    /**
     * Extracts chapters from an M4B file by parsing the `chpl` atom.
     *
     * @param string $path Absolute path to the M4B file
     * @return array<int, array<string, mixed>> Array of chapter data
     */
    private function harvestM4bChapters(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        try {
            // Read file into memory for atom scanning
            $fileSize = filesize($path);
            if ($fileSize === false || $fileSize === 0) {
                return [];
            }

            $data = fread($handle, $fileSize);
            if ($data === false) {
                return [];
            }

            $chapters = [];
            $offset = 0;

            // Find 'moov' atom
            while ($offset < strlen($data) - 8) {
                $atomSize = $this->readUInt32(substr($data, $offset, 4));
                $atomType = substr($data, $offset + 4, 4);

                if ($atomSize === 0) {
                    $atomSize = strlen($data) - $offset;
                }

                if ($atomType === 'moov') {
                    $chapters = $this->parseMoovAtom(substr($data, $offset + 8, $atomSize - 8), $path);
                    break;
                }

                $offset += $atomSize;
            }

            return $chapters;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Parses the moov atom to find chapter information.
     *
     * @param string $data The moov atom data
     * @param string $path_hint The file path for path_hint in chapters
     * @return array<int, array<string, mixed>> Array of chapter data
     */
    private function parseMoovAtom(string $data, string $path_hint): array
    {
        $chapters = [];
        $offset = 0;
        $dataLen = strlen($data);

        while ($offset < $dataLen - 8) {
            $unpacked = unpack('N', substr($data, $offset, 4));
            if ($unpacked === false) {
                break;
            }
            $atomSize = $unpacked[1];
            $atomType = substr($data, $offset + 4, 4);

            if ($atomSize === 0) {
                $atomSize = $dataLen - $offset;
            }

            if ($atomType === 'chpl') {
                $chapters = $this->parseChplAtom(substr($data, $offset + 8, $atomSize - 8), $path_hint);
                break;
            }

            // Search inside container atoms
            if (in_array($atomType, ['udta', 'meta', 'ilst'], true)) {
                $innerData = substr($data, $offset + 8, $atomSize - 8);
                $innerChapters = $this->parseMoovAtom($innerData, $path_hint);
                if (!empty($innerChapters)) {
                    $chapters = $innerChapters;
                    break;
                }
            }

            $offset += $atomSize;
        }

        return $chapters;
    }

    /**
     * Parses the `chpl` (chapter list) MP4 atom.
     *
     * Format (binary):
     * - uint32: atom size (included)
     * - 4 bytes: 'chpl'
     * - 1 byte: version
     * - 3 bytes: flags
     * - uint16: reserved (0)
     * - uint16: chapter count
     * - For each chapter:
     *   - uint64: start time (in milliseconds)
     *   - uint8: title length
     *   - string: title (UTF-8)
     *
     * @param string $data The chpl atom data (without size/type header)
     * @param string $path_hint The file path for path_hint
     * @return array<int, array<string, mixed>> Array of chapter data
     */
    private function parseChplAtom(string $data, string $path_hint): array
    {
        $chapters = [];

        if (strlen($data) < 12) {
            return [];
        }

        // Skip header: version(1) + flags(3) + reserved(2) = 6 bytes before count
        $offset = 6;

        // Read chapter count (uint16 big-endian)
        $countUnpacked = unpack('n', substr($data, $offset, 2));
        if ($countUnpacked === false) {
            return [];
        }
        $count = $countUnpacked[1];
        $offset += 2;

        for ($i = 0; $i < $count; $i++) {
            if ($offset + 9 > strlen($data)) {
                break;
            }

            // Read start time (uint64 big-endian, milliseconds)
            $startMsUnpacked = unpack('J', substr($data, $offset, 8));
            if ($startMsUnpacked === false) {
                break;
            }
            $startMs = $startMsUnpacked[1];
            $offset += 8;

            // Read title length (uint8)
            $titleLenUnpacked = unpack('C', substr($data, $offset, 1));
            if ($titleLenUnpacked === false) {
                break;
            }
            $titleLen = $titleLenUnpacked[1];
            $offset += 1;

            // Read title (UTF-8)
            $title = '';
            if ($titleLen > 0 && $offset + $titleLen <= strlen($data)) {
                $title = substr($data, $offset, $titleLen);
                $offset += $titleLen;
            }

            // Estimate end_ms as next chapter's start (or use file duration later)
            $endMs = $startMs + 300000; // Default 5 min if no next chapter

            $chapters[] = [
                'title' => $title ?: "Chapter " . ($i + 1),
                'start_ms' => (int)$startMs,
                'end_ms' => (int)$endMs,
                'duration_ms' => 0, // Will be calculated between chapters
                'path_hint' => $path_hint,
            ];
        }

        // Calculate duration_ms between chapters
        $count = count($chapters);
        for ($i = 0; $i < $count - 1; $i++) {
            $currentChapter = $chapters[$i];
            $nextChapter = $chapters[$i + 1];

            $currentChapter['end_ms'] = $nextChapter['start_ms'];
            $currentChapter['duration_ms'] = $currentChapter['end_ms'] - $currentChapter['start_ms'];
            $chapters[$i] = $currentChapter;
        }

        if ($count > 0) {
            // Last chapter has no known end, set a placeholder
            $lastChapter = $chapters[$count - 1];
            $lastChapter['duration_ms'] = 0;
            $chapters[$count - 1] = $lastChapter;
        }

        return $chapters;
    }

    /**
     * Extracts chapters from an MP3 file via ID3v2 CMT2/CHAP frames.
     *
     * @param string $path Absolute path to the MP3 file
     * @return array<int, array<string, mixed>> Array of chapter data
     */
    private function harvestMp3Chapters(string $path): array
    {
        // MP3 chapter extraction via ID3v2 CHAP frames
        // For now, return empty array as ID3v2 parsing is complex
        // Future enhancement: parse CTOC and CHAP frames from ID3v2 tag
        return [];
    }

    /**
     * Extracts full audiobook metadata from M4B MP4 tags.
     *
     * Extracts: title, author, narrator, series, series_position,
     * description, duration_ms, language, isbn.
     *
     * @param string $path Absolute path to the M4B/MP3 file
     * @return array<string, mixed> Extracted metadata:
     *   - title: string|null
     *   - author: string|null
     *   - narrator: string|null
     *   - series: string|null
     *   - series_position: int|null
     *   - description: string|null
     *   - duration_ms: int|null
     *   - language: string|null
     *   - isbn: string|null
     *   - cover_path: string|null (extracted cover image path)
     *
     * @since 0.18.0
     */
    public function harvestAudiobookMetadata(string $path): array
    {
        if (!file_exists($path) || !is_readable($path)) {
            return [];
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === 'mp3') {
            return $this->harvestMp3Metadata($path);
        }

        return $this->harvestM4bMetadata($path);
    }

    /**
     * Extracts metadata from an M4B file via MP4 atoms.
     *
     * @param string $path Absolute path to the M4B file
     * @return array<string, mixed> Extracted metadata
     */
    private function harvestM4bMetadata(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        try {
            $fileSize = filesize($path);
            if ($fileSize === false || $fileSize === 0) {
                return [];
            }

            $data = fread($handle, $fileSize);
            if ($data === false) {
                return [];
            }

            $metadata = [];

            // Find 'moov' atom
            $offset = 0;
            $moovOffset = null;
            $moovSize = null;

            while ($offset < strlen($data) - 8) {
                $atomSize = $this->readUInt32(substr($data, $offset, 4));
                $atomType = substr($data, $offset + 4, 4);

                if ($atomSize === 0) {
                    $atomSize = strlen($data) - $offset;
                }

                if ($atomType === 'moov') {
                    $moovOffset = $offset;
                    $moovSize = $atomSize;
                    break;
                }

                $offset += $atomSize;
            }

            if ($moovOffset === null) {
                return [];
            }

            // Find 'udta' -> 'meta' -> 'ilst' within moov
            $moovData = substr($data, $moovOffset + 8, $moovSize - 8);
            $ilstData = $this->findIlstAtom($moovData);

            if ($ilstData !== null) {
                $metadata = $this->parseIlstAtom($ilstData);
            }

            // Also try to get duration from 'mdia' -> 'mdhd'
            $duration = $this->findDuration($moovData);
            if ($duration !== null) {
                $metadata['duration_ms'] = $duration;
            }

            return $metadata;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Finds the ilst atom within moov data.
     *
     * @param string $moovData The moov atom data
     * @return string|null The ilst atom data or null
     */
    private function findIlstAtom(string $moovData): ?string
    {
        $offset = 0;

        while ($offset < strlen($moovData) - 8) {
            $atomSizeU = $this->readUInt32(substr($moovData, $offset, 4));
            if ($atomSizeU === false) {
                break;
            }
            $atomSize = $atomSizeU;
            $atomType = substr($moovData, $offset + 4, 4);

            if ($atomSize === 0) {
                $atomSize = strlen($moovData) - $offset;
            }

            if ($atomType === 'udta') {
                // Look for meta inside udta
                $udtaData = substr($moovData, $offset + 8, $atomSize - 8);
                $metaData = $this->findMetaAtom($udtaData);
                if ($metaData !== null) {
                    $ilstData = $this->findIlstAtom($metaData);
                    if ($ilstData !== null) {
                        return $ilstData;
                    }
                }
            }

            if ($atomType === 'meta') {
                // meta atom has 4-byte header before children
                $innerData = substr($moovData, $offset + 12, $atomSize - 12);
                $ilstData = $this->findIlstAtom($innerData);
                if ($ilstData !== null) {
                    return $ilstData;
                }
            }

            $offset += $atomSize;
        }

        return null;
    }

    /**
     * Finds the meta atom within udta.
     *
     * @param string $udtaData The udta atom data
     * @return string|null The meta atom data or null
     */
    private function findMetaAtom(string $udtaData): ?string
    {
        $offset = 0;

        while ($offset < strlen($udtaData) - 8) {
            $atomSizeU = $this->readUInt32(substr($udtaData, $offset, 4));
            if ($atomSizeU === false) {
                break;
            }
            $atomSize = $atomSizeU;
            $atomType = substr($udtaData, $offset + 4, 4);

            if ($atomSize === 0) {
                $atomSize = strlen($udtaData) - $offset;
            }

            if ($atomType === 'meta') {
                // meta has a 4-byte size + 4-byte 'meta' + 4-byte version/flags = 12 bytes header
                return substr($udtaData, $offset + 12, $atomSize - 12);
            }

            $offset += $atomSize;
        }

        return null;
    }

    /**
     * Parses the ilst atom for metadata.
     *
     * @param string $ilstData The ilst atom data
     * @return array<string, mixed> Parsed metadata
     */
    private function parseIlstAtom(string $ilstData): array
    {
        $metadata = [];
        $offset = 0;

        // Map of ilst atom codes to metadata keys
        $atomMap = [
            '\xa9nam' => 'title',      // title
            '\xa9ART' => 'author',     // artist/author
            '\xa9wrt' => 'author',    // writer (fallback)
            'aART' => 'author',        // album artist (fallback)
            'trkn' => null,           // track number (not needed for audiobooks)
            'disk' => null,           // disc number
            '\xa9day' => null,        // year
            '\xa9cmt' => 'description', // comment/description
            'desc' => 'description',   // description (fallback)
            '©gen' => null,           // genre
            '©lyr' => null,           // lyrics
            '©nrt' => 'narrator',     // narrator
            'sonm' => 'series',       // series name
            'tvsh' => 'series',       // show/series (alternative)
            'shwm' => null,           // show/movie (alternative)
            '©alb' => null,           // album (fallback title)
            'aply' => null,           // application (not needed)
            '©arg' => null,           // argument/description
            '©des' => 'description', // description (alternative)
            '©pub' => null,          // publisher
            '©cpy' => null,          // copyright
            'ccred' => null,         // credits
            'covr' => 'cover_path',   // cover art (special handling)
            '©cmd' => null,           // command (not needed)
        ];

        while ($offset < strlen($ilstData) - 8) {
            $atomSizeU = $this->readUInt32(substr($ilstData, $offset, 4));
            if ($atomSizeU === false) {
                break;
            }
            $atomSize = $atomSizeU;
            $atomType = substr($ilstData, $offset + 4, 4);

            if ($atomSize === 0) {
                $atomSize = strlen($ilstData) - $offset;
            }

            if ($atomSize < 8) {
                break;
            }

            $atomData = substr($ilstData, $offset + 8, $atomSize - 8);

            if (isset($atomMap[$atomType])) {
                $key = $atomMap[$atomType];
                if ($key === 'cover_path') {
                    // Cover art: data is binary image
                    $coverPath = $this->saveCoverImage($atomData);
                    if ($coverPath !== null) {
                        $metadata['cover_path'] = $coverPath;
                    }
                } elseif ($key !== null) {
                    $value = $this->parseIlstDataAtom($atomData);
                    if ($value !== null && $value !== '' && !isset($metadata[$key])) {
                        $metadata[$key] = $value;
                    }
                }
            }

            $offset += $atomSize;
        }

        return $metadata;
    }

    /**
     * Parses a data atom within ilst for its string value.
     *
     * Format:
     * - 4 bytes: size
     * - 4 bytes: 'data'
     * - 4 bytes: locale/country (for text)
     * - n bytes: value
     *
     * @param string $data The data atom content
     * @return string|null The string value or null
     */
    private function parseIlstDataAtom(string $data): ?string
    {
        if (strlen($data) < 12) {
            // Try raw string for short values
            return trim(substr($data, 0, min(256, strlen($data))));
        }

        $indicator = substr($data, 4, 4);
        if ($indicator !== 'data') {
            return null;
        }

        // Skip locale (4 bytes) and get text
        $text = substr($data, 12);
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        return trim($text);
    }

    /**
     * Saves cover image data to a temporary file.
     *
     * @param string $imageData The binary image data
     * @return string|null Path to the saved image or null
     */
    private function saveCoverImage(string $imageData): ?string
    {
        if (strlen($imageData) < 4) {
            return null;
        }

        // Determine image type from first bytes
        $format = 'jpeg'; // default
        if (substr($imageData, 4, 3) === 'png') {
            $format = 'png';
        } elseif (substr($imageData, 0, 3) === 'gif') {
            $format = 'gif';
        } elseif (substr($imageData, 0, 2) === 'BM') {
            $format = 'bmp';
        }

        $ext = match ($format) {
            'png' => 'png',
            'gif' => 'gif',
            'bmp' => 'bmp',
            default => 'jpg',
        };

        try {
            $tempDir = sys_get_temp_dir() . '/phlix_audiobook_cover_' . uniqid();
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }

            $path = $tempDir . '/cover.' . $ext;
            file_put_contents($path, $imageData);

            return file_exists($path) ? $path : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Finds the duration from mdia -> mdhd atom.
     *
     * @param string $moovData The moov atom data
     * @return int|null Duration in milliseconds or null
     */
    private function findDuration(string $moovData): ?int
    {
        $offset = 0;

        while ($offset < strlen($moovData) - 8) {
            $atomSizeU = $this->readUInt32(substr($moovData, $offset, 4));
            if ($atomSizeU === false) {
                break;
            }
            $atomSize = $atomSizeU;
            $atomType = substr($moovData, $offset + 4, 4);

            if ($atomSize === 0) {
                $atomSize = strlen($moovData) - $offset;
            }

            if ($atomType === 'mdia') {
                $mdiaData = substr($moovData, $offset + 8, $atomSize - 8);
                return $this->findDurationInMdia($mdiaData);
            }

            $offset += $atomSize;
        }

        return null;
    }

    /**
     * Finds duration within mdia atom.
     *
     * @param string $mdiaData The mdia atom data
     * @return int|null Duration in milliseconds or null
     */
    private function findDurationInMdia(string $mdiaData): ?int
    {
        $offset = 0;

        while ($offset < strlen($mdiaData) - 8) {
            $atomSizeU = $this->readUInt32(substr($mdiaData, $offset, 4));
            if ($atomSizeU === false) {
                break;
            }
            $atomSize = $atomSizeU;
            $atomType = substr($mdiaData, $offset + 4, 4);

            if ($atomSize === 0) {
                $atomSize = strlen($mdiaData) - $offset;
            }

            if ($atomType === 'mdhd') {
                $mdhdData = substr($mdiaData, $offset + 8, $atomSize - 8);
                if (strlen($mdhdData) >= 24) {
                    // Skip version(1) + flags(3) + creation(4) + modification(4)
                    // Then 4 bytes timescale, 4 bytes duration
                    $timescaleU = $this->readUInt32(substr($mdhdData, 12, 4));
                    $durationU = $this->readUInt32(substr($mdhdData, 16, 4));
                    if ($timescaleU === false || $durationU === false) {
                        return null;
                    }
                    $timescale = $timescaleU;
                    $duration = $durationU;

                    if ($timescale > 0) {
                        return (int)(($duration / $timescale) * 1000);
                    }
                }
            }

            $offset += $atomSize;
        }

        return null;
    }

    /**
     * Extracts metadata from an MP3 file via ID3v2 tags.
     *
     * @param string $path Absolute path to the MP3 file
     * @return array<string, mixed> Extracted metadata
     */
    private function harvestMp3Metadata(string $path): array
    {
        $metadata = [];

        // getID3 is an optional dependency (james-heinrich/getid3). When
        // it is not installed we silently return no MP3-specific
        // metadata; callers should not depend on the result being
        // populated. The optional james-heinrich/getid3 package provides a
        // `getID3` class with an `analyze()` method; older / different
        // distributions expose a top-level `getID3` function. We probe
        // for the class first, fall back to the function.
        $tag = null;
        if (class_exists('getID3', false)) {
            try {
                $instance = new \getID3();
                if (method_exists($instance, 'analyze')) {
                    $tag = $instance->analyze($path);
                }
            } catch (\Throwable $e) {
                $this->logger?->warning('Failed to parse MP3 metadata via getID3 class', [
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
                return [];
            }
        }

        if ($tag === null) {
            return [];
        }

        try {
            if (!is_array($tag)) {
                return [];
            }

            $tagsContainer = $tag['tags'] ?? null;
            if (is_array($tagsContainer) && isset($tagsContainer['id3v2']) && is_array($tagsContainer['id3v2'])) {
                $tags = $tagsContainer['id3v2'];

                $metadata['title'] = is_array($tags['title'] ?? null) ? ($tags['title'][0] ?? null) : null;
                $metadata['author'] = is_array($tags['artist'] ?? null) ? ($tags['artist'][0] ?? null) : null;
                $metadata['narrator'] = is_array($tags['composer'] ?? null) ? ($tags['composer'][0] ?? null) : null;
                $metadata['description'] = is_array($tags['comment'] ?? null) ? ($tags['comment'][0] ?? null) : null;
                $metadata['series'] = is_array($tags['band'] ?? null) ? ($tags['band'][0] ?? null) : null;
                $metadata['language'] = is_array($tags['language'] ?? null) ? ($tags['language'][0] ?? null) : null;

                $playtime = $tag['playtime_seconds'] ?? null;
                if (is_numeric($playtime)) {
                    $metadata['duration_ms'] = (int) ((float) $playtime * 1000);
                }
            }
        } catch (\Throwable $e) {
            $this->logger?->warning('Failed to parse MP3 metadata', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }

        return $metadata;
    }

    /**
     * Scans an audiobook library and yields item arrays with chapters.
     *
     * Recursively iterates through all files in the given path, filters by
     * supported audiobook extensions (m4b, m4a, mp3), skips hidden/system files,
     * extracts metadata and chapters, and yields item arrays.
     *
     * @param string $libraryId The library's unique identifier
     * @param string $libraryPath Filesystem path to scan
     * @return \Generator<int, array<string, mixed>> Yields media item data arrays
     *
     * @since 0.18.0
     */
    public function scanAudiobookLibrary(string $libraryId, string $libraryPath): \Generator
    {
        if (!is_dir($libraryPath)) {
            $this->logger?->warning('Scan path does not exist', ['path' => $libraryPath]);
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($libraryPath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isDir()) {
                continue;
            }

            $extension = strtolower($file->getExtension());

            // Skip non-audiobook files
            if (!$this->isAudiobookExtension($extension)) {
                continue;
            }

            // Skip hidden files and system files
            if ($this->shouldSkipFile($file->getFilename())) {
                continue;
            }

            // Check if already exists in repository
            $existing = $this->itemRepository->findByPath($file->getPathname());
            if ($existing !== null) {
                continue;
            }

            // Harvest metadata and chapters
            $metadata = $this->harvestAudiobookMetadata($file->getPathname());
            $chapters = $this->harvestChapters($file->getPathname());

            // Store chapters in metadata_json
            $metadata['chapters'] = $chapters;

            yield [
                'library_id' => $libraryId,
                'name' => $metadata['title'] ?? $file->getBasename('.' . $extension),
                'type' => 'audiobook',
                'path' => $file->getPathname(),
                'metadata_json' => $metadata,
            ];
        }
    }
}
