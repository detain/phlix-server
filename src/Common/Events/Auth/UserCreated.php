<?php

declare(strict_types=1);

namespace Phlex\Common\Events\Auth;

use Phlex\Common\Events\AbstractEvent;

/**
 * Fired immediately after a new user account is created.
 *
 * Fired by: `\Phlex\Auth\AuthManager::register()` after the user row is
 * persisted and before the response JWT is generated.
 * Typical listener: welcome-email sender, audit-log writer, default-
 * library-permissions bootstrap, hub-side "user came from server X"
 * mirror (Phase C+).
 *
 * Manifest alias (Phase A.4 loader): `phlex.user.created`.
 *
 * @package Phlex\Common\Events\Auth
 * @since 0.10.0
 */
final class UserCreated extends AbstractEvent
{
    /**
     * @param string $userId   UUID of the freshly-created user row.
     * @param string $username Chosen username (3-50 chars per AuthManager).
     * @param string $email    Validated email address on the account.
     */
    public function __construct(
        public readonly string $userId,
        public readonly string $username,
        public readonly string $email,
    ) {
        parent::__construct();
    }
}
