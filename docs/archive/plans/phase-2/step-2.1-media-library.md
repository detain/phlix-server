# Step 2.1: Media Library Management

**Phase:** 2 - Media Library & Metadata System  
**Plan File:** step-2.1-media-library.md  
**Objective:** Implement library manager, media scanner, folder watcher, and library API endpoints

---

## Overview

This step implements the media library management system including creating libraries, scanning media files, watching for changes, and managing library metadata.

**Prerequisites:** Phase 1 must be completed first.

---

## Tasks

### 2.1.1 Create Library Manager

Create `src/Media/Library/LibraryManager.php`:
```php
<?php

namespace Phlex\Media\Library;

use Phlex\Common\Database\Connection;
use Phlex\Common\Logger\StructuredLogger;
use Phlex\Common\Logger\LoggerFactory;
use Phlex\Common\Logger\LogChannels;

class LibraryManager
{
    private Connection $db;
    private StructuredLogger $logger;
    private LibraryScanner $scanner;
    private FolderWatcher $watcher;

    public function __construct(
        Connection $db,
        LibraryScanner $scanner,
        FolderWatcher $watcher
    ) {
        $this->db = $db;
        $this->scanner = $scanner;
        $this->watcher = $watcher;
        $this->logger = LoggerFactory::get(LogChannels::MEDIA);
    }

    public function createLibrary(string $name, string $type, array $paths, array $options = []): string
    {
        $id = $this->generateUuid();

        $this->db->query(
            "INSERT INTO libraries (id, name, type, paths, options) VALUES (?, ?, ?, ?, ?)",
            [$id, $name, $type, json_encode($paths), json_encode($options)]
        );

        $this->logger->info('Library created', ['library_id' => $id, 'name' => $name, 'type' => $type]);

        // Initial scan
        $this->scanLibrary($id);

        // Start watching for changes
        $this->watcher->watch($id, $paths);

        return $id;
    }

    public function getLibrary(string $id): ?array
    {
        $result = $this->db->query("SELECT * FROM libraries WHERE id = ?", [$id]);
        if (empty($result)) {
            return null;
        }
        $library = $result[0];
        $library['paths'] = json_decode($library['paths'], true);
        $library['options'] = json_decode($library['options'] ?? '{}', true);
        return $library;
    }

    public function getAllLibraries(): array
    {
        $results = $this->db->query("SELECT * FROM libraries ORDER BY display_order, name");
        return array_map(function ($lib) {
            $lib['paths'] = json_decode($lib['paths'], true);
            $lib['options'] = json_decode($lib['options'] ?? '{}', true);
            return $lib;
        }, $results);
    }

    public function updateLibrary(string $id, array $data): void
    {
        $sets = [];
        $values = [];
        
        if (isset($data['name'])) {
            $sets[] = 'name = ?';
            $values[] = $data['name'];
        }
        if (isset($data['paths'])) {
            $sets[] = 'paths = ?';
            $values[] = json_encode($data['paths']);
        }
        if (isset($data['options'])) {
            $sets[] = 'options = ?';
            $values[] = json_encode($data['options']);
        }
        
        if (empty($sets)) {
            return;
        }
        
        $values[] = $id;
        $this->db->query(
            "UPDATE libraries SET " . implode(', ', $sets) . " WHERE id = ?",
            $values
        );
        
        $this->logger->info('Library updated', ['library_id' => $id]);
    }

    public function deleteLibrary(string $id): void
    {
        $this->db->query("DELETE FROM libraries WHERE id = ?", [$id]);
        $this->logger->info('Library deleted', ['library_id' => $id]);
    }

    public function scanLibrary(string $libraryId): void
    {
        $library = $this->getLibrary($libraryId);
        if (!$library) {
            throw new \InvalidArgumentException("Library not found: $libraryId");
        }

        $this->logger->info('Starting library scan', ['library_id' => $libraryId, 'name' => $library['name']]);

        foreach ($library['paths'] as $path) {
            if (!is_dir($path)) {
                $this->logger->warning('Library path does not exist', ['path' => $path]);
                continue;
            }
            $this->scanner->scan($libraryId, $path, $library['type']);
        }

        $this->logger->info('Library scan complete', ['library_id' => $libraryId]);
    }

    public function rescanLibrary(string $libraryId): void
    {
        // Remove existing items
        $this->db->query("DELETE FROM media_items WHERE library_id = ?", [$libraryId]);
        
        // Rescan
        $this->scanLibrary($libraryId);
    }

    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
```

