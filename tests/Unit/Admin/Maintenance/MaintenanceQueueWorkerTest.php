<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Admin\Maintenance;

use Phlix\Admin\Maintenance\MaintenanceJobRepository;
use Phlix\Admin\Maintenance\MaintenanceQueueWorker;
use Phlix\Admin\Maintenance\MaintenanceTask;
use Phlix\Admin\Maintenance\MaintenanceTaskRunner;
use Phlix\Common\Logger\StructuredLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * {@see MaintenanceQueueWorker} — draining the queue (S77).
 *
 * Every assertion here is about a job's TERMINAL state, because a maintenance
 * job that neither completes nor fails stays `running` forever, and a `running`
 * row is never claimed again — which makes
 * {@see MaintenanceJobRepository::enqueue()} refuse every future request for
 * that task. The admin's button then stops working with no error anywhere.
 */
final class MaintenanceQueueWorkerTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $queue Jobs handed out by claimNext(), in order.
     */
    private function repository(array $queue): MaintenanceJobRepository
    {
        $repo = $this->createMock(MaintenanceJobRepository::class);
        $repo->method('claimNext')->willReturnCallback(
            static function () use (&$queue): ?array {
                return array_shift($queue);
            }
        );

        return $repo;
    }

    /**
     * @return array<string, mixed>
     */
    private function job(string $id, string $task, array $params = []): array
    {
        return ['id' => $id, 'task' => $task, 'params' => $params, 'status' => 'running'];
    }

    private function worker(
        MaintenanceJobRepository $repo,
        MaintenanceTaskRunner $runner,
    ): MaintenanceQueueWorker {
        return new MaintenanceQueueWorker($repo, $runner, $this->createMock(StructuredLogger::class));
    }

    public function test_an_empty_queue_runs_nothing(): void
    {
        $runner = $this->createMock(MaintenanceTaskRunner::class);
        $runner->expects(self::never())->method('run');

        self::assertSame(0, $this->worker($this->repository([]), $runner)->drain());
    }

    public function test_a_claimed_job_is_run_and_marked_completed_with_its_result(): void
    {
        $repo = $this->repository([$this->job('j1', MaintenanceTask::STORAGE_SNAPSHOT)]);
        $repo->expects(self::once())
            ->method('markCompleted')
            ->with('j1', ['buckets' => 5]);
        $repo->expects(self::never())->method('markFailed');

        $runner = $this->createMock(MaintenanceTaskRunner::class);
        $runner->expects(self::once())
            ->method('run')
            ->with(MaintenanceTask::STORAGE_SNAPSHOT, [])
            ->willReturn(['buckets' => 5]);

        self::assertSame(1, $this->worker($repo, $runner)->drain());
    }

    /**
     * The stored params reach the runner. Without this, `dedupe-paths` would
     * always run as a dry run however the button was pressed.
     */
    public function test_the_stored_params_are_forwarded_to_the_runner(): void
    {
        $repo = $this->repository([
            $this->job('j1', MaintenanceTask::DEDUPE_PATHS, ['apply' => true, 'batch_size' => 7]),
        ]);

        $runner = $this->createMock(MaintenanceTaskRunner::class);
        $runner->expects(self::once())
            ->method('run')
            ->with(MaintenanceTask::DEDUPE_PATHS, ['apply' => true, 'batch_size' => 7])
            ->willReturn([]);

        $this->worker($repo, $runner)->drain();
    }

    /**
     * A THROWING task is marked `failed` with its message — never left
     * `running`. See the class docblock for why that distinction is the whole
     * point of this file.
     */
    public function test_a_throwing_task_is_marked_failed_and_the_drain_continues(): void
    {
        $repo = $this->repository([
            $this->job('j1', MaintenanceTask::CLEANUP_ORPHANED_STATS),
            $this->job('j2', MaintenanceTask::STORAGE_SNAPSHOT),
        ]);
        $repo->expects(self::once())
            ->method('markFailed')
            ->with('j1', 'media_items reports zero rows');
        $repo->expects(self::once())->method('markCompleted')->with('j2', []);

        $runner = $this->createMock(MaintenanceTaskRunner::class);
        $runner->method('run')->willReturnCallback(
            static function (string $task): array {
                if ($task === MaintenanceTask::CLEANUP_ORPHANED_STATS) {
                    throw new RuntimeException('media_items reports zero rows');
                }

                return [];
            }
        );

        self::assertSame(
            2,
            $this->worker($repo, $runner)->drain(),
            'One failing job must not abandon the rest of the queue.'
        );
    }

    /**
     * 🚨 An UNKNOWN task name is FAILED, never run.
     *
     * `maintenance_jobs.task` is a VARCHAR — the vocabulary lives in PHP — so
     * the database cannot reject a bad value. A row carrying one must terminate
     * rather than being handed to the runner or left `running`.
     */
    public function test_an_unknown_task_is_failed_without_ever_reaching_the_runner(): void
    {
        $repo = $this->repository([$this->job('j1', 'dangerous-unknown-task')]);
        $repo->expects(self::once())
            ->method('markFailed')
            ->with('j1', 'Unknown maintenance task: dangerous-unknown-task');
        $repo->expects(self::never())->method('markCompleted');

        $runner = $this->createMock(MaintenanceTaskRunner::class);
        $runner->expects(self::never())->method('run');

        self::assertSame(1, $this->worker($repo, $runner)->drain());
    }

    /**
     * A claim that throws does not take the drain (or the fork) down.
     *
     * This worker shares the `phlix-background-timers` process with the backup
     * and storage-snapshot timers, where an uncaught throwable kills the
     * process and systemd restarts it.
     */
    public function test_a_throwing_claim_is_swallowed_rather_than_killing_the_fork(): void
    {
        $repo = $this->createMock(MaintenanceJobRepository::class);
        $repo->method('claimNext')->willThrowException(new RuntimeException('database gone'));

        $runner = $this->createMock(MaintenanceTaskRunner::class);
        $runner->expects(self::never())->method('run');

        self::assertSame(0, $this->worker($repo, $runner)->drain());
    }

    /**
     * A row with no id terminates the claim loop instead of spinning on it.
     *
     * `runOnce()` must still report TRUE (something was claimed) so the caller
     * looks again, but `drain()` must not loop forever on an unmarkable row —
     * the empty queue that follows is what stops it.
     */
    public function test_a_job_row_with_no_id_does_not_spin_the_drain(): void
    {
        $repo = $this->repository([['id' => '', 'task' => MaintenanceTask::STORAGE_SNAPSHOT, 'params' => []]]);
        $repo->expects(self::never())->method('markCompleted');
        $repo->expects(self::never())->method('markFailed');

        $runner = $this->createMock(MaintenanceTaskRunner::class);
        $runner->expects(self::never())->method('run');

        self::assertSame(1, $this->worker($repo, $runner)->drain());
    }

    /**
     * `drain()` keeps claiming until the queue empties, rather than doing one
     * job per five-second tick — a burst of clicks must not be spread over a
     * minute of polls.
     */
    public function test_drain_empties_the_whole_queue_in_one_pass(): void
    {
        $repo = $this->repository([
            $this->job('j1', MaintenanceTask::STORAGE_SNAPSHOT),
            $this->job('j2', MaintenanceTask::DEDUPE_PATHS),
            $this->job('j3', MaintenanceTask::STORAGE_SNAPSHOT),
        ]);

        $runner = $this->createMock(MaintenanceTaskRunner::class);
        $runner->expects(self::exactly(3))->method('run')->willReturn([]);

        self::assertSame(3, $this->worker($repo, $runner)->drain());
    }
}
