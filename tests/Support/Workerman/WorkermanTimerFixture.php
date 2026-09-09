<?php

declare(strict_types=1);

namespace Phlix\Tests\Support\Workerman;

use ReflectionProperty;
use Workerman\Events\EventInterface;
use Workerman\Events\Select;
use Workerman\Timer;

/**
 * S266 — make Workerman-Timer code under test deterministic, whatever else
 * already ran in the PHPUnit process.
 *
 * Diagnosis (re-derived at S266 tip, vendor workerman/workerman v5.2.2):
 * `Workerman\Timer::add()` throws RuntimeException('Timer can only be used in
 * workerman running environment') if and only if BOTH process-global statics
 * are empty — `Workerman\Timer::$event` (null) and `Workerman\Worker::$workers`
 * ([]). The old `isTimerAvailable()` probes in the six
 * tests/Unit/LiveTv/Relay/ cases read that throw as an environment signal, but
 * under this repo's single-process `executionOrder="random"` suite it was a
 * race: WebhookServiceTest, StreamSessionServiceTest and
 * RecordingSchedulerTest each did `if (!Worker::getAllWorkers()) new Worker();`
 * in setUp with no restore, and WebSocketServerTest registers workers through
 * the `WebSocketServer` constructor on nearly every test method — so a seed in
 * which both relay classes landed before every leaker produced six skips, and
 * any other seed produced none. The `Timer::init()` writers
 * (SsdpMSearchListenerTest, TraktSyncBootRealDbTest) already save and restore
 * `Timer::$event` around their own loops; this trait follows that same model.
 *
 * The fixture snapshots `Timer::$event`, installs a fresh pure-PHP
 * {@see Select} loop that is never run, and restores the snapshot afterwards.
 * Code under test therefore always takes the deterministic
 * `Timer::add() → event->delay()/repeat()` branch instead of the
 * pcntl-alarm-scheduler branch; no armed callback ever executes during a unit
 * test on either branch (tests/bootstrap.php intentionally swallows the stray
 * SIGALRM). Outcome: the six LiveTv/Relay cases ALWAYS RUN — no probe, no
 * self-skip, no order-dependence — which restores the estate-wide skip count
 * to being a real tripwire (a rise in skips is evidence of a neutered gate).
 */
trait WorkermanTimerFixture
{
    /**
     * Lane liveness token — proves this file survived the merge ritual.
     */
    public const string SURVIVAL_TOKEN = 'S266SKIPDETERMX9B3';

    private ?EventInterface $s266SavedTimerEvent = null;

    /**
     * Guarantee `Workerman\Timer::add()` is usable for the current test.
     */
    protected function installWorkermanTimerFixture(): void
    {
        $this->s266SavedTimerEvent = self::readTimerEventStatic();
        Timer::init(new Select());
    }

    /**
     * Hand the process-global Timer static back exactly as found.
     */
    protected function removeWorkermanTimerFixture(): void
    {
        // Restore via reflection, never via Timer::init(null): a null argument
        // re-registers Workerman's SIGALRM handler and would undo the no-op
        // guard that tests/bootstrap.php installed on purpose.
        self::writeTimerEventStatic($this->s266SavedTimerEvent);
        $this->s266SavedTimerEvent = null;
    }

    private static function readTimerEventStatic(): ?EventInterface
    {
        $property = new ReflectionProperty(Timer::class, 'event');
        $property->setAccessible(true);
        /** @var EventInterface|null $event */
        $event = $property->getValue();

        return $event;
    }

    private static function writeTimerEventStatic(?EventInterface $event): void
    {
        $property = new ReflectionProperty(Timer::class, 'event');
        $property->setAccessible(true);
        $property->setValue(null, $event);
    }
}
