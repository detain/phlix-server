<?php

declare(strict_types=1);

namespace Phlex\Webhooks;

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
