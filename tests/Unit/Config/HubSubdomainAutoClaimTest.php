<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;

/**
 * PHLIX_SUBDOMAIN_AUTO_CLAIM must be an actual opt-out.
 *
 * The shipped line was `(bool)(getenv('PHLIX_SUBDOMAIN_AUTO_CLAIM') ?: true)`:
 * the STRING '0' is PHP-falsy, so `?:` treated the one value every operator
 * reaches for to switch something off as if it were unset — the flag was stuck
 * on for any explicit value (minors M-SERVER-72, batch W-min105). The cure
 * copies the file's own neighbouring precedent (PHLIX_TLS_ENABLED above the
 * return) and the DB_POOL_ENABLED law in config/database.php, whose comment
 * names this exact trap.
 *
 * Enforcement point pinned here as well: scripts/claim-subdomain.php reads the
 * config value and refuses to claim when it is false — the flag is not inert
 * machinery, so the truth is "off means the claim CLI refuses", not "off
 * deletes the code path".
 */
final class HubSubdomainAutoClaimTest extends TestCase
{
    private const ENV_KEY = 'PHLIX_SUBDOMAIN_AUTO_CLAIM';

    /** @var array<string, string|false> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        $this->envBackup[self::ENV_KEY] = getenv(self::ENV_KEY);
        putenv(self::ENV_KEY);
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
        $this->envBackup = [];
    }

    public function testTheFlagIsOnWhenTheEnvVarIsUnset(): void
    {
        self::assertTrue($this->flag(), 'unset keeps the historical default (claim is on)');
    }

    /**
     * Every spelling the shipped tolerant rule treats as OFF. '0' is the
     * discriminating member: the pre-cure `?:` chain re-enabled exactly it.
     *
     * @return array<string, array{0: string}>
     */
    public static function offValueProvider(): array
    {
        return [
            "'0'" => ['0'],
            "'false'" => ['false'],
            "'off'" => ['off'],
            "'no'" => ['no'],
            'empty' => [''],
        ];
    }

    /**
     * @dataProvider offValueProvider
     */
    public function testExplicitOffValuesDisableTheClaim(string $value): void
    {
        putenv(self::ENV_KEY . '=' . $value);
        self::assertFalse($this->flag(), sprintf('explicit %s must switch the flag off', var_export($value, true)));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function onValueProvider(): array
    {
        return [
            "'1'" => ['1'],
            "'true'" => ['true'],
            "'on'" => ['on'],
            "'yes'" => ['yes'],
        ];
    }

    /**
     * @dataProvider onValueProvider
     */
    public function testExplicitOnValuesKeepTheClaimEnabled(string $value): void
    {
        putenv(self::ENV_KEY . '=' . $value);
        self::assertTrue($this->flag(), sprintf('explicit %s keeps the flag on', var_export($value, true)));
    }

    public function testUnrecognisedValuesFailTowardOffLikeTheTlsSibling(): void
    {
        // filter_var(FILTER_VALIDATE_BOOLEAN) non-strict maps anything it does
        // not recognise to false — the same tolerant rule PHLIX_TLS_ENABLED and
        // DB_POOL_ENABLED ship. A typo must NOT silently keep a claimed
        // subdomain pipeline running that the operator meant to stop.
        putenv(self::ENV_KEY . '=banana');
        self::assertFalse($this->flag());
    }

    public function testTheClaimScriptIsTheEnforcementPoint(): void
    {
        // The config key is consumed by scripts/claim-subdomain.php; pin that
        // wiring so neither side renames it out from under the other.
        $script = (string) file_get_contents(dirname(__DIR__, 3) . '/scripts/claim-subdomain.php');
        self::assertStringContainsString("\$hubConfig['subdomain_auto_claim']", $script);
        self::assertStringContainsString('subdomain_auto_claim is disabled', $script);
    }

    private function flag(): bool
    {
        $config = require dirname(__DIR__, 3) . '/config/hub.php';
        self::assertIsArray($config);

        return $config['subdomain_auto_claim'];
    }
}
