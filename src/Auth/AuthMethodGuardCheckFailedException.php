<?php

/**
 * Phlix media server component: Auth.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Auth;

/**
 * The guard PROBE itself blew up mid-check inside the serialized protocol.
 *
 * Raised by {@see AuthMethodPolicy::guardAndPersistThrough()} when anything
 * other than an {@see AuthMethodLockoutException} escapes the locked re-read
 * + {@see AuthMethodPolicy::assertSafeTransition()} window — an unreadable
 * settings store, a vanished users table mid-admin-probe, a malformed
 * proposed map. The transaction is already rolled back when this surfaces,
 * so nothing was persisted; the wrapper carries the ORIGINAL message verbatim
 * (and keeps the previous exception for logs) so both HTTP surfaces keep
 * answering their byte-identical fail-closed 500 envelope
 * (`error: 'Auth-method policy check failed'`).
 *
 * The distinction from a plain rethrow is load-bearing: BEGIN/lock/persist/
 * COMMIT failures propagate UNWRAPPED so the surfaces keep classifying them
 * as storage errors (the admin PUT's 500 'Failed to update settings' path,
 * the provider route's dispatcher 500), while any failure INSIDE the check
 * window is unambiguously the guard refusing to certify the write.
 *
 * @package Phlix\Auth
 * @since 1.4.0 (provider-parity close of the B2 serialization protocol)
 */
final class AuthMethodGuardCheckFailedException extends \RuntimeException
{
}
