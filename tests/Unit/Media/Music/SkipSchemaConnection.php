<?php

/**
 * Phlix media server test double: Media\Music.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Music;

use Phlix\Media\Music\MusicScanSkipIndex;
use Workerman\MySQL\Connection;

/**
 * In-memory `media_items` + `music_*` schema for the S122(a) tests.
 *
 * Deliberately its OWN double rather than a change to
 * {@see MusicLibraryScannerTest}'s `MusicSchemaConnection`: that one has been hardened
 * across ten review rounds around the INSERT/UPDATE return-value contract and is
 * pinned by tests that count its arms. This one adds what S122 needs — a
 * `metadata_json` document per row, the `JSON_SET` stamp, the identity-map SELECT and
 * the heal gate — without touching those pins.
 *
 * It follows the same measured client contract, because that contract is what the
 * production code branches on: `SELECT` → list, `INSERT` → the insert id AS A STRING
 * (`'0'` for `media_items`, which has a UUID primary key and no `AUTO_INCREMENT` — and
 * `'0'` is FALSY, which is why the scanner must not use `if (!$result)`),
 * `UPDATE`/`DELETE`/`REPLACE` → an affected-row `int`, anything else → `null`.
 *
 * @internal
 */
final class SkipSchemaConnection extends Connection
{
    /**
     * @var list<array{id:string, library_id:?string, type:string, name:string, path:string,
     *     parent_id:?string, metadata_json:array<string, mixed>}>
     */
    public array $mediaItems = [];

    /** @var array<string, array{id:int, name:string, media_item_id:?string}> By lower-case name. */
    public array $artists = [];

    /**
     * @var array<int, array{id:int, artist_id:int, title:string, media_item_id:?string, total_tracks:int}>
     */
    public array $albums = [];

    /**
     * ⚠ `artist_id` was MISSING from this row until S145 — `runInsert()` dropped
     * `$p[2]` entirely, so the double could not express a track whose `artist_id`
     * disagrees with its album, which is exactly the mis-parenting S145 repairs.
     * A double that cannot represent the defect cannot pin the fix.
     *
     * @var array<string, array{id:int, album_id:int, artist_id:int, title:string,
     *     track_number:int, disc_number:int, duration_secs:int}> By media_item_id.
     */
    public array $tracks = [];

    /** @var list<string> Every statement, in order. */
    public array $statements = [];

    /** @var list<string> Statement substrings whose query() returns NULL. */
    private array $nullOn = [];

    /** @var list<string> Statement substrings whose query() THROWS. */
    private array $throwOn = [];

    private int $autoInc = 0;

    /** Intentionally does not call the parent constructor (which would connect). */
    public function __construct()
    {
    }

    /**
     * Make every statement containing `$needle` report that it wrote nothing, the way
     * the real client does for an INSERT: `null`.
     *
     * @param string $needle Statement substring.
     * @return void
     */
    public function returnNullFor(string $needle): void
    {
        $this->nullOn[] = $needle;
    }

    /**
     * Disarm every {@see self::returnNullFor()}.
     *
     * @return void
     */
    public function clearNullFor(): void
    {
        $this->nullOn = [];
    }

    /**
     * Make every statement containing `$needle` THROW — the shape a real SQL error takes
     * (`Connection::execute()` re-throws, it never returns `false`).
     *
     * @param string $needle Statement substring.
     * @return void
     */
    public function throwFor(string $needle): void
    {
        $this->throwOn[] = $needle;
    }

