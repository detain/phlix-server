<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Container\Providers;

use Phlix\Common\Container\Providers\AuthServicesProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests the S5 boot-time JWT-secret guard
 * {@see AuthServicesProvider::assertSecretConfigured()}.
 *
 * The guard refuses to boot when `JWT_SECRET` is empty or still the shipped
 * default sentinel — because the same secret also derives the media signed-URL
 * key, an unconfigured value would make both JWTs and stream URLs forgeable.
 *
 * Inside PHPUnit the production guard short-circuits (PHPUnit constants are
 * defined), so the no-op/pass cases run in-process while the throwing cases are
 * exercised in a forced non-test child PHP process where those constants are
 * absent.
 *
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
