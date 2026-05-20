<?php

declare(strict_types=1);

namespace Phlix\Webhooks;

use Phlix\Webhooks\Plugins\AbstractNotificationPlugin;
use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;

/**
 * Registry for managing webhook notification plugins.
 *
 * Provides centralized access to all installed notification plugins
 * and allows dispatching events to specific or all providers.
 */
class PluginRegistry
{
    /**
     * @var array<string, WebhookPluginInterface>
     */
    private array $plugins = [];

    public function __construct()
    {
    }

    /**
     * Registers a plugin instance.
     */
    public function register(WebhookPluginInterface $plugin): void
    {
        $name = $plugin::getName();
        $this->plugins[$name] = $plugin;

        $this->getLogger()->debug('Webhook plugin registered', [
            'name' => $name,
        ]);
    }

    /**
     * Gets a plugin by name.
     */
    public function get(string $name): ?WebhookPluginInterface
    {
        return $this->plugins[$name] ?? null;
    }

    /**
     * Lists all registered plugin names.
     *
     * @return array<string>
     */
    public function listAll(): array
    {
        return array_keys($this->plugins);
    }

    /**
     * Checks if a plugin is registered.
     */
    public function has(string $name): bool
    {
        return isset($this->plugins[$name]);
    }

    /**
     * Sends an event to a specific plugin by name.
     */
    public function sendTo(string $pluginName, WebhookEvent $event): bool
    {
        $plugin = $this->get($pluginName);

        if ($plugin === null) {
            $this->getLogger()->warning('Plugin not found', [
                'plugin' => $pluginName,
                'event_type' => $event->eventType,
            ]);
            return false;
        }

        return $plugin->send($event);
    }

    /**
     * Sends an event to all registered plugins that support it.
     *
     * @return array<string, bool> Map of plugin name to success status
     */
    public function broadcast(WebhookEvent $event): array
    {
        $results = [];

        foreach ($this->plugins as $name => $plugin) {
            $supportedEvents = $plugin::getSupportedEvents();
            if (in_array($event->eventType, $supportedEvents, true)) {
                $results[$name] = $plugin->send($event);
            }
        }

        return $results;
    }

    /**
     * Registers all built-in notification plugins with default configurations.
     *
     * @param array<string, mixed>|null $config
     */
    public function registerBuiltIns(?array $config = null): void
    {
        $this->register(new Plugins\DiscordPlugin($config));
        $this->register(new Plugins\SlackPlugin($config));
        $this->register(new Plugins\TelegramPlugin($config));
        $this->register(new Plugins\NtfyPlugin($config));
        $this->register(new Plugins\PushoverPlugin($config));
        $this->register(new Plugins\ApprisePlugin($config));
        $this->register(new Plugins\MqttPlugin($config));
    }

    private function getLogger(): \Phlix\Common\Logger\StructuredLogger
    {
        return LoggerFactory::get(LogChannels::APPLICATION);
    }
}
