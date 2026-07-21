<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlix\Admin\SettingsRepository;
use Phlix\Common\Container\Providers\MediaServicesProvider;
use Phlix\Media\Library\ScanIgnorePatterns;
use Phlix\Media\Storage\ArtworkDownloadPolicy;
use PHPUnit\Framework\TestCase;

use function DI\value;

/**
 * Guards the "inert by construction" failure mode.
 *
 * PHP-DI SKIPS optional constructor parameters during autowiring. A policy
 * object declared as `?SettingsRepository $settings = null` that is not named
 * explicitly in the provider therefore receives NULL, silently degrades to its
 * shipped defaults, and the admin control renders, accepts a PUT and does
 * nothing forever — a fake setting created purely by wiring.
 *
 * These tests resolve the two new policy objects from a REAL container and
 * assert they OBSERVE a stubbed settings store, i.e. that the container really
 * handed the store over. Asserting `$container->has(...)` would not catch this:
 * a store-less policy resolves perfectly happily.
 *
 * The stub values below differ from the shipped defaults in both cases, so a
 * policy that quietly fell back to its literal fails rather than coincidentally
 * agreeing.
 *
 * @covers \Phlix\Common\Container\Providers\MediaServicesProvider
 */
final class MediaServicesProviderSettingsWiringTest extends TestCase
{
    /**
     * A container with the media provider registered and a stubbed
     * SettingsRepository bound.
     *
     * @param array<string, mixed> $settingValues Effective values by key.
     */
    private function containerWithSettings(array $settingValues): \DI\Container
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->willReturnCallback(
            /** @return mixed */
            static fn (string $key) => $settingValues[$key] ?? null
        );

        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);

        (new MediaServicesProvider())->register($builder, []);

        // Bind the stub AFTER the provider so it wins.
        $builder->addDefinitions([SettingsRepository::class => value($settings)]);

        return $builder->build();
    }

    /**
     * The container-built {@see ArtworkDownloadPolicy} must observe an override
     * of FALSE. The shipped default is TRUE, so a store-less instance returns
     * true here and fails.
     */
    public function test_the_container_hands_a_configured_artwork_policy(): void
    {
        $container = $this->containerWithSettings([
            ArtworkDownloadPolicy::SETTING_KEY => false,
        ]);

        $policy = $container->get(ArtworkDownloadPolicy::class);
        $this->assertInstanceOf(ArtworkDownloadPolicy::class, $policy);

        $this->assertFalse(
            $policy->downloadsEnabled(),
            'The DI-built policy ignored the settings store — the setting is inert by construction.'
        );
    }

    /**
     * Same for {@see ScanIgnorePatterns}: the override list shares no member
     * with the shipped defaults, so a store-less instance fails both assertions.
     */
    public function test_the_container_hands_a_configured_ignore_pattern_list(): void
    {
        $container = $this->containerWithSettings([
            ScanIgnorePatterns::SETTING_KEY => ['zzz-marker'],
        ]);

        $patterns = $container->get(ScanIgnorePatterns::class);
        $this->assertInstanceOf(ScanIgnorePatterns::class, $patterns);

        $this->assertSame(['zzz-marker'], $patterns->patterns());
        $this->assertTrue($patterns->matches('movie.zzz-marker.mkv'));
        $this->assertFalse(
            $patterns->matches('half.part'),
            'The DI-built list still contains a shipped default — the override did not arrive.'
        );
    }

    /**
     * A container with NO settings binding at all must still resolve both
     * policies (degraded to defaults) rather than throwing out of the factory.
     * A scan or a metadata match must never fail because settings are absent.
     */
    public function test_both_policies_resolve_without_a_settings_binding(): void
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(false);

        (new MediaServicesProvider())->register($builder, []);

        $container = $builder->build();

        $policy = $container->get(ArtworkDownloadPolicy::class);
        $this->assertInstanceOf(ArtworkDownloadPolicy::class, $policy);
        $this->assertTrue($policy->downloadsEnabled());

        $patterns = $container->get(ScanIgnorePatterns::class);
        $this->assertInstanceOf(ScanIgnorePatterns::class, $patterns);
        $this->assertSame(ScanIgnorePatterns::DEFAULT_PATTERNS, $patterns->patterns());
    }

    /**
     * Capture the definition arrays the provider registers, so the wiring can
     * be asserted directly instead of being trusted.
     *
     * @return array<string, mixed> Merged entry name => definition.
     */
    private function registeredDefinitions(): array
    {
        $spy = new class extends ContainerBuilder {
            /** @var list<array<string, mixed>> */
            public array $seen = [];

            public function addDefinitions(string|array|\DI\Definition\Source\DefinitionSource ...$definitions): ContainerBuilder
            {
                foreach ($definitions as $definition) {
                    if (is_array($definition)) {
                        $this->seen[] = $definition;
                    }
                }

                return parent::addDefinitions(...$definitions);
            }
        };

        (new MediaServicesProvider())->register($spy, []);

        return array_merge(...$spy->seen);
    }

    /**
     * Assert that `$class`'s definition binds its `$parameter` constructor
     * argument to a container reference for `$expectedEntry`.
     *
     * PHP-DI normalises `->constructorParameter('name', ...)` to the parameter's
     * POSITIONAL index, so the index is resolved by reflecting the real
     * constructor. An unnamed (merely optional) parameter is absent from the
     * definition entirely — which is exactly the defect being guarded against.
     *
     * @param array<string, mixed> $definitions
     */
    private function assertConstructorParameterBound(
        array $definitions,
        string $class,
        string $parameter,
        string $expectedEntry
    ): void {
        $this->assertArrayHasKey($class, $definitions, "{$class} is not registered.");

        $helper = $definitions[$class];
        $this->assertInstanceOf(\DI\Definition\Helper\DefinitionHelper::class, $helper);

        $definition = $helper->getDefinition($class);
        $this->assertInstanceOf(\DI\Definition\AutowireDefinition::class, $definition);

        $index = null;
        foreach ((new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $param) {
            if ($param->getName() === $parameter) {
                $index = $param->getPosition();
                break;
            }
        }
        $this->assertNotNull($index, "{$class} has no constructor parameter \${$parameter}.");

        $params = $definition->getConstructorInjection()?->getParameters() ?? [];
        $this->assertArrayHasKey(
            $index,
            $params,
            "{$class}::__construct() \${$parameter} is NOT named in MediaServicesProvider. "
            . 'PHP-DI skips optional parameters during autowiring, so it will receive null '
            . 'and the setting will be inert by construction.'
        );

        $reference = $params[$index];
        $this->assertInstanceOf(\DI\Definition\Reference::class, $reference);
        $this->assertSame($expectedEntry, $reference->getTargetEntryName());
    }

    /**
     * The scanner must NAME `ignorePatterns`, else `scanner.ignore_patterns`
     * is inert.
     */
    public function test_the_scanner_definition_names_the_ignore_patterns_parameter(): void
    {
        $this->assertConstructorParameterBound(
            $this->registeredDefinitions(),
            \Phlix\Media\Library\MediaScanner::class,
            'ignorePatterns',
            ScanIgnorePatterns::class,
        );
    }

    /**
     * The matcher must NAME `artworkDownloadPolicy`, else
     * `artwork.download_enabled` is inert.
     */
    public function test_the_matcher_definition_names_the_artwork_policy_parameter(): void
    {
        $this->assertConstructorParameterBound(
            $this->registeredDefinitions(),
            \Phlix\Media\Metadata\LibraryMetadataMatcher::class,
            'artworkDownloadPolicy',
            ArtworkDownloadPolicy::class,
        );
    }
}
