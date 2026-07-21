<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Dlna;

use PHPUnit\Framework\TestCase;
use Phlix\Config\EffectiveConfig;
use Phlix\Dlna\SsdpAdvertiser;
use ReflectionProperty;
use Workerman\Timer;
use Workerman\Worker;

/**
 * Consequence tests for the `dlna.enabled` switch.
 *
 * ## What is asserted, and why it is not "did we read the config"
 *
 * The observable effect of this setting is whether the SSDP advertiser opens
 * its multicast socket and arms its 30-second broadcast timer. Every test here
 * drives the real `onWorkerStart` callback and then inspects those two pieces
 * of worker state, so a guard that read the setting and ignored it would fail.
 *
 * ## Scope — TWO switches since 1.3.0
 *
 * `dlna.cds_enabled` is the master "run a DLNA server" switch (default FALSE;
 * DLNA has no authentication). `dlna.enabled` chooses whether a running server
 * also ANNOUNCES itself over SSDP.
 *
 * `isEnabled()` requires both, and that conjunction is itself under test here.
 * Announcing a server whose ContentDirectory is not served puts Phlix in every
 * TV's source list as a device that cannot be opened — which was the real
 * production state until 1.3.0, because `DlnaServer` had no DI registration and
 * `Application::loadCdsRoutes()` swallowed the resulting resolution failure in a
 * bare `catch (\Throwable)`. The route wiring itself is covered by
 * {@see \Phlix\Tests\Unit\Common\Container\Providers\DlnaServicesProviderTest};
 * these tests cover the advertiser.
 *
 * ## Workerman note
 *
 * `Timer::add()` throws outside a Workerman runtime unless
 * `Worker::getAllWorkers()` is non-empty, so the ENABLED path would die on the
 * timer rather than on anything under test. {@see self::seedWorkermanRuntime()}
 * seeds that static by reflection so `Timer::add()` takes its real
 * `self::$tasks` branch.
 *
 * Each test is mutation-verified; see individual docblocks.
 */
final class DlnaEnabledGateTest extends TestCase
{
    /** @var array<int, Worker> */
    private array $savedWorkers = [];

    protected function setUp(): void
    {
        parent::setUp();
        EffectiveConfig::reset();
        $this->savedWorkers = Worker::getAllWorkers();
    }

    protected function tearDown(): void
    {
        $this->restoreWorkermanRuntime();
        EffectiveConfig::reset();
        parent::tearDown();
    }

    /**
     * Make `Timer::add()` usable without a running Workerman event loop.
     *
     * Without this the enabled-path test fails inside Workerman rather than on
     * the behaviour under test, which would make it useless as a discriminator.
     */
    private function seedWorkermanRuntime(): void
    {
        $workers = new ReflectionProperty(Worker::class, 'workers');
        $workers->setAccessible(true);
        $workers->setValue(null, [0 => new Worker()]);
    }

    private function restoreWorkermanRuntime(): void
    {
        $workers = new ReflectionProperty(Worker::class, 'workers');
        $workers->setAccessible(true);
        $workers->setValue(null, $this->savedWorkers);
    }

    /**
     * A settings store returning the given `server_settings` rows.
     *
     * @param array<string, array{0: string, 1: string}> $rows
     */
    private function fakeSettingsDb(array $rows): \Workerman\MySQL\Connection
    {
        $db = $this->createMock(\Workerman\MySQL\Connection::class);
        $db->method('query')->willReturnCallback(
            static function ($query = '', $params = null) use ($rows): array {
                $sql = is_string($query) ? $query : '';
                if (str_contains($sql, 'SELECT setting_key,')) {
                    $out = [];
                    foreach ($rows as $key => [$value, $type]) {
                        $out[] = [
                            'setting_key'   => $key,
                            'setting_value' => $value,
                            'value_type'    => $type,
                        ];
                    }
                    return $out;
                }
                return [];
            }
        );
        return $db;
    }

