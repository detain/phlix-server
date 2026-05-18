<?php

declare(strict_types=1);

namespace Phlex\Webhooks\Plugins;

use Phlex\Webhooks\WebhookEvent;
use Phlex\Common\Logger\LogChannels;
use Phlex\Common\Logger\LoggerFactory;

/**
 * Apprise notification plugin as generic webhook receiver.
 *
 * Sends notifications to any Apprise URL with:
 * - JSON payload body
 * - Event type in X-Phlex-Event header
 * - Timestamp in X-Phlex-Timestamp header
 */
class ApprisePlugin extends AbstractNotificationPlugin
{
    private const DEFAULT_CONFIG_PATH = __DIR__ . '/../../../config/notifications.php';

    /** @var array<string, mixed>|null */
    private ?array $config = null;

    /**
     * @param array<string, mixed>|null $config
     */
    public function __construct(?array $config = null)
    {
        $this->config = $config;
    }

    public static function getName(): string
    {
        return 'apprise';
    }

    /**
     * @return array<string>
     */
    public static function getSupportedEvents(): array
    {
        return [
            'playback.started',
            'playback.ended',
            'library.updated',
            'download.complete',
            'alert',
            'recording.started',
            'recording.stopped',
        ];
    }

    public function send(WebhookEvent $event): bool
    {
        $url = $this->getConfigValue('url');
        if ($url === '' || !$this->isEnabled()) {
            return false;
        }

        $payload = $this->buildPayload($event);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => [
                    'Content-Type: application/json',
                    'X-Phlex-Event: ' . $event->eventType,
                    'X-Phlex-Timestamp: ' . $event->occurredAt->format(\DateTimeImmutable::ATOM),
                ],
                'content' => json_encode($payload, JSON_THROW_ON_ERROR),
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        $success = $response !== false && $this->getLastResponseCode() === 200;

        $this->getLogger()->debug('Apprise notification sent', [
            'event_type' => $event->eventType,
            'success' => $success,
        ]);

        return $success;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildPayload(WebhookEvent $event): array
    {
        return [
            'event' => $event->eventType,
            'title' => $this->getTitleFromPayload($event->payload) ?: ucfirst(str_replace('.', ' ', $event->eventType)),
            'message' => $this->formatMessage($event),
            'when' => $event->occurredAt->format(\DateTimeImmutable::ATOM),
            'payload' => $event->payload,
        ];
    }

    private function getConfigValue(string $key): string
    {
        $config = $this->loadConfig();
        return $this->stringFromMixed($config[$key] ?? '');
    }

    private function isEnabled(): bool
    {
        $config = $this->loadConfig();
        return (bool) ($config['enabled'] ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadConfig(): array
    {
        if ($this->config !== null) {
            return $this->config;
        }

        $configPath = defined('PHLEX_CONFIG_PATH') ? PHLEX_CONFIG_PATH : self::DEFAULT_CONFIG_PATH;
        $configFile = $configPath . '/notifications.php';

        if (file_exists($configFile)) {
            /** @var array<string, mixed> $config */
            $config = include $configFile;
            $providerConfig = $config['apprise'] ?? [];
            if (is_array($providerConfig)) {
                /** @var array<string, mixed> */
                return $providerConfig;
            }
        }

        /** @var array<string, mixed> */
        return [];
    }

    private function getLastResponseCode(): ?int
    {
        global $http_response_header;
        if (isset($http_response_header[0])) {
            if (preg_match('/HTTP\/\d+\.?\d*\s+(\d+)/', $http_response_header[0], $matches)) {
                return (int) $matches[1];
            }
        }
        return null;
    }

    private function getLogger(): \Phlex\Common\Logger\StructuredLogger
    {
        return LoggerFactory::get(LogChannels::APPLICATION);
    }
}
