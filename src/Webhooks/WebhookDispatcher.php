<?php

declare(strict_types=1);

namespace Phlex\Webhooks;

use Phlex\Common\Logger\LogChannels;
use Phlex\Common\Logger\LoggerFactory;
use Phlex\Common\Logger\StructuredLogger;
use Workerman\MySQL\Connection;
use Workerman\Timer;

class WebhookDispatcher
{
    private const UUID_FORMAT = '%04x%04x-%04x-%04x-%04x-%04x%04x%04x';

    private ?StructuredLogger $logger;

    public function __construct(
        private readonly Connection $db,
        ?StructuredLogger $logger = null,
    ) {
        $this->logger = $logger;
    }

    /**
     * @param array<string> $events
     */
    public function register(string $name, string $url, string $secret, array $events): string
    {
        $id = $this->generateUuid();
        $eventsJson = json_encode($events, JSON_THROW_ON_ERROR);

        $this->db->query(
            "INSERT INTO webhooks (id, name, url, secret, events_json, " .
            "is_active, created_at, failure_count) VALUES (?, ?, ?, ?, ?, TRUE, NOW(), 0)",
            [$id, $name, $url, $secret, $eventsJson]
        );

        $this->getLogger()->info('Webhook registered', [
            'webhook_id' => $id,
            'name' => $name,
            'url' => $url,
            'events' => $events,
        ]);

        return $id;
    }

    public function unregister(string $webhookId): bool
    {
        $this->db->query(
            "DELETE FROM webhooks WHERE id = ?",
            [$webhookId]
        );

        $this->getLogger()->info('Webhook unregistered', [
            'webhook_id' => $webhookId,
        ]);

        return true;
    }

