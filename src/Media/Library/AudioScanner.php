<?php

declare(strict_types=1);

namespace Phlex\Media\Library;

/**
 * AudioScanner discovers and indexes audio files and harvests ID3/MP4 tags.
 *
 * This class extends MediaScanner to handle audio files specifically, parsing
 * metadata from FLAC (Vorbis comments), MP3 (ID3v2.3/2.4), M4A/AAC (MP4 atoms),
 * and OGG (Vorbis comments) formats. It uses pure-PHP parsing for maximum
 * portability - no external dependencies like getID3 required.
 *
 * @author Phlex Development Team
 * @version 1.0.0
 * @description Audio file scanner with ID3v2/MP4 tag harvesting
 * @see MediaScanner For base scanner functionality
 * @see MusicLibraryManager For library-level orchestration
 */
class AudioScanner extends MediaScanner
{
    /** @var array<string, array<string>> File extensions by audio format */
    private const AUDIO_EXTENSIONS = [
        'flac' => ['flac'],
        'mp3' => ['mp3'],
        'm4a' => ['m4a', 'aac', 'alac'],
        'ogg' => ['ogg', 'oga'],
        'opus' => ['opus'],
        'wav' => ['wav'],
        'wma' => ['wma'],
    ];

    /**
     * Harvests ID3/MP4/Ogg tags from an audio file.
     *
     * Returns a structured array with all available tag fields.
     * Never throws - returns partial results on best-effort basis.
     *
     * @param string $path Absolute filesystem path to the audio file
     * @return array<string, mixed> Tag metadata including:
     *   - title: Track title
     *   - artist: Primary artist name
     *   - album: Album name
     *   - album_artist: Album artist name
     *   - year: Release year
     *   - genre: Genre name(s)
     *   - track_number: Track number within album
     *   - disc_number: Disc number within set
     *   - duration_secs: Duration in seconds
     *   - bitrate: Bitrate in kbps
     *   - sample_rate: Sample rate in Hz
     *   - channels: Number of audio channels
     *   - composer: Composer name
     *   - comment: File comment
     *
     * @example
     * ```php
     * $tags = $scanner->harvestTags('/music/artist/album/01-track.mp3');
     * // ['title' => 'Track Name', 'artist' => 'Artist', 'album' => 'Album', ...]
     * ```
     */
    public function harvestTags(string $path): array
    {
        if (!file_exists($path) || !is_readable($path)) {
            return [];
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'mp3' => $this->harvestId3v2Tags($path),
            'flac' => $this->harvestFlacTags($path),
            'm4a', 'aac', 'alac' => $this->harvestMp4Tags($path),
            'ogg', 'oga', 'opus' => $this->harvestVorbisTags($path),
            'wav' => $this->harvestRiffTags($path),
            'wma' => $this->harvestAsfTags($path),
            default => [],
        };
    }

    /**
     * Scans a music library folder and yields media item rows.
     *
     * This is a Generator to avoid loading 10,000 tracks into memory at once.
     * Each yield produces an array suitable for ItemRepository::create().
     *
     * @param string $libraryId The library's unique identifier
     * @param string $libraryPath Root path of the music library
     * @param string $folderPath Specific folder path to scan
     * @return \Generator<int, array<string, mixed>> Yields media item data arrays
     *
     * @example
     * ```php
     * foreach ($scanner->scanMusicLibrary('lib-1', '/music', '/music/Artist/Album') as $item) {
     *     // Process each item
     * }
     * ```
     */
    public function scanMusicLibrary(string $libraryId, string $libraryPath, string $folderPath): \Generator
    {
        if (!is_dir($folderPath) || !is_readable($folderPath)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($folderPath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                continue;
            }

            $extension = strtolower($file->getExtension());
            if (!$this->isSupportedAudioExtension($extension)) {
                continue;
            }

            if ($this->shouldSkipFile($file->getFilename())) {
                continue;
            }

            $tags = $this->harvestTags($file->getPathname());
            $metadata = $this->buildMetadataFromTags($tags, $file);

            yield [
                'library_id' => $libraryId,
                'name' => $tags['title'] ?? $file->getBasename('.' . $extension),
                'type' => 'track',
                'path' => $file->getPathname(),
                'metadata_json' => $metadata,
            ];
        }
    }

