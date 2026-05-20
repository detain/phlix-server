<?php

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Trakt;

use RuntimeException;

/**
 * Raised when an inbound Trakt OAuth callback presents a `state`
 * parameter that does not match any state value previously issued by
 * this server (i.e. a CSRF attempt, a replay of an already-consumed
 * state, or a session that has expired).
 *
 * Callers should respond to inbound HTTP requests with 403 and avoid
 * leaking which of the three conditions triggered the failure.
 *
 * @since 0.16.0
 */
final class InvalidOAuthStateException extends RuntimeException
{
}
