<?php

/**
 * Phlix media server component: Stats.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Stats;

/**
 * Contract for the AD-27 client telemetry landing (S518).
 *
 * Separates the consent-gated writer (the controller: "shape it, gate it,
 * record it, never fail the client over it") from its storage. The only
 * production implementation is {@see ClientHeartbeatStore}; the interface
 * exists so the unit venue can double all three verbs of a `final` DB-backed
 * class (the estate's store-interface doctrine).
 *
 * @package Phlix\Stats
 * @since 1.2.3
 */
interface ClientHeartbeatStoreInterface
{
    /** Column-width caps mirroring the migration-106 schema (parse-boundary contract). */
    public const int MAX_INSTANCE_ID_LENGTH = 64;

    public const int MAX_VERSION_LENGTH = 32;

    public const int MAX_CLIENT_TYPE_LENGTH = 32;

    public const int MAX_BUILD_TOKEN_LENGTH = 64;

    /**
     * Best-effort upsert of one consenting client's tick.
     *
     * @return bool True only when the row provably landed; never throws.
     */
    public function record(string $instanceId, string $version, string $clientType, string $buildToken): bool;

    /**
     * Most-recently-seen consenting instances, bounded page.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 200): array;

    /** Total distinct consenting instances (census denominator). */
    public function countInstances(): int;
}