    /**
     * Determines if an extension is a supported audio format.
     *
     * @param string $extension File extension (without dot)
     * @return bool True if the extension is a supported audio format
     */
    private function isSupportedAudioExtension(string $extension): bool
    {
        foreach (self::AUDIO_EXTENSIONS as $format => $extensions) {
            if (in_array($extension, $extensions, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Builds metadata array from harvested tags.
     *
     * @param array<string, mixed> $tags Raw tag data
     * @param \SplFileInfo $file File info object
     * @return array<string, mixed> Formatted metadata for storage
     */
    private function buildMetadataFromTags(array $tags, \SplFileInfo $file): array
    {
        $metadata = [];

        if (isset($tags['title'])) {
            $metadata['title'] = $tags['title'];
        }
        if (isset($tags['artist'])) {
            $metadata['artist'] = $tags['artist'];
        }
        if (isset($tags['album'])) {
            $metadata['album'] = $tags['album'];
        }
        if (isset($tags['album_artist'])) {
            $metadata['album_artist'] = $tags['album_artist'];
        }
        if (isset($tags['year'])) {
            $metadata['year'] = (int)$tags['year'];
        }
        if (isset($tags['genre'])) {
            $metadata['genre'] = is_array($tags['genre']) ? $tags['genre'] : [$tags['genre']];
        }
        if (isset($tags['track_number'])) {
            $metadata['track_number'] = (int)$tags['track_number'];
        }
        if (isset($tags['disc_number'])) {
            $metadata['disc_number'] = (int)$tags['disc_number'];
        }
        if (isset($tags['duration_secs'])) {
            $metadata['duration_secs'] = (float)$tags['duration_secs'];
        }
        if (isset($tags['bitrate'])) {
            $metadata['bitrate'] = (int)$tags['bitrate'];
        }
        if (isset($tags['sample_rate'])) {
            $metadata['sample_rate'] = (int)$tags['sample_rate'];
        }
        if (isset($tags['channels'])) {
            $metadata['channels'] = (int)$tags['channels'];
        }
        if (isset($tags['composer'])) {
            $metadata['composer'] = $tags['composer'];
        }
        if (isset($tags['comment'])) {
            $metadata['comment'] = $tags['comment'];
        }

        // Add file-based metadata
        $metadata['file_size'] = $file->getSize();
        $metadata['file_mtime'] = $file->getMTime();

        return $metadata;
    }

    /**
     * Harvests ID3v2 tags from an MP3 file.
     *
     * Supports ID3v2.3 and ID3v2.4. Reads only the tag block at the
     * beginning of the file without loading the entire file into memory.
     *
     * @param string $path Path to the MP3 file
     * @return array<string, mixed> Parsed tag data
     */
    private function harvestId3v2Tags(string $path): array
    {
        $tags = [];
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return [];
        }

        try {
            // Read first 10 bytes to check for ID3v2 header
            $header = fread($handle, 10);

            if ($header === false || strlen($header) < 10) {
                return [];
            }

            // Check for ID3v2 magic bytes
            if (substr($header, 0, 3) !== 'ID3') {
                return [];
            }

            // Parse ID3v2 header
            $version = ord($header[3]);
            $revision = ord($header[4]);
            $flags = ord($header[5]);
            $size = $this->synchsafe28(substr($header, 6, 4));

            // Read tag frames (only if size is valid)
            $tags = [];
            if ($size > 0) {
                $tagData = fread($handle, $size);
                if ($tagData !== false) {
                    $tags = $this->parseId3v2Frames($tagData, $version);
                }
            }

            // Get duration from MP3 frame header
            $duration = $this->getMp3Duration($path);
            if ($duration !== null) {
                $tags['duration_secs'] = $duration;
            }
        } finally {
            fclose($handle);
        }

        return $tags;
    }

    /**
     * Parses ID3v2 frames from tag data.
     *
     * @param string $data Raw frame data
     * @param int $version ID3v2 version (3 or 4)
     * @return array<string, mixed> Parsed frame data
     */
    private function parseId3v2Frames(string $data, int $version): array
    {
        $tags = [];
        $offset = 0;
        $frameSize = $version === 4 ? 4 : 4;

        while ($offset < strlen($data) - 10) {
            // Check for frame sync (0x80 or higher in version 4, 0x00 in older)
            if ($version === 4 && ord($data[$offset]) < 0x80) {
                break;
            }

            $frameId = substr($data, $offset, 4);

            // Frame ID must be alphanumeric
            if (!ctype_alnum($frameId)) {
                break;
            }

            $offset += 4;

            if ($version === 4) {
                $frameSizeBytes = substr($data, $offset, 4);
                $frameSize = $this->synchsafe28($frameSizeBytes);
            } else {
                $frameSizeBytes = substr($data, $offset, 4);
                $frameSize = (
                    (ord($frameSizeBytes[0]) << 24) |
                    (ord($frameSizeBytes[1]) << 16) |
                    (ord($frameSizeBytes[2]) << 8) |
                    ord($frameSizeBytes[3])
                );
            }

            $offset += 4;
            $offset += 2; // Skip flags

            if ($frameSize <= 0 || $offset + $frameSize > strlen($data)) {
                break;
            }

            $frameData = substr($data, $offset, $frameSize);
            $offset += $frameSize;

            // Parse common frames
            $tags = $this->mapId3v2Frame($frameId, $frameData, $tags);
        }

        return $tags;
    }

    /**
     * Maps an ID3v2 frame to our tag format.
     *
     * @param string $frameId Frame identifier (e.g., 'TIT2', 'TYER')
     * @param string $frameData Raw frame data
     * @param array<string, mixed> $tags Current tags array
     * @return array<string, mixed> Updated tags
     */
    private function mapId3v2Frame(string $frameId, string $frameData, array $tags): array
    {
        // Text information frames ( начинаются с T)
        if (str_starts_with($frameId, 'T') && $frameId !== 'TXX') {
            $encoding = ord($frameData[0]) ?? 0;
            $text = $this->decodeId3String(substr($frameData, 1), $encoding);

            switch ($frameId) {
                case 'TIT2':
                    $tags['title'] = $text;
                    break;
                case 'TPE1':
                    $tags['artist'] = $text;
                    break;
                case 'TALB':
                    $tags['album'] = $text;
                    break;
                case 'TPE2':
                    $tags['album_artist'] = $text;
                    break;
                case 'TYER':
                case 'TDRC':
                    $tags['year'] = $text;
                    break;
                case 'TCON':
                    $tags['genre'] = $this->parseGenre($text);
                    break;
                case 'TRCK':
                    $parts = explode('/', $text);
                    $tags['track_number'] = (int)($parts[0] ?? 0);
                    break;
                case 'TPOS':
                    $parts = explode('/', $text);
                    $tags['disc_number'] = (int)($parts[0] ?? 0);
                    break;
                case 'TCOM':
                    $tags['composer'] = $text;
                    break;
            }
        }

        // Comment frame
        if ($frameId === 'COMM') {
            $tags['comment'] = $this->parseCommentFrame($frameData);
        }

        return $tags;
    }

    /**
     * Decodes an ID3v2 encoded string.
     *
     * @param string $data String data (after encoding byte)
     * @param int $encoding Encoding byte (0=ISO-8859-1, 1=UTF-16, 2=UTF-16BE, 3=UTF-8)
     * @return string Decoded string
     */
    private function decodeId3String(string $data, int $encoding): string
    {
        if (empty($data)) {
            return '';
        }

        return match ($encoding) {
            0 => $data, // ISO-8859-1
            1 => mb_convert_encoding($data, 'UTF-8', 'UTF-16'), // UTF-16
            2 => mb_convert_encoding($data, 'UTF-8', 'UTF-16BE'), // UTF-16BE
            3 => $data, // UTF-8
            default => $data,
        };
    }

    /**
     * Parses a genre string that may contain numeric genre references.
     *
     * @param string $genre Raw genre string
     * @return string|array<string> Parsed genre
     */
    private function parseGenre(string $genre): array|string
    {
        // Remove parentheses and brackets (e.g., "(17)" or "((comment))")
        $genre = preg_replace('/[\(\[\(].*?[\)\]\)]/', '', $genre);
        $genre = trim($genre);

        if (empty($genre)) {
            return [];
        }

        return [$genre];
    }

    /**
     * Parses an ID3v2 comment frame.
     *
     * @param string $data Frame data
     * @return string Comment text
     */
    private function parseCommentFrame(string $data): string
    {
        if (strlen($data) < 4) {
            return '';
        }

        $encoding = ord($data[0]);
        $language = substr($data, 1, 3);
        $rest = substr($data, 4);

        // Find null terminator (end of description)
        $nullPos = strpos($rest, "\x00");
        if ($nullPos === false) {
            return '';
        }

        $text = substr($rest, $nullPos + 1);
        return $this->decodeId3String($text, $encoding);
    }

    /**
     * Converts a synchsafe integer to a regular integer.
     *
     * Synchsafe integers are used in ID3v2 for sizes that must not
     * exceed 28 bits (seven usable bytes).
     *
     * @param string $bytes 4 bytes representing synchsafe integer
     * @return int Regular integer value
     */
    private function synchsafe28(string $bytes): int
    {
        return (
            ((ord($bytes[0]) & 0x7F) << 21) |
            ((ord($bytes[1]) & 0x7F) << 14) |
            ((ord($bytes[2]) & 0x7F) << 7) |
            (ord($bytes[3]) & 0x7F)
        );
    }

    /**
     * Gets duration of an MP3 file by scanning frame headers.
     *
     * @param string $path Path to the MP3 file
     * @return float|null Duration in seconds, or null if unknown
     */
    private function getMp3Duration(string $path): ?float
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            // Skip ID3v2 tag if present
            $header = fread($handle, 10);
            if ($header !== false && substr($header, 0, 3) === 'ID3') {
                $size = $this->synchsafe28(substr($header, 6, 4));
                fseek($handle, 10 + $size);
            } else {
                fseek($handle, 0);
            }

            // Find first valid MP3 frame header
            $bitrate = null;
            $sampleRate = null;
            $padding = false;

            while (!feof($handle)) {
                $pos = ftell($handle);
                $byte = fread($handle, 1);

                if ($byte === false || ord($byte) !== 0xFF) {
                    continue;
                }

                $nextByte = fread($handle, 1);
                if ($nextByte === false) {
                    break;
                }

                $byte2 = ord($nextByte);

                // Check for valid frame sync (11 bits set: 11111111111 = 0x7FF)
                if (($byte2 & 0xE0) !== 0xE0) {
                    continue;
                }

                // MPEG Audio Layer III
                if (($byte2 & 0x18) !== 0x18) {
                    continue;
                }

                // Bitrate index
                $bitrateIndex = ($byte2 & 0x0C) >> 2;
                $bitrate = $this->mpegBitrate($bitrateIndex);
                if ($bitrate === null) {
                    continue;
                }

                // Sample rate index
                $sampleRateIndex = ($byte2 & 0x03) << 1 | (ord(fread($handle, 1)) >> 7);
                $sampleRate = $this->mpegSampleRate($sampleRateIndex);
                if ($sampleRate === null) {
                    continue;
                }

                // Padding flag
                $padding = ((ord($nextByte) >> 1) & 1) === 1;

                break;
            }

            if ($bitrate !== null && $sampleRate !== null) {
                // Frame size = 144 * bitrate / sample rate
                $frameSize = (int)(144 * $bitrate * 1000 / $sampleRate);
                if ($padding) {
                    $frameSize++;
                }

                // Get file size and calculate duration
                $fileSize = filesize($path);
                if ($fileSize !== false && $frameSize > 0) {
                    return (float)(($fileSize - 10) / $frameSize * 8); // Approximate
                }
            }

            return null;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Returns bitrate from MPEG bitrate index.
     *
     * @param int $index Bitrate index (0-15)
     * @return int|null Bitrate in kbps, or null if invalid
     */
    private function mpegBitrate(int $index): ?int
    {
        $bitrates = [
            0 => null, 1 => 32, 2 => 40, 3 => 48,
            4 => 56, 5 => 64, 6 => 80, 7 => 96,
            8 => 112, 9 => 128, 10 => 160, 11 => 192,
            12 => 224, 13 => 256, 14 => 320, 15 => null,
        ];
        return $bitrates[$index] ?? null;
    }

    /**
     * Returns sample rate from MPEG sample rate index.
     *
     * @param int $index Sample rate index (0-3)
     * @return int|null Sample rate in Hz, or null if invalid
     */
    private function mpegSampleRate(int $index): ?int
    {
        $rates = [
            0 => 44100, 1 => 48000, 2 => 32000, 3 => null,
        ];
        return $rates[$index] ?? null;
    }

    /**
     * Harvests Vorbis comments from a FLAC file.
     *
     * FLAC uses a standard metadata block system. The Vorbis comment block
     * contains key=value pairs with the same format as Ogg Vorbis comments.
     *
     * @param string $path Path to the FLAC file
     * @return array<string, mixed> Parsed tag data
     */
    private function harvestFlacTags(string $path): array
    {
        $tags = [];

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        try {
            // Read 'fLaC' marker
            $marker = fread($handle, 4);
            if ($marker !== 'fLaC') {
                return [];
            }

            // Read metadata blocks
            while (!feof($handle)) {
                $headerByte = fread($handle, 1);
                if ($headerByte === false) {
                    break;
                }

                $isLast = (ord($headerByte) & 0x80) !== 0;
                $blockType = ord($headerByte) & 0x7F;

                // Read block length (24 bits)
                $lenBytes = fread($handle, 3);
                if ($lenBytes === false) {
                    break;
                }
                $blockLength = (ord($lenBytes[0]) << 16) | (ord($lenBytes[1]) << 8) | ord($lenBytes[2]);

                if ($blockType === 4) {
                    // Vorbis comment block
                    $blockData = fread($handle, $blockLength);
                    if ($blockData !== false) {
                        $tags = $this->parseVorbisComments($blockData);
                    }
                    break;
                }

                if ($isLast) {
                    break;
                }

                fseek($handle, $blockLength, SEEK_CUR);
            }
        } finally {
            fclose($handle);
        }

        // Get audio properties from streaminfo block
        $duration = $this->getFlacDuration($path);
        if ($duration !== null) {
            $tags['duration_secs'] = $duration;
        }

        return $tags;
    }

    /**
     * Parses Vorbis comment data.
     *
     * @param string $data Raw vorbis comment block data
     * @return array<string, mixed> Parsed comments
     */
    private function parseVorbisComments(string $data): array
    {
        $tags = [];
        $offset = 0;

        // Vendor string length and value
        $vendorLen = unpack('V', substr($data, $offset, 4))[1] ?? 0;
        $offset += 4 + $vendorLen;

        // Number of comments
        if ($offset + 4 > strlen($data)) {
            return [];
        }

        $commentCount = unpack('V', substr($data, $offset, 4))[1] ?? 0;
        $offset += 4;

        for ($i = 0; $i < $commentCount && $offset < strlen($data); $i++) {
            $len = unpack('V', substr($data, $offset, 4))[1] ?? 0;
            $offset += 4;

            if ($len <= 0 || $offset + $len > strlen($data)) {
                break;
            }

            $comment = substr($data, $offset, $len);
            $offset += $len;

            // Parse key=value
            $eqPos = strpos($comment, '=');
            if ($eqPos !== false) {
                $key = strtoupper(substr($comment, 0, $eqPos));
                $value = substr($comment, $eqPos + 1);

                $this->mapVorbisTag($key, $value, $tags);
            }
        }

        return $tags;
    }

    /**
     * Maps a Vorbis comment to our tag format.
     *
     * @param string $key Uppercase comment key
     * @param string $value Comment value
     * @param array<string, mixed> $tags Current tags array
     * @return void
     */
    private function mapVorbisTag(string $key, string $value, array &$tags): void
    {
        switch ($key) {
            case 'TITLE':
                $tags['title'] = $value;
                break;
            case 'ARTIST':
                $tags['artist'] = $value;
                break;
            case 'ALBUM':
                $tags['album'] = $value;
                break;
            case 'ALBUMARTIST':
                $tags['album_artist'] = $value;
                break;
            case 'DATE':
            case 'YEAR':
                if (!isset($tags['year'])) {
                    $tags['year'] = $value;
                }
                break;
            case 'GENRE':
                $tags['genre'] = array_map('trim', explode(';', $value));
                break;
            case 'TRACKNUMBER':
                $parts = explode('/', $value);
                $tags['track_number'] = (int)($parts[0] ?? 0);
                break;
            case 'DISCNUMBER':
                $parts = explode('/', $value);
                $tags['disc_number'] = (int)($parts[0] ?? 0);
                break;
            case 'COMPOSER':
                $tags['composer'] = $value;
                break;
            case 'COMMENT':
            case 'DESCRIPTION':
                if (!isset($tags['comment'])) {
                    $tags['comment'] = $value;
                }
                break;
        }
    }

    /**
     * Gets duration of a FLAC file by reading the streaminfo block.
     *
     * @param string $path Path to the FLAC file
     * @return float|null Duration in seconds, or null if unknown
     */
    private function getFlacDuration(string $path): ?float
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            // Read 'fLaC' marker
            $marker = fread($handle, 4);
            if ($marker !== 'fLaC') {
                return null;
            }

            // Read first metadata block (streaminfo)
            $headerByte = fread($handle, 1);
            if ($headerByte === false) {
                return null;
            }

            $isLast = (ord($headerByte) & 0x80) !== 0;
            $blockType = ord($headerByte) & 0x7F;

            if ($blockType !== 0) {
                return null; // First block should be streaminfo
            }

            // Read block length (24 bits)
            $lenBytes = fread($handle, 3);
            if ($lenBytes === false) {
                return null;
            }
            $blockLength = (ord($lenBytes[0]) << 16) | (ord($lenBytes[1]) << 8) | ord($lenBytes[2]);

            $streamInfo = fread($handle, $blockLength);
            if ($streamInfo === false || strlen($streamInfo) < 18) {
                return null;
            }

            // Parse streaminfo
            // Sample rate is at offset 10, 20 bits
            $sampleRate = (unpack('N', "\x00" . substr($streamInfo, 10, 3))[1] ?? 0) >> 12;
            if ($sampleRate === 0) {
                return null;
            }

            // Total samples is at offset 13, 4 bits (actually 36 bits but we only handle first 32)
            // For simplicity, we'll estimate from file size
            // This is an approximation - proper implementation would need to read the seektable

            $fileSize = filesize($path);
            if ($fileSize === false) {
                return null;
            }

            // Estimate duration based on average bitrate
            // This is a rough approximation
            return null; // Duration requires seektable or full decode
        } finally {
            fclose($handle);
        }
    }

    /**
     * Harvests tags from an M4A/AAC file (MP4 container).
     *
     * Reads the 'moov' atom and parses 'ilst' items.
     *
     * @param string $path Path to the M4A file
     * @return array<string, mixed> Parsed tag data
     */
    private function harvestMp4Tags(string $path): array
    {
        $tags = [];

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        try {
            // Find moov atom
            $atomMap = $this->findMp4Atoms($handle);

            if (isset($atomMap['moov'])) {
                fseek($handle, $atomMap['moov']);

                // Parse moov atom
                $tags = $this->parseMoovAtom($handle, filesize($path));
            }
        } finally {
            fclose($handle);
        }

        // Get duration from mvhd atom
        if (isset($atomMap['mvhd'])) {
            fopen($path, 'rb');
            // mvhd parsing would give us exact duration
        }

        return $tags;
    }

    /**
     * Finds MP4 atoms by scanning the file.
     *
     * @param resource $handle File handle
     * @return array<string, int> Map of atom names to offsets
     */
    private function findMp4Atoms($handle): array
    {
        $atoms = [];
        $fileSize = filesize(stream_get_meta_data($handle)['uri']);

        fseek($handle, 0);

        while (ftell($handle) < $fileSize - 8) {
            $header = fread($handle, 8);
            if ($header === false || strlen($header) < 8) {
                break;
            }

            $size = unpack('N', substr($header, 0, 4))[1] ?? 0;
            $type = substr($header, 4, 4);

            if ($size === 0) {
                $size = $fileSize - ftell($handle) + 8;
            }

            if ($size < 8) {
                break;
            }

            $offset = ftell($handle) - 8;

            if (in_array($type, ['moov', 'udta', 'meta', 'ilst', 'mvhd', 'trak'])) {
                $atoms[$type] = $offset;
            }

            fseek($handle, $offset + $size);
        }

        return $atoms;
    }

    /**
     * Parses the moov atom for metadata.
     *
     * @param resource $handle File handle
     * @param int $fileSize Total file size
     * @return array<string, mixed> Parsed tags
     */
    private function parseMoovAtom($handle, int $fileSize): array
    {
        $tags = [];
        $startPos = ftell($handle);

        while (ftell($handle) < $startPos + 0xFFFFFFFF && ftell($handle) < $fileSize - 8) {
            $header = fread($handle, 8);
            if ($header === false || strlen($header) < 8) {
                break;
            }

            $size = unpack('N', substr($header, 0, 4))[1] ?? 0;
            $type = substr($header, 4, 4);

            if ($size === 0) {
                $size = $fileSize - ftell($handle) + 8;
            }

            if ($size < 8) {
                break;
            }

            if ($type === 'udta' || $type === 'meta') {
                // Parse user data or metadata
                $atomEnd = ftell($handle) - 8 + $size;
                $this->parseMetaAtom($handle, $atomEnd, $tags);
            } elseif ($type === 'ilst') {
                $this->parseIlstAtom($handle, $tags);
            }

            fseek($handle, ftell($handle) - 8 + $size);
        }

        return $tags;
    }

    /**
     * Parses the meta atom for metadata.
     *
     * @param resource $handle File handle
     * @param int $endPos End position of meta atom
     * @param array<string, mixed> $tags Current tags array
     * @return void
     */
    private function parseMetaAtom($handle, int $endPos, array &$tags): void
    {
        // Skip 4 bytes after 'meta' (version/flags)
        fread($handle, 4);

        while (ftell($handle) < $endPos - 8) {
            $header = fread($handle, 8);
            if ($header === false || strlen($header) < 8) {
                break;
            }

            $size = unpack('N', substr($header, 0, 4))[1] ?? 0;
            $type = substr($header, 4, 4);

            if ($size === 0) {
                $size = $endPos - ftell($handle) + 8;
            }

            if ($size < 8) {
                break;
            }

            if ($type === 'ilst') {
                $this->parseIlstAtom($handle, $tags);
            }

            fseek($handle, ftell($handle) - 8 + $size);
        }
    }

    /**
     * Parses the ilst (item list) atom.
     *
     * @param resource $handle File handle
     * @param array<string, mixed> $tags Current tags array
     * @return void
     */
    private function parseIlstAtom($handle, array &$tags): void
    {
        while (!feof($handle)) {
            $header = fread($handle, 8);
            if ($header === false || strlen($header) < 8) {
                break;
            }

            $size = unpack('N', substr($header, 0, 4))[1] ?? 0;
            $type = substr($header, 4, 4);

            if ($size === 0 || $size < 8) {
                break;
            }

            // Check if it's a known mean/iles pair
            if (
                in_array($type, ['\xA9' . 'nam', '\xA9' . 'ART', '\xA9' . 'alb', '\xA9' . 'aART',
                '\xA9' . 'day', '\xA9' . 'gen', '\xA9' . 'trkn', '\xA9' . 'disk',
                '\xA9' . 'wrt', '\xA9' . 'cmt'])
            ) {
                $this->parseMp4Item($handle, $type, $tags);
            }

            fseek($handle, ftell($handle) - 8 + $size);
        }
    }

    /**
     * Parses an individual MP4 metadata item.
     *
     * @param resource $handle File handle
     * @param string $type Atom type
     * @param array<string, mixed> $tags Current tags array
     * @return void
     */
    private function parseMp4Item($handle, string $type, array &$tags): void
    {
        // Read data atom header
        $dataHeader = fread($handle, 8);
        if ($dataHeader === false || strlen($dataHeader) < 8) {
            return;
        }

        $dataSize = unpack('N', substr($dataHeader, 0, 4))[1] ?? 0;
        $dataType = substr($dataHeader, 4, 4);

        // Skip locale (4 bytes) at start of data
        fread($handle, 4);
        $dataSize -= 8;

        $value = fread($handle, $dataSize);
        if ($value === false) {
            return;
        }

        // Map to our tags
        switch ($type) {
            case '\xA9' . 'nam':
                $tags['title'] = trim($value);
                break;
            case '\xA9' . 'ART':
                $tags['artist'] = trim($value);
                break;
            case '\xA9' . 'alb':
                $tags['album'] = trim($value);
                break;
            case '\xA9' . 'aART':
                $tags['album_artist'] = trim($value);
                break;
            case '\xA9' . 'day':
                $tags['year'] = trim($value);
                break;
            case '\xA9' . 'gen':
                $tags['genre'] = [trim($value)];
                break;
            case '\xA9' . 'wrt':
                $tags['composer'] = trim($value);
                break;
            case '\xA9' . 'cmt':
                $tags['comment'] = trim($value);
                break;
            case '\xA9' . 'trkn':
                // Track number is stored as big-endian short
                if (strlen($value) >= 4) {
                    $trackNum = unpack('n', substr($value, 0, 2))[1] ?? 0;
                    $tags['track_number'] = $trackNum;
                }
                break;
            case '\xA9' . 'disk':
                if (strlen($value) >= 2) {
                    $discNum = unpack('n', substr($value, 0, 2))[1] ?? 0;
                    $tags['disc_number'] = $discNum;
                }
                break;
        }
    }

    /**
     * Harvests Vorbis comments from an OGG file.
     *
     * OGG containers store Vorbis comments in the first metadata packet.
     *
     * @param string $path Path to the OGG file
     * @return array<string, mixed> Parsed tag data
     */
    private function harvestVorbisTags(string $path): array
    {
        $tags = [];

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        try {
            // OGG page header is 27 bytes
            $pageHeader = fread($handle, 27);
            if ($pageHeader === false || strlen($pageHeader) < 27) {
                return [];
            }

            // Check OGGS magic bytes
            if (substr($pageHeader, 0, 4) !== 'OggS') {
                return [];
            }

            // Skip to vorbis comment (first packet starts after page header)
            // This is a simplified implementation - proper OGG parsing would handle
            // page segments and packet spanning

            // For now, read and look for vorbis comment header
            $capturePattern = fread($handle, 7);
            if ($capturePattern === false || strlen($capturePattern) < 7) {
                return [];
            }

            // Read more data to find vorbis comment
            $packetData = fread($handle, 1024 * 64); // Read up to 64KB for headers
            if ($packetData === false) {
                return [];
            }

            // Look for vorbis comment header (1 = comment, 3 = vendor length)
            $vorbisPos = strpos($packetData, "\x03\xvorbis");
            if ($vorbisPos !== false) {
                $commentData = substr($packetData, $vorbisPos - 4);
                $tags = $this->parseVorbisComments($commentData);
            }
        } finally {
            fclose($handle);
        }

        return $tags;
    }

    /**
     * Harvests basic info from a WAV file.
     *
     * WAV files use RIFF chunks. This reads the fmt and data chunks
     * to extract basic audio properties.
     *
     * @param string $path Path to the WAV file
     * @return array<string, mixed> Basic audio properties
     */
    private function harvestRiffTags(string $path): array
    {
        $tags = [];

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        try {
            $header = fread($handle, 12);
            if ($header === false || strlen($header) < 12) {
                return [];
            }

            // Check RIFF magic
            if (substr($header, 0, 4) !== 'RIFF' || substr($header, 8, 4) !== 'WAVE') {
                return [];
            }

            // Find fmt and data chunks
            while (!feof($handle)) {
                $chunkHeader = fread($handle, 8);
                if ($chunkHeader === false || strlen($chunkHeader) < 8) {
                    break;
                }

                $chunkSize = unpack('V', substr($chunkHeader, 4, 4))[1] ?? 0;
                $chunkType = substr($chunkHeader, 0, 4);

                if ($chunkType === 'fmt ') {
                    $fmtData = fread($handle, $chunkSize);
                    if ($fmtData !== false && strlen($fmtData) >= 16) {
                        $tags['sample_rate'] = unpack('v', substr($fmtData, 24, 2))[1] ?? 44100;
                        $tags['channels'] = unpack('v', substr($fmtData, 22, 2))[1] ?? 2;
                        $tags['bitrate'] = ((unpack('v', substr($fmtData, 34, 2))[1] ?? 0) / 8) * $tags['sample_rate'] * $tags['channels'];
                    }
                } elseif ($chunkType === 'data') {
                    // Calculate approximate duration
                    if (isset($tags['sample_rate']) && isset($tags['channels'])) {
                        $byteRate = $tags['sample_rate'] * $tags['channels'] * 2; // 16-bit
                        if ($byteRate > 0) {
                            $tags['duration_secs'] = (float)$chunkSize / $byteRate;
                        }
                    }
                }

                // Pad to word boundary
                if ($chunkSize % 2 !== 0) {
                    fread($handle, 1);
                }
            }
        } finally {
            fclose($handle);
        }

        return $tags;
    }

    /**
     * Harvests basic info from a WMA file.
     *
     * WMA uses ASF container format. This is a simplified parser
     * that extracts basic properties from the header.
     *
     * @param string $path Path to the WMA file
     * @return array<string, mixed> Basic audio properties
     */
    private function harvestAsfTags(string $path): array
    {
        $tags = [];

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        try {
            $header = fread($handle, 30);
            if ($header === false || strlen($header) < 30) {
                return [];
            }

            // Check ASF magic bytes
            if (substr($header, 0, 16) !== pack('H*', '3026b886d26e381c5f4b8a3c7021e9e4f7021e9e4')) {
                return [];
            }

            // ASF parsing is complex - for now just return empty
            // A proper implementation would parse the ASF header objects
        } finally {
            fclose($handle);
        }

        return $tags;
    }
}