### 2.1.2 Create Media Scanner

Create `src/Media/Library/MediaScanner.php`:
```php
<?php

namespace Phlex\Media\Library;

use Phlex\Common\Database\Connection;
use Phlex\Common\Logger\LoggerFactory;
use Phlex\Common\Logger\LogChannels;

class MediaScanner
{
    private Connection $db;
    private StructuredLogger $logger;
    private array $namingOptions;
    private ItemRepository $itemRepository;

    public function __construct(Connection $db, ItemRepository $itemRepository)
    {
        $this->db = $db;
        $this->itemRepository = $itemRepository;
        $this->logger = LoggerFactory::get(LogChannels::MEDIA);
        $this->namingOptions = $this->loadNamingOptions();
    }

    private function loadNamingOptions(): array
    {
        return [
            'video' => ['mkv', 'mp4', 'avi', 'mov', 'wmv', 'flv', 'webm', 'm4v', 'mpg', 'mpeg', 'ts'],
            'audio' => ['mp3', 'flac', 'aac', 'ogg', 'wav', 'm4a', 'wma', 'alac', 'opus'],
            'image' => ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'tiff', 'tif'],
        ];
    }

    public function scan(string $libraryId, string $path, string $type): void
    {
        if (!is_dir($path)) {
            $this->logger->warning('Scan path does not exist', ['path' => $path]);
            return;
        }

        $extensions = $this->namingOptions[$type] ?? $this->namingOptions['video'];
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $scanned = 0;
        $skipped = 0;

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                continue;
            }

            $extension = strtolower($file->getExtension());
            if (!in_array($extension, $extensions)) {
                $skipped++;
                continue;
            }

            // Skip hidden files and system files
            if ($this->shouldSkipFile($file->getFilename())) {
                $skipped++;
                continue;
            }

            $this->processFile($libraryId, $file, $type);
            $scanned++;
        }

        $this->logger->info('Scan complete', [
            'library_id' => $libraryId,
            'path' => $path,
            'scanned' => $scanned,
            'skipped' => $skipped,
        ]);
    }

    private function shouldSkipFile(string $filename): bool
    {
        // Skip hidden files
        if (str_starts_with($filename, '.')) {
            return true;
        }

        // Skip system files
        $skipPatterns = ['.part', '.tmp', '_unpack', '.download', '.!ut'];
        foreach ($skipPatterns as $pattern) {
            if (str_contains($filename, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function processFile(string $libraryId, \SplFileInfo $file, string $type): void
    {
        $path = $file->getPathname();
        
        // Check if already exists
        $existing = $this->itemRepository->findByPath($path);
        if ($existing) {
            return; // Already scanned
        }

        // Determine media type
        $mediaType = $this->determineMediaType($file, $type);
        
        // Parse naming for series/movies
        $metadata = $this->parseNaming($file->getFilename(), $mediaType);

        // Create media item
        $itemId = $this->itemRepository->create([
            'library_id' => $libraryId,
            'name' => $metadata['name'] ?? $file->getBasename('.' . $file->getExtension()),
            'type' => $mediaType,
            'path' => $path,
            'metadata_json' => $metadata,
        ]);

        $this->logger->debug('Media file scanned', [
            'item_id' => $itemId,
            'name' => $metadata['name'] ?? 'unknown',
            'type' => $mediaType,
        ]);
    }

    private function determineMediaType(\SplFileInfo $file, string $libraryType): string
    {
        if ($libraryType !== 'video') {
            return $libraryType;
        }

        // Could add series episode detection here
        return 'movie';
    }

    private function parseNaming(string $filename, string $type): array
    {
        $metadata = [];
        
        // Remove extension
        $name = pathinfo($filename, PATHINFO_FILENAME);
        
        // Movie pattern: Movie Name (Year) or Movie Name.Year
        if ($type === 'movie') {
            if (preg_match('/(.+?)\s*[\(\[(\s*(\d{4})\s*\)\]\)]/', $name, $matches)) {
                $metadata['name'] = trim($matches[1]);
                $metadata['year'] = $matches[3] ?? null;
            } else {
                $metadata['name'] = $name;
            }
        }
        
        // Series pattern: Series S01E01 or Series - S01E01 - Episode Title
        if (preg_match('/^(.+?)\s*S(\d{2})E(\d{2})/i', $name, $matches)) {
            $metadata['name'] = trim($matches[1]);
            $metadata['season'] = (int)$matches[2];
            $metadata['episode'] = (int)$matches[3];
            
            // Extract episode title if present
            if (preg_match('/E\d{2}\s*-\s*(.+)$/', $name, $titleMatch)) {
                $metadata['episode_title'] = trim($titleMatch[1]);
            }
        }

        return $metadata;
    }
}
```

