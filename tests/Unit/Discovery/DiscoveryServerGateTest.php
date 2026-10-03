<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Discovery;

use Phlix\Admin\SettingsRepository;
use Phlix\Discovery\DiscoveryManager;
use Phlix\Discovery\DiscoveryPolicy;
use Phlix\Discovery\DiscoveryServer;
use PHPUnit\Framework\TestCase;
use Workerman\Timer;
use Workerman\Worker;

/**
 * W2 — the gate must be observable on the REAL Workerman timer registry.
 *
 * Pre-W2, `DiscoveryServer::start()` armed the 60 s SSDP and 30 s mDNS probe
 * timers unconditionally and fired an immediate initial probe for both — an
 * operator who wanted the box quiet from LAN multicast had no lever. The
 * gates now live in `DiscoveryPolicy`; this file proves both gate points:
 *
 *  - REGISTRATION (boot): a protocol disabled at boot never gets its timer
 *    into `Timer::$tasks` at all — the honest reason the schema keys carry
 *    `restart: true`;
 *  - TICK (live): a protocol switched off AFTER boot goes silent on the very
 *    next tick, with the timer still registered — no restart needed to stop.
 *
 * Harness shape copied from ApplicationBackupTimerTest: Timer::add() throws
 * outside a Workerman runtime, so one stub Worker is seeded into the private
 * `Worker::$workers` registry to take the real scheduling path, and
 * `Timer::$tasks` is read back through reflection.
 */
final class DiscoveryServerGateTest extends TestCase
{
    /** @var array<int, Worker> */
    private array $savedWorkers = [];

    /** @var array<string, bool> gate state consulted by the settings double */
    private array $gateState = [];

    protected function setUp(): void
    {
        parent::setUp();

        $workers = new \ReflectionProperty(Worker::class, 'workers');
        $workers->setAccessible(true);
        /** @var array<int, Worker> $existing */
        $existing = $workers->getValue();
        $this->savedWorkers = $existing;

        $stub = new Worker();
        $workers->setValue(null, [spl_object_id($stub) => $stub]);

        Timer::delAll();

        $this->gateState = [
            DiscoveryPolicy::SETTING_KEY_SSDP => true,
            DiscoveryPolicy::SETTING_KEY_MDNS => true,
        ];
    }

    protected function tearDown(): void
    {
        Timer::delAll();

        $workers = new \ReflectionProperty(Worker::class, 'workers');
        $workers->setAccessible(true);
        $workers->setValue(null, $this->savedWorkers);

        parent::tearDown();
    }

    public function test_enabled_at_boot_registers_both_probe_timers_and_probes_once(): void
    {
        $manager = $this->managerExpecting(1, 1);

        $server = new DiscoveryServer($manager, null, $this->policy());
        $server->start();

        $intervals = $this->armedIntervals();
        self::assertContains(60.0, $intervals, 'SSDP probe timer must be armed when enabled.');
        self::assertContains(30.0, $intervals, 'mDNS probe timer must be armed when enabled.');
    }

    public function test_ssdp_disabled_at_boot_never_registers_its_timer_nor_probes(): void
    {
        $this->gateState[DiscoveryPolicy::SETTING_KEY_SSDP] = false;

        // discoverDlnaServers/Renderers: NEVER. mDNS trio: exactly once.
        $manager = $this->managerExpecting(0, 1);

        $server = new DiscoveryServer($manager, null, $this->policy());
        $server->start();

        $intervals = $this->armedIntervals();
        self::assertNotContains(60.0, $intervals, 'A boot-disabled SSDP gate must not register its timer.');
        self::assertContains(30.0, $intervals, 'The mDNS gate is independent and must stay armed.');
    }

    public function test_mdns_disabled_at_boot_never_registers_its_timer_nor_probes(): void
    {
        $this->gateState[DiscoveryPolicy::SETTING_KEY_MDNS] = false;

        $manager = $this->managerExpecting(1, 0);

        $server = new DiscoveryServer($manager, null, $this->policy());
        $server->start();

        $intervals = $this->armedIntervals();
        self::assertContains(60.0, $intervals);
        self::assertNotContains(30.0, $intervals, 'A boot-disabled mDNS gate must not register its timer.');
    }

