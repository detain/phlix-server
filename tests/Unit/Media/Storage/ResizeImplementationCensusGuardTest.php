<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Storage;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\PhotoLibraryManager;
use Phlix\Media\Metadata\ExifProvider;
use Phlix\Media\Storage\AvatarStorage;
use Phlix\Server\Http\Controllers\PhotoController;
use Phlix\Server\Http\Request;
use Workerman\MySQL\Connection;

/**
 * S456 — the resize-implementation census, EXECUTABLE, plus the two golden-byte
 * delegation pins that make the consolidation falsifiable.
 *
 * ## Lineage (why the denominator is what it is)
 *
 * S71's plan block (plan_updates.md) recorded THREE resize implementations in
 * the estate: ArtworkStorage (poster pipeline), AvatarStorage (square cover-fit
 * crop) and PhotoController::generateThumbnail() (photo-library cover/contain
 * fit). S71 moved the ArtworkStorage pipeline into ImageResizer; S72/S73 grew
 * that service further (lazy single-variant resize, byte-verbatim logo store).
 * S456 finishes the job: the AvatarStorage crop arithmetic moved verbatim to
 * ImageResizer::renderSquareCoverJpeg() and the PhotoController thumbnail
 * arithmetic moved verbatim to ImageResizer::renderFitJpeg(). The callers kept
 * their own validation, storage layout and HTTP layers — AvatarStorage still
 * enforces its stricter 5 MB upload rule and 'Avatar …' messages; the photo
 * route still answers its own 404/500 shapes.
 *
 * Measured denominator at this tip: 774 *.php files under src/, of which
 * exactly ONE contains a GD pixel-resample token (imagecopyresampled /
 * imagecopyresized): src/Media/Storage/ImageResizer.php. If a fifth resizer is
 * ever planted, or this file count shifts, re-measure and re-pin HERE (same
 * doctrine as the S427/S431 estate census re-pin), never by loosening the pin.
 *
 * ## Mutation-provability, declared up front
 *
 * The census could go vacuously green on a broken scanner, so the planted-fork
 * test copies a real src/ fixture into a temp tree, plants a genuinely used
 * imagecopyresampled() call, and requires the scanner to report it — an empty
 * scan of a planted tree is a wiring failure, not a pass. The two golden-byte
 * pins run the moved algorithms' PRE-S456 inline GD sequences verbatim inside
 * the test and demand byte equality with what AvatarStorage::store() /
 * PhotoController::getThumbnail() now produce THROUGH ImageResizer — mutating
 * either render primitive (geometry, fit rule, transparency axis, quality)
 * reddens a named test, proving the delegation is load-bearing, not decorative.
 */
final class ResizeImplementationCensusGuardTest extends TestCase
{
    /** Lane survival token (S456). Code-resident by design; never prose. */
    public const string SURVIVAL_TOKEN = 'CS456RESIZECONSOLX9J';

    /** GD pixel-resample function names that mark a resize implementation. */
    private const RESIZE_TOKENS = ['imagecopyresampled', 'imagecopyresized'];

    /** The estate's only permitted resize home, relative to src/. */
    private const ALLOWED_RESIZE_FILES = ['Media/Storage/ImageResizer.php'];

    /**
     * Floor from the lineage above (774 src/ files at the S456 tip). A partial
     * tree must fail loudly instead of passing an empty-or-small scan.
     */
    private const MIN_SCANNED_SRC_FILES = 700;

    public function testSurvivalTokenIsResident(): void
    {
        $this->assertSame('CS456RESIZECONSOLX9J', self::SURVIVAL_TOKEN);
    }