### 2.1.3 Create Folder Watcher

Create `src/Media/Library/FolderWatcher.php`:
```php
<?php

namespace Phlex\Media\Library;

use Phlex\Common\Logger\LoggerFactory;
use Phlex\Common\Logger\LogChannels;

class FolderWatcher
{
    private StructuredLogger $logger;
    private array $watchedPaths = [];
    private array $fileChecksums = [];
    private int $checkInterval = 30;
    private bool $running = false;

    public function __construct()
    {
        $this->logger = LoggerFactory::get(LogChannels::MEDIA);
    }

    public function watch(string $libraryId, array $paths): void
    {
        foreach ($paths as $path) {
            if (!is_dir($path)) {
                $this->logger->warning('Cannot watch non-existent path', ['path' => $path]);
                continue;
            }

            $this->watchedPaths[$path] = [
                'library_id' => $libraryId,
                'paths' => $paths,
            ];

            // Initial checksum scan
            $this->fileChecksums[$path] = $this->calculateDirectoryChecksum($path);

            $this->logger->info('Started watching path', ['path' => $path, 'library_id' => $libraryId]);
        }
    }

    public function unwatch(string $libraryId): void
    {
        foreach ($this->watchedPaths as $path => $info) {
            if ($info['library_id'] === $libraryId) {
                unset($this->watchedPaths[$path], $this->fileChecksums[$path]);
                $this->logger->info('Stopped watching path', ['path' => $path]);
            }
        }
    }

    public function checkForChanges(): array
    {
        $changes = [];

        foreach ($this->watchedPaths as $path => $info) {
            $newChecksum = $this->calculateDirectoryChecksum($path);
            
            if ($newChecksum !== $this->fileChecksums[$path]) {
                $changes[] = [
                    'library_id' => $info['library_id'],
                    'path' => $path,
                    'change_detected' => true,
                ];
                
                $this->fileChecksums[$path] = $newChecksum;
            }
        }

        return $changes;
    }

    private function calculateDirectoryChecksum(string $path): string
    {
        $checksum = '';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $files = [];
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname() . ':' . $file->getMTime();
            }
        }

        sort($files);
        foreach ($files as $file) {
            $checksum .= $file;
        }

        return md5($checksum);
    }

    public function getWatchedPaths(): array
    {
        return $this->watchedPaths;
    }

    public function setCheckInterval(int $seconds): void
    {
        $this->checkInterval = $seconds;
    }
}
```

### 2.1.4 Create Library Controller (API Endpoints)

