<?php

/**
 * Phlix media server component: Stats.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Stats\Metrics;

use Phlix\Stats\Metrics\MetricsRegistry;
use Phlix\Stats\Metrics\MetricsRepository;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Cross-repo metrics-vocabulary parity pin (finding A-13, wave-5).
 *
 * The hub runs a mirrored metrics stack (`Phlix\Hub\Stats\Metrics\*`) that
 * writes the SAME rollup vocabulary this server writes: three tables whose
 * column names ARE the metric/label names on every read side (the admin
 * metrics API, the console's metrics overlay, the UI's MetricsPage). A
 * unilateral rename on either side — a bucket column, a histogram bound,
 * the `__other__` sentinel — silently breaks one side's SQL against a
 * database shaped by the other, or strands the shared read vocabulary
 * mid-flight while each repo's own tests stay green. That is the class of
 * drift A-13 measured (hub registry prose claimed structure-identity while
 * ~100 code lines had diverged).
 *
 * The canonical home of this vocabulary is `Phlix\Shared\Stats\*` — a
 * v0.57 shared-lift charter item (owner-gated release). Until it lands,
 * the two repos can only share law BY DUPLICATION: this file exists under
 * the same name in BOTH repos with the SAME literal lists below,
 * honestly disclosed as duplicated-by-necessity. A unilateral rename of
 * anything pinned here reddens the drifting repo right here — which is
 * the whole point: it turns "silent divergence" into a loud red.
 *
 * The two copies are literal-for-literal identical except for the
 * package header, the namespace, and the `Phlix\...` class names they
 * import. Known-INTENTIONAL per-repo splits deliberately NOT pinned as
 * shared here: `worker_id` is SMALLINT (server) vs VARCHAR(64) (hub), and
 * the hub's `metrics_rollup` carries extra relay_* columns (its migrations
 * 036/039); both repos' vocabularies above must stay common.
 *
 * @package Phlix\Tests\Unit\Stats\Metrics
 */
final class MetricsVocabularyParityTest extends TestCase
{
    /** @var list<string> The three rollup tables both repos create and write. */
    private const TABLES = ['metrics_rollup', 'metrics_route_rollup', 'metrics_connections'];

    /** @var list<string> Shared columns of `metrics_rollup` (the bucket table). */
    private const ROLLUP_COLUMNS = [
        'bucket_started_at',
        'worker_id',
        'request_count',
        'error_count',
        'duration_ms_sum',
        'duration_ms_max',
        'bytes_in',
        'bytes_out',
        'h_le_10',
        'h_le_50',
        'h_le_100',
        'h_le_250',
        'h_le_500',
        'h_le_1000',
        'h_le_2500',
        'h_le_5000',
        'h_gt_5000',
    ];

    /** @var list<string> Columns of `metrics_route_rollup`. */
    private const ROUTE_ROLLUP_COLUMNS = [
        'bucket_started_at',
        'worker_id',
        'method',
        'route',
        'request_count',
        'error_count',
        'duration_ms_sum',
        'duration_ms_max',
    ];

    /** @var list<string> Columns of `metrics_connections`. */
    private const CONNECTION_COLUMNS = [
        'connection_id',
        'worker_id',
        'kind',
        'user_id',
        'remote_ip',
        'session_id',
        'media_item_id',
        'bytes_in',
        'bytes_out',
        'bytes_in_rate',
        'bytes_out_rate',
        'opened_at',
        'last_seen_at',
    ];

    /** @var list<string> Index names pinned across repos. */
    private const INDEX_NAMES = [
        'idx_metrics_rollup_bucket',
        'idx_metrics_route_rollup_bucket',
        'idx_metrics_connections_last_seen',
    ];

    /** @var list<int> Ascending histogram upper bounds in ms (the shared buckets law). */
    private const HISTOGRAM_BOUNDS_MS = [10, 50, 100, 250, 500, 1000, 2500, 5000];

    /** Sentinel bucket key for routes beyond the cardinality cap. */
    private const OTHER_ROUTE = '__other__';

    /** @var list<string> Top-level keys of a drained overall-rollup row. */
    private const DRAIN_OVERALL_KEYS = [
        'bucket_started_at',
        'request_count',
        'error_count',
        'duration_ms_sum',
        'duration_ms_max',
        'bytes_in',
        'bytes_out',
        'histogram',
    ];

    /** @var list<string> Top-level keys of a drained route-rollup row. */
    private const DRAIN_ROUTE_KEYS = [
        'bucket_started_at',
        'method',
        'route',
        'request_count',
        'error_count',
        'duration_ms_sum',
        'duration_ms_max',
    ];

    /**
     * Read the repo's own `*_metrics_schema.sql` migration verbatim.
     *
     * The schema file — not an ORM — is the SSOT for the persisted column
     * names, so the parity pin greps the DDL text itself.
     */
    private function schemaSql(): string
    {
        $files = glob(dirname(__DIR__, 4) . '/migrations/*_metrics_schema.sql');
        $this->assertIsArray($files);
        $this->assertCount(1, $files, 'exactly one metrics schema migration is expected');

        $sql = file_get_contents((string) $files[0]);
        $this->assertIsString($sql);

        return $sql;
    }

    /**
     * Extract the `CREATE TABLE` body of one pinned table from the schema DDL.
     */
    private function tableBody(string $sql, string $table): string
    {
        $matched = preg_match(
            '/CREATE TABLE IF NOT EXISTS ' . preg_quote($table, '/') . ' \((.*?)\n\)[^)]*?;/s',
            $sql,
            $m,
        );
        $this->assertSame(1, $matched, "CREATE TABLE block for {$table} must exist in the schema");

        return $m[1];
    }