    public function testSrcEstateHasExactlyOneResizeImplementation(): void
    {
        $srcDir = \dirname(__DIR__, 4) . '/src';
        $resizeFiles = [];
        $scanned = 0;
        foreach (self::phpFiles($srcDir) as $file) {
            $scanned++;
            if (self::containsResizeToken($file)) {
                $resizeFiles[] = substr($file, strlen($srcDir) + 1);
            }
        }

        $this->assertGreaterThanOrEqual(
            self::MIN_SCANNED_SRC_FILES,
            $scanned,
            'resize census ran on a partial src/ tree — estate missing?',
        );
        sort($resizeFiles);
        $this->assertSame(
            self::ALLOWED_RESIZE_FILES,
            $resizeFiles,
            'a second resize implementation appeared under src/ — consolidate it onto '
            . 'ImageResizer (renderSquareCoverJpeg/renderFitJpeg/resizeToWidths/resizeOneWidth) '
            . 'or re-measure and re-pin this census with lineage, do not just widen the pin.',
        );
    }

    public function testPlantedForkedResizerIsDetected(): void
    {
        $fixture = \dirname(__DIR__, 4) . '/src/Media/Storage/AvatarStorage.php';
        $tmpDir = $this->makeTempDir();
        try {
            $src = (string) file_get_contents($fixture);
            // Plant a genuinely-invoked resample inside the file, mirroring how
            // a forked thumbnailer would call GD directly.
            $planted = str_replace(
                'public function delete(string $userId): void',
                'public function plantedForkResize(\GdImage $dst, \GdImage $src): bool'
                . ' { return imagecopyresampled($dst, $src, 0, 0, 0, 0, 10, 10, 20, 20); }'
                . ' public function delete(string $userId): void',
                $src,
            );
            $this->assertNotSame($src, $planted, 'fixture anchor missing — planted-fork test is stale');
            file_put_contents($tmpDir . '/PlantedFork.php', $planted);

            $hits = [];
            foreach (self::phpFiles($tmpDir) as $file) {
                if (self::containsResizeToken($file)) {
                    $hits[] = basename($file);
                }
            }
            $this->assertSame(['PlantedFork.php'], $hits, 'planted resize call must be detected');
        } finally {
            $this->removeDirectory($tmpDir);
        }
    }

    /**
     * Golden-byte pin for the AVATAR delegation: the stored file must equal,
     * byte for byte, what the PRE-S456 inline algorithm in AvatarStorage::
     * resizeToAvatar() produced — reproduced verbatim below as the oracle.
     */
    public function testAvatarStoreBytesEqualThePreS456InlineAlgorithm(): void
    {
        $tmpDir = $this->makeTempDir();
        try {
            $sourcePath = $tmpDir . '/wide-source.jpg';
            $source = imagecreatetruecolor(1000, 500);
            $this->assertNotFalse($source);
            $red = \imagecolorallocate($source, 255, 0, 0);
            imagefill($source, 0, 0, $red);
            // Spatial variation so crop-WINDOW mutations (offset, scale) change
            // bytes — a solid fill would hide them behind identical pixels.
            $white = \imagecolorallocate($source, 255, 255, 255);
            imagefilledrectangle($source, 300, 100, 700, 400, $white);
            $this->assertNotFalse(imagejpeg($source, $sourcePath, 95));
            imagedestroy($source);

            $expected = self::legacySquareCoverJpeg($sourcePath, 256);
            $this->assertNotNull($expected, 'oracle must produce bytes');

            $storage = new AvatarStorage($tmpDir . '/avatars/');
            $storedPath = $storage->store('user-golden', $sourcePath);

            $this->assertSame(
                $expected,
                (string) file_get_contents($storedPath),
                'AvatarStorage output drifted from the moved algorithm — the delegation is not byte-exact',
            );
        } finally {
            $this->removeDirectory($tmpDir);
        }
    }

