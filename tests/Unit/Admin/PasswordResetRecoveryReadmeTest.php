<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
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
 * the load-bearing strings are derived at runtime from the enforcing code.
 *
 * r4 (review M-SERVER-33__r2) upgraded two structurally toothless arms into
 * real legs. The refusal story is now attributed PER SENTENCE: the login leg
 * must name PasswordChangeRequiredException + the bare 500 it truly reaches,
 * and the refresh leg must name 401 — the r2-shipped blanket sentence that
 * conflated both legs into "refused by PasswordChangeRequiredException → 500"
 * reddens the refresh attribution pin (its probe-B was green precisely
 * because the old pins only checked that strings appeared somewhere in the
 * block). And the handler docblock pin stopped using getDocComment() to hunt
 * a phrase that ever lived only in the method BODY — it now scrapes
 * docblock+body through getStartLine()/getEndLine() (mirroring this file's
 * UserRepository scraper), so both fiction phrases AND the r2-cured truth
 * phrases on each plane have teeth. The blanket "the account cannot sign in"
 * prose, the passkey/provider non-consultation disclosure, and the CLI row's
 * token-revocation clause (UserRepository::update stamps
 * tokens_not_valid_after on every password write) are pinned likewise.
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
     * Normalize a prose carrier for attribution pins: strip markdown
     * emphasis/inline-code markers and flatten every run of whitespace
     * (including source line wrapping) to a single space.
     */
    private static function flatten(string $text): string
    {
        return (string) preg_replace(
            '/\s+/',
            ' ',
            str_replace(['**', '`'], '', $text)
        );
    }

    /**
     * The endpoint row must exist, must name the shipped `{message}`-only
     * response, must SCOPE the refusal to the password path (r4: the blanket
     * "the account cannot sign in" is false — passkey and provider sign-in
     * never consult the latch), and the fiction phrases must be gone from
     * every prose plane they ever lived on.
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

        // r4 leg-scoping (review M-SERVER-33__r2 remedy 1): the refusal prose
        // must name what the latch actually gates plus the bypass, so the
        // blanket sentence cannot silently re-enter via a table-row rewrite.
        $flatRow = self::flatten((string) $row);
        $this->assertStringContainsString(
            'cannot sign in with its password',
            $flatRow,
            'the endpoint row must scope the sign-in refusal to the password path (passkey/provider bypass it)'
        );
        $this->assertStringContainsString(
            'passkey',
            $flatRow,
            'the endpoint row must disclose that passkey sign-in does not consult the flag'
        );

        $classDoc = (string) (new ReflectionClass(AdminUserController::class))->getDocComment();
        $this->assertStringNotContainsString(
            'generate a new password',
            $classDoc,
            'the class docblock endpoint list must not promise a returned password'
        );

        // r4 (review M-SERVER-33__r2 remedy 3): the old pin hunted
        // 'reset-password form' with getDocComment(), but that phrase ever
        // lived ONLY in the method BODY — a structurally unfailable pin
        // (probe A: restoring the full fiction note stayed green). Scrape
        // docblock+body via getStartLine()/getEndLine(), mirroring the
        // UserRepository scraper below, so both planes carry teeth: the
        // fiction phrases must stay out AND the r2-cured truth phrases must
        // stay in (a silent revert of either plane now reddens this test).
        $method = new ReflectionMethod(AdminUserController::class, 'resetPassword');
        $lines = file((string) $method->getFileName()) ?: [];
        $methodSource = (string) $method->getDocComment() . "\n" . implode('', array_slice(
            $lines,
            (int) $method->getStartLine() - 1,
            (int) $method->getEndLine() - (int) $method->getStartLine() + 1
        ));
        $flatSource = self::flatten($methodSource);

        foreach (['reset-password form', 'sent to the user via email', 'generate a new password'] as $fiction) {
            $this->assertStringNotContainsString(
                $fiction,
                $methodSource,
                "the handler's live docblock+body source must not carry the fiction '{$fiction}'"
            );
        }

        $this->assertStringContainsString(
            'no mailer leg and no reset-completion endpoint',
            $flatSource,
            'the handler docblock must keep stating that delivery/consumption never shipped (r2 cure plane)'
        );
        $this->assertStringContainsString(
            'the plaintext $token is never sent',
            $flatSource,
            'the handler body note must keep stating the token never leaves the server (r2 cure plane)'
        );

        // r4 disclosure (review remedy 1, code plane): the docblock must name
        // the two unguarded sign-in entry points it scopes the latch away from.
        $this->assertStringContainsString(
            'finishAuthentication',
            $flatSource,
            'docblock must cite the passkey entry point'
        );
        $this->assertStringContainsString(
            'loginWithProvider',
            $flatSource,
            'docblock must cite the provider entry point'
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
     *
     * r4 (review M-SERVER-33__r2 remedy 2+3): the r2 paragraph collapsed the
     * two refusal legs into one false sentence. The login leg really dies as an
     * unmapped `PasswordChangeRequiredException` in the last-resort Throwable
     * catch (bare 500, error log names the class), but the refresh leg throws a
     * plain `InvalidArgumentException` that AuthController's own catch turns
     * into a 401 `{error}` with no code field. Presence-of-strings pins could
     * not tell that conflation from the truth (the r2 probe-B install stayed
     * green), so each leg is now pinned AT ITS SENTENCE: any sentence
     * attributing a refusal to the refresh path must carry the 401 and must
     * NOT name the login exception; any sentence attributing the login refusal
     * must name the exception and the 500 it truly reaches. The paragraph must
     * also carry the passkey/provider non-consultation disclosure — the
     * security-relevant survivor of r1 MAJOR-1 (WebAuthn ships default-enabled
     * in config/auth.php and provider login never consults the latch).
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

        // r4 leg attribution: split the flattened paragraph into sentences
        // (period followed by whitespace — code identifiers such as
        // AuthController::refresh never present that shape) and audit the
        // refusal carriers separately, per leg.
        $flat = self::flatten($paragraph);
        $sentences = preg_split('/(?<=\.)\s+/', $flat) ?: [];

        $refreshSentences = [];
        $loginSentences = [];
        foreach ($sentences as $sentence) {
            if (strpos($sentence, 'refus') === false) {
                continue;
            }
            if (stripos($sentence, 'refresh') !== false) {
                $refreshSentences[] = $sentence;
            }
            if (stripos($sentence, 'login') !== false) {
                $loginSentences[] = $sentence;
            }
        }

        $this->assertNotEmpty($loginSentences, 'the recovery story must keep the login-leg refusal sentence');
        $this->assertNotEmpty($refreshSentences, 'the recovery story must keep the refresh-leg refusal sentence');
        foreach ($loginSentences as $sentence) {
            $this->assertStringContainsString(
                $exception,
                $sentence,
                "the login-leg refusal sentence must name {$exception}: '{$sentence}'"
            );
            $this->assertStringContainsString(
                '500',
                $sentence,
                "the login-leg refusal sentence must state the unmapped-exception 500: '{$sentence}'"
            );
        }
        foreach ($refreshSentences as $sentence) {
            $this->assertStringContainsString(
                '401',
                $sentence,
                'the refresh-leg refusal sentence must state the status '
                . "AuthController::refresh actually answers: '{$sentence}'"
            );
            $this->assertStringNotContainsString(
                $exception,
                $sentence,
                "the refresh-leg refusal must NOT be attributed to {$exception} "
                . '(it throws plain InvalidArgumentException): ' . "'{$sentence}'"
            );
        }

        $this->assertStringContainsString(
            'InvalidArgumentException',
            $paragraph,
            'the recovery story must name the exception the refresh leg really throws'
        );

        $this->assertStringContainsString('passkey', $flat, 'the recovery story must disclose the passkey bypass');
        $this->assertStringContainsString(
            'WebAuthnController',
            $flat,
            'the recovery story must name the ungated passkey entry point'
        );
        $this->assertStringContainsString(
            'loginWithProvider',
            $flat,
            'the recovery story must name the ungated provider entry point'
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
     * The CLI table row must stay honest about its own limits: the command
     * writes the password hash but never lifts the latch (r1 finding), and —
     * r4, review M-SERVER-33__r2 MINOR-1 carry-over remedy 4 — a password
     * write is NOT hash-only: the UserRepository::update choke point stamps
     * tokens_not_valid_after = NOW() on every password write, so the CLI
     * reset also revokes the account's live sessions. The row must name that
     * column (the "hash only" understatement reddens).
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
                $this->assertStringContainsString(
                    'tokens_not_valid_after',
                    $line,
                    'the CLI row must disclose that the password write also revokes live sessions'
                );

                return;
            }
        }

        $this->fail('the user:reset-password CLI row must stay documented in the command table');
    }
}
