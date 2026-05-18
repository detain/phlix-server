<?php

declare(strict_types=1);

namespace Phlex\Webhooks;

use DateTimeImmutable;

class WebhookEvent
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $eventType,
        public readonly array $payload,
        public readonly DateTimeImmutable $occurredAt,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event_type' => $this->eventType,
            'payload' => $this->payload,
            'occurred_at' => $this->occurredAt->format(DateTimeImmutable::ATOM),
        ];
    }

    public function getSignature(string $secret): string
    {
        $payload = json_encode($this->toArray(), JSON_THROW_ON_ERROR);
        return 'sha256=' . hash_hmac('sha256', $payload, $secret);
    }
}
