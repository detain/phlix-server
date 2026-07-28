<?php

declare(strict_types=1);

namespace Phlix\Tests\Support\Database;

use Exception;

/**
 * S126 — raised when a listener ACCEPTED a TCP connection on the configured
 * MySQL host/port but no query could actually be run over it.
 *
 * A distinct class so the outcome is greppable in a CI log and cannot be
 * confused with an unrelated `\RuntimeException` thrown from production code
 * under test. See {@see IntegrationDbGuard} for why this is raised rather than
 * skipped.
 *
 * ⚠ Extends {@see Exception} DIRECTLY, not `\RuntimeException`. S120's two named
 * swallower shapes are `catch (\Throwable)` and `catch (\RuntimeException)`; a
 * pre-existing `catch (RuntimeException $e)` anywhere between a
 * `requireRealDatabase()` call and the test body would silently absorb this and
 * restore the exact "broken DB reports as absent" outcome S126 removed —
 * `MusicScanIncrementalFlushIntegrationTest.php:225` already has one (around a
 * scanner call, so it is not live today). Dropping `RuntimeException` removes
 * one of the two swallowers at zero cost: nothing in the tree catches or
 * type-asserts this class. `tests/Unit/Support/IntegrationDbGuardAdoptionTest.php`
 * covers the other by flagging a `catch` around the guard that ends in
 * `markTestSkipped()`.
 */
final class IntegrationDbUnusableException extends Exception
{
}
