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
 * Event type mapping:
 * - PlaybackStarted          → media.started
 * - PlaybackStopped (end)    → media.played
 * - PlaybackStopped (stopped)→ media.stopped
 * - MediaItemAdded           → media.added
 * - UserLoggedIn            → user.login
 * - UserLoggedOut           → user.logout
 * - UserCreated             → user.created
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
        $this->webhookService->emit('media.started', [
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
     * Emits "media.played" when the user reached the end (full watch),
     * and "media.stopped" when the user stopped mid-stream.
     */
    public function onPlaybackStopped(PlaybackStopped $event): void
    {
        $eventType = $event->reachedEnd ? 'media.played' : 'media.stopped';

        $this->webhookService->emit($eventType, [
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
        $this->webhookService->emit('media.added', [
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