    /**
     * Mirrors the driver's own signature (`Connection::query($query, $params,
     * $fetchmode)`), which is why it is untyped here.
     *
     * @param string $query
     * @param array<int, mixed>|null $params
     * @param int $fetchmode
     * @return array<int, mixed>|int|string|null
     */
    public function query($query = '', $params = null, $fetchmode = \PDO::FETCH_ASSOC)
    {
        unset($fetchmode);
        $sql = ltrim((string) $query);
        $bound = is_array($params) ? array_values($params) : [];
        $this->statements[] = $sql;

        foreach ($this->throwOn as $needle) {
            if (str_contains($sql, $needle)) {
                throw new \RuntimeException('injected failure for: ' . $needle);
            }
        }

        foreach ($this->nullOn as $needle) {
            if (str_contains($sql, $needle)) {
                return null;
            }
        }

        return match (strtolower(trim(explode(' ', trim($sql))[0]))) {
            'select', 'show' => $this->runSelect($sql, $bound),
            'insert' => $this->runInsert($sql, $bound),
            'update', 'delete', 'replace' => $this->runUpdate($sql, $bound),
            default => null,
        };
    }

    /** @return string */
    public function lastInsertId()
    {
        return (string) $this->autoInc;
    }

    /**
     * @param array<int, mixed> $p
     * @return list<array<string, mixed>>
     */
    private function runSelect(string $sql, array $p): array
    {
        // S122(a) identity map.
        //
        // ⚠ THE BRANCH IS RECOGNISED BY THE COLUMNS IT ASKS FOR, AND THE JOIN IS APPLIED
        // ONLY WHEN THE STATEMENT ACTUALLY CONTAINS IT. Keying the branch on the join
        // itself made this double UNFAITHFUL to the one mutation that matters: deleting
        // `JOIN music_tracks` from the production SQL made the statement fall through to
        // the other handlers and return NOTHING, so two tests stayed green while a real
        // MySQL would have returned MORE rows and skipped a file that has no track row.
        // Measured: with that shape,
        // `testAFileTheScanLostIsNotStampedAndIsRetried` passed against the mutation. Same
        // rule the sibling double applies to the album adoption's `parent_id` scoping — a
        // fake that filters unconditionally keeps passing after the predicate is deleted.
        if (str_contains($sql, 'file_mtime') && str_contains($sql, "mi.type = 'track'")) {
            $joined = str_contains($sql, 'JOIN music_tracks mt ON mt.media_item_id = mi.id');
            $out = [];
            foreach ($this->mediaItems as $row) {
                if ($row['type'] !== 'track' || $row['library_id'] !== ($p[0] ?? null)) {
                    continue;
                }
                if ($joined && !isset($this->tracks[$row['id']])) {
                    continue;
                }
                $mtime = $row['metadata_json'][MusicScanSkipIndex::KEY_MTIME] ?? null;
                $size = $row['metadata_json'][MusicScanSkipIndex::KEY_SIZE] ?? null;
                $out[] = [
                    'path' => $row['path'],
                    // `->>` unquotes to a STRING, or SQL NULL when the path is absent.
                    'file_mtime' => is_int($mtime) ? (string) $mtime : null,
                    'file_size' => is_int($size) ? (string) $size : null,
                ];
            }

            return $out;
        }

        // S122(a) heal gate (S96(e) protection).
        if (str_contains($sql, 'AS unhealed FROM music_artists WHERE media_item_id IS NULL')) {
            foreach ($this->artists as $artist) {
                if ($artist['media_item_id'] === null) {
                    return [['unhealed' => 1]];
                }
            }
            foreach ($this->albums as $album) {
                if ($album['media_item_id'] === null) {
                    return [['unhealed' => 1]];
                }
            }

            return [];
        }

        // One-per-scan orphan gate.
        if (str_contains($sql, 'LEFT JOIN music_artists ar') && str_contains($sql, 'LEFT JOIN music_albums al')) {
            foreach ($this->mediaItems as $row) {
                if (
                    in_array($row['type'], ['artist', 'album'], true)
                    && $row['path'] === ''
                    && $row['library_id'] === ($p[0] ?? null)
                    && !$this->isReferenced($row['id'])
                ) {
                    return [['id' => $row['id']]];
                }
            }

            return [];
        }

        if (str_contains($sql, 'LEFT JOIN music_artists ma')) {
            foreach ($this->mediaItems as $row) {
                if (
                    $row['type'] === 'artist'
                    && $row['name'] === ($p[0] ?? null)
                    && $row['path'] === ''
                    && $row['library_id'] === ($p[1] ?? null)
                    && !$this->isReferenced($row['id'])
                ) {
                    return [['id' => $row['id']]];
                }
            }

            return [];
        }

        if (str_contains($sql, 'LEFT JOIN music_albums ma')) {
            foreach ($this->mediaItems as $row) {
                if (
                    $row['type'] === 'album'
                    && $row['name'] === ($p[0] ?? null)
                    && $row['path'] === ''
                    && $row['library_id'] === ($p[1] ?? null)
                    && !$this->isReferenced($row['id'])
                ) {
                    return [['id' => $row['id']]];
                }
            }

            return [];
        }

        if (str_contains($sql, 'FROM music_artists WHERE name')) {
            $key = strtolower((string) ($p[0] ?? ''));

            return isset($this->artists[$key])
                ? [['id' => $this->artists[$key]['id'], 'media_item_id' => $this->artists[$key]['media_item_id']]]
                : [];
        }

        if (str_contains($sql, 'FROM music_albums WHERE artist_id')) {
            foreach ($this->albums as $album) {
                if ($album['artist_id'] === (int) ($p[0] ?? 0) && $album['title'] === ($p[1] ?? null)) {
                    return [['id' => $album['id'], 'media_item_id' => $album['media_item_id']]];
                }
            }

            return [];
        }

        // S151: the production statement is
        // `type = 'track' AND path_hash = ? AND path = ? AND library_id <=> ?`,
        // bound `[sha1($path), $path, $libraryId]`. The hash is matched by RECOMPUTING
        // it from the stored row rather than trusting the position, so reverting the
        // production query to the pre-S151 two-parameter form (`[$path, $libraryId]`)
        // makes `$p[0]` a raw path that can never equal a SHA-1 and every lookup misses
        // — i.e. this double KILLS that mutation instead of shrugging at it.
        //
        // ⚠ The production method has a SECOND, raw-path pass (`[$path, $libraryId]`)
        // for databases where migration 087 left `path_hash` NULL. This branch matches
        // it too and, because the sha1 test then fails, answers it with `[]` — i.e.
        // this double models a DB where the hash IS present, so the fallback is
        // correctly inert here. Its behaviour is pinned separately by
        // MusicLibraryScannerTest::testTheTrackLookupStillResolvesWhenPathHashIsNullForEveryRow().
        if (str_contains($sql, "FROM media_items WHERE type = 'track'")) {
            foreach ($this->mediaItems as $row) {
                if (
                    $row['type'] === 'track'
                    && sha1((string) $row['path']) === ($p[0] ?? null)
                    && $row['path'] === ($p[1] ?? null)
                    && $row['library_id'] === ($p[2] ?? null)
                ) {
                    return [['id' => $row['id']]];
                }
            }

            return [];
        }

        // Returns the stored row wholesale, so its key set IS the production SELECT's
        // column list: `id, album_id, artist_id, title, track_number, disc_number,
        // duration_secs` (S145 widened both ends together — the row gained `artist_id`
        // in `runInsert()`, the statement gained `album_id, artist_id`).
        //
        // ⚠ "Wholesale" means the statement's column list is NEVER consulted, so this
        // double cannot tell a fetched column from an unfetched one — the permissiveness
        // that let mutation M10 survive the whole unit suite in S145 (see the same
        // warning, at length, on {@see MusicLibraryScannerTest::statefulDbMock()}). A
        // claim about WHICH COLUMNS a statement fetches therefore belongs in
        // {@see \Phlix\Tests\Integration\Media\RecordingMySqlConnection}, against a
        // real server.
        //
        // Statement VOLUME is a different matter and is deliberately NOT covered by that
        // warning: {@see self::$statements} appends every statement this double is asked
        // to run, so counting them is exact — that is what
        // {@see MusicScanReparentTest::testAllTracksMovingOffOneAlbumCostASingleRecount()}
        // and the stamp-UPDATE guards in the same file do. Two honest limits on such a
        // count: only the SQL is kept, never the bound parameters, so it can say HOW MANY
        // statements of a shape were issued and never WHICH ROW they targeted; and it
        // counts what the scanner issued GIVEN THE ANSWERS ABOVE, so wherever a branch
        // turns on what a real server would have returned, the count is only as good as
        // this model of it.
        if (str_contains($sql, 'FROM music_tracks WHERE media_item_id')) {
            $mid = (string) ($p[0] ?? '');

            return isset($this->tracks[$mid]) ? [$this->tracks[$mid]] : [];
        }

        return [];
    }

