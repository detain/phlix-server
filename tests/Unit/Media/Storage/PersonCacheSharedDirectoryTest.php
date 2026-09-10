<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Storage;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Storage\ArtworkStorage;
use Workerman\Http\Client;
use Workerman\Http\Response as HttpResponse;

/**
 * S72 — the DISK half of the single-fetch proof.
 *
 * The matcher-level test (LibraryMetadataMatcherPeopleCacheTest) pins that one
 * download is issued per person per RUN. This test pins the CROSS-RUN half with
 * the REAL {@see ArtworkStorage} (fake transport only): person photos live in
 * the flat `people-{id}` directory, so a SECOND storage instance — what every
 * later match run / worker restart constructs — finds all variants present and
 * early-returns WITHOUT a network fetch. Distinct persons keep distinct
 * directories, and person directories sit side by side with media-item UUID
 * directories under the one artwork root (the flat-key layout S71's
 * ImageResizer gate already accepts — no storage-layer change).
 */
final class PersonCacheSharedDirectoryTest extends TestCase
{
    /**
     * Lane survival token (this step's premerge `--token` assertion strips
     * comments before matching, so the string must live in CODE, not prose).
     */
    private const LANE_TOKEN = 'CS72PEOPLECACHEX9E';

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/person-cache-test-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->removeDirectory($this->tmpDir);
    }

    /**
     * Same person, two storage instances (two enrichment runs): exactly ONE
     * network fetch, identical variant lists, one shared directory. A DIFFERENT
     * person on the second instance costs exactly one ADDITIONAL fetch (keys
     * are per-person, not global).
     */
    public function testSecondRunForSamePersonIsServedFromTheSharedDirectoryWithoutRefetching(): void
    {
        if (!\extension_loaded('swoole')) {
            self::markTestSkipped('Swoole extension required for the async coroutine path');
        }

        $jpeg = $this->makeJpegBytes(600, 900);
        $fetches = [];

        $first = new TestableArtworkStorage($this->tmpDir);
        $first->forceBlocking = false;
        $first->fakeClient = $this->makeFakeClient(
            function (string $url, array $options) use ($jpeg, &$fetches): void {
                $fetches[] = $url;
                self::assertIsCallable($options['success'] ?? null);
                ($options['success'])(new HttpResponse(200, [], $jpeg));
            }
        );

        /** @var list<string>|null $firstResult */
        $firstResult = null;
        \Swoole\Coroutine\run(function () use ($first, &$firstResult): void {
            $firstResult = $first->downloadAndStore('people-287', '/keanu.jpg');
        });

        self::assertSame(['https://image.tmdb.org/t/p/original/keanu.jpg'], $fetches);
        self::assertSame(['w185', 'w342', 'w500', 'w780', 'original'], $firstResult);
        self::assertDirectoryExists($this->tmpDir . '/people-287');
        self::assertFileExists($this->tmpDir . '/people-287/w185.jpg');

        // Second enrichment run: a FRESH instance over the same root (no in-run
        // override map, nothing but the disk) — and a transport that records,
        // so any refetch is visible.
        $second = new TestableArtworkStorage($this->tmpDir);
        $second->forceBlocking = false;
        $second->fakeClient = $this->makeFakeClient(
            function (string $url, array $options) use ($jpeg, &$fetches): void {
                $fetches[] = $url;
                self::assertIsCallable($options['success'] ?? null);
                ($options['success'])(new HttpResponse(200, [], $jpeg));
            }
        );

        /** @var list<string>|null $reuseResult */
        $reuseResult = null;
        /** @var list<string>|null $otherPerson */
        $otherPerson = null;
        \Swoole\Coroutine\run(function () use ($second, &$reuseResult, &$otherPerson): void {
            $reuseResult = $second->downloadAndStore('people-287', '/keanu.jpg');
            $otherPerson = $second->downloadAndStore('people-288', '/carrie.jpg');
        });

        // Person 287: ZERO additional fetches, the same variant set. (The
        // early-return list comes from getStoredVariants()/scandir, so compare
        // SETS, not the first run's generation order.)
        self::assertEqualsCanonicalizing($firstResult, $reuseResult);
        self::assertCount(
            2,
            $fetches,
            self::LANE_TOKEN . ': only person 288 may hit the network; 287 must be '
                . 'served from the shared flat directory.'
        );
        self::assertSame('https://image.tmdb.org/t/p/original/carrie.jpg', $fetches[1]);

        // Distinct persons ⇒ distinct flat directories under ONE root…
        self::assertDirectoryExists($this->tmpDir . '/people-288');
        // …and the served URL rides the existing artwork route unchanged: flat
        // key, existing w185 size gate — no endpoint or validation widening
        // (the width-ladder rule travels with the first BACKDROP write, S73).
        self::assertSame(
            '/api/v1/artwork/people-287?size=w185',
            $second->relativePath('people-287', 'w185')
        );
    }

    /**
     * A media-item UUID directory and a people directory coexist under the one
     * root untouched by each other — the shared cache is additive layout, not
     * a re-key of the poster cache (deleteItemArtwork on an item must never
     * reach a people directory, and vice versa, because the keys differ).
     */
    public function testPersonAndItemDirectoriesCoexistAndDeleteIndependently(): void
    {
        $storage = new TestableArtworkStorage($this->tmpDir);
        foreach (['m-item-1', 'people-287'] as $key) {
            mkdir($this->tmpDir . '/' . $key, 0755, true);
            file_put_contents($this->tmpDir . '/' . $key . '/w185.jpg', 'JPEG');
        }

        $storage->deleteItemArtwork('m-item-1');

        self::assertDirectoryDoesNotExist($this->tmpDir . '/m-item-1');
        self::assertDirectoryExists($this->tmpDir . '/people-287');
        self::assertFileExists($this->tmpDir . '/people-287/w185.jpg');
    }

    /**
     * @param callable(string, array<array-key, mixed>): void $handler
     */
    private function makeFakeClient(callable $handler): Client
    {
        return new class ($handler) extends Client {
            /** @var callable(string, array<array-key, mixed>): void */
            private $handler;

            /**
             * @param callable(string, array<array-key, mixed>): void $handler
             */
            public function __construct(callable $handler)
            {
                parent::__construct([]);
                $this->handler = $handler;
            }

            /**
             * @param array<array-key, mixed> $options
             */
            public function request(string $url, array $options = []): mixed
            {
                ($this->handler)($url, $options);

                return null;
            }
        };
    }

    /**
     * Generate valid JPEG bytes of the given dimensions via GD.
     *
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    private function makeJpegBytes(int $width, int $height): string
    {
        $img = imagecreatetruecolor($width, $height);
        self::assertNotFalse($img);
        $color = imagecolorallocate($img, 120, 80, 200);
        imagefilledrectangle($img, 0, 0, $width - 1, $height - 1, $color === false ? 0 : $color);

        ob_start();
        imagejpeg($img, null, 85);
        $bytes = ob_get_clean();
        imagedestroy($img);

        self::assertIsString($bytes);
        self::assertNotSame('', $bytes);

        return $bytes;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
