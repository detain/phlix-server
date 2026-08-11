<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Platform;

use PHPUnit\Framework\TestCase;

/**
 * S314 — pins the ONE derived PHP-extension contract to its three consumers.
 *
 * `scripts/required-php-extensions.php` is the single derived list. Three things
 * have to agree with it or the whole scheme is decoration:
 *
 *   1. `composer.json` -> `require`          — `"ext-<name>": "*"` per entry.
 *   2. `composer.json` -> `config.platform`  — the same set, so RESOLUTION is
 *      identical on the dev box (8.3.6), CI (8.3) and production (8.5).
 *   3. the call site each entry cites        — the symbol must still be there.
 *
 * (3) is the half that stops the list rotting into an over-broad wish list: an
 * entry whose justification is deleted goes RED here and has to be removed
 * deliberately, in a visible diff.
 *
 * ⚠ These assertions are deliberately EXACT, in both directions. A one-way
 * "every contract entry is in composer.json" check would happily pass while
 * composer.json required an extension nothing in `src/` calls; the reverse-
 * direction assertions below are what make the contract a contract.
 *
 * ⚠ This test does NOT call `extension_loaded()` to decide what belongs on the
 * list. A list derived from the environment it is checking self-adjusts and can
 * never fail. The presence check is a separate, executed gate:
 * `vendor/composer/platform_check.php` (enabled by `config.platform-check: true`)
 * runs on every `vendor/autoload.php` include, so PHPUnit itself cannot start on
 * a box missing one of these — proven by removing `20-exif.ini` from the ini
 * scan dir, which turns `vendor/bin/phpunit --version` into exit 255 naming
 * exactly `exif` while `composer install` stays clean.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
final class RequiredPhpExtensionsContractTest extends TestCase
{
    /**
     * The two extensions Composer's autoload-time platform check CANNOT enforce,
     * because `symfony/polyfill-ctype` / `symfony/polyfill-mbstring` declare
     * `provide` for them. They are still part of the contract; they are simply
     * not in `platform_check.php`, and this list records why so that a reader
     * of that generated file does not conclude the contract is short by two.
     *
     * @var list<string>
     */
    private const POLYFILL_PROVIDED = ['ctype', 'mbstring'];

    /**
     * The lowest PHP the project supports, and therefore the version
     * `config.platform.php` must pin. Derived from `require.php` (">=8.3") by
     * {@see testPlatformPinsTheLowestSupportedPhp}, not asserted independently.
     */
    private const EXPECTED_PLATFORM_PHP = '8.3.0';

    private static string $repoRoot;

    public static function setUpBeforeClass(): void
    {
        self::$repoRoot = dirname(__DIR__, 3);
    }

    /**
     * @return array<string, array{symbol: string, source: string, why: string}>
     */
    private function contract(): array
    {
        /** @var array<string, array{symbol: string, source: string, why: string}> $contract */
        $contract = require self::$repoRoot . '/scripts/required-php-extensions.php';

        return $contract;
    }

    /**
     * @return array<string, mixed>
     */
    private function composerJson(): array
    {
        $raw = file_get_contents(self::$repoRoot . '/composer.json');
        self::assertIsString($raw, 'composer.json must be readable.');

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    public function testTheContractIsNotEmptyAndEveryEntryIsFullyPopulated(): void
    {
        $contract = $this->contract();

        // The denominator. A contract that quietly shrank to nothing would make
        // every other assertion in this file vacuously true.
        self::assertGreaterThanOrEqual(
            20,
            count($contract),
            'The derived extension contract collapsed. It was 24 entries at S314; a drop this '
            . 'large means the derivation was replaced, not refined.',
        );

        foreach ($contract as $extension => $entry) {
            self::assertMatchesRegularExpression(
                '/^[a-z][a-z0-9_]*$/',
                $extension,
                'Contract keys are bare extension names without the "ext-" prefix.',
            );
            self::assertNotSame('', $entry['symbol'], sprintf('%s: empty symbol', $extension));
            self::assertNotSame('', $entry['source'], sprintf('%s: empty source', $extension));
            self::assertNotSame('', $entry['why'], sprintf('%s: empty rationale', $extension));
        }
    }

    /**
     * Every entry must still be able to point at the code that justifies it.
     */
    public function testEveryContractEntryStillHasItsCallSite(): void
    {
        $contract = $this->contract();
        $checked = 0;

        foreach ($contract as $extension => $entry) {
            $path = self::$repoRoot . '/' . $entry['source'];
            self::assertFileExists(
                $path,
                sprintf(
                    'ext-%s cites %s, which no longer exists. Re-derive the call site or drop '
                    . 'the requirement — do not leave the citation dangling.',
                    $extension,
                    $entry['source'],
                ),
            );

            $source = file_get_contents($path);
            self::assertIsString($source);
            self::assertStringContainsString(
                $entry['symbol'],
                $source,
                sprintf(
                    'ext-%s is required because %s calls %s — and that call is GONE. Either the '
                    . 'requirement is no longer justified (remove it from the contract, from '
                    . 'composer.json require and from config.platform), or the citation needs '
                    . 'updating to a call site that still exists.',
                    $extension,
                    $entry['source'],
                    $entry['symbol'],
                ),
            );
            $checked++;
        }

        self::assertSame(
            count($contract),
            $checked,
            'Every contract entry must have been checked; a loop that examined zero entries '
            . 'reads exactly like a pass.',
        );
    }

    public function testComposerRequireNamesExactlyTheContractExtensions(): void
    {
        $composer = $this->composerJson();
        self::assertIsArray($composer['require'] ?? null);
        /** @var array<string, string> $require */
        $require = $composer['require'];

        $declared = [];
        foreach ($require as $package => $constraint) {
            if (str_starts_with($package, 'ext-')) {
                $declared[substr($package, 4)] = $constraint;
            }
        }

        $expected = array_keys($this->contract());
        sort($expected);
        $actual = array_keys($declared);
        sort($actual);

        self::assertSame(
            $expected,
            $actual,
            'composer.json "require" and scripts/required-php-extensions.php disagree. They are '
            . 'the same contract expressed twice; change both or neither.',
        );

        foreach ($declared as $extension => $constraint) {
            self::assertSame(
                '*',
                $constraint,
                sprintf(
                    'ext-%s is version-constrained. Every extension constraint in this project is '
                    . '"*" — a version here would interact with config.platform, which pins every '
                    . 'extension to a single synthetic version.',
                    $extension,
                ),
            );
        }
    }

    public function testConfigPlatformPinsExactlyTheContractExtensionsPlusPhp(): void
    {
        $composer = $this->composerJson();
        self::assertIsArray($composer['config'] ?? null);
        /** @var array<string, mixed> $config */
        $config = $composer['config'];

        self::assertIsArray(
            $config['platform'] ?? null,
            'config.platform is ABSENT. Without it, dependency resolution binds to whichever '
            . 'machine ran composer — and this estate resolves on PHP 8.3.6 and runs on 8.5.',
        );
        /** @var array<string, string> $platform */
        $platform = $config['platform'];

        self::assertArrayHasKey('php', $platform, 'config.platform must pin php.');

        $pinnedExtensions = [];
        foreach ($platform as $package => $version) {
            if ($package === 'php') {
                continue;
            }
            self::assertStringStartsWith(
                'ext-',
                $package,
                sprintf('config.platform pins "%s", which is neither php nor an extension.', $package),
            );
            $pinnedExtensions[] = substr($package, 4);
        }

        $expected = array_keys($this->contract());
        sort($expected);
        sort($pinnedExtensions);

        self::assertSame(
            $expected,
            $pinnedExtensions,
            'config.platform and the derived contract disagree. An extension pinned here but not '
            . 'required is a lie told to the solver; one required but not pinned makes resolution '
            . 'depend on the resolving machine again.',
        );
    }

    public function testPlatformPinsTheLowestSupportedPhp(): void
    {
        $composer = $this->composerJson();
        /** @var array<string, mixed> $config */
        $config = $composer['config'];
        /** @var array<string, string> $platform */
        $platform = $config['platform'];
        /** @var array<string, string> $require */
        $require = $composer['require'];

        self::assertSame(
            '>=8.3',
            $require['php'],
            'The php requirement moved. config.platform.php must follow it to the new LOWEST '
            . 'supported version — pinning the highest would let a package that needs the newer '
            . 'PHP into a lock that older installs then cannot satisfy.',
        );
        self::assertSame(
            self::EXPECTED_PLATFORM_PHP,
            $platform['php'],
            'config.platform.php must be the lowest supported PHP (8.3.0), not the version of '
            . 'whatever box last ran composer.',
        );
    }

    /**
     * The pin makes `composer install` insensitive to the machine. The executed
     * gate that is NOT insensitive is `vendor/composer/platform_check.php`, and
     * it only exists when `platform-check` is `true` — the Composer default is
     * `"php-only"`, which checks the PHP version and NO extensions at all.
     */
    public function testPlatformCheckIsFullyEnabled(): void
    {
        $composer = $this->composerJson();
        /** @var array<string, mixed> $config */
        $config = $composer['config'];

        self::assertTrue(
            $config['platform-check'] ?? null,
            'config.platform-check must be TRUE. At Composer\'s default ("php-only") the '
            . 'generated vendor/composer/platform_check.php checks the PHP version and not one '
            . 'extension — so config.platform would mask a missing extension at install time with '
            . 'nothing downstream to catch it.',
        );
    }

    /**
     * Guards the one place the generated check is legitimately narrower than the
     * contract, so that gap is a recorded decision instead of a surprise.
     */
    public function testGeneratedPlatformCheckCoversEveryContractExtensionExceptThePolyfilledPair(): void
    {
        $generated = self::$repoRoot . '/vendor/composer/platform_check.php';
        if (!is_file($generated)) {
            self::markTestSkipped('vendor/composer/platform_check.php is absent (no composer install).');
        }

        $source = file_get_contents($generated);
        self::assertIsString($source);

        $checked = [];
        if (preg_match_all("/extension_loaded\('([a-z0-9_]+)'\)/", $source, $matches) > 0) {
            $checked = $matches[1];
        }

        self::assertNotEmpty(
            $checked,
            'platform_check.php contains no extension_loaded() call at all. Either '
            . 'config.platform-check regressed to "php-only", or composer install has not been '
            . 're-run since it was enabled.',
        );

        $expected = array_values(array_diff(array_keys($this->contract()), self::POLYFILL_PROVIDED));
        sort($expected);
        sort($checked);

        self::assertSame(
            $expected,
            $checked,
            sprintf(
                'The generated platform check no longer matches the contract. Only %s may be '
                . 'absent from it (symfony/polyfill-* declares "provide" for them, so Composer '
                . 'omits them by design). Anything else missing means an extension is declared '
                . 'but not enforced at runtime.',
                implode(' and ', self::POLYFILL_PROVIDED),
            ),
        );
    }

    /**
     * The lock records the pin as `platform-overrides`. If that block drifts from
     * composer.json the lock was produced by a different composer.json than the
     * one in the tree, which is precisely the reproducibility hole this closes.
     */
    public function testTheLockRecordsTheSamePlatformOverrides(): void
    {
        $raw = file_get_contents(self::$repoRoot . '/composer.lock');
        self::assertIsString($raw);
        /** @var array<string, mixed> $lock */
        $lock = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray(
            $lock['platform-overrides'] ?? null,
            'composer.lock has no "platform-overrides" block, so it was generated without the '
            . 'pin. Run `composer update --lock`.',
        );

        $composer = $this->composerJson();
        /** @var array<string, mixed> $config */
        $config = $composer['config'];

        self::assertSame(
            $config['platform'],
            $lock['platform-overrides'],
            'composer.lock\'s platform-overrides differ from composer.json\'s config.platform.',
        );
    }
}
