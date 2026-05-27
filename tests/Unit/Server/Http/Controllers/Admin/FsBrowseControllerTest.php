<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers\Admin;

use Phlix\Server\Http\Controllers\Admin\FsBrowseController;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the filesystem-browse JSON API (Step 0.6).
 *
 * Auth (401/403) is enforced by {@see \Phlix\Server\Http\Middleware\AdminMiddleware}
 * upstream of this controller and is covered by the middleware's own tests;
 * here we assert the controller's directory-listing behaviour and — most
 * importantly — the path-traversal jail (`../` escape, symlink escape, and
 * non-allowed roots must all be rejected with 403).
 *
 * Each test runs against a real sandbox directory tree built under
 * {@see sys_get_temp_dir()} in {@see setUp()} and torn down afterwards.
 *
 * @covers \Phlix\Server\Http\Controllers\Admin\FsBrowseController
 */
final class FsBrowseControllerTest extends TestCase
{
    /** Sandbox root that acts as the single allowed browse root. */
    private string $root;

    /** A real directory OUTSIDE the allowed root (target of escape attempts). */
    private string $outside;

    /** Whether symlink() succeeded so the symlink-escape test can run. */
    private bool $symlinkAvailable = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/phlix_fs_browse_' . uniqid('', true);
        mkdir($this->root, 0775, true);
        mkdir($this->root . '/movies', 0775, true);
        mkdir($this->root . '/tv', 0775, true);
        file_put_contents($this->root . '/note.txt', 'not a directory');

        $this->outside = sys_get_temp_dir() . '/phlix_fs_browse_outside_' . uniqid('', true);
        mkdir($this->outside, 0775, true);

        // A symlink inside the root that points to the outside dir. realpath()
        // resolves it to $this->outside, which is not under the allowed root.
        if (@symlink($this->outside, $this->root . '/escape')) {
            $this->symlinkAvailable = true;
        }
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        $this->removeTree($this->outside);

