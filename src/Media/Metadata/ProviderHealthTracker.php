<?php

/**
 * Phlix media server component: Metadata.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Metadata;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;

/**
 * Tracks per-provider request outcomes so a totally-dead provider announces itself.
 *
 * Per-request logging alone is not enough to catch a provider outage: each
 * individual failure looks like one unlucky title, and at DEBUG it is invisible
 * anyway. What distinguishes "this obscure film is not in TMDB" from "TMDB has
 * rejected every single call we have made" is the *aggregate* — so this tracker
 * keeps a running tally per provider and escalates when the tally says the
 * provider is not working at all.
 *
 * The `ever_succeeded` flag is the sharpest signal here. A provider that has
 * never once returned a usable response since worker start is misconfigured,
 * not unlucky, and that is precisely the state an invalid `tmdb.api_key` puts
 * the server in.
 *
 * **Resident-worker safety:** the stats map is bounded to {@see MAX_PROVIDERS}
 * entries, and escalation logs are rate-limited to a geometric schedule
 * ({@see ESCALATION_THRESHOLDS}) so a sustained outage cannot flood the log.
 *
 * @author Phlix Development Team
 * @version 1.0.0
 * @description Aggregate health tracking for metadata providers
 * @see MetadataHttpClient Which feeds this tracker on every request
 */
class ProviderHealthTracker
{
    /**
     * Consecutive-failure counts at which an escalation is logged.
     *
     * Geometric rather than periodic so a long outage produces a handful of
     * lines rather than one per request. Past the final threshold, escalation
     * repeats every {@see ESCALATION_REPEAT_EVERY} failures.
     *
     * @var list<int>
     */
    private const ESCALATION_THRESHOLDS = [1, 10, 100, 1000];

    /** Escalate every N consecutive failures once past the final threshold. */
    private const ESCALATION_REPEAT_EVERY = 1000;

    /** Maximum distinct providers tracked before the oldest entry is evicted. */
    private const MAX_PROVIDERS = 64;

    /**
     * Per-provider counters.
     *
     * @var array<string, array{
     *     successes: int,
     *     failures: int,
     *     consecutive_failures: int,
     *     ever_succeeded: bool,
     *     last_failure_kind: string|null
     * }>
     */
    private array $stats = [];

    /** @var StructuredLogger Structured logger instance */
    private StructuredLogger $logger;

    /**
     * Constructor for ProviderHealthTracker.
     *
     * @param StructuredLogger|null $logger Optional logger; defaults to MEDIA channel.
     */
    public function __construct(?StructuredLogger $logger = null)
    {
        $this->logger = $logger ?? LoggerFactory::get(LogChannels::MEDIA);
    }

    /**
     * Record a successful request, clearing the consecutive-failure streak.
     *
     * Logs at INFO when a provider recovers from a streak that had already been
     * escalated, so the log shows the outage closing and not just opening.
     *
     * @param string $provider Provider label, e.g. `tmdb`.
     */
    public function recordSuccess(string $provider): void
    {
        $entry = $this->entryFor($provider);
        $priorStreak = $entry['consecutive_failures'];

        $entry['successes']++;
        $entry['consecutive_failures'] = 0;
        $entry['ever_succeeded'] = true;
        $entry['last_failure_kind'] = null;
        $this->store($provider, $entry);

        if ($priorStreak >= self::ESCALATION_THRESHOLDS[0]) {
            $this->logger->info('Metadata provider recovered', [
                'provider' => $provider,
                'failed_streak' => $priorStreak,
                'successes' => $entry['successes'],
            ]);
        }
    }

