<?php

declare(strict_types=1);

namespace Phlex\Plugins\Lastfm;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Phlex\Media\Library\ItemRepository;
use Phlex\Plugins\Contract\LifecycleInterface;
use Phlex\Session\SessionManager;
use Phlex\Shared\Events\Playback\PlaybackStarted;
use Phlex\Shared\Events\Playback\PlaybackStopped;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Last.fm scrobbler plugin entry class.
 *
 * Subscribes to {@see PlaybackStopped} (and optionally
 * {@see PlaybackStarted} for Now Playing updates) and submits
 * scrobble data to the Last.fm `track.scrobble` API when the
 * configured threshold is met.
 *
 * Implements:
 *  - {@see LifecycleInterface} — plugin lifecycle (onEnable / onDisable).
 *  - {@see EventSubscriberInterface} — PSR-14 event subscriptions.
 *
 * @package Phlex\Plugins\Lastfm
 * @since 0.15.0
 */
final class Plugin implements LifecycleInterface, EventSubscriberInterface
{
    /**
     * Plugin type identifier used in the plugin manifest.
     */
    public const PLUGIN_TYPE = 'scrobbler';

    private ?ItemRepository $itemRepository = null;
    private ?LoggerInterface $logger = null;

    /** Disables scrobbling when false even if fully configured. */
    private bool $enabled = false;

    /** Last.fm API key from config. */
    private string $apiKey = '';

    /** Last.fm API secret from config. */
    private string $apiSecret = '';

    /** Authenticated session key; empty when not yet authenticated. */
    private string $sessionKey = '';

    /** Username for scrobble attribution. */
    private string $username = '';

    /** Whether to send Now Playing on track start. */
    private bool $submitNowPlaying = true;

    /**
     * Fraction of track duration that must be played before a scrobble
     * is submitted (0.0 – 1.0). Defaults to 0.5 (50%).
     */
    private float $scrobbleThreshold = 0.5;

    /**
     * In-memory flag so we don't re-validate the session key on every
     * scrobble — validation only happens once per plugin enable cycle.
     */
    private bool $sessionValidated = false;

    /**
     * @param LoggerInterface|null $logger Optional PSR-3 logger.
     */
    public function __construct(
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger;
    }

    /**
     * Configure the plugin from a settings array (persisted in the DB
     * by the plugin loader and passed back on enable).
     *
     * @param array<string, mixed> $settings Key-value settings from
     *                                      `plugins.settings_json`.
     *
     * @return void
     */
    public function configure(array $settings): void
    {
        $this->apiKey = is_string($settings['api_key'] ?? null) ? $settings['api_key'] : '';
        $this->apiSecret = is_string($settings['api_secret'] ?? null) ? $settings['api_secret'] : '';
        $this->sessionKey = is_string($settings['session_key'] ?? null) ? $settings['session_key'] : '';
        $this->username = is_string($settings['username'] ?? null) ? $settings['username'] : '';
        $submitNp = $settings['submit_now_playing'] ?? null;
        $this->submitNowPlaying = is_bool($submitNp) ? $submitNp : true;
        $threshold = $settings['scrobble_threshold'] ?? null;
        $this->scrobbleThreshold = is_numeric($threshold) ? (float) $threshold : 0.5;
        $enabled = $settings['enabled'] ?? null;
        $this->enabled = is_bool($enabled) ? $enabled : false;
    }

    /**
     * Build a new configured LastfmApiClient from current settings.
     *
     * Exposed so tests can call `new LastfmApiClient(...)` directly
     * while Plugin can call this for re-initialisation.
     *
     * @return LastfmApiClientInterface
     */
    public function buildApiClient(): LastfmApiClientInterface
    {
        return new LastfmApiClient($this->apiKey, $this->apiSecret, $this->logger);
    }

    /**
     * @param ContainerInterface $container Host PSR-11 container.
     *
     * @return void
     */
    public function onEnable(ContainerInterface $container): void
    {
        if ($this->logger === null) {
            $logger = $container->get(LoggerInterface::class);
            $this->logger = $logger instanceof LoggerInterface ? $logger : null;
        }
        $itemRepo = $container->get(ItemRepository::class);
        $this->itemRepository = $itemRepo instanceof ItemRepository ? $itemRepo : null;
    }

    /**
     * Release resources on disable.
     *
     * @return void
     */
    public function onDisable(): void
    {
        $this->itemRepository = null;
        $this->sessionValidated = false;
    }

    /**
     * Return the event subscriptions for this plugin.
     *
     * @return array<class-string, string|callable>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            PlaybackStopped::class => 'onPlaybackStopped',
            PlaybackStarted::class => 'onPlaybackStarted',
        ];
    }

    /**
     * @return array<class-string, string|callable>
     */
    public function subscribedEvents(): array
    {
        return self::getSubscribedEvents();
    }