        parent::tearDown();
    }

    public function testListsImmediateSubdirectories(): void
    {
        $controller = new FsBrowseController([$this->root]);
        $response   = $controller->browse($this->makeRequest($this->root), []);

        self::assertSame(200, $response->statusCode);

        $body = $this->decode($response->body);
        self::assertTrue($body['success']);

        $names = array_column($body['data']['entries'], 'name');
        self::assertContains('movies', $names);
        self::assertContains('tv', $names);
        self::assertNotContains('note.txt', $names);
    }

    public function testEmptyPathReturnsConfiguredRoots(): void
    {
        $controller = new FsBrowseController([$this->root]);
        $response   = $controller->browse($this->makeRequest(''), []);

        self::assertSame(200, $response->statusCode);

        $body = $this->decode($response->body);
        self::assertTrue($body['success']);
        self::assertNull($body['data']['path']);
        self::assertNull($body['data']['parent']);

        $paths = array_column($body['data']['entries'], 'path');
        self::assertSame([$this->root], $paths);
    }

    public function testRejectsTraversalEscape(): void
    {
        $controller = new FsBrowseController([$this->root]);
        // A `..` segment that resolves outside the jail.
        $path     = $this->root . '/../' . basename($this->outside);
        $response = $controller->browse($this->makeRequest($path), []);

        self::assertSame(403, $response->statusCode);
        $body = $this->decode($response->body);
        self::assertFalse($body['success']);
    }

    public function testRejectsSymlinkEscape(): void
    {
        if (!$this->symlinkAvailable) {
            self::markTestSkipped('symlink() is not available on this platform.');
        }

        $controller = new FsBrowseController([$this->root]);
        $response   = $controller->browse($this->makeRequest($this->root . '/escape'), []);

        self::assertSame(403, $response->statusCode);
        $body = $this->decode($response->body);
        self::assertFalse($body['success']);
    }

    public function testRejectsNonAllowedRoot(): void
    {
        $controller = new FsBrowseController([$this->root]);
        $response   = $controller->browse($this->makeRequest($this->outside), []);

        self::assertSame(403, $response->statusCode);
        $body = $this->decode($response->body);
        self::assertFalse($body['success']);
    }

    public function testNonExistentPathReturns404(): void
    {
        $controller = new FsBrowseController([$this->root]);
        $response   = $controller->browse($this->makeRequest($this->root . '/does-not-exist'), []);

        self::assertSame(404, $response->statusCode);
        $body = $this->decode($response->body);
        self::assertFalse($body['success']);
    }

    public function testFileInsteadOfDirectoryReturns400(): void
    {
        $controller = new FsBrowseController([$this->root]);
        $response   = $controller->browse($this->makeRequest($this->root . '/note.txt'), []);

        self::assertSame(400, $response->statusCode);
        $body = $this->decode($response->body);
        self::assertFalse($body['success']);
    }

    /**
     * A configured root that does not `realpath()`-resolve is silently dropped
     * at construction (controller ctor `continue`); the surviving real root is
     * still browsable, and a path under the dropped (non-existent) root is
     * rejected with 404 because `realpath()` returns false for it.
     */
    public function testConstructorDropsNonResolvingRoots(): void
    {
        $nonResolving = sys_get_temp_dir() . '/phlix_fs_browse_missing_' . uniqid('', true);
        self::assertFalse(realpath($nonResolving), 'precondition: the bad root must not resolve');

        // Bad (non-resolving) root first, then the real sandbox root. The ctor
        // must drop the bad one without erroring and keep the good one.
        $controller = new FsBrowseController([$nonResolving, $this->root]);

        // The good root still works: browsing it returns its subdirectories.
        $okResponse = $controller->browse($this->makeRequest($this->root), []);
        self::assertSame(200, $okResponse->statusCode);
        $okBody = $this->decode($okResponse->body);
        self::assertTrue($okBody['success']);
        $names = array_column($okBody['data']['entries'], 'name');
        self::assertContains('movies', $names);
        self::assertContains('tv', $names);

        // A path under the dropped (non-existent) root resolves to false → 404,
        // proving the bad root was not silently retained as a usable jail.
        $badResponse = $controller->browse($this->makeRequest($nonResolving . '/anything'), []);
        self::assertSame(404, $badResponse->statusCode);
        $badBody = $this->decode($badResponse->body);
        self::assertFalse($badBody['success']);
    }

    /**
     * Browsing a SUBDIRECTORY whose parent is still inside the jail returns a
     * non-null `data.parent` (the realpath of that parent), exercising the
     * truthy arm of the `$parent` computation. Browsing the root itself yields
     * `parent === null` (its filesystem parent is outside the jail), so we use a
     * nested directory here to reach the in-jail-parent case.
     */
    public function testBrowseReturnsParentWhenParentIsWithinJail(): void
    {
        // Nested tree: <root>/movies/marvel — created here and cleaned by the
        // existing recursive removeTree() in tearDown() (it descends real dirs).
        $movies = $this->root . '/movies';
        $marvel = $movies . '/marvel';
        mkdir($marvel, 0775, true);

        $controller = new FsBrowseController([$this->root]);
        $response   = $controller->browse($this->makeRequest($movies), []);

        self::assertSame(200, $response->statusCode);
        $body = $this->decode($response->body);
        self::assertTrue($body['success']);

        // path is the realpath of the browsed subdirectory.
        self::assertSame(realpath($movies), $body['data']['path']);

        // parent is non-null and equals the realpath of the root (movies' parent
        // is the root, which is in-jail) — this is the line-145 truthy arm.
        self::assertNotNull($body['data']['parent']);
        self::assertSame(realpath($this->root), $body['data']['parent']);

        // the nested subdir is listed among movies' entries.
        $names = array_column($body['data']['entries'], 'name');
        self::assertContains('marvel', $names);
    }

    /**
     * Build a Request whose `?path=` query value is the given path.
     */
    private function makeRequest(string $path): Request
    {
        $request        = new Request();
        $request->query = ['path' => $path];

        return $request;
    }

    /**
     * Decode a JSON response body into an associative array.
     *
     * @return array<string, mixed>
     */
    private function decode(string $body): array
    {
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Recursively delete a directory tree (handles the symlink we create).
     */
    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
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

            // Unlink symlinks (and files) directly; do NOT descend into a
            // symlinked directory or we would delete the link target.
            if (is_link($path) || is_file($path)) {
                @unlink($path);
                continue;
            }

            if (is_dir($path)) {
                $this->removeTree($path);
            }
        }

        @rmdir($dir);
    }
}
