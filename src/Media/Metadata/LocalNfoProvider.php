<?php

declare(strict_types=1);

namespace Phlex\Media\Metadata;

/**
 * Local NFO file parser for movies and TV series metadata.
 *
 * This provider implements the MetadataProviderInterface to parse local NFO files
 * in XBMC/Kodi format. It supports parsing of movie, TV show, and episode NFO files,
 * both in XML format and simple ID-only format.
 *
 * ## Supported NFO Formats
 * - **Movie NFO**: movie.nfo - Contains movie metadata with TMDB/IMDB IDs
 * - **TV Show NFO**: tvshow.nfo - Contains series metadata with TVDB ID
 * - **Episode NFO**: episode.nfo - Contains episode-specific metadata
 *
 * ## XML Format Support
 * Parses standard XBMC/Kodi XML elements including:
 * - title, originaltitle, plot, year, premiered
 * - rating, votes, runtime, mpaa, tagline
 * - genre, studio, director, credits, actor
 * - tmdbid, imdbid, tvdbid
 *
 * ## Simple Format Support
 * Also supports simple format NFO files containing one ID per line:
 * - tmdb: 12345
 * - imdb: tt54321
 * - tvdb: 789
 *
 * ## Image Discovery
 * When parsing a directory, this provider can also discover related image files:
 * - posters: poster.jpg, cover.jpg, folder.jpg, movie.jpg, show.jpg, tvshow.jpg
 * - backdrops: fanart.jpg, backdrop.jpg, background.jpg, art.jpg
 *
 * @author Phlex Development Team
 * @version 1.0.0
 * @description Local NFO file parser for XBMC/Kodi format metadata
 * @see MetadataProviderInterface For provider contract
 * @see https://kodi.wiki/view/NFO_files for NFO format specification
 */
class LocalNfoProvider implements MetadataProviderInterface
{
    /** @var string Base media path for resolving relative NFO file paths */
    private string $mediaPath;

    /** @var array<string, mixed> In-memory cache for parsed NFO data (unused in current impl) */
    private array $cache = [];

    /**
     * Constructor for LocalNfoProvider.
     *
     * @param string $mediaPath Base path for media files (used for resolving relative paths)
     *                         Can be empty if absolute paths are always provided
     */
    public function __construct(string $mediaPath = '')
    {
        $this->mediaPath = rtrim($mediaPath, '/');
    }

    /**
     * Search is not supported by local NFO provider.
     *
     * Local NFO files require direct file path access. This method always returns
     * an empty array. Use getDetails() with an NFO file path instead.
     *
     * @param string $query Ignored
     * @param array<string, mixed> $options Ignored
     * @return array<int, mixed> Always empty - use getDetails() with file path
     */
    public function search(string $query, array $options = []): array
    {
        // Local NFO doesn't support search
        return [];
    }

    /**
     * Parse NFO file or directory containing NFO files.
     *
     * @param string $externalId File path to NFO file OR directory containing NFO files
     * @param array<string, mixed> $options Ignored
     * @return array<string, mixed> Parsed metadata including:
     *     - For movie.nfo: type, name, overview, year, rating, genres, studios, actors, external_ids
     *     - For tvshow.nfo: type, name, overview, year, status, genres, studios, actors, external_ids
     *     - For episode.nfo: type, name, overview, season_number, episode_number, aired, rating
     *     - For directory: ['type' => 'movie'|'tvshow'|'generic', 'metadata' => [...]]
     *     - Empty array if file/directory not found or parsing fails
     */
    public function getDetails(string $externalId, array $options = []): array
    {
        // If externalId is a file path, parse that NFO
        if (str_ends_with($externalId, '.nfo') || is_file($externalId)) {
            return $this->parseNfoFile($externalId);
        }

        // If it's a directory, look for NFO files
        if (is_dir($externalId)) {
            return $this->parseDirectory($externalId);
        }

        return [];
    }

    /**
     * Find local image files in the same directory as the NFO or specified path.
     *
     * @param string $externalId Directory path to search for images
     * @return array<string, array<int, array{
     *                url: string,
     *                type: string,
     *                filename: string
     *            }>> Images grouped by type:
     *            - posters: Poster images found
     *            - backdrops: Background/fanart images found
     *            - logos: Logo images found (if recognized)
     *            - thumbs: Thumbnail images found (if recognized)
     */
    public function getImages(string $externalId): array
    {
        // Local NFO typically doesn't contain images, but we can
        // look for related image files in the same directory
        if (is_dir($externalId)) {
            return $this->findLocalImages($externalId);
        }

        $dir = dirname($externalId);
        return $this->findLocalImages($dir);
    }

