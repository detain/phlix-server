<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Container;

use DI\ContainerBuilder;
use Phlix\Admin\BackupManager;
use Phlix\Auth\AuthManager;
use Phlix\Auth\DbLoginRateLimitStore;
use Phlix\Auth\JwtHandler;
use Phlix\Auth\UserRepository;
use Phlix\Auth\WatchHistory;
use Phlix\Auth\WebAuthn\WebAuthnCredentialRepository;
use Phlix\Auth\WebAuthn\WebAuthnManager;
use Phlix\Media\Library\MediaScanner;
use Phlix\Media\Library\RatingGate;
use Phlix\Media\Markers\Detection\BackgroundDetectorWorker;
use Phlix\Media\MediaAsset\MediaAssetJobStore;
use Phlix\Media\Metadata\FuzzyMatcher;
use Phlix\Media\Metadata\LibraryMetadataMatcher;
use Phlix\Media\Metadata\MetadataManager;
use Phlix\Media\SimilarityJobStore;
use Phlix\Media\SimilarityWorker;
use Phlix\Media\Storage\ArtworkStorage;
use Phlix\Playlists\SmartPlaylistRefreshHandler;
use Phlix\Server\Http\Controllers\Admin\AdminUserController;
use Phlix\Server\Http\Controllers\MediaUserDataController;
use Phlix\Webhooks\WebhookService;
use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Container\Providers\AdminServicesProvider;
use Phlix\Common\Container\Providers\AuthServicesProvider;
use Phlix\Common\Container\Providers\CoreServicesProvider;
use Phlix\Common\Container\Providers\EventServicesProvider;
use Phlix\Common\Container\Providers\MediaServicesProvider;
use Phlix\Common\Container\Providers\PluginsProvider;
use Phlix\Common\Container\Providers\SessionServicesProvider;
use Phlix\Common\Container\Providers\ThemingServicesProvider;
use Phlix\Common\Container\Providers\WebPortalServicesProvider;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Library\LibraryManager;
use Phlix\Tests\Fixtures\Container\CircularA;
use Phlix\Tests\Fixtures\Container\CircularB;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;
use Workerman\MySQL\Connection;

use function DI\factory;

/**
 * Unit tests for {@see ContainerFactory}.
 *
 * The tests stub the database {@see Connection} and the logger config so the
 * container can be exercised in isolation without touching MySQL or the
 * filesystem-bound logger handlers.
 */
final class ContainerFactoryTest extends TestCase
{
    /** @var string Path to an isolated logger config that writes to a temp dir. */
    private string $loggerConfigPath = '';

    /** @var string Temp directory backing $loggerConfigPath. */
    private string $tempDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        LoggerFactory::reset();

        $this->tempDir = sys_get_temp_dir() . '/phlix_container_' . uniqid('', true);
        mkdir($this->tempDir, 0775, true);

        $configContents = "<?php\nreturn [\n"
            . "    'default' => 'file',\n"
            . "    'handlers' => [\n"
            . "        'file' => [\n"
            . "            'type' => 'stream',\n"
            . "            'path' => " . var_export($this->tempDir . '/app.log', true) . ",\n"
            . "            'level' => 'debug',\n"
            . "        ],\n"
            . "    ],\n"
            . "];\n";
        $this->loggerConfigPath = $this->tempDir . '/logger.php';
        file_put_contents($this->loggerConfigPath, $configContents);