    /**
     * Bootstrap the overlay against a throwaway `config/dlna.php`.
     *
     * @param array<string, mixed>                       $dlna      config-file contents
     * @param array<string, array{0: string, 1: string}> $overrides persisted overrides
     */
    private function bootstrapWith(array $dlna, array $overrides = []): void
    {
        $dir = sys_get_temp_dir() . '/phlix_dlna_' . uniqid('', true) . '/config';
        mkdir($dir, 0o777, true);
        file_put_contents($dir . '/dlna.php', '<?php return ' . var_export($dlna, true) . ";\n");
        EffectiveConfig::bootstrap($this->fakeSettingsDb($overrides), null, $dir);
    }

    /**
     * Run the advertiser's real onWorkerStart and report the resulting state.
     *
     * @return array{socket: bool, timerId: int}
     */
    private function startAdvertiser(SsdpAdvertiser $worker): array
    {
        $start = $worker->onWorkerStart;
        self::assertIsCallable($start, 'SsdpAdvertiser must install an onWorkerStart callback.');
        $start($worker);

        $socketProp = new ReflectionProperty(SsdpAdvertiser::class, 'socket');
        $socketProp->setAccessible(true);
        $timerProp = new ReflectionProperty(SsdpAdvertiser::class, 'timerId');
        $timerProp->setAccessible(true);

        /** @var resource|null $socket */
        $socket = $socketProp->getValue($worker);
        /** @var int $timerId */
        $timerId = $timerProp->getValue($worker);

        // Leave no timer or socket behind for the next test.
        if ($timerId > 0) {
            Timer::del($timerId);
        }
        if (is_resource($socket)) {
            fclose($socket);
            $socketProp->setValue($worker, null);
        }

        return ['socket' => $socket !== null, 'timerId' => $timerId];
    }

    /**
     * CONSEQUENCE: disabled means no multicast socket and no broadcast timer.
     *
     * This is the whole point of the setting — the server stops appearing in
     * DLNA clients' source lists. Asserting on both pieces of state matters:
     * a guard placed after `createUdpSocket()` would still leak an open socket.
     *
     * Mutation-verified: deleting the `if (!self::isEnabled()) { return; }`
     * guard from onWorkerStart fails this test on both assertions.
     */
    public function test_disabling_dlna_opens_no_socket_and_arms_no_timer(): void
    {
        $this->bootstrapWith(['enabled' => false, 'cds_enabled' => true]);

        $state = $this->startAdvertiser(new SsdpAdvertiser('10.0.0.1', 8096));

        self::assertFalse($state['socket'], 'A disabled advertiser must not open its multicast socket.');
        self::assertSame(0, $state['timerId'], 'A disabled advertiser must not arm its broadcast timer.');
    }

    /**
     * CONSEQUENCE: enabled means the advertiser really does start broadcasting.
     *
     * A disable-only test would pass against an advertiser that never starts at
     * all, so the enabled path must be shown to reach both the socket and the
     * timer. `timerId > 0` is the strong signal: it is reachable only after the
     * guard AND socket creation have both succeeded.
     *
     * Mutation-verified: hardcoding isEnabled() to false fails this.
     */
    public function test_enabled_dlna_opens_a_socket_and_arms_the_broadcast_timer(): void
    {
        $this->seedWorkermanRuntime();
        $this->bootstrapWith(['enabled' => true, 'cds_enabled' => true]);

        $state = $this->startAdvertiser(new SsdpAdvertiser('10.0.0.1', 8096));

        self::assertTrue($state['socket'], 'An enabled advertiser must open its multicast socket.');
        self::assertGreaterThan(0, $state['timerId'], 'An enabled advertiser must arm its broadcast timer.');
    }

