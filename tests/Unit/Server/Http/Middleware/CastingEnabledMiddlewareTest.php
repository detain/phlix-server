<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Middleware;

use Phlix\Admin\SettingsRepository;
use Phlix\Config\EffectiveConfig;
use Phlix\Server\Http\Middleware\CastingEnabledMiddleware;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Plugins\Util\RecursiveDelete;
use PHPUnit\Framework\TestCase;

/**
 * Consequence tests for the three `casting.*.enabled` switches.
 *
 * ## What is asserted
 *
 * Middleware semantics are the behaviour under test: returning `null` lets the
 * request through to the casting controller, returning a {@see Response}
 * short-circuits it. Every test asserts which of those two happened, not
 * whether a config value was read.
 *
 * The three protocols are covered INDIVIDUALLY. A single shared switch, or a
 * middleware that ignored its `$protocol` and read one hardcoded key, would
 * pass a test that only exercised Chromecast — the "half-effective setting"
 * failure this program keeps hitting — so the cross-protocol independence test
 * below is the one that makes the three-separate-switches claim real.
 *
 * ## Note on what these switches do NOT prove
 *
 * The switches are real; two of the features behind them are incomplete (Roku's
 * mDNS service string is malformed and Roku does not use mDNS for ECP anyway;
 * AirPlay never sends RTP audio). See `config/casting.php`. These tests
 * deliberately assert only that the GATE works, which is all this settings
 * change claims.
 *
 * Each test is mutation-verified; see individual docblocks.
 */
final class CastingEnabledMiddlewareTest extends TestCase
{
    /** @var list<string> minted config roots removed in tearDown (S439 zero-residue). */
    private array $mintedConfigRoots = [];

    protected function setUp(): void
    {
        parent::setUp();
        EffectiveConfig::reset();
    }

    protected function tearDown(): void
    {
        EffectiveConfig::reset();
        foreach ($this->mintedConfigRoots as $root) {
            RecursiveDelete::remove($root);
        }
        $this->mintedConfigRoots = [];
        parent::tearDown();
    }

    /**
     * A settings repository whose getEffective() answers from the given map.
     *
     * @param array<string, mixed> $values Dotted key => effective value.
     */
    private function settingsReturning(array $values): SettingsRepository
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->willReturnCallback(
            static fn (string $key): mixed => $values[$key] ?? true
        );

