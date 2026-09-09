<?php

/**
 * S266 guard support — probe bootstrap for the static-residue child process.
 *
 * Used ONLY as `--bootstrap` of a child `vendor/bin/phpunit` run spawned by
 * tests/Unit/Support/WorkermanStaticStateLeakGuardTest.php. It loads the
 * normal tests/bootstrap.php, then registers a shutdown hook that writes the
 * end-of-process state of the two Workerman statics the S266 skip-determinism
 * fix hinges on — `Workerman\Worker::$workers` and `Workerman\Timer::$event`
 * (protected statics, read via reflection, the same idiom the
 * WorkermanTimerFixture uses) — as JSON to the file named by
 * PHLIX_S266_STATIC_DUMP. `Timer::$tasks` and `Timer::$status` counts are
 * implementation details of this vendor version and ride along as DIAGNOSTICS
 * ONLY; the guard asserts neither. Without the env var set this file is a
 * plain bootstrap with no hook — inert for every other consumer.
 *
 * Side effects only; declares no symbols (PSR-1).
 */

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

$probeDumpPath = getenv('PHLIX_S266_STATIC_DUMP');

if (is_string($probeDumpPath) && $probeDumpPath !== '') {
    register_shutdown_function(static function () use ($probeDumpPath): void {
        $workersProperty = new ReflectionProperty(Workerman\Worker::class, 'workers');
        $workersProperty->setAccessible(true);
        $eventProperty = new ReflectionProperty(Workerman\Timer::class, 'event');
        $eventProperty->setAccessible(true);
        $workersValue = $workersProperty->getValue();
        $eventValue = $eventProperty->getValue();

        $dump = [
            'workers' => is_countable($workersValue) ? count($workersValue) : -1,
            'timerEventNull' => $eventValue === null,
            'timerEventClass' => is_object($eventValue) ? get_class($eventValue) : null,
            'timerTasks' => null,
            'timerStatus' => null,
        ];

        foreach (['tasks' => 'timerTasks', 'status' => 'timerStatus'] as $prop => $key) {
            if ((new ReflectionClass(Workerman\Timer::class))->hasProperty($prop)) {
                $value = (new ReflectionProperty(Workerman\Timer::class, $prop))->getValue();
                $dump[$key] = is_countable($value) ? count($value) : -1;
            }
        }

        file_put_contents($probeDumpPath, json_encode($dump, JSON_PRETTY_PRINT));
    });
}
