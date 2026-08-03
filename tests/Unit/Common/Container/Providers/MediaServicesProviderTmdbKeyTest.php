<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlix\Admin\SettingsRepository;
use Phlix\Common\Container\Providers\MediaServicesProvider;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Metadata\TmdbProvider;
use PHPUnit\Framework\TestCase;

use function DI\value;

/**
 * Guards the "silently empty TMDB key" failure mode.
 *
 * ## The defect
 *
 * `TmdbProvider` is registered as a PHP-DI `factory()`, so its result is cached
 * as a PER-WORKER singleton. The factory read the admin-managed key out of
 * `server_settings` inside a `try { … } catch (\Throwable) { }` whose body was a
 * comment. On a deployment where `server_settings` is the ONLY source of the key
 * — the normal case once it is managed from the admin UI, because
 * `config/tmdb.php` resolves `api_key` to `getenv('TMDB_API_KEY') ?: ''` — ANY
 * transient failure to reach the database at the moment the worker first built
 * the provider (connection refused, auth blip, pool exhaustion) baked an EMPTY
 * key into that worker for its entire lifetime.
 *
 * The consequence is invisible: every TMDB lookup returns `[]`, which is
 * indistinguishable from "no match". Unmatched-item counts stall and NOTHING is
 * logged to say why. Observed on production 2026-07-28: a probe run without DB
 * credentials produced `hasApiKey=false`, `di_key_len=0` and silent empty search
 * results; the same probe with credentials produced `hasApiKey=true`,
 * `di_key_len=32` and real results.
 *
 * ## What is pinned here
 *
 * 1. The failure is LOGGED (with the exception class and message) rather than
 *    swallowed — so the empty key is attributable.
 * 2. The failure is NOT PERMANENT: the provider carries a re-resolver and picks
 *    the key up on a later lookup once the store answers again.
 *
 * Both are asserted for the two shapes the failure actually takes: the store
 * resolves but its query throws, and the store does not resolve from the
 * container at all.
 */
final class MediaServicesProviderTmdbKeyTest extends TestCase
{
    /** @var string|false Pre-test TMDB_API_KEY, restored in tearDown. */
    private string|false $envKeyBefore = false;

    protected function setUp(): void
    {
        // The config/env fallback reads TMDB_API_KEY. Pin it to empty so the
        // tests below control the fallback explicitly instead of inheriting
        // whatever the developer's shell exports.
        $this->envKeyBefore = getenv('TMDB_API_KEY');
        putenv('TMDB_API_KEY=');
    }

    protected function tearDown(): void
    {
        if (is_string($this->envKeyBefore)) {
            putenv('TMDB_API_KEY=' . $this->envKeyBefore);
        } else {
            putenv('TMDB_API_KEY');
        }
    }

    /**
     * Build a container with the media provider registered.
     *
     * @param SettingsRepository|null $settings   Bound as the settings store, or
     *                                           null to bind none at all (so the
     *                                           container lookup itself fails).
     * @param StructuredLogger|null   $logger     Bound as `logger.media`.
     * @param array<string, mixed>    $appConfig  Provider config (carries the
     *                                            config/env TMDB key).
     */
    private function container(
        ?SettingsRepository $settings,
        ?StructuredLogger $logger = null,
        array $appConfig = []
    ): \DI\Container {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);

        (new MediaServicesProvider())->register($builder, $appConfig);

        // Bound AFTER the provider so these win.
        $definitions = [];
        if ($settings !== null) {
            $definitions[SettingsRepository::class] = value($settings);
        }
        if ($logger !== null) {
            $definitions['logger.media'] = value($logger);
        }
        if ($definitions !== []) {
            $builder->addDefinitions($definitions);
        }

