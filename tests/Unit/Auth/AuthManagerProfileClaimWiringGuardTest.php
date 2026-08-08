<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Auth;

use DI\ContainerBuilder;
use Phlix\Auth\AuthManager;
use Phlix\Auth\JwtHandler;
use Phlix\Auth\UserProfileManager;
use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Container\ServiceProviderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use Workerman\MySQL\Connection;

use function DI\factory;

/**
 * S80 — the PRODUCTION DI wiring of the token `profile_id` claim.
 *
 * ## The silent degradation this closes
 *
 * `AuthManager::$profileManager` is the collaborator that stamps
 * {@see JwtHandler::CLAIM_PROFILE_ID} onto every minted token and re-verifies it
 * on every `validateAccessToken()`. It is an OPTIONAL constructor parameter,
 * because `AuthManager`'s constructor already carries nine of them and dozens of
 * tests build it by hand.
 *
 * PHP-DI's `autowire()` **skips optional constructor parameters**. So unless
 * `AuthServicesProvider` names this one explicitly — exactly as it already has to
 * for `settingsRepository`, `providerManager`, `statsCollector` and
 * `loginRateLimitStore` — the container hands back an `AuthManager` whose
 * `$profileManager` is null. Nothing throws. Every token is simply minted without
 * a profile claim, every session silently falls back to the account-wide
 * `user_profiles.is_active` flag, and **S80 is inert with a fully green suite** —
 * including its own integration suite, which constructs the manager by hand and
 * therefore cannot see this at all.
 *
 * That is the same class of defect as `project_di_provider_silent_degradation`
 * and as the `UserProfileManager::$settings` note in `AuthServicesProvider`.
 *
 * **A test that builds the object by hand cannot prove the container builds it.**
 * So this file never calls `new AuthManager(...)`.
 */
final class AuthManagerProfileClaimWiringGuardTest extends TestCase
{
    private ?ContainerInterface $sharedContainer = null;

    /**
     * The container-composed `AuthManager` carries a real profile resolver.
     */
    public function testTheContainerComposedAuthManagerCarriesAProfileResolver(): void
    {
        $authManager = $this->container()->get(AuthManager::class);

        $this->assertInstanceOf(AuthManager::class, $authManager);

        $reflected = (new ReflectionClass(AuthManager::class))->getProperty('profileManager');
        $reflected->setAccessible(true);
        $profileManager = $reflected->getValue($authManager);

        $this->assertNotNull(
            $profileManager,
            'AuthManager::$profileManager is null after container resolution. PHP-DI skipped the '
            . 'optional parameter, so no token will ever carry a profile_id claim and every '
            . 'session silently reverts to the account-wide is_active flag — S80 inert, suite green.'
        );
        $this->assertInstanceOf(UserProfileManager::class, $profileManager);
    }

    /**
     * The claim constant is what the provider wiring exists to make reachable, so
     * pin its literal value: renaming it would silently invalidate every token
     * already in the wild, and a claim named something else is not the claim the
     * clients and the hub relay were told about.
     */
    public function testTheProfileClaimNameIsStable(): void
    {
        $this->assertSame('profile_id', JwtHandler::CLAIM_PROFILE_ID);
    }

    /**
     * `createRefreshToken()` must keep accepting extra claims.
     *
     * If it lost the parameter, the access token would still carry the profile and
     * the integration suite's login assertions would stay green — but the FIRST
     * refresh, an hour into a session, would silently drop the device back to the
     * account default. That is a bug with a one-hour fuse and no error.
     */
    public function testRefreshTokensCanStillCarryClaims(): void
    {
        $constructor = (new ReflectionClass(JwtHandler::class))->getMethod('createRefreshToken');

        $names = [];
        foreach ($constructor->getParameters() as $parameter) {
            $names[] = $parameter->getName();
        }

        $this->assertContains(
            'claims',
            $names,
            'createRefreshToken() must accept claims, or a refresh loses the session profile'
        );
    }

    // ---- helpers -------------------------------------------------------------

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
            'logger_config_path' => dirname(__DIR__, 3) . '/config/logger.php',
            'db_config_path' => null,
        ], $providers);
    }
}
