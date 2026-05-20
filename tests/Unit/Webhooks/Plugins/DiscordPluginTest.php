<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Webhooks\Plugins;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Phlix\Webhooks\Plugins\DiscordPlugin;
use Phlix\Webhooks\WebhookEvent;

class DiscordPluginTest extends TestCase
{
    public function testGetNameReturnsDiscord(): void
    {
        $plugin = new DiscordPlugin();
        $this->assertEquals('discord', DiscordPlugin::getName());
    }

    public function testGetSupportedEventsReturnsExpectedList(): void
    {
        $events = DiscordPlugin::getSupportedEvents();
        $this->assertIsArray($events);
        $this->assertContains('playback.started', $events);
        $this->assertContains('playback.ended', $events);
        $this->assertContains('library.updated', $events);
        $this->assertContains('download.complete', $events);
    }

    public function testSendReturnsFalseWhenDisabled(): void
    {
        $config = [
            'webhook_url' => 'https://discord.com/api/webhooks/test',
            'enabled' => false,
        ];

        $plugin = new DiscordPlugin($config);
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

        $plugin = new DiscordPlugin($config);
        $event = $this->createEvent('playback.started', ['title' => 'Test Movie']);

        $result = $plugin->send($event);
        $this->assertFalse($result);
    }

    public function testBuildPayloadCreatesCorrectEmbedStructure(): void
    {
        $plugin = new DiscordPlugin([
            'webhook_url' => 'https://discord.com/api/webhooks/test',
            'enabled' => true,
        ]);

        $event = $this->createEvent('playback.started', [
            'title' => 'Test Movie',
            'description' => 'A test movie description',
        ]);

        // Use reflection to access protected method
        $reflection = new \ReflectionClass($plugin);
        $method = $reflection->getMethod('buildPayload');
        $method->setAccessible(true);

        $payload = $method->invoke($plugin, $event);

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('embeds', $payload);
        $this->assertIsArray($payload['embeds']);
        $this->assertCount(1, $payload['embeds']);

        $embed = $payload['embeds'][0];
        $this->assertEquals('Test Movie', $embed['title']);
        $this->assertArrayHasKey('description', $embed);
        $this->assertArrayHasKey('color', $embed);
        $this->assertArrayHasKey('timestamp', $embed);
        $this->assertArrayHasKey('footer', $embed);
    }

    public function testBuildPayloadWithThumbnail(): void
    {
        $plugin = new DiscordPlugin([
            'webhook_url' => 'https://discord.com/api/webhooks/test',
            'enabled' => true,
        ]);

        $event = $this->createEvent('playback.started', [
            'title' => 'Test Movie',
            'thumbnail' => 'https://example.com/thumb.jpg',
        ]);

        $reflection = new \ReflectionClass($plugin);
        $method = $reflection->getMethod('buildPayload');
        $method->setAccessible(true);

        $payload = $method->invoke($plugin, $event);

        $embed = $payload['embeds'][0];
        $this->assertArrayHasKey('thumbnail', $embed);
        $this->assertEquals('https://example.com/thumb.jpg', $embed['thumbnail']['url']);
    }

    public function testGetEmbedColorReturnsCorrectColors(): void
    {
        $plugin = new DiscordPlugin([
            'webhook_url' => '',
            'enabled' => false,
        ]);

        $reflection = new \ReflectionClass($plugin);
        $method = $reflection->getMethod('getEmbedColor');
        $method->setAccessible(true);

        $playbackStarted = $this->createEvent('playback.started', []);
        $this->assertEquals(0x2ECC71, $method->invoke($plugin, $playbackStarted));

        $playbackEnded = $this->createEvent('playback.ended', []);
        $this->assertEquals(0x3498DB, $method->invoke($plugin, $playbackEnded));

        $libraryUpdated = $this->createEvent('library.updated', []);
        $this->assertEquals(0x9B59B6, $method->invoke($plugin, $libraryUpdated));

        $downloadComplete = $this->createEvent('download.complete', []);
        $this->assertEquals(0xE67E22, $method->invoke($plugin, $downloadComplete));
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