    /**
     * Get provider name aliases.
     *
     * @return array<string> Provider names: ['local', 'nfo']
     */
    public function getProviders(): array
    {
        return ['local', 'nfo'];
    }

    /**
     * {@inheritdoc}
     */
    public function getSourceName(): string
    {
        return 'local';
    }

    /**
     * Parse a movie NFO file.
     *
     * Supports both XML format and simple ID-per-line format.
     *
     * @param string $filePath Absolute path to movie.nfo file
     * @return array<string, mixed> Movie metadata including:
     *                           - type: 'movie'
     *                           - name, original_name, overview, year, premiered
     *                           - rating, votes, runtime, mpaa, tagline
     *                           - genres, studios, directors, credits, actors
     *                           - external_ids: ['tmdb' => ..., 'imdb' => ...]
     *                           - Empty array if file not found or parsing fails
     */
    public function parseMovieNfo(string $filePath): array
    {
        if (!file_exists($filePath)) {
            return [];
        }

        $content = file_get_contents($filePath);
        if (!$content) {
            return [];
        }

        // Try XML format first
        if (str_contains($content, '<?xml')) {
            return $this->parseXmlNfo($content, 'movie');
        }

        // Try simple format (one ID per line)
        return $this->parseSimpleNfo($content);
    }

    /**
     * Parse a TV show NFO file.
     *
     * Supports both XML format and simple ID-per-line format.
     *
     * @param string $filePath Absolute path to tvshow.nfo file
     * @return array<string, mixed> TV show metadata including:
     *                           - type: 'tvshow'
     *                           - name, original_name, overview, year, premiered
     *                           - rating, votes, status, episode_run_time
     *                           - genres, studios, actors
     *                           - external_ids: ['tvdb' => ..., 'imdb' => ...]
     *                           - Empty array if file not found or parsing fails
     */
    public function parseTvShowNfo(string $filePath): array
    {
        if (!file_exists($filePath)) {
            return [];
        }

        $content = file_get_contents($filePath);
        if (!$content) {
            return [];
        }

        // Try XML format first
        if (str_contains($content, '<?xml')) {
            return $this->parseXmlNfo($content, 'tvshow');
        }

        // Try simple format
        return $this->parseSimpleNfo($content);
    }

    /**
     * Parse an episode NFO file.
     *
     * Supports both XML format and simple ID-per-line format.
     *
     * @param string $filePath Absolute path to episode.nfo file
     * @return array<string, mixed> Episode metadata including:
     *                           - type: 'episode'
     *                           - name, overview, season_number, episode_number
     *                           - aired, rating, runtime
     *                           - director, credits
     *                           - Empty array if file not found or parsing fails
     */
    public function parseEpisodeNfo(string $filePath): array
    {
        if (!file_exists($filePath)) {
            return [];
        }

        $content = file_get_contents($filePath);
        if (!$content) {
            return [];
        }

        if (str_contains($content, '<?xml')) {
            return $this->parseXmlNfo($content, 'episodedetails');
        }

        return $this->parseSimpleNfo($content);
    }

    /**
     * Find and parse all NFO files in a directory.
     *
     * Searches for tvshow.nfo (TV series) or movie.nfo (movie) in the directory.
     * Falls back to any .nfo file if standard files not found.
     *
     * @param string $dirPath Absolute path to media directory
     * @return array<string, mixed> Result including:
     *                           - type: 'tvshow' | 'movie' | 'generic' | 'unknown'
     *                           - metadata: Parsed NFO data or empty if no NFO found
     *                           - 'unknown' type with empty metadata if no NFO files found
     */
    public function parseDirectory(string $dirPath): array
    {
        $dirPath = rtrim($dirPath, '/');
        $result = [
            'type' => 'unknown',
            'metadata' => [],
        ];

        // Look for tvshow.nfo (TV series)
        $tvshowPath = $dirPath . '/tvshow.nfo';
        if (file_exists($tvshowPath)) {
            $result['type'] = 'tvshow';
            $result['metadata'] = $this->parseTvShowNfo($tvshowPath);
            return $result;
        }

        // Look for movie.nfo or a single .nfo file with movie content
        $moviePath = $dirPath . '/movie.nfo';
        if (file_exists($moviePath)) {
            $result['type'] = 'movie';
            $result['metadata'] = $this->parseMovieNfo($moviePath);
            return $result;
        }

        // Look for any .nfo file
        $nfoFiles = glob($dirPath . '/*.nfo');
        if (!empty($nfoFiles)) {
            $result['type'] = 'generic';
            $result['metadata'] = $this->parseNfoFile($nfoFiles[0]);
            return $result;
        }

        return $result;
    }

