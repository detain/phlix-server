<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Webhooks\Plugins;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Phlex\Webhooks\Plugins\TelegramPlugin;
use Phlex\Webhooks\WebhookEvent;

class TelegramPluginTest extends TestCase
{
    public function testGetNameReturnsTelegram(): void
    {
        $this->assertEquals('telegram', TelegramPlugin::getName());
    }

    public function testGetSupportedEvents(): void
    {
        $events = TelegramPlugin::getSupportedEvents();
        $this->assertIsArray($events);
        $this->assertContains('playback.started', $events);
        $this->assertContains('playback.ended', $events);
        $this->assertContains('library.updated', $events);
        $this->assertContains('download.complete', $events);
        $this->assertContains('alert', $events);
    }

    public function testSendReturnsFalseWhenDisabled(): void
    {
        $config = [
            'bot_token' => '123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11',
            'chat_id' => '123456789',
            'enabled' => false,
        ];

        $plugin = new TelegramPlugin($config);
        $event = $this->createEvent('playback.started', ['title' => 'Test Movie']);

        $result = $plugin->send($event);
        $this->assertFalse($result);
    }

    public function testSendReturnsFalseWhenBotTokenEmpty(): void
    {
        $config = [
            'bot_token' => '',
            'chat_id' => '123456789',
            'enabled' => true,
        ];

        $plugin = new TelegramPlugin($config);
        $event = $this->createEvent('playback.started', ['title' => 'Test Movie']);

        $result = $plugin->send($event);
        $this->assertFalse($result);
    }

    public function testSendReturnsFalseWhenChatIdEmpty(): void
    {
        $config = [
            'bot_token' => '123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11',
            'chat_id' => '',
            'enabled' => true,
        ];

        $plugin = new TelegramPlugin($config);
        $event = $this->createEvent('playback.started', ['title' => 'Test Movie']);

        $result = $plugin->send($event);
        $this->assertFalse($result);
    }

    public function testBuildPayloadCreatesCorrectStructure(): void
    {
        $config = [
            'bot_token' => '123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11',
            'chat_id' => '123456789',
            'enabled' => true,
        ];

        $plugin = new TelegramPlugin($config);
        $event = $this->createEvent('playback.started', [
            'title' => 'Test Movie',
            'description' => 'A test movie description',
        ]);

        $reflection = new \ReflectionClass($plugin);
        $method = $reflection->getMethod('buildPayload');
        $method->setAccessible(true);

        $payload = $method->invoke($plugin, $event);

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('chat_id', $payload);
        $this->assertEquals('123456789', $payload['chat_id']);
        $this->assertArrayHasKey('text', $payload);
        $this->assertStringContainsString('Test Movie', $payload['text']);
        $this->assertStringContainsString('Phlex Media Server', $payload['text']);
        $this->assertArrayHasKey('parse_mode', $payload);
        $this->assertEquals('Markdown', $payload['parse_mode']);
        $this->assertArrayHasKey('disable_web_page_preview', $payload);
        $this->assertTrue($payload['disable_web_page_preview']);
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
