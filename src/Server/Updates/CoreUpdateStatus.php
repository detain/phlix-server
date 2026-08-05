<?php

/**
 * Phlix media server component: Updates.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Updates;

/**
 * Immutable result of a core (server application) update check (S74).
 *
 * Returned by {@see CoreUpdateCheckService::status()} and serialised straight
 * onto `GET /api/v1/admin/updates/status`. Field names are camelCase and the
 * envelope is `{success, data}` so S76's shared `UpdateAvailableBanner.vue` can
 * consume this endpoint and phlix-hub's twin with ONE parser.
 *
 * `updateCommand` is a copy-to-clipboard string ONLY: the server never runs
 * git/composer/systemctl on an operator's behalf, so there is deliberately no
 * "apply" counterpart to this DTO.
 *
 * @package Phlix\Server\Updates
 * @since   S74 (core update check)
 */
final class CoreUpdateStatus
{
    /**
     * @param string      $currentVersion  Compiled {@see \Phlix\Common\Version::STRING}.
     * @param string|null $latestVersion   Last successfully fetched marker, or null if never fetched.
     * @param bool        $updateAvailable True when `latestVersion` is strictly newer than `currentVersion`.
     * @param bool        $checkEnabled    Effective `updates.check_enabled`.
     * @param int|null    $lastCheckedAt   Unix timestamp of the last COMPLETED check, or null.
     * @param string|null $lastError       Error from the last failed check, or null when the last check was clean.
     * @param string      $updateCommand   Copy-to-clipboard shell command that performs the update.
     */
    public function __construct(
        public readonly string $currentVersion,
        public readonly ?string $latestVersion,
        public readonly bool $updateAvailable,
        public readonly bool $checkEnabled,
        public readonly ?int $lastCheckedAt,
        public readonly ?string $lastError,
        public readonly string $updateCommand,
    ) {
    }

    /**
     * JSON-ready payload for the admin status endpoint.
     *
     * @return array{
     *     currentVersion: string,
     *     latestVersion: string|null,
     *     updateAvailable: bool,
     *     checkEnabled: bool,
     *     lastCheckedAt: int|null,
     *     lastError: string|null,
     *     updateCommand: string
     * }
     */
    public function toArray(): array
    {
        return [
            'currentVersion'  => $this->currentVersion,
            'latestVersion'   => $this->latestVersion,
            'updateAvailable' => $this->updateAvailable,
            'checkEnabled'    => $this->checkEnabled,
            'lastCheckedAt'   => $this->lastCheckedAt,
            'lastError'       => $this->lastError,
            'updateCommand'   => $this->updateCommand,
        ];
    }
}
