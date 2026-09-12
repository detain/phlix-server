<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Contracts;

use DI\ContainerBuilder;
use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Common\Database\ConnectionPool;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Version;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\RequestContext;
use Phlix\Server\Http\Router;
use Phlix\Server\WebPortal\WebPortalRouter;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Workerman\MySQL\Connection;

use function DI\factory;

/**
 * S65 — machine-readable OpenAPI currency guard for `openapi.yaml`.
 *
 * ## The contract this file enforces
 *
 * `openapi.yaml` must describe EXACTLY the routes the server actually serves — no
 * phantom operation (a documented route nobody registers: the S204 defect class), no
 * undocumented operation (a served route the spec omits), and every documented
 * operation's `x-phlix-middleware` must equal the REAL route-level middleware chain,
 * in order. This is the same tuple-set == spec-paths currency the phlix-hub S66 guard
 * (tests/Unit/Http/RouteRegistration/OpenApiSpecMatchesRouterTest.php) establishes,
 * mirrored here for phlix-server.
 *
 * ## Why the comparison is honest (the point of the whole step)
 *
 * The registry side is NOT a list this file declares — it is recomposed live from the
 * PRODUCTION container on every assertion, exactly as
 * {@see \Phlix\Tests\Unit\Server\Core\ApplicationRouterWirePathGuardTest} recomposes
 * `Application`'s router and {@see \Phlix\Tests\Unit\Server\WebPortal\WebPortalRouterWirePathGuardTest}
 * recomposes `WebPortalRouter`'s. The spec side is parsed straight out of the committed
 * YAML. A derived spec regenerated in CI could never disagree with the code it derived
 * from; a hand-maintained one can, and that is precisely what fails loud here.
 *
 * ## The 404-tuple ground truth (W50 re-spec; re-derived on the S73 rebase, S240)
 *
 * The served surface is the UNION of the two routers with `Application` taking
 * precedence on overlap: `HttpHandler` dispatches `Application` first and only falls
 * through to the container-composed `WebPortalRouter` when `Application` answers 404
 * (src/Server/Workerman/HttpHandler.php). So the effective middleware for a method+path
 * both routers expose is `Application`'s — the S349 "single real path" rule: the spec
 * documents the path the dispatcher actually serves, never a shadowed second opinion.
 * That yields 367 (Application) + 48 (WebPortalRouter, incl. S73's
 * GET /api/v1/people/{personId}/photo) − 11 overlap = 404 operations across 342 path
 * templates. The eleven overlaps are re-derived from the live routers
 * every run; four of them expose divergent chains (`GET /api/v1/libraries`,
 * `GET /api/v1/libraries/{id}`, `GET /api/v1/media/{id}/posters` and
 * `PUT /api/v1/media/{id}/poster` are `[]` from `Application` yet gated on
 * `WebPortalRouter`) — `Application` wins, and this test proves the spec matches the
 * winner rather than the shadowed chain.
 *
 * ## The token, and why it sits in code
 *
 * {@see self::SPEC_TOKEN} is the step's collision sentinel, executed by an assertion and
 * mirrored into the YAML header, so a stray duplicate copy of this work is detectable by
 * grep alone and a spec that loses its sentinel is caught here. It deliberately lives in
 * no prose file (S457 house pattern).
 */
final class OpenapiSpecCurrencyTest extends TestCase
{
    public const SPEC_TOKEN = 'CS65OPENAPISPECX9G';

    private const REPO = __DIR__ . '/../../../..';

    private const SPEC = self::REPO . '/openapi.yaml';

    /**
     * Anti-vacuity floor on the operation count, on BOTH sides of the comparison.
     *
     * Deliberately a floor and not the exact 401: its job is to turn "the extractor read
     * nothing" or "the production container collapsed into a hand-rolled 53-route graph"
     * into a NAMED failure instead of a vacuous green. It sits below the measured 401 and
     * far above the 53 a wrong container yields (S164).
     */
    private const MINIMUM_OPERATIONS = 350;