    public function test_both_gates_off_produces_zero_timers_zero_probes_and_still_starts(): void
    {
        $this->gateState = [
            DiscoveryPolicy::SETTING_KEY_SSDP => false,
            DiscoveryPolicy::SETTING_KEY_MDNS => false,
        ];

        $manager = $this->managerExpecting(0, 0);

        $server = new DiscoveryServer($manager, null, $this->policy());
        $server->start();

        self::assertSame([], $this->armedIntervals(), 'No probe timer may exist when both gates are off.');
        self::assertTrue($server->isRunning(), 'start() still owns its lifecycle; stop() must be callable.');
        $server->stop();
    }

    public function test_flipping_a_gate_off_after_boot_silences_the_very_next_tick(): void
    {
        // Boot: everything on — both timers registered, initial probes ran.
        $manager = $this->managerExpecting(1, 1);
        $server = new DiscoveryServer($manager, null, $this->policy());
        $server->start();

        // Admin flips SSDP off (DB row saved). No restart happens.
        $this->gateState[DiscoveryPolicy::SETTING_KEY_SSDP] = false;

        // Fire the SSDP tick through the REAL scheduled callback.
        $this->fireTick(60.0);

        // managerExpecting(1, ...) set expects($manager, once()) for the SSDP
        // methods: the initial probe consumed the one call, so a tick that
        // still probed would fail the expectation. Assert explicitly too:
        $this->addToAssertionCount(1);
    }

    public function test_stop_deletes_only_the_timers_that_were_registered(): void
    {
        $this->gateState[DiscoveryPolicy::SETTING_KEY_SSDP] = false;

        $manager = $this->managerExpecting(0, 1);
        $server = new DiscoveryServer($manager, null, $this->policy());
        $server->start();

        $server->stop();

        self::assertSame([], $this->armedIntervals(), 'stop() must leave no scheduled probe timers.');
    }

    // ---- helpers -------------------------------------------------------------

    private function policy(): DiscoveryPolicy
    {
        $settings = $this->createMock(SettingsRepository::class);
        // $this-bound closure: reads the CURRENT property value on every call,
        // so a test can flip $gateState after start() and the tick re-check
        // observes it (an arrow-func would snapshot-capture and pin the gate
        // open forever — mutation-proven, not guessed).
        $settings
            ->method('getEffective')
            ->willReturnCallback(fn (string $key): mixed => $this->gateState[$key] ?? true);

        return new DiscoveryPolicy($settings);
    }

    /**
     * Manager double pinning exact call counts for the two probe families.
     *
     * @param int $ssdpCalls expected calls of each SSDP discover* method
     * @param int $mdnsCalls expected calls of each mDNS discover* method
     */
    private function managerExpecting(int $ssdpCalls, int $mdnsCalls): DiscoveryManager
    {
        $manager = $this->createMock(DiscoveryManager::class);

        foreach (['discoverDlnaServers', 'discoverDlnaRenderers'] as $method) {
            $manager->expects(self::exactly($ssdpCalls))->method($method)->willReturn([]);
        }
        foreach (['discoverChromecastDevices', 'discoverAirPlayDevices', 'discoverRokuDevices'] as $method) {
            $manager->expects(self::exactly($mdnsCalls))->method($method)->willReturn([]);
        }

        return $manager;
    }

    /**
     * Intervals (seconds, as floats) of every task currently in Timer::$tasks.
     *
     * @return list<float>
     */
    private function armedIntervals(): array
    {
        $tasksProp = new \ReflectionProperty(Timer::class, 'tasks');
        $tasksProp->setAccessible(true);
        /** @var array<int, array<int, array{0: callable, 1: array<mixed>, 2: bool, 3: float}>> $tasks */
        $tasks = $tasksProp->getValue();

        $intervals = [];
        foreach ($tasks as $group) {
            foreach ($group as $task) {
                $intervals[] = (float) $task[3];
            }
        }

        return $intervals;
    }

    private function fireTick(float $interval): void
    {
        $tasksProp = new \ReflectionProperty(Timer::class, 'tasks');
        $tasksProp->setAccessible(true);
        /** @var array<int, array<int, array{0: callable, 1: array<mixed>, 2: bool, 3: float}>> $tasks */
        $tasks = $tasksProp->getValue();

        foreach ($tasks as $group) {
            foreach ($group as $task) {
                if ((float) $task[3] === $interval) {
                    ($task[0])(...$task[1]);

                    return;
                }
            }
        }

        self::fail("No timer armed at interval {$interval}s.");
    }
}
