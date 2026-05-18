<?php

declare(strict_types=1);

namespace Phlex\Webhooks\Plugins;

use Phlex\Webhooks\WebhookEvent;
use Phlex\Webhooks\WebhookPluginInterface;

/**
 * Abstract base class for notification plugins.
 *
 * Provides common formatting helpers and enforces the contract
 * that subclasses implement buildPayload().
 */
abstract class AbstractNotificationPlugin implements WebhookPluginInterface
{
    /**
     * Formats the event as a human-readable message string.
     */
    protected function formatMessage(WebhookEvent $event): string
    {
        $type = $event->eventType;
        $payload = $event->payload;

        $title = $this->getTitleFromPayload($payload);
        $description = $this->getDescriptionFromPayload($payload);

        $message = ucfirst(str_replace('.', ' ', $type));
        if ($title !== '') {
            $message .= ": {$title}";
        }
        if ($description !== '' && $description !== $title) {
            $message .= " — {$description}";
        }

        return $message;
    }

    /**
     * Returns an RGB integer color based on event type.
     */
    protected function getEmbedColor(WebhookEvent $event): int
    {
        return match ($event->eventType) {
            'playback.started' => 0x2ECC71,   // Green
            'playback.ended' => 0x3498DB,      // Blue
            'library.updated' => 0x9B59B6,     // Purple
            'download.complete' => 0xE67E22,  // Orange
            'recording.started' => 0xE74C3C,  // Red
            'recording.stopped' => 0x95A5A6,  // Gray
            'alert' => 0xE74C3C,              // Red
            default => 0x34495E,               // Dark gray
        };
    }

    /**
     * Extracts a title from the event payload.
     *
     * @param array<string, mixed> $payload
     */
    protected function getTitleFromPayload(array $payload): string
    {
        return $this->stringFromMixed($payload['title'] ?? $payload['name'] ?? $payload['media_title'] ?? '');
    }

    /**
     * Extracts a description from the event payload.
     *
     * @param array<string, mixed> $payload
     */
    protected function getDescriptionFromPayload(array $payload): string
    {
        return $this->stringFromMixed(
            $payload['description'] ?? $payload['summary'] ?? $payload['message'] ?? ''
        );
    }

    /**
     * Extracts a media thumbnail URL from the event payload.
     *
     * @param array<string, mixed> $payload
     */
    protected function getThumbnailFromPayload(array $payload): string
    {
        return $this->stringFromMixed($payload['thumbnail'] ?? $payload['poster'] ?? '');
    }

    /**
     * @param mixed $value
     */
    protected function stringFromMixed(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return strval($value);
        }
        return '';
    }

    /**
     * Builds the platform-specific payload for the given event.
     *
     * @return array<string, mixed>
     */
    abstract protected function buildPayload(WebhookEvent $event): array;
}
