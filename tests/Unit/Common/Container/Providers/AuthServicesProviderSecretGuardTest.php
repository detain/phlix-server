<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Container\Providers;

use Phlix\Common\Container\Providers\AuthServicesProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests the S5 boot-time JWT-secret guard
 * {@see AuthServicesProvider::assertSecretConfigured()}.
 *
 * The guard refuses to boot when `JWT_SECRET` is empty, the shipped default
 * sentinel, or a publicly-known placeholder (M11) — because the same secret
 * also derives the media signed-URL
 * key, an unconfigured value would make both JWTs and stream URLs forgeable.
 *
 * Inside PHPUnit the production guard short-circuits (PHPUnit constants are
 * defined), so the no-op/pass cases run in-process while the throwing cases are
 * exercised in a forced non-test child PHP process where those constants are
 * absent.
 */
final class AuthServicesProviderSecretGuardTest extends TestCase
{
    /** @var string|false */
    private string|false $originalPhlixEnv = false;

    /** @var string|false */
    private string|false $originalJwtSecret = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalPhlixEnv = getenv('PHLIX_ENV');
        $this->originalJwtSecret = getenv('JWT_SECRET');
    }

    protected function tearDown(): void
    {
        $this->restoreEnv('PHLIX_ENV', $this->originalPhlixEnv);
        $this->restoreEnv('JWT_SECRET', $this->originalJwtSecret);
        parent::tearDown();
    }

    private function restoreEnv(string $name, string|false $value): void
    {
        if ($value === false) {
            putenv($name);
        } else {
            putenv($name . '=' . $value);
        }
    }

    /**
     * In the test environment the guard is a no-op regardless of the secret —
     * the suite never sets a real `JWT_SECRET`, so the production check must not
     * abort it. (PHPUnit constants are defined, so this exercises the skip path.)
     */
    public function testSkipsCheckInTestEnvironment(): void
    {
        putenv('PHLIX_ENV=test');
        putenv('JWT_SECRET'); // unset

        AuthServicesProvider::assertSecretConfigured();
        AuthServicesProvider::assertSecretConfigured('');
        AuthServicesProvider::assertSecretConfigured(AuthServicesProvider::DEFAULT_JWT_SECRET);

        $this->expectNotToPerformAssertions();
    }

    /**
     * A real, high-entropy secret passes — even were the guard not skipped, a
     * configured value is accepted (no exception).
     */
    public function testPassesOnRealSecret(): void
    {
        // Skip path keeps it green; the contract is "real secret never throws".
        AuthServicesProvider::assertSecretConfigured('a-real-high-entropy-secret-value');
        $this->expectNotToPerformAssertions();
    }

    /**
     * The sentinel is exported from exactly one place and matches the historical
     * literal that callers (SignedUrl, public/index.php) depended on.
     */
    public function testSentinelConstantValueIsStable(): void
    {
        self::assertSame('default-secret-change-me', AuthServicesProvider::DEFAULT_JWT_SECRET);
    }

    /**
     * The guard aborts when the resolved secret is empty (forced non-test child
     * process — PHPUnit constants absent so the skip path does not apply).
     */
    public function testThrowsOnEmptySecretInNonTestEnvironment(): void
    {
        $this->assertGuardThrows('', 'empty');
    }

    /**
     * The guard throws when the resolved secret is still the shipped default
     * sentinel.
     */
    public function testThrowsOnDefaultSentinelInNonTestEnvironment(): void
    {
        $this->assertGuardThrows(AuthServicesProvider::DEFAULT_JWT_SECRET, 'default');
    }

    /**
     * L-4 (security audit 2026-09-29): the length floor is enforced end to end —
     * a short-but-real secret aborts the boot in a forced non-test child.
     */
    public function testThrowsOnShortSecretInNonTestEnvironment(): void
    {
        $this->assertGuardThrows(str_repeat('a', AuthServicesProvider::MIN_SECRET_BYTES - 1), 'bytes');
    }

    /**
     * L-4: the pure policy seam. assertSecretConfigured cannot run under
     * PHPUnit (the test-env early-return is the very thing that keeps the suite
     * bootable), so the tier logic is unit-tested directly on secretViolation:
     * empty/sentinel are fatal EVERYWHERE (dev included); only the length floor
     * gets the dev relaxation.
     */
    public function testSecretViolationTiers(): void
    {
        // Tier 1+2: fatal in both modes.
        self::assertStringContainsString('not set', (string) AuthServicesProvider::secretViolation(''));
        self::assertStringContainsString('not set', (string) AuthServicesProvider::secretViolation('', true));
        self::assertStringContainsString(
            'shipped default',
            (string) AuthServicesProvider::secretViolation(AuthServicesProvider::DEFAULT_JWT_SECRET)
        );
        self::assertStringContainsString(
            'shipped default',
            (string) AuthServicesProvider::secretViolation(AuthServicesProvider::DEFAULT_JWT_SECRET, true)
        );

        // Tier 3: length floor outside dev only.
        $short = str_repeat('k', AuthServicesProvider::MIN_SECRET_BYTES - 1);
        self::assertStringContainsString('bytes', (string) AuthServicesProvider::secretViolation($short));
        self::assertNull(AuthServicesProvider::secretViolation($short, true));

        // Floor boundary: exactly MIN_SECRET_BYTES passes.
        self::assertNull(
            AuthServicesProvider::secretViolation(str_repeat('k', AuthServicesProvider::MIN_SECRET_BYTES))
        );
        // A realistic 64-hex (openssl rand -hex 32) key passes.
        self::assertNull(AuthServicesProvider::secretViolation(str_repeat('0123456789abcdef', 4)));

        // M11 corollary: real keys that merely CONTAIN a placeholder word keep
        // passing — the tier is prefix/exact, never substring.
        self::assertNull(AuthServicesProvider::secretViolation('a-real-high-entropy-secret-value'));
        self::assertNull(AuthServicesProvider::secretViolation(str_repeat('9f8e7d6c', 8)));
    }

    /**
     * Review server__devops M11: the placeholder tier is fatal EVERYWHERE, dev
     * included — unlike the length floor, a committed example has no entropy to
     * relax about. Covers the 31-byte `.env.example` value, padded 40+ variants
     * that the length floor would wave through, uppercase forms, and the bare
     * exact words (which the dev relaxation used to accept outright).
     */
    public function testPlaceholderSecretsAreFatalOnEveryTierIncludingDev(): void
    {
        $values = [
            'change_me_generate_with_openssl',
            'change_me_' . str_repeat('9', 40),
            'CHANGEME',
            'change-me-in-production',
            'secret',
            'password',
            'your-secret-here',
            'your_secret_here',
            'placeholder',
            'example',
            'test',
        ];

        foreach ($values as $value) {
            self::assertStringContainsString(
                'placeholder',
                (string) AuthServicesProvider::secretViolation($value),
                'placeholder value must be fatal in production: ' . $value
            );
            self::assertStringContainsString(
                'placeholder',
                (string) AuthServicesProvider::secretViolation($value, true),
                'placeholder value must be fatal even in dev: ' . $value
            );
        }
    }

    /**
     * M11 end to end: a padded placeholder (so long the pre-M11 tiers could not
     * see it) aborts the boot in a forced non-test child.
     */
    public function testThrowsOnPlaceholderSecretInNonTestEnvironment(): void
    {
        $this->assertGuardThrows('change_me_' . str_repeat('9', 40), 'placeholder');
    }

    /**
     * The M11 lift leaves TWO guards enforcing one policy (docker entrypoint
     * + PHP boot). Pin the PHP pattern families against the entrypoint's case
     * arm so they cannot drift apart: star patterns ↔
     * PLACEHOLDER_SECRET_PREFIXES, exact patterns ↔ PLACEHOLDER_SECRETS plus
     * the sentinel (which the PHP side refuses as its own tier 2 message).
     */
    public function testPlaceholderFamilyMatchesTheDockerEntrypointTwin(): void
    {
        $entrypointPath = dirname(__DIR__, 5) . '/docker/docker-entrypoint.sh';
        self::assertFileExists($entrypointPath);
        $entrypoint = (string) file_get_contents($entrypointPath);

        $start = strpos($entrypoint, 'phlix_is_placeholder_secret()');
        self::assertIsInt(
            $start,
            'the entrypoint guard must stay in place as carrier-side defense-in-depth'
        );
        $fn = substr($entrypoint, $start, 2000);

        self::assertTrue(
            (bool) preg_match('/\bin\s+(.*?)\)\s*\n\s*return\s+0/s', $fn, $m),
            'could not locate the placeholder case arm in phlix_is_placeholder_secret()'
        );
        $arm = str_replace(["\\\n", '\\', "\n"], ' ', $m[1]);
        $patterns = array_values(array_filter(
            array_map('trim', preg_split('/\|/', $arm) ?: []),
            static fn (string $p): bool => $p !== ''
        ));
        self::assertNotEmpty($patterns, 'the case arm must list patterns');

        $stars = [];
        $exacts = [];
        foreach ($patterns as $pattern) {
            if (str_ends_with($pattern, '*')) {
                $stars[] = strtolower(substr($pattern, 0, -1));
            } else {
                $exacts[] = strtolower($pattern);
            }
        }
        sort($stars);
        sort($exacts);

        $phpPrefixes = AuthServicesProvider::PLACEHOLDER_SECRET_PREFIXES;
        sort($phpPrefixes);
        self::assertSame(
            $stars,
            $phpPrefixes,
            'PHP PLACEHOLDER_SECRET_PREFIXES must equal the entrypoint star patterns'
        );

        self::assertContains(
            AuthServicesProvider::DEFAULT_JWT_SECRET,
            $exacts,
            'the entrypoint keeps the sentinel in its arm list (carrier independence)'
        );
        $exactsWithoutSentinel = array_values(array_filter(
            $exacts,
            static fn (string $p): bool => $p !== AuthServicesProvider::DEFAULT_JWT_SECRET
        ));
        $phpExacts = AuthServicesProvider::PLACEHOLDER_SECRETS;
        sort($exactsWithoutSentinel);
        sort($phpExacts);
        self::assertSame(
            $exactsWithoutSentinel,
            $phpExacts,
            'PHP PLACEHOLDER_SECRETS must equal the entrypoint exact patterns minus the sentinel'
        );
    }

    /**
     * Runs assertSecretConfigured() in a forced non-test child PHP process so the
     * PHPUnit-constant short-circuit does not apply, and asserts it aborts with a
     * non-zero exit and a CRITICAL message mentioning JWT_SECRET.
     *
     * @param string $secret      The JWT_SECRET value to feed the child.
     * @param string $expectWord  A word expected in the CRITICAL message.
     */
    private function assertGuardThrows(string $secret, string $expectWord): void
    {
        $autoload = dirname(__DIR__, 5) . '/vendor/autoload.php';
        self::assertFileExists($autoload, 'composer autoloader must exist for the subprocess guard test');

        $script = <<<'PHP'
<?php
require %s;
use Phlix\Common\Container\Providers\AuthServicesProvider;
try {
    AuthServicesProvider::assertSecretConfigured(getenv('JWT_SECRET') === false ? '' : getenv('JWT_SECRET'));
    echo "NO_THROW";
    exit(0);
} catch (\RuntimeException $e) {
    fwrite(STDERR, $e->getMessage());
    exit(7);
}
PHP;
        $script = sprintf($script, var_export($autoload, true));

        $tmp = tempnam(sys_get_temp_dir(), 'phlix_s5_guard_');
        self::assertIsString($tmp);
        file_put_contents($tmp, $script);

        try {
            $env = $_ENV;
            $env['PHLIX_ENV'] = 'production';
            $env['JWT_SECRET'] = $secret;

            $descriptors = [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $proc = proc_open(
                [PHP_BINARY, $tmp],
                $descriptors,
                $pipes,
                null,
                $env,
            );
            self::assertIsResource($proc);

            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($proc);

            self::assertSame(7, $exit, 'guard must abort with the RuntimeException exit code; stdout=' . $stdout);
            self::assertStringNotContainsString('NO_THROW', (string) $stdout);
            self::assertStringContainsString('CRITICAL', (string) $stderr);
            self::assertStringContainsString('JWT_SECRET', (string) $stderr);
            self::assertStringContainsStringIgnoringCase($expectWord, (string) $stderr);
        } finally {
            @unlink($tmp);
        }
    }
}
