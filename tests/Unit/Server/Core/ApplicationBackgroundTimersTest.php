<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Core;

use PHPUnit\Framework\TestCase;
use Phlix\Common\Database\ConnectionPool;
use Phlix\Server\Core\Application;
use Psr\Container\ContainerInterface;
use Workerman\MySQL\Connection;

/**
 * Regression tests for {@see Application::startBackgroundTimers()} and the daemon
 * wiring that reaches it.
 *
 * ## The defect these pin
 *
 * The backup, storage-snapshot, transcode-reaper and newsletter timers were
 * registered ONLY inside {@see Application::run()} — a CGI-era entry point with no
 * caller in the tree. On every Workerman install none of them ran: production had
 * zero rows in `backups` and zero in `stats_storage`.
 *
 * Nothing caught it because **a timer that is never registered throws nothing**.
 * A test asserting "the method exists" or "the config key is present" would have
 * stayed green throughout. So every assertion here checks an OBSERVABLE
 * CONSEQUENCE of the timers actually executing:
 *
 *  - the backup and storage-snapshot timers each take a pooled DB connection, so
 *    a mock pool that is never asked for one proves they early-returned;
 *  - `config/server.php` must actually compose `newsletter`, because the gate
 *    reads `$config['newsletter']` and `EffectiveConfig` refuses to create keys.
 *
 * Each test below was mutation-verified: the cited change was applied, the test
 * observed to fail, and the change reverted.
 */
class ApplicationBackgroundTimersTest extends TestCase
{
    /**
     * Build an Application without running the heavy route-loading constructor,
     * matching the convention in the sibling Application tests.
     *
     * @param array<string, mixed> $config
     */
    private function makeApp(array $config, ConnectionPool $pool, ContainerInterface $container): Application
    {
        $ref = new \ReflectionClass(Application::class);
        /** @var Application $app */
        $app = $ref->newInstanceWithoutConstructor();

        foreach (['config' => $config, 'connectionPool' => $pool, 'container' => $container] as $prop => $value) {
            $p = $ref->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue($app, $value);
        }

        return $app;
    }

    /**
     * CONSEQUENCE: both DB-backed timers must actually run.
     *
     * The backup timer and the storage-snapshot timer each call
     * `getPooledConnection('mysql')`. If `startBackgroundTimers()` stops invoking
     * them — the exact regression that made this method necessary — the pool is
     * never asked for a connection and this fails.
     *
     * Mutation-verified: commenting out `startStorageSnapshotTimer()` in
     * `startBackgroundTimers()` drops the call count and fails this test.
     */
    public function test_background_timers_open_db_connections_for_backup_and_snapshot(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $pool = $this->createMock(ConnectionPool::class);
        $pool->expects($this->atLeast(2))
            ->method('getPooledConnection')
            ->with('mysql')
            ->willReturn($db);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $container->method('get')->willThrowException(new \RuntimeException('not bound'));

        // '_config_dir' is never set in production either; the code falls back to
        // the relative 'config', which resolves because the daemon runs with
        // WorkingDirectory=/var/www/phlix and PHPUnit runs from the repo root.
        $app = $this->makeApp(['_config_dir' => __DIR__ . '/../../../../config'], $pool, $container);

        $app->startBackgroundTimers();
    }

    /**
     * CONSEQUENCE: a reaper failure must not prevent the other timers running.
     *
     * The container here throws for every service, which is what an unbound
     * TranscodeManager looks like. The backup and snapshot timers must still have
     * taken their connections.
     */
    public function test_a_failing_transcode_reaper_does_not_abort_the_other_timers(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);

        $pool = $this->createMock(ConnectionPool::class);
        $pool->expects($this->atLeast(2))
            ->method('getPooledConnection')
            ->willReturn($db);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $container->method('get')->willThrowException(new \RuntimeException('TranscodeManager unbound'));

