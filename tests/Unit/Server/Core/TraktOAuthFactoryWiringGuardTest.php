<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Core;

use DI\ContainerBuilder;
use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Common\Database\ConnectionPool;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\Controllers\TraktOAuthController;
use Phlix\Server\Http\RequestContext;
use Phlix\Server\Integrations\Trakt\SodiumTokenCipher;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionMethod;
use ReflectionObject;
use Workerman\MySQL\Connection;

use function DI\factory;

/**
 * M-4 (security audit 2026-09-30) production-WIRING guard.
 *
 * The unit tests ({@see \Phlix\Tests\Unit\Server\Http\Controllers\TraktOAuthControllerTest})
 * prove the controller's BEHAVIOUR when handed an admin gate and an encryption
 * key. This file pins the other half of the defect: that the PRODUCTION
 * factory (`Application::getTraktOAuthController()`) actually supplies them.
 * Before the fix the factory passed neither — so the fail-closed gate and the
 * cipher existed but were dead code in every deployed install:
 *
 *  - `adminGate` unwired  → every callback 403s → fixed by wiring the
 *    container's AdminMiddleware as the gate closure;
 *  - `tokenEncryptionKey` unwired → tokens land in `plugins.settings_json`
 *    as PLAINTEXT despite the documented `token_encryption_key` config —
 *    fixed by reading it through {@see TraktOperatorConfig}-style file/env
 *    resolution at construction time.
 *
 * A factory edit that drops either argument reddens the matching assertion
 * here — the unit suite cannot see it because it constructs the controller
 * itself.
 *
 * Harness mirrors the S437/L-1L-2 production pattern: real
 * {@see ContainerFactory::defaultProviders()} container with only the MySQL
 * {@see Connection} doubled, and the real `Application` so the private
 * factory runs with its real dependencies.
 *
 * @package Phlix\Tests\Unit\Server\Core
 */
final class TraktOAuthFactoryWiringGuardTest extends TestCase
{
    private const ENV_KEY = 'TRAKT_TOKEN_ENCRYPTION_KEY';

    // Merge-lane canary (P-1); executed below so the literal survives
    // comment-stripping. Not a security assertion.
    private const LANE_SENTINEL = 'M4WIRINGGUARDX2Q8';

    private string $tempDir = '';
    private string $loggerConfigPath = '';
    private ?ContainerInterface $sharedContainer = null;
    private ?Application $sharedApplication = null;
    private string|false $priorEnvValue = false;

    protected function setUp(): void
    {
        parent::setUp();
        LoggerFactory::reset();
        RequestContext::setUserId(null);
        RequestContext::setProfileId(null);

        $this->priorEnvValue = getenv(self::ENV_KEY);
        // Determinism: the production config file reads this env at include
        // time; the "no key -> plaintext degrade" leg needs it absent.
        putenv(self::ENV_KEY);

        $this->tempDir = sys_get_temp_dir() . '/phlix_m4wiring_' . uniqid('', true);
        mkdir($this->tempDir, 0775, true);

        $this->loggerConfigPath = $this->tempDir . '/logger.php';
        file_put_contents(
            $this->loggerConfigPath,
            "<?php\nreturn [\n"
            . "    'default' => 'file',\n"
            . "    'handlers' => [\n"
            . "        'file' => [\n"
            . "            'type' => 'stream',\n"
            . "            'path' => " . var_export($this->tempDir . '/app.log', true) . ",\n"
            . "            'level' => 'debug',\n"
            . "        ],\n"
            . "    ],\n"
            . "];\n"
        );
    }

    protected function tearDown(): void
    {
        if ($this->priorEnvValue === false) {
            putenv(self::ENV_KEY);
        } else {
            putenv(self::ENV_KEY . '=' . $this->priorEnvValue);
        }

        foreach (['phlix_media_asset_jobs', 'phlix_similarity_jobs'] as $sharedQueue) {
            $sharedDir = sys_get_temp_dir() . '/' . $sharedQueue;
            if (is_dir($sharedDir)) {
                foreach (glob($sharedDir . '/*') ?: [] as $queued) {
                    @unlink($queued);
                }
                @rmdir($sharedDir);
            }
        }
        RequestContext::setUserId(null);
        RequestContext::setProfileId(null);
        LoggerFactory::reset();

        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }

