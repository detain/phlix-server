<?php

/**
 * Phlix media server component: Webhooks.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Webhooks;

use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Common\Net\SsrfGuard;
use Phlix\Common\Uuid;
use InvalidArgumentException;
use Workerman\MySQL\Connection;

class WebhookDispatcher
{
    private ?StructuredLogger $logger;

    /** @var WebhookHttpClient|null Async HTTP client (lazy initialized) */
    private ?WebhookHttpClient $httpClient = null;

    /**
     * Backoff config constants.
     */
    private const BACKOFF_BASE_DELAY_MS = 1_000;
    private const BACKOFF_MAX_DELAY_MS = 32_000;

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
        // SSRF guard at config time (admin-triggered, off the media hot path).
        SsrfGuard::assertPublicUrl($url);

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

    /**
     * @param array<string, mixed> $updateData keys: name, url, events (optional)
     */
    public function update(string $webhookId, array $updateData): bool
    {
        $sets = [];
        $params = [];
        if (isset($updateData['name'])) {
            $sets[] = 'name = ?';
            $params[] = $updateData['name'];
        }
        if (isset($updateData['url'])) {
            $sets[] = 'url = ?';
            $params[] = $updateData['url'];
        }
        if (isset($updateData['events'])) {
            $sets[] = 'events_json = ?';
            $params[] = json_encode($updateData['events'], JSON_THROW_ON_ERROR);
        }
        if ($sets === []) {
            return false;
        }
        $params[] = $webhookId;
        $this->db->query(
            "UPDATE webhooks SET " . implode(', ', $sets) . " WHERE id = ?",
            $params
        );
        $this->getLogger()->info('Webhook updated', [
            'webhook_id' => $webhookId,
            'fields' => array_keys($updateData),
        ]);
        return true;
    }

    public function dispatch(WebhookEvent $event): DispatchResult
    {
        // Master kill-switch. Checked BEFORE the DB lookup so switching webhooks
        // off also stops the per-dispatch query, and returned as an empty
        // DispatchResult rather than an error: callers dispatch as a
        // side-effect of unrelated work and must not fail because an operator
        // turned webhooks off.
        if (!$this->isEnabled()) {
            return new DispatchResult(0, 0, []);
        }

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
     * Deliver one event to ONE specific webhook row, bypassing the event-type
     * subscription matching {@see dispatch()} performs.
     *
     * ## Why this exists
     *
     * The admin "Test" button targets a webhook the operator picked by id. It
     * used to route through {@see dispatch()}, which only delivers to rows whose
     * stored `events_json` contains the event type. The test event is
     * `webhook.test`, and the admin UI's subscribable catalogue deliberately
     * excludes it, so NO webhook created through the UI ever matched:
     * `getMatchingWebhooks()` returned `[]`, `dispatch()` short-circuited to
     * `DispatchResult(0, 0, [])`, and the caller read `failureCount === 0` as
     * success. The button reported a delivery that never left the process.
     *
     * This method answers the question the operator actually asked -- "can the
     * server reach THIS webhook?" -- so the reported outcome is the real one.
     * Signing, secret handling, SSRF re-validation, retry/backoff and delivery
     * logging are unchanged: it reuses the same {@see sendToWebhook()} path as
     * {@see dispatch()}.
     *
     * A webhook that does not exist, is inactive, or whose URL is unreachable
     * is reported as a FAILURE with the reason surfaced in
     * {@see DispatchResult::$failures}.
     *
     * @param string       $webhookId Id of the webhook row to deliver to.
     * @param WebhookEvent $event     Event to deliver.
     *
     * @return DispatchResult Always describes exactly one delivery attempt.
     *
     * @since 1.3.0
     */
    public function dispatchToWebhook(string $webhookId, WebhookEvent $event): DispatchResult
    {
        // Master kill-switch. Unlike dispatch() -- whose callers fire webhooks
        // as a side-effect of unrelated work and must not fail when an operator
        // turned webhooks off -- this is an explicit, operator-initiated
        // delivery. Reporting "success, 0 delivered" here would be the same lie
        // this method exists to remove, so say plainly that nothing was sent.
        if (!$this->isEnabled()) {
            return new DispatchResult(0, 1, [[
                'webhook_id' => $webhookId,
                'url' => '',
                'error' => 'Webhook delivery is disabled (webhooks.enabled is false).',
            ]]);
        }

        $webhook = $this->findWebhookById($webhookId);

        if ($webhook === null) {
            return new DispatchResult(0, 1, [[
                'webhook_id' => $webhookId,
                'url' => '',
                'error' => 'Webhook not found.',
            ]]);
        }

        $url = $this->stringFromMixed($webhook['url'] ?? null);

        if (!$this->isRowActive($webhook)) {
            return new DispatchResult(0, 1, [[
                'webhook_id' => $webhookId,
                'url' => $url,
                'error' => 'Webhook is inactive.',
            ]]);
        }

        $result = $this->sendToWebhook($webhook, $event);

        if ($result['success'] === true) {
            $this->updateLastTriggered($webhookId);

            return new DispatchResult(1, 0, []);
        }

        $this->incrementFailureCount($webhookId);

        return new DispatchResult(0, 1, [[
            'webhook_id' => $webhookId,
            'url' => $url,
            'error' => $this->stringFromMixed($result['error'] ?? null),
        ]]);
    }

    /**
     * Load a single webhook row by id, including its `secret` so
     * {@see sendToWebhook()} can sign identically to the subscription path.
     *
     * {@see listWebhooks()} deliberately omits `secret`, so callers holding a
     * listing row cannot be used for delivery.
     *
     * @return array<string, mixed>|null Null when no row has that id.
     */
    private function findWebhookById(string $webhookId): ?array
    {
        /** @var array<array<string, mixed>> $rows */
        $rows = $this->db->query(
            "SELECT id, name, url, secret, events_json, is_active FROM webhooks WHERE id = ?",
            [$webhookId]
        );

        foreach ($rows as $row) {
            if (is_array($row)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Is this webhook row active?
     *
     * `is_active` arrives as a bool, an int, or a numeric string depending on
     * driver/emulation settings, so coerce rather than trusting `===`. A row
     * that does not carry the column at all is treated as active: the column is
     * `TRUE`-defaulted at insert and its absence means "not selected", not
     * "disabled".
     *
     * @param array<string, mixed> $webhook
     */
    private function isRowActive(array $webhook): bool
    {
        if (!array_key_exists('is_active', $webhook)) {
            return true;
        }

        $value = $webhook['is_active'];

        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            return $value !== '' && $value !== '0' && strtolower($value) !== 'false';
        }

        return false;
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

    /**
     * Compute jittered exponential backoff delay in milliseconds.
     *
     * @param int $attempt Zero-based attempt index
     *
     * @return int Delay in milliseconds
     *
     * @since SV-4.4
     */
    private function computeBackoffDelayMs(int $attempt): int
    {
        $delay = min(
            self::BACKOFF_BASE_DELAY_MS * (2 ** $attempt),
            self::BACKOFF_MAX_DELAY_MS
        );
        // Add jitter: random value in [0, delay]
        $jitter = mt_rand(0, (int) $delay);
        return (int) ($delay + $jitter);
    }

    /**
     * Get the async HTTP client (lazy initialized).
     *
     * Protected for the same reason as {@see sleepMilliseconds()}: tests
     * substitute a double so delivery outcomes are asserted deterministically
     * and offline, instead of depending on a real network round trip.
     */
    protected function getHttpClient(): WebhookHttpClient
    {
        if ($this->httpClient === null) {
            $config = $this->getConfig();
            $timeout = $this->intFromMixed($config['timeout'] ?? null, 5);
            // SV-4.4 / S-F10: connect timeout, distinct from the total request
            // timeout, so an unreachable target fails fast on every attempt.
            $connectTimeout = $this->intFromMixed($config['connect_timeout'] ?? null, 5);
            $this->httpClient = new WebhookHttpClient($timeout, $connectTimeout);
        }
        return $this->httpClient;
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
        $url = $this->stringFromMixed($webhook['url'] ?? null);

        if ($url === '') {
            $webhookId = $this->stringFromMixed($webhook['id'] ?? null);
            $this->logDispatch(
                $webhookId,
                $event->eventType,
                null,
                null,
                'Empty URL',
            );
            return ['success' => false, 'error' => 'Empty webhook URL'];
        }

        // SSRF guard at dispatch time: re-validate the stored URL before any
        // outbound fetch so a row that was poisoned after creation (or via a
        // direct DB write) cannot reach loopback/link-local/private targets.
        // The dispatch path runs in the background notification timer, never on
        // the per-request media-serving hot path.
        try {
            SsrfGuard::assertPublicUrl($url);
        } catch (InvalidArgumentException $e) {
            $webhookId = $this->stringFromMixed($webhook['id'] ?? null);
            $this->logDispatch(
                $webhookId,
                $event->eventType,
                null,
                null,
                'SSRF guard blocked URL: ' . $e->getMessage(),
            );
            return ['success' => false, 'error' => $e->getMessage()];
        }

        $payload = json_encode($event->toArray(), JSON_THROW_ON_ERROR);
        $secret = $this->stringFromMixed($webhook['secret'] ?? null);
        $signature = $event->getSignature($secret);

        $config = $this->getConfig();
        $maxRetries = $this->intFromMixed($config['max_retries'] ?? null, 2);

        // SV-4.4 / S-F10: delegate through the shared, connect-timeout-aware
        // WebhookHttpClient (the same async/blocking-cURL dispatch pattern used
        // by the other HTTP clients) instead of a fresh, duplicated blocking
        // cURL call. postWithHeaders() preserves this subsystem's header-signed
        // raw-body wire format (X-Phlix-Signature header + raw event JSON body)
        // rather than post()'s {payload,signature} envelope, so registered
        // webhook receivers see no wire-format change.
        $client = $this->getHttpClient();
        $headers = [
            'Content-Type' => 'application/json',
            'X-Phlix-Signature' => $signature,
        ];

        $retries = 0;
        $lastError = 'Unknown error';
        $responseCode = null;

        do {
            $result = $client->postWithHeaders($url, $headers, $payload);

            if ($result['success']) {
                $webhookId = $this->stringFromMixed($webhook['id'] ?? null);
                $this->logDispatch(
                    $webhookId,
                    $event->eventType,
                    $result['response_code'],
                    $result['response_body'],
                    null
                );
                return ['success' => true];
            }

            $responseCode = $result['response_code'];
            $lastError = $result['error'] ?? ('HTTP ' . ($responseCode ?? 'unknown'));
            $retries++;

            // SV-4.4 / S-F10: jittered exponential backoff between synchronous
            // retry attempts instead of hammering a failing endpoint back-to-back.
            // Only sleep when another attempt will actually be made.
            if ($retries <= $maxRetries) {
                $this->sleepMilliseconds($this->computeBackoffDelayMs($retries - 1));
            }
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

    /**
     * Sleep for the given number of milliseconds between synchronous retry
     * attempts. Uses `usleep()`, which cooperatively yields to the event loop
     * under the Swoole coroutine SLEEP hook (same idiom as
     * {@see \Phlix\Media\Metadata\MetadataHttpClient::get()}'s retry loop)
     * rather than busy-spinning.
     *
     * Protected so tests can substitute a no-op/spy without real wall-clock
     * delay.
     *
     * @since SV-4.4
     */
    protected function sleepMilliseconds(int $milliseconds): void
    {
        if ($milliseconds <= 0) {
            return;
        }
        usleep($milliseconds * 1_000);
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
        // Routed through EffectiveConfig::file() so `webhooks.*` admin overrides
        // actually reach this class. It previously did a RAW `include` of
        // config/webhooks.php, which is read-path class (d) NOT REACHABLE: the
        // file's values were honoured but no override on top of them ever was.
        // {@see \Phlix\Config\EffectiveConfig}
        $config = \Phlix\Config\EffectiveConfig::file('webhooks');

        if ($config !== []) {
            return $config;
        }

        return [
            'enabled' => true,
            'timeout' => 5,
            'connect_timeout' => 5,
            'max_retries' => 2,
            'parallel_dispatch' => true,
        ];
    }

    /**
     * Is outbound webhook delivery enabled?
     *
     * Backs the `webhooks.enabled` setting. Before this existed, that config
     * key had ZERO consumers -- `'enabled' => true` appeared only in the
     * fallback array returned when the config file was missing, and was never
     * read on any path. The toggle shipped in config and did nothing.
     *
     * Defaults to TRUE when unset so an install that has never seen the key
     * keeps delivering, exactly as it did before.
     *
     * @return bool
     *
     * @since 1.3.0
     */
    public function isEnabled(): bool
    {
        return ($this->getConfig()['enabled'] ?? true) !== false;
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
        return Uuid::v4();
    }
}