    public function dispatch(WebhookEvent $event): DispatchResult
    {
        $webhooks = $this->getMatchingWebhooks($event->eventType);

        if ($webhooks === []) {
            return new DispatchResult(0, 0, []);
        }

        /** @var array<array<string, string>> $failures */
        $failures = [];
        $successCount = 0;
        $failureCount = 0;

        foreach ($webhooks as $webhook) {
            /** @var array<string, mixed> $webhook */
            $result = $this->sendToWebhook($webhook, $event);
            $webhookId = $this->stringFromMixed($webhook['id'] ?? null);
            if ($result['success']) {
                $successCount++;
                $this->updateLastTriggered($webhookId);
            } else {
                $failureCount++;
                $failures[] = [
                    'webhook_id' => $webhookId,
                    'url' => $this->stringFromMixed($webhook['url'] ?? null),
                    'error' => $this->stringFromMixed($result['error'] ?? null),
                ];
                $this->incrementFailureCount($webhookId);
            }
        }

        return new DispatchResult($successCount, $failureCount, $failures);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listWebhooks(): array
    {
        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query(
            "SELECT id, name, url, events_json, is_active, created_at, " .
            "last_triggered_at, failure_count FROM webhooks"
        );

        $webhooks = [];
        foreach ($rows as $row) {
            /** @var array<string, mixed> $row */
            $row['events'] = $this->jsonDecodeMixed($row['events_json'] ?? null);
            unset($row['events_json']);
            $webhooks[] = $row;
        }

        return $webhooks;
    }

    public function dispatchAsync(WebhookEvent $event): void
    {
        $webhooks = $this->getMatchingWebhooks($event->eventType);

        if ($webhooks === []) {
            return;
        }

        foreach ($webhooks as $webhook) {
            /** @var array<string, mixed> $webhook */
            Timer::add(0, function () use ($webhook, $event): void {
                $result = $this->sendToWebhook($webhook, $event);
                $webhookId = $this->stringFromMixed($webhook['id'] ?? null);
                if ($result['success']) {
                    $this->updateLastTriggered($webhookId);
                } else {
                    $this->incrementFailureCount($webhookId);
                }
            }, [], false);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getMatchingWebhooks(string $eventType): array
    {
        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query(
            "SELECT id, name, url, secret, events_json FROM webhooks WHERE is_active = TRUE"
        );

        $matching = [];
        foreach ($rows as $row) {
            /** @var array<string, mixed> $row */
            $events = $this->jsonDecodeMixed($row['events_json'] ?? null);
            if (in_array($eventType, $events, true)) {
                $matching[] = $row;
            }
        }

        return $matching;
    }

    /**
     * @param array<string, mixed> $webhook
     * @return array<string, mixed>
     */
    private function sendToWebhook(array $webhook, WebhookEvent $event): array
    {
        $payload = json_encode($event->toArray(), JSON_THROW_ON_ERROR);
        $secret = $this->stringFromMixed($webhook['secret'] ?? null);
        $signature = $event->getSignature($secret);

        $config = $this->getConfig();
        $timeout = $this->intFromMixed($config['timeout'] ?? null, 5);
        $maxRetries = $this->intFromMixed($config['max_retries'] ?? null, 2);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => [
                    "Content-Type: application/json",
                    "X-Phlex-Signature: {$signature}",
                ],
                'content' => $payload,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
            'ssl' => $this->buildSslContextOptions($config),
        ]);

        $retries = 0;
        $lastError = 'Unknown error';
        $responseCode = null;

        do {
            $url = $this->stringFromMixed($webhook['url'] ?? null);
            $response = @file_get_contents($url, false, $context);

            if ($response !== false) {
                $responseCode = $this->getLastResponseCode();
                if ($responseCode !== null && $responseCode >= 200 && $responseCode < 300) {
                    $webhookId = $this->stringFromMixed($webhook['id'] ?? null);
                    $this->logDispatch(
                        $webhookId,
                        $event->eventType,
                        $responseCode,
                        $response,
                        null
                    );
                    return ['success' => true];
                }
                $lastError = "HTTP {$responseCode}";
            } else {
                $lastError = error_get_last()['message'] ?? 'Request failed';
            }

            $retries++;
        } while ($retries <= $maxRetries);

        $webhookId = $this->stringFromMixed($webhook['id'] ?? null);
        $this->logDispatch(
            $webhookId,
            $event->eventType,
            $responseCode,
            null,
            $lastError
        );

        return ['success' => false, 'error' => $lastError];
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

    private function logDispatch(
        string $webhookId,
        string $eventType,
        ?int $responseCode,
        ?string $responseBody,
        ?string $errorMessage
    ): void {
        $id = $this->generateUuid();

        $this->db->query(
            "INSERT INTO webhook_logs (id, webhook_id, event_type, response_code, " .
            "response_body, error_message, triggered_at) VALUES (?, ?, ?, ?, ?, ?, NOW())",
            [$id, $webhookId, $eventType, $responseCode, $responseBody, $errorMessage]
        );
    }

    private function updateLastTriggered(string $webhookId): void
    {
        $this->db->query(
            "UPDATE webhooks SET last_triggered_at = NOW(), failure_count = 0 WHERE id = ?",
            [$webhookId]
        );
    }

    private function incrementFailureCount(string $webhookId): void
    {
        $this->db->query(
            "UPDATE webhooks SET failure_count = failure_count + 1 WHERE id = ?",
            [$webhookId]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function getConfig(): array
    {
        $configPath = defined('PHLEX_CONFIG_PATH') ? PHLEX_CONFIG_PATH : __DIR__ . '/../../config';
        $configFile = $configPath . '/webhooks.php';

        if (file_exists($configFile)) {
            /** @var array<string, mixed> $config */
            $config = include $configFile;
            return $config;
        }

        return [
            'enabled' => true,
            'timeout' => 5,
            'max_retries' => 2,
            'parallel_dispatch' => true,
        ];
    }

    /**
     * Default system CA bundle used when no override is configured.
     */
    public const DEFAULT_CA_BUNDLE = '/etc/ssl/certs/ca-certificates.crt';

    /**
     * Build a `stream_context_create()` `ssl` block that verifies the
     * peer certificate and hostname against a configurable CA bundle.
     *
     * Webhooks target third-party HTTPS endpoints that MUST be TLS-verified
     * to prevent MITM tampering of webhook payloads in transit. The CA
     * bundle path is overridable via `config/webhooks.php` (`ca_bundle`)
     * so admins can pin a private/internal CA.
     *
     * @param array<string, mixed> $config Webhook config array
     *
     * @return array<string, mixed>
     */
    public function buildSslContextOptions(array $config): array
    {
        $caBundle = $this->stringFromMixed(
            $config['ca_bundle'] ?? self::DEFAULT_CA_BUNDLE
        );
        if ($caBundle === '') {
            $caBundle = self::DEFAULT_CA_BUNDLE;
        }

        return [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'cafile' => $caBundle,
            'SNI_enabled' => true,
        ];
    }

    private function getLogger(): StructuredLogger
    {
        if ($this->logger === null) {
            $this->logger = LoggerFactory::get(LogChannels::APPLICATION);
        }
        return $this->logger;
    }

    /**
     * @param mixed $value
     */
    private function stringFromMixed(mixed $value): string
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
     * @param mixed $value
     */
    private function intFromMixed(mixed $value, int $default): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }
        return $default;
    }

    /**
     * @param mixed $value
     * @return array<mixed, mixed>
     */
    private function jsonDecodeMixed(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }

    private function generateUuid(): string
    {
        return sprintf(
            self::UUID_FORMAT,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
        );
    }
}
