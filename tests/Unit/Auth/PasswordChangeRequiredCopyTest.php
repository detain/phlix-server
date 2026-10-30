<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use Phlix\Auth\PasswordChangeRequiredException;
use PHPUnit\Framework\TestCase;

/**
 * Review server__copywriter M-SERVER-03: the forced-password-change 403 told
 * members to "Please use the password reset link" — a reset link does not
 * exist in this product. The only reset surface is admin-side
 * (POST /admin/users/{id}/reset-password); the member-side completion flow is
 * explicitly not shipped (see the AuthManager login-path comment). A member
 * hitting this wall was pointed at phantom UI.
 *
 * The shipped copy directs the member to their administrator — the only
 * actor that can lift `must_change_password` today. The stable code
 * (auth.password_change_required) is wire contract and stays pinned here.
 *
 * @package Phlix\Tests\Unit\Auth
 */
final class PasswordChangeRequiredCopyTest extends TestCase
{
    public static function testMessageNeverPromisesAPhantomResetLink(): void
    {
        $message = (new PasswordChangeRequiredException())->getMessage();

        self::assertStringNotContainsString(
            'reset link',
            $message,
            'the forced-change message must not point at a password-reset link the server does not have.'
        );
    }

    public static function testMessageNamesTheOnlyActorWhoCanUnblock(): void
    {
        $message = (new PasswordChangeRequiredException())->getMessage();

        self::assertSame(
            'Your password must be changed before you can sign in. Ask your server administrator to reset it.',
            $message,
            'the shipped member-facing sentence (review server__copywriter M-SERVER-03 cure).'
        );
    }

    public static function testStableErrorCodeIsUnchanged(): void
    {
        self::assertSame(
            'auth.password_change_required',
            (new PasswordChangeRequiredException())->errorCode,
            'the dotted wire code is contract; only the human copy may move.'
        );
    }
}
