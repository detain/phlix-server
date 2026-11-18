<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers;

use Phlix\Server\Http\Controllers\TranscodeFileServer;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * #13c — cache policy of the HLS/DASH file server.
 *
 * - Segments are access-controlled bytes: `private`, never `public`, so a shared cache
 *   cannot store them. The year-long immutability is unchanged.
 * - A FINISHED VOD playlist (closing #EXT-X-ENDLIST) gets `private, max-age=60` plus an
 *   ETag, and answers a matching If-None-Match with 304.
 * - A live or in-progress playlist keeps `no-cache` and never gets an ETag or a 304.
 */
final class TranscodeFileServerCacheTest extends TestCase
{
    private const SEG_BYTES = 'SEGMENTDATA';

    private const LIVE_PLAYLIST = "#EXTM3U\n#EXT-X-TARGETDURATION:6\n#EXTINF:6.0,\nseg-1.m4s\n";

    private const VOD_PLAYLIST = "#EXTM3U\n#EXT-X-PLAYLIST-TYPE:VOD\n#EXTINF:6.0,\nseg-1.m4s\n#EXT-X-ENDLIST\n";

    private string $jobDir = '';

    protected function setUp(): void
    {
        $this->jobDir = sys_get_temp_dir() . '/phlix_13c_' . bin2hex(random_bytes(6));
        mkdir($this->jobDir, 0o755, true);
        file_put_contents($this->jobDir . '/seg-1.m4s', self::SEG_BYTES);
        file_put_contents($this->jobDir . '/live.m3u8', self::LIVE_PLAYLIST);
        file_put_contents($this->jobDir . '/vod.m3u8', self::VOD_PLAYLIST);
    }

    protected function tearDown(): void
    {
        if ($this->jobDir !== '' && is_dir($this->jobDir)) {
            foreach (glob($this->jobDir . '/*') ?: [] as $entry) {
                @unlink($entry);
            }
            @rmdir($this->jobDir);
        }
    }

    /** @param array<string, string> $headers */
    private function serve(string $file, array $headers = []): Response
    {
        $request = new Request();
        $request->userId = 'u-13c';
        foreach ($headers as $name => $value) {
            $request->headers[$name] = $value;
        }

        // TranscodeFileServer is a trait mixed into the HLS/DASH controllers; the
        // anonymous host runs the exact shipped serveJobFile() code path.
        $host = new class () {
            use TranscodeFileServer;
        };
        $method = new ReflectionMethod($host, 'serveJobFile');

        return $method->invoke($host, $request, $this->jobDir, $file);
    }

    public function test_a_segment_is_private_and_still_immutable(): void
    {
        $resp = $this->serve('seg-1.m4s');

        self::assertSame(200, $resp->statusCode);
        self::assertSame('private, max-age=31536000', $resp->headers['Cache-Control']);
        self::assertArrayNotHasKey('ETag', $resp->headers);
    }

    public function test_a_live_playlist_stays_uncached_with_no_validator_or_304(): void
    {
        $resp = $this->serve('live.m3u8');

        self::assertSame(200, $resp->statusCode);
        self::assertSame('no-cache', $resp->headers['Cache-Control']);
        self::assertArrayNotHasKey('ETag', $resp->headers);

        // Even a matching-looking validator must not short-circuit a live playlist.
        $revalidated = $this->serve('live.m3u8', ['If-None-Match' => '*']);
        self::assertSame(200, $revalidated->statusCode);
    }

    public function test_a_finished_vod_playlist_gets_a_private_max_age_and_an_etag(): void
    {
        $resp = $this->serve('vod.m3u8');

        self::assertSame(200, $resp->statusCode);
        self::assertSame('private, max-age=60', $resp->headers['Cache-Control']);
        self::assertArrayHasKey('ETag', $resp->headers);
        self::assertMatchesRegularExpression('/^"[0-9a-f]{32}"$/', $resp->headers['ETag']);
        self::assertSame($this->jobDir . '/vod.m3u8', $resp->filePath);
    }

    public function test_a_matching_if_none_match_answers_304_with_no_body(): void
    {
        $etag = $this->serve('vod.m3u8')->headers['ETag'];

        $resp = $this->serve('vod.m3u8', ['If-None-Match' => $etag]);

        self::assertSame(304, $resp->statusCode);
        self::assertSame($etag, $resp->headers['ETag']);
        self::assertSame('private, max-age=60', $resp->headers['Cache-Control']);
        self::assertNull($resp->filePath, 'a 304 must not stream the playlist');
    }

    public function test_a_weak_or_list_or_wildcard_if_none_match_also_matches(): void
    {
        $etag = $this->serve('vod.m3u8')->headers['ETag'];

        self::assertSame(304, $this->serve('vod.m3u8', ['If-None-Match' => 'W/' . $etag])->statusCode);
        self::assertSame(304, $this->serve('vod.m3u8', ['If-None-Match' => '"other", ' . $etag])->statusCode);
        self::assertSame(304, $this->serve('vod.m3u8', ['If-None-Match' => '*'])->statusCode);
    }

    public function test_a_stale_etag_gets_the_whole_playlist_back(): void
    {
        $resp = $this->serve('vod.m3u8', ['If-None-Match' => '"0000000000000000000000000000dead"']);

        self::assertSame(200, $resp->statusCode);
        self::assertNotNull($resp->filePath);
    }

    public function test_if_none_match_takes_precedence_over_if_modified_since(): void
    {
        // RFC 7232 §3.3: an If-None-Match that does not match decides alone. A matching
        // date must not turn a changed playlist into a 304.
        $mtime = filemtime($this->jobDir . '/vod.m3u8');
        self::assertIsInt($mtime);
        $sameDate = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';

        $resp = $this->serve('vod.m3u8', [
            'If-None-Match' => '"0000000000000000000000000000dead"',
            'If-Modified-Since' => $sameDate,
        ]);

        self::assertSame(200, $resp->statusCode);
    }

    public function test_a_rewritten_playlist_gets_a_new_etag_so_the_old_one_no_longer_matches(): void
    {
        $oldEtag = $this->serve('vod.m3u8')->headers['ETag'];

        // A finished playlist with one more entry: different size, so a different validator.
        file_put_contents(
            $this->jobDir . '/vod.m3u8',
            self::VOD_PLAYLIST . "#EXTINF:6.0,\nseg-2.m4s\n",
        );

        $resp = $this->serve('vod.m3u8', ['If-None-Match' => $oldEtag]);

        self::assertSame(200, $resp->statusCode);
        self::assertNotSame($oldEtag, $resp->headers['ETag']);
    }

    public function test_a_segment_range_request_is_still_a_private_206(): void
    {
        $resp = $this->serve('seg-1.m4s', ['Range' => 'bytes=0-3']);

        self::assertSame(206, $resp->statusCode);
        self::assertSame('private, max-age=31536000', $resp->headers['Cache-Control']);
    }

    public function test_a_finished_playlist_is_not_revalidated_by_if_modified_since(): void
    {
        // Last-Modified has one-second resolution. A client holding the in-progress
        // playlist sends IMS with the second ENDLIST was appended in; that must reach
        // the 200 + ETag path, not a 304 that keeps the unfinished copy.
        $vod = $this->serve('vod.m3u8');
        self::assertSame(200, $vod->statusCode);

        $mtime = filemtime($this->jobDir . '/vod.m3u8');
        self::assertIsInt($mtime);
        $sameSecond = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';

        $resp = $this->serve('vod.m3u8', ['If-Modified-Since' => $sameSecond]);

        self::assertSame(200, $resp->statusCode, 'a playlist is validated by its ETag, never by the date');
        self::assertSame($vod->headers['ETag'], $resp->headers['ETag']);
        self::assertNotNull($resp->filePath);
    }

    public function test_an_atomic_same_size_rewrite_changes_the_etag(): void
    {
        // mtime and ctime are whole seconds, so a same-size rewrite inside one second
        // used to keep the ETag. An atomic replace gets a new inode, and the inode is
        // in the validator now. The replacement is the same length (seg-1 -> seg-2).
        $oldEtag = $this->serve('vod.m3u8')->headers['ETag'];

        $replacement = str_replace('seg-1.m4s', 'seg-2.m4s', self::VOD_PLAYLIST);
        self::assertSame(strlen(self::VOD_PLAYLIST), strlen($replacement));
        $tmp = $this->jobDir . '/vod.tmp';
        file_put_contents($tmp, $replacement);
        self::assertTrue(rename($tmp, $this->jobDir . '/vod.m3u8'));

        $resp = $this->serve('vod.m3u8', ['If-None-Match' => $oldEtag]);

        self::assertSame(200, $resp->statusCode, 'the old validator must not match the replaced playlist');
        self::assertNotSame($oldEtag, $resp->headers['ETag']);
    }

    public function test_an_in_place_same_size_rewrite_changes_the_etag(): void
    {
        // The HLS muxer and TranscodeManager write playlists in place, with no rename. The
        // inode, size, mtime and ctime can all stay the same inside one second, so a
        // stat-based validator cannot see this rewrite. The ETag is a digest of the bytes.
        $path = $this->jobDir . '/vod.m3u8';
        $oldEtag = $this->serve('vod.m3u8')->headers['ETag'];

        clearstatcache(true, $path);
        $before = stat($path);
        self::assertIsArray($before);

        $replacement = str_replace('seg-1.m4s', 'seg-2.m4s', self::VOD_PLAYLIST);
        self::assertSame(strlen(self::VOD_PLAYLIST), strlen($replacement));
        file_put_contents($path, $replacement);

        clearstatcache(true, $path);
        $after = stat($path);
        self::assertIsArray($after);
        self::assertSame($before['ino'], $after['ino'], 'the rewrite must be in place, not a new inode');
        self::assertSame($before['size'], $after['size'], 'the rewrite must keep the byte length');

        $resp = $this->serve('vod.m3u8', ['If-None-Match' => $oldEtag]);

        self::assertSame(200, $resp->statusCode, 'the old validator must not match the in-place rewrite');
        self::assertNotSame($oldEtag, $resp->headers['ETag']);
    }

    public function test_an_oversize_finished_playlist_falls_back_to_a_stat_etag_and_still_revalidates(): void
    {
        // Over the 1 MiB digest cap the whole file is not read, so the validator is the
        // stat-based one. A finished playlist is still served with an ETag and still 304s.
        $big = "#EXTM3U\n#EXT-X-PLAYLIST-TYPE:VOD\n"
            . str_repeat("#EXTINF:6.0,\nseg.m4s\n", 60000)
            . "#EXT-X-ENDLIST\n";
        self::assertGreaterThan(1048576, strlen($big));
        file_put_contents($this->jobDir . '/big.m3u8', $big);

        $resp = $this->serve('big.m3u8');

        self::assertSame(200, $resp->statusCode);
        self::assertSame('private, max-age=60', $resp->headers['Cache-Control']);
        self::assertMatchesRegularExpression('/^"[0-9a-f]{32}"$/', $resp->headers['ETag']);

        $revalidated = $this->serve('big.m3u8', ['If-None-Match' => $resp->headers['ETag']]);
        self::assertSame(304, $revalidated->statusCode);
    }

    public function test_a_segment_still_answers_304_to_a_matching_if_modified_since(): void
    {
        // The date check is kept for immutable segments: only playlists were carved out.
        $mtime = filemtime($this->jobDir . '/seg-1.m4s');
        self::assertIsInt($mtime);

        $resp = $this->serve('seg-1.m4s', ['If-Modified-Since' => gmdate('D, d M Y H:i:s', $mtime) . ' GMT']);

        self::assertSame(304, $resp->statusCode);
        self::assertNull($resp->filePath);
    }

    public function test_if_range_with_the_current_etag_honours_the_range(): void
    {
        $etag = $this->serve('vod.m3u8')->headers['ETag'];

        $resp = $this->serve('vod.m3u8', ['Range' => 'bytes=0-3', 'If-Range' => $etag]);

        self::assertSame(206, $resp->statusCode);
        self::assertSame($this->jobDir . '/vod.m3u8', $resp->filePath);
    }

    public function test_if_range_with_a_stale_etag_returns_the_whole_playlist(): void
    {
        $resp = $this->serve('vod.m3u8', [
            'Range' => 'bytes=0-3',
            'If-Range' => '"0000000000000000000000000000dead"',
        ]);

        self::assertSame(200, $resp->statusCode, 'a stale If-Range ignores the Range entirely');
        self::assertSame($this->jobDir . '/vod.m3u8', $resp->filePath);
    }

    public function test_a_weak_if_range_etag_never_matches(): void
    {
        // RFC 7233 §3.2: If-Range uses the strong comparison, so a weak tag is stale.
        $etag = $this->serve('vod.m3u8')->headers['ETag'];

        $resp = $this->serve('vod.m3u8', ['Range' => 'bytes=0-3', 'If-Range' => 'W/' . $etag]);

        self::assertSame(200, $resp->statusCode);
    }

    public function test_an_if_range_etag_on_a_file_with_no_etag_is_stale(): void
    {
        // A live playlist has no validator, so no entity-tag If-Range can match it.
        $resp = $this->serve('live.m3u8', ['Range' => 'bytes=0-3', 'If-Range' => '"anything"']);

        self::assertSame(200, $resp->statusCode);
    }
}
