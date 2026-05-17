<?php

declare(strict_types=1);

namespace Phlex\Plugins\Exception;

use RuntimeException;

/**
 * Thrown by {@see \Phlex\Plugins\Manifest::fromJson()} when the raw
 * input cannot be parsed at all — i.e. the payload is not valid JSON or
 * its root is not a JSON object.
 *
 * Schema-level problems (missing required fields, unknown plugin type,
 * bad semver, …) are NOT exceptions: they are returned as
 * {@see \Phlex\Plugins\ManifestValidationError} instances by
 * {@see \Phlex\Plugins\Manifest::validate()} so callers can present all
 * issues at once.
 *
 * @package Phlex\Plugins\Exception
 * @since 0.10.0
 */
final class InvalidManifestException extends RuntimeException
{
}