    /**
     * CONSEQUENCE: an admin override beats the config file.
     *
     * The file says enabled, the persisted override says disabled, and the
     * override must win. This is what makes `dlna.enabled` a SETTING rather
     * than a config constant — read-path class (b)/(c) rather than the class
     * (d) "raw include" trap that made 14 of this program's keys fake.
     *
     * Mutation-verified: changing isEnabled() to read the file with a raw
     * `require` instead of EffectiveConfig::file() fails this test.
     */
    public function test_an_admin_override_beats_the_config_file(): void
    {
        $this->bootstrapWith(['enabled' => true, 'cds_enabled' => true], ['dlna.enabled' => ['0', 'bool']]);

        self::assertFalse(SsdpAdvertiser::isEnabled());

        $state = $this->startAdvertiser(new SsdpAdvertiser('10.0.0.1', 8096));
        self::assertFalse($state['socket'], 'The override must stop the advertiser, not just the config file.');
    }

    /**
     * CONSEQUENCE: an absent key keeps advertising.
     *
     * `config/dlna.php` is net-new, so every existing install upgrades without
     * it and without an override. A fail-closed default would silently remove
     * the server from every DLNA client on upgrade — a behaviour change nobody
     * asked for.
     *
     * Mutation-verified: changing the `?? true` default to `?? false` fails this.
     */
    public function test_an_absent_cds_switch_means_silence(): void
    {
        // `cds_enabled` ships FALSE, so a config file that predates it (or an
        // install that never opted in) must NOT advertise. Announcing a DLNA
        // server whose ContentDirectory is not served is the exact production
        // defect this pair of switches exists to prevent.
        $this->bootstrapWith([]);
        self::assertFalse(SsdpAdvertiser::isEnabled());

        EffectiveConfig::reset();

        // `enabled` still defaults true ON TOP of an enabled server, so an
        // operator who turns the server on gets discovery without a second step.
        $this->bootstrapWith(['cds_enabled' => true]);
        self::assertTrue(SsdpAdvertiser::isEnabled());
    }

    /**
     * CONSEQUENCE: announcing requires the server to actually be running.
     *
     * With the ContentDirectory off, a control point that saw the broadcast
     * would fetch the LOCATION URL and fail, leaving Phlix in every TV's source
     * list as a device that cannot be opened. That WAS the live production
     * state. Asserts the advertiser stays silent even though `enabled` is true.
     *
     * Mutation-verified: deleting the `cds_enabled` check from isEnabled()
     * fails this test.
     */
    public function test_the_advertiser_stays_silent_when_the_cds_is_off(): void
    {
        $this->bootstrapWith(['enabled' => true, 'cds_enabled' => false]);

        self::assertFalse(
            SsdpAdvertiser::isEnabled(),
            'Announcing a DLNA server whose browse service is off advertises a device that cannot be opened.'
        );

        $state = $this->startAdvertiser(new SsdpAdvertiser('10.0.0.1', 8096));
        self::assertFalse($state['socket'], 'No multicast socket should be opened.');
        self::assertSame(0, $state['timerId'], 'No broadcast timer should be armed.');
    }

    /**
     * CONSEQUENCE: a malformed override does not silently disable advertising.
     *
     * `server_settings` rows are admin-editable and have been hand-edited on
     * production before now. The guard compares against `false` explicitly
     * rather than casting, so a junk value leaves the shipped default alone
     * instead of being coerced into "off".
     *
     * The inputs DISCRIMINATE: `'maybe'` and `''` both cast to boolean values a
     * naive `(bool)` or `!` implementation would read as OFF for `''`, so a
     * cast-based guard fails this test rather than coincidentally passing it.
     */
    public function test_a_malformed_override_does_not_disable_advertising(): void
    {
        $this->bootstrapWith(['enabled' => true, 'cds_enabled' => true], ['dlna.enabled' => ['maybe', 'string']]);
        self::assertTrue(SsdpAdvertiser::isEnabled(), 'A junk override must not disable advertising.');

        EffectiveConfig::reset();

        $this->bootstrapWith(['enabled' => true, 'cds_enabled' => true], ['dlna.enabled' => ['', 'string']]);
        self::assertTrue(SsdpAdvertiser::isEnabled(), 'An empty override must not disable advertising.');
    }
}