        parent::tearDown();
    }

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
            'logger_config_path' => $this->loggerConfigPath,
            'db_config_path' => null,
        ], $providers);
    }

    private function application(): Application
    {
        if ($this->sharedApplication !== null) {
            return $this->sharedApplication;
        }

        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getPooledConnection')->willReturn($this->createMock(Connection::class));

        $config = ['hub' => ['config_dir' => $this->tempDir]];

        return $this->sharedApplication = new Application($this->container(), $config, $pool);
    }

    private function builtController(): TraktOAuthController
    {
        $factory = new ReflectionMethod(Application::class, 'getTraktOAuthController');
        $factory->setAccessible(true);
        $controller = $factory->invoke($this->application());

        $this->assertInstanceOf(TraktOAuthController::class, $controller);

        return $controller;
    }

    private function privateProperty(object $object, string $name): mixed
    {
        $property = (new ReflectionObject($object))->getProperty($name);
        $property->setAccessible(true);

        return $property->getValue($object);
    }

    // -----------------------------------------------------------------
    // 1. The admin gate is wired from the container's AdminMiddleware.
    // -----------------------------------------------------------------

    public function testFactoryWiresTheAdminGateClosure(): void
    {
        $gate = $this->privateProperty($this->builtController(), 'adminGate');

        $this->assertInstanceOf(
            \Closure::class,
            $gate,
            'The production factory MUST hand TraktOAuthController a callable admin gate '
            . '(M-4: the callback binds the SERVER-WIDE Trakt account). A null here means the '
            . 'wiring regressed to fail-closed-always, i.e. the Connect flow is dead.'
        );
    }

    public function testWiredGateRejectsAnAnonymousRequestWith401(): void
    {
        $gate = $this->privateProperty($this->builtController(), 'adminGate');
        $this->assertInstanceOf(\Closure::class, $gate);

        $request = new \Phlix\Server\Http\Request();
        $request->method = 'GET';
        $request->path = '/api/v1/oauth/trakt/callback';
        // NO userId: an anonymous callback must be refused by the wired gate.
        RequestContext::setUserId(null);

        $denial = $gate($request);

        $this->assertInstanceOf(\Phlix\Server\Http\Response::class, $denial);
        $this->assertSame(401, $denial->statusCode, 'The wired gate delegates to AdminMiddleware, whose '
            . 'anonymous arm is 401 auth.required; a null return here means an anonymous caller would '
            . 'reach the token-binding code (M-4).');
        $decoded = json_decode((string) $denial->body, true);
        $this->assertIsArray($decoded);
        $this->assertSame('auth.required', $decoded['code'] ?? null, 'Must reuse the REGISTERED auth '
            . 'family code (no new error-code literals).');
    }

    public function testWiredGateRejectsAnAuthenticatedNonAdminWith403(): void
    {
        $gate = $this->privateProperty($this->builtController(), 'adminGate');
        $this->assertInstanceOf(\Closure::class, $gate);

        $request = new \Phlix\Server\Http\Request();
        $request->method = 'GET';
        $request->path = '/api/v1/oauth/trakt/callback';
        // Authenticated, but the doubled Connection resolves no users row -> no admin.
        $request->userId = 'non-admin-user-1';
        RequestContext::setUserId(null);

        $denial = $gate($request);

        $this->assertInstanceOf(\Phlix\Server\Http\Response::class, $denial);
        $this->assertSame(403, $denial->statusCode, 'The M-4 defect was ANY authenticated user binding '
            . 'the server-wide Trakt account; the wired gate must 403 the authenticated non-admin arm.');
        $decoded = json_decode((string) $denial->body, true);
        $this->assertIsArray($decoded);
        $this->assertSame('auth.not_admin', $decoded['code'] ?? null);
    }

    // -----------------------------------------------------------------
    // 2. The documented token_encryption_key reaches the controller.
    // -----------------------------------------------------------------

    public function testFactoryLeavesCipherNullWhenNoKeyIsConfigured(): void
    {
        // setUp cleared TRAKT_TOKEN_ENCRYPTION_KEY and the shipped
        // config/scrobblers/trakt.php carries only the getenv fallback, so the
        // resolved key is '' -> the documented graceful degrade: cipher null,
        // tokens stored as-is, NOTHING thrown in a resident worker.
        $cipher = $this->privateProperty($this->builtController(), 'cipher');

        $this->assertNull($cipher);
    }

    public function testFactoryBuildsCipherFromConfiguredEncryptionKey(): void
    {
        // Valid 64-char hex (32 bytes) — the documented accepted encoding.
        putenv(self::ENV_KEY . '=' . bin2hex(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));

        $cipher = $this->privateProperty($this->builtController(), 'cipher');

        $this->assertInstanceOf(
            SodiumTokenCipher::class,
            $cipher,
            'With the documented TRAKT_TOKEN_ENCRYPTION_KEY set, tokens MUST encrypt at rest '
            . '(M-4b). A null cipher here means the factory stopped reading the config.'
        );
    }

    public function testLaneSentinelIsResidentInCode(): void
    {
        $this->assertMatchesRegularExpression('/^[A-Z0-9_]{16,32}$/', self::LANE_SENTINEL);
    }
}
