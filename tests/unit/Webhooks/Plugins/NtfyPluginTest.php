<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Webhooks\Plugins;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Phlex\Webhooks\Plugins\NtfyPlugin;
use Phlex\Webhooks\WebhookEvent;

class NtfyPluginTest extends TestCase
{
    public function testGetNameReturnsNtfy(): void
    {
        $this->assertEquals('ntfy', NtfyPlugin::getName());
    }

    public function testGetSupportedEvents(): void
    {
        $events = NtfyPlugin::getSupportedEvents();
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
            'topic' => 'test-topic',
            'server' => 'https://ntfy.sh',
            'enabled' => false,
        ];

        $plugin = new NtfyPlugin($config);
        $event = $this->createEvent('playback.started', ['title' => 'Test Movie']);

        $result = $plugin->send($event);
        $this->assertFalse($result);
    }

    public function testSendReturnsFalseWhenTopicEmpty(): void
    {
        $config = [
            'topic' => '',
            'server' => 'https://ntfy.sh',
            'enabled' => true,
        ];

        $plugin = new NtfyPlugin($config);
        $event = $this->createEvent('playback.started', ['title' => 'Test Movie']);

        $result = $plugin->send($event);
        $this->assertFalse($result);
    }

    public function testBuildPayloadCreatesCorrectStructure(): void
    {
        $config = [
            'topic' => 'test-topic',
            'server' => 'https://ntfy.sh',
            'enabled' => true,
        ];

        $plugin = new NtfyPlugin($config);
        $event = $this->createEvent('playback.started', [
            'title' => 'Test Movie',
        ]);

        $reflection = new \ReflectionClass($plugin);
        $method = $reflection->getMethod('buildPayload');
        $method->setAccessible(true);

        $payload = $method->invoke($plugin, $event);

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('title', $payload);
        $this->assertEquals('Test Movie', $payload['title']);
        $this->assertArrayHasKey('message', $payload);
        $this->assertArrayHasKey('event', $payload);
        $this->assertEquals('playback.started', $payload['event']);
        $this->assertArrayHasKey('when', $payload);
    }

    public function testGetTagsFromEventReturnsCorrectTags(): void
    {
        $plugin = new NtfyPlugin([
            'topic' => '',
            'enabled' => false,
        ]);

        $reflection = new \ReflectionClass($plugin);
        $method = $reflection->getMethod('getTagsFromEvent');
        $method->setAccessible(true);

        $playbackStarted = $this->createEvent('playback.started', []);
        $this->assertStringContainsString('play', $method->invoke($plugin, $playbackStarted));

        $playbackEnded = $this->createEvent('playback.ended', []);
        $this->assertStringContainsString('stop', $method->invoke($plugin, $playbackEnded));

        $libraryUpdated = $this->createEvent('library.updated', []);
        $this->assertStringContainsString('books', $method->invoke($plugin, $libraryUpdated));

        $downloadComplete = $this->createEvent('download.complete', []);
        $this->assertStringContainsString('arrow_down', $method->invoke($plugin, $downloadComplete));

        $alert = $this->createEvent('alert', []);
        $this->assertStringContainsString('warning', $method->invoke($plugin, $alert));
    }

    public function testGetPriorityFromEventReturnsCorrectPriority(): void
    {
        $plugin = new NtfyPlugin([
            'topic' => '',
            'enabled' => false,
        ]);

        $reflection = new \ReflectionClass($plugin);
        $method = $reflection->getMethod('getPriorityFromEvent');
        $method->setAccessible(true);

        $alert = $this->createEvent('alert', []);
        $this->assertEquals(5, $method->invoke($plugin, $alert));

        $recordingStarted = $this->createEvent('recording.started', []);
        $this->assertEquals(4, $method->invoke($plugin, $recordingStarted));

        $downloadComplete = $this->createEvent('download.complete', []);
        $this->assertEquals(3, $method->invoke($plugin, $downloadComplete));

        $playbackStarted = $this->createEvent('playback.started', []);
        $this->assertEquals(2, $method->invoke($plugin, $playbackStarted));
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