        $app = $this->makeApp(['_config_dir' => __DIR__ . '/../../../../config'], $pool, $container);

        $app->startBackgroundTimers();
    }

    /**
     * CONSEQUENCE: `config/server.php` must compose the newsletter config.
     *
     * `startNewsletterTimerIfEnabled()` reads `$this->config['newsletter']` and
     * returns early when it is absent — which it always was, because
     * `config/server.php` never required `newsletter.php`. The overlay cannot
     * rescue it: `EffectiveConfig` refuses to create keys that do not already
     * exist, so the `newsletter.*` admin settings were unreachable too.
     *
     * Mutation-verified: removing the `'newsletter' => require ...` line from
     * `config/server.php` fails this test.
     */
    public function test_server_config_composes_the_newsletter_block(): void
    {
        /** @var array<string, mixed> $config */
        $config = require __DIR__ . '/../../../../config/server.php';

        $this->assertArrayHasKey(
            'newsletter',
            $config,
            'config/server.php must compose config/newsletter.php, or the newsletter '
            . 'gate in Application::startNewsletterTimerIfEnabled() returns early on '
            . 'every boot and the newsletter.* settings resolve to nothing.'
        );
        $this->assertIsArray($config['newsletter']);
        $this->assertArrayHasKey('enabled', $config['newsletter']);
        $this->assertArrayHasKey('send_hour', $config['newsletter']);

        // Ships disabled: activating the timer must not start mailing users on
        // upgrade. If this ever flips to true by default, that is a deliberate
        // product decision and this assertion should be the thing that surfaces it.
        $this->assertFalse(
            $config['newsletter']['enabled'],
            'The newsletter must ship disabled — the timer is now genuinely wired, '
            . 'so a true default would start sending on every existing install.'
        );
    }

    /**
     * CONSEQUENCE: the daemon must actually register the timer worker.
     *
     * `Application::startBackgroundTimers()` being correct is worthless if nothing
     * calls it. `start.php` cannot be exercised without booting Workerman, so this
     * asserts against its source — deliberately, because the failure being guarded
     * is "the call site was never added", which no runtime test in this suite can
     * observe.
     *
     * Mutation-verified: deleting the worker block from `start.php` fails this.
     */
    public function test_daemon_registers_a_single_background_timer_worker(): void
    {
        $startPhp = file_get_contents(__DIR__ . '/../../../../start.php');
        $this->assertIsString($startPhp);

        // Match the CALL, not a mention. An earlier revision of this test asserted
        // the bare string 'startBackgroundTimers()', which the explanatory comment
        // in start.php also satisfies — so deleting the actual call left the test
        // green. Mutation testing caught it. Require the `->` invocation.
        $this->assertMatchesRegularExpression(
            '/\$\w+->startBackgroundTimers\(\)\s*;/',
            $startPhp,
            'start.php must CALL Application::startBackgroundTimers(); it never calls '
            . 'Application::run(), so the timers are otherwise unreachable.'
        );
        $this->assertMatchesRegularExpression(
            '/\$backgroundTimerWorker->name\s*=\s*\'phlix-background-timers\'\s*;/',
            $startPhp
        );

        // count=1 is a correctness requirement: these timers write backups and
        // storage snapshots, so per-worker registration would multiply both.
        $this->assertMatchesRegularExpression(
            '/\$backgroundTimerWorker->count\s*=\s*1\s*;/',
            $startPhp,
            'The background timer worker must be count=1 — backups and storage '
            . 'snapshots would otherwise be written once per HTTP worker.'
        );

        // Must be armed for SIGTERM cleanup like every other non-HTTP worker, or
        // it fatals on shutdown ("API must be called in the coroutine").
        $this->assertMatchesRegularExpression(
            '/\$backgroundTimerWorker \?\? null,/',
            $startPhp,
            'The background timer worker must be added to the '
            . 'ConnectionPool::armWorkerStopCleanup() list or it fatals on SIGTERM.'
        );
    }
}
