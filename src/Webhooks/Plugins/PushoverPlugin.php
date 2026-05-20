<?php

declare(strict_types=1);

namespace Phlix\Webhooks\Plugins;

use Phlix\Webhooks\WebhookEvent;
use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;

/**
 * Pushover notification plugin using Pushover API.
 *
 * Sends priority notifications to mobile devices using:
 * - User key and API token
 * - Title and message
 * - Priority levels
 */
class PushoverPlugin extends AbstractNotificationPlugin
{
    private const DEFAULT_CONFIG_PATH = __DIR__ . '/../../../config/notifications.php';
    private const API_URL = 'https://api.pushover.net/1/messages.json';

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
        return 'pushover';
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
        ];
    }

    public function send(WebhookEvent $event): bool
    {
        $userKey = $this->getConfigValue('user_key');
        $apiToken = $this->getConfigValue('api_token');

        if ($userKey === '' || $apiToken === '' || !$this->isEnabled()) {
            return false;
        }

        $payload = $this->buildPayload($event);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => [
                    'Content-Type: application/x-www-form-urlencoded',
                ],
                'content' => http_build_query($payload),
                'timeout' => 10,
                'ignore_errors' => true,
            ],
            'ssl' => $this->buildSslContextOptions($this->loadConfig()),
        ]);

        $response = @file_get_contents(self::API_URL, false, $context);
        $success = $response !== false && $this->getLastResponseCode() === 200;

        $this->getLogger()->debug('Pushover notification sent', [
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
        $title = $this->getTitleFromPayload($event->payload) ?: ucfirst(str_replace('.', ' ', $event->eventType));
        $message = $this->formatMessage($event);
        $priority = $this->getPriorityFromEvent($event);

        return [
            'token' => $this->getConfigValue('api_token'),
            'user' => $this->getConfigValue('user_key'),
            'title' => $title,
            'message' => $message,
            'priority' => $priority,
            'retry' => $priority > 2 ? 60 : 0,
            'expire' => $priority > 2 ? 3600 : 0,
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

        $configPath = defined('PHLIX_CONFIG_PATH') ? PHLIX_CONFIG_PATH : self::DEFAULT_CONFIG_PATH;
        $configFile = $configPath . '/notifications.php';

        if (file_exists($configFile)) {
            /** @var array<string, mixed> $config */
            $config = include $configFile;
            $providerConfig = $config['pushover'] ?? [];
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

    private function getLogger(): \Phlix\Common\Logger\StructuredLogger
    {
        return LoggerFactory::get(LogChannels::APPLICATION);
    }

    private function getPriorityFromEvent(WebhookEvent $event): int
    {
        return match ($event->eventType) {
            'alert' => 2,
            'recording.started' => 1,
            default => 0,
        };
    }
}
