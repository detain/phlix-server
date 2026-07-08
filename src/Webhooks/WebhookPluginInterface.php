<?php

/**
 * Phlix media server component: Webhooks.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Webhooks;

interface WebhookPluginInterface
{
    public static function getName(): string;

    /**
     * @return array<string>
     */
    public static function getSupportedEvents(): array;

    public function send(WebhookEvent $event): bool;
}