        putenv('JWT_SECRET');
        putenv('PHLIX_CONTAINER_COMPILE');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        LoggerFactory::reset();
        $this->rmdir($this->tempDir);
    }

    private function rmdir(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        $entries = scandir($dir) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * @return array<string, mixed>
     */
    private function baseConfig(): array
    {
        return [
            'logger_config_path' => $this->loggerConfigPath,
            'db_config_path' => null,
        ];
    }

    /**
     * Build a container with the canonical providers but with the
     * MySQL Connection rebound to a PHPUnit mock so resolving the
     * database does not touch a real DB server.
     */
    private function containerWithMockedDb(): ContainerInterface
    {
        $mockConnection = $this->createMock(Connection::class);

        $providers = ContainerFactory::defaultProviders();
        $providers[] = new class ($mockConnection) implements ServiceProviderInterface {
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

        return ContainerFactory::create($this->baseConfig(), $providers);
    }

    public function test_create_returns_psr_container(): void
    {
        $container = ContainerFactory::create([]);
        $this->assertInstanceOf(ContainerInterface::class, $container);
    }

    public function test_resolves_jwt_handler_with_env_secret(): void
    {
        putenv('JWT_SECRET=xyz-secret-from-env');

        $container = ContainerFactory::create($this->baseConfig());

        /** @var JwtHandler $handler */
        $handler = $container->get(JwtHandler::class);
        $this->assertSame('xyz-secret-from-env', $this->readPrivate($handler, 'secretKey'));
    }

    public function test_resolves_jwt_handler_with_default_secret_when_env_missing(): void
    {
        putenv('JWT_SECRET');

        $container = ContainerFactory::create($this->baseConfig());

        /** @var JwtHandler $handler */
        $handler = $container->get(JwtHandler::class);
        $this->assertSame(
            AuthServicesProvider::DEFAULT_JWT_SECRET,
            $this->readPrivate($handler, 'secretKey')
        );
    }

    public function test_resolves_auth_manager_with_dependencies_wired(): void
    {
        $container = $this->containerWithMockedDb();

        /** @var AuthManager $manager */
        $manager = $container->get(AuthManager::class);

        $this->assertInstanceOf(JwtHandler::class, $this->readPrivate($manager, 'jwtHandler'));
        $this->assertInstanceOf(
            StructuredLogger::class,
            $this->readPrivate($manager, 'logger')
        );
    }

    public function test_resolves_singleton_returns_same_instance(): void
    {
        $container = $this->containerWithMockedDb();

        $first  = $container->get(LibraryManager::class);
        $second = $container->get(LibraryManager::class);

        $this->assertSame($first, $second);
    }

    public function test_get_unknown_id_throws_psr_not_found_exception(): void
    {
        $container = ContainerFactory::create($this->baseConfig());

        $this->expectException(NotFoundExceptionInterface::class);
        $container->get('definitely.not.a.real.binding');
    }

    public function test_get_with_circular_dependency_throws(): void
    {
        $container = ContainerFactory::create($this->baseConfig());

        $this->expectException(\Throwable::class);
        $container->get(CircularA::class);
        $container->get(CircularB::class);
    }

    public function test_db_connection_factory_resolves_via_connection_pool(): void
    {
        $mockConnection = $this->createMock(Connection::class);

        $providers = ContainerFactory::defaultProviders();
        $providers[] = new class ($mockConnection) implements ServiceProviderInterface {
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

        $container = ContainerFactory::create($this->baseConfig(), $providers);

        $this->assertSame($mockConnection, $container->get(Connection::class));
    }

    public function test_default_providers_returns_canonical_stack(): void
    {
        $providers = ContainerFactory::defaultProviders();

        $this->assertCount(15, $providers);
        $this->assertInstanceOf(CoreServicesProvider::class, $providers[0]);
        // DlnaServicesProvider (added 1.3.0) registers DlnaServer/CdsServer.
        // Without it CdsServer cannot resolve at all and every DLNA browse
        // route silently fails to register — the defect it was added to fix.
        $this->assertInstanceOf(
            \Phlix\Common\Container\Providers\DlnaServicesProvider::class,
            $providers[14],
        );
        $this->assertInstanceOf(EventServicesProvider::class, $providers[1]);
        $this->assertInstanceOf(AuthServicesProvider::class, $providers[2]);
        $this->assertInstanceOf(\Phlix\Common\Container\Providers\HubServicesProvider::class, $providers[3]);
        $this->assertInstanceOf(MediaServicesProvider::class, $providers[4]);
        $this->assertInstanceOf(\Phlix\Common\Container\Providers\MetricsServicesProvider::class, $providers[5]);
        $this->assertInstanceOf(\Phlix\Common\Container\Providers\TranscodeServicesProvider::class, $providers[6]);
        $this->assertInstanceOf(\Phlix\Common\Container\Providers\NetworkServicesProvider::class, $providers[7]);
        $this->assertInstanceOf(SessionServicesProvider::class, $providers[8]);
        $this->assertInstanceOf(\Phlix\Common\Container\Providers\LiveTvServicesProvider::class, $providers[9]);
        $this->assertInstanceOf(WebPortalServicesProvider::class, $providers[10]);
        $this->assertInstanceOf(AdminServicesProvider::class, $providers[11]);
        $this->assertInstanceOf(PluginsProvider::class, $providers[12]);
        $this->assertInstanceOf(ThemingServicesProvider::class, $providers[13]);
    }

    public function test_resolves_hls_streamer_with_config_overrides(): void
    {
        $container = ContainerFactory::create([
            'logger_config_path' => $this->loggerConfigPath,
            'hls' => [
                'segment_dir' => $this->tempDir . '/segments',
                'base_url' => 'https://example.test/stream',
            ],
        ]);

        $streamer = $container->get(\Phlix\Media\Streaming\HlsStreamer::class);
        $this->assertInstanceOf(\Phlix\Media\Streaming\HlsStreamer::class, $streamer);
        $this->assertSame(
            $this->tempDir . '/segments',
            $this->readPrivate($streamer, 'segmentDir')
        );
        $this->assertSame(
            'https://example.test/stream',
            $this->readPrivate($streamer, 'baseUrl')
        );
    }

    public function test_resolves_web_portal_template_dir_from_config(): void
    {
        $customDir = $this->tempDir . '/my-templates';
        @mkdir($customDir, 0775, true);

        $providers = ContainerFactory::defaultProviders();
        $mockConnection = $this->createMock(Connection::class);
        $providers[] = new class ($mockConnection) implements ServiceProviderInterface {
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

        $container = ContainerFactory::create([
            'logger_config_path' => $this->loggerConfigPath,
            'db_config_path' => null,
            'web_portal' => ['template_dir' => $customDir],
        ], $providers);

        // The Smarty page UI retired (D-SRV-DEL); the template dir is still
        // exposed as a container string for the remaining consumer(s).
        $resolved = $container->get('web_portal.template_dir');
        $this->assertSame($customDir, $resolved);
    }

    public function test_resolves_web_portal_default_template_dir_when_config_missing(): void
    {
        $mockConnection = $this->createMock(Connection::class);

        $providers = ContainerFactory::defaultProviders();
        $providers[] = new class ($mockConnection) implements ServiceProviderInterface {
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

        $container = ContainerFactory::create($this->baseConfig(), $providers);

        $resolved = $container->get('web_portal.template_dir');
        $this->assertIsString($resolved);
        $this->assertStringEndsWith(
            DIRECTORY_SEPARATOR . WebPortalServicesProvider::DEFAULT_TEMPLATE_DIR,
            (string) $resolved
        );
    }

    public function test_logger_aliases_resolve_to_structured_loggers(): void
    {
        $container = ContainerFactory::create($this->baseConfig());

        foreach (CoreServicesProvider::channels() as $alias => $_channel) {
            $this->assertInstanceOf(
                StructuredLogger::class,
                $container->get($alias),
                "alias {$alias} should resolve to StructuredLogger"
            );
        }
    }

    public function test_compile_dir_created_when_flag_enabled(): void
    {
        $compileDir = $this->tempDir . '/compiled';
        putenv('PHLIX_CONTAINER_COMPILE=1');

        try {
            // PHP-DI 7 cannot compile closures that capture `use` variables;
            // the current provider style relies on closures, so we only
            // assert here that the factory honours the flag by creating
            // the compile directory and (so as not to depend on the
            // compiler succeeding) suppress the inner failure. The compile
            // path is exercised end-to-end in `ContainerFactoryCompileTest`
            // once Phase B replaces closure factories with invokable
            // classes.
            try {
                ContainerFactory::create([
                    'logger_config_path' => $this->loggerConfigPath,
                    'compile_dir' => $compileDir,
                ]);
            } catch (\Throwable $e) {
                $this->assertStringContainsString('compile', strtolower($e->getMessage()));
            }
            $this->assertDirectoryExists($compileDir);
        } finally {
            putenv('PHLIX_CONTAINER_COMPILE');
        }
    }

    /**
     * SV-1.10: the DB-backed login rate-limit store must be DI-injected into
     * the production AuthManager. PHP-DI silently skips optional defaulted ctor
     * params unless named, so a missing `->constructorParameter('loginRateLimitStore', …)`
     * leaves the field null and the server falls back to the unbounded per-worker
     * static array. This asserts the real container wires the DB store.
     */
    public function test_auth_manager_wires_login_rate_limit_store_in_prod(): void
    {
        $container = $this->containerWithMockedDb();

        /** @var AuthManager $manager */
        $manager = $container->get(AuthManager::class);

        $this->assertInstanceOf(
            DbLoginRateLimitStore::class,
            $this->readPrivate($manager, 'loginRateLimitStore'),
            'AuthManager must resolve with a DB-backed login rate-limit store, '
            . 'not the unbounded static fallback.'
        );
    }

    /**
     * SV-2.7: the AuthManager user-status cache (5s TTL) is invalidated by
     * AdminUserController on approve/disable/reject/delete so an in-process
     * status change takes effect on this worker's very next request instead
     * of waiting out the TTL. The controller's ctor takes
     * `?AuthManager $authManager = null`; PHP-DI silently skips optional
     * defaulted params unless named, so a missing
     * `->constructorParameter('authManager', …)` leaves the field null and
     * those admin actions would never invalidate the cache. This asserts the
     * real container wires AuthManager into the controller.
     */
    public function test_admin_user_controller_wires_auth_manager_in_prod(): void
    {
        $container = $this->containerWithMockedDb();

        /** @var AdminUserController $controller */
        $controller = $container->get(AdminUserController::class);

        $this->assertInstanceOf(
            AuthManager::class,
            $this->readPrivate($controller, 'authManager'),
            'AdminUserController must resolve with an AuthManager so status-changing '
            . 'actions invalidate the in-worker user-status cache immediately.'
        );
    }

    /**
     * SV-1.3: the media-asset (chapter-thumbnail + trickplay) job store must be
     * DI-injected into the production MediaScanner. Without the named ctor param
     * the store stays null, the scanner's enqueue guard is never true, and those
     * assets are never generated in prod (inline generation was removed).
     */
    public function test_media_scanner_wires_media_asset_job_store_in_prod(): void
    {
        $container = $this->containerWithMockedDb();

        /** @var MediaScanner $scanner */
        $scanner = $container->get(MediaScanner::class);

        $this->assertInstanceOf(
            MediaAssetJobStore::class,
            $this->readPrivate($scanner, 'mediaAssetJobStore'),
            'MediaScanner must resolve with a media-asset job store so chapter '
            . 'thumbnails + trickplay are enqueued.'
        );
    }

    /**
     * SV-2.9: the similarity job store must be DI-injected into the production
     * MediaScanner so per-item similarity is deferred to a background job rather
     * than run inline (O(N²)) — or silently skipped when unwired.
     */
    public function test_media_scanner_wires_similarity_job_store_in_prod(): void
    {
        $container = $this->containerWithMockedDb();

        /** @var MediaScanner $scanner */
        $scanner = $container->get(MediaScanner::class);

        $this->assertInstanceOf(
            SimilarityJobStore::class,
            $this->readPrivate($scanner, 'similarityJobStore'),
            'MediaScanner must resolve with a similarity job store so the '
            . 'similarity enqueue path is reachable in prod.'
        );
    }

    /**
     * SV-2.9: the similarity CONSUMER (SimilarityWorker) must be resolvable from
     * the production container so start.php's managed-worker fork can build and
     * run it. Without a buildable worker the scanner's similarity enqueue would
     * accumulate undrained on disk (leak). This asserts the factory wiring (store
     * + SimilarityService deps) resolves and shares the SAME store the scanner
     * enqueues into.
     */
    public function test_container_resolves_similarity_worker_in_prod(): void
    {
        $container = $this->containerWithMockedDb();

        /** @var SimilarityWorker $worker */
        $worker = $container->get(SimilarityWorker::class);
        $this->assertInstanceOf(SimilarityWorker::class, $worker);

        // The worker and the scanner must drain/enqueue the SAME store instance.
        $this->assertSame(
            $container->get(SimilarityJobStore::class),
            $this->readPrivate($worker, 'store'),
            'SimilarityWorker must consume the same SimilarityJobStore the scanner enqueues into.'
        );
    }

    /**
     * SV-3.4: the local-artwork cache (ArtworkStorage) must be DI-injected into
     * the production LibraryMetadataMatcher. The matcher's ctor takes
     * `?ArtworkStorage $artworkStorage = null`; PHP-DI silently skips optional
     * defaulted params unless named, so a missing
     * `->constructorParameter('artworkStorage', …)` leaves the field null and
     * `cacheArtworkLocally()` is a no-op — TMDB posters are never downloaded or
     * resized locally and `poster_srcset` is never emitted. This asserts the
     * real container wires the artwork store.
     */
    public function test_library_metadata_matcher_wires_artwork_storage_in_prod(): void
    {
        $container = $this->containerWithMockedDb();

        /** @var LibraryMetadataMatcher $matcher */
        $matcher = $container->get(LibraryMetadataMatcher::class);

        $this->assertInstanceOf(
            ArtworkStorage::class,
            $this->readPrivate($matcher, 'artworkStorage'),
            'LibraryMetadataMatcher must resolve with an ArtworkStorage so posters '
            . 'are cached locally and poster_srcset is populated.'
        );
    }

    /**
     * SV-3.4: the ArtworkStorage storage directory is config-driven. The
     * `artwork.storage_path` app-config value must flow through to the resolved
     * ArtworkStorage's private $storageDir (normalized with a trailing slash),
     * so an operator can relocate the cache via config/env.
     */
    public function test_artwork_storage_dir_is_config_driven(): void
    {
        $customDir = $this->tempDir . '/artwork-cache';

        $providers = ContainerFactory::defaultProviders();
        $mockConnection = $this->createMock(Connection::class);
        $providers[] = new class ($mockConnection) implements ServiceProviderInterface {
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

        $container = ContainerFactory::create([
            'logger_config_path' => $this->loggerConfigPath,
            'db_config_path' => null,
            'artwork' => ['storage_path' => $customDir],
        ], $providers);

        /** @var ArtworkStorage $storage */
        $storage = $container->get(ArtworkStorage::class);
        $this->assertSame(
            rtrim($customDir, '/') . '/',
            $this->readPrivate($storage, 'storageDir'),
            'ArtworkStorage must honour the configured artwork.storage_path.'
        );
    }

    /**
     * S-F48/SV-4.10: `MetadataManager`'s ctor takes
     * `?array $providerPriority = null`; PHP-DI silently skips optional
     * defaulted params unless named, so a missing
     * `->constructorParameter('providerPriority', …)` would leave the field
     * resolved from the ctor's own fallback rather than the DI-wired factory —
     * both happen to trace back to {@see MetadataManager::defaultProviderPriority()}
     * today, but this test proves the binding is genuinely wired (not silently
     * skipped) by asserting the real container's resolved instance carries
     * exactly that value, not an empty/default-constructed array.
     */
    public function test_metadata_manager_wires_provider_priority_in_prod(): void
    {
        $container = $this->containerWithMockedDb();

        /** @var MetadataManager $manager */
        $manager = $container->get(MetadataManager::class);

        $this->assertSame(
            MetadataManager::defaultProviderPriority(),
            $this->readPrivate($manager, 'providerPriority'),
            'MetadataManager must resolve with the config/metadata.php-derived '
            . 'provider-priority map via the named DI binding, not a divergent default.'
        );
    }

    /**
     * The production defect: `RatingGate::class => autowire()` (bare) left
     * {@see RatingGate::$users} NULL, because PHP-DI skips optional ctor params
     * that carry a default. `$users` is what guards the account-owner/admin
     * bypass in {@see RatingGate::resolveFilterForUser()}, so the CONTAINER-BUILT
     * gate capped the owner at the active profile's `profile_settings.content_rating`
     * (default 'R') and every NC-17 item 404'd out of `/playback-info` and
     * `/transcode` for the owner — while an anonymous request still got a 200.
     *
     * A hand-constructed `new RatingGate($items, $profiles, $users)` would NOT
     * have caught this, so the assertion is deliberately against the container.
     */
    public function test_rating_gate_wires_user_repository_in_prod(): void
    {
        $container = $this->containerWithMockedDb();

        /** @var RatingGate $gate */
        $gate = $container->get(RatingGate::class);

        $this->assertInstanceOf(
            UserRepository::class,
            $this->readPrivate($gate, 'users'),
            'RatingGate must resolve with a UserRepository or the account-owner/admin '
            . 'parental bypass is silently dead and the owner gets 404s on over-cap items.'
        );
    }

    /**
     * The behavioural half of the same regression, end-to-end through the real
     * container: an `is_admin` ACCOUNT must not be capped even when its active
     * profile carries a restrictive `profile_settings.content_rating`.
     *
     * The mocked connection answers all three queries the path makes, and the
     * profile row is deliberately NOT an admin profile with a real 'R' cap — so
     * with a null `$users` (the pre-fix wiring) `resolveFilterForUser()` falls
     * through to `UserProfileManager::getActiveRatingFilter()` and returns the
     * G/PG/PG-13/R cap instead of null. That is what made NC-17 unplayable.
     */
    public function test_container_built_rating_gate_does_not_cap_an_admin_account(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function (string $sql): array {
                if (str_contains($sql, 'FROM users')) {
                    return [['id' => 'owner-1', 'is_admin' => 1]];
                }
                if (str_contains($sql, 'user_profiles')) {
                    return [[
                        'id' => 'profile-1',
                        'user_id' => 'owner-1',
                        'name' => 'Kids',
                        'is_active' => 1,
                        'is_admin' => 0,
                        'content_rating' => 'R',
                    ]];
                }
                if (str_contains($sql, 'profile_settings')) {
                    return [['content_rating' => 'R', 'allow_unrated' => 1]];
                }
                return [];
            }
        );

        /** @var RatingGate $gate */
        $gate = $this->containerWithConnection($db)->get(RatingGate::class);

        $this->assertNull(
            $gate->resolveFilterForUser('owner-1'),
            'The container-built gate must return the permissive null filter for an '
            . 'is_admin account; a non-null cap is the NC-17 playback 404 defect.'
        );
    }

    /**
     * `MediaUserDataController::$ratingGate` is the same PHP-DI landmine: optional,
     * defaulted, therefore skipped by a bare `autowire()`. Every parental check in
     * the controller is written `$this->ratingGate?->…` / guarded by
     * `$this->ratingGate !== null`, so a null gate skipped the cap ENTIRELY and a
     * rating-capped profile could favorite / rate / like / mark-watched items above
     * its cap.
     */
    public function test_media_user_data_controller_wires_rating_gate_in_prod(): void
    {
        $container = $this->containerWithMockedDb();

        /** @var MediaUserDataController $controller */
        $controller = $container->get(MediaUserDataController::class);

        $this->assertInstanceOf(
            RatingGate::class,
            $this->readPrivate($controller, 'ratingGate'),
            'MediaUserDataController must resolve with a RatingGate or its parental '
            . 'checks are all skipped (fail-open).'
        );
    }

    /**
     * Every class-typed OPTIONAL constructor parameter that must be bound
     * explicitly, as `[container id, ctor param name]`.
     *
     * PHP-DI's own definition dump is the oracle here — it renders a skipped
     * optional param as `$name = (default value)` and a bound one as
     * `$name = get(...)`. That is exactly the evidence that identified the
     * RatingGate defect on production, and it is the only check that catches the
     * cases where the class ALSO has an internal `?? fallback` (FuzzyMatcher,
     * WebhookService, BackgroundDetectorWorker), where a resolved-object
     * assertion cannot tell a bound dependency from a self-built one.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function explicitlyBoundOptionalParams(): array
    {
        return [
            'RatingGate::$users' => [RatingGate::class, 'users'],
            'MediaUserDataController::$ratingGate' => [MediaUserDataController::class, 'ratingGate'],
            'FuzzyMatcher::$logger' => [FuzzyMatcher::class, 'logger'],
            'SmartPlaylistRefreshHandler::$collectionManager' => [
                SmartPlaylistRefreshHandler::class,
                'collectionManager',
            ],
            'SmartPlaylistRefreshHandler::$collectionRepo' => [
                SmartPlaylistRefreshHandler::class,
                'collectionRepo',
            ],
            'BackgroundDetectorWorker::$logger' => [BackgroundDetectorWorker::class, 'logger'],
            'BackupManager::$logger' => [BackupManager::class, 'logger'],
            'BackupManager::$auditLogger' => [BackupManager::class, 'auditLogger'],
            'WebhookService::$logger' => [WebhookService::class, 'logger'],
            'WatchHistory::$recommendationService' => [WatchHistory::class, 'recommendationService'],
            'WebAuthnCredentialRepository::$logger' => [WebAuthnCredentialRepository::class, 'logger'],
            'WebAuthnManager::$logger' => [WebAuthnManager::class, 'logger'],
        ];
    }

    /**
     * @dataProvider explicitlyBoundOptionalParams
     */
    public function test_optional_ctor_param_is_explicitly_bound_not_skipped(
        string $id,
        string $param
    ): void {
        $container = ContainerFactory::create($this->baseConfig());
        if (!$container instanceof \DI\Container) {
            self::fail('ContainerFactory must build a PHP-DI container for definition introspection.');
        }

        $definition = $container->debugEntry($id);

        $this->assertStringNotContainsString(
            '$' . $param . ' = (default value)',
            $definition,
            "{$id}::\${$param} is an optional ctor param, so PHP-DI SKIPS it unless the "
            . "definition names it via ->constructorParameter('{$param}', get(…)). It is "
            . "currently skipped, which silently disables the feature it feeds:\n" . $definition
        );
        $this->assertMatchesRegularExpression(
            '/\$' . preg_quote($param, '/') . ' = get\(/',
            $definition,
            "{$id}::\${$param} must be bound with get(…):\n" . $definition
        );
    }

    /**
     * BOOT SAFETY: `get(X::class)` on an unresolvable id throws when the entry is
     * built, so every dependency named above must actually resolve. This walks the
     * real provider stack (only the MySQL connection is a double) and builds each
     * touched class, so a missing binding, an un-autowirable interface or a
     * dependency CYCLE fails here in CI instead of taking the server down at boot.
     *
     * @return array<string, array{0: string}>
     */
    public static function classesWithNewlyBoundDependencies(): array
    {
        $out = [];
        foreach (self::explicitlyBoundOptionalParams() as [$id, $_param]) {
            $out[$id] = [$id];
        }

        return $out;
    }

    /**
     * @dataProvider classesWithNewlyBoundDependencies
     */
    public function test_container_resolves_class_with_its_newly_bound_dependencies(string $id): void
    {
        $container = $this->containerWithMockedDb();

        $this->assertIsObject(
            $container->get($id),
            "{$id} must be resolvable from the production provider stack — an unresolvable "
            . 'explicit get(…) throws at container-build/boot time, which is worse than the '
            . 'silently-null dependency it replaces.'
        );
    }

    /**
     * Build the canonical provider stack with the MySQL {@see Connection} rebound
     * to the supplied double, so a test can script the SQL the resolved services
     * run without touching a database.
     */
    private function containerWithConnection(Connection $connection): ContainerInterface
    {
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

        return ContainerFactory::create($this->baseConfig(), $providers);
    }

    /**
     * Read a private property without modifying production visibility.
     */
    private function readPrivate(object $target, string $property): mixed
    {
        $ref  = new ReflectionClass($target);
        $prop = $ref->getProperty($property);
        $prop->setAccessible(true);
        return $prop->getValue($target);
    }
}
