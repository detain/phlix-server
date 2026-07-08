<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Webhooks\Plugins;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Phlix\Webhooks\Plugins\NtfyPlugin;
use Phlix\Webhooks\WebhookEvent;

class NtfyPluginTest extends TestCase
{
    public function testGetNameReturnsNtfy(): void
    {
        $this->assertEquals('ntfy', NtfyPlugin::getName());
    }

    public function testGetSupportedEvents(): void
    {
        $events = NtfyPlugin::getSupportedEvents();
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
        $playbackStartedTags = $method->invoke($plugin, $playbackStarted);
        $this->assertIsString($playbackStartedTags);
        $this->assertStringContainsString('play', $playbackStartedTags);

        $playbackEnded = $this->createEvent('playback.ended', []);
        $playbackEndedTags = $method->invoke($plugin, $playbackEnded);
        $this->assertIsString($playbackEndedTags);
        $this->assertStringContainsString('stop', $playbackEndedTags);

        $libraryUpdated = $this->createEvent('library.updated', []);
        $libraryUpdatedTags = $method->invoke($plugin, $libraryUpdated);
        $this->assertIsString($libraryUpdatedTags);
        $this->assertStringContainsString('books', $libraryUpdatedTags);

        $downloadComplete = $this->createEvent('download.complete', []);
        $downloadCompleteTags = $method->invoke($plugin, $downloadComplete);
        $this->assertIsString($downloadCompleteTags);
        $this->assertStringContainsString('arrow_down', $downloadCompleteTags);

        $alert = $this->createEvent('alert', []);
        $alertTags = $method->invoke($plugin, $alert);
        $this->assertIsString($alertTags);
        $this->assertStringContainsString('warning', $alertTags);
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

    /**
     * @param array<string, mixed> $payload
     */
    private function createEvent(string $eventType, array $payload): WebhookEvent
    {
        return new WebhookEvent(
            $eventType,
            $payload,
            new DateTimeImmutable('2024-01-15T10:30:00+00:00')
        );
    }
}
