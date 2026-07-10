<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library;

use Phlix\Auth\SignedUrl;
use Phlix\Media\Library\StreamTrackShaper;
use PHPUnit\Framework\TestCase;

/**
 * Tests for StreamTrackShaper — the shared audio_tracks / subtitle_tracks
 * shaper used by BOTH playback-info dispatch paths.
 */
class StreamTrackShaperTest extends TestCase
{
    /**
     * A representative media_streams row set: one video, two audio, one text
     * subtitle, one bitmap subtitle, one more text subtitle (already in
     * stream_index order, as ItemRepository::getItemStreams() returns them).
     *
     * @return list<array<string, mixed>>
     */
    private function streamRows(): array
    {
        return [
            ['id' => 's-v0', 'stream_index' => 0, 'stream_type' => 'video', 'codec' => 'h264', 'language' => null, 'bitrate' => 6000000],
            ['id' => 's-a0', 'stream_index' => 1, 'stream_type' => 'audio', 'codec' => 'aac', 'language' => 'eng', 'bitrate' => 128000],
            ['id' => 's-a1', 'stream_index' => 2, 'stream_type' => 'audio', 'codec' => 'ac3', 'language' => 'fre', 'bitrate' => 384000],
            ['id' => 's-s0', 'stream_index' => 3, 'stream_type' => 'subtitle', 'codec' => 'subrip', 'language' => 'eng', 'bitrate' => null],
            // Bitmap subtitle: consumes a 0:s:N ordinal but must NOT be listed.
            ['id' => 's-s1', 'stream_index' => 4, 'stream_type' => 'subtitle', 'codec' => 'hdmv_pgs_subtitle', 'language' => 'eng', 'bitrate' => null],
            ['id' => 's-s2', 'stream_index' => 5, 'stream_type' => 'subtitle', 'codec' => 'ass', 'language' => 'jpn', 'title' => 'Signs & Songs', 'bitrate' => null],
        ];
    }

    public function testAudioTracksCarryIndexStreamIndexAndDefault(): void
    {
        $tracks = StreamTrackShaper::audioTracks($this->streamRows());

        $this->assertCount(2, $tracks);

        // Track 0: per-type ordinal 0, global stream_index 1, promoted default.
        $this->assertSame('s-a0', $tracks[0]['id']);
        $this->assertSame(0, $tracks[0]['index']);
        $this->assertSame(1, $tracks[0]['stream_index']);
        $this->assertSame('aac', $tracks[0]['codec']);
        $this->assertSame('eng', $tracks[0]['language']);
        $this->assertSame(128000, $tracks[0]['bitrate']);
        $this->assertTrue($tracks[0]['default']);

        // Track 1: ordinal 1, global stream_index 2, not default.
        $this->assertSame('s-a1', $tracks[1]['id']);
        $this->assertSame(1, $tracks[1]['index']);
        $this->assertSame(2, $tracks[1]['stream_index']);
        $this->assertFalse($tracks[1]['default']);

        // Pre-existing P3B-S2 fields are all still present on every track.
        foreach ($tracks as $track) {
            foreach (['id', 'codec', 'language', 'channels', 'bitrate', 'title'] as $key) {
                $this->assertArrayHasKey($key, $track);
            }
        }
    }

    public function testAudioTracksHonorStoredDefaultDisposition(): void
    {
        $rows = $this->streamRows();
        // Mark the SECOND audio stream as the container default.
        $rows[2]['disposition'] = ['default' => 1];

        $tracks = StreamTrackShaper::audioTracks($rows);

        $this->assertFalse($tracks[0]['default'], 'stored disposition must win over first-track promotion');
        $this->assertTrue($tracks[1]['default']);
    }

    public function testAudioTracksEmptyWhenNoAudioStreams(): void
    {
        $this->assertSame([], StreamTrackShaper::audioTracks([
            ['id' => 's-v0', 'stream_index' => 0, 'stream_type' => 'video', 'codec' => 'h264'],
        ]));
    }

    public function testSubtitleTracksSkipBitmapsButKeepFfmpegOrdinalSpace(): void
    {
        $tracks = StreamTrackShaper::subtitleTracks($this->streamRows(), 'ep-1');

        // Only the two TEXT tracks are listed (the PGS bitmap is skipped)…
        $this->assertCount(2, $tracks);
        $this->assertSame('subrip', $tracks[0]['codec']);
        $this->assertSame('ass', $tracks[1]['codec']);

        // …but the ordinal counts the bitmap, matching ffmpeg's 0:s:N selector
        // that the extraction endpoint maps the {index} param to.
        $this->assertSame(0, $tracks[0]['index']);
        $this->assertSame(2, $tracks[1]['index']);

        // Global ffprobe indexes come from the stream_index column.
        $this->assertSame(3, $tracks[0]['stream_index']);
        $this->assertSame(5, $tracks[1]['stream_index']);

        // Label: title wins, else language, and language defaults to 'und'.
        $this->assertSame('eng', $tracks[0]['language']);
        $this->assertSame('eng', $tracks[0]['label']);
        $this->assertSame('Signs & Songs', $tracks[1]['label']);
    }

    public function testSubtitleTrackUrlsAreVerifiableSignedUrls(): void
    {
        $tracks = StreamTrackShaper::subtitleTracks($this->streamRows(), 'ep-1');

        foreach ([0 => 0, 1 => 2] as $trackPos => $ordinal) {
            $url = $tracks[$trackPos]['url'];
            $this->assertIsString($url);
            $path = "/api/v1/media/ep-1/subtitles/{$ordinal}";
            $this->assertStringStartsWith($path . '?', $url);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            /** @var array<string, string> $q */
            $this->assertTrue(
                SignedUrl::fromEnv()->verify($path, (string) ($q['exp'] ?? ''), (string) ($q['sig'] ?? '')),
                "subtitle_tracks[$trackPos].url must be a verifiable signed URL for {$path}",
            );
        }
    }

    public function testSubtitleLabelFallsBackToNumberedPlaceholder(): void
    {
        $tracks = StreamTrackShaper::subtitleTracks([
            ['id' => 's-s0', 'stream_index' => 2, 'stream_type' => 'subtitle', 'codec' => 'mov_text', 'language' => null],
        ], 'ep-1');

        $this->assertCount(1, $tracks);
        $this->assertSame('und', $tracks[0]['language']);
        $this->assertSame('Subtitle 1', $tracks[0]['label']);
    }

    public function testSubtitleTracksEmptyItemIdYieldsNullUrl(): void
    {
        $tracks = StreamTrackShaper::subtitleTracks([
            ['id' => 's-s0', 'stream_index' => 2, 'stream_type' => 'subtitle', 'codec' => 'subrip', 'language' => 'eng'],
        ], '');

        $this->assertNull($tracks[0]['url']);
    }

    public function testTracksAreOrderedByStreamIndexEvenWhenRowsArriveUnordered(): void
    {
        $rows = array_reverse($this->streamRows());

        $audio = StreamTrackShaper::audioTracks($rows);
        $this->assertSame(['s-a0', 's-a1'], array_column($audio, 'id'));

        $subs = StreamTrackShaper::subtitleTracks($rows, 'ep-1');
        $this->assertSame([0, 2], array_column($subs, 'index'));
    }
}
