<?php

declare(strict_types=1);

namespace Phlix\Webhooks\Plugins;

use Phlix\Webhooks\WebhookEvent;
use Phlix\Webhooks\WebhookPluginInterface;

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
     * Default path to the system CA bundle used for outbound TLS
     * verification when no override is provided in configuration.
     */
    public const DEFAULT_CA_BUNDLE = '/etc/ssl/certs/ca-certificates.crt';

    /**
     * Build a stream context SSL block with `verify_peer` and
     * `verify_peer_name` enabled.
     *
     * Notification plugins call out to third-party HTTPS endpoints
     * (Discord, Slack, Telegram, …) and MUST verify the peer certificate
     * chain. The CA bundle path is overridable via the notifications
     * config (`<provider>.ca_bundle` or top-level `ca_bundle`) so admins
     * can point Phlix at a private CA. Defaults to the standard Debian
     * system bundle.
     *
     * @param array<string, mixed> $providerConfig Per-provider config slice
     *
     * @return array<string, mixed> ssl context options
     */
    protected function buildSslContextOptions(array $providerConfig = []): array
    {
        $caBundle = $this->stringFromMixed(
            $providerConfig['ca_bundle']
            ?? $this->resolveDefaultCaBundle()
        );

        return [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'cafile' => $caBundle,
            'SNI_enabled' => true,
        ];
    }

    /**
     * Resolve the default CA bundle path, preferring an admin override
     * from the global `notifications.php` config file when present.
     */
    private function resolveDefaultCaBundle(): string
    {
        $configPath = defined('PHLIX_CONFIG_PATH') ? PHLIX_CONFIG_PATH : __DIR__ . '/../../../config';
        $configFile = $configPath . '/notifications.php';
        if (file_exists($configFile)) {
            /** @var array<string, mixed> $config */
            $config = include $configFile;
            if (is_string($config['ca_bundle'] ?? null) && $config['ca_bundle'] !== '') {
                return $config['ca_bundle'];
            }
        }
        return self::DEFAULT_CA_BUNDLE;
    }

    /**
     * Builds the platform-specific payload for the given event.
     *
     * @return array<string, mixed>
     */
    abstract protected function buildPayload(WebhookEvent $event): array;
}
