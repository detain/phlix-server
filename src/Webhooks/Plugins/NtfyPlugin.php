<?php

declare(strict_types=1);

namespace Phlix\Webhooks\Plugins;

use Phlix\Webhooks\WebhookEvent;
use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;

/**
 * ntfy notification plugin using REST API.
 *
 * Sends notifications to ntfy.sh or self-hosted servers with:
 * - Topic-based pub/sub
 * - Tags and priority
 * - Markdown message body
 */
class NtfyPlugin extends AbstractNotificationPlugin
{
    private const DEFAULT_CONFIG_PATH = __DIR__ . '/../../../config/notifications.php';
    private const DEFAULT_SERVER = 'https://ntfy.sh';

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
        return 'ntfy';
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
        $topic = $this->getConfigValue('topic');
        if ($topic === '' || !$this->isEnabled()) {
            return false;
        }

        $payload = $this->buildPayload($event);
        $server = $this->getConfigValue('server') ?: self::DEFAULT_SERVER;
        $url = rtrim($server, '/') . '/' . urlencode($topic);

        $tags = $this->getTagsFromEvent($event);
        $priority = $this->getPriorityFromEvent($event);

        $headers = [
            'Content-Type: text/plain',
        ];

        if ($tags !== '') {
            $headers[] = 'Tags: ' . $tags;
        }

        if ($priority > 0) {
            $headers[] = 'X-Priority: ' . $priority;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => $headers,
                'content' => json_encode($payload, JSON_THROW_ON_ERROR),
                'timeout' => 10,
                'ignore_errors' => true,
            ],
            'ssl' => $this->buildSslContextOptions($this->loadConfig()),
        ]);

        $response = @file_get_contents($url, false, $context);
        $success = $response !== false && $this->getLastResponseCode() === 200;

        $this->getLogger()->debug('Ntfy notification sent', [
            'event_type' => $event->eventType,
            'topic' => $topic,
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

        return [
            'title' => $title,
            'message' => $this->formatMessage($event),
            'event' => $event->eventType,
            'when' => $event->occurredAt->format('c'),
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
            $providerConfig = $config['ntfy'] ?? [];
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

    private function getTagsFromEvent(WebhookEvent $event): string
    {
        return match ($event->eventType) {
            'playback.started' => 'play, arrow_forward',
            'playback.ended' => 'stop, arrow_forward',
            'library.updated' => 'books, refresh',
            'download.complete' => 'arrow_down, white_check_mark',
            'recording.started' => 'red_circle, dot',
            'recording.stopped' => 'stop_button',
            'alert' => 'warning, exclamation',
            default => 'bell',
        };
    }

    private function getPriorityFromEvent(WebhookEvent $event): int
    {
        return match ($event->eventType) {
            'alert' => 5,
            'recording.started' => 4,
            'download.complete' => 3,
            default => 2,
        };
    }
}
