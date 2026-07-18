<?php

/**
 * Phlix media server component: Trakt.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Trakt;

use Phlix\Auth\WatchHistory;
use Workerman\MySQL\Connection;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\MediaItem;
use Phlix\Plugins\Contract\LifecycleInterface;
use Phlix\Shared\Events\Playback\PlaybackStarted;
use Phlix\Shared\Events\Playback\PlaybackStopped;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Trakt.tv scrobbler plugin entry class.
 *
 * Subscribes to PlaybackStarted and PlaybackStopped events and submits
 * scrobble data to Trakt.tv using the 3-state scrobble protocol (start/stop).
 *
 * Also handles two-way watch history sync:
 * - Phlix → Trakt: On PlaybackStopped with ≥ 90% completion
 * - Trakt → Phlix: On scheduled sync (via TraktHistorySync)
 *
 * @package Phlix\Plugins\Scrobbler\Trakt
 * @since 0.14.0
 */
final class TraktPlugin implements LifecycleInterface
{
    /**
     * Plugin type identifier used in the plugin manifest.
     */
    public const PLUGIN_TYPE = 'scrobbler';

    /**
     * Profile the scrobbler reconciles watch history into.
     *
     * Trakt is single-profile today: one connected Trakt account (one
     * {@see TraktSettings::$username}) with no per-Phlix-profile mapping, so
     * BOTH sync directions use this identifier — the push path
     * ({@see self::syncToTrakt()}) and the SV-3.6a pull path
     * ({@see self::syncHistoryFromTrakt()}). Multi-profile (a Trakt account per
     * Phlix profile, one sync per profile) is a future extension; when it lands,
     * the callers iterate over configured profiles instead of this constant.
     */
    public const DEFAULT_PROFILE_ID = 'default';

    private ?ItemRepository $itemRepository = null;
    private ?WatchHistory $watchHistory = null;
    private ?LoggerInterface $logger = null;
    private ?TraktApi $api = null;
    private ?Connection $db = null;

    /** Disables all scrobbling and sync when false. */
    private bool $enabled = false;

    /** User-specific settings (tokens, username, prefs). */
    private TraktSettings $settings;

