<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library;

use Phlix\Media\Library\LibraryRootJail;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Second-layer path guard for the AUTHLESS DLNA stream route.
 *
 * ## Why this exists at all
 *
 * `/dlna/stream/{id}` carries no credentials — DLNA has no user — so its only
 * gate is an inbound IP allowlist. The path it serves comes from
 * `media_items.path`, i.e. the database, never the request; this jail is what
 * bounds the damage if a row is ever poisoned (a bad importer, a plugin, an
 * injection elsewhere). Without it the route would be an unauthenticated
 * arbitrary-file-read primitive for anything the worker can open.
 *
 * The tests therefore assert CONSEQUENCES an attacker would care about: a path
 * outside every configured root is refused, a symlink pointing out of a root is
 * refused (resolution happens before comparison), a sibling directory sharing a
 * name prefix is refused, and an unresolvable root set refuses EVERYTHING rather
 * than degrading to allow-all.
 *
 * @covers \Phlix\Media\Library\LibraryRootJail
 */
final class LibraryRootJailTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        parent::setUp();
        $base = realpath(sys_get_temp_dir());
        self::assertIsString($base);
        $this->tmp = $base . '/phlix_jail_' . uniqid('', true);
        mkdir($this->tmp . '/library/Movies', 0o777, true);
        mkdir($this->tmp . '/library-private', 0o777, true);
        mkdir($this->tmp . '/secrets', 0o777, true);
        file_put_contents($this->tmp . '/library/Movies/inside.mkv', 'inside');
        file_put_contents($this->tmp . '/library-private/sibling.mkv', 'sibling');
        file_put_contents($this->tmp . '/secrets/outside.mkv', 'outside');
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
     * A jail over a `libraries` table whose rows carry the given `paths` values.
     *
     * @param list<mixed> $pathsColumns One raw `paths` column value per row.
     */
    private function jailOver(array $pathsColumns): LibraryRootJail
    {
        $rows = [];
        foreach ($pathsColumns as $paths) {
            $rows[] = ['paths' => $paths];
        }

        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn($rows);

        return new LibraryRootJail($db);
    }

    /**
     * CONSEQUENCE: a file inside a configured root is admitted.
     *
     * The positive control — without it every other assertion here would also
     * pass against a jail that refused everything.
     */
    public function test_a_file_inside_a_configured_root_is_admitted(): void
    {
        $jail = $this->jailOver([json_encode([$this->tmp . '/library'])]);

        self::assertTrue($jail->allows($this->tmp . '/library/Movies/inside.mkv'));
    }

    /**
     * THE CORE SECURITY PROPERTY: a path outside every configured root is refused.
     *
     * Mutation-verified in intent: making allows() return true unconditionally
     * fails this.
     */
    public function test_a_path_outside_every_root_is_refused(): void
    {
        $jail = $this->jailOver([json_encode([$this->tmp . '/library'])]);

        self::assertFalse($jail->allows($this->tmp . '/secrets/outside.mkv'));
        self::assertFalse($jail->allows('/etc/passwd'));
    }

    /**
     * CONSEQUENCE: a traversal built from a root prefix is refused, because the
     * path is canonicalised BEFORE the prefix comparison.
     *
     * DISCRIMINATING: a naive `str_starts_with($rawPath, $root)` check admits
     * this exact string.
     */
    public function test_a_traversal_through_a_root_is_refused(): void
    {
        $jail = $this->jailOver([json_encode([$this->tmp . '/library'])]);

        self::assertFalse($jail->allows($this->tmp . '/library/../secrets/outside.mkv'));
    }

    /**
     * CONSEQUENCE: a symlink inside a root that points OUT of it is refused.
     *
     * realpath() resolves the link target first, so the escape is visible. A jail
     * that compared the link's own path would admit it.
     */
    public function test_a_symlink_escaping_a_root_is_refused(): void
    {
        $link = $this->tmp . '/library/Movies/escape.mkv';
        if (!@symlink($this->tmp . '/secrets/outside.mkv', $link)) {
            self::markTestSkipped('symlink() unavailable on this filesystem.');
        }

        $jail = $this->jailOver([json_encode([$this->tmp . '/library'])]);

        self::assertFalse($jail->allows($link), 'A symlink out of the library must not be served.');
    }

    /**
     * CONSEQUENCE: a sibling directory that merely shares a name prefix is NOT
     * inside the root.
     *
     * DISCRIMINATING: dropping the trailing separator from the stored root makes
     * `/library-private/...` match `/library`, so this test fails.
     */
    public function test_a_prefix_sharing_sibling_directory_is_refused(): void
    {
        $jail = $this->jailOver([json_encode([$this->tmp . '/library'])]);

        self::assertFalse($jail->allows($this->tmp . '/library-private/sibling.mkv'));
    }

    /**
     * CONSEQUENCE: any of SEVERAL configured libraries admits its own files.
     */
    public function test_every_configured_library_is_a_root(): void
    {
        $jail = $this->jailOver([
            json_encode([$this->tmp . '/secrets']),
            json_encode([$this->tmp . '/library', $this->tmp . '/library-private']),
        ]);

        self::assertTrue($jail->allows($this->tmp . '/library/Movies/inside.mkv'));
        self::assertTrue($jail->allows($this->tmp . '/library-private/sibling.mkv'));
        self::assertTrue($jail->allows($this->tmp . '/secrets/outside.mkv'));
    }

    /**
     * FAIL CLOSED: no resolvable root means nothing is allowed.
     *
     * An empty `libraries` table, a `paths` value that is not JSON, and a root
     * that does not exist on disk all resolve toward DENIAL. This is the property
     * that keeps a database hiccup from turning the authless route into an
     * open file server.
     *
     * @dataProvider unusableRootSets
     *
     * @param list<mixed> $pathsColumns
     */
    public function test_an_unusable_root_set_denies_everything(array $pathsColumns): void
    {
        $jail = $this->jailOver($pathsColumns);

        self::assertSame([], $jail->roots());
        self::assertFalse($jail->allows($this->tmp . '/library/Movies/inside.mkv'));
    }

    /**
     * @return iterable<string, array{0: list<mixed>}>
     */
    public static function unusableRootSets(): iterable
    {
        yield 'no libraries at all'   => [[]];
        yield 'paths is not json'     => [['not json']];
        yield 'paths is null'         => [[null]];
        yield 'paths is an empty set' => [['[]']];
        yield 'root does not exist'   => [['["/definitely/not/here"]']];
        yield 'root is a file'        => [['["/etc/hostname"]']];
    }

    /**
     * FAIL CLOSED: a database failure denies everything instead of propagating a
     * 500 out of an unauthenticated route.
     */
    public function test_a_database_failure_denies_everything(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willThrowException(new \RuntimeException('db down'));

        $jail = new LibraryRootJail($db);

        self::assertSame([], $jail->roots());
        self::assertFalse($jail->allows($this->tmp . '/library/Movies/inside.mkv'));
    }

    /**
     * CONSEQUENCE: a non-existent path is refused (there is nothing to serve),
     * and an empty path never reaches realpath().
     */
    public function test_missing_and_empty_paths_are_refused(): void
    {
        $jail = $this->jailOver([json_encode([$this->tmp . '/library'])]);

        self::assertFalse($jail->allows($this->tmp . '/library/Movies/gone.mkv'));
        self::assertFalse($jail->allows(''));
    }

    /**
     * CONSEQUENCE: the root itself is inside the jail, and each reported root is
     * canonical with a trailing separator (the invariant the prefix test relies
     * on).
     */
    public function test_reported_roots_are_canonical_and_separator_terminated(): void
    {
        $jail = $this->jailOver([json_encode([$this->tmp . '/library/'])]);

        self::assertSame([$this->tmp . '/library/'], $jail->roots());
        self::assertTrue($jail->allows($this->tmp . '/library'));
    }

    /**
     * CONSEQUENCE: the root list is read ONCE and reused, so a renderer issuing
     * hundreds of Range requests does not issue hundreds of extra queries in a
     * resident worker.
     */
    public function test_roots_are_cached_across_calls(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())
            ->method('query')
            ->willReturn([['paths' => json_encode([$this->tmp . '/library'])]]);

        $jail = new LibraryRootJail($db);

        self::assertTrue($jail->allows($this->tmp . '/library/Movies/inside.mkv'));
        self::assertTrue($jail->allows($this->tmp . '/library/Movies/inside.mkv'));
        self::assertNotSame([], $jail->roots());
    }

    /**
     * CONSEQUENCE: the cache EXPIRES, so a library added while the worker is up
     * starts being served within a minute instead of 404ing until the next restart.
     *
     * The cache-hit test above pins only half the contract, and the two failure
     * modes it cannot see are opposite and both real: a jail that never re-reads
     * makes a newly-added library permanently unservable, and one that re-reads on
     * every call issues a query per Range request — hundreds per playback — inside a
     * resident worker. This drives the TTL boundary directly (by ageing `cachedAt`
     * through reflection rather than sleeping, so the test stays instant and does
     * not block the event loop) and asserts EXACTLY one re-read, plus that the new
     * root is what the second read returns.
     */
    public function test_the_root_cache_is_refreshed_exactly_once_after_the_ttl(): void
    {
        $first = [['paths' => json_encode([$this->tmp . '/library'])]];
        $second = [['paths' => json_encode([$this->tmp . '/library', $this->tmp . '/library-private'])]];

        $db = $this->createMock(Connection::class);
        $db->expects(self::exactly(2))
            ->method('query')
            ->willReturnOnConsecutiveCalls($first, $second);

        $jail = new LibraryRootJail($db);

        // Read 1 populates the cache; the repeat is a hit (still one query).
        self::assertSame([$this->tmp . '/library/'], $jail->roots());
        self::assertSame([$this->tmp . '/library/'], $jail->roots());

        // Age the cache past its TTL without sleeping.
        $ttl = (new \ReflectionClass(LibraryRootJail::class))->getConstant('CACHE_TTL_SECONDS');
        self::assertIsInt($ttl);
        $cachedAt = new \ReflectionProperty(LibraryRootJail::class, 'cachedAt');
        $cachedAt->setValue($jail, time() - $ttl - 1);

        // Read 2 re-reads and picks up the newly-added library …
        self::assertSame(
            [$this->tmp . '/library/', $this->tmp . '/library-private/'],
            $jail->roots(),
            'A library added after the TTL window must become visible.',
        );
        self::assertTrue($jail->allows($this->tmp . '/library-private/sibling.mkv'));

        // … and then caches again, so the mock's exactly(2) also proves it did not
        // start re-reading on every call.
        self::assertSame(
            [$this->tmp . '/library/', $this->tmp . '/library-private/'],
            $jail->roots(),
        );
    }
}
