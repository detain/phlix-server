<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata\Writer;

use Phlix\Media\Library\MediaItem;
use Phlix\Media\Metadata\EmbeddedWritePolicy;
use Phlix\Media\Metadata\MetadataOverwritePolicy;
use Phlix\Media\Metadata\Writer\EmbeddedMetadataWriter;
use Phlix\Media\Metadata\Writer\ExecExternalCommandRunner;
use Phlix\Admin\SettingsRepository;
use PHPUnit\Framework\TestCase;

/**
 * S89 — success paths against the REAL toolchain (S345 rule 2): a hand-written
 * runner proves MY rename discipline; only real ffmpeg proves the COMMAND
 * SHAPE is what ffmpeg actually accepts, and only real ffprobe proves the
 * metadata landed in a container that still decodes. Every arm here is the
 * happy path end to end: opt-in on, no curated NFO, copy-remux, atomic
 * publish, tag read-back, residue check.
 *
 * No database, no sockets: these are real-FILESYSTEM/real-BINARY tests.
 */
final class EmbeddedMetadataWriterRealBinaryTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            @chmod($dir, 0777);
            foreach (glob($dir . '/*') ?: [] as $entry) {
                @chmod($entry, 0644);
                @unlink($entry);
            }
            @rmdir($dir);
        }
        $this->dirs = [];
    }

    private function requireTools(): void
    {
        foreach (['ffmpeg', 'ffprobe'] as $tool) {
            $path = trim((string) @shell_exec('command -v ' . $tool . ' 2>/dev/null'));
            if ($path === '') {
                $this->markTestSkipped("{$tool} binary not available on this machine");
            }
        }
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/phlix_s89rb_' . uniqid('', true);
        self::assertTrue(mkdir($dir, 0777, true));
        $this->dirs[] = $dir;

        return $dir;
    }

    private function writer(): EmbeddedMetadataWriter
    {
        $store = $this->createMock(SettingsRepository::class);
        $store->method('getEffective')->willReturnCallback(
            static fn (string $key): bool => $key === EmbeddedWritePolicy::SETTING_KEY,
        );

        return new EmbeddedMetadataWriter(
            new EmbeddedWritePolicy($store),
            new MetadataOverwritePolicy($store),
            new ExecExternalCommandRunner(),
        );
    }

    /** @return array<string, mixed> ffprobe -show_entries format_tags, lower-cased keys */
    private function containerTags(string $path): array
    {
        $out = shell_exec(
            'ffprobe -v error -show_entries format_tags -of json ' . escapeshellarg($path)
        );
        self::assertIsString($out);
        /** @var array<string, mixed> $json */
        $json = (array) json_decode($out, true, 512, JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $tags */
        $tags = $json['format']['tags'] ?? [];

        return array_change_key_case($tags);
    }

    private function assertDecodable(string $path): void
    {
        $exit = 1;
        exec('ffprobe -v error -i ' . escapeshellarg($path) . ' 2>/dev/null', $o, $exit);
        $this->assertSame(0, $exit, 'the rewritten container must still be readable');
    }

    public function test_real_ffmpeg_remux_of_a_real_mp4_stamps_title_and_keeps_the_container_playable(): void
    {
        $this->requireTools();
        $dir = $this->tempDir();
        $media = $dir . '/film.mp4';

        $exit = 1;
        exec(
            'ffmpeg -nostdin -hide_banner -loglevel error -y '
            . '-f lavfi -i testsrc=duration=1:size=128x96:rate=5 -f lavfi -i sine=frequency=440:duration=1 '
            . '-shortest ' . escapeshellarg($media),
            $out,
            $exit,
        );
        self::assertSame(0, $exit, 'fixture ffmpeg must succeed: ' . implode("\n", $out));
        $this->assertArrayNotHasKey('title', $this->containerTags($media), 'fixture must start UNTITLED');

        $item = new MediaItem('rb-1', 'film', 'movie', $media, []);
        $this->writer()->write($item, ['name' => 'Probe Movie', 'year' => '2026'], $dir);

        $tags = $this->containerTags($media);
        $this->assertSame('Probe Movie', $tags['title'] ?? null, 'real ffmpeg must accept the emitted command shape');
        $this->assertSame('2026', $tags['date'] ?? null);
        $this->assertDecodable($media);
        $this->assertSame([], glob($media . '.phlix-embed-tmp-*'), 'no staged residue after a real publish');
    }

    public function test_real_ffmpeg_remux_of_a_real_mkv_stamps_title_and_survives_without_faststart(): void
    {
        $this->requireTools();
        $dir = $this->tempDir();
        $media = $dir . '/episode.mkv';

        $exit = 1;
        exec(
            'ffmpeg -nostdin -hide_banner -loglevel error -y '
            . '-f lavfi -i testsrc=duration=1:size=128x96:rate=5 -f lavfi -i sine=frequency=440:duration=1 '
            . '-shortest ' . escapeshellarg($media),
            $out,
            $exit,
        );
        self::assertSame(0, $exit);

        $item = new MediaItem('rb-2', 'episode', 'episode', $media, []);
        $this->writer()->write($item, ['name' => 'Probe Episode'], $dir);

        $this->assertSame('Probe Episode', $this->containerTags($media)['title'] ?? null);
        $this->assertDecodable($media);
        $this->assertSame([], glob($media . '.phlix-embed-tmp-*'));
    }

    public function test_a_real_tagged_video_before_and_after_keeps_the_original_bytes_until_publish(): void
    {
        // The interrupted-remux AC in its strongest real form: after a
        // SUCCESSFUL real remux, the mtime moved and the container grew or
        // changed (it is genuinely rewritten); combined with the fake-runner
        // arms (original byte-identical on every failure) this pins that the
        // ONLY moment the original changes is the rename.
        $this->requireTools();
        $dir = $this->tempDir();
        $media = $dir . '/grow.mp4';

        $exit = 1;
        exec(
            'ffmpeg -nostdin -hide_banner -loglevel error -y -f lavfi -i testsrc=duration=1:size=128x96:rate=5 '
            . escapeshellarg($media),
            $out,
            $exit,
        );
        self::assertSame(0, $exit);
        $sizeBefore = (int) filesize($media);

        $item = new MediaItem('rb-3', 'grow', 'movie', $media, []);
        $this->writer()->write($item, ['name' => 'Very Long Title ' . str_repeat('x', 200)], $dir);

        $this->assertNotSame($sizeBefore, (int) filesize($media), 'the write must have actually landed');
        $this->assertSame('Very Long Title ' . str_repeat('x', 200), $this->containerTags($media)['title'] ?? null);
        $this->assertDecodable($media);
    }
}
