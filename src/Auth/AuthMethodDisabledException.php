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
 * The requested sign-in method is switched off by server policy.
 *
 * Thrown by {@see AuthManager::login()} (and mirrored by the WebAuthn login
 * routes) when the credential itself was never consulted: the method it would
 * use — password, passkey, or an external provider — is disabled through the
 * `auth.<method>.enabled` toggles. It extends {@see \InvalidArgumentException}
 * on purpose so every existing 401 mapping around {@see AuthManager} keeps the
 * right status WITHOUT leaking which factor is off more than the stable code
 * already does: the message is a fixed string and the code constant is the
 * only machine-readable signal.
 *
 * @package Phlix\Auth
 * @since 1.4.0 (F7 auth-method toggles)
 */
final class AuthMethodDisabledException extends \InvalidArgumentException
{
    /**
     * Stable wire code sent to API clients (401 body `code`).
     */
    public const CODE = 'auth.method_disabled';

    public function __construct()
    {
        // Fixed message: it must NOT reveal which method is enabled, so all
        // disabled-method rejections are indistinguishable on the wire beyond
        // the single stable code.
        parent::__construct(self::CODE);
    }
}
