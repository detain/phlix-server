<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Admin;

use Phlix\Auth\PasswordChangeRequiredException;
use Phlix\Auth\UserRepository;
use Phlix\Server\Http\Controllers\Admin\AdminUserController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Review server__seceng M-SERVER-33: the README documented the admin
 * reset-password endpoint as "(returns new password)" while the shipped
 * handler only issues a stored token, sets `must_change_password` and
 * revokes sessions — a self-inflicted lockout procedure dressed up as
 * routine. The docs plane now tells the shipped truth (endpoint row, the
 * two-step "Bad days" recovery entry, the CLI table caveat, and the
 * controller/AuthManager comments); this test keeps it that way in the
 * house README-claim-detector style (AuthMethodLockoutReadmeTest shape):
 * the load-bearing strings are derived at runtime from the enforcing code
 * — the refusal's exception class and the HTTP status the last-resort
 * handler actually answers, the latch column scraped from
 * UserRepository's own UPDATE, and the fiction phrases the cure removed.
 *
 * @package Phlix\Tests\Unit\Admin
 */
final class PasswordResetRecoveryReadmeTest extends TestCase
{
    private static function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function readme(): string
    {
        return (string) file_get_contents(self::repoRoot() . '/README.md');
    }

    /**
     * The endpoint row must exist, must name the shipped `{message}`-only
     * response, and the two fiction phrases must be gone from every prose
     * plane they ever lived on (README table + controller docblocks).
     */
    public function testReadmeRowTellsShippedTruthAndFictionsAreGone(): void
    {
        $readme = self::readme();

        $this->assertStringNotContainsString(
            '(returns new password)',
            $readme,
            'the M-SERVER-33 fiction "(returns new password)" must never return to the README'
        );

        $row = null;
        foreach (explode("\n", $readme) as $line) {
            if (str_contains($line, '/api/v1/admin/users/{id}/reset-password') && str_starts_with($line, '| POST')) {
                $row = $line;
            }
        }
        $this->assertNotNull($row, 'the admin reset-password row must stay documented in the API reference');
        $this->assertStringContainsString(
            '{message}',
            (string) $row,
            'the row must state the shipped response shape (message only, no password returned)'
        );
        $this->assertStringContainsString(
            '#bad-days-recovery-from-the-cli',
            (string) $row,
            'the row must route operators to the recovery section (lockout trap cure)'
        );

        $classDoc = (string) (new ReflectionClass(AdminUserController::class))->getDocComment();
        $this->assertStringNotContainsString(
            'generate a new password',
            $classDoc,
            'the class docblock endpoint list must not promise a returned password'
        );

        $methodDoc = (string) (new ReflectionMethod(AdminUserController::class, 'resetPassword'))->getDocComment();
        $this->assertStringNotContainsString(
            'reset-password form',
            $methodDoc,
            'the handler docblock must not reference a reset-completion form that never shipped'
        );
    }

    /**
     * M-SERVER-33 r2 wire truth: the "refused with 403 + stable code" story r1
     * briefly shipped was itself fiction — AuthManager throws the
     * exception, no HTTP boundary maps it (AuthController's login/refresh catch
     * arms do not match; the last-resort handlers answer a bare 500 and log the
     * exception class). So the refusal story must name the exception class the
     * error log reports, must state the status the served path really answers,
     * and the whole README must NOT carry the code as wire contract — the
     * anti-fiction pin is derived from the exception-plane constant so it only
     * ever flips when a real emitter ships (r1 finding 1; estate riders R1-R3).
     */
    public function testRefusalStoryTellsShippedTruthNotTheUnshippedWireCode(): void
    {
        $readme = self::readme();
        $exception = (new ReflectionClass(PasswordChangeRequiredException::class))->getShortName();

        $paragraph = '';
        foreach (explode("\n\n", $readme) as $block) {
            if (str_contains($block, '**Locked by the admin')) {
                $paragraph = $block;
                break;
            }
        }

        $this->assertStringContainsString(
            $exception,
            $paragraph,
            "the refusal story must name the exception class the HTTP error log reports ('{$exception}')"
        );
        $this->assertStringContainsString(
            '500',
            $paragraph,
            'the refusal story must state the status the served path actually answers (the r1-cured 403 was fiction)'
        );

        $this->assertStringNotContainsString(
            PasswordChangeRequiredException::ERROR_CODE,
            $readme,
            'no shipped emitter places this code on the wire, so the README must not present it as a wire contract'
        );
    }

    /**
     * Both recovery legs must stay documented, and the SQL leg's column
     * name is scraped live from UserRepository's setMustChangePassword()
     * UPDATE — rename the column in code and the docs go red.
     */
    public function testRecoveryStoryCarriesBothLegsWithTheRealLatchColumn(): void
    {
        $method = new ReflectionMethod(UserRepository::class, 'setMustChangePassword');
        $sourceFile = (string) $method->getFileName();
        $lines = file($sourceFile) ?: [];
        $body = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        $this->assertSame(
            1,
            preg_match('/UPDATE\s+users\s+SET\s+(\w+)\s*=\s*\?/', $body, $matches),
            'setMustChangePassword must keep its UPDATE users SET <column> = ? shape for the doc pin to have teeth'
        );
        $latchColumn = $matches[1];

        $readme = self::readme();
        $this->assertStringContainsString(
            'php bin/phlix user:reset-password',
            $readme,
            'the recovery story must keep the CLI password leg'
        );
        $this->assertStringContainsString(
            'SET ' . $latchColumn . ' = 0',
            $readme,
            "the recovery story must keep the direct-write flag leg naming the real column '{$latchColumn}'"
        );
    }

    /**
     * The CLI table row must stay honest about its own limitation: the
     * command writes the password hash but never lifts the latch, and the
     * row says so (closes the second-order misread the endpoint-row cure
     * would otherwise leave behind).
     */
    public function testCliTableRowDisclosesTheLatchLimitation(): void
    {
        $readme = self::readme();

        foreach (explode("\n", $readme) as $line) {
            if (str_starts_with($line, '| `user:reset-password`')) {
                $this->assertStringContainsString(
                    'must_change_password',
                    $line,
                    'the CLI row must disclose that the command does not clear the force-change flag'
                );

                return;
            }
        }

        $this->fail('the user:reset-password CLI row must stay documented in the command table');
    }
}
