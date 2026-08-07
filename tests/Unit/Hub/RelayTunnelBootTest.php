<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Hub;

use DI\ContainerBuilder;
use Phlix\Common\Container\Providers\HubServicesProvider;
use Phlix\Hub\RelayStateStore;
use Phlix\Hub\RelayTunnelBoot;
use Phlix\Server\Http\Controllers\Admin\HealthController;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * S39: the relay operator kill-switch must ACTUALLY kill the tunnel.
 *
 * Before this, `PHLIX_RELAY_DISABLED` and the persisted `relay-control.json`
 * flag gated only `RelayConfig::withAutoEnable()` in `start.php`, while
 * `$consumer->start()` ran unconditionally — so with `hub_relay_ws_url` set
 * (which `config/relay.php` recommends for TLS deployments) the admin "Disable"
 * control was a COMPLETE no-op while the endpoint claimed the tunnel would
 * disconnect on the next reload.
 *
 * `start.php` runs outside PHPUnit, so the decision now lives in
 * {@see RelayTunnelBoot} and is pinned here on three levels:
 *   1. the pure decision (env spellings, control file, combination);
 *   2. the boot gate's ONE side effect — persisting an honest disabled state —
 *      proven to be READ BACK by the code that serves `/api/v1/health/relay`,
 *      through the real DI provider binding the relay fork resolves;
 *   3. a structural guard that `start.php` actually delegates to the gate and
 *      returns before building the tunnel.
 */
