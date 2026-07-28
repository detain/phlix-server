<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

use PHPUnit\Framework\TestCase;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Media\Metadata\MetadataFailureKind;
use Phlix\Media\Metadata\ProviderHealthTracker;

/**
 * Covers the aggregate escalation that catches a provider failing 100% of calls.
 *
 * Per-request logging alone would not have caught the prod incident: each
 * individual failure looks like one unlucky title. What identifies an outage is
 * the tally — specifically a provider with zero lifetime successes.
 */
class ProviderHealthTrackerTest extends TestCase
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $logs = [];

    private function makeTracker(): ProviderHealthTracker
    {
        $this->logs = [];

        $logger = $this->createMock(StructuredLogger::class);
        $logger->method('error')->willReturnCallback(
            function (string $message, array $context = []): void {
                $this->logs[] = ['level' => 'error', 'message' => $message, 'context' => $context];
            }
        );
        $logger->method('info')->willReturnCallback(
            function (string $message, array $context = []): void {
                $this->logs[] = ['level' => 'info', 'message' => $message, 'context' => $context];
            }
        );

        return new ProviderHealthTracker($logger);
    }

    public function testFirstFailureEscalatesImmediately(): void
    {
        $tracker = $this->makeTracker();
        $tracker->recordFailure('tmdb', MetadataFailureKind::Auth);

        $this->assertCount(1, $this->logs);
        $this->assertSame('error', $this->logs[0]['level']);
        $this->assertSame('Metadata provider is failing', $this->logs[0]['message']);
        $this->assertSame('tmdb', $this->logs[0]['context']['provider']);
    }

    public function testNeverSucceededProviderIsCalledOutExplicitly(): void
    {
        $tracker = $this->makeTracker();
        $tracker->recordFailure('tmdb', MetadataFailureKind::Auth);

        $context = $this->logs[0]['context'];
        $this->assertFalse($context['ever_succeeded']);
        $this->assertStringContainsString('NEVER succeeded', $context['diagnosis']);
        // The operational punchline: match results from this provider are junk.
        $this->assertStringContainsString('unreliable', $context['diagnosis']);
    }

    public function testProviderThatWorkedEarlierGetsADifferentDiagnosis(): void
    {
        $tracker = $this->makeTracker();
        $tracker->recordSuccess('tmdb');
        $tracker->recordFailure('tmdb', MetadataFailureKind::ServerError);

        $context = $this->logs[0]['context'];
        $this->assertTrue($context['ever_succeeded']);
        $this->assertStringNotContainsString('NEVER succeeded', $context['diagnosis']);
        $this->assertStringContainsString('working earlier', $context['diagnosis']);
    }

    public function testEscalationIsRateLimitedOverALongOutage(): void
    {
        $tracker = $this->makeTracker();

        for ($i = 0; $i < 1000; $i++) {
            $tracker->recordFailure('tmdb', MetadataFailureKind::Auth);
        }

        // Geometric thresholds 1, 10, 100, 1000 — four lines, not 1000. A rule
        // that floods the log is a rule that gets muted, so this bound matters.
        $this->assertCount(4, $this->logs);
        $streaks = array_map(
            static fn(array $l): int => $l['context']['consecutive_failures'],
            $this->logs
        );
        $this->assertSame([1, 10, 100, 1000], $streaks);
    }

    public function testSuccessResetsTheStreakAndLogsRecovery(): void
    {
        $tracker = $this->makeTracker();
        $tracker->recordFailure('tmdb', MetadataFailureKind::Auth);
        $tracker->recordSuccess('tmdb');

        $this->assertCount(2, $this->logs);
        $this->assertSame('info', $this->logs[1]['level']);
        $this->assertSame('Metadata provider recovered', $this->logs[1]['message']);
        $this->assertSame(1, $this->logs[1]['context']['failed_streak']);

        // Streak cleared, so the next failure escalates as a fresh incident.
        $tracker->recordFailure('tmdb', MetadataFailureKind::Auth);
        $this->assertSame(1, $this->logs[2]['context']['consecutive_failures']);
    }

    public function testHealthIsTrackedPerProviderIndependently(): void
    {
        $tracker = $this->makeTracker();
        $tracker->recordSuccess('tvdb');
        $tracker->recordFailure('tmdb', MetadataFailureKind::Auth);

        $this->assertTrue($tracker->isHealthy('tvdb'));
        $this->assertFalse($tracker->isHealthy('tmdb'));
    }

    public function testUntouchedProviderCountsAsHealthy(): void
    {
        // Absence of evidence is not evidence of failure.
        $this->assertTrue($this->makeTracker()->isHealthy('never-called'));
    }

    public function testSnapshotReportsCounters(): void
    {
        $tracker = $this->makeTracker();
        $tracker->recordSuccess('tmdb');
        $tracker->recordSuccess('tmdb');
        $tracker->recordFailure('tmdb', MetadataFailureKind::RateLimited);

        $snapshot = $tracker->snapshot();

        $this->assertSame(2, $snapshot['tmdb']['successes']);
        $this->assertSame(1, $snapshot['tmdb']['failures']);
        $this->assertSame(1, $snapshot['tmdb']['consecutive_failures']);
        $this->assertTrue($snapshot['tmdb']['ever_succeeded']);
        $this->assertSame('rate_limited', $snapshot['tmdb']['last_failure_kind']);
    }

    public function testStatsMapStaysBoundedInAResidentWorker(): void
    {
        $tracker = $this->makeTracker();

        for ($i = 0; $i < 500; $i++) {
            $tracker->recordSuccess("provider-{$i}");
        }

        // MAX_PROVIDERS = 64; without eviction this map would grow forever in a
        // long-lived Workerman process.
        $this->assertLessThanOrEqual(64, count($tracker->snapshot()));
    }

    public function testResetClearsEverything(): void
    {
        $tracker = $this->makeTracker();
        $tracker->recordFailure('tmdb', MetadataFailureKind::Auth);
        $tracker->reset();

        $this->assertSame([], $tracker->snapshot());
        $this->assertTrue($tracker->isHealthy('tmdb'));
    }
}