    /**
     * The verbs the registry can hold. Mirrors the hub whitelist verbatim so an
     * unexpected verb in either artefact is invisible to neither side.
     *
     * @var list<string>
     */
    private const HTTP_VERBS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    /** A single operation's identity as it appears in both artefacts: `"<VERB> <path>"`. */
    private const PROBE_PATH = '/api/v1/shuffle';

    private string $tempDir = '';

    private string $loggerConfigPath = '';

    private ?ContainerInterface $sharedContainer = null;

    private ?Application $sharedApplication = null;

    // -----------------------------------------------------------------
    // Fixtures — the production container, MySQL doubled, routing untouched
    // -----------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();
        LoggerFactory::reset();

        // Application registers AccessScheduleMiddleware GLOBALLY and it answers 403
        // whenever RequestContext carries a user id. That context is process static, so a
        // sibling that left one set could change what the composed graph's dispatch-time
        // behaviour would be. The route TABLE is registered at construction and is not
        // affected, but clearing it keeps this harness identical to its siblings.
        RequestContext::setUserId(null);
        RequestContext::setProfileId(null);

        $this->tempDir = sys_get_temp_dir() . '/phlix_s65_' . uniqid('', true);
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
        // The container graph this test resolves constructs MediaAssetJobStore and
        // SimilarityJobStore through MediaServicesProvider's factories at the production
        // default queue paths; their constructors mint the shared /tmp directories. Sweep
        // so the suite leaves zero residue (identical to ApplicationRouterWirePathGuardTest).
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