    /**
     * Golden-byte pin for the PHOTO-ROUTE delegation: getThumbnail's served
     * bytes must equal the PRE-S456 inline PhotoController::generateThumbnail()
     * algorithm — reproduced verbatim below as the oracle — for a non-square
     * source in BOTH fit modes (the cover/contain fork and the truncating-cast
     * geometry are exactly what a mutation would perturb).
     */
    public function testPhotoThumbnailBytesEqualThePreS456InlineAlgorithm(): void
    {
        $db = $this->createMock(Connection::class);
        $itemRepo = new ItemRepository($db);
        /** @var PhotoLibraryManager&MockObject $photoManager */
        $photoManager = $this->createMock(PhotoLibraryManager::class);
        $controller = new PhotoController($itemRepo, $photoManager, new ExifProvider($itemRepo));

        $tempDir = sys_get_temp_dir();
        $testFile = $tempDir . '/s456_golden_' . uniqid('', true) . '.jpg';
        $source = imagecreatetruecolor(400, 200);
        $this->assertNotFalse($source);
        $blue = \imagecolorallocate($source, 0, 60, 200);
        imagefill($source, 0, 0, $blue);
        // Spatial variation: crop-window and ratio mutations must change bytes.
        $yellow = \imagecolorallocate($source, 240, 220, 0);
        imagefilledrectangle($source, 150, 20, 250, 120, $yellow);
        $this->assertNotFalse(imagejpeg($source, $testFile, 95));
        imagedestroy($source);

        $prevRoots = getenv('PHLIX_LIBRARY_ROOTS');
        putenv('PHLIX_LIBRARY_ROOTS=' . $tempDir);
        \Phlix\Common\Fs\LibraryRootGuard::reset();

        try {
            $db->method('query')->willReturn([[
                'id' => 'photo-golden',
                'name' => 'Golden',
                'type' => 'photo',
                'library_id' => 'lib-1',
                'path' => $testFile,
                'metadata_json' => '{}',
            ]]);

            foreach (['cover', 'contain'] as $fit) {
                $request = new Request();
                $request->query = ['w' => '100', 'h' => '100', 'fit' => $fit];
                $response = $controller->getThumbnail($request, ['id' => 'photo-golden']);

                $this->assertSame(200, $response->statusCode, "fit={$fit} must serve");
                $served = base64_decode($response->body, true);
                $expected = self::legacyFitJpeg($testFile, 100, 100, $fit);
                $this->assertNotNull($expected, "oracle must produce bytes for fit={$fit}");
                $this->assertSame(
                    $expected,
                    $served,
                    "PhotoController thumbnail bytes drifted from the moved algorithm for fit={$fit}"
                    . ' — the delegation is not byte-exact',
                );
            }
        } finally {
            unlink($testFile);
            if ($prevRoots === false) {
                putenv('PHLIX_LIBRARY_ROOTS');
            } else {
                putenv('PHLIX_LIBRARY_ROOTS=' . $prevRoots);
            }
            \Phlix\Common\Fs\LibraryRootGuard::reset();
        }
    }

    // ------------------------------------------------------------------
    // Scanner
    // ------------------------------------------------------------------