    /**
     * @param array<int, mixed> $p
     * @return string The insert id, as the real client reports it.
     */
    private function runInsert(string $sql, array $p): string
    {
        if (str_starts_with($sql, 'INSERT INTO media_items')) {
            $decoded = is_string($p[5] ?? null) ? json_decode($p[5], true) : null;
            $this->mediaItems[] = [
                'id' => (string) ($p[0] ?? ''),
                'library_id' => is_string($p[1] ?? null) ? $p[1] : null,
                'type' => (string) ($p[2] ?? ''),
                'name' => (string) ($p[3] ?? ''),
                'path' => (string) ($p[4] ?? ''),
                'parent_id' => null,
                'metadata_json' => is_array($decoded) ? $decoded : [],
            ];

            // media_items has a UUID primary key and no AUTO_INCREMENT, so a SUCCESSFUL
            // insert reports lastInsertId() = '0' — falsy, and measured.
            return '0';
        }

        if (str_starts_with($sql, 'INSERT INTO music_artists')) {
            $this->autoInc++;
            $name = (string) ($p[0] ?? '');
            $this->artists[strtolower($name)] = [
                'id' => $this->autoInc,
                'name' => $name,
                'media_item_id' => is_string($p[2] ?? null) ? $p[2] : null,
            ];

            return (string) $this->autoInc;
        }

        if (str_starts_with($sql, 'INSERT INTO music_albums')) {
            $this->autoInc++;
            $this->albums[$this->autoInc] = [
                'id' => $this->autoInc,
                'artist_id' => (int) ($p[0] ?? 0),
                'title' => (string) ($p[2] ?? ''),
                'media_item_id' => is_string($p[1] ?? null) ? $p[1] : null,
                'total_tracks' => 0,
            ];

            return (string) $this->autoInc;
        }

        if (str_starts_with($sql, 'INSERT INTO music_tracks')) {
            $this->autoInc++;
            $this->tracks[(string) ($p[0] ?? '')] = [
                'id' => $this->autoInc,
                'album_id' => (int) ($p[1] ?? 0),
                // S145: `$p[2]` used to be dropped on the floor. The production INSERT
                // has always named `artist_id` third, so the stored row silently lacked
                // the column, `runSelect()` could never return it, and no test could
                // assert on a track's artist parentage.
                'artist_id' => (int) ($p[2] ?? 0),
                'title' => (string) ($p[3] ?? ''),
                'track_number' => (int) ($p[4] ?? 0),
                'disc_number' => (int) ($p[5] ?? 1),
                'duration_secs' => (int) ($p[6] ?? 0),
            ];

            return (string) $this->autoInc;
        }

        return '0';
    }