    /**
     * Handle playback start — submit Now Playing to Last.fm if configured.
     *
     * @param PlaybackStarted $event The playback started event.
     *
     * @return void
     */
    public function onPlaybackStarted(PlaybackStarted $event): void
    {
        if (!$this->submitNowPlaying) {
            return;
        }

        if (!$this->isConfigured()) {
            return;
        }

        $mediaItem = $this->findMediaItem($event->mediaItemId);
        if ($mediaItem === null) {
            $this->logger?->debug('Last.fm: media item not found for Now Playing', [
                'media_item_id' => $event->mediaItemId,
            ]);
            return;
        }

        $nowPlaying = $this->buildNowPlayingData($mediaItem, $event->positionTicks);
        if ($nowPlaying === null) {
            return;
        }

        try {
            $api = $this->buildApiClient();
            $success = $api->nowPlaying($nowPlaying);
            $this->logger?->debug('Last.fm nowPlaying submitted', [
                'artist' => $nowPlaying->artist_name,
                'track' => $nowPlaying->track_title,
                'success' => $success,
            ]);
        } catch (LastfmPluginNotConfiguredException | LastfmScrobbleFailedException $e) {
            $this->logger?->warning('Last.fm nowPlaying failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle playback stop — submit scrobble to Last.fm if threshold met.
     *
     * A scrobble is only submitted when:
     *  - the plugin is `enabled` in config
     *  - `api_key`, `api_secret`, `session_key` are all non-empty
     *  - `finalPositionTicks / durationTicks >= scrobble_threshold`
     *
     * @param PlaybackStopped $event The playback stopped event.
     *
     * @return void
     */
    public function onPlaybackStopped(PlaybackStopped $event): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        // Validate session key once per plugin lifecycle
        if (!$this->sessionValidated) {
            $api = $this->buildApiClient();
            if (!$api->validateSession($this->sessionKey)) {
                $this->logger?->warning('Last.fm session key is invalid', [
                    'username' => $this->username,
                ]);
                return;
            }
            $this->sessionValidated = true;
        }

        $mediaItem = $this->findMediaItem($event->mediaItemId);
        if ($mediaItem === null) {
            $this->logger?->debug('Last.fm: media item not found for scrobble', [
                'media_item_id' => $event->mediaItemId,
            ]);
            return;
        }

        $scrobbleData = $this->buildScrobbleData($mediaItem, $event->finalPositionTicks, $event->reachedEnd);
        if ($scrobbleData === null) {
            return;
        }

        // Apply scrobble threshold check
        if (!$this->meetsThreshold($mediaItem, $event->finalPositionTicks)) {
            $this->logger?->debug('Last.fm: scrobble threshold not met', [
                'media_item_id' => $event->mediaItemId,
                'position_ticks' => $event->finalPositionTicks,
            ]);
            return;
        }

        try {
            $api = $this->buildApiClient();
            $success = $api->scrobble($scrobbleData);
            $this->logger?->info('Last.fm scrobble submitted', [
                'artist' => $scrobbleData->artist_name,
                'track' => $scrobbleData->track_title,
                'timestamp' => $scrobbleData->timestamp_unix,
                'success' => $success,
            ]);
        } catch (LastfmScrobbleFailedException $e) {
            $this->logger?->warning('Last.fm scrobble failed', [
                'error' => $e->getMessage(),
                'artist' => $scrobbleData->artist_name,
                'track' => $scrobbleData->track_title,
            ]);
        }
    }

    /**
     * @return string The plugin type identifier ('scrobbler').
     */
    public function getPluginType(): string
    {
        return self::PLUGIN_TYPE;
    }

    /**
     * @return string The plugin name ('lastfm').
     */
    public function getPluginName(): string
    {
        return 'lastfm';
    }

    /**
     * Whether the plugin has all required configuration to attempt
     * API calls (api_key, api_secret, and session_key all non-empty).
     *
     * @return bool
     */
    private function isConfigured(): bool
    {
        return $this->enabled
            && $this->apiKey !== ''
            && $this->apiSecret !== ''
            && $this->sessionKey !== '';
    }

    /**
     * Check if the scrobble threshold is met.
     *
     * The threshold is met when:
     *   (finalPositionTicks / durationTicks) >= scrobbleThreshold
     *
     * @param array<string, mixed> $mediaItem        Hydrated media item.
     * @param int                 $finalPositionTicks Final position in ticks.
     *
     * @return bool True when threshold is met or duration is unknown.
     */
    private function meetsThreshold(array $mediaItem, int $finalPositionTicks): bool
    {
        $durationTicksRaw = $mediaItem['duration_ticks'] ?? 0;
        $durationTicks = is_numeric($durationTicksRaw) ? (int) $durationTicksRaw : 0;
        if ($durationTicks <= 0) {
            // Unknown duration — be permissive and allow scrobble
            return true;
        }

        $fraction = $finalPositionTicks / $durationTicks;
        return $fraction >= $this->scrobbleThreshold;
    }

    /**
     * Look up a media item by ID.
     *
     * @param string $mediaItemId Media item UUID.
     *
     * @return array<string, mixed>|null Hydrated media item or null if not found.
     */
    private function findMediaItem(string $mediaItemId): ?array
    {
        if ($this->itemRepository === null) {
            return null;
        }

        return $this->itemRepository->findById($mediaItemId);
    }

    /**
     * Extract {@see ScrobbleData} from a hydrated media item.
     *
     * Returns null when the media item has no usable track metadata.
     *
     * @param array<string, mixed> $mediaItem         Hydrated media item with 'metadata'.
     * @param int                 $finalPositionTicks Final playback position in ticks.
     * @param bool                $reachedEnd         Whether playback reached the end.
     *
     * @return ScrobbleData|null
     */
    private function buildScrobbleData(array $mediaItem, int $finalPositionTicks, bool $reachedEnd): ?ScrobbleData
    {
        /** @var array<string, mixed> $meta */
        $meta = is_array($mediaItem['metadata'] ?? null) ? $mediaItem['metadata'] : [];

        $artist = is_string($meta['artist'] ?? null) ? $meta['artist']
            : (is_string($mediaItem['artist'] ?? null) ? $mediaItem['artist'] : null);
        $track = is_string($mediaItem['name'] ?? null) ? $mediaItem['name'] : null;

        if ($artist === null || $track === null) {
            return null;
        }

        // Calculate timestamp when track started playing
        $durationTicksRaw = $mediaItem['duration_ticks'] ?? 0;
        $durationTicks = is_numeric($durationTicksRaw) ? (int) $durationTicksRaw : 0;
        $startPositionTicks = $durationTicks > 0 ? max(0, $finalPositionTicks - $durationTicks) : 0;
        $startTimestamp = time() - (int) ($startPositionTicks / 10_000_000); // ticks to seconds

        $albumName = is_string($meta['album'] ?? null) ? $meta['album'] : null;
        $trackNumberRaw = $meta['track_number'] ?? null;
        $trackNumber = is_numeric($trackNumberRaw) ? (int) $trackNumberRaw : null;
        $durationSecs = $durationTicks > 0 ? (int) ($durationTicks / 10_000_000) : null;
        $mbid = is_string($meta['mbid'] ?? null) ? $meta['mbid'] : null;

        return new ScrobbleData(
            artist_name: $artist,
            track_title: $track,
            timestamp_unix: $startTimestamp,
            album_name: $albumName,
            track_number: $trackNumber,
            duration_secs: $durationSecs,
            mbid: $mbid,
        );
    }

    /**
     * Extract {@see NowPlayingData} from a hydrated media item.
     *
     * Returns null when the media item has no usable track metadata.
     *
     * @param array<string, mixed> $mediaItem    Hydrated media item with 'metadata'.
     * @param int                 $positionTicks Current position in ticks.
     *
     * @return NowPlayingData|null
     */
    private function buildNowPlayingData(array $mediaItem, int $positionTicks): ?NowPlayingData
    {
        /** @var array<string, mixed> $meta */
        $meta = is_array($mediaItem['metadata'] ?? null) ? $mediaItem['metadata'] : [];

        $artist = is_string($meta['artist'] ?? null) ? $meta['artist']
            : (is_string($mediaItem['artist'] ?? null) ? $mediaItem['artist'] : null);
        $track = is_string($mediaItem['name'] ?? null) ? $mediaItem['name'] : null;

        if ($artist === null || $track === null) {
            return null;
        }

        $durationTicksRaw = $mediaItem['duration_ticks'] ?? 0;
        $durationTicks = is_numeric($durationTicksRaw) ? (int) $durationTicksRaw : 0;
        $albumName = is_string($meta['album'] ?? null) ? $meta['album'] : null;
        $durationSecs = $durationTicks > 0 ? (int) ($durationTicks / 10_000_000) : null;
        $mbid = is_string($meta['mbid'] ?? null) ? $meta['mbid'] : null;

        return new NowPlayingData(
            artist_name: $artist,
            track_title: $track,
            album_name: $albumName,
            duration_secs: $durationSecs,
            mbid: $mbid,
        );
    }
}
