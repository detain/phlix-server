<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers\Dlna;

use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryRootJail;
use Phlix\Server\Http\Controllers\Dlna\DlnaStreamController;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * The bytes half of DLNA playback: `GET|HEAD /dlna/stream/{mediaItemId}`.
 *
 * ## What is actually under test
 *
 * Not "does it return a Response" — the consequences a renderer and an attacker
 * each experience:
 *
 *  - a renderer's scrub bar works: `bytes=A-B`, `bytes=A-`, `bytes=-N` all yield
 *    206 with the RIGHT bytes and an accurate `Content-Range`, and an
 *    unsatisfiable range yields 416 rather than a truncated 200;
 *  - a bogus id is a clean 404 — never a 500 and never a filesystem path;
 *  - a client-supplied id never reaches the filesystem: a traversal-shaped id is
 *    rejected before the repository is even consulted;
 *  - a row whose path escapes the library roots is refused, and the refusal is
 *    indistinguishable from "not found";
 *  - a container this server does not direct-play is 415, not bytes with a
 *    guessed Content-Type.
 *
 * `Response::materializeFileWindow()` collapses a `withFile()` response into the
 * exact status + headers + windowed body that the event loop would put on the
 * wire, so the byte-level assertions here are the real output, not a proxy for it.
 *
 * @covers \Phlix\Server\Http\Controllers\Dlna\DlnaStreamController
 */
final class DlnaStreamControllerTest extends TestCase
{
    /** Fixture body: 26 bytes, so every offset is identifiable. */
    private const BODY = 'abcdefghijklmnopqrstuvwxyz';