    /**
     * @param TraktSettings|null $settings Initial settings (loaded from DB on enable)
     * @param LoggerInterface|null $logger Optional PSR-3 logger
     */
    public function __construct(
        ?TraktSettings $settings = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->settings = $settings ?? new TraktSettings();
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Configure the plugin from a settings array (persisted in the DB
     * by the plugin loader and passed back on enable).
     *
     * @param array<string, mixed> $settings Key-value settings from plugins.settings_json
     *
     * @return void
     *
     * @since 0.14.0
     */
    public function configure(array $settings): void
    {
        $this->settings = TraktSettings::fromArray($settings);
        $this->enabled = ($settings['enabled'] ?? false) === true;
    }

    /**
     * @param ContainerInterface $container Host PSR-11 container
     *
     * @return void
     *
     * @since 0.14.0
     */
    public function onEnable(ContainerInterface $container): void
    {
        if ($this->logger instanceof NullLogger) {
            $logger = $container->get(LoggerInterface::class);
            $this->logger = $logger instanceof LoggerInterface ? $logger : new NullLogger();
        }

        $itemRepo = $container->get(ItemRepository::class);
        $this->itemRepository = $itemRepo instanceof ItemRepository ? $itemRepo : null;

        $watchHist = $container->get(WatchHistory::class);
        $this->watchHistory = $watchHist instanceof WatchHistory ? $watchHist : null;

        $db = $container->get(Connection::class);
        $this->db = $db instanceof Connection ? $db : null;

        $this->initApi();
    }

    /**
     * Release resources on disable.
     *
     * @return void
     *
     * @since 0.14.0
     */
    public function onDisable(): void
    {
        $this->itemRepository = null;
        $this->watchHistory = null;
        $this->db = null;
    }

    /**
     * Run a Trakt → Phlix watched-history pull for the given profile.
     *
     * Thin wiring entry point for the resident-worker pull-sync Timer
     * (SV-3.6a, armed worker-0-gated in `start.php`). It mirrors the push path
     * {@see self::syncToTrakt()}: it builds a {@see TraktHistorySync} from the
     * plugin's current (DB-persisted) settings + collaborators and delegates the
     * actual reconciliation to {@see TraktHistorySync::syncTraktToPhlix()} — this
     * method adds NO reconciliation logic of its own (resume positions and
     * pagination live inside the sync, sub-steps 3.6c/3.6d).
     *
     * The resident server does not call {@see PluginLoader::bootstrapEnabled()}
     * at boot, so the Timer resolves a fresh entry instance each tick via
     * {@see PluginLoader::getEntryInstance()} — which applies the persisted
     * settings (so runtime enable/token changes are picked up) but does NOT call
     * {@see self::onEnable()}. This method therefore wires its runtime
     * collaborators itself (idempotently) when they are missing; `onEnable()` only
     * resolves container services + builds the API client (no I/O, no listener
     * subscriptions — that happens in {@see PluginLoader::enable()}), so it is
     * safe to call here on the sync cadence.
     *
     * @param ContainerInterface $container Host container for collaborator lookup.
     * @param string $profileId Profile to reconcile history into. Defaults to the
     *     single-profile {@see self::DEFAULT_PROFILE_ID}, consistent with the push
     *     path; multi-profile is a future extension.
     *
     * @return int Number of local history entries written (0 when not
     *     configured/enabled or two-way sync is disabled).
     *
     * @since 1.2.2 (SV-3.6a)
     */
    public function syncHistoryFromTrakt(
        ContainerInterface $container,
        string $profileId = self::DEFAULT_PROFILE_ID,
    ): int {
        if ($this->api === null || $this->watchHistory === null || $this->db === null) {
            $this->onEnable($container);
        }

        if (!$this->isConfigured() || !$this->settings->syncEnabled) {
            return 0;
        }

        // Defensive: onEnable() leaves these null when operator credentials are
        // absent (initApi() no-ops) or a container binding is missing. Without
        // them a TraktHistorySync cannot be built, so skip this cycle.
        if ($this->api === null || $this->watchHistory === null || $this->db === null) {
            return 0;
        }

        $sync = new TraktHistorySync(
            $this->api,
            $this->watchHistory,
            $this->settings,
            $this->db,
            $this->logger,
        );

        return $sync->syncTraktToPhlix($profileId);
    }

    /**
     * Return the event subscriptions for this plugin.
     *
     * @return array<class-string, string|callable>
     *
     * @since 0.14.0
     */
    public function subscribedEvents(): array
    {
        return [
            PlaybackStarted::class => 'onPlaybackStarted',
            PlaybackStopped::class => 'onPlaybackStopped',
        ];
    }

    /**
     * Handle playback start — submit scrobble start to Trakt.
     *
     * @param PlaybackStarted $event The playback started event
     *
     * @return void
     *
     * @since 0.14.0
     */
    public function onPlaybackStarted(PlaybackStarted $event): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        if (!$this->settings->scrobbleEnabled) {
            $this->logger?->debug('Trakt: scrobble disabled, skipping start');
            return;
        }

        $mediaItem = $this->findMediaItem($event->mediaItemId);
        if ($mediaItem === null) {
            $this->logger?->debug('Trakt: media item not found', [
                'media_item_id' => $event->mediaItemId,
            ]);
            return;
        }

        $progressSecs = (int)($event->positionTicks / 10_000_000);

        /** @var TraktApi */
        $api = $this->api;

        try {
            $result = $api->scrobbleStart($mediaItem, $progressSecs, $this->settings->accessToken ?? '');

            $this->logger?->info('Trakt scrobble start submitted', [
                'title' => $mediaItem->name,
                'progress' => $progressSecs,
            ]);
        } catch (TraktAuthenticationException $e) {
            $this->logger?->warning('Trakt: scrobble start failed (auth), attempting token refresh', [
                'error' => $e->getMessage(),
            ]);

            if ($this->ensureFreshToken()) {
                // Retry once with the new token
                try {
                    $api->scrobbleStart($mediaItem, $progressSecs, $this->settings->accessToken ?? '');
                    $this->logger?->info('Trakt scrobble start submitted after token refresh', [
                        'title' => $mediaItem->name,
                        'progress' => $progressSecs,
                    ]);
                } catch (TraktAuthenticationException $retryAuthEx) {
                    $this->logger?->warning('Trakt: scrobble start still failing after refresh', [
                        'error' => $retryAuthEx->getMessage(),
                    ]);
                } catch (TraktApiException $retryEx) {
                    $this->logger?->warning('Trakt: scrobble start retry failed', [
                        'error' => $retryEx->getMessage(),
                    ]);
                }
            }
        } catch (TraktApiException $e) {
            $this->logger?->warning('Trakt: scrobble start failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle playback stop — submit scrobble stop to Trakt.
     *
     * Also triggers Phlix → Trakt sync if the item reached ≥ 90% completion.
     *
     * @param PlaybackStopped $event The playback stopped event
     *
     * @return void
     *
     * @since 0.14.0
     */
    public function onPlaybackStopped(PlaybackStopped $event): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        if (!$this->settings->scrobbleEnabled) {
            return;
        }

        $mediaItem = $this->findMediaItem($event->mediaItemId);
        if ($mediaItem === null) {
            $this->logger?->debug('Trakt: media item not found on stop', [
                'media_item_id' => $event->mediaItemId,
            ]);
            return;
        }

        $progressSecs = (int)($event->finalPositionTicks / 10_000_000);

        /** @var TraktApi */
        $api = $this->api;

        try {
            $result = $api->scrobbleStop($mediaItem, $progressSecs, $this->settings->accessToken ?? '');

            $this->logger?->info('Trakt scrobble stop submitted', [
                'title' => $mediaItem->name,
                'progress' => $progressSecs,
            ]);
        } catch (TraktAuthenticationException $e) {
            $this->logger?->warning('Trakt: scrobble stop failed (auth), attempting token refresh', [
                'error' => $e->getMessage(),
            ]);

            if ($this->ensureFreshToken()) {
                // Retry once with the new token
                try {
                    $api->scrobbleStop($mediaItem, $progressSecs, $this->settings->accessToken ?? '');
                    $this->logger?->info('Trakt scrobble stop submitted after token refresh', [
                        'title' => $mediaItem->name,
                        'progress' => $progressSecs,
                    ]);
                } catch (TraktAuthenticationException $retryAuthEx) {
                    $this->logger?->warning('Trakt: scrobble stop still failing after refresh', [
                        'error' => $retryAuthEx->getMessage(),
                    ]);
                } catch (TraktApiException $retryEx) {
                    $this->logger?->warning('Trakt: scrobble stop retry failed', [
                        'error' => $retryEx->getMessage(),
                    ]);
                }
            }
        } catch (TraktApiException $e) {
            $this->logger?->warning('Trakt: scrobble stop failed', [
                'error' => $e->getMessage(),
            ]);
        }

        if ($event->reachedEnd && $this->watchHistory !== null) {
            $this->syncToTrakt($event->mediaItemId, $event->finalPositionTicks);
        }
    }

    /**
     * Get the current settings.
     *
     * @return TraktSettings
     *
     * @since 0.14.0
     */
    public function getSettings(): TraktSettings
    {
        return $this->settings;
    }

    /**
     * Update the access token.
     *
     * @param string $token New access token
     *
     * @return void
     *
     * @since 0.14.0
     */
    public function setAccessToken(string $token): void
    {
        $this->settings = new TraktSettings(
            accessToken: $token,
            refreshToken: $this->settings->refreshToken,
            expiresAt: $this->settings->expiresAt,
            syncEnabled: $this->settings->syncEnabled,
            syncIntervalMinutes: $this->settings->syncIntervalMinutes,
            scrobbleEnabled: $this->settings->scrobbleEnabled,
            username: $this->settings->username,
        );
    }

    /**
     * Update the refresh token.
     *
     * @param string $token New refresh token
     *
     * @return void
     *
     * @since 0.14.0
     */
    public function setRefreshToken(string $token): void
    {
        $this->settings = new TraktSettings(
            accessToken: $this->settings->accessToken,
            refreshToken: $token,
            expiresAt: $this->settings->expiresAt,
            syncEnabled: $this->settings->syncEnabled,
            syncIntervalMinutes: $this->settings->syncIntervalMinutes,
            scrobbleEnabled: $this->settings->scrobbleEnabled,
            username: $this->settings->username,
        );
    }

    /**
     * Refresh the access token after an auth failure, with single-flight guard.
     *
     * When multiple concurrent scrobble calls each receive a 401, only one
     * actual token refresh POST is made. All callers await the same result.
     *
     * @return bool True if token was refreshed, false if no refresh token available
     *
     * @since 0.14.0
     */
    public function ensureFreshToken(): bool
    {
        // Single-flight mutex at the plugin level. Since TraktPlugin is a
        // singleton per plugin loader, this prevents concurrent coroutines
        // on the same worker from each POSTing a refresh.
        static $inFlightRefresh = null;

        if ($this->api === null) {
            $this->logger?->warning('Trakt: API not initialized');

            return false;
        }

        if ($this->settings->refreshToken === null || $this->settings->refreshToken === '') {
            $this->logger?->warning('Trakt: no refresh token available');

            return false;
        }

        // Key cache by token hash to avoid cross-token cache pollution
        $tokenKey = md5($this->settings->refreshToken);

        // If another call is already refreshing, spin until it completes
        // Use \Co\sleep to yield to the event loop instead of blocking with usleep()
        while (isset($inFlightRefresh[$tokenKey]) && $inFlightRefresh[$tokenKey] === 'pending') {
            if (function_exists('\Co\sleep')) {
                \Co\sleep(0.005); // 5ms - yields to event loop in async context
            } else {
                usleep(5000); // Fallback for non-Swoole (unit tests)
            }
        }

        if (isset($inFlightRefresh[$tokenKey]) && is_array($inFlightRefresh[$tokenKey])) {
            // Don't null out here - let subsequent calls use the same result
            return true;
        }

        $inFlightRefresh[$tokenKey] = 'pending';

        try {
            $refreshResult = $this->api->refreshAfterAuthFailure($this->settings->refreshToken);

            /** @var string $newAccessToken */
            $newAccessToken = is_string($refreshResult['access_token'] ?? null) ? $refreshResult['access_token'] : '';
            /** @var string $newRefreshToken */
            $newRefreshToken = is_string($refreshResult['refresh_token'] ?? null) ? $refreshResult['refresh_token'] :
                '';

            if ($newAccessToken === '') {
                $this->logger?->warning('Trakt: refresh returned empty access token');

                return false;
            }

            $this->setAccessToken($newAccessToken);

            if ($newRefreshToken !== '') {
                $this->setRefreshToken($newRefreshToken);
            }

            $inFlightRefresh[$tokenKey] = $refreshResult;

            $this->logger?->info('Trakt: token refreshed successfully');

            return true;
        } catch (TraktApiException $e) {
            unset($inFlightRefresh[$tokenKey]);
            $this->logger?->warning('Trakt: token refresh failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        } catch (\Throwable $e) {
            unset($inFlightRefresh[$tokenKey]);
            $this->logger?->error('Trakt: token refresh threw', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Initialize the Trakt API client from current settings.
     *
     * @return void
     */
    private function initApi(): void
    {
        $config = $this->loadConfig();

        $clientId = is_string($config['client_id'] ?? null) ? $config['client_id'] : '';
        $clientSecret = is_string($config['client_secret'] ?? null) ? $config['client_secret'] : '';

        if ($clientId !== '' && $clientSecret !== '') {
            $this->api = new TraktApi(
                new HttpClient(),
                $clientId,
                $clientSecret,
                $this->logger
            );
        }
    }

    /**
     * Whether the plugin has all required configuration.
     *
     * @return bool
     */
    private function isConfigured(): bool
    {
        return $this->enabled
            && $this->settings->isConfigured()
            && $this->api !== null;
    }

    /**
     * Look up a media item by ID.
     *
     * @param string $mediaItemId Media item UUID
     *
     * @return MediaItem|null
     */
    private function findMediaItem(string $mediaItemId): ?MediaItem
    {
        if ($this->itemRepository === null) {
            return null;
        }

        $row = $this->itemRepository->findById($mediaItemId);
        if ($row === null) {
            return null;
        }

        return MediaItem::fromRow($row);
    }

    /**
     * Sync completed playback to Trakt history.
     *
     * @param string $mediaItemId Media item ID
     * @param int $positionTicks Final position
     *
     * @return void
     */
    private function syncToTrakt(string $mediaItemId, int $positionTicks): void
    {
        if ($this->watchHistory === null || $this->api === null || $this->db === null) {
            return;
        }

        $entry = $this->watchHistory->getForMediaItem(self::DEFAULT_PROFILE_ID, $mediaItemId);
        $progress = $entry['progress_percent'] ?? 0;
        if ($entry === null || !is_numeric($progress) || (float) $progress < 90.0) {
            return;
        }

        $sync = new TraktHistorySync(
            $this->api,
            $this->watchHistory,
            $this->settings,
            $this->db,
            $this->logger
        );

        $lastWatchedAt = $entry['last_watched_at'] ?? 'now';
        $durationTicks = $entry['duration_ticks'] ?? null;

        $sync->syncPhlixToTrakt(
            $mediaItemId,
            is_string($lastWatchedAt) ? $lastWatchedAt : 'now',
            $positionTicks,
            is_numeric($durationTicks) ? (int) $durationTicks : null
        );
    }

    /**
     * Load Trakt plugin configuration.
     *
     * @return array<string, mixed>
     */
    private function loadConfig(): array
    {
        $configFile = dirname(__DIR__, 5) . '/config/scrobblers/trakt.php';

        if (is_file($configFile)) {
            /** @var array<string, mixed> $config */
            $config = include $configFile;

            return $config;
        }

        return [];
    }
}
