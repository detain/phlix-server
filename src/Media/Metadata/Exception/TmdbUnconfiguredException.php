<?php

declare(strict_types=1);

namespace Phlix\Media\Metadata\Exception;

use RuntimeException;

/**
 * Thrown by the interactive per-item match API when no usable TMDB provider is
 * available (e.g. no `tmdb.api_key` configured) so the caller cannot search or
 * apply a metadata match.
 *
 * The controller maps this to HTTP `422 Unprocessable Entity` with the stable
 * error code `metadata.tmdb_unconfigured`, so the UI can surface a clear
 * "configure a TMDB API key" message rather than a generic failure.
 *
 * @package Phlix\Media\Metadata\Exception
 * @since   0.25.0
 */
final class TmdbUnconfiguredException extends RuntimeException
{
    /** Stable machine-readable error code surfaced to API clients. */
    public const ERROR_CODE = 'metadata.tmdb_unconfigured';

    public function __construct(string $message = 'TMDB is not configured (missing API key).')
    {
        parent::__construct($message);
    }
}