Create `src/Server/Http/Controllers/LibraryController.php`:
```php
<?php

namespace Phlex\Server\Http\Controllers;

use Phlex\Server\Http\Request;
use Phlex\Server\Http\Response;
use Phlex\Media\Library\LibraryManager;

class LibraryController
{
    private LibraryManager $libraryManager;

    public function __construct(LibraryManager $libraryManager)
    {
        $this->libraryManager = $libraryManager;
    }

    public function index(Request $request, array $params): Response
    {
        $libraries = $this->libraryManager->getAllLibraries();
        return (new Response())->json(['libraries' => $libraries]);
    }

    public function show(Request $request, array $params): Response
    {
        $library = $this->libraryManager->getLibrary($params['id']);
        if (!$library) {
            return (new Response())->status(404)->json(['error' => 'Library not found']);
        }
        return (new Response())->json(['library' => $library]);
    }

    public function create(Request $request, array $params): Response
    {
        $data = $request->body;
        
        if (empty($data['name']) || empty($data['type']) || empty($data['paths'])) {
            return (new Response())->status(400)->json([
                'error' => 'Missing required fields: name, type, paths',
            ]);
        }

        $validTypes = ['movie', 'series', 'music', 'photo', 'video'];
        if (!in_array($data['type'], $validTypes)) {
            return (new Response())->status(400)->json([
                'error' => 'Invalid library type',
                'valid_types' => $validTypes,
            ]);
        }

        $libraryId = $this->libraryManager->createLibrary(
            $data['name'],
            $data['type'],
            $data['paths'],
            $data['options'] ?? []
        );

        return (new Response())->status(201)->json([
            'library_id' => $libraryId,
            'message' => 'Library created successfully',
        ]);
    }

    public function update(Request $request, array $params): Response
    {
        $data = $request->body;
        $library = $this->libraryManager->getLibrary($params['id']);
        
        if (!$library) {
            return (new Response())->status(404)->json(['error' => 'Library not found']);
        }

        $this->libraryManager->updateLibrary($params['id'], $data);

        return (new Response())->json(['message' => 'Library updated successfully']);
    }

    public function delete(Request $request, array $params): Response
    {
        $library = $this->libraryManager->getLibrary($params['id']);
        if (!$library) {
            return (new Response())->status(404)->json(['error' => 'Library not found']);
        }

        $this->libraryManager->deleteLibrary($params['id']);

        return (new Response())->json(['message' => 'Library deleted successfully']);
    }

    public function scan(Request $request, array $params): Response
    {
        $library = $this->libraryManager->getLibrary($params['id']);
        if (!$library) {
            return (new Response())->status(404)->json(['error' => 'Library not found']);
        }

        $this->libraryManager->scanLibrary($params['id']);

        return (new Response())->json(['message' => 'Library scan started']);
    }

    public function rescan(Request $request, array $params): Response
    {
        $library = $this->libraryManager->getLibrary($params['id']);
        if (!$library) {
            return (new Response())->status(404)->json(['error' => 'Library not found']);
        }

        $this->libraryManager->rescanLibrary($params['id']);

        return (new Response())->json(['message' => 'Library rescan started']);
    }
}
```

### 2.1.5 Create Unit Tests

Create `tests/unit/Media/Library/LibraryManagerTest.php`:
```php
<?php

namespace Phlex\Tests\Unit\Media\Library;

use PHPUnit\Framework\TestCase;
use Phlex\Media\Library\LibraryManager;
use Phlex\Media\Library\LibraryScanner;
use Phlex\Media\Library\FolderWatcher;
use Phlex\Media\Library\ItemRepository;
use Phlex\Common\Database\Connection;

class LibraryManagerTest extends TestCase
{
    public function testCanCreateLibraryManager(): void
    {
        $db = $this->createMock(Connection::class);
        $scanner = $this->createMock(LibraryScanner::class);
        $watcher = $this->createMock(FolderWatcher::class);
        
        $manager = new LibraryManager($db, $scanner, $watcher);
        
        $this->assertInstanceOf(LibraryManager::class, $manager);
    }
}
```

Create `tests/unit/Media/Library/MediaScannerTest.php`:
```php
<?php

namespace Phlex\Tests\Unit\Media\Library;

use PHPUnit\Framework\TestCase;
use Phlex\Media\Library\MediaScanner;

class MediaScannerTest extends TestCase
{
    public function testCanCreateMediaScanner(): void
    {
        $scanner = new MediaScanner(
            $this->createMock(\Phlex\Common\Database\Connection::class),
            $this->createMock(ItemRepository::class)
        );
        
        $this->assertInstanceOf(MediaScanner::class, $scanner);
    }
}
```

---

## Verification

After completing all tasks:

1. Run unit tests:
```bash
cd /home/sites/phlex
./vendor/bin/phpunit tests/unit/Media/Library/ --testdox
```

2. Verify classes exist:
```bash
ls -la /home/sites/phlex/src/Media/Library/
ls -la /home/sites/phlex/src/Server/Http/Controllers/
```

---

## Git Workflow

After verification, commit your changes:

```bash
cd /home/sites/phlex
git checkout -b step-2.1-media-library
git add .
git commit -m "Step 2.1: Implement media library management"
unset GITHUB_TOKEN
gh pr create --title "Step 2.1: Media Library Management" --body "Implements LibraryManager, MediaScanner, FolderWatcher, and LibraryController for media library operations."
gh pr merge --squash --delete-branch
git checkout master && git pull
```

---

## Next Step

After completing and merging this step, proceed to **Step 2.2: Metadata Fetching** (`plans/phase-2/step-2.2-metadata-fetching.md`).
