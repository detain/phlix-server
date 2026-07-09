<?php

/**
 * Phlix media server component: Webhooks.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Webhooks;

class DispatchResult
{
    /**
     * @param array<array<string, string>> $failures
     */
    public function __construct(
        public readonly int $successCount,
        public readonly int $failureCount,
        public readonly array $failures,
    ) {
    }
}
