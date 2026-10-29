<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Admin;

use Phlix\Auth\AuthMethodLockoutException;
use Phlix\Auth\AuthMethodPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Review server__pm M-SERVER-30: "Auth-method 422 guards (all-off / admin
 * lockout) enforce user-visible policy with zero explanation anywhere." The
 * guards always shipped a machine-readable `reason` token; what was missing
 * was any documentation of what the tokens mean and what to do. The docs now
 * exist (README "Auth-method lock-out guards", the API-Reference 422
 * paragraph, the CLI recovery section, and docs/dev/AUTH_METHOD_LOCKOUT.md);
 * this test is what keeps them honest, in the house README-claim-detector
 * style (the shape of BackupSubsystemReadmeTest): every load-bearing token
 * is derived at runtime from the enforcing constants — rename a constant in
 * code and the docs go red; delete a documented token and the docs go red.
 *
 * @package Phlix\Tests\Unit\Admin
 */
final class AuthMethodLockoutReadmeTest extends TestCase
{
    private static function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function readme(): string
    {
        return (string) file_get_contents(self::repoRoot() . '/README.md');
    }

    private static function devDoc(): string
    {
        return (string) file_get_contents(
            self::repoRoot() . '/docs/dev/AUTH_METHOD_LOCKOUT.md'
        );
    }

    /**
     * Both reason tokens ship in the README guard section AND the API-Reference
     * error-meaning table, and their VALUES come from the exception constants
     * the throwing code uses — one source of truth, pinned to the prose.
     */
    public function testReadmeDocumentsBothReasonTokensFromTheExceptionConstants(): void
    {
        $readme = self::readme();

        $this->assertStringContainsString(
            '### Auth-method lock-out guards',
            $readme,
            'the README must keep the dedicated guard section (M-SERVER-30)'
        );

        $tokens = [
            AuthMethodLockoutException::REASON_ALL_METHODS_DISABLED,
            AuthMethodLockoutException::REASON_ADMIN_LOCKOUT,
        ];
        foreach ($tokens as $token) {
            $this->assertStringContainsString(
                '`' . $token . '`',
                $readme,
                "the reason token '{$token}' must stay documented in the README"
            );
        }

        // The tokens documented must be exactly the tokens that exist — a
        // third REASON_* appearing in code without docs goes red here.
        $reflected = new \ReflectionClass(AuthMethodLockoutException::class);
        $reasonConstants = array_filter(
            $reflected->getConstants(),
            static fn (string $name): bool => str_starts_with($name, 'REASON_'),
            ARRAY_FILTER_USE_KEY,
        );
        $this->assertSame(
            $tokens,
            array_values($reasonConstants),
            'the README pair must track the REASON_* constant set exactly'
        );
    }

    /**
     * The API-Reference surface: both guarded routes must carry the 422
     * anchor (table rows and the reason-meaning table paragraph), so an
     * integrator reading only the endpoint reference still finds the meaning.
     */
    public function testReadmeApiReferenceCarriesTheGuardAnchors(): void
    {
        $readme = self::readme();

        $this->assertStringContainsString(
            '**Auth-method lock-out guard (`422`).**',
            $readme,
            'the API-Reference error-meaning block must stay (Rate-limiting-paragraph idiom)'
        );
        $this->assertStringContainsString(
            '/api/v1/admin/auth-providers/{name}/enable',
            $readme,
            'the provider toggle endpoint row must remain documented'
        );
        // The paragraph cross-links the operator section it defers to.
        $this->assertStringContainsString(
            '[Auth-method lock-out guards](#auth-method-lock-out-guards)',
            $readme,
            'the 422 paragraph must link the guard section'
        );
    }

    /**
     * The recovery story must name the fallback event (derived from the
     * policy constant) and the concrete re-open SQL, in BOTH the README
     * recovery section and the dev doc.
     */
    public function testRecoveryPathNamesTheFallbackEventAndTheReOpenSql(): void
    {
        $fallbackEvent = AuthMethodPolicy::FALLBACK_EVENT;

        foreach (['readme' => self::readme(), 'dev doc' => self::devDoc()] as $label => $text) {
            $this->assertStringContainsString(
                $fallbackEvent,
                $text,
                "{$label} must document the read-path fallback event name"
            );
            $this->assertStringContainsString(
                "setting_key = 'auth." . AuthMethodPolicy::PASSWORD . ".enabled'",
                $text,
                "{$label} must carry the concrete re-open-of-password recovery"
            );
        }
    }

    /**
     * Every governed key string (derived from AuthMethodPolicy::settingsKey()
     * over METHODS) must appear in the dev doc's key table — a sixth method
     * added to the policy without doc coverage goes red here.
     */
    public function testDevDocTabulatesEveryGovernedSettingsKey(): void
    {
        $doc = self::devDoc();

        foreach (AuthMethodPolicy::METHODS as $method) {
            $key = AuthMethodPolicy::settingsKey($method);
            $this->assertStringContainsString(
                $key,
                $doc,
                "the governed key '{$key}' (method '{$method}') must stay documented"
            );
        }
    }

    /**
     * The dev doc must keep explaining the two envelopes' shared contract and
     * the serialization law by name — the load-bearing phrases integrators
     * grep for.
     */
    public function testDevDocCarriesTheEnvelopeAndProtocolLaws(): void
    {
        $doc = self::devDoc();

        $this->assertStringContainsString(
            '"reason":',
            $doc,
            'the 422 envelope shape must stay documented'
        );
        $this->assertStringContainsString(
            'FOR UPDATE',
            $doc,
            'the B2 serialization (lock) law must stay documented'
        );
        $this->assertStringContainsString(
            'AuthMethodPolicy::assertSafeTransition()',
            $doc,
            'the guard SSOT must stay named in the doc'
        );
    }
}