        return $this->sharedApplication = new Application($this->container(), [], $pool);
    }

    // -----------------------------------------------------------------
    // Registry side — recomposed live, never declared here
    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function routerOf(object $owner): array
    {
        $property = (new ReflectionClass($owner))->getProperty('router');
        $property->setAccessible(true);
        $router = $property->getValue($owner);

        if (!$router instanceof Router) {
            throw new RuntimeException(
                static::class . ': expected a Router instance, got ' . get_debug_type($router)
            );
        }

        return $router->getRoutes();
    }

    /**
     * The composed served surface: `"<VERB> <path literal>" => ordered middleware short
     * names`, with `Application` winning every method+path both routers expose.
     *
     * @return array<string, list<string>>
     */
    private function composedRegistry(): array
    {
        $appTable = $this->flatten($this->routerOf($this->application()));
        $portalTable = $this->flatten($this->routerOf($this->container()->get(WebPortalRouter::class)));

        // Application is dispatched first, so its middleware chain is the truth on overlap.
        $registry = $portalTable;
        foreach ($appTable as $key => $chain) {
            $registry[$key] = $chain;
        }

        ksort($registry);

        if (count($registry) < self::MINIMUM_OPERATIONS) {
            $this->fail(sprintf(
                'ANTI-VACUITY: the composed served surface holds %d operation(s), below the %d floor. '
                . 'Either a loader was hollowed out or this harness stopped reading the production '
                . 'container (a hand-rolled one collapses to 53). The currency guard is NOT guarding.',
                count($registry),
                self::MINIMUM_OPERATIONS
            ));
        }

        return $registry;
    }

    /**
     * @param array<string, array<string, array<string, mixed>>> $routes
     *
     * @return array<string, list<string>>
     */
    private function flatten(array $routes): array
    {
        $out = [];
        foreach ($routes as $method => $entries) {
            foreach ($entries as $entry) {
                $path = $entry['path'];
                $this->assertIsString($path, 'every route entry must carry its literal path');

                $chain = [];
                foreach ($entry['middleware'] ?? [] as $middleware) {
                    $this->assertIsObject($middleware, "{$method} {$path} carries a non-object middleware");
                    $chain[] = $middleware instanceof \Closure
                        ? 'Closure'
                        : (new ReflectionClass($middleware))->getShortName();
                }

                // Defense-in-depth, not the primary detector: `Router::addRoute` keys
                // static routes by [METHOD][path] and parametric ones by [METHOD][pattern],
                // so a *same-key* re-registration is already folded last-wins before
                // `getRoutes()` ever returns — and last-wins there equals dispatch-wins,
                // so the served truth is preserved regardless. The realistic case this
                // assert still catches is two method-*case* variants folding to one key.
                $key = strtoupper($method) . ' ' . $path;
                $this->assertArrayNotHasKey(
                    $key,
                    $out,
                    "{$key} collides twice within one router after method-case folding; "
                    . 'same-key re-registration is folded last-wins upstream (== dispatch-wins).'
                );
                $out[$key] = $chain;
            }
        }

        return $out;
    }

    // -----------------------------------------------------------------
    // Spec side — the narrow line-wise reader (mirrors hub verbatim)
    // -----------------------------------------------------------------

    /**
     * Parse `"<VERB> <path>" => ordered middleware` straight out of the YAML.
     *
     * The extractor is deliberately line-wise (the runtime ships no YAML parser, and pinning
     * the exact indentation contract — path at two spaces, operation verb at four,
     * `x-phlix-middleware` at six — is part of what the currency test guards). It FAILS
     * rather than quietly returning less: a missing/empty file, a missing `paths:` section,
     * an operation without a middleware line, or an under-filled extraction each raise.
     *
     * @return array<string, list<string>>
     */
    private function specOperations(?string $overrideFile = null): array
    {
        $file = $overrideFile ?? self::SPEC;
        $lines = $this->readSpecLines($file);

        /** @var array<string, list<string>> $operations */
        $operations = [];
        $inPaths = false;
        $currentPath = null;
        $routerPath = null;
        $currentVerb = null;
        $currentKey = null;
        $sawMiddleware = false;
        $operationsForPath = 0;

        foreach ($lines as $raw) {
            $line = rtrim($raw, "\r");

            if (!$inPaths) {
                if ($line === 'paths:') {
                    $inPaths = true;
                }
                continue;
            }

            // A column-0 key ends the paths section.
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*:/', $line) === 1) {
                break;
            }

            if (preg_match('/^  "(\/[^"]*)":$/', $line, $m) === 1) {
                $this->closeOperation($currentVerb, $currentKey, $sawMiddleware);
                if ($currentPath !== null) {
                    $this->assertGreaterThan(
                        0,
                        $operationsForPath,
                        sprintf('Path "%s" declares no operations.', $currentPath)
                    );
                }
                $currentPath = $m[1];
                $routerPath = null;
                $currentVerb = null;
                $currentKey = null;
                $sawMiddleware = false;
                $operationsForPath = 0;
                continue;
            }

            if (preg_match('/^    x-phlix-route: "(.+)"$/', $line, $m) === 1) {
                $this->assertNotNull($currentPath, 'x-phlix-route appeared outside a path item');
                $routerPath = $m[1];
                continue;
            }

            if (preg_match('/^    ([a-z]+):$/', $line, $m) === 1 && in_array($m[1], self::HTTP_VERBS, true)) {
                $this->closeOperation($currentVerb, $currentKey, $sawMiddleware);
                $currentVerb = strtoupper($m[1]);
                $currentKey = $currentVerb . ' ' . ($routerPath ?? (string) $currentPath);
                $sawMiddleware = false;
                $operationsForPath++;
                continue;
            }

            if (preg_match('/^      x-phlix-middleware: \[(.*)\]$/', $line, $m) === 1) {
                $this->assertNotNull($currentKey, 'x-phlix-middleware appeared outside an operation');
                /** @var list<string> $chain */
                $chain = array_values(array_filter(
                    array_map('trim', explode(',', $m[1])),
                    static fn (string $name): bool => $name !== ''
                ));
                $operations[(string) $currentKey] = $chain;
                $sawMiddleware = true;
            }
        }

        $this->assertTrue($inPaths, 'openapi.yaml has no `paths:` section.');

        $this->closeOperation($currentVerb, $currentKey, $sawMiddleware);

        $this->assertGreaterThanOrEqual(
            self::MINIMUM_OPERATIONS,
            count($operations),
            'ANTI-VACUITY: the spec extraction found fewer operations than the floor — the reader '
            . 'no longer matches the file layout, or the file was truncated.'
        );

        ksort($operations);

        return $operations;
    }

    /**
     * The state-machine's one assertion on a finished operation: an operation is only
     * ever recorded after its middleware line is seen, so reaching this point with a
     * current operation whose middleware was never seen is the exact "operation with no
     * x-phlix-middleware" defect and must fail by name — not be silently skipped.
     */
    private function closeOperation(?string $verb, ?string $key, bool $sawMiddleware): void
    {
        if ($verb === null || $key === null) {
            return;
        }

        $this->assertTrue(
            $sawMiddleware,
            sprintf('%s has no x-phlix-middleware line. Every operation must declare one.', $key)
        );
    }

    /**
     * @return list<string>
     */
    private function readSpecLines(string $file): array
    {
        if (!is_file($file)) {
            $this->fail(sprintf('The OpenAPI spec at %s does not exist. It is the served contract.', $file));
        }
        $contents = file_get_contents($file);
        if (!is_string($contents) || trim($contents) === '') {
            $this->fail(sprintf('The OpenAPI spec at %s is empty.', $file));
        }

        return preg_split('/\r\n|\n/', $contents) ?: [];
    }

    // -----------------------------------------------------------------
    // The pure drift report — the single place bijection + chains are compared
    // -----------------------------------------------------------------

    /**
     * @param array<string, list<string>> $registry
     * @param array<string, list<string>> $spec
     *
     * @return list<string>
     */
    private function driftReport(array $registry, array $spec): array
    {
        $problems = [];

        foreach ($registry as $key => $chain) {
            if (!array_key_exists($key, $spec)) {
                $problems[] = sprintf(
                    'REGISTERED BUT UNDOCUMENTED: %s (route-level middleware [%s]).',
                    $key,
                    implode(', ', $chain)
                );
                continue;
            }
            if ($spec[$key] !== $chain) {
                $problems[] = sprintf(
                    'MIDDLEWARE MISMATCH: %s — registry [%s], spec [%s].',
                    $key,
                    implode(', ', $chain),
                    implode(', ', $spec[$key])
                );
            }
        }

        foreach ($spec as $key => $chain) {
            if (!array_key_exists($key, $registry)) {
                $problems[] = sprintf(
                    'DOCUMENTED BUT NOT REGISTERED: %s (spec middleware [%s]).',
                    $key,
                    implode(', ', $chain)
                );
            }
        }

        sort($problems);

        return $problems;
    }

    // -----------------------------------------------------------------
    // Assertions
    // -----------------------------------------------------------------

    public function testTheSpecificationIsWellFormedOpenApi(): void
    {
        /** @var array<string, mixed> $doc */
        $doc = Yaml::parseFile(self::SPEC);

        $this->assertSame('3.1.0', $doc['openapi'] ?? null, 'the spec must be OpenAPI 3.1.0');
        $this->assertIsArray($doc['paths'] ?? null, 'the spec must carry a paths object');
        // 339 at the S65 authoring; 340 after the S73 people-photo endpoint
        // (GET /api/v1/people/{personId}/photo), re-measured on this rebase; 342 after
        // S240's additive music detail-by-name query routes (GET /api/v1/music/artist,
        // GET /api/v1/music/album), re-measured from this phpunit red.
        $this->assertCount(342, $doc['paths'], 'the served surface is 342 distinct path templates');

        // The contract version is pinned to the app release version on purpose: the
        // whole thesis of this file is that drift must fail LOUD, so `info.version`
        // is not allowed to sit silently stale after a release bump either.
        $this->assertSame(
            Version::STRING,
            (string) ($doc['info']['version'] ?? ''),
            'openapi.yaml info.version must track Phlix\Common\Version::STRING'
        );

        $operations = 0;
        foreach ($doc['paths'] as $path => $item) {
            $this->assertIsArray($item, "path {$path} must be a path item");
            $declared = 0;
            foreach ($item as $verb => $op) {
                if ($verb === 'x-phlix-route') {
                    continue;
                }
                $this->assertContains($verb, self::HTTP_VERBS, "unexpected key {$verb} under {$path}");
                $this->assertArrayHasKey('operationId', $op, "{$verb} {$path} needs an operationId");
                $this->assertArrayHasKey('x-phlix-middleware', $op, "{$verb} {$path} needs x-phlix-middleware");
                $declared++;
                $operations++;
            }
            $this->assertGreaterThan(0, $declared, "{$path} declares no operations");
        }
        $this->assertGreaterThanOrEqual(self::MINIMUM_OPERATIONS, $operations);
    }

    public function testTheSpecTokenIsExecutedAndMirroredIntoTheArtefact(): void
    {
        $this->assertSame(18, strlen(self::SPEC_TOKEN), 'the collision sentinel is 18 chars');
        $this->assertMatchesRegularExpression('/^[A-Z0-9]+$/', self::SPEC_TOKEN);

        $contents = (string) file_get_contents(self::SPEC);
        $this->assertStringContainsString(
            self::SPEC_TOKEN,
            $contents,
            'the executed SPEC_TOKEN must be mirrored into openapi.yaml (code == artefact).'
        );
    }

    public function testEveryDocumentedOperationIsServedAndEveryServedOperationIsDocumented(): void
    {
        $problems = $this->driftReport($this->composedRegistry(), $this->specOperations());

        $this->assertSame(
            [],
            $problems,
            "openapi.yaml has drifted from the composed route registry:\n" . implode("\n", $problems)
        );
    }

    /**
     * The planted-drift RED proof: each of the three failure modes the guard exists to
     * catch is EXECUTED against a mutated copy of the spec and asserted to be caught, by
     * name. A guard nobody has seen fail is a guard nobody can trust (S457 house pattern).
     */
    public function testPlantedDriftTurnsTheGuardRed(): void
    {
        $registry = $this->composedRegistry();

        // 1. A phantom operation (documented, never registered) — the S204 class.
        $phantomFile = $this->tempFile($this->withPhantomPath());
        $phantomReport = $this->driftReport($registry, $this->specOperations($phantomFile));
        $this->assertNotEmpty($phantomReport, 'a phantom path must be caught');
        $this->assertTrue(
            $this->reportMentions($phantomReport, 'DOCUMENTED BUT NOT REGISTERED: GET /api/v1/s65-planted-phantom'),
            'the phantom drift must name the planted path'
        );

        // 2. A registered operation removed from the spec — the silent-rename class.
        $missingFile = $this->tempFile($this->withoutPath(self::PROBE_PATH));
        $missingReport = $this->driftReport($registry, $this->specOperations($missingFile));
        $this->assertTrue(
            $this->reportMentions($missingReport, 'REGISTERED BUT UNDOCUMENTED: POST ' . self::PROBE_PATH),
            'removing a served route from the spec must be caught and named'
        );

        // 3. A re-gated operation: the chain edited without the registry moving.
        $regateFile = $this->tempFile($this->regateProbe());
        $regateReport = $this->driftReport($registry, $this->specOperations($regateFile));
        $this->assertTrue(
            $this->reportMentions($regateReport, 'MIDDLEWARE MISMATCH: POST ' . self::PROBE_PATH),
            'a middleware-chain edit must be caught and named'
        );

        // Control: the unmutated spec is clean under the exact same report helper.
        $this->assertSame([], $this->driftReport($registry, $this->specOperations()));
    }

    // -----------------------------------------------------------------
    // Mutation helpers (spec-content only; the registry is always live)
    // -----------------------------------------------------------------

    private function tempFile(string $contents): string
    {
        $path = $this->tempDir . '/mutated-' . uniqid('', true) . '.yaml';
        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * @param list<string> $report
     */
    private function reportMentions(array $report, string $needle): bool
    {
        foreach ($report as $line) {
            if (str_starts_with($line, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function specContents(): string
    {
        return (string) file_get_contents(self::SPEC);
    }

    /**
     * Append a phantom path (documented but never registered) just before `components:`.
     * Its `[AuthMiddleware]`/`security: []` pairing is deliberately incoherent: the
     * line-wise currency reader only inspects `x-phlix-middleware`, so the pair keeps the
     * fixture minimal, and this string is a throwaway in-memory mutation that is never
     * written back to the real spec — do NOT copy-adapt it into a committed operation.
     */
    private function withPhantomPath(): string
    {
        $insertion = "  \"/api/v1/s65-planted-phantom\":\n"
            . "    get:\n"
            . "      operationId: \"get_api_v1_s65_planted_phantom\"\n"
            . "      tags: [\"System\"]\n"
            . "      summary: \"Planted phantom (drift proof only)\"\n"
            . "      x-phlix-middleware: [AuthMiddleware]\n"
            . "      security: []\n"
            . "      responses:\n"
            . "        \"200\":\n"
            . "          description: \"Success.\"\n"
            . "\n";

        return str_replace("\ncomponents:\n", "\n" . $insertion . "components:\n", $this->specContents());
    }

    /**
     * Remove a whole path item (its key line through the line before the next path key or
     * the closing section key), so the served route it documented vanishes from the spec.
     */
    private function withoutPath(string $path): string
    {
        $lines = explode("\n", $this->specContents());
        $keyLine = '  "' . $path . '":';
        $start = array_search($keyLine, $lines, true);
        $this->assertIsInt($start, "mutation target path {$path} is not present in the spec");

        $end = $start + 1;
        $total = count($lines);
        while ($end < $total) {
            $candidate = $lines[$end];
            $isNextPath = preg_match('/^  "(\/[^"]*)":$/', $candidate) === 1;
            $isClosingKey = preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*:/', $candidate) === 1;
            if ($isNextPath || $isClosingKey) {
                break;
            }
            $end++;
        }

        array_splice($lines, $start, $end - $start);

        return implode("\n", $lines);
    }

    /**
     * Rewrite the probe path's operation to claim a DIFFERENT middleware chain than the
     * registry actually composes for it.
     */
    private function regateProbe(): string
    {
        $lines = explode("\n", $this->specContents());
        $keyLine = '  "' . self::PROBE_PATH . '":';
        $start = array_search($keyLine, $lines, true);
        $this->assertIsInt($start);

        $total = count($lines);
        $mutated = false;
        for ($i = $start + 1; $i < $total; $i++) {
            // Stay inside the probe path item: stop at the next path key or section key,
            // so a probe missing its own middleware line can never mutate a sibling.
            $isNextPath = preg_match('/^  "(\/[^"]*)":$/', $lines[$i]) === 1;
            $isClosingKey = preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*:/', $lines[$i]) === 1;
            if ($isNextPath || $isClosingKey) {
                break;
            }
            if (preg_match('/^      x-phlix-middleware: \[.*\]$/', $lines[$i]) === 1) {
                $lines[$i] = '      x-phlix-middleware: [AuthMiddleware, AdminMiddleware]';
                $mutated = true;
                break;
            }
        }

        $this->assertTrue(
            $mutated,
            'planted drift did not apply: no x-phlix-middleware line found within '
            . self::PROBE_PATH . ' — the fixture shape the mutation guard assumes has changed.'
        );

        return implode("\n", $lines);
    }
}