    /**
     * Record a failed request and escalate when the streak crosses a threshold.
     *
     * @param string              $provider Provider label, e.g. `tmdb`.
     * @param MetadataFailureKind $kind     Why the request failed.
     * @param array<string, mixed> $context Extra log context (endpoint, status, …).
     */
    public function recordFailure(string $provider, MetadataFailureKind $kind, array $context = []): void
    {
        $entry = $this->entryFor($provider);
        $entry['failures']++;
        $entry['consecutive_failures']++;
        $entry['last_failure_kind'] = $kind->value;
        $this->store($provider, $entry);

        if (!$this->shouldEscalate($entry['consecutive_failures'])) {
            return;
        }

        $neverSucceeded = !$entry['ever_succeeded'];

        $this->logger->error('Metadata provider is failing', array_merge($context, [
            'provider' => $provider,
            'reason' => $kind->reason(),
            'outcome' => $kind->value,
            'consecutive_failures' => $entry['consecutive_failures'],
            'successes' => $entry['successes'],
            'failures' => $entry['failures'],
            'ever_succeeded' => $entry['ever_succeeded'],
            // The operator-facing headline: a provider with zero lifetime
            // successes is misconfigured, and every "no match" it produced is
            // an artefact rather than a real absence.
            'diagnosis' => $neverSucceeded
                ? 'provider has NEVER succeeded since worker start — check the API key; '
                    . 'any "no match" results from this provider are unreliable'
                : 'provider was working earlier and is now failing',
        ]));
    }

    /**
     * Whether a provider is currently believed healthy.
     *
     * A provider with no recorded activity counts as healthy — absence of
     * evidence is not evidence of failure.
     *
     * @param string $provider Provider label.
     *
     * @return bool False once the consecutive-failure streak has reached the
     *              first escalation threshold.
     */
    public function isHealthy(string $provider): bool
    {
        $entry = $this->stats[$provider] ?? null;
        if ($entry === null) {
            return true;
        }

        return $entry['consecutive_failures'] < self::ESCALATION_THRESHOLDS[0];
    }

    /**
     * Current counters for every tracked provider.
     *
     * Intended for an admin health endpoint or a periodic health sweep.
     *
     * @return array<string, array{
     *     successes: int,
     *     failures: int,
     *     consecutive_failures: int,
     *     ever_succeeded: bool,
     *     last_failure_kind: string|null
     * }> Snapshot keyed by provider label.
     */
    public function snapshot(): array
    {
        return $this->stats;
    }

    /**
     * Clear all tracked counters.
     */
    public function reset(): void
    {
        $this->stats = [];
    }

    /**
     * Whether a streak of $consecutiveFailures warrants an escalation log line.
     *
     * @param int $consecutiveFailures Current streak length.
     *
     * @return bool True at each geometric threshold, then every
     *              {@see ESCALATION_REPEAT_EVERY} failures.
     */
    private function shouldEscalate(int $consecutiveFailures): bool
    {
        if (in_array($consecutiveFailures, self::ESCALATION_THRESHOLDS, true)) {
            return true;
        }

        $last = self::ESCALATION_THRESHOLDS[count(self::ESCALATION_THRESHOLDS) - 1];

        return $consecutiveFailures > $last
            && $consecutiveFailures % self::ESCALATION_REPEAT_EVERY === 0;
    }

    /**
     * Fetch (or initialise) the counters for a provider.
     *
     * @param string $provider Provider label.
     *
     * @return array{
     *     successes: int,
     *     failures: int,
     *     consecutive_failures: int,
     *     ever_succeeded: bool,
     *     last_failure_kind: string|null
     * } Existing or zeroed counters.
     */
    private function entryFor(string $provider): array
    {
        return $this->stats[$provider] ?? [
            'successes' => 0,
            'failures' => 0,
            'consecutive_failures' => 0,
            'ever_succeeded' => false,
            'last_failure_kind' => null,
        ];
    }

    /**
     * Write counters back, evicting the oldest provider when at capacity.
     *
     * @param string $provider Provider label.
     * @param array{
     *     successes: int,
     *     failures: int,
     *     consecutive_failures: int,
     *     ever_succeeded: bool,
     *     last_failure_kind: string|null
     * } $entry Counters to store.
     */
    private function store(string $provider, array $entry): void
    {
        $this->stats[$provider] = $entry;

        if (count($this->stats) > self::MAX_PROVIDERS) {
            $oldest = array_key_first($this->stats);
            if ($oldest !== null && $oldest !== $provider) {
                unset($this->stats[$oldest]);
            }
        }
    }
}
