<?php

declare(strict_types=1);

namespace Phlix\Webhooks\Plugins;

use Phlix\Webhooks\WebhookEvent;
use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;

/**
 * MQTT notification plugin publishing JSON to an MQTT broker.
 *
 * Publishes event payloads to a configured MQTT topic with:
 * - JSON-encoded message body
 * - Configurable broker and topic
 * - Optional authentication
 *
 * Note: This plugin uses HTTP-based MQTT REST API (if broker supports it)
 * or falls back to sending to a generic webhook that bridges to MQTT.
 * For full MQTT support, a library like phpMQTT would be needed.
 */
class MqttPlugin extends AbstractNotificationPlugin
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
        return 'mqtt';
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
        $broker = $this->getConfigValue('broker');
        $topic = $this->getConfigValue('topic');

        if ($broker === '' || $topic === '' || !$this->isEnabled()) {
            return false;
        }

        $payload = $this->buildPayload($event);

        // For HTTP-based MQTT bridges (like Eclipse Hono, AWS IoT Core HTTP, etc.)
        // we send a POST request to the broker's REST endpoint
        // If the broker is a plain MQTT broker without HTTP, this would need
        // a proper MQTT client library. Here we use a webhook approach.
        $url = $this->buildBrokerUrl($broker, $topic);

        $headers = [
            'Content-Type: application/json',
        ];

        $username = $this->getConfigValue('username');
        $password = $this->getConfigValue('password');

        if ($username !== '' && $password !== '') {
            $auth = base64_encode($username . ':' . $password);
            $headers[] = 'Authorization: Basic ' . $auth;
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

        $this->getLogger()->debug('MQTT notification sent', [
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
        return [
            'topic' => $event->eventType,
            'event' => $event->eventType,
            'title' => $this->getTitleFromPayload($event->payload) ?: ucfirst(str_replace('.', ' ', $event->eventType)),
            'message' => $this->formatMessage($event),
            'timestamp' => $event->occurredAt->format(\DateTimeImmutable::ATOM),
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

        $configPath = defined('PHLIX_CONFIG_PATH') ? PHLIX_CONFIG_PATH : self::DEFAULT_CONFIG_PATH;
        $configFile = $configPath . '/notifications.php';

        if (file_exists($configFile)) {
            /** @var array<string, mixed> $config */
            $config = include $configFile;
            $providerConfig = $config['mqtt'] ?? [];
            if (is_array($providerConfig)) {
                /** @var array<string, mixed> */
                return $providerConfig;
            }
        }

        /** @var array<string, mixed> */
        return [];
    }

    /**
     * Builds the HTTP URL for MQTT broker REST API.
     *
     * Supports common MQTT-over-HTTP bridges:
     * - Eclipse Hono: https://hono.eclipse.org:8080/telemetry
     * - AWS IoT Core: https://xxxxx.iot.region.amazonaws.com/topics/topic
     * - Generic HTTP: just appends topic path
     */
    private function buildBrokerUrl(string $broker, string $topic): string
    {
        $broker = trim($broker);

        // If broker already has a scheme, use it as-is with topic appended
        if (str_starts_with($broker, 'http://') || str_starts_with($broker, 'https://')) {
            return rtrim($broker, '/') . '/' . urlencode($topic);
        }

        // For plain host:port brokers, assume HTTP on port 8080
        if (preg_match('/^([^:]+):(\d+)$/', $broker, $matches)) {
            return 'http://' . $broker . '/' . urlencode($topic);
        }

        // For bare hostname, use default MQTT HTTP bridge port
        return 'http://' . $broker . ':8080/' . urlencode($topic);
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
}
