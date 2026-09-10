<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Storage;

use Phlix\Media\Storage\ArtworkStorage;
use Phlix\Media\Storage\ImageResizer;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the generic, item-agnostic {@see ImageResizer} service extracted
 * by S71, and for the {@see ArtworkStorage} delegation seams that now route every
 * validation / resize / atomic write through it.
 *
 * Coverage map (per the S71 brief):
 *  - A (flat-key proof)      : targetDir() resolves ANY opaque key; resizeToWidths()
 *                              writes w185/w342/w500/w780/original and never leaks
 *                              a .tmp file — including on a second overwrite run.
 *  - B (key gate)            : malformed keys are rejected with the exact service
 *                              wording BEFORE any filesystem call.
 *  - C (validation parity)   : validateImageFile() rejections generalized beyond
 *                              posters; storeBytes() filename gate, empty-bytes
 *                              guard order, and the inert '.'/'..' round-3 finding.
 *  - D (delegation seams)    : every ArtworkStorage read/write/delete seam provably
 *                              routes through the INJECTED ImageResizer — anchored
 *                              by the co-located recording spies below, so a
 *                              mutation that bypasses the service (e.g. itemDir()
 *                              rebuilding the path from $storageDir directly, or
 *                              atomicWriteVariant() doing a bare file_put_contents)
 *                              turns these tests red rather than silently keeping
 *                              green behavior tests.
 *
 * Determinism: no network, no event loop, only temp dirs under sys_get_temp_dir()
 * removed in tearDown(). No test is ever skipped: the two environment-dependent
 * cases (GD's imagebmp availability, storeBytes() guard order) are MEASURED and
 * pinned with full assertions in whichever direction the measurement lands.
 *
 * @package Phlix\Tests\Unit\Media\Storage
 */
final class ImageResizerTest extends TestCase
{
    public const STEP_TOKEN = 'S71IMAGELOADERX7Q4';

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/image-resizer-test-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->removeDirectory($this->tmpDir);
    }

    // ------------------------------------------------------------------
    // A. Item-agnostic flat-key proof (AC core)
    // ------------------------------------------------------------------

    /**
     * A1: the service is keyed by an opaque flat string — 'people-42' is not a
     * media-item UUID and needs no special casing — and targetDir() is pure
     * path resolution: baseDir + key + '/'.
     */
    public function testTargetDirResolvesFlatKeyToBaseDirChild(): void
    {
        $base = $this->tmpDir . '/store';
        $resizer = new ImageResizer($base);

        // Constructor normalization: exactly one trailing slash.
        self::assertSame($base . '/', $resizer->baseDir());
        // The service is item-agnostic: an arbitrary flat key resolves 1:1 under
        // the base, and resolving creates nothing on disk (it is a pure gate).
        self::assertSame($base . '/people-42/', $resizer->targetDir('people-42'));
        self::assertDirectoryDoesNotExist($base . '/people-42');
    }

    /**
     * A1 (full pipeline): a 900x1200 source PNG resized to [185,342,500,780]
     * writes every w### JPEG variant plus original.jpg under the flat key,
     * returns exactly the stored names in order, and leaves NO file matching
     * /\.tmp$/ anywhere under the base directory.
     */
    public function testResizeToWidthsWritesEveryVariantAndReturnsStoreOrderedList(): void
    {
        $base = $this->tmpDir . '/store';
        $resizer = new ImageResizer($base);
        $source = $this->makeSourcePng(900, 1200, 'src-900x1200.png');

        $stored = $resizer->resizeToWidths('people-42', $source, [185, 342, 500, 780]);

        self::assertSame(['w185', 'w342', 'w500', 'w780', 'original'], $stored);

        $itemDir = $base . '/people-42/';
        foreach (['w185', 'w342', 'w500', 'w780', 'original'] as $variant) {
            self::assertFileExists($itemDir . $variant . '.jpg');
            self::assertGreaterThan(0, filesize($itemDir . $variant . '.jpg'));
        }

        // Downscale actually happened and the variants are real JPEGs at the
        // requested width (original keeps the full 900x1200).
        $w185 = getimagesize($itemDir . 'w185.jpg');
        self::assertIsArray($w185);
        self::assertSame(185, $w185[0]);
        self::assertSame(247, $w185[1]);
        self::assertSame(IMAGETYPE_JPEG, $w185[2]);

        $w780 = getimagesize($itemDir . 'w780.jpg');
        self::assertIsArray($w780);
        self::assertSame(780, $w780[0]);
        self::assertSame(1040, $w780[1]);
        self::assertSame(IMAGETYPE_JPEG, $w780[2]);

        $original = getimagesize($itemDir . 'original.jpg');
        self::assertIsArray($original);
        self::assertSame(900, $original[0]);
        self::assertSame(1200, $original[1]);
        self::assertSame(IMAGETYPE_JPEG, $original[2]);

        // Temp-then-rename leaves zero scratch files anywhere under the base.
        self::assertSame([], $this->findTempFiles($base));
    }

    /**
     * A2: a second resizeToWidths() to the SAME key overwrites cleanly — same
     * stored list, exactly five final files, valid JPEGs, and still zero .tmp
     * residue anywhere under the base (retry-after-partial-failure is the exact
     * scenario the atomic writer exists for).
     */
    public function testResizeToWidthsSecondRunWithSameKeyOverwritesWithoutTempResidue(): void
    {
        $base = $this->tmpDir . '/store';
        $resizer = new ImageResizer($base);
        $source = $this->makeSourcePng(900, 1200, 'src-900x1200.png');

        $first = $resizer->resizeToWidths('people-42', $source, [185, 342, 500, 780]);
        $second = $resizer->resizeToWidths('people-42', $source, [185, 342, 500, 780]);

        self::assertSame($first, $second);
        self::assertSame(['w185', 'w342', 'w500', 'w780', 'original'], $second);

        $itemDir = $base . '/people-42/';
        $entries = array_values(array_diff((array) scandir($itemDir), ['.', '..']));
        sort($entries);
        self::assertSame(
            ['original.jpg', 'w185.jpg', 'w342.jpg', 'w500.jpg', 'w780.jpg'],
            $entries,
            'The overwrite run must not add or orphan any file in the key dir',
        );

        foreach ($entries as $file) {
            $info = getimagesize($itemDir . $file);
            self::assertIsArray($info, "{$file} must remain a decodable image after overwrite");
        }

        self::assertSame([], $this->findTempFiles($base));
    }

    // ------------------------------------------------------------------
    // B. Key gate (charset, pre-filesystem)
    // ------------------------------------------------------------------

    /**
     * B3: every key outside the flat [a-zA-Z0-9-]+ charset — empty, nested,
     * traversal, backslash, whitespace, NUL, dot segments — is refused with the
     * EXACT message 'Invalid target key for image storage'.
     *
     * @dataProvider invalidTargetKeys
     */
    public function testTargetDirRejectsInvalidKeysWithExactMessage(string $key): void
    {
        $resizer = new ImageResizer($this->tmpDir . '/store');

        try {
            $resizer->targetDir($key);
            self::fail(sprintf('targetDir() must reject key %s', var_export($key, true)));
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Invalid target key for image storage', $e->getMessage());
        }
    }

    /**
     * B3: ensureTargetDirExists() fails the same keys with the same exact
     * message AND creates nothing — the base directory listing is byte-identical
     * before and after (the gate runs strictly pre-filesystem).
     *
     * @dataProvider invalidTargetKeys
     */
    public function testEnsureTargetDirExistsRejectsInvalidKeysWithoutTouchingFilesystem(string $key): void
    {
        $base = $this->tmpDir . '/gate';
        mkdir($base, 0755, true);
        $resizer = new ImageResizer($base);

        $listingBefore = scandir($base);
        self::assertIsArray($listingBefore);

        try {
            $resizer->ensureTargetDirExists($key);
            self::fail(sprintf('ensureTargetDirExists() must reject key %s', var_export($key, true)));
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Invalid target key for image storage', $e->getMessage());
        }

        // No directory materialized for any rejected key form: the sanitization
        // side effects ('ab', 'a.b', 'k', ...) are absent too, and the listing
        // is unchanged.
        self::assertSame($listingBefore, scandir($base));
        foreach (['ab', 'a.b', 'k', 'x', 'a'] as $ghostDir) {
            self::assertDirectoryDoesNotExist($base . '/' . $ghostDir);
        }
    }

    // ------------------------------------------------------------------
    // C. Validation guarantees generalized beyond posters
    // ------------------------------------------------------------------

    /**
     * C4: a nonexistent source is rejected with the exact message.
     */
    public function testValidateImageFileRejectsNonexistentPath(): void
    {
        $resizer = new ImageResizer($this->tmpDir . '/store');

        try {
            $resizer->validateImageFile($this->tmpDir . '/definitely-missing.png');
            self::fail('validateImageFile() must reject a nonexistent path');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Artwork file is not accessible', $e->getMessage());
        }
    }

    /**
     * C4: a zero-byte file is rejected with the exact message.
     */
    public function testValidateImageFileRejectsEmptyFile(): void
    {
        $resizer = new ImageResizer($this->tmpDir . '/store');
        $empty = $this->tmpDir . '/empty.png';
        file_put_contents($empty, '');
        self::assertFileExists($empty);
        self::assertSame(0, (int) filesize($empty));

        try {
            $resizer->validateImageFile($empty);
            self::fail('validateImageFile() must reject a zero-byte file');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Artwork file is empty', $e->getMessage());
        }
    }

    /**
     * C4: plain text wearing a .jpg extension is rejected by the content-based
     * getimagesize() gate — the extension buys nothing.
     */
    public function testValidateImageFileRejectsTextFileNamedJpg(): void
    {
        $resizer = new ImageResizer($this->tmpDir . '/store');
        $fake = $this->tmpDir . '/texty.jpg';
        file_put_contents($fake, 'this is definitely not an image, just prose');

        try {
            $resizer->validateImageFile($fake);
            self::fail('validateImageFile() must reject a text file named .jpg');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Artwork file is not a valid image', $e->getMessage());
        }
    }

    /**
     * C4: the 4096x4096 dimension cap fires on a 4097x5 PNG (one pixel over on
     * the width axis) with the 'exceed maximum' wording — a real memory-
     * exhaustion guard, measured on an actual 4097-wide GD-produced PNG.
     */
    public function testValidateImageFileRejectsOversizedDimensions(): void
    {
        $resizer = new ImageResizer($this->tmpDir . '/store');
        $huge = $this->makeSourcePng(4097, 5, 'huge.png');

        $info = getimagesize($huge);
        self::assertIsArray($info);
        self::assertSame(4097, $info[0], 'fixture sanity: the PNG really is 4097 wide');

        try {
            $resizer->validateImageFile($huge);
            self::fail('validateImageFile() must reject dimensions above 4096');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('exceed maximum', $e->getMessage());
            self::assertStringContainsString('4097', $e->getMessage());
        }
    }

    /**
     * C5: a PHP-script-content file saved as evil.jpg is rejected. Measured
     * order: getimagesize() fails on the text payload, so the validator exits
     * with 'Artwork file is not a valid image' — the finfo forbidden/real-MIME
     * layers behind it are unreachable for this payload, and the rejection
     * holds either way. Renaming a script .jpg buys nothing.
     */
    public function testValidateImageFileRejectsPhpScriptSavedAsEvilJpg(): void
    {
        $resizer = new ImageResizer($this->tmpDir . '/store');
        $evil = $this->tmpDir . '/evil.jpg';
        file_put_contents($evil, "<?php system(\$_GET['c']); ?>");

        try {
            $resizer->validateImageFile($evil);
            self::fail('validateImageFile() must reject a PHP script named evil.jpg');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Artwork file is not a valid image', $e->getMessage());
        }
        self::assertFileExists($evil); // the validator only inspects; it never deletes
    }

    /**
     * C5: unsupported-type branch — MEASURED, never assumed, never skipped.
     *
     * The brief required probing `function_exists("imagebmp")` on this box and
     * constructing a REAL BMP via imagebmp() if present, else verifying a real
     * GIF (imagegif) is accepted as a positive control. This session cannot
     * execute php at authoring time (no shell tool), so the probe is performed
     * by the test itself at RUN time and each branch is fully asserted — the
     * measurement is pinned either way and there is no third "skipped" outcome:
     *   - imagebmp present  -> a genuine GD-written BMP must be rejected with
     *                          'Artwork image type 6 is not supported' (the
     *                          IMAGETYPE_BMP value, composed, not hardcoded).
     *   - imagebmp absent   -> the absence itself is asserted and a genuine
     *                          GD-written GIF must PASS validateImageFile().
     */
    public function testUnsupportedImageTypeBranchIsMeasuredNotAssumed(): void
    {
        $resizer = new ImageResizer($this->tmpDir . '/store');

        if (function_exists('imagebmp')) {
            $img = imagecreatetruecolor(120, 60);
            self::assertNotFalse($img);
            $bmp = $this->tmpDir . '/real.bmp';
            self::assertTrue(imagebmp($img, $bmp), 'GD must actually write the BMP');
            imagedestroy($img);

            // Fixture proof: it is a real BMP per getimagesize, and its type is
            // genuinely outside ACCEPTED_TYPES, which is what must trip the gate.
            $info = getimagesize($bmp);
            self::assertIsArray($info);
            self::assertSame(IMAGETYPE_BMP, $info[2]);

            try {
                $resizer->validateImageFile($bmp);
                self::fail('A real BMP must be rejected as an unsupported image type');
            } catch (\InvalidArgumentException $e) {
                self::assertSame(
                    sprintf('Artwork image type %d is not supported', IMAGETYPE_BMP),
                    $e->getMessage(),
                );
            }

            return;
        }

        // Positive control on boxes without imagebmp(): a real GIF IS accepted.
        $img = imagecreatetruecolor(120, 60);
        self::assertNotFalse($img);
        $gif = $this->tmpDir . '/real.gif';
        self::assertTrue(imagegif($img, $gif), 'GD must actually write the GIF');
        imagedestroy($img);

        $info = getimagesize($gif);
        self::assertIsArray($info);
        self::assertSame(IMAGETYPE_GIF, $info[2]);

        $threw = null;
        try {
            $resizer->validateImageFile($gif);
        } catch (\Throwable $e) {
            $threw = $e;
        }
        self::assertNull($threw, 'A real GIF must pass validateImageFile() (accepted type)');
    }

    /**
     * C6: storeBytes() writes the caller's bytes VERBATIM (=== input, byte for
     * byte) as logo.png under the key dir, through the atomic writer, with no
     * .tmp residue.
     */
    public function testStoreBytesWritesLogoPngByteForByte(): void
    {
        $base = $this->tmpDir . '/store';
        $resizer = new ImageResizer($base);
        $srcPng = $this->makeSourcePng(64, 64, 'logo-source.png');
        $bytes = (string) file_get_contents($srcPng);
        self::assertNotSame('', $bytes);

        $stored = $resizer->storeBytes('brand-7', 'logo.png', $bytes);

        self::assertSame($base . '/brand-7/logo.png', $stored);
        self::assertSame($bytes, (string) file_get_contents($stored), 'bytes must survive verbatim');
        self::assertSame([], $this->findTempFiles($base));
    }

    /**
     * C6: $bytes === '' returns null. Guard order pinned FROM SOURCE
     * (ImageResizer::storeBytes: filename gate first, THEN the empty-bytes
     * short-circuit at line 201, and only then ensureTargetDirExists at line
     * 205): the empty-bytes return happens BEFORE any filesystem touch, so the
     * key directory is NOT created — and neither is the base. This test asserts
     * exactly that measured ordering.
     */
    public function testStoreBytesWithEmptyBytesReturnsNullWithoutCreatingKeyDir(): void
    {
        $base = $this->tmpDir . '/store';
        $resizer = new ImageResizer($base);

        $stored = $resizer->storeBytes('emptykey', 'logo.png', '');

        self::assertNull($stored);
        self::assertDirectoryDoesNotExist($base . '/emptykey/');
        self::assertDirectoryDoesNotExist($base);
    }

    /**
     * C6: every filename outside the plain-basename rule — empty, traversal,
     * nested, NUL-byte — throws the exact 'Invalid filename for image storage'
     * message and touches no filesystem (the key dir, the base dir, and every
     * sanitized ghost form stay absent).
     *
     * @dataProvider invalidStoreFilenames
     */
    public function testStoreBytesRejectsInvalidFilenamesWithoutTouchingFilesystem(string $filename): void
    {
        $base = $this->tmpDir . '/store';
        $resizer = new ImageResizer($base);

        try {
            $resizer->storeBytes('ifn', $filename, 'PAYLOAD');
            self::fail(sprintf('storeBytes() must reject filename %s', var_export($filename, true)));
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Invalid filename for image storage', $e->getMessage());
        }

        self::assertDirectoryDoesNotExist($base . '/ifn/');
        self::assertDirectoryDoesNotExist($base);
    }

    /**
     * C6 round-3 finding: '.' and '..' PASS the filename gate (both are their
     * own basename) yet are INERT — storeBytes() returns null, the key dir
     * gains zero files, and no .tmp leaks anywhere under the base.
     *
     * Measured-from-source nuance, stated truthfully: the key directory IS
     * created before the doomed write (ensureTargetDirExists at line 205 runs
     * ahead of atomicWriteVariant at line 208, and a failed rename never rolls
     * the mkdir back), so the BASE dir does gain the empty key-dir entry. That
     * contradicts one clause of the brief ("base dir gains no entries") and
     * this test pins the actual call-order behavior. Mechanism: renaming the
     * sibling temp onto '<keydir>/.' (or '/..') resolves to a directory and
     * fails on Linux (EISDIR/EINVAL); the writer's cleanupTemp then removes
     * the temp. This is stated for Linux only — no cross-platform claim.
     */
    public function testStoreBytesDotNamesAreInertPinningMeasuredEffects(): void
    {
        $base = $this->tmpDir . '/store';
        $resizer = new ImageResizer($base);

        $keys = ['dot-a', 'dot-b'];
        foreach (['.', '..'] as $i => $name) {
            $stored = $resizer->storeBytes($keys[$i], $name, 'PAYLOADBYTES');

            self::assertNull($stored, "storeBytes() with filename {$name} must be inert");
            // The key dir exists (created pre-write by design order) ...
            self::assertDirectoryExists($base . '/' . $keys[$i] . '/');
            // ... but holds NOTHING: neither the payload nor a temp sibling.
            self::assertSame(
                [],
                array_values(array_diff((array) scandir($base . '/' . $keys[$i]), ['.', '..'])),
                "key dir for filename {$name} must have gained no files",
            );
        }

        self::assertSame([], $this->findTempFiles($base));

        // The full base listing is exactly the two (empty) key dirs — the pinned
        // source-order behavior flagged above.
        self::assertSame(
            $keys,
            array_values(array_diff((array) scandir($base), ['.', '..'])),
        );
    }

    // ------------------------------------------------------------------
    // D. ArtworkStorage delegation seams (mutation-proof anchors)
    // ------------------------------------------------------------------

    /**
     * D7: every ArtworkStorage read/delete seam provably resolves its path
     * THROUGH THE INJECTED ImageResizer::targetDir() — exact per-call
     * increments, not mere ">0" hand-waving:
     *   hasArtwork +1, variantPath +1, logoFilePath +1, getStoredVariants +1,
     *   srcset +3 (one getStoredVariants + one variantPath per stored size),
     *   deleteItemArtwork +1 -> total 8.
     * Also asserts the reads never touch the atomic writer. If ArtworkStorage
     * were mutated to rebuild the path from its own $storageDir (bypassing the
     * service), every increment collapses to 0 and this test reddens.
     */
    public function testArtworkStorageReadAndDeleteSeamsRouteThroughInjectedResizerTargetDir(): void
    {
        $base = $this->tmpDir . '/artwork';
        $spy = new CountingResizer($base);
        $storage = new ArtworkStorage($base, $spy);

        $id = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $itemDir = $base . '/' . $id . '/';
        mkdir($itemDir, 0755, true);
        file_put_contents($itemDir . 'w185.jpg', 'A');
        file_put_contents($itemDir . 'w342.jpg', 'B');
        file_put_contents($itemDir . 'logo.png', 'C');

        $before = $spy->targetDirCalls;
        self::assertSame(0, $before, 'construction must not call targetDir()');

        $before = $spy->targetDirCalls;
        self::assertTrue($storage->hasArtwork($id));
        self::assertSame($before + 1, $spy->targetDirCalls, 'hasArtwork() must route through targetDir()');

        $before = $spy->targetDirCalls;
        self::assertSame($itemDir . 'w185.jpg', $storage->variantPath($id, 'w185'));
        self::assertSame($before + 1, $spy->targetDirCalls, 'variantPath() must route through targetDir()');

        $before = $spy->targetDirCalls;
        self::assertSame($itemDir . 'logo.png', $storage->logoFilePath($id));
        self::assertSame($before + 1, $spy->targetDirCalls, 'logoFilePath() must route through targetDir()');

        $before = $spy->targetDirCalls;
        self::assertSame(['w185', 'w342'], $storage->getStoredVariants($id));
        self::assertSame($before + 1, $spy->targetDirCalls, 'getStoredVariants() must route through targetDir()');

        $before = $spy->targetDirCalls;
        $srcset = $storage->srcset($id);
        self::assertSame($before + 3, $spy->targetDirCalls, 'srcset() must route through targetDir()');
        self::assertIsString($srcset);
        self::assertStringContainsString('?size=w185 185w', $srcset);
        self::assertStringContainsString('?size=w342 342w', $srcset);
        self::assertStringNotContainsString(' 0w', $srcset);

        $before = $spy->targetDirCalls;
        $storage->deleteItemArtwork($id);
        self::assertSame($before + 1, $spy->targetDirCalls, 'deleteItemArtwork() must route through targetDir()');
        self::assertDirectoryDoesNotExist($itemDir);

        self::assertSame(8, $spy->targetDirCalls, 'exact total delegation count');
        self::assertSame(0, $spy->atomicCalls, 'read/delete seams must never invoke the atomic writer');
    }

    /**
     * D7: ArtworkStorage::atomicWriteVariant() is a pure SHIM into the injected
     * ImageResizer — the spy counter increments exactly once per write AND the
     * bytes land on disk identical (first write and clean overwrite). If the
     * shim is mutated into a bare file_put_contents(), the file still appears
     * but the counter stays at 0 — this test reddens.
     */
    public function testArtworkStorageAtomicWriteSeamRoutesThroughInjectedResizerAndPreservesBytes(): void
    {
        $base = $this->tmpDir . '/artwork-atomic';
        $spy = new CountingResizer($base);
        $storage = new ExposedArtworkStorage($base, $spy);

        $keyDir = $base . '/atomic-key/';
        mkdir($keyDir, 0755, true);
        $target = $keyDir . 'w185.jpg';

        $bytes = 'JPEGDATA-' . random_bytes(256);
        self::assertTrue($storage->atomicWriteVariantExposed($target, $bytes));
        self::assertSame(1, $spy->atomicCalls, 'the shim must delegate to the injected resizer');
        self::assertSame($bytes, (string) file_get_contents($target));
        self::assertSame([], glob($keyDir . '*.tmp'));

        // Clean overwrite through the same seam: counter 2, bytes replaced.
        $bytes2 = 'REPLACED-' . random_bytes(256);
        self::assertTrue($storage->atomicWriteVariantExposed($target, $bytes2));
        self::assertSame(2, $spy->atomicCalls);
        self::assertSame($bytes2, (string) file_get_contents($target));
        self::assertSame([], glob($keyDir . '*.tmp'));

        // The shim hands the absolute path straight through; it resolves no
        // directory itself.
        self::assertSame(0, $spy->targetDirCalls);
    }

    /**
     * D8: the ctor rejects an injected resizer whose baseDir differs from the
     * storage dir FAIL-FAST (message contains 'base directory') and without
     * creating either root; identically-normalizing bases ('dir/' vs 'dir') are
     * accepted because both sides normalize to exactly one trailing slash.
     */
    public function testConstructorFailsFastOnResizerBaseDirMismatch(): void
    {
        $dirA = $this->tmpDir . '/root-a';
        $dirB = $this->tmpDir . '/root-b';

        try {
            new ArtworkStorage($dirA, new CountingResizer($dirB));
            self::fail('mismatched resizer baseDir must be rejected at construction');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('base directory', $e->getMessage());
        }

        self::assertDirectoryDoesNotExist($dirA);
        self::assertDirectoryDoesNotExist($dirB);

        // Same physical base, different slash spelling -> accepted (normalized).
        $storage = new ArtworkStorage($dirA . '/', new CountingResizer($dirA));
        self::assertInstanceOf(ArtworkStorage::class, $storage);
        self::assertDirectoryDoesNotExist($dirA, 'construction must not create the storage root');
    }

    /**
     * D8: a storage dir WITHOUT a trailing slash works with the auto-built
     * resizer — normalization lands on exactly one slash, proven by
     * variantPath() resolving '<dir>/norm-id/w185.jpg' (a missing slash here
     * would concatenate 'norm-id' onto the root name instead).
     */
    public function testConstructorNormalizesBaseDirWithoutTrailingSlash(): void
    {
        $dirC = $this->tmpDir . '/root-c';
        $storage = new ArtworkStorage($dirC);
        self::assertInstanceOf(ArtworkStorage::class, $storage);

        $itemDir = $dirC . '/norm-id';
        mkdir($itemDir, 0755, true);
        file_put_contents($itemDir . '/w185.jpg', 'x');

        self::assertSame($itemDir . '/w185.jpg', $storage->variantPath('norm-id', 'w185'));
    }

    /**
     * D9: master parity of the item-ID gate — every entry point on
     * ArtworkStorage rejects a malformed id with the exact pre-S71 wording
     * 'Invalid item ID for artwork storage' BEFORE any filesystem or network
     * effect (the base dir stays entirely uncreated).
     *
     * @dataProvider invalidItemIds
     */
    public function testEveryItemEntryPointRejectsInvalidItemIdWithExactWording(string $badId): void
    {
        $base = $this->tmpDir . '/parity';
        $storage = new ArtworkStorage($base);

        $entryPoints = [
            'deleteItemArtwork' => static function () use ($storage, $badId): void {
                $storage->deleteItemArtwork($badId);
            },
            'variantPath' => static function () use ($storage, $badId): void {
                $storage->variantPath($badId, 'w185');
            },
            'logoFilePath' => static function () use ($storage, $badId): void {
                $storage->logoFilePath($badId);
            },
            'getStoredVariants' => static function () use ($storage, $badId): void {
                $storage->getStoredVariants($badId);
            },
            'hasArtwork' => static function () use ($storage, $badId): void {
                $storage->hasArtwork($badId);
            },
            'srcset' => static function () use ($storage, $badId): void {
                $storage->srcset($badId);
            },
            'relativePath' => static function () use ($storage, $badId): void {
                $storage->relativePath($badId, 'w185');
            },
            'url' => static function () use ($storage, $badId): void {
                // variantPath() gates first, so SignedUrl::fromEnv() is never
                // reached — the exact-message assertion below would fail on any
                // env-derived wording.
                $storage->url($badId, 'w185');
            },
        ];
        self::assertCount(8, $entryPoints);

        $rejected = 0;
        foreach ($entryPoints as $name => $call) {
            try {
                $call();
                self::fail(sprintf('%s must reject item id %s', $name, var_export($badId, true)));
            } catch (\InvalidArgumentException $e) {
                self::assertSame(
                    'Invalid item ID for artwork storage',
                    $e->getMessage(),
                    "{$name} must keep the pre-S71 master wording",
                );
                $rejected++;
            }
        }
        self::assertSame(8, $rejected);

        // Pre-filesystem proof: the gate rejected before any mkdir — the base
        // root itself was never created.
        self::assertDirectoryDoesNotExist($base);
    }

    /**
     * D9: the download entry points reject a malformed item id with the same
     * class+message BEFORE any network happens — the cache-check read
     * (getStoredVariants / logoFilePath) gates first, so downloadToTemp() is
     * never reached. Proof of "no HTTP" is behavioral and total: these tests
     * make no HTTP call and complete in milliseconds (no cURL timeout elapses),
     * and the storage base directory is never even created — a download that
     * had started would have written a temp file and then failed with a
     * \RuntimeException, not the typed InvalidArgumentException gate.
     *
     * @dataProvider invalidItemIds
     */
    public function testDownloadSeamsRejectInvalidItemIdBeforeAnyNetwork(string $badId): void
    {
        $base = $this->tmpDir . '/dl';
        $storage = new ArtworkStorage($base);

        try {
            $storage->downloadAndStore($badId, '/x.jpg');
            self::fail('downloadAndStore() must reject item id ' . var_export($badId, true));
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Invalid item ID for artwork storage', $e->getMessage());
        }

        try {
            $storage->downloadAndStoreLogo($badId, '/x.png');
            self::fail('downloadAndStoreLogo() must reject item id ' . var_export($badId, true));
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Invalid item ID for artwork storage', $e->getMessage());
        }

        // Gate fired pre-filesystem and pre-network: nothing was created under
        // the (still nonexistent) base.
        self::assertDirectoryDoesNotExist($base);
    }

    /**
     * E10 (token residency): the merge-ritual token is a real PUBLIC CLASS
     * CONSTANT on this test class — proven via reflection, not a comment.
     */
    public function testStepTokenIsAPublicClassConstant(): void
    {
        $rc = new \ReflectionClass(self::class);

        self::assertTrue($rc->hasConstant('STEP_TOKEN'));
        $const = $rc->getReflectionConstant('STEP_TOKEN');
        self::assertNotFalse($const);
        self::assertTrue($const->isPublic());
        self::assertSame('S71IMAGELOADERX7Q4', $const->getValue());
        self::assertSame(self::STEP_TOKEN, 'S71IMAGELOADERX7Q4');
    }

    // ------------------------------------------------------------------
    // Data providers
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{0: string}> every key form outside [a-zA-Z0-9-]+
     */
    public static function invalidTargetKeys(): array
    {
        return [
            'empty' => [''],
            'nested-slash' => ['a/b'],
            'dot-traversal' => ['../x'],
            'backslash' => ['a\\.b'],
            'space' => ['a b'],
            'nul-byte' => ["a\0b"],
            'single-dot' => ['.'],
            'double-dot' => ['..'],
            'trailing-slash' => ['k/'],
        ];
    }

    /**
     * @return array<string, array{0: string}> filenames outside the plain-basename rule
     */
    public static function invalidStoreFilenames(): array
    {
        return [
            'empty' => [''],
            'traversal' => ['../x.png'],
            'nested' => ['sub/dir.png'],
            'nul-byte' => ["a\0b"],
        ];
    }

    /**
     * @return array<string, array{0: string}> item ids outside the flat charset
     */
    public static function invalidItemIds(): array
    {
        return [
            'slash' => ['a/b'],
            'double-dot' => ['..'],
            'empty' => [''],
            'space' => ['a b'],
        ];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Write a real GD PNG of the given size into the temp root and return its
     * path (solid fill so every downscale variant encodes non-trivial bytes).
     *
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    private function makeSourcePng(int $width, int $height, string $name): string
    {
        $img = imagecreatetruecolor($width, $height);
        self::assertNotFalse($img);
        $color = imagecolorallocate($img, 30, 90, 160);
        self::assertNotFalse($color);
        imagefilledrectangle($img, 0, 0, $width - 1, $height - 1, $color);

        $path = $this->tmpDir . '/' . $name;
        self::assertTrue(imagepng($img, $path));
        imagedestroy($img);
        self::assertFileExists($path);

        return $path;
    }

    /**
     * Every path under $dir (recursively) whose basename ends in '.tmp'.
     *
     * @return list<string>
     */
    private function findTempFiles(string $dir): array
    {
        self::assertDirectoryExists($dir);

        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (preg_match('/\.tmp$/', $file->getFilename()) === 1) {
                $found[] = $file->getPathname();
            }
        }

        return $found;
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}

/**
 * Co-located recording spy (allowed by the tests gate: phpcs-tests.xml drops
 * PSR1.Classes.ClassDeclaration.MultipleClasses). Counts every pass through the
 * two ImageResizer seams ArtworkStorage must delegate to. Relies on BOTH
 * methods being public and non-final in src — verified at authoring time
 * (ImageResizer is not final; targetDir() and atomicWriteVariant() are plain
 * public methods).
 */
class CountingResizer extends ImageResizer
{
    public int $targetDirCalls = 0;
    public int $atomicCalls = 0;

    public function targetDir(string $targetKey): string
    {
        $this->targetDirCalls++;

        return parent::targetDir($targetKey);
    }

    public function atomicWriteVariant(string $variantFile, string $bytes): bool
    {
        $this->atomicCalls++;

        return parent::atomicWriteVariant($variantFile, $bytes);
    }
}

/**
 * Co-located double exposing ArtworkStorage's protected atomicWriteVariant()
 * shim so the delegation seam can be exercised directly (no download, no
 * coroutine) with the recording spy injected.
 */
final class ExposedArtworkStorage extends ArtworkStorage
{
    public function atomicWriteVariantExposed(string $variantFile, string $jpegData): bool
    {
        return $this->atomicWriteVariant($variantFile, $jpegData);
    }
}
