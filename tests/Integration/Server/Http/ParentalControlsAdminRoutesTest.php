<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Server\Http;

use DI\ContainerBuilder;
use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Router;
use Phlix\Server\Http\Routes\AdminRoutes;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Workerman\MySQL\Connection;

use function DI\factory;

/**
 * S208 defect (1) — REACHABILITY guard for the parental-controls admin API.
 *
 * ## What was broken
 *
 * `phlix-ui/src/api/admin/users.ts` calls eight endpoints under
 * `/api/v1/admin/profiles/{id}/…` — schedules (GET/POST/DELETE), tags
 * (GET/POST/DELETE) and stream-limits (GET/PUT). phlix-server registered every
 * one of them WITHOUT the `/admin` segment, so the entire Parental Controls
 * screen 404'd. `AdminRoutes` now carries them.
 *
 * ## Why this file drives the real registrar through the real container
 *
 * A list of route literals asserted against a hand-built router is a
 * self-fulfilling test: it can only disagree with the registrar it does not
 * consult. So this file builds the production DI container
 * ({@see ContainerFactory::create()} over {@see ContainerFactory::defaultProviders()},
 * with only the MySQL {@see Connection} replaced), hands it to the production
 * registrar {@see AdminRoutes::register()} — the same call
 * `Application::loadRoutes()` makes at `Application.php:887` — and dispatches
 * real {@see Request}s.
 *
 * Building the container for real is load-bearing in a second way: it is what
 * proves the three controllers are still AUTOWIRABLE after gaining a required
 * `ProfileAccessPolicy` constructor parameter. A hand-rolled stub container
 * would hide a missing binding, and PHP-DI silently skips OPTIONAL parameters —
 * which is exactly why that parameter is required.
 *
 * ⚠ Asserting "not 404" is not enough on its own: the admin group answers 401
 * and 403 without ever reaching a handler, so each case below asserts a
 * response shape only the intended handler produces.
 */
final class ParentalControlsAdminRoutesTest extends TestCase
{
    private const ADMIN_ID = 'cccccccc-3333-4333-8333-cccccccccccc';
    private const PLAIN_ID = 'dddddddd-4444-4444-8444-dddddddddddd';
    private const PROFILE_ID = 'a1a1a1a1-1111-4111-8111-a1a1a1a1a1a1';
    private const SCHEDULE_ID = 7;
    private const TAG_ID = 11;

    private string $tempDir = '';
    private string $loggerConfigPath = '';
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        LoggerFactory::reset();

        $this->tempDir = sys_get_temp_dir() . '/phlix_s208_routes_' . uniqid('', true);
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

