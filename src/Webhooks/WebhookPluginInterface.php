<?php

declare(strict_types=1);

namespace Phlex\Webhooks;

interface WebhookPluginInterface
{
    public static function getName(): string;

    /**
     * @return array<string>
     */
    public static function getSupportedEvents(): array;

    public function send(WebhookEvent $event): bool;
}