    /**
     * Parse an NFO file, auto-detecting format and type.
     *
     * @param string $filePath Path to NFO file (with or without .nfo extension)
     * @return array<string, mixed> Parsed metadata based on file content/type
     */
    private function parseNfoFile(string $filePath): array
    {
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);

        if ($extension !== 'nfo') {
            $filePath .= '.nfo';
        }

        if (!file_exists($filePath)) {
            return [];
        }

        // Determine type by filename
        $filename = basename($filePath);

        if ($filename === 'tvshow.nfo') {
            return $this->parseTvShowNfo($filePath);
        }

        if ($filename === 'movie.nfo') {
            return $this->parseMovieNfo($filePath);
        }

        // Check content to determine type
        $content = file_get_contents($filePath);

        if (str_contains($content, 'episodedetails')) {
            return $this->parseEpisodeNfo($filePath);
        }

        if (str_contains($content, '<tvshow')) {
            return $this->parseTvShowNfo($filePath);
        }

        // Default to movie format
        return $this->parseMovieNfo($filePath);
    }

    /**
     * Parse XML format NFO content.
     *
     * @param string $content Raw XML content
     * @param string $type Content type: 'movie', 'tvshow', 'episodedetails'
     * @return array<string, mixed> Parsed XML metadata
     */
    private function parseXmlNfo(string $content, string $type): array
    {
        $xml = @simplexml_load_string($content);

        if (!$xml) {
            return $this->parseSimpleNfo($content);
        }

        return match ($type) {
            'movie' => $this->extractMovieFromXml($xml),
            'tvshow' => $this->extractTvShowFromXml($xml),
            'episodedetails' => $this->extractEpisodeFromXml($xml),
            default => [],
        };
    }

    /**
     * Extract movie metadata from XML element.
     *
     * @param SimpleXMLElement $xml Parsed XML element
     * @return array<string, mixed> Movie metadata with structure:
     *                           - type: 'movie'
     *                           - name, original_name, overview, year, premiered
     *                           - rating, votes, runtime, mpaa, tagline
     *                           - genres, studios, credits, directors, actors
     *                           - external_ids: ['tmdb' => ..., 'imdb' => ...]
     */
    private function extractMovieFromXml(\SimpleXMLElement $xml): array
    {
        $result = [
            'type' => 'movie',
            'name' => (string) ($xml->title ?? ''),
            'original_name' => (string) ($xml->originaltitle ?? ''),
            'overview' => (string) ($xml->plot ?? ''),
            'year' => $this->extractYear((string) ($xml->year ?? '')),
            'premiered' => (string) ($xml->premiered ?? ''),
            'rating' => $this->parseFloat((string) ($xml->rating ?? 0)),
            'votes' => $this->parseInt((string) ($xml->votes ?? 0)),
            'runtime' => $this->parseInt((string) ($xml->runtime ?? 0)),
            'mpaa' => (string) ($xml->mpaa ?? ''),
            'tagline' => (string) ($xml->tagline ?? ''),
            'genres' => $this->extractGenres($xml),
            'studios' => $this->extractStudios($xml),
            'credits' => $this->extractCredits($xml),
            'directors' => $this->extractDirectors($xml),
            'actors' => $this->extractActors($xml),
        ];

        // Extract external IDs
        $result['external_ids'] = [
            'tmdb' => (string) ($xml->tmdbid ?? ''),
            'imdb' => (string) ($xml->imdbid ?? ''),
        ];

        return array_filter($result, fn($v) => $v !== '' && $v !== null && $v !== []);
    }

    /**
     * Extract TV show metadata from XML element.
     *
     * @param SimpleXMLElement $xml Parsed XML element
     * @return array<string, mixed> TV show metadata with structure:
     *                           - type: 'tvshow'
     *                           - name, original_name, overview, year, premiered
     *                           - rating, votes, status, episode_run_time
     *                           - genres, studios, actors
     *                           - external_ids: ['tvdb' => ..., 'imdb' => ...]
     */
    private function extractTvShowFromXml(\SimpleXMLElement $xml): array
    {
        $result = [
            'type' => 'tvshow',
            'name' => (string) ($xml->title ?? ''),
            'original_name' => (string) ($xml->originaltitle ?? ''),
            'overview' => (string) ($xml->plot ?? ''),
            'year' => $this->extractYear((string) ($xml->premiered ?? '')),
            'premiered' => (string) ($xml->premiered ?? ''),
            'rating' => $this->parseFloat((string) ($xml->rating ?? 0)),
            'votes' => $this->parseInt((string) ($xml->votes ?? 0)),
            'status' => (string) ($xml->status ?? ''),
            'episode_run_time' => $this->parseInt((string) ($xml->episode_run_time ?? 0)),
            'genres' => $this->extractGenres($xml),
            'studios' => $this->extractStudios($xml),
            'actors' => $this->extractActors($xml),
        ];

        // Extract TVDB ID
        $result['external_ids'] = [
            'tvdb' => (string) ($xml->tvdbid ?? ''),
            'imdb' => (string) ($xml->imdbid ?? ''),
        ];

        return array_filter($result, fn($v) => $v !== '' && $v !== null && $v !== []);
    }

    /**
     * Extract episode metadata from XML element.
     *
     * @param SimpleXMLElement $xml Parsed XML element
     * @return array<string, mixed> Episode metadata with structure:
     *                           - type: 'episode'
     *                           - name, overview, season_number, episode_number
     *                           - aired, rating, runtime
     *                           - director, credits
     */
    private function extractEpisodeFromXml(\SimpleXMLElement $xml): array
    {
        $result = [
            'type' => 'episode',
            'name' => (string) ($xml->title ?? ''),
            'overview' => (string) ($xml->plot ?? ''),
            'season_number' => $this->parseInt((string) ($xml->season ?? 0)),
            'episode_number' => $this->parseInt((string) ($xml->episode ?? 0)),
            'aired' => (string) ($xml->aired ?? ''),
            'rating' => $this->parseFloat((string) ($xml->rating ?? 0)),
            'runtime' => $this->parseInt((string) ($xml->runtime ?? 0)),
            'director' => (string) ($xml->director ?? ''),
            'credits' => (string) ($xml->credits ?? ''),
        ];

        return array_filter($result, fn($v) => $v !== '' && $v !== null && $v !== []);
    }

    /**
     * Extract genre elements from XML.
     *
     * Genres can be either multiple <genre> elements or pipe-separated within
     * a single genre element.
     *
     * @param SimpleXMLElement $xml Parsed XML element
     * @return array<int, string> Array of unique, non-empty genre names
     */
    private function extractGenres(\SimpleXMLElement $xml): array
    {
        $genres = [];

        if (isset($xml->genre)) {
            foreach ($xml->genre as $genre) {
                $genreStr = trim((string) $genre);
                if (!empty($genreStr)) {
                    // Genres can be pipe-separated or multiple elements
                    $parts = array_filter(array_map('trim', explode('|', $genreStr)));
                    $genres = array_merge($genres, $parts);
                }
            }
        }

        return array_unique(array_filter($genres));
    }

    /**
     * Extract studio elements from XML.
     *
     * @param SimpleXMLElement $xml Parsed XML element
     * @return array<int, string> Array of studio names
     */
    private function extractStudios(\SimpleXMLElement $xml): array
    {
        $studios = [];

        if (isset($xml->studio)) {
            foreach ($xml->studio as $studio) {
                $studioStr = trim((string) $studio);
                if (!empty($studioStr)) {
                    $studios[] = $studioStr;
                }
            }
        }

        return $studios;
    }

    /**
     * Extract writer credits from XML.
     *
     * @param SimpleXMLElement $xml Parsed XML element
     * @return array<int, string> Array of writer names
     */
    private function extractCredits(\SimpleXMLElement $xml): array
    {
        $credits = [];

        if (isset($xml->credits)) {
            foreach ($xml->credits as $credit) {
                $creditStr = trim((string) $credit);
                if (!empty($creditStr)) {
                    $credits[] = $creditStr;
                }
            }
        }

        return $credits;
    }

    /**
     * Extract director names from XML.
     *
     * @param SimpleXMLElement $xml Parsed XML element
     * @return array<int, string> Array of director names
     */
    private function extractDirectors(\SimpleXMLElement $xml): array
    {
        $directors = [];

        if (isset($xml->director)) {
            foreach ($xml->director as $director) {
                $directorStr = trim((string) $director);
                if (!empty($directorStr)) {
                    $directors[] = $directorStr;
                }
            }
        }

        return $directors;
    }

    /**
     * Extract actor information from XML.
     *
     * @param SimpleXMLElement $xml Parsed XML element
     * @return array<int, array{
     *                name: string,
     *                role: string,
     *                order: int
     *            }> Array of actor details
     */
    private function extractActors(\SimpleXMLElement $xml): array
    {
        $actors = [];

        if (isset($xml->actor)) {
            $order = 0;
            foreach ($xml->actor as $actor) {
                $name = trim((string) ($actor->name ?? ''));
                $role = trim((string) ($actor->role ?? ''));

                if (!empty($name)) {
                    $actors[] = [
                        'name' => $name,
                        'role' => $role,
                        'order' => $order++,
                    ];
                }
            }
        }

        return $actors;
    }

    /**
     * Parse simple ID-per-line NFO format.
     *
     * Simple format uses one ID per line with patterns like:
     * - tmdb: 12345
     * - imdb: tt54321
     * - tvdb: 789
     *
     * @param string $content Raw NFO content
     * @return array<string, mixed> Metadata with external_ids and type
     */
    private function parseSimpleNfo(string $content): array
    {
        $lines = array_filter(array_map('trim', explode("\n", $content)));
        $result = [
            'type' => 'movie',
            'external_ids' => [],
        ];

        foreach ($lines as $line) {
            // Skip XML tags and empty lines
            if (empty($line) || str_starts_with($line, '<')) {
                continue;
            }

            // Look for ID patterns
            if (preg_match('/(tmdb|themoviedb)[^\d]*(\d+)/i', $line, $matches)) {
                $result['external_ids']['tmdb'] = $matches[2];
            }
            if (preg_match('/(imdb|tt)(\d+)/i', $line, $matches)) {
                $result['external_ids']['imdb'] = 'tt' . $matches[2];
            }
            if (preg_match('/(tvdb|thetvdb)[^\d]*(\d+)/i', $line, $matches)) {
                $result['external_ids']['tvdb'] = $matches[2];
                $result['type'] = 'tvshow';
            }
        }

        return $result;
    }

    /**
     * Find local image files in a directory.
     *
     * Searches for common image naming patterns:
     * - Posters: poster, cover, folder, movie, show, tvshow
     * - Backdrops: fanart, backdrop, background, art
     *
     * @param string $dirPath Directory path to search
     * @return array<string, array<int, array{
     *                url: string,
     *                type: string,
     *                filename: string
     *            }>> Images grouped by type (posters, backdrops, logos, thumbs)
     */
    private function findLocalImages(string $dirPath): array
    {
        $images = [
            'posters' => [],
            'backdrops' => [],
            'logos' => [],
            'thumbs' => [],
        ];

        // Common poster filenames
        $posterPatterns = ['poster', 'cover', 'folder', 'movie', 'show', 'tvshow'];
        // Common backdrop/fanart filenames
        $backdropPatterns = ['fanart', 'backdrop', 'background', 'art'];

        $files = glob($dirPath . '/*.{jpg,jpeg,png}', GLOB_BRACE);

        foreach ($files as $file) {
            $filename = strtolower(basename($file, '.jpg'));
            $filename = strtok($filename, '.'); // Remove any suffix after extension

            foreach ($posterPatterns as $pattern) {
                if (str_contains($filename, $pattern)) {
                    $images['posters'][] = [
                        'url' => $file,
                        'type' => 'local',
                        'filename' => basename($file),
                    ];
                    break;
                }
            }

            foreach ($backdropPatterns as $pattern) {
                if (str_contains($filename, $pattern)) {
                    $images['backdrops'][] = [
                        'url' => $file,
                        'type' => 'local',
                        'filename' => basename($file),
                    ];
                    break;
                }
            }
        }

        return array_filter($images, fn($v) => !empty($v));
    }

    /**
     * Extract year from date string.
     *
     * @param string $dateStr Date string (e.g., '2023-05-15', '2023')
     * @return int|null Extracted year or null if not found
     */
    private function extractYear(string $dateStr): ?int
    {
        if (empty($dateStr)) {
            return null;
        }

        if (preg_match('/(\d{4})/', $dateStr, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Parse float value from string.
     *
     * @param string $value String value to parse
     * @return float|null Parsed float or null if invalid
     */
    private function parseFloat(string $value): ?float
    {
        $value = trim($value);
        if ($value === '' || !is_numeric($value)) {
            return null;
        }
        return (float) $value;
    }

    /**
     * Parse integer value from string.
     *
     * @param string $value String value to parse
     * @return int|null Parsed integer or null if invalid
     */
    private function parseInt(string $value): ?int
    {
        $value = trim($value);
        if ($value === '' || !is_numeric($value)) {
            return null;
        }
        return (int) $value;
    }
}
