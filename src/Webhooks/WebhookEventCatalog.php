<?php

/**
 * Phlix media server component: Webhooks.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Webhooks;

/**
 * Single source of truth for the webhook event vocabulary.
 *
 * The canonical names mirror the wire-truth catalogue declared by
 * detain/phlix-shared's schemas/webhook-events.json (playback.*, library.*,
 * download.*, recording.*, alert) plus the three account-lifecycle events the
 * server has emitted since P9 (user.login, user.logout, user.created) which
 * that schema file does not yet list — the phlix-shared cascade owns widening
 * the shipped schema; until then this class and the admin SPA picker
 * (@phlix/ui WEBHOOK_EVENT_CATEGORIES) are the estate's live vocabulary.
 *
 * Before this catalogue existed, the emitter (WebhookEventSubscriber) used
 * media.* names while the admin subscription validator used a fictional
 * list (media.scanned, transcode.*, user.watched, syncplay.*), and the SPA
 * picker offered the schema names — three disjoint vocabularies, so
 * real domain events could never match a UI-created subscription.
 *
 * Legacy emitter names are accepted via {@see normalize()} so subscription
 * rows written before the rename keep receiving deliveries without a
 * migration; new writes are stored in canonical form.
 */
final class WebhookEventCatalog
{
    /**
     * The user-subscribable event names (the admin picker's list).
     *
     * Note: `webhook.test` is deliberately absent — it is internal to the
     * admin "test" button and not subscribable.
     *
     * @var list<string>
     */
    public const EVENTS = [
        'playback.started',
        'playback.ended',
        'library.updated',
        'download.complete',
        'recording.started',
        'recording.stopped',
        'alert',
        'user.login',
        'user.logout',
        'user.created',
    ];

    /**
     * Pre-rename emitter names → canonical event names (migration-free alias).
     *
     * @var array<string, string>
     */
    public const LEGACY_ALIASES = [
        'media.started' => 'playback.started',
        'media.played' => 'playback.ended',
        'media.stopped' => 'playback.ended',
        'media.added' => 'library.updated',
    ];

    /**
     * Return the canonical name for a possibly-legacy event type.
     */
    public static function normalize(string $eventType): string
    {
        return self::LEGACY_ALIASES[$eventType] ?? $eventType;
    }

    /**
     * Whether the (already canonical) event type is user-subscribable.
     */
    public static function isSubscribable(string $eventType): bool
    {
        return in_array($eventType, self::EVENTS, true);
    }

    /**
     * Whether the event type is accepted at the subscription API boundary
     * (canonical name or legacy alias).
     */
    public static function isAccepted(string $eventType): bool
    {
        return isset(self::LEGACY_ALIASES[$eventType]) || self::isSubscribable($eventType);
    }
}
