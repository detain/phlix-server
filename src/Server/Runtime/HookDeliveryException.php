<?php

/**
 * Phlix media server component: Runtime.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Runtime;

/**
 * Thrown when the curated coroutine-hook mask was NOT demonstrably delivered
 * to the worker that just started — or when the delivery probe could not reach
 * a verdict.
 *
 * S433 exists because every check available before it (`Coroutine::getOptions()`
 * and the mask arithmetic pinned by `SwooleRuntimeTest`) reported SUCCESS
 * whether or not the allowlist had physically landed. So BOTH failure shapes
 * are loud: a detected mismatch, and an inconclusive probe. A check that
 * cannot decide must never be scored as a pass — the same "gate that proves
 * nothing" defect class S146 removed from the CI jobs.
 *
 * @package Phlix\Server\Runtime
 * @since 1.2.4
 */
final class HookDeliveryException extends \RuntimeException
{
}