    /**
     * @return list<string> every *.php under $dir (vendor/node_modules/.git excluded)
     */
    private static function phpFiles(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo || !$entry->isFile()) {
                continue;
            }
            $path = $entry->getPathname();
            if (preg_match('#/(vendor|node_modules|\.git)/#', $path) === 1) {
                continue;
            }
            if ($entry->getExtension() === 'php') {
                $files[] = $path;
            }
        }
        sort($files);
        return $files;
    }

    /** Tokenize and report whether the file invokes a GD pixel-resample function. */
    private static function containsResizeToken(string $file): bool
    {
        $tokens = \token_get_all((string) file_get_contents($file));
        foreach ($tokens as $token) {
            if (
                \is_array($token)
                && $token[0] === T_STRING
                && \in_array(strtolower($token[1]), self::RESIZE_TOKENS, true)
            ) {
                return true;
            }
        }
        return false;
    }

    // ------------------------------------------------------------------
    // Oracles — the PRE-S456 inline algorithms, verbatim
    // ------------------------------------------------------------------

    /** Pre-S456 AvatarStorage::resizeToAvatar(), byte-for-byte, as an oracle. */
    private static function legacySquareCoverJpeg(string $tmpPath, int $targetSize): ?string
    {
        /** @var array{0: int, 1: int, 2: int}|false */
        $imageInfo = @getimagesize($tmpPath);
        if ($imageInfo === false) {
            return null;
        }
        $sourceWidth = $imageInfo[0];
        $sourceHeight = $imageInfo[1];
        $sourceType = $imageInfo[2];

        $source = match ($sourceType) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($tmpPath),
            IMAGETYPE_PNG => imagecreatefrompng($tmpPath),
            IMAGETYPE_GIF => imagecreatefromgif($tmpPath),
            IMAGETYPE_WEBP => imagecreatefromwebp($tmpPath),
            default => false,
        };
        if ($source === false) {
            return null;
        }

        $ratio = max($targetSize / $sourceWidth, $targetSize / $sourceHeight);
        $scaledWidth = (int) round($sourceWidth * $ratio);
        $scaledHeight = (int) round($sourceHeight * $ratio);
        $srcX = max(0, (int) (($scaledWidth - $targetSize) / 2));
        $srcY = max(0, (int) (($scaledHeight - $targetSize) / 2));

        $canvas = @imagecreatetruecolor($targetSize, $targetSize);
        if ($canvas === false) {
            return null;
        }
        if ($sourceType === IMAGETYPE_PNG || $sourceType === IMAGETYPE_WEBP) {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
        }
        if (
            imagecopyresampled(
                $canvas,
                $source,
                0,
                0,
                $srcX,
                $srcY,
                $targetSize,
                $targetSize,
                $targetSize,
                $targetSize,
            ) === false
        ) {
            return null;
        }

        ob_start();
        imagejpeg($canvas, null, 85);
        $jpegData = ob_get_clean();

        return ($jpegData === false || $jpegData === '') ? null : $jpegData;
    }

    /** Pre-S456 PhotoController::generateThumbnail(), byte-for-byte, as an oracle. */
    private static function legacyFitJpeg(string $path, int $width, int $height, string $fit): ?string
    {
        /** @var array{0: int, 1: int, 2: int}|false */
        $imageInfo = @getimagesize($path);
        if ($imageInfo === false) {
            return null;
        }
        $sourceWidth = $imageInfo[0];
        $sourceHeight = $imageInfo[1];
        $sourceType = $imageInfo[2];

        $source = match ($sourceType) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($path),
            IMAGETYPE_PNG => imagecreatefrompng($path),
            IMAGETYPE_GIF => imagecreatefromgif($path),
            IMAGETYPE_WEBP => imagecreatefromwebp($path),
            default => imagecreatefromjpeg($path),
        };
        if ($source === false) {
            return null;
        }

        $ratio = $fit === 'cover'
            ? max($width / $sourceWidth, $height / $sourceHeight)
            : min($width / $sourceWidth, $height / $sourceHeight);
        $newWidth = (int)($sourceWidth * $ratio);
        $newHeight = (int)($sourceHeight * $ratio);
        $srcX = $fit === 'cover' ? max(0, (int)(($newWidth - $width) / 2)) : 0;
        $srcY = $fit === 'cover' ? max(0, (int)(($newHeight - $height) / 2)) : 0;

        $thumbWidth = max(1, $width);
        $thumbHeight = max(1, $height);
        $thumb = @imagecreatetruecolor($thumbWidth, $thumbHeight);
        if ($thumb === false) {
            return null;
        }
        if ($sourceType === IMAGETYPE_PNG) {
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
        }
        if (imagecopyresampled($thumb, $source, 0, 0, $srcX, $srcY, $width, $height, $newWidth, $newHeight) === false) {
            return null;
        }

        ob_start();
        imagejpeg($thumb, null, 85);
        $data = ob_get_clean();

        return $data !== false ? $data : null;
    }

    // ------------------------------------------------------------------
    // Temp tree helpers (same idiom as AvatarStorageTest)
    // ------------------------------------------------------------------

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/s456-census-' . bin2hex(random_bytes(6));
        $this->assertNotFalse(mkdir($dir, 0777, true));
        return $dir;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = array_diff(scandir($dir), ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
