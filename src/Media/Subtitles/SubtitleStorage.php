<?php

/**
 * Phlix media server component: Subtitles.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Subtitles;

use Phlix\Shared\Subtitle\SubtitleFile;
use RuntimeException;

/**
 * On-disk storage for DOWNLOADED external subtitles (Wave 3 / F3).
 *
 * Mirrors {@see \Phlix\Media\Storage\ArtworkStorage}'s per-item sub-directory
 * layout: each media item gets a directory under the configurable base path
 * (`subtitles.storage_path`, default `/var/subtitles`, operator-overridable via
 * `SUBTITLE_STORAGE_PATH`), holding one `.vtt` file per stored subtitle keyed by
 * language (+ a `.hi` marker for hearing-impaired variants):
 *
 *     <base>/<itemId>/<lang>[.hi].vtt
 *
 * The downloaded content is normalised to WebVTT on store
 * ({@see WebVttConverter}) so the serving endpoint is a trivial static read and
 * the player consumes it through the exact same `text/vtt` `<track>` contract
 * as the embedded-track extractor.
 *
 * The base dir is INJECTED (never hard-wired) so tests point it at a temp
 * directory and never touch `/var`.
 *
 * @package Phlix\Media\Subtitles
 * @since 0.43.0
 */
final class SubtitleStorage
{
    /**
     * Absolute base directory, normalised WITHOUT a trailing slash.
     */
    private readonly string $baseDir;

    /**
     * @param string $baseDir Root directory for downloaded subtitle files.
     *        Defaults to `/var/subtitles`; injected as a temp dir in tests.
     */
    public function __construct(string $baseDir = '/var/subtitles')
    {
        $this->baseDir = rtrim($baseDir, '/');
    }

    /**
     * The configured base directory (no trailing slash).
     */
    public function baseDir(): string
    {
        return $this->baseDir;
    }

    /**
     * Persist a downloaded subtitle for an item, returning its absolute path.
     *
     * The content is converted to WebVTT and written to
     * `<base>/<itemId>/<lang>[.hi].vtt`. Re-storing the same (item, language,
     * hearing-impaired) triple overwrites in place — a later download for the
     * same track replaces rather than duplicates.
     *
     * @param string       $itemId Media item UUID (a path component — sanitised).
     * @param SubtitleFile  $file   The downloaded subtitle.
     *
     * @return string Absolute path to the written `.vtt` file.
     *
     * @throws RuntimeException When the item directory cannot be created or the
     *         file cannot be written.
     *
     * @since 0.43.0
     */
    public function store(string $itemId, SubtitleFile $file): string
    {
        $dir = $this->itemDir($itemId);
        if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new RuntimeException(
                sprintf('Failed to create subtitle storage directory: %s', $dir),
            );
        }

        $path = $dir . DIRECTORY_SEPARATOR
            . $this->fileName($file->language, $file->hearingImpaired);

        $vtt = WebVttConverter::toWebVtt($file->content, $file->format);
        if (@file_put_contents($path, $vtt) === false) {
            throw new RuntimeException(
                sprintf('Failed to write subtitle file: %s', $path),
            );
        }

        return $path;
    }

    /**
     * Read a stored subtitle file's WebVTT content, or null when absent.
     *
     * SECURITY (path traversal): the resolved real path MUST live under the
     * configured base directory, otherwise the read is refused (returns null) —
     * so a crafted `storage_path` value can never escape the subtitle root.
     *
     * @param string $path Absolute path previously returned by {@see store()}.
     *
     * @return string|null The file contents, or null when missing / out of jail.
     *
     * @since 0.43.0
     */
    public function read(string $path): ?string
    {
        if ($path === '') {
            return null;
        }

        $real = realpath($path);
        if ($real === false) {
            return null;
        }

        $baseReal = realpath($this->baseDir);
        if ($baseReal === false || !str_starts_with($real, $baseReal . DIRECTORY_SEPARATOR)) {
            return null;
        }

        $content = @file_get_contents($real);

        return $content === false ? null : $content;
    }

    /**
     * The absolute per-item storage directory (sanitised item id).
     *
     * @param string $itemId Media item UUID.
     */
    public function itemDir(string $itemId): string
    {
        return $this->baseDir . DIRECTORY_SEPARATOR . $this->safeComponent($itemId, 'item');
    }

    /**
     * The `<lang>[.hi].vtt` file name for a language + hearing-impaired flag.
     *
     * @param string $language        ISO 639 language code.
     * @param bool   $hearingImpaired Whether this is an SDH/HI subtitle.
     */
    public function fileName(string $language, bool $hearingImpaired): string
    {
        $lang = $this->safeComponent($language, 'und');

        return $lang . ($hearingImpaired ? '.hi' : '') . '.vtt';
    }

    /**
     * Reduce a caller-supplied string to a safe single path component.
     *
     * Strips directory separators and anything outside `[A-Za-z0-9._-]`, so an
     * item id / language code can never introduce a traversal segment. Falls
     * back to `$fallback` when nothing usable remains.
     *
     * @param string $value    Raw value.
     * @param string $fallback Component to use when $value sanitises to empty.
     */
    private function safeComponent(string $value, string $fallback): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '', basename($value));

        return is_string($clean) && $clean !== '' ? $clean : $fallback;
    }
}
