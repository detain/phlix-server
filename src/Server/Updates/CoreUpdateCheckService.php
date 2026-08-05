<?php

/**
 * Phlix media server component: Updates.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Server\Updates;

use Phlix\Admin\SettingsRepository;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Common\Version;
use Throwable;

/**
 * Core (server application) update check — S74 / updates.md #48.
 *
 * Fetches the repository's root `VERSION` marker and compares it against the
 * compiled {@see Version::STRING} constant. The outcome is PERSISTED in
 * `server_settings` so the admin HTTP surface can answer
 * `GET /api/v1/admin/updates/status` from the database with **no outbound I/O
 * inside an HTTP handler** — the fetch happens on the count=1
 * `phlix-background-timers` worker ({@see CoreUpdateCheckWorker}), never on a
 * request.
 *
 * ## Persistence
 *
 * Three `server_settings` rows carry the check RESULT. They are read back
 * through `getOverride()` (not `getEffective()`), so they need no
 * `config/updates.php` default and can never be confused with a knob:
 *
 *  - `updates.latest_version`   — last successfully fetched marker.
 *  - `updates.last_checked_at`  — unix time of the last COMPLETED check.
 *  - `updates.last_error`       — error text of the last failed check (`''` when clean).
 *
 * The one genuine setting, `updates.check_enabled`, IS resolved as an
 * *effective* value (override → `config/updates.php` default), so an admin
 * toggle applies to the very next poll with no restart.
 *
 * ## What this class will never do
 *
 * It does not, and must not, apply an update: no git, no composer, no
 * systemctl, no shelling out at all. {@see status()} surfaces `updateCommand` —
 * a copy-to-clipboard one-liner the operator runs on the box themselves.
 *
 * @package Phlix\Server\Updates
 * @since   S74 (core update check)
 */
final class CoreUpdateCheckService
{
    /** Effective setting: master switch for the periodic check. */
    public const SETTING_CHECK_ENABLED = 'updates.check_enabled';

    /** Persisted result: last successfully fetched marker version. */
    public const STATE_LATEST_VERSION = 'updates.latest_version';

    /** Persisted result: unix timestamp of the last completed check. */
    public const STATE_LAST_CHECKED_AT = 'updates.last_checked_at';

    /** Persisted result: error text of the last failed check (empty string = clean). */
    public const STATE_LAST_ERROR = 'updates.last_error';

    /**
     * Accepted shape of a version marker: semver, optional leading `v`,
     * optional pre-release / build metadata.
     */
    private const MARKER_PATTERN = '/^v?(\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?)$/';

    /**
     * Completion callback for the in-flight {@see check()}, if any.
     *
     * Single-slot and cleared as soon as it fires: the only production caller
     * is the count=1 background-timer worker, which never has two checks in
     * flight, so this cannot grow without bound (resident-memory rule).
     *
     * @var (callable(CoreUpdateStatus):void)|null
     */
    private $pendingCompletion = null;

