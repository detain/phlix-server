<?php

/**
 * Phlix media server component: Common.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Common;

use Phlix\Shared\Database\Uuid as SharedUuid;

/**
 * External-contract shell delegating to the shared Uuid kernel.
 *
 * The v4()/isV4() rules live in Phlix\Shared\Database\Uuid (the v0.57 S1.7
 * thin-delegate idiom); the cross-kernel differential pins in
 * tests/Unit/Common/UuidTest.php and the SharedDelegationCensus carrier row
 * for this file together make a re-inlined body loud.
 *
 * The class itself cannot be removed: plugins receive `Phlix\Common\Uuid` as
 * a HOST-PROVIDED class — phlix-plugin-trakt imports this exact FQCN in
 * src/DbTraktOAuthStateStore.php, stubs it via class_alias in its
 * tests/bootstrap.php ("host-supplied"), and documents it as host-provided
 * in its README. Deleting the name breaks the plugin estate, so the name
 * stays and the RULE moves.
 *
 * The S443 entropy law (ids come from a CSPRNG that `mt_srand()` cannot
 * steer — the same 1062-duplicate-PK history recorded in the kernel's
 * docblock, where the full law text now lives) has exactly one body: the
 * kernel's random_bytes implementation. This shell mints nothing itself.
 *
 * @package Phlix\Common
 */
final readonly class Uuid
{
    /**
     * Generate a random UUID v4 string from the CSPRNG.
     *
     * @return string UUID in standard format xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
     */
    public static function v4(): string
    {
        return SharedUuid::v4();
    }

    /**
     * True when the value is exactly a canonical UUID v4 string.
     *
     * Boundary use is unchanged (S435 rawurldecode → path interpolation
     * requires a proven id); the pattern lives in the kernel's public
     * V4_PATTERN and this shell forwards the check.
     */
    public static function isV4(string $value): bool
    {
        return SharedUuid::isV4($value);
    }

    /**
     * Prevent instantiation — static methods only.
     */
    private function __construct()
    {
    }
}