    /**
     * @param array<int, mixed> $p
     * @return int Affected rows, as the real client reports for these keywords.
     */
    private function runUpdate(string $sql, array $p): int
    {
        // S122(a) stamp. JSON_SET semantics: MERGE the two keys into the existing
        // document, never replace it — a fake that replaced it would hide the fact that
        // the production statement has to COALESCE a NULL document.
        if (str_contains($sql, 'UPDATE media_items SET metadata_json = JSON_SET')) {
            $id = (string) ($p[2] ?? '');
            foreach ($this->mediaItems as $i => $row) {
                if ($row['id'] !== $id) {
                    continue;
                }
                $this->mediaItems[$i]['metadata_json'][MusicScanSkipIndex::KEY_MTIME] = (int) ($p[0] ?? 0);
                $this->mediaItems[$i]['metadata_json'][MusicScanSkipIndex::KEY_SIZE] = (int) ($p[1] ?? 0);

                return 1;
            }

            return 0;
        }

        if (str_contains($sql, 'UPDATE music_artists SET media_item_id')) {
            foreach ($this->artists as $key => $artist) {
                if ($artist['id'] !== (int) ($p[1] ?? 0) || $artist['media_item_id'] !== null) {
                    continue;
                }
                $this->artists[$key]['media_item_id'] = is_string($p[0] ?? null) ? $p[0] : null;

                return 1;
            }

            return 0;
        }

        if (str_contains($sql, 'UPDATE music_albums SET media_item_id')) {
            foreach ($this->albums as $id => $album) {
                if ($album['id'] !== (int) ($p[1] ?? 0) || $album['media_item_id'] !== null) {
                    continue;
                }
                $this->albums[$id]['media_item_id'] = is_string($p[0] ?? null) ? $p[0] : null;

                return 1;
            }

            return 0;
        }

        if (str_contains($sql, 'SET a.total_tracks')) {
            $albumId = (int) ($p[0] ?? 0);
            if (!isset($this->albums[$albumId])) {
                return 0;
            }
            $count = 0;
            foreach ($this->tracks as $track) {
                if ($track['album_id'] === $albumId) {
                    $count++;
                }
            }
            $this->albums[$albumId]['total_tracks'] = $count;

            return 1;
        }

        if (str_contains($sql, 'UPDATE music_albums SET year')) {
            return 1;
        }

        // ⚠ S145 — THE POSITIONAL LANDMINE. This handler read the row id from `$p[4]`,
        // which was the last parameter while the production UPDATE named four columns.
        // The moment that statement gained `album_id` and `artist_id`, `$p[4]` stopped
        // being the id, this `foreach` matched nothing, and
        // {@see MusicScanUnchangedSkipTest::testAChangedFileIsStampedSoItIsSkippedOnTheFollowingScan()}
        // went RED **with production correct**. That is why the double and the
        // `upsertTrack()` change ship in ONE commit.
        //
        // The production column order is pinned deliberately: the two new columns are
        // APPENDED after `duration_secs` and `id` stays LAST, so this stays a
        // three-index edit rather than a re-shuffle.
        if (str_contains($sql, 'UPDATE music_tracks SET title')) {
            foreach ($this->tracks as $mid => $track) {
                if ($track['id'] !== (int) ($p[6] ?? 0)) {
                    continue;
                }
                $this->tracks[$mid]['title'] = (string) ($p[0] ?? '');
                $this->tracks[$mid]['track_number'] = (int) ($p[1] ?? 0);
                $this->tracks[$mid]['disc_number'] = (int) ($p[2] ?? 1);
                $this->tracks[$mid]['duration_secs'] = (int) ($p[3] ?? 0);
                $this->tracks[$mid]['album_id'] = (int) ($p[4] ?? 0);
                $this->tracks[$mid]['artist_id'] = (int) ($p[5] ?? 0);

                return 1;
            }

            return 0;
        }

        return 0;
    }

    /**
     * Every artist/album `media_items` row that no `music_*` row points at.
     *
     * @return list<string>
     */
    public function orphanedMusicMediaItems(): array
    {
        $out = [];
        foreach ($this->mediaItems as $row) {
            if (in_array($row['type'], ['artist', 'album'], true) && !$this->isReferenced($row['id'])) {
                $out[] = $row['id'];
            }
        }

        return $out;
    }

    /**
     * Is this `media_items.id` referenced by a `music_artists`/`music_albums` row?
     *
     * @param string $mediaItemId Candidate id.
     * @return bool
     */
    public function isReferenced(string $mediaItemId): bool
    {
        foreach ($this->artists as $artist) {
            if ($artist['media_item_id'] === $mediaItemId) {
                return true;
            }
        }
        foreach ($this->albums as $album) {
            if ($album['media_item_id'] === $mediaItemId) {
                return true;
            }
        }

        return false;
    }
}
