<?php

/**
 * Phlix media server test double: Media\Music.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

// The PHP stream-wrapper API dictates every method name below (`stream_open`,
// `stream_read`, `url_stat`, …). They CANNOT be camelCased — PHP looks them up by those
// exact names — so the naming sniff is switched off for this file only, with the reason
// on the line above rather than left as permanent phpcs noise.
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

namespace Phlix\Tests\Unit\Media\Music;

/**
 * Counts the bytes, reads and seeks a consumer performs on a file.
 *
 * A stream wrapper is the only way to measure getID3's read pattern from inside PHP,
 * and it is what makes every figure in {@see MusicLibraryScanner::getId3Reader()}'s
 * docblock reproducible in CI rather than a one-off `strace` nobody can re-run.
 *
 * @internal
 */
final class CountingStreamWrapper
{
    public const SCHEME = 'phlixcount';

    /** Bytes returned by `stream_read()`. */
    public static int $bytes = 0;

    /** Calls to `stream_read()`. */
    public static int $reads = 0;

    /** Calls to `stream_seek()`. */
    public static int $seeks = 0;

    /** @var resource Underlying handle. */
    private $handle;

    /** @var resource|null Set by PHP for stream contexts. */
    public $context;

    /**
     * Zeroes every counter.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$bytes = 0;
        self::$reads = 0;
        self::$seeks = 0;
    }

    /**
     * @param string $path Wrapped path (`phlixcount://<real path>`).
     * @param string $mode fopen mode.
     * @param int $options Wrapper options.
     * @param string|null $openedPath Out-param.
     * @return bool
     */
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        unset($options, $openedPath);
        $handle = @fopen(self::realPath($path), $mode);
        if (!is_resource($handle)) {
            return false;
        }
        $this->handle = $handle;

        return true;
    }

    /**
     * @param int $count Bytes requested.
     * @return string|false
     */
    public function stream_read(int $count): string|false
    {
        $data = fread($this->handle, $count);
        if (is_string($data)) {
            self::$bytes += strlen($data);
            self::$reads++;
        }

        return $data;
    }

    public function stream_tell(): int
    {
        return (int) ftell($this->handle);
    }

    public function stream_eof(): bool
    {
        return feof($this->handle);
    }

    /**
     * @param int $offset Byte offset.
     * @param int $whence SEEK_* constant.
     * @return bool
     */
    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        self::$seeks++;

        return fseek($this->handle, $offset, $whence) === 0;
    }

    /** @return array<int|string, int>|false */
    public function stream_stat(): array|false
    {
        return fstat($this->handle);
    }

    public function stream_close(): void
    {
        fclose($this->handle);
    }

    /**
     * @param string $path Wrapped path.
     * @param int $flags Wrapper flags.
     * @return array<int|string, int>|false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        unset($flags);

        return @stat(self::realPath($path));
    }

    /**
     * Strips the scheme prefix.
     */
    private static function realPath(string $path): string
    {
        return substr($path, strlen(self::SCHEME . '://'));
    }
}
