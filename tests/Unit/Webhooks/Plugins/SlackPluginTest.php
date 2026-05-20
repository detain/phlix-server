<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Webhooks\Plugins;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Phlix\Webhooks\Plugins\SlackPlugin;
use Phlix\Webhooks\WebhookEvent;

class SlackPluginTest extends TestCase
{
    public function testGetNameReturnsSlack(): void
    {
        $this->assertEquals('slack', SlackPlugin::getName());
    }

    public function testGetSupportedEvents(): void
    {
        $events = SlackPlugin::getSupportedEvents();
        $this->assertIsArray($events);
        $this->assertContains('playback.started', $events);
        $this->assertContains('playback.ended', $events);
        $this->assertContains('library.updated', $events);
        $this->assertContains('download.complete', $events);
    }

    public function testSendReturnsFalseWhenDisabled(): void
    {
        $config = [
            'webhook_url' => 'https://hooks.slack.com/services/test',
            'enabled' => false,
        ];

        $plugin = new SlackPlugin($config);
        $event = $this->createEvent('playback.started', ['title' => 'Test Movie']);

        $result = $plugin->send($event);
        $this->assertFalse($result);
    }

    public function testSendReturnsFalseWhenWebhookUrlEmpty(): void
    {
        $config = [
            'webhook_url' => '',
            'enabled' => true,
        ];

        $plugin = new SlackPlugin($config);
        $event = $this->createEvent('playback.started', ['title' => 'Test Movie']);

        $result = $plugin->send($event);
        $this->assertFalse($result);
    }

    public function testBuildPayloadCreatesBlockKitStructure(): void
    {
        $plugin = new SlackPlugin([
            'webhook_url' => 'https://hooks.slack.com/services/test',
            'enabled' => true,
        ]);

        $event = $this->createEvent('playback.started', [
            'title' => 'Test Movie',
            'description' => 'A test movie description',
        ]);

        $reflection = new \ReflectionClass($plugin);
        $method = $reflection->getMethod('buildPayload');
        $method->setAccessible(true);

        $payload = $method->invoke($plugin, $event);

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('blocks', $payload);
        $this->assertIsArray($payload['blocks']);
        $this->assertCount(3, $payload['blocks']);

        // Check header block
        $this->assertEquals('header', $payload['blocks'][0]['type']);
        $this->assertEquals('Test Movie', $payload['blocks'][0]['text']['text']);

        // Check section block with message
        $this->assertEquals('section', $payload['blocks'][1]['type']);
        $this->assertArrayHasKey('text', $payload['blocks'][1]);

        // Check context block
        $this->assertEquals('context', $payload['blocks'][2]['type']);

        // Check attachments
        $this->assertArrayHasKey('attachments', $payload);
        $this->assertIsArray($payload['attachments']);
        $this->assertArrayHasKey('color', $payload['attachments'][0]);
    }

    public function testGetEmbedColorAsHexReturnsCorrectFormat(): void
    {
        $plugin = new SlackPlugin([
            'webhook_url' => '',
            'enabled' => false,
        ]);

        $reflection = new \ReflectionClass($plugin);
        $method = $reflection->getMethod('getEmbedColorAsHex');
        $method->setAccessible(true);

        $event = $this->createEvent('playback.started', []);
        $color = $method->invoke($plugin, $event);

        $this->assertIsString($color);
        $this->assertStringStartsWith('#', $color);
        $this->assertEquals(7, strlen($color)); // #RRGGBB
    }

    private function createEvent(string $eventType, array $payload): WebhookEvent
    {
        return new WebhookEvent(
            $eventType,
            $payload,
            new DateTimeImmutable('2024-01-15T10:30:00+00:00')
        );
    }
}
