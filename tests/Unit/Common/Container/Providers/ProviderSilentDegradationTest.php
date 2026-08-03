<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlix\Admin\SettingsRepository;
use Phlix\Common\Container\Providers\MediaServicesProvider;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Library\ScanIgnorePatterns;
use Phlix\Media\Metadata\Resolution\PriorityConfig;
use Phlix\Media\Metadata\TitleSuffixStripper;
use Phlix\Media\Storage\ArtworkDownloadPolicy;
use PHPUnit\Framework\TestCase;

use function DI\value;

/**
 * A service provider that falls back must SAY so.
 *
 * ## The defect class
 *
 * Provider factories legitimately swallow failures — a settings store that is
 * down must not stop a scan. The problem was that the swallow was silent, and
 * PHP-DI caches a `factory()` result per worker, so whatever was decided during
 * a momentary outage is frozen for that worker's entire lifetime. An admin's
 * saved value then stays ignored with nothing anywhere to say why.
 *
 * The TMDB API key was the case that made it concrete (see
 * {@see MediaServicesProviderTmdbKeyTest}); these are its siblings.
 *
 * ## The other half: silence on the NORMAL path
 *
 * A rule that fires when nothing is wrong gets switched off, so these tests pin
 * both directions — a bound-but-broken store warns, and an absent store (a
 * perfectly normal shape for the unit containers throughout this suite) does
 * NOT.
 */
final class ProviderSilentDegradationTest extends TestCase
{
    /**
     * @param list<array{message: string, context: array<string, mixed>}> $sink
     */
    private function recordingLogger(array &$sink): StructuredLogger
    {
        $logger = $this->createMock(StructuredLogger::class);
        $logger->method('warning')->willReturnCallback(
            static function (string|\Stringable $message, array $context = []) use (&$sink): void {
                $sink[] = ['message' => (string) $message, 'context' => $context];
            }
        );

        return $logger;
    }

