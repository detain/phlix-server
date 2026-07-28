<?php

declare(strict_types=1);

namespace Phlix\Tests\Support\Database;

use RuntimeException;

/**
 * S126 — raised when a listener ACCEPTED a TCP connection on the configured
 * MySQL host/port but no query could actually be run over it.
 *
 * A distinct class so the outcome is greppable in a CI log and cannot be
 * confused with an unrelated {@see RuntimeException} thrown from production
 * code under test. See {@see IntegrationDbGuard} for why this is raised rather
 * than skipped.
 */
final class IntegrationDbUnusableException extends RuntimeException
{
}
