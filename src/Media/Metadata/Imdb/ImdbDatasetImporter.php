<?php

declare(strict_types=1);

namespace Phlix\Media\Metadata\Imdb;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use RuntimeException;
use Workerman\MySQL\Connection;

/**
 * Imports IMDb's free daily datasets into the local `imdb_titles` table.
 *
 * The importer downloads `title.basics.tsv.gz` and `title.ratings.tsv.gz` to a
 * configurable cache directory (with retry/back-off), loads the smaller ratings
 * file into an in-memory map, then streams the (much larger) basics file line by
 * line — filtering to the configured movie `titleType`s, joining the matching
 * rating, and UPSERTing rows into `imdb_titles` in multi-row batches
 * (`INSERT ... ON DUPLICATE KEY UPDATE`, ~500 rows per statement).
 *
 * Streaming (gzgets) rather than slurping keeps memory bounded — the basics
 * dataset is hundreds of MB uncompressed — which matters under Workerman's
 * resident-memory runtime.
 *
 * Both the HTTP download and the on-disk source-file paths are injectable so a
 * unit test can feed tiny local fixture `.tsv.gz` files and never touch the
 * network:
 *   - {@see self::setDownloader()} swaps the URL→path fetch implementation.
 *   - {@see self::importFromFiles()} skips downloading entirely and parses two
 *     already-present local gz files.
 *
 * Database access is exclusively through the async {@see Connection} client with
 * parameterised queries — never PDO/mysqli, never string-interpolated values.
 *
 * @package Phlix\Media\Metadata\Imdb
 * @since   0.21.0
 */
class ImdbDatasetImporter
{
    /** @var int Rows per multi-row INSERT ... ON DUPLICATE KEY UPDATE statement. */
    private const BATCH_SIZE = 500;

    /** @var int Columns written per row (keep in sync with the INSERT below). */
    private const COLUMNS_PER_ROW = 10;

    /** @var Connection Async MySQL connection used for all UPSERTs. */
    private Connection $db;

    /** @var string Source URL for title.basics.tsv.gz. */
    private string $basicsUrl;

    /** @var string Source URL for title.ratings.tsv.gz. */
    private string $ratingsUrl;

    /** @var string Directory the gz datasets are downloaded to / read from. */
    private string $cacheDir;

    /**
     * IMDb `titleType` values kept during import.
     *
     * @var list<string>
     */
    private array $titleTypes;

    /**
     * Download implementation: `fn(string $url, string $destination): bool`.
     * Defaults to a curl-based fetch with retry; overridable in tests.
     *
     * @var callable(string, string): bool
     */
    private $downloader;

    /**
     * @param Connection           $db         Async MySQL connection.
     * @param array<string, mixed> $config     The `config/imdb.php` array
     *                                          (basics_url, ratings_url,
     *                                          cache_dir, title_types).
     *
     * @since 0.21.0
     */
    public function __construct(Connection $db, array $config)
    {
        $this->db = $db;

        $basicsUrl = $config['basics_url'] ?? '';
        $ratingsUrl = $config['ratings_url'] ?? '';
        $cacheDir = $config['cache_dir'] ?? '';
        $titleTypes = $config['title_types'] ?? ['movie', 'tvMovie'];

        $this->basicsUrl = is_string($basicsUrl) ? $basicsUrl : '';
        $this->ratingsUrl = is_string($ratingsUrl) ? $ratingsUrl : '';
        $this->cacheDir = rtrim(is_string($cacheDir) ? $cacheDir : '', '/');

        $types = [];
        if (is_array($titleTypes)) {
            foreach ($titleTypes as $type) {
                if (is_string($type) && $type !== '') {
                    $types[] = $type;
                }
            }
        }
        $this->titleTypes = $types === [] ? ['movie', 'tvMovie'] : $types;

        $this->downloader = [$this, 'downloadWithRetry'];
    }

    /**
     * Override the download implementation (e.g. to point at local fixtures or
     * to mock the network in unit tests).
     *
     * @param callable(string, string): bool $downloader fn(url, destination): bool
     *
     * @since 0.21.0
     */
    public function setDownloader(callable $downloader): void
    {
        $this->downloader = $downloader;
    }