        $this->router = new Router();
        AdminRoutes::register($this->router, $this->container());
    }

    protected function tearDown(): void
    {
        LoggerFactory::reset();

        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);

        parent::tearDown();
    }

    /**
     * The eight paths `phlix-ui/src/api/admin/users.ts` actually calls.
     *
     * ⚠ The step text says "nine"; the client declares EIGHT parental-controls
     * calls (3 schedules + 3 tags + 2 stream-limits). Counted from the source,
     * not from the step.
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>, 3: string}>
     */
    public static function spaPathProvider(): array
    {
        $profile = self::PROFILE_ID;

        return [
            'GET schedules' => [
                'GET',
                "/api/v1/admin/profiles/{$profile}/schedules",
                [],
                'schedules',
            ],
            'POST schedules' => [
                'POST',
                "/api/v1/admin/profiles/{$profile}/schedules",
                [
                    'name' => 'Bedtime',
                    'start_time' => '20:00:00',
                    'end_time' => '22:00:00',
                    'days_of_week' => ['mon'],
                ],
                'schedule_id',
            ],
            'DELETE schedule' => [
                'DELETE',
                "/api/v1/admin/profiles/{$profile}/schedules/" . self::SCHEDULE_ID,
                [],
                'Schedule deleted successfully',
            ],
            'GET tags' => [
                'GET',
                "/api/v1/admin/profiles/{$profile}/tags",
                [],
                'tags',
            ],
            // ⚠ The body key here is the SERVER's spelling, `type`, because this
            // file pins ROUTE RESOLUTION and a 400 would prove nothing about it.
            // The client sends `tag_type` (`users.ts:650`), so creating a tag
            // from the admin screen still 400s on the field name even now that
            // the path resolves. That contract drift was flagged by S209 and is
            // explicitly NOT S208's to fix — do not "align" this fixture without
            // fixing the controller, or the mismatch becomes invisible again.
            'POST tags' => [
                'POST',
                "/api/v1/admin/profiles/{$profile}/tags",
                ['tag' => 'violence', 'type' => 'blocked'],
                'tag_id',
            ],
            'DELETE tag' => [
                'DELETE',
                "/api/v1/admin/profiles/{$profile}/tags/" . self::TAG_ID,
                [],
                'Tag removed successfully',
            ],
            'GET stream-limits' => [
                'GET',
                "/api/v1/admin/profiles/{$profile}/stream-limits",
                [],
                'stream_limits',
            ],
            'PUT stream-limits' => [
                'PUT',
                "/api/v1/admin/profiles/{$profile}/stream-limits",
                ['max_concurrent_streams' => 3],
                'Stream limits updated successfully',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @dataProvider spaPathProvider
     */
    public function testEverySpaPathResolvesToItsHandler(
        string $method,
        string $path,
        array $body,
        string $handlerMarker,
    ): void {
        $response = $this->router->dispatch($this->request($method, $path, self::ADMIN_ID, $body));

        $this->assertNotSame(
            404,
            $response->statusCode,
            "{$method} {$path} must resolve to a handler — it 404'd, which is the S208 defect",
        );
        $this->assertStringNotContainsString('The requested resource was not found', (string) $response->body);
        $this->assertStringContainsString(
            $handlerMarker,
            (string) $response->body,
            "{$method} {$path} reached SOMETHING, but not the handler that answers with '{$handlerMarker}'",
        );
    }

    /**
     * S202 depends on this route existing; today's SPA does not call it yet, so
     * it needs its own pin or nothing would notice it disappearing.
     */
    public function testTheAdminSchedulePutExistsForS202(): void
    {
        $response = $this->router->dispatch($this->request(
            'PUT',
            '/api/v1/admin/profiles/' . self::PROFILE_ID . '/schedules/' . self::SCHEDULE_ID,
            self::ADMIN_ID,
            ['name' => 'Later bedtime'],
        ));

        $this->assertNotSame(404, $response->statusCode);
        $this->assertStringContainsString('Schedule updated successfully', (string) $response->body);
    }

    public function testTheAdminByIdScheduleGetExists(): void
    {
        $response = $this->router->dispatch($this->request(
            'GET',
            '/api/v1/admin/profiles/' . self::PROFILE_ID . '/schedules/' . self::SCHEDULE_ID,
            self::ADMIN_ID,
        ));

        $this->assertSame(200, $response->statusCode);
        $this->assertStringContainsString('Bedtime', (string) $response->body);
    }

    public function testTheAdminActiveStreamsRouteExists(): void
    {
        $response = $this->router->dispatch($this->request(
            'GET',
            '/api/v1/admin/profiles/' . self::PROFILE_ID . '/active-streams',
            self::ADMIN_ID,
        ));

        $this->assertSame(200, $response->statusCode);
        $this->assertStringContainsString('active_streams', (string) $response->body);
    }

    /**
     * The whole point of registering under `/admin` rather than repointing the
     * SPA: the group carries AdminMiddleware, so a plain signed-in user is
     * turned away before any handler runs.
     */
    public function testAPlainUserIsRefusedByTheAdminGroupGate(): void
    {
        $response = $this->router->dispatch($this->request(
            'DELETE',
            '/api/v1/admin/profiles/' . self::PROFILE_ID . '/schedules/' . self::SCHEDULE_ID,
            self::PLAIN_ID,
        ));

        $this->assertSame(403, $response->statusCode);
        $this->assertStringNotContainsString('Schedule deleted successfully', (string) $response->body);
    }

    public function testAnAnonymousCallerIsRefusedByTheAdminGroupGate(): void
    {
        $response = $this->router->dispatch($this->request(
            'DELETE',
            '/api/v1/admin/profiles/' . self::PROFILE_ID . '/schedules/' . self::SCHEDULE_ID,
            null,
        ));

        $this->assertSame(401, $response->statusCode);
    }

    /**
     * The new 3- and 4-segment routes must not have swallowed the sibling
     * `/profiles/{id}` routes that were already there.
     */
    public function testTheSiblingProfileRoutesStillResolve(): void
    {
        foreach (
            [
            ['GET', '/api/v1/admin/profiles/' . self::PROFILE_ID],
            ['PUT', '/api/v1/admin/profiles/' . self::PROFILE_ID],
            ['DELETE', '/api/v1/admin/profiles/' . self::PROFILE_ID],
            ['POST', '/api/v1/admin/profiles/' . self::PROFILE_ID . '/pin'],
            ['DELETE', '/api/v1/admin/profiles/' . self::PROFILE_ID . '/pin'],
            ] as [$method, $path]
        ) {
            $response = $this->router->dispatch($this->request($method, $path, self::ADMIN_ID, ['pin' => '1234']));
            $this->assertStringNotContainsString(
                'The requested resource was not found',
                (string) $response->body,
                "{$method} {$path} must still resolve",
            );
        }
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $body
     */
    private function request(string $method, string $path, ?string $userId, array $body = []): Request
    {
        $request = new Request();
        $request->method = $method;
        $request->path = $path;
        $request->userId = $userId;
        $request->body = $body;

        return $request;
    }

    private function container(): ContainerInterface
    {
        $connection = $this->connection();

        $providers = ContainerFactory::defaultProviders();
        $providers[] = new class ($connection) implements ServiceProviderInterface {
            public function __construct(private Connection $connection)
            {
            }

            /**
             * @param ContainerBuilder<\DI\Container> $builder
             * @param array<string, mixed>            $appConfig
             */
            public function register(ContainerBuilder $builder, array $appConfig): void
            {
                $connection = $this->connection;
                $builder->addDefinitions([
                    Connection::class => factory(static fn (): Connection => $connection),
                ]);
            }
        };

        return ContainerFactory::create([
            'logger_config_path' => $this->loggerConfigPath,
            'db_config_path' => null,
        ], $providers);
    }

    /**
     * A connection that answers just enough for the admin gate, the access
     * policy and the three parental services to run end-to-end.
     */
    private function connection(): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(
            static function (mixed $sql = '', mixed $params = null): mixed {
                $sql = is_string($sql) ? $sql : '';
                $params = is_array($params) ? $params : [];
                $first = is_string($params[0] ?? null) ? $params[0] : '';

                if (str_contains($sql, 'FROM user_profiles')) {
                    if ($first !== self::PROFILE_ID) {
                        return [];
                    }

                    return [['id' => $first, 'user_id' => 'someone-else', 'name' => 'Kid']];
                }

                if (str_contains($sql, 'FROM users')) {
                    return $first === self::ADMIN_ID
                        ? [['id' => $first, 'is_admin' => 1, 'status' => 'active']]
                        : [];
                }

                if (str_contains($sql, 'FROM access_schedules')) {
                    return [[
                        'id' => (string) self::SCHEDULE_ID,
                        'profile_id' => self::PROFILE_ID,
                        'name' => 'Bedtime',
                        'start_time' => '20:00:00',
                        'end_time' => '22:00:00',
                        'days_of_week' => 'mon',
                        'is_active' => 1,
                    ]];
                }

                if (str_contains($sql, 'FROM profile_tags')) {
                    return [[
                        'id' => (string) self::TAG_ID,
                        'profile_id' => self::PROFILE_ID,
                        'tag' => 'violence',
                        'tag_type' => 'blocked',
                    ]];
                }

                if (str_contains($sql, 'FROM profile_stream_limits')) {
                    return [[
                        'profile_id' => self::PROFILE_ID,
                        'max_concurrent_streams' => 2,
                        'max_total_bandwidth_kbps' => null,
                    ]];
                }

                if (str_contains($sql, 'FROM active_streams')) {
                    return [];
                }

                return true;
            },
        );
        $db->method('lastInsertId')->willReturn('99');

        return $db;
    }
}