    private string $tmp = '';
    private string $mediaPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $base = realpath(sys_get_temp_dir());
        self::assertIsString($base);
        $this->tmp = $base . '/phlix_dlnastream_' . uniqid('', true);
        mkdir($this->tmp . '/library', 0o777, true);
        mkdir($this->tmp . '/outside', 0o777, true);
        $this->mediaPath = $this->tmp . '/library/Movie.mkv';
        file_put_contents($this->mediaPath, self::BODY);
        file_put_contents($this->tmp . '/outside/Secret.mkv', 'secret');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmp);
        parent::tearDown();
    }

    private function removeTree(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_link($path) || is_file($path)) {
                unlink($path);
                continue;
            }
            $this->removeTree($path);
        }
        rmdir($dir);
    }

    /**
     * A real {@see LibraryRootJail} whose only library root is the fixture dir.
     */
    private function jail(): LibraryRootJail
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([['paths' => json_encode([$this->tmp . '/library'])]]);

        return new LibraryRootJail($db);
    }

    /**
     * Controller over a repository that answers `$row` for ANY id.
     *
     * @param array<string, mixed>|null $row The hydrated `media_items` row.
     */
    private function controller(?array $row): DlnaStreamController
    {
        $items = $this->createMock(ItemRepository::class);
        $items->method('findById')->willReturn($row);

        return new DlnaStreamController($items, $this->jail(), null);
    }

    /**
     * A row pointing at the fixture file.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'id'   => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'type' => 'movie',
            'path' => $this->mediaPath,
        ], $overrides);
    }

    private function request(string $method = 'GET', ?string $range = null): Request
    {
        $request = new Request();
        $request->method = $method;
        $request->path = '/dlna/stream/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $request->remoteIp = '192.168.1.50';
        if ($range !== null) {
            $request->headers = ['RANGE' => $range];
        }

        return $request;
    }

    /**
     * @param array<string, string> $params
     */
    private function handle(
        DlnaStreamController $controller,
        Request $request,
        array $params = ['mediaItemId' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'],
    ): Response {
        return $controller->handle($request, $params)->materializeFileWindow();
    }

    /**
     * CONSEQUENCE: a plain GET streams the whole file with the container's real
     * MIME and advertises byte-range support (which S53's `DLNA.ORG_OP=01` claims).
     */
    public function test_a_plain_get_serves_the_whole_file(): void
    {
        $response = $this->handle($this->controller($this->row()), $this->request());

        self::assertSame(200, $response->statusCode);
        self::assertSame(self::BODY, $response->body);
        self::assertSame('video/x-matroska', $response->headers['Content-Type'] ?? null);
        self::assertSame('bytes', $response->headers['Accept-Ranges'] ?? null);
        self::assertSame((string) strlen(self::BODY), $response->headers['Content-Length'] ?? null);
    }

    /**
     * CONSEQUENCE: a closed range returns EXACTLY that window, 206, with an
     * accurate `Content-Range`.
     *
     * DISCRIMINATING on the bytes: an off-by-one in the length arithmetic
     * (`end - start` instead of `end - start + 1`) returns 'efgh' and fails.
     */
    public function test_a_closed_range_returns_that_window(): void
    {
        $response = $this->handle($this->controller($this->row()), $this->request('GET', 'bytes=4-8'));

        self::assertSame(206, $response->statusCode);
        self::assertSame('efghi', $response->body);
        self::assertSame('bytes 4-8/26', $response->headers['Content-Range'] ?? null);
        self::assertSame('5', $response->headers['Content-Length'] ?? null);
        self::assertSame('video/x-matroska', $response->headers['Content-Type'] ?? null);
    }

    /**
     * CONSEQUENCE: an open-ended range (the form a renderer's seek uses) streams
     * from the offset to EOF.
     */
    public function test_an_open_ended_range_streams_to_eof(): void
    {
        $response = $this->handle($this->controller($this->row()), $this->request('GET', 'bytes=20-'));

        self::assertSame(206, $response->statusCode);
        self::assertSame('uvwxyz', $response->body);
        self::assertSame('bytes 20-25/26', $response->headers['Content-Range'] ?? null);
    }

    /**
     * CONSEQUENCE: a suffix range returns the LAST N bytes.
     */
    public function test_a_suffix_range_returns_the_tail(): void
    {
        $response = $this->handle($this->controller($this->row()), $this->request('GET', 'bytes=-3'));

        self::assertSame(206, $response->statusCode);
        self::assertSame('xyz', $response->body);
        self::assertSame('bytes 23-25/26', $response->headers['Content-Range'] ?? null);
    }

    /**
     * CONSEQUENCE: an over-long last-byte-pos is CLAMPED to EOF and answered 206
     * (RFC 7233 §2.1), not rejected — some renderers ask for `bytes=0-999999999`.
     */
    public function test_an_over_long_range_end_is_clamped_not_rejected(): void
    {
        $response = $this->handle($this->controller($this->row()), $this->request('GET', 'bytes=0-999999'));

        self::assertSame(206, $response->statusCode);
        self::assertSame(self::BODY, $response->body);
        self::assertSame('bytes 0-25/26', $response->headers['Content-Range'] ?? null);
    }

    /**
     * CONSEQUENCE: a range starting past EOF is 416 with `Content-Range: bytes
     * *\/size` and NO body — never a silently-truncated 200.
     */
    public function test_an_unsatisfiable_range_is_416(): void
    {
        $response = $this->handle($this->controller($this->row()), $this->request('GET', 'bytes=100-200'));

        self::assertSame(416, $response->statusCode);
        self::assertSame('bytes */26', $response->headers['Content-Range'] ?? null);
        self::assertSame('', $response->body);
    }

    /**
     * CONSEQUENCE: an unsupported multi-range value falls back to a whole-file
     * 200 (a valid RFC 7233 response) rather than erroring.
     */
    public function test_a_multi_range_request_falls_back_to_the_whole_file(): void
    {
        $response = $this->handle($this->controller($this->row()), $this->request('GET', 'bytes=0-1,4-5'));

        self::assertSame(200, $response->statusCode);
        self::assertSame(self::BODY, $response->body);
    }

    /**
     * CONSEQUENCE: HEAD reports the real size and range support with an EMPTY
     * body — many renderers HEAD before opening a resource, and a
     * `Content-Length: 0` there makes them give up.
     *
     * ## Asserted on the ENCODED BYTES, deliberately
     *
     * This test used to read `Response::$headers`, and that is why it passed while
     * the wire was broken: Workerman's encoder appends its OWN
     * `Content-Length: strlen($body)` after every header the caller set
     * (`vendor/workerman/workerman/src/Protocols/Http/Response.php:580-583`), so
     * the reply shipped `Content-Length: 26` … `Content-Length: 0`, with the bogus
     * value LAST. The headers array showed only the correct one. A header-array
     * assertion cannot observe this class of defect AT ALL, so the assertion has
     * to be made one layer down, on what actually leaves the socket.
     *
     * RFC 9110 §8.6 makes a message with conflicting `Content-Length` invalid:
     * recipients must reject it and hardened proxies drop it as a smuggling
     * defence — which breaks precisely the probe this arm exists to serve.
     *
     * DISCRIMINATING: reverting to a plain `WorkermanResponse` makes
     * `Content-Length:` appear twice and this test fails; relying on
     * `Router::dispatch()`'s GET→HEAD fallback (which suppresses the file-backed
     * body without computing its length) reports 0 and it fails too.
     */
    public function test_head_puts_exactly_one_real_content_length_on_the_wire(): void
    {
        $response = $this->controller($this->row())->handle(
            $this->request('HEAD'),
            ['mediaItemId' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'],
        );

        $wire = (string) $response->toWorkermanResponse();

        self::assertSame(
            1,
            substr_count($wire, 'Content-Length:'),
            "A HEAD reply must carry exactly ONE Content-Length. Encoded bytes were:\n" . $wire,
        );
        self::assertStringContainsString("HTTP/1.1 200 OK\r\n", $wire);
        self::assertStringContainsString("Content-Length: 26\r\n", $wire);
        self::assertStringNotContainsString('Content-Length: 0', $wire);
        self::assertStringContainsString("Accept-Ranges: bytes\r\n", $wire);
        self::assertStringContainsString("Content-Type: video/x-matroska\r\n", $wire);

        // …and no body at all after the header terminator (RFC 9110 §9.3.2).
        $parts = explode("\r\n\r\n", $wire, 2);
        self::assertSame('', $parts[1] ?? 'HEADER TERMINATOR MISSING', 'A HEAD reply must have no body.');
    }

    /**
     * CONSEQUENCE: an unknown id is a clean 404 whose body names nothing.
     */
    public function test_an_unknown_id_is_a_clean_404(): void
    {
        $response = $this->handle($this->controller(null), $this->request());

        self::assertSame(404, $response->statusCode);
        self::assertSame('Media not found', $response->body);
    }

    /**
     * SECURITY: a client-supplied id that looks like a path never reaches the
     * repository, let alone the filesystem.
     *
     * The repository mock asserts `findById` is NEVER called: the id is rejected
     * on shape first. Dropping the id-pattern check makes this test fail even
     * though the response status would still be 404.
     *
     * @dataProvider hostileIds
     */
    public function test_a_hostile_id_is_rejected_before_any_lookup(string $id): void
    {
        $items = $this->createMock(ItemRepository::class);
        $items->expects(self::never())->method('findById');
        $controller = new DlnaStreamController($items, $this->jail(), null);

        $response = $controller->handle($this->request(), ['mediaItemId' => $id]);

        self::assertSame(404, $response->statusCode);
        self::assertStringNotContainsString('/', $response->body);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function hostileIds(): iterable
    {
        yield 'parent traversal'   => ['..'];
        yield 'encoded traversal'  => ['%2e%2e%2f%2e%2e%2fetc%2fpasswd'];
        yield 'absolute path'      => ['\\etc\\passwd'];
        yield 'dotted filename'    => ['etc.passwd'];
        yield 'null byte'          => ["abc\0def"];
        yield 'empty'              => [''];
        yield 'sql-ish'            => ["1' OR '1'='1"];
        yield 'over-long'          => [str_repeat('a', 65)];
    }

    /**
     * SECURITY: a row whose path resolves OUTSIDE every configured library root
     * is refused — and refused with the same 404 as "not found", so an
     * unauthenticated caller cannot use the response to probe the filesystem.
     *
     * This is the consequence that matters if a `media_items` row is ever
     * poisoned: the authless route must not become an arbitrary-file reader.
     */
    public function test_a_row_pointing_outside_the_library_roots_is_refused(): void
    {
        $response = $this->handle(
            $this->controller($this->row(['path' => $this->tmp . '/outside/Secret.mkv'])),
            $this->request(),
        );

        self::assertSame(404, $response->statusCode);
        self::assertSame('Media not found', $response->body);
        self::assertStringNotContainsString($this->tmp, $response->body, 'The reply must not leak a path.');
    }

    /**
     * SECURITY: the same refusal for the classic traversal payload smuggled into
     * the stored path, because the path is canonicalised before the jail check.
     */
    public function test_a_row_with_a_traversal_path_is_refused(): void
    {
        $response = $this->handle(
            $this->controller($this->row(['path' => $this->tmp . '/library/../outside/Secret.mkv'])),
            $this->request(),
        );

        self::assertSame(404, $response->statusCode);
    }

    /**
     * CONSEQUENCE: a row whose file is gone (or is a directory, as every
     * container object's `path` is) is 404, not a 500.
     *
     * @dataProvider unservablePaths
     */
    public function test_an_unservable_path_is_404(string $suffix): void
    {
        $response = $this->handle(
            $this->controller($this->row(['path' => $this->tmp . $suffix])),
            $this->request(),
        );

        self::assertSame(404, $response->statusCode);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function unservablePaths(): iterable
    {
        yield 'file deleted'        => ['/library/Gone.mkv'];
        yield 'path is a directory' => ['/library'];
    }

    /**
     * CONSEQUENCE: a row with a missing/blank/non-string `path` is 404.
     *
     * @dataProvider brokenRowPaths
     */
    public function test_a_row_without_a_usable_path_is_404(mixed $path): void
    {
        $response = $this->handle($this->controller($this->row(['path' => $path])), $this->request());

        self::assertSame(404, $response->statusCode);
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function brokenRowPaths(): iterable
    {
        yield 'null'   => [null];
        yield 'empty'  => [''];
        yield 'number' => [42];
        yield 'array'  => [['/x']];
    }

    /**
     * CONSEQUENCE: a container this server does not direct-play is answered 415,
     * not bytes under a guessed Content-Type.
     *
     * DISCRIMINATING: the row is typed `movie`, so a gate keyed on the row's
     * `type` (rather than on the container) would happily serve this `.iso` as
     * `video/mp4` and the renderer would fail mid-decode.
     */
    public function test_an_unrecognised_container_is_415(): void
    {
        $isoPath = $this->tmp . '/library/Disc.iso';
        file_put_contents($isoPath, 'iso');

        $response = $this->handle($this->controller($this->row(['path' => $isoPath])), $this->request());

        self::assertSame(415, $response->statusCode);
        self::assertSame('Unsupported media type for direct play', $response->body);
    }

    /**
     * SECURITY / CONSEQUENCE: the direct-play gate reads the CANONICAL path, so a
     * symlink cannot smuggle an unsupported container past it.
     *
     * `Film.mp4 → Disc.iso` inside a library root is exactly the case where the
     * stored path and the bytes disagree: the jail resolves the symlink and admits
     * the target (it is inside the root), and the bytes served are the `.iso`'s. A
     * gate reading the RAW `media_items.path` sees `.mp4`, so it serves an ISO
     * image labelled `video/mp4` and the renderer fails mid-decode — the very
     * failure the gate's own comment claims to prevent, reached by symlink instead
     * of by the row's `type`.
     *
     * DISCRIMINATING: reverting the gate to `forPath($path)` returns 200 with
     * `Content-Type: video/mp4` and this fails on both counts.
     */
    public function test_a_symlink_to_an_unsupported_container_is_still_415(): void
    {
        $isoPath = $this->tmp . '/library/Disc.iso';
        file_put_contents($isoPath, 'iso-bytes');
        $linkPath = $this->tmp . '/library/Film.mp4';
        self::assertTrue(symlink($isoPath, $linkPath), 'Fixture requires symlink support.');

        $response = $this->handle($this->controller($this->row(['path' => $linkPath])), $this->request());

        self::assertSame(415, $response->statusCode, 'The container that is SERVED is what must be gated.');
        self::assertSame('Unsupported media type for direct play', $response->body);
        self::assertNotSame('video/mp4', $response->headers['Content-Type'] ?? null);
    }

    /**
     * CONSEQUENCE (the inverse control): a symlink to a container this server DOES
     * direct-play is served, and typed from the TARGET, not from the link name.
     *
     * Without this the test above would also pass against a controller that
     * rejected every symlink outright, which would break the perfectly ordinary
     * "library of symlinks into a NAS" layout.
     */
    public function test_a_symlink_to_a_supported_container_is_served_as_the_target_type(): void
    {
        $targetPath = $this->tmp . '/library/Real.webm';
        file_put_contents($targetPath, self::BODY);
        $linkPath = $this->tmp . '/library/Alias.mp4';
        self::assertTrue(symlink($targetPath, $linkPath), 'Fixture requires symlink support.');

        $response = $this->handle($this->controller($this->row(['path' => $linkPath])), $this->request());

        self::assertSame(200, $response->statusCode);
        self::assertSame(self::BODY, $response->body);
        self::assertSame('video/webm', $response->headers['Content-Type'] ?? null);
    }

    /**
     * CONSEQUENCE: an explicit `mime_type` on the row is what the bytes are
     * served as, so the `Content-Type` matches the `protocolInfo` MIME the
     * ContentDirectory advertises for the same item.
     */
    public function test_an_explicit_row_mime_type_is_served(): void
    {
        $response = $this->handle(
            $this->controller($this->row(['mime_type' => 'video/mp4'])),
            $this->request(),
        );

        self::assertSame(200, $response->statusCode);
        self::assertSame('video/mp4', $response->headers['Content-Type'] ?? null);
    }

    /**
     * CONSEQUENCE: a junk `mime_type` (these rows are hand-editable) does not
     * reach the renderer — the container's own type is used instead.
     */
    public function test_a_junk_row_mime_type_falls_back_to_the_container_type(): void
    {
        $response = $this->handle(
            $this->controller($this->row(['mime_type' => 'not-a-mime'])),
            $this->request(),
        );

        self::assertSame(200, $response->statusCode);
        self::assertSame('video/x-matroska', $response->headers['Content-Type'] ?? null);
    }

    /**
     * CONSEQUENCE: no response on ANY path carries a filesystem path.
     *
     * DLNA is unauthenticated, so every reply is public. This sweeps the whole
     * answer surface at once rather than trusting each branch individually.
     */
    public function test_no_response_ever_leaks_a_filesystem_path(): void
    {
        $cases = [
            $this->handle($this->controller($this->row()), $this->request()),
            $this->handle($this->controller($this->row()), $this->request('GET', 'bytes=999-')),
            $this->handle($this->controller(null), $this->request()),
            $this->handle($this->controller($this->row(['path' => $this->tmp . '/outside/Secret.mkv'])), $this->request()),
            $this->handle($this->controller($this->row(['path' => $this->tmp . '/library/Gone.mkv'])), $this->request()),
        ];

        foreach ($cases as $index => $response) {
            self::assertStringNotContainsString(
                $this->tmp,
                $response->body . implode('|', $response->headers),
                sprintf('Case %d leaked a filesystem path.', $index),
            );
        }
    }
}
