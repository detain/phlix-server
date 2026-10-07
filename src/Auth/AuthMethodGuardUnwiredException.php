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
 * The serialized auth-toggle write protocol was entered with NO policy instance.
 *
 * Raised by {@see AuthMethodPolicy::guardAndPersistThrough()} when the caller's
 * guard is the nullable, hand-built default — the state
 * {@see AuthMethodPolicyWiringGuardTest} keeps unreachable in the container.
 * An unwired guard must never degrade into an unguarded toggle write, so the
 * protocol still took the transaction and the row locks (a caller cannot get
 * a serialized write it did not ask for, and the B2 statement law for this
 * path — BEGIN → LOCK → ROLLBACK — stays pinned) and then failed closed
 * WITHOUT persisting anything.
 *
 * The HTTP surfaces translate this to a 500 whose `error` names the missing
 * service; it is a deployment/wiring defect, never a user input error.
 *
 * @package Phlix\Auth
 * @since 1.4.0 (provider-parity close of the B2 serialization protocol)
 */
final class AuthMethodGuardUnwiredException extends \RuntimeException
{
}