    /**
     * @param SettingsRepository            $settings       Server settings store (defaults + overrides + result rows).
     * @param VersionMarkerFetcherInterface $fetcher        Non-blocking marker fetcher.
     * @param StructuredLogger              $logger         Application logger.
     * @param string                        $markerUrl      Absolute URL of the remote `VERSION` marker.
     * @param string                        $updateCommand  Copy-to-clipboard update command.
     * @param string                        $currentVersion Compiled version; overridable for tests.
     */
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly VersionMarkerFetcherInterface $fetcher,
        private readonly StructuredLogger $logger,
        private readonly string $markerUrl,
        private readonly string $updateCommand,
        private readonly string $currentVersion = Version::STRING,
    ) {
    }

    /**
     * Effective `updates.check_enabled` (override → config default → true).
     *
     * Fail-OPEN: an absent/unreadable config resolves to enabled, because the
     * failure mode of checking when you meant not to is a single HTTP GET a
     * day, while the failure mode of the inverse is an operator who never
     * learns a security release shipped.
     *
     * @return bool
     */
    public function isCheckEnabled(): bool
    {
        /** @var mixed $value */
        $value = $this->settings->getEffective(self::SETTING_CHECK_ENABLED);
        if ($value === null) {
            return true;
        }

        return (bool) $value;
    }

    /**
     * Persist the `updates.check_enabled` override.
     *
     * @param bool $enabled New value.
     *
     * @return void
     */
    public function setCheckEnabled(bool $enabled): void
    {
        $this->settings->set(self::SETTING_CHECK_ENABLED, $enabled, 'bool');
    }

    /**
     * Run one check: fetch the marker, compare, persist, then report.
     *
     * Non-blocking — the fetch is handed to {@see VersionMarkerFetcherInterface}
     * and `$onComplete` fires on the event loop once the response (or the
     * error) arrives. When the check is DISABLED nothing is fetched and nothing
     * is persisted; `$onComplete` still receives the current persisted status,
     * so a caller never has to special-case the toggle.
     *
     * @param callable(CoreUpdateStatus):void|null $onComplete Optional completion callback.
     *
     * @return void
     */
    public function check(?callable $onComplete = null): void
    {
        if (!$this->isCheckEnabled()) {
            $this->logger->debug('Updates: core update check is disabled, skipping fetch');
            $this->complete($onComplete);
            return;
        }

        // ORDER MATTERS: the completion slot is armed BEFORE the fetch is
        // issued. A fetcher is free to call back synchronously (every test
        // double does, and a cached/failed-fast transport may too), in which
        // case record() -> complete() runs inside the call below — arming the
        // slot afterwards would silently drop the callback.
        $this->pendingCompletion = $onComplete;

        $this->fetcher->fetch($this->markerUrl, function (?string $body, ?string $error): void {
            $this->record($body, $error);
        });
    }

    /**
     * Current status, assembled from persisted state. Performs NO network I/O,
     * which is what makes it safe to call from an HTTP handler.
     *
     * @return CoreUpdateStatus
     */
    public function status(): CoreUpdateStatus
    {
        $latest    = $this->readStateString(self::STATE_LATEST_VERSION);
        $checkedAt = $this->readStateInt(self::STATE_LAST_CHECKED_AT);
        $error     = $this->readStateString(self::STATE_LAST_ERROR);

        return new CoreUpdateStatus(
            $this->currentVersion,
            $latest,
            $latest !== null && self::isNewer($latest, $this->currentVersion),
            $this->isCheckEnabled(),
            $checkedAt,
            $error,
            $this->updateCommand,
        );
    }

    /**
     * Strict "is `$candidate` newer than `$current`" comparison.
     *
     * Both sides are normalised (trimmed, optional leading `v` removed) and
     * must match {@see self::MARKER_PATTERN}; anything else is NOT newer, so a
     * garbage marker can never raise a false "update available".
     *
     * @param string $candidate Remote marker version.
     * @param string $current   Compiled version.
     *
     * @return bool
     */
    public static function isNewer(string $candidate, string $current): bool
    {
        $left  = self::normalise($candidate);
        $right = self::normalise($current);
        if ($left === null || $right === null) {
            return false;
        }

        return version_compare($left, $right, '>');
    }

    /**
     * Validate + normalise a raw marker body into a bare semver string.
     *
     * @param string $raw Raw marker text (may carry a trailing newline / `v` prefix).
     *
     * @return string|null Normalised semver, or null when the input is not a version.
     */
    public static function normalise(string $raw): ?string
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        $matches = [];
        if (preg_match(self::MARKER_PATTERN, $trimmed, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * Persist the outcome of one completed fetch and fire the pending
     * completion callback.
     *
     * @param string|null $body  Marker body when the fetch succeeded.
     * @param string|null $error Error text when it did not.
     *
     * @return void
     */
    private function record(?string $body, ?string $error): void
    {
        $now = time();

        try {
            if ($error !== null) {
                $this->settings->set(self::STATE_LAST_ERROR, $error, 'string');
                $this->settings->set(self::STATE_LAST_CHECKED_AT, $now, 'int');
                $this->logger->warning('Updates: core update check failed', [
                    'url'   => $this->markerUrl,
                    'error' => $error,
                ]);
                $this->complete();
                return;
            }

            $latest = self::normalise((string) $body);
            if ($latest === null) {
                $message = 'update check: version marker is not a semver string';
                $this->settings->set(self::STATE_LAST_ERROR, $message, 'string');
                $this->settings->set(self::STATE_LAST_CHECKED_AT, $now, 'int');
                $this->logger->warning('Updates: core update check returned an unusable marker', [
                    'url' => $this->markerUrl,
                ]);
                $this->complete();
                return;
            }

            $this->settings->set(self::STATE_LATEST_VERSION, $latest, 'string');
            $this->settings->set(self::STATE_LAST_ERROR, '', 'string');
            $this->settings->set(self::STATE_LAST_CHECKED_AT, $now, 'int');

            $this->logger->info('Updates: core update check completed', [
                'current'          => $this->currentVersion,
                'latest'           => $latest,
                'update_available' => self::isNewer($latest, $this->currentVersion),
            ]);
        } catch (Throwable $e) {
            // A DB hiccup on a background poll must never escape into the timer.
            $this->logger->error('Updates: failed to persist core update check result', [
                'error' => $e->getMessage(),
            ]);
        }

        $this->complete();
    }

    /**
     * Fire (and clear) the pending completion callback with a fresh status.
     *
     * @param (callable(CoreUpdateStatus):void)|null $override Callback to use instead of the pending one.
     *
     * @return void
     */
    private function complete(?callable $override = null): void
    {
        $callback = $override ?? $this->pendingCompletion;
        $this->pendingCompletion = null;
        if ($callback === null) {
            return;
        }

        try {
            $callback($this->status());
        } catch (Throwable $e) {
            $this->logger->error('Updates: core update check completion callback failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Read a persisted string state row, or null when unset/blank.
     *
     * @param string $key Dotted state key.
     *
     * @return string|null
     */
    private function readStateString(string $key): ?string
    {
        $row = $this->settings->getOverride($key);
        if ($row === null) {
            return null;
        }

        /** @var mixed $value */
        $value = $row['value'];
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    /**
     * Read a persisted integer state row, or null when unset/zero.
     *
     * @param string $key Dotted state key.
     *
     * @return int|null
     */
    private function readStateInt(string $key): ?int
    {
        $row = $this->settings->getOverride($key);
        if ($row === null) {
            return null;
        }

        /** @var mixed $value */
        $value = $row['value'];
        if (!is_int($value) || $value <= 0) {
            return null;
        }

        return $value;
    }
}
