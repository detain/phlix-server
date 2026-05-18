<?php

declare(strict_types=1);

namespace Phlex\Arr;

/**
 * Common interface for Sonarr/Radarr API clients.
 *
 * @package Phlex\Arr
 * @since 0.12.0
 */
interface ArrClientInterface
{
    /**
     * Returns the current download/activity queue.
     *
     * @return array<int, array<string, mixed>> Queue items.
     */
    public function getQueue(): array;

    /**
     * Returns available quality profiles.
     *
     * @return array<int, array<string, mixed>> Quality profiles.
     */
    public function getQualityProfiles(): array;

    /**
     * Returns all configured tags.
     *
     * @return array<int, array<string, mixed>> Tags.
     */
    public function getTagList(): array;

    /**
     * Tests connectivity and authentication with the *arr instance.
     *
     * @return bool True if connection is successful, false otherwise.
     */
    public function testConnection(): bool;
}
