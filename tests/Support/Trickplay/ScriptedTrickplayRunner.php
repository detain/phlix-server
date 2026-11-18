<?php

declare(strict_types=1);

namespace Phlix\Tests\Support\Trickplay;

use Phlix\Media\Transcoding\FfmpegRunner;

/**
 * Runner whose probe and process launch are scripted. The launch seam writes the
 * artefacts the command names, the way ffmpeg would.
 */
final class ScriptedTrickplayRunner extends FfmpegRunner
{
    private const JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9";

    /** @var list<string> */
    public array $commands = [];

    public bool $writeSprite = true;

    public bool $probeFails = false;

    /** How many BIF frames the scripted ffmpeg writes. */
    public int $bifFramesToWrite = 600;

    /** @var list<int> BIF frame indexes to leave unwritten (a hole in the sequence). */
    public array $skipBifIndexes = [];

    /** Exit code the scripted ffmpeg reports. */
    public int $exitCodeToReturn = 0;

    /** Text ffmpeg writes to its captured output, or null for none. */
    public ?string $ffmpegMessage = null;

    /** Slots the scripted sampler lists in the framemd5 file; -1 writes no file. */
    public int $spriteSlotsToWrite = 60;

    /** Whether the launch throws after writing its outputs. */
    public bool $throwOnRun = false;

    /**
     * Source path whose rename fails, or null for none. Only renames FROM this path fail, so
     * the rollback renames that restore a previous copy still succeed.
     */
    public ?string $failRenameFrom = null;

    protected function renameTrickplayPath(string $from, string $to): bool
    {
        if ($this->failRenameFrom !== null && $from === $this->failRenameFrom) {
            return false;
        }

        return parent::renameTrickplayPath($from, $to);
    }

    /** Path to replace with a symlink to /dev/full during the run, or null for none. */
    public ?string $devFullLinkAt = null;

    public function __construct(string $ffmpeg, string $ffprobe, string $transcodeDir, private float $duration)
    {
        parent::__construct($ffmpeg, $ffprobe, $transcodeDir);
    }

    /**
     * @return array{streams: array<int, array<string, mixed>>, format: array<string, mixed>}|null
     */
    public function probe(string $inputPath): ?array
    {
        if ($this->probeFails) {
            return null;
        }

        return [
            'streams' => [],
            'format' => ['duration' => (string) $this->duration],
        ];
    }

    protected function runTrickplayCommand(string $cmd, array &$output, int &$exitCode): void
    {
        $this->commands[] = $cmd;
        $output = $this->ffmpegMessage === null ? [] : [$this->ffmpegMessage];
        $exitCode = $this->exitCodeToReturn;

        if ($this->devFullLinkAt !== null) {
            @unlink($this->devFullLinkAt);
            symlink('/dev/full', $this->devFullLinkAt);
        }

        // The sprite is written under its temporary name and renamed afterwards.
        if ($this->writeSprite && preg_match("#'([^']*/\\.tmp-sprite\\.jpg)'#", $cmd, $m) === 1) {
            file_put_contents($m[1], self::JPEG);
        }

        if (preg_match("#'([^']*/bifframe_%05d\\.jpg)'#", $cmd, $m) === 1) {
            for ($i = 1; $i <= $this->bifFramesToWrite; $i++) {
                if (in_array($i, $this->skipBifIndexes, true)) {
                    continue;
                }
                file_put_contents(sprintf($m[1], $i), self::JPEG);
            }
        }

        // The slot counter: one framemd5 line per sampled slot, after the header lines.
        if ($this->spriteSlotsToWrite >= 0 && preg_match("#-f framemd5 '([^']*)'#", $cmd, $m) === 1) {
            $lines = ['#format: frame checksums', '#version: 2'];
            for ($i = 0; $i < $this->spriteSlotsToWrite; $i++) {
                $lines[] = $i . ', ' . $i . ', ' . $i . ', 1, 1, 0x00000000';
            }
            file_put_contents($m[1], implode("\n", $lines) . "\n");
        }

        if ($this->throwOnRun) {
            throw new \RuntimeException('launch failed');
        }
    }
}