    /**
     * Download both datasets into the cache dir (unless already present) and
     * import them.
     *
     * @param bool $forceDownload Re-download even if the cached files exist.
     *
     * @return array{titles: int, ratings: int} Count of upserted titles and of
     *                                           ratings loaded into the join map.
     *
     * @throws RuntimeException When the cache dir is unusable or a download fails.
     *
     * @since 0.21.0
     */
    public function import(bool $forceDownload = false): array
    {
        if ($this->cacheDir === '') {
            throw new RuntimeException('IMDb cache directory is not configured.');
        }

        if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0775, true) && !is_dir($this->cacheDir)) {
            throw new RuntimeException("Unable to create IMDb cache directory: {$this->cacheDir}");
        }

        $basicsPath = $this->cacheDir . '/title.basics.tsv.gz';
        $ratingsPath = $this->cacheDir . '/title.ratings.tsv.gz';

        if ($forceDownload || !is_file($basicsPath)) {
            $this->fetch($this->basicsUrl, $basicsPath);
        }
        if ($forceDownload || !is_file($ratingsPath)) {
            $this->fetch($this->ratingsUrl, $ratingsPath);
        }

        return $this->importFromFiles($basicsPath, $ratingsPath);
    }

    /**
     * Import from two already-present local gz files, skipping any download.
     *
     * This is the seam unit tests use: write tiny fixture `.tsv.gz` files (via
     * `gzencode`) and pass their paths here.
     *
     * @param string $basicsPath  Path to a gzipped title.basics TSV.
     * @param string $ratingsPath Path to a gzipped title.ratings TSV.
     *
     * @return array{titles: int, ratings: int}
     *
     * @throws RuntimeException When a source file is missing or unreadable.
     *
     * @since 0.21.0
     */
    public function importFromFiles(string $basicsPath, string $ratingsPath): array
    {
        if (!is_file($basicsPath)) {
            throw new RuntimeException("IMDb basics file not found: {$basicsPath}");
        }
        if (!is_file($ratingsPath)) {
            throw new RuntimeException("IMDb ratings file not found: {$ratingsPath}");
        }

        $ratingsMap = $this->loadRatings($ratingsPath);
        $titlesUpserted = $this->streamBasics($basicsPath, $ratingsMap);

        $this->logger()->info('IMDb dataset import complete', [
            'titles' => $titlesUpserted,
            'ratings' => count($ratingsMap),
        ]);

        return [
            'titles' => $titlesUpserted,
            'ratings' => count($ratingsMap),
        ];
    }

    /**
     * Normalize a movie title for matching (lowercase, strip punctuation,
     * collapse whitespace, drop a leading article).
     *
     * Kept byte-faithful with {@see ImdbLookup::normalizeTitle()} so importer
     * and lookup agree on the stored key.
     *
     * @param string $title Raw title.
     *
     * @return string Normalized title.
     *
     * @since 0.21.0
     */
    public static function normalizeTitle(string $title): string
    {
        $normalized = mb_strtolower($title, 'UTF-8');
        $normalized = preg_replace('/[:\-\'\"!?\.]/u', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
        $normalized = trim($normalized);

        foreach (['the ', 'a ', 'an '] as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                $normalized = substr($normalized, strlen($prefix));
            }
        }

        return trim($normalized);
    }

    /**
     * Load `title.ratings.tsv.gz` into a tconst→[rating, votes] map.
     *
     * @param string $path Gzipped ratings TSV.
     *
     * @return array<string, array{rating: float|null, votes: int|null}>
     */
    private function loadRatings(string $path): array
    {
        $gz = gzopen($path, 'rb');
        if ($gz === false) {
            throw new RuntimeException("Unable to open IMDb ratings file: {$path}");
        }

        $headerLine = gzgets($gz);
        if ($headerLine === false) {
            gzclose($gz);
            return [];
        }
        $header = array_flip(explode("\t", trim($headerLine)));

        $tconstIdx = $header['tconst'] ?? null;
        $ratingIdx = $header['averageRating'] ?? null;
        $votesIdx = $header['numVotes'] ?? null;

        $map = [];
        if ($tconstIdx === null) {
            gzclose($gz);
            return $map;
        }

        while (($line = gzgets($gz)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                continue;
            }
            $fields = explode("\t", $line);
            $tconst = $fields[$tconstIdx] ?? '';
            if ($tconst === '') {
                continue;
            }

            $ratingRaw = $ratingIdx !== null ? ($fields[$ratingIdx] ?? '') : '';
            $votesRaw = $votesIdx !== null ? ($fields[$votesIdx] ?? '') : '';

            $map[$tconst] = [
                'rating' => ($ratingRaw === '' || $ratingRaw === '\\N') ? null : (float) $ratingRaw,
                'votes' => ($votesRaw === '' || $votesRaw === '\\N') ? null : (int) $votesRaw,
            ];
        }

        gzclose($gz);
        return $map;
    }

    /**
     * Stream `title.basics.tsv.gz`, filter to movie types, join ratings, and
     * UPSERT in batches.
     *
     * @param string                                                             $path       Gzipped basics TSV.
     * @param array<string, array{rating: float|null, votes: int|null}>          $ratingsMap Ratings join map.
     *
     * @return int Number of rows upserted.
     */
    private function streamBasics(string $path, array $ratingsMap): int
    {
        $gz = gzopen($path, 'rb');
        if ($gz === false) {
            throw new RuntimeException("Unable to open IMDb basics file: {$path}");
        }

        $headerLine = gzgets($gz);
        if ($headerLine === false) {
            gzclose($gz);
            return 0;
        }
        $header = array_flip(explode("\t", trim($headerLine)));

        $idx = [
            'tconst' => $header['tconst'] ?? null,
            'titleType' => $header['titleType'] ?? null,
            'primaryTitle' => $header['primaryTitle'] ?? null,
            'originalTitle' => $header['originalTitle'] ?? null,
            'startYear' => $header['startYear'] ?? null,
            'runtimeMinutes' => $header['runtimeMinutes'] ?? null,
            'genres' => $header['genres'] ?? null,
        ];

        if ($idx['tconst'] === null || $idx['titleType'] === null || $idx['primaryTitle'] === null) {
            gzclose($gz);
            return 0;
        }

        $typeSet = array_flip($this->titleTypes);

        $batch = [];
        $upserted = 0;

        while (($line = gzgets($gz)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                continue;
            }
            $fields = explode("\t", $line);

            $titleType = $fields[$idx['titleType']] ?? '';
            if (!isset($typeSet[$titleType])) {
                continue;
            }

            $tconst = $fields[$idx['tconst']] ?? '';
            $primaryTitle = $fields[$idx['primaryTitle']] ?? '';
            if ($tconst === '' || $primaryTitle === '') {
                continue;
            }

            $originalTitle = $idx['originalTitle'] !== null ? ($fields[$idx['originalTitle']] ?? '') : '';
            $startYearRaw = $idx['startYear'] !== null ? ($fields[$idx['startYear']] ?? '') : '';
            $runtimeRaw = $idx['runtimeMinutes'] !== null ? ($fields[$idx['runtimeMinutes']] ?? '') : '';
            $genresRaw = $idx['genres'] !== null ? ($fields[$idx['genres']] ?? '') : '';

            $rating = $ratingsMap[$tconst] ?? ['rating' => null, 'votes' => null];

            $batch[] = [
                $tconst,
                mb_substr($primaryTitle, 0, 512),
                ($originalTitle === '' || $originalTitle === '\\N') ? null : mb_substr($originalTitle, 0, 512),
                mb_substr(self::normalizeTitle($primaryTitle), 0, 255),
                mb_substr($titleType, 0, 20),
                ($startYearRaw === '' || $startYearRaw === '\\N') ? null : (int) $startYearRaw,
                ($genresRaw === '' || $genresRaw === '\\N') ? null : mb_substr($genresRaw, 0, 255),
                ($runtimeRaw === '' || $runtimeRaw === '\\N') ? null : (int) $runtimeRaw,
                $rating['rating'],
                $rating['votes'],
            ];

            if (count($batch) >= self::BATCH_SIZE) {
                $this->upsertBatch($batch);
                $upserted += count($batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $this->upsertBatch($batch);
            $upserted += count($batch);
        }

        gzclose($gz);
        return $upserted;
    }

    /**
     * UPSERT one batch of rows with a single multi-row
     * INSERT ... ON DUPLICATE KEY UPDATE statement.
     *
     * @param list<array{0: string, 1: string, 2: string|null, 3: string, 4: string, 5: int|null, 6: string|null, 7: int|null, 8: float|null, 9: int|null}> $rows
     */
    private function upsertBatch(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $placeholders = implode(
            ', ',
            array_fill(0, count($rows), '(' . implode(', ', array_fill(0, self::COLUMNS_PER_ROW, '?')) . ')')
        );

        $params = [];
        foreach ($rows as $row) {
            foreach ($row as $value) {
                $params[] = $value;
            }
        }

        $sql = 'INSERT INTO imdb_titles '
            . '(tconst, primary_title, original_title, normalized_title, title_type, '
            . 'start_year, genres, runtime_minutes, average_rating, num_votes) VALUES '
            . $placeholders
            . ' ON DUPLICATE KEY UPDATE '
            . 'primary_title = VALUES(primary_title), '
            . 'original_title = VALUES(original_title), '
            . 'normalized_title = VALUES(normalized_title), '
            . 'title_type = VALUES(title_type), '
            . 'start_year = VALUES(start_year), '
            . 'genres = VALUES(genres), '
            . 'runtime_minutes = VALUES(runtime_minutes), '
            . 'average_rating = VALUES(average_rating), '
            . 'num_votes = VALUES(num_votes)';

        $this->db->query($sql, $params);
    }

    /**
     * Fetch a URL to a destination path via the (possibly overridden)
     * downloader, raising on failure.
     */
    private function fetch(string $url, string $destination): void
    {
        if ($url === '') {
            throw new RuntimeException("No IMDb dataset URL configured for: {$destination}");
        }

        $ok = ($this->downloader)($url, $destination);
        if ($ok !== true) {
            throw new RuntimeException("Failed to download IMDb dataset: {$url}");
        }
    }

    /**
     * Default download implementation: curl with retry and exponential
     * back-off, mirroring the reference script's `downloadWithRetry()`.
     *
     * Note: this is only invoked from the importer CLI (a one-shot process),
     * never inline in an HTTP request handler, so the curl/sleep here does not
     * block the resident worker event loop.
     *
     * @param string $url         Source URL.
     * @param string $destination Local destination path.
     * @param int    $maxRetries  Maximum attempts.
     *
     * @return bool True on success.
     */
    private function downloadWithRetry(string $url, string $destination, int $maxRetries = 3): bool
    {
        $attempt = 0;
        $delay = 1;

        while ($attempt < $maxRetries) {
            $attempt++;

            $ch = curl_init($url);
            if ($ch === false) {
                return false;
            }

            $fh = fopen($destination, 'wb');
            if ($fh === false) {
                curl_close($ch);
                return false;
            }

            curl_setopt_array($ch, [
                CURLOPT_FILE => $fh,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 600,
                CURLOPT_USERAGENT => 'Phlix-IMDb-Importer/1.0',
            ]);

            $success = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            fclose($fh);
            curl_close($ch);

            if ($success !== false && $httpCode === 200) {
                return true;
            }

            if ($attempt < $maxRetries) {
                sleep($delay);
                $delay *= 2;
            }
        }

        return false;
    }

    /**
     * Resolve the MEDIA-channel logger, tolerating an uninitialised factory
     * (e.g. in a bare CLI/test context) by returning a no-throw fallback.
     */
    private function logger(): \Phlix\Common\Logger\StructuredLogger
    {
        return LoggerFactory::get(LogChannels::MEDIA);
    }
}
