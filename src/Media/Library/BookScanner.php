<?php

declare(strict_types=1);

namespace Phlex\Media\Library;

use Phlex\Common\Logger\LogChannels;
use Phlex\Common\Logger\StructuredLogger;
use Psr\Log\LoggerInterface;
use Workerman\MySQL\Connection;
use SplFileInfo;

/**
 * BookScanner discovers and indexes book files (EPUB, PDF, CBZ).
 *
 * This class extends MediaScanner to handle book-specific file formats,
 * extracting metadata from EPUB content.opf, PDF metadata, and CBZ
 * ComicInfo.xml files.
 *
 * @author Phlex Development Team
 * @version 1.0.0
 * @description Scanner for EPUB, PDF, and CBZ book files
 * @since 0.17.0
 */
class BookScanner extends MediaScanner
{
    /** @var array<string, array<string>> Supported book file extensions by format */
    private const BOOK_EXTENSIONS = [
        'epub' => ['epub'],
        'pdf' => ['pdf'],
        'cbz' => ['cbz'],
    ];

    /** @var StructuredLogger|null Logger instance */
    private ?StructuredLogger $logger = null;

    /**
     * Constructor for BookScanner.
     *
     * @param Connection $db Database connection
     * @param ItemRepository $itemRepository Repository for media item operations
     * @param StructuredLogger|null $logger Optional custom logger
     * @param \Psr\EventDispatcher\EventDispatcherInterface|null $eventDispatcher Optional PSR-14 dispatcher
     */
    public function __construct(
        Connection $db,
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
        $tempDir = sys_get_temp_dir() . '/phlex_media_' . uniqid();
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $config = [
            'handlers' => [
                'stream' => [
                    'type' => 'stream',
                    'path' => $tempDir . '/book_scanner.log',
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
     * Checks if an extension represents a book file.
     *
     * @param string $extension File extension (without dot)
     * @return bool True if the extension is a supported book format
     *
     * @since 0.17.0
     */
    public function isBookExtension(string $extension): bool
    {
        $extension = strtolower($extension);
        foreach (self::BOOK_EXTENSIONS as $extensions) {
            if (in_array($extension, $extensions, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Extracts metadata from an EPUB file.
     *
     * Parses the container.xml to find content.opf, then extracts
     * metadata elements including title, creator, publisher, ISBN,
     * language, publication date, and description. Also extracts
     * the cover image if present.
     *
     * @param string $path Absolute path to the EPUB file
     * @return array<string, mixed> Extracted metadata:
     *   - title: string|null
     *   - author: string|null
     *   - publisher: string|null
     *   - isbn: string|null
     *   - language: string|null
     *   - pub_date: string|null (ISO 8601)
     *   - description: string|null
     *   - cover_url: string|null (extracted cover image path)
     *
     * @since 0.17.0
     */
    public function harvestEpub(string $path): array
    {
        if (!file_exists($path) || !is_readable($path)) {
            return [];
        }

        $za = new \ZipArchive();
        if ($za->open($path) !== true) {
            return [];
        }

        try {
            // Find container.xml to locate content.opf
            $containerXml = $za->getFromName('META-INF/container.xml');
            if ($containerXml === false) {
                return [];
            }

            $container = @simplexml_load_string($containerXml);
            if ($container === false || !isset($container->rootfile)) {
                return [];
            }

            // Get the path to content.opf
            /** @var \SimpleXMLElement $rootfile */
            $rootfile = $container->rootfile;
            $opfPath = (string)($rootfile['full-path'] ?? '');
            if (empty($opfPath)) {
                return [];
            }

            // Parse content.opf
            $opfXml = $za->getFromName($opfPath);
            if ($opfXml === false) {
                return [];
            }

            $opf = @simplexml_load_string($opfXml);
            if ($opf === false) {
                return [];
            }

            // Register Dublin Core namespace
            $opf->registerXPathNamespace('dc', 'http://purl.org/dc/elements/1.1/');
            $opf->registerXPathNamespace('opf', 'http://www.idpf.org/2007/opf');

            $metadata = [];

            // Extract title
            $titleNodes = $opf->xpath('//dc:title');
            if (is_array($titleNodes) && count($titleNodes) > 0) {
                $metadata['title'] = trim((string)($titleNodes[0]));
            }

            // Extract creator (author)
            $creatorNodes = $opf->xpath('//dc:creator');
            if (is_array($creatorNodes) && count($creatorNodes) > 0) {
                $metadata['author'] = trim((string)($creatorNodes[0]));
            }

            // Extract publisher
            $publisherNodes = $opf->xpath('//dc:publisher');
            if (is_array($publisherNodes) && count($publisherNodes) > 0) {
                $metadata['publisher'] = trim((string)($publisherNodes[0]));
            }

            // Extract identifier (ISBN or URN)
            $identifierNodes = $opf->xpath('//dc:identifier');
            if (is_array($identifierNodes) && count($identifierNodes) > 0) {
                foreach ($identifierNodes as $identifier) {
                    $value = trim((string)$identifier);
                    // Check if it's an ISBN
                    $isbnPattern = '/(?:ISBN(?:-1[03])?:?\s*)?(?=[0-9X]{10}$|(?=(?:[0-9]+[-\s]){3})'
                        . '[-\s0-9X]{13}$|97[89][0-9]{10}$|(?=(?:[0-9]+[-\s]){4})'
                        . '[-\s0-9]{17}$)(?:97[89][-\s]?)?[0-9]{1,5}[-\s]?[0-9]+[-\s]?[0-9]+[-\s]?[0-9X]$/i';
                    if (preg_match($isbnPattern, $value)) {
                        $metadata['isbn'] = preg_replace('/[^0-9X]/', '', $value);
                        break;
                    }
                }
                // Fallback to first identifier as URN
                if (!isset($metadata['isbn'])) {
                    $metadata['urn'] = trim((string)($identifierNodes[0]));
                }
            }

            // Extract language
            $languageNodes = $opf->xpath('//dc:language');
            if (is_array($languageNodes) && count($languageNodes) > 0) {
                $metadata['language'] = trim((string)($languageNodes[0]));
            }

            // Extract publication date
            $dateNodes = $opf->xpath('//dc:date');
            if (is_array($dateNodes) && count($dateNodes) > 0) {
                $metadata['pub_date'] = trim((string)($dateNodes[0]));
            }

            // Extract description
            $descNodes = $opf->xpath('//dc:description');
            if (is_array($descNodes) && count($descNodes) > 0) {
                $metadata['description'] = trim((string)($descNodes[0]));
            }

            // Try to find cover image
            $coverMeta = $opf->xpath('//meta[@name="cover"]');
            if (is_array($coverMeta) && count($coverMeta) > 0) {
                $coverId = (string)($coverMeta[0]['content'] ?? '');
                if (!empty($coverId)) {
                    $coverItem = $opf->xpath('//*[@id="' . $coverId . '"]');
                    if (is_array($coverItem) && count($coverItem) > 0) {
                        $coverHref = (string)($coverItem[0]['href'] ?? '');
                        if (!empty($coverHref)) {
                            // Resolve relative path
                            $opfDir = dirname($opfPath);
                            if ($opfDir !== '.' && $opfDir !== '/') {
                                $coverPath = $opfDir . '/' . $coverHref;
                            } else {
                                $coverPath = $coverHref;
                            }
                            // Extract cover to temp file
                            $coverData = $za->getFromName($coverPath);
                            if ($coverData !== false) {
                                $ext = pathinfo($coverHref, PATHINFO_EXTENSION);
                                $tempCover = sys_get_temp_dir() . '/phlex_cover_' . uniqid() . '.' . $ext;
                                file_put_contents($tempCover, $coverData);
                                $metadata['cover_path'] = $tempCover;
                            }
                        }
                    }
                }
            }

            return $metadata;
        } catch (\Throwable $e) {
            $this->logger?->warning('Failed to parse EPUB metadata', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            return [];
        } finally {
            $za->close();
        }
    }

    /**
     * Extracts metadata from a PDF file.
     *
     * Uses exif_read_data() for XMP/EXIF metadata when available,
     * and falls back to pure-PHP PDF string parsing for basic fields.
     *
     * @param string $path Absolute path to the PDF file
     * @return array<string, mixed> Extracted metadata:
     *   - title: string|null
     *   - author: string|null
     *   - subject: string|null
     *   - keywords: string|null
     *   - creator: string|null
     *   - producer: string|null
     *   - creation_date: string|null
     *   - page_count: int|null
     *
     * @since 0.17.0
     */
    public function harvestPdf(string $path): array
    {
        if (!file_exists($path) || !is_readable($path)) {
            return [];
        }

        $metadata = [];

        // Try exif_read_data for XMP metadata
        try {
            $exif = @exif_read_data($path, 'PDF', true);
            if ($exif !== false) {
                if (!empty($exif['PDF']['Title'])) {
                    $metadata['title'] = trim($exif['PDF']['Title']);
                }
                if (!empty($exif['PDF']['Author'])) {
                    $metadata['author'] = trim($exif['PDF']['Author']);
                }
                if (!empty($exif['PDF']['Subject'])) {
                    $metadata['subject'] = trim($exif['PDF']['Subject']);
                }
                if (!empty($exif['PDF']['Keywords'])) {
                    $metadata['keywords'] = trim($exif['PDF']['Keywords']);
                }
                if (!empty($exif['PDF']['Creator'])) {
                    $metadata['creator'] = trim($exif['PDF']['Creator']);
                }
                if (!empty($exif['PDF']['Producer'])) {
                    $metadata['producer'] = trim($exif['PDF']['Producer']);
                }
                if (!empty($exif['PDF']['CreationDate'])) {
                    $metadata['creation_date'] = trim($exif['PDF']['CreationDate']);
                }
                if (!empty($exif['PDF']['PageCount'])) {
                    $metadata['page_count'] = (int)$exif['PDF']['PageCount'];
                }
            }
        } catch (\Throwable) {
            // Fall through to string parsing
        }

        // If we don't have page count yet, try to extract from PDF
        if (!isset($metadata['page_count'])) {
            $metadata['page_count'] = $this->extractPdfPageCount($path);
        }

        return $metadata;
    }

    /**
     * Extracts page count from a PDF file using string parsing.
     *
     * @param string $path Absolute path to the PDF file
     * @return int|null Page count or null if not found
     */
    private function extractPdfPageCount(string $path): ?int
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            // Read the end of the file to find page count
            fseek($handle, max(0, filesize($path) - 8192));
            $tail = fread($handle, 8192);
            if ($tail === false) {
                return null;
            }

            // Look for /PageCount or /Pages pattern
            if (preg_match('/\/PageCount\s+(\d+)/', $tail, $matches)) {
                return (int)$matches[1];
            }
            if (preg_match('/\/Pages\s+.*?\/Count\s+(\d+)/s', $tail, $matches)) {
                return (int)$matches[1];
            }

            return null;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Extracts metadata from a CBZ (comic book archive) file.
     *
     * CBZ files are ZIP archives containing images (typically JPEGs).
     * Parses ComicInfo.xml if present for extended metadata including
     * series, volume, and author information. Extracts the first image
     * as the cover.
     *
     * @param string $path Absolute path to the CBZ file
     * @return array<string, mixed> Extracted metadata:
     *   - title: string|null
     *   - series: string|null
     *   - volume: string|null
     *   - authors: array<string>|null
     *   - page_count: int|null
     *   - cover_page: int|null (1-based index of cover page)
     *   - cover_path: string|null (temporary extracted cover image path)
     *
     * @since 0.17.0
     */
    public function harvestCbz(string $path): array
    {
        if (!file_exists($path) || !is_readable($path)) {
            return [];
        }

        $za = new \ZipArchive();
        if ($za->open($path) !== true) {
            return [];
        }

        try {
            $metadata = [];

            // Look for ComicInfo.xml
            $comicInfoXml = $za->getFromName('ComicInfo.xml');
            if ($comicInfoXml !== false) {
                $comicInfo = @simplexml_load_string($comicInfoXml);
                if ($comicInfo !== false) {
                    // Register the ComicInfo namespace if present
                    $comicInfo->registerXPathNamespace('ci', 'http://www.astivian.com/comic');

                    if (!empty($comicInfo->Title)) {
                        $metadata['title'] = trim((string)$comicInfo->Title);
                    }
                    if (!empty($comicInfo->Series)) {
                        $metadata['series'] = trim((string)$comicInfo->Series);
                    }
                    if (!empty($comicInfo->Volume)) {
                        $metadata['volume'] = trim((string)$comicInfo->Volume);
                    }
                    if (!empty($comicInfo->Writer)) {
                        $writers = explode(',', (string)$comicInfo->Writer);
                        $metadata['authors'] = array_map('trim', $writers);
                    }
                    if (!empty($comicInfo->PageCount)) {
                        $metadata['page_count'] = (int)$comicInfo->PageCount;
                    }
                    if (!empty($comicInfo->Cover)) {
                        $metadata['cover_page'] = (int)$comicInfo->Cover + 1; // Convert 0-based to 1-based
                    }
                }
            }

            // Get list of image files and count pages
            $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $imageFiles = [];

            for ($i = 0; $i < $za->numFiles; $i++) {
                $name = $za->getNameIndex($i);
                if ($name === false) {
                    continue;
                }

                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (in_array($ext, $imageExtensions, true)) {
                    $imageFiles[] = $name;
                }
            }

            sort($imageFiles);

            if (!isset($metadata['page_count'])) {
                $metadata['page_count'] = count($imageFiles);
            }

            // Extract cover image (first image or explicitly marked cover)
            $coverIndex = isset($metadata['cover_page']) ? ($metadata['cover_page'] - 1) : 0;
            if ($coverIndex < 0 || $coverIndex >= count($imageFiles)) {
                $coverIndex = 0;
            }

            if (!empty($imageFiles) && isset($imageFiles[$coverIndex])) {
                $coverFile = $imageFiles[$coverIndex];
                $coverData = $za->getFromName($coverFile);
                if ($coverData !== false) {
                    $ext = pathinfo($coverFile, PATHINFO_EXTENSION);
                    $tempCover = sys_get_temp_dir() . '/phlex_cover_' . uniqid() . '.' . $ext;
                    file_put_contents($tempCover, $coverData);
                    $metadata['cover_path'] = $tempCover;
                }
            }

            return $metadata;
        } catch (\Throwable $e) {
            $this->logger?->warning('Failed to parse CBZ metadata', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            return [];
        } finally {
            $za->close();
        }
    }

    /**
     * Scans a book library directory and yields media item arrays.
     *
     * Recursively iterates through all files in the given path, filters by
     * supported book extensions (epub, pdf, cbz), skips hidden/system files,
     * extracts metadata, and yields item arrays.
     *
     * @param string $libraryId The library's unique identifier
     * @param string $libraryPath Filesystem path to scan
     * @return \Generator<int, array<string, mixed>> Yields media item data arrays
     *
     * @since 0.17.0
     */
    public function scanBookLibrary(string $libraryId, string $libraryPath): \Generator
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

            // Skip non-book files
            if (!$this->isBookExtension($extension)) {
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

            // Harvest metadata based on file type
            $metadata = match ($extension) {
                'epub' => $this->harvestEpub($file->getPathname()),
                'pdf' => $this->harvestPdf($file->getPathname()),
                'cbz' => $this->harvestCbz($file->getPathname()),
                default => [],
            };

            yield [
                'library_id' => $libraryId,
                'name' => $metadata['title'] ?? $file->getBasename('.' . $extension),
                'type' => 'book',
                'path' => $file->getPathname(),
                'metadata_json' => $metadata,
            ];
        }
    }

    /**
     * Determines if a file should be skipped during scanning.
     *
     * @param string $filename The filename to check
     * @return bool True if the file should be skipped
     */
    protected function shouldSkipFile(string $filename): bool
    {
        // Skip hidden files
        if (str_starts_with($filename, '.')) {
            return true;
        }

        // Skip system files
        $skipPatterns = ['.part', '.tmp', '.download', '.!ut'];
        foreach ($skipPatterns as $pattern) {
            if (str_contains($filename, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
