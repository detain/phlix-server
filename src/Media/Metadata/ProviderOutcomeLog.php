<?php

/**
 * Phlix media server component: Metadata.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Metadata;

use Phlix\Common\Logger\StructuredLogger;

/**
 * Logs a provider lookup that produced no usable data, at the level its cause deserves.
 *
 * Every metadata provider used to funnel "no data" into a single DEBUG line —
 * `TmdbProvider: search miss` — regardless of whether the title genuinely was
 * not in the upstream database or the provider had rejected our API key. Those
 * two readings are opposite in meaning: the first is an expected outcome of any
 * library scan, the second means every match result the scan produced is
 * worthless. Collapsing them cost days of undetected TMDB downtime and a
 * silently invalidated match-rate baseline.
 *
 * This helper keeps the historic `"<Provider>: <operation> miss"` message for
 * genuine misses — so existing log greps and dashboards keep working — and
 * emits a distinct, higher-severity message for anything that means the
 * provider did not serve us.
 *
 * @author Phlix Development Team
 * @version 1.0.0
 * @description Level-appropriate logging for empty provider lookups
 * @see MetadataFailureKind::logLevel() For the level mapping
 */
final class ProviderOutcomeLog
{
    /**
     * Log an empty provider lookup, distinguishing a miss from a failure.
     *
     * @param StructuredLogger     $logger    Provider's logger.
     * @param string               $provider  Provider class label, e.g. `TmdbProvider`.
     * @param string               $operation Operation name, e.g. `search`.
     * @param MetadataHttpResult   $result    Outcome from {@see MetadataHttpClient::getResult()}.
     * @param array<string, mixed> $context   Extra log context (query, ids, endpoint, …).
     */
    public static function record(
        StructuredLogger $logger,
        string $provider,
        string $operation,
        MetadataHttpResult $result,
        array $context = []
    ): void {
        $merged = array_merge($context, $result->logContext(), ['provider' => $provider]);

        if ($result->isFailure()) {
            $logger->log(
                $result->kind->logLevel(),
                sprintf('%s: %s FAILED (not a miss) — %s', $provider, $operation, $result->kind->reason()),
                $merged
            );

            return;
        }

        // Either a 2xx whose payload lacked the expected key, or a legitimate
        // upstream "not found". Both are ordinary; keep them at DEBUG.
        $logger->debug(sprintf('%s: %s miss', $provider, $operation), $merged);
    }
}