final class RelayTunnelBootTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/phlix-relay-boot-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function store(): RelayStateStore
    {
        return new RelayStateStore($this->dir);
    }

    /** The exact instance `start.php`'s relay fork resolves from the container. */
    private function providerStore(string $configDir): RelayStateStore
    {
        $builder = new ContainerBuilder();
        (new HubServicesProvider())->register($builder, ['hub' => ['config_dir' => $configDir]]);
        $container = $builder->build();

        $store = $container->get(RelayStateStore::class);
        self::assertInstanceOf(RelayStateStore::class, $store);

        return $store;
    }

    // ---------------------------------------------------------------------
    // 1. The pure decision.
    // ---------------------------------------------------------------------

    /**
     * @return list<array{string|false|null, bool}>
     */
    public static function envSpellings(): array
    {
        return [
            ['1', true],
            ['true', true],
            ['TRUE', true],
            ['Yes', true],
            [' on ', true],
            ['0', false],
            ['false', false],
            ['off', false],
            ['', false],
            ['maybe', false],
            [false, false],
            [null, false],
        ];
    }

    /**
     * @dataProvider envSpellings
     */
    public function testEnvDisablesRecognisesOnlyTruthySpellings(string|false|null $raw, bool $expected): void
    {
        self::assertSame($expected, RelayTunnelBoot::envDisables($raw));
    }

    public function testOperatorDisabledWhenOnlyTheControlFileIsSet(): void
    {
        $store = $this->store();
        self::assertFalse(RelayTunnelBoot::isOperatorDisabled(false, $store));

        self::assertTrue($store->setRelayDisabled(true));
        self::assertTrue(RelayTunnelBoot::isOperatorDisabled(false, $store));
    }

    public function testOperatorDisabledWhenOnlyTheEnvVarIsSet(): void
    {
        // Control file explicitly says ENABLED — the env var must still win.
        $store = $this->store();
        self::assertTrue($store->setRelayDisabled(false));

        self::assertFalse(RelayTunnelBoot::isOperatorDisabled(false, $store));
        self::assertTrue(RelayTunnelBoot::isOperatorDisabled('1', $store));
    }

    // ---------------------------------------------------------------------
    // 2. The boot gate suppresses the tunnel and persists an honest state.
    // ---------------------------------------------------------------------

    public function testAllowBootReturnsTrueAndWritesNothingWhenEnabled(): void
    {
        $store = $this->store();

        self::assertTrue(RelayTunnelBoot::allowBoot(false, $store));
        self::assertSame([], $store->readRelayState(), 'the enabled path must not touch the state file');
    }

    public function testAllowBootReturnsFalseWhenTheKillSwitchIsSet(): void
    {
        self::assertFalse(RelayTunnelBoot::allowBoot(true, $this->store()));
    }

    public function testDisabledBootPersistsAnHonestDownState(): void
    {
        $store = $this->store();

        // A previous, enabled run left a "tunnel is up" snapshot behind.
        $store->writeRelayState([
            'connected' => true,
            'active' => true,
            'reconnectAttempts' => 0,
            'activeSessions' => 4,
            'lastDisconnectTime' => null,
            'lastConnectError' => null,
            'lastConnectErrorAt' => null,
        ]);

        self::assertFalse(RelayTunnelBoot::allowBoot(true, $store));

        $state = $store->readRelayState();
        self::assertFalse($state['connected']);
        self::assertFalse($state['active']);
        self::assertSame(0, $state['activeSessions']);
        self::assertSame(RelayTunnelBoot::DISABLED_REASON, $state['lastConnectError']);
        self::assertSame(
            'relay disabled by operator kill-switch',
            $state['lastConnectError'],
            'the persisted reason is part of the operator-visible contract'
        );
        self::assertIsString($state['lastConnectErrorAt']);
        self::assertIsString($state['updatedAt']);
    }

    // ---------------------------------------------------------------------
    // 2b. READ-BACK PROOF: the state the relay fork writes is the state
    //     /api/v1/health/relay serves. A kill-switch that writes where nobody
    //     reads (S211) is the failure mode this pins shut.
    // ---------------------------------------------------------------------

    public function testDisabledStateIsReadBackByTheRelayHealthEndpoint(): void
    {
        // WRITE side: the store the relay fork gets from the container, built
        // by the real HubServicesProvider off `hub.config_dir`.
        $forkStore = $this->providerStore($this->dir);
        self::assertFalse(RelayTunnelBoot::allowBoot(true, $forkStore));

        // READ side: HealthController is constructed in Application.php with
        // exactly this expression over the same config array.
        $hubConfig = ['config_dir' => $this->dir];
        $configDir = is_string($hubConfig['config_dir'] ?? null) ? $hubConfig['config_dir'] : 'config';
        $controller = new HealthController(null, $configDir);

        $response = $controller->relayHealth(new Request(), []);
        self::assertSame(200, $response->statusCode);

        $body = json_decode($response->body, true);
        self::assertIsArray($body);
        self::assertIsArray($body['relay']);

        self::assertFalse($body['relay']['connected']);
        self::assertFalse($body['relay']['active']);
        self::assertSame(0, $body['relay']['activeSessions']);
        self::assertSame(RelayTunnelBoot::DISABLED_REASON, $body['relay']['lastConnectError']);
    }

    public function testProviderStoreAndHealthControllerResolveTheSameFileUnderTheShippedConfig(): void
    {
        // Not a synthetic dir: the real config/hub.php the daemon loads.
        /** @var array<string, mixed> $hubConfig */
        $hubConfig = require dirname(__DIR__, 3) . '/config/hub.php';
        self::assertIsString($hubConfig['config_dir'] ?? null);

        $storeDir = $this->configDirOf($this->providerStore((string) $hubConfig['config_dir']));

        $controller = new HealthController(null, (string) $hubConfig['config_dir']);
        $controllerDir = (string) (new \ReflectionProperty(HealthController::class, 'configDir'))
            ->getValue($controller);

        self::assertNotSame('', $storeDir);
        self::assertSame(
            rtrim($storeDir, '/'),
            rtrim($controllerDir, '/'),
            'the relay fork writes relay-tunnel.state.json where /api/v1/health/relay reads it'
        );
        self::assertStringStartsWith('/', $storeDir, 'the shipped config_dir must be absolute (S211)');
    }

    private function configDirOf(RelayStateStore $store): string
    {
        return (string) (new \ReflectionProperty(RelayStateStore::class, 'configDir'))->getValue($store);
    }

    // ---------------------------------------------------------------------
    // 3. start.php actually delegates to the gate.
    //
    //    start.php is outside PHPUnit and cannot be executed here, so this is a
    //    structural guard on its SOURCE (comments stripped, so the assertions
    //    cannot be satisfied by the explanatory comment block next to the code).
    // ---------------------------------------------------------------------

    public function testStartPhpGatesTheTunnelOnRelayTunnelBoot(): void
    {
        $code = $this->startPhpWithoutComments();

        self::assertStringContainsString(
            '$relayTunnelWorker->name',
            $code,
            'guard is vacuous unless the relay worker block is present in the stripped source'
        );

        $gateAt = strpos($code, 'RelayTunnelBoot::allowBoot(');
        self::assertIsInt($gateAt, 'start.php must route the relay boot through RelayTunnelBoot::allowBoot()');

        $startAt = strpos($code, '$consumer->start()');
        self::assertIsInt($startAt, 'the relay fork must still start the consumer when enabled');

        self::assertLessThan(
            $startAt,
            $gateAt,
            'the kill-switch gate must run BEFORE the tunnel is started'
        );

        // The gate must be a real early return, not an ignored boolean.
        self::assertMatchesRegularExpression(
            '/if\s*\(\s*!\s*\\\\?Phlix\\\\Hub\\\\RelayTunnelBoot::allowBoot\([^)]*\)\s*\)\s*\{\s*return;/s',
            $code,
            'a disabled relay must return out of onWorkerStart, not fall through to $consumer->start()'
        );

        self::assertStringContainsString(
            'RelayTunnelBoot::isOperatorDisabled(',
            $code,
            'the env var + control-file combination must come from the tested helper'
        );
    }

    /** start.php source with all comments removed. */
    private function startPhpWithoutComments(): string
    {
        $path = dirname(__DIR__, 3) . '/start.php';
        $source = file_get_contents($path);
        self::assertIsString($source);

        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }

        return $out;
    }
}