    /**
     * A container with the media provider registered.
     *
     * @param list<array{message: string, context: array<string, mixed>}> $sink
     * @param bool $bindSettings Whether to bind a settings store AT ALL.
     */
    private function container(array &$sink, bool $bindSettings): \DI\Container
    {
        $builder = new ContainerBuilder();
        // Autowiring OFF when no settings store is wanted: with it ON, PHP-DI
        // autowires SettingsRepository itself and then fails building its
        // Connection, which is a DIFFERENT condition (defined-but-unbuildable)
        // covered by its own test below. Mirrors the established "no settings
        // store" shape in MediaServicesProviderSettingsWiringTest.
        $builder->useAutowiring($bindSettings);

        (new MediaServicesProvider())->register($builder, []);

        $definitions = ['logger.media' => value($this->recordingLogger($sink))];

        if ($bindSettings) {
            // The realistic shape: the repository object exists, and it is the
            // QUERY that fails (connection refused, auth blip, pool exhaustion).
            $settings = $this->createMock(SettingsRepository::class);
            $settings->method('getEffective')
                ->willThrowException(new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused'));
            $definitions[SettingsRepository::class] = value($settings);
        }

        $builder->addDefinitions($definitions);

        return $builder->build();
    }

    /**
     * @param list<array{message: string, context: array<string, mixed>}> $sink
     */
    private function assertWarnedAbout(array $sink, string $needle): void
    {
        $joined = json_encode($sink, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString(
            $needle,
            $joined,
            "No warning mentioned '{$needle}'. The fallback is silent, so an admin-saved "
            . 'value is being ignored with nothing to explain it.'
        );
        $this->assertStringContainsString(
            \RuntimeException::class,
            $joined,
            'The warning does not name the exception class.'
        );
    }

    /**
     * CONSEQUENCE: `matching.noise_suffixes` falling back to the in-code list is
     * reported. The default list is otherwise indistinguishable from "the admin
     * never set one".
     */
    public function test_a_failed_noise_suffix_read_is_reported(): void
    {
        $seen = [];
        $suffixes = $this->container($seen, true)->get('matching.noise_suffixes');

        $this->assertSame(TitleSuffixStripper::NOISE_SUFFIXES, $suffixes, 'Precondition: it fell back.');
        $this->assertWarnedAbout($seen, 'matching.noise_suffixes');
    }

    /**
     * CONSEQUENCE: the provider-priority / genres-mode fallback is reported.
     */
    public function test_a_failed_priority_config_read_is_reported(): void
    {
        $seen = [];
        $config = $this->container($seen, true)->get(PriorityConfig::class);

        $this->assertInstanceOf(PriorityConfig::class, $config, 'Precondition: it still built.');
        $this->assertWarnedAbout($seen, 'metadata.provider_priority');
    }

    /**
     * THE SCOPE BOUNDARY, pinned deliberately.
     *
     * `optionalSettings()` reports a store it could not RESOLVE. It does not —
     * and must not — report a store that resolves fine but whose QUERY later
     * fails: that is handled inside the consumers
     * ({@see ArtworkDownloadPolicy::downloadsEnabled()} and
     * {@see ScanIgnorePatterns}), which have their own deliberate fallbacks.
     *
     * Those are per-call runtime paths — `downloadsEnabled()` runs once per
     * persisted item — so logging there would emit a line per item across a
     * whole library. That is precisely the shape of rule that gets switched off,
     * which is why the container-build sites are logged and the per-call sites
     * are not.
     */
    public function test_a_failing_query_is_left_to_the_consumer_and_not_logged_per_call(): void
    {
        $seen = [];
        $container = $this->container($seen, true);

        $policy = $container->get(ArtworkDownloadPolicy::class);
        $this->assertInstanceOf(ArtworkDownloadPolicy::class, $policy);

        // The store RESOLVED (it is bound); only its query throws.
        $this->assertTrue(
            $policy->downloadsEnabled(),
            'The policy must degrade to its shipped default when the query fails.'
        );

        $this->assertSame(
            [],
            array_values(array_filter(
                $seen,
                static fn (array $w): bool => str_contains($w['message'], 'settings store is bound')
            )),
            'optionalSettings() reported a query failure. It resolves the store; the '
            . 'per-call query path belongs to the consumer, and logging it here would '
            . 'emit one line per persisted item.'
        );
    }

    /**
     * A degraded build must still BUILD. Reporting the failure must not turn a
     * survivable outage into a dead container.
     */
    public function test_every_affected_entry_still_resolves_while_the_store_is_down(): void
    {
        $seen = [];
        $container = $this->container($seen, true);

        $this->assertIsArray($container->get('matching.noise_suffixes'));
        $this->assertInstanceOf(PriorityConfig::class, $container->get(PriorityConfig::class));
        $this->assertInstanceOf(ArtworkDownloadPolicy::class, $container->get(ArtworkDownloadPolicy::class));
        $this->assertInstanceOf(ScanIgnorePatterns::class, $container->get(ScanIgnorePatterns::class));
    }

    /**
     * THE NOISE CONTROL, and the reason `optionalSettings()` checks `has()`
     * before `get()`: a container with NO settings binding is a normal shape —
     * most unit containers in this suite are exactly that — and must stay
     * SILENT. A warning here would fire on every such container and the rule
     * would deservedly be switched off.
     */
    public function test_an_absent_settings_binding_is_silent(): void
    {
        $seen = [];
        $container = $this->container($seen, false);

        $container->get('matching.noise_suffixes');
        $container->get(PriorityConfig::class);
        $container->get(ArtworkDownloadPolicy::class);
        $container->get(ScanIgnorePatterns::class);

        $this->assertSame(
            [],
            $seen,
            'A container with no settings store logged a degradation warning. That is the '
            . 'normal shape for unit containers, so this rule would fire constantly and be '
            . 'switched off.'
        );
    }

    /**
     * The OTHER side of the distinction: a settings store that this container
     * is meant to have but cannot BUILD (here: autowirable, but its Connection
     * is unavailable) IS a real degradation and must be reported.
     *
     * This is why the guard tests the exception TYPE rather than calling
     * `$c->has()`: with autowiring enabled `has()` answers TRUE for any
     * instantiable class, so it cannot tell this case apart from the silent one
     * above.
     */
    public function test_a_bound_but_unbuildable_settings_store_is_reported(): void
    {
        $seen = [];

        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        (new MediaServicesProvider())->register($builder, []);
        $builder->addDefinitions(['logger.media' => value($this->recordingLogger($seen))]);
        $container = $builder->build();

        $policy = $container->get(ArtworkDownloadPolicy::class);

        $this->assertInstanceOf(ArtworkDownloadPolicy::class, $policy, 'It must still build.');
        $this->assertNotSame(
            [],
            $seen,
            'A settings store that could not be built was swallowed silently.'
        );
    }

    /**
     * A HEALTHY store must also be silent — the warning must key off failure,
     * not merely off "an override was absent".
     */
    public function test_a_healthy_settings_store_is_silent(): void
    {
        $seen = [];

        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        (new MediaServicesProvider())->register($builder, []);

        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->willReturn(null);

        $builder->addDefinitions([
            'logger.media' => value($this->recordingLogger($seen)),
            SettingsRepository::class => value($settings),
        ]);
        $container = $builder->build();

        $container->get('matching.noise_suffixes');
        $container->get(PriorityConfig::class);
        $container->get(ArtworkDownloadPolicy::class);

        $this->assertSame([], $seen, 'A healthy store produced a degradation warning.');
    }
}
