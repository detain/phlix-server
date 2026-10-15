<?php

/**
 * Phlix media server component: Webhooks.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Webhooks;

use Phlix\Common\Events\ListenerRegistry;
use Phlix\Shared\Events\Auth\UserCreated;
use Phlix\Shared\Events\Auth\UserLoggedIn;
use Phlix\Shared\Events\Auth\UserLoggedOut;
use Phlix\Shared\Events\Library\MediaItemAdded;
use Phlix\Shared\Events\Playback\PlaybackStarted;
use Phlix\Shared\Events\Playback\PlaybackStopped;

/**
 * Subscribes to domain PSR-14 events and emits them to the webhook queue.
 *
 * This class bridges the PSR-14 event dispatcher (used throughout the
 * domain model) to the async webhook delivery system. When domain events
 * fire (playback started/stopped, media added, user logged in, etc.)
 * this subscriber translates them into webhook event types and calls
 * {@see WebhookService::emit()} to queue deliveries.
 *
 * Event type mapping (names are the {@see WebhookEventCatalog} canonical
 * vocabulary — the same list the admin SPA picker and the subscription API
 * validate against; R5-03 replaced the old media.* emitter names which no
 * subscription could ever be created for):
 * - PlaybackStarted           → playback.started
 * - PlaybackStopped (either)  → playback.ended  (payload keeps reached_end,
 *                                 so finish-vs-stop stays distinguishable —
 *                                 the schema collapses both into one event)
 * - MediaItemAdded            → library.updated
 * - UserLoggedIn              → user.login
 * - UserLoggedOut             → user.logout
 * - UserCreated               → user.created
 *
 * @since P9
 */
final class WebhookEventSubscriber
{
    public function __construct(
        private readonly WebhookService $webhookService,
        private readonly ListenerRegistry $listeners,
    ) {
    }

    /**
     * Register all webhook event subscriptions with the listener registry.
     *
     * @return string[] Listener IDs returned by the registry
     */
    public function register(): array
    {
        return [
            $this->listeners->subscribe(PlaybackStarted::class, [$this, 'onPlaybackStarted']),
            $this->listeners->subscribe(PlaybackStopped::class, [$this, 'onPlaybackStopped']),
            $this->listeners->subscribe(MediaItemAdded::class, [$this, 'onMediaItemAdded']),
            $this->listeners->subscribe(UserLoggedIn::class, [$this, 'onUserLoggedIn']),
            $this->listeners->subscribe(UserLoggedOut::class, [$this, 'onUserLoggedOut']),
            $this->listeners->subscribe(UserCreated::class, [$this, 'onUserCreated']),
        ];
    }

    /**
     * Handle playback started event.
     */
    public function onPlaybackStarted(PlaybackStarted $event): void
    {
        $this->webhookService->emit('playback.started', [
            'session_id' => $event->sessionId,
            'user_id' => $event->userId,
            'media_item_id' => $event->mediaItemId,
            'device_id' => $event->deviceId,
            'position_ticks' => $event->positionTicks,
        ]);
    }

    /**
     * Handle playback stopped event.
     *
     * Emits "playback.ended" for both endings — the canonical catalogue
     * collapses finish and stop into one event (wire truth: "a playback
     * session finished or was stopped"); the reached_end payload flag keeps
     * the distinction for consumers.
     */
    public function onPlaybackStopped(PlaybackStopped $event): void
    {
        $this->webhookService->emit('playback.ended', [
            'session_id' => $event->sessionId,
            'user_id' => $event->userId,
            'media_item_id' => $event->mediaItemId,
            'device_id' => $event->deviceId,
            'final_position_ticks' => $event->finalPositionTicks,
            'reached_end' => $event->reachedEnd,
        ]);
    }

    /**
     * Handle new media item added event.
     */
    public function onMediaItemAdded(MediaItemAdded $event): void
    {
        $this->webhookService->emit('library.updated', [
            'media_item_id' => $event->mediaItemId,
            'library_id' => $event->libraryId,
            'path' => $event->path,
            'type' => $event->type,
        ]);
    }

    /**
     * Handle user logged in event.
     */
    public function onUserLoggedIn(UserLoggedIn $event): void
    {
        $this->webhookService->emit('user.login', [
            'user_id' => $event->userId,
            'session_id' => $event->sessionId,
            'ip_address' => $event->ipAddress,
            'user_agent' => $event->userAgent,
        ]);
    }

    /**
     * Handle user logged out event.
     */
    public function onUserLoggedOut(UserLoggedOut $event): void
    {
        $this->webhookService->emit('user.logout', [
            'user_id' => $event->userId,
            'session_id' => $event->sessionId,
            'reason' => $event->reason,
        ]);
    }

    /**
     * Handle new user created event.
     */
    public function onUserCreated(UserCreated $event): void
    {
        $this->webhookService->emit('user.created', [
            'user_id' => $event->userId,
            'username' => $event->username,
            'email' => $event->email,
        ]);
    }
}