        return $settings;
    }

    /**
     * CONSEQUENCE: a disabled protocol is not routed to its controller.
     *
     * Asserts on the middleware contract — a Response short-circuits dispatch,
     * so the controller (and the blocking mDNS query behind it) never runs.
     *
     * Mutation-verified: inverting the `isEnabled()` check in __invoke() fails
     * this test.
     */
    public function test_a_disabled_protocol_short_circuits_the_request(): void
    {
        $middleware = new CastingEnabledMiddleware(
            'chromecast',
            $this->settingsReturning(['casting.chromecast.enabled' => false]),
        );

        $response = $middleware(new Request());

        self::assertInstanceOf(Response::class, $response, 'A disabled protocol must not reach its controller.');
        self::assertSame(404, $response->statusCode);
    }

    /**
     * CONSEQUENCE: an enabled protocol IS routed to its controller.
     *
     * A disable-only test would pass against a middleware that blocked
     * everything, so the enabled path must be shown to return null.
     *
     * Mutation-verified: hardcoding isEnabled() to false fails this.
     */
    public function test_an_enabled_protocol_passes_the_request_through(): void
    {
        $middleware = new CastingEnabledMiddleware(
            'chromecast',
            $this->settingsReturning(['casting.chromecast.enabled' => true]),
        );

        self::assertNull($middleware(new Request()), 'An enabled protocol must reach its controller.');
    }

    /**
     * CONSEQUENCE: the three switches are INDEPENDENT.
     *
     * This is the test that makes "three separate keys" real rather than
     * asserted. With only Roku switched off, Roku must be blocked while
     * Chromecast and AirPlay still pass — so a middleware that ignored its
     * $protocol, or that read one shared key, fails here even though every
     * single-protocol test above would still pass.
     *
     * Mutation-verified: hardcoding the key to 'casting.chromecast.enabled'
     * regardless of $protocol fails this test.
     */
    public function test_each_protocol_has_its_own_independent_switch(): void
    {
        $settings = $this->settingsReturning([
            'casting.chromecast.enabled' => true,
            'casting.roku.enabled'       => false,
            'casting.airplay.enabled'    => true,
        ]);

        self::assertNull(
            (new CastingEnabledMiddleware('chromecast', $settings))(new Request()),
            'Disabling Roku must not disable Chromecast.'
        );
        self::assertInstanceOf(
            Response::class,
            (new CastingEnabledMiddleware('roku', $settings))(new Request()),
            'Roku was switched off and must be blocked.'
        );
        self::assertNull(
            (new CastingEnabledMiddleware('airplay', $settings))(new Request()),
            'Disabling Roku must not disable AirPlay.'
        );
    }

    /**
     * CONSEQUENCE: with no settings repository, the overlaid config file decides.
     *
     * The route loader passes whatever `optionalSettingsRepository()` returns,
     * which is null when the container cannot supply one. The switch must still
     * work in that case rather than silently defaulting to "on".
     *
     * Mutation-verified: deleting the EffectiveConfig fallback branch (leaving
     * only `return true`) fails this test.
     */
    public function test_the_config_file_decides_when_no_settings_repository_is_available(): void
    {
        $this->bootstrapCastingConfig(['roku' => ['enabled' => false]]);

        $middleware = new CastingEnabledMiddleware('roku', null);

        self::assertInstanceOf(
            Response::class,
            $middleware(new Request()),
            'The config file must still gate the protocol when no settings store is wired.'
        );
    }

    /**
     * CONSEQUENCE: an absent key leaves casting enabled.
     *
     * `config/casting.php` is net-new, so every existing install upgrades
     * without it and without an override. A fail-closed default would silently
     * remove working endpoints on upgrade.
     *
     * BOTH shapes of "absent" are asserted, and the second one is the reason
     * this test is written the way it is. An earlier version only covered the
     * whole-file-empty case, and mutation verification showed it could not fail:
     * with no `airplay` section at all, `!is_array($section)` short-circuits and
     * returns before the `?? true` is ever evaluated, so flipping that default
     * to `?? false` left the test GREEN while claiming to pin it. The
     * section-present-but-key-missing case is the input that actually reaches
     * the branch and therefore actually discriminates.
     *
     * Mutation-verified: changing the `?? true` default to `?? false` fails
     * this test — on the second assertion, not the first.
     */
    public function test_an_absent_setting_leaves_casting_enabled(): void
    {
        $this->bootstrapCastingConfig([]);
        self::assertTrue(
            (new CastingEnabledMiddleware('airplay', null))->isEnabled(),
            'A config file with no casting sections at all must leave casting enabled.'
        );

        EffectiveConfig::reset();

        // Section present, `enabled` key missing — the input that reaches the
        // `?? true` default instead of short-circuiting on !is_array().
        $this->bootstrapCastingConfig(['airplay' => []]);
        self::assertTrue(
            (new CastingEnabledMiddleware('airplay', null))->isEnabled(),
            'A casting section with no `enabled` key must leave casting enabled.'
        );
    }

    /**
     * CONSEQUENCE: a malformed override does not silently disable casting.
     *
     * `server_settings` rows are admin-editable and have been hand-edited on
     * production before now. The guard compares against `false` explicitly
     * rather than casting.
     *
     * The inputs DISCRIMINATE: `''` and `0` are values a `(bool)` cast or a
     * bare `!` would read as OFF, so a cast-based implementation fails this
     * test rather than coincidentally passing it. Only a real `false` disables.
     */
    public function test_a_malformed_override_does_not_disable_casting(): void
    {
        foreach (['', 0, 'no', null] as $junk) {
            $middleware = new CastingEnabledMiddleware(
                'chromecast',
                $this->settingsReturning(['casting.chromecast.enabled' => $junk]),
            );

            self::assertTrue(
                $middleware->isEnabled(),
                sprintf('A %s override must not disable casting.', var_export($junk, true))
            );
        }
    }

    /**
     * CONSEQUENCE: a throwing settings store falls back rather than 500ing.
     *
     * Casting works today, so a settings-store failure must not take the
     * endpoints down with it — the same fail-open reasoning webhooks.enabled
     * and stats.enabled use.
     *
     * Mutation-verified: removing the try/catch turns this into an error.
     */
    public function test_a_failing_settings_store_falls_back_to_the_config_file(): void
    {
        $this->bootstrapCastingConfig(['chromecast' => ['enabled' => true]]);

        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->willThrowException(new \RuntimeException('db down'));

        self::assertNull(
            (new CastingEnabledMiddleware('chromecast', $settings))(new Request()),
            'A settings-store failure must not take working casting endpoints down.'
        );
    }

    /**
     * Bootstrap the overlay against a throwaway `config/casting.php`.
     *
     * @param array<string, mixed> $casting
     */
    private function bootstrapCastingConfig(array $casting): void
    {
        $dir = sys_get_temp_dir() . '/phlix_casting_' . uniqid('', true) . '/config';
        mkdir($dir, 0o777, true);
        $this->mintedConfigRoots[] = dirname($dir);
        file_put_contents($dir . '/casting.php', '<?php return ' . var_export($casting, true) . ";\n");

        $db = $this->createMock(\Workerman\MySQL\Connection::class);
        $db->method('query')->willReturn([]);
        EffectiveConfig::bootstrap($db, null, $dir);
    }
}
