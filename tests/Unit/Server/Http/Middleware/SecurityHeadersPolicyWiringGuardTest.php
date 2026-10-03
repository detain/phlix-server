<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Middleware;

use DI\ContainerBuilder;
use Phlix\Admin\SettingsRepository;
use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Server\Http\Middleware\SecurityHeadersPolicy;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use Workerman\MySQL\Connection;

use function DI\factory;

/**
 * W4 — the PRODUCTION wiring of the security-header policy.
 *
 * Two halves, one for each side of the seam that can rot silently:
 *
 *  1. the CONTAINER half (AuthMethodPolicyWiringGuardTest idiom): the
 *     production container must yield a SecurityHeadersPolicy whose $settings
 *     is a real SettingsRepository — a factory that lost its thread would hand
 *     HttpHandler a store-less policy, freezing both headers at their defaults
 *     while every hand-built unit test passes the store positionally;
 *  2. the CONSUMER half (structural): HttpHandler::__invoke and the 429 branch
 *     must both go through the single memoised securityHeaders() accessor.
 *     A reintroduced bare `new SecurityHeaders()` beside the accessor would
 *     silently bypass the settings on whichever branch kept it — the exact
 *     "two entries diverge" class F16 was about.
 */
final class SecurityHeadersPolicyWiringGuardTest extends TestCase
{
    private ?ContainerInterface $sharedContainer = null;

    public function testTheContainerComposesTheSecurityHeadersPolicy(): void
    {
        $policy = $this->container()->get(SecurityHeadersPolicy::class);

        self::assertInstanceOf(SecurityHeadersPolicy::class, $policy);

        $settings = (new ReflectionClass(SecurityHeadersPolicy::class))->getProperty('settings');
        $settings->setAccessible(true);

        self::assertInstanceOf(
            SettingsRepository::class,
            $settings->getValue($policy),
            'SecurityHeadersPolicy::$settings is not a store in the production container — '
            . 'CoreServicesProvider lost the SettingsRepository thread, so admin overrides of '
            . 'security.hsts_max_age_seconds / security.frame_options would be inert.',
        );
    }

    public function testHttpHandlerFunnelsthroughthesingleaccessoratbothsites(): void
    {
        $source = file_get_contents(dirname(__DIR__, 5) . '/src/Server/Workerman/HttpHandler.php');
        self::assertIsString($source);

        // The accessor is defined once and called twice: the main dispatch
        // (CORS/preflight seam where $securityHeaders is assigned) and the
        // SV-4.15 F4 429 branch.
        self::assertSame(
            2,
            substr_count($source, '$this->securityHeaders()'),
            'HttpHandler must reach the decorator ONLY via securityHeaders() — twice '
            . '(main path + 429 branch). Any bare `new SecurityHeaders()` reintroduced '
            . 'beside it bypasses the settings on that branch.',
        );

        // The ONLY `new SecurityHeaders(` in the file lives INSIDE the accessor
        // (fed by the policy) — a second construction site is the divergence.
        self::assertSame(
            1,
            substr_count($source, 'new SecurityHeaders('),
            'Exactly one SecurityHeaders construction (the memoised accessor) may exist.',
        );

        self::assertStringNotContainsString(
            'new SecurityHeaders()',
            $source,
            'A store-less SecurityHeaders in HttpHandler emits pre-key defaults on its branch.',
        );
    }

    /**
     * The PRODUCTION container: `ContainerFactory::defaultProviders()`, with only
     * the MySQL {@see Connection} doubled.
     */
    private function container(): ContainerInterface
    {
        if ($this->sharedContainer !== null) {
            return $this->sharedContainer;
        }

        $connection = $this->createMock(Connection::class);

        $providers = ContainerFactory::defaultProviders();
        $providers[] = new class ($connection) implements ServiceProviderInterface {
            public function __construct(private Connection $connection)
            {
            }

            public function register(ContainerBuilder $builder, array $appConfig): void
            {
                $connection = $this->connection;

                $builder->addDefinitions([
                    Connection::class => factory(static fn (): Connection => $connection),
                ]);
            }
        };

        return $this->sharedContainer = ContainerFactory::create([
            'logger_config_path' => dirname(__DIR__, 5) . '/config/logger.php',
            'db_config_path' => null,
        ], $providers);
    }
}