        return $builder->build();
    }

    /**
     * A settings store whose `getEffective()` throws the given exceptions in
     * order, then returns `$thenReturn` for every subsequent call.
     *
     * Models the real shape of the defect: the repository object exists (it was
     * constructed with a Connection), and it is the QUERY that fails.
     *
     * @param list<\Throwable> $throwFirst Exceptions for the leading calls.
     */
    private function flakySettings(array $throwFirst, mixed $thenReturn): SettingsRepository
    {
        $settings = $this->createMock(SettingsRepository::class);
        $call = 0;
        $settings->method('getEffective')->willReturnCallback(
            /** @return mixed */
            static function (string $key) use (&$call, $throwFirst, $thenReturn) {
                $index = $call++;
                if (isset($throwFirst[$index])) {
                    throw $throwFirst[$index];
                }

                return $key === 'tmdb.api_key' ? $thenReturn : null;
            }
        );

        return $settings;
    }

    /**
     * Read the provider's captured key. There is no getter — `hasApiKey()` only
     * reports presence — and the key is exactly the value under test.
     */
    private function keyOf(TmdbProvider $provider): string
    {
        $prop = new \ReflectionProperty(TmdbProvider::class, 'apiKey');
        $prop->setAccessible(true);

        /** @var string $key */
        $key = $prop->getValue($provider);

        return $key;
    }

    /**
     * CONSEQUENCE: a settings-store throwable must not produce a silently empty
     * provider. The catch block must LOG, naming the exception class and message.
     *
     * Goes RED against the original `catch (\Throwable) { }` whose body was a
     * comment: the mock logger's `warning()` is never called.
     */
    public function test_a_settings_failure_is_logged_not_swallowed(): void
    {
        $logger = $this->createMock(StructuredLogger::class);

        $seen = [];
        $logger->expects($this->atLeastOnce())
            ->method('warning')
            ->willReturnCallback(
                static function (string|\Stringable $message, array $context = []) use (&$seen): void {
                    $seen[] = ['message' => (string) $message, 'context' => $context];
                }
            );

        $container = $this->container(
            $this->flakySettings([new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused')], 'db-key'),
            $logger,
            ['tmdb' => ['api_key' => 'config-key']]
        );

        $container->get(TmdbProvider::class);

        $this->assertNotSame([], $seen, 'The settings failure was swallowed — nothing was logged.');

        $joined = json_encode($seen, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('TMDB', $joined, 'The warning does not identify the subsystem.');
        $this->assertStringContainsString(
            \RuntimeException::class,
            $joined,
            'The warning must carry the exception CLASS — without it the log cannot '
            . 'distinguish a connection refusal from an auth failure.'
        );
        $this->assertStringContainsString(
            'Connection refused',
            $joined,
            'The warning must carry the exception MESSAGE.'
        );
    }

    /**
     * Second shape: the settings store does not resolve from the container at
     * all (no binding, and autowiring cannot build one without a Connection).
     * The container lookup itself throws, and that too must be logged.
     */
    public function test_an_unresolvable_settings_store_is_logged_not_swallowed(): void
    {
        $logger = $this->createMock(StructuredLogger::class);
        $logger->expects($this->atLeastOnce())->method('warning');

        $container = $this->container(null, $logger, ['tmdb' => ['api_key' => 'config-key']]);

        $provider = $container->get(TmdbProvider::class);
        $this->assertInstanceOf(TmdbProvider::class, $provider);
    }

    /**
     * CONSEQUENCE: the failure must not be PERMANENT. A provider built while the
     * store was down must pick the key up on the next lookup once it recovers —
     * the worker is resident and is never rebuilt on its own.
     *
     * Goes RED before the fix: the old factory returned `new TmdbProvider($key)`
     * with no way to ever re-resolve, so `hasApiKey()` stayed false forever.
     */
    public function test_a_transient_settings_failure_is_not_permanent(): void
    {
        $container = $this->container(
            // Throws at build time; answers on every later call.
            $this->flakySettings([new \RuntimeException('pool exhausted')], 'recovered-key'),
            $this->createMock(StructuredLogger::class),
            // No config/env fallback, so an empty key here is genuinely empty —
            // exactly the production shape.
            ['tmdb' => ['api_key' => '']]
        );

        $provider = $container->get(TmdbProvider::class);

        $this->assertSame(
            '',
            $this->keyOf($provider),
            'Precondition: the build-time resolution failed, so the key starts empty.'
        );

        $this->assertTrue(
            $provider->hasApiKey(),
            'The provider never re-resolved the key. A transient DB failure at fork time '
            . 'has disabled TMDB for this worker permanently, and every lookup will '
            . 'return [] — indistinguishable from "no match".'
        );
        $this->assertSame('recovered-key', $this->keyOf($provider));
    }

    /**
     * The same singleton must serve the recovered key to LATER consumers too —
     * PHP-DI hands every consumer the one cached instance, so recovery has to
     * stick on the object rather than being recomputed per call.
     */
    public function test_the_recovered_key_sticks_on_the_cached_singleton(): void
    {
        $container = $this->container(
            $this->flakySettings([new \RuntimeException('blip')], 'recovered-key'),
            $this->createMock(StructuredLogger::class),
            ['tmdb' => ['api_key' => '']]
        );

        $first = $container->get(TmdbProvider::class);
        $this->assertTrue($first->hasApiKey());

        $second = $container->get(TmdbProvider::class);
        $this->assertSame($first, $second, 'Precondition: PHP-DI caches the factory result.');
        $this->assertSame('recovered-key', $this->keyOf($second));
    }

    /**
     * Baseline precedence, unchanged: an admin-saved override beats config/env.
     */
    public function test_an_admin_saved_key_wins_over_the_config_key(): void
    {
        $container = $this->container(
            $this->flakySettings([], 'db-key'),
            $this->createMock(StructuredLogger::class),
            ['tmdb' => ['api_key' => 'config-key']]
        );

        $this->assertSame('db-key', $this->keyOf($container->get(TmdbProvider::class)));
    }

    /**
     * …and with no override saved, the config/env key still stands. Pins that
     * the re-resolver cannot BLANK a working key: `getEffective()` returning an
     * empty override must leave the config key in place, not overwrite it.
     */
    public function test_an_empty_override_does_not_clobber_the_config_key(): void
    {
        $provider = $this->container(
            $this->flakySettings([], ''),
            $this->createMock(StructuredLogger::class),
            ['tmdb' => ['api_key' => 'config-key']]
        )->get(TmdbProvider::class);

        $this->assertSame('config-key', $this->keyOf($provider));
        $this->assertTrue($provider->hasApiKey());
    }

    /**
     * A container with no settings binding at all must still RESOLVE the
     * provider (degraded to the config/env key) rather than throw out of the
     * factory. A metadata match must never fail because settings are absent.
     */
    public function test_the_provider_resolves_without_a_settings_binding(): void
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(false);

        (new MediaServicesProvider())->register($builder, ['tmdb' => ['api_key' => 'config-key']]);

        $provider = $builder->build()->get(TmdbProvider::class);

        $this->assertInstanceOf(TmdbProvider::class, $provider);
        $this->assertSame('config-key', $this->keyOf($provider));
    }
}