    public function testAllThreeRollupTablesAreDefined(): void
    {
        $sql = $this->schemaSql();
        foreach (self::TABLES as $table) {
            $this->assertStringContainsString(
                'CREATE TABLE IF NOT EXISTS ' . $table . ' (',
                $sql,
                "table {$table} is shared law between server and hub",
            );
        }
    }

    public function testRollupColumnsMatchThePinnedSharedVocabulary(): void
    {
        $body = $this->tableBody($this->schemaSql(), 'metrics_rollup');
        foreach (self::ROLLUP_COLUMNS as $column) {
            $this->assertMatchesRegularExpression(
                '/\b' . preg_quote($column, '/') . '\b/',
                $body,
                "metrics_rollup.{$column} is shared law between server and hub",
            );
        }
    }

    public function testRouteRollupColumnsMatchThePinnedSharedVocabulary(): void
    {
        $body = $this->tableBody($this->schemaSql(), 'metrics_route_rollup');
        foreach (self::ROUTE_ROLLUP_COLUMNS as $column) {
            $this->assertMatchesRegularExpression(
                '/\b' . preg_quote($column, '/') . '\b/',
                $body,
                "metrics_route_rollup.{$column} is shared law between server and hub",
            );
        }
    }

    public function testConnectionColumnsMatchThePinnedSharedVocabulary(): void
    {
        $body = $this->tableBody($this->schemaSql(), 'metrics_connections');
        foreach (self::CONNECTION_COLUMNS as $column) {
            $this->assertMatchesRegularExpression(
                '/\b' . preg_quote($column, '/') . '\b/',
                $body,
                "metrics_connections.{$column} is shared law between server and hub",
            );
        }
    }

    public function testIndexNamesMatchThePinnedSharedVocabulary(): void
    {
        $sql = $this->schemaSql();
        foreach (self::INDEX_NAMES as $index) {
            $this->assertStringContainsString(
                $index,
                $sql,
                "index {$index} is shared law between server and hub",
            );
        }
    }

    public function testHistogramSchemaColumnsAreDerivedFromThePinnedBounds(): void
    {
        $expected = [];
        foreach (self::HISTOGRAM_BOUNDS_MS as $bound) {
            $expected[] = 'h_le_' . $bound;
        }
        $expected[] = 'h_gt_' . self::HISTOGRAM_BOUNDS_MS[array_key_last(self::HISTOGRAM_BOUNDS_MS)];

        $hColumns = array_values(array_filter(
            self::ROLLUP_COLUMNS,
            static fn (string $c): bool => str_starts_with($c, 'h_le_') || str_starts_with($c, 'h_gt_'),
        ));

        $this->assertSame(
            $expected,
            $hColumns,
            'the pinned h_* columns must equal the h_le_*/h_gt_* derivation of the pinned bounds',
        );
    }

    public function testRepositoryHistogramConstantMatchesThePinnedVocabulary(): void
    {
        /** @var list<array{col: string, le: int}> $histogram */
        $histogram = (new ReflectionClass(MetricsRepository::class))->getConstant('HISTOGRAM');
        $this->assertIsArray($histogram);

        $expected = [];
        foreach (self::HISTOGRAM_BOUNDS_MS as $bound) {
            $expected[] = ['col' => 'h_le_' . $bound, 'le' => $bound];
        }
        $expected[] = ['col' => 'h_gt_5000', 'le' => 5000];

        $this->assertSame(
            $expected,
            $histogram,
            'MetricsRepository::HISTOGRAM is shared law with the hub copy (A-13 parity pin)',
        );
    }

    public function testRegistryConstructorDefaultsMatchThePinnedVocabulary(): void
    {
        $constructor = (new ReflectionClass(MetricsRegistry::class))->getConstructor();
        $this->assertNotNull($constructor);

        $defaults = [];
        foreach ($constructor->getParameters() as $parameter) {
            $defaults[$parameter->getName()] = $parameter->getDefaultValue();
        }

        $this->assertSame(10, $defaults['bucketSeconds'] ?? null, 'default bucket granularity is shared law');
        $this->assertSame(
            self::HISTOGRAM_BOUNDS_MS,
            $defaults['latencyBucketsMs'] ?? null,
            'default latency bucket bounds are shared law (server const / hub literal — same values, A-13)',
        );
        $this->assertSame(
            200,
            $defaults['routeCardinalityCap'] ?? null,
            'default route cardinality cap is shared law',
        );
    }

    public function testOtherRouteSentinelMatchesThePinnedVocabulary(): void
    {
        $this->assertSame(
            self::OTHER_ROUTE,
            MetricsRegistry::OTHER_ROUTE,
            "the __other__ fold-in sentinel is shared law between server and hub",
        );
    }

    public function testDrainedRollupRowsCarryThePinnedSchemaKeys(): void
    {
        $registry = new MetricsRegistry();
        $registry->recordRequest('GET', '/api/v1/media', 200, 42.0, 100, 900, 1700000000);

        $drained = $registry->drainRollups(1700000060);

        $this->assertArrayHasKey('overall', $drained);
        $this->assertArrayHasKey('routes', $drained);
        $this->assertIsArray($drained['overall']);
        $this->assertIsArray($drained['routes']);

        $overallRow = reset($drained['overall']);
        $this->assertIsArray($overallRow);
        $this->assertSame(
            self::DRAIN_OVERALL_KEYS,
            array_keys($overallRow),
            'the drained overall-rollup key vocabulary is shared law between server and hub',
        );

        $routeRow = reset($drained['routes']);
        $this->assertIsArray($routeRow);
        $this->assertSame(
            self::DRAIN_ROUTE_KEYS,
            array_keys($routeRow),
            'the drained route-rollup key vocabulary is shared law between server and hub',
        );
    }
}
