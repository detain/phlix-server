<?php

/**
 * Phlix media server component: Plugins.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Webhooks\Plugins;

use Phlix\Webhooks\WebhookEvent;
use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;

/**
 * Telegram notification plugin using Bot API.
 *
 * Sends formatted messages to Telegram using:
 * - Bot API sendMessage endpoint
 * - Markdown-formatted text
 * - chat_id destination
 */
class TelegramPlugin extends AbstractNotificationPlugin
{
    private const DEFAULT_CONFIG_PATH = __DIR__ . '/../../../config/notifications.php';
    private const API_BASE_URL = 'https://api.telegram.org/bot';

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
        return 'telegram';
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
        $botToken = $this->getConfigValue('bot_token');
        $chatId = $this->getConfigValue('chat_id');

        if ($botToken === '' || $chatId === '' || !$this->isEnabled()) {
            return false;
        }

        $payload = $this->buildPayload($event);
        $url = self::API_BASE_URL . $botToken . '/sendMessage';

        // SRV-M20 parity: the host is structurally fixed, but the
        // moment-of-use check still vets its resolution at check time — a
        // persistently poisoned answer for api.telegram.org is refused here,
        // zero bytes out, like every sibling. (Connect-time rebind inside the
        // guard→socket window and the redirect class: see the check-time note
        // and redirect law in AbstractNotificationPlugin.)
        if (!$this->guardEgressUrl($url, $event)) {
            return false;
        }

        $context = stream_context_create([
            'http' => $this->buildEgressHttpOptions([
                'method' => 'POST',
                'header' => [
                    'Content-Type: application/json',
                ],
                'content' => json_encode($payload, JSON_THROW_ON_ERROR),
            ]),
            'ssl' => $this->buildSslContextOptions($this->loadConfig()),
        ]);

        $response = @file_get_contents($url, false, $context);
        $statusLine = $http_response_header[0] ?? '';
        $success = $this->egressSucceeded($event, $response, $statusLine);

        $this->getLogger()->debug('Telegram notification sent', [
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

        $text = "*Phlix Media Server*\n";
        $text .= "_{$title}_\n\n";
        $text .= $this->formatMessage($event);

        return [
            'chat_id' => $this->getConfigValue('chat_id'),
            'text' => $text,
            'parse_mode' => 'Markdown',
            'disable_web_page_preview' => true,
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
            $providerConfig = $config['telegram'] ?? [];
            if (is_array($providerConfig)) {
                /** @var array<string, mixed> */
                return $providerConfig;
            }
        }

        /** @var array<string, mixed> */
        return [];
    }

    private function getLogger(): \Phlix\Common\Logger\StructuredLogger
    {
        return LoggerFactory::get(LogChannels::APPLICATION);
    }
}
